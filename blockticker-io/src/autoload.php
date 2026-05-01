<?php
/**
 * BlockTicker Autoloader
 * 
 * PSR-4 compliant autoloader for BlockTicker plugin.
 * Maps namespace BlockTicker\ to /src/ directory.
 * 
 * @package BlockTicker
 * @since 119.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register PSR-4 autoloader for BlockTicker namespace
 */
spl_autoload_register( function( $class ) {
    // Only autoload BlockTicker namespace
    $prefix = 'BlockTicker\\';
    
    // Check if class uses our namespace
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    
    // Get relative class name (remove namespace prefix)
    $relative_class = substr( $class, strlen( $prefix ) );
    
    // Convert namespace separators to directory separators
    $file_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );
    
    // Build full file path
    $file = plugin_dir_path( dirname( __FILE__ ) ) . 'src/' . $file_path . '.php';
    
    // Load file if it exists
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/**
 * Initialize core modules
 */
add_action( 'plugins_loaded', function() {
    // Initialize Security module
    if ( class_exists( 'BlockTicker\\Core\\Security' ) ) {
        BlockTicker\Core\Security::init();
    }
    
    // Additional core modules will be added here
    // BlockTicker\Core\Performance::init();
    // BlockTicker\Core\Cache::init();
}, 1 );
