<?php
/**
 * Meadow Order Cleanup
 *
 * What it does:
 *  - Cancels stale on-hold WooCommerce kiosk orders (HPOS-safe)
 *  - Optionally marks stale meadow_payment posts as "abandoned" (does not touch orders)
 *  - Provides Tools -> Meadow Order Cleanup admin UI + hourly cron
 *
 * Safety:
 *  - Only touches orders with _meadow_order_type = 'kiosk'
 *  - Never cancels paid orders (date_paid or transaction_id present)
 *  - Default min age is conservative (20 minutes)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('Meadow_Order_Cleanup', false) ) {

  class Meadow_Order_Cleanup {

    const CRON_HOOK = 'meadow_kiosk_order_cleanup_cron';
    const CRON_SCHEDULE = 'hourly';

    const LOCK_TRANSIENT = 'meadow_kiosk_cleanup_lock';
    const LOCK_TTL = 60;

    const LOG_OPTION = 'meadow_kiosk_cleanup_log';
    const LOG_MAX = 250;

    // Defaults
    const DEFAULT_MIN_AGE_MINUTES = 20;
    const DEFAULT_MAX_AGE_DAYS    = 14;
    const DEFAULT_LIMIT           = 50;

    // Order meta marker (this already exists in your flow)
    const ORDER_META_ORDER_TYPE = '_meadow_order_type'; // = 'kiosk'

    // Helpful order meta keys (shown in notes / logs)
    const ORDER_META_KIOSK_ID   = '_meadow_kiosk_id';
    const ORDER_META_MOTOR      = '_meadow_motor';
    const ORDER_META_REFERENCE  = '_meadow_reference';
    const ORDER_META_SESSION_ID = '_meadow_session_id';

    // Optional: if you ever want to mark "cleaned"
    const ORDER_META_CLEANED_AT = '_meadow_cleanup_cancelled_at';
    const ORDER_META_CLEAN_NOTE = '_meadow_cleanup_cancel_note';

    // meadow_payment CPT (optional marking)
    const PAY_POST_TYPE   = 'meadow_payment';
    const PAY_META_STATUS = '_meadow_status';
    const PAY_META_ORDER  = '_meadow_order_id';
    const PAY_META_UPDATED_AT = '_meadow_updated_at';

    const PAY_META_CLEANED_AT   = '_meadow_cleaned_at';
    const PAY_META_CLEANED_NOTE = '_meadow_clean_note';

    public static function init(): void {
      static $did = false;
      if ($did) return;
      $did = true;

      add_action('init', [__CLASS__, 'schedule_cron']);
      add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);

      if (is_admin()) {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_meadow_cleanup_run', [__CLASS__, 'handle_admin_run']);
      }
    }

    /* -------------------- Cron -------------------- */

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
      self::run_cleanup([
        'source' => 'cron',
        'dry_run' => false,
        'limit' => self::DEFAULT_LIMIT,
        'min_age' => self::DEFAULT_MIN_AGE_MINUTES,
        'max_age' => self::DEFAULT_MAX_AGE_DAYS,
        'cancel_on_hold_orders' => true,
        'mark_stale_payments' => true,
        'user_id' => 0,
      ]);
    }

    /* -------------------- Admin UI -------------------- */

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

      $ran = isset($_GET['ran']) ? (int)$_GET['ran'] : 0;
      if ($ran) {
        $dry = !empty($_GET['dry']);
        $msg = sprintf(
          'Last run: dry_run=%s | order_candidates=%s order_actions=%s | pay_candidates=%s pay_actions=%s',
          $dry ? 'true' : 'false',
          isset($_GET['oc']) ? (int)$_GET['oc'] : 0,
          isset($_GET['oa']) ? (int)$_GET['oa'] : 0,
          isset($_GET['pc']) ? (int)$_GET['pc'] : 0,
          isset($_GET['pa']) ? (int)$_GET['pa'] : 0
        );
        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
      }

      echo '<div class="wrap">';
      echo '<h1>Meadow Order Cleanup</h1>';
      echo '<p>Cancels stale <strong>on-hold kiosk orders</strong> (safe: kiosk-only + unpaid-only). Optionally marks stale meadow_payment posts as abandoned.</p>';

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="meadow_cleanup_run">';
      echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';

      echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only)</label></p>';
      echo '<p><label><input type="checkbox" name="cancel_on_hold_orders" value="1" checked> Cancel stale on-hold kiosk orders</label></p>';
      echo '<p><label><input type="checkbox" name="mark_stale_payments" value="1" checked> Mark stale meadow_payment posts as abandoned</label></p>';

      echo '<p><label>Limit <input type="number" name="limit" value="' . esc_attr(self::DEFAULT_LIMIT) . '" min="1" max="500"></label></p>';
      echo '<p><label>Minimum age (minutes) <input type="number" name="min_age" value="' . esc_attr(self::DEFAULT_MIN_AGE_MINUTES) . '" min="1" max="1440"></label></p>';
      echo '<p><label>Max age (days) <input type="number" name="max_age" value="' . esc_attr(self::DEFAULT_MAX_AGE_DAYS) . '" min="1" max="365"></label></p>';

      submit_button('Run Cleanup');
      echo '</form>';

      echo '<h2>Recent Log</h2>';
      if (empty($log)) {
        echo '<p>No log entries yet.</p>';
      } else {
        echo '<table class="widefat striped"><thead><tr><th style="width:220px;">Time (UTC)</th><th style="width:90px;">Source</th><th>Summary</th></tr></thead><tbody>';
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

      $dry_run  = ! empty($_POST['dry_run']);
      $limit    = isset($_POST['limit']) ? max(1, min(500, (int)$_POST['limit'])) : self::DEFAULT_LIMIT;
      $min_age  = isset($_POST['min_age']) ? max(1, min(1440, (int)$_POST['min_age'])) : self::DEFAULT_MIN_AGE_MINUTES;
      $max_age  = isset($_POST['max_age']) ? max(1, min(365, (int)$_POST['max_age'])) : self::DEFAULT_MAX_AGE_DAYS;

      $cancel_orders = ! empty($_POST['cancel_on_hold_orders']);
      $mark_payments = ! empty($_POST['mark_stale_payments']);

      $res = self::run_cleanup([
        'source' => 'admin',
        'dry_run' => $dry_run,
        'limit' => $limit,
        'min_age' => $min_age,
        'max_age' => $max_age,
        'cancel_on_hold_orders' => $cancel_orders,
        'mark_stale_payments' => $mark_payments,
        'user_id' => get_current_user_id(),
      ]);

      wp_safe_redirect(add_query_arg([
        'page' => 'meadow-order-cleanup',
        'ran'  => 1,
        'dry'  => $dry_run ? 1 : 0,
        'oc'   => $res['order_candidates'] ?? 0,
        'oa'   => $res['order_actions'] ?? 0,
        'pc'   => $res['pay_candidates'] ?? 0,
        'pa'   => $res['pay_actions'] ?? 0,
      ], admin_url('tools.php')));
      exit;
    }

    /* -------------------- Core -------------------- */

    public static function run_cleanup(array $args): array {
      // Basic lock to avoid double-runs
      if ( get_transient(self::LOCK_TRANSIENT) ) {
        self::log((string)($args['source'] ?? 'unknown'), 'Skipped (lock present).');
        return ['skipped' => true];
      }
      set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL);

      $source = (string)($args['source'] ?? 'unknown');
      $dry_run = (bool)($args['dry_run'] ?? true);
      $limit = (int)($args['limit'] ?? self::DEFAULT_LIMIT);
      $min_age = (int)($args['min_age'] ?? self::DEFAULT_MIN_AGE_MINUTES);
      $max_age = (int)($args['max_age'] ?? self::DEFAULT_MAX_AGE_DAYS);
      $user_id = (int)($args['user_id'] ?? 0);

      $cancel_orders = (bool)($args['cancel_on_hold_orders'] ?? true);
      $mark_payments = (bool)($args['mark_stale_payments'] ?? true);

      $order_candidates = 0;
      $order_actions = 0;
      $pay_candidates = 0;
      $pay_actions = 0;

      try {
        $now = time();
        $min_ts = $now - ($min_age * 60);
        $max_ts = $now - ($max_age * 86400);

        // A) Cancel stale on-hold kiosk orders
        if ($cancel_orders) {
          $orders = self::find_stale_on_hold_kiosk_orders($min_ts, $max_ts, $limit);
          $order_candidates = count($orders);

          foreach ($orders as $order) {
            if (self::cancel_order_if_safe($order, $dry_run, $user_id)) {
              $order_actions++;
            }
          }
        }

        // B) Mark stale meadow_payment posts as abandoned (optional)
        if ($mark_payments) {
          $pays = self::find_stale_payment_posts($min_ts, $max_ts, $limit);
          $pay_candidates = count($pays);

          foreach ($pays as $p) {
            if (self::mark_payment_post_abandoned((int)$p->ID, $dry_run)) {
              $pay_actions++;
            }
          }
        }

        self::log($source, sprintf(
          'Run complete. dry_run=%s orders(candidates=%d actions=%d) payments(candidates=%d actions=%d) min_age=%dm max_age=%dd limit=%d',
          $dry_run ? 'true' : 'false',
          $order_candidates, $order_actions,
          $pay_candidates, $pay_actions,
          $min_age, $max_age, $limit
        ));

        return [
          'dry_run' => $dry_run,
          'order_candidates' => $order_candidates,
          'order_actions' => $order_actions,
          'pay_candidates' => $pay_candidates,
          'pay_actions' => $pay_actions,
        ];

      } finally {
        delete_transient(self::LOCK_TRANSIENT);
      }
    }

    /* -------------------- Orders cleanup -------------------- */

/**
 * Find stale on-hold kiosk orders within (max_age .. min_age) window.
 * HPOS-safe: use Woo date range string (NOT an array).
 */
private static function find_stale_on_hold_kiosk_orders(int $min_ts, int $max_ts, int $limit): array {
  if ( ! function_exists('wc_get_orders') ) return [];

  // Want orders created between max_ts and min_ts (older than min_age, newer than max_age).
  $after  = gmdate('Y-m-d H:i:s', $max_ts);
  $before = gmdate('Y-m-d H:i:s', $min_ts);

  // ✅ Woo range string format (HPOS-safe)
  $date_range = $after . '...' . $before;

  try {
    $orders = wc_get_orders([
      'limit'        => $limit,
      'status'       => ['on-hold'],
      'orderby'      => 'date',
      'order'        => 'ASC',
      'date_created' => $date_range,
      'meta_query'   => [
        [
          'key'     => self::ORDER_META_ORDER_TYPE,
          'value'   => 'kiosk',
          'compare' => '=',
        ],
      ],
    ]);

    return is_array($orders) ? $orders : [];
  } catch (\Throwable $e) {
    self::log('order-cleanup', 'wc_get_orders failed: ' . $e->getMessage());
    return [];
  }
}


    /**
     * Cancel an order only if it's safe (unpaid + still on-hold).
     */
    private static function cancel_order_if_safe($order, bool $dry_run, int $user_id): bool {
      if ( ! $order || ! is_a($order, 'WC_Order') ) return false;

      // Still on-hold?
      if ($order->get_status() !== 'on-hold') return false;

      // Must be kiosk order
      $otype = (string)$order->get_meta(self::ORDER_META_ORDER_TYPE, true);
      if ($otype !== 'kiosk') return false;

      // Never touch paid orders
      if ($order->get_date_paid() || $order->get_transaction_id()) return false;

      // Avoid repeating
      if ($order->get_meta(self::ORDER_META_CLEANED_AT, true)) return false;

      $kiosk_id = (string)$order->get_meta(self::ORDER_META_KIOSK_ID, true);
      $motor    = (string)$order->get_meta(self::ORDER_META_MOTOR, true);
      $ref      = (string)$order->get_meta(self::ORDER_META_REFERENCE, true);
      $sid      = (string)$order->get_meta(self::ORDER_META_SESSION_ID, true);

      $note = sprintf(
        '[Meadow] Auto-cancelled stale on-hold kiosk order. kiosk=%s motor=%s ref=%s session=%s',
        $kiosk_id !== '' ? $kiosk_id : '?',
        $motor   !== '' ? $motor   : '?',
        $ref     !== '' ? $ref     : '?',
        $sid     !== '' ? $sid     : '?'
      );

      if ($dry_run) return true;

      // Cancel + annotate
      $order->update_status('cancelled', $note);

      // Mark as cleaned so we don't repeat
      $order->update_meta_data(self::ORDER_META_CLEANED_AT, time());
      $order->update_meta_data(self::ORDER_META_CLEAN_NOTE, $note);
      if ($user_id > 0) {
        $order->add_order_note('[Meadow] Cleanup run by user_id=' . (int)$user_id);
      }
      $order->save();

      return true;
    }

    /* -------------------- meadow_payment cleanup -------------------- */

/**
 * Find stale meadow_payment posts that look genuinely stuck.
 */
private static function find_stale_payment_posts(int $min_ts, int $max_ts, int $limit): array {
  $stuck_statuses = [
    'created',
    'started',
    'pending',
    'payment_pending',
    'awaiting_payment',
    'awaiting_kiosk_payment',
    'awaiting_vend',
    'vend_pending',
    'authorising',
  ];

  $q = new WP_Query([
    'post_type'      => self::PAY_POST_TYPE,
    'post_status'    => 'any',
    'posts_per_page' => $limit,
    'orderby'        => 'modified',
    'order'          => 'ASC',
    'meta_query'     => [
      'relation' => 'AND',
      [
        'key'     => self::PAY_META_CLEANED_AT,
        'compare' => 'NOT EXISTS',
      ],
      [
        'key'     => self::PAY_META_STATUS,
        'value'   => $stuck_statuses,
        'compare' => 'IN',
      ],
    ],
    'no_found_rows'  => true,
  ]);

  $out = [];
  foreach ($q->posts as $p) {
    $ts = self::payment_updated_ts((int)$p->ID);
    if ($ts <= 0) continue;
    if ($ts > $min_ts) continue; // too recent
    if ($ts < $max_ts) continue; // too old
    $out[] = $p;
  }
  return $out;
}

/**
 * Mark payment post abandoned (only if linked order isn't paid/completed).
 */
private static function mark_payment_post_abandoned(int $pay_id, bool $dry_run): bool {
  if ( get_post_meta($pay_id, self::PAY_META_CLEANED_AT, true) ) return false;

  $status = (string) get_post_meta($pay_id, self::PAY_META_STATUS, true);

  // Never touch already-final statuses
  $final = ['paid','vended','completed','cancelled','abandoned','failed'];
  if (in_array($status, $final, true)) return false;

  // If it links to an order that is paid/processing/completed, don't touch it.
  $order_id = (int) get_post_meta($pay_id, self::PAY_META_ORDER, true);
  if ($order_id && function_exists('wc_get_order')) {
    $order = wc_get_order($order_id);
    if ($order) {
      $st = $order->get_status();
      if (in_array($st, ['processing','completed'], true)) return false;
      if ($order->get_date_paid() || $order->get_transaction_id()) return false;
    }
  }

  if ($dry_run) return true;

  update_post_meta($pay_id, self::PAY_META_STATUS, 'abandoned');
  update_post_meta($pay_id, self::PAY_META_CLEANED_AT, time());
  update_post_meta($pay_id, self::PAY_META_CLEANED_NOTE, 'cleanup: stale payment post (prev_status=' . $status . ')');

  return true;
}

    /* -------------------- Logging -------------------- */

    private static function log(string $source, string $message): void {
      $log = get_option(self::LOG_OPTION, []);
      if ( ! is_array($log) ) $log = [];

      $log[] = [
        'time' => gmdate('c'),
        'source' => $source,
        'message' => $message,
      ];

      if (count($log) > self::LOG_MAX) {
        $log = array_slice($log, -self::LOG_MAX);
      }

      update_option(self::LOG_OPTION, $log, false);
    }
  }

  // Auto-init
  add_action('plugins_loaded', ['Meadow_Order_Cleanup', 'init']);
}
