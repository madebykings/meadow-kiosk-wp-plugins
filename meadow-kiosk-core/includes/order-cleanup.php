<?php
/**
 * Meadow Order Cleanup (On-hold kiosk orders)
 */
if ( ! defined('ABSPATH') ) exit;

class Meadow_Order_Cleanup {

  const CRON_HOOK = 'meadow_kiosk_order_cleanup_cron';
  const LOCK_TRANSIENT = 'meadow_kiosk_cleanup_lock';
  const LOCK_TTL = 60;

  const MIN_AGE_MINUTES = 20;
  const MAX_AGE_DAYS    = 14;
  const BATCH_LIMIT     = 50;

  public static function init(): void {
    add_action('init', [__CLASS__, 'schedule_cron']);
    add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_meadow_cleanup_run', [__CLASS__, 'handle_admin_run']);
  }

  public static function schedule_cron(): void {
    if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
      wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
    }
  }

  public static function run_cron(): void {
    self::run_cleanup([
      'dry_run' => false,
      'source'  => 'cron',
      'limit'   => self::BATCH_LIMIT,
      'min_age' => self::MIN_AGE_MINUTES,
      'max_age' => self::MAX_AGE_DAYS,
    ]);
  }

  public static function admin_menu(): void {
    add_management_page(
      'Meadow Order Cleanup',
      'Meadow Order Cleanup',
      'manage_woocommerce',
      'meadow-order-cleanup',
      [__CLASS__, 'render_admin_page']
    );
  }

  public static function render_admin_page(): void {
    if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
    $nonce = wp_create_nonce('meadow_cleanup_run');

    echo '<div class="wrap"><h1>Meadow Order Cleanup</h1>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="meadow_cleanup_run">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';

    echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only)</label></p>';
    echo '<p><label>Limit <input type="number" name="limit" value="50" min="1" max="500"></label></p>';
    echo '<p><label>Minimum age (minutes) <input type="number" name="min_age" value="20" min="1" max="1440"></label></p>';
    echo '<p><label>Max age (days) <input type="number" name="max_age" value="14" min="1" max="365"></label></p>';

    submit_button('Cancel stale on-hold kiosk orders');
    echo '</form></div>';
  }

  public static function handle_admin_run(): void {
    if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
    check_admin_referer('meadow_cleanup_run');

    $dry_run = ! empty($_POST['dry_run']);
    $limit   = isset($_POST['limit']) ? max(1, min(500, intval($_POST['limit']))) : 50;
    $min_age = isset($_POST['min_age']) ? max(1, min(1440, intval($_POST['min_age']))) : 20;
    $max_age = isset($_POST['max_age']) ? max(1, min(365, intval($_POST['max_age']))) : 14;

    $res = self::run_cleanup([
      'dry_run' => $dry_run,
      'source'  => 'admin',
      'limit'   => $limit,
      'min_age' => $min_age,
      'max_age' => $max_age,
    ]);

    wp_safe_redirect(add_query_arg([
      'page' => 'meadow-order-cleanup',
      'ran'  => 1,
      'dry'  => $dry_run ? 1 : 0,
      'c'    => $res['candidates'] ?? 0,
      'a'    => $res['actions'] ?? 0,
    ], admin_url('tools.php')));
    exit;
  }

  public static function run_cleanup(array $args): array {
    if ( get_transient(self::LOCK_TRANSIENT) ) return ['skipped' => true];
    set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

    try {
      if ( ! function_exists('wc_get_orders') ) return ['error' => 'woocommerce_not_loaded'];

      $dry_run = (bool)($args['dry_run'] ?? true);
      $limit   = (int)($args['limit'] ?? 50);
      $min_age = (int)($args['min_age'] ?? 20);
      $max_age = (int)($args['max_age'] ?? 14);

      $now    = time();
      $min_ts = $now - ($min_age * 60);
      $max_ts = $now - ($max_age * 86400);

      $orders = wc_get_orders([
        'limit'   => $limit,
        'status'  => ['on-hold'],
        'orderby' => 'date',
        'order'   => 'ASC',
        'date_created' => [
          'after'     => gmdate('Y-m-d H:i:s', $max_ts),
          'before'    => gmdate('Y-m-d H:i:s', $min_ts),
          'inclusive' => true,
        ],
        // ✅ this matches your rest_start_payment() stamping
        'meta_query' => [
          [
            'key'   => '_meadow_order_type',
            'value' => 'kiosk',
          ],
        ],
      ]);

      $actions = 0;
      foreach ($orders as $order) {
        /** @var WC_Order $order */
        if ($order->get_date_paid() || $order->get_transaction_id()) continue; // never touch paid
        if ($dry_run) { $actions++; continue; }

        $ref = (string)$order->get_meta('_meadow_reference', true);
        $kiosk_id = (string)$order->get_meta('_meadow_kiosk_id', true);
        $motor = (string)$order->get_meta('_meadow_motor', true);

        $order->update_status('cancelled', "[Meadow] Auto-cancel stale on-hold kiosk order (kiosk={$kiosk_id}, motor={$motor}, ref={$ref}).");
        $actions++;
      }

      return ['candidates' => count($orders), 'actions' => $actions, 'dry_run' => $dry_run];

    } finally {
      delete_transient(self::LOCK_TRANSIENT);
    }
  }
}

add_action('plugins_loaded', ['Meadow_Order_Cleanup', 'init']);
