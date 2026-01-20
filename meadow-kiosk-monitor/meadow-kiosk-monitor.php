<?php
/**
 * Plugin Name: Meadow Kiosk Monitor
 * Description: Emails when a kiosk is offline or stuck in a mode (reads hhg_meadow_kiosk_state; no recovery emails).
 * Version: 2026-01-20 v2
 */

if ( ! defined('ABSPATH') ) exit;

class Meadow_Kiosk_Monitor_V2 {

  const CRON_HOOK = 'meadow_kiosk_monitor_cron_v2';
  const LOCK_KEY  = 'meadow_kiosk_monitor_lock_v2';
  const LOCK_TTL  = 45; // seconds

  static function table_name() { return 'hhg_meadow_kiosk_state'; }
  static function opt_key() { return 'meadow_kiosk_monitor_options_v2'; }

  static function defaults() {
    return [
      'email_to'        => get_option('admin_email'),

      // thresholds (seconds)
      'offline_after'   => 120,
      'stuck_payment'   => 180,
      'stuck_vending'   => 180,
      'stuck_thankyou'  => 180,
      'stuck_error'     => 120,

      // throttle
      'alert_cooldown'  => 1800, // 30 mins

      // templates
      'subject_tpl'     => '[Meadow] {kiosk_label} {issue} (mode={mode})',
      'body_tpl'        =>
        "Meadow Kiosk Alert\n\n".
        "Kiosk: {kiosk_label}\n".
        "Kiosk post ID: {kiosk_post_id}\n".
        "Issue: {issue}\n".
        "Mode: {mode}\n".
        "Order ID: {order_id}\n".
        "Last seen (UTC): {last_seen_utc}\n".
        "Mode since (UTC): {mode_since_utc}\n".
        "Age seconds: {age_seconds}\n".
        "Stuck seconds: {stuck_seconds}\n".
        "\n".
        "{admin_link}\n",

      'include_debug'   => 1,
    ];
  }

  static function opts() {
    $o = get_option(self::opt_key(), []);
    return array_merge(self::defaults(), is_array($o) ? $o : []);
  }

  static function now_utc_mysql() { return gmdate('Y-m-d H:i:s'); }

  static function init() {
    add_action('init', [__CLASS__, 'maybe_install']);
    add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_run']);

    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
  }

  static function cron_schedules($schedules) {
    if (!isset($schedules['meadow_every_minute'])) {
      $schedules['meadow_every_minute'] = [
        'interval' => 60,
        'display'  => 'Every Minute (Meadow)',
      ];
    }
    return $schedules;
  }

  static function on_activate() {
    self::maybe_install();
    self::ensure_cron();
  }

  static function on_deactivate() {
    wp_clear_scheduled_hook(self::CRON_HOOK);
    delete_transient(self::LOCK_KEY);
  }

  static function ensure_cron() {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 30, 'meadow_every_minute', self::CRON_HOOK);
    }
  }

  static function maybe_install() {
    global $wpdb;
    $table = self::table_name();

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return;

    // Ensure minimal columns exist (safe, non-fatal)
    $cols = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
    $have = [];
    foreach ($cols as $c) $have[strtolower($c['Field'])] = true;

    $alter = [];
    if (!isset($have['mode_since_utc'])) $alter[] = "ADD COLUMN `mode_since_utc` DATETIME NULL";
    if (!isset($have['last_alert_key'])) $alter[] = "ADD COLUMN `last_alert_key` VARCHAR(80) NULL";
    if (!isset($have['last_alert_utc'])) $alter[] = "ADD COLUMN `last_alert_utc` DATETIME NULL";
    if ($alter) {
      $wpdb->query("ALTER TABLE `$table` " . implode(", ", $alter));
    }

    // Helpful indexes (attempt; non-fatal if they already exist)
    $wpdb->query("CREATE INDEX `idx_mkm2_last_seen_utc` ON `$table` (`last_seen_utc`)");
    $wpdb->query("CREATE INDEX `idx_mkm2_mode_since_utc` ON `$table` (`mode_since_utc`)");
  }

  static function acquire_lock() {
    if (get_transient(self::LOCK_KEY)) return false;
    set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);
    return true;
  }
  static function release_lock() { delete_transient(self::LOCK_KEY); }

  static function mysql_utc_to_ts($mysql) {
    if (!$mysql) return 0;
    $t = strtotime($mysql . ' UTC');
    return $t ? $t : 0;
  }

  static function wp_mail_plain($to, $subject, $body) {
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    return wp_mail($to, $subject, $body, $headers);
  }

  static function render_tpl($tpl, array $vars) {
    return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function($m) use ($vars) {
      $k = strtolower($m[1]);
      return array_key_exists($k, $vars) ? (string)$vars[$k] : '';
    }, $tpl);
  }

  static function issue_label($issue) {
    switch ($issue) {
      case 'offline': return 'OFFLINE';
      case 'stuck_payment': return 'STUCK_PAYMENT';
      case 'stuck_vending': return 'STUCK_VENDING';
      case 'stuck_thankyou': return 'STUCK_THANKYOU';
      case 'stuck_error': return 'STUCK_ERROR';
      default: return strtoupper((string)$issue);
    }
  }

  static function kiosk_label_from_row(array $r) {
  $post_id = (int)($r['kiosk_post_id'] ?? 0);
  if ($post_id > 0) {
    $title = get_the_title($post_id);
    if (is_string($title) && trim($title) !== '') {
      return trim($title); // e.g. "Kiosk 1"
    }
  }

  // Fallback to kiosk_id if present
  $kid = isset($r['kiosk_id']) ? (int)$r['kiosk_id'] : 0;
  if ($kid > 0) return 'Kiosk #' . $kid;

  return 'Kiosk Post #' . $post_id;
}


  static function cron_run() {
    global $wpdb;
    if (!self::acquire_lock()) return;

    try {
      $opts = self::opts();
      $to = trim((string)$opts['email_to']);
      if ($to === '') return;

      $table = self::table_name();
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if ($exists !== $table) return;

      $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
      if (!$rows) return;

      $now_ts = time();

      $offline_after  = max(30, (int)$opts['offline_after']);
      $cooldown       = max(60, (int)$opts['alert_cooldown']);

      $stuck_payment  = max(30, (int)$opts['stuck_payment']);
      $stuck_vending  = max(30, (int)$opts['stuck_vending']);
      $stuck_thankyou = max(30, (int)$opts['stuck_thankyou']);
      $stuck_error    = max(30, (int)$opts['stuck_error']);

      foreach ($rows as $r) {
        $kiosk_post_id = (int)($r['kiosk_post_id'] ?? 0);
        if ($kiosk_post_id <= 0) continue;

        $mode = strtolower((string)($r['screen_mode'] ?? ''));
        if ($mode === '') $mode = 'ads';

        $order_id = (int)($r['screen_order_id'] ?? 0);

        $last_seen_ts = self::mysql_utc_to_ts($r['last_seen_utc'] ?? '');
        $mode_since_ts = self::mysql_utc_to_ts($r['mode_since_utc'] ?? '');

        // Fallback: if mode_since_utc missing/null, approximate from updated_utc then last_seen_utc
        if (!$mode_since_ts) {
          $mode_since_ts = self::mysql_utc_to_ts($r['updated_utc'] ?? '');
          if (!$mode_since_ts) $mode_since_ts = $last_seen_ts;
        }

        $age = $last_seen_ts ? ($now_ts - $last_seen_ts) : 999999;
        $stuck_for = $mode_since_ts ? ($now_ts - $mode_since_ts) : 0;

        $issue = null;

        if (!$last_seen_ts || $age > $offline_after) {
          $issue = 'offline';
        } else {
          if ($mode === 'payment'  && $stuck_for > $stuck_payment)  $issue = 'stuck_payment';
          else if ($mode === 'vending'  && $stuck_for > $stuck_vending)  $issue = 'stuck_vending';
          else if ($mode === 'thankyou' && $stuck_for > $stuck_thankyou) $issue = 'stuck_thankyou';
          else if ($mode === 'error'    && $stuck_for > $stuck_error)    $issue = 'stuck_error';
        }

        if (!$issue) continue;

        $issue_label = self::issue_label($issue);

        // Throttle per kiosk_post_id + issue + mode
        $alert_key = $kiosk_post_id . '|' . $issue_label . '|' . $mode;
        $last_key = (string)($r['last_alert_key'] ?? '');
        $last_alert_ts = self::mysql_utc_to_ts($r['last_alert_utc'] ?? '');

        if ($last_key === $alert_key && $last_alert_ts && ($now_ts - $last_alert_ts) < $cooldown) {
          continue;
        }

        $kiosk_label = self::kiosk_label_from_row($r);
        $admin_link = '';
        if (function_exists('get_edit_post_link')) {
          $url = get_edit_post_link($kiosk_post_id, '');
          if ($url) $admin_link = 'Admin: ' . $url;
        }

        $vars = [
          'kiosk_label'    => $kiosk_label,
          'kiosk_post_id'  => (string)$kiosk_post_id,
          'kiosk_id'       => (string)((int)($r['kiosk_id'] ?? 0)),
          'issue'          => $issue_label,
          'mode'           => $mode,
          'order_id'       => (string)$order_id,
          'last_seen_utc'  => (string)($r['last_seen_utc'] ?? ''),
          'mode_since_utc' => (string)($r['mode_since_utc'] ?? ''),
          'age_seconds'    => (string)$age,
          'stuck_seconds'  => (string)$stuck_for,
          'admin_link'     => $admin_link,

          // debug fields (only used if your template references them)
          'config_version'    => (string)($r['config_version'] ?? ''),
          'sigma_terminal_id' => (string)($r['sigma_terminal_id'] ?? ''),
          'sigma_imei'        => (string)($r['sigma_imei'] ?? ''),
          'revision'          => (string)($r['revision'] ?? ''),
          'updated_utc'       => (string)($r['updated_utc'] ?? ''),
        ];

        $subject = self::render_tpl((string)$opts['subject_tpl'], $vars);
        $body    = self::render_tpl((string)$opts['body_tpl'], $vars);

        if (empty($opts['include_debug'])) {
          // crude stripping if people kept debug vars in body template
          $body = preg_replace('/^Config:.*\n/m', '', $body);
          $body = preg_replace('/^Revision:.*\n/m', '', $body);
          $body = preg_replace('/^Sigma.*\n/m', '', $body);
        }

        self::wp_mail_plain($to, $subject, $body);

        // Persist last alert (conservative: avoid loops even if mail fails)
        $wpdb->update(
          $table,
          [
            'last_alert_key' => $alert_key,
            'last_alert_utc' => self::now_utc_mysql(),
          ],
          [ 'kiosk_post_id' => $kiosk_post_id ]
        );
      }

    } finally {
      self::release_lock();
    }
  }

  static function admin_menu() {
    add_management_page(
      'Meadow Kiosk Monitor',
      'Meadow Kiosk Monitor',
      'manage_options',
      'meadow-kiosk-monitor',
      [__CLASS__, 'admin_page']
    );
  }

  static function admin_page() {
    if (!current_user_can('manage_options')) return;

    self::ensure_cron();
    $opts = self::opts();

    if (isset($_POST['mkm2_save']) && check_admin_referer('mkm2_save')) {
      $new = [
        'email_to'        => sanitize_text_field($_POST['email_to'] ?? ''),
        'offline_after'   => max(30, (int)($_POST['offline_after'] ?? 120)),
        'stuck_payment'   => max(30, (int)($_POST['stuck_payment'] ?? 180)),
        'stuck_vending'   => max(30, (int)($_POST['stuck_vending'] ?? 180)),
        'stuck_thankyou'  => max(30, (int)($_POST['stuck_thankyou'] ?? 180)),
        'stuck_error'     => max(30, (int)($_POST['stuck_error'] ?? 120)),
        'alert_cooldown'  => max(60, (int)($_POST['alert_cooldown'] ?? 1800)),
        'subject_tpl'     => sanitize_text_field($_POST['subject_tpl'] ?? self::defaults()['subject_tpl']),
        'body_tpl'        => (string)($_POST['body_tpl'] ?? self::defaults()['body_tpl']),
        'include_debug'   => empty($_POST['include_debug']) ? 0 : 1,
      ];
      update_option(self::opt_key(), $new);
      $opts = self::opts();
      echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    if (isset($_POST['mkm2_test']) && check_admin_referer('mkm2_test')) {
      $ok = self::wp_mail_plain($opts['email_to'], '[Meadow] Test alert', "Test email from Meadow Kiosk Monitor v2.\nUTC: " . self::now_utc_mysql());
      echo $ok
        ? '<div class="notice notice-success"><p>Test email sent (wp_mail returned true).</p></div>'
        : '<div class="notice notice-error"><p>Test email failed (wp_mail returned false). Configure SMTP.</p></div>';
    }

    if (isset($_POST['mkm2_run_now']) && check_admin_referer('mkm2_run_now')) {
      self::cron_run();
      echo '<div class="notice notice-info"><p>Monitor check executed.</p></div>';
    }

    $vars = '{kiosk_label} {kiosk_post_id} {kiosk_id} {issue} {mode} {order_id} {last_seen_utc} {mode_since_utc} {age_seconds} {stuck_seconds} {admin_link} {config_version} {revision} {sigma_terminal_id} {sigma_imei} {updated_utc}';

    ?>
    <div class="wrap">
      <h1>Meadow Kiosk Monitor (v2)</h1>

      <form method="post">
        <?php wp_nonce_field('mkm2_save'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="email_to">Alert recipients</label></th>
            <td>
              <input type="text" id="email_to" name="email_to" class="regular-text" value="<?php echo esc_attr($opts['email_to']); ?>" />
              <p class="description">Comma-separated allowed (depends on mail setup). Use SMTP for reliability.</p>
            </td>
          </tr>

          <tr><th scope="row">Offline after (seconds)</th><td><input type="number" name="offline_after" value="<?php echo esc_attr($opts['offline_after']); ?>"></td></tr>
          <tr><th scope="row">Payment stuck after (seconds)</th><td><input type="number" name="stuck_payment" value="<?php echo esc_attr($opts['stuck_payment']); ?>"></td></tr>
          <tr><th scope="row">Vending stuck after (seconds)</th><td><input type="number" name="stuck_vending" value="<?php echo esc_attr($opts['stuck_vending']); ?>"></td></tr>
          <tr><th scope="row">Thankyou stuck after (seconds)</th><td><input type="number" name="stuck_thankyou" value="<?php echo esc_attr($opts['stuck_thankyou']); ?>"></td></tr>
          <tr><th scope="row">Error stuck after (seconds)</th><td><input type="number" name="stuck_error" value="<?php echo esc_attr($opts['stuck_error']); ?>"></td></tr>
          <tr><th scope="row">Alert cooldown (seconds)</th><td><input type="number" name="alert_cooldown" value="<?php echo esc_attr($opts['alert_cooldown']); ?>"></td></tr>

          <tr>
            <th scope="row">Subject template</th>
            <td>
              <input type="text" name="subject_tpl" class="large-text" value="<?php echo esc_attr($opts['subject_tpl']); ?>">
              <p class="description">Available vars: <?php echo esc_html($vars); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row">Body template</th>
            <td>
              <textarea name="body_tpl" class="large-text code" rows="12"><?php echo esc_textarea($opts['body_tpl']); ?></textarea>
              <p class="description">Available vars: <?php echo esc_html($vars); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row">Include debug vars</th>
            <td>
              <label><input type="checkbox" name="include_debug" value="1" <?php checked(!empty($opts['include_debug'])); ?>> Keep debug fields in emails</label>
            </td>
          </tr>
        </table>

        <p><button type="submit" name="mkm2_save" class="button button-primary">Save</button></p>
      </form>

      <hr>

      <form method="post" style="display:inline-block; margin-right:10px;">
        <?php wp_nonce_field('mkm2_test'); ?>
        <button type="submit" name="mkm2_test" class="button">Send test email</button>
      </form>

      <form method="post" style="display:inline-block;">
        <?php wp_nonce_field('mkm2_run_now'); ?>
        <button type="submit" name="mkm2_run_now" class="button">Run monitor check now</button>
      </form>

      <p style="margin-top:16px;">
        <strong>Note:</strong> This monitor reads <code>screen_mode</code>/<code>screen_order_id</code> and <code>last_seen_utc</code> from <code>hhg_meadow_kiosk_state</code>.
        For best stuck-mode accuracy, ensure Meadow Core writes <code>mode_since_utc</code> when <code>screen_mode</code> changes.
      </p>
    </div>
    <?php
  }
}

Meadow_Kiosk_Monitor_V2::init();
