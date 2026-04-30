<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Theme {
    public static function init() {
        // Dark mode only - no toggle needed
        add_action( 'wp_head', array( __CLASS__, 'force_dark' ), 1 );
    }

    public static function force_dark() {
        echo '<style>html{background:#0b0f1a!important}body{background:#0b0f1a!important;color:#c8cdd8!important}</style>';
    }
}

