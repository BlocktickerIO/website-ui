<?php
/**
 * BlockTicker — Performance / Track-Record subsystem
 *
 * Public-facing trust & E-E-A-T page that audits every published trading
 * signal. Pure aggregation over the existing `bt_signal_tracker` option;
 * no new tables, no AI, no opinions — just verified outcomes.
 *
 * Outcomes are written by BT_Signal_Tracker::track_signal() 48h after a
 * signal first appears (0.3% noise threshold, direction-aligned check).
 * This file only READS that data and renders it.
 *
 * Public surface
 *   /performance/                            — full track-record page
 *   [bt_performance_dashboard]               — drop-in for the page above
 *   [bt_performance_summary_card]            — compact 4-tile homepage embed
 *   BlockTicker → Performance (admin)        — operator overview + recompute
 *
 * @since v119.11.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Performance {

    /** Cache key for the rendered dashboard (5-minute window). */
    const CACHE_KEY = 'bt_perf_dashboard_html_v1';
    const CACHE_TTL = 300;

    public static function setup() {
        add_shortcode( 'bt_performance_dashboard',     array( __CLASS__, 'sc_dashboard' ) );
        add_shortcode( 'bt_performance_summary_card',  array( __CLASS__, 'sc_summary_card' ) );

        add_action( 'admin_menu',          array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_post_bt_perf_recompute', array( __CLASS__, 'admin_handle_recompute' ) );

        // Bust cache whenever the tracker is updated.
        add_action( 'update_option_bt_signal_tracker', array( __CLASS__, 'bust_cache' ), 10, 0 );
    }

    public static function bust_cache() {
        delete_transient( self::CACHE_KEY );
    }

    /* ================================================================
     *  Aggregation helpers — pure functions over the tracker option
     * ================================================================ */

    /**
     * Fetch the resolved signals (outcome ∈ {win, loss, flat}) sorted by
     * verification time DESC. Optionally limited and/or filtered by symbol.
     *
     * @param int    $limit   Max rows to return (0 = no limit).
     * @param string $symbol  Optional symbol filter (e.g. 'BTC').
     * @return array
     */
    public static function get_resolved_signals( $limit = 0, $symbol = '' ) {
        $tracker = get_option( 'bt_signal_tracker', array() );
        $rows    = array();
        $symbol  = strtoupper( trim( $symbol ) );

        foreach ( $tracker as $hash => $entry ) {
            $outcome = $entry['outcome'] ?? '';
            if ( ! in_array( $outcome, array( 'win', 'loss', 'flat' ), true ) ) continue;
            if ( $symbol !== '' && strtoupper( $entry['sym'] ?? '' ) !== $symbol ) continue;

            $rows[] = array(
                'hash'        => $hash,
                'sym'         => $entry['sym'] ?? '',
                'dir'         => $entry['dir'] ?? 'neutral',
                'init'        => (float) ( $entry['init'] ?? 0 ),
                'final'       => (float) ( $entry['final'] ?? 0 ),
                'final_pct'   => (float) ( $entry['final_pct'] ?? 0 ),
                'outcome'     => $outcome,
                'first_seen'  => intval( $entry['first_seen'] ?? 0 ),
                'verified_at' => intval( $entry['verified_at'] ?? ( $entry['first_seen'] ?? 0 ) ),
            );
        }

        usort( $rows, function( $a, $b ) {
            return $b['verified_at'] <=> $a['verified_at'];
        } );

        if ( $limit > 0 ) $rows = array_slice( $rows, 0, $limit );
        return $rows;
    }

    /**
     * Top-level aggregate stats across all resolved signals.
     * Uses the `bt_signal_tracker` option (the verified one) — NOT the
     * older `bt_signal_track` series consumed by the existing track_record
     * shortcode. Keeps the two systems independent.
     */
    public static function get_aggregate_stats() {
        $rows = self::get_resolved_signals();
        $total = count( $rows );

        $wins = 0; $losses = 0; $flats = 0;
        $abs_move_sum = 0.0;
        $first_ts = 0; $last_ts = 0;

        foreach ( $rows as $r ) {
            if ( $r['outcome'] === 'win' )       $wins++;
            elseif ( $r['outcome'] === 'loss' )  $losses++;
            else                                  $flats++;

            $abs_move_sum += abs( $r['final_pct'] );
            $ts = $r['verified_at'] ?: $r['first_seen'];
            if ( $ts > 0 ) {
                if ( $first_ts === 0 || $ts < $first_ts ) $first_ts = $ts;
                if ( $ts > $last_ts )                     $last_ts  = $ts;
            }
        }

        $resolved = $wins + $losses; // flats excluded from rate calc
        $accuracy = $resolved > 0 ? round( ( $wins / $resolved ) * 100, 1 ) : null;
        $avg_move = $total > 0 ? round( $abs_move_sum / $total, 2 ) : 0.0;
        $days     = ( $first_ts > 0 && $last_ts > $first_ts )
                  ? max( 1, (int) ceil( ( $last_ts - $first_ts ) / DAY_IN_SECONDS ) )
                  : 0;

        return array(
            'total'    => $total,
            'wins'     => $wins,
            'losses'   => $losses,
            'flats'    => $flats,
            'resolved' => $resolved,
            'accuracy' => $accuracy,
            'avg_move' => $avg_move,
            'days'     => $days,
            'first_ts' => $first_ts,
            'last_ts'  => $last_ts,
        );
    }

    /**
     * Per-asset (symbol) breakdown — wins/losses/win-rate/avg-move per coin.
     * Sorted by total signals DESC. Symbols with < $min_signals are excluded.
     */
    public static function get_per_asset_breakdown( $min_signals = 2 ) {
        $rows    = self::get_resolved_signals();
        $by_sym  = array();

        foreach ( $rows as $r ) {
            $sym = strtoupper( $r['sym'] );
            if ( $sym === '' ) continue;
            if ( ! isset( $by_sym[ $sym ] ) ) {
                $by_sym[ $sym ] = array(
                    'sym'      => $sym,
                    'total'    => 0,
                    'wins'     => 0,
                    'losses'   => 0,
                    'flats'    => 0,
                    'move_sum' => 0.0,
                    'best_pct' => 0.0,
                );
            }
            $b =& $by_sym[ $sym ];
            $b['total']++;
            if ( $r['outcome'] === 'win' )       $b['wins']++;
            elseif ( $r['outcome'] === 'loss' )  $b['losses']++;
            else                                  $b['flats']++;
            $abs = abs( $r['final_pct'] );
            $b['move_sum'] += $abs;
            if ( $abs > $b['best_pct'] ) $b['best_pct'] = $abs;
        }
        unset( $b );

        $out = array();
        foreach ( $by_sym as $b ) {
            if ( $b['total'] < $min_signals ) continue;
            $rated      = $b['wins'] + $b['losses'];
            $b['rate']  = $rated > 0 ? round( ( $b['wins'] / $rated ) * 100, 1 ) : 0.0;
            $b['avg']   = $b['total'] > 0 ? round( $b['move_sum'] / $b['total'], 2 ) : 0.0;
            $b['rated'] = $rated;
            unset( $b['move_sum'] );
            $out[] = $b;
        }

        usort( $out, function( $a, $b ) {
            return $b['total'] <=> $a['total'];
        } );

        return $out;
    }

    /**
     * 30-day rolling accuracy series — array of { date, accuracy, count }
     * per UTC day, oldest → newest. Used for the sparkline.
     */
    public static function get_daily_accuracy_series( $days = 30 ) {
        $rows = self::get_resolved_signals();
        $now  = time();
        $start = strtotime( gmdate( 'Y-m-d', $now ) ) - ( $days - 1 ) * DAY_IN_SECONDS;

        // Initialize buckets so missing days still appear with zero count.
        $buckets = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $d = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
            $buckets[ $d ] = array( 'date' => $d, 'wins' => 0, 'losses' => 0, 'flats' => 0 );
        }

        foreach ( $rows as $r ) {
            $ts = $r['verified_at'] ?: $r['first_seen'];
            if ( $ts < $start ) continue;
            $d = gmdate( 'Y-m-d', $ts );
            if ( ! isset( $buckets[ $d ] ) ) continue; // out of range
            if ( $r['outcome'] === 'win' )       $buckets[ $d ]['wins']++;
            elseif ( $r['outcome'] === 'loss' )  $buckets[ $d ]['losses']++;
            else                                  $buckets[ $d ]['flats']++;
        }

        $out = array();
        foreach ( $buckets as $b ) {
            $rated = $b['wins'] + $b['losses'];
            $out[] = array(
                'date'     => $b['date'],
                'accuracy' => $rated > 0 ? round( ( $b['wins'] / $rated ) * 100, 1 ) : null,
                'count'    => $b['wins'] + $b['losses'] + $b['flats'],
            );
        }
        return $out;
    }

    /**
     * Render an inline SVG sparkline of daily accuracy.
     * Days with 0 resolved signals are gaps (no point), not zero.
     */
    private static function render_sparkline( $series ) {
        $w = 480; $h = 60; $pad = 4;
        $points = array();
        $n = count( $series );
        if ( $n < 2 ) return '<div class="bt-perf-spark-empty">' . esc_html__( 'Not enough data yet.', 'blockticker' ) . '</div>';

        $step = ( $w - 2 * $pad ) / max( 1, $n - 1 );
        $bars = '';
        $line_pts = array();

        foreach ( $series as $i => $pt ) {
            $x = $pad + $i * $step;
            // Volume bar (height by count, capped at 8)
            $cnt    = (int) $pt['count'];
            $bar_h  = $cnt > 0 ? max( 2, min( 12, $cnt * 1.5 ) ) : 0;
            if ( $bar_h > 0 ) {
                $bars .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="2" height="%.1f" fill="rgba(0,255,102,.18)" />',
                    $x - 1, $h - $bar_h, $bar_h
                );
            }
            // Accuracy point (0..100 → bottom..top of upper band)
            if ( $pt['accuracy'] !== null ) {
                $y = $pad + ( 100 - $pt['accuracy'] ) / 100 * ( $h - 16 - $pad );
                $line_pts[] = sprintf( '%.1f,%.1f', $x, $y );
            }
        }

        $line = '';
        if ( count( $line_pts ) >= 2 ) {
            $line = '<polyline fill="none" stroke="var(--bt-accent,#00ff66)" stroke-width="1.5" stroke-linejoin="round" points="' . implode( ' ', $line_pts ) . '" />';
            // Final dot
            $last = end( $line_pts );
            list( $lx, $ly ) = explode( ',', $last );
            $line .= sprintf( '<circle cx="%s" cy="%s" r="3" fill="var(--bt-accent,#00ff66)" />', $lx, $ly );
        }

        // 50% reference line
        $ref_y = $pad + ( 100 - 50 ) / 100 * ( $h - 16 - $pad );
        $ref = sprintf(
            '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="rgba(255,255,255,.10)" stroke-dasharray="2,3" />',
            $pad, $ref_y, $w - $pad, $ref_y
        );

        return sprintf(
            '<svg class="bt-perf-spark" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">%s%s%s</svg>',
            $w, $h,
            esc_attr__( '30-day daily accuracy sparkline', 'blockticker' ),
            $bars, $ref, $line
        );
    }

    /* ================================================================
     *  Public shortcode: full dashboard
     * ================================================================ */

    public static function sc_dashboard( $atts = array() ) {
        $a = shortcode_atts( array(
            'cache' => '1',
        ), $atts, 'bt_performance_dashboard' );

        // Cache the rendered HTML to keep this page snappy under load.
        if ( $a['cache'] === '1' ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) return $cached;
        }

        $stats     = self::get_aggregate_stats();
        $by_asset  = self::get_per_asset_breakdown( 2 );
        $recent    = self::get_resolved_signals( 25 );
        $series    = self::get_daily_accuracy_series( 30 );
        $leaderbd  = method_exists( 'BT_Signal_Tracker', 'get_leaderboard' )
                   ? BT_Signal_Tracker::get_leaderboard( 3, 90 )
                   : array();

        ob_start();

        // ── Schema.org Dataset markup (E-E-A-T signal for Google) ──
        if ( $stats['total'] > 0 ) {
            $schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'Dataset',
                'name'        => 'BlockTicker Trading Signal Track Record',
                'description' => sprintf(
                    'Verified outcomes for %d trading signals over %d days. Each signal is checked against price action 48 hours after publication; wins are direction-aligned moves of 0.3%% or more.',
                    $stats['total'],
                    max( 1, $stats['days'] )
                ),
                'creator'     => array(
                    '@type' => 'Organization',
                    'name'  => get_bloginfo( 'name' ),
                    'url'   => home_url( '/' ),
                ),
                'license'     => 'https://creativecommons.org/licenses/by-nd/4.0/',
                'variableMeasured' => array(
                    array( '@type' => 'PropertyValue', 'name' => 'Accuracy',         'value' => $stats['accuracy'], 'unitText' => 'percent' ),
                    array( '@type' => 'PropertyValue', 'name' => 'Total Signals',    'value' => $stats['total'] ),
                    array( '@type' => 'PropertyValue', 'name' => 'Average Movement', 'value' => $stats['avg_move'], 'unitText' => 'percent' ),
                ),
            );
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>';
        }

        ?>
        <div class="bt-perf-wrap" data-perf-version="119.11.0">

            <!-- ── HERO STATS ─────────────────────────────────────────── -->
            <header class="bt-perf-hero">
                <div class="bt-perf-eyebrow">
                    <span class="bt-perf-eyebrow-dot">●</span>
                    <span><?php esc_html_e( 'VERIFIED TRACK RECORD', 'blockticker' ); ?></span>
                </div>
                <h1 class="bt-perf-title"><?php esc_html_e( 'Signal Performance', 'blockticker' ); ?></h1>
                <p class="bt-perf-sub">
                    <?php esc_html_e( 'Every trading signal we publish is recorded the moment it appears, then checked against the market 48 hours later. No editing, no cherry-picking — the unedited record is below.', 'blockticker' ); ?>
                </p>

                <?php if ( $stats['total'] === 0 ) : ?>
                    <div class="bt-perf-empty">
                        <div class="bt-perf-empty-icon">📡</div>
                        <h2><?php esc_html_e( 'Track record warming up', 'blockticker' ); ?></h2>
                        <p><?php esc_html_e( 'Verified signal outcomes will appear here as the 48-hour resolution windows close. Check back soon — every signal we publish is being recorded.', 'blockticker' ); ?></p>
                    </div>
                <?php else : ?>

                    <div class="bt-perf-stats">
                        <div class="bt-perf-stat bt-perf-stat-primary">
                            <div class="bt-perf-stat-lbl"><?php esc_html_e( 'Verified Accuracy', 'blockticker' ); ?></div>
                            <div class="bt-perf-stat-val">
                                <?php echo $stats['accuracy'] !== null ? esc_html( number_format( $stats['accuracy'], 1 ) ) . '%' : '—'; ?>
                            </div>
                            <div class="bt-perf-stat-meta">
                                <?php
                                printf(
                                    /* translators: %1$d wins, %2$d resolved */
                                    esc_html__( '%1$d wins / %2$d resolved', 'blockticker' ),
                                    $stats['wins'], $stats['resolved']
                                );
                                ?>
                            </div>
                        </div>
                        <div class="bt-perf-stat">
                            <div class="bt-perf-stat-lbl"><?php esc_html_e( 'Total Signals', 'blockticker' ); ?></div>
                            <div class="bt-perf-stat-val"><?php echo esc_html( number_format( $stats['total'] ) ); ?></div>
                            <div class="bt-perf-stat-meta">
                                <?php printf( esc_html__( '%d still resolving', 'blockticker' ), max( 0, $stats['total'] - $stats['resolved'] - $stats['flats'] ) ); ?>
                            </div>
                        </div>
                        <div class="bt-perf-stat">
                            <div class="bt-perf-stat-lbl"><?php esc_html_e( 'Avg. Move', 'blockticker' ); ?></div>
                            <div class="bt-perf-stat-val"><?php echo esc_html( number_format( $stats['avg_move'], 2 ) ); ?>%</div>
                            <div class="bt-perf-stat-meta"><?php esc_html_e( 'per signal, abs.', 'blockticker' ); ?></div>
                        </div>
                        <div class="bt-perf-stat">
                            <div class="bt-perf-stat-lbl"><?php esc_html_e( 'Tracking Window', 'blockticker' ); ?></div>
                            <div class="bt-perf-stat-val"><?php echo esc_html( $stats['days'] ); ?>d</div>
                            <div class="bt-perf-stat-meta">
                                <?php
                                if ( $stats['last_ts'] > 0 ) {
                                    /* translators: %s: human time diff (e.g. "2 hours") */
                                    printf( esc_html__( 'last update %s ago', 'blockticker' ), esc_html( human_time_diff( $stats['last_ts'] ) ) );
                                } else {
                                    esc_html_e( 'rolling 14d', 'blockticker' );
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sparkline -->
                    <div class="bt-perf-spark-wrap">
                        <div class="bt-perf-spark-head">
                            <span class="bt-perf-spark-title"><?php esc_html_e( 'Daily accuracy — last 30 days', 'blockticker' ); ?></span>
                            <span class="bt-perf-spark-legend">
                                <span class="bt-perf-spark-line"></span> <?php esc_html_e( 'win rate', 'blockticker' ); ?>
                                <span class="bt-perf-spark-bar"></span> <?php esc_html_e( 'volume', 'blockticker' ); ?>
                                <span class="bt-perf-spark-ref"></span> <?php esc_html_e( '50% line', 'blockticker' ); ?>
                            </span>
                        </div>
                        <?php echo self::render_sparkline( $series ); // SVG output, escaped at construction ?>
                    </div>

                <?php endif; ?>
            </header>

            <!-- ── METHODOLOGY (the trust play) ───────────────────────── -->
            <section class="bt-perf-method" id="methodology">
                <h2 class="bt-perf-h2">
                    <span class="bt-perf-h2-num">01</span>
                    <?php esc_html_e( 'How outcomes are calculated', 'blockticker' ); ?>
                </h2>
                <div class="bt-perf-method-grid">
                    <div class="bt-perf-method-card">
                        <div class="bt-perf-method-num">1.</div>
                        <h3><?php esc_html_e( 'Capture on publish', 'blockticker' ); ?></h3>
                        <p><?php esc_html_e( 'The moment a signal appears in our feed, we snapshot the asset symbol, the direction (bullish / bearish / neutral), and the live spot price.', 'blockticker' ); ?></p>
                    </div>
                    <div class="bt-perf-method-card">
                        <div class="bt-perf-method-num">2.</div>
                        <h3><?php esc_html_e( 'Wait 48 hours', 'blockticker' ); ?></h3>
                        <p><?php esc_html_e( 'A fixed 48-hour window starts. We do not move it, retry, or re-evaluate. Every signal gets the same window.', 'blockticker' ); ?></p>
                    </div>
                    <div class="bt-perf-method-card">
                        <div class="bt-perf-method-num">3.</div>
                        <h3><?php esc_html_e( 'Verify the move', 'blockticker' ); ?></h3>
                        <p><?php esc_html_e( 'When the window closes, we compare the live price to the snapshot. A signal counts as a WIN only if the price moved in the predicted direction by 0.3% or more.', 'blockticker' ); ?></p>
                    </div>
                    <div class="bt-perf-method-card">
                        <div class="bt-perf-method-num">4.</div>
                        <h3><?php esc_html_e( 'Publish — every result', 'blockticker' ); ?></h3>
                        <p><?php esc_html_e( 'Wins, losses, and flats are all written to the table below. Nothing is removed. The full rolling history is here for you to inspect.', 'blockticker' ); ?></p>
                    </div>
                </div>
                <p class="bt-perf-method-foot">
                    <strong><?php esc_html_e( 'Definitions:', 'blockticker' ); ?></strong>
                    <?php esc_html_e( 'A “win” is a 0.3%+ move in the predicted direction. A “loss” is a 0.3%+ move against the prediction. A “flat” is anything within ±0.3% — too small to count either way, so flats are excluded from the accuracy rate calculation. Win rate = wins ÷ (wins + losses).', 'blockticker' ); ?>
                </p>
            </section>

            <?php if ( ! empty( $by_asset ) ) : ?>
            <!-- ── PER-ASSET BREAKDOWN ────────────────────────────────── -->
            <section class="bt-perf-section">
                <h2 class="bt-perf-h2">
                    <span class="bt-perf-h2-num">02</span>
                    <?php esc_html_e( 'Performance by asset', 'blockticker' ); ?>
                </h2>
                <div class="bt-perf-table-wrap">
                <table class="bt-perf-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'Signals', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'W / L / Flat', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'Win rate', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'Avg. move', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'Best call', 'blockticker' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( array_slice( $by_asset, 0, 12 ) as $row ) :
                        $rate_cls = $row['rate'] >= 60 ? 'high' : ( $row['rate'] >= 45 ? 'mid' : 'low' );
                    ?>
                        <tr>
                            <td class="bt-perf-asset"><strong><?php echo esc_html( $row['sym'] ); ?></strong></td>
                            <td class="bt-perf-num"><?php echo esc_html( $row['total'] ); ?></td>
                            <td class="bt-perf-num bt-perf-wlf">
                                <span class="bt-perf-w"><?php echo esc_html( $row['wins'] ); ?></span>
                                <span class="bt-perf-sep">·</span>
                                <span class="bt-perf-l"><?php echo esc_html( $row['losses'] ); ?></span>
                                <span class="bt-perf-sep">·</span>
                                <span class="bt-perf-f"><?php echo esc_html( $row['flats'] ); ?></span>
                            </td>
                            <td class="bt-perf-num">
                                <span class="bt-perf-rate bt-perf-rate-<?php echo esc_attr( $rate_cls ); ?>">
                                    <?php echo $row['rated'] > 0 ? esc_html( number_format( $row['rate'], 1 ) ) . '%' : '—'; ?>
                                </span>
                            </td>
                            <td class="bt-perf-num"><?php echo esc_html( number_format( $row['avg'], 2 ) ); ?>%</td>
                            <td class="bt-perf-num"><?php echo esc_html( number_format( $row['best_pct'], 2 ) ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ( count( $by_asset ) > 12 ) : ?>
                <p class="bt-perf-foot-note">
                    <?php
                    /* translators: %d: count */
                    printf( esc_html__( 'Showing top 12 of %d tracked assets (min. 2 signals).', 'blockticker' ), count( $by_asset ) );
                    ?>
                </p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ( ! empty( $recent ) ) : ?>
            <!-- ── RECENT VERIFIED SIGNALS ────────────────────────────── -->
            <section class="bt-perf-section">
                <h2 class="bt-perf-h2">
                    <span class="bt-perf-h2-num">03</span>
                    <?php esc_html_e( 'Recent verified signals', 'blockticker' ); ?>
                </h2>
                <div class="bt-perf-table-wrap">
                <table class="bt-perf-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Verified', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Direction', 'blockticker' ); ?></th>
                            <th class="bt-perf-num"><?php esc_html_e( 'Move', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Outcome', 'blockticker' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $r ) :
                        $oc = $r['outcome'];
                        $dir_lbl = $r['dir'] === 'bull' ? __( '🐂 Bullish', 'blockticker' )
                                 : ( $r['dir'] === 'bear' ? __( '🐻 Bearish', 'blockticker' ) : __( '◻ Neutral', 'blockticker' ) );
                        $oc_lbl = $oc === 'win'  ? __( '✓ Win', 'blockticker' )
                                : ( $oc === 'loss' ? __( '✗ Loss', 'blockticker' ) : __( '— Flat', 'blockticker' ) );
                        $pct_sign = $r['final_pct'] >= 0 ? '+' : '';
                        $pct_cls  = $r['final_pct'] > 0 ? 'pos' : ( $r['final_pct'] < 0 ? 'neg' : 'flat' );
                    ?>
                        <tr class="bt-perf-row-<?php echo esc_attr( $oc ); ?>">
                            <td class="bt-perf-time">
                                <time datetime="<?php echo esc_attr( gmdate( 'c', $r['verified_at'] ) ); ?>">
                                    <?php echo esc_html( gmdate( 'M j, Y', $r['verified_at'] ) ); ?>
                                </time>
                            </td>
                            <td class="bt-perf-asset"><strong><?php echo esc_html( $r['sym'] ); ?></strong></td>
                            <td class="bt-perf-dir bt-perf-dir-<?php echo esc_attr( $r['dir'] ); ?>"><?php echo esc_html( $dir_lbl ); ?></td>
                            <td class="bt-perf-num bt-perf-pct bt-perf-pct-<?php echo esc_attr( $pct_cls ); ?>">
                                <?php echo esc_html( $pct_sign . number_format( $r['final_pct'], 2 ) ); ?>%
                            </td>
                            <td><span class="bt-perf-outcome bt-perf-outcome-<?php echo esc_attr( $oc ); ?>"><?php echo esc_html( $oc_lbl ); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="bt-perf-foot-note">
                    <?php esc_html_e( 'Showing the last 25 verified signals. Older entries roll off as the 14-day window slides forward.', 'blockticker' ); ?>
                </p>
            </section>
            <?php endif; ?>

            <?php if ( ! empty( $leaderbd ) ) : ?>
            <!-- ── SOURCE LEADERBOARD ─────────────────────────────────── -->
            <section class="bt-perf-section">
                <h2 class="bt-perf-h2">
                    <span class="bt-perf-h2-num">04</span>
                    <?php esc_html_e( 'Performance by source', 'blockticker' ); ?>
                </h2>
                <?php echo do_shortcode( '[bt_signal_leaderboard min_signals="3" window_days="90" max_rows="8"]' ); ?>
            </section>
            <?php endif; ?>

            <!-- ── DISCLAIMER (always last, prominent) ────────────────── -->
            <aside class="bt-perf-disclaimer">
                <strong><?php esc_html_e( 'Not investment advice.', 'blockticker' ); ?></strong>
                <?php esc_html_e( 'Past performance does not guarantee future results. The data on this page is published transparently for accountability — not as a recommendation to buy, sell, or hold any asset. Always do your own research and consider consulting a licensed advisor.', 'blockticker' ); ?>
            </aside>

        </div>
        <?php

        $html = ob_get_clean();
        if ( $a['cache'] === '1' ) set_transient( self::CACHE_KEY, $html, self::CACHE_TTL );
        return $html;
    }

    /* ================================================================
     *  Public shortcode: compact homepage embed
     * ================================================================ */

    public static function sc_summary_card( $atts = array() ) {
        $a = shortcode_atts( array(
            'cta'      => '/performance/',
            'cta_text' => __( 'See the full track record →', 'blockticker' ),
        ), $atts, 'bt_performance_summary_card' );

        $stats = self::get_aggregate_stats();
        if ( $stats['total'] === 0 ) {
            return '<div class="bt-perf-card-empty">' . esc_html__( 'Track record warming up — first verified results will appear here shortly.', 'blockticker' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="bt-perf-card">
            <div class="bt-perf-card-head">
                <span class="bt-perf-card-eyebrow"><?php esc_html_e( 'VERIFIED · UPDATED LIVE', 'blockticker' ); ?></span>
                <span class="bt-perf-card-title"><?php esc_html_e( 'Signal Track Record', 'blockticker' ); ?></span>
            </div>
            <div class="bt-perf-card-stats">
                <div class="bt-perf-card-stat">
                    <div class="bt-perf-card-val">
                        <?php echo $stats['accuracy'] !== null ? esc_html( number_format( $stats['accuracy'], 1 ) ) . '%' : '—'; ?>
                    </div>
                    <div class="bt-perf-card-lbl"><?php esc_html_e( 'Accuracy', 'blockticker' ); ?></div>
                </div>
                <div class="bt-perf-card-stat">
                    <div class="bt-perf-card-val"><?php echo esc_html( number_format( $stats['total'] ) ); ?></div>
                    <div class="bt-perf-card-lbl"><?php esc_html_e( 'Signals', 'blockticker' ); ?></div>
                </div>
                <div class="bt-perf-card-stat">
                    <div class="bt-perf-card-val"><?php echo esc_html( number_format( $stats['avg_move'], 2 ) ); ?>%</div>
                    <div class="bt-perf-card-lbl"><?php esc_html_e( 'Avg. move', 'blockticker' ); ?></div>
                </div>
                <div class="bt-perf-card-stat">
                    <div class="bt-perf-card-val"><?php echo esc_html( $stats['days'] ); ?>d</div>
                    <div class="bt-perf-card-lbl"><?php esc_html_e( 'Tracked', 'blockticker' ); ?></div>
                </div>
            </div>
            <a href="<?php echo esc_url( $a['cta'] ); ?>" class="bt-perf-card-cta"><?php echo esc_html( $a['cta_text'] ); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  Admin overview
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Performance', 'blockticker' ),
            __( 'Performance', 'blockticker' ),
            'manage_options',
            'bt-performance-overview',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function admin_handle_recompute() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_perf_recompute' );

        // Force the tracker tick to run; safe to invoke directly.
        if ( method_exists( 'BT_Signal_Tracker', 'cron_signal_tracker_tick' ) ) {
            BT_Signal_Tracker::cron_signal_tracker_tick();
        }
        self::bust_cache();

        wp_safe_redirect( add_query_arg( array(
            'page'      => 'bt-performance-overview',
            'recomputed' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $stats    = self::get_aggregate_stats();
        $by_asset = self::get_per_asset_breakdown( 1 );
        $recent   = self::get_resolved_signals( 10 );
        $public_url = home_url( '/performance/' );
        $recomputed = ! empty( $_GET['recomputed'] );
        ?>
        <div class="wrap bt-perf-admin">
            <h1><?php esc_html_e( 'BlockTicker — Performance', 'blockticker' ); ?></h1>

            <?php if ( $recomputed ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Outcomes recomputed and dashboard cache cleared.', 'blockticker' ); ?></p></div>
            <?php endif; ?>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: public URL */
                    wp_kses_post( __( 'Operator overview of the verified signal track record. The public-facing page is at <a href="%1$s" target="_blank" rel="noopener">%1$s</a>.', 'blockticker' ) ),
                    esc_url( $public_url )
                );
                ?>
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;max-width:900px;margin:18px 0 24px">
                <div class="card" style="padding:14px">
                    <div style="font-size:11px;text-transform:uppercase;color:#646970;letter-spacing:.6px"><?php esc_html_e( 'Accuracy', 'blockticker' ); ?></div>
                    <div style="font-size:28px;font-weight:700;margin-top:4px">
                        <?php echo $stats['accuracy'] !== null ? esc_html( number_format( $stats['accuracy'], 1 ) ) . '%' : '—'; ?>
                    </div>
                    <div style="font-size:12px;color:#646970"><?php printf( esc_html__( '%1$d wins / %2$d resolved', 'blockticker' ), $stats['wins'], $stats['resolved'] ); ?></div>
                </div>
                <div class="card" style="padding:14px">
                    <div style="font-size:11px;text-transform:uppercase;color:#646970;letter-spacing:.6px"><?php esc_html_e( 'Total signals', 'blockticker' ); ?></div>
                    <div style="font-size:28px;font-weight:700;margin-top:4px"><?php echo esc_html( number_format( $stats['total'] ) ); ?></div>
                    <div style="font-size:12px;color:#646970">
                        <?php printf( esc_html__( '%d flat (excl. from rate)', 'blockticker' ), $stats['flats'] ); ?>
                    </div>
                </div>
                <div class="card" style="padding:14px">
                    <div style="font-size:11px;text-transform:uppercase;color:#646970;letter-spacing:.6px"><?php esc_html_e( 'Avg. move', 'blockticker' ); ?></div>
                    <div style="font-size:28px;font-weight:700;margin-top:4px"><?php echo esc_html( number_format( $stats['avg_move'], 2 ) ); ?>%</div>
                    <div style="font-size:12px;color:#646970"><?php esc_html_e( 'absolute, per signal', 'blockticker' ); ?></div>
                </div>
                <div class="card" style="padding:14px">
                    <div style="font-size:11px;text-transform:uppercase;color:#646970;letter-spacing:.6px"><?php esc_html_e( 'Window', 'blockticker' ); ?></div>
                    <div style="font-size:28px;font-weight:700;margin-top:4px"><?php echo esc_html( $stats['days'] ); ?>d</div>
                    <div style="font-size:12px;color:#646970">
                        <?php
                        if ( $stats['last_ts'] > 0 ) {
                            printf( esc_html__( 'last update %s ago', 'blockticker' ), esc_html( human_time_diff( $stats['last_ts'] ) ) );
                        } else {
                            esc_html_e( 'no resolutions yet', 'blockticker' );
                        }
                        ?>
                    </div>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
                <input type="hidden" name="action" value="bt_perf_recompute">
                <?php wp_nonce_field( 'bt_perf_recompute' ); ?>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Recompute outcomes &amp; clear cache', 'blockticker' ); ?>
                </button>
                <span class="description" style="margin-left:8px">
                    <?php esc_html_e( 'Runs the tracker tick immediately, then busts the dashboard cache.', 'blockticker' ); ?>
                </span>
            </form>

            <h2><?php esc_html_e( 'Per-asset breakdown', 'blockticker' ); ?></h2>
            <?php if ( empty( $by_asset ) ) : ?>
                <p><?php esc_html_e( 'No verified signals yet.', 'blockticker' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px;margin-bottom:24px">
                    <thead><tr>
                        <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Wins', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Losses', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Flats', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Win rate', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Avg. move', 'blockticker' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $by_asset as $row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $row['sym'] ); ?></strong></td>
                            <td><?php echo esc_html( $row['total'] ); ?></td>
                            <td><?php echo esc_html( $row['wins'] ); ?></td>
                            <td><?php echo esc_html( $row['losses'] ); ?></td>
                            <td><?php echo esc_html( $row['flats'] ); ?></td>
                            <td><?php echo $row['rated'] > 0 ? esc_html( number_format( $row['rate'], 1 ) ) . '%' : '—'; ?></td>
                            <td><?php echo esc_html( number_format( $row['avg'], 2 ) ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Recent verified signals', 'blockticker' ); ?></h2>
            <?php if ( empty( $recent ) ) : ?>
                <p><?php esc_html_e( 'No verified signals yet.', 'blockticker' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr>
                        <th><?php esc_html_e( 'Verified', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Symbol', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Direction', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Move %', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Outcome', 'blockticker' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $recent as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i', $r['verified_at'] ) ); ?> UTC</td>
                            <td><?php echo esc_html( $r['sym'] ); ?></td>
                            <td><?php echo esc_html( $r['dir'] ); ?></td>
                            <td><?php echo esc_html( number_format( $r['final_pct'], 2 ) ); ?>%</td>
                            <td><strong><?php echo esc_html( strtoupper( $r['outcome'] ) ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

BT_Performance::setup();
