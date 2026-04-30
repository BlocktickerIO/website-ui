<?php
/**
 * BT_Deprecation — Legacy fxlm_* option key helpers.
 *
 * As of v101.0, the pre_option_fxlm_* read-through shims that were installed
 * in v99.0 have been removed. The fxlm_* option rows were deleted in the v100.0
 * upgrade, and one full release cycle has passed — the safety-net period is over.
 *
 * What remains in this class:
 *  - delete_legacy_rows()  — used by BT_V100_Upgrade (kept for manual re-run)
 *  - count_legacy_rows()   — used by the DB admin panel preview
 *  - admin_panel_html()    — DB admin right-column panel (now shows "complete" state)
 *
 * The _doing_it_wrong() notice infrastructure is also removed. Any remaining
 * third-party code reading fxlm_* keys will now get WordPress's default
 * behaviour: an empty/false return (the rows no longer exist in wp_options).
 *
 * @since 99.0.0
 * @updated 101.0.0  pre_option shims removed; class simplified to helpers only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Deprecation {

    /**
     * init() is kept for backward compatibility — existing plugins_loaded
     * hook in fx-live-markets.php calls this. It is now a no-op.
     */
    public static function init() {
        // No-op in v101.0. pre_option shims removed.
    }

    /* ------------------------------------------------------------------
     * Admin: deprecation status panel
     * ------------------------------------------------------------------ */

    /**
     * Return HTML for the admin panel — now shows migration-complete state.
     *
     * @return string  HTML.
     */
    public static function admin_panel_html() {
        ob_start(); ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    ✅ fxlm_* Migration Complete
                </h2>
            </div>
            <div class="inside">
                <table class="widefat striped" style="font-size:13px;margin-bottom:12px;">
                    <tbody>
                        <tr>
                            <th>Option rows</th>
                            <td>All <code>fxlm_*</code> rows deleted in v100.0 upgrade. <?php
                                $remaining = self::count_legacy_rows();
                                if ( $remaining > 0 ) {
                                    echo '<span style="color:#d63638;">' . intval($remaining) . ' rows still present — re-run the v100.0 upgrade above.</span>';
                                } else {
                                    echo '<span style="color:#00a32a;">0 rows remaining ✓</span>';
                                }
                            ?></td>
                        </tr>
                        <tr>
                            <th>pre_option shims</th>
                            <td><span style="color:#00a32a;">Removed in v101.0</span> — safety-net period complete.</td>
                        </tr>
                        <tr>
                            <th>Class declarations</th>
                            <td><code>FXLM_*</code> are now reverse aliases of <code>BT_*</code> (physical rename done in v101.0).</td>
                        </tr>
                    </tbody>
                </table>
                <p style="font-size:12px;color:#888;margin:0;">
                    The <code>fxlm_* → bt_*</code> migration arc is complete.
                    All plugin identifiers are now canonical <code>BT_*</code>.
                    External code reading <code>fxlm_*</code> option keys will receive
                    an empty/false value — those rows no longer exist.
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Legacy row helpers (kept for manual re-run via v100 panel)
     * ------------------------------------------------------------------ */

    /**
     * Delete every fxlm_* row from wp_options (idempotent — safe if already empty).
     *
     * @return int  Number of rows deleted.
     */
    public static function delete_legacy_rows() {
        global $wpdb;
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'fxlm_' ) . '%'
            )
        );
        return (int) $result;
    }

    /**
     * Count remaining fxlm_* rows (for admin UI preview).
     *
     * @return int
     */
    public static function count_legacy_rows() {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'fxlm_' ) . '%'
            )
        );
    }
}
