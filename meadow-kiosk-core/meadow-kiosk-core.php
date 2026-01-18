<?php
/**
 * Plugin Name: Meadow Kiosk Core
 * Description: Core REST API + data model + kiosk/payment/vend/ads logic for Meadow kiosks.
 * Version: 3.0.0
 */

if ( ! defined('ABSPATH') ) exit;

define('MEADOW_KIOSK_CORE_VERSION', '3.0.0');
define('MEADOW_KIOSK_CORE_PATH', plugin_dir_path(__FILE__));
define('MEADOW_KIOSK_CORE_URL', plugin_dir_url(__FILE__));

require_once MEADOW_KIOSK_CORE_PATH . 'includes/helpers.php';
require_once MEADOW_KIOSK_CORE_PATH . 'includes/class-meadow-kiosk-core.php';
require_once MEADOW_KIOSK_CORE_PATH . 'includes/shortcodes.php';
require_once MEADOW_KIOSK_CORE_PATH . 'includes/order-cleanup.php';
require_once MEADOW_KIOSK_CORE_PATH . 'includes/admin-debug.php';
require_once MEADOW_KIOSK_CORE_PATH . 'includes/account.php';

function meadow_kiosk_core_boot() {
    $GLOBALS['meadow_kiosk_core'] = new Meadow_Kiosk_Core();
}
add_action('plugins_loaded', 'meadow_kiosk_core_boot');

register_deactivation_hook(__FILE__, function () {
  if (class_exists('Meadow_Order_Cleanup')) {
    Meadow_Order_Cleanup::unschedule_cron();
  }
});


