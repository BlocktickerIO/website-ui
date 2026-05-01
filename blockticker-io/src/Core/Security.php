<?php
/**
 * BlockTicker Core Security Module
 * 
 * Handles security headers, rate limiting, and diagnostic file cleanup.
 * 
 * @package BlockTicker\Core
 * @since 119.29.0
 */

namespace BlockTicker\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Security handler for BlockTicker plugin
 */
class Security {
    
    /**
     * Rate limit storage key prefix
     */
    const RATE_LIMIT_PREFIX = 'bt_rate_limit_';
    
    /**
     * Default rate limit: requests per minute per IP
     */
    const DEFAULT_RATE_LIMIT = 60;
    
    /**
     * Diagnostic file path (relative to plugin dir)
     */
    const DIAG_FILE = 'blockticker-diag.php';
    
    /**
     * Initialize security hooks
     */
    public static function init() {
        add_action( 'send_headers', array( __CLASS__, 'add_security_headers' ) );
        add_action( 'admin_init', array( __CLASS__, 'remove_diagnostic_file' ) );
        add_filter( 'wp_ajax_nopriv_', array( __CLASS__, 'rate_limit_ajax' ), 1, 1 );
        add_action( 'plugins_loaded', array( __CLASS__, 'check_diagnostic_on_production' ), 1 );
    }
    
    /**
     * Add security headers to all responses
     * 
     * @global WP $wp Current WordPress environment instance
     */
    public static function add_security_headers() {
        // Content Security Policy - restrict script sources
        $csp_policy = implode( '; ', array(
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.google-analytics.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https: blob:",
            "connect-src 'self' https://api.coingecko.com https://www.tradingview.com https://api.frankfurter.app",
            "frame-src 'self' https://www.tradingview.com https://www.google.com",
            "upgrade-insecure-requests"
        ) );
        
        header( "Content-Security-Policy: {$csp_policy}" );
        
        // Prevent clickjacking
        header( 'X-Frame-Options: SAMEORIGIN' );
        
        // XSS Protection
        header( 'X-XSS-Protection: 1; mode=block' );
        
        // Prevent MIME type sniffing
        header( 'X-Content-Type-Options: nosniff' );
        
        // Referrer Policy
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        
        // Permissions Policy (formerly Feature-Policy)
        header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
    }
    
    /**
     * Remove diagnostic file from production environments
     * 
     * This runs on every admin page load but only acts once.
     * Diagnostic files are dangerous in production as they expose:
     * - Database structure
     * - API keys (even if masked)
     * - Server configuration
     * - User data patterns
     */
    public static function remove_diagnostic_file() {
        $diag_path = plugin_dir_path( dirname( __FILE__ ) . '/../../' ) . self::DIAG_FILE;
        
        if ( file_exists( $diag_path ) ) {
            // Only delete on non-development environments
            if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
                wp_delete_file( $diag_path );
                error_log( '[BlockTicker] Removed diagnostic file for security.' );
            }
        }
    }
    
    /**
     * Check and remove diagnostic file on production during plugins_loaded
     * 
     * Early check before any potential exploitation
     */
    public static function check_diagnostic_on_production() {
        $diag_path = plugin_dir_path( dirname( __FILE__ ) . '/../../' ) . self::DIAG_FILE;
        
        // If running on production (no WP_DEBUG or false)
        if ( file_exists( $diag_path ) && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
            // Check if we're in a production-like environment
            $is_production = true;
            
            // Allow local development environments
            $local_hosts = array( 'localhost', '127.0.0.1', '::1' );
            if ( isset( $_SERVER['HTTP_HOST'] ) && in_array( $_SERVER['HTTP_HOST'], $local_hosts ) ) {
                $is_production = false;
            }
            
            // Check for common local development paths
            $wp_path = ABSPATH;
            if ( stripos( $wp_path, 'local' ) !== false || 
                 stripos( $wp_path, 'dev' ) !== false ||
                 stripos( $wp_path, 'sandbox' ) !== false ) {
                $is_production = false;
            }
            
            if ( $is_production ) {
                wp_delete_file( $diag_path );
                error_log( '[BlockTicker] Production diagnostic file removed.' );
            }
        }
    }
    
    /**
     * Rate limit AJAX requests per IP address
     * 
     * @param string $action The AJAX action name
     * @return mixed Modified action or original
     */
    public static function rate_limit_ajax( $action ) {
        // Get client IP (handle proxies)
        $ip = self::get_client_ip();
        
        // Create rate limit key
        $key = self::RATE_LIMIT_PREFIX . md5( $ip . '_' . $action );
        
        // Get current count from transient (1 minute window)
        $count = get_transient( $key );
        
        if ( $count === false ) {
            // First request in this minute
            set_transient( $key, 1, 60 );
        } else {
            $count = intval( $count ) + 1;
            
            // Check if rate limit exceeded
            if ( $count > self::DEFAULT_RATE_LIMIT ) {
                error_log( "[BlockTicker] Rate limit exceeded for IP {$ip} on action {$action}" );
                wp_send_json_error( array(
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many requests. Please try again later.'
                ) );
                exit;
            }
            
            // Update count
            set_transient( $key, $count, 60 );
        }
        
        return $action;
    }
    
    /**
     * Get client IP address handling proxies and Cloudflare
     * 
     * @return string Client IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        // Cloudflare
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        }
        // Standard proxy headers
        elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] );
            // Take first IP if multiple
            if ( strpos( $ip, ',' ) !== false ) {
                $parts = explode( ',', $ip );
                $ip = trim( $parts[0] );
            }
        }
        elseif ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = sanitize_text_field( $_SERVER['HTTP_X_REAL_IP'] );
        }
        elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        }
        
        // Validate IP format
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Verify nonce with improved error handling
     * 
     * @param string $nonce Nonce value
     * @param string $action Nonce action
     * @return bool True if valid, false otherwise
     */
    public static function verify_nonce( $nonce, $action ) {
        if ( empty( $nonce ) ) {
            return false;
        }
        
        $result = wp_verify_nonce( $nonce, $action );
        
        if ( $result === false ) {
            error_log( "[BlockTicker] Invalid nonce for action: {$action}" );
        }
        
        return $result !== false;
    }
    
    /**
     * Sanitize and validate API key input
     * 
     * @param string $key API key to validate
     * @return string|false Sanitized key or false if invalid
     */
    public static function sanitize_api_key( $key ) {
        if ( empty( $key ) || ! is_string( $key ) ) {
            return false;
        }
        
        // Remove whitespace
        $key = trim( $key );
        
        // Basic validation: alphanumeric with common API key characters
        if ( ! preg_match( '/^[a-zA-Z0-9_\-\.\~]+$/', $key ) ) {
            return false;
        }
        
        // Minimum length check
        if ( strlen( $key ) < 16 ) {
            return false;
        }
        
        return $key;
    }
}
