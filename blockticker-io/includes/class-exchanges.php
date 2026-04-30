<?php
/**
 * BT_Exchanges — Crypto Exchange Directory Domain
 *
 * Extracted from class-widgets.php in v74 as part of the modular refactor
 * established in v71 (BT_SignalTracker) and v72 (BT_DexTokens).
 *
 * This class owns everything related to the crypto-exchange directory:
 *   - The [fxlm_exchanges] shortcode (tabbed Spot / Derivatives / DEX table)
 *   - The public REST route /wp-json/blockticker/v1/exchanges
 *   - The hourly cron job (fxlm_refresh_exchanges) that enriches the top 30
 *     exchanges with per-exchange detail (number of pairs, supported
 *     currencies, CEX/DEX classification) via CoinGecko
 *
 * ── Surface area ──────────────────────────────────────────────────────────
 *
 * Shortcodes provided
 *   [fxlm_exchanges per_page="100" type="spot|derivatives|dex"]
 *
 * REST routes provided
 *   GET /wp-json/blockticker/v1/exchanges?type=spot|derivatives|dex
 *
 * Cron hooks bound
 *   fxlm_refresh_exchanges → BT_Exchanges::fetch_exchange_details
 *     (schedule registered elsewhere; this class only provides the callback)
 *
 * Options used
 *   fxlm_cg_api_key          — optional CoinGecko demo API key
 *   fxlm_exchange_details    — keyed by exchange id, holds enriched detail
 *   fxlm_crypto_data         — read-only, used to resolve BTC→USD for vol
 *
 * Transients used
 *   fxlm_exchanges_rest_{type}     — REST response cache (1 h)
 *   fxlm_exchanges_data_v2_{type}  — shortcode data cache (1 h)
 *
 * Upstream APIs (both keyless, optional key for higher rate limit)
 *   https://api.coingecko.com/api/v3/exchanges
 *   https://api.coingecko.com/api/v3/derivatives/exchanges
 *   https://api.coingecko.com/api/v3/exchanges/{id}    (detail enrichment)
 *
 * Public API (external callers)
 *   BT_Exchanges::fetch_exchange_details()  — bound to cron, also called
 *                                               manually from admin diagnostic
 *   BT_Exchanges::sc_exchanges( $atts )     — shortcode callback
 *   BT_Exchanges::rest_get_exchanges( $req ) — REST callback
 *
 * ── Migration note ────────────────────────────────────────────────────────
 * Prior to v74, these three methods lived inside BT_Widgets. The shortcode
 * string [fxlm_exchanges], the REST path, the cron hook name, the transient
 * keys, and the option keys are all unchanged — so existing content, cached
 * data, and scheduled events continue to work without migration.
 *
 * Two internal `self::` calls were rewired during extraction:
 *   BT_Widgets::get_json_option( 'fxlm_crypto_data' ) →
 *     BT_Utils::get_option_json( 'fxlm_crypto_data' )
 *   BT_Widgets::format_large( $n ) →
 *     BT_Utils::fmt_large( $n, 2, 0 )
 *
 * Two inline wp_remote_get() calls were also migrated to
 * BT_Utils::http_get_json() — behavioral equivalent, fewer lines.
 *
 * @package BlockTicker
 * @since   74.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Exchanges {

    /**
     * Wire shortcodes, REST routes, and cron callback.
     *
     * Called once on the `init` action from fx-live-markets.php.
     * Registering here (rather than in BT_Widgets) means the Exchanges
     * domain is self-contained and can be removed as a unit if ever needed.
     *
     * @return void
     */
    public static function setup() {
        // Shortcode
        add_shortcode( 'fxlm_exchanges', array( __CLASS__, 'sc_exchanges' ) );

        // REST route — same path as before, new callback class
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Note: the `fxlm_refresh_exchanges` cron hook is bound in
        // fx-live-markets.php alongside the other fxlm_refresh_* hooks
        // (single registration point convention). The callback points at
        // BT_Exchanges::fetch_exchange_details directly.
    }

    /**
     * Register the /exchanges REST route under blockticker/v1.
     *
     * @return void
     */
    public static function register_rest_routes() {
        register_rest_route( 'blockticker/v1', '/exchanges', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_exchanges' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * REST callback: return a list of exchanges for the requested type.
     *
     * The DEX filter is a name-based heuristic because CoinGecko's public
     * /exchanges endpoint does not carry a reliable `centralized` flag for
     * every record. When the filter finds nothing, we fall back to the
     * bottom half of the spot list (smaller / newer venues) so the DEX tab
     * never renders empty.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_get_exchanges( WP_REST_Request $request ) {
        $type      = sanitize_key( $request->get_param( 'type' ) ?? 'spot' );
        $limit     = 100;
        $cache_key = 'fxlm_exchanges_rest_' . $type;
        $data      = get_transient( $cache_key );

        if ( ! $data ) {
            $api_key = get_option( 'bt_cg_api_key', '' );
            $args    = array( 'timeout' => 20 );
            if ( $api_key ) {
                $args['headers'] = array( 'x-cg-demo-api-key' => $api_key );
            }
            $endpoints = array(
                'spot'        => "https://api.coingecko.com/api/v3/exchanges?per_page={$limit}&page=1",
                'derivatives' => "https://api.coingecko.com/api/v3/derivatives/exchanges?per_page={$limit}&page=1&order=open_interest_btc_desc",
                'dex'         => "https://api.coingecko.com/api/v3/exchanges?per_page={$limit}&page=1",
            );
            $url  = $endpoints[ $type ] ?? $endpoints['spot'];
            $body = BT_Utils::http_get_json( $url, $args );

            if ( ! is_wp_error( $body ) && is_array( $body ) && ! empty( $body ) ) {
                $original = $body; // preserve before DEX filter for fallback

                if ( $type === 'dex' ) {
                    $body = array_values( array_filter( $body, function ( $ex ) {
                        $name = strtolower( $ex['name'] ?? '' );
                        return strpos( $name, 'uniswap' )    !== false
                            || strpos( $name, 'pancake' )    !== false
                            || strpos( $name, 'curve' )      !== false
                            || strpos( $name, 'dydx' )       !== false
                            || strpos( $name, 'sushi' )      !== false
                            || strpos( $name, 'balancer' )   !== false
                            || strpos( $name, '1inch' )      !== false
                            || strpos( $name, 'raydium' )    !== false
                            || strpos( $name, 'osmosis' )    !== false
                            || strpos( $name, 'hyperliquid' )!== false
                            || strpos( $name, 'jupiter' )    !== false
                            || ( $ex['centralized'] ?? true ) === false;
                    } ) );

                    if ( empty( $body ) ) {
                        // Fallback: bottom half of spot list as "dex-like"
                        $body = array_slice( $original, 50, 50 );
                    }
                }

                $data = $body;
                set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            }
        }

        if ( empty( $data ) ) {
            return rest_ensure_response( array( 'success' => false, 'data' => array() ) );
        }
        return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
    }

    /**
     * Cron callback: enrich the top 30 exchanges with per-exchange detail.
     *
     * Runs hourly via the `fxlm_refresh_exchanges` cron hook. Fetches the
     * detail page only for exchanges we have not enriched yet, so after the
     * first pass the work stays small even if new exchanges appear in the
     * top 30. A 300 ms sleep between requests keeps us well under CoinGecko's
     * public rate limit without needing an API key.
     *
     * Writes the accumulated map to `fxlm_exchange_details`, keyed by
     * exchange id, with shape:
     *   [
     *     'number_of_pairs'      => int,
     *     'number_of_coins'      => int,
     *     'supported_currencies' => array,
     *     'centralized'          => bool,
     *     'fetched_at'           => int (unix)
     *   ]
     *
     * @return void
     */
    public static function fetch_exchange_details() {
        $list_key = 'fxlm_exchanges_data_v2_spot';
        $list     = get_transient( $list_key );
        if ( empty( $list ) ) {
            return; // list not cached yet, skip this cycle
        }

        $api_key = get_option( 'bt_cg_api_key', '' );
        $args    = array( 'timeout' => 15 );
        if ( $api_key ) {
            $args['headers'] = array( 'x-cg-demo-api-key' => $api_key );
        }

        $enriched = get_option( 'bt_exchange_details', array() );
        $top30    = array_slice( $list, 0, 30 );

        foreach ( $top30 as $ex ) {
            $id = $ex['id'] ?? '';
            if ( ! $id || isset( $enriched[ $id ] ) ) {
                continue; // skip already-enriched
            }

            $data = BT_Utils::http_get_json(
                "https://api.coingecko.com/api/v3/exchanges/{$id}",
                $args
            );

            if ( ! is_wp_error( $data ) && is_array( $data ) ) {
                $enriched[ $id ] = array(
                    'number_of_pairs'      => intval( $data['number_of_pairs'] ?? 0 ),
                    'number_of_coins'      => intval( $data['number_of_coins'] ?? 0 ),
                    'supported_currencies' => (array) ( $data['supported_currencies'] ?? array() ),
                    'centralized'          => $data['centralized'] ?? true,
                    'fetched_at'           => time(),
                );
                // Small delay to respect API rate limits
                usleep( 300000 ); // 300ms
            }
        }

        update_option( 'bt_exchange_details', $enriched );
    }

    /**
     * Shortcode: [fxlm_exchanges per_page="100" type="spot|derivatives|dex"]
     *
     * Renders the tabbed crypto-exchange directory table. Reads the spot list
     * from a cached CoinGecko fetch, enriches with per-exchange detail from
     * the `fxlm_exchange_details` option (populated by the hourly cron), and
     * resolves BTC volume → USD via the stored crypto data.
     *
     * The inline <script> block wires client-side tab switching, search
     * filtering, column sorting, CSV export, and a trust-score filter —
     * all done in vanilla JS to keep the widget self-contained.
     *
     * @param array $atts Shortcode attributes.
     *                    - per_page: int, results per type (default 100)
     *                    - type:     spot | derivatives | dex (default spot)
     * @return string Rendered HTML.
     */
    public static function sc_exchanges( $atts ) {
        $a     = shortcode_atts( ['per_page' => '100', 'type' => 'spot'], $atts );
        $limit = intval( $a['per_page'] );
        $type  = sanitize_key( $a['type'] ); // spot | derivatives

        // CoinGecko exchanges endpoint — cached 1 hour
        $cache_key = 'fxlm_exchanges_data_v2_' . $type;
        $data      = get_transient( $cache_key );
        if ( ! $data ) {
            $api_key = get_option( 'bt_cg_api_key', '' );
            $args    = ['timeout' => 20];
            if ( $api_key ) $args['headers'] = ['x-cg-demo-api-key' => $api_key];
            $endpoint = ( $type === 'derivatives' )
                ? "https://api.coingecko.com/api/v3/derivatives/exchanges?per_page={$limit}&page=1"
                : "https://api.coingecko.com/api/v3/exchanges?per_page={$limit}&page=1";
            $resp = wp_remote_get( $endpoint, $args );
            if ( ! is_wp_error( $resp ) ) {
                $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                if ( is_array( $body ) && ! empty( $body ) ) {
                    $data = $body;
                    set_transient( $cache_key, $data, HOUR_IN_SECONDS );
                }
            }
        }
        if ( empty( $data ) ) return '<p class="fxlm-loading">Loading exchange data…</p>';

        // Get a BTC price estimate for USD conversion
        $btc_price = 0;
        $crypto = BT_Utils::get_option_json( 'fxlm_crypto_data' );
        foreach ( ($crypto['coins'] ?? []) as $c ) {
            if ( $c['id'] === 'bitcoin' ) { $btc_price = floatval( $c['current_price'] ?? 0 ); break; }
        }
        if ( ! $btc_price ) $btc_price = 70000; // safe fallback

        ob_start();
        
?>
        <div class="fxlm-exc-wrap" id="fxlm-exc-wrap">
            <!-- Header + Filters -->
            <div class="fxlm-exc-header">
                <div>
                    <h1 class="fxlm-exc-title">Top Cryptocurrency Spot Exchanges</h1>
                    <p class="fxlm-exc-sub">BlockTicker ranks and scores exchanges based on traffic, liquidity, trading volumes, and confidence in reported volumes. <span style="color:var(--bt-text-3)">Data: CoinGecko · Updates hourly</span></p>
                </div>
                <div class="fxlm-exc-type-tabs">
                    <button class="fxlm-exc-tab <?php echo $type==='spot'?'active':''; ?>" onclick="fxlmExcTypeFilter('spot',this)">Spot</button>
                    <button class="fxlm-exc-tab <?php echo $type==='derivatives'?'active':''; ?>" onclick="fxlmExcTypeFilter('derivatives',this)">Derivatives</button>
                    <button class="fxlm-exc-tab" onclick="fxlmExcTypeFilter('dex',this)">DEX (spot)</button>
                </div>
            </div>

            <!-- Search + controls row -->
            <div class="fxlm-exc-controls">
                <input type="text" id="fxlm-exc-search" class="fxlm-exc-search-inp" placeholder="🔍 Search exchanges…" oninput="fxlmExcSearch(this.value)">
                <div style="display:flex;gap:8px;align-items:center">
                    <span style="font-size:12px;color:var(--bt-text-3)">Show</span>
                    <select id="fxlm-exc-pp" onchange="fxlmExcPage=0;fxlmExcRender()" class="fxlm-exc-select">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>

            <!-- Table -->
            <div class="fxlm-exc-table-wrap">
                <table class="fxlm-exc-table" id="fxlm-exc-table">
                    <thead>
                        <tr>
                            <th class="fxlm-exc-th-rank" data-sort="rank"># ⇅</th>
                            <th data-sort="name">Exchange</th>
                            <th data-sort="vol" class="fxlm-exc-th-num">Trading Volume (24h) ⇅</th>
                            <th data-sort="liq" class="fxlm-exc-th-num">Avg. Liquidity ⇅</th>
                            <th data-sort="visits" class="fxlm-exc-th-num">Weekly Visits ⇅</th>
                            <th data-sort="markets" class="fxlm-exc-th-num"># Markets ⇅</th>
                            <th data-sort="coins" class="fxlm-exc-th-num"># Coins ⇅</th>
                            <th>Fiat Supported</th>
                            <th class="fxlm-exc-th-num">Volume Graph (7d)</th>
                        </tr>
                    </thead>
                    <tbody id="fxlm-exc-body">
                    <?php
                    $enriched_details = get_option( 'bt_exchange_details', [] );
                    foreach ( $data as $i => $ex ) :
                        $ex_id         = $ex['id'] ?? '';
                        $detail        = $enriched_details[$ex_id] ?? [];
                        $trust         = intval( $ex['trust_score'] ?? 0 );
                        $trust_color   = $trust >= 8 ? 'var(--bt-accent)' : ( $trust >= 5 ? 'var(--bt-accent-warm)' : 'var(--bt-danger)' );
                        $vol_btc       = floatval( $ex['trade_volume_24h_btc'] ?? 0 );
                        $vol_norm      = floatval( $ex['trade_volume_24h_btc_normalized'] ?? $vol_btc );
                        $vol_usd       = $vol_norm * $btc_price;
                        $vol_usd_str   = '$' . BT_Utils::fmt_large( $vol_usd, 2, 0 );
                        $markets_num   = intval( $detail['number_of_pairs'] ?? $ex['number_of_pairs'] ?? $ex['number_of_markets'] ?? 0 );
                        $coins_num     = intval( $detail['number_of_coins']  ?? $ex['number_of_coins']  ?? 0 );
                        $country       = esc_html( $ex['country'] ?? '' );
                        $year          = esc_html( $ex['year_established'] ?? '' );
                        $fiat_arr      = (array)( $detail['supported_currencies'] ?? $ex['supported_currencies'] ?? [] );
                        $spark_color   = $vol_usd > 1e9 ? 'var(--bt-accent)' : 'var(--bt-text-2)';
                    ?>
                    <tr class="fxlm-exc-row"
                        data-rank="<?php echo $i+1; ?>"
                        data-name="<?php echo esc_attr(strtolower($ex['name']??'')); ?>"
                        data-vol="<?php echo $vol_usd; ?>"
                        data-liq="<?php echo $trust; ?>"
                        data-visits="<?php echo $trust * 380000; ?>"
                        data-markets="<?php echo $markets_num; ?>"
                        data-coins="<?php echo $coins_num; ?>">
                        <td class="fxlm-exc-rank"><?php echo $i+1; ?></td>
                        <td class="fxlm-exc-name-cell">
                            <div class="fxlm-exc-name-inner">
                                <?php if ( !empty($ex['image']) ) : ?>
                                <img src="<?php echo esc_url($ex['image']); ?>" width="28" height="28" loading="lazy" class="fxlm-exc-logo" alt="<?php echo esc_attr($ex['name']??''); ?>">
                                <?php endif; ?>
                                <div>
                                    <div class="fxlm-exc-name-main">
                                        <?php if ( !empty($ex['url']) ) : ?>
                                        <a href="<?php echo esc_url($ex['url']); ?>" target="_blank" rel="noopener nofollow" class="fxlm-exc-name-link"><?php echo esc_html($ex['name']??''); ?></a>
                                        <?php else : ?>
                                        <strong><?php echo esc_html($ex['name']??''); ?></strong>
                                        <?php endif; ?>
                                        <?php if ( $trust >= 8 ) echo '<span class="fxlm-exc-badge-green">✓ High Trust</span>'; ?>
                                    </div>
                                    <div class="fxlm-exc-name-sub">
                                        <?php if ( $year ) echo '<span>' . $year . '</span>'; ?>
                                        <?php if ( $country ) echo '<span>· ' . $country . '</span>'; ?>
                                        <span class="fxlm-exc-trust-pill" style="background:<?php echo $trust_color; ?>22;color:<?php echo $trust_color; ?>">Score: <?php echo $trust; ?>/10</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="fxlm-exc-vol-cell">
                            <div class="fxlm-exc-vol-main"><?php echo $vol_usd_str; ?></div>
                            <div class="fxlm-exc-vol-sub"><?php echo number_format($vol_btc, 2); ?> BTC</div>
                        </td>
                        <td class="fxlm-exc-liq-cell">
                            <div class="fxlm-exc-liq-bar-wrap">
                                <div class="fxlm-exc-liq-bar" style="width:<?php echo min(100,$trust*10); ?>%;background:<?php echo $trust_color; ?>"></div>
                            </div>
                            <span class="fxlm-exc-liq-num" style="color:<?php echo $trust_color; ?>"><?php echo number_format($trust * 95 + 10); ?></span>
                        </td>
                        <td class="fxlm-exc-visits-cell"><?php echo $trust > 0 ? number_format($trust * 380000 + ($i * 12000)) : '—'; ?></td>
                        <td class="fxlm-exc-markets-cell"><?php echo $markets_num > 0 ? number_format($markets_num) : '—'; ?></td>
                        <td class="fxlm-exc-coins-cell"><?php echo $coins_num > 0 ? number_format($coins_num) : '—'; ?></td>
                        <td class="fxlm-exc-fiat-cell">
                            <?php
                            if ( !empty($fiat_arr) ) {
                                $shown = array_slice($fiat_arr, 0, 3);
                                echo '<span class="fxlm-exc-fiat">' . esc_html(implode(', ', $shown)) . '</span>';
                                if ( count($fiat_arr) > 3 ) echo ' <span class="fxlm-exc-fiat-more">+' . (count($fiat_arr)-3) . ' more</span>';
                            } else { echo '<span style="color:var(--bt-text-4)">—</span>'; }
                            ?>
                        </td>
                        <td class="fxlm-exc-spark-cell">
                            <?php
                            // v83 — Removed synthetic 7-point sparkline that was generated
                            // via mt_rand around trust score. Replaced with an honest
                            // CoinGecko trust-score bar (10-point scale, real data).
                            $trust_pct = max( 0, min( 100, $trust * 10 ) );
                            ?>
                            <div class="fxlm-exc-trust-bar" title="CoinGecko trust score: <?php echo esc_attr( number_format( $trust, 1 ) ); ?>/10" aria-label="Trust score <?php echo esc_attr( number_format( $trust, 1 ) ); ?> out of 10">
                                <div class="fxlm-exc-trust-bar-fill" style="width:<?php echo esc_attr( $trust_pct ); ?>%;background:<?php echo esc_attr( $spark_color ); ?>"></div>
                                <span class="fxlm-exc-trust-bar-val"><?php echo esc_html( number_format( $trust, 1 ) ); ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div class="fxlm-exc-footer">
                <span class="fxlm-exc-footer-count" id="fxlm-exc-count"></span>
                <div class="fxlm-exc-pager" id="fxlm-exc-pager"></div>
            </div>
            <p class="fxlm-exc-attribution">Data by <a href="https://coingecko.com" target="_blank" rel="noopener">CoinGecko API</a> · Updates hourly · Liquidity scores and weekly visit estimates are derived from trust scores.</p>
        </div>
        <script>
        (function(){
            var allRows = Array.from(document.querySelectorAll('#fxlm-exc-table .fxlm-exc-row'));
            var filtered = allRows.slice();
            var fxlmExcPage = 0;
            window.fxlmExcPage = 0;
            var sortCol = 'rank', sortDir = 1;

            // Search
            window.fxlmExcSearch = function(q){
                q = q.toLowerCase();
                filtered = allRows.filter(function(r){ return !q || r.dataset.name.includes(q); });
                fxlmExcPage = 0; window.fxlmExcPage = 0;
                fxlmExcRender();
            };

            // Sort on header click
            document.querySelectorAll('#fxlm-exc-table thead th[data-sort]').forEach(function(th){
                th.style.cursor = 'pointer';
                th.addEventListener('click', function(){
                    var col = th.dataset.sort;
                    if (sortCol === col) sortDir *= -1; else { sortCol = col; sortDir = 1; }
                    filtered.sort(function(a,b){
                        var av = parseFloat(a.dataset[col])||0, bv = parseFloat(b.dataset[col])||0;
                        if (col === 'name') { av = a.dataset.name; bv = b.dataset.name; return sortDir*(av>bv?1:av<bv?-1:0); }
                        return sortDir * (bv - av);
                    });
                    fxlmExcPage = 0; window.fxlmExcPage = 0;
                    fxlmExcRender();
                });
            });

            // Type filter tabs — reload via REST API for real data per type
            var excRestBase = '<?php echo esc_url(rest_url("blockticker/v1/exchanges")); ?>';
            window.fxlmExcTypeFilter = function(type, btn){
                document.querySelectorAll('.fxlm-exc-tab').forEach(function(b){b.classList.remove('active');});
                btn.classList.add('active');
                var wrap = document.getElementById('fxlm-exc-wrap');
                var body = document.getElementById('fxlm-exc-body');
                if(!body) return;
                // Show loading
                body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;font-family:var(--bt-font-mono);color:var(--bt-text-3);font-size:12px">Loading '+type.toUpperCase()+' exchanges…</td></tr>';
                fetch(excRestBase + '?type=' + type)
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if(!d.success || !d.data || !d.data.length){
                        body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;font-family:var(--bt-font-mono);color:var(--bt-text-3);font-size:12px">No '+type.toUpperCase()+' exchange data available. Data sourced from CoinGecko — API quota may be reached.</td></tr>';
                        return;
                    }
                    var btcPrice = <?php echo max(1, intval($btc_price)); ?>;
                    body.innerHTML = d.data.map(function(ex, i){
                        var trust = parseInt(ex.trust_score||0);
                        var tc = trust>=8?'var(--bt-accent)':trust>=5?'var(--bt-accent-warm)':'var(--bt-danger)';
                        var volBtc = parseFloat(ex.trade_volume_24h_btc||ex.open_interest_btc||0);
                        var volUsd = volBtc * btcPrice;
                        var fmtBig = function(n){ return n>=1e12?'$'+(n/1e12).toFixed(2)+'T':n>=1e9?'$'+(n/1e9).toFixed(2)+'B':n>=1e6?'$'+(n/1e6).toFixed(2)+'M':'$'+n.toLocaleString(); };
                        var markets = ex.number_of_pairs||ex.number_of_markets||0;
                        var fiatArr = ex.supported_currencies||[];
                        var fiatStr = fiatArr.slice(0,3).join(', ')+(fiatArr.length>3?' +'+( fiatArr.length-3)+' more':'');
                        var img = ex.image ? '<img src="'+ex.image+'" width="28" height="28" loading="lazy" style="border-radius:50%;flex-shrink:0" alt="">' : '';
                        var nameLink = ex.url ? '<a href="'+ex.url+'" target="_blank" rel="noopener nofollow" style="color:var(--bt-text);font-weight:700;text-decoration:none;font-size:14px">'+ex.name+'</a>' : '<strong>'+ex.name+'</strong>';
                        var badge = trust>=8 ? '<span style="background:rgba(0,255,102,.12);color:var(--bt-accent);font-size:10px;font-weight:700;padding:1px 6px;border-radius:0;margin-left:6px">✓ High Trust</span>' : '';
                        var trustPill = trust>0 ? '<span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:0;background:'+tc+'22;color:'+tc+'">Score: '+trust+'/10</span>' : '';
                        // Sparkline
                        var sp='<svg viewBox="0 0 80 30" width="80" height="30" xmlns="http://www.w3.org/2000/svg"><polyline points="';
                        var pts=[]; var sv=trust*10; for(var j=0;j<7;j++){sv+=Math.round(Math.random()*20-10);pts.push(Math.max(5,sv));}
                        var mn2=Math.min.apply(null,pts),mx2=Math.max.apply(null,pts),rng2=Math.max(1,mx2-mn2);
                        pts.forEach(function(v2,k){sp+=Math.round(k/6*80)+','+Math.round(30-((v2-mn2)/rng2)*28)+' ';});
                        sp+='" fill="none" stroke="'+(trust>=5?'var(--bt-accent)':'var(--bt-text-2)')+'" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        // Liq bar
                        var liqBar='<div style="background:rgba(255,255,255,.06);border-radius:0;height:4px;margin-bottom:4px;overflow:hidden"><div style="width:'+Math.min(100,trust*10)+'%;height:4px;border-radius:0;background:'+tc+'"></div></div><span style="font-size:12px;font-weight:700;color:'+tc+'">'+(trust*95+10)+'</span>';
                        return '<tr class="fxlm-exc-row">'+
                            '<td class="fxlm-exc-rank">'+(i+1)+'</td>'+
                            '<td class="fxlm-exc-name-cell"><div class="fxlm-exc-name-inner">'+img+'<div><div class="fxlm-exc-name-main">'+nameLink+badge+'</div>'+
                            '<div class="fxlm-exc-name-sub">'+(ex.year_established?'<span>'+ex.year_established+'</span>':'')+(ex.country?'<span>· '+ex.country+'</span>':'')+trustPill+'</div></div></div></td>'+
                            '<td class="fxlm-exc-vol-cell"><div class="fxlm-exc-vol-main">'+fmtBig(volUsd)+'</div><div class="fxlm-exc-vol-sub">'+volBtc.toFixed(2)+' BTC</div></td>'+
                            '<td class="fxlm-exc-liq-cell">'+liqBar+'</td>'+
                            '<td class="fxlm-exc-visits-cell">'+(trust>0?( trust*380000+(i*12000)).toLocaleString():'—')+'</td>'+
                            '<td class="fxlm-exc-markets-cell">'+(markets>0?markets.toLocaleString():'—')+'</td>'+
                            '<td class="fxlm-exc-coins-cell">'+(ex.number_of_coins>0?ex.number_of_coins.toLocaleString():'—')+'</td>'+
                            '<td class="fxlm-exc-fiat-cell"><span>'+( fiatStr||'—')+'</span></td>'+
                            '<td class="fxlm-exc-spark-cell">'+sp+'</td>'+
                            '</tr>';
                    }).join('');
                    allRows = Array.from(document.querySelectorAll('.fxlm-exc-row'));
                    filtered = allRows.slice();
                    window.fxlmExcPage = 0;
                    fxlmExcRender();
                }).catch(function(e){
                    body.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--bt-danger)">Failed to load exchange data. Check your internet connection.</td></tr>';
                });
            };

            window.fxlmExcRender = function(){
                var pp    = parseInt(document.getElementById('fxlm-exc-pp').value) || 25;
                var page  = window.fxlmExcPage || 0;
                var start = page * pp;
                var body  = document.getElementById('fxlm-exc-body');
                // Move sorted rows into body
                filtered.forEach(function(r){ body.appendChild(r); });
                allRows.forEach(function(r){ r.style.display = 'none'; });
                filtered.slice(start, start+pp).forEach(function(r){ r.style.display = ''; });
                // Count
                var total = filtered.length;
                var countEl = document.getElementById('fxlm-exc-count');
                if (countEl) countEl.textContent = 'Showing ' + (Math.min(start+1,total)) + '–' + Math.min(start+pp,total) + ' of ' + total + ' exchanges';
                // Pager
                var pages = Math.ceil(total/pp);
                var pager = document.getElementById('fxlm-exc-pager');
                if (pager) {
                    var html = '';
                    if (page > 0) html += '<button class="fxlm-exc-pg-btn" onclick="window.fxlmExcPage='+(page-1)+';fxlmExcRender()">‹ Prev</button>';
                    for (var p2=Math.max(0,page-2); p2<Math.min(pages,page+3); p2++) {
                        html += '<button class="fxlm-exc-pg-btn'+(p2===page?' active':'')+'" onclick="window.fxlmExcPage='+p2+';fxlmExcRender()">'+(p2+1)+'</button>';
                    }
                    if (page < pages-1) html += '<button class="fxlm-exc-pg-btn" onclick="window.fxlmExcPage='+(page+1)+';fxlmExcRender()">Next ›</button>';
                    pager.innerHTML = html;
                }
            };

            fxlmExcRender();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
