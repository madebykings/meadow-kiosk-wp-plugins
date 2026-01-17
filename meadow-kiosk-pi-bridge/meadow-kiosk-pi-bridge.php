<?php
/**
 * Plugin Name: Meadow Kiosk Pi Bridge
 * Description: WP→Pi proxy endpoints (purchase/vend/control) for Meadow kiosks. Depends on Meadow Kiosk Core.
 * Version: 1.0.0
 */

if ( ! defined('ABSPATH') ) exit;

define('MEADOW_KIOSK_PI_BRIDGE_VERSION', '1.0.0');
define('MEADOW_KIOSK_PI_BRIDGE_PATH', plugin_dir_path(__FILE__));

require_once MEADOW_KIOSK_PI_BRIDGE_PATH . 'includes/class-meadow-kiosk-pi-bridge.php';

add_action('plugins_loaded', function(){
    if ( ! defined('MEADOW_KIOSK_CORE_VERSION') ) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>Meadow Kiosk Pi Bridge</strong> requires <strong>Meadow Kiosk Core</strong> to be active.</p></div>';
        });
        return;
    }
    $GLOBALS['meadow_kiosk_pi_bridge'] = new Meadow_Kiosk_Pi_Bridge();
});
