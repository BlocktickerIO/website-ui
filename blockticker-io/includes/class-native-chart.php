<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_NativeChart
 *
 * Provides the [bt_price_chart] shortcode — a proprietary candlestick /
 * area chart built on TradingView's open-source lightweight-charts library
 * (MIT licence, ~45 KB gzipped). No TradingView embed iframe needed.
 *
 * Chart types available via shortcode attribute:
 *   area        — Smooth area chart with gradient fill (default)
 *   line        — Plain price line, no fill
 *   signal      — Area chart with signal-outcome arrow markers
 *
 * Data source:
 *   Fetches from GET /wp-json/blockticker/v1/history/{symbol}?days=N&resolution=R
 *   If fewer than BT_CHART_MIN_ROWS rows exist (fresh install), falls back to
 *   TradingView embed and shows a "Building history…" notice.
 *
 * Shortcode attributes:
 *   symbol      BTC | ETH | EUR/USD | etc.          default: BTC
 *   type        area | line | signal                 default: area
 *   days        1–365                                default: 30
 *   resolution  5m | 1h | 1d                         default: 1h
 *   height      px integer                           default: 380
 *   theme       dark | light | auto                  default: auto
 *   show_volume 1 | 0                                default: 1
 *   title       string                               default: '' (auto-generated)
 *   tv_symbol   TradingView fallback symbol          default: auto-mapped
 *   show_ranges 1 | 0  — show 1D/7D/30D/90D buttons default: 1
 *
 * Added in v96.4 — additive, no breaking changes.
 */
class BT_NativeChart {

    /**
     * Minimum rows required before we show the native chart instead of
     * the TradingView fallback.
     */
    const MIN_ROWS = 10;

    /**
     * CDN URL for lightweight-charts (MIT, TradingView OSS).
     * Pinned to 4.1 — breaking changes in 5.x require a separate upgrade.
     */
    const LW_CDN = 'https://cdn.jsdelivr.net/npm/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js';

    // ── CoinGecko / TradingView symbol maps ───────────────────────────────
    private static $tv_symbol_map = array(
        'BTC'  => 'BINANCE:BTCUSDT',
        'ETH'  => 'BINANCE:ETHUSDT',
        'BNB'  => 'BINANCE:BNBUSDT',
        'SOL'  => 'BINANCE:SOLUSDT',
        'XRP'  => 'BINANCE:XRPUSDT',
        'ADA'  => 'BINANCE:ADAUSDT',
        'DOGE' => 'BINANCE:DOGEUSDT',
        'AVAX' => 'BINANCE:AVAXUSDT',
        'LINK' => 'BINANCE:LINKUSDT',
        'DOT'  => 'BINANCE:DOTUSDT',
        'MATIC'=> 'BINANCE:MATICUSDT',
        'LTC'  => 'BINANCE:LTCUSDT',
        'SHIB' => 'BINANCE:SHIBUSDT',
        // Forex
        'EUR/USD' => 'FX:EURUSD',
        'GBP/USD' => 'FX:GBPUSD',
        'USD/JPY' => 'FX:USDJPY',
        'USD/CHF' => 'FX:USDCHF',
        'AUD/USD' => 'FX:AUDUSD',
        'USD/CAD' => 'FX:USDCAD',
        'NZD/USD' => 'FX:NZDUSD',
        'EUR/GBP' => 'FX:EURGBP',
        'EUR/JPY' => 'FX:EURJPY',
        'GBP/JPY' => 'FX:GBPJPY',
    );

    // -------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------
    public static function init() {
        add_shortcode( 'bt_price_chart', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
        // REST: optimised chart-data endpoint (adds ?signals=1 support on top
        // of the generic /history endpoint already registered in class-api.php)
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
    }

    // -------------------------------------------------------------------
    // Asset enqueueing — registered always, enqueued lazily by shortcode
    // -------------------------------------------------------------------
    public static function maybe_enqueue() {
        wp_register_script(
            'bt-lightweight-charts',
            self::LW_CDN,
            array(),
            '4.1.3',
            true
        );
    }

    // -------------------------------------------------------------------
    // REST endpoint  GET /wp-json/blockticker/v1/chart/{symbol}
    //
    // Extended version of /history that optionally appends signal markers.
    // -------------------------------------------------------------------
    public static function register_rest() {
        register_rest_route( 'blockticker/v1', '/chart/(?P<symbol>[A-Za-z0-9\-\/]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_chart_data' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'days'       => array( 'default' => 30,   'sanitize_callback' => 'absint' ),
                'resolution' => array( 'default' => '1h', 'sanitize_callback' => 'sanitize_text_field' ),
                'signals'    => array( 'default' => '0',  'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    public static function rest_chart_data( $request ) {
        if ( ! class_exists( 'BT_Database' ) ) {
            return new WP_Error( 'bt_not_ready', 'History storage not initialised.', array( 'status' => 503 ) );
        }

        $symbol     = strtoupper( sanitize_text_field( $request->get_param( 'symbol' ) ) );
        $days       = min( 365, max( 1, (int) $request->get_param( 'days' ) ) );
        $resolution = in_array( $request->get_param( 'resolution' ), array( '5m', '1h', '1d' ), true )
            ? $request->get_param( 'resolution' ) : '1h';
        $with_signals = ( $request->get_param( 'signals' ) === '1' );

        $rows    = BT_Database::get_price_history( $symbol, $days, $resolution );
        $price_series = array();
        $volume_series = array();

        foreach ( $rows as $r ) {
            $ts = isset( $r['captured_at'] ) ? strtotime( $r['captured_at'] . ' UTC' ) : 0;
            if ( ! $ts ) continue;
            $price    = round( (float) ( $r['price_usd'] ?? 0 ), 8 );
            $vol      = round( (float) ( $r['volume_24h'] ?? 0 ), 2 );
            $price_series[]  = array( 'time' => $ts, 'value' => $price );
            $volume_series[] = array( 'time' => $ts, 'value' => $vol );
        }

        $signals = array();
        if ( $with_signals && class_exists( 'BT_Database' ) ) {
            $signals = self::get_signal_markers( $symbol, $days );
        }

        $response = array(
            'symbol'       => $symbol,
            'days'         => $days,
            'resolution'   => $resolution,
            'count'        => count( $price_series ),
            'price_series' => $price_series,
            'volume_series'=> $volume_series,
            'signals'      => $signals,
        );

        $http_response = new WP_REST_Response( array(
            'data'   => $response,
            'meta'   => array( 'generated_at' => gmdate( 'c' ) ),
            'source' => 'BlockTicker native chart data',
        ), 200 );
        $http_response->header( 'Cache-Control', 'public, max-age=120' );
        $http_response->header( 'Access-Control-Allow-Origin', '*' );
        return $http_response;
    }

    /**
     * Pull signal markers for a symbol within the last N days.
     * Returns lightweight-charts marker objects.
     */
    private static function get_signal_markers( $symbol, $days ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $table  = $wpdb->prefix . 'bt_signals_history';

        // Check table exists first
        if ( ! $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ) ) ) {
            return array();
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT direction, signaled_at, source_name, outcome, entry_price
             FROM `{$table}`
             WHERE symbol = %s AND signaled_at >= %s
             ORDER BY signaled_at ASC
             LIMIT 200",
            $symbol, $cutoff
        ), ARRAY_A );

        $markers = array();
        foreach ( (array) $rows as $r ) {
            $ts        = strtotime( $r['signaled_at'] . ' UTC' );
            $direction = strtoupper( $r['direction'] ?? 'NEUTRAL' );
            $outcome   = strtoupper( $r['outcome'] ?? 'OPEN' );

            // Color encodes outcome: green = win, red = loss, gray = open/unknown
            $color = '#888888';
            if ( $outcome === 'WIN' )  $color = '#26a69a';
            if ( $outcome === 'LOSS' ) $color = '#ef5350';

            $shape    = ( $direction === 'LONG' ) ? 'arrowUp' : 'arrowDown';
            $position = ( $direction === 'LONG' ) ? 'belowBar' : 'aboveBar';

            $markers[] = array(
                'time'     => $ts,
                'position' => $position,
                'color'    => $color,
                'shape'    => $shape,
                'text'     => $direction . ( $outcome !== 'OPEN' ? ' · ' . $outcome : '' ),
            );
        }

        return $markers;
    }

    // -------------------------------------------------------------------
    // Shortcode renderer
    // -------------------------------------------------------------------
    public static function shortcode( $atts ) {
        $a = shortcode_atts( array(
            'symbol'      => 'BTC',
            'type'        => 'area',
            'days'        => '30',
            'resolution'  => '1h',
            'height'      => '380',
            'theme'       => 'auto',
            'show_volume' => '1',
            'title'       => '',
            'tv_symbol'   => '',
            'show_ranges' => '1',
        ), $atts );

        $symbol      = strtoupper( sanitize_text_field( $a['symbol'] ) );
        $type        = in_array( $a['type'], array( 'area', 'line', 'signal' ), true ) ? $a['type'] : 'area';
        $days        = min( 365, max( 1, intval( $a['days'] ) ) );
        $resolution  = in_array( $a['resolution'], array( '5m', '1h', '1d' ), true ) ? $a['resolution'] : '1h';
        $height      = max( 200, min( 800, intval( $a['height'] ) ) );
        $show_volume = $a['show_volume'] !== '0';
        $show_ranges = $a['show_ranges'] !== '0';
        $with_signals = ( $type === 'signal' );

        // Auto-title
        $title = $a['title'] ?: $symbol . ' Price';

        // TradingView fallback symbol
        $tv_sym = $a['tv_symbol'] ?: ( self::$tv_symbol_map[ $symbol ] ?? 'BINANCE:' . $symbol . 'USDT' );

        // Determine whether we have enough history for the native chart.
        $row_count = 0;
        if ( class_exists( 'BT_Database' ) ) {
            $rows      = BT_Database::get_price_history( $symbol, $days, $resolution );
            $row_count = count( $rows );
        }

        $use_native = ( $row_count >= self::MIN_ROWS );

        // Unique ID for this chart instance
        $uid = 'bt_chart_' . substr( md5( $symbol . $type . $days . $resolution . uniqid() ), 0, 8 );

        // Enqueue lightweight-charts only if native
        if ( $use_native ) {
            wp_enqueue_script( 'bt-lightweight-charts' );
        }

        // REST endpoint URL for JS fetch
        $rest_url = add_query_arg(
            array(
                'days'       => $days,
                'resolution' => $resolution,
                'signals'    => $with_signals ? '1' : '0',
            ),
            rest_url( 'blockticker/v1/chart/' . rawurlencode( $symbol ) )
        );

        ob_start();
        self::render_chart_html(
            $uid, $symbol, $title, $type, $height, $show_volume, $show_ranges,
            $use_native, $tv_sym, $rest_url, $days, $row_count, $resolution
        );
        return ob_get_clean();
    }

    // -------------------------------------------------------------------
    // HTML / JS output
    // -------------------------------------------------------------------
    private static function render_chart_html(
        $uid, $symbol, $title, $type, $height, $show_volume, $show_ranges,
        $use_native, $tv_sym, $rest_url, $days, $row_count, $resolution
    ) {
        $chart_height    = $show_volume ? intval( $height * 0.72 ) : $height;
        $volume_height   = $show_volume ? ( $height - $chart_height - 6 ) : 0;
        $nonce           = wp_create_nonce( 'wp_rest' );
        $ranges_json     = wp_json_encode( array(
            array( 'label' => '1D',  'days' => 1,   'res' => '5m' ),
            array( 'label' => '7D',  'days' => 7,   'res' => '1h' ),
            array( 'label' => '30D', 'days' => 30,  'res' => '1h' ),
            array( 'label' => '90D', 'days' => 90,  'res' => '1d' ),
        ) );
        $base_rest       = esc_url( rest_url( 'blockticker/v1/chart/' . rawurlencode( $symbol ) ) );
        $active_range    = ( $days <= 1 ? '1D' : ( $days <= 7 ? '7D' : ( $days <= 30 ? '30D' : '90D' ) ) );
        ?>
        <div class="bt-chart-wrap" id="<?php echo esc_attr( $uid ); ?>_wrap" style="position:relative;border-radius:0;overflow:hidden;background:var(--bt-chart-bg,#131722);">

            <!-- Header bar -->
            <div class="bt-chart-header" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px 8px;border-bottom:1px solid rgba(255,255,255,.06);">
                <span class="bt-chart-title" style="font-size:13px;font-weight:600;color:#d1d4dc;letter-spacing:.03em;">
                    <?php echo esc_html( $title ); ?>
                    <span class="bt-chart-res-badge" style="font-size:10px;font-weight:400;color:#555;margin-left:6px;text-transform:uppercase;">
                        <?php echo esc_html( $resolution ); ?>
                    </span>
                </span>
                <div style="display:flex;align-items:center;gap:6px;">
                    <?php if ( $show_ranges ) : ?>
                    <div class="bt-range-btns" id="<?php echo esc_attr( $uid ); ?>_ranges" style="display:flex;gap:3px;">
                        <?php
                        $range_defs = array(
                            array( '1D', 1, '5m' ), array( '7D', 7, '1h' ),
                            array( '30D', 30, '1h' ), array( '90D', 90, '1d' ),
                        );
                        foreach ( $range_defs as $rd ) :
                            $active = ( $rd[0] === $active_range );
                        ?>
                        <button
                            class="bt-range-btn<?php echo $active ? ' bt-range-active' : ''; ?>"
                            data-days="<?php echo esc_attr( $rd[1] ); ?>"
                            data-res="<?php echo esc_attr( $rd[2] ); ?>"
                            style="background:<?php echo $active ? 'rgba(255,255,255,.12)' : 'transparent'; ?>;border:none;border-radius:0;color:<?php echo $active ? '#d1d4dc' : '#555'; ?>;font-size:11px;padding:3px 7px;cursor:pointer;transition:all .15s;">
                            <?php echo esc_html( $rd[0] ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! $use_native ) : ?>
                    <span style="font-size:10px;color:#555;margin-left:4px;" title="Collecting price history — native chart will appear automatically once data accumulates.">
                        ⏳ Building history (<?php echo esc_html( $row_count ); ?>/<?php echo esc_html( self::MIN_ROWS ); ?> rows)
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $use_native ) : ?>
            <!-- Native chart containers -->
            <div id="<?php echo esc_attr( $uid ); ?>_price" style="width:100%;height:<?php echo esc_attr( $chart_height ); ?>px;"></div>
            <?php if ( $show_volume ) : ?>
            <div id="<?php echo esc_attr( $uid ); ?>_volume" style="width:100%;height:<?php echo esc_attr( $volume_height ); ?>px;margin-top:2px;opacity:.7;"></div>
            <?php endif; ?>
            <div id="<?php echo esc_attr( $uid ); ?>_tooltip" style="position:absolute;top:46px;left:14px;font-size:12px;color:#d1d4dc;pointer-events:none;z-index:10;"></div>
            <div id="<?php echo esc_attr( $uid ); ?>_loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(19,23,34,.85);font-size:13px;color:#555;z-index:20;">
                Loading chart data…
            </div>

            <?php else : // Fallback: TradingView embed ?>
            <div class="bt-chart-tv-fallback" style="width:100%;height:<?php echo esc_attr( $height ); ?>px;">
                <!-- TradingView embed (fallback until local history accumulates) -->
                <?php echo BT_Utils::render_tv_chart( array(
                    'symbol'   => $tv_sym,
                    'height'   => $height,
                    'interval' => 'D',
                ) ); ?>
            </div>
            <?php endif; ?>

        </div><!-- /.bt-chart-wrap -->

        <?php if ( $use_native ) : ?>
        <style>
        .bt-range-btn:hover{background:rgba(255,255,255,.07)!important;color:#999!important;}
        .bt-chart-wrap *{box-sizing:border-box;}
        </style>
        <script>
        (function() {
            'use strict';
            var uid       = <?php echo wp_json_encode( $uid ); ?>;
            var baseUrl   = <?php echo wp_json_encode( $base_rest ); ?>;
            var chartType = <?php echo wp_json_encode( $type ); ?>;
            var showVol   = <?php echo $show_volume ? 'true' : 'false'; ?>;
            var withSig   = <?php echo ( $type === 'signal' ) ? 'true' : 'false'; ?>;
            var nonce     = <?php echo wp_json_encode( $nonce ); ?>;

            var priceEl   = document.getElementById( uid + '_price' );
            var volumeEl  = document.getElementById( uid + '_volume' );
            var loading   = document.getElementById( uid + '_loading' );
            var tooltip   = document.getElementById( uid + '_tooltip' );
            var rangeWrap = document.getElementById( uid + '_ranges' );

            var priceChart = null, volumeChart = null;
            var priceSeries = null, volumeSeries = null;

            function fmtPrice(v, sym) {
                if (!v && v !== 0) return '';
                sym = sym || '';
                if (sym.indexOf('/') !== -1) return v.toFixed(5);
                if (v >= 1000) return '$' + v.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                if (v >= 1)    return '$' + v.toFixed(4);
                return '$' + v.toFixed(8);
            }
            function fmtVol(v) {
                if (!v) return '';
                if (v >= 1e9) return '$' + (v/1e9).toFixed(2) + 'B';
                if (v >= 1e6) return '$' + (v/1e6).toFixed(1) + 'M';
                return '$' + v.toLocaleString();
            }

            function buildCharts() {
                if (typeof LightweightCharts === 'undefined') return;

                var darkBg    = '#131722';
                var gridColor = 'rgba(255,255,255,.04)';
                var textColor = '#555';
                var lineColor = '#2962ff';

                var commonOpts = {
                    layout:     { background: { type: 'solid', color: darkBg }, textColor: '#777', fontSize: 11 },
                    grid:       { vertLines: { color: gridColor }, horzLines: { color: gridColor } },
                    rightPriceScale: { borderColor: 'rgba(255,255,255,.06)' },
                    timeScale:  { borderColor: 'rgba(255,255,255,.06)', timeVisible: true, secondsVisible: false },
                    crosshair:  { mode: 1 },
                    handleScroll: { vertTouchDrag: false },
                };

                // Price chart
                priceChart = LightweightCharts.createChart(priceEl, Object.assign({}, commonOpts, {
                    timeScale: Object.assign({}, commonOpts.timeScale, { visible: !showVol }),
                }));

                if (chartType === 'line') {
                    priceSeries = priceChart.addLineSeries({ color: lineColor, lineWidth: 2 });
                } else {
                    // area (default) + signal (same visual, signals added as markers)
                    priceSeries = priceChart.addAreaSeries({
                        lineColor: lineColor,
                        topColor:   'rgba(41,98,255,.25)',
                        bottomColor:'rgba(41,98,255,0)',
                        lineWidth: 2,
                    });
                }

                // Volume chart (separate chart, time-scale synced)
                if (showVol && volumeEl) {
                    var volOpts = Object.assign({}, commonOpts, {
                        timeScale: Object.assign({}, commonOpts.timeScale, { visible: true }),
                        rightPriceScale: { borderColor: 'rgba(255,255,255,.06)', scaleMargins: { top: 0.1, bottom: 0 } },
                    });
                    volumeChart = LightweightCharts.createChart(volumeEl, volOpts);
                    volumeSeries = volumeChart.addHistogramSeries({
                        color: 'rgba(41,98,255,.35)',
                        priceFormat: { type: 'volume' },
                    });

                    // Sync time scales
                    priceChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                        if (range && volumeChart) volumeChart.timeScale().setVisibleLogicalRange(range);
                    });
                    volumeChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                        if (range && priceChart) priceChart.timeScale().setVisibleLogicalRange(range);
                    });
                }

                // Tooltip
                priceChart.subscribeCrosshairMove(function(param) {
                    if (!param.time || !param.seriesData) {
                        tooltip.innerHTML = '';
                        return;
                    }
                    var d = param.seriesData.get(priceSeries);
                    if (!d) return;
                    var dateStr = new Date(param.time * 1000).toLocaleString(undefined, {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
                    tooltip.innerHTML = '<span style="color:#555;">' + dateStr + '</span> &nbsp;<span style="color:#d1d4dc;font-weight:600;">' + fmtPrice(d.value || d.close) + '</span>';
                });

                // Resize observer
                if (typeof ResizeObserver !== 'undefined') {
                    var ro = new ResizeObserver(function() {
                        var w = priceEl.parentElement.clientWidth;
                        if (priceChart && w > 0) priceChart.resize(w, priceEl.clientHeight);
                        if (volumeChart && w > 0) volumeChart.resize(w, volumeEl.clientHeight);
                    });
                    ro.observe(priceEl.parentElement);
                }
            }

            function loadData(days, res, signals) {
                if (loading) loading.style.display = 'flex';
                var url = baseUrl + '?days=' + encodeURIComponent(days) + '&resolution=' + encodeURIComponent(res) + '&signals=' + (signals ? '1' : '0');
                fetch(url, { headers: { 'X-WP-Nonce': nonce } })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (loading) loading.style.display = 'none';
                        var d = resp.data || {};
                        if (!d.price_series || !d.price_series.length) {
                            if (tooltip) tooltip.innerHTML = '<span style="color:#555;">No data for this range yet</span>';
                            return;
                        }

                        if (!priceChart) buildCharts();

                        priceSeries.setData(d.price_series);
                        if (volumeSeries && d.volume_series && d.volume_series.length) {
                            volumeSeries.setData(d.volume_series);
                        }

                        // Signal markers
                        if (withSig && d.signals && d.signals.length) {
                            priceSeries.setMarkers(d.signals);
                        }

                        priceChart.timeScale().fitContent();
                        if (volumeChart) volumeChart.timeScale().fitContent();
                    })
                    .catch(function(err) {
                        if (loading) loading.innerHTML = '<span style="color:#555;">Failed to load chart data</span>';
                        console.error('bt-chart:', err);
                    });
            }

            // Range button wiring
            if (rangeWrap) {
                rangeWrap.addEventListener('click', function(e) {
                    var btn = e.target.closest('.bt-range-btn');
                    if (!btn) return;
                    rangeWrap.querySelectorAll('.bt-range-btn').forEach(function(b) {
                        b.style.background = 'transparent';
                        b.style.color = '#555';
                        b.classList.remove('bt-range-active');
                    });
                    btn.style.background = 'rgba(255,255,255,.12)';
                    btn.style.color = '#d1d4dc';
                    btn.classList.add('bt-range-active');
                    loadData(btn.dataset.days, btn.dataset.res, withSig);
                });
            }

            // Load lightweight-charts if not already present, then init
            function initChart() {
                if (typeof LightweightCharts !== 'undefined') {
                    loadData(<?php echo (int) $days; ?>, <?php echo wp_json_encode( $resolution ); ?>, withSig);
                    return;
                }
                // Library not yet loaded — wait for DOMContentLoaded + script load
                var script = document.querySelector('script[src*="lightweight-charts"]');
                if (script) {
                    script.addEventListener('load', function() {
                        loadData(<?php echo (int) $days; ?>, <?php echo wp_json_encode( $resolution ); ?>, withSig);
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initChart);
            } else {
                initChart();
            }
        })();
        </script>
        <?php endif; ?>
        <?php
    }
}
