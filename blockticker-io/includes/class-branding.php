<?php
/**
 * BlockTicker — Site Branding (v119.25.0)
 *
 * Handles uploaded site logo + favicon configuration. Operates in two layers:
 *
 *   1. Settings storage  — bt_site_logo, bt_site_favicon options. Both are
 *                          attachment IDs from the Media Library (preferred)
 *                          OR full URLs (fallback). Storing IDs is better
 *                          because we can derive multiple sizes for retina /
 *                          OG / etc., but URL fallback covers users who paste
 *                          a CDN URL directly.
 *
 *   2. Front-of-site injection
 *        - Favicon → wp_head: link rel="icon" + apple-touch-icon. Honours
 *          WP's get_site_icon_url() if the user already set the core
 *          Customizer icon. We only inject if our own option is set.
 *        - Logo  → exposed via BT_Branding::get_logo_url() and
 *          [bt_site_logo] shortcode for theme/builder reuse.
 *
 * @package BlockTicker
 * @since   119.25.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Branding {

    const OPT_LOGO    = 'bt_site_logo';
    const OPT_FAVICON = 'bt_site_favicon';

    public static function init() {
        add_action( 'wp_head',   array( __CLASS__, 'inject_favicon' ), 5 );
        add_action( 'admin_head',array( __CLASS__, 'inject_favicon' ), 5 );
        add_shortcode( 'bt_site_logo', array( __CLASS__, 'sc_logo' ) );

        // Make sure the WP media library scripts are available on the
        // Credentials page so the upload buttons we render can use them.
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media_lib' ) );
    }

    public static function enqueue_media_lib( $hook ) {
        // Only on our own admin pages.
        if ( strpos( (string) $hook, 'bt-' ) === false &&
             strpos( (string) $hook, 'fxlm-' ) === false ) return;
        wp_enqueue_media();
    }

    /**
     * Resolve a stored value (attachment ID or URL) → URL string.
     *
     * @param mixed  $stored  Option value: numeric ID or URL or empty.
     * @param string $size    WP image size when stored is an attachment ID.
     * @return string  URL or '' when not configured.
     */
    public static function resolve_url( $stored, $size = 'full' ) {
        if ( empty( $stored ) ) return '';
        if ( is_numeric( $stored ) ) {
            $url = wp_get_attachment_image_url( (int) $stored, $size );
            return $url ?: '';
        }
        return esc_url_raw( (string) $stored );
    }

    /** @return string Logo URL or '' if not set. */
    public static function get_logo_url( $size = 'full' ) {
        return self::resolve_url( get_option( self::OPT_LOGO ), $size );
    }

    /** @return string Favicon URL or '' if not set. */
    public static function get_favicon_url() {
        $url = self::resolve_url( get_option( self::OPT_FAVICON ), 'full' );
        if ( ! $url ) {
            // Fall back to WP core's site_icon if the user set it via Customizer.
            $url = get_site_icon_url( 192 );
        }
        return $url;
    }

    /**
     * Inject favicon link tags into <head>. Skips if neither our option nor
     * the WP core site_icon is set, and skips if the active theme already
     * renders <link rel="icon"> via wp_site_icon() (we don't want duplicates).
     */
    public static function inject_favicon() {
        // If the WP core site icon is active, it's already in the head via
        // wp_site_icon() — don't double up.
        if ( has_site_icon() ) return;

        $url = self::resolve_url( get_option( self::OPT_FAVICON ), 'full' );
        if ( ! $url ) return;
        ?>
        <link rel="icon"             href="<?php echo esc_url( $url ); ?>" />
        <link rel="shortcut icon"    href="<?php echo esc_url( $url ); ?>" />
        <link rel="apple-touch-icon" href="<?php echo esc_url( $url ); ?>" />
        <?php
    }

    /**
     * [bt_site_logo size="full" alt="Site logo" link="home"]
     * Renders the configured site logo as an <img>, optionally wrapped in
     * a link to the homepage.
     */
    public static function sc_logo( $atts ) {
        $a = shortcode_atts( array(
            'size' => 'full',
            'alt'  => get_bloginfo( 'name' ),
            'link' => 'home',
            'class'=> 'bt-site-logo',
            'max_height' => '40',
        ), $atts );

        $url = self::get_logo_url( $a['size'] );
        if ( ! $url ) return '';

        $img = sprintf(
            '<img src="%1$s" alt="%2$s" class="%3$s" style="max-height:%4$dpx;width:auto;display:block" />',
            esc_url( $url ),
            esc_attr( $a['alt'] ),
            esc_attr( $a['class'] ),
            (int) $a['max_height']
        );

        if ( $a['link'] === 'home' ) {
            $img = sprintf( '<a href="%s" rel="home">%s</a>', esc_url( home_url( '/' ) ), $img );
        }
        return $img;
    }

    // ── Admin page: Site Branding ────────────────────────────────────────────

    /**
     * Render the Site Branding panel — embedded inside the Credentials page
     * via BT_Admin::render_credentials() or accessible standalone.
     */
    public static function render_panel() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Save handler — separate POST flag so it doesn't conflict with the
        // credentials form on the same page.
        if ( isset( $_POST['bt_save_branding'] ) && check_admin_referer( 'bt_branding' ) ) {
            $logo_id    = isset( $_POST['bt_site_logo_id'] )    ? absint( $_POST['bt_site_logo_id'] )    : 0;
            $logo_url   = isset( $_POST['bt_site_logo_url'] )   ? esc_url_raw( $_POST['bt_site_logo_url'] )   : '';
            $favicon_id = isset( $_POST['bt_site_favicon_id'] ) ? absint( $_POST['bt_site_favicon_id'] ) : 0;
            $favicon_url= isset( $_POST['bt_site_favicon_url'] )? esc_url_raw( $_POST['bt_site_favicon_url'] ): '';

            // Prefer attachment IDs (better — derivative sizes available).
            // Fall back to URL when the operator pasted an external CDN URL.
            $logo_value    = $logo_id    > 0 ? $logo_id    : $logo_url;
            $favicon_value = $favicon_id > 0 ? $favicon_id : $favicon_url;

            update_option( self::OPT_LOGO,    $logo_value );
            update_option( self::OPT_FAVICON, $favicon_value );

            echo '<div class="notice notice-success is-dismissible"><p>Branding saved. Refresh the front of site to see the new logo / favicon.</p></div>';
        }

        $logo_stored    = get_option( self::OPT_LOGO,    '' );
        $favicon_stored = get_option( self::OPT_FAVICON, '' );
        $logo_url       = self::resolve_url( $logo_stored,    'medium' );
        $favicon_url    = self::resolve_url( $favicon_stored, 'thumbnail' );
        $logo_id        = is_numeric( $logo_stored )    ? (int) $logo_stored    : 0;
        $favicon_id     = is_numeric( $favicon_stored ) ? (int) $favicon_stored : 0;
        ?>
        <div class="bt-branding-panel" style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:18px 22px;margin-bottom:16px">
            <h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#1d2327">🎨 Site Branding</h2>
            <p style="margin:0 0 16px;font-size:13px;color:#646970">Configure your site logo and favicon. Both render across the public site and admin tabs. PNG or SVG recommended for logo; ICO / PNG for favicon (any square ratio).</p>

            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field( 'bt_branding' ); ?>

                <!-- Logo row -->
                <div style="display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start;padding:14px 0;border-top:1px solid #f0f0f0">
                    <label style="font-weight:600;font-size:13px;padding-top:7px">Site Logo<br>
                        <span style="font-weight:400;font-size:11px;color:#646970">PNG / SVG · Wide aspect (3:1) ideal</span>
                    </label>
                    <div>
                        <div class="bt-brand-preview" id="bt-logo-preview" style="margin-bottom:10px;min-height:60px;padding:14px;border:1px dashed #ccc;background:#fafafa;display:flex;align-items:center;justify-content:flex-start">
                            <?php if ( $logo_url ) : ?>
                                <img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:60px;max-width:280px;display:block">
                            <?php else : ?>
                                <span style="color:#888;font-size:12px;font-style:italic">No logo set</span>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" id="bt_site_logo_id"  name="bt_site_logo_id"  value="<?php echo esc_attr( $logo_id ); ?>">
                        <input type="url"    id="bt_site_logo_url" name="bt_site_logo_url" value="<?php echo esc_attr( is_numeric( $logo_stored ) ? '' : $logo_stored ); ?>" placeholder="Or paste a URL (CDN, S3, etc.)" class="regular-text" style="width:100%;max-width:500px">
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button type="button" class="button bt-media-upload" data-target="bt_site_logo_id" data-preview="bt-logo-preview" data-title="Choose Site Logo">📁 Select from Media Library</button>
                            <?php if ( $logo_stored ) : ?>
                            <button type="button" class="button" id="bt-clear-logo">Clear</button>
                            <?php endif; ?>
                        </div>
                        <p class="description" style="margin:6px 0 0;font-size:12px;color:#646970">Used in the site header (theme template), email digests, and OG meta. Stored as a Media Library attachment when uploaded; URL field is a fallback for external hosts.</p>
                    </div>
                </div>

                <!-- Favicon row -->
                <div style="display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start;padding:14px 0;border-top:1px solid #f0f0f0">
                    <label style="font-weight:600;font-size:13px;padding-top:7px">Favicon<br>
                        <span style="font-weight:400;font-size:11px;color:#646970">Square · 192×192 px or larger</span>
                    </label>
                    <div>
                        <div class="bt-brand-preview" id="bt-favicon-preview" style="margin-bottom:10px;padding:14px;border:1px dashed #ccc;background:#fafafa;display:flex;align-items:center;gap:14px">
                            <?php if ( $favicon_url ) : ?>
                                <img src="<?php echo esc_url( $favicon_url ); ?>" alt="" style="width:32px;height:32px;display:block;background:#fff;border-radius:6px">
                                <img src="<?php echo esc_url( $favicon_url ); ?>" alt="" style="width:16px;height:16px;display:block;background:#fff;border-radius:3px">
                                <span style="font-size:11px;color:#888">32 px / 16 px tab preview</span>
                            <?php else : ?>
                                <span style="color:#888;font-size:12px;font-style:italic">No favicon set — browsers will show a default icon</span>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" id="bt_site_favicon_id"  name="bt_site_favicon_id"  value="<?php echo esc_attr( $favicon_id ); ?>">
                        <input type="url"    id="bt_site_favicon_url" name="bt_site_favicon_url" value="<?php echo esc_attr( is_numeric( $favicon_stored ) ? '' : $favicon_stored ); ?>" placeholder="Or paste a URL" class="regular-text" style="width:100%;max-width:500px">
                        <div style="margin-top:8px;display:flex;gap:8px">
                            <button type="button" class="button bt-media-upload" data-target="bt_site_favicon_id" data-preview="bt-favicon-preview" data-title="Choose Favicon">📁 Select from Media Library</button>
                            <?php if ( $favicon_stored ) : ?>
                            <button type="button" class="button" id="bt-clear-favicon">Clear</button>
                            <?php endif; ?>
                        </div>
                        <p class="description" style="margin:6px 0 0;font-size:12px;color:#646970">If you've already set the WordPress core "Site Icon" via Appearance → Customize, leave this blank — that one takes precedence.</p>
                    </div>
                </div>

                <p style="margin-top:18px">
                    <button type="submit" name="bt_save_branding" class="button button-primary button-large">Save Branding</button>
                </p>
            </form>
        </div>

        <script>
        jQuery(function($){
            // Generic Media Library picker — wires every .bt-media-upload button.
            $('.bt-media-upload').on('click', function(e){
                e.preventDefault();
                var btn      = $(this);
                var targetId = btn.data('target');
                var preview  = btn.data('preview');
                var title    = btn.data('title') || 'Select Image';

                var frame = wp.media({
                    title: title,
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#' + targetId).val(att.id);
                    // Replace preview with the chosen image
                    var $pv = $('#' + preview);
                    var imgURL = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                    $pv.html('<img src="' + imgURL + '" alt="" style="max-height:60px;max-width:280px;display:block">');
                    // Clear any URL-fallback field paired with this hidden ID
                    var urlField = targetId.replace(/_id$/, '_url');
                    $('#' + urlField).val('');
                });
                frame.open();
            });

            // Clear buttons
            $('#bt-clear-logo').on('click', function(){
                $('#bt_site_logo_id').val('0');
                $('#bt_site_logo_url').val('');
                $('#bt-logo-preview').html('<span style="color:#888;font-size:12px;font-style:italic">No logo set</span>');
            });
            $('#bt-clear-favicon').on('click', function(){
                $('#bt_site_favicon_id').val('0');
                $('#bt_site_favicon_url').val('');
                $('#bt-favicon-preview').html('<span style="color:#888;font-size:12px;font-style:italic">No favicon set</span>');
            });
        });
        </script>
        <?php
    }
}
