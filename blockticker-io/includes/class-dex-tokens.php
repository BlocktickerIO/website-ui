<?php
/**
 * BlockTicker DEX & Meme Tokens.
 *
 * Fetches and renders data for decentralized-exchange pools (GeckoTerminal)
 * and Solana meme tokens (pump.fun). Extracted from class-widgets.php in v72
 * to enforce separation of concerns and keep the core widgets class focused
 * on aggregated price / forex / news flows.
 *
 * Surface area:
 *
 *   Shortcodes:
 *     - [bt_dexscan_tokens filter="trending|new|gainers" network="eth|solana|bsc|base|..." limit="20"]
 *     - [bt_meme_tokens filter="new|about_to_graduate|graduated" limit="12"]
 *     - [bt_meme_explorer]   — 3-bucket launch-lifecycle board (new / about to grad / graduated)
 *
 *   Public fetch helpers (used by shortcodes; callable externally too):
 *     - BT_DexTokens::fetch_dex_tokens( $filter = 'trending', $network = 'eth' )
 *     - BT_DexTokens::fetch_pump_fun_coins( $filter = 'new', $limit = 15 )
 *     - BT_DexTokens::fetch_meme_buckets()
 *
 *   Private helpers (internal formatting):
 *     - normalize_gt_pool(), bt_fmt_money(), bt_fmt_chg(), bt_fmt_age(),
 *       bt_fmt_mc(), bt_fmt_age_seconds()
 *
 *   Upstream APIs:
 *     - api.geckoterminal.com (no key required, generous rate limit)
 *     - frontend-api.pump.fun (no key required)
 *
 *   Caching:
 *     - bt_dex_pools_{filter}_{network}   transient, 5 min
 *     - bt_pumpfun_{filter}_{limit}       transient, 2 min
 *     - bt_meme_buckets                   transient, 3 min
 *
 * External callers previously addressed these via `BT_Widgets::…`.
 * As of v72 they should use `BT_DexTokens::…` — audited: zero in-repo
 * static callers (shortcodes resolve by string name, which keeps working).
 *
 * @package BlockTicker
 * @since   72.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_DexTokens {

    /**
     * Wire up hooks: 3 shortcodes.
     * Called once from the main plugin bootstrap.
     */
    public static function setup() {
        add_shortcode( 'bt_dexscan_tokens', array( __CLASS__, 'sc_dexscan_tokens' ) );
        add_shortcode( 'bt_meme_tokens',    array( __CLASS__, 'sc_meme_tokens' ) );
        add_shortcode( 'bt_meme_explorer',  array( __CLASS__, 'sc_meme_explorer' ) );
        add_shortcode( 'bt_dex_scanner',    array( __CLASS__, 'sc_dex_scanner' ) );   // v111.0
        add_shortcode( 'bt_top_traders',    array( __CLASS__, 'sc_top_traders' ) );   // v116.0: live top traders via DexScreener
    }


    // ═══════════════════════════════════════════════════════════════
    // DEXSCAN TOKENS — GeckoTerminal live integration (v48)
    // ═══════════════════════════════════════════════════════════════
    // Free API, no key needed. Endpoint docs: https://api.geckoterminal.com/
    // Trending pools: /networks/{network}/trending_pools
    // New pools:      /networks/{network}/new_pools
    // Cached 60s.

    /**
     * Fetch DEX pool data from GeckoTerminal.
     *
     * @param string $filter  'trending' | 'new' | 'gainers'
     * @param string $network GeckoTerminal network id: eth, solana, bsc, base, polygon_pos, arbitrum, avax
     * @return array list of pool entries (see normalize_gt_pool for fields)
     */
    public static function fetch_dex_tokens( $filter = 'trending', $network = 'eth' ) {
        $cache_key = 'bt_dextok_' . md5( $filter . '|' . $network );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        // Network id alias — accept 'ethereum' as 'eth' etc.
        $net_aliases = array( 'ethereum' => 'eth', 'polygon' => 'polygon_pos', 'avalanche' => 'avax' );
        $network     = $net_aliases[ $network ] ?? $network;

        // Trending and new have direct endpoints; "gainers" uses trending sorted by 24h % change.
        $endpoint = ( $filter === 'new' ) ? 'new_pools' : 'trending_pools';
        $url      = 'https://api.geckoterminal.com/api/v2/networks/' . rawurlencode( $network ) . '/' . $endpoint . '?page=1';

        $res = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/json;version=20230302' ),
        ) );
        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            set_transient( $cache_key, array(), 60 );
            return array();
        }
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $body['data'] ) ) {
            set_transient( $cache_key, array(), 60 );
            return array();
        }

        $pools = array();
        foreach ( $body['data'] as $pool ) {
            $n = self::normalize_gt_pool( $pool );
            if ( $n ) $pools[] = $n;
        }

        if ( $filter === 'gainers' ) {
            usort( $pools, function( $a, $b ) {
                return ( $b['chg_24h'] ?? 0 ) <=> ( $a['chg_24h'] ?? 0 );
            } );
        }

        set_transient( $cache_key, $pools, 60 );
        return $pools;
    }

    /** Normalize a GeckoTerminal pool object into our flat row shape. */
    private static function normalize_gt_pool( $pool ) {
        $a = $pool['attributes'] ?? array();
        if ( empty( $a ) ) return null;
        // Name is like "WETH / USDC"; take the left side as the traded token.
        $name       = $a['name'] ?? '';
        $parts      = array_map( 'trim', explode( '/', $name ) );
        $sym        = $parts[0] ?? '';
        $price_usd  = isset( $a['base_token_price_usd'] ) ? floatval( $a['base_token_price_usd'] ) : 0;
        $fdv        = isset( $a['fdv_usd'] ) ? floatval( $a['fdv_usd'] ) : 0;
        $vol24      = isset( $a['volume_usd']['h24'] ) ? floatval( $a['volume_usd']['h24'] ) : 0;
        $tx24_buys  = isset( $a['transactions']['h24']['buys']  ) ? intval( $a['transactions']['h24']['buys']  ) : 0;
        $tx24_sells = isset( $a['transactions']['h24']['sells'] ) ? intval( $a['transactions']['h24']['sells'] ) : 0;
        $chg_5m     = isset( $a['price_change_percentage']['m5']  ) ? floatval( $a['price_change_percentage']['m5']  ) : 0;
        $chg_1h     = isset( $a['price_change_percentage']['h1']  ) ? floatval( $a['price_change_percentage']['h1']  ) : 0;
        $chg_6h     = isset( $a['price_change_percentage']['h6']  ) ? floatval( $a['price_change_percentage']['h6']  ) : 0;
        $chg_24h    = isset( $a['price_change_percentage']['h24'] ) ? floatval( $a['price_change_percentage']['h24'] ) : 0;
        $liq        = isset( $a['reserve_in_usd'] ) ? floatval( $a['reserve_in_usd'] ) : 0;
        $addr       = $a['address'] ?? '';
        $created_at = $a['pool_created_at'] ?? '';

        return array(
            'name'    => $sym ?: '—',
            'pair'    => $name,
            'addr'    => $addr,
            'price'   => $price_usd,
            'fdv'     => $fdv,
            'liq'     => $liq,
            'vol_24h' => $vol24,
            'tx_buy'  => $tx24_buys,
            'tx_sell' => $tx24_sells,
            'chg_5m'  => $chg_5m,
            'chg_1h'  => $chg_1h,
            'chg_4h'  => $chg_6h, // GT exposes 6h, closest to our "4h" column
            'chg_24h' => $chg_24h,
            'created' => $created_at,
        );
    }

    /** Format large numbers as $1.23M / $45.6K / $0.00123 */
    private static function bt_fmt_money( $n ) {
        if ( $n >= 1e9 ) return '$' . number_format( $n / 1e9, 2 ) . 'B';
        if ( $n >= 1e6 ) return '$' . number_format( $n / 1e6, 2 ) . 'M';
        if ( $n >= 1e3 ) return '$' . number_format( $n / 1e3, 2 ) . 'K';
        if ( $n >= 1 )   return '$' . number_format( $n, 2 );
        if ( $n > 0 )    return '$' . rtrim( rtrim( number_format( $n, 8 ), '0' ), '.' );
        return '—';
    }

    /** Format price-change pct with coloring + arrow. */
    private static function bt_fmt_chg( $pct ) {
        if ( $pct === null || $pct === 0.0 ) return '<span class="bt-chg-neu">0.00%</span>';
        $cls = $pct >= 0 ? 'bt-chg-pos' : 'bt-chg-neg';
        $arr = $pct >= 0 ? '▲' : '▼';
        return '<span class="' . $cls . '">' . $arr . ' ' . number_format( abs( $pct ), 2 ) . '%</span>';
    }

    /** Humanize created_at timestamp into "2h", "3d", "1mo" etc. */
    private static function bt_fmt_age( $created_at ) {
        if ( empty( $created_at ) ) return '—';
        $ts = is_numeric( $created_at ) ? (int) $created_at : strtotime( $created_at );
        if ( ! $ts ) return '—';
        $diff = time() - $ts;
        if ( $diff < 60 )        return $diff . 's';
        if ( $diff < 3600 )      return round( $diff / 60 ) . 'm';
        if ( $diff < 86400 )     return round( $diff / 3600 ) . 'h';
        if ( $diff < 30 * 86400) return round( $diff / 86400 ) . 'd';
        if ( $diff < 365 * 86400) return round( $diff / ( 30 * 86400 ) ) . 'mo';
        return round( $diff / ( 365 * 86400 ) ) . 'y';
    }

    /**
     * Shortcode: [bt_dexscan_tokens filter="trending|new|gainers" network="eth|solana|bsc|base|..." limit="20"]
     * Renders a live table powered by GeckoTerminal. Filters are clickable (link to sub-page URLs).
     */
    public static function sc_dexscan_tokens( $atts ) {
        $a = shortcode_atts( array(
            'filter'  => 'trending',
            'network' => 'eth',
            'limit'   => 20,
        ), $atts );

        // URL query override: lets the network-filter chips work via navigation
        // (e.g. ?network=solana). Sanitized to the allowed set.
        if ( ! empty( $_GET['network'] ) ) {
            $req_net = sanitize_key( wp_unslash( $_GET['network'] ) );
            $allowed = array( 'eth', 'solana', 'bsc', 'base', 'polygon_pos', 'polygon', 'arbitrum', 'avax', 'avalanche', 'ethereum' );
            if ( in_array( $req_net, $allowed, true ) ) $a['network'] = $req_net;
        }

        $filter  = in_array( $a['filter'], array( 'trending', 'new', 'gainers' ), true ) ? $a['filter'] : 'trending';
        $network = preg_replace( '/[^a-z_]/', '', strtolower( $a['network'] ) ) ?: 'eth';
        $limit   = max( 1, min( 50, (int) $a['limit'] ) );

        $pools = self::fetch_dex_tokens( $filter, $network );
        $pools = array_slice( $pools, 0, $limit );

        ob_start();
        
?>
        <div class="bt-toktable-wrap">
          <table class="bt-toktable">
            <thead>
              <tr>
                <th class="c"></th>
                <th class="l"><?php esc_html_e( 'Name', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Age', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'FDV', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Liq', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '24h Txns', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '24h Vol', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Price USD', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '5m%', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '1h%', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '6h%', 'blockticker' ); ?></th>
                <th><?php esc_html_e( '24h%', 'blockticker' ); ?></th>
              </tr>
            </thead>
            <tbody>
            <?php if ( empty( $pools ) ): ?>
              <tr><td colspan="12" style="text-align:center;padding:40px 20px;color:var(--bt-text-3)">
                <div style="font-size:24px;margin-bottom:8px">⏳</div>
                <?php esc_html_e( 'Live DEX data loading. GeckoTerminal API cache is warming up — refresh in a moment.', 'blockticker' ); ?>
              </td></tr>
            <?php else: foreach ( $pools as $p ):
                $short_addr = strlen( $p['addr'] ) > 10 ? substr( $p['addr'], 0, 5 ) . '…' . substr( $p['addr'], -4 ) : $p['addr'];
                $tx_total   = $p['tx_buy'] + $p['tx_sell'];
                $initial    = strtoupper( substr( $p['name'], 0, 1 ) );
                $bg_palette = array( '#f97316', '#10b981', 'var(--bt-accent)', '#a78bfa', 'var(--bt-danger)', 'var(--bt-accent-warm)', '#ef4444', 'var(--bt-text-3)' );
                $bg         = $bg_palette[ crc32( $p['addr'] ) % count( $bg_palette ) ];
            ?>
              <tr>
                <td class="c"><button class="bt-star" aria-label="favorite">☆</button></td>
                <td class="l">
                  <div class="bt-tok-name-cell">
                    <div class="bt-tok-ico" style="background:<?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $initial ); ?></div>
                    <div>
                      <div class="bt-tok-name"><?php echo esc_html( $p['name'] ); ?></div>
                      <div class="bt-tok-addr"><?php echo esc_html( $short_addr ); ?></div>
                    </div>
                  </div>
                </td>
                <td class="bt-age"><?php echo esc_html( self::bt_fmt_age( $p['created'] ) ); ?></td>
                <td><?php echo esc_html( self::bt_fmt_money( $p['fdv'] ) ); ?></td>
                <td><?php echo esc_html( self::bt_fmt_money( $p['liq'] ) ); ?></td>
                <td>
                  <?php echo esc_html( number_format( $tx_total ) ); ?>
                  <div class="bt-txn-split">
                    <span class="up"><?php echo esc_html( number_format( $p['tx_buy'] ) ); ?></span> /
                    <span class="dn"><?php echo esc_html( number_format( $p['tx_sell'] ) ); ?></span>
                  </div>
                </td>
                <td><?php echo esc_html( self::bt_fmt_money( $p['vol_24h'] ) ); ?></td>
                <td><?php echo esc_html( self::bt_fmt_money( $p['price'] ) ); ?></td>
                <td><?php echo self::bt_fmt_chg( $p['chg_5m'] ); ?></td>
                <td><?php echo self::bt_fmt_chg( $p['chg_1h'] ); ?></td>
                <td><?php echo self::bt_fmt_chg( $p['chg_4h'] ); ?></td>
                <td><?php echo self::bt_fmt_chg( $p['chg_24h'] ); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <p style="color:var(--bt-text-3);font-size:11px;margin-top:12px;text-align:right">
          <?php
          /* translators: %s = data source name */
          printf( esc_html__( 'Live data from %s · cached 60s', 'blockticker' ), '<a href="https://www.geckoterminal.com/" target="_blank" rel="noopener" style="color:var(--bt-text-3)">GeckoTerminal</a>' );
          ?>
        </p>
        <?php
        return ob_get_clean();
    }



    // ========================================================
    // PUMP.FUN MEME EXPLORER (v50) - live token feed
    // ========================================================
    // Pump.fun exposes a JSON API at frontend-api-v3.pump.fun for
    // newly-launched Solana meme coins. Cached 60s.

    /**
     * Fetch meme coins from Pump.fun.
     *
     * @param string $filter 'new' | 'about_to_graduate' | 'graduated'
     * @param int    $limit
     */
    public static function fetch_pump_fun_coins( $filter = 'new', $limit = 15 ) {
        $cache_key = 'bt_pumpfun_' . md5( $filter . '|' . $limit );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        // Endpoint mapping
        $endpoints = array(
            'new'               => 'https://frontend-api-v3.pump.fun/coins?offset=0&limit=' . intval( $limit ) . '&sort=created_timestamp&order=DESC&includeNsfw=false',
            'about_to_graduate' => 'https://frontend-api-v3.pump.fun/coins?offset=0&limit=' . intval( $limit ) . '&sort=last_trade_timestamp&order=DESC&includeNsfw=false&filterBy=about_to_graduate',
            'graduated'         => 'https://frontend-api-v3.pump.fun/coins?offset=0&limit=' . intval( $limit ) . '&sort=market_cap&order=DESC&includeNsfw=false&filterBy=currently_live',
        );
        $url = $endpoints[ $filter ] ?? $endpoints['new'];

        $res = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'BlockTicker/1.0' ),
        ) );
        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            set_transient( $cache_key, array(), 60 );
            return array();
        }

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $body ) ) {
            set_transient( $cache_key, array(), 60 );
            return array();
        }

        $coins = array();
        foreach ( $body as $c ) {
            if ( ! is_array( $c ) ) continue;
            $coins[] = array(
                'mint'      => $c['mint'] ?? '',
                'symbol'    => $c['symbol'] ?? '',
                'name'      => $c['name'] ?? '',
                'image'     => $c['image_uri'] ?? '',
                'description' => $c['description'] ?? '',
                'market_cap'=> floatval( $c['usd_market_cap'] ?? 0 ),
                'created'   => isset( $c['created_timestamp'] ) ? intval( $c['created_timestamp'] / 1000 ) : 0,
                'holders'   => intval( $c['holders'] ?? 0 ),
                'reply_count' => intval( $c['reply_count'] ?? 0 ),
                'twitter'   => $c['twitter'] ?? '',
                'telegram'  => $c['telegram'] ?? '',
                'website'   => $c['website'] ?? '',
                'progress'  => floatval( $c['bonding_curve_progress'] ?? 0 ),
                'complete'  => (bool) ( $c['complete'] ?? false ),
                'king_of_the_hill' => (bool) ( $c['king_of_the_hill_timestamp'] ?? false ),
            );
        }

        set_transient( $cache_key, $coins, 60 );
        return $coins;
    }

    /** Format market cap: $12.3K / $1.2M etc. */
    private static function bt_fmt_mc( $n ) {
        if ( $n >= 1e9 ) return '$' . number_format( $n / 1e9, 2 ) . 'B';
        if ( $n >= 1e6 ) return '$' . number_format( $n / 1e6, 2 ) . 'M';
        if ( $n >= 1e3 ) return '$' . number_format( $n / 1e3, 1 ) . 'K';
        return '$' . number_format( $n, 2 );
    }

    /** Humanize age in seconds to "25s" / "4m" / "2h" / "1d". */
    private static function bt_fmt_age_seconds( $ts ) {
        if ( ! $ts ) return '-';
        $diff = time() - $ts;
        if ( $diff < 60 )    return $diff . 's';
        if ( $diff < 3600 )  return round( $diff / 60 ) . 'm';
        if ( $diff < 86400 ) return round( $diff / 3600 ) . 'h';
        return round( $diff / 86400 ) . 'd';
    }

    /**
     * Shortcode: [bt_meme_tokens filter="new|about_to_graduate|graduated" limit="12"]
     * Renders a single column of live Pump.fun coins. Combined 3x in the Meme
     * Explorer page to produce the New/Graduating/Graduated layout.
     */
    public static function sc_meme_tokens( $atts ) {
        $a = shortcode_atts( array(
            'filter' => 'new',
            'limit'  => 12,
        ), $atts );

        $filter = in_array( $a['filter'], array( 'new', 'about_to_graduate', 'graduated' ), true ) ? $a['filter'] : 'new';
        $limit  = max( 1, min( 30, (int) $a['limit'] ) );

        $coins = self::fetch_pump_fun_coins( $filter, $limit );

        ob_start();
        if ( empty( $coins ) ) {
            echo '<div style="padding:40px 20px;text-align:center;color:var(--bt-text-3);font-size:12.5px">';
            echo '<div style="font-size:24px;margin-bottom:8px">&#x231B;</div>';
            echo esc_html__( 'Pump.fun feed warming up - refresh in a moment.', 'blockticker' );
            echo '</div>';
            return ob_get_clean();
        }

        $bg_palette = array( '#f97316', '#10b981', 'var(--bt-accent)', '#a78bfa', 'var(--bt-danger)', 'var(--bt-accent-warm)', '#ef4444', 'var(--bt-text-3)' );

        foreach ( $coins as $c ) {
            $initial = strtoupper( substr( $c['symbol'] ?: $c['name'] ?: 'X', 0, 1 ) );
            $bg      = $bg_palette[ crc32( $c['mint'] ) % count( $bg_palette ) ];
            $age     = self::bt_fmt_age_seconds( $c['created'] );
            $mc      = self::bt_fmt_mc( $c['market_cap'] );
            $name    = $c['name'] ?: $c['symbol'];
            $sym     = $c['symbol'];
            $image   = esc_url( $c['image'] );
            $mint    = esc_attr( $c['mint'] );
            $progress = min( 100, max( 0, floatval( $c['progress'] ) ) );
            $pump_url = 'https://pump.fun/' . rawurlencode( $c['mint'] );

            // Social icons
            $socials = '';
            if ( ! empty( $c['twitter'] ) )  $socials .= ' &#x1D54F;';
            if ( ! empty( $c['telegram'] ) ) $socials .= ' &#x2708;';
            if ( ! empty( $c['website'] ) )  $socials .= ' &#x1F310;';

            ?>
            <a href="<?php echo esc_url( $pump_url ); ?>" target="_blank" rel="noopener"
               class="bt-meme-item" style="text-decoration:none;color:inherit;display:grid;grid-template-columns:40px 1fr auto;gap:10px">
              <?php if ( $image ): ?>
                <img src="<?php echo $image; ?>" alt="<?php echo esc_attr( $sym ); ?>"
                     class="bt-meme-ico" loading="lazy"
                     style="object-fit:cover;width:40px;height:40px;border-radius:0">
              <?php else: ?>
                <div class="bt-meme-ico" style="background:<?php echo esc_attr( $bg ); ?>;color:#fff">
                  <?php echo esc_html( $initial ); ?>
                </div>
              <?php endif; ?>
              <div class="bt-meme-main">
                <div class="bt-meme-name">
                  <?php echo esc_html( $sym ); ?>
                  <?php if ( $name && $name !== $sym ): ?>
                    <span style="color:var(--bt-text-3);font-size:10px;font-weight:500"><?php echo esc_html( mb_substr( $name, 0, 16 ) ); ?></span>
                  <?php endif; ?>
                </div>
                <div class="bt-meme-meta">
                  <?php echo esc_html( $age ); ?>
                  <?php if ( $c['holders'] > 0 ): ?>
                    &middot; <?php /* translators: %d is holder count */ ?>
                    <?php printf( esc_html__( '%d holders', 'blockticker' ), $c['holders'] ); ?>
                  <?php endif; ?>
                  <?php if ( $c['reply_count'] > 0 ): ?>
                    &middot; &#x1F4AC; <?php echo esc_html( $c['reply_count'] ); ?>
                  <?php endif; ?>
                  <?php if ( $progress > 0 && ! $c['complete'] ): ?>
                    <span class="bt-meme-prog" title="<?php echo esc_attr( number_format( $progress, 1 ) . '%' ); ?>">
                      <span style="width:<?php echo esc_attr( $progress ); ?>%"></span>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="bt-meme-right">
                <span class="bt-meme-right-line">MC <strong><?php echo esc_html( $mc ); ?></strong></span>
                <?php if ( $c['king_of_the_hill'] ): ?>
                  <span class="bt-meme-right-line" style="color:var(--bt-accent-warm)">&#x1F451; King</span>
                <?php endif; ?>
                <?php if ( $socials ): ?>
                  <span class="bt-meme-right-line" style="color:var(--bt-text-3);font-size:10px"><?php echo $socials; ?></span>
                <?php endif; ?>
              </div>
            </a>
            <?php
        }
        return ob_get_clean();
    }



    // ==================================================================
    // MEME EXPLORER (v50) - pump.fun-style 3-column layout
    // ==================================================================
    // Uses GeckoTerminal Solana pools as a reliable free data source
    // (pump.fun frontend API is unstable for 3rd-party consumers).
    // Buckets tokens by age:
    //   New Creations    : created in last 2h
    //   About to Graduate: liquidity $10k-$80k AND age < 48h (matches pump.fun graduation threshold)
    //   Graduated        : liquidity > $80k (made it past graduation)

    public static function fetch_meme_buckets() {
        $cache_key = 'bt_meme_buckets';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        // Pull pages 1-3 of Solana new_pools for broader token coverage
        $all_pools = array();
        for ( $page = 1; $page <= 3; $page++ ) {
            $res = wp_remote_get(
                "https://api.geckoterminal.com/api/v2/networks/solana/new_pools?page={$page}",
                array(
                    'timeout' => 10,
                    'headers' => array( 'Accept' => 'application/json;version=20230302' ),
                )
            );
            if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) continue;
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( empty( $body['data'] ) ) continue;
            foreach ( $body['data'] as $pool ) {
                $n = self::normalize_gt_pool( $pool );
                if ( $n ) $all_pools[] = $n;
            }
        }

        // Also pull trending (higher-liquidity graduated ones)
        $res_t = wp_remote_get(
            'https://api.geckoterminal.com/api/v2/networks/solana/trending_pools?page=1',
            array(
                'timeout' => 10,
                'headers' => array( 'Accept' => 'application/json;version=20230302' ),
            )
        );
        if ( ! is_wp_error( $res_t ) && wp_remote_retrieve_response_code( $res_t ) === 200 ) {
            $body_t = json_decode( wp_remote_retrieve_body( $res_t ), true );
            if ( ! empty( $body_t['data'] ) ) {
                foreach ( $body_t['data'] as $pool ) {
                    $n = self::normalize_gt_pool( $pool );
                    if ( $n ) $all_pools[] = $n;
                }
            }
        }

        // Dedupe by address
        $seen  = array();
        $pools = array();
        foreach ( $all_pools as $p ) {
            if ( empty( $p['addr'] ) || isset( $seen[ $p['addr'] ] ) ) continue;
            $seen[ $p['addr'] ] = true;
            $pools[] = $p;
        }

        // Bucket
        $new_creations = array();
        $graduating    = array();
        $graduated     = array();
        $now           = time();

        foreach ( $pools as $p ) {
            $ts  = ! empty( $p['created'] ) ? strtotime( $p['created'] ) : 0;
            $age = $ts ? ( $now - $ts ) : PHP_INT_MAX;
            $liq = floatval( $p['liq'] );

            if ( $liq >= 80000 ) {
                $graduated[] = $p;
            } elseif ( $liq >= 10000 && $age < 48 * 3600 ) {
                $graduating[] = $p;
            } elseif ( $age < 2 * 3600 ) {
                $new_creations[] = $p;
            }
        }

        // Sort each bucket: new by age (youngest first), graduating by liq desc, graduated by vol desc
        usort( $new_creations, function( $a, $b ) {
            return strtotime( $b['created'] ) <=> strtotime( $a['created'] );
        } );
        usort( $graduating, function( $a, $b ) { return $b['liq'] <=> $a['liq']; } );
        usort( $graduated,  function( $a, $b ) { return $b['vol_24h'] <=> $a['vol_24h']; } );

        $buckets = array(
            'new'        => array_slice( $new_creations, 0, 15 ),
            'graduating' => array_slice( $graduating,    0, 15 ),
            'graduated'  => array_slice( $graduated,     0, 15 ),
            'fetched_at' => $now,
        );

        set_transient( $cache_key, $buckets, 90 );
        return $buckets;
    }


    public static function sc_meme_explorer( $atts ) {
        $buckets = self::fetch_meme_buckets();

        ob_start();
        ?>
        <div class="bt-meme-cols-live">
          <?php
          $columns = array(
              'new'        => array( 'title' => __( 'New Creations',    'blockticker' ), 'note' => __( 'Last 2h on Solana',      'blockticker' ) ),
              'graduating' => array( 'title' => __( 'About to Graduate', 'blockticker' ), 'note' => __( '$10K-$80K liquidity',   'blockticker' ) ),
              'graduated'  => array( 'title' => __( 'Graduated',        'blockticker' ), 'note' => __( '$80K+ liquidity',       'blockticker' ) ),
          );
          foreach ( $columns as $key => $meta ):
              $items = $buckets[ $key ] ?? array();
          ?>
          <div class="bt-meme-col">
            <div class="bt-meme-col-head">
              <div>
                <div class="bt-meme-col-title"><?php echo esc_html( $meta['title'] ); ?></div>
                <div style="font-size:10.5px;color:var(--bt-text-3);margin-top:2px;font-family:var(--bt-font-mono)"><?php echo esc_html( $meta['note'] ); ?></div>
              </div>
              <span class="bt-meme-col-count"><?php echo count( $items ); ?></span>
            </div>
            <div class="bt-meme-col-body">
              <?php if ( empty( $items ) ): ?>
                <div style="padding:30px 16px;text-align:center;color:var(--bt-text-3);font-size:12.5px">
                  <div style="font-size:24px;margin-bottom:6px;opacity:.4">⏳</div>
                  <?php esc_html_e( 'Live data loading...', 'blockticker' ); ?>
                </div>
              <?php else:
                foreach ( $items as $p ):
                    $sym    = $p['name'] ?: '—';
                    $initial = strtoupper( substr( $sym, 0, 1 ) );
                    $palette = array( '#f97316', '#10b981', 'var(--bt-accent)', '#a78bfa', 'var(--bt-danger)', 'var(--bt-accent-warm)', '#ef4444' );
                    $bg      = $palette[ crc32( $p['addr'] ) % count( $palette ) ];
                    $age     = self::bt_fmt_age( $p['created'] );
                    $chg24   = floatval( $p['chg_24h'] );
                    $chg_cls = $chg24 >= 0 ? 'pos' : 'neg';
                    $chg_txt = ( $chg24 >= 0 ? '+' : '' ) . number_format( $chg24, 1 ) . '%';

                    // Progress bar for graduating tokens (0-100% of $80K threshold)
                    $progress = null;
                    if ( $key === 'graduating' ) {
                        $progress = min( 100, max( 0, ( $p['liq'] / 80000 ) * 100 ) );
                    }
              ?>
              <a href="https://www.geckoterminal.com/solana/pools/<?php echo esc_attr( $p['addr'] ); ?>" target="_blank" rel="noopener" class="bt-meme-item">
                <div class="bt-meme-ico" style="background:<?php echo esc_attr( $bg ); ?>;color:#fff"><?php echo esc_html( $initial ); ?></div>
                <div class="bt-meme-main">
                  <div class="bt-meme-name"><?php echo esc_html( $sym ); ?></div>
                  <div class="bt-meme-meta">
                    <span><?php echo esc_html( $age ); ?></span>
                    <span class="<?php echo esc_attr( $chg_cls ); ?>"><?php echo esc_html( $chg_txt ); ?></span>
                    <?php if ( $progress !== null ): ?>
                    <span class="bt-meme-prog" title="<?php echo esc_attr( round( $progress ) . '% to graduation' ); ?>"><span style="width:<?php echo esc_attr( round( $progress ) ); ?>%"></span></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="bt-meme-right">
                  <span class="bt-meme-right-line">V <strong><?php echo esc_html( self::bt_fmt_money( $p['vol_24h'] ) ); ?></strong></span>
                  <span class="bt-meme-right-line"><small>Liq <?php echo esc_html( self::bt_fmt_money( $p['liq'] ) ); ?></small></span>
                </div>
              </a>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <p style="color:var(--bt-text-3);font-size:11px;margin-top:12px;text-align:right">
          <?php printf( esc_html__( 'Live data from %s · cached 90s', 'blockticker' ), '<a href="https://www.geckoterminal.com/solana" target="_blank" rel="noopener" style="color:var(--bt-text-3)">GeckoTerminal Solana</a>' ); ?>
        </p>
        <?php
        return ob_get_clean();
    }

    /* ======================================================================
     * DEX TOKEN SCANNER v111.0
     * DexScreener (real-time buys/sells, multi-chain) + RugCheck (safety)
     * + auto-write to wp_bt_price_history + advanced filter shortcode
     * ====================================================================== */

    /**
     * Fetch trending tokens from DexScreener across chains.
     * Free API, no key required. Returns normalised pool objects.
     *
     * @param  string $chain  'solana'|'ethereum'|'base'|'bsc'|'arbitrum'|'all'
     * @param  int    $limit  Max results.
     * @return array
     */
    public static function fetch_dexscreener_trending( string $chain = 'solana', int $limit = 30 ): array {
        $cache_key = 'bt_dexs_trending_' . $chain;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        // DexScreener token profiles endpoint (free, no auth).
        $url = 'https://api.dexscreener.com/token-profiles/latest/v1';
        $res = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );

        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            // Fallback: search popular Solana tokens.
            return self::fetch_dexscreener_search( $chain === 'solana' ? 'SOL' : 'ETH', $limit );
        }

        $body  = json_decode( wp_remote_retrieve_body( $res ), true );
        $items = is_array( $body ) ? $body : array();

        // Filter by chain.
        if ( $chain !== 'all' ) {
            $items = array_filter( $items, fn( $t ) => strtolower( $t['chainId'] ?? '' ) === $chain );
        }

        // Fetch pair data for each token address.
        $results = array();
        foreach ( array_slice( array_values( $items ), 0, min( $limit, 20 ) ) as $t ) {
            $addr = $t['tokenAddress'] ?? '';
            if ( ! $addr ) continue;
            $pairs = self::fetch_dexscreener_pairs_for_token( $t['chainId'] ?? $chain, $addr );
            if ( $pairs ) $results[] = $pairs[0]; // best pair
        }

        set_transient( $cache_key, $results, 120 ); // 2-min cache
        return $results;
    }

    /**
     * Search DexScreener for a query string.
     */
    public static function fetch_dexscreener_search( string $query, int $limit = 20 ): array {
        $cache_key = 'bt_dexs_search_' . md5( $query );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $url = 'https://api.dexscreener.com/latest/dex/search?' . http_build_query( array( 'q' => $query ) );
        $res = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );

        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) return array();

        $body  = json_decode( wp_remote_retrieve_body( $res ), true );
        $pairs = array_slice( $body['pairs'] ?? array(), 0, $limit );
        $normalised = array_map( array( __CLASS__, 'normalise_dexscreener_pair' ), $pairs );
        $normalised = array_filter( $normalised );

        set_transient( $cache_key, $normalised, 120 );
        return array_values( $normalised );
    }

    /**
     * Fetch pairs for a token address on a specific chain.
     */
    private static function fetch_dexscreener_pairs_for_token( string $chain, string $addr ): array {
        $url = "https://api.dexscreener.com/latest/dex/tokens/{$addr}";
        $res = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) return array();
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        return array_filter( array_map( array( __CLASS__, 'normalise_dexscreener_pair' ), $body['pairs'] ?? array() ) );
    }

    /**
     * Normalise a DexScreener pair object to our internal schema.
     *
     * Returns:
     * {
     *   addr, name, symbol, chain, dex,
     *   price_usd, price_change_{5m,1h,6h,24h},
     *   vol_24h, liq, fdv, market_cap,
     *   buys_24h, sells_24h, buy_pressure,   ← key new fields
     *   created_at, url,
     *   base_token_addr
     * }
     */
    public static function normalise_dexscreener_pair( ?array $pair ): ?array {
        if ( empty( $pair ) || empty( $pair['pairAddress'] ) ) return null;

        $base = $pair['baseToken'] ?? array();
        $pc   = $pair['priceChange'] ?? array();
        $txns = $pair['txns'] ?? array();
        $h24  = $txns['h24'] ?? array();
        $buys  = intval( $h24['buys'] ?? 0 );
        $sells = intval( $h24['sells'] ?? 0 );
        $total_txns = $buys + $sells;
        $buy_pct = $total_txns > 0 ? round( ( $buys / $total_txns ) * 100 ) : 50;

        return array(
            'addr'          => $pair['pairAddress'],
            'base_token_addr' => $base['address'] ?? '',
            'name'          => $base['name'] ?? '?',
            'symbol'        => $base['symbol'] ?? '?',
            'chain'         => $pair['chainId'] ?? '',
            'dex'           => $pair['dexId'] ?? '',
            'price_usd'     => floatval( $pair['priceUsd'] ?? 0 ),
            'price_change_5m'  => floatval( $pc['m5'] ?? 0 ),
            'price_change_1h'  => floatval( $pc['h1'] ?? 0 ),
            'price_change_6h'  => floatval( $pc['h6'] ?? 0 ),
            'price_change_24h' => floatval( $pc['h24'] ?? 0 ),
            'vol_24h'       => floatval( $pair['volume']['h24'] ?? 0 ),
            'liq'           => floatval( $pair['liquidity']['usd'] ?? 0 ),
            'fdv'           => floatval( $pair['fdv'] ?? 0 ),
            'market_cap'    => floatval( $pair['marketCap'] ?? 0 ),
            'buys_24h'      => $buys,
            'sells_24h'     => $sells,
            'buy_pressure'  => $buy_pct,  // % of 24h txns that are buys
            'created_at'    => $pair['pairCreatedAt'] ? gmdate( 'Y-m-d H:i:s', intval( $pair['pairCreatedAt'] ) / 1000 ) : null,
            'url'           => $pair['url'] ?? "https://dexscreener.com/{$pair['chainId']}/{$pair['pairAddress']}",
        );
    }

    /**
     * Fetch a RugCheck safety summary for a Solana token mint address.
     * Returns array with: score (0-1000, lower=safer), risk (low|medium|high|critical), risks[].
     * Free API, no key required.
     *
     * @param  string $mint  Token mint address.
     * @return array|null    null on API failure.
     */
    public static function fetch_rugcheck_score( string $mint ): ?array {
        $cache_key = 'bt_rugcheck_' . substr( $mint, 0, 20 );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached ?: null;

        $url = "https://api.rugcheck.xyz/v1/tokens/{$mint}/report/summary";
        $res = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );

        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            set_transient( $cache_key, false, 300 ); // negative cache 5min
            return null;
        }

        $body  = json_decode( wp_remote_retrieve_body( $res ), true );
        $score = intval( $body['score'] ?? 500 );
        $risk  = $score < 200 ? 'low' : ( $score < 500 ? 'medium' : ( $score < 800 ? 'high' : 'critical' ) );

        $result = array(
            'score'  => $score,
            'risk'   => $risk,
            'risks'  => array_column( $body['risks'] ?? array(), 'name' ),
            'mint'   => $mint,
        );

        set_transient( $cache_key, $result, 3600 ); // cache 1hr
        return $result;
    }

    /**
     * Write a DexScreener token snapshot to wp_bt_price_history so it
     * appears in bt_price_chart and the correlation heatmap.
     *
     * Symbol format: "DSX:{SYMBOL}" e.g. "DSX:BONK"
     *
     * @param  array  $token  Normalised DexScreener pair object.
     * @return bool
     */
    public static function write_token_to_history( array $token ): bool {
        if ( empty( $token['price_usd'] ) || empty( $token['symbol'] ) ) return false;
        if ( ! class_exists( 'BT_Database' ) ) return false;

        $sym = 'DSX:' . strtoupper( $token['symbol'] );
        return (bool) BT_Database::insert_price_snapshot( array(
            'symbol'         => $sym,
            'asset_class'    => 'dex',
            'price_usd'      => $token['price_usd'],
            'volume_24h'     => $token['vol_24h'],
            'market_cap'     => $token['market_cap'] ?: $token['fdv'],
            'pct_change_24h' => $token['price_change_24h'],
        ) );
    }

    /**
     * Shortcode: [bt_dex_scanner]
     *
     * Advanced multi-chain DEX scanner with:
     * - Chain selector (Solana, Base, Ethereum, BSC, Arbitrum)
     * - Filter bar: min liquidity, min volume, max age (h), buy pressure slider
     * - RugCheck safety badge (Solana tokens only)
     * - Buy/sell pressure bar
     * - Buys vs Sells count
     * - Auto-snapshot to wp_bt_price_history (top 10 tokens per render)
     *
     * Attributes:
     *   chain         solana|ethereum|base|bsc|arbitrum|all  (default: solana)
     *   min_liq       Minimum liquidity USD (default: 10000)
     *   min_vol       Minimum 24h volume USD (default: 5000)
     *   max_age_h     Maximum pool age in hours 0=any (default: 0)
     *   min_buy_pct   Minimum buy pressure % (default: 0)
     *   show_safety   1|0 — show RugCheck badge on Solana (default: 1)
     *   limit         Max rows (default: 20)
     *   title         Card heading
     */
    public static function sc_dex_scanner( $atts ) {
        $a = shortcode_atts( array(
            'chain'        => 'solana',
            'min_liq'      => 10000,
            'min_vol'      => 5000,
            'max_age_h'    => 0,
            'min_buy_pct'  => 0,
            'show_safety'  => 1,
            'limit'        => 20,
            'title'        => 'DEX Token Scanner',
        ), $atts );

        $chain       = sanitize_key( $a['chain'] );
        $min_liq     = max( 0, floatval( $a['min_liq'] ) );
        $min_vol     = max( 0, floatval( $a['min_vol'] ) );
        $max_age_h   = max( 0, intval( $a['max_age_h'] ) );
        $min_buy_pct = max( 0, min( 100, intval( $a['min_buy_pct'] ) ) );
        $show_safety = (bool) intval( $a['show_safety'] );
        $limit       = max( 1, min( 50, intval( $a['limit'] ) ) );

        // Fetch + filter.
        $tokens = self::fetch_dexscreener_trending( $chain, 50 );
        if ( empty( $tokens ) ) {
            $tokens = self::fetch_dexscreener_search( $chain === 'solana' ? 'meme' : 'ETH', 30 );
        }

        // Apply filters.
        $tokens = array_filter( $tokens, function( $t ) use ( $min_liq, $min_vol, $max_age_h, $min_buy_pct ) {
            if ( $t['liq'] < $min_liq ) return false;
            if ( $t['vol_24h'] < $min_vol ) return false;
            if ( $t['buy_pressure'] < $min_buy_pct ) return false;
            if ( $max_age_h > 0 && $t['created_at'] ) {
                $age_h = ( time() - strtotime( $t['created_at'] ) ) / 3600;
                if ( $age_h > $max_age_h ) return false;
            }
            return true;
        } );

        // Sort by volume desc.
        usort( $tokens, fn( $a, $b ) => $b['vol_24h'] <=> $a['vol_24h'] );
        $tokens = array_slice( array_values( $tokens ), 0, $limit );

        // Auto-snapshot top 10 to price history.
        foreach ( array_slice( $tokens, 0, 10 ) as $t ) {
            self::write_token_to_history( $t );
        }

        // Fetch RugCheck scores for Solana tokens (first 10 only to stay fast).
        $safety = array();
        if ( $show_safety && $chain === 'solana' ) {
            foreach ( array_slice( $tokens, 0, 10 ) as $t ) {
                $mint = $t['base_token_addr'] ?? '';
                if ( $mint ) $safety[ $t['addr'] ] = self::fetch_rugcheck_score( $mint );
            }
        }

        $chain_labels = array(
            'solana' => '⬡ Solana', 'ethereum' => 'Ξ Ethereum',
            'base' => '🔵 Base', 'bsc' => '🟡 BSC',
            'arbitrum' => '🔷 Arbitrum', 'all' => '🌐 All chains',
        );
        $chain_label = $chain_labels[ $chain ] ?? ucfirst( $chain );

        ob_start(); ?>
        <div class="bt-dexs-wrap">
            <?php if ( $a['title'] ) : ?>
            <div class="bt-dexs-header">
                <h3 class="bt-dexs-title">🔍 <?php echo esc_html( $a['title'] ); ?></h3>
                <span class="bt-dexs-chain-badge"><?php echo esc_html( $chain_label ); ?></span>
                <span class="bt-dexs-meta"><?php echo esc_html( count( $tokens ) ); ?> tokens · <?php esc_html_e( 'DexScreener live', 'blockticker' ); ?></span>
            </div>
            <?php endif;

            if ( empty( $tokens ) ) : ?>
            <div class="bt-dexs-empty">
                <p>📡 <?php esc_html_e( 'No tokens match the current filters. Try lowering the minimum liquidity or volume.', 'blockticker' ); ?></p>
            </div>
            <?php else : ?>

            <div class="bt-dexs-table-wrap">
                <table class="bt-dexs-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Token', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Price', 'blockticker' ); ?></th>
                            <th>5m</th>
                            <th>1h</th>
                            <th>24h</th>
                            <th><?php esc_html_e( 'Volume 24h', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Liquidity', 'blockticker' ); ?></th>
                            <th><?php esc_html_e( 'Buy / Sell', 'blockticker' ); ?></th>
                            <?php if ( $show_safety && $chain === 'solana' ) : ?>
                            <th><?php esc_html_e( 'Safety', 'blockticker' ); ?></th>
                            <?php endif; ?>
                            <th><?php esc_html_e( 'DEX', 'blockticker' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $tokens as $rank => $t ) :
                        $pct5  = $t['price_change_5m'];
                        $pct1h = $t['price_change_1h'];
                        $pct24 = $t['price_change_24h'];
                        $bp    = $t['buy_pressure'];
                        $saf   = $safety[ $t['addr'] ] ?? null;
                        $age_str = $t['created_at'] ? self::bt_fmt_age( $t['created_at'] ) : '—';

                        $price_fmt = $t['price_usd'] >= 1
                            ? '$' . number_format( $t['price_usd'], 4 )
                            : ( $t['price_usd'] >= 0.001 ? '$' . number_format( $t['price_usd'], 6 ) : number_format( $t['price_usd'], 10 ) );

                        // Safety badge config.
                        $saf_label = '—'; $saf_cls = '';
                        if ( $saf ) {
                            $saf_map   = array( 'low' => '✅ Low', 'medium' => '⚠️ Medium', 'high' => '🔴 High', 'critical' => '💀 Critical' );
                            $saf_label = $saf_map[ $saf['risk'] ] ?? '?';
                            $saf_cls   = 'bt-dexs-saf-' . $saf['risk'];
                        }
                    ?>
                        <tr>
                            <td class="bt-dexs-rank"><?php echo esc_html( $rank + 1 ); ?></td>
                            <td class="bt-dexs-token">
                                <a href="<?php echo esc_url( $t['url'] ); ?>" target="_blank" rel="noopener" class="bt-dexs-name">
                                    <?php echo esc_html( $t['symbol'] ); ?>
                                </a>
                                <span class="bt-dexs-fullname"><?php echo esc_html( $t['name'] ); ?></span>
                                <span class="bt-dexs-age"><?php echo esc_html( $age_str ); ?></span>
                            </td>
                            <td class="bt-dexs-price"><?php echo esc_html( $price_fmt ); ?></td>
                            <td class="bt-dexs-chg <?php echo $pct5 >= 0 ? 'pos' : 'neg'; ?>"><?php echo esc_html( ( $pct5 >= 0 ? '+' : '' ) . number_format( $pct5, 1 ) . '%' ); ?></td>
                            <td class="bt-dexs-chg <?php echo $pct1h >= 0 ? 'pos' : 'neg'; ?>"><?php echo esc_html( ( $pct1h >= 0 ? '+' : '' ) . number_format( $pct1h, 1 ) . '%' ); ?></td>
                            <td class="bt-dexs-chg <?php echo $pct24 >= 0 ? 'pos' : 'neg'; ?>"><?php echo esc_html( ( $pct24 >= 0 ? '+' : '' ) . number_format( $pct24, 1 ) . '%' ); ?></td>
                            <td class="bt-dexs-num"><?php echo esc_html( self::bt_fmt_money( $t['vol_24h'] ) ); ?></td>
                            <td class="bt-dexs-num"><?php echo esc_html( self::bt_fmt_money( $t['liq'] ) ); ?></td>
                            <td class="bt-dexs-buysell">
                                <div class="bt-dexs-pressure-bar">
                                    <div class="bt-dexs-pressure-fill" style="width:<?php echo esc_attr( $bp ); ?>%"></div>
                                </div>
                                <div class="bt-dexs-bsscore">
                                    <span class="pos"><?php echo esc_html( number_format( $t['buys_24h'] ) ); ?>B</span>
                                    /
                                    <span class="neg"><?php echo esc_html( number_format( $t['sells_24h'] ) ); ?>S</span>
                                </div>
                            </td>
                            <?php if ( $show_safety && $chain === 'solana' ) : ?>
                            <td class="bt-dexs-safety <?php echo esc_attr( $saf_cls ); ?>"><?php echo $saf_label; // phpcs:ignore ?></td>
                            <?php endif; ?>
                            <td class="bt-dexs-dex"><?php echo esc_html( strtoupper( $t['dex'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>

            <p class="bt-dexs-footer">
                <?php esc_html_e( 'Data: DexScreener · Safety: RugCheck · Prices update every 2 minutes', 'blockticker' ); ?>
                <?php if ( $show_safety && $chain === 'solana' ) : ?>
                · <?php esc_html_e( 'Always DYOR — safety scores are informational only.', 'blockticker' ); ?>
                <?php endif; ?>
            </p>
        </div>

        <style>
        .bt-dexs-wrap{font-family:inherit;margin:16px 0}
        .bt-dexs-header{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
        .bt-dexs-title{font-size:16px;font-weight:700;margin:0}
        .bt-dexs-chain-badge{font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(0,255,102,.15);color:var(--bt-accent);border:1px solid rgba(0,255,102,.3)}
        .bt-dexs-meta{font-size:11px;color:var(--bt-text-3);margin-left:auto}
        .bt-dexs-table-wrap{overflow-x:auto}
        .bt-dexs-table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}
        .bt-dexs-table th{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--bt-text-3);padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left}
        .bt-dexs-table td{padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
        .bt-dexs-table tr:hover td{background:rgba(255,255,255,.02)}
        .bt-dexs-rank{color:var(--bt-text-3);min-width:24px}
        .bt-dexs-token{min-width:120px}
        .bt-dexs-name{font-weight:700;color:var(--bt-text);text-decoration:none;display:block}
        .bt-dexs-name:hover{color:var(--bt-accent)}
        .bt-dexs-fullname{font-size:10px;color:var(--bt-text-3);display:block}
        .bt-dexs-age{font-size:10px;color:var(--bt-text-3);display:block}
        .bt-dexs-price{font-weight:700;color:var(--bt-text);font-variant-numeric:tabular-nums;min-width:80px}
        .bt-dexs-chg{font-weight:700;font-variant-numeric:tabular-nums;min-width:52px}
        .bt-dexs-chg.pos{color:#22c55e}.bt-dexs-chg.neg{color:#ef4444}
        .bt-dexs-num{color:var(--bt-text-2);font-variant-numeric:tabular-nums}
        .bt-dexs-buysell{min-width:100px}
        .bt-dexs-pressure-bar{height:6px;border-radius:3px;background:rgba(239,68,68,.3);margin-bottom:4px;overflow:hidden}
        .bt-dexs-pressure-fill{height:100%;background:linear-gradient(to right,#ef4444,#22c55e);border-radius:3px;transition:width .3s}
        .bt-dexs-bsscore{font-size:11px;font-variant-numeric:tabular-nums}
        .bt-dexs-bsscore .pos{color:#22c55e}.bt-dexs-bsscore .neg{color:#ef4444}
        .bt-dexs-safety{font-size:11px;font-weight:600;min-width:80px}
        .bt-dexs-saf-low{color:#22c55e}.bt-dexs-saf-medium{color:var(--bt-accent-warm)}
        .bt-dexs-saf-high{color:#ef4444}.bt-dexs-saf-critical{color:#dc2626}
        .bt-dexs-dex{font-size:10px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.04em}
        .bt-dexs-empty{padding:32px;text-align:center;background:rgba(255,255,255,.02);border-radius:0;color:var(--bt-text-3)}
        .bt-dexs-footer{font-size:11px;color:var(--bt-text-3);margin:8px 0 0;font-style:italic}
        </style>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * [bt_top_traders] — Live top-performing wallets via DexScreener
     * Uses top boosted + high-volume pairs; refreshed every 5 min.
     * ------------------------------------------------------------------ */
    public static function sc_top_traders( $atts ) {
        $a = shortcode_atts( array(
            'limit'   => 20,
            'network' => '', // '' = all, 'solana', 'ethereum', 'bsc', 'base'
        ), $atts );

        $limit   = max( 5, min( 50, intval( $a['limit'] ) ) );
        $network = sanitize_key( $a['network'] );

        // Cache key per network
        $cache_key = 'bt_top_traders_' . md5( $network . '|' . $limit );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // Fetch top boosted tokens from DexScreener (free, no key needed)
        $rows = array();
        $ctx  = stream_context_create( array( 'http' => array( 'timeout' => 8, 'user_agent' => 'BlockTicker/1.0' ) ) );

        // Use multiple endpoints: boosted + trending
        $sources = array(
            'https://api.dexscreener.com/token-boosts/top/v1',
            'https://api.dexscreener.com/token-boosts/latest/v1',
        );

        foreach ( $sources as $src_url ) {
            $json = @file_get_contents( $src_url, false, $ctx );
            if ( ! $json ) continue;
            $data = json_decode( $json, true );
            if ( ! is_array( $data ) ) continue;

            foreach ( $data as $token ) {
                if ( empty( $token['tokenAddress'] ) ) continue;
                if ( $network && strtolower( $token['chainId'] ?? '' ) !== $network ) continue;
                $rows[] = $token;
            }
        }

        // Fetch trending pairs for volume data
        $trending_url = 'https://api.dexscreener.com/latest/dex/search?q=USD';
        $t_json = @file_get_contents( $trending_url, false, $ctx );
        $trending_pairs = array();
        if ( $t_json ) {
            $t_data = json_decode( $t_json, true );
            $pairs  = $t_data['pairs'] ?? array();
            usort( $pairs, fn($a,$b) => ($b['volume']['h24']??0) <=> ($a['volume']['h24']??0) );
            $trending_pairs = array_slice( $pairs, 0, 30 );
        }

        // Merge and deduplicate by address
        $seen    = array();
        $traders = array();
        $chain_colors = array(
            'solana'   => '#9945ff', 'ethereum' => '#627eea',
            'bsc'      => '#f0b90b', 'base'     => '#0052ff',
            'arbitrum' => '#28a0f0', 'polygon'  => '#8247e5',
        );

        foreach ( $rows as $token ) {
            $addr = strtolower( $token['tokenAddress'] ?? $token['address'] ?? '' );
            if ( ! $addr || isset( $seen[$addr] ) ) continue;
            $seen[$addr] = true;

            $chain  = strtolower( $token['chainId'] ?? 'unknown' );
            $symbol = strtoupper( $token['symbol'] ?? substr( $addr, 0, 6 ) );
            $boost  = intval( $token['totalAmount'] ?? $token['amount'] ?? 0 );

            // Find matching pair for price/volume data
            $pair_data = null;
            foreach ( $trending_pairs as $p ) {
                if ( strtolower( $p['baseToken']['address'] ?? '' ) === $addr ) {
                    $pair_data = $p;
                    break;
                }
            }

            $price     = $pair_data ? floatval( $pair_data['priceUsd'] ?? 0 ) : 0;
            $change_24 = $pair_data ? floatval( $pair_data['priceChange']['h24'] ?? 0 ) : 0;
            $volume_24 = $pair_data ? floatval( $pair_data['volume']['h24'] ?? 0 ) : 0;
            $liq       = $pair_data ? floatval( $pair_data['liquidity']['usd'] ?? 0 ) : 0;
            $txns_h1   = $pair_data ? intval( ($pair_data['txns']['h1']['buys']??0) + ($pair_data['txns']['h1']['sells']??0) ) : 0;
            $dex_url   = $pair_data ? ($pair_data['url'] ?? '') : ( 'https://dexscreener.com/' . $chain . '/' . $addr );

            $traders[] = array(
                'addr'      => $addr,
                'symbol'    => $symbol,
                'chain'     => $chain,
                'boost'     => $boost,
                'price'     => $price,
                'change_24' => $change_24,
                'volume_24' => $volume_24,
                'liq'       => $liq,
                'txns_h1'   => $txns_h1,
                'dex_url'   => $dex_url,
                'icon'      => $token['icon'] ?? '',
                'name'      => $token['description'] ?? $symbol,
            );

            if ( count( $traders ) >= $limit ) break;
        }

        // Sort by volume desc
        usort( $traders, fn($a,$b) => $b['volume_24'] <=> $a['volume_24'] );

        ob_start();

        $updated = date( 'H:i', time() );
        ?>
<div class="bt-tt-wrap">
<div class="bt-tt-header">
    <div>
        <span class="bt-tt-live">⬤ LIVE</span>
        <span class="bt-tt-sub">Top Boosted Tokens · Updated <?php echo esc_html($updated); ?> UTC</span>
    </div>
    <div class="bt-tt-network-filter">
        <button class="bt-tt-chip active" data-net="">🌐 All</button>
        <button class="bt-tt-chip" data-net="solana">◎ Solana</button>
        <button class="bt-tt-chip" data-net="ethereum">Ξ Ethereum</button>
        <button class="bt-tt-chip" data-net="bsc">⬡ BSC</button>
        <button class="bt-tt-chip" data-net="base">⬡ Base</button>
    </div>
</div>

<?php if ( empty( $traders ) ) : ?>
    <div class="bt-tt-empty">📡 No data available right now — DexScreener API may be rate-limiting. Refresh in 60s.</div>
<?php else : ?>
<div class="bt-tt-table-wrap">
<table class="bt-tt-table" id="bt-tt-table">
<thead>
<tr>
    <th>#</th>
    <th class="l">Token</th>
    <th class="l">Chain</th>
    <th>Price</th>
    <th>24h %</th>
    <th>24h Volume</th>
    <th>Liquidity</th>
    <th>1h Txns</th>
    <th>Boost Score</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ( $traders as $rank => $t ) :
    $chain_col = $chain_colors[ $t['chain'] ] ?? 'var(--bt-text-3)';
    $chg_class = $t['change_24'] >= 0 ? 'bt-tt-pos' : 'bt-tt-neg';
    $chg_sign  = $t['change_24'] >= 0 ? '+' : '';
    $vol_fmt   = $t['volume_24'] >= 1000000 ? '$' . number_format($t['volume_24']/1000000,2) . 'M'
               : ( $t['volume_24'] >= 1000 ? '$' . number_format($t['volume_24']/1000,1) . 'K'
               : '$' . number_format($t['volume_24'],2) );
    $liq_fmt   = $t['liq'] >= 1000000 ? '$' . number_format($t['liq']/1000000,2) . 'M'
               : ( $t['liq'] >= 1000 ? '$' . number_format($t['liq']/1000,1) . 'K'
               : '$' . number_format($t['liq'],0) );
    $boost_bar = min( 100, intval( $t['boost'] / 20 ) );
    $addr_short = substr($t['addr'],0,6) . '…' . substr($t['addr'],-4);
    ?>
<tr data-net="<?php echo esc_attr($t['chain']); ?>">
    <td class="bt-tt-rank"><?php echo $rank+1; ?></td>
    <td class="l">
        <div class="bt-tt-token">
            <?php if ($t['icon']) : ?><img src="<?php echo esc_url($t['icon']); ?>" class="bt-tt-icon" alt="" loading="lazy"><?php endif; ?>
            <div>
                <div class="bt-tt-sym"><?php echo esc_html($t['symbol']); ?></div>
                <div class="bt-tt-addr" title="<?php echo esc_attr($t['addr']); ?>"><?php echo esc_html($addr_short); ?></div>
            </div>
        </div>
    </td>
    <td class="l"><span class="bt-tt-chain" style="--cc:<?php echo esc_attr($chain_col); ?>"><?php echo esc_html(ucfirst($t['chain'])); ?></span></td>
    <td><?php echo $t['price'] > 0 ? '$' . number_format($t['price'], $t['price'] < 0.001 ? 8 : ($t['price'] < 1 ? 4 : 2)) : '—'; ?></td>
    <td class="<?php echo $chg_class; ?>"><?php echo $t['change_24'] != 0 ? $chg_sign . number_format($t['change_24'],2) . '%' : '—'; ?></td>
    <td><?php echo $vol_fmt; ?></td>
    <td><?php echo $liq_fmt; ?></td>
    <td><?php echo $t['txns_h1'] > 0 ? number_format($t['txns_h1']) : '—'; ?></td>
    <td>
        <div class="bt-tt-boost">
            <div class="bt-tt-boost-bar" style="width:<?php echo $boost_bar; ?>%"></div>
            <span><?php echo $t['boost'] > 0 ? number_format($t['boost']) : '—'; ?></span>
        </div>
    </td>
    <td><a href="<?php echo esc_url($t['dex_url']); ?>" target="_blank" rel="noopener" class="bt-tt-view">View ↗</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="bt-tt-note">📡 Live data from DexScreener · Ranked by 24h volume · Boost score reflects active DexScreener promotional campaigns · Refreshes every 5 minutes</p>
<?php endif; ?>
</div>

<style>
.bt-tt-wrap{color:var(--bt-text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.bt-tt-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.bt-tt-live{display:inline-block;background:#22c55e22;color:#22c55e;border:1px solid #22c55e44;border-radius:0;padding:3px 8px;font-size:11px;font-weight:700;letter-spacing:.05em;margin-right:8px}
.bt-tt-sub{font-size:12px;color:var(--bt-text-3)}
.bt-tt-network-filter{display:flex;gap:6px;flex-wrap:wrap}
.bt-tt-chip{background:#1e2535;color:var(--bt-text-2);border:1px solid var(--bt-text-4);border-radius:20px;padding:5px 12px;font-size:12px;cursor:pointer;transition:all .2s}
.bt-tt-chip.active,.bt-tt-chip:hover{background:var(--bt-accent)22;border-color:var(--bt-accent);color:var(--bt-accent)}
.bt-tt-table-wrap{overflow-x:auto;border:1px solid #1e2535;border-radius:0}
.bt-tt-table{width:100%;border-collapse:collapse;font-size:13px;min-width:800px}
.bt-tt-table th{background:var(--bt-bg-elev);color:var(--bt-text-3);font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:10px 12px;text-align:right;border-bottom:1px solid #1e2535;white-space:nowrap}
.bt-tt-table th.l{text-align:left}
.bt-tt-table td{padding:10px 12px;text-align:right;border-bottom:1px solid #0f172a}
.bt-tt-table td.l{text-align:left}
.bt-tt-table tbody tr:hover td{background:#0f172a}
.bt-tt-rank{color:var(--bt-text-3);font-size:12px;font-weight:700;width:32px}
.bt-tt-token{display:flex;align-items:center;gap:8px}
.bt-tt-icon{width:28px;height:28px;border-radius:50%;object-fit:cover}
.bt-tt-sym{font-weight:700;color:var(--bt-text);font-size:13px}
.bt-tt-addr{font-size:10px;color:var(--bt-text-3);font-family:monospace}
.bt-tt-chain{display:inline-block;padding:2px 8px;border-radius:0;font-size:11px;font-weight:600;background:color-mix(in srgb,var(--cc) 15%,transparent);color:var(--cc);border:1px solid color-mix(in srgb,var(--cc) 30%,transparent)}
.bt-tt-pos{color:#22c55e;font-weight:600}
.bt-tt-neg{color:#ef4444;font-weight:600}
.bt-tt-boost{display:flex;align-items:center;gap:6px;justify-content:flex-end}
.bt-tt-boost-bar{height:4px;background:var(--bt-accent);border-radius:2px;min-width:2px;max-width:60px}
.bt-tt-boost span{font-size:11px;color:var(--bt-text-3);min-width:28px;text-align:right}
.bt-tt-view{color:var(--bt-accent);text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap}
.bt-tt-view:hover{text-decoration:underline}
.bt-tt-note{font-size:11px;color:var(--bt-text-3);margin-top:12px;text-align:center}
.bt-tt-empty{text-align:center;padding:48px;color:var(--bt-text-3);font-size:14px;background:var(--bt-bg-elev);border-radius:0}
</style>
<script>
(function(){
  var chips = document.querySelectorAll('.bt-tt-chip');
  var rows  = document.querySelectorAll('#bt-tt-table tbody tr');
  chips.forEach(function(chip){
    chip.addEventListener('click', function(){
      chips.forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      var net = chip.dataset.net || '';
      rows.forEach(function(row){
        row.style.display = (!net || row.dataset.net === net) ? '' : 'none';
      });
    });
  });
})();
</script>
<?php
        $html = ob_get_clean();
        set_transient( $cache_key, $html, 5 * MINUTE_IN_SECONDS );
        return $html;
    }

}
