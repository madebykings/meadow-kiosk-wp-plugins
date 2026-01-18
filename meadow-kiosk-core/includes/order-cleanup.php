<?php
/**
 * Meadow Order Cleanup
 * Cron + manual admin tool to mark stale kiosk payments abandoned and cancel safe WC orders.
 */

if ( ! defined('ABSPATH') ) exit;

class Meadow_Order_Cleanup {

  const CRON_HOOK     = 'meadow_kiosk_order_cleanup_cron';
  const CRON_SCHEDULE = 'hourly';

  const LOG_OPTION     = 'meadow_kiosk_cleanup_log';
  const LOCK_TRANSIENT = 'meadow_kiosk_cleanup_lock';

  const PAY_POST_TYPE = 'meadow_payment';

  const META_SESSION_ID = '_meadow_session_id';
  const META_ORDER_ID   = '_meadow_order_id';
  const META_STATUS     = '_meadow_status';
  const META_UPDATED_AT = '_meadow_updated_at';

  const META_CLEANED_AT = '_meadow_cleaned_at';
  const META_CLEANED_BY = '_meadow_cleaned_by';
  const META_CLEAN_NOTE = '_meadow_clean_note';

  const MIN_AGE_MINUTES = 20;
  const MAX_AGE_DAYS    = 14;
  const BATCH_LIMIT     = 50;
  const LOCK_TTL        = 60;

  public static function stuck_statuses(): array {
    return [
      'created','started','pending','authorizing','awaiting_payment','payment_started','vending',
    ];
  }

  public static function final_statuses(): array {
    return [
      'paid','approved','completed','refunded','failed_final',
    ];
  }

  public static function init(): void {
    add_action('init', [__CLASS__, 'schedule_cron']);
    add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_meadow_cleanup_run', [__CLASS__, 'handle_admin_run']);
  }

  public static function schedule_cron(): void {
    if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
      wp_schedule_event(time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK);
    }
  }

  public static function unschedule_cron(): void {
    $ts = wp_next_scheduled(self::CRON_HOOK);
    if ($ts) {
      wp_unschedule_event($ts, self::CRON_HOOK);
    }
    wp_clear_scheduled_hook(self::CRON_HOOK);
  }

  public static function run_cron(): void {
    self::run_cleanup([
      'dry_run' => false,
      'source'  => 'cron',
      'limit'   => self::BATCH_LIMIT,
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

    $log = get_option(self::LOG_OPTION, []);
    if ( ! is_array($log) ) $log = [];

    $nonce = wp_create_nonce('meadow_cleanup_run');

    echo '<div class="wrap">';
    echo '<h1>Meadow Order Cleanup</h1>';
    echo '<p>Cleans up stale kiosk payment sessions and cancels associated WooCommerce orders only when safe.</p>';

    echo '<h2>Run</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="meadow_cleanup_run">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';

    echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only)</label></p>';
    echo '<p><label>Limit <input type="number" name="limit" value="' . esc_attr(self::BATCH_LIMIT) . '" min="1" max="500"></label></p>';
    echo '<p><label>Minimum age (minutes) <input type="number" name="min_age" value="' . esc_attr(self::MIN_AGE_MINUTES) . '" min="1" max="1440"></label></p>';
    echo '<p><label>Max age (days) <input type="number" name="max_age" value="' . esc_attr(self::MAX_AGE_DAYS) . '" min="1" max="365"></label></p>';

    submit_button('Run Cleanup');
    echo '</form>';

    echo '<h2>Recent Log</h2>';
    if (empty($log)) {
      echo '<p>No log entries yet.</p>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Source</th><th>Summary</th></tr></thead><tbody>';
      foreach (array_reverse($log) as $row) {
        $t = esc_html($row['time'] ?? '');
        $s = esc_html($row['source'] ?? '');
        $m = esc_html($row['message'] ?? '');
        echo "<tr><td>{$t}</td><td>{$s}</td><td>{$m}</td></tr>";
      }
      echo '</tbody></table>';
    }

    echo '</div>';
  }

  public static function handle_admin_run(): void {
    if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
    check_admin_referer('meadow_cleanup_run');

    $dry_run = ! empty($_POST['dry_run']);
    $limit   = isset($_POST['limit']) ? max(1, min(500, intval($_POST['limit']))) : self::BATCH_LIMIT;
    $min_age = isset($_POST['min_age']) ? max(1, min(1440, intval($_POST['min_age']))) : self::MIN_AGE_MINUTES;
    $max_age = isset($_POST['max_age']) ? max(1, min(365, intval($_POST['max_age']))) : self::MAX_AGE_DAYS;

    $res = self::run_cleanup([
      'dry_run' => $dry_run,
      'source'  => 'admin',
      'limit'   => $limit,
      'min_age' => $min_age,
      'max_age' => $max_age,
      'user_id' => get_current_user_id(),
    ]);

    $qs = [
      'page' => 'meadow-order-cleanup',
      'ran'  => 1,
      'dry'  => $dry_run ? 1 : 0,
      'c'    => $res['candidates'] ?? 0,
      'a'    => $res['actions'] ?? 0,
    ];
    wp_safe_redirect(add_query_arg($qs, admin_url('tools.php')));
    exit;
  }

  public static function run_cleanup(array $args): array {
    $dry_run = (bool)($args['dry_run'] ?? true);
    $source  = (string)($args['source'] ?? 'unknown');
    $limit   = isset($args['limit']) ? intval($args['limit']) : self::BATCH_LIMIT;
    $min_age = isset($args['min_age']) ? intval($args['min_age']) : self::MIN_AGE_MINUTES;
    $max_age = isset($args['max_age']) ? intval($args['max_age']) : self::MAX_AGE_DAYS;
    $user_id = isset($args['user_id']) ? intval($args['user_id']) : 0;

    if ( get_transient(self::LOCK_TRANSIENT) ) {
      self::log($source, 'Skipped (lock present).');
      return ['skipped' => true];
    }
    set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

    try {
      if ( ! class_exists('WooCommerce') ) {
        self::log($source, 'Aborted: WooCommerce not loaded.');
        return ['error' => 'woocommerce_not_loaded'];
      }

      $now    = time();
      $min_ts = $now - ($min_age * 60);
      $max_ts = $now - ($max_age * 86400);

      $candidates = self::find_candidates($min_ts, $max_ts, $limit);
      $actions = 0;

      foreach ($candidates as $pay_post) {
        $did = self::process_payment_post((int)$pay_post->ID, [
          'dry_run' => $dry_run,
          'user_id' => $user_id,
        ]);
        if ($did) $actions++;
      }

      self::log($source, sprintf(
        'Run complete. dry_run=%s candidates=%d actions=%d (min_age=%dm max_age=%dd limit=%d)',
        $dry_run ? 'true' : 'false',
        count($candidates),
        $actions,
        $min_age,
        $max_age,
        $limit
      ));

      return ['dry_run'=>$dry_run,'candidates'=>count($candidates),'actions'=>$actions];

    } finally {
      delete_transient(self::LOCK_TRANSIENT);
    }
  }

  private static function find_candidates(int $min_ts, int $max_ts, int $limit): array {
    $stuck = self::stuck_statuses();
    $final = self::final_statuses();

    $q = new WP_Query([
      'post_type'      => self::PAY_POST_TYPE,
      'post_status'    => 'any',
      'posts_per_page' => $limit,
      'orderby'        => 'modified',
      'order'          => 'ASC',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => self::META_STATUS,
          'value'   => $stuck,
          'compare' => 'IN',
        ],
        [
          'key'     => self::META_CLEANED_AT,
          'compare' => 'NOT EXISTS',
        ],
      ],
      'no_found_rows'  => true,
    ]);

    $out = [];
    foreach ($q->posts as $p) {
      $ts = self::payment_updated_ts((int)$p->ID);
      if ($ts <= 0) continue;
      if ($ts > $min_ts) continue;
      if ($ts < $max_ts) continue;

      $status = (string)get_post_meta((int)$p->ID, self::META_STATUS, true);
      if (in_array($status, $final, true)) continue;

      $out[] = $p;
    }
    return $out;
  }

  private static function payment_updated_ts(int $pay_id): int {
    $v = get_post_meta($pay_id, self::META_UPDATED_AT, true);
    if (is_numeric($v)) return (int)$v;

    $post = get_post($pay_id);
    if (!$post) return 0;

    $gmt = $post->post_modified_gmt ?: $post->post_date_gmt;
    if (!$gmt) return 0;

    $ts = strtotime($gmt . ' GMT');
    return $ts ?: 0;
  }

  private static function process_payment_post(int $pay_id, array $args): bool {
    $dry_run = (bool)($args['dry_run'] ?? true);
    $user_id = (int)($args['user_id'] ?? 0);

    $status   = (string)get_post_meta($pay_id, self::META_STATUS, true);
    $session  = (string)get_post_meta($pay_id, self::META_SESSION_ID, true);
    $order_id = (int)get_post_meta($pay_id, self::META_ORDER_ID, true);

    $order = $order_id ? wc_get_order($order_id) : null;

    if ($order) {
      $wc_status = $order->get_status();
      if (in_array($wc_status, ['processing','completed','on-hold'], true)) return false;
      if ($order->get_date_paid() || $order->get_transaction_id()) return false;
    }

    $note = "cleanup: stale payment status={$status} session={$session} order_id={$order_id}";

    if ($dry_run) return true;

    update_post_meta($pay_id, self::META_STATUS, 'abandoned');
    update_post_meta($pay_id, self::META_CLEANED_AT, time());
    update_post_meta($pay_id, self::META_CLEANED_BY, $user_id ?: 0);
    update_post_meta($pay_id, self::META_CLEAN_NOTE, $note);

    if ($order) {
      $wc_status = $order->get_status();
      if (in_array($wc_status, ['pending','failed','cancelled'], true) && $wc_status !== 'cancelled') {
        $order->update_status('cancelled', '[Meadow] Auto-cancelled stale kiosk order. ' . $note);
      }
    }

    return true;
  }

  private static function log(string $source, string $message): void {
    $log = get_option(self::LOG_OPTION, []);
    if ( ! is_array($log) ) $log = [];

    $log[] = ['time'=>gmdate('c'),'source'=>$source,'message'=>$message];

    if (count($log) > 200) $log = array_slice($log, -200);

    update_option(self::LOG_OPTION, $log, false);
  }
}

add_action('plugins_loaded', ['Meadow_Order_Cleanup', 'init']);
