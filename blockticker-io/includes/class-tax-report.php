<?php
/**
 * BT_TaxReport — Realized gains tax report from the v114 transaction ledger.
 *
 * Extends BT_PortfolioV2's FIFO/HIFO cost-basis engines to produce the one
 * output the v114 summary couldn't give: an explicit list of CLOSED LOTS —
 * every matched (buy → sell) pair with both dates, both prices, proceeds,
 * cost basis, gain/loss, and holding period. That list is everything a
 * tax return actually needs.
 *
 * LT vs ST classification (long-term vs short-term) uses a configurable
 * holding-period threshold:
 *   - US: 366+ days = LT (IRS § 1222)
 *   - UK / DE / most EU: 366+ days = LT (varies — operator's call)
 *   - threshold stored in bt_tax_lt_days option; default 366
 *
 * Three export formats:
 *   1. HTML report (print-to-PDF friendly) — paginated, totals, all lots
 *   2. Raw CSV — every closed lot row with all engine fields
 *   3. Form 8949-compatible CSV — exact TurboTax / FreeTaxUSA column layout
 *
 * Shortcode:
 *   [bt_tax_report default_year="2026"]
 *
 * REST:
 *   GET /wp-json/blockticker/v1/portfolio/v2/tax-report
 *     ?year=2026[&method=fifo][&lt_days=366][&format=json|csv|csv_form8949|html]
 *
 * The ACB engine doesn't produce lot-level matches (proceeds are computed
 * against a running average pool, which has no single acquisition date),
 * so tax-report mode uses FIFO by default and exposes a per-request method
 * override. Users on ACB-only regimes (Canada, UK §104) should consult an
 * accountant — ACB proceeds are typically reported at the pool level on
 * the disposal date, which this plugin does not attempt to produce on the
 * user's behalf.
 *
 * @package BlockTicker
 * @since   116.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_TaxReport {

	const OPT_LT_DAYS = 'bt_tax_lt_days';

	public static function init() {
		add_shortcode( 'bt_tax_report', array( __CLASS__, 'sc_tax_report' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		// Intercept raw-format responses (CSV / HTML) before WP JSON-encodes them.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw_response' ), 10, 2 );
	}

	/**
	 * If the REST response carries a bt_raw_type header marker, emit the body
	 * directly and short-circuit WP's JSON encoder.  Runs on rest_pre_serve_request.
	 *
	 * @param  bool             $served   Whether the request has already been served.
	 * @param  WP_HTTP_Response $response Response object.
	 * @return bool  true = WP skips its own output; false = WP handles normally.
	 */
	public static function serve_raw_response( $served, $response ) {
		$raw_type = $response->get_headers()['X-BT-Raw-Type'] ?? '';
		if ( ! $raw_type ) return false;

		// Emit the real Content-Type and Disposition, strip the internal marker.
		$headers = $response->get_headers();
		unset( $headers['X-BT-Raw-Type'] );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		status_header( $response->get_status() );
		echo $response->get_data();
		return true; // tell WP: request is served, skip JSON output.
	}

	public static function register_rest_routes() {
		register_rest_route( 'blockticker/v1', '/portfolio/v2/tax-report', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_tax_report' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'year'    => array( 'type' => 'integer', 'required' => false ),
				'method'  => array( 'type' => 'string',  'enum' => array( 'fifo', 'hifo' ), 'default' => 'fifo' ),
				'lt_days' => array( 'type' => 'integer', 'required' => false ),
				'format'  => array( 'type' => 'string',  'enum' => array( 'json', 'csv', 'csv_form8949', 'html' ), 'default' => 'json' ),
			),
		) );
	}

	/* ------------------------------------------------------------------
	 * Core engine — replay the ledger, emit closed-lot matches
	 * ------------------------------------------------------------------ */

	/**
	 * Replay a coin's transaction history and return each closed lot as a
	 * separate row. A "closed lot" is the part of a sell that was matched
	 * against a specific buy — so a single sell that drew from 3 open lots
	 * produces 3 rows, each with its own acquired_at, cost_basis, proceeds,
	 * gain, and holding period.
	 *
	 * @param array  $txns    Transactions for ONE coin (any order).
	 * @param string $method  'fifo' (default) or 'hifo'.
	 * @return array list of closed-lot rows.
	 */
	public static function compute_closed_lots( $txns, $method = 'fifo' ) {
		if ( ! is_array( $txns ) || ! $txns ) return array();

		// Sort ascending by ts — same deterministic order as BT_PortfolioV2.
		usort( $txns, function( $a, $b ) { return ( $a['ts'] ?? 0 ) <=> ( $b['ts'] ?? 0 ); } );

		$lots         = array(); // { qty, unit_cost, ts, buy_id }
		$closed       = array();

		foreach ( $txns as $t ) {
			if ( ! isset( $t['kind'], $t['qty'], $t['price'] ) ) continue;
			$qty   = (float) $t['qty'];
			$price = (float) $t['price'];
			$fee   = (float) ( $t['fee']   ?? 0 );
			$ts    = (int)   ( $t['ts']    ?? 0 );
			if ( $qty <= 0 ) continue;

			if ( $t['kind'] === 'buy' ) {
				$unit = $price + ( $qty > 0 ? $fee / $qty : 0 );
				$lots[] = array(
					'qty'       => $qty,
					'unit_cost' => $unit,
					'ts'        => $ts,
					'buy_id'    => (string) ( $t['id'] ?? '' ),
					'buy_fee'   => $fee,
					'buy_qty'   => $qty,
				);
				continue;
			}

			// SELL
			$remaining = $qty;

			if ( $method === 'hifo' ) {
				usort( $lots, function( $a, $b ) { return ( $b['unit_cost'] ?? 0 ) <=> ( $a['unit_cost'] ?? 0 ); } );
			} else { // fifo
				usort( $lots, function( $a, $b ) { return ( $a['ts'] ?? 0 ) <=> ( $b['ts'] ?? 0 ); } );
			}

			foreach ( $lots as &$lot ) {
				if ( $remaining <= 0 ) break;
				if ( $lot['qty'] <= 0 )  continue;
				$take = min( $lot['qty'], $remaining );

				$proceeds = $price * $take;
				$basis    = $lot['unit_cost'] * $take;
				// Proportionally allocate the sell fee to this closed lot.
				$fee_share = $qty > 0 ? $fee * ( $take / $qty ) : 0;
				$gain     = $proceeds - $basis - $fee_share;

				$closed[] = array(
					'symbol'         => strtoupper( (string) ( $t['symbol'] ?? '' ) ),
					'coin_id'        => (string) ( $t['coin_id'] ?? '' ),
					'qty'            => $take,
					'acquired_at'    => (int) $lot['ts'],
					'disposed_at'    => (int) $ts,
					'unit_cost'      => (float) $lot['unit_cost'],
					'unit_sale'      => $price,
					'cost_basis'     => $basis,
					'proceeds'       => $proceeds,
					'sell_fee_share' => $fee_share,
					'gain'           => $gain,
					'holding_days'   => max( 0, (int) floor( ( $ts - $lot['ts'] ) / DAY_IN_SECONDS ) ),
					'buy_id'         => (string) ( $lot['buy_id'] ?? '' ),
					'sell_id'        => (string) ( $t['id']       ?? '' ),
				);

				$lot['qty']  -= $take;
				$remaining   -= $take;
			}
			unset( $lot );

			$lots = array_values( array_filter( $lots, function( $l ) { return $l['qty'] > 0.0000000001; } ) );

			if ( $remaining > 0.0000000001 ) {
				// Uncovered sell — user's import is incomplete. Emit a row with
				// basis=0 so the gain reflects the full proceeds, flagged for the UI.
				$closed[] = array(
					'symbol'         => strtoupper( (string) ( $t['symbol'] ?? '' ) ),
					'coin_id'        => (string) ( $t['coin_id'] ?? '' ),
					'qty'            => $remaining,
					'acquired_at'    => 0,
					'disposed_at'    => (int) $ts,
					'unit_cost'      => 0,
					'unit_sale'      => $price,
					'cost_basis'     => 0,
					'proceeds'       => $price * $remaining,
					'sell_fee_share' => 0,
					'gain'           => $price * $remaining,
					'holding_days'   => 0,
					'buy_id'         => '',
					'sell_id'        => (string) ( $t['id'] ?? '' ),
					'broken'         => true,
				);
			}
		}

		return $closed;
	}

	/**
	 * Build the full tax report for a user.
	 *
	 * @param int      $user_id
	 * @param int|null $year    Tax year (UTC). Defaults to current year.
	 * @param string   $method  'fifo' or 'hifo'.
	 * @param int|null $lt_days Long-term threshold in days (default: option, or 366).
	 * @return array
	 */
	public static function build( $user_id, $year = null, $method = 'fifo', $lt_days = null ) {
		if ( ! class_exists( 'BT_PortfolioV2' ) ) {
			return array( 'error' => 'BT_PortfolioV2 unavailable' );
		}

		if ( $lt_days === null ) $lt_days = (int) get_option( self::OPT_LT_DAYS, 366 );
		$lt_days = max( 1, (int) $lt_days );

		if ( $year === null ) $year = (int) gmdate( 'Y' );

		$year_start = gmmktime( 0, 0, 0, 1, 1, $year );
		$year_end   = gmmktime( 23, 59, 59, 12, 31, $year );

		$all_txns = BT_PortfolioV2::get_user_txns( (int) $user_id );

		// Group by coin_id to replay each position independently.
		$by_coin = array();
		foreach ( $all_txns as $t ) {
			$cid = $t['coin_id'] ?? '';
			if ( $cid === '' ) continue;
			$by_coin[ $cid ][] = $t;
		}

		// Gather all closed lots from all coins.
		$all_lots = array();
		foreach ( $by_coin as $cid => $coin_txns ) {
			$lots = self::compute_closed_lots( $coin_txns, $method );
			foreach ( $lots as $l ) {
				$all_lots[] = $l;
			}
		}

		// Filter to the requested tax year.
		$year_lots = array();
		foreach ( $all_lots as $l ) {
			if ( $l['disposed_at'] >= $year_start && $l['disposed_at'] <= $year_end ) {
				$l['term']         = $l['holding_days'] >= $lt_days ? 'long' : 'short';
				$l['gain_type']    = $l['gain'] >= 0 ? 'gain' : 'loss';
				$year_lots[] = $l;
			}
		}

		// Sort by disposed_at ascending — chronological order for tax reporting.
		usort( $year_lots, function( $a, $b ) { return $a['disposed_at'] <=> $b['disposed_at']; } );

		// Summary totals.
		$short_proceeds = $short_basis = $short_gain = 0.0;
		$long_proceeds  = $long_basis  = $long_gain  = 0.0;
		$broken_count = 0;
		foreach ( $year_lots as $l ) {
			if ( ! empty( $l['broken'] ) ) $broken_count++;
			if ( $l['term'] === 'long' ) {
				$long_proceeds  += $l['proceeds'];
				$long_basis     += $l['cost_basis'];
				$long_gain      += $l['gain'];
			} else {
				$short_proceeds += $l['proceeds'];
				$short_basis    += $l['cost_basis'];
				$short_gain     += $l['gain'];
			}
		}

		return array(
			'user_id'         => (int) $user_id,
			'year'            => (int) $year,
			'method'          => $method,
			'lt_threshold'    => (int) $lt_days,
			'generated_at'    => time(),
			'lots'            => $year_lots,
			'lot_count'       => count( $year_lots ),
			'broken_count'    => $broken_count,
			'short_term'      => array(
				'proceeds'   => $short_proceeds,
				'cost_basis' => $short_basis,
				'gain'       => $short_gain,
			),
			'long_term'       => array(
				'proceeds'   => $long_proceeds,
				'cost_basis' => $long_basis,
				'gain'       => $long_gain,
			),
			'total'           => array(
				'proceeds'   => $short_proceeds + $long_proceeds,
				'cost_basis' => $short_basis    + $long_basis,
				'gain'       => $short_gain     + $long_gain,
			),
		);
	}

	/* ------------------------------------------------------------------
	 * List of years with activity — drives the year dropdown
	 * ------------------------------------------------------------------ */

	public static function years_with_activity( $user_id ) {
		if ( ! class_exists( 'BT_PortfolioV2' ) ) return array();
		$txns = BT_PortfolioV2::get_user_txns( (int) $user_id );
		$years = array();
		foreach ( $txns as $t ) {
			if ( ( $t['kind'] ?? '' ) !== 'sell' ) continue;
			$y = (int) gmdate( 'Y', (int) ( $t['ts'] ?? 0 ) );
			if ( $y > 1970 ) $years[ $y ] = true;
		}
		$years = array_keys( $years );
		rsort( $years );
		return $years;
	}

	/* ------------------------------------------------------------------
	 * Formatters
	 * ------------------------------------------------------------------ */

	private static function fmt_usd( $v, $force_sign = false ) {
		$v = (float) $v;
		$sign = $force_sign ? ( $v >= 0 ? '+' : '-' ) : ( $v < 0 ? '-' : '' );
		return $sign . '$' . number_format( abs( $v ), 2 );
	}

	private static function fmt_qty( $v ) {
		$s = sprintf( '%.8f', (float) $v );
		return rtrim( rtrim( $s, '0' ), '.' );
	}

	private static function fmt_date( $ts ) {
		return $ts > 0 ? gmdate( 'Y-m-d', (int) $ts ) : '—';
	}

	/* ------------------------------------------------------------------
	 * CSV builders
	 * ------------------------------------------------------------------ */

	/**
	 * Raw realized-gains CSV — every closed lot row, all engine fields.
	 * Human-readable column headers, ISO dates. Designed to be opened
	 * in Excel / Google Sheets for analysis and custom filters.
	 */
	public static function to_csv_raw( $report ) {
		$headers = array(
			'Symbol', 'Coin ID', 'Quantity',
			'Acquired', 'Disposed', 'Holding Days', 'Term (ST/LT)',
			'Unit Cost', 'Unit Sale', 'Cost Basis', 'Proceeds',
			'Sell Fee Share', 'Gain/Loss', 'Gain Type',
			'Buy TXN ID', 'Sell TXN ID', 'Broken',
		);

		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, $headers );

		foreach ( $report['lots'] as $l ) {
			fputcsv( $fh, array(
				$l['symbol'],
				$l['coin_id'],
				self::fmt_qty( $l['qty'] ),
				self::fmt_date( $l['acquired_at'] ),
				self::fmt_date( $l['disposed_at'] ),
				$l['holding_days'],
				strtoupper( $l['term'] ),
				number_format( $l['unit_cost'],     8, '.', '' ),
				number_format( $l['unit_sale'],     8, '.', '' ),
				number_format( $l['cost_basis'],    2, '.', '' ),
				number_format( $l['proceeds'],      2, '.', '' ),
				number_format( $l['sell_fee_share'], 2, '.', '' ),
				number_format( $l['gain'],          2, '.', '' ),
				strtoupper( $l['gain_type'] ),
				$l['buy_id'],
				$l['sell_id'],
				empty( $l['broken'] ) ? '' : 'YES',
			) );
		}

		// Summary footer rows (blank-line separated).
		fputcsv( $fh, array() );
		fputcsv( $fh, array( 'SUMMARY', 'Year', $report['year'], 'Method', strtoupper( $report['method'] ), 'LT threshold (days)', $report['lt_threshold'] ) );
		fputcsv( $fh, array( '', 'Short-term proceeds',   number_format( $report['short_term']['proceeds'],   2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'Short-term cost basis', number_format( $report['short_term']['cost_basis'], 2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'Short-term gain/loss',  number_format( $report['short_term']['gain'],       2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'Long-term proceeds',    number_format( $report['long_term']['proceeds'],    2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'Long-term cost basis',  number_format( $report['long_term']['cost_basis'],  2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'Long-term gain/loss',   number_format( $report['long_term']['gain'],        2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'TOTAL proceeds',        number_format( $report['total']['proceeds'],        2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'TOTAL cost basis',      number_format( $report['total']['cost_basis'],      2, '.', '' ) ) );
		fputcsv( $fh, array( '', 'TOTAL gain/loss',       number_format( $report['total']['gain'],            2, '.', '' ) ) );

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/**
	 * Form 8949-compatible CSV — exact column order/names accepted by
	 * TurboTax and FreeTaxUSA's bulk-import for crypto Form 8949:
	 *
	 *   Description, Date Acquired, Date Sold, Proceeds, Cost Basis,
	 *   Gain/Loss, Short/Long
	 *
	 * "Various" is substituted for Date Acquired when the lot is broken
	 * (no matching buy) — IRS accepts this for stock/crypto inherited or
	 * acquired in multiple purchases lumped together.
	 */
	public static function to_csv_form8949( $report ) {
		$headers = array( 'Description', 'Date Acquired', 'Date Sold', 'Proceeds', 'Cost Basis', 'Gain/Loss', 'Short/Long' );

		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, $headers );

		foreach ( $report['lots'] as $l ) {
			$desc    = self::fmt_qty( $l['qty'] ) . ' ' . $l['symbol'];
			$acq     = ! empty( $l['broken'] ) || $l['acquired_at'] === 0 ? 'Various' : gmdate( 'm/d/Y', $l['acquired_at'] );
			$disp    = gmdate( 'm/d/Y', $l['disposed_at'] );
			$short_l = $l['term'] === 'long' ? 'Long' : 'Short';

			fputcsv( $fh, array(
				$desc,
				$acq,
				$disp,
				number_format( $l['proceeds'],   2, '.', '' ),
				number_format( $l['cost_basis'], 2, '.', '' ),
				number_format( $l['gain'],       2, '.', '' ),
				$short_l,
			) );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/* ------------------------------------------------------------------
	 * HTML report (print-optimised — converts to PDF via browser print)
	 * ------------------------------------------------------------------ */

	public static function to_html( $report, $user_info = array() ) {
		$site_name = get_option( 'bt_site_name', 'BlockTicker' );
		$title     = 'Realized Gains Report — Tax Year ' . (int) $report['year'];

		$filer  = ! empty( $user_info['display_name'] ) ? $user_info['display_name'] : '';
		$email  = ! empty( $user_info['email'] )        ? $user_info['email']        : '';

		ob_start(); ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?></title>
<style>
/* Print-optimised: converts to PDF cleanly via Ctrl/Cmd-P or File → Print */
@page { size: letter; margin: 0.6in; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #111; margin: 0; padding: 24px; font-size: 12px; line-height: 1.45; background: #fff; }
.bt-tax-report { max-width: 960px; margin: 0 auto; }
.bt-tax-header { border-bottom: 3px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
.bt-tax-title { font-size: 22px; font-weight: 800; margin: 0 0 4px; }
.bt-tax-sub   { color: #555; font-size: 12px; margin: 0; }
.bt-tax-meta  { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 14px 0 18px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; font-size: 11px; }
.bt-tax-meta strong { display: block; color: #555; text-transform: uppercase; font-size: 10px; letter-spacing: .03em; }
.bt-tax-meta span { font-size: 13px; font-weight: 600; color: #111; }

.bt-tax-summary { margin: 18px 0; }
.bt-tax-summary table { width: 100%; border-collapse: collapse; font-size: 12px; }
.bt-tax-summary th, .bt-tax-summary td { border: 1px solid #aaa; padding: 8px 10px; text-align: right; }
.bt-tax-summary th { background: #e5e7eb; font-weight: 700; text-align: left; }
.bt-tax-summary td:first-child { text-align: left; font-weight: 600; }
.bt-tax-summary .row-total { background: #111; color: #fff; font-weight: 700; }
.bt-tax-summary .row-total td { border-color: #111; }

.bt-tax-section-title { font-size: 15px; font-weight: 700; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #111; }

table.bt-tax-lots { width: 100%; border-collapse: collapse; font-size: 10.5px; margin-bottom: 18px; page-break-inside: auto; }
table.bt-tax-lots thead { display: table-header-group; }
table.bt-tax-lots tr { page-break-inside: avoid; page-break-after: auto; }
table.bt-tax-lots th { background: #374151; color: #fff; padding: 6px 8px; text-align: right; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: .02em; }
table.bt-tax-lots th:first-child, table.bt-tax-lots th:nth-child(2) { text-align: left; }
table.bt-tax-lots td { border-bottom: 1px solid #e5e7eb; padding: 5px 8px; text-align: right; }
table.bt-tax-lots td:first-child { text-align: left; font-weight: 700; }
table.bt-tax-lots td:nth-child(2) { text-align: left; }
table.bt-tax-lots tr.broken td { background: #fef3c7; }
table.bt-tax-lots tr:nth-child(even) td { background: #f9fafb; }
.bt-tax-gain-pos { color: #047857; font-weight: 600; }
.bt-tax-gain-neg { color: #b91c1c; font-weight: 600; }
.bt-tax-term { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 9.5px; font-weight: 700; }
.bt-tax-term.short { background: #fee2e2; color: #991b1b; }
.bt-tax-term.long  { background: #d1fae5; color: #065f46; }

.bt-tax-disclaimer { margin-top: 24px; padding: 10px 12px; background: #fef3c7; border: 1px solid var(--bt-accent-warm); border-radius: 4px; font-size: 10.5px; color: #78350f; }
.bt-tax-footer { margin-top: 16px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 10px; color: #666; text-align: center; }

@media print { .no-print { display: none !important; } body { padding: 0; } }
</style>
</head>
<body>
<div class="bt-tax-report">

<div class="bt-tax-header">
	<h1 class="bt-tax-title"><?php echo esc_html( $title ); ?></h1>
	<p class="bt-tax-sub">Generated by <?php echo esc_html( $site_name ); ?> · <?php echo esc_html( gmdate( 'F j, Y' ) ); ?></p>
</div>

<div class="bt-tax-meta">
	<div><strong>Filer</strong><span><?php echo esc_html( $filer ?: '—' ); ?></span></div>
	<div><strong>Tax Year</strong><span><?php echo (int) $report['year']; ?></span></div>
	<div><strong>Cost-basis Method</strong><span><?php echo esc_html( strtoupper( $report['method'] ) ); ?></span></div>
	<div><strong>LT Threshold</strong><span><?php echo (int) $report['lt_threshold']; ?> days</span></div>
</div>

<h2 class="bt-tax-section-title">Summary</h2>
<div class="bt-tax-summary">
	<table>
		<thead>
			<tr><th>Term</th><th>Proceeds</th><th>Cost Basis</th><th>Gain / Loss</th></tr>
		</thead>
		<tbody>
			<tr>
				<td>Short-term (≤ <?php echo (int) $report['lt_threshold']; ?> days)</td>
				<td><?php echo esc_html( self::fmt_usd( $report['short_term']['proceeds'] ) ); ?></td>
				<td><?php echo esc_html( self::fmt_usd( $report['short_term']['cost_basis'] ) ); ?></td>
				<td class="<?php echo $report['short_term']['gain'] >= 0 ? 'bt-tax-gain-pos' : 'bt-tax-gain-neg'; ?>"><?php echo esc_html( self::fmt_usd( $report['short_term']['gain'], true ) ); ?></td>
			</tr>
			<tr>
				<td>Long-term (> <?php echo (int) $report['lt_threshold']; ?> days)</td>
				<td><?php echo esc_html( self::fmt_usd( $report['long_term']['proceeds'] ) ); ?></td>
				<td><?php echo esc_html( self::fmt_usd( $report['long_term']['cost_basis'] ) ); ?></td>
				<td class="<?php echo $report['long_term']['gain'] >= 0 ? 'bt-tax-gain-pos' : 'bt-tax-gain-neg'; ?>"><?php echo esc_html( self::fmt_usd( $report['long_term']['gain'], true ) ); ?></td>
			</tr>
			<tr class="row-total">
				<td>Total</td>
				<td><?php echo esc_html( self::fmt_usd( $report['total']['proceeds'] ) ); ?></td>
				<td><?php echo esc_html( self::fmt_usd( $report['total']['cost_basis'] ) ); ?></td>
				<td><?php echo esc_html( self::fmt_usd( $report['total']['gain'], true ) ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<h2 class="bt-tax-section-title">Realized Lots (<?php echo count( $report['lots'] ); ?><?php echo $report['broken_count'] > 0 ? ' · ' . $report['broken_count'] . ' flagged' : ''; ?>)</h2>
<?php if ( ! $report['lots'] ) : ?>
	<p style="color:#666;font-style:italic;">No realized gains in this tax year — no sell transactions occurred.</p>
<?php else : ?>
<table class="bt-tax-lots">
<thead>
	<tr>
		<th>Asset</th><th>Qty</th>
		<th>Acquired</th><th>Disposed</th><th>Days</th><th>Term</th>
		<th>Basis</th><th>Proceeds</th><th>Gain/Loss</th>
	</tr>
</thead>
<tbody>
<?php foreach ( $report['lots'] as $l ) : ?>
	<tr class="<?php echo ! empty( $l['broken'] ) ? 'broken' : ''; ?>">
		<td><?php echo esc_html( $l['symbol'] ); ?><?php echo ! empty( $l['broken'] ) ? ' ⚠' : ''; ?></td>
		<td><?php echo esc_html( self::fmt_qty( $l['qty'] ) ); ?></td>
		<td><?php echo esc_html( self::fmt_date( $l['acquired_at'] ) ); ?></td>
		<td><?php echo esc_html( self::fmt_date( $l['disposed_at'] ) ); ?></td>
		<td><?php echo $l['acquired_at'] ? (int) $l['holding_days'] : '—'; ?></td>
		<td><span class="bt-tax-term <?php echo esc_attr( $l['term'] ); ?>"><?php echo esc_html( strtoupper( $l['term'] ) ); ?></span></td>
		<td><?php echo esc_html( self::fmt_usd( $l['cost_basis'] ) ); ?></td>
		<td><?php echo esc_html( self::fmt_usd( $l['proceeds'] ) ); ?></td>
		<td class="<?php echo $l['gain'] >= 0 ? 'bt-tax-gain-pos' : 'bt-tax-gain-neg'; ?>"><?php echo esc_html( self::fmt_usd( $l['gain'], true ) ); ?></td>
	</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<div class="bt-tax-disclaimer">
	<strong>⚠ Important.</strong> This report is generated from your transaction ledger alone. It does <em>not</em> constitute tax advice. You remain responsible for the accuracy and completeness of data you entered or imported. Consult a qualified tax professional for your jurisdiction's specific rules on capital-gains treatment of digital assets.
	<?php if ( $report['broken_count'] > 0 ) : ?>
		<br><strong>⚠ <?php echo (int) $report['broken_count']; ?> lot<?php echo $report['broken_count'] === 1 ? '' : 's'; ?> flagged</strong> — one or more sells exceeded your tracked buys. These rows use a zero cost basis, which overstates your gain. Import your complete buy history to fix this.
	<?php endif; ?>
</div>

<div class="bt-tax-footer">
	Generated <?php echo esc_html( gmdate( 'Y-m-d H:i', $report['generated_at'] ) ); ?> UTC · <?php echo esc_html( $site_name ); ?> · Plugin v<?php echo esc_html( defined( 'BT_VERSION' ) ? BT_VERSION : '?' ); ?>
</div>

</div>
</body>
</html><?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * REST handler
	 * ------------------------------------------------------------------ */

	public static function rest_tax_report( $req ) {
		$uid     = get_current_user_id();
		$year    = (int) ( $req->get_param( 'year' )    ?: gmdate( 'Y' ) );
		$method  = (string) ( $req->get_param( 'method' ) ?: 'fifo' );
		$lt_days = $req->get_param( 'lt_days' );
		$lt_days = $lt_days !== null ? (int) $lt_days : null;
		$format  = (string) ( $req->get_param( 'format' ) ?: 'json' );

		$report = self::build( $uid, $year, $method, $lt_days );
		if ( ! empty( $report['error'] ) ) {
			return new WP_Error( 'report_failed', $report['error'], array( 'status' => 500 ) );
		}

		switch ( $format ) {
			case 'csv':
				$csv      = self::to_csv_raw( $report );
				$response = new WP_REST_Response( $csv, 200 );
				$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
				$response->header( 'Content-Disposition', 'attachment; filename="blockticker-tax-' . (int) $report['year'] . '.csv"' );
				$response->header( 'X-BT-Raw-Type', 'csv' );
				return $response;

			case 'csv_form8949':
				$csv      = self::to_csv_form8949( $report );
				$response = new WP_REST_Response( $csv, 200 );
				$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
				$response->header( 'Content-Disposition', 'attachment; filename="blockticker-form8949-' . (int) $report['year'] . '.csv"' );
				$response->header( 'X-BT-Raw-Type', 'csv_form8949' );
				return $response;

			case 'html':
				$u        = get_userdata( $uid );
				$info     = $u ? array( 'display_name' => $u->display_name, 'email' => $u->user_email ) : array();
				$html     = self::to_html( $report, $info );
				$response = new WP_REST_Response( $html, 200 );
				$response->header( 'Content-Type', 'text/html; charset=utf-8' );
				$response->header( 'X-BT-Raw-Type', 'html' );
				return $response;

			case 'json':
			default:
				return rest_ensure_response( $report );
		}
	}

	/* ------------------------------------------------------------------
	 * Shortcode: [bt_tax_report]
	 * ------------------------------------------------------------------ */

	public static function sc_tax_report( $atts ) {
		$a = shortcode_atts( array(
			'default_year'   => (int) gmdate( 'Y' ),
			'default_method' => 'fifo',
		), $atts );

		if ( ! is_user_logged_in() ) {
			return '<div class="bt-tax-guest">📊 <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a> to generate your tax report.</div>';
		}

		$uid       = get_current_user_id();
		$years     = self::years_with_activity( $uid );
		if ( ! $years ) $years = array( (int) $a['default_year'] );
		$def_year  = in_array( (int) $a['default_year'], $years, true ) ? (int) $a['default_year'] : $years[0];
		$lt_days   = (int) get_option( self::OPT_LT_DAYS, 366 );
		$rest_url  = esc_url_raw( rest_url( 'blockticker/v1/portfolio/v2/tax-report' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );

		ob_start(); ?>
		<div class="bt-tax-wrap" data-rest="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<div class="bt-tax-controls">
				<h2>📊 Tax Report</h2>
				<p>Realized capital gains for your tax year, computed from your transaction ledger with full lot-matching and long-term / short-term classification.</p>

				<div class="bt-tax-form">
					<label>Tax year
						<select class="bt-tax-year">
							<?php foreach ( $years as $y ) : ?>
								<option value="<?php echo (int) $y; ?>" <?php selected( $y, $def_year ); ?>><?php echo (int) $y; ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>Method
						<select class="bt-tax-method">
							<option value="fifo" <?php selected( 'fifo', $a['default_method'] ); ?>>FIFO</option>
							<option value="hifo" <?php selected( 'hifo', $a['default_method'] ); ?>>HIFO</option>
						</select>
					</label>
					<label>LT threshold
						<input type="number" class="bt-tax-lt" value="<?php echo (int) $lt_days; ?>" min="1" max="3650" style="width:80px"> days
					</label>
					<button type="button" class="bt-tax-preview-btn">↻ Update preview</button>
				</div>

				<div class="bt-tax-downloads">
					<a class="bt-tax-dl" data-fmt="html"            target="_blank">📄 HTML report (print-ready)</a>
					<a class="bt-tax-dl" data-fmt="csv"             target="_blank">📊 Realized-gains CSV</a>
					<a class="bt-tax-dl" data-fmt="csv_form8949"    target="_blank">📋 Form 8949 CSV</a>
				</div>
			</div>

			<div class="bt-tax-preview">
				<p class="bt-tax-loading">Loading preview…</p>
			</div>
		</div>

		<?php echo self::inline_css(); ?>
		<?php echo self::inline_js(); ?>
		<?php
		return ob_get_clean();
	}

	private static function inline_css() {
		static $done = false;
		if ( $done ) return '';
		$done = true;
		return '<style>
.bt-tax-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--bt-text);background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:20px;margin:16px 0}
.bt-tax-guest{padding:24px;background:var(--bt-bg-elev);border:1px solid var(--bt-text-4);border-radius:0;color:var(--bt-text-2);text-align:center}
.bt-tax-guest a{color:var(--bt-accent);text-decoration:none;font-weight:600}
.bt-tax-controls h2{margin:0 0 4px;color:var(--bt-text);font-size:20px}
.bt-tax-controls p{margin:0 0 14px;color:var(--bt-text-2);font-size:13px}
.bt-tax-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px;margin-bottom:12px}
.bt-tax-form label{display:flex;flex-direction:column;font-size:11px;color:var(--bt-text-2);text-transform:uppercase;letter-spacing:.04em;gap:4px}
.bt-tax-form select,.bt-tax-form input{background:#0b0f1a;color:var(--bt-text);border:1px solid var(--bt-text-4);border-radius:0;padding:6px 8px;font-size:13px}
.bt-tax-preview-btn{background:var(--bt-accent);color:#0b0f1a;border:0;border-radius:0;padding:8px 14px;font-weight:700;cursor:pointer;font-size:13px;align-self:end}
.bt-tax-downloads{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.bt-tax-dl{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#1e2535;color:var(--bt-text);border:1px solid var(--bt-text-4);border-radius:0;font-size:13px;text-decoration:none;cursor:pointer}
.bt-tax-dl:hover{background:var(--bt-text-4);border-color:var(--bt-accent);color:var(--bt-accent)}
.bt-tax-preview{margin-top:14px;padding:16px;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;min-height:200px}
.bt-tax-loading{color:var(--bt-text-3);text-align:center;padding:24px}
.bt-tax-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px}
.bt-tax-tile{background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:10px}
.bt-tax-tile-label{font-size:10px;color:var(--bt-text-2);text-transform:uppercase;letter-spacing:.04em}
.bt-tax-tile-v{font-size:16px;font-weight:700;margin-top:3px}
.bt-tax-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.bt-tax-table th{background:#1e2535;color:var(--bt-text-2);font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:7px;text-align:right}
.bt-tax-table th:first-child,.bt-tax-table th:nth-child(2),.bt-tax-table th:nth-child(3),.bt-tax-table th:nth-child(4){text-align:left}
.bt-tax-table td{border-bottom:1px solid #1e2535;padding:7px;text-align:right}
.bt-tax-table td:first-child,.bt-tax-table td:nth-child(2),.bt-tax-table td:nth-child(3),.bt-tax-table td:nth-child(4){text-align:left}
.bt-tax-table tr.broken td{background:#422006}
.bt-tax-term-short{background:#991b1b;color:#fff;padding:1px 6px;border-radius:0;font-size:10px;font-weight:700}
.bt-tax-term-long{background:#065f46;color:#fff;padding:1px 6px;border-radius:0;font-size:10px;font-weight:700}
.bt-tax-pos{color:#22c55e;font-weight:600}
.bt-tax-neg{color:#ef4444;font-weight:600}
.bt-tax-empty{color:var(--bt-text-3);text-align:center;padding:30px;font-style:italic}
.bt-tax-broken-warn{background:#422006;border:1px solid var(--bt-accent-warm);border-radius:0;padding:8px 12px;font-size:12px;color:#fcd34d;margin-bottom:10px}
</style>';
	}

	private static function inline_js() {
		return "<script>(function(){
function onReady(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn):fn();}
function usd(v,signed){v=Number(v)||0;var s=signed?(v>=0?'+':'-'):(v<0?'-':'');return s+'\$'+Math.abs(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function qty(v){var s=Number(v).toFixed(8);return s.replace(/0+\$/,'').replace(/\\.\$/,'');}
function fmtDate(ts){return ts>0?new Date(ts*1000).toISOString().slice(0,10):'\u2014';}

onReady(function(){
document.querySelectorAll('.bt-tax-wrap').forEach(function(root){
	if(root.dataset.btBound)return; root.dataset.btBound='1';
	var rest=root.dataset.rest, nonce=root.dataset.nonce;

	function currentArgs(){
		return {
			year:    root.querySelector('.bt-tax-year').value,
			method:  root.querySelector('.bt-tax-method').value,
			lt_days: root.querySelector('.bt-tax-lt').value,
		};
	}
	function buildUrl(fmt){
		var a=currentArgs();
		var p='?_wpnonce='+encodeURIComponent(nonce)+'&year='+encodeURIComponent(a.year)+'&method='+encodeURIComponent(a.method)+'&lt_days='+encodeURIComponent(a.lt_days);
		if(fmt) p += '&format='+fmt;
		return rest + p;
	}

	function updateDownloadLinks(){
		root.querySelectorAll('.bt-tax-dl').forEach(function(a){
			a.href = buildUrl(a.dataset.fmt);
			var fn = '';
			if (a.dataset.fmt === 'csv')          fn = 'blockticker-tax-'+currentArgs().year+'.csv';
			if (a.dataset.fmt === 'csv_form8949') fn = 'blockticker-form8949-'+currentArgs().year+'.csv';
			if (a.dataset.fmt === 'html')         fn = 'blockticker-tax-'+currentArgs().year+'.html';
			if (fn) a.setAttribute('download', fn);
		});
	}

	function loadPreview(){
		var box = root.querySelector('.bt-tax-preview');
		box.innerHTML = '<p class=bt-tax-loading>Loading preview…</p>';
		fetch(buildUrl('json'), { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } })
			.then(function(r){ return r.json(); })
			.then(function(d){ renderPreview(box, d); })
			.catch(function(e){ box.innerHTML = '<p class=bt-tax-empty style=\"color:#ef4444;\">Failed to load: '+e.message+'</p>'; });
		updateDownloadLinks();
	}

	function renderPreview(box, d){
		if(!d.lots){ box.innerHTML = '<p class=bt-tax-empty>'+(d.message||'No data')+'</p>'; return; }
		if(d.lots.length===0){ box.innerHTML = '<p class=bt-tax-empty>No sell transactions in '+d.year+' — no realized gains to report.</p>'; return; }

		var html = '';
		if (d.broken_count > 0) {
			html += '<div class=bt-tax-broken-warn>\u26A0 '+d.broken_count+' lot'+(d.broken_count===1?'':'s')+' flagged — sells exceeded buys. Zero cost basis used for excess qty; import your full buy history to fix.</div>';
		}

		html += '<div class=bt-tax-tiles>'
			+ '<div class=bt-tax-tile><div class=bt-tax-tile-label>Short-term gain</div><div class=bt-tax-tile-v '+(d.short_term.gain>=0?'bt-tax-pos':'bt-tax-neg')+'>'+usd(d.short_term.gain,true)+'</div></div>'
			+ '<div class=bt-tax-tile><div class=bt-tax-tile-label>Long-term gain</div><div class=bt-tax-tile-v '+(d.long_term.gain>=0?'bt-tax-pos':'bt-tax-neg')+'>'+usd(d.long_term.gain,true)+'</div></div>'
			+ '<div class=bt-tax-tile><div class=bt-tax-tile-label>Total proceeds</div><div class=bt-tax-tile-v>'+usd(d.total.proceeds)+'</div></div>'
			+ '<div class=bt-tax-tile><div class=bt-tax-tile-label>Total cost basis</div><div class=bt-tax-tile-v>'+usd(d.total.cost_basis)+'</div></div>'
			+ '<div class=bt-tax-tile><div class=bt-tax-tile-label>Net gain/loss</div><div class=bt-tax-tile-v '+(d.total.gain>=0?'bt-tax-pos':'bt-tax-neg')+'>'+usd(d.total.gain,true)+'</div></div>'
			+ '</div>';

		html += '<table class=bt-tax-table>'
			+ '<thead><tr><th>Asset</th><th>Qty</th><th>Acquired</th><th>Disposed</th><th>Days</th><th>Term</th><th>Basis</th><th>Proceeds</th><th>Gain</th></tr></thead><tbody>';
		d.lots.forEach(function(l){
			var termCls = l.term==='long'?'bt-tax-term-long':'bt-tax-term-short';
			var gainCls = l.gain>=0?'bt-tax-pos':'bt-tax-neg';
			html += '<tr'+(l.broken?' class=broken':'')+'>'
				+ '<td><strong>'+l.symbol+'</strong>'+(l.broken?' \u26A0':'')+'</td>'
				+ '<td>'+qty(l.qty)+'</td>'
				+ '<td>'+fmtDate(l.acquired_at)+'</td>'
				+ '<td>'+fmtDate(l.disposed_at)+'</td>'
				+ '<td>'+(l.acquired_at?l.holding_days:'\u2014')+'</td>'
				+ '<td><span class='+termCls+'>'+l.term.toUpperCase()+'</span></td>'
				+ '<td>'+usd(l.cost_basis)+'</td>'
				+ '<td>'+usd(l.proceeds)+'</td>'
				+ '<td class='+gainCls+'>'+usd(l.gain,true)+'</td>'
				+ '</tr>';
		});
		html += '</tbody></table>';
		box.innerHTML = html;
	}

	root.querySelector('.bt-tax-preview-btn').addEventListener('click', loadPreview);
	['.bt-tax-year','.bt-tax-method','.bt-tax-lt'].forEach(function(s){
		root.querySelector(s).addEventListener('change', updateDownloadLinks);
	});

	updateDownloadLinks();
	loadPreview();
});
});
})();</script>";
	}
}
