<?php
/**
 * BT_Correlation — Price Correlation Heatmap.
 *
 * Reads daily closing prices from wp_bt_price_history, computes Pearson
 * correlation coefficients between every pair of assets, and renders a
 * colour-coded square matrix as inline HTML/CSS.
 *
 * No external JS library required — the heatmap is pure PHP/HTML/CSS.
 * Computation is cached as a transient to avoid repeated DB queries.
 *
 * Shortcode:
 *   [bt_correlation_heatmap symbols="BTC,ETH,SOL,EUR/USD,GBP/USD" days="30"
 *                            title="Price Correlation (30d)" colorscale="rg"]
 *
 * REST endpoint:
 *   GET /wp-json/blockticker/v1/correlation?symbols=BTC,ETH&days=30
 *
 * @package BlockTicker
 * @since   106.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Correlation {

    /** Default asset list if none supplied. */
    const DEFAULT_SYMBOLS = array( 'BTC', 'ETH', 'SOL', 'EUR/USD', 'GBP/USD', 'XAU' );

    /** Maximum number of symbols allowed (matrix grows as n²). */
    const MAX_SYMBOLS = 12;

    /** Transient TTL — 6 hours, recomputed after price refresh. */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_shortcode( 'bt_correlation_heatmap', array( __CLASS__, 'sc_heatmap' ) );
        add_action( 'rest_api_init',             array( __CLASS__, 'register_rest_route' ) );
        // Bust cache when prices refresh.
        add_action( 'bt_refresh_prices', array( __CLASS__, 'bust_cache' ), 99 );
    }

    /* ------------------------------------------------------------------
     * Core: fetch + compute
     * ------------------------------------------------------------------ */

    /**
     * Build the full correlation matrix for the given symbols and window.
     *
     * @param  string[] $symbols  e.g. ['BTC','ETH','EUR/USD']
     * @param  int      $days     Look-back window in days.
     * @return array {
     *     symbols: string[],
     *     matrix:  float[][],   // n × n, 1.0 on diagonal
     *     dates:   int,         // number of daily closes used
     *     warning: string|null  // set when a symbol has insufficient data
     * }
     */
    public static function compute( array $symbols, int $days = 30 ): array {
        $cache_key = 'bt_corr_' . md5( implode( ',', $symbols ) . '_' . $days );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        // Fetch daily price series per symbol.
        $series   = array();
        $warnings = array();
        foreach ( $symbols as $sym ) {
            $rows = BT_Database::get_price_history( $sym, $days, '1d' );
            if ( count( $rows ) < 5 ) {
                $warnings[] = $sym . ' (insufficient data)';
                $series[ $sym ] = array();
                continue;
            }
            // Index by date string so we can align across symbols.
            $keyed = array();
            foreach ( $rows as $r ) {
                $date          = substr( $r['captured_at'], 0, 10 );
                $keyed[ $date ] = (float) $r['price_usd'];
            }
            $series[ $sym ] = $keyed;
        }

        // Find the common dates across all symbols with data.
        $date_sets = array_filter( array_map( 'array_keys', $series ) );
        if ( empty( $date_sets ) ) {
            return array( 'symbols' => $symbols, 'matrix' => array(), 'dates' => 0, 'warning' => 'No price data found.' );
        }
        $common_dates = array_values( array_intersect( ...$date_sets ) );
        sort( $common_dates );
        $n_dates = count( $common_dates );

        // Build aligned return vectors (log-return: ln(P_t / P_{t-1})).
        $returns = array();
        foreach ( $symbols as $sym ) {
            $s = $series[ $sym ];
            $r = array();
            for ( $i = 1; $i < $n_dates; $i++ ) {
                $prev = $s[ $common_dates[ $i - 1 ] ] ?? null;
                $curr = $s[ $common_dates[ $i ] ] ?? null;
                if ( $prev === null || $curr === null || $prev <= 0 ) {
                    $r[] = 0.0;
                } else {
                    $r[] = log( $curr / $prev );
                }
            }
            $returns[ $sym ] = $r;
        }

        // Compute Pearson correlation for every pair.
        $n      = count( $symbols );
        $matrix = array_fill( 0, $n, array_fill( 0, $n, null ) );

        for ( $i = 0; $i < $n; $i++ ) {
            $matrix[ $i ][ $i ] = 1.0; // diagonal
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $r = self::pearson( $returns[ $symbols[ $i ] ], $returns[ $symbols[ $j ] ] );
                $matrix[ $i ][ $j ] = $r;
                $matrix[ $j ][ $i ] = $r; // symmetric
            }
        }

        $result = array(
            'symbols' => $symbols,
            'matrix'  => $matrix,
            'dates'   => $n_dates,
            'warning' => $warnings ? implode( ', ', $warnings ) : null,
        );

        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    /**
     * Pearson correlation coefficient between two equal-length float arrays.
     * Returns null when std-dev is zero (constant series).
     */
    private static function pearson( array $x, array $y ): ?float {
        $n = min( count( $x ), count( $y ) );
        if ( $n < 2 ) return null;

        $x = array_slice( $x, 0, $n );
        $y = array_slice( $y, 0, $n );

        $mx = array_sum( $x ) / $n;
        $my = array_sum( $y ) / $n;

        $num = 0.0;
        $dx2 = 0.0;
        $dy2 = 0.0;

        for ( $i = 0; $i < $n; $i++ ) {
            $dx   = $x[ $i ] - $mx;
            $dy   = $y[ $i ] - $my;
            $num += $dx * $dy;
            $dx2 += $dx * $dx;
            $dy2 += $dy * $dy;
        }

        $denom = sqrt( $dx2 * $dy2 );
        if ( $denom < 1e-10 ) return null;

        return round( $num / $denom, 3 );
    }

    public static function bust_cache() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bt_corr_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bt_corr_%'" );
    }

    /* ------------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------------ */

    /**
     * [bt_correlation_heatmap]
     *
     * Attributes:
     *   symbols     Comma-separated list (default: BTC,ETH,SOL,EUR/USD,GBP/USD,XAU)
     *   days        Look-back window 7–365 (default 30)
     *   title       Card heading
     *   colorscale  "rg" (red→grey→green, default) or "bwr" (blue→white→red)
     *   show_values 0|1 — show numeric coefficient in each cell (default 1)
     *   min_r       Float — grey out cells below this absolute value (default 0)
     */
    public static function sc_heatmap( $atts ) {
        $a = shortcode_atts( array(
            'symbols'     => implode( ',', self::DEFAULT_SYMBOLS ),
            'days'        => 30,
            'title'       => 'Price Correlation Heatmap',
            'colorscale'  => 'rg',
            'show_values' => 1,
            'min_r'       => 0,
        ), $atts );

        $raw_syms = array_map( 'strtoupper', array_map( 'trim', explode( ',', $a['symbols'] ) ) );
        $symbols  = array_values( array_unique( array_filter( $raw_syms ) ) );
        $symbols  = array_slice( $symbols, 0, self::MAX_SYMBOLS );
        $days     = max( 7, min( 365, intval( $a['days'] ) ) );
        $show_val = (bool) intval( $a['show_values'] );
        $min_r    = floatval( $a['min_r'] );
        $cs       = $a['colorscale'] === 'bwr' ? 'bwr' : 'rg';

        if ( count( $symbols ) < 2 ) {
            return '<p class="bt-corr-error">' . esc_html__( 'Please supply at least 2 symbols.', 'blockticker' ) . '</p>';
        }

        $data = self::compute( $symbols, $days );
        $n    = count( $data['symbols'] );

        if ( empty( $data['matrix'] ) || $n < 2 ) {
            return '<div class="bt-corr-empty"><p>📊 ' . esc_html__( 'Not enough price history yet — heatmap will appear once data accumulates.', 'blockticker' ) . '</p></div>';
        }

        ob_start(); ?>
        <div class="bt-corr-wrap">
            <?php if ( $a['title'] ) : ?>
            <div class="bt-corr-header">
                <h3 class="bt-corr-title">📊 <?php echo esc_html( $a['title'] ); ?></h3>
                <span class="bt-corr-meta">
                    <?php printf(
                        /* translators: 1: days window, 2: number of daily closes */
                        esc_html__( '%1$d-day window · %2$d daily closes', 'blockticker' ),
                        esc_html( $days ),
                        esc_html( $data['dates'] )
                    ); ?>
                </span>
            </div>
            <?php endif;

            if ( $data['warning'] ) : ?>
            <p class="bt-corr-warning">⚠️ <?php echo esc_html( $data['warning'] ); ?></p>
            <?php endif; ?>

            <div class="bt-corr-matrix-wrap">
                <table class="bt-corr-matrix" aria-label="<?php echo esc_attr( $a['title'] ); ?>">
                    <thead>
                        <tr>
                            <th class="bt-corr-th-empty" aria-hidden="true"></th>
                            <?php foreach ( $data['symbols'] as $sym ) : ?>
                            <th class="bt-corr-col-label" scope="col"><?php echo esc_html( $sym ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ( $i = 0; $i < $n; $i++ ) : ?>
                        <tr>
                            <th class="bt-corr-row-label" scope="row"><?php echo esc_html( $data['symbols'][ $i ] ); ?></th>
                            <?php for ( $j = 0; $j < $n; $j++ ) :
                                $r    = $data['matrix'][ $i ][ $j ];
                                $bg   = self::r_to_css( $r, $cs, $min_r );
                                $text = self::r_to_text_colour( $r );
                                $label = $r !== null ? ( $i === $j ? '1.0' : number_format( $r, 2 ) ) : 'N/A';
                                $title_attr = $i === $j
                                    ? esc_attr( $data['symbols'][ $i ] . ' vs ' . $data['symbols'][ $i ] )
                                    : esc_attr( $data['symbols'][ $i ] . ' vs ' . $data['symbols'][ $j ] . ': ' . $label );
                            ?>
                            <td class="bt-corr-cell<?php echo $i === $j ? ' bt-corr-diag' : ''; ?>"
                                style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $text ); ?>"
                                title="<?php echo $title_attr; ?>"
                                aria-label="<?php echo $title_attr; ?>">
                                <?php if ( $show_val ) : ?>
                                <span class="bt-corr-val"><?php echo esc_html( $label ); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="bt-corr-legend">
                <div class="bt-corr-legend-bar" style="background:<?php echo esc_attr( self::legend_gradient( $cs ) ); ?>"></div>
                <div class="bt-corr-legend-labels">
                    <span>–1.0</span><span>0</span><span>+1.0</span>
                </div>
                <div class="bt-corr-legend-text">
                    <?php
                    if ( $cs === 'bwr' ) {
                        esc_html_e( 'Blue = inversely correlated · Red = strongly correlated', 'blockticker' );
                    } else {
                        esc_html_e( 'Red = inversely correlated · Green = strongly correlated', 'blockticker' );
                    }
                    ?>
                </div>
            </div>

            <p class="bt-corr-note">
                <?php esc_html_e( 'Pearson correlation of daily log-returns. 1.0 = perfect co-movement, –1.0 = perfect inverse, 0 = no linear relationship.', 'blockticker' ); ?>
            </p>
        </div>

        <style>
        .bt-corr-wrap{font-family:inherit;margin:20px 0}
        .bt-corr-header{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px}
        .bt-corr-title{font-size:16px;font-weight:700;margin:0}
        .bt-corr-meta{font-size:12px;color:#888}
        .bt-corr-warning{font-size:12px;color:#e6972b;margin:0 0 8px}
        .bt-corr-matrix-wrap{overflow-x:auto}
        .bt-corr-matrix{border-collapse:collapse;table-layout:fixed}
        .bt-corr-th-empty{width:72px;min-width:72px}
        .bt-corr-col-label,.bt-corr-row-label{font-size:11px;font-weight:700;color:#888;text-align:center;padding:4px 6px;white-space:nowrap;max-width:72px;overflow:hidden;text-overflow:ellipsis}
        .bt-corr-row-label{text-align:right;min-width:72px}
        .bt-corr-cell{width:52px;height:52px;text-align:center;vertical-align:middle;border:1px solid rgba(0,0,0,.08);transition:opacity .15s;cursor:default}
        .bt-corr-cell:hover{opacity:.85;outline:2px solid rgba(255,255,255,.4);outline-offset:-1px;position:relative;z-index:1}
        .bt-corr-diag{opacity:.9}
        .bt-corr-val{font-size:11px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1;display:block}
        .bt-corr-legend{margin:14px 0 0}
        .bt-corr-legend-bar{height:12px;border-radius:0;margin-bottom:4px}
        .bt-corr-legend-labels{display:flex;justify-content:space-between;font-size:11px;color:#888;margin-bottom:3px}
        .bt-corr-legend-text{font-size:11px;color:#888;text-align:center}
        .bt-corr-note{font-size:11px;color:#888;margin:10px 0 0;font-style:italic}
        .bt-corr-empty,.bt-corr-error{padding:24px;text-align:center;background:rgba(255,255,255,.03);border-radius:0;color:#888}
        @media(max-width:480px){
            .bt-corr-cell{width:40px;height:40px}
            .bt-corr-val{font-size:9px}
            .bt-corr-th-empty,.bt-corr-row-label{min-width:52px;max-width:52px}
        }
        </style>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * REST endpoint
     * ------------------------------------------------------------------ */

    public static function register_rest_route() {
        register_rest_route( 'blockticker/v1', '/correlation', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_correlation' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_correlation( WP_REST_Request $request ) {
        $raw = $request->get_param( 'symbols' ) ?: implode( ',', self::DEFAULT_SYMBOLS );
        $symbols = array_values( array_unique( array_filter(
            array_map( 'strtoupper', array_map( 'trim', explode( ',', $raw ) ) )
        ) ) );
        $symbols = array_slice( $symbols, 0, self::MAX_SYMBOLS );
        $days    = max( 7, min( 365, intval( $request->get_param( 'days' ) ?: 30 ) ) );

        if ( count( $symbols ) < 2 ) {
            return new WP_REST_Response( array( 'error' => 'Supply at least 2 symbols.' ), 400 );
        }

        $data = self::compute( $symbols, $days );
        return new WP_REST_Response( array(
            'data'   => $data,
            'meta'   => array( 'generated_at' => gmdate( 'c' ), 'days' => $days ),
            'source' => 'BlockTicker price correlation (Pearson, daily log-returns)',
        ), 200 );
    }

    /* ------------------------------------------------------------------
     * Colour helpers
     * ------------------------------------------------------------------ */

    /**
     * Map a correlation coefficient to a CSS background colour.
     *
     * @param float|null $r           Correlation value.
     * @param string     $colorscale  'rg' (red→grey→green) or 'bwr' (blue→white→red).
     * @param float      $min_r       Threshold below which cells are rendered grey.
     */
    private static function r_to_css( ?float $r, string $colorscale, float $min_r ): string {
        if ( $r === null ) return 'rgba(128,128,128,.15)';

        // Apply minimum threshold — grey out weak correlations.
        if ( abs( $r ) < $min_r ) return 'rgba(128,128,128,.18)';

        $t = ( $r + 1.0 ) / 2.0; // 0 → 1

        if ( $colorscale === 'bwr' ) {
            // Blue (r=-1) → White (r=0) → Red (r=+1)
            if ( $r < 0 ) {
                $intensity = (int) round( ( 1.0 + $r ) * 255 );
                return "rgb({$intensity},{$intensity},255)";
            } elseif ( $r > 0 ) {
                $intensity = (int) round( ( 1.0 - $r ) * 255 );
                return "rgb(255,{$intensity},{$intensity})";
            }
            return '#ffffff';
        }

        // Default: red→grey→green
        if ( $r < 0 ) {
            // red: opacity scales with |r|
            $alpha = round( abs( $r ) * 0.75 + 0.12, 2 );
            return "rgba(214,54,56,{$alpha})";
        } elseif ( $r > 0 ) {
            // green
            $alpha = round( $r * 0.75 + 0.12, 2 );
            return "rgba(0,163,42,{$alpha})";
        }
        return 'rgba(128,128,128,.18)';
    }

    /**
     * Choose black or white text so it reads on the cell background.
     */
    private static function r_to_text_colour( ?float $r ): string {
        if ( $r === null ) return '#888';
        // Strong correlations (coloured backgrounds) → white; weak → grey.
        return abs( $r ) > 0.5 ? '#fff' : '#aaa';
    }

    private static function legend_gradient( string $cs ): string {
        if ( $cs === 'bwr' ) {
            return 'linear-gradient(to right,#0000ff,#ffffff,#ff0000)';
        }
        return 'linear-gradient(to right,rgba(214,54,56,.87),rgba(128,128,128,.25),rgba(0,163,42,.87))';
    }
}
