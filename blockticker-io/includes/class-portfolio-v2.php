<?php
/**
 * BT_PortfolioV2 — Portfolio Tracker 3.0.
 *
 * Upgrades the v52-era flat-holdings tracker in BT_Portfolio to a
 * transaction-level ledger with proper cost-basis accounting. Runs alongside
 * the legacy class — does NOT replace it, so existing [bt_portfolio] /
 * [fxlm_portfolio] embeds keep working identically.
 *
 * Key capabilities v113 can't do:
 *   - SELL transactions (legacy only tracks running avgBuy)
 *   - Realized P&L from closed lots
 *   - FIFO / HIFO / Average (ACB) cost-basis methods
 *   - CSV import with flexible header detection
 *   - Per-transaction fees and notes
 *   - Server-side storage for logged-in users (usermeta)
 *
 * Data model (stored in usermeta `bt_portfolio_txns` as a JSON array;
 * localStorage `bt_portfolio_txns` for guests):
 *   {
 *     id:       string  "txn_xxxxxxxx" (8-char random suffix)
 *     kind:     string  "buy" | "sell"
 *     coin_id:  string  CoinGecko-style id, e.g. "bitcoin"
 *     symbol:   string  uppercase ticker, e.g. "BTC"
 *     qty:      float   units bought or sold (always positive)
 *     price:    float   per-unit USD price at the transaction
 *     fee:      float   USD fee for the transaction (0 if none)
 *     ts:       int     unix timestamp (seconds)
 *     note:     string  optional free-text
 *   }
 *
 * Shortcodes:
 *   [bt_portfolio_v2]      — full dashboard (add txn + table + summary)
 *   [bt_portfolio_summary] — one-row summary widget
 *   [bt_portfolio_import]  — CSV import form
 *
 * REST:
 *   GET    /wp-json/blockticker/v1/portfolio/v2/transactions
 *   POST   /wp-json/blockticker/v1/portfolio/v2/transactions  (create or batch)
 *   DELETE /wp-json/blockticker/v1/portfolio/v2/transactions/(?P<id>[a-z0-9_]+)
 *   POST   /wp-json/blockticker/v1/portfolio/v2/import        (multipart CSV)
 *
 * @package BlockTicker
 * @since   114.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_PortfolioV2 {

	const META_TXNS    = 'bt_portfolio_txns';
	const META_METHOD  = 'bt_portfolio_method'; // 'fifo' | 'hifo' | 'acb'
	const MAX_TXNS     = 5000; // per user — guardrail against abuse

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	public static function init() {
		add_shortcode( 'bt_portfolio_v2',      array( __CLASS__, 'sc_portfolio_v2' ) );
		add_shortcode( 'bt_portfolio_summary', array( __CLASS__, 'sc_portfolio_summary' ) );
		add_shortcode( 'bt_portfolio_import',  array( __CLASS__, 'sc_portfolio_import' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_rest_routes() {
		$ns = 'blockticker/v1';

		register_rest_route( $ns, '/portfolio/v2/transactions', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_txns' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save_txns' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			),
		) );

		register_rest_route( $ns, '/portfolio/v2/transactions/(?P<id>[a-z0-9_]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'rest_delete_txn' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
			'args'                => array(
				'id' => array( 'validate_callback' => function( $v ) { return is_string( $v ) && preg_match( '/^[a-z0-9_]{3,64}$/', $v ); } ),
			),
		) );

		register_rest_route( $ns, '/portfolio/v2/import', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_import_csv' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( $ns, '/portfolio/v2/method', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_set_method' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );
	}

	public static function require_login() {
		return is_user_logged_in();
	}

	/* ------------------------------------------------------------------
	 * Storage helpers
	 * ------------------------------------------------------------------ */

	public static function get_user_txns( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_TXNS, true );
		if ( ! $raw ) return array();
		$arr = json_decode( $raw, true );
		return is_array( $arr ) ? $arr : array();
	}

	public static function save_user_txns( $user_id, $txns ) {
		if ( ! is_array( $txns ) ) $txns = array();
		if ( count( $txns ) > self::MAX_TXNS ) {
			// Keep the newest N — guardrail.
			usort( $txns, function( $a, $b ) { return ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ); } );
			$txns = array_slice( $txns, 0, self::MAX_TXNS );
		}
		update_user_meta( (int) $user_id, self::META_TXNS, wp_json_encode( array_values( $txns ) ) );
		return count( $txns );
	}

	public static function get_user_method( $user_id ) {
		$m = get_user_meta( (int) $user_id, self::META_METHOD, true );
		return in_array( $m, array( 'fifo', 'hifo', 'acb' ), true ) ? $m : 'fifo';
	}

	/* ------------------------------------------------------------------
	 * Validation / sanitisation
	 * ------------------------------------------------------------------ */

	public static function sanitise_txn( $t ) {
		if ( ! is_array( $t ) ) return null;
		$kind = strtolower( (string) ( $t['kind'] ?? '' ) );
		if ( ! in_array( $kind, array( 'buy', 'sell' ), true ) ) return null;

		$coin_id = sanitize_key( (string) ( $t['coin_id'] ?? '' ) );
		if ( $coin_id === '' ) return null;

		$symbol = strtoupper( preg_replace( '/[^A-Za-z0-9\/]/', '', (string) ( $t['symbol'] ?? '' ) ) );
		if ( $symbol === '' ) $symbol = strtoupper( $coin_id );

		$qty   = (float) ( $t['qty']   ?? 0 );
		$price = (float) ( $t['price'] ?? 0 );
		$fee   = (float) ( $t['fee']   ?? 0 );
		if ( $qty <= 0 || $price < 0 || $fee < 0 ) return null;

		$ts = (int) ( $t['ts'] ?? 0 );
		if ( $ts <= 0 ) $ts = time();
		// Reject far-future timestamps (probably ms vs s confusion).
		if ( $ts > 4102444800 ) $ts = (int) ( $ts / 1000 );

		$note = sanitize_text_field( (string) ( $t['note'] ?? '' ) );
		if ( BT_Utils::strlen_unicode( $note ) > 200 ) $note = BT_Utils::substr_unicode( $note, 0, 200 );

		$id = isset( $t['id'] ) ? sanitize_key( (string) $t['id'] ) : '';
		if ( $id === '' || ! preg_match( '/^[a-z0-9_]{3,64}$/', $id ) ) {
			$id = 'txn_' . substr( md5( wp_generate_password( 12, false ) . microtime( true ) ), 0, 8 );
		}

		return array(
			'id'      => $id,
			'kind'    => $kind,
			'coin_id' => $coin_id,
			'symbol'  => $symbol,
			'qty'     => round( $qty,   10 ),
			'price'   => round( $price, 8 ),
			'fee'     => round( $fee,   4 ),
			'ts'      => $ts,
			'note'    => $note,
		);
	}

	/* ------------------------------------------------------------------
	 * Cost-basis engines
	 *
	 * All engines consume a time-ordered list of transactions for ONE coin
	 * and produce:
	 *   {
	 *     qty_held:       float   currently held units
	 *     cost_basis:     float   total USD still invested in open lots (incl fees pro-rata)
	 *     realized_pnl:   float   realized gain/loss across all closed lots
	 *     sell_count:     int     number of sell transactions processed
	 *     broken:         bool    true if sells ever exceeded available qty (data issue)
	 *   }
	 * ------------------------------------------------------------------ */

	public static function compute_position( $txns, $method = 'fifo' ) {
		if ( ! is_array( $txns ) || ! $txns ) {
			return array(
				'qty_held'     => 0.0,
				'cost_basis'   => 0.0,
				'realized_pnl' => 0.0,
				'sell_count'   => 0,
				'broken'       => false,
			);
		}

		// Sort ascending by timestamp (oldest first) for deterministic processing.
		usort( $txns, function( $a, $b ) { return ( $a['ts'] ?? 0 ) <=> ( $b['ts'] ?? 0 ); } );

		if ( $method === 'acb' ) {
			return self::_position_acb( $txns );
		}
		return self::_position_lots( $txns, $method );
	}

	/**
	 * Lot-based engines (FIFO and HIFO). Open lots carry qty + effective
	 * per-unit cost including a pro-rata share of the buy fee.
	 */
	private static function _position_lots( $txns, $method ) {
		$lots = array(); // each: { qty, unit_cost, ts }
		$realized = 0.0;
		$sell_count = 0;
		$broken = false;

		foreach ( $txns as $t ) {
			if ( ! isset( $t['kind'], $t['qty'], $t['price'] ) ) continue;
			$qty   = (float) $t['qty'];
			$price = (float) $t['price'];
			$fee   = (float) ( $t['fee'] ?? 0 );
			if ( $qty <= 0 ) continue;

			if ( $t['kind'] === 'buy' ) {
				// Effective unit cost absorbs the fee, giving a true cost basis.
				$unit = $price + ( $qty > 0 ? $fee / $qty : 0 );
				$lots[] = array( 'qty' => $qty, 'unit_cost' => $unit, 'ts' => (int) ( $t['ts'] ?? 0 ) );
				continue;
			}

			// SELL — deplete lots by the chosen strategy.
			$remaining = $qty;

			// Order lots for this strategy.
			if ( $method === 'hifo' ) {
				usort( $lots, function( $a, $b ) { return ( $b['unit_cost'] ?? 0 ) <=> ( $a['unit_cost'] ?? 0 ); } );
			} else { // fifo
				usort( $lots, function( $a, $b ) { return ( $a['ts'] ?? 0 ) <=> ( $b['ts'] ?? 0 ); } );
			}

			foreach ( $lots as &$lot ) {
				if ( $remaining <= 0 ) break;
				if ( $lot['qty'] <= 0 )  continue;
				$take = min( $lot['qty'], $remaining );
				// Realized = (sell_price - lot_unit_cost) * take, minus proportional sell fee.
				$sell_fee_share = $qty > 0 ? $fee * ( $take / $qty ) : 0;
				$realized += ( $price - $lot['unit_cost'] ) * $take - $sell_fee_share;
				$lot['qty'] -= $take;
				$remaining   -= $take;
			}
			unset( $lot );

			// Drop fully-consumed lots.
			$lots = array_values( array_filter( $lots, function( $l ) { return $l['qty'] > 0.0000000001; } ) );

			if ( $remaining > 0.0000000001 ) {
				// Sold more than was held. Typical cause: user didn't import their
				// buy history fully. Treat the excess sell as pure realized
				// proceeds (cost basis of 0) and flag "broken" for the UI.
				$realized += $price * $remaining;
				$broken   = true;
			}
			$sell_count++;
		}

		// Open-lot aggregates.
		$qty_held   = 0.0;
		$cost_basis = 0.0;
		foreach ( $lots as $lot ) {
			$qty_held   += $lot['qty'];
			$cost_basis += $lot['qty'] * $lot['unit_cost'];
		}

		return array(
			'qty_held'     => $qty_held,
			'cost_basis'   => $cost_basis,
			'realized_pnl' => $realized,
			'sell_count'   => $sell_count,
			'broken'       => $broken,
		);
	}

	/**
	 * Average-Cost-Basis engine. At every sell, the realized gain uses the
	 * current running average unit cost; the cost basis pool is reduced by
	 * (sold_qty * average_unit_cost).
	 */
	private static function _position_acb( $txns ) {
		$qty       = 0.0;
		$cost_pool = 0.0;  // sum of (lot_qty * unit_cost) across all surviving units
		$realized  = 0.0;
		$sell_count = 0;
		$broken    = false;

		foreach ( $txns as $t ) {
			if ( ! isset( $t['kind'], $t['qty'], $t['price'] ) ) continue;
			$q = (float) $t['qty'];
			$p = (float) $t['price'];
			$f = (float) ( $t['fee'] ?? 0 );
			if ( $q <= 0 ) continue;

			if ( $t['kind'] === 'buy' ) {
				$qty       += $q;
				$cost_pool += ( $p * $q ) + $f;
				continue;
			}

			// SELL at average
			$avg = $qty > 0 ? $cost_pool / $qty : 0;

			if ( $q > $qty + 0.0000000001 ) {
				// Overshoot — realized assumes unknown buys at zero basis beyond pool.
				$covered         = max( 0.0, $qty );
				$uncovered       = $q - $covered;
				$realized       += ( $p - $avg ) * $covered - $f * ( $covered / $q ) + ( $p * $uncovered );
				$cost_pool       = 0.0;
				$qty             = 0.0;
				$broken          = true;
			} else {
				$realized   += ( $p - $avg ) * $q - $f;
				$cost_pool  -= $avg * $q;
				$qty        -= $q;
				if ( $qty < 1e-12 ) { $qty = 0.0; $cost_pool = 0.0; }
			}
			$sell_count++;
		}

		return array(
			'qty_held'     => $qty,
			'cost_basis'   => max( 0.0, $cost_pool ),
			'realized_pnl' => $realized,
			'sell_count'   => $sell_count,
			'broken'       => $broken,
		);
	}

	/* ------------------------------------------------------------------
	 * Portfolio-wide aggregator
	 * ------------------------------------------------------------------ */

	/**
	 * Group transactions by coin_id and compute each position.
	 * Returns a keyed array [coin_id => { name, symbol, current_price, position, unrealized_pnl, value }]
	 * plus a totals row.
	 */
	public static function compute_portfolio( $txns, $method = 'fifo' ) {
		$by_coin = array();
		foreach ( $txns as $t ) {
			$cid = $t['coin_id'] ?? '';
			if ( $cid === '' ) continue;
			if ( ! isset( $by_coin[ $cid ] ) ) $by_coin[ $cid ] = array();
			$by_coin[ $cid ][] = $t;
		}

		// Pull live price lookup once.
		$prices_by_id     = array();
		$prices_by_symbol = array();
		$names_by_id      = array();
		if ( class_exists( 'BT_Widgets' ) ) {
			$crypto = BT_Widgets::get_json_option( 'bt_crypto_data' );
			foreach ( $crypto['coins'] ?? array() as $c ) {
				$cid  = strtolower( $c['id']     ?? '' );
				$csym = strtoupper( $c['symbol'] ?? '' );
				$cpr  = (float)     ( $c['current_price'] ?? 0 );
				if ( $cid  ) { $prices_by_id[ $cid ]      = $cpr; $names_by_id[ $cid ] = $c['name'] ?? $cid; }
				if ( $csym ) { $prices_by_symbol[ $csym ] = $cpr; }
			}
		}

		$rows = array();
		$t_value = 0.0; $t_basis = 0.0; $t_realized = 0.0; $t_unrealized = 0.0;

		foreach ( $by_coin as $cid => $ctxns ) {
			$pos   = self::compute_position( $ctxns, $method );
			$cur   = $prices_by_id[ $cid ] ?? $prices_by_symbol[ strtoupper( $ctxns[0]['symbol'] ?? '' ) ] ?? 0;
			$val   = $pos['qty_held'] * $cur;
			$unrl  = $val - $pos['cost_basis'];

			$rows[ $cid ] = array(
				'coin_id'       => $cid,
				'symbol'        => strtoupper( $ctxns[0]['symbol'] ?? '' ),
				'name'          => $names_by_id[ $cid ] ?? ucwords( str_replace( '-', ' ', $cid ) ),
				'current_price' => $cur,
				'qty_held'      => $pos['qty_held'],
				'cost_basis'    => $pos['cost_basis'],
				'avg_cost'      => $pos['qty_held'] > 0 ? ( $pos['cost_basis'] / $pos['qty_held'] ) : 0,
				'value'         => $val,
				'realized_pnl'  => $pos['realized_pnl'],
				'unrealized_pnl'=> $unrl,
				'sell_count'    => $pos['sell_count'],
				'broken'        => $pos['broken'],
				'txn_count'     => count( $ctxns ),
			);

			$t_value      += $val;
			$t_basis      += $pos['cost_basis'];
			$t_realized   += $pos['realized_pnl'];
			$t_unrealized += $unrl;
		}

		// Sort by value desc.
		uasort( $rows, function( $a, $b ) { return $b['value'] <=> $a['value']; } );

		return array(
			'method'   => $method,
			'rows'     => $rows,
			'totals'   => array(
				'value'          => $t_value,
				'cost_basis'     => $t_basis,
				'realized_pnl'   => $t_realized,
				'unrealized_pnl' => $t_unrealized,
				'total_pnl'      => $t_realized + $t_unrealized,
				'total_pct'      => $t_basis > 0 ? ( ( $t_realized + $t_unrealized ) / $t_basis ) * 100 : 0,
			),
			'generated_at' => time(),
		);
	}

	/* ------------------------------------------------------------------
	 * REST handlers
	 * ------------------------------------------------------------------ */

	public static function rest_get_txns( $req ) {
		$uid    = get_current_user_id();
		$method = self::get_user_method( $uid );
		$txns   = self::get_user_txns( $uid );
		$agg    = self::compute_portfolio( $txns, $method );

		return rest_ensure_response( array(
			'transactions' => array_values( $txns ),
			'method'       => $method,
			'summary'      => $agg,
		) );
	}

	public static function rest_save_txns( $req ) {
		$uid      = get_current_user_id();
		$existing = self::get_user_txns( $uid );
		$body     = $req->get_json_params();

		// Two accepted shapes: single txn {kind,coin_id,…} OR {transactions:[…]} bulk replace.
		if ( isset( $body['transactions'] ) && is_array( $body['transactions'] ) ) {
			$clean = array();
			foreach ( $body['transactions'] as $t ) {
				$c = self::sanitise_txn( $t );
				if ( $c ) $clean[] = $c;
			}
			self::save_user_txns( $uid, $clean );
			$agg = self::compute_portfolio( $clean, self::get_user_method( $uid ) );
			return rest_ensure_response( array( 'success' => true, 'count' => count( $clean ), 'summary' => $agg ) );
		}

		// Single add.
		$clean = self::sanitise_txn( $body );
		if ( ! $clean ) return new WP_Error( 'invalid', 'Invalid transaction', array( 'status' => 400 ) );

		// Upsert by id.
		$found = false;
		foreach ( $existing as $i => $t ) {
			if ( ( $t['id'] ?? '' ) === $clean['id'] ) { $existing[ $i ] = $clean; $found = true; break; }
		}
		if ( ! $found ) $existing[] = $clean;

		self::save_user_txns( $uid, $existing );
		$agg = self::compute_portfolio( $existing, self::get_user_method( $uid ) );
		return rest_ensure_response( array( 'success' => true, 'txn' => $clean, 'summary' => $agg ) );
	}

	public static function rest_delete_txn( $req ) {
		$uid = get_current_user_id();
		$id  = (string) $req['id'];

		$txns = self::get_user_txns( $uid );
		$before = count( $txns );
		$txns = array_values( array_filter( $txns, function( $t ) use ( $id ) { return ( $t['id'] ?? '' ) !== $id; } ) );
		$after = count( $txns );

		if ( $before === $after ) {
			return new WP_Error( 'not_found', 'Transaction not found', array( 'status' => 404 ) );
		}
		self::save_user_txns( $uid, $txns );
		$agg = self::compute_portfolio( $txns, self::get_user_method( $uid ) );
		return rest_ensure_response( array( 'success' => true, 'deleted' => $id, 'summary' => $agg ) );
	}

	public static function rest_set_method( $req ) {
		$uid    = get_current_user_id();
		$method = strtolower( (string) $req->get_param( 'method' ) );
		if ( ! in_array( $method, array( 'fifo', 'hifo', 'acb' ), true ) ) {
			return new WP_Error( 'invalid', 'Method must be fifo, hifo, or acb', array( 'status' => 400 ) );
		}
		update_user_meta( $uid, self::META_METHOD, $method );
		$agg = self::compute_portfolio( self::get_user_txns( $uid ), $method );
		return rest_ensure_response( array( 'success' => true, 'method' => $method, 'summary' => $agg ) );
	}

	public static function rest_import_csv( $req ) {
		$uid = get_current_user_id();
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded (multipart field "file" required)', array( 'status' => 400 ) );
		}
		$path = $_FILES['file']['tmp_name'];
		$size = (int) ( $_FILES['file']['size'] ?? 0 );
		if ( $size > 2 * 1024 * 1024 ) {
			return new WP_Error( 'too_large', 'CSV exceeds 2 MB limit', array( 'status' => 413 ) );
		}

		$result = self::parse_csv_file( $path );
		if ( is_wp_error( $result ) ) return $result;

		$mode = sanitize_key( (string) ( $_POST['mode'] ?? 'append' ) );
		$txns = self::get_user_txns( $uid );
		if ( $mode === 'replace' ) $txns = array();

		$added = 0; $skipped = 0;
		foreach ( $result['rows'] as $raw ) {
			$c = self::sanitise_txn( $raw );
			if ( ! $c ) { $skipped++; continue; }
			// Dedupe: if an existing txn has the same coin, kind, qty, price AND ts ±1m, skip.
			$dup = false;
			foreach ( $txns as $existing ) {
				if ( ( $existing['coin_id'] ?? '' ) === $c['coin_id']
				  && ( $existing['kind']    ?? '' ) === $c['kind']
				  && abs( ( $existing['qty']   ?? 0 ) - $c['qty'] )   < 1e-9
				  && abs( ( $existing['price'] ?? 0 ) - $c['price'] ) < 1e-6
				  && abs( ( $existing['ts']    ?? 0 ) - $c['ts'] )    < 60 ) {
					$dup = true; break;
				}
			}
			if ( $dup ) { $skipped++; continue; }
			$txns[] = $c;
			$added++;
		}
		self::save_user_txns( $uid, $txns );
		$agg = self::compute_portfolio( $txns, self::get_user_method( $uid ) );

		return rest_ensure_response( array(
			'success'  => true,
			'added'    => $added,
			'skipped'  => $skipped,
			'total'    => count( $txns ),
			'dialect'  => $result['dialect'],
			'summary'  => $agg,
		) );
	}

	/* ------------------------------------------------------------------
	 * CSV parsing — flexible header detection
	 * ------------------------------------------------------------------ */

	/**
	 * Parse an uploaded CSV into a list of raw txn shapes (pre-sanitise).
	 * Auto-detects the dialect: bt_native / coinbase / binance / kraken / generic.
	 *
	 * Recognised column aliases:
	 *   date / timestamp / time / created at / transaction date
	 *   type / side / kind / transaction type
	 *   symbol / asset / ticker / currency / base asset
	 *   coin / coin id / asset id
	 *   qty / quantity / amount / size / units / filled
	 *   price / unit price / price per coin / trade price
	 *   fee / fees / commission
	 *   note / notes / memo / description
	 */
	public static function parse_csv_file( $path ) {
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) return new WP_Error( 'read_fail', 'Cannot read uploaded file', array( 'status' => 500 ) );

		// Detect delimiter from first non-empty line.
		$first = '';
		while ( ! feof( $fh ) ) {
			$first = fgets( $fh, 8192 );
			if ( trim( $first ) !== '' ) break;
		}
		if ( $first === '' ) { fclose( $fh ); return new WP_Error( 'empty', 'CSV is empty', array( 'status' => 400 ) ); }

		$delim = self::detect_delimiter( $first );
		rewind( $fh );

		$header = fgetcsv( $fh, 0, $delim );
		if ( ! $header ) { fclose( $fh ); return new WP_Error( 'bad_csv', 'Missing header row', array( 'status' => 400 ) ); }

		$map = self::map_headers( $header );
		if ( ! isset( $map['type'] ) || ! ( isset( $map['symbol'] ) || isset( $map['coin'] ) ) || ! isset( $map['qty'] ) || ! isset( $map['price'] ) ) {
			fclose( $fh );
			return new WP_Error( 'missing_cols', 'CSV must have type, symbol (or coin), qty, price columns', array( 'status' => 400 ) );
		}

		$rows = array();
		while ( ( $r = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			if ( empty( $r ) || ( count( $r ) === 1 && trim( (string) $r[0] ) === '' ) ) continue;

			$type_raw = strtolower( trim( $r[ $map['type'] ] ?? '' ) );
			$kind     = self::normalise_kind( $type_raw );
			if ( ! $kind ) continue;

			$symbol  = isset( $map['symbol'] ) ? strtoupper( trim( (string) ( $r[ $map['symbol'] ] ?? '' ) ) ) : '';
			$coin_id = isset( $map['coin'] )   ? sanitize_key( (string) ( $r[ $map['coin'] ] ?? '' ) ) : '';

			// If we only have a symbol, guess a coin_id from well-known mappings.
			if ( $coin_id === '' ) $coin_id = self::symbol_to_coin_id( $symbol );
			if ( $coin_id === '' ) continue;
			if ( $symbol === '' )  $symbol  = strtoupper( $coin_id );

			$qty   = (float) self::strip_currency( (string) ( $r[ $map['qty'] ]   ?? '0' ) );
			$price = (float) self::strip_currency( (string) ( $r[ $map['price'] ] ?? '0' ) );
			$fee   = isset( $map['fee'] )  ? (float) self::strip_currency( (string) ( $r[ $map['fee'] ] ?? '0' ) ) : 0;
			$note  = isset( $map['note'] ) ? (string) ( $r[ $map['note'] ] ?? '' ) : '';
			$ts    = isset( $map['date'] ) ? self::parse_date( (string) ( $r[ $map['date'] ] ?? '' ) ) : time();

			if ( $qty <= 0 || $price < 0 ) continue;

			$rows[] = array(
				'kind'    => $kind,
				'coin_id' => $coin_id,
				'symbol'  => $symbol,
				'qty'     => $qty,
				'price'   => $price,
				'fee'     => $fee,
				'ts'      => $ts,
				'note'    => $note,
			);
		}
		fclose( $fh );

		return array(
			'rows'    => $rows,
			'dialect' => self::guess_dialect( $header ),
		);
	}

	private static function detect_delimiter( $line ) {
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );
		return key( $counts );
	}

	private static function map_headers( $header ) {
		$map = array();
		foreach ( $header as $i => $h ) {
			$k = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $h ) ) );
			if ( in_array( $k, array( 'date', 'timestamp', 'time', 'created at', 'transaction date', 'trade time', 'filled at', 'time (utc)' ), true ) ) $map['date']   = $i;
			if ( in_array( $k, array( 'type', 'side', 'kind', 'transaction type', 'operation', 'buy/sell', 'action' ),               true ) ) $map['type']   = $i;
			if ( in_array( $k, array( 'symbol', 'asset', 'ticker', 'currency', 'base asset', 'asset symbol', 'pair' ),                true ) ) $map['symbol'] = $i;
			if ( in_array( $k, array( 'coin', 'coin id', 'asset id', 'asset name', 'coin name' ),                                     true ) ) $map['coin']   = $i;
			if ( in_array( $k, array( 'qty', 'quantity', 'amount', 'size', 'units', 'filled', 'executed qty', 'shares' ),             true ) ) $map['qty']    = $i;
			if ( in_array( $k, array( 'price', 'unit price', 'price per coin', 'trade price', 'fill price', 'spot price usd' ),       true ) ) $map['price']  = $i;
			if ( in_array( $k, array( 'fee', 'fees', 'commission', 'trading fee', 'fee amount' ),                                     true ) ) $map['fee']    = $i;
			if ( in_array( $k, array( 'note', 'notes', 'memo', 'description', 'remark' ),                                             true ) ) $map['note']   = $i;
		}
		return $map;
	}

	private static function normalise_kind( $type_raw ) {
		$buy  = array( 'buy', 'b', 'long', 'purchase', 'in', 'deposit', 'acquire' );
		$sell = array( 'sell', 's', 'short', 'disposal', 'out', 'withdraw', 'dispose' );
		foreach ( $buy as $w )  if ( strpos( $type_raw, $w ) !== false ) return 'buy';
		foreach ( $sell as $w ) if ( strpos( $type_raw, $w ) !== false ) return 'sell';
		return '';
	}

	private static function strip_currency( $s ) {
		$s = (string) $s;
		// Remove $, €, £, comma thousands separators.
		$s = preg_replace( '/[^\d\.\-]/', '', $s );
		return $s === '' ? '0' : $s;
	}

	private static function parse_date( $s ) {
		$s = trim( (string) $s );
		if ( $s === '' ) return time();
		if ( ctype_digit( $s ) && strlen( $s ) >= 10 ) {
			$v = (int) $s;
			return $v > 4102444800 ? (int) ( $v / 1000 ) : $v;
		}
		$ts = strtotime( $s );
		return $ts ?: time();
	}

	private static function guess_dialect( $header ) {
		$joined = strtolower( implode( '|', array_map( 'strval', $header ) ) );
		if ( strpos( $joined, 'base asset' ) !== false && strpos( $joined, 'executed qty' ) !== false ) return 'binance';
		if ( strpos( $joined, 'transaction type' ) !== false && strpos( $joined, 'spot price usd' ) !== false ) return 'coinbase';
		if ( strpos( $joined, 'ledgers' ) !== false || strpos( $joined, 'refid' ) !== false ) return 'kraken';
		return 'generic';
	}

	/** Translate a ticker to the CoinGecko-style coin_id for a handful of majors. */
	private static function symbol_to_coin_id( $symbol ) {
		$map = array(
			'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'SOL' => 'solana', 'BNB' => 'binancecoin',
			'XRP' => 'ripple', 'ADA' => 'cardano', 'DOGE' => 'dogecoin', 'AVAX' => 'avalanche-2',
			'MATIC' => 'matic-network', 'POL' => 'polygon-ecosystem-token', 'DOT' => 'polkadot',
			'LINK' => 'chainlink', 'TRX' => 'tron', 'TON' => 'the-open-network', 'LTC' => 'litecoin',
			'BCH' => 'bitcoin-cash', 'SHIB' => 'shiba-inu', 'UNI' => 'uniswap', 'ATOM' => 'cosmos',
			'XLM' => 'stellar', 'NEAR' => 'near', 'APT' => 'aptos', 'ARB' => 'arbitrum', 'OP' => 'optimism',
			'FIL' => 'filecoin', 'HBAR' => 'hedera-hashgraph', 'ICP' => 'internet-computer',
			'USDT' => 'tether', 'USDC' => 'usd-coin', 'DAI' => 'dai',
		);
		$s = strtoupper( trim( (string) $symbol ) );
		// Strip common quote-suffixes (BTCUSDT → BTC).
		foreach ( array( 'USDT', 'USDC', 'BUSD', 'USD' ) as $q ) {
			if ( strlen( $s ) > strlen( $q ) && substr( $s, -strlen( $q ) ) === $q ) {
				$s = substr( $s, 0, -strlen( $q ) );
				break;
			}
		}
		return $map[ $s ] ?? strtolower( $s );
	}

	/* ------------------------------------------------------------------
	 * Shortcode: [bt_portfolio_v2]
	 * ------------------------------------------------------------------ */

	public static function sc_portfolio_v2( $atts ) {
		$a = shortcode_atts( array(
			'default_method' => 'fifo',
			'show_import'    => 1,
		), $atts );

		$uid       = get_current_user_id();
		$logged_in = $uid > 0;
		$method    = $logged_in ? self::get_user_method( $uid ) : sanitize_key( $a['default_method'] );
		$txns      = $logged_in ? self::get_user_txns( $uid )   : array();
		$summary   = self::compute_portfolio( $txns, $method );

		// Coins for dropdown — top 100 by market cap from bt_crypto_data.
		$crypto = class_exists( 'BT_Widgets' ) ? BT_Widgets::get_json_option( 'bt_crypto_data' ) : array();
		$coins  = array_slice( $crypto['coins'] ?? array(), 0, 100 );

		$nonce    = wp_create_nonce( 'wp_rest' );
		$rest_url = esc_url_raw( rest_url( 'blockticker/v1/portfolio/v2' ) );

		ob_start(); ?>
		<div class="bt-pv2-wrap" data-rest="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"
			 data-logged-in="<?php echo $logged_in ? '1' : '0'; ?>" data-method="<?php echo esc_attr( $method ); ?>">

			<div class="bt-pv2-header">
				<div class="bt-pv2-title">💼 Portfolio <span class="bt-pv2-tag">v2</span></div>
				<div class="bt-pv2-method">
					<label>Cost basis:</label>
					<select class="bt-pv2-method-sel">
						<option value="fifo" <?php selected( $method, 'fifo' ); ?>>FIFO</option>
						<option value="hifo" <?php selected( $method, 'hifo' ); ?>>HIFO</option>
						<option value="acb"  <?php selected( $method, 'acb' );  ?>>Average</option>
					</select>
				</div>
			</div>

			<?php if ( ! $logged_in ) : ?>
			<div class="bt-pv2-guest-notice">
				<strong>Browsing as guest</strong> — transactions are saved to your browser only.
				<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Log in</a> to sync across devices.
			</div>
			<?php endif; ?>

			<!-- Summary tiles -->
			<div class="bt-pv2-tiles">
				<div class="bt-pv2-tile">
					<div class="bt-pv2-tile-label">Current value</div>
					<div class="bt-pv2-tile-v" data-k="value">$<?php echo number_format( $summary['totals']['value'], 2 ); ?></div>
				</div>
				<div class="bt-pv2-tile">
					<div class="bt-pv2-tile-label">Cost basis</div>
					<div class="bt-pv2-tile-v" data-k="basis">$<?php echo number_format( $summary['totals']['cost_basis'], 2 ); ?></div>
				</div>
				<div class="bt-pv2-tile">
					<div class="bt-pv2-tile-label">Unrealized P&L</div>
					<div class="bt-pv2-tile-v bt-pv2-pnl" data-k="unrl"><?php echo self::fmt_signed( $summary['totals']['unrealized_pnl'] ); ?></div>
				</div>
				<div class="bt-pv2-tile">
					<div class="bt-pv2-tile-label">Realized P&L</div>
					<div class="bt-pv2-tile-v bt-pv2-pnl" data-k="rl"><?php echo self::fmt_signed( $summary['totals']['realized_pnl'] ); ?></div>
				</div>
				<div class="bt-pv2-tile">
					<div class="bt-pv2-tile-label">Total return</div>
					<div class="bt-pv2-tile-v bt-pv2-pnl" data-k="pct"><?php echo ( $summary['totals']['total_pct'] >= 0 ? '+' : '' ) . number_format( $summary['totals']['total_pct'], 2 ) . '%'; ?></div>
				</div>
			</div>

			<!-- Add transaction -->
			<div class="bt-pv2-add">
				<h3>➕ Add transaction</h3>
				<div class="bt-pv2-add-grid">
					<select class="bt-pv2-coin">
						<?php foreach ( $coins as $c ) : ?>
						<option value="<?php echo esc_attr( $c['id'] ); ?>" data-sym="<?php echo esc_attr( strtoupper( $c['symbol'] ) ); ?>" data-price="<?php echo esc_attr( $c['current_price'] ); ?>">
							<?php echo esc_html( $c['name'] . ' (' . strtoupper( $c['symbol'] ) . ')' ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<select class="bt-pv2-kind">
						<option value="buy">BUY</option>
						<option value="sell">SELL</option>
					</select>
					<input type="number" step="any" min="0" placeholder="Quantity"   class="bt-pv2-qty">
					<input type="number" step="any" min="0" placeholder="Unit price" class="bt-pv2-price">
					<input type="number" step="any" min="0" placeholder="Fee (USD)"  class="bt-pv2-fee">
					<input type="date" class="bt-pv2-date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
					<input type="text" placeholder="Note (optional)" class="bt-pv2-note" maxlength="200">
					<button type="button" class="bt-pv2-add-btn">Add</button>
				</div>
			</div>

			<!-- Per-coin table -->
			<div class="bt-pv2-table-wrap">
				<table class="bt-pv2-table">
					<thead>
						<tr>
							<th>Asset</th><th>Qty held</th><th>Avg cost</th><th>Current</th>
							<th>Value</th><th>Unrealized P&L</th><th>Realized P&L</th>
						</tr>
					</thead>
					<tbody class="bt-pv2-rows">
						<?php if ( ! $summary['rows'] ) : ?>
							<tr><td colspan="7" class="bt-pv2-empty">No positions yet. Add your first transaction above.</td></tr>
						<?php else : foreach ( $summary['rows'] as $r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $r['symbol'] ); ?></strong> <small><?php echo esc_html( $r['name'] ); ?></small>
								<?php if ( ! empty( $r['broken'] ) ) : ?><span class="bt-pv2-warn" title="Sell exceeded buys — import history incomplete?">⚠</span><?php endif; ?>
							</td>
							<td><?php echo $r['qty_held'] ? esc_html( rtrim( rtrim( sprintf( '%.8f', $r['qty_held'] ), '0' ), '.' ) ) : '—'; ?></td>
							<td><?php echo $r['avg_cost'] > 0 ? '$' . number_format( $r['avg_cost'], $r['avg_cost'] < 1 ? 6 : 2 ) : '—'; ?></td>
							<td>$<?php echo number_format( $r['current_price'], $r['current_price'] < 1 ? 6 : 2 ); ?></td>
							<td>$<?php echo number_format( $r['value'], 2 ); ?></td>
							<td class="bt-pv2-pnl"><?php echo self::fmt_signed( $r['unrealized_pnl'] ); ?></td>
							<td class="bt-pv2-pnl"><?php echo self::fmt_signed( $r['realized_pnl'] ); ?></td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Transactions log -->
			<div class="bt-pv2-txns">
				<h3>📜 Transactions (<?php echo count( $txns ); ?>)</h3>
				<table class="bt-pv2-txn-table">
					<thead>
						<tr><th>Date</th><th>Type</th><th>Asset</th><th>Qty</th><th>Price</th><th>Fee</th><th>Note</th><th></th></tr>
					</thead>
					<tbody class="bt-pv2-txn-rows">
						<?php if ( ! $txns ) : ?>
							<tr><td colspan="8" class="bt-pv2-empty">No transactions yet.</td></tr>
						<?php else :
							$sorted = $txns;
							usort( $sorted, function( $a, $b ) { return ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ); } );
							foreach ( array_slice( $sorted, 0, 100 ) as $t ) : ?>
							<tr data-id="<?php echo esc_attr( $t['id'] ); ?>">
								<td><?php echo esc_html( gmdate( 'Y-m-d', $t['ts'] ) ); ?></td>
								<td><span class="bt-pv2-kind-<?php echo esc_attr( $t['kind'] ); ?>"><?php echo esc_html( strtoupper( $t['kind'] ) ); ?></span></td>
								<td><?php echo esc_html( $t['symbol'] ); ?></td>
								<td><?php echo esc_html( rtrim( rtrim( sprintf( '%.8f', $t['qty'] ), '0' ), '.' ) ); ?></td>
								<td>$<?php echo number_format( $t['price'], $t['price'] < 1 ? 6 : 2 ); ?></td>
								<td><?php echo $t['fee'] > 0 ? '$' . number_format( $t['fee'], 2 ) : '—'; ?></td>
								<td class="bt-pv2-note-cell"><?php echo esc_html( $t['note'] ); ?></td>
								<td><button class="bt-pv2-del" data-id="<?php echo esc_attr( $t['id'] ); ?>" title="Delete">✕</button></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( (int) $a['show_import'] && $logged_in ) : ?>
			<div class="bt-pv2-import">
				<h3>📤 Import CSV</h3>
				<p class="bt-pv2-hint">Accepts Coinbase, Binance, Kraken, or any CSV with <code>date, type, symbol, qty, price, fee</code> columns (order doesn't matter).</p>
				<input type="file" accept=".csv,text/csv" class="bt-pv2-csv-file">
				<label><input type="radio" name="bt-pv2-mode" value="append" checked> Append to existing</label>
				<label><input type="radio" name="bt-pv2-mode" value="replace"> Replace all</label>
				<button type="button" class="bt-pv2-import-btn">Import</button>
				<div class="bt-pv2-import-result"></div>
			</div>
			<?php endif; ?>
		</div>

		<?php echo self::inline_css(); ?>
		<?php echo self::inline_js(); ?>
		<?php
		return ob_get_clean();
	}

	public static function sc_portfolio_summary( $atts ) {
		$a = shortcode_atts( array( 'show_method' => 0 ), $atts );

		$uid = get_current_user_id();
		if ( ! $uid ) {
			return '<div class="bt-pv2-summary-inline bt-pv2-guest">💼 Log in to view your portfolio.</div>';
		}
		$method  = self::get_user_method( $uid );
		$txns    = self::get_user_txns( $uid );
		$agg     = self::compute_portfolio( $txns, $method );
		$t       = $agg['totals'];

		$pnl_colour = $t['total_pnl'] >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)';

		$method_badge = (int) $a['show_method']
			? '<span class="bt-pv2-method-badge">' . esc_html( strtoupper( $method ) ) . '</span>'
			: '';

		return '<div class="bt-pv2-summary-inline">💼 '
			. 'Value <strong>$' . number_format( $t['value'], 2 ) . '</strong> · '
			. 'P&L <strong style="color:' . $pnl_colour . '">' . self::fmt_signed( $t['total_pnl'] ) . '</strong> '
			. '(' . ( $t['total_pct'] >= 0 ? '+' : '' ) . number_format( $t['total_pct'], 2 ) . '%) '
			. $method_badge
			. '</div>';
	}

	public static function sc_portfolio_import( $atts ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return '<div class="bt-pv2-guest-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to import transactions.</div>';
		}
		$nonce    = wp_create_nonce( 'wp_rest' );
		$rest_url = esc_url_raw( rest_url( 'blockticker/v1/portfolio/v2/import' ) );
		ob_start(); ?>
		<div class="bt-pv2-wrap">
			<div class="bt-pv2-import" data-rest="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<h3>📤 Import CSV transactions</h3>
				<p class="bt-pv2-hint">Accepted: Coinbase, Binance, Kraken, or any CSV with <code>date, type, symbol, qty, price, fee</code>.</p>
				<input type="file" accept=".csv,text/csv" class="bt-pv2-csv-file">
				<label style="margin-left:8px;"><input type="radio" name="bt-pv2-mode" value="append" checked> Append</label>
				<label><input type="radio" name="bt-pv2-mode" value="replace"> Replace all</label>
				<button type="button" class="bt-pv2-import-btn">Import</button>
				<div class="bt-pv2-import-result"></div>
			</div>
			<?php echo self::inline_css(); ?>
			<?php echo self::inline_import_js(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	public static function fmt_signed( $v ) {
		$v = (float) $v;
		$sign = $v >= 0 ? '+' : '-';
		return $sign . '$' . number_format( abs( $v ), 2 );
	}

	private static function inline_css() {
		static $done = false;
		if ( $done ) return '';
		$done = true;
		return '<style>
.bt-pv2-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--bt-text);background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:18px;margin:16px 0;max-width:100%;overflow-x:auto}
.bt-pv2-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;flex-wrap:wrap}
.bt-pv2-title{font-size:18px;font-weight:700}
.bt-pv2-tag{font-size:10px;padding:2px 6px;background:var(--bt-accent);color:#0b0f1a;border-radius:0;margin-left:6px;vertical-align:middle}
.bt-pv2-method label{font-size:12px;color:var(--bt-text-2);margin-right:6px}
.bt-pv2-method select{background:var(--bt-bg-elev);color:var(--bt-text);border:1px solid var(--bt-text-4);border-radius:0;padding:4px 8px;font-size:13px}
.bt-pv2-guest-notice{background:var(--bt-bg-elev);border:1px solid var(--bt-text-4);border-radius:0;padding:10px 12px;font-size:13px;color:var(--bt-text-2);margin-bottom:12px}
.bt-pv2-guest-notice a{color:var(--bt-accent);text-decoration:none}
.bt-pv2-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.bt-pv2-tile{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px}
.bt-pv2-tile-label{font-size:11px;color:var(--bt-text-2);text-transform:uppercase;letter-spacing:.04em}
.bt-pv2-tile-v{font-size:20px;font-weight:700;margin-top:4px}
.bt-pv2-pnl{color:var(--bt-text)}
.bt-pv2-pnl.pos{color:var(--bt-accent)} .bt-pv2-pnl.neg{color:var(--bt-danger)}
.bt-pv2-add{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px;margin-bottom:16px}
.bt-pv2-add h3{margin:0 0 10px;font-size:14px;color:var(--bt-text)}
.bt-pv2-add-grid{display:grid;grid-template-columns:minmax(160px,2fr) 80px 100px 110px 90px 130px minmax(120px,1.5fr) auto;gap:8px}
.bt-pv2-add-grid input,.bt-pv2-add-grid select{background:#0b0f1a;color:var(--bt-text);border:1px solid var(--bt-text-4);border-radius:0;padding:6px 8px;font-size:13px;min-width:0}
.bt-pv2-add-btn{background:var(--bt-accent);color:#0b0f1a;border:0;border-radius:0;padding:6px 14px;font-weight:700;cursor:pointer;font-size:13px}
.bt-pv2-add-btn:hover{background:var(--bt-accent)}
.bt-pv2-table,.bt-pv2-txn-table{width:100%;border-collapse:collapse;font-size:13px}
.bt-pv2-table th,.bt-pv2-txn-table th{text-align:left;padding:8px;background:var(--bt-bg-elev);color:var(--bt-text-2);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #1e2535}
.bt-pv2-table td,.bt-pv2-txn-table td{padding:8px;border-bottom:1px solid #1e2535}
.bt-pv2-table small{color:var(--bt-text-3);font-weight:400;margin-left:4px}
.bt-pv2-kind-buy{background:var(--bt-accent);color:#0b0f1a;padding:2px 8px;border-radius:0;font-size:11px;font-weight:700}
.bt-pv2-kind-sell{background:var(--bt-danger);color:#fff;padding:2px 8px;border-radius:0;font-size:11px;font-weight:700}
.bt-pv2-empty{text-align:center;color:var(--bt-text-3);padding:24px !important}
.bt-pv2-warn{color:var(--bt-accent-warm);margin-left:4px;cursor:help}
.bt-pv2-del{background:transparent;border:0;color:var(--bt-text-3);cursor:pointer;font-size:14px}
.bt-pv2-del:hover{color:var(--bt-danger)}
.bt-pv2-note-cell{color:var(--bt-text-2);font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bt-pv2-txns{margin-top:16px}
.bt-pv2-txns h3,.bt-pv2-import h3{margin:0 0 10px;font-size:14px;color:var(--bt-text)}
.bt-pv2-import{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px;margin-top:16px}
.bt-pv2-import input[type=file]{color:var(--bt-text-2);font-size:12px}
.bt-pv2-import label{font-size:12px;color:var(--bt-text-2);margin-left:12px}
.bt-pv2-import-btn{background:var(--bt-accent);color:#0b0f1a;border:0;border-radius:0;padding:6px 14px;font-weight:700;cursor:pointer;font-size:13px;margin-left:12px}
.bt-pv2-hint{font-size:12px;color:var(--bt-text-2);margin:0 0 10px}
.bt-pv2-import-result{margin-top:10px;font-size:13px}
.bt-pv2-summary-inline{background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:10px 14px;font-size:13px;color:var(--bt-text);display:inline-block}
.bt-pv2-method-badge{background:#1e2535;color:var(--bt-text-2);padding:2px 6px;border-radius:0;font-size:10px;font-weight:700;margin-left:6px}
@media(max-width:720px){.bt-pv2-add-grid{grid-template-columns:1fr 1fr;gap:6px}.bt-pv2-table,.bt-pv2-txn-table{font-size:12px}}
</style>';
	}

	private static function inline_import_js() {
		return "<script>(function(){
function onReady(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn):fn();}
onReady(function(){
	document.querySelectorAll('.bt-pv2-import').forEach(function(box){
		if(box.dataset.btBound)return; box.dataset.btBound='1';
		var btn=box.querySelector('.bt-pv2-import-btn');
		var file=box.querySelector('.bt-pv2-csv-file');
		var out=box.querySelector('.bt-pv2-import-result');
		btn.addEventListener('click',function(){
			if(!file.files||!file.files[0]){out.innerHTML='<span style=\"color:var(--bt-danger)\">Pick a CSV first.</span>';return;}
			var mode=(box.querySelector('input[name=bt-pv2-mode]:checked')||{}).value||'append';
			var fd=new FormData();fd.append('file',file.files[0]);fd.append('mode',mode);
			out.textContent='Uploading…';
			fetch(box.dataset.rest,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':box.dataset.nonce},body:fd})
			.then(function(r){return r.json();})
			.then(function(j){
				if(j.success){out.innerHTML='<span style=\"color:var(--bt-accent)\">✓ Imported '+j.added+' transactions'+(j.skipped?' ('+j.skipped+' skipped)':'')+' — dialect: '+j.dialect+'. Refresh the page to see them.</span>';}
				else{out.innerHTML='<span style=\"color:var(--bt-danger)\">✗ '+(j.message||'Import failed')+'</span>';}
			}).catch(function(e){out.innerHTML='<span style=\"color:var(--bt-danger)\">✗ '+e.message+'</span>';});
		});
	});
});
})();</script>";
	}

	private static function inline_js() {
		static $done = false;
		if ( $done ) return '';
		$done = true;
		return "<script>(function(){
function onReady(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn):fn();}
function fmt(n){return '$'+Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtSigned(n){var s=n>=0?'+':'-';return s+'$'+Math.abs(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function applyPnlColour(el,v){el.classList.remove('pos','neg');el.classList.add(v>=0?'pos':'neg');}

onReady(function(){
	document.querySelectorAll('.bt-pv2-wrap').forEach(function(root){
		if(root.dataset.btBound)return; root.dataset.btBound='1';
		var rest=root.dataset.rest;
		var nonce=root.dataset.nonce;
		var loggedIn=root.dataset.loggedIn==='1';

		function post(path,body){
			return fetch(rest+path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(body)}).then(function(r){return r.json();});
		}
		function del(id){
			return fetch(rest+'/transactions/'+encodeURIComponent(id),{method:'DELETE',credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.json();});
		}

		// Add transaction
		var addBtn=root.querySelector('.bt-pv2-add-btn');
		if(addBtn){addBtn.addEventListener('click',function(){
			var coinSel=root.querySelector('.bt-pv2-coin');
			var opt=coinSel.options[coinSel.selectedIndex];
			var coinId=coinSel.value;
			var sym=opt.dataset.sym;
			var kind=root.querySelector('.bt-pv2-kind').value;
			var qty=parseFloat(root.querySelector('.bt-pv2-qty').value);
			var price=parseFloat(root.querySelector('.bt-pv2-price').value);
			var fee=parseFloat(root.querySelector('.bt-pv2-fee').value)||0;
			var dateStr=root.querySelector('.bt-pv2-date').value;
			var note=root.querySelector('.bt-pv2-note').value;
			if(!qty||qty<=0||!price||price<0){alert('Enter a valid quantity and unit price.');return;}
			var ts=dateStr?Math.floor(new Date(dateStr+'T00:00:00Z').getTime()/1000):Math.floor(Date.now()/1000);

			if(!loggedIn){
				// localStorage fallback
				var list=[];try{list=JSON.parse(localStorage.getItem('bt_portfolio_txns')||'[]');}catch(e){}
				list.push({id:'txn_'+Math.random().toString(36).slice(2,10),kind:kind,coin_id:coinId,symbol:sym,qty:qty,price:price,fee:fee,ts:ts,note:note});
				localStorage.setItem('bt_portfolio_txns',JSON.stringify(list));
				location.reload();return;
			}
			post('/transactions',{kind:kind,coin_id:coinId,symbol:sym,qty:qty,price:price,fee:fee,ts:ts,note:note}).then(function(j){
				if(j.success){location.reload();}
				else{alert(j.message||'Save failed');}
			});
		});}

		// Delete transaction
		root.addEventListener('click',function(e){
			if(!e.target.classList.contains('bt-pv2-del'))return;
			var id=e.target.dataset.id;
			if(!confirm('Delete this transaction?'))return;
			if(!loggedIn){
				var list=[];try{list=JSON.parse(localStorage.getItem('bt_portfolio_txns')||'[]');}catch(e2){}
				localStorage.setItem('bt_portfolio_txns',JSON.stringify(list.filter(function(t){return t.id!==id;})));
				location.reload();return;
			}
			del(id).then(function(j){if(j.success)location.reload();else alert(j.message||'Delete failed');});
		});

		// Method change
		var methodSel=root.querySelector('.bt-pv2-method-sel');
		if(methodSel){methodSel.addEventListener('change',function(){
			if(!loggedIn){root.dataset.method=this.value;location.reload();return;}
			post('/method',{method:this.value}).then(function(j){if(j.success)location.reload();});
		});}

		// Apply pnl colours on initial render
		root.querySelectorAll('.bt-pv2-pnl').forEach(function(el){
			var txt=el.textContent.trim();
			var sign=txt.charAt(0);
			if(sign==='+'||sign==='-')applyPnlColour(el,sign==='+'?1:-1);
		});

		// CSV import
		var importBox=root.querySelector('.bt-pv2-import');
		if(importBox){
			var btn=importBox.querySelector('.bt-pv2-import-btn');
			var file=importBox.querySelector('.bt-pv2-csv-file');
			var out=importBox.querySelector('.bt-pv2-import-result');
			btn.addEventListener('click',function(){
				if(!file.files||!file.files[0]){out.innerHTML='<span style=\"color:var(--bt-danger)\">Pick a CSV first.</span>';return;}
				var mode=(importBox.querySelector('input[name=bt-pv2-mode]:checked')||{}).value||'append';
				var fd=new FormData();fd.append('file',file.files[0]);fd.append('mode',mode);
				out.textContent='Uploading…';
				fetch(rest+'/import',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':nonce},body:fd})
				.then(function(r){return r.json();})
				.then(function(j){
					if(j.success){out.innerHTML='<span style=\"color:var(--bt-accent)\">✓ Imported '+j.added+' transactions'+(j.skipped?' ('+j.skipped+' skipped)':'')+' — dialect: '+j.dialect+'.</span>';setTimeout(function(){location.reload();},1200);}
					else{out.innerHTML='<span style=\"color:var(--bt-danger)\">✗ '+(j.message||'Import failed')+'</span>';}
				}).catch(function(e){out.innerHTML='<span style=\"color:var(--bt-danger)\">✗ '+e.message+'</span>';});
			});
		}
	});
});
})();</script>";
	}
}
