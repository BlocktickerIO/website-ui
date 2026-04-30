<?php
/**
 * BT_APIDocs — OpenAPI 3.0 spec + Swagger UI renderer.
 *
 * Auto-builds a hand-curated OpenAPI 3.0 document describing every
 * documented public endpoint under /wp-json/blockticker/v1/, serves it at
 * /wp-json/blockticker/v1/openapi.json, and provides a
 * [bt_api_swagger] shortcode that drops the Swagger UI v5 right into any
 * page — letting visitors try calls against their own API key directly
 * from the browser.
 *
 * The spec is manually curated rather than auto-generated from
 * rest_get_server()->get_routes(). That gives us accurate descriptions,
 * example responses, and proper parameter docs — which matters more than
 * auto-completeness for a public-facing reference.
 *
 * Swagger UI is loaded from the official JSDelivr CDN (no bundling). For
 * stricter CSPs, sites can set bt_api_swagger_cdn to 'unpkg' or 'self'
 * and self-host the assets.
 *
 * @package BlockTicker
 * @since   115.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_APIDocs {

	const SPEC_VERSION = '3.0.3';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_spec_route' ) );
		add_shortcode( 'bt_api_swagger', array( __CLASS__, 'sc_swagger_ui' ) );
	}

	public static function register_spec_route() {
		register_rest_route( 'blockticker/v1', '/openapi.json', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'serve_spec' ),
			'permission_callback' => '__return_true',
		) );
		// Also publish under the conventional .yaml path name.
		register_rest_route( 'blockticker/v1', '/openapi', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'serve_spec' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function serve_spec( $req ) {
		$spec = self::build_spec();
		// Emit with proper CORS so Swagger UI on any origin can fetch it.
		$resp = new WP_REST_Response( $spec, 200 );
		$resp->header( 'Access-Control-Allow-Origin', '*' );
		$resp->header( 'Cache-Control', 'public, max-age=300' );
		return $resp;
	}

	/* ------------------------------------------------------------------
	 * Spec construction
	 * ------------------------------------------------------------------ */

	public static function build_spec() {
		$site_name = get_option( 'bt_site_name', 'BlockTicker' );
		$base_url  = home_url( '/wp-json/blockticker/v1' );
		$version   = defined( 'BT_VERSION' ) ? BT_VERSION : '1.0.0';

		return array(
			'openapi' => self::SPEC_VERSION,
			'info'    => array(
				'title'       => $site_name . ' REST API',
				'description' => "Live crypto & forex market data, Fear & Greed index, trading signals, "
					. "news headlines, price history, market sentiment, and correlation data. "
					. "\n\n**Authentication.** All endpoints are publicly readable (60 requests/min per IP). "
					. "Send your API key via the `X-BT-API-Key` header to unlock the standard tier (600/min) "
					. "or premium tier (3000/min).\n\n"
					. "**Rate limits.** Responses include `X-BT-RateLimit-Remaining` and `X-BT-RateLimit-Reset` "
					. "headers so clients can self-regulate.\n\n"
					. "**CORS.** All endpoints return `Access-Control-Allow-Origin: *`.\n\n"
					. "**Response envelope.** Every successful response follows the shape `{ data: …, meta: { api_version, generated_at, source, … } }`.\n\n"
					. "**Errors** are standard WP REST shape: `{ code, message, data: { status } }` with the proper HTTP status code.",
				'version'     => $version,
				'contact'     => array( 'name' => $site_name, 'url' => home_url( '/' ) ),
				'license'     => array( 'name' => 'Read-only commercial OK', 'url' => home_url( '/api/terms/' ) ),
			),
			'servers' => array(
				array( 'url' => $base_url, 'description' => 'Production' ),
			),
			'tags' => array(
				array( 'name' => 'Prices',     'description' => 'Live crypto and forex prices' ),
				array( 'name' => 'Market',     'description' => 'Fear & Greed, sentiment, correlation' ),
				array( 'name' => 'News',       'description' => 'News headlines and sentiment' ),
				array( 'name' => 'Signals',    'description' => 'Trading signal feed + leaderboard' ),
				array( 'name' => 'History',    'description' => 'Price history time-series' ),
				array( 'name' => 'Account',    'description' => 'User-facing endpoints (login required)' ),
			),
			'components' => array(
				'securitySchemes' => array(
					'ApiKeyAuth' => array(
						'type' => 'apiKey',
						'in'   => 'header',
						'name' => 'X-BT-API-Key',
						'description' => 'Get a key from your account page or the /user/api-keys endpoint.',
					),
					'CookieAuth' => array(
						'type' => 'apiKey',
						'in'   => 'cookie',
						'name' => 'wordpress_logged_in',
						'description' => 'Logged-in WordPress session cookie (for /user/* endpoints).',
					),
				),
				'schemas' => self::schemas(),
			),
			'paths' => self::paths(),
		);
	}

	/* ------------------------------------------------------------------
	 * Schemas
	 * ------------------------------------------------------------------ */

	private static function schemas() {
		return array(
			'Meta' => array(
				'type' => 'object',
				'properties' => array(
					'api_version'  => array( 'type' => 'string', 'example' => 'v1' ),
					'generated_at' => array( 'type' => 'string', 'format' => 'date-time' ),
					'source'       => array( 'type' => 'string', 'nullable' => true ),
					'rate_limit'   => array( 'type' => 'object', 'nullable' => true ),
					'cached_at'    => array( 'type' => 'string', 'format' => 'date-time', 'nullable' => true ),
				),
			),
			'Error' => array(
				'type' => 'object',
				'properties' => array(
					'code'    => array( 'type' => 'string',  'example' => 'rate_limit_exceeded' ),
					'message' => array( 'type' => 'string' ),
					'data'    => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'integer' ) ) ),
				),
			),
			'Coin' => array(
				'type' => 'object',
				'properties' => array(
					'id'                              => array( 'type' => 'string',  'example' => 'bitcoin' ),
					'symbol'                          => array( 'type' => 'string',  'example' => 'btc' ),
					'name'                            => array( 'type' => 'string',  'example' => 'Bitcoin' ),
					'current_price'                   => array( 'type' => 'number',  'example' => 67234.50 ),
					'market_cap'                      => array( 'type' => 'number',  'example' => 1320000000000 ),
					'total_volume'                    => array( 'type' => 'number',  'example' => 28000000000 ),
					'price_change_percentage_24h'     => array( 'type' => 'number',  'example' => 2.45 ),
					'price_change_percentage_7d'      => array( 'type' => 'number',  'example' => 5.78 ),
					'market_cap_rank'                 => array( 'type' => 'integer', 'example' => 1 ),
				),
			),
			'FearGreed' => array(
				'type' => 'object',
				'properties' => array(
					'value'                => array( 'type' => 'integer', 'example' => 72 ),
					'value_classification' => array( 'type' => 'string',  'example' => 'Greed' ),
					'timestamp'            => array( 'type' => 'integer' ),
					'time_until_update'    => array( 'type' => 'integer', 'nullable' => true ),
				),
			),
			'NewsItem' => array(
				'type' => 'object',
				'properties' => array(
					'title'           => array( 'type' => 'string' ),
					'link'            => array( 'type' => 'string', 'format' => 'uri' ),
					'description'     => array( 'type' => 'string' ),
					'source'          => array( 'type' => 'string' ),
					'category'        => array( 'type' => 'string' ),
					'timestamp'       => array( 'type' => 'integer' ),
					'image'           => array( 'type' => 'string', 'format' => 'uri', 'nullable' => true ),
					'sentiment_score' => array( 'type' => 'number', 'nullable' => true, 'description' => 'Range -1 (extreme bear) to +1 (extreme bull)' ),
				),
			),
			'Signal' => array(
				'type' => 'object',
				'properties' => array(
					'title'       => array( 'type' => 'string' ),
					'link'        => array( 'type' => 'string', 'format' => 'uri' ),
					'source'      => array( 'type' => 'string' ),
					'category'    => array( 'type' => 'string' ),
					'timestamp'   => array( 'type' => 'integer' ),
				),
			),
			'HistoryPoint' => array(
				'type' => 'object',
				'properties' => array(
					'recorded_at' => array( 'type' => 'string',  'format' => 'date-time' ),
					'price_usd'   => array( 'type' => 'number' ),
				),
			),
			'SentimentMood' => array(
				'type' => 'object',
				'properties' => array(
					'score'      => array( 'type' => 'number',  'description' => '-1.0 to +1.0, recency-weighted average' ),
					'label'      => array( 'type' => 'string',  'example' => 'Greed' ),
					'count'      => array( 'type' => 'integer', 'description' => 'Number of scored articles in window' ),
					'total'      => array( 'type' => 'integer', 'description' => 'Total articles in window (scored + unscored)' ),
					'scored_pct' => array( 'type' => 'number' ),
				),
			),
			'CorrelationMatrix' => array(
				'type' => 'object',
				'properties' => array(
					'symbols' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'matrix'  => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'number' ) ) ),
					'dates'   => array( 'type' => 'integer', 'description' => 'Number of aligned daily data points' ),
					'warning' => array( 'type' => 'string', 'nullable' => true ),
				),
			),
			'APIKey' => array(
				'type' => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'string',  'example' => 'bt_a1b2c3d4' ),
					'label'        => array( 'type' => 'string' ),
					'tier'         => array( 'type' => 'string',  'enum' => array( 'public', 'standard', 'premium' ) ),
					'key_preview'  => array( 'type' => 'string',  'description' => 'Masked key suitable for display' ),
					'key'          => array( 'type' => 'string',  'description' => 'Full plaintext key — ONLY returned on POST /user/api-keys' ),
					'rate_per_min' => array( 'type' => 'integer' ),
					'rate_per_day' => array( 'type' => 'integer' ),
					'created'      => array( 'type' => 'integer', 'description' => 'Unix timestamp' ),
					'last_used'    => array( 'type' => 'integer', 'nullable' => true ),
					'usage_today'  => array( 'type' => 'integer' ),
					'usage_total'  => array( 'type' => 'integer' ),
					'revoked'      => array( 'type' => 'boolean' ),
				),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------ */

	private static function paths() {
		return array(
			'/' => array(
				'get' => array(
					'tags'    => array( 'Market' ),
					'summary' => 'API root — overview + endpoint list',
					'responses' => array(
						'200' => array(
							'description' => 'API metadata',
							'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
					),
				),
			),
			'/prices/crypto' => array(
				'get' => array(
					'tags'    => array( 'Prices' ),
					'summary' => 'List top crypto prices',
					'description' => 'Returns live prices for the top N cryptocurrencies sorted by market cap.',
					'parameters' => array(
						array( 'name' => 'limit',  'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500 ), 'description' => 'Number of coins to return' ),
						array( 'name' => 'symbol', 'in' => 'query', 'schema' => array( 'type' => 'string' ), 'description' => 'Filter to one symbol (e.g. `btc`)' ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'List of coins',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/Coin' ) ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
						'429' => self::error_ref( 'Rate limit exceeded' ),
					),
				),
			),
			'/prices/crypto/{symbol}' => array(
				'get' => array(
					'tags'    => array( 'Prices' ),
					'summary' => 'Single crypto lookup',
					'parameters' => array(
						array( 'name' => 'symbol', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ), 'description' => 'Ticker symbol or id (e.g. `btc`, `bitcoin`)' ),
					),
					'responses' => array(
						'200' => array( 'description' => 'Single coin', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
						'404' => self::error_ref( 'Coin not found' ),
					),
				),
			),
			'/prices/forex' => array(
				'get' => array(
					'tags'    => array( 'Prices' ),
					'summary' => 'All tracked forex pair rates',
					'responses' => array( '200' => array( 'description' => 'Forex snapshot' ) ),
				),
			),
			'/prices/forex/{pair}' => array(
				'get' => array(
					'tags'    => array( 'Prices' ),
					'summary' => 'Single forex pair',
					'parameters' => array(
						array( 'name' => 'pair', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ), 'description' => '6-letter pair (e.g. `EURUSD`)' ),
					),
					'responses' => array( '200' => array( 'description' => 'Pair rate' ), '404' => self::error_ref( 'Pair not found' ) ),
				),
			),
			'/fear-greed' => array(
				'get' => array(
					'tags'    => array( 'Market' ),
					'summary' => 'Crypto Fear & Greed Index',
					'description' => 'Returns the current Fear & Greed Index value (0 = Extreme Fear, 100 = Extreme Greed) along with its classification label.',
					'responses' => array(
						'200' => array(
							'description' => 'Current F&G',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( '$ref' => '#/components/schemas/FearGreed' ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/sentiment' => array(
				'get' => array(
					'tags'    => array( 'Market' ),
					'summary' => 'News-derived market sentiment (v104)',
					'description' => 'Recency-weighted mood score computed from scored news items. Articles in the last 6h are weighted 3×; 12h 2×; 24h 1×.',
					'parameters' => array(
						array( 'name' => 'hours', 'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 168 ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Sentiment snapshot',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( '$ref' => '#/components/schemas/SentimentMood' ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/correlation' => array(
				'get' => array(
					'tags'    => array( 'Market' ),
					'summary' => 'Pair-wise price correlation (v106)',
					'description' => 'Pearson correlation of daily log-returns between the requested symbols.',
					'parameters' => array(
						array( 'name' => 'symbols', 'in' => 'query', 'schema' => array( 'type' => 'string' ), 'description' => 'Comma-separated list (max 12). Default: `BTC,ETH,SOL,EUR/USD,GBP/USD,XAU`' ),
						array( 'name' => 'days',    'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 7, 'maximum' => 365 ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Correlation matrix',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( '$ref' => '#/components/schemas/CorrelationMatrix' ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/news' => array(
				'get' => array(
					'tags'    => array( 'News' ),
					'summary' => 'Latest news headlines',
					'parameters' => array(
						array( 'name' => 'limit',    'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ) ),
						array( 'name' => 'category', 'in' => 'query', 'schema' => array( 'type' => 'string' ), 'description' => 'Filter by category slug (e.g. `crypto`, `forex`)' ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Article list',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/NewsItem' ) ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/signals' => array(
				'get' => array(
					'tags'    => array( 'Signals' ),
					'summary' => 'Latest trading signals',
					'parameters' => array(
						array( 'name' => 'limit', 'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Signal list',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/Signal' ) ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/history/{symbol}' => array(
				'get' => array(
					'tags'    => array( 'History' ),
					'summary' => 'Price history time-series',
					'parameters' => array(
						array( 'name' => 'symbol',     'in' => 'path',  'required' => true, 'schema' => array( 'type' => 'string' ), 'description' => 'Ticker or pair (e.g. `BTC`, `EUR/USD`)' ),
						array( 'name' => 'days',       'in' => 'query', 'schema' => array( 'type' => 'integer', 'default' => 7, 'minimum' => 1, 'maximum' => 365 ) ),
						array( 'name' => 'resolution', 'in' => 'query', 'schema' => array( 'type' => 'string', 'enum' => array( '5m', '1h', '1d' ), 'default' => '1h' ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Time-series',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'data' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/HistoryPoint' ) ),
									'meta' => array( '$ref' => '#/components/schemas/Meta' ),
								),
							) ) ),
						),
					),
				),
			),
			'/user/api-keys' => array(
				'get' => array(
					'tags'    => array( 'Account' ),
					'summary' => "List the current user's API keys (v115)",
					'security' => array( array( 'CookieAuth' => array() ) ),
					'responses' => array(
						'200' => array(
							'description' => 'Key list (keys are masked)',
							'content' => array( 'application/json' => array( 'schema' => array(
								'type' => 'object',
								'properties' => array(
									'keys' => array( 'type' => 'array', 'items' => array( '$ref' => '#/components/schemas/APIKey' ) ),
								),
							) ) ),
						),
						'401' => self::error_ref( 'Not logged in' ),
					),
				),
				'post' => array(
					'tags'    => array( 'Account' ),
					'summary' => 'Mint a new API key',
					'security' => array( array( 'CookieAuth' => array() ) ),
					'requestBody' => array(
						'content' => array( 'application/json' => array( 'schema' => array(
							'type' => 'object',
							'properties' => array(
								'label' => array( 'type' => 'string',  'description' => 'Human-readable label (max 80 chars)' ),
								'tier'  => array( 'type' => 'string',  'enum' => array( 'public', 'standard', 'premium' ), 'default' => 'standard' ),
							),
						) ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'Newly minted key. The plaintext `key` is returned ONLY in this response.',
							'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/APIKey' ) ) ),
						),
						'429' => self::error_ref( 'Per-user key limit reached (10 active)' ),
					),
				),
			),
			'/user/api-keys/{id}' => array(
				'delete' => array(
					'tags'    => array( 'Account' ),
					'summary' => 'Revoke an API key',
					'security' => array( array( 'CookieAuth' => array() ) ),
					'parameters' => array(
						array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string', 'pattern' => '^bt_[a-f0-9]{8}$' ) ),
					),
					'responses' => array(
						'200' => array( 'description' => 'Revoked' ),
						'404' => self::error_ref( 'Key not found or not owned by user' ),
					),
				),
			),
			'/user/api-keys/{id}/rotate' => array(
				'post' => array(
					'tags'    => array( 'Account' ),
					'summary' => 'Rotate — revoke the old secret and issue a new one',
					'security' => array( array( 'CookieAuth' => array() ) ),
					'parameters' => array(
						array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string', 'pattern' => '^bt_[a-f0-9]{8}$' ) ),
					),
					'responses' => array(
						'200' => array(
							'description' => 'New key (plaintext returned once)',
							'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/APIKey' ) ) ),
						),
					),
				),
			),
			'/openapi.json' => array(
				'get' => array(
					'tags'    => array( 'Market' ),
					'summary' => 'This OpenAPI 3.0 spec',
					'responses' => array( '200' => array( 'description' => 'The spec itself' ) ),
				),
			),
		);
	}

	private static function error_ref( $description ) {
		return array(
			'description' => $description,
			'content' => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Shortcode: [bt_api_swagger]
	 * ------------------------------------------------------------------ */

	public static function sc_swagger_ui( $atts ) {
		$a = shortcode_atts( array(
			'height'  => '85vh',
			'cdn'     => get_option( 'bt_api_swagger_cdn', 'jsdelivr' ), // jsdelivr | unpkg | self
			'try_it'  => '1',
			'theme'   => 'auto', // auto | light | dark
		), $atts );

		$spec_url = esc_url_raw( rest_url( 'blockticker/v1/openapi.json' ) );

		$css_src = $a['cdn'] === 'unpkg'
			? 'https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css'
			: 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css';
		$js_bundle   = $a['cdn'] === 'unpkg'
			? 'https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js'
			: 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js';
		$js_preset   = $a['cdn'] === 'unpkg'
			? 'https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js'
			: 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js';

		$uid = 'bt-swagger-' . wp_generate_password( 6, false, false );

		ob_start(); ?>
		<div class="bt-swagger-shell">
			<link rel="stylesheet" href="<?php echo esc_url( $css_src ); ?>" crossorigin>
			<div id="<?php echo esc_attr( $uid ); ?>" class="bt-swagger-mount" style="min-height:<?php echo esc_attr( $a['height'] ); ?>;"></div>

			<script src="<?php echo esc_url( $js_bundle ); ?>" crossorigin></script>
			<script src="<?php echo esc_url( $js_preset ); ?>" crossorigin></script>
			<script>
			(function(){
				function boot(){
					if (!window.SwaggerUIBundle) return setTimeout(boot, 150);
					window.SwaggerUIBundle({
						url:               '<?php echo esc_js( $spec_url ); ?>',
						dom_id:            '#<?php echo esc_js( $uid ); ?>',
						deepLinking:       true,
						presets:           [ SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset ],
						plugins:           [ SwaggerUIBundle.plugins.DownloadUrl ],
						layout:            'StandaloneLayout',
						defaultModelsExpandDepth: 0,
						tryItOutEnabled:   <?php echo $a['try_it'] ? 'true' : 'false'; ?>,
						persistAuthorization: true,
						displayRequestDuration: true,
						filter:            true,
						syntaxHighlight:   { theme: 'nord' }
					});
				}
				boot();
			})();
			</script>
			<style>
			.bt-swagger-shell{margin:16px 0;border:1px solid #1e2535;border-radius:0;overflow:hidden;background:#fafafa}
			.bt-swagger-shell .topbar{display:none !important}
			<?php if ( $a['theme'] === 'dark' || $a['theme'] === 'auto' ) : ?>
			@media (prefers-color-scheme: dark) {
				.bt-swagger-shell{background:#0b0f1a}
				.bt-swagger-shell .swagger-ui{filter:invert(.92) hue-rotate(180deg)}
				.bt-swagger-shell .swagger-ui .highlight-code,.bt-swagger-shell .swagger-ui .microlight{filter:invert(1) hue-rotate(180deg)}
			}
			<?php endif; ?>
			</style>
		</div>
		<?php
		return ob_get_clean();
	}
}
