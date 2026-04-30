<?php
/**
 * BlockTicker Signal Track Record.
 *
 * Tracks the 48-hour performance of every published trading signal so visitors
 * can see historical accuracy — the "trust moat" originally lived inside
 * class-widgets.php but was extracted in v70 to enforce separation of concerns.
 *
 * Surface area (all preserved from the original):
 *
 *   Options:
 *     - bt_signal_track   — array keyed by signal_id → { symbol, initial_price, ... }
 *
 *   Cron:
 *     - bt_signal_tracker_tick (hourly) → cron_signal_tracker_tick()
 *
 *   Shortcodes:
 *     - [bt_signal_tracker_badge hash=... title=... timestamp=...]
 *     - [bt_signal_track_badge id=...]     (legacy alias)
 *     - [bt_signal_track_record window=30]
 *
 *   Public API:
 *     - BT_SignalTracker::register_signal_for_tracking( $id, $symbol, $price )
 *     - BT_SignalTracker::cron_signal_tracker_tick()
 *     - BT_SignalTracker::get_signal_track_stats()
 *     - BT_SignalTracker::track_signal( $hash, $title, $timestamp )
 *
 * External callers previously addressed these via `BT_Widgets::…`. As of v70
 * they should use `BT_SignalTracker::…` — existing callers were audited
 * (zero in-repo, shortcodes resolve by string, so no shims needed).
 *
 * @package BlockTicker
 * @since   70.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_SignalTracker {

    /**
     * Wire up hooks: cron schedule + cron handler + 3 shortcodes.
     * Called once from the main plugin bootstrap.
     */
    public static function setup() {
        // Hourly cron — schedule if not already queued (idempotent, safe across activations)
        if ( ! wp_next_scheduled( 'bt_signal_tracker_tick' ) ) {
            wp_schedule_event( time() + 600, 'hourly', 'bt_signal_tracker_tick' );
        }
        add_action( 'bt_signal_tracker_tick', array( __CLASS__, 'cron_signal_tracker_tick' ) );

        // Shortcodes — strings are identical to v69 so any existing posts keep rendering
        add_shortcode( 'bt_signal_tracker_badge', array( __CLASS__, 'sc_signal_tracker_badge' ) );
        add_shortcode( 'bt_signal_track_record',  array( __CLASS__, 'sc_signal_track_record' ) );
        add_shortcode( 'bt_signal_track_badge',   array( __CLASS__, 'sc_signal_track_badge' ) );
        add_shortcode( 'bt_signal_leaderboard',    array( __CLASS__, 'sc_signal_leaderboard' ) );  // v105.0
    }

    // ========================================================
    // SIGNAL TRACK RECORD (v50) - trust moat
    // ========================================================
    //
    // Tracks each published signal's performance over 48h.
    // Storage: option 'bt_signal_track' is an array keyed by signal_id.
    // Each entry: { symbol, initial_price, initial_time, target_time,
    //               peak_gain, final_change, outcome } where outcome is
    //               'pending', 'hit', 'missed', 'neutral'.
    //
    // Lifecycle:
    //   1. When signals feed first shows a new signal, register_signal() is called
    //      to snapshot initial price.
    //   2. Hourly cron checks each pending signal's current price.
    //   3. At 48h mark (or when a signal hits >=5% gain), marks outcome.

    /** Register a signal for tracking. Idempotent. */
    public static function register_signal_for_tracking( $signal_id, $symbol, $initial_price ) {
        if ( empty( $signal_id ) || empty( $symbol ) || ! is_numeric( $initial_price ) ) return false;
        $track = get_option( 'bt_signal_track', array() );
        if ( isset( $track[ $signal_id ] ) ) return false; // already tracked
        $track[ $signal_id ] = array(
            'symbol'        => strtoupper( $symbol ),
            'initial_price' => floatval( $initial_price ),
            'initial_time'  => time(),
            'target_time'   => time() + ( 48 * HOUR_IN_SECONDS ),
            'peak_gain'     => 0.0,
            'final_change'  => null,
            'outcome'       => 'pending',
        );
        // Cap tracker size at 500 signals, drop oldest completed ones
        if ( count( $track ) > 500 ) {
            uasort( $track, function( $a, $b ) {
                if ( $a['outcome'] === 'pending' && $b['outcome'] !== 'pending' ) return 1;
                if ( $b['outcome'] === 'pending' && $a['outcome'] !== 'pending' ) return -1;
                return ( $b['initial_time'] ?? 0 ) <=> ( $a['initial_time'] ?? 0 );
            } );
            $track = array_slice( $track, 0, 500, true );
        }
        update_option( 'bt_signal_track', $track );
        return true;
    }

    /**
     * Cron handler - checks each pending signal and updates outcome.
     * Runs hourly via bt_signal_tracker_tick schedule.
     */
    public static function cron_signal_tracker_tick() {
        $track = get_option( 'bt_signal_track', array() );
        if ( empty( $track ) ) return;

        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $forex  = BT_Widgets::get_json_option( 'fxlm_forex_data' );

        $updated = false;
        foreach ( $track as $id => &$t ) {
            if ( $t['outcome'] !== 'pending' ) continue;

            // Find current price for this symbol
            $current = null;
            $sym = strtoupper( $t['symbol'] );

            // Try crypto first (symbol match)
            if ( ! empty( $crypto['coins'] ) ) {
                foreach ( $crypto['coins'] as $c ) {
                    if ( strtoupper( $c['symbol'] ) === $sym ) {
                        $current = floatval( $c['current_price'] );
                        break;
                    }
                }
            }
            // Then forex (pair format like EUR/USD)
            if ( $current === null && ! empty( $forex['rates'][ $sym ] ) ) {
                $current = floatval( $forex['rates'][ $sym ]['rate'] ?? 0 );
            }

            if ( $current === null || $current <= 0 ) continue;

            $change_pct = ( ( $current - $t['initial_price'] ) / $t['initial_price'] ) * 100;

            // Track peak gain
            if ( $change_pct > $t['peak_gain'] ) {
                $t['peak_gain'] = round( $change_pct, 2 );
                $updated = true;
            }

            // Check if target hit (peak gain >= 5%) - early resolution
            if ( $t['peak_gain'] >= 5 && $t['outcome'] === 'pending' ) {
                $t['final_change'] = $t['peak_gain'];
                $t['outcome']      = 'hit';
                $updated = true;
                continue;
            }

            // Check if 48h window elapsed
            if ( time() >= $t['target_time'] ) {
                $t['final_change'] = round( $change_pct, 2 );
                if ( $t['peak_gain'] >= 2 )        $t['outcome'] = 'hit';      // touched target at some point
                elseif ( $change_pct >= 1 )         $t['outcome'] = 'neutral';  // modest gain
                elseif ( $change_pct <= -2 )        $t['outcome'] = 'missed';   // drawdown
                else                                $t['outcome'] = 'neutral';
                $updated = true;
            }
        }
        unset( $t );

        if ( $updated ) update_option( 'bt_signal_track', $track );
    }

    /** Get summary stats across all tracked signals. */
    public static function get_signal_track_stats() {
        $track = get_option( 'bt_signal_track', array() );
        $total   = count( $track );
        $pending = 0; $hit = 0; $missed = 0; $neutral = 0;
        $sum_gain = 0; $resolved = 0;
        foreach ( $track as $t ) {
            if ( $t['outcome'] === 'pending' ) { $pending++; continue; }
            $resolved++;
            if ( $t['outcome'] === 'hit' )     $hit++;
            elseif ( $t['outcome'] === 'missed' ) $missed++;
            else                                  $neutral++;
            if ( isset( $t['final_change'] ) )    $sum_gain += $t['final_change'];
        }
        return array(
            'total'    => $total,
            'pending'  => $pending,
            'hit'      => $hit,
            'missed'   => $missed,
            'neutral'  => $neutral,
            'resolved' => $resolved,
            'hit_rate' => $resolved > 0 ? round( ( $hit / $resolved ) * 100, 1 ) : null,
            'avg_gain' => $resolved > 0 ? round( $sum_gain / $resolved, 2 ) : null,
        );
    }

    /**
     * Shortcode: [bt_signal_track_badge symbol="BTC"]
     * Renders a small track-record badge for a given symbol.
     */
    public static function sc_signal_track_badge( $atts ) {
        $a = shortcode_atts( array( 'symbol' => '' ), $atts );
        $sym = strtoupper( sanitize_text_field( $a['symbol'] ) );
        if ( ! $sym ) return '';

        $track = get_option( 'bt_signal_track', array() );
        $hit = 0; $total = 0;
        foreach ( $track as $t ) {
            if ( ( $t['symbol'] ?? '' ) !== $sym || $t['outcome'] === 'pending' ) continue;
            $total++;
            if ( $t['outcome'] === 'hit' ) $hit++;
        }
        if ( $total === 0 ) return '';
        $rate = round( ( $hit / $total ) * 100 );
        ob_start();
        
?>
        <span class="bt-track-badge" title="<?php
            /* translators: %1$d is hit count, %2$d is total signals */
            printf( esc_attr__( '%1$d hits / %2$d signals', 'blockticker' ), $hit, $total );
        ?>">
            <span>&#x2713;</span>
            <?php /* translators: %d is hit-rate percentage */ ?>
            <?php printf( esc_html__( '%d%% track record', 'blockticker' ), $rate ); ?>
        </span>
        <?php
        return ob_get_clean();
    }

    // ==================================================================
    // SIGNAL TRACKER (v50) - shows price move since signal publish
    // ==================================================================
    // Transparent "has this signal aged well?" indicator.
    // On first render, records the current price for the signal's primary
    // asset. On later renders (> 2h old), compares against current price
    // and displays the delta. Cleaned up after 14 days.

    /** Extract primary asset symbol from a signal title. */
    private static function extract_signal_symbol( $title ) {
        // Common pair patterns
        $pairs = array( 'EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'EUR/GBP', 'USD/TRY', 'USD/MXN', 'GBP/JPY', 'EUR/JPY' );
        foreach ( $pairs as $p ) {
            if ( stripos( $title, $p ) !== false ) return $p;
        }
        // Crypto symbols
        $coins = array( 'BTC', 'ETH', 'SOL', 'XRP', 'ADA', 'DOGE', 'BNB', 'DOT', 'AVAX', 'LINK', 'MATIC', 'LTC', 'UNI', 'ATOM', 'ARB', 'OP' );
        foreach ( $coins as $c ) {
            if ( preg_match( '/\b' . $c . '\b/i', $title ) ) return $c;
        }
        // Generic "Bitcoin", "Ethereum" etc.
        $name_map = array( 'bitcoin' => 'BTC', 'ethereum' => 'ETH', 'solana' => 'SOL', 'ripple' => 'XRP', 'cardano' => 'ADA', 'dogecoin' => 'DOGE', 'chainlink' => 'LINK', 'polygon' => 'MATIC' );
        foreach ( $name_map as $name => $sym ) {
            if ( stripos( $title, $name ) !== false ) return $sym;
        }
        return '';
    }

    /** Get the current price for a tracked symbol from our caches. */
    private static function get_tracker_price( $sym ) {
        if ( empty( $sym ) ) return 0;
        // Crypto: look in fxlm_crypto_data
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        if ( ! empty( $crypto['coins'] ) ) {
            foreach ( $crypto['coins'] as $coin ) {
                if ( strtoupper( $coin['symbol'] ) === strtoupper( $sym ) ) {
                    return floatval( $coin['current_price'] );
                }
            }
        }
        // Forex: look in fxlm_forex_data
        if ( strpos( $sym, '/' ) !== false ) {
            $forex = BT_Widgets::get_json_option( 'fxlm_forex_data' );
            if ( ! empty( $forex['rates'][ $sym ]['rate'] ) ) {
                return floatval( $forex['rates'][ $sym ]['rate'] );
            }
        }
        return 0;
    }

    /** Record or update a signal's price history. Called on render. */
    /** Parse directional intent (bullish / bearish / neutral) from signal title. */
    private static function extract_signal_direction( $title ) {
        if ( preg_match( '/\b(bullish|bull case|buy|long|rally|surge|breakout|upside|outperform|target|recovery|bounce|gain|climb|rise|up[\\s-]?trend)\b/i', $title ) ) {
            return 'bull';
        }
        if ( preg_match( '/\b(bearish|bear case|sell|short|dump|crash|breakdown|downside|underperform|slide|plunge|fall|drop|decline|down[\\s-]?trend|resistance rejected)\b/i', $title ) ) {
            return 'bear';
        }
        return 'neutral';
    }

    public static function track_signal( $signal_hash, $title, $timestamp ) {
        $tracker = get_option( 'bt_signal_tracker', array() );
        if ( isset( $tracker[ $signal_hash ] ) ) {
            $entry = $tracker[ $signal_hash ];
            // Resolve outcome once the signal is 48h+ old and we have both prices
            if ( empty( $entry['outcome'] ) && ( time() - intval( $entry['first_seen'] ) ) >= 48 * 3600 ) {
                $current = self::get_tracker_price( $entry['sym'] );
                if ( $current > 0 && ! empty( $entry['init'] ) ) {
                    $pct = ( ( $current - $entry['init'] ) / $entry['init'] ) * 100;
                    $dir = $entry['dir'] ?? 'neutral';
                    // Outcome: direction aligns with move, threshold 0.3% to avoid noise
                    if ( abs( $pct ) < 0.3 ) {
                        $entry['outcome'] = 'flat';
                    } elseif ( $dir === 'bull' ) {
                        $entry['outcome'] = $pct > 0 ? 'win' : 'loss';
                    } elseif ( $dir === 'bear' ) {
                        $entry['outcome'] = $pct < 0 ? 'win' : 'loss';
                    } else {
                        $entry['outcome'] = 'neutral';
                    }
                    $entry['final']       = $current;
                    $entry['final_pct']   = round( $pct, 2 );
                    $entry['verified_at'] = time();
                    $tracker[ $signal_hash ] = $entry;
                    update_option( 'bt_signal_tracker', $tracker, false );
                }
            }
            return $entry;
        }
        $sym = self::extract_signal_symbol( $title );
        if ( empty( $sym ) ) return null;
        $price = self::get_tracker_price( $sym );
        if ( $price <= 0 ) return null;
        $entry = array(
            'sym'        => $sym,
            'dir'        => self::extract_signal_direction( $title ),
            'init'       => $price,
            'published'  => intval( $timestamp ) ?: time(),
            'first_seen' => time(),
        );
        $tracker[ $signal_hash ] = $entry;
        // Prune entries older than 14 days
        $cutoff = time() - 14 * 86400;
        foreach ( $tracker as $k => $e ) {
            if ( ( $e['first_seen'] ?? 0 ) < $cutoff ) unset( $tracker[ $k ] );
        }
        update_option( 'bt_signal_tracker', $tracker, false );
        return $entry;
    }

    /** Render a small badge next to a signal showing price move since publish. */
    public static function sc_signal_tracker_badge( $atts ) {
        $a = shortcode_atts( array( 'hash' => '', 'title' => '', 'timestamp' => 0 ), $atts );
        if ( empty( $a['hash'] ) && empty( $a['title'] ) ) return '';
        $hash    = $a['hash'] ?: md5( $a['title'] );
        $entry   = self::track_signal( $hash, $a['title'], $a['timestamp'] );
        if ( ! $entry ) return '';
        // Only show once 2h+ old (enough time for a meaningful move)
        $age = time() - intval( $entry['first_seen'] );
        if ( $age < 2 * 3600 ) return '';
        $current = self::get_tracker_price( $entry['sym'] );
        if ( $current <= 0 || $entry['init'] <= 0 ) return '';
        $pct = ( ( $current - $entry['init'] ) / $entry['init'] ) * 100;
        $cls = $pct >= 0 ? 'pos' : 'neg';
        $arr = $pct >= 0 ? "↗" : "↘";
        $since = human_time_diff( $entry['first_seen'] );
        $dir   = $entry['dir'] ?? 'neutral';
        $outcome = $entry['outcome'] ?? '';

        // If we have a resolved outcome, show a marker instead of the raw move
        $marker = '';
        if ( $outcome === 'win' ) {
            $marker = '<span class="bt-sig-outcome bt-sig-outcome-win" title="' . esc_attr__( 'Direction matched price move', 'blockticker' ) . '">✓ ' . esc_html__( 'Called it', 'blockticker' ) . '</span>';
        } elseif ( $outcome === 'loss' ) {
            $marker = '<span class="bt-sig-outcome bt-sig-outcome-loss" title="' . esc_attr__( 'Direction opposed price move', 'blockticker' ) . '">✗ ' . esc_html__( 'Missed', 'blockticker' ) . '</span>';
        } elseif ( $outcome === 'flat' || $outcome === 'neutral' ) {
            // flat: <0.3% move; neutral: no direction parsed. Skip marker, show move only.
        }

        $badge = sprintf(
            '<span class="bt-sig-track bt-sig-track-%s" title="%s since first seen %s ago">%s <strong>%s%s%%</strong></span>',
            esc_attr( $cls ),
            esc_attr( $entry['sym'] ),
            esc_attr( $since ),
            $arr,
            $pct >= 0 ? '+' : '',
            number_format( $pct, 2 )
        );
        return $marker . $badge;
    }

    /**
     * Shortcode: [bt_signal_track_record window="30"]
     * Shows aggregate win/loss stats for the last N verified signals.
     */
    public static function sc_signal_track_record( $atts ) {
        $a = shortcode_atts( array( 'window' => 30 ), $atts );
        $window  = max( 5, min( 100, intval( $a['window'] ) ) );
        $tracker = get_option( 'bt_signal_tracker', array() );
        if ( empty( $tracker ) ) return '';

        // Keep only resolved entries (with outcome)
        $resolved = array();
        foreach ( $tracker as $e ) {
            if ( ! empty( $e['outcome'] ) && in_array( $e['outcome'], array( 'win', 'loss' ), true ) ) {
                $resolved[] = $e;
            }
        }
        if ( empty( $resolved ) ) {
            // Show a "warming up" card while we wait for 48h to elapse on first signals
            $total_tracked = count( $tracker );
            ob_start();
            ?>
            <div class="bt-track-record bt-track-record-warming">
              <div class="bt-track-head">
                <span class="bt-track-icon">📊</span>
                <h3><?php esc_html_e( 'Signal Track Record', 'blockticker' ); ?></h3>
                <span class="bt-track-badge"><?php esc_html_e( 'Warming up', 'blockticker' ); ?></span>
              </div>
              <p class="bt-track-body"><?php
                /* translators: %d = number of signals currently being tracked */
                printf( esc_html__( 'We\'re tracking %d signal(s). Outcomes resolve 48 hours after first seen — first verified results appear here shortly.', 'blockticker' ), $total_tracked );
              ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        // Sort by verified_at desc, take window
        usort( $resolved, function( $a, $b ) {
            return ( $b['verified_at'] ?? 0 ) <=> ( $a['verified_at'] ?? 0 );
        } );
        $slice = array_slice( $resolved, 0, $window );
        $wins  = 0;
        $total = count( $slice );
        $total_move = 0;
        $best_win = null;
        foreach ( $slice as $e ) {
            if ( $e['outcome'] === 'win' ) {
                $wins++;
                // "Best call" for bulls = most positive %, for bears = most negative %
                $abs_move = abs( $e['final_pct'] ?? 0 );
                if ( $best_win === null || $abs_move > abs( $best_win['final_pct'] ?? 0 ) ) {
                    $best_win = $e;
                }
            }
            $total_move += abs( $e['final_pct'] ?? 0 );
        }
        $accuracy = $total > 0 ? round( ( $wins / $total ) * 100 ) : 0;
        $avg_move = $total > 0 ? round( $total_move / $total, 2 ) : 0;

        ob_start();
        ?>
        <div class="bt-track-record">
          <div class="bt-track-head">
            <span class="bt-track-icon">🎯</span>
            <h3><?php esc_html_e( 'Signal Track Record', 'blockticker' ); ?></h3>
            <span class="bt-track-window"><?php
              /* translators: %d = window count */
              printf( esc_html__( 'Last %d verified', 'blockticker' ), $total );
            ?></span>
          </div>
          <div class="bt-track-stats">
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl"><?php esc_html_e( 'Accuracy', 'blockticker' ); ?></div>
              <div class="bt-track-stat-val bt-track-accuracy-<?php echo $accuracy >= 60 ? 'high' : ( $accuracy >= 45 ? 'mid' : 'low' ); ?>"><?php echo esc_html( $accuracy ); ?>%</div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl"><?php esc_html_e( 'Wins', 'blockticker' ); ?></div>
              <div class="bt-track-stat-val bt-track-wins"><?php echo esc_html( $wins ); ?></div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl"><?php esc_html_e( 'Misses', 'blockticker' ); ?></div>
              <div class="bt-track-stat-val bt-track-losses"><?php echo esc_html( $total - $wins ); ?></div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl"><?php esc_html_e( 'Avg. Move', 'blockticker' ); ?></div>
              <div class="bt-track-stat-val"><?php echo esc_html( $avg_move ); ?>%</div>
            </div>
          </div>
          <?php if ( $best_win ): ?>
          <div class="bt-track-best">
            <span class="bt-track-best-lbl"><?php esc_html_e( 'Best verified call', 'blockticker' ); ?></span>
            <span class="bt-track-best-sym"><?php echo esc_html( $best_win['sym'] ); ?></span>
            <span class="bt-track-best-dir"><?php echo $best_win['dir'] === 'bull' ? "🐂 " . esc_html__( 'Bullish call', 'blockticker' ) : "🐻 " . esc_html__( 'Bearish call', 'blockticker' ); ?></span>
            <span class="bt-track-best-pct"><?php echo ( $best_win['final_pct'] >= 0 ? '+' : '' ) . esc_html( number_format( $best_win['final_pct'], 2 ) ); ?>%</span>
          </div>
          <?php endif; ?>
          <p class="bt-track-note"><?php esc_html_e( 'Every trading signal we publish is verified 48 hours after first seen. No cherry-picking, no survivorship bias — the full window is shown above.', 'blockticker' ); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ========================================================
     * SIGNAL SOURCE LEADERBOARD (v105.0)
     * ======================================================== */

    /**
     * Build a source → stats map from bt_signal_tracker + bt_signal_items.
     *
     * Algorithm:
     *   1. Read bt_signal_items to build hash → source lookup
     *      (hash = md5($item['title']), matching what sc_signals_feed writes)
     *   2. Walk bt_signal_tracker; for each resolved entry look up its source
     *   3. Aggregate per source: wins / losses / flats / avg_abs_move
     *   4. Sort by win_rate DESC; sources with < $min_signals are ranked last
     *
     * @param  int   $min_signals  Minimum resolved signals to qualify for ranking.
     * @param  int   $window_days  Only count signals resolved in the last N days (0 = all).
     * @return array  Array of source rows, sorted by win_rate DESC.
     */
    public static function get_leaderboard( $min_signals = 3, $window_days = 90 ) {
        $tracker = get_option( 'bt_signal_tracker', array() );
        $items   = get_option( 'bt_signal_items',   array() );

        if ( empty( $tracker ) ) return array();

        // Build hash → source lookup from all known signal items.
        $hash_to_source = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['title'] ) && ! empty( $item['source'] ) ) {
                $hash_to_source[ md5( $item['title'] ) ] = $item['source'];
            }
        }

        $cutoff   = $window_days > 0 ? ( time() - $window_days * DAY_IN_SECONDS ) : 0;
        $sources  = array();

        foreach ( $tracker as $hash => $entry ) {
            $outcome = $entry['outcome'] ?? '';
            if ( ! in_array( $outcome, array( 'win', 'loss', 'flat' ), true ) ) continue;

            // Filter by window.
            $resolved_at = $entry['verified_at'] ?? ( $entry['first_seen'] ?? 0 );
            if ( $cutoff > 0 && $resolved_at < $cutoff ) continue;

            $source = $hash_to_source[ $hash ] ?? 'Unknown';

            if ( ! isset( $sources[ $source ] ) ) {
                $sources[ $source ] = array(
                    'source'      => $source,
                    'wins'        => 0,
                    'losses'      => 0,
                    'flats'       => 0,
                    'total'       => 0,
                    'move_sum'    => 0.0,
                    'best_win'    => null,  // entry with highest abs final_pct
                    'best_sym'    => '',
                    'best_pct'    => 0.0,
                );
            }

            $s =& $sources[ $source ];
            $s['total']++;
            $abs_move = abs( (float) ( $entry['final_pct'] ?? 0 ) );
            $s['move_sum'] += $abs_move;

            if ( $outcome === 'win' ) {
                $s['wins']++;
                if ( $abs_move > $s['best_pct'] ) {
                    $s['best_pct'] = $abs_move;
                    $s['best_sym'] = $entry['sym'] ?? '';
                    $s['best_win'] = array(
                        'sym'       => $entry['sym'] ?? '',
                        'dir'       => $entry['dir'] ?? '',
                        'final_pct' => (float) ( $entry['final_pct'] ?? 0 ),
                    );
                }
            } elseif ( $outcome === 'loss' ) {
                $s['losses']++;
            } else {
                $s['flats']++;
            }
        }
        unset( $s );

        // Compute derived stats.
        foreach ( $sources as &$row ) {
            $resolved         = $row['wins'] + $row['losses']; // flats excluded from rate
            $row['resolved']  = $resolved;
            $row['win_rate']  = $resolved > 0 ? round( ( $row['wins'] / $resolved ) * 100, 1 ) : 0.0;
            $row['avg_move']  = $row['total'] > 0 ? round( $row['move_sum'] / $row['total'], 2 ) : 0.0;
            $row['qualified'] = $resolved >= $min_signals;
        }
        unset( $row );

        // Sort: qualified sources by win_rate DESC, then unqualified by total DESC.
        usort( $sources, function( $a, $b ) {
            if ( $a['qualified'] !== $b['qualified'] ) {
                return $a['qualified'] ? -1 : 1; // qualified first
            }
            if ( $a['win_rate'] !== $b['win_rate'] ) {
                return $b['win_rate'] <=> $a['win_rate'];
            }
            return $b['total'] <=> $a['total'];
        } );

        return array_values( $sources );
    }

    /**
     * [bt_signal_leaderboard min_signals="3" window_days="90" max_rows="10" show_best="1"]
     *
     * Renders a ranked table of signal sources by win rate.
     *
     * Attributes:
     *   min_signals  int   Minimum resolved signals to qualify for a rank badge (default 3)
     *   window_days  int   Look-back window in days; 0 = all-time (default 90)
     *   max_rows     int   Maximum rows to show (default 10)
     *   show_best    bool  Show best verified call column (default 1)
     *   title        str   Card heading (default "Signal Source Leaderboard")
     */
    public static function sc_signal_leaderboard( $atts ) {
        $a = shortcode_atts( array(
            'min_signals' => 3,
            'window_days' => 90,
            'max_rows'    => 10,
            'show_best'   => 1,
            'title'       => 'Signal Source Leaderboard',
        ), $atts );

        $min_signals = max( 1, intval( $a['min_signals'] ) );
        $window_days = max( 0, intval( $a['window_days'] ) );
        $max_rows    = max( 1, min( 50, intval( $a['max_rows'] ) ) );
        $show_best   = (bool) $a['show_best'];

        $rows = self::get_leaderboard( $min_signals, $window_days );

        if ( empty( $rows ) ) {
            return '<div class="bt-lb-empty"><p>📡 ' . esc_html__( 'Leaderboard is building — signal outcomes resolve 48 hours after first seen.', 'blockticker' ) . '</p></div>';
        }

        $rows = array_slice( $rows, 0, $max_rows );
        $rank = 0;

        ob_start(); ?>
        <div class="bt-leaderboard-wrap">
            <?php if ( $a['title'] ) : ?>
            <div class="bt-lb-header">
                <h3 class="bt-lb-title">🏆 <?php echo esc_html( $a['title'] ); ?></h3>
                <span class="bt-lb-meta">
                    <?php
                    if ( $window_days > 0 ) {
                        /* translators: %d = number of days */
                        printf( esc_html__( 'Last %d days', 'blockticker' ), $window_days );
                    } else {
                        esc_html_e( 'All-time', 'blockticker' );
                    }
                    ?>
                    &middot;
                    <?php
                    /* translators: %d = minimum signals required */
                    printf( esc_html__( 'min. %d signals to rank', 'blockticker' ), $min_signals );
                    ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="bt-lb-table-wrap">
                <table class="bt-lb-table">
                    <thead>
                        <tr>
                            <th class="bt-lb-th-rank">#</th>
                            <th class="bt-lb-th-source">Source</th>
                            <th class="bt-lb-th-rate">Win Rate</th>
                            <th class="bt-lb-th-wl">W&thinsp;/&thinsp;L</th>
                            <th class="bt-lb-th-move">Avg Move</th>
                            <?php if ( $show_best ) : ?>
                            <th class="bt-lb-th-best">Best Call</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $qualified = $row['qualified'];
                            if ( $qualified ) $rank++;
                            $rate      = $row['win_rate'];
                            $rate_cls  = $rate >= 60 ? 'bt-lb-rate-high' : ( $rate >= 45 ? 'bt-lb-rate-mid' : 'bt-lb-rate-low' );
                            $medal     = $qualified ? ( $rank === 1 ? '🥇' : ( $rank === 2 ? '🥈' : ( $rank === 3 ? '🥉' : '#' . $rank ) ) ) : '—';
                        ?>
                        <tr class="bt-lb-row<?php echo $qualified ? '' : ' bt-lb-row-unq'; ?>">
                            <td class="bt-lb-rank"><?php echo esc_html( $medal ); ?></td>
                            <td class="bt-lb-source">
                                <?php echo esc_html( $row['source'] ); ?>
                                <?php if ( ! $qualified ) : ?>
                                <span class="bt-lb-needs-more" title="<?php
                                    /* translators: %d = min signals required */
                                    printf( esc_attr__( 'Needs %d resolved signals to rank', 'blockticker' ), $min_signals );
                                ?>">&#x2022;</span>
                                <?php endif; ?>
                            </td>
                            <td class="bt-lb-rate <?php echo esc_attr( $rate_cls ); ?>">
                                <?php if ( $qualified ) : ?>
                                <span class="bt-lb-rate-bar" style="--pct:<?php echo esc_attr( $rate ); ?>%"></span>
                                <span class="bt-lb-rate-num"><?php echo esc_html( number_format( $rate, 1 ) ); ?>%</span>
                                <?php else : ?>
                                <span class="bt-lb-rate-pending">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="bt-lb-wl">
                                <span class="bt-lb-wins"><?php echo esc_html( $row['wins'] ); ?></span>
                                /
                                <span class="bt-lb-losses"><?php echo esc_html( $row['losses'] ); ?></span>
                                <?php if ( $row['flats'] > 0 ) : ?>
                                <span class="bt-lb-flats"> (<?php echo esc_html( $row['flats'] ); ?> flat)</span>
                                <?php endif; ?>
                            </td>
                            <td class="bt-lb-move"><?php echo esc_html( number_format( $row['avg_move'], 2 ) ); ?>%</td>
                            <?php if ( $show_best ) : ?>
                            <td class="bt-lb-best">
                                <?php if ( ! empty( $row['best_win'] ) ) :
                                    $bw = $row['best_win'];
                                    $sign = $bw['final_pct'] >= 0 ? '+' : '';
                                ?>
                                <span class="bt-lb-best-sym"><?php echo esc_html( $bw['sym'] ); ?></span>
                                <span class="bt-lb-best-dir"><?php echo $bw['dir'] === 'bear' ? '🐻' : '🐂'; ?></span>
                                <span class="bt-lb-best-pct"><?php echo esc_html( $sign . number_format( $bw['final_pct'], 1 ) ); ?>%</span>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="bt-lb-note">
                <?php esc_html_e( 'Win rate counts LONG signals that moved up ≥0.3% and SHORT signals that moved down ≥0.3% within 48 hours. Flat outcomes excluded from rate. No cherry-picking — every tracked signal is included.', 'blockticker' ); ?>
            </p>
        </div>

        <style>
        .bt-leaderboard-wrap{font-family:inherit;margin:20px 0}
        .bt-lb-header{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px}
        .bt-lb-title{font-size:16px;font-weight:700;margin:0}
        .bt-lb-meta{font-size:12px;color:#888}
        .bt-lb-table-wrap{overflow-x:auto}
        .bt-lb-table{width:100%;border-collapse:collapse;font-size:13px}
        .bt-lb-table thead tr{border-bottom:2px solid rgba(255,255,255,.08)}
        .bt-lb-table th{padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#888;font-weight:600;white-space:nowrap}
        .bt-lb-table td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
        .bt-lb-row:hover td{background:rgba(255,255,255,.03)}
        .bt-lb-row-unq td{opacity:.6}
        .bt-lb-rank{font-size:15px;min-width:36px}
        .bt-lb-source{font-weight:600;white-space:nowrap}
        .bt-lb-needs-more{color:#888;margin-left:4px;cursor:help}
        .bt-lb-rate{position:relative;min-width:90px}
        .bt-lb-rate-bar{display:block;position:absolute;left:0;top:0;bottom:0;background:currentColor;opacity:.12;border-radius:3px;width:var(--pct)}
        .bt-lb-rate-num{position:relative;font-weight:700;font-variant-numeric:tabular-nums}
        .bt-lb-rate-high .bt-lb-rate-num{color:#00a32a}
        .bt-lb-rate-mid  .bt-lb-rate-num{color:#e6972b}
        .bt-lb-rate-low  .bt-lb-rate-num{color:#d63638}
        .bt-lb-rate-high .bt-lb-rate-bar{color:#00a32a}
        .bt-lb-rate-mid  .bt-lb-rate-bar{color:#e6972b}
        .bt-lb-rate-low  .bt-lb-rate-bar{color:#d63638}
        .bt-lb-wins{color:#00a32a;font-weight:600}
        .bt-lb-losses{color:#d63638;font-weight:600}
        .bt-lb-flats{color:#888;font-size:11px}
        .bt-lb-move{color:#888;font-variant-numeric:tabular-nums}
        .bt-lb-best{white-space:nowrap;font-size:12px}
        .bt-lb-best-sym{font-weight:700;margin-right:2px}
        .bt-lb-best-pct{color:#00a32a;font-variant-numeric:tabular-nums}
        .bt-lb-note{font-size:11px;color:#888;margin:10px 0 0;font-style:italic}
        .bt-lb-empty{padding:24px;text-align:center;background:rgba(255,255,255,.03);border-radius:0;color:#888}
        </style>
        <?php
        return ob_get_clean();
    }


}
