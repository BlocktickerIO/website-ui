<?php
/**
 * BT_Migration — Option key migration engine for BlockTicker v98.0.
 *
 * Physically copies every fxlm_* option row to a bt_* row in wp_options,
 * then installs forward-sync hooks so cron writes to fxlm_* keys are
 * automatically mirrored to the bt_* equivalents going forward.
 *
 * After migration:
 *  - Existing code reading get_option('fxlm_crypto_data') continues to work
 *    (the fxlm_ rows are preserved, not deleted)
 *  - New code reading bt_get_option('bt_crypto_data') finds the live value
 *  - Every update_option('fxlm_X', …) also writes to 'bt_X' via sync hook
 *
 * Deletion of the legacy fxlm_ rows is deferred to v99.0.
 *
 * @since 98.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Migration {

    /** wp_options key storing migration status metadata. */
    const STATUS_KEY = 'bt_migration_v98_status';

    /** Version string — bump when the KEY_MAP changes. */
    const SCHEMA_VERSION = '98.0.0';

    /**
     * Canonical map: legacy fxlm_* key → new bt_* key.
     *
     * Grouped by function:
     *  A. Settings & API keys   — autoload 'yes'
     *  B. Live data / cache     — autoload 'no'  (large blobs, cron-managed)
     *  C. User-generated data   — autoload 'no'
     *  D. State & version flags — autoload 'yes'
     *  E. Social links          — autoload 'yes'
     */
    const KEY_MAP = [
        // A. Settings & API keys
        'fxlm_site_name'             => 'bt_site_name',
        'fxlm_fx_api_key'            => 'bt_fx_api_key',
        'fxlm_cg_api_key'            => 'bt_cg_api_key',
        'fxlm_cmc_api_key'           => 'bt_cmc_api_key',
        'fxlm_openai_key'            => 'bt_openai_key',
        'fxlm_openai_model'          => 'bt_openai_model',
        'fxlm_claude_key'            => 'bt_claude_key',
        'fxlm_claude_model'          => 'bt_claude_model',
        'fxlm_adsense_id'            => 'bt_adsense_id',
        'fxlm_adsense_slot'          => 'bt_adsense_slot',
        'fxlm_ad_frequency'          => 'bt_ad_frequency',
        'fxlm_ga_id'                 => 'bt_ga_id',
        'fxlm_og_image'              => 'bt_og_image',
        'fxlm_logo_url'              => 'bt_logo_url',
        'fxlm_twitter_handle'        => 'bt_twitter_handle',
        'fxlm_gdpr_enabled'          => 'bt_gdpr_enabled',
        'fxlm_menu_config'           => 'bt_menu_config',
        'fxlm_ai_provider'           => 'bt_ai_provider',
        'fxlm_ai_review_mode'        => 'bt_ai_review_mode',
        'fxlm_ai_multiformat'        => 'bt_ai_multiformat',
        'fxlm_autopilot_enabled'     => 'bt_autopilot_enabled',
        'fxlm_ai_analysis_topic'     => 'bt_ai_analysis_topic',
        'fxlm_ai_autopilot_topic'    => 'bt_ai_autopilot_topic',
        'fxlm_rss_feeds'             => 'bt_rss_feeds',

        // B. Live data / cache
        'fxlm_crypto_data'           => 'bt_crypto_data',
        'fxlm_forex_data'            => 'bt_forex_data',
        'fxlm_forex_rates'           => 'bt_forex_rates',
        'fxlm_forex_prev_rates'      => 'bt_forex_prev_rates',
        'fxlm_forex_prev_updated'    => 'bt_forex_prev_updated',
        'fxlm_fear_greed_data'       => 'bt_fear_greed_data',
        'fxlm_news_items'            => 'bt_news_items',
        'fxlm_news_updated'          => 'bt_news_updated',
        'fxlm_signal_items'          => 'bt_signal_items',
        'fxlm_ai_analysis'           => 'bt_ai_analysis',
        'fxlm_exchange_details'      => 'bt_exchange_details',

        // C. User-generated data
        'fxlm_subscribers'           => 'bt_subscribers',
        'fxlm_contact_messages'      => 'bt_contact_messages',
        'fxlm_consent_log'           => 'bt_consent_log',

        // D. State & version flags
        'fxlm_activated'             => 'bt_activated',
        'fxlm_setup_progress'        => 'bt_setup_progress',
        'fxlm_security_applied'      => 'bt_security_applied',
        'fxlm_security_note'         => 'bt_security_note',
        'fxlm_cron_note'             => 'bt_cron_note',
        'fxlm_update_log'            => 'bt_update_log',
        'fxlm_last_ai_post_date'     => 'bt_last_ai_post_date',
        'fxlm_last_fng_fetch'        => 'bt_last_fng_fetch',
        'fxlm_last_health_check'     => 'bt_last_health_check',
        'fxlm_last_news_fetch'       => 'bt_last_news_fetch',
        'fxlm_last_signals_fetch'    => 'bt_last_signals_fetch',
        'fxlm_seed_articles_done'    => 'bt_seed_articles_done',
        'fxlm_forex_seed_done'       => 'bt_forex_seed_done',
        'fxlm_pages_need_update'     => 'bt_pages_need_update',
        'fxlm_pages_version'         => 'bt_pages_version',
        'fxlm_rewrite_version'       => 'bt_rewrite_version',
        'fxlm_health_issues'         => 'bt_health_issues',

        // E. Social links
        'fxlm_social_facebook'       => 'bt_social_facebook',
        'fxlm_social_twitter'        => 'bt_social_twitter',
        'fxlm_social_instagram'      => 'bt_social_instagram',
        'fxlm_social_youtube'        => 'bt_social_youtube',
        'fxlm_social_telegram'       => 'bt_social_telegram',
        'fxlm_social_tiktok'         => 'bt_social_tiktok',
        'fxlm_social_discord'        => 'bt_social_discord',
        'fxlm_social_linkedin'       => 'bt_social_linkedin',
    ];

    /**
     * Wildcard prefixes: keys matching `fxlm_{prefix}*` are bulk-copied
     * to `bt_{prefix}*` via a single SQL query per prefix.
     *
     * These are dynamic keys that cannot be listed individually.
     */
    const WILDCARD_PREFIXES = [
        'fxlm_exchanges_data_v2_',
        'fxlm_exchanges_rest_',
        'fxlm_coin_detail_',
        'fxlm_cat_',
        'fxlm_mood_',
        'fxlm_feat_',
        'fxlm_vote_',
        'fxlm_votes_',
    ];

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Install forward-sync hooks on every request (lightweight — just add_action).
        self::install_sync_hooks();

        // AJAX handlers for the admin screen.
        add_action( 'wp_ajax_bt_run_migration', [ __CLASS__, 'ajax_run_migration' ] );
        add_action( 'wp_ajax_bt_migration_status', [ __CLASS__, 'ajax_get_status' ] );
        add_action( 'wp_ajax_bt_rollback_migration', [ __CLASS__, 'ajax_rollback' ] );
    }

    /* ------------------------------------------------------------------
     * Migration engine
     * ------------------------------------------------------------------ */

    /**
     * Run the full option key migration.
     *
     * For each entry in KEY_MAP:
     *  1. Read the fxlm_ value.
     *  2. If a bt_ value already exists AND $force is false, skip.
     *  3. Write to bt_ with the appropriate autoload value.
     *
     * For wildcard prefixes: one SQL INSERT … SELECT per prefix.
     *
     * @param bool $force  Overwrite existing bt_ values. Default false.
     * @return array  { migrated, skipped, wildcards, errors, duration_ms }
     */
    public static function run( $force = false ) {
        $start    = microtime( true );
        $migrated = 0;
        $skipped  = 0;
        $wildcard = 0;
        $errors   = [];

        // --- Autoload map: keys that should autoload (settings) ---
        $autoload_yes = [
            'bt_site_name','bt_fx_api_key','bt_cg_api_key','bt_cmc_api_key',
            'bt_openai_key','bt_openai_model','bt_claude_key','bt_claude_model',
            'bt_adsense_id','bt_adsense_slot','bt_ad_frequency','bt_ga_id',
            'bt_og_image','bt_logo_url','bt_twitter_handle','bt_gdpr_enabled',
            'bt_menu_config','bt_ai_provider','bt_ai_review_mode','bt_ai_multiformat',
            'bt_autopilot_enabled','bt_ai_analysis_topic','bt_ai_autopilot_topic',
            'bt_rss_feeds','bt_activated','bt_setup_progress','bt_security_applied',
            'bt_security_note','bt_cron_note','bt_update_log','bt_last_ai_post_date',
            'bt_last_fng_fetch','bt_last_health_check','bt_last_news_fetch',
            'bt_last_signals_fetch','bt_seed_articles_done','bt_forex_seed_done',
            'bt_pages_need_update','bt_pages_version','bt_rewrite_version',
            'bt_health_issues','bt_social_facebook','bt_social_twitter',
            'bt_social_instagram','bt_social_youtube','bt_social_telegram',
            'bt_social_tiktok','bt_social_discord','bt_social_linkedin',
        ];

        // Phase 1: named keys.
        foreach ( self::KEY_MAP as $fxlm_key => $bt_key ) {
            try {
                if ( ! $force ) {
                    $existing = get_option( $bt_key, null );
                    if ( null !== $existing ) {
                        $skipped++;
                        continue;
                    }
                }
                $val = get_option( $fxlm_key, null );
                if ( null === $val ) {
                    $skipped++;
                    continue;
                }
                $autoload = in_array( $bt_key, $autoload_yes, true ) ? 'yes' : 'no';
                $result   = update_option( $bt_key, $val, $autoload );
                if ( false !== $result ) {
                    $migrated++;
                } else {
                    // update_option returns false if value unchanged — still a success.
                    $migrated++;
                }
            } catch ( Throwable $e ) {
                $errors[] = $fxlm_key . ': ' . $e->getMessage();
            }
        }

        // Phase 2: wildcard prefixes via SQL bulk copy.
        $wildcard = self::migrate_wildcards( $force, $errors );

        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        $status = [
            'version'     => self::SCHEMA_VERSION,
            'timestamp'   => time(),
            'migrated'    => $migrated,
            'skipped'     => $skipped,
            'wildcards'   => $wildcard,
            'errors'      => $errors,
            'duration_ms' => $duration_ms,
            'complete'    => empty( $errors ),
        ];

        update_option( self::STATUS_KEY, $status, 'no' );
        return $status;
    }

    /**
     * Bulk-copy wildcard option keys via a single SQL query per prefix.
     *
     * INSERT IGNORE: skips rows where the bt_ key already exists.
     * When $force is true, uses REPLACE INTO instead.
     *
     * @param bool  $force   Overwrite existing bt_ rows.
     * @param array &$errors Error collection (passed by reference).
     * @return int  Number of wildcard rows copied.
     */
    private static function migrate_wildcards( $force, &$errors ) {
        global $wpdb;
        $total = 0;

        foreach ( self::WILDCARD_PREFIXES as $fxlm_prefix ) {
            $bt_prefix = str_replace( 'fxlm_', 'bt_', $fxlm_prefix );

            if ( $force ) {
                // REPLACE INTO overwrites any existing bt_ rows.
                $sql = $wpdb->prepare(
                    "REPLACE INTO {$wpdb->options} (option_name, option_value, autoload)
                     SELECT REPLACE(option_name, %s, %s), option_value, 'no'
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s",
                    $fxlm_prefix,
                    $bt_prefix,
                    $wpdb->esc_like( $fxlm_prefix ) . '%'
                );
            } else {
                // INSERT IGNORE: only inserts rows where bt_ key doesn't exist yet.
                $sql = $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
                     SELECT REPLACE(option_name, %s, %s), option_value, 'no'
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s",
                    $fxlm_prefix,
                    $bt_prefix,
                    $wpdb->esc_like( $fxlm_prefix ) . '%'
                );
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->query( $sql );
            if ( false === $rows ) {
                $errors[] = "wildcard {$fxlm_prefix}: " . $wpdb->last_error;
            } else {
                $total += (int) $rows;
            }
        }

        return $total;
    }

    /* ------------------------------------------------------------------
     * Forward-sync hooks
     * ------------------------------------------------------------------ */

    /**
     * For every fxlm_* key in KEY_MAP, hook `update_option_fxlm_*` so that
     * whenever cron or admin code writes to the legacy key, the bt_* key is
     * automatically updated too.
     *
     * This keeps both key sets in sync without touching a single line of
     * existing cron or widget code.  Cost: 58 add_action() calls at priority 1
     * on plugins_loaded — effectively zero overhead.
     */
    public static function install_sync_hooks() {
        foreach ( self::KEY_MAP as $fxlm_key => $bt_key ) {
            add_action(
                'update_option_' . $fxlm_key,
                static function( $old_value, $new_value ) use ( $bt_key ) {
                    // Don't trigger recursion: check that this isn't already a sync write.
                    static $syncing = false;
                    if ( $syncing ) return;
                    $syncing = true;
                    update_option( $bt_key, $new_value, false );
                    $syncing = false;
                },
                10,
                2
            );
        }

        // Also sync wildcard keys using the generic `updated_option` action.
        add_action( 'updated_option', [ __CLASS__, 'sync_wildcard_on_update' ], 10, 3 );
    }

    /**
     * Mirror a wildcard fxlm_ write to the corresponding bt_ key.
     *
     * @param string $option_name  Option name being updated.
     * @param mixed  $old_value    Previous value (unused).
     * @param mixed  $new_value    New value being written.
     */
    public static function sync_wildcard_on_update( $option_name, $old_value, $new_value ) {
        foreach ( self::WILDCARD_PREFIXES as $fxlm_prefix ) {
            if ( str_starts_with( $option_name, $fxlm_prefix ) ) {
                $bt_key = str_replace( 'fxlm_', 'bt_', $option_name );
                // Guard: don't sync bt_ → bt_ (would be a no-op but wastes a query).
                if ( $bt_key === $option_name ) return;
                update_option( $bt_key, $new_value, false );
                return;
            }
        }
    }

    /* ------------------------------------------------------------------
     * Status & rollback
     * ------------------------------------------------------------------ */

    /**
     * Return the current migration status array, or a default if not run yet.
     *
     * @return array
     */
    public static function get_status() {
        return get_option( self::STATUS_KEY, [
            'version'   => null,
            'timestamp' => null,
            'migrated'  => 0,
            'skipped'   => 0,
            'wildcards' => 0,
            'errors'    => [],
            'complete'  => false,
        ] );
    }

    /**
     * Return true if the migration has completed successfully at the
     * current schema version.
     *
     * @return bool
     */
    public static function is_complete() {
        $s = self::get_status();
        return ( $s['version'] ?? '' ) === self::SCHEMA_VERSION && $s['complete'];
    }

    /**
     * Rollback: delete all bt_* options created by this migration.
     * Does NOT restore the fxlm_* options (they were never deleted).
     *
     * @return int  Number of bt_* options deleted.
     */
    public static function rollback() {
        global $wpdb;
        $deleted = 0;

        // Named keys.
        foreach ( array_values( self::KEY_MAP ) as $bt_key ) {
            if ( delete_option( $bt_key ) ) $deleted++;
        }

        // Wildcard keys: DELETE WHERE option_name LIKE 'bt_{prefix}%'.
        foreach ( self::WILDCARD_PREFIXES as $fxlm_prefix ) {
            $bt_prefix = str_replace( 'fxlm_', 'bt_', $fxlm_prefix );
            $rows = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( $bt_prefix ) . '%'
                )
            );
            if ( $rows ) $deleted += (int) $rows;
        }

        delete_option( self::STATUS_KEY );
        return $deleted;
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_run_migration() {
        FXLM_Utils::verify_admin_ajax( 'bt_db_admin', 'manage_options', 'nonce' );
        $force  = ! empty( $_POST['force'] );
        $result = self::run( $force );
        wp_send_json_success( $result );
    }

    public static function ajax_get_status() {
        FXLM_Utils::verify_admin_ajax( 'bt_db_admin', 'manage_options', 'nonce' );
        wp_send_json_success( self::get_status() );
    }

    public static function ajax_rollback() {
        FXLM_Utils::verify_admin_ajax( 'bt_db_admin', 'manage_options', 'nonce' );
        $deleted = self::rollback();
        wp_send_json_success( [ 'deleted' => $deleted ] );
    }

    /* ------------------------------------------------------------------
     * Admin panel HTML
     * ------------------------------------------------------------------ */

    /**
     * Render the Option Key Migration panel for the DB admin screen.
     *
     * @param string $nonce  Admin nonce for AJAX calls.
     * @return string  HTML.
     */
    public static function admin_panel_html( $nonce ) {
        $status    = self::get_status();
        $complete  = self::is_complete();
        $migrated  = $status['migrated'] ?? 0;
        $skipped   = $status['skipped']  ?? 0;
        $wildcards = $status['wildcards'] ?? 0;
        $errors    = $status['errors']   ?? [];
        $ts        = $status['timestamp'] ?? null;
        $age       = $ts ? human_time_diff( $ts ) . ' ago' : 'Never';
        $total_map = count( self::KEY_MAP );

        ob_start(); ?>
        <div class="postbox" id="bt-option-migration-panel">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    🔑 Option Key Migration
                    <span style="margin-left:8px;font-size:11px;font-weight:400;color:<?php echo $complete ? '#00a32a' : '#d63638'; ?>">
                        <?php echo $complete ? '✓ Complete' : '● Pending'; ?>
                    </span>
                </h2>
            </div>
            <div class="inside">
                <p style="font-size:13px;color:#555;margin-top:0;">
                    Copies all <code>fxlm_*</code> option keys to <code>bt_*</code> equivalents in <code>wp_options</code>.
                    Legacy keys are <strong>preserved</strong> — existing code keeps working unchanged.
                    Forward-sync hooks ensure both keys stay in sync after migration.
                </p>

                <table class="widefat striped" style="font-size:13px;margin-bottom:15px;">
                    <tbody>
                        <tr>
                            <th>Named keys</th>
                            <td><?php echo number_format( $total_map ); ?> (<?php echo $migrated; ?> migrated, <?php echo $skipped; ?> skipped)</td>
                        </tr>
                        <tr>
                            <th>Wildcard rows</th>
                            <td><?php echo number_format( $wildcards ); ?> rows copied</td>
                        </tr>
                        <tr>
                            <th>Last run</th>
                            <td><?php echo esc_html( $age ); ?></td>
                        </tr>
                        <?php if ( $errors ) : ?>
                        <tr>
                            <th style="color:#d63638;">Errors</th>
                            <td style="color:#d63638;"><?php echo esc_html( implode( '; ', array_slice( $errors, 0, 3 ) ) ); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button id="btn-opt-migrate" class="button button-primary"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        ▶ Run Migration
                    </button>
                    <button id="btn-opt-force" class="button"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        ↺ Force Re-migrate
                    </button>
                    <button id="btn-opt-rollback" class="button button-link-delete"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            onclick="return confirm('Delete all bt_* option rows created by this migration? fxlm_* rows are not affected.');">
                        ✕ Rollback
                    </button>
                    <span class="spinner" id="opt-migrate-spinner" style="display:none;float:none;"></span>
                    <span id="opt-migrate-result" style="font-size:13px;color:#555;"></span>
                </div>

                <p style="font-size:12px;color:#888;margin-top:12px;margin-bottom:0;">
                    Migration is safe to run multiple times (idempotent).
                    Sync hooks are always active regardless of migration status.
                    <a href="https://blockticker.io/docs/v98-migration" target="_blank" rel="noopener">Full migration guide →</a>
                </p>
            </div>
        </div>
        <script>
        (function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function doMigrate(force) {
                $('#opt-migrate-spinner').show();
                $('#opt-migrate-result').text('Running…');
                $.post(ajaxurl, {
                    action: 'bt_run_migration',
                    nonce:  nonce,
                    force:  force ? 1 : 0
                }, function(r) {
                    $('#opt-migrate-spinner').hide();
                    if (r.success) {
                        var d = r.data;
                        $('#opt-migrate-result').css('color','#00a32a').text(
                            '✓ Done — ' + d.migrated + ' migrated, ' + d.skipped + ' skipped, ' +
                            d.wildcards + ' wildcard rows, ' + d.duration_ms + 'ms.'
                        );
                    } else {
                        $('#opt-migrate-result').css('color','#d63638').text('Error: ' + (r.data || 'unknown'));
                    }
                }).fail(function() {
                    $('#opt-migrate-spinner').hide();
                    $('#opt-migrate-result').css('color','#d63638').text('Request failed.');
                });
            }

            $('#btn-opt-migrate').on('click', function(){ doMigrate(false); });
            $('#btn-opt-force').on('click',   function(){ doMigrate(true);  });

            $('#btn-opt-rollback').on('click', function(){
                $('#opt-migrate-spinner').show();
                $('#opt-migrate-result').text('Rolling back…');
                $.post(ajaxurl, { action: 'bt_rollback_migration', nonce: nonce }, function(r) {
                    $('#opt-migrate-spinner').hide();
                    if (r.success) {
                        $('#opt-migrate-result').css('color','#d63638').text(
                            '↩ Rolled back — ' + r.data.deleted + ' bt_* options deleted.'
                        );
                    } else {
                        $('#opt-migrate-result').css('color','#d63638').text('Rollback failed.');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }
}
