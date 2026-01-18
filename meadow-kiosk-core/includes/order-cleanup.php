<?php
/**
 * Meadow Order Cleanup
 * Cron + admin tool to cleanup stale kiosk payments and cancel stale on-hold kiosk orders.
 */

if ( ! defined('ABSPATH') ) exit;

class Meadow_Order_Cleanup {

  const CRON_HOOK     = 'meadow_kiosk_order_cleanup_cron';
  const CRON_SCHEDULE = 'hourly';

  const LOG_OPTION     = 'meadow_kiosk_cleanup_log';
  const LOCK_TRANSIENT = 'meadow_kiosk_cleanup_lock';

  // ---- Meadow payment CPT (optional) ----
  const PAY_POST_TYPE   = 'meadow_payment';
  const META_STATUS     = '_meadow_status';
  const META_UPDATED_AT = '_meadow_updated_at';
  const META_SESSION_ID = '_meadow_session_id';
  const META_ORDER_ID   = '_meadow_order_id';

  const META_CLEANED_AT = '_meadow_cleaned_at';
  const META_CLEANED_BY = '_meadow_cleaned_by';
  const META_CLEAN_NOTE = '_meadow_clean_note';

  // ---- Woo order meta markers (kiosk-only filter) ----
  const ORDER_META_KIOSK_ID   = '_meadow_kiosk_id';
  const ORDER_META_SESSION_ID = '_meadow_session_id';
  const ORDER_META_KIOSK_REF  = '_meadow_kiosk_ref';

  const ORDER_META_CLEANED_AT = '_meadow_order_cleaned_at';
  const ORDER_META_CLEAN_NOTE = '_meadow_order_clean_note';

  // thresholds
  const MIN_AGE_MINUTES = 20;
  const MAX_AGE_DAYS    = 14;
  const BATCH_LIMIT     = 50;
  const LOCK_TTL        = 60;

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
    if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    wp_clear_scheduled_hook(self::CRON_HOOK);
  }

  public static function run_cron(): void {
    // Cron: conservative but DOES include on-hold kiosk orders.
    self::run_cleanup([
      'dry_run' => false,
      'source'  => 'cron',
      'limit'   => self::BATCH_LIMIT,
      'include_on_hold_orders' => true,
    ]);
  }

  // ---------------- Admin UI ----------------

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

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="meadow_cleanup_run">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';

    echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only)</label></p>';
    echo '<p><label><input type="checkbox" name="include_on_hold_orders" value="1" checked> Also cancel stale on-hold kiosk orders</label></p>';

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
    $incl_on_hold = ! empty($_POST['include_on_hold_orders']);

    $res = self::run_cleanup([
      'dry_run' => $dry_run,
      'source'  => 'admin',
      'limit'   => $limit,
      'min_age' => $min_age,
      'max_age' => $max_age,
      'user_id' => get_current_user_id(),
      'include_on_hold_orders' => $incl_on_hold,
    ]);

    $qs = [
      'page' => 'meadow-order-cleanup',
      'ran'  => 1,
      'dry'  => $dry_run ? 1 : 0,
      'wc'   => $res['wc_candidates'] ?? 0,
      'wca'  => $res['wc_actions'] ?? 0,
      'p'    => $res['pay_candidates'] ?? 0,
      'pa'   => $res['pay_actions'] ?? 0,
    ];
    wp_safe_redirect(add_query_arg($qs, admin_url('tools.php')));
    exit;
  }

  // ---------------- Core ----------------

  public static function run_cleanup(array $args): array {
    $dry_run = (bool)($args['dry_run'] ?? true);
    $source  = (string)($args['source'] ?? 'unknown');
    $limit   = isset($args['limit']) ? intval($args['limit']) : self::BATCH_LIMIT;
    $min_age = isset($args['min_age']) ? intval($args['min_age']) : self::MIN_AGE_MINUTES;
    $max_age = isset($args['max_age']) ? intval($args['max_age']) : self::MAX_AGE_DAYS;
    $user_id = isset($args['user_id']) ? intval($args['user_id']) : 0;
    $include_on_hold = (bool)($args['include_on_hold_orders'] ?? false);

    if ( get_transient(self::LOCK_TRANSIENT) ) {
      self::log($source, 'Skipped (lock present).');
      return ['skipped' => true];
    }
    set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

    try {
      if ( ! function_exists('wc_get_orders') ) {
        self::log($source, 'Aborted: WooCommerce not loaded.');
        return ['error' => 'woocommerce_not_loaded'];
      }

      $now    = time();
      $min_ts = $now - ($min_age * 60);
      $max_ts = $now - ($max_age * 86400);

      // A) Woo order cleanup (what you actually want)
      $wc_candidates = $include_on_hold ? self::find_wc_on_hold_candidates($min_ts, $max_ts, $limit) : [];
      $wc_actions = 0;
      foreach ($wc_candidates as $order) {
        if (self::process_wc_order((int)$order->get_id(), $dry_run, $user_id)) $wc_actions++;
      }

      // B) Payment CPT cleanup (optional / legacy)
      $pay_candidates = self::find_payment_candidates($min_ts, $max_ts, $limit);
      $pay_actions = 0;
      foreach ($pay_candidates as $p) {
        if (self::process_payment_post((int)$p->ID, $dry_run, $user_id)) $pay_actions++;
      }

      self::log($source, sprintf(
        'Run complete. dry_run=%s wc_candidates=%d wc_actions=%d pay_candidates=%d pay_actions=%d (min_age=%dm max_age=%dd limit=%d)',
        $dry_run ? 'true' : 'false',
        count($wc_candidates), $wc_actions,
        count($pay_candidates), $pay_actions,
        $min_age, $max_age, $limit
      ));

      return [
        'dry_run'        => $dry_run,
        'wc_candidates'  => count($wc_candidates),
        'wc_actions'     => $wc_actions,
        'pay_candidates' => count($pay_candidates),
        'pay_actions'    => $pay_actions,
      ];

    } finally {
      delete_transient(self::LOCK_TRANSIENT);
    }
  }

  // ---- WC on-hold candidates ----
  private static function find_wc_on_hold_candidates(int $min_ts, int $max_ts, int $limit): array {
    // want: older than min_ts AND newer than max_ts
    $date_created = [
      'after'     => gmdate('Y-m-d H:i:s', $max_ts),
      'before'    => gmdate('Y-m-d H:i:s', $min_ts),
      'inclusive' => true,
    ];

    $orders = wc_get_orders([
      'limit'        => $limit,
      'status'       => ['on-hold'], // you said on-hold specifically
      'orderby'      => 'date',
      'order'        => 'ASC',
      'date_created' => $date_created,
      'meta_query'   => [
        'relation' => 'OR',
        [ 'key' => self::ORDER_META_KIOSK_ID,   'compare' => 'EXISTS' ],
        [ 'key' => self::ORDER_META_SESSION_ID, 'compare' => 'EXISTS' ],
        [ 'key' => self::ORDER_META_KIOSK_REF,  'compare' => 'EXISTS' ],
      ],
    ]);

    return is_array($orders) ? $orders : [];
  }

  private static function process_wc_order(int $order_id, bool $dry_run, int $user_id): bool {
    $order = wc_get_order($order_id);
    if (!$order) return false;

    // Don't repeat
    if ( $order->get_meta(self::ORDER_META_CLEANED_AT, true) ) return false;

    // Safety: never cancel if paid
    if ($order->get_date_paid() || $order->get_transaction_id()) return false;

    // Safety: only cancel if still on-hold
    if ($order->get_status() !== 'on-hold') return false;

    $kiosk_id = $order->get_meta(self::ORDER_META_KIOSK_ID, true);
    $session  = $order->get_meta(self::ORDER_META_SESSION_ID, true);
    $ref      = $order->get_meta(self::ORDER_META_KIOSK_REF, true);

    $note = sprintf(
      'cleanup: stale on-hold kiosk order (kiosk=%s session=%s ref=%s)',
      $kiosk_id ?: '?',
      $session ?: '?',
      $ref ?: '?'
    );

    if ($dry_run) return true;

    $order->update_status('cancelled', '[Meadow] Auto-cancelled stale on-hold kiosk order. ' . $note);
    $order->update_meta_data(self::ORDER_META_CLEANED_AT, time());
    $order->update_meta_data(self::ORDER_META_CLEAN_NOTE, $note);
    $order->save();

    return true;
  }

  // ---- Payment CPT candidates (legacy) ----
  private static function find_payment_candidates(int $min_ts, int $max_ts, int $limit): array {
    // If you want, you can delete this whole section later.
    $q = new WP_Query([
      'post_type'      => self::PAY_POST_TYPE,
      'post_status'    => 'any',
      'posts_per_page' => $limit,
      'orderby'        => 'modified',
      'order'          => 'ASC',
      'meta_query'     => [
        'relation' => 'AND',
        [ 'key' => self::META_CLEANED_AT, 'compare' => 'NOT EXISTS' ],
      ],
      'no_found_rows'  => true,
    ]);

    $out = [];
    foreach ($q->posts as $p) {
      $ts = self::payment_updated_ts((int)$p->ID);
      if ($ts <= 0) continue;
      if ($ts > $min_ts) continue;
      if ($ts < $max_ts) continue;
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

  private static function process_payment_post(int $pay_id, bool $dry_run, int $user_id): bool {
    // Minimal: just mark payment post abandoned. We do NOT touch on-hold orders here anymore.
    if ( get_post_meta($pay_id, self::META_CLEANED_AT, true) ) return false;

    $status   = (string)get_post_meta($pay_id, self::META_STATUS, true);
    $session  = (string)get_post_meta($pay_id, self::META_SESSION_ID, true);
    $order_id = (int)get_post_meta($pay_id, self::META_ORDER_ID, true);

    $note = "cleanup: stale payment post status={$status} session={$session} order_id={$order_id}";
    if ($dry_run) return true;

    update_post_meta($pay_id, self::META_STATUS, 'abandoned');
    update_post_meta($pay_id, self::META_CLEANED_AT, time());
    update_post_meta($pay_id, self::META_CLEANED_BY, $user_id ?: 0);
    update_post_meta($pay_id, self::META_CLEAN_NOTE, $note);

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
