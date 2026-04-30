<?php
/**
 * BlockTicker — Top-N Landing Pages
 *
 * Commercial-intent SEO landing pages — ranked lists of cryptocurrencies
 * targeting buying-intent search queries (e.g. "best DeFi tokens",
 * "top altcoins", "best stablecoins"). Companion to the v119.10 forecast
 * pipeline: forecasts capture informational intent, top-lists capture
 * transactional intent.
 *
 * URL structure
 *   /top/                       — index page (CollectionPage schema)
 *   /top/{list-slug}/           — individual ranked list (ItemList + FAQPage schema)
 *
 * Data sources
 *   1. Local `bt_crypto_data` option (already refreshed by existing cron) for
 *      mcap-rank, gainers/losers, altcoins lists — no extra API calls.
 *   2. Existing CoinGecko per-category transient cache (set by class-widgets'
 *      `[fxlm_crypto_category]` shortcode at 30-min TTL) for category lists.
 *
 * Sitemap integration via the v119.10 `bt_sitemap_extra_urls` filter.
 *
 * @since v119.12.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_TopLists {

    const CACHE_PREFIX = 'bt_toplist_html_';
    const CACHE_TTL    = 15 * MINUTE_IN_SECONDS;

    /**
     * Master list configuration. Each entry produces one indexable page.
     * Source = 'local' uses bt_crypto_data; source = 'category' uses the
     * CoinGecko category transient (key 'fxlm_cat_{cat}_v3').
     *
     * Stablecoins are excluded from "altcoins" by symbol-list, not by
     * CoinGecko categories endpoint, so the local view stays fast.
     */
    public static function get_lists() {
        return apply_filters( 'bt_toplist_definitions', array(

            'top-cryptocurrencies' => array(
                'title'       => 'Top 20 Cryptocurrencies by Market Cap',
                'h1'          => 'The Top 20 Cryptocurrencies in 2026',
                'meta_desc'   => 'Live ranking of the top 20 cryptocurrencies by market capitalisation. Real-time prices, 24-hour change, market cap and 7-day trend — refreshed every few minutes.',
                'eyebrow'     => 'MARKET CAP RANKING',
                'icon'        => '🏆',
                'source'      => 'local',
                'count'       => 20,
                'sort'        => 'market_cap_desc',
                'filter'      => 'all',
                'intro'       => 'Market capitalisation — the total dollar value of all coins in circulation — is the single most-watched metric for sizing up a cryptocurrency. The top 20 by market cap typically capture more than 85% of the total crypto market value, making this list the natural starting point for anyone building a portfolio. The ranking below updates continuously: prices come straight from the CoinGecko aggregate, and rank changes are reflected as soon as our cache refreshes.',
                'methodology' => 'Coins are ranked by current market capitalisation in USD. Market cap = circulating supply × current price. We pull the live data from the CoinGecko API every few minutes and re-rank the list on each refresh. No manual curation, no editorial intervention — the order you see is the order the market itself sets.',
                'faqs'        => array(
                    array(
                        'q' => 'How is the top 20 ranking calculated?',
                        'a' => 'Strictly by market capitalisation in US dollars (circulating supply multiplied by current price). The list refreshes automatically — there is no editorial weighting, no quality filter, and no minimum-volume threshold beyond what CoinGecko applies upstream.',
                    ),
                    array(
                        'q' => 'How often does this list update?',
                        'a' => 'Prices and ranks are cached for up to 15 minutes. Coins moving up or down in rank will appear in their new position on the next cache refresh.',
                    ),
                    array(
                        'q' => 'Why does the order keep changing?',
                        'a' => 'Crypto markets trade 24/7 globally, and small price movements between assets with similar market caps can swap their ranks several times per day. This is normal, especially in the 10-20 range where market caps are closer together.',
                    ),
                    array(
                        'q' => 'Is the top 20 a buy recommendation?',
                        'a' => 'No. This page reports market data — it is not investment advice. A high market cap means an asset is large and widely held; it does not predict future price performance. Always do your own research before investing.',
                    ),
                ),
            ),

            'top-altcoins' => array(
                'title'       => 'Top 20 Altcoins',
                'h1'          => 'The Top 20 Altcoins to Watch in 2026',
                'meta_desc'   => 'Top altcoins ranked by market cap, excluding Bitcoin and stablecoins. Live prices, 24h change, and 7-day trend on the leading non-Bitcoin cryptocurrencies.',
                'eyebrow'     => 'NON-BTC RANKING',
                'icon'        => '⚡',
                'source'      => 'local',
                'count'       => 20,
                'sort'        => 'market_cap_desc',
                'filter'      => 'altcoins', // excludes BTC + stablecoins
                'intro'       => 'An altcoin — short for "alternative coin" — is any cryptocurrency that is not Bitcoin. Altcoins span an enormous range of designs, from smart-contract platforms like Ethereum and Solana to privacy-focused chains, layer-2 scaling networks, and application-specific tokens. The list below ranks the top 20 altcoins by market capitalisation, excluding Bitcoin (which dominates the broader market) and excluding stablecoins (which have market cap by design and would distort a like-for-like comparison).',
                'methodology' => 'We start from the full top-100 by market cap, then remove Bitcoin and any asset categorised as a stablecoin (USDT, USDC, DAI, BUSD, FDUSD, TUSD, USDP, USDE, FRAX, PYUSD, GUSD, LUSD, sUSD, MIM, USDD). The remaining coins keep their original mcap order. The result is an apples-to-apples ranking of the largest non-Bitcoin, non-stablecoin assets.',
                'faqs'        => array(
                    array(
                        'q' => 'What counts as an "altcoin"?',
                        'a' => 'Any cryptocurrency other than Bitcoin. Some traders also exclude stablecoins from the altcoin definition because they are designed not to fluctuate — we follow that convention here so the list reflects volatile-price assets only.',
                    ),
                    array(
                        'q' => 'Why is Ethereum at the top?',
                        'a' => 'After Bitcoin, Ethereum has held the second-largest market cap for nearly all of crypto history. It powers the largest smart-contract ecosystem and is the settlement layer for most DeFi, NFTs, and layer-2 networks.',
                    ),
                    array(
                        'q' => 'Are altcoins riskier than Bitcoin?',
                        'a' => 'Generally, yes. Altcoins typically have smaller market caps, lower liquidity, and shorter track records than Bitcoin, which can lead to larger price swings in both directions. Risk varies enormously between individual altcoins.',
                    ),
                    array(
                        'q' => 'How often does altcoin ranking change?',
                        'a' => 'Frequently. The middle of the top 20 (positions 8-20) often sees several rank swaps per week as market caps move. The top 5 — Ethereum, Solana, BNB, XRP and similar — tend to be more stable but still trade positions during major market moves.',
                    ),
                ),
            ),

            'top-gainers-24h' => array(
                'title'       => 'Top 10 Crypto Gainers (24h)',
                'h1'          => 'Top Cryptocurrency Gainers — Last 24 Hours',
                'meta_desc'   => 'The biggest cryptocurrency gainers in the last 24 hours. Live percentage moves, current prices, and market caps — refreshed continuously.',
                'eyebrow'     => 'BIGGEST 24H MOVES',
                'icon'        => '📈',
                'source'      => 'local',
                'count'       => 10,
                'sort'        => 'change_24h_desc',
                'filter'      => 'liquid', // top-100 mcap to filter out dust pumps
                'intro'       => 'The largest 24-hour gainers in the top-100 by market cap. We restrict the list to the top-100 to filter out low-liquidity tokens whose prices can spike from a handful of small trades — every coin shown below has meaningful daily volume and a market cap above the long-tail. Strong 24-hour moves can signal a genuine catalyst (news, token unlock, listing) or simply mean-reversion after a previous decline. Click into any asset for the deeper context.',
                'methodology' => 'The list is the top-100 by market cap, sorted by 24-hour percentage change descending, top 10 returned. Stablecoins are not excluded explicitly — by definition they should not appear in a top-gainers list, but if one does it indicates a temporary peg deviation worth investigating.',
                'faqs'        => array(
                    array(
                        'q' => 'Why limit to top-100 assets?',
                        'a' => 'Without a market-cap floor, a top-gainers list is dominated by low-liquidity tokens that can move 50% on a $5,000 trade. Restricting to the top-100 keeps the list to assets where a percentage move reflects genuine market interest.',
                    ),
                    array(
                        'q' => 'What does a +20% 24h move mean?',
                        'a' => 'It means the price is 20% higher than 24 hours ago. The reason can be anything from a major partnership announcement to a token unlock cliff to broader market sentiment shifts — context matters more than the percentage.',
                    ),
                    array(
                        'q' => 'Should I buy the top gainer?',
                        'a' => 'Not without research. Strong recent gains mean the entry price is higher than yesterday — buying at a peak is the most common way new traders lose money. This list is for monitoring, not for blind execution.',
                    ),
                ),
            ),

            'top-losers-24h' => array(
                'title'       => 'Top 10 Crypto Losers (24h)',
                'h1'          => 'Top Cryptocurrency Losers — Last 24 Hours',
                'meta_desc'   => 'The biggest cryptocurrency losers in the last 24 hours. Live percentage drops, current prices, and market cap — refreshed continuously.',
                'eyebrow'     => 'BIGGEST 24H DROPS',
                'icon'        => '📉',
                'source'      => 'local',
                'count'       => 10,
                'sort'        => 'change_24h_asc',
                'filter'      => 'liquid',
                'intro'       => 'The largest 24-hour losers in the top-100 by market cap. Sharp declines often precede recoveries — but they can also signal the start of a longer downtrend. The list below is purely descriptive: we report the moves, we do not editorialise about whether each represents an opportunity or a warning.',
                'methodology' => 'The list is the top-100 by market cap, sorted by 24-hour percentage change ascending (most negative first), top 10 returned. We deliberately use the top-100 floor to avoid the list being dominated by long-tail tokens whose volatility is mostly noise.',
                'faqs'        => array(
                    array(
                        'q' => 'Are big losers a buying opportunity?',
                        'a' => 'Sometimes. Sharp single-day declines in fundamentally sound assets often see partial recovery within days. But big losses can also be the first day of a longer downtrend driven by serious news. Distinguishing the two requires reading the catalyst, not just the percentage.',
                    ),
                    array(
                        'q' => 'Why does the same coin keep appearing?',
                        'a' => 'Coins in extended downtrends will appear day after day until they stabilise or rally. If you see a coin recur for many consecutive days, treat the persistent weakness as more meaningful than a single-day move.',
                    ),
                    array(
                        'q' => 'How does the top-100 filter affect this list?',
                        'a' => 'Without it, the top-losers list would be dominated by recent ICO tokens and meme coins making 80% moves daily on near-zero volume. The top-100 floor surfaces meaningful corrections in established assets.',
                    ),
                ),
            ),

            'best-defi-tokens' => array(
                'title'       => 'Best DeFi Tokens',
                'h1'          => 'The Best DeFi Tokens in 2026',
                'meta_desc'   => 'Top DeFi tokens by market cap. Live prices, 24h moves, and market caps for the leading decentralized finance protocols on Ethereum, Solana and beyond.',
                'eyebrow'     => 'DECENTRALIZED FINANCE',
                'icon'        => '🔗',
                'source'      => 'category',
                'cg_id'       => 'decentralized-finance-defi',
                'cache_key'   => 'fxlm_cat_defi_v3',
                'count'       => 15,
                'sort'        => 'market_cap_desc',
                'intro'       => 'DeFi — short for "decentralized finance" — is the cluster of crypto protocols that recreate traditional financial services without intermediaries. Lending protocols replace banks, automated market makers replace exchanges, and yield aggregators replace fund managers. The tokens below are the governance and utility tokens of the largest DeFi protocols by market capitalisation, drawn from the CoinGecko DeFi category. They span Ethereum, Solana, Avalanche, and other smart-contract platforms.',
                'methodology' => 'Source: CoinGecko\'s "Decentralized Finance (DeFi)" category. Coins are ranked by market capitalisation. We pull the top 15 from the category endpoint with a 30-minute cache. Inclusion in the category is determined upstream by CoinGecko\'s editorial team and is the same dataset used by major aggregators.',
                'faqs'        => array(
                    array(
                        'q' => 'What is a DeFi token?',
                        'a' => 'A token issued by a decentralized finance protocol. Most DeFi tokens grant governance rights — the ability to vote on protocol parameters, fees, and treasury decisions. Some also capture a share of protocol revenue.',
                    ),
                    array(
                        'q' => 'How are DeFi tokens different from stablecoins?',
                        'a' => 'Stablecoins are designed to hold a fixed value (usually $1). DeFi tokens are price-volatile assets whose value reflects market expectations of the underlying protocol\'s future cash flows, governance importance, and adoption.',
                    ),
                    array(
                        'q' => 'Which blockchains do these tokens live on?',
                        'a' => 'Most large DeFi protocols are still Ethereum-native, but the category spans Solana, Avalanche, BNB Chain, Polygon, Arbitrum, Optimism, Base, and many others. Cross-chain DeFi has grown substantially in recent years.',
                    ),
                    array(
                        'q' => 'Are DeFi tokens regulated?',
                        'a' => 'Regulation varies widely by jurisdiction and is evolving rapidly. Some DeFi tokens have been treated as securities by regulators in certain countries. Always check the regulatory status in your jurisdiction before investing.',
                    ),
                ),
            ),

            'best-stablecoins' => array(
                'title'       => 'Best Stablecoins',
                'h1'          => 'The Best Stablecoins in 2026',
                'meta_desc'   => 'Top stablecoins by market cap. Live data on USDT, USDC, DAI, and other dollar-pegged crypto assets — peg stability, market cap, and 24h volume.',
                'eyebrow'     => 'DOLLAR-PEGGED ASSETS',
                'icon'        => '🔒',
                'source'      => 'category',
                'cg_id'       => 'stablecoins',
                'cache_key'   => 'fxlm_cat_stablecoins_v3',
                'count'       => 10,
                'sort'        => 'market_cap_desc',
                'intro'       => 'Stablecoins are cryptocurrencies designed to hold a stable value, almost always pegged 1:1 to the US dollar. They are the plumbing of the crypto economy: more than 80% of all on-chain trading volume settles in stablecoins, and they serve as the cash leg for DeFi lending, perpetual futures collateral, and cross-border transfers. The list below ranks the top stablecoins by market capitalisation — a rough proxy for issuance and adoption.',
                'methodology' => 'Source: CoinGecko\'s "Stablecoins" category, ranked by market capitalisation. We pull the top 10 from the category endpoint with a 30-minute cache. Note that "market cap" for a stablecoin is essentially the total amount issued — a $100B stablecoin means $100B has been minted (and, in theory, is backed by reserves).',
                'faqs'        => array(
                    array(
                        'q' => 'How do stablecoins keep their peg?',
                        'a' => 'Three main mechanisms: (1) fiat-backed (USDT, USDC) — the issuer holds dollars and short-term Treasuries equal to circulating supply; (2) crypto-collateralized (DAI) — over-collateralized by other crypto held in smart contracts; (3) algorithmic — uses on-chain mechanisms to mint and burn supply against price. Algorithmic designs have a poor track record.',
                    ),
                    array(
                        'q' => 'Are stablecoins safe?',
                        'a' => 'Safer than volatile crypto, but not risk-free. Fiat-backed stablecoins carry counterparty risk (the issuer holds your collateral). Crypto-backed ones can break their peg in extreme volatility. Algorithmic ones have failed catastrophically in the past. Diversify across issuers if holding large amounts.',
                    ),
                    array(
                        'q' => 'Which stablecoin is largest?',
                        'a' => 'Tether (USDT) has held the #1 stablecoin spot for years, with USDC consistently second. Together they typically account for over 85% of stablecoin market cap. Look at the live data above for the current numbers.',
                    ),
                    array(
                        'q' => 'Can I earn interest on stablecoins?',
                        'a' => 'Yes — DeFi protocols routinely pay 3-12% APY on stablecoin deposits, depending on the protocol and risk profile. Centralized exchanges and CeFi services also offer yield products. Always understand where the yield comes from before depositing.',
                    ),
                ),
            ),

            'top-gaming-tokens' => array(
                'title'       => 'Top Gaming Tokens',
                'h1'          => 'The Top Crypto Gaming Tokens in 2026',
                'meta_desc'   => 'Top blockchain gaming and GameFi tokens by market cap. Live prices on the leading play-to-earn and gaming platform tokens.',
                'eyebrow'     => 'GAMEFI · PLAY-TO-EARN',
                'icon'        => '🎮',
                'source'      => 'category',
                'cg_id'       => 'gaming',
                'cache_key'   => 'fxlm_cat_gaming_v3',
                'count'       => 15,
                'sort'        => 'market_cap_desc',
                'intro'       => 'Gaming tokens — sometimes called GameFi — combine blockchain ownership with gaming economies. Players can own in-game items as NFTs, earn currency through gameplay, and trade assets across games. The category includes everything from large platform tokens (which power multiple games) to single-game governance and reward tokens. The space has matured substantially since the early play-to-earn boom of 2021-22, with most surviving projects now focusing on game quality first and token mechanics second.',
                'methodology' => 'Source: CoinGecko\'s "Gaming" category. Ranked by market capitalisation, top 15 returned, 30-minute cache. Some tokens may also appear in the broader Metaverse category — we list each only once, in whichever category has higher relevance to gaming specifically.',
                'faqs'        => array(
                    array(
                        'q' => 'What does GameFi mean?',
                        'a' => 'GameFi = Game + DeFi. It refers to blockchain games where in-game economies are built on crypto rails — players truly own their items, can earn tradeable rewards, and the game economy interacts with broader DeFi protocols.',
                    ),
                    array(
                        'q' => 'Are play-to-earn games still relevant?',
                        'a' => 'The 2021-era pure play-to-earn model (where earning was the primary draw) has largely failed — those economies were unsustainable. Modern blockchain games focus on "play and earn" — fun-first games where token rewards are a bonus, not the main reason to play.',
                    ),
                    array(
                        'q' => 'How do gaming tokens make money?',
                        'a' => 'Most gaming tokens accrue value through some combination of: in-game utility (you need them to play or upgrade), governance (vote on game development), staking rewards, and revenue share from game economies. The strongest tokens have multiple value-capture mechanisms.',
                    ),
                ),
            ),

            'top-metaverse-tokens' => array(
                'title'       => 'Top Metaverse Tokens',
                'h1'          => 'The Top Metaverse Tokens in 2026',
                'meta_desc'   => 'Top metaverse and virtual world tokens by market cap. Live prices on the leading projects building blockchain-based virtual worlds.',
                'eyebrow'     => 'VIRTUAL WORLDS',
                'icon'        => '🌐',
                'source'      => 'category',
                'cg_id'       => 'metaverse',
                'cache_key'   => 'fxlm_cat_metaverse_v3',
                'count'       => 10,
                'sort'        => 'market_cap_desc',
                'intro'       => 'Metaverse tokens power virtual worlds — persistent, shared 3D environments where users own land, items, and identities as NFTs. The category includes platforms like Decentraland and The Sandbox, infrastructure tokens for virtual economies, and tokens for adjacent areas like virtual events and digital fashion. The space cooled significantly after the 2021-22 hype peak; the projects that remain are the ones with active user bases, real partnerships, and credible technical roadmaps.',
                'methodology' => 'Source: CoinGecko\'s "Metaverse" category, ranked by market capitalisation, top 10 returned with a 30-minute cache. Some overlap with the Gaming category is normal; we list each token in the category most relevant to its primary use case.',
                'faqs'        => array(
                    array(
                        'q' => 'What is the metaverse?',
                        'a' => 'In crypto context, the metaverse refers to persistent virtual worlds where users own digital land, items, and identities through blockchain. It is broader than any single platform — multiple competing metaverse projects exist with their own tokens and economies.',
                    ),
                    array(
                        'q' => 'How is virtual land valued?',
                        'a' => 'Like physical real estate, by location, scarcity, and adjacent activity. Land near popular hubs in active metaverse platforms commands much higher prices than peripheral plots in less-used worlds. Most metaverse land is represented as an NFT, separate from the platform\'s native token.',
                    ),
                    array(
                        'q' => 'Did the metaverse hype die?',
                        'a' => 'The peak hype of 2021-22 has cooled significantly, and many metaverse tokens are far below their all-time highs. But active platforms still have user bases, and a more mature, less hype-driven phase of metaverse development is ongoing.',
                    ),
                ),
            ),

        ) );
    }

    /* ================================================================
     *  SETUP
     * ================================================================ */

    public static function setup() {
        add_action( 'init',                  array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',            array( __CLASS__, 'add_query_vars' ) );
        add_action( 'wp_loaded',             array( __CLASS__, 'intercept_url' ), 1 );
        add_action( 'wp_head',               array( __CLASS__, 'output_meta' ) );
        add_filter( 'document_title_parts',  array( __CLASS__, 'filter_title' ) );
        add_filter( 'bt_sitemap_extra_urls', array( __CLASS__, 'sitemap_urls' ) );
        add_action( 'admin_menu',            array( __CLASS__, 'register_admin_menu' ), 26 );

        // Bust per-list cache when source data refreshes.
        add_action( 'update_option_bt_crypto_data', array( __CLASS__, 'bust_local_caches' ), 10, 0 );
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule( '^top/?$',
            'index.php?bt_toplist_view=index', 'top' );
        add_rewrite_rule( '^top/([a-z0-9-]+)/?$',
            'index.php?bt_toplist_view=detail&bt_toplist_slug=$matches[1]', 'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'bt_toplist_view';
        $vars[] = 'bt_toplist_slug';
        return $vars;
    }

    /**
     * Direct URI parsing — works regardless of rewrite-rule flush state.
     * Matches the pattern used by BT_AssetPages and BT_Forecast.
     */
    public static function intercept_url() {
        $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( $uri === 'top' ) {
            self::render_index();
            exit;
        }
        if ( preg_match( '#^top/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            self::render_detail( $m[1] );
            exit;
        }
    }

    public static function bust_local_caches() {
        foreach ( self::get_lists() as $slug => $cfg ) {
            if ( ( $cfg['source'] ?? '' ) === 'local' ) {
                delete_transient( self::CACHE_PREFIX . $slug );
            }
        }
        delete_transient( self::CACHE_PREFIX . '_index' );
    }

    /* ================================================================
     *  DATA — fetch & filter
     * ================================================================ */

    /** Hard-coded stablecoin symbol set used to exclude stablecoins from altcoin rankings. */
    private static function stablecoin_symbols() {
        return array( 'usdt', 'usdc', 'dai', 'busd', 'fdusd', 'tusd', 'usdp', 'usde', 'frax', 'pyusd', 'gusd', 'lusd', 'susd', 'mim', 'usdd' );
    }

    /**
     * Get the coin list for a given list config.
     *
     * @return array  CoinGecko-shape coin objects, already filtered + sorted + sliced.
     */
    public static function get_coins_for_list( $cfg ) {
        $coins = array();

        if ( ( $cfg['source'] ?? '' ) === 'category' ) {
            // Reuse the existing per-category transient written by [fxlm_crypto_category]
            $cached = get_transient( $cfg['cache_key'] );
            if ( is_array( $cached ) ) $coins = $cached;
            // If the transient is empty, the category page hasn't been hit yet;
            // we render an empty-state rather than calling the API ourselves
            // (avoids surprise API spend on a public page).
        } else {
            $data = get_option( 'bt_crypto_data', array() );
            if ( is_string( $data ) ) $data = json_decode( $data, true );
            if ( is_array( $data ) && ! empty( $data['coins'] ) ) {
                $coins = $data['coins'];
            }
        }

        if ( empty( $coins ) || ! is_array( $coins ) ) return array();

        // Apply filter
        $filter = $cfg['filter'] ?? 'all';
        if ( $filter === 'altcoins' ) {
            $stables = self::stablecoin_symbols();
            $coins = array_values( array_filter( $coins, function( $c ) use ( $stables ) {
                $sym = strtolower( $c['symbol'] ?? '' );
                if ( $sym === 'btc' ) return false;
                if ( in_array( $sym, $stables, true ) ) return false;
                return true;
            } ) );
        } elseif ( $filter === 'liquid' ) {
            // top-100 by mcap rank — already what bt_crypto_data['coins'] holds
            $coins = array_values( array_filter( $coins, function( $c ) {
                $rank = intval( $c['market_cap_rank'] ?? 999 );
                return $rank > 0 && $rank <= 100;
            } ) );
        }

        // Apply sort
        $sort = $cfg['sort'] ?? 'market_cap_desc';
        usort( $coins, function( $a, $b ) use ( $sort ) {
            switch ( $sort ) {
                case 'change_24h_desc':
                    return ( floatval( $b['price_change_percentage_24h'] ?? 0 ) <=> floatval( $a['price_change_percentage_24h'] ?? 0 ) );
                case 'change_24h_asc':
                    return ( floatval( $a['price_change_percentage_24h'] ?? 0 ) <=> floatval( $b['price_change_percentage_24h'] ?? 0 ) );
                case 'market_cap_desc':
                default:
                    return ( floatval( $b['market_cap'] ?? 0 ) <=> floatval( $a['market_cap'] ?? 0 ) );
            }
        } );

        $count = intval( $cfg['count'] ?? 10 );
        if ( $count > 0 ) $coins = array_slice( $coins, 0, $count );

        return $coins;
    }

    /* ================================================================
     *  RENDERING — INDEX (/top/)
     * ================================================================ */

    public static function render_index() {
        $cached = get_transient( self::CACHE_PREFIX . '_index' );
        if ( $cached !== false ) {
            self::output_page( $cached, __( 'Top Cryptocurrency Lists', 'blockticker' ), __( 'Browse our ranked lists of cryptocurrencies — from the overall top by market cap to category-specific best-of lists for DeFi, gaming, stablecoins and more.', 'blockticker' ) );
            return;
        }

        $lists = self::get_lists();
        ob_start();
        ?>
        <div class="bt-toplist-wrap">

            <header class="bt-toplist-hero">
                <div class="bt-toplist-eyebrow">
                    <span class="bt-toplist-eyebrow-dot">●</span>
                    <span><?php esc_html_e( 'CRYPTO RANKINGS HUB', 'blockticker' ); ?></span>
                </div>
                <h1 class="bt-toplist-title"><?php esc_html_e( 'Top Cryptocurrency Lists', 'blockticker' ); ?></h1>
                <p class="bt-toplist-sub">
                    <?php esc_html_e( 'Live, market-data-driven rankings — refreshed continuously, curated by no one. Browse the overall top by market cap, the biggest 24-hour movers, or category-specific best-of lists across DeFi, gaming, stablecoins, and more.', 'blockticker' ); ?>
                </p>
            </header>

            <section class="bt-toplist-section">
                <h2 class="bt-toplist-h2">
                    <span class="bt-toplist-h2-num">01</span>
                    <?php esc_html_e( 'All rankings', 'blockticker' ); ?>
                </h2>

                <div class="bt-toplist-grid">
                <?php foreach ( $lists as $slug => $cfg ) :
                    $coins = self::get_coins_for_list( $cfg );
                    $top3  = array_slice( $coins, 0, 3 );
                    $url   = home_url( '/top/' . $slug . '/' );
                ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="bt-toplist-card">
                        <div class="bt-toplist-card-head">
                            <span class="bt-toplist-card-icon"><?php echo esc_html( $cfg['icon'] ?? '★' ); ?></span>
                            <span class="bt-toplist-card-eyebrow"><?php echo esc_html( $cfg['eyebrow'] ?? '' ); ?></span>
                        </div>
                        <h3 class="bt-toplist-card-title"><?php echo esc_html( $cfg['title'] ); ?></h3>
                        <?php if ( ! empty( $top3 ) ) : ?>
                            <ol class="bt-toplist-card-preview">
                                <?php foreach ( $top3 as $i => $c ) :
                                    $chg = floatval( $c['price_change_percentage_24h'] ?? 0 );
                                ?>
                                    <li>
                                        <span class="bt-toplist-card-rank"><?php echo (int) ( $i + 1 ); ?></span>
                                        <span class="bt-toplist-card-sym"><?php echo esc_html( strtoupper( $c['symbol'] ?? '' ) ); ?></span>
                                        <span class="bt-toplist-card-name"><?php echo esc_html( $c['name'] ?? '' ); ?></span>
                                        <span class="bt-toplist-card-chg <?php echo $chg >= 0 ? 'pos' : 'neg'; ?>">
                                            <?php echo ( $chg >= 0 ? '+' : '' ) . esc_html( number_format( $chg, 2 ) ); ?>%
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else : ?>
                            <p class="bt-toplist-card-empty"><?php esc_html_e( 'Loading data…', 'blockticker' ); ?></p>
                        <?php endif; ?>
                        <span class="bt-toplist-card-cta"><?php esc_html_e( 'View full list →', 'blockticker' ); ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            </section>

            <aside class="bt-toplist-disclaimer">
                <strong><?php esc_html_e( 'Not investment advice.', 'blockticker' ); ?></strong>
                <?php esc_html_e( 'These rankings are derived from live market data and are presented for informational purposes only. Past performance does not guarantee future results. Always do your own research before investing.', 'blockticker' ); ?>
            </aside>

        </div>
        <?php
        $html = ob_get_clean();
        set_transient( self::CACHE_PREFIX . '_index', $html, self::CACHE_TTL );

        self::output_page( $html, __( 'Top Cryptocurrency Lists', 'blockticker' ), __( 'Browse our ranked lists of cryptocurrencies — from the overall top by market cap to category-specific best-of lists for DeFi, gaming, stablecoins and more.', 'blockticker' ) );
    }

    /* ================================================================
     *  RENDERING — DETAIL (/top/{slug}/)
     * ================================================================ */

    public static function render_detail( $slug ) {
        $lists = self::get_lists();
        if ( ! isset( $lists[ $slug ] ) ) {
            // 404 for unknown slugs.
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            // Render the theme 404 if possible, otherwise simple fallback.
            $tpl = get_404_template();
            if ( $tpl ) {
                include $tpl;
                exit;
            }
            self::output_page( '<div class="bt-toplist-wrap"><h1>' . esc_html__( 'List not found', 'blockticker' ) . '</h1></div>', __( 'List not found', 'blockticker' ), '' );
            return;
        }

        $cfg = $lists[ $slug ];

        $cached = get_transient( self::CACHE_PREFIX . $slug );
        if ( $cached !== false ) {
            self::output_page( $cached, $cfg['title'], $cfg['meta_desc'] ?? '' );
            return;
        }

        $coins = self::get_coins_for_list( $cfg );
        ob_start();

        // ── Schema.org ItemList + FAQPage ────────────────────────────
        if ( ! empty( $coins ) ) {
            $list_items = array();
            foreach ( $coins as $i => $c ) {
                $list_items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'item'     => array(
                        '@type'  => 'Thing',
                        'name'   => ( $c['name'] ?? '' ) . ' (' . strtoupper( $c['symbol'] ?? '' ) . ')',
                        'url'    => home_url( '/crypto/' . sanitize_title( $c['id'] ?? $c['symbol'] ?? '' ) . '/' ),
                    ),
                );
            }
            $schema = array(
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => $cfg['title'],
                'description'     => $cfg['meta_desc'] ?? '',
                'numberOfItems'   => count( $coins ),
                'itemListElement' => $list_items,
            );
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>';
        }
        if ( ! empty( $cfg['faqs'] ) ) {
            $faq_items = array();
            foreach ( $cfg['faqs'] as $faq ) {
                $faq_items[] = array(
                    '@type'          => 'Question',
                    'name'           => $faq['q'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $faq['a'],
                    ),
                );
            }
            $faq_schema = array(
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $faq_items,
            );
            echo '<script type="application/ld+json">' . wp_json_encode( $faq_schema, JSON_UNESCAPED_SLASHES ) . '</script>';
        }

        ?>
        <div class="bt-toplist-wrap" data-list-slug="<?php echo esc_attr( $slug ); ?>">

            <!-- Breadcrumb -->
            <nav class="bt-toplist-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'blockticker' ); ?>">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'blockticker' ); ?></a>
                <span class="bt-toplist-crumb-sep">›</span>
                <a href="<?php echo esc_url( home_url( '/top/' ) ); ?>"><?php esc_html_e( 'Top Lists', 'blockticker' ); ?></a>
                <span class="bt-toplist-crumb-sep">›</span>
                <span class="bt-toplist-crumb-current"><?php echo esc_html( $cfg['title'] ); ?></span>
            </nav>

            <!-- Hero -->
            <header class="bt-toplist-hero">
                <div class="bt-toplist-eyebrow">
                    <span class="bt-toplist-eyebrow-dot">●</span>
                    <span><?php echo esc_html( $cfg['eyebrow'] ?? 'RANKING' ); ?></span>
                    <span class="bt-toplist-eyebrow-sep">·</span>
                    <span><?php
                        /* translators: %s: human-readable date */
                        printf( esc_html__( 'Updated %s', 'blockticker' ), esc_html( gmdate( 'M j, Y · H:i', current_time( 'timestamp', true ) ) ) . ' UTC' );
                    ?></span>
                </div>
                <h1 class="bt-toplist-title"><?php echo esc_html( $cfg['h1'] ?? $cfg['title'] ); ?></h1>
                <p class="bt-toplist-sub">
                    <?php echo esc_html( $cfg['intro'] ?? '' ); ?>
                </p>
            </header>

            <?php if ( empty( $coins ) ) : ?>
                <!-- Empty state -->
                <div class="bt-toplist-empty">
                    <div class="bt-toplist-empty-icon">📡</div>
                    <h2><?php esc_html_e( 'Data is loading', 'blockticker' ); ?></h2>
                    <p><?php
                        if ( ( $cfg['source'] ?? '' ) === 'category' ) {
                            esc_html_e( 'This category list is built from data refreshed by visits to the related category page. Check back in a few minutes.', 'blockticker' );
                        } else {
                            esc_html_e( 'Live market data is refreshing. Try reloading in a moment.', 'blockticker' );
                        }
                    ?></p>
                </div>
            <?php else : ?>

            <!-- Ranked table -->
            <section class="bt-toplist-section">
                <div class="bt-toplist-table-wrap">
                <table class="bt-toplist-table">
                    <thead>
                        <tr>
                            <th class="bt-toplist-th-rank">#</th>
                            <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                            <th class="bt-toplist-num"><?php esc_html_e( 'Price', 'blockticker' ); ?></th>
                            <th class="bt-toplist-num"><?php esc_html_e( '24h %', 'blockticker' ); ?></th>
                            <th class="bt-toplist-num bt-toplist-hide-mobile"><?php esc_html_e( '7d %', 'blockticker' ); ?></th>
                            <th class="bt-toplist-num bt-toplist-hide-mobile"><?php esc_html_e( 'Market Cap', 'blockticker' ); ?></th>
                            <th class="bt-toplist-th-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'blockticker' ); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $coins as $i => $c ) :
                        $sym       = strtoupper( $c['symbol'] ?? '' );
                        $name      = $c['name'] ?? '';
                        $img       = $c['image'] ?? '';
                        $price     = floatval( $c['current_price'] ?? 0 );
                        $chg24     = floatval( $c['price_change_percentage_24h'] ?? 0 );
                        $chg7      = floatval( $c['price_change_percentage_7d_in_currency'] ?? $chg24 );
                        $mcap      = floatval( $c['market_cap'] ?? 0 );
                        $coin_id   = sanitize_title( $c['id'] ?? $c['symbol'] ?? '' );
                        $detail_url = home_url( '/crypto/' . $coin_id . '/' );
                        $rank_disp = $i + 1;

                        // Price formatting — adapt precision to magnitude
                        if ( $price >= 1 )            $price_str = '$' . number_format( $price, 2 );
                        elseif ( $price >= 0.01 )     $price_str = '$' . number_format( $price, 4 );
                        else                          $price_str = '$' . number_format( $price, 8 );

                        // Market cap formatting (B / M)
                        if ( $mcap >= 1e9 )           $mcap_str = '$' . number_format( $mcap / 1e9, 2 ) . 'B';
                        elseif ( $mcap >= 1e6 )       $mcap_str = '$' . number_format( $mcap / 1e6, 1 ) . 'M';
                        else                          $mcap_str = '$' . number_format( $mcap, 0 );
                    ?>
                        <tr>
                            <td class="bt-toplist-rank"><?php echo (int) $rank_disp; ?></td>
                            <td class="bt-toplist-asset">
                                <a href="<?php echo esc_url( $detail_url ); ?>" class="bt-toplist-asset-link">
                                    <?php if ( $img ) : ?>
                                        <img src="<?php echo esc_url( $img ); ?>" alt="" width="22" height="22" loading="lazy" class="bt-toplist-coin-img">
                                    <?php endif; ?>
                                    <span class="bt-toplist-coin-name"><?php echo esc_html( $name ); ?></span>
                                    <span class="bt-toplist-coin-sym"><?php echo esc_html( $sym ); ?></span>
                                </a>
                            </td>
                            <td class="bt-toplist-num bt-toplist-price"><?php echo esc_html( $price_str ); ?></td>
                            <td class="bt-toplist-num bt-toplist-chg <?php echo $chg24 >= 0 ? 'pos' : 'neg'; ?>">
                                <?php echo ( $chg24 >= 0 ? '+' : '' ) . esc_html( number_format( $chg24, 2 ) ); ?>%
                            </td>
                            <td class="bt-toplist-num bt-toplist-hide-mobile bt-toplist-chg <?php echo $chg7 >= 0 ? 'pos' : 'neg'; ?>">
                                <?php echo ( $chg7 >= 0 ? '+' : '' ) . esc_html( number_format( $chg7, 2 ) ); ?>%
                            </td>
                            <td class="bt-toplist-num bt-toplist-hide-mobile"><?php echo esc_html( $mcap_str ); ?></td>
                            <td class="bt-toplist-actions">
                                <a href="<?php echo esc_url( $detail_url ); ?>" class="bt-toplist-detail-btn"><?php esc_html_e( 'Details', 'blockticker' ); ?> →</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>

            <!-- Methodology -->
            <section class="bt-toplist-section">
                <h2 class="bt-toplist-h2">
                    <span class="bt-toplist-h2-num">02</span>
                    <?php esc_html_e( 'How this list is compiled', 'blockticker' ); ?>
                </h2>
                <div class="bt-toplist-method">
                    <p><?php echo esc_html( $cfg['methodology'] ?? '' ); ?></p>
                </div>
            </section>

            <?php endif; ?>

            <?php if ( ! empty( $cfg['faqs'] ) ) : ?>
            <!-- FAQ -->
            <section class="bt-toplist-section">
                <h2 class="bt-toplist-h2">
                    <span class="bt-toplist-h2-num">03</span>
                    <?php esc_html_e( 'Frequently asked questions', 'blockticker' ); ?>
                </h2>
                <div class="bt-toplist-faqs">
                    <?php foreach ( $cfg['faqs'] as $i => $faq ) : ?>
                        <details class="bt-toplist-faq" <?php echo $i === 0 ? 'open' : ''; ?>>
                            <summary class="bt-toplist-faq-q">
                                <span class="bt-toplist-faq-q-text"><?php echo esc_html( $faq['q'] ); ?></span>
                                <span class="bt-toplist-faq-q-icon" aria-hidden="true">+</span>
                            </summary>
                            <div class="bt-toplist-faq-a"><p><?php echo esc_html( $faq['a'] ); ?></p></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- See also -->
            <section class="bt-toplist-section bt-toplist-seealso">
                <h2 class="bt-toplist-h2">
                    <span class="bt-toplist-h2-num">04</span>
                    <?php esc_html_e( 'See also', 'blockticker' ); ?>
                </h2>
                <div class="bt-toplist-seealso-grid">
                    <?php
                    $other = array();
                    foreach ( $lists as $other_slug => $other_cfg ) {
                        if ( $other_slug === $slug ) continue;
                        $other[ $other_slug ] = $other_cfg;
                    }
                    // Show 4 others
                    $other = array_slice( $other, 0, 4, true );
                    foreach ( $other as $other_slug => $other_cfg ) :
                    ?>
                        <a href="<?php echo esc_url( home_url( '/top/' . $other_slug . '/' ) ); ?>" class="bt-toplist-seealso-card">
                            <span class="bt-toplist-seealso-icon"><?php echo esc_html( $other_cfg['icon'] ?? '★' ); ?></span>
                            <span class="bt-toplist-seealso-title"><?php echo esc_html( $other_cfg['title'] ); ?></span>
                            <span class="bt-toplist-seealso-eyebrow"><?php echo esc_html( $other_cfg['eyebrow'] ?? '' ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Disclaimer -->
            <aside class="bt-toplist-disclaimer">
                <strong><?php esc_html_e( 'Not investment advice.', 'blockticker' ); ?></strong>
                <?php esc_html_e( 'The data on this page is presented for informational purposes only. Past performance does not guarantee future results. Cryptocurrency is highly volatile and can lose value rapidly. Always do your own research and consider consulting a licensed advisor before investing.', 'blockticker' ); ?>
            </aside>

        </div>
        <?php

        $html = ob_get_clean();
        set_transient( self::CACHE_PREFIX . $slug, $html, self::CACHE_TTL );
        self::output_page( $html, $cfg['title'], $cfg['meta_desc'] ?? '' );
    }

    /* ================================================================
     *  PAGE OUTPUT — wraps content in the theme shell
     * ================================================================ */

    /**
     * Hands the assembled HTML off to the theme via a virtual page.
     * Uses the exact same approach as BT_Forecast::output_page.
     */
    private static function output_page( $body_html, $title, $meta_desc ) {
        global $bt_toplist_current;
        $bt_toplist_current = array(
            'title'     => $title,
            'meta_desc' => $meta_desc,
            'body'      => $body_html,
        );

        // Use the same theme-shell trick as forecast: filter the_content.
        add_filter( 'the_content', function( $content ) use ( $body_html ) {
            global $bt_toplist_current;
            if ( ! empty( $bt_toplist_current ) && in_the_loop() && is_main_query() ) {
                return $body_html;
            }
            return $content;
        }, 10 );

        // Trigger a page-template render via a virtual post object.
        $post_id = -2026;
        $virtual = new WP_Post( (object) array(
            'ID'             => $post_id,
            'post_author'    => 1,
            'post_date'      => current_time( 'mysql' ),
            'post_date_gmt'  => current_time( 'mysql', 1 ),
            'post_content'   => $body_html,
            'post_title'     => $title,
            'post_excerpt'   => $meta_desc,
            'post_status'    => 'publish',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'post_name'      => 'bt-toplist',
            'post_type'      => 'page',
            'filter'         => 'raw',
        ) );

        wp_cache_add( $post_id, $virtual, 'posts' );

        // Set up the global query state.
        global $wp_query, $wp;
        $wp_query->post           = $virtual;
        $wp_query->posts          = array( $virtual );
        $wp_query->queried_object = $virtual;
        $wp_query->queried_object_id = $post_id;
        $wp_query->found_posts    = 1;
        $wp_query->post_count     = 1;
        $wp_query->is_page        = true;
        $wp_query->is_singular    = true;
        $wp_query->is_home        = false;
        $wp_query->is_archive     = false;
        $wp_query->is_404         = false;

        status_header( 200 );

        // Render via theme.
        $tpl = get_page_template();
        if ( ! $tpl ) $tpl = get_index_template();
        if ( $tpl ) {
            include $tpl;
            return;
        }

        // Last-ditch fallback if the theme doesn't return a template
        get_header();
        echo '<main class="site-main"><article class="post">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo $body_html; // already escaped at construction
        echo '</article></main>';
        get_footer();
    }

    /* ================================================================
     *  SEO META + TITLE
     * ================================================================ */

    public static function output_meta() {
        global $bt_toplist_current;
        if ( empty( $bt_toplist_current ) ) return;

        $desc = $bt_toplist_current['meta_desc'] ?? '';
        $url  = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );

        if ( $desc ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
        echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $bt_toplist_current['title'] ) . '">' . "\n";
        if ( $desc ) {
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta name="robots" content="index,follow,max-image-preview:large">' . "\n";
    }

    public static function filter_title( $parts ) {
        global $bt_toplist_current;
        if ( ! empty( $bt_toplist_current ) ) {
            $parts['title'] = $bt_toplist_current['title'];
        }
        return $parts;
    }

    /* ================================================================
     *  SITEMAP HOOK
     * ================================================================ */

    public static function sitemap_urls( $urls ) {
        if ( ! is_array( $urls ) ) $urls = array();
        // Index page
        $urls[] = array(
            'loc'        => home_url( '/top/' ),
            'lastmod'    => gmdate( 'Y-m-d' ),
            'changefreq' => 'daily',
            'priority'   => '0.8',
        );
        foreach ( self::get_lists() as $slug => $cfg ) {
            $urls[] = array(
                'loc'        => home_url( '/top/' . $slug . '/' ),
                'lastmod'    => gmdate( 'Y-m-d' ),
                'changefreq' => 'daily',
                'priority'   => '0.7',
            );
        }
        return $urls;
    }

    /* ================================================================
     *  ADMIN
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Top Lists', 'blockticker' ),
            __( 'Top Lists', 'blockticker' ),
            'manage_options',
            'bt-toplists-overview',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $lists = self::get_lists();

        if ( ! empty( $_POST['bt_bust_caches'] ) && check_admin_referer( 'bt_toplist_admin' ) ) {
            self::bust_local_caches();
            foreach ( $lists as $slug => $cfg ) {
                delete_transient( self::CACHE_PREFIX . $slug );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Top-List caches cleared.', 'blockticker' ) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlockTicker — Top Lists', 'blockticker' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Public-facing ranked lists at /top/. Pages are cached as 15-minute transients keyed by slug; the local-source caches auto-bust whenever the bt_crypto_data option updates.', 'blockticker' ); ?>
            </p>

            <form method="post" style="margin:18px 0">
                <?php wp_nonce_field( 'bt_toplist_admin' ); ?>
                <input type="submit" name="bt_bust_caches" class="button" value="<?php esc_attr_e( 'Clear all rendered-page caches', 'blockticker' ); ?>">
                <span class="description" style="margin-left:8px"><?php esc_html_e( 'Forces every list page to re-render on next visit.', 'blockticker' ); ?></span>
            </form>

            <table class="widefat striped" style="max-width:1100px">
                <thead><tr>
                    <th><?php esc_html_e( 'Slug', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Items', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Cache', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'URL', 'blockticker' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $lists as $slug => $cfg ) :
                    $coins  = self::get_coins_for_list( $cfg );
                    $cached = ( get_transient( self::CACHE_PREFIX . $slug ) !== false );
                    $url    = home_url( '/top/' . $slug . '/' );
                ?>
                    <tr>
                        <td><code><?php echo esc_html( $slug ); ?></code></td>
                        <td><?php echo esc_html( $cfg['title'] ); ?></td>
                        <td><?php echo esc_html( ( $cfg['source'] ?? 'local' ) === 'category' ? 'CG: ' . ( $cfg['cg_id'] ?? '' ) : 'local' ); ?></td>
                        <td><?php echo count( $coins ); ?> / <?php echo intval( $cfg['count'] ?? 0 ); ?></td>
                        <td><?php echo $cached ? '<span style="color:#00a32a">●</span> cached' : '<span style="color:#646970">○</span> empty'; ?></td>
                        <td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

BT_TopLists::setup();
