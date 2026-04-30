<?php
/**
 * BT_UserAuth — User Accounts: Register, Login, Portfolio & Watchlist Cloud Sync
 *
 * - Login/Register modal (AJAX, stays on page, no redirect)
 * - Portfolio stored in user_meta (logged in) or localStorage (guest)
 * - Watchlist stored in user_meta (logged in) or localStorage (guest)
 * - Sync localStorage → user_meta on login
 * - REST endpoints for portfolio/watchlist CRUD
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_UserAuth {

    const META_PORTFOLIO = 'bt_portfolio';
    const META_WATCHLIST = 'bt_watchlist';

    public static function init() {
        // REST endpoints
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        // AJAX handlers
        add_action( 'wp_ajax_nopriv_bt_auth_login',     array( __CLASS__, 'ajax_login' ) );
        add_action( 'wp_ajax_nopriv_bt_auth_register',  array( __CLASS__, 'ajax_register' ) );
        add_action( 'wp_ajax_bt_auth_logout',           array( __CLASS__, 'ajax_logout' ) );
        // v67: Fresh nonce endpoint — auth nonces are session-tied; after a
        // logout the inline nonce baked into the page is invalid. JS hits this
        // right before submit to get a usable nonce.
        add_action( 'wp_ajax_bt_auth_nonce',            array( __CLASS__, 'ajax_get_auth_nonce' ) );
        add_action( 'wp_ajax_nopriv_bt_auth_nonce',     array( __CLASS__, 'ajax_get_auth_nonce' ) );
        // Inject auth modal + navbar auth JS
        add_action( 'wp_footer', array( __CLASS__, 'render_auth_modal' ), 15 );
        // Shortcodes for full-page views
        add_shortcode( 'bt_portfolio_page',  array( __CLASS__, 'sc_portfolio_page' ) );
        add_shortcode( 'bt_watchlist_page',  array( __CLASS__, 'sc_watchlist_page' ) );
        add_shortcode( 'bt_watchlist_v2',     array( __CLASS__, 'sc_watchlist_v2' ) );  // v110.0
    }

    // ── REST ROUTES ──────────────────────────────────────────────────────────
    public static function register_rest_routes() {
        $ns = 'blockticker/v1';

        // Portfolio
        register_rest_route( $ns, '/user/portfolio', array(
            array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'rest_get_portfolio' ),  'permission_callback' => array( __CLASS__, 'is_logged_in' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_portfolio' ), 'permission_callback' => array( __CLASS__, 'is_logged_in' ) ),
        ) );
        // Watchlist
        register_rest_route( $ns, '/user/watchlist', array(
            array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'rest_get_watchlist' ),  'permission_callback' => array( __CLASS__, 'is_logged_in' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_watchlist' ), 'permission_callback' => array( __CLASS__, 'is_logged_in' ) ),
        ) );
        // Auth status
        register_rest_route( $ns, '/user/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_user_status' ),
            'permission_callback' => '__return_true',
        ) );
        // Google OAuth
        register_rest_route( $ns, '/auth/google', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_google_redirect' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( $ns, '/auth/google/callback', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_google_callback' ),
            'permission_callback' => '__return_true',
        ) );
        // GitHub OAuth
        register_rest_route( $ns, '/auth/github', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_github_redirect' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( $ns, '/auth/github/callback', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_github_callback' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function is_logged_in() {
        return is_user_logged_in();
    }

    public static function rest_get_portfolio( $req ) {
        $user_id = get_current_user_id();
        $data = get_user_meta( $user_id, self::META_PORTFOLIO, true );
        return rest_ensure_response( array( 'holdings' => $data ? json_decode( $data, true ) : array() ) );
    }

    public static function rest_save_portfolio( $req ) {
        $user_id  = get_current_user_id();
        $holdings = $req->get_param( 'holdings' );
        if ( ! is_array( $holdings ) ) return new WP_Error( 'invalid', 'Invalid data', array( 'status' => 400 ) );
        // Sanitize each holding
        $clean = array();
        foreach ( $holdings as $h ) {
            if ( empty( $h['id'] ) ) continue;
            $clean[] = array(
                'id'      => sanitize_key( $h['id'] ),
                'qty'     => floatval( $h['qty'] ?? 0 ),
                'avgBuy'  => floatval( $h['avgBuy'] ?? 0 ),
                'addedAt' => intval( $h['addedAt'] ?? time() * 1000 ),
            );
        }
        update_user_meta( $user_id, self::META_PORTFOLIO, wp_json_encode( $clean ) );
        return rest_ensure_response( array( 'success' => true, 'count' => count( $clean ) ) );
    }

    public static function rest_get_watchlist( $req ) {
        $user_id = get_current_user_id();
        $data = get_user_meta( $user_id, self::META_WATCHLIST, true );
        return rest_ensure_response( array( 'watchlist' => $data ? json_decode( $data, true ) : array() ) );
    }

    public static function rest_save_watchlist( $req ) {
        $user_id   = get_current_user_id();
        $watchlist = $req->get_param( 'watchlist' );
        if ( ! is_array( $watchlist ) ) return new WP_Error( 'invalid', 'Invalid data', array( 'status' => 400 ) );
        $clean = array_map( 'sanitize_key', $watchlist );
        update_user_meta( $user_id, self::META_WATCHLIST, wp_json_encode( $clean ) );
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function rest_user_status( $req ) {
        if ( ! is_user_logged_in() ) {
            return rest_ensure_response( array( 'logged_in' => false ) );
        }
        $user = wp_get_current_user();
        return rest_ensure_response( array(
            'logged_in' => true,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'avatar'       => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    // ── GOOGLE OAUTH ─────────────────────────────────────────────────────────
    public static function rest_google_redirect( $req ) {
        $client_id   = get_option( 'bt_google_client_id', '' );
        if ( empty( $client_id ) ) {
            wp_redirect( home_url( '/?bt_auth_error=google_not_configured' ) );
            exit;
        }

        $state       = wp_create_nonce( 'bt_google_oauth' );
        $redirect_uri = rest_url( 'blockticker/v1/auth/google/callback' );
        $scope       = 'openid email profile';
        $return_to   = sanitize_url( $req->get_param( 'return_to' ) ?: home_url( '/' ) );

        // Store state + return_to in transient
        set_transient( 'bt_oauth_state_' . $state, $return_to, 10 * MINUTE_IN_SECONDS );

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ) );

        wp_redirect( $url );
        exit;
    }

    public static function rest_google_callback( $req ) {
        $code  = sanitize_text_field( $req->get_param( 'code' ) );
        $state = sanitize_text_field( $req->get_param( 'state' ) );
        $error = $req->get_param( 'error' );

        if ( $error ) {
            wp_redirect( home_url( '/?bt_auth_error=' . urlencode( $error ) ) );
            exit;
        }

        // Verify state
        $return_to = get_transient( 'bt_oauth_state_' . $state );
        if ( ! $return_to || ! wp_verify_nonce( $state, 'bt_google_oauth' ) ) {
            wp_redirect( home_url( '/?bt_auth_error=invalid_state' ) );
            exit;
        }
        delete_transient( 'bt_oauth_state_' . $state );

        // Exchange code for access token
        $client_id     = get_option( 'bt_google_client_id', '' );
        $client_secret = get_option( 'bt_google_client_secret', '' );
        $redirect_uri  = rest_url( 'blockticker/v1/auth/google/callback' );

        $token_resp = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body'    => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $token_resp ) ) {
            wp_redirect( home_url( '/?bt_auth_error=token_exchange_failed' ) );
            exit;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $access_token = $token_data['access_token'] ?? '';

        if ( empty( $access_token ) ) {
            wp_redirect( home_url( '/?bt_auth_error=no_access_token' ) );
            exit;
        }

        // Get user info from Google
        $user_resp = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
            'timeout' => 15,
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
        ) );

        if ( is_wp_error( $user_resp ) ) {
            wp_redirect( home_url( '/?bt_auth_error=userinfo_failed' ) );
            exit;
        }

        $google_user = json_decode( wp_remote_retrieve_body( $user_resp ), true );
        $email       = sanitize_email( $google_user['email'] ?? '' );
        $name        = sanitize_text_field( $google_user['name'] ?? '' );
        $google_id   = sanitize_text_field( $google_user['id'] ?? '' );
        $avatar_url  = esc_url_raw( $google_user['picture'] ?? '' );

        if ( ! is_email( $email ) ) {
            wp_redirect( home_url( '/?bt_auth_error=invalid_email' ) );
            exit;
        }

        // Find or create WP user
        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            // Create new account
            $username = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) . '.' . substr( md5( $email ), 0, 4 ) );
            while ( username_exists( $username ) ) { $username .= rand(1,9); }

            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 32, true ),
                'display_name' => $name,
                'role'         => 'subscriber',
            ) );

            if ( is_wp_error( $user_id ) ) {
                wp_redirect( home_url( '/?bt_auth_error=create_user_failed' ) );
                exit;
            }

            update_user_meta( $user_id, 'bt_google_id', $google_id );
            update_user_meta( $user_id, 'bt_google_avatar', $avatar_url );
            $user = get_user_by( 'id', $user_id );
        } else {
            // Update Google meta on existing user
            update_user_meta( $user->ID, 'bt_google_id', $google_id );
            update_user_meta( $user->ID, 'bt_google_avatar', $avatar_url );
        }

        // Log user in
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        // Redirect back with success flag so JS can sync localStorage
        $return_to = add_query_arg( 'bt_auth_success', '1', $return_to );
        wp_redirect( $return_to );
        exit;
    }

    // ── GITHUB OAUTH ─────────────────────────────────────────────────────────
    public static function rest_github_redirect( $req ) {
        $client_id = get_option( 'bt_github_client_id', '' );
        if ( empty( $client_id ) ) {
            wp_redirect( home_url( '/?bt_auth_error=github_not_configured' ) );
            exit;
        }
        $state      = wp_create_nonce( 'bt_github_oauth' );
        $return_to  = sanitize_url( $req->get_param( 'return_to' ) ?: home_url( '/' ) );
        set_transient( 'bt_oauth_state_' . $state, $return_to, 10 * MINUTE_IN_SECONDS );

        $url = 'https://github.com/login/oauth/authorize?' . http_build_query( array(
            'client_id' => $client_id,
            'scope'     => 'user:email',
            'state'     => $state,
        ) );
        wp_redirect( $url );
        exit;
    }

    public static function rest_github_callback( $req ) {
        $code  = sanitize_text_field( $req->get_param( 'code' ) );
        $state = sanitize_text_field( $req->get_param( 'state' ) );
        $error = $req->get_param( 'error' );

        if ( $error ) {
            wp_redirect( home_url( '/?bt_auth_error=' . urlencode( $error ) ) ); exit;
        }
        $return_to = get_transient( 'bt_oauth_state_' . $state );
        if ( ! $return_to || ! wp_verify_nonce( $state, 'bt_github_oauth' ) ) {
            wp_redirect( home_url( '/?bt_auth_error=invalid_state' ) ); exit;
        }
        delete_transient( 'bt_oauth_state_' . $state );

        // Exchange code for token
        $client_id     = get_option( 'bt_github_client_id', '' );
        $client_secret = get_option( 'bt_github_client_secret', '' );

        $token_resp = wp_remote_post( 'https://github.com/login/oauth/access_token', array(
            'timeout' => 20,
            'headers' => array( 'Accept' => 'application/json' ),
            'body'    => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => $code,
            ),
        ) );

        if ( is_wp_error( $token_resp ) ) {
            wp_redirect( home_url( '/?bt_auth_error=token_exchange_failed' ) ); exit;
        }

        $token_data   = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $access_token = $token_data['access_token'] ?? '';
        if ( empty( $access_token ) ) {
            wp_redirect( home_url( '/?bt_auth_error=no_access_token' ) ); exit;
        }

        // Get user info
        $user_resp = wp_remote_get( 'https://api.github.com/user', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'BlockTicker/1.0',
            ),
        ) );
        if ( is_wp_error( $user_resp ) ) {
            wp_redirect( home_url( '/?bt_auth_error=userinfo_failed' ) ); exit;
        }
        $gh_user   = json_decode( wp_remote_retrieve_body( $user_resp ), true );
        $github_id = intval( $gh_user['id'] ?? 0 );
        $name      = sanitize_text_field( $gh_user['name'] ?: $gh_user['login'] ?: 'GitHub User' );
        $avatar    = esc_url_raw( $gh_user['avatar_url'] ?? '' );
        $email     = sanitize_email( $gh_user['email'] ?? '' );

        // If no public email, fetch from /user/emails endpoint
        if ( ! is_email( $email ) ) {
            $email_resp = wp_remote_get( 'https://api.github.com/user/emails', array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'BlockTicker/1.0',
                ),
            ) );
            if ( ! is_wp_error( $email_resp ) ) {
                $emails = json_decode( wp_remote_retrieve_body( $email_resp ), true );
                foreach ( (array) $emails as $e ) {
                    if ( ! empty( $e['primary'] ) && ! empty( $e['verified'] ) ) {
                        $email = sanitize_email( $e['email'] );
                        break;
                    }
                }
            }
        }

        if ( ! is_email( $email ) ) {
            // Last resort: use github_id as email placeholder
            $email = 'github_' . $github_id . '@users.noreply.github.com';
        }

        // Find or create user
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            // Check if GitHub ID already linked
            $users = get_users( array( 'meta_key' => 'bt_github_id', 'meta_value' => $github_id, 'number' => 1 ) );
            if ( ! empty( $users ) ) {
                $user = $users[0];
            }
        }

        if ( ! $user ) {
            $username = sanitize_user( strtolower( $gh_user['login'] ?? 'gh_user' ) );
            $i = 0;
            while ( username_exists( $username ) ) { $username = sanitize_user( $gh_user['login'] ) . ( ++$i ); }
            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 32, true ),
                'display_name' => $name,
                'role'         => 'subscriber',
            ) );
            if ( is_wp_error( $user_id ) ) {
                wp_redirect( home_url( '/?bt_auth_error=create_user_failed' ) ); exit;
            }
            $user = get_user_by( 'id', $user_id );
        }

        update_user_meta( $user->ID, 'bt_github_id',     $github_id );
        update_user_meta( $user->ID, 'bt_github_avatar',  $avatar );

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        wp_redirect( add_query_arg( 'bt_auth_success', '1', $return_to ) );
        exit;
    }

    // ── AJAX LOGIN / REGISTER ─────────────────────────────────────────────────

    /**
     * v67: Return a fresh bt_auth nonce. Called by the modal JS right before
     * each submit so the nonce is always tied to the current session — fixes
     * "Security check failed" on first attempt after logout.
     */
    public static function ajax_get_auth_nonce() {
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'bt_auth' ) ) );
    }

    public static function ajax_login() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bt_auth' ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
        $email    = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';

        if ( ! is_email( $email ) || empty( $password ) ) {
            wp_send_json_error( 'Please enter your email and password.' );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            wp_send_json_error( 'No account found with that email address.' );
        }

        $result = wp_signon( array(
            'user_login'    => $user->user_login,
            'user_password' => $password,
            'remember'      => ! empty( $_POST['remember'] ),
        ), is_ssl() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Incorrect password. Please try again.' );
        }

        wp_send_json_success( array(
            'display_name' => $result->display_name,
            'avatar'       => get_avatar_url( $result->ID, array( 'size' => 32 ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'message'      => 'Welcome back, ' . $result->display_name . '!',
        ) );
    }

    public static function ajax_register() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bt_auth' ) ) {
            wp_send_json_error( 'Security check failed.' );
        }

        if ( ! get_option( 'users_can_register' ) ) {
            wp_send_json_error( 'Registration is currently disabled. Please contact the site administrator.' );
        }

        $name     = sanitize_text_field( $_POST['name']     ?? '' );
        $email    = sanitize_email(      $_POST['email']    ?? '' );
        $password = $_POST['password'] ?? '';

        if ( ! $name ) wp_send_json_error( 'Please enter your name.' );
        if ( ! is_email( $email ) ) wp_send_json_error( 'Please enter a valid email address.' );
        if ( strlen( $password ) < 8 ) wp_send_json_error( 'Password must be at least 8 characters.' );
        if ( email_exists( $email ) ) wp_send_json_error( 'An account with this email already exists. Please log in.' );

        $username = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) . '.' . substr( md5( $email ), 0, 4 ) );
        while ( username_exists( $username ) ) {
            $username .= rand( 1, 9 );
        }

        $user_id = wp_insert_user( array(
            'user_login'    => $username,
            'user_email'    => $email,
            'user_pass'     => $password,
            'display_name'  => $name,
            'role'          => 'subscriber',
        ) );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( 'Could not create account. Please try again.' );
        }

        // Auto-login
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        $user = get_user_by( 'id', $user_id );

        wp_send_json_success( array(
            'display_name' => $user->display_name,
            'avatar'       => get_avatar_url( $user_id, array( 'size' => 32 ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'message'      => 'Account created! Welcome, ' . $user->display_name . '.',
        ) );
    }

    public static function ajax_logout() {
        wp_logout();
        wp_send_json_success( 'Logged out.' );
    }

    // ── AUTH MODAL ────────────────────────────────────────────────────────────
    public static function render_auth_modal() {
        if ( is_admin() ) return;
        $nonce    = wp_create_nonce( 'bt_auth' );
        $ajax     = esc_url( admin_url( 'admin-ajax.php' ) );
        $rest_url = esc_url( rest_url( 'blockticker/v1/user/' ) );
        $reg_open = get_option( 'users_can_register' ) ? 'true' : 'false';
        
?>
        <!-- Auth Modal — matches CMC screenshot style -->
        <div id="bt-auth-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);align-items:center;justify-content:center">
            <div class="bt-auth-box">
                <!-- Tabs -->
                <div class="bt-auth-tabs">
                    <button id="bt-auth-tab-login"    class="bt-auth-tab active"    onclick="btAuthTab('login')" data-i18n="auth.login"><?php _ebt('auth.login'); ?></button>
                    <button id="bt-auth-tab-register" class="bt-auth-tab"           onclick="btAuthTab('register')" data-i18n="auth.signup"><?php _ebt('auth.signup'); ?></button>
                    <button onclick="btAuthClose()" class="bt-auth-close">&#x2715;</button>
                </div>

                <div class="bt-auth-body">
                    <!-- Social login buttons -->
                    <?php
                    $google_id    = get_option( 'bt_google_client_id', '' );
                    $github_id    = get_option( 'bt_github_client_id', '' );
                    $google_ready = ! empty( $google_id );
                    $github_ready = ! empty( $github_id );
                    $base_return  = urlencode( home_url( '/' ) );
                    $google_url   = $google_ready
                        ? esc_url( rest_url( 'blockticker/v1/auth/google' ) . '?return_to=' . $base_return )
                        : '#';
                    $github_url   = $github_ready
                        ? esc_url( rest_url( 'blockticker/v1/auth/github' ) . '?return_to=' . $base_return )
                        : '#';
                    ?>
                    <a href="<?php echo $google_url; ?>"
                       class="bt-auth-social-btn<?php echo $google_ready ? '' : ' bt-auth-social-disabled'; ?>"
                       <?php if ( ! $google_ready ) echo 'onclick="btAuthSocialNA(event,\'google\')"'; ?>>
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" fill="#4285F4"/><path d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/><path d="M3.964 10.707A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.707V4.961H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.039l3.007-2.332z" fill="#FBBC05"/><path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 6.293C4.672 4.169 6.656 3.58 9 3.58z" fill="#EA4335"/></svg>
                        <span data-i18n="auth.continue_google"><?php _ebt('auth.continue_google'); ?></span>
                        <?php if ( ! $google_ready ) echo '<span class="bt-auth-social-badge" data-i18n="auth.social_setup">' . __bt('auth.social_setup') . '</span>'; ?>
                    </a>

                    <a href="<?php echo $github_url; ?>"
                       class="bt-auth-social-btn<?php echo $github_ready ? '' : ' bt-auth-social-disabled'; ?>"
                       <?php if ( ! $github_ready ) echo 'onclick="btAuthSocialNA(event,\'github\')"'; ?>>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
                        <span data-i18n="auth.continue_github"><?php _ebt('auth.continue_github'); ?></span>
                        <?php if ( ! $github_ready ) echo '<span class="bt-auth-social-badge" data-i18n="auth.social_setup">' . __bt('auth.social_setup') . '</span>'; ?>
                    </a>

                    <div class="bt-auth-divider"><span data-i18n="auth.or_email"><?php _ebt('auth.or_email'); ?></span></div>

                    <!-- Login form (v119.28.33: each label has for= → input has matching id=) -->
                    <form id="bt-auth-login-form">
                        <div class="bt-auth-field">
                            <label class="bt-auth-label" for="bt-login-email" data-i18n="auth.email_label"><?php _ebt('auth.email_label'); ?></label>
                            <input type="email" id="bt-login-email" name="email" autocomplete="email" data-i18n-ph="auth.email_placeholder" placeholder="<?php echo esc_attr(__bt('auth.email_placeholder')); ?>" required class="bt-auth-input">
                        </div>
                        <div class="bt-auth-field">
                            <label class="bt-auth-label" for="bt-login-pw" data-i18n="auth.password_label"><?php _ebt('auth.password_label'); ?></label>
                            <div class="bt-auth-pw-wrap">
                                <input type="password" name="password" id="bt-login-pw" autocomplete="current-password" data-i18n-ph="auth.password_placeholder" placeholder="<?php echo esc_attr(__bt('auth.password_placeholder')); ?>" required class="bt-auth-input">
                                <button type="button" class="bt-auth-pw-toggle" onclick="btTogglePw('bt-login-pw',this)" tabindex="-1" aria-label="Show password">👁</button>
                            </div>
                        </div>
                        <div style="text-align:right;margin-bottom:16px">
                            <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="font-size:12px;color:var(--bt-text-3);text-decoration:none" data-i18n="auth.forgot_pw"><?php _ebt('auth.forgot_pw'); ?></a>
                        </div>
                        <button type="submit" class="bt-auth-submit" data-i18n="auth.submit_login"><?php _ebt('auth.submit_login'); ?></button>
                        <div id="bt-auth-login-msg" class="bt-auth-msg" role="alert" aria-live="polite" hidden></div>
                    </form>

                    <!-- Register form (v119.28.33: each label has for= → input has matching id=) -->
                    <form id="bt-auth-register-form" hidden>
                        <div class="bt-auth-field">
                            <label class="bt-auth-label" for="bt-reg-name" data-i18n="auth.name_label"><?php _ebt('auth.name_label'); ?></label>
                            <input type="text" id="bt-reg-name" name="name" autocomplete="name" data-i18n-ph="auth.name_placeholder" placeholder="<?php echo esc_attr(__bt('auth.name_placeholder')); ?>" required class="bt-auth-input">
                        </div>
                        <div class="bt-auth-field">
                            <label class="bt-auth-label" for="bt-reg-email" data-i18n="auth.email_label"><?php _ebt('auth.email_label'); ?></label>
                            <input type="email" id="bt-reg-email" name="email" autocomplete="email" data-i18n-ph="auth.email_placeholder" placeholder="<?php echo esc_attr(__bt('auth.email_placeholder')); ?>" required class="bt-auth-input">
                        </div>
                        <div class="bt-auth-field">
                            <label class="bt-auth-label" for="bt-reg-pw" data-i18n="auth.password_label"><?php _ebt('auth.password_label'); ?></label>
                            <div class="bt-auth-pw-wrap">
                                <input type="password" name="password" id="bt-reg-pw" autocomplete="new-password" data-i18n-ph="auth.password_min" placeholder="<?php echo esc_attr(__bt('auth.password_min')); ?>" required class="bt-auth-input">
                                <button type="button" class="bt-auth-pw-toggle" onclick="btTogglePw('bt-reg-pw',this)" tabindex="-1" aria-label="Show password">👁</button>
                            </div>
                        </div>
                        <label class="bt-auth-newsletter-opt">
                            <input type="checkbox" name="newsletter" value="1" checked>
                            <span data-i18n="auth.newsletter_optin"><?php _ebt('auth.newsletter_optin'); ?></span>
                        </label>
                        <button type="submit" class="bt-auth-submit" data-i18n="auth.submit_register"><?php _ebt('auth.submit_register'); ?></button>
                        <div id="bt-auth-register-msg" class="bt-auth-msg" role="alert" aria-live="polite" hidden></div>
                        <?php if ( ! get_option( 'users_can_register' ) ): ?>
                        <p style="font-size:12px;color:var(--bt-danger);margin:12px 0 0;text-align:center" data-i18n="auth.registration_disabled"><?php _ebt('auth.registration_disabled'); ?></p>
                        <?php endif; ?>
                    </form>

                    <p class="bt-auth-terms" data-i18n-html="auth.terms_line"><?php echo __bt('auth.terms_line', esc_url(home_url('/privacy-policy/'))); ?></p>
                </div>
            </div>
        </div>

        <script>
        /* ── Auth Modal ── */
        /* Social auth not available handler */
        window.btAuthSocialNA = function(e, provider) {
            e.preventDefault();
            var msgs = {
                google: 'Google login requires setup. Add your Google OAuth credentials in BlockTicker Setup → API Keys.',
                github: 'GitHub login is coming soon. Please use email to sign up for now.'
            };
            alert(msgs[provider] || 'This login method is not yet available.');
        };

        /* Password show/hide toggle */
        window.btTogglePw = function(inputId, btn) {
            var inp = document.getElementById(inputId);
            if (!inp) return;
            if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '🙈'; }
            else { inp.type = 'password'; btn.textContent = '👁'; }
        };

        /* Handle redirect back after Google OAuth success */
        (function(){
            var params = new URLSearchParams(window.location.search);
            if (params.get('bt_auth_success') === '1') {
                /* User just logged in via Google - sync localStorage to server */
                window.dispatchEvent(new Event('bt_auth_success'));
                /* Clean URL */
                var clean = window.location.href.replace(/[?&]bt_auth_success=1/, '');
                window.history.replaceState({}, document.title, clean);
            }
            if (params.get('bt_auth_error')) {
                var errs = {
                    google_not_configured: 'Google login not configured. Please use email.',
                    invalid_state: 'Security check failed. Please try again.',
                    no_access_token: 'Google auth failed. Please try again.',
                    token_exchange_failed: 'Could not connect to Google. Please try again.'
                };
                var msg = errs[params.get('bt_auth_error')] || 'Login error. Please try again.';
                setTimeout(function(){ btAuthOpen('login'); }, 300);
                setTimeout(function(){
                    var el = document.getElementById('bt-auth-login-msg');
                    if (el) { el.style.display='block'; el.style.color='var(--bt-danger)'; el.textContent='❌ '+msg; }
                }, 400);
                var clean2 = window.location.href.replace(/[?&]bt_auth_error=[^&]*/, '');
                window.history.replaceState({}, document.title, clean2);
            }
        })();

        window.btAuthOpen = function(tab) {
            var m = document.getElementById('bt-auth-modal');
            if(m){ m.style.display='flex'; btAuthTab(tab||'login'); }
        };
        window.btAuthClose = function() {
            var m = document.getElementById('bt-auth-modal');
            if(m) m.style.display='none';
        };
        window.btAuthTab = function(tab) {
            var lf=document.getElementById('bt-auth-login-form'), rf=document.getElementById('bt-auth-register-form');
            var lt=document.getElementById('bt-auth-tab-login'), rt=document.getElementById('bt-auth-tab-register');
            // v119.28.33: use [hidden] attribute (matches new HTML markup) instead of inline style.
            // Removing 'hidden' shows; setting it hides. Browsers honor this with display:none.
            if(tab==='login'){
                lf.hidden = false; rf.hidden = true;
                lt.setAttribute('aria-selected','true'); rt.setAttribute('aria-selected','false');
                lt.style.background='linear-gradient(135deg,var(--bt-accent),var(--bt-accent))'; lt.style.color='#0A0B0D';
                rt.style.background='transparent'; rt.style.color='var(--bt-text-3)';
            } else {
                lf.hidden = true;  rf.hidden = false;
                rt.setAttribute('aria-selected','true'); lt.setAttribute('aria-selected','false');
                rt.style.background='linear-gradient(135deg,var(--bt-accent),var(--bt-accent))'; rt.style.color='#0A0B0D';
                lt.style.background='transparent'; lt.style.color='var(--bt-text-3)';
            }
        };
        document.getElementById('bt-auth-modal').addEventListener('click', function(e){
            if(e.target===this) btAuthClose();
        });

        function btAuthMsg(id, text, ok){
            var el=document.getElementById(id);
            if(!el) return;
            // v119.28.33: paired with new [hidden] markup on .bt-auth-msg
            el.hidden = false;
            el.style.background=ok?'rgba(0,255,102,.1)':'rgba(255,59,48,.08)';
            el.style.border='1px solid '+(ok?'rgba(0,255,102,.2)':'rgba(255,59,48,.2)');
            el.style.color=ok?'var(--bt-accent)':'var(--bt-danger)';
            el.textContent=(ok?'✅ ':'❌ ')+text;
        }

        function afterLogin(data) {
            /* Update navbar — hide login+signup, show avatar.
               PHP renders one or the other server-side. When user logs in via
               modal without a page reload, we need to swap them in JS. */
            var av   = document.getElementById('bt-nav-avatar');
            var nm   = document.getElementById('bt-nav-username');
            var li   = document.getElementById('bt-nav-login-btn');
            var su   = document.getElementById('bt-nav-signup-btn');
            var wrap = document.getElementById('bt-nav-avatar-wrap');

            // Hide auth buttons
            if(li) li.style.display='none';
            if(su) su.style.display='none';

            if(av && wrap){
                // Avatar-wrap already in DOM (user was previously logged in on this render) — just update
                av.src = data.avatar;
                if(nm) nm.textContent = data.display_name;
                wrap.style.display = 'flex';
            } else {
                // Avatar-wrap not in DOM (page rendered logged-out) — inject it
                var actions = document.getElementById('bt-nav-user-actions');
                if(actions){
                    var div = document.createElement('div');
                    div.id = 'bt-nav-avatar-wrap';
                    div.className = 'bt-nav-avatar-wrap';
                    div.style.cssText = 'display:flex;align-items:center;gap:6px;position:relative;cursor:pointer';
                    div.onclick = function(){ btNavUserMenu(); };
                    div.innerHTML = '<img src="'+data.avatar+'" id="bt-nav-avatar" width="28" height="28" style="border-radius:50%;border:2px solid rgba(0,255,102,.4)">'
                        +'<span id="bt-nav-username" style="font-size:12px;font-weight:600;color:var(--bt-text-2)">'+data.display_name+'</span>'
                        +'<div id="bt-nav-usermenu" style="display:none;position:absolute;top:calc(100% + 8px);right:0;background:var(--bt-bg-elev,#16181d);border:1px solid rgba(255,255,255,.1);border-radius:6px;min-width:220px;max-width:calc(100vw - 24px);box-shadow:0 12px 40px rgba(0,0,0,.6);z-index:9999;padding:8px 0;overflow:hidden">'
                        +'<div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06)"><div style="font-size:13px;font-weight:700;color:var(--bt-text)">'+data.display_name+'</div></div>'
                        +'<a href="/portfolio/" style="display:block;padding:10px 16px;font-size:13px;color:#c8d6e5;text-decoration:none">&#x1F4CA; My Portfolio</a>'
                        +'<a href="/watchlist/" style="display:block;padding:10px 16px;font-size:13px;color:#c8d6e5;text-decoration:none">&#x2B50; My Watchlist</a>'
                        +'<div style="border-top:1px solid rgba(255,255,255,.06);margin:6px 0"></div>'
                        +'<button onclick="btAuthLogout()" style="display:block;width:100%;text-align:left;padding:10px 16px;background:none;border:none;color:var(--bt-danger);font-size:13px;cursor:pointer;font-family:inherit">Sign out</button>'
                        +'</div>';
                    // Insert before the login button (or at end of actions)
                    if(li) actions.insertBefore(div, li);
                    else actions.appendChild(div);
                }
            }

            /* Store nonce for REST calls */
            window.BT_REST_NONCE = data.nonce;
            window.BT_LOGGED_IN  = true;

            /* v67: Two-way sync — push local first, then pull server's authoritative
               state into localStorage. Reload after so portfolio/watchlist widgets
               re-read storage and show the user's saved data. */
            btSyncToServer();
            btHydrateFromServer().then(function(){
                btAuthClose();
                /* Only reload if we're on a page that displays portfolio/watchlist —
                   otherwise the hydration is silent and a reload would be jarring. */
                var path = location.pathname || '';
                if (path.indexOf('portfolio') !== -1 || path.indexOf('watchlist') !== -1) {
                    setTimeout(function(){ location.reload(); }, 400);
                }
            });
        }

        function btSyncToServer() {
            var nonce = window.BT_REST_NONCE;
            if(!nonce) return;
            /* Sync portfolio */
            try {
                var port = JSON.parse(localStorage.getItem('bt_portfolio')||'[]');
                if(port.length) {
                    fetch('<?php echo $rest_url; ?>portfolio', {
                        method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
                        body: JSON.stringify({holdings:port})
                    });
                }
            } catch(e){}
            /* Sync watchlist */
            try {
                var wl = JSON.parse(localStorage.getItem('bt_watchlist')||'[]');
                if(wl.length) {
                    fetch('<?php echo $rest_url; ?>watchlist', {
                        method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
                        body: JSON.stringify({watchlist:wl})
                    });
                }
            } catch(e){}
        }

        /* v67: Pull server-side data into localStorage. Called on login so the
           user's saved portfolio/watchlist (from a previous session or another
           device) actually populates the UI. Merges with local, server wins on
           conflicts of the same item key.
           This was the missing half of "sync" — before v67 we only pushed, never pulled. */
        function btHydrateFromServer() {
            var nonce = window.BT_REST_NONCE;
            if(!nonce) return Promise.resolve();
            var headers = { 'X-WP-Nonce': nonce };
            var creds   = { credentials: 'same-origin', headers: headers };

            var pPort = fetch('<?php echo $rest_url; ?>portfolio', creds)
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(d){
                    if(!d) return;
                    var serverList = (d.holdings || d.data || []);
                    if(!Array.isArray(serverList) || !serverList.length) return;
                    try {
                        var local = JSON.parse(localStorage.getItem('bt_portfolio')||'[]');
                        // Merge by symbol — server wins on conflict
                        var bySym = {};
                        local.forEach(function(h){ if(h && h.symbol) bySym[h.symbol.toUpperCase()] = h; });
                        serverList.forEach(function(h){ if(h && h.symbol) bySym[h.symbol.toUpperCase()] = h; });
                        var merged = Object.values(bySym);
                        localStorage.setItem('bt_portfolio', JSON.stringify(merged));
                    } catch(e){
                        localStorage.setItem('bt_portfolio', JSON.stringify(serverList));
                    }
                }).catch(function(){});

            var pWL = fetch('<?php echo $rest_url; ?>watchlist', creds)
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(d){
                    if(!d) return;
                    var serverList = (d.watchlist || d.data || []);
                    if(!Array.isArray(serverList) || !serverList.length) return;
                    try {
                        var local = JSON.parse(localStorage.getItem('bt_watchlist')||'[]');
                        // Merge unique by id+type
                        var seen = {};
                        var merged = [];
                        local.concat(serverList).forEach(function(item){
                            if(!item) return;
                            var key = (item.id || item.symbol || JSON.stringify(item)) + '|' + (item.type || '');
                            if(!seen[key]) { seen[key] = true; merged.push(item); }
                        });
                        localStorage.setItem('bt_watchlist', JSON.stringify(merged));
                    } catch(e){
                        localStorage.setItem('bt_watchlist', JSON.stringify(serverList));
                    }
                }).catch(function(){});

            return Promise.all([pPort, pWL]);
        }

        /* v67: Fetch a fresh bt_auth nonce — the inline nonce is tied to the
           session at page-render time and is invalid after a logout/login cycle. */
        function btFreshAuthNonce() {
            return fetch('<?php echo $ajax; ?>?action=bt_auth_nonce', { credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){ return (d && d.success && d.data && d.data.nonce) ? d.data.nonce : '<?php echo esc_js($nonce); ?>'; })
                .catch(function(){ return '<?php echo esc_js($nonce); ?>'; });
        }

        /* Login form submit */
        document.getElementById('bt-auth-login-form').addEventListener('submit', function(e){
            e.preventDefault();
            var form = this;
            var btn=this.querySelector('button[type=submit]');
            btn.textContent='Logging in…'; btn.disabled=true;
            btFreshAuthNonce().then(function(nonce){
                var fd=new FormData(form);
                fd.append('action','bt_auth_login');
                fd.append('nonce', nonce);
                fetch('<?php echo $ajax; ?>',{method:'POST',body:fd, credentials:'same-origin'})
                .then(r=>r.json()).then(function(d){
                    btn.textContent='Log In →'; btn.disabled=false;
                    if(d.success){ btAuthMsg('bt-auth-login-msg',d.data.message,true); afterLogin(d.data); }
                    else btAuthMsg('bt-auth-login-msg',d.data,false);
                }).catch(function(){
                    btn.textContent='Log In →'; btn.disabled=false;
                    btAuthMsg('bt-auth-login-msg','Network error. Please try again.',false);
                });
            });
        });

        /* Register form submit */
        document.getElementById('bt-auth-register-form').addEventListener('submit', function(e){
            e.preventDefault();
            var form = this;
            var btn=this.querySelector('button[type=submit]');
            btn.textContent='Creating account…'; btn.disabled=true;
            btFreshAuthNonce().then(function(nonce){
                var fd=new FormData(form);
                fd.append('action','bt_auth_register');
                fd.append('nonce', nonce);
                fetch('<?php echo $ajax; ?>',{method:'POST',body:fd, credentials:'same-origin'})
                .then(r=>r.json()).then(function(d){
                    btn.textContent='Create Account →'; btn.disabled=false;
                    if(d.success){ btAuthMsg('bt-auth-register-msg',d.data.message,true); afterLogin(d.data); }
                    else btAuthMsg('bt-auth-register-msg',d.data,false);
                }).catch(function(){
                    btn.textContent='Create Account →'; btn.disabled=false;
                    btAuthMsg('bt-auth-register-msg','Network error. Please try again.',false);
                });
            });
        });

        /* Init: check current login state */
        (function(){
            <?php if ( is_user_logged_in() ):
                $user = wp_get_current_user();
                $rest_nonce = wp_create_nonce('wp_rest');
            ?>
            window.BT_LOGGED_IN  = true;
            window.BT_REST_NONCE = '<?php echo esc_js($rest_nonce); ?>';
            window.BT_USER       = {
                name: '<?php echo esc_js($user->display_name); ?>',
                avatar: '<?php echo esc_js(get_avatar_url($user->ID,array('size'=>32))); ?>'
            };
            /* v67: Hydrate from server for already-logged-in users. Marked idempotent
               per session via sessionStorage so navigating between pages doesn't re-fetch
               on every page view — only fires once per tab session. */
            /* v91: Always hydrate on portfolio/watchlist pages so data is never stale.
               On other pages, once per tab session is enough to avoid needless requests. */
            try {
                var _onUserPage = location.pathname.indexOf('portfolio') !== -1 || location.pathname.indexOf('watchlist') !== -1;
                if (_onUserPage || !sessionStorage.getItem('bt_hydrated')) {
                    btHydrateFromServer().then(function(){
                        sessionStorage.setItem('bt_hydrated', '1');
                    });
                }
            } catch(e) {}
            <?php else: ?>
            window.BT_LOGGED_IN  = false;
            window.BT_REST_NONCE = null;
            window.BT_USER       = null;
            /* v67: Clear the hydration flag on logout so next login re-pulls fresh */
            try { sessionStorage.removeItem('bt_hydrated'); } catch(e) {}
            <?php endif; ?>
        })();
        </script>
        <?php
    }

    // ── PAGE SHORTCODES ───────────────────────────────────────────────────────
    public static function sc_portfolio_page( $atts ) {
        ob_start();
        $rest_url = esc_url( rest_url( 'blockticker/v1/' ) );
        $crypto   = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coins    = array_slice( $crypto['coins'] ?? array(), 0, 100 );
        $fg       = get_option( 'bt_fear_greed', array() );
        $fg_val   = isset( $fg['value'] ) ? intval( $fg['value'] ) : null;
        $fg_lbl   = isset( $fg['value_classification'] ) ? $fg['value_classification'] : '';
        $coin_opts = '';
        foreach ( $coins as $c ) {
            $coin_opts .= '<option value="' . esc_attr($c['id']) . '" data-price="' . esc_attr($c['current_price']) . '">'
                . esc_html($c['name']) . ' (' . esc_html(strtoupper($c['symbol'])) . ')</option>';
        }
        ?>
        <div class="bt-portpage-wrap">
            <!-- Auth banner -->
            <?php if ( ! is_user_logged_in() ): ?>
            <div class="bt-portpage-auth-banner">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--bt-text);margin-bottom:4px">☁️ Save your portfolio to the cloud</div>
                        <div style="font-size:13px;color:var(--bt-text-3)">Create a free account to access your portfolio from any device. Guest data is saved in this browser only.</div>
                    </div>
                    <button onclick="btAuthOpen('register')" class="bt-portpage-auth-btn">Sign Up Free →</button>
                    <button onclick="btAuthOpen('login')" style="background:transparent;border:1px solid rgba(255,255,255,.15);color:var(--bt-text-2);font-weight:600;border-radius:0;padding:9px 18px;cursor:pointer;font-size:13px;font-family:inherit">Log In</button>
                </div>
            </div>
            <?php else:
                $user = wp_get_current_user();
            ?>
            <div class="bt-portpage-auth-banner" style="background:rgba(0,255,102,.05);border-color:rgba(0,255,102,.2)">
                <div style="display:flex;align-items:center;gap:12px">
                    <?php echo get_avatar( $user->ID, 36, '', '', array('class'=>'') ); ?>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:var(--bt-text)"><?php echo esc_html($user->display_name); ?></div>
                        <div style="font-size:12px;color:var(--bt-text-3)">☁️ Portfolio synced to cloud</div>
                    </div>
                    <button onclick="btAuthLogout()" style="margin-left:auto;background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--bt-text-3);border-radius:0;padding:6px 14px;cursor:pointer;font-size:12px;font-family:inherit">Log out</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Concentration Warnings (v50) -->
            <div id="bt-port-warnings" class="bt-port-warnings" style="display:none"></div>

            <!-- F&G Rebalance Prompt (v51) -->
            <div id="bt-port-rebalance" class="bt-port-rebalance" style="display:none"></div>

            <!-- Portfolio stats bar -->
            <div class="bt-portpage-stats" id="bt-portpage-stats" style="display:none">
                <div class="bt-portpage-stat"><span>Total Value</span><strong id="btp-total-val">$0</strong></div>
                <div class="bt-portpage-stat"><span>Invested</span><strong id="btp-total-inv">$0</strong></div>
                <div class="bt-portpage-stat"><span>P&amp;L</span><strong id="btp-pnl">$0</strong></div>
                <div class="bt-portpage-stat"><span>Return</span><strong id="btp-pct">0%</strong></div>
                <div class="bt-portpage-stat"><span>Holdings</span><strong id="btp-count">0</strong></div>
            </div>

            <!-- Allocation Donut (v50) -->
            <div id="bt-port-allocation" class="bt-port-allocation" style="display:none">
                <div class="bt-port-alloc-head">
                    <h3><span>&#x1F967;</span> <span data-i18n="portfolio.allocation">Portfolio Allocation</span></h3>
                    <span class="bt-port-alloc-sub" data-i18n="portfolio.allocation_sub">by market value</span>
                </div>
                <div class="bt-port-alloc-body">
                    <div class="bt-port-donut-wrap">
                        <svg id="bt-port-donut-svg" width="200" height="200" viewBox="0 0 200 200" aria-hidden="true"></svg>
                        <div class="bt-port-donut-center">
                            <div class="bt-port-donut-ct-lbl" data-i18n="portfolio.holdings">Holdings</div>
                            <div class="bt-port-donut-ct-val" id="bt-port-donut-count">0</div>
                        </div>
                    </div>
                    <div class="bt-port-alloc-legend" id="bt-port-alloc-legend"></div>
                </div>
            </div>

            <!-- Add transaction -->
            <div class="bt-portpage-add">
                <h3 style="font-size:15px;font-weight:700;color:var(--bt-text);margin:0 0 16px">➕ Add Transaction</h3>
                <div class="bt-portpage-add-row">
                    <div style="flex:2;min-width:180px">
                        <label style="display:block;font-size:11px;font-weight:700;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Coin</label>
                        <select id="btp-coin" class="bt-port-select"><?php echo $coin_opts; ?></select>
                    </div>
                    <div style="flex:1;min-width:120px">
                        <label style="display:block;font-size:11px;font-weight:700;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Quantity</label>
                        <input type="number" id="btp-qty" placeholder="0.5" min="0" step="any" class="bt-port-input" style="width:100%">
                    </div>
                    <div style="flex:1;min-width:120px">
                        <label style="display:block;font-size:11px;font-weight:700;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Buy Price (USD)</label>
                        <input type="number" id="btp-buy" placeholder="74000" min="0" step="any" class="bt-port-input" style="width:100%">
                    </div>
                    <div style="align-self:flex-end">
                        <button onclick="btpAdd()" class="bt-port-add-btn" style="white-space:nowrap">Add Holding</button>
                    </div>
                </div>
            </div>

            <!-- Holdings -->
            <div class="bt-portpage-holdings">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
                    <h3 style="font-size:15px;font-weight:700;color:var(--bt-text);margin:0">📊 Holdings</h3>
                    <div style="display:flex;gap:8px">
                        <button onclick="btpExport()" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--bt-text-2);border-radius:7px;padding:6px 14px;cursor:pointer;font-size:12px;font-family:inherit">↓ Export CSV</button>
                        <button onclick="btpClear()" style="background:rgba(255,59,48,.06);border:1px solid rgba(255,59,48,.2);color:var(--bt-danger);border-radius:7px;padding:6px 14px;cursor:pointer;font-size:12px;font-family:inherit">Clear All</button>
                    </div>
                </div>
                <div id="btp-empty" style="padding:48px;text-align:center;color:var(--bt-text-3);background:var(--bt-bg);border-radius:0;border:1px dashed rgba(255,255,255,.07)">
                    <div style="font-size:32px;margin-bottom:12px">📊</div>
                    <div style="font-size:15px;font-weight:600;color:var(--bt-text-4);margin-bottom:6px">No holdings yet</div>
                    <div style="font-size:13px">Add your first coin above to start tracking your portfolio.</div>
                </div>
                <div class="bt-portpage-table-wrap" id="btp-table-wrap" style="display:none">
                    <table class="bt-port-table">
                        <thead><tr>
                            <th>Coin</th><th>Holdings</th><th>Avg Buy</th>
                            <th>Current Price</th><th>Value</th><th>P&amp;L</th><th>24h</th><th></th>
                        </tr></thead>
                        <tbody id="btp-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        // Expose Fear & Greed value for renderRebalance (v51)
        window.BT_FG = { value: <?php echo ( $fg_val !== null ) ? intval( $fg_val ) : 'null'; ?>, label: <?php echo wp_json_encode( $fg_lbl ); ?> };
        (function(){
            var REST = '<?php echo esc_js($rest_url); ?>';
            var PRICES = {};
            var chg24  = {};
            <?php foreach ($coins as $c): ?>
            PRICES['<?php echo esc_js($c['id']); ?>'] = {
                name:'<?php echo esc_js($c['name']); ?>',
                sym:'<?php echo esc_js(strtoupper($c['symbol'])); ?>',
                img:'<?php echo esc_js($c['image'] ?? ''); ?>',
                price:<?php echo floatval($c['current_price']); ?>,
                chg:<?php echo floatval($c['price_change_percentage_24h'] ?? 0); ?>
            };
            <?php endforeach; ?>

            var holdings = [];

            function save(){
                localStorage.setItem('bt_portfolio', JSON.stringify(holdings));
                if(window.BT_LOGGED_IN && window.BT_REST_NONCE){
                    fetch(REST+'user/portfolio',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':window.BT_REST_NONCE},body:JSON.stringify({holdings:holdings})});
                }
            }

            function load(){
                /* Logged in: load from server, fall back to localStorage */
                if(window.BT_LOGGED_IN && window.BT_REST_NONCE){
                    fetch(REST+'user/portfolio',{headers:{'X-WP-Nonce':window.BT_REST_NONCE}})
                    .then(r=>r.json()).then(function(d){
                        if(d.holdings && d.holdings.length){ holdings=d.holdings; localStorage.setItem('bt_portfolio',JSON.stringify(holdings)); }
                        else { holdings=JSON.parse(localStorage.getItem('bt_portfolio')||'[]'); }
                        render();
                    }).catch(function(){ holdings=JSON.parse(localStorage.getItem('bt_portfolio')||'[]'); render(); });
                } else {
                    holdings=JSON.parse(localStorage.getItem('bt_portfolio')||'[]'); render();
                }
            }

            function fmt(n){ if(n>=1e9)return'$'+(n/1e9).toFixed(2)+'B';if(n>=1e6)return'$'+(n/1e6).toFixed(2)+'M';return'$'+n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
            function fmtS(n){ return n<0.001?'$'+n.toFixed(6):n<1?'$'+n.toFixed(4):fmt(n); }

            function render(){
                var tbody=document.getElementById('btp-tbody'), empty=document.getElementById('btp-empty'), wrap=document.getElementById('btp-table-wrap'), stats=document.getElementById('bt-portpage-stats');
                if(!holdings.length){ empty.style.display='block'; wrap.style.display='none'; stats.style.display='none'; return; }
                empty.style.display='none'; wrap.style.display='block'; stats.style.display='flex';
                var ti=0,tv=0;
                tbody.innerHTML=holdings.map(function(h,i){
                    var info=PRICES[h.id]||{name:h.id,sym:h.id.toUpperCase(),price:0,chg:0,img:''};
                    var val=info.price*h.qty, inv=h.avgBuy*h.qty, pnl=val-inv, pct=inv?pnl/inv*100:0;
                    var clr=pnl>=0?'var(--bt-accent)':'var(--bt-danger)'; var chg24c=info.chg>=0?'var(--bt-accent)':'var(--bt-danger)';
                    ti+=inv; tv+=val;
                    return '<tr><td><div style="display:flex;align-items:center;gap:10px">'+(info.img?'<img src="'+info.img+'" width="28" height="28" style="border-radius:50%;flex-shrink:0" alt="">':'')+'<div><strong style="color:var(--bt-text)">'+info.name+'</strong><div style="font-size:11px;color:var(--bt-text-3)">'+info.sym+'</div></div></div></td>'
                        +'<td style="color:var(--bt-text)">'+h.qty.toLocaleString(undefined,{maximumFractionDigits:8})+'</td>'
                        +'<td>'+fmtS(h.avgBuy)+'</td>'
                        +'<td>'+fmtS(info.price)+'</td>'
                        +'<td style="font-weight:700;color:var(--bt-text)">'+fmt(val)+'</td>'
                        +'<td style="color:'+clr+'"><strong>'+(pnl>=0?'+':'')+fmt(pnl)+'</strong><div style="font-size:11px">'+(pct>=0?'+':'')+pct.toFixed(2)+'%</div></td>'
                        +'<td style="color:'+chg24c+'">'+(info.chg>=0?'▲':'▼')+Math.abs(info.chg).toFixed(2)+'%</td>'
                        +'<td><button onclick="btpRemove('+i+')" style="background:none;border:none;color:var(--bt-text-4);cursor:pointer;font-size:16px;padding:4px;transition:color .15s" onmouseover="this.style.color=\'var(--bt-danger)\'" onmouseout="this.style.color=\'var(--bt-text-4)\'">✕</button></td></tr>';
                }).join('');
                var pnl=tv-ti, pct=ti?pnl/ti*100:0; var clr=pnl>=0?'var(--bt-accent)':'var(--bt-danger)';
                document.getElementById('btp-total-val').textContent=fmt(tv);
                document.getElementById('btp-total-inv').textContent=fmt(ti);
                document.getElementById('btp-pnl').textContent=(pnl>=0?'+':'')+fmt(pnl); document.getElementById('btp-pnl').style.color=clr;
                document.getElementById('btp-pct').textContent=(pct>=0?'+':'')+pct.toFixed(2)+'%'; document.getElementById('btp-pct').style.color=clr;
                document.getElementById('btp-count').textContent=holdings.length;
                // Paint donut + warnings (v50)
                renderDonut(holdings, tv);
                renderWarnings(holdings, tv);
                renderRebalance(holdings, tv);
            }

            // ---- F&G Rebalance Prompt (v51) ----
            // Exposed from PHP: window.BT_FG = { value: N, label: 'Fear' }
            function renderRebalance(hs, totalVal){
                var box = document.getElementById('bt-port-rebalance');
                if(!hs.length || totalVal <= 0 || !window.BT_FG || window.BT_FG.value === null){ box.style.display='none'; return; }
                var fg = window.BT_FG.value;
                // Compute stablecoin %
                var stableSyms = ['USDT','USDC','DAI','TUSD','USDP','BUSD','FDUSD','PYUSD'];
                var stablePct = 0;
                hs.forEach(function(h){
                    var info = PRICES[h.id] || {price:0, sym:h.id.toUpperCase()};
                    if(stableSyms.indexOf(info.sym) >= 0){
                        stablePct += (info.price * h.qty) / totalVal * 100;
                    }
                });
                var prompt = null;
                if(fg <= 25){
                    // Extreme fear
                    if(stablePct >= 15){
                        prompt = {
                            tone:'bull',
                            icon:'\u{1F4B0}',
                            label:'Extreme Fear ('+fg+') — Historical accumulation zone',
                            body:'Fear & Greed at '+fg+' has historically preceded strong recoveries. You\u2019re holding '+stablePct.toFixed(0)+'% in stablecoins. Consider a measured rotation into BTC or ETH.',
                            cta:'Review holdings ↓'
                        };
                    } else if (stablePct < 5 && hs.length >= 3) {
                        prompt = {
                            tone:'neutral',
                            icon:'\u{1F504}',
                            label:'Extreme Fear ('+fg+')',
                            body:'No stablecoin reserves detected. If markets fall further, you\u2019ll have no dry powder to average down. Consider parking 5–10% in stables.',
                            cta:null
                        };
                    }
                } else if (fg <= 35) {
                    if(stablePct >= 25){
                        prompt = {
                            tone:'neutral',
                            icon:'\u{1F4CA}',
                            label:'Fear ('+fg+')',
                            body:'Sentiment is bearish-leaning. Your '+stablePct.toFixed(0)+'% stablecoin allocation gives you optionality — keep an eye on the Fear & Greed index if it drops below 25.',
                            cta:null
                        };
                    }
                } else if (fg >= 75) {
                    // Extreme greed
                    if(stablePct < 10){
                        prompt = {
                            tone:'bear',
                            icon:'\u{26A0}',
                            label:'Extreme Greed ('+fg+') — Consider trimming',
                            body:'Fear & Greed at '+fg+' has historically preceded pullbacks. You\u2019re only '+stablePct.toFixed(1)+'% in stables. Consider harvesting some winners to lock in gains.',
                            cta:'Review holdings ↓'
                        };
                    } else {
                        prompt = {
                            tone:'neutral',
                            icon:'\u{1F4CA}',
                            label:'Extreme Greed ('+fg+')',
                            body:'Sentiment is at euphoric levels. Your '+stablePct.toFixed(0)+'% stablecoin cushion is well-positioned for a potential reset.',
                            cta:null
                        };
                    }
                } else if (fg >= 65) {
                    if(stablePct < 5){
                        prompt = {
                            tone:'neutral',
                            icon:'\u{1F4CA}',
                            label:'Greed ('+fg+')',
                            body:'Sentiment is bullish-leaning. If the index pushes above 75, consider taking partial profits into stables.',
                            cta:null
                        };
                    }
                }
                if(!prompt){ box.style.display='none'; return; }
                box.style.display='block';
                box.className = 'bt-port-rebalance bt-port-rebalance-' + prompt.tone;
                box.innerHTML =
                    '<div class="bt-port-reb-head">' +
                        '<span class="bt-port-reb-icon">'+prompt.icon+'</span>' +
                        '<strong>'+prompt.label+'</strong>' +
                    '</div>' +
                    '<p>'+prompt.body+'</p>' +
                    (prompt.cta ? '<span class="bt-port-reb-cta">'+prompt.cta+'</span>' : '');
            }

            // ---- Allocation donut ----
            function renderDonut(hs, totalVal){
                var wrap = document.getElementById('bt-port-allocation');
                var svg  = document.getElementById('bt-port-donut-svg');
                var legend = document.getElementById('bt-port-alloc-legend');
                var count = document.getElementById('bt-port-donut-count');
                if(!hs.length || totalVal <= 0){ wrap.style.display='none'; return; }
                wrap.style.display='flex';
                count.textContent = hs.length;
                // Compute per-holding value and sort desc
                var rows = hs.map(function(h){
                    var info = PRICES[h.id] || {name:h.id, sym:h.id.toUpperCase(), price:0, img:''};
                    return { id:h.id, name:info.name, sym:info.sym, val: info.price * h.qty };
                }).filter(function(r){ return r.val > 0; }).sort(function(a,b){ return b.val - a.val; });
                var totalDraw = rows.reduce(function(s,r){ return s + r.val; }, 0) || 1;
                // Collapse tail: keep top 7, lump rest into 'Others'
                var top = rows.slice(0, 7);
                var rest = rows.slice(7);
                if(rest.length){
                    var sum = rest.reduce(function(s,r){ return s+r.val; }, 0);
                    top.push({ id:'__others', name:'Others', sym:'OTH', val:sum });
                }
                // Color palette (matches brand)
                var palette = ['var(--bt-accent)','var(--bt-accent)','#a78bfa','var(--bt-accent-warm)','var(--bt-danger)','#10b981','#f97316','var(--bt-text-3)'];
                // Paint SVG donut
                var cx=100, cy=100, r=72, sw=26;
                svg.innerHTML = '';
                var ns = 'http://www.w3.org/2000/svg';
                var bgRing = document.createElementNS(ns,'circle');
                bgRing.setAttribute('cx',cx); bgRing.setAttribute('cy',cy); bgRing.setAttribute('r',r);
                bgRing.setAttribute('fill','none');
                bgRing.setAttribute('stroke','rgba(255,255,255,.04)');
                bgRing.setAttribute('stroke-width',sw);
                svg.appendChild(bgRing);
                var circumference = 2 * Math.PI * r;
                var offset = 0;
                top.forEach(function(row, i){
                    var pct = row.val / totalDraw;
                    var seg = document.createElementNS(ns,'circle');
                    seg.setAttribute('cx',cx); seg.setAttribute('cy',cy); seg.setAttribute('r',r);
                    seg.setAttribute('fill','none');
                    seg.setAttribute('stroke', palette[i % palette.length]);
                    seg.setAttribute('stroke-width',sw);
                    seg.setAttribute('stroke-dasharray', (circumference * pct) + ' ' + circumference);
                    seg.setAttribute('stroke-dashoffset', -offset);
                    seg.setAttribute('transform','rotate(-90 '+cx+' '+cy+')');
                    seg.style.transition = 'stroke-dasharray .6s ease-out';
                    svg.appendChild(seg);
                    offset += circumference * pct;
                });
                // Legend
                legend.innerHTML = top.map(function(row, i){
                    var pct = (row.val / totalDraw) * 100;
                    var col = palette[i % palette.length];
                    var sym = row.sym;
                    return '<div class="bt-port-legend-row">' +
                        '<span class="bt-port-legend-dot" style="background:'+col+'"></span>' +
                        '<span class="bt-port-legend-sym">'+sym+'</span>' +
                        '<span class="bt-port-legend-pct">'+pct.toFixed(1)+'%</span>' +
                        '<span class="bt-port-legend-val">'+fmt(row.val)+'</span>' +
                        '</div>';
                }).join('');
            }

            // ---- Concentration warnings ----
            function renderWarnings(hs, totalVal){
                var box = document.getElementById('bt-port-warnings');
                if(!hs.length || totalVal <= 0){ box.style.display='none'; return; }
                var warnings = [];
                // Compute per-holding pct
                var rows = hs.map(function(h){
                    var info = PRICES[h.id] || {price:0, sym:h.id.toUpperCase()};
                    return { sym:info.sym, pct: (info.price * h.qty) / totalVal * 100 };
                }).sort(function(a,b){ return b.pct - a.pct; });
                // Single-coin concentration >40%
                if(rows[0] && rows[0].pct >= 40){
                    warnings.push({
                        level:'warn', icon:'&#x26A0;',
                        title:'High single-asset concentration',
                        text: rows[0].sym + ' is ' + rows[0].pct.toFixed(1) + '% of your portfolio. Consider rebalancing to reduce idiosyncratic risk.'
                    });
                }
                // Top-3 concentration >80%
                if(rows.length >= 3){
                    var top3 = rows[0].pct + rows[1].pct + rows[2].pct;
                    if(top3 >= 80 && rows[0].pct < 40){
                        warnings.push({
                            level:'info', icon:'&#x1F4CA;',
                            title:'Concentrated in top 3 holdings',
                            text: 'Your top 3 holdings (' + rows[0].sym + ', ' + rows[1].sym + ', ' + rows[2].sym + ') make up ' + top3.toFixed(0) + '% of portfolio value.'
                        });
                    }
                }
                // Stablecoin-heavy? detect common stable symbols
                var stableSyms = ['USDT','USDC','DAI','TUSD','USDP','BUSD','FDUSD','PYUSD'];
                var stablePct = rows.reduce(function(s,r){
                    return s + (stableSyms.indexOf(r.sym) >= 0 ? r.pct : 0);
                }, 0);
                if(stablePct >= 60){
                    warnings.push({
                        level:'info', icon:'&#x1F4B5;',
                        title:'Defensive positioning',
                        text: stablePct.toFixed(0) + '% in stablecoins. Not invested in the upside of a rally - keep an eye on Fear & Greed extremes for re-entry signals.'
                    });
                }
                // BTC-only? (single-asset and it's BTC)
                if(rows.length === 1 && rows[0].sym === 'BTC'){
                    warnings.push({
                        level:'info', icon:'&#x20BF;',
                        title:'100% Bitcoin',
                        text: 'Simple and historically strong. Consider a small ETH or stablecoin allocation for drawdown resilience.'
                    });
                }
                if(!warnings.length){ box.style.display='none'; return; }
                box.style.display='flex';
                box.innerHTML = warnings.map(function(w){
                    return '<div class="bt-port-warn bt-port-warn-'+w.level+'">' +
                        '<span class="bt-port-warn-icon">'+w.icon+'</span>' +
                        '<div><strong>'+w.title+'</strong><span>'+w.text+'</span></div>' +
                        '</div>';
                }).join('');
            }

            window.btpAdd=function(){
                var id=document.getElementById('btp-coin').value;
                var qty=parseFloat(document.getElementById('btp-qty').value);
                var buy=parseFloat(document.getElementById('btp-buy').value);
                if(!id||!qty||qty<=0||!buy||buy<=0){alert('Please enter a valid quantity and buy price.');return;}
                var ex=holdings.find(function(h){return h.id===id;});
                if(ex){ var tot=ex.qty+qty; ex.avgBuy=(ex.avgBuy*ex.qty+buy*qty)/tot; ex.qty=tot; }
                else holdings.push({id:id,qty:qty,avgBuy:buy,addedAt:Date.now()});
                save(); render();
                document.getElementById('btp-qty').value=''; document.getElementById('btp-buy').value='';
            };
            window.btpRemove=function(i){ holdings.splice(i,1); save(); render(); };
            window.btpClear=function(){ if(!confirm('Remove all holdings?'))return; holdings=[]; save(); render(); };
            window.btpExport=function(){
                var rows=['Coin,Symbol,Quantity,Avg Buy Price,Current Price,Value,P&L,P&L %'];
                holdings.forEach(function(h){
                    var info=PRICES[h.id]||{name:h.id,sym:h.id,price:0};
                    var val=info.price*h.qty, inv=h.avgBuy*h.qty, pnl=val-inv, pct=inv?pnl/inv*100:0;
                    rows.push([info.name,info.sym,h.qty,h.avgBuy,info.price,val.toFixed(2),pnl.toFixed(2),pct.toFixed(2)+'%'].join(','));
                });
                var blob=new Blob([rows.join('\n')],{type:'text/csv'});
                var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='blockticker-portfolio.csv'; a.click();
            };
            window.btAuthLogout=function(){
                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>',{method:'POST',body:new URLSearchParams({action:'bt_auth_logout'})})
                .then(function(){ window.location.reload(); });
            };

            document.addEventListener('DOMContentLoaded', load);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function sc_watchlist_page( $atts ) {
        // Reuse existing watchlist shortcode but wrap with auth banner
        ob_start();
        $rest_url = rest_url( 'blockticker/v1/' );
        ?>
        <div class="bt-portpage-wrap">
            <?php if ( ! is_user_logged_in() ): ?>
            <div class="bt-portpage-auth-banner">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--bt-text);margin-bottom:4px">☁️ Sync your watchlist across devices</div>
                        <div style="font-size:13px;color:var(--bt-text-3)">Create a free account to access your starred coins from any device.</div>
                    </div>
                    <button onclick="btAuthOpen('register')" class="bt-portpage-auth-btn">Sign Up Free →</button>
                    <button onclick="btAuthOpen('login')" style="background:transparent;border:1px solid rgba(255,255,255,.15);color:var(--bt-text-2);font-weight:600;border-radius:0;padding:9px 18px;cursor:pointer;font-size:13px;font-family:inherit">Log In</button>
                </div>
            </div>
            <?php endif; ?>
            <?php echo do_shortcode('[fxlm_watchlist]'); ?>
        </div>
        <script>
        /* Sync watchlist star actions to server when logged in */
        (function(){
            var REST = '<?php echo esc_js($rest_url); ?>';
            var origSave = localStorage.setItem.bind(localStorage);
            /* Override localStorage watchlist saves to also push to server */
            var _setItem = localStorage.setItem;
            localStorage.setItem = function(key, value){
                _setItem.call(localStorage, key, value);
                if(key === 'bt_watchlist' && window.BT_LOGGED_IN && window.BT_REST_NONCE){
                    try {
                        var wl = JSON.parse(value || '[]');
                        fetch(REST+'user/watchlist',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':window.BT_REST_NONCE},body:JSON.stringify({watchlist:wl})});
                    } catch(e){}
                }
            };
            /* On load: if logged in, load from server */
            if(window.BT_LOGGED_IN && window.BT_REST_NONCE){
                fetch(REST+'user/watchlist',{headers:{'X-WP-Nonce':window.BT_REST_NONCE}})
                .then(r=>r.json()).then(function(d){
                    if(d.watchlist && d.watchlist.length){
                        _setItem.call(localStorage,'bt_watchlist',JSON.stringify(d.watchlist));
                    }
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    /* ======================================================================
     * WATCHLISTS v110.0 — named lists, annotations, share tokens
     * ====================================================================== */

    const META_WATCHLISTS = 'bt_watchlists';  // v110: multi-list meta key
    const SHARE_OPTION_PREFIX = 'bt_wl_share_'; // option prefix for share tokens

    /**
     * Data structure (JSON in bt_watchlists usermeta):
     * {
     *   "default": { "name": "My Watchlist", "items": [
     *     { "id": "bitcoin", "note": "Bought at $45k", "target": 100000, "added_at": 1234567890 }
     *   ]},
     *   "defi":    { "name": "DeFi", "items": [...] }
     * }
     */

    // ── REST: get all named watchlists ──────────────────────────────────────
    public static function rest_get_watchlists( $req ) {
        $user_id = get_current_user_id();
        $raw     = get_user_meta( $user_id, self::META_WATCHLISTS, true );
        $lists   = $raw ? json_decode( $raw, true ) : array();

        // Seed from legacy bt_watchlist if no v2 data yet.
        if ( empty( $lists ) ) {
            $legacy_raw = get_user_meta( $user_id, self::META_WATCHLIST, true );
            $legacy     = $legacy_raw ? json_decode( $legacy_raw, true ) : array();
            if ( ! empty( $legacy ) ) {
                $items = array_map( function( $id ) {
                    return array( 'id' => sanitize_key( $id ), 'note' => '', 'target' => null, 'added_at' => time() );
                }, array_values( $legacy ) );
                $lists = array( 'default' => array( 'name' => 'My Watchlist', 'items' => $items ) );
            }
        }

        return rest_ensure_response( array( 'watchlists' => $lists ?: new stdClass() ) );
    }

    // ── REST: save all named watchlists ────────────────────────────────────
    public static function rest_save_watchlists( $req ) {
        $user_id = get_current_user_id();
        $raw     = $req->get_param( 'watchlists' );
        if ( ! is_array( $raw ) ) {
            return new WP_Error( 'invalid', 'watchlists must be an object', array( 'status' => 400 ) );
        }

        $clean = array();
        foreach ( $raw as $slug => $list ) {
            $slug = sanitize_key( $slug );
            if ( empty( $slug ) ) continue;
            $name  = sanitize_text_field( $list['name'] ?? 'Watchlist' );
            $items = array();
            foreach ( (array) ( $list['items'] ?? array() ) as $item ) {
                $id = sanitize_key( $item['id'] ?? '' );
                if ( ! $id ) continue;
                $items[] = array(
                    'id'       => $id,
                    'note'     => sanitize_textarea_field( $item['note'] ?? '' ),
                    'target'   => isset( $item['target'] ) && is_numeric( $item['target'] ) ? floatval( $item['target'] ) : null,
                    'added_at' => intval( $item['added_at'] ?? time() ),
                );
            }
            $clean[ $slug ] = array( 'name' => $name, 'items' => $items );
        }

        update_user_meta( $user_id, self::META_WATCHLISTS, wp_json_encode( $clean ) );

        // Keep legacy bt_watchlist in sync (flat array of IDs) for backward compat.
        $legacy_ids = array();
        foreach ( $clean as $list ) {
            foreach ( $list['items'] as $item ) {
                $legacy_ids[] = $item['id'];
            }
        }
        update_user_meta( $user_id, self::META_WATCHLIST, wp_json_encode( array_values( array_unique( $legacy_ids ) ) ) );

        return rest_ensure_response( array( 'success' => true, 'lists' => count( $clean ) ) );
    }

    // ── REST: create shareable token for a list ────────────────────────────
    public static function rest_create_share( $req ) {
        $user_id = get_current_user_id();
        $slug    = sanitize_key( $req->get_param( 'slug' ) ?: 'default' );

        $raw   = get_user_meta( $user_id, self::META_WATCHLISTS, true );
        $lists = $raw ? json_decode( $raw, true ) : array();

        if ( empty( $lists[ $slug ] ) ) {
            return new WP_Error( 'not_found', 'Watchlist not found', array( 'status' => 404 ) );
        }

        // Reuse existing token if one already exists for this user+slug.
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_value LIKE %s LIMIT 1",
            $wpdb->esc_like( self::SHARE_OPTION_PREFIX ) . '%',
            '%"user_id":' . $user_id . '%'
        ) );
        if ( $existing ) {
            $token = str_replace( self::SHARE_OPTION_PREFIX, '', $existing );
        } else {
            $token = substr( md5( $user_id . $slug . time() . wp_generate_password( 8, false ) ), 0, 16 );
            update_option( self::SHARE_OPTION_PREFIX . $token, wp_json_encode( array(
                'user_id'    => $user_id,
                'slug'       => $slug,
                'created_at' => time(),
            ) ), 'no' );
        }

        $share_url = home_url( '/watchlist/?share=' . $token );
        return rest_ensure_response( array( 'token' => $token, 'url' => $share_url ) );
    }

    // ── REST: public shared watchlist (no auth) ────────────────────────────
    public static function rest_get_shared( $req ) {
        $token = sanitize_key( $req->get_param( 'token' ) );
        $meta  = get_option( self::SHARE_OPTION_PREFIX . $token );
        if ( ! $meta ) {
            return new WP_Error( 'not_found', 'Share link not found or expired', array( 'status' => 404 ) );
        }

        $info    = json_decode( $meta, true );
        $user_id = intval( $info['user_id'] ?? 0 );
        $slug    = sanitize_key( $info['slug'] ?? 'default' );

        $raw   = get_user_meta( $user_id, self::META_WATCHLISTS, true );
        $lists = $raw ? json_decode( $raw, true ) : array();

        if ( empty( $lists[ $slug ] ) ) {
            return new WP_Error( 'not_found', 'Watchlist not found', array( 'status' => 404 ) );
        }

        $user       = get_userdata( $user_id );
        $owner_name = $user ? $user->display_name : 'Anonymous';

        return rest_ensure_response( array(
            'watchlist'  => $lists[ $slug ],
            'slug'       => $slug,
            'owner'      => $owner_name,
            'shared_at'  => $info['created_at'] ?? 0,
        ) );
    }

    // ── Shortcode: [bt_watchlist_v2] ───────────────────────────────────────
    /**
     * Full multi-watchlist UI.
     * - Named tabs (add/rename/delete)
     * - Per-coin: note, target price, ★ star, ✕ remove
     * - Share button generating a public URL
     * - Reads from REST API for logged-in users, localStorage for guests
     *
     * [bt_watchlist_v2 default_tab="default"]
     */
    public static function sc_watchlist_v2( $atts ) {
        $a       = shortcode_atts( array( 'default_tab' => 'default' ), $atts );
        $rest    = esc_url( rest_url( 'blockticker/v1/' ) );
        $prices  = esc_url( rest_url( 'blockticker/v1/prices' ) );
        $logged  = is_user_logged_in();

        ob_start(); ?>
        <div class="bt-wlv2-wrap" id="bt-wlv2">

            <!-- Tabs row -->
            <div class="bt-wlv2-tabs-row">
                <div class="bt-wlv2-tabs" id="bt-wlv2-tabs"></div>
                <div class="bt-wlv2-tab-actions">
                    <button class="bt-wlv2-btn" id="bt-wlv2-add-list" title="<?php esc_attr_e( 'New watchlist', 'blockticker' ); ?>">＋ New list</button>
                    <button class="bt-wlv2-btn bt-wlv2-btn-share" id="bt-wlv2-share-btn" title="<?php esc_attr_e( 'Share this watchlist', 'blockticker' ); ?>">🔗 Share</button>
                </div>
            </div>

            <!-- Search / add coin -->
            <div class="bt-wlv2-search-wrap">
                <input type="text" id="bt-wlv2-search" class="bt-wlv2-search"
                       placeholder="<?php esc_attr_e( '🔍 Search and add a coin…', 'blockticker' ); ?>"
                       autocomplete="off">
                <div id="bt-wlv2-suggestions" class="bt-wlv2-suggestions"></div>
            </div>

            <!-- Share toast -->
            <div id="bt-wlv2-share-toast" class="bt-wlv2-share-toast" style="display:none">
                <span id="bt-wlv2-share-url"></span>
                <button id="bt-wlv2-copy-url">📋 Copy</button>
                <button id="bt-wlv2-close-toast">✕</button>
            </div>

            <!-- Watchlist table -->
            <div id="bt-wlv2-content">
                <div id="bt-wlv2-empty" class="bt-wlv2-empty" style="display:none">
                    <p>⭐ <?php esc_html_e( 'This watchlist is empty. Search for a coin above to add it.', 'blockticker' ); ?></p>
                </div>
                <table class="bt-wlv2-table" id="bt-wlv2-table" style="display:none">
                    <thead><tr>
                        <th></th>
                        <th><?php esc_html_e( 'Coin', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Price', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( '24h', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Target', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Note', 'blockticker' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody id="bt-wlv2-body"></tbody>
                </table>
            </div>

            <?php if ( ! $logged ) : ?>
            <p class="bt-wlv2-guest-note">
                💡 <?php esc_html_e( 'Sign in to sync your watchlist across devices and enable sharing.', 'blockticker' ); ?>
                <button onclick="btAuthOpen('register')" class="bt-wlv2-inline-btn"><?php esc_html_e( 'Sign up free', 'blockticker' ); ?></button>
            </p>
            <?php endif; ?>
        </div>

        <style>
        .bt-wlv2-wrap{font-family:inherit;margin:16px 0}
        .bt-wlv2-tabs-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
        .bt-wlv2-tabs{display:flex;gap:4px;flex-wrap:wrap;flex:1}
        .bt-wlv2-tab{padding:7px 14px;border-radius:0 8px 0 0;font-size:13px;font-weight:600;cursor:pointer;border:none;background:rgba(255,255,255,.06);color:var(--bt-text-2);transition:all .15s}
        .bt-wlv2-tab.active{background:var(--bt-accent);color:#0b0f1a}
        .bt-wlv2-tab-actions{display:flex;gap:6px}
        .bt-wlv2-btn{padding:6px 12px;border-radius:0;font-size:12px;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:transparent;color:var(--bt-text-2);transition:all .15s}
        .bt-wlv2-btn:hover{background:rgba(255,255,255,.08);color:var(--bt-text)}
        .bt-wlv2-btn-share{color:var(--bt-accent);border-color:var(--bt-accent)40}
        .bt-wlv2-search-wrap{position:relative;margin-bottom:14px}
        .bt-wlv2-search{width:100%;padding:10px 14px;border-radius:0;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--bt-text);font-size:14px;font-family:inherit;box-sizing:border-box}
        .bt-wlv2-search:focus{outline:none;border-color:var(--bt-accent)40;background:rgba(0,255,102,.04)}
        .bt-wlv2-suggestions{position:absolute;top:100%;left:0;right:0;background:#1e2535;border:1px solid rgba(255,255,255,.1);border-radius:0 0 10px 10px;z-index:100;max-height:220px;overflow-y:auto;display:none}
        .bt-wlv2-sug-item{padding:10px 14px;cursor:pointer;font-size:13px;color:var(--bt-text);display:flex;align-items:center;gap:10px}
        .bt-wlv2-sug-item:hover{background:rgba(0,255,102,.1)}
        .bt-wlv2-sug-price{margin-left:auto;color:var(--bt-text-3);font-size:12px}
        .bt-wlv2-table{width:100%;border-collapse:collapse;font-size:13px}
        .bt-wlv2-table th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--bt-text-3);padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left}
        .bt-wlv2-table td{padding:10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
        .bt-wlv2-table tr:hover td{background:rgba(255,255,255,.02)}
        .bt-wlv2-coin-name{font-weight:700;color:var(--bt-text)}
        .bt-wlv2-coin-sym{font-size:11px;color:var(--bt-text-3);text-transform:uppercase}
        .bt-wlv2-price{font-weight:700;color:var(--bt-text);font-variant-numeric:tabular-nums}
        .bt-wlv2-chg.pos{color:#22c55e}.bt-wlv2-chg.neg{color:#ef4444}
        .bt-wlv2-target{font-size:12px;color:var(--bt-text-2);font-variant-numeric:tabular-nums}
        .bt-wlv2-target.hit{color:#22c55e;font-weight:700}
        .bt-wlv2-target-hit-badge{font-size:10px;background:#22c55e22;color:#22c55e;border-radius:0;padding:1px 5px;margin-left:4px}
        .bt-wlv2-note-input{font-size:12px;background:transparent;border:1px solid transparent;border-radius:0;color:var(--bt-text-2);padding:3px 6px;width:120px;font-family:inherit}
        .bt-wlv2-note-input:focus{outline:none;border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.04);color:var(--bt-text)}
        .bt-wlv2-target-input{font-size:12px;background:transparent;border:1px solid transparent;border-radius:0;color:var(--bt-text-2);padding:3px 6px;width:80px;font-family:inherit;font-variant-numeric:tabular-nums}
        .bt-wlv2-target-input:focus{outline:none;border-color:rgba(255,255,255,.15);background:rgba(255,255,255,.04)}
        .bt-wlv2-rm{background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;padding:2px 6px;border-radius:0;opacity:.6}
        .bt-wlv2-rm:hover{opacity:1;background:rgba(239,68,68,.1)}
        .bt-wlv2-empty{padding:32px;text-align:center;background:rgba(255,255,255,.02);border-radius:0;color:var(--bt-text-3)}
        .bt-wlv2-share-toast{background:#1e2535;border:1px solid var(--bt-accent)40;border-radius:0;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:13px}
        .bt-wlv2-share-toast span{flex:1;word-break:break-all;color:var(--bt-accent)}
        .bt-wlv2-share-toast button{border:1px solid rgba(255,255,255,.12);background:transparent;color:var(--bt-text-2);border-radius:0;padding:4px 10px;cursor:pointer;font-size:12px}
        .bt-wlv2-guest-note{font-size:12px;color:var(--bt-text-3);margin-top:14px}
        .bt-wlv2-inline-btn{background:none;border:1px solid var(--bt-accent)40;color:var(--bt-accent);border-radius:0;padding:3px 10px;cursor:pointer;font-size:12px;margin-left:6px;font-family:inherit}
        </style>

        <script>
        (function(){
            var REST    = <?php echo wp_json_encode( $rest ); ?>;
            var PRICES  = <?php echo wp_json_encode( $prices ); ?>;
            var LOGGED  = <?php echo $logged ? 'true' : 'false'; ?>;
            var WL_KEY  = 'bt_wlv2';
            var allCoins = [];
            var watchlists = {}; // slug → {name, items[]}
            var activeTab = <?php echo wp_json_encode( $a['default_tab'] ); ?>;
            var dirtyTimer;

            // ── Load prices ──────────────────────────────────────────────
            fetch(PRICES).then(r=>r.json()).then(function(d){
                allCoins = (d.crypto && d.crypto.coins) ? d.crypto.coins : [];
            }).catch(function(){});

            // ── Load watchlists ──────────────────────────────────────────
            function loadWatchlists(){
                if(LOGGED && window.BT_REST_NONCE){
                    fetch(REST+'user/watchlists',{headers:{'X-WP-Nonce':window.BT_REST_NONCE}})
                    .then(r=>r.json()).then(function(d){
                        watchlists = d.watchlists || {};
                        if(Object.keys(watchlists).length === 0) watchlists = {default:{name:'My Watchlist',items:[]}};
                        render();
                    });
                } else {
                    // Guest: localStorage
                    try { watchlists = JSON.parse(localStorage.getItem(WL_KEY)||'{}'); } catch(e){ watchlists={}; }
                    if(Object.keys(watchlists).length === 0) watchlists = {default:{name:'My Watchlist',items:[]}};
                    render();
                }
            }

            // ── Save watchlists ──────────────────────────────────────────
            function saveWatchlists(){
                clearTimeout(dirtyTimer);
                dirtyTimer = setTimeout(function(){
                    if(LOGGED && window.BT_REST_NONCE){
                        fetch(REST+'user/watchlists',{
                            method:'POST',
                            headers:{'Content-Type':'application/json','X-WP-Nonce':window.BT_REST_NONCE},
                            body:JSON.stringify({watchlists:watchlists})
                        });
                    } else {
                        localStorage.setItem(WL_KEY, JSON.stringify(watchlists));
                    }
                }, 800); // debounce 800ms
            }

            // ── Render ───────────────────────────────────────────────────
            function render(){
                renderTabs();
                renderTable();
            }

            function renderTabs(){
                var tabsEl = document.getElementById('bt-wlv2-tabs');
                tabsEl.innerHTML = '';
                Object.keys(watchlists).forEach(function(slug){
                    var list = watchlists[slug];
                    var btn  = document.createElement('button');
                    btn.className = 'bt-wlv2-tab' + (slug===activeTab?' active':'');
                    btn.textContent = list.name + ' (' + (list.items||[]).length + ')';
                    btn.addEventListener('click', function(){ activeTab=slug; render(); });
                    tabsEl.appendChild(btn);
                });
            }

            function renderTable(){
                var list  = watchlists[activeTab];
                var items = list ? (list.items||[]) : [];
                var body  = document.getElementById('bt-wlv2-body');
                var table = document.getElementById('bt-wlv2-table');
                var empty = document.getElementById('bt-wlv2-empty');

                body.innerHTML = '';
                if(items.length === 0){
                    table.style.display='none'; empty.style.display='block'; return;
                }
                table.style.display=''; empty.style.display='none';

                items.forEach(function(item, idx){
                    var coin = allCoins.find(function(c){ return c.id===item.id; });
                    var price   = coin ? coin.current_price : null;
                    var chg     = coin ? coin.price_change_percentage_24h : null;
                    var name    = coin ? coin.name : item.id;
                    var sym     = coin ? coin.symbol.toUpperCase() : item.id.toUpperCase();

                    var priceFmt = price !== null ? (price>=1?'$'+price.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):'$'+price.toFixed(6)) : '—';
                    var chgFmt   = chg  !== null ? (chg>=0?'+':'')+chg.toFixed(2)+'%' : '—';
                    var chgCls   = chg  !== null ? (chg>=0?'pos':'neg') : '';

                    var targetHit = item.target && price !== null && price >= item.target;
                    var targetFmt = item.target ? '$'+parseFloat(item.target).toLocaleString() : '';

                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td style="font-size:16px;">⭐</td>' +
                        '<td><div class="bt-wlv2-coin-name">'+escH(name)+'</div><div class="bt-wlv2-coin-sym">'+escH(sym)+'</div></td>' +
                        '<td class="bt-wlv2-price">'+priceFmt+'</td>' +
                        '<td class="bt-wlv2-chg '+chgCls+'">'+chgFmt+'</td>' +
                        '<td class="bt-wlv2-target'+(targetHit?' hit':'')+'">' +
                            '<input class="bt-wlv2-target-input" type="number" value="'+escH(item.target||'')+'" placeholder="—" min="0" step="any" data-idx="'+idx+'">' +
                            (targetHit ? '<span class="bt-wlv2-target-hit-badge">🎯 Hit!</span>' : '') +
                        '</td>' +
                        '<td><input class="bt-wlv2-note-input" type="text" value="'+escH(item.note||'')+'" placeholder="Add a note…" data-idx="'+idx+'" maxlength="120"></td>' +
                        '<td><button class="bt-wlv2-rm" data-idx="'+idx+'">✕</button></td>';
                    body.appendChild(tr);
                });

                // Note / target inline edit.
                body.querySelectorAll('.bt-wlv2-note-input').forEach(function(el){
                    el.addEventListener('input', function(){
                        watchlists[activeTab].items[parseInt(this.dataset.idx)].note = this.value;
                        saveWatchlists();
                    });
                });
                body.querySelectorAll('.bt-wlv2-target-input').forEach(function(el){
                    el.addEventListener('change', function(){
                        var v = parseFloat(this.value);
                        watchlists[activeTab].items[parseInt(this.dataset.idx)].target = isNaN(v)?null:v;
                        saveWatchlists(); renderTable();
                    });
                });
                body.querySelectorAll('.bt-wlv2-rm').forEach(function(el){
                    el.addEventListener('click', function(){
                        watchlists[activeTab].items.splice(parseInt(this.dataset.idx),1);
                        saveWatchlists(); render();
                    });
                });
            }

            // ── Search ───────────────────────────────────────────────────
            var search = document.getElementById('bt-wlv2-search');
            var sugg   = document.getElementById('bt-wlv2-suggestions');

            search.addEventListener('input', function(){
                var q = this.value.trim().toLowerCase();
                sugg.innerHTML=''; sugg.style.display='none';
                if(q.length < 1) return;
                var matches = allCoins.filter(function(c){
                    return c.name.toLowerCase().includes(q) || (c.symbol||'').toLowerCase().includes(q);
                }).slice(0,8);
                if(!matches.length) return;
                matches.forEach(function(c){
                    var div = document.createElement('div');
                    div.className='bt-wlv2-sug-item';
                    div.innerHTML='<span>'+escH(c.name)+'</span><span style="font-size:11px;color:var(--bt-text-3)">'+escH((c.symbol||'').toUpperCase())+'</span>'
                        +'<span class="bt-wlv2-sug-price">$'+parseFloat(c.current_price||0).toLocaleString()+'</span>';
                    div.addEventListener('click', function(){
                        addCoin(c.id); search.value=''; sugg.style.display='none';
                    });
                    sugg.appendChild(div);
                });
                sugg.style.display='block';
            });
            document.addEventListener('click', function(e){ if(!search.contains(e.target)) sugg.style.display='none'; });

            function addCoin(id){
                if(!watchlists[activeTab]) watchlists[activeTab]={name:'My Watchlist',items:[]};
                var existing = watchlists[activeTab].items.find(function(i){ return i.id===id; });
                if(existing) return;
                watchlists[activeTab].items.push({id:id, note:'', target:null, added_at:Math.floor(Date.now()/1000)});
                saveWatchlists(); render();
            }

            // ── New list ─────────────────────────────────────────────────
            document.getElementById('bt-wlv2-add-list').addEventListener('click', function(){
                var name = prompt('<?php echo esc_js( __( 'Watchlist name:', 'blockticker' ) ); ?>');
                if(!name) return;
                var slug = name.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'') || 'list'+Date.now();
                watchlists[slug]={name:name,items:[]};
                activeTab=slug; saveWatchlists(); render();
            });

            // ── Share ────────────────────────────────────────────────────
            var shareToast = document.getElementById('bt-wlv2-share-toast');
            var shareUrlEl = document.getElementById('bt-wlv2-share-url');

            document.getElementById('bt-wlv2-share-btn').addEventListener('click', function(){
                if(!LOGGED){
                    alert('<?php echo esc_js( __( 'Sign in to share your watchlist.', 'blockticker' ) ); ?>');
                    return;
                }
                fetch(REST+'user/watchlists/share',{
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-WP-Nonce':window.BT_REST_NONCE},
                    body:JSON.stringify({slug:activeTab})
                }).then(r=>r.json()).then(function(d){
                    if(d.url){
                        shareUrlEl.textContent = d.url;
                        shareToast.style.display='flex';
                    }
                });
            });
            document.getElementById('bt-wlv2-copy-url').addEventListener('click', function(){
                navigator.clipboard.writeText(shareUrlEl.textContent).then(function(){
                    document.getElementById('bt-wlv2-copy-url').textContent='✅ Copied!';
                });
            });
            document.getElementById('bt-wlv2-close-toast').addEventListener('click', function(){
                shareToast.style.display='none';
            });

            function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

            // ── Init ─────────────────────────────────────────────────────
            loadWatchlists();
        })();
        </script>
        <?php
        return ob_get_clean();
    }


}
