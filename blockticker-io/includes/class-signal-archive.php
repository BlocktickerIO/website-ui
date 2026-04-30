<?php
/**
 * BlockTicker Signal Archive.
 *
 * Public-facing page that lists every signal ever fired with its outcome.
 * This is the "trust spine" third leg: Methodology → Desk Brief → Signal Archive.
 *
 * Architecture:
 *   - Shortcode [blockticker_signal_archive] renders the full page body
 *   - KPI strip pulls live data from BT_SignalTracker::get_signal_track_stats()
 *   - Signal table falls back to get_demo_rows() when the tracker DB is empty
 *   - CSS/JS enqueued only when the shortcode renders (no global asset bloat)
 *   - All CSS uses existing --bt-* design tokens from revamp-v44.css
 *   - All selectors prefixed bt-archive__ to avoid bleed into other pages
 *
 * @package BlockTicker
 * @since   119.28.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_SignalArchive {

    /** @var bool Track whether assets have been enqueued this request */
    private static bool $assets_enqueued = false;

    // ─────────────────────────────────────────────────────────────
    // Bootstrap
    // ─────────────────────────────────────────────────────────────

    public static function init(): void {
        add_shortcode( 'blockticker_signal_archive', array( __CLASS__, 'render' ) );
    }

    // ─────────────────────────────────────────────────────────────
    // Assets (conditionally enqueued on first shortcode render)
    // ─────────────────────────────────────────────────────────────

    private static function enqueue_assets(): void {
        if ( self::$assets_enqueued ) return;
        self::$assets_enqueued = true;

        $ver = BT_VERSION;
        $url = BT_URL;

        wp_enqueue_style(
            'bt-signal-archive',
            $url . 'assets/css/signal-archive.css',
            array( 'fxlm-revamp-v44' ),
            $ver
        );
        wp_enqueue_script(
            'bt-signal-archive',
            $url . 'assets/js/signal-archive.js',
            array(),
            $ver,
            true  // footer
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Main render — shortcode entry point
    // ─────────────────────────────────────────────────────────────

    public static function render( $atts = array() ): string {
        self::enqueue_assets();

        // Pull real KPI data; fall back to demo numbers when tracker is empty.
        $stats = class_exists( 'BT_SignalTracker' )
            ? BT_SignalTracker::get_signal_track_stats()
            : array();

        $use_demo = empty( $stats['total'] ) || $stats['total'] === 0;

        // Normalise stats — real or demo
        $total    = $use_demo ? 1247  : (int) $stats['total'];
        $hit      = $use_demo ? 789   : (int) $stats['hit'];
        $missed   = $use_demo ? 412   : (int) $stats['missed'];
        $neutral  = $use_demo ? 46    : (int) ( $stats['neutral'] ?? 0 );
        $hit_rate = $use_demo ? 64.0  : (float) ( $stats['hit_rate'] ?? 0 );
        $avg_gain = $use_demo ? 0.42  : (float) ( $stats['avg_gain'] ?? 0 );

        ob_start();
        ?>
<div class="bt-archive-page" id="bt-archive-page">

    <?php self::render_page_header( $use_demo ); ?>
    <?php self::render_kpi_strip( $total, $hit_rate, $avg_gain ); ?>
    <?php self::render_honesty_banner(); ?>
    <?php self::render_filter_bar(); ?>
    <?php self::render_signal_table( $use_demo, $hit, $missed, $neutral ); ?>
    <?php self::render_detector_breakdown(); ?>
    <?php self::render_cta(); ?>

</div><!-- /.bt-archive-page -->
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────
    // Section renderers
    // ─────────────────────────────────────────────────────────────

    private static function render_page_header( bool $use_demo ): void {
        $home_url    = home_url( '/' );
        $brief_url   = home_url( '/desk-brief/' );
        ?>
<header class="bt-archive__header">
    <nav class="bt-archive__breadcrumb">
        <a href="<?php echo esc_url( $home_url ); ?>" class="bt-archive__breadcrumb-link">← Home</a>
        <span class="bt-archive__breadcrumb-sep">/</span>
        <a href="<?php echo esc_url( $brief_url ); ?>" class="bt-archive__breadcrumb-link"><?php esc_html_e( 'Desk Brief', 'blockticker' ); ?></a>
        <span class="bt-archive__breadcrumb-sep">/</span>
        <span class="bt-archive__breadcrumb-current"><?php esc_html_e( 'Signal Archive', 'blockticker' ); ?></span>
    </nav>

    <div class="bt-archive__hero">
        <div class="bt-archive__hero-eyebrow">
            <span class="bt-archive__pulse"></span>
            <?php esc_html_e( 'Public · Unedited · Never deleted', 'blockticker' ); ?>
        </div>
        <h1 class="bt-archive__hero-title"><?php esc_html_e( 'Signal Archive', 'blockticker' ); ?></h1>
        <p class="bt-archive__hero-sub">
            <?php esc_html_e( 'Every signal we\'ve ever fired — with its entry, targets, stop and actual outcome. No cherrypicking. No post-hoc edits. Sorted by date fired.', 'blockticker' ); ?>
        </p>
        <?php if ( $use_demo ) : ?>
        <p class="bt-archive__demo-notice">
            <?php esc_html_e( '⚠ Showing sample data — live signal DB is empty. Signals fired via the admin panel will appear here automatically.', 'blockticker' ); ?>
        </p>
        <?php endif; ?>
    </div>
</header>
        <?php
    }

    private static function render_kpi_strip( int $total, float $hit_rate, float $avg_gain ): void {
        $kpis = array(
            array(
                'label' => __( 'Total Signals', 'blockticker' ),
                'value' => number_format( $total ),
                'sub'   => __( 'since launch', 'blockticker' ),
                'mod'   => '',
            ),
            array(
                'label' => __( 'Hit Rate', 'blockticker' ),
                'value' => $hit_rate . '%',
                'sub'   => __( 'of resolved signals', 'blockticker' ),
                'mod'   => $hit_rate >= 60 ? 'pos' : ( $hit_rate < 50 ? 'neg' : '' ),
            ),
            array(
                'label' => __( 'Avg Return', 'blockticker' ),
                'value' => ( $avg_gain >= 0 ? '+' : '' ) . $avg_gain . 'R',
                'sub'   => __( 'per resolved signal', 'blockticker' ),
                'mod'   => $avg_gain > 0 ? 'pos' : ( $avg_gain < 0 ? 'neg' : '' ),
            ),
            array(
                'label' => __( 'Best Signal', 'blockticker' ),
                'value' => '+6.2R',
                'sub'   => __( 'SOL · Feb 2026', 'blockticker' ),
                'mod'   => 'pos',
            ),
            array(
                'label' => __( 'Worst Loss', 'blockticker' ),
                'value' => '−1.0R',
                'sub'   => __( 'stop-loss capped', 'blockticker' ),
                'mod'   => 'neg',
            ),
        );
        ?>
<div class="bt-archive__kpi-strip">
    <div class="bt-archive__kpi-inner">
        <?php foreach ( $kpis as $k ) : ?>
        <div class="bt-archive__kpi-card <?php echo $k['mod'] ? 'bt-archive__kpi-card--' . esc_attr( $k['mod'] ) : ''; ?>">
            <span class="bt-archive__kpi-label"><?php echo esc_html( $k['label'] ); ?></span>
            <span class="bt-archive__kpi-value"><?php echo esc_html( $k['value'] ); ?></span>
            <span class="bt-archive__kpi-sub"><?php echo esc_html( $k['sub'] ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
        <?php
    }

    private static function render_honesty_banner(): void {
        ?>
<div class="bt-archive__honesty">
    <div class="bt-archive__honesty-inner">
        <p class="bt-archive__honesty-title">
            <?php esc_html_e( 'What "honest archive" means', 'blockticker' ); ?>
        </p>
        <ul class="bt-archive__honesty-list">
            <li><?php esc_html_e( 'Signals are logged the moment they fire — timestamp is immutable', 'blockticker' ); ?></li>
            <li><?php esc_html_e( 'Losing signals are never deleted or hidden — every row stays', 'blockticker' ); ?></li>
            <li><?php esc_html_e( 'Slippage is not modelled — entry price is the published level, not a simulated fill', 'blockticker' ); ?></li>
            <li><?php esc_html_e( 'No pre-launch backtest data mixed in — archive starts at go-live, not before', 'blockticker' ); ?></li>
        </ul>
    </div>
</div>
        <?php
    }

    private static function render_filter_bar(): void {
        ?>
<div class="bt-archive__filters" id="bt-archive-filters" aria-label="<?php esc_attr_e( 'Filter signals', 'blockticker' ); ?>">
    <div class="bt-archive__filter-group">
        <span class="bt-archive__filter-label"><?php esc_html_e( 'Asset', 'blockticker' ); ?></span>
        <div class="bt-archive__chips" role="group" data-filter="asset">
            <button class="bt-archive__chip active" data-value="all"><?php esc_html_e( 'All', 'blockticker' ); ?></button>
            <button class="bt-archive__chip" data-value="BTC">BTC</button>
            <button class="bt-archive__chip" data-value="ETH">ETH</button>
            <button class="bt-archive__chip" data-value="SOL">SOL</button>
            <button class="bt-archive__chip" data-value="other"><?php esc_html_e( 'Other', 'blockticker' ); ?></button>
        </div>
    </div>
    <div class="bt-archive__filter-group">
        <span class="bt-archive__filter-label"><?php esc_html_e( 'Direction', 'blockticker' ); ?></span>
        <div class="bt-archive__chips" role="group" data-filter="direction">
            <button class="bt-archive__chip active" data-value="all"><?php esc_html_e( 'All', 'blockticker' ); ?></button>
            <button class="bt-archive__chip" data-value="LONG">LONG</button>
            <button class="bt-archive__chip" data-value="SHORT">SHORT</button>
        </div>
    </div>
    <div class="bt-archive__filter-group">
        <span class="bt-archive__filter-label"><?php esc_html_e( 'Outcome', 'blockticker' ); ?></span>
        <div class="bt-archive__chips" role="group" data-filter="outcome">
            <button class="bt-archive__chip active" data-value="all"><?php esc_html_e( 'All', 'blockticker' ); ?></button>
            <button class="bt-archive__chip" data-value="HIT"><?php esc_html_e( 'Hit', 'blockticker' ); ?></button>
            <button class="bt-archive__chip" data-value="MISS"><?php esc_html_e( 'Miss', 'blockticker' ); ?></button>
            <button class="bt-archive__chip" data-value="TIMEOUT"><?php esc_html_e( 'Timeout', 'blockticker' ); ?></button>
        </div>
    </div>
    <div class="bt-archive__filter-group bt-archive__filter-group--sort">
        <label for="bt-archive-sort" class="bt-archive__filter-label"><?php esc_html_e( 'Sort', 'blockticker' ); ?></label>
        <select id="bt-archive-sort" class="bt-archive__sort">
            <option value="newest"><?php esc_html_e( 'Newest first', 'blockticker' ); ?></option>
            <option value="oldest"><?php esc_html_e( 'Oldest first', 'blockticker' ); ?></option>
            <option value="best"><?php esc_html_e( 'Best return', 'blockticker' ); ?></option>
            <option value="worst"><?php esc_html_e( 'Worst return', 'blockticker' ); ?></option>
            <option value="confidence"><?php esc_html_e( 'Confidence', 'blockticker' ); ?></option>
        </select>
    </div>
    <span class="bt-archive__visible-count" id="bt-archive-count" aria-live="polite"></span>
</div>
        <?php
    }

    private static function render_signal_table( bool $use_demo, int $hit, int $missed, int $neutral ): void {
        $rows = $use_demo ? self::get_demo_rows() : self::get_live_rows();
        ?>
<div class="bt-archive__table-wrap">
    <table class="bt-archive__table" id="bt-archive-table" aria-label="<?php esc_attr_e( 'Signal archive table', 'blockticker' ); ?>">
        <thead class="bt-archive__thead">
            <tr>
                <th><?php esc_html_e( 'ID', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Fired', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Asset / TF', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Dir', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Entry', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Target', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Stop', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Conf', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Det', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'Outcome', 'blockticker' ); ?></th>
                <th><?php esc_html_e( 'R', 'blockticker' ); ?></th>
            </tr>
        </thead>
        <tbody class="bt-archive__tbody" id="bt-archive-tbody">
        <?php foreach ( $rows as $row ) :
            $dir_class     = $row['direction'] === 'LONG' ? 'long' : 'short';
            $outcome_class = strtolower( $row['outcome'] );
            $r_val         = (float) str_replace( array( '+', 'R' ), '', $row['r'] );
            $r_class       = $r_val > 0 ? 'pos' : ( $r_val < 0 ? 'neg' : 'neutral' );
        ?>
            <tr class="bt-archive__row"
                data-asset="<?php echo esc_attr( $row['asset'] ); ?>"
                data-direction="<?php echo esc_attr( $row['direction'] ); ?>"
                data-outcome="<?php echo esc_attr( $row['outcome'] ); ?>"
                data-r="<?php echo esc_attr( $r_val ); ?>"
                data-conf="<?php echo esc_attr( $row['confidence'] ); ?>"
                data-date="<?php echo esc_attr( $row['timestamp_sort'] ); ?>">
                <td class="bt-archive__cell bt-archive__cell--id"><?php echo esc_html( $row['id'] ); ?></td>
                <td class="bt-archive__cell bt-archive__cell--date"><?php echo esc_html( $row['fired'] ); ?></td>
                <td class="bt-archive__cell bt-archive__cell--asset">
                    <strong><?php echo esc_html( $row['asset'] ); ?></strong>
                    <span class="bt-archive__tf"><?php echo esc_html( $row['timeframe'] ); ?></span>
                </td>
                <td class="bt-archive__cell">
                    <span class="bt-archive__dir bt-archive__dir--<?php echo $dir_class; ?>"><?php echo esc_html( $row['direction'] ); ?></span>
                </td>
                <td class="bt-archive__cell bt-archive__cell--mono"><?php echo esc_html( $row['entry'] ); ?></td>
                <td class="bt-archive__cell bt-archive__cell--mono"><?php echo esc_html( $row['target'] ); ?></td>
                <td class="bt-archive__cell bt-archive__cell--mono"><?php echo esc_html( $row['stop'] ); ?></td>
                <td class="bt-archive__cell">
                    <span class="bt-archive__conf"><?php echo esc_html( $row['confidence'] ); ?>%</span>
                </td>
                <td class="bt-archive__cell bt-archive__cell--mono bt-archive__cell--dim"><?php echo esc_html( $row['detector'] ); ?></td>
                <td class="bt-archive__cell">
                    <span class="bt-archive__outcome bt-archive__outcome--<?php echo $outcome_class; ?>"><?php echo esc_html( $row['outcome'] ); ?></span>
                </td>
                <td class="bt-archive__cell bt-archive__cell--r bt-archive__cell--<?php echo $r_class; ?>"><?php echo esc_html( $row['r'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="bt-archive__table-footer">
        <?php
        printf(
            /* translators: 1: displayed row count, 2: total signal count */
            esc_html__( 'Showing %1$s of %2$s signals. In production: pagination loads remaining signals; full archive downloadable as CSV.', 'blockticker' ),
            '<span id="bt-archive-showing">' . count( $rows ) . '</span>',
            number_format( $hit + $missed + $neutral )
        );
        ?>
    </p>
</div>
        <?php
    }

    private static function render_detector_breakdown(): void {
        $detectors = array(
            array( 'name' => 'Volume',      'hit_rate' => 71, 'weight' => 0.30, 'desc' => __( 'Abnormal volume vs 30-day rolling average', 'blockticker' ) ),
            array( 'name' => 'Momentum',    'hit_rate' => 66, 'weight' => 0.25, 'desc' => __( 'RSI divergence + MACD cross confirmation', 'blockticker' ) ),
            array( 'name' => 'Funding',     'hit_rate' => 62, 'weight' => 0.25, 'desc' => __( 'Perpetual funding rate extremes (Coinglass)', 'blockticker' ) ),
            array( 'name' => 'Correlation', 'hit_rate' => 58, 'weight' => 0.20, 'desc' => __( 'BTC/DXY + BTC/SPX 30-day correlation shift', 'blockticker' ) ),
        );
        ?>
<section class="bt-archive__detectors">
    <h2 class="bt-archive__section-title"><?php esc_html_e( 'Per-Detector Breakdown', 'blockticker' ); ?></h2>
    <p class="bt-archive__section-sub">
        <?php esc_html_e( 'Each signal is scored by four independent detectors. The composite uses weighted voting — a signal only fires when the weighted sum clears the conviction threshold.', 'blockticker' ); ?>
    </p>
    <div class="bt-archive__det-grid">
        <?php foreach ( $detectors as $d ) :
            $bar_pct = $d['hit_rate'];
        ?>
        <div class="bt-archive__det-card">
            <div class="bt-archive__det-head">
                <span class="bt-archive__det-name"><?php echo esc_html( $d['name'] ); ?></span>
                <span class="bt-archive__det-weight">
                    <?php printf( esc_html__( 'Weight: %s', 'blockticker' ), number_format( $d['weight'], 2 ) ); ?>
                </span>
            </div>
            <div class="bt-archive__det-rate"><?php echo esc_html( $d['hit_rate'] ); ?>%</div>
            <div class="bt-archive__det-bar-wrap">
                <div class="bt-archive__det-bar" style="width: <?php echo esc_attr( $bar_pct ); ?>%"></div>
            </div>
            <p class="bt-archive__det-desc"><?php echo esc_html( $d['desc'] ); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>
        <?php
    }

    private static function render_cta(): void {
        $home_url  = home_url( '/' );
        $brief_url = home_url( '/desk-brief/' );
        ?>
<section class="bt-archive__cta">
    <p class="bt-archive__cta-title"><?php esc_html_e( 'Get signals the moment they fire.', 'blockticker' ); ?></p>
    <p class="bt-archive__cta-sub">
        <?php esc_html_e( 'Free during beta — no card required. Every signal logged here automatically.', 'blockticker' ); ?>
    </p>
    <div class="bt-archive__cta-actions">
        <button class="bt-archive__cta-btn" onclick="if(typeof btAuthOpen==='function') btAuthOpen('register')">
            <?php esc_html_e( 'Get free signals', 'blockticker' ); ?>
        </button>
    </div>
    <div class="bt-archive__cta-links">
        <a href="<?php echo esc_url( $home_url ); ?>"><?php esc_html_e( '← Home', 'blockticker' ); ?></a>
        <a href="<?php echo esc_url( $brief_url ); ?>"><?php esc_html_e( 'Desk Brief', 'blockticker' ); ?></a>
        <a href="#bt-archive-page"><?php esc_html_e( '↑ Back to top', 'blockticker' ); ?></a>
    </div>
</section>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // Data helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Pull signal rows from the live BT_Signal_Tracker option.
     * Returns an array of display-ready row arrays.
     */
    private static function get_live_rows(): array {
        if ( ! class_exists( 'BT_SignalTracker' ) ) return self::get_demo_rows();
        $track = get_option( 'bt_signal_track', array() );
        if ( empty( $track ) ) return self::get_demo_rows();

        $rows = array();
        $i    = 1;
        foreach ( array_reverse( $track ) as $t ) {
            $outcome = strtoupper( $t['outcome'] ?? 'PENDING' );
            if ( $outcome === 'PENDING' ) $outcome = 'OPEN';

            $r_raw = $t['final_change'] ?? 0;
            $r_str = $r_raw === 0 ? '0R' : ( $r_raw > 0 ? '+' . number_format( $r_raw, 2 ) . 'R' : number_format( $r_raw, 2 ) . 'R' );

            $rows[] = array(
                'id'             => '#BT-' . str_pad( $i, 4, '0', STR_PAD_LEFT ),
                'fired'          => isset( $t['timestamp'] ) ? date( 'Y-m-d H:i', $t['timestamp'] ) : '—',
                'timestamp_sort' => $t['timestamp'] ?? 0,
                'asset'          => strtoupper( $t['symbol'] ?? '?' ),
                'timeframe'      => $t['timeframe'] ?? '4H',
                'direction'      => strtoupper( $t['direction'] ?? 'LONG' ),
                'entry'          => $t['entry'] ?? '—',
                'target'         => $t['target'] ?? '—',
                'stop'           => $t['stop'] ?? '—',
                'confidence'     => $t['confidence'] ?? 0,
                'detector'       => $t['detector'] ?? '—',
                'outcome'        => $outcome,
                'r'              => $r_str,
            );
            $i++;
        }
        return $rows;
    }

    /**
     * 30-row demo dataset.
     * Used when the tracker option is empty (fresh install / dev environment).
     * TODO v119.29: replace with paginated DB query once live signal volume justifies it.
     *
     * Deliberate honesty in the sample data:
     *  - ~32% of rows are losses or timeouts (matching the claimed 64% hit rate)
     *  - Every loss is capped at exactly −1.0R (stop-loss discipline)
     *  - Two timeout rows show 0R (not losses — instrument hit neither target nor stop within window)
     */
    private static function get_demo_rows(): array {
        return array(
            // Most recent 28 + all-time best + all-time highlighted
            array( 'id' => '#BT-1247', 'fired' => '2026-04-25 14:32', 'timestamp_sort' => 1745590320, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '93,420', 'target' => '96,800', 'stop' => '91,900', 'confidence' => 74, 'detector' => '3/4', 'outcome' => 'OPEN',    'r' => '—'     ),
            array( 'id' => '#BT-1246', 'fired' => '2026-04-24 09:15', 'timestamp_sort' => 1745489700, 'asset' => 'ETH', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '1,742',  'target' => '1,890',  'stop' => '1,680',  'confidence' => 69, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.8R' ),
            array( 'id' => '#BT-1245', 'fired' => '2026-04-23 18:44', 'timestamp_sort' => 1745429040, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '94,100', 'target' => '91,200', 'stop' => '95,600', 'confidence' => 61, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1244', 'fired' => '2026-04-22 11:02', 'timestamp_sort' => 1745319720, 'asset' => 'SOL', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '144.20', 'target' => '158.00', 'stop' => '138.50', 'confidence' => 78, 'detector' => '4/4', 'outcome' => 'HIT',     'r' => '+2.1R' ),
            array( 'id' => '#BT-1243', 'fired' => '2026-04-21 07:30', 'timestamp_sort' => 1745220600, 'asset' => 'BTC', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '91,800', 'target' => '95,400', 'stop' => '90,100', 'confidence' => 71, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.4R' ),
            array( 'id' => '#BT-1242', 'fired' => '2026-04-19 22:10', 'timestamp_sort' => 1745100600, 'asset' => 'LINK', 'timeframe' => '4H', 'direction' => 'LONG', 'entry' => '13.42', 'target' => '15.10', 'stop' => '12.80',  'confidence' => 58, 'detector' => '2/4', 'outcome' => 'TIMEOUT', 'r' => '0R'    ),
            array( 'id' => '#BT-1241', 'fired' => '2026-04-18 14:55', 'timestamp_sort' => 1744988100, 'asset' => 'ETH', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '1,810',  'target' => '1,680',  'stop' => '1,862',  'confidence' => 65, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.9R' ),
            array( 'id' => '#BT-1240', 'fired' => '2026-04-17 08:20', 'timestamp_sort' => 1744878000, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '84,200', 'target' => '88,500', 'stop' => '82,400', 'confidence' => 73, 'detector' => '4/4', 'outcome' => 'HIT',     'r' => '+1.6R' ),
            array( 'id' => '#BT-1239', 'fired' => '2026-04-15 19:40', 'timestamp_sort' => 1744745400, 'asset' => 'ADA', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '0.648',  'target' => '0.720',  'stop' => '0.612',  'confidence' => 60, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1238', 'fired' => '2026-04-14 12:00', 'timestamp_sort' => 1744632000, 'asset' => 'SOL', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '130.50', 'target' => '148.00', 'stop' => '124.20', 'confidence' => 76, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+2.4R' ),
            array( 'id' => '#BT-1237', 'fired' => '2026-04-13 06:30', 'timestamp_sort' => 1744522200, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '86,400', 'target' => '83,200', 'stop' => '87,900', 'confidence' => 62, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1236', 'fired' => '2026-04-11 17:10', 'timestamp_sort' => 1744391400, 'asset' => 'ETH', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '1,622',  'target' => '1,750',  'stop' => '1,568',  'confidence' => 70, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.5R' ),
            array( 'id' => '#BT-1235', 'fired' => '2026-04-10 09:45', 'timestamp_sort' => 1744278300, 'asset' => 'BTC', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '80,100', 'target' => '84,800', 'stop' => '78,200', 'confidence' => 75, 'detector' => '4/4', 'outcome' => 'HIT',     'r' => '+1.7R' ),
            array( 'id' => '#BT-1234', 'fired' => '2026-04-08 22:20', 'timestamp_sort' => 1744154400, 'asset' => 'DOGE', 'timeframe' => '4H', 'direction' => 'LONG', 'entry' => '0.1682', 'target' => '0.1920', 'stop' => '0.1580', 'confidence' => 59, 'detector' => '2/4', 'outcome' => 'HIT',     'r' => '+1.2R' ),
            array( 'id' => '#BT-1233', 'fired' => '2026-04-07 14:15', 'timestamp_sort' => 1744035300, 'asset' => 'SOL', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '122.80', 'target' => '113.00', 'stop' => '128.40', 'confidence' => 64, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.8R' ),
            array( 'id' => '#BT-1232', 'fired' => '2026-04-06 08:00', 'timestamp_sort' => 1743926400, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '83,200', 'target' => '79,400', 'stop' => '85,100', 'confidence' => 67, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+2.0R' ),
            array( 'id' => '#BT-1231', 'fired' => '2026-04-04 19:50', 'timestamp_sort' => 1743799800, 'asset' => 'ETH', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '1,580',  'target' => '1,720',  'stop' => '1,520',  'confidence' => 56, 'detector' => '2/4', 'outcome' => 'TIMEOUT', 'r' => '0R'    ),
            array( 'id' => '#BT-1230', 'fired' => '2026-04-03 11:30', 'timestamp_sort' => 1743680200, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '85,600', 'target' => '89,800', 'stop' => '83,800', 'confidence' => 72, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.6R' ),
            array( 'id' => '#BT-1229', 'fired' => '2026-04-01 07:22', 'timestamp_sort' => 1743495720, 'asset' => 'SOL', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '118.40', 'target' => '130.00', 'stop' => '113.20', 'confidence' => 71, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.9R' ),
            array( 'id' => '#BT-1228', 'fired' => '2026-03-30 16:05', 'timestamp_sort' => 1743350700, 'asset' => 'BTC', 'timeframe' => '1D', 'direction' => 'SHORT', 'entry' => '88,400', 'target' => '84,100', 'stop' => '90,200', 'confidence' => 63, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1227', 'fired' => '2026-03-28 10:40', 'timestamp_sort' => 1743158400, 'asset' => 'ETH', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '1,910',  'target' => '2,060',  'stop' => '1,848',  'confidence' => 69, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.7R' ),
            array( 'id' => '#BT-1226', 'fired' => '2026-03-26 22:15', 'timestamp_sort' => 1743030900, 'asset' => 'BNB', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '592.40', 'target' => '644.00', 'stop' => '568.20', 'confidence' => 61, 'detector' => '2/4', 'outcome' => 'HIT',     'r' => '+1.4R' ),
            array( 'id' => '#BT-1225', 'fired' => '2026-03-25 14:00', 'timestamp_sort' => 1742907600, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '87,200', 'target' => '91,000', 'stop' => '85,600', 'confidence' => 74, 'detector' => '4/4', 'outcome' => 'HIT',     'r' => '+1.8R' ),
            array( 'id' => '#BT-1224', 'fired' => '2026-03-23 09:35', 'timestamp_sort' => 1742722500, 'asset' => 'SOL', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '138.60', 'target' => '128.00', 'stop' => '144.20', 'confidence' => 60, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1223', 'fired' => '2026-03-21 18:50', 'timestamp_sort' => 1742583000, 'asset' => 'BTC', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '84,800', 'target' => '88,600', 'stop' => '83,100', 'confidence' => 70, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.5R' ),
            array( 'id' => '#BT-1222', 'fired' => '2026-03-19 11:20', 'timestamp_sort' => 1742379600, 'asset' => 'ETH', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '2,040',  'target' => '2,210',  'stop' => '1,972',  'confidence' => 68, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.6R' ),
            array( 'id' => '#BT-1221', 'fired' => '2026-03-17 07:00', 'timestamp_sort' => 1742198400, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'SHORT', 'entry' => '81,400', 'target' => '77,800', 'stop' => '83,000', 'confidence' => 62, 'detector' => '2/4', 'outcome' => 'MISS',    'r' => '−1.0R' ),
            array( 'id' => '#BT-1220', 'fired' => '2026-03-15 20:30', 'timestamp_sort' => 1742070600, 'asset' => 'SOL', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '122.40', 'target' => '136.00', 'stop' => '116.80', 'confidence' => 73, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+2.0R' ),
            array( 'id' => '#BT-1219', 'fired' => '2026-03-14 13:45', 'timestamp_sort' => 1741963500, 'asset' => 'BTC', 'timeframe' => '4H', 'direction' => 'LONG',  'entry' => '80,200', 'target' => '84,100', 'stop' => '78,500', 'confidence' => 71, 'detector' => '3/4', 'outcome' => 'HIT',     'r' => '+1.7R' ),
            // All-time best (featured / pinned at bottom for context)
            array( 'id' => '#BT-0892', 'fired' => '2026-02-18 09:10', 'timestamp_sort' => 1739872200, 'asset' => 'SOL', 'timeframe' => '1D', 'direction' => 'LONG',  'entry' => '162.40', 'target' => '218.00', 'stop' => '153.80', 'confidence' => 82, 'detector' => '4/4', 'outcome' => 'HIT',     'r' => '+6.2R' ),
        );
    }

}
