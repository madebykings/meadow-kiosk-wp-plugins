<?php
if ( ! defined('ABSPATH') ) exit;

class Meadow_Kiosk_Core {

    const DB_VERSION = '2026-01-20-kiosk-monitor-columns';
    const PAY_POST_TYPE = 'meadow_payment';

    // JetEngine repeater meta
    const SLOT_REPEATER_META_KEY = '_meadow_kiosk_slots';

    // Repeater row field names:
    const SLOT_FIELD_MOTOR    = '_meadow_motor_number';
    const SLOT_FIELD_PRODUCT  = '_meadow_wc_product_id';
    const SLOT_FIELD_CAPACITY = '_meadow_capacity';
    const SLOT_FIELD_STOCK    = '_meadow_current_stock';
    const SLOT_FIELD_ENABLED  = '_meadow_enabled';

    // Optional per-row fields
    const SLOT_FIELD_GPIO_PIN  = '_meadow_gpio_pin';
    const SLOT_FIELD_SPIN_TIME = '_meadow_spin_time';

    // Ads
    const AD_POST_TYPE = 'ad';
    const RELATION_AD_TO_KIOSK_ID = 10;
    const TAX_KIOSK_SEGMENT = 'kiosk_segment';
    const TAX_AD_SEGMENT    = 'ad_segment';

    public function __construct() {

        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'plugins_loaded', [ $this, 'install_or_upgrade_db' ] );

        // Admin filtering
        add_action( 'pre_get_posts', [ $this, 'filter_kiosk_list_for_venues' ] );
        add_action( 'pre_get_posts', [ $this, 'filter_ads_for_advertisers' ] );

        // Legacy WC flow (keep if any legacy checkout remains)
        add_action( 'woocommerce_payment_complete',       [ $this, 'handle_order_completed' ] );
        //add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ] );

        // Ads: subscription linking + kiosk limit enforcement
        add_action( 'add_meta_boxes', [ $this, 'add_ad_subscription_metabox' ] );
        add_action( 'save_post', [ $this, 'save_ad_subscription_meta' ], 10, 2 );

        // Ads: product meta (venue limit)
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_product_kiosk_limit_field' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_kiosk_limit_field' ] );

        // Ads: keep dates in sync on subscription lifecycle
        add_action( 'woocommerce_subscription_status_updated', [ $this, 'on_subscription_status_updated' ], 10, 3 );
        add_action( 'woocommerce_subscription_renewal_payment_complete', [ $this, 'on_subscription_renewal_payment_complete' ], 10, 2 );

        // Kiosk page globals (so JS can read kiosk_id/api_key/pi_base)
        add_action( 'wp_head', [ $this, 'inject_kiosk_globals' ], 20 );

        // Ensure kiosk URLs are never cached
        add_action( 'template_redirect', [ $this, 'nocache_kiosk_paths' ], 0 );

        // Virtual meta bridge
        add_filter( 'get_post_metadata', [ $this, 'filter_virtual_kiosk_meta' ], 10, 4 );
        add_filter( 'update_post_metadata', [ $this, 'filter_update_kiosk_meta' ], 10, 5 );
    }

    private function table_name() {
        return meadow_kiosk_table_name();
    }

    private function utc_now_mysql() {
        return gmdate('Y-m-d H:i:s');
    }

    public function install_or_upgrade_db() {
        global $wpdb;

        $installed = get_option('meadow_db_version');
        if ($installed === self::DB_VERSION) return;

        $table = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            kiosk_post_id BIGINT(20) UNSIGNED NOT NULL,
            screen_mode VARCHAR(32) NOT NULL DEFAULT '',
            screen_order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            idle_timeout INT(11) NOT NULL DEFAULT 0,
            thankyou_timeout INT(11) NOT NULL DEFAULT 0,

            last_seen_utc DATETIME NULL,
            config_version VARCHAR(64) NULL,
            sigma_terminal_id VARCHAR(64) NULL,
            sigma_imei VARCHAR(64) NULL,

            revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            updated_utc DATETIME NOT NULL,
            created_utc DATETIME NOT NULL,

            PRIMARY KEY (kiosk_post_id),
            KEY idx_last_seen_utc (last_seen_utc),
            KEY idx_updated_utc (updated_utc),
            KEY idx_screen_mode (screen_mode)
        ) {$charset_collate};";

        dbDelta($sql);

        // Ensure monitoring columns exist (safe + compatible)
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $cols = array_map('strtolower', is_array($cols) ? $cols : []);

        if (!in_array('mode_since_utc', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `mode_since_utc` DATETIME NULL");
        }
        if (!in_array('last_alert_key', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `last_alert_key` VARCHAR(80) NULL");
        }
        if (!in_array('last_alert_utc', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `last_alert_utc` DATETIME NULL");
        }

        update_option('meadow_db_version', self::DB_VERSION);

    }

    private function dual_write_enabled() {
        return (bool) apply_filters('meadow_state_dual_write', true);
    }

    private function meta_fallback_enabled() {
        return (bool) apply_filters('meadow_state_meta_fallback', true);
    }

    private function get_kiosk_row($kiosk_post_id) {
        global $wpdb;
        $table = $this->table_name();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE kiosk_post_id = %d", $kiosk_post_id),
            ARRAY_A
        );
    }

    private function upsert_kiosk_row($kiosk_post_id, array $data) {
        global $wpdb;
        $table = $this->table_name();

        $row = $this->get_kiosk_row($kiosk_post_id);
        $now = $this->utc_now_mysql();

        // Track when the (screen) mode last changed for stuck-mode alerts.
        if (isset($data['screen_mode'])) {
            $new_mode  = strtolower(trim((string)$data['screen_mode']));
            $prev_mode = ($row && isset($row['screen_mode'])) ? strtolower(trim((string)$row['screen_mode'])) : '';

            if (!$row || ($new_mode !== '' && $new_mode !== $prev_mode)) {
                $data['mode_since_utc'] = $now;
            }
        }

        if (!$row) {
            $data = array_merge([
                'kiosk_post_id' => $kiosk_post_id,
                'revision' => 0,
                'updated_utc' => $now,
                'created_utc' => $now,
            ], $data);

            $wpdb->insert($table, $data);
            return;
        }

        $data['revision'] = (int)$row['revision'] + 1;
        $data['updated_utc'] = $now;

        $wpdb->update($table, $data, [ 'kiosk_post_id' => $kiosk_post_id ]);
    }

    private function screen_get_payload($kiosk_post_id) {
        $row = $this->get_kiosk_row($kiosk_post_id);

        if ($row) {
            return [
                'mode'             => $row['screen_mode'] !== '' ? $row['screen_mode'] : 'ads',
                'order_id'         => (int) $row['screen_order_id'],
                'idle_timeout'     => (int) $row['idle_timeout'],
                'thankyou_timeout' => (int) $row['thankyou_timeout'],
                'revision'         => (int) $row['revision'],
            ];
        }

        if (!$this->meta_fallback_enabled()) {
            return [
                'mode' => 'ads',
                'order_id' => 0,
                'idle_timeout' => 0,
                'thankyou_timeout' => 0,
                'revision' => 0,
            ];
        }

        return [
            'mode'             => get_post_meta($kiosk_post_id,'_meadow_screen_mode',true) ?: 'ads',
            'order_id'         => (int) get_post_meta($kiosk_post_id,'_meadow_screen_order_id',true),
            'idle_timeout'     => (int) get_post_meta($kiosk_post_id,'_meadow_idle_timeout',true),
            'thankyou_timeout' => (int) get_post_meta($kiosk_post_id,'_meadow_thankyou_timeout',true),
        ];
    }

    private function screen_set_payload($kiosk_post_id, $mode, $order_id = 0) {
        $payload = $this->screen_get_payload($kiosk_post_id);

        $data = [
            'screen_mode' => (string) $mode,
            'screen_order_id' => (int) $order_id,
            'idle_timeout' => (int) $payload['idle_timeout'],
            'thankyou_timeout' => (int) $payload['thankyou_timeout'],
        ];

        $this->upsert_kiosk_row($kiosk_post_id, $data);

        if ($this->dual_write_enabled()) {
            update_post_meta($kiosk_post_id,'_meadow_screen_mode',(string)$mode);
            if ((int)$order_id) update_post_meta($kiosk_post_id,'_meadow_screen_order_id',(int)$order_id);
        }
    }

    private function heartbeat_touch($kiosk_post_id, $git = '', $sigma_terminal_id = '', $sigma_imei = '') {
        $data = [
            'last_seen_utc' => $this->utc_now_mysql(),
        ];
        if ($git !== '') $data['config_version'] = sanitize_text_field($git);
        if ($sigma_terminal_id !== '') $data['sigma_terminal_id'] = sanitize_text_field($sigma_terminal_id);
        if ($sigma_imei !== '') $data['sigma_imei'] = sanitize_text_field($sigma_imei);

        $this->upsert_kiosk_row($kiosk_post_id, $data);

        if ($this->dual_write_enabled()) {
            update_post_meta($kiosk_post_id, '_meadow_last_seen', time());
            if ($git !== '') update_post_meta($kiosk_post_id, '_meadow_config_version', sanitize_text_field($git));
            if ($sigma_terminal_id !== '') update_post_meta($kiosk_post_id, '_meadow_sigma_terminal_id', sanitize_text_field($sigma_terminal_id));
            if ($sigma_imei !== '') update_post_meta($kiosk_post_id, '_meadow_sigma_imei', sanitize_text_field($sigma_imei));
        }
    }

    private function nocache() {
        meadow_kiosk_nocache_headers();
    }

        /**
     * JetEngine repeater safety: ensure rows are valid arrays and keys are 0..n-1.
     * Prevents admin UI oddities caused by gappy numeric indexes.
     */
    private function normalize_slots_array( $slots ) {
        if ( ! is_array( $slots ) ) return [];

        // Keep only rows that are arrays
        $slots = array_filter( $slots, 'is_array' );

        // Reindex to 0..n-1 (critical)
        $slots = array_values( $slots );

        return $slots;
    }

    private function bool_param($v) {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function slot_enabled($raw): bool {
        if (is_array($raw)) {
            if (array_key_exists('value', $raw)) $raw = $raw['value'];
            elseif (array_key_exists('checked', $raw)) $raw = $raw['checked'];
            elseif (array_key_exists('raw', $raw)) $raw = $raw['raw'];
            else $raw = reset($raw);
        }

        if ($raw === '' || $raw === null) return true;

        if (is_bool($raw)) return $raw;
        if (is_int($raw))  return $raw === 1;
        if (is_float($raw)) return ((int)$raw) === 1;

        $s = strtolower(trim((string)$raw));
        if ($s === '') return true;

        if (in_array($s, ['false','no','n','off','disabled','disable','unchecked','0'], true)) return false;

        return in_array($s, ['1','true','yes','y','on','enabled','enable','checked'], true);

    }

    private function meta_enabled($raw, $default = true): bool {
        if (is_array($raw)) {
            if (array_key_exists('value', $raw)) $raw = $raw['value'];
            elseif (array_key_exists('checked', $raw)) $raw = $raw['checked'];
            elseif (array_key_exists('raw', $raw)) $raw = $raw['raw'];
            else $raw = reset($raw);
        }

        if ($raw === '' || $raw === null) return (bool)$default;
        if (is_bool($raw)) return $raw;
        if (is_int($raw))  return $raw === 1;
        if (is_float($raw)) return ((int)$raw) === 1;

        $s = strtolower(trim((string)$raw));
        if ($s === '') return (bool)$default;

        if (in_array($s, ['false','no','n','off','disabled','disable','unchecked','0'], true)) return false;
        return in_array($s, ['1','true','yes','y','on','enabled','enable','checked'], true);
    }

    private function ensure_wc_loaded() {
        if ( ! function_exists('wc_get_product') || ! function_exists('wc_create_order') ) {
            return new WP_Error('server_error','WooCommerce not available',[ 'status'=>500 ]);
        }
        return true;
    }

    private function send_stock_alert( $kiosk_id, $motor_number, $new_stock, $type = 'out', $extra = [] ) {
        $kiosk_id = (int) $kiosk_id;
        $motor_number = (int) $motor_number;

        if ( $kiosk_id <= 0 || $motor_number <= 0 ) return;

        // Pull alert recipient from kiosk CPT meta
        $to = trim( (string) get_post_meta( $kiosk_id, '_meadow_stock_alert_email', true ) );
        if ( empty( $to ) || ! is_email( $to ) ) return;

        $type = strtolower( (string) $type );
        if ( $type !== 'low' && $type !== 'out' ) $type = 'out';

        // Prevent spam: one alert per kiosk+motor+type per 12h
        $lock_key = 'meadow_stock_alert_' . $type . '_' . $kiosk_id . '_' . $motor_number;
        if ( get_transient( $lock_key ) ) return;
        set_transient( $lock_key, 1, 12 * HOUR_IN_SECONDS );

        $kiosk_title = get_the_title( $kiosk_id );
        if ( ! $kiosk_title ) $kiosk_title = 'Kiosk #' . $kiosk_id;

        $subject = sprintf(
            '[Meadow] %s: Row %d %s',
            $kiosk_title,
            $motor_number,
            ($type === 'low' ? 'low stock' : 'out of stock')
        );

        $lines = [];
        $lines[] = 'Kiosk: ' . $kiosk_title . ' (ID ' . $kiosk_id . ')';
        $lines[] = 'Row/Motor: ' . $motor_number;
        $lines[] = 'Stock: ' . (int) $new_stock;

        // If we got a product_id but no product_name, resolve it
        if ( empty($extra['product_name']) && !empty($extra['product_id']) && function_exists('wc_get_product') ) {
            $p = wc_get_product( (int) $extra['product_id'] );
            if ( $p ) {
                $extra['product_name'] = $p->get_name();
            }
        }

        // Optional extra context if you have it (product, order, etc.)
        if ( ! empty( $extra['product_name'] ) ) {
            $lines[] = 'Product: ' . (string) $extra['product_name'];
        }
        if ( ! empty( $extra['order_id'] ) ) {
            $lines[] = 'Order: #' . (int) $extra['order_id'];
        }
        if ( ! empty( $extra['site'] ) ) {
            $lines[] = 'Site: ' . (string) $extra['site'];
        }

        $message = implode( "\n", $lines ) . "\n";

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        // wp_mail returns bool; we intentionally don't fatal if it fails
        wp_mail( $to, $subject, $message, $headers );
    }

    private function get_slot_by_motor( $kiosk_post_id, $motor ) {
    $motor = (int) $motor;

    $slots = get_post_meta($kiosk_post_id, self::SLOT_REPEATER_META_KEY, true);
    $slots = $this->normalize_slots_array( $slots ); // ✅ critical

    foreach ( $slots as $idx => $row ) {
        if ( is_array($row) && (int) ($row[self::SLOT_FIELD_MOTOR] ?? 0) === $motor ) {
            return [ (int)$idx, $row, $slots ];
        }
    }
    return [ null, null, $slots ];
}


    /* ---------------------------------------------
     * CPT: payment sessions
     * ------------------------------------------- */

    public function register_post_types() {
        if ( ! post_type_exists( self::PAY_POST_TYPE ) ) {
            register_post_type( self::PAY_POST_TYPE, [
                'label'       => 'Meadow Payments',
                'public'      => false,
                'show_ui'     => true,
                'supports'    => [ 'title' ],
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            ] );
        }
    }

    /* ---------------------------------------------
     * REST ROUTES (Core only; Pi routes moved to Pi Bridge plugin)
     * ------------------------------------------- */

    public function register_rest_routes() {

        register_rest_route( 'meadow/v1', '/kiosk-config', [
            'methods'  => 'GET',
            'callback' => [ $this, 'rest_kiosk_config' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/kiosk-heartbeat', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_kiosk_heartbeat' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/ad-impression', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_ad_impression' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/kiosk-ads', [
            'methods'  => 'GET',
            'callback' => [ $this, 'rest_kiosk_ads' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/kiosk-screen', [
            'methods'  => [ 'GET', 'POST' ],
            'callback' => [ $this, 'rest_kiosk_screen' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/start-payment', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_start_payment' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/payment-result', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_payment_result' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'meadow/v1', '/vend-result', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_vend_result' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route('meadow/v1', '/venue/restock', [
  'methods'  => 'POST',
  'callback' => [$this, 'rest_venue_restock'],
    'permission_callback' => function () {
    if ( ! is_user_logged_in() ) return false;
    $u = wp_get_current_user();
    return user_can($u, 'manage_options') || in_array('venue', (array)$u->roles, true);
  },
]);


    }

/* ---------------------------------------------
 * REST: venue restock (NEW – MUST BE STANDALONE)
 * ------------------------------------------- */
public function rest_venue_restock( WP_REST_Request $req ) {

  $user = wp_get_current_user();
  if ( ! $user || ! $user->ID ) {
    return new WP_REST_Response([ 'ok' => false, 'error' => 'not_logged_in' ], 401);
  }

  $is_admin = user_can($user, 'manage_options');
  $is_venue = in_array('venue', (array)$user->roles, true);

  if ( ! $is_admin && ! $is_venue ) {
    return new WP_REST_Response([ 'ok' => false, 'error' => 'not_allowed' ], 403);
  }

  $params  = $req->get_json_params();
  $updates = $params['updates'] ?? null;

  if ( ! is_array($updates) || empty($updates) ) {
    return new WP_REST_Response([ 'ok' => false, 'error' => 'missing_updates' ], 400);
  }

  // Find kiosk linked to this venue
  $q = new WP_Query([
    'post_type'      => 'kiosk',
    'posts_per_page' => 1,
    'post_status'    => 'any',
    'meta_key'       => '_meadow_venue_user_id',
    'meta_value'     => (string)$user->ID,
    'no_found_rows'  => true,
  ]);

  $kiosk_id = $q->posts[0]->ID ?? 0;
  if ( ! $kiosk_id ) {
    return new WP_REST_Response([ 'ok' => false, 'error' => 'kiosk_not_found' ], 404);
  }

  // Load repeater
  $slots = get_post_meta($kiosk_id, self::SLOT_REPEATER_META_KEY, true);

  // Normalize slots to prevent "ghost rows" caused by gappy numeric keys / non-array rows
  $before_count = is_array($slots) ? count($slots) : 0;
  $before_keys  = is_array($slots) ? implode(',', array_keys($slots)) : '';
  $slots = $this->normalize_slots_array( $slots );

  // Optional: log if normalization changed something (safe to remove later)
  if ( $before_count && (count($slots) !== $before_count || ($before_keys !== '' && $before_keys !== implode(',', array_keys($slots))) ) ) {
    error_log('[Meadow] rest_venue_restock: normalized slots (count/keys changed) kiosk_id=' . $kiosk_id . ' before_count=' . $before_count . ' after_count=' . count($slots) . ' before_keys=' . $before_keys . ' after_keys=' . implode(',', array_keys($slots)));
  }

  // Map motor → repeater index
  $motor_to_idx = [];
  foreach ($slots as $idx => $row) {
    if ( ! is_array($row) ) continue;
    $m = intval($row[self::SLOT_FIELD_MOTOR] ?? 0);
    if ($m > 0) $motor_to_idx[$m] = (int)$idx;
  }

  $changed = [];
  foreach ($updates as $u) {
    $motor = intval($u['motor'] ?? 0);
    $stock = isset($u['stock']) ? intval($u['stock']) : null;

    if ($motor <= 0 || $stock === null) continue;
    if (!isset($motor_to_idx[$motor])) continue;

    if ($stock < 0) $stock = 0;

    $idx = $motor_to_idx[$motor];
    if ( ! isset($slots[$idx]) || ! is_array($slots[$idx]) ) continue;

    $slots[$idx][ self::SLOT_FIELD_STOCK ] = $stock;

    $changed[] = [ 'motor' => $motor, 'stock' => $stock ];
  }

  // Normalize again right before save (belt & braces)
  $slots = $this->normalize_slots_array( $slots );

  update_post_meta($kiosk_id, self::SLOT_REPEATER_META_KEY, $slots);
  update_post_meta($kiosk_id, '_meadow_config_version', time());

  return new WP_REST_Response([
    'ok'       => true,
    'kiosk_id' => $kiosk_id,
    'changed'  => $changed,
  ], 200);
}



    /* ---------------------------------------------
     * REST: kiosk-screen
     * ------------------------------------------- */
    
    private function get_stock_by_motor( $kiosk_post_id ): array {
    $slots = get_post_meta($kiosk_post_id, self::SLOT_REPEATER_META_KEY, true);
    $slots = $this->normalize_slots_array($slots);

    $out = [];
    foreach ( $slots as $row ) {
        $enabled = $this->slot_enabled($row[self::SLOT_FIELD_ENABLED] ?? null);
        if ( ! $enabled ) continue;

        $motor = (int) ($row[self::SLOT_FIELD_MOTOR] ?? 0);
        if ( ! $motor ) continue;

        $stock = (int) ($row[self::SLOT_FIELD_STOCK] ?? 0);
        $out[(string)$motor] = $stock;
    }
    return $out;
}



    public function rest_kiosk_screen( WP_REST_Request $req ) {
        $this->nocache();

        $kiosk_id = (int) $req->get_param('kiosk_id');
        if ( $kiosk_id <= 0 ) {
            return new WP_Error('bad_request','Missing kiosk_id',[ 'status'=>400 ]);
        }

        $kiosk = meadow_kiosk_get_kiosk_by_kiosk_id( $kiosk_id );
        if ( ! $kiosk ) {
            return new WP_Error('not_found','Kiosk not found (check _meadow_kiosk_id)',[ 'status'=>404 ]);
        }

        if ( $req->get_method() === 'GET' ) {
            $payload = $this->screen_get_payload( $kiosk->ID );

            if ( isset($payload['idle_timeout']) && (int)$payload['idle_timeout'] <= 0 ) {
                $payload['idle_timeout'] = null;
            }
            if ( isset($payload['thankyou_timeout']) && (int)$payload['thankyou_timeout'] <= 0 ) {
                $payload['thankyou_timeout'] = null;
            }

            $payload['kiosk_id'] = $kiosk_id;

            $stock_by_motor = $this->get_stock_by_motor( $kiosk->ID );

$payload['stock_by_motor'] = (object) $stock_by_motor;
$payload['stock_total']    = array_sum($stock_by_motor);
$payload['out_of_stock']   = ($payload['stock_total'] <= 0);


            $resp = new WP_REST_Response( $payload, 200 );
            if ( isset($payload['revision']) ) {
                $resp->header('ETag', 'W/"meadow-rev-' . (int)$payload['revision'] . '"');
            }
            return $resp;
        }

        $body = (array) $req->get_json_params();
        $mode = isset($body['mode']) ? (string) $body['mode'] : (string) $req->get_param('mode');
        $order_id = isset($body['order_id']) ? (int) $body['order_id'] : (int) $req->get_param('order_id');

        $key = '';
        if ( isset($body['key']) ) $key = (string) $body['key'];
        if ( $key === '' ) $key = (string) $req->get_param('key');
        if ( $key === '' ) $key = (string) $req->get_param('api_key');

        if ( ! meadow_kiosk_is_admin_request() ) {
            $auth = meadow_kiosk_require_kiosk_auth_from_values( $kiosk_id, $key );
            if ( is_wp_error($auth) ) return $auth;
            $kiosk = $auth;
        }

        $mode = strtolower( trim( $mode ) );
        $allowed = [ 'ads','browse','payment','finalising','vending','thankyou','error','payment_failed' ];
        if ( $mode === '' || ! in_array( $mode, $allowed, true ) ) {
            return new WP_Error('bad_request','Invalid mode',[ 'status'=>400, 'allowed'=>$allowed ]);
        }

        $this->screen_set_payload( $kiosk->ID, $mode, $order_id );

        $payload = $this->screen_get_payload( $kiosk->ID );
        if ( isset($payload['idle_timeout']) && (int)$payload['idle_timeout'] <= 0 ) {
            $payload['idle_timeout'] = null;
        }
        if ( isset($payload['thankyou_timeout']) && (int)$payload['thankyou_timeout'] <= 0 ) {
            $payload['thankyou_timeout'] = null;
        }
        $payload['kiosk_id'] = $kiosk_id;

        return new WP_REST_Response([
            'ok' => true,
            'state' => $payload,
        ], 200);
    }

    /* ---------------------------------------------
     * REST: kiosk-config
     * ------------------------------------------- */

    public function rest_kiosk_config( WP_REST_Request $req ) {
        $this->nocache();

        $token = $req->get_param('token');
        $key   = $req->get_param('key');

        $pi_imei        = $req->get_param('imei');
        $sigma_imei_in  = $req->get_param('sigma_imei');

        if ( ! $token || ! $key ) {
            return new WP_Error('bad_request', 'Missing token or key', [ 'status' => 400 ]);
        }

        $opts       = get_option('meadow-settings');
        $master_key = $opts['meadow_api_master_key'] ?? '';

        if ( $key !== $master_key ) {
            return new WP_Error('forbidden', 'Invalid provision key', [ 'status' => 403 ]);
        }

        $kiosk = meadow_kiosk_get_kiosk_by_token( (string)$token );
        if ( ! $kiosk ) {
            return new WP_Error('not_found', 'Kiosk not found', [ 'status' => 404 ]);
        }

        if ( $pi_imei ) {
            update_post_meta( $kiosk->ID, '_meadow_modem_imei', sanitize_text_field($pi_imei) );
        }
        if ( $sigma_imei_in ) {
            update_post_meta( $kiosk->ID, '_meadow_sigma_imei', sanitize_text_field($sigma_imei_in) );
        }

        $kiosk_id   = (int) get_post_meta($kiosk->ID, '_meadow_kiosk_id', true);
        $domain     = (string) get_post_meta($kiosk->ID, '_meadow_domain', true);
        $page_slug  = (string) get_post_meta($kiosk->ID, '_meadow_kiosk_page_slug', true);
        $api_key    = (string) get_post_meta($kiosk->ID, '_meadow_api_key', true);
        $mode       = (string) ( get_post_meta($kiosk->ID, '_meadow_kiosk_mode', true) ?: 'vending' );

        $idle_timeout     = (int) get_post_meta($kiosk->ID, '_meadow_idle_timeout', true);
        $thankyou_timeout = (int) get_post_meta($kiosk->ID, '_meadow_thankyou_timeout', true);

        $slots = get_post_meta($kiosk->ID, self::SLOT_REPEATER_META_KEY, true);
$slots = $this->normalize_slots_array($slots);

$motors  = [];
$spin    = [];
$catalog = [];

foreach ( $slots as $row ) {
    if ( ! is_array($row) ) continue;

    $enabled = $this->slot_enabled($row[self::SLOT_FIELD_ENABLED] ?? null);
    if ( ! $enabled ) continue;


            $motor = (int) ($row[self::SLOT_FIELD_MOTOR] ?? 0);
            if ( ! $motor ) continue;

            $gpio_pin  = (int)   ($row[self::SLOT_FIELD_GPIO_PIN] ?? 0);
            $spin_time = (float) ($row[self::SLOT_FIELD_SPIN_TIME] ?? 0);

            if ( $gpio_pin )  $motors[(string)$motor] = $gpio_pin;
            if ( $spin_time ) $spin[(string)$motor]   = $spin_time;

            $product_id = (int) ($row[self::SLOT_FIELD_PRODUCT] ?? 0);
            $capacity   = (int) ($row[self::SLOT_FIELD_CAPACITY] ?? 0);
            $stock      = (int) ($row[self::SLOT_FIELD_STOCK] ?? 0);

            $catalog[] = [
                'motor'      => $motor,
                'product_id' => $product_id,
                'capacity'   => $capacity,
                'stock'      => $stock,
                'enabled'    => (bool) $enabled,
                'gpio_pin'   => $gpio_pin,
                'spin_time'  => $spin_time,
            ];
        }

        $payment_provider = (string) ( get_post_meta($kiosk->ID, '_meadow_payment_provider', true) ?: 'sigma' );
        $payment_enabled_meta = get_post_meta($kiosk->ID, '_meadow_payment_enabled', true);
        $payment_enabled = $this->meta_enabled($payment_enabled_meta, true);

        $sigma_usb_path  = (string) get_post_meta($kiosk->ID, '_meadow_sigma_usb_path', true);
        $sigma_baud      = (int)    get_post_meta($kiosk->ID, '_meadow_sigma_baud', true);
        if ( ! $sigma_baud ) $sigma_baud = 115200;

        $sigma_mode      = (string) ( get_post_meta($kiosk->ID, '_meadow_sigma_mode', true) ?: 'usb' );
        $sigma_imei      = (string) get_post_meta($kiosk->ID, '_meadow_sigma_imei', true);

        $kiosk_url = rtrim($domain, '/') . '/' . ltrim($page_slug, '/');

        return [
            'kiosk_id'         => $kiosk_id,
            'domain'           => $domain,
            'kiosk_page'       => '/' . ltrim($page_slug, '/'),
            'kiosk_url'        => $kiosk_url,
            'api_key'          => $api_key,

            'mode'             => $mode,
            'idle_timeout'     => $idle_timeout,
            'thankyou_timeout' => $thankyou_timeout,

            'motors'           => (object) $motors,
            'spin_time'        => (object) $spin,
            'catalog'          => $catalog,

            'payment' => [
                'provider' => $payment_provider,
                'enabled'  => $payment_enabled,
                'sigma' => [
                    'mode'      => $sigma_mode,
                    'imei'      => $sigma_imei,
                    'usb_path'  => $sigma_usb_path,
                    'baud'      => $sigma_baud,
                    'currency_num' => '826',
                ],
            ],

            'device' => [
                'pi_modem_imei' => (string) get_post_meta($kiosk->ID, '_meadow_modem_imei', true),
            ],
        ];
    }

/* ---------------------------------------------
 * REST: start-payment
 * ------------------------------------------- */

public function rest_start_payment( WP_REST_Request $req ) {
    $this->nocache();

    $kiosk_id = (int) $req->get_param('kiosk_id');
    $motor    = (int) $req->get_param('motor');
    $key      = (string) $req->get_param('key');

    if ( ! $kiosk_id || ! $motor || $key === '' ) {
        return new WP_Error('bad_request','Missing kiosk_id, motor, or key',[ 'status'=>400 ]);
    }

    $kiosk = meadow_kiosk_require_kiosk_auth_from_request( $req );
    if ( is_wp_error($kiosk) ) return $kiosk;

    $wc_ok = $this->ensure_wc_loaded();
    if ( is_wp_error($wc_ok) ) return $wc_ok;

    list($slot_index, $row_found, $slots_all) = $this->get_slot_by_motor( $kiosk->ID, $motor );
    if ( $slot_index === null || ! $row_found ) {
        return new WP_Error('not_found','Motor not mapped to a slot',[ 'status'=>404 ]);
    }

    $enabled = $this->slot_enabled($row_found[self::SLOT_FIELD_ENABLED] ?? null);
    if ( ! $enabled ) {
        return new WP_Error('forbidden','Motor disabled',[ 'status'=>403 ]);
    }

    $stock = (int) ($row_found[self::SLOT_FIELD_STOCK] ?? 0);
    if ( $stock <= 0 ) {
        return new WP_Error('forbidden','Out of stock',[ 'status'=>403 ]);
    }

    $product_id = (int) ($row_found[self::SLOT_FIELD_PRODUCT] ?? 0);
    if ( ! $product_id ) {
        return new WP_Error('not_found','No product mapped to this motor',[ 'status'=>404 ]);
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error('not_found','Product not found',[ 'status'=>404 ]);
    }

    $price = (float) $product->get_price();
    if ( $price <= 0 ) {
        return new WP_Error('forbidden','Invalid product price',[ 'status'=>403 ]);
    }

    $amount_minor = (int) round( $price * 100 );
    $currency     = (string) ( get_woocommerce_currency() ?: 'GBP' );
    $currency_num = '826';

    $session_id = wp_generate_uuid4();
    $reference  = 'MEADOW-' . $kiosk_id . '-' . $motor . '-' . substr(str_replace('-','',$session_id), 0, 8);

    $order = wc_create_order();
    if ( is_wp_error($order) ) {
        return new WP_Error('server_error','Failed to create Woo order',[ 'status'=>500 ]);
    }

    $order->add_product( $product, 1 );
    $order->set_currency( $currency );
    $order->calculate_totals();


    // -------------------------------------------------------
    // IMPORTANT: mark this as a KIOSK/VENDING order in Woo
    // -------------------------------------------------------
    $order->update_meta_data('_meadow_order_type', 'kiosk');   // <— main flag for admin filtering
    $order->update_meta_data('_meadow_kiosk_id', $kiosk_id);
    $order->update_meta_data('_meadow_motor', $motor);
    $order->update_meta_data('_meadow_slot_index', (int)$slot_index);
    $order->update_meta_data('_meadow_product_id', $product_id);
    $order->update_meta_data('_meadow_session_id', $session_id);
    $order->update_meta_data('_meadow_reference', $reference);
    $order->update_meta_data('_meadow_amount_minor', $amount_minor);
    $order->update_meta_data('_meadow_currency_num', $currency_num);

    // Make it instantly obvious in WP Admin orders list
    if ( method_exists($order, 'set_payment_method') ) {
        $order->set_payment_method('meadow_kiosk');
        $order->set_payment_method_title('Meadow Kiosk');
    }

    $order->add_order_note("Meadow kiosk order: kiosk={$kiosk_id}, motor={$motor}, ref={$reference}");

    $order->set_status( 'on-hold', 'Meadow: awaiting kiosk payment confirmation.' );
    $order->save();

    $order_id = (int) $order->get_id();

    $pay_id = wp_insert_post([
        'post_type'   => self::PAY_POST_TYPE,
        'post_status' => 'publish',
        'post_title'  => "PAY: kiosk {$kiosk_id} motor {$motor} {$reference}",
    ]);

    if ( ! $pay_id || is_wp_error($pay_id) ) {
        return new WP_Error('server_error','Failed to create payment session post',[ 'status'=>500 ]);
    }

    update_post_meta($pay_id, '_meadow_session_id', $session_id);
    update_post_meta($pay_id, '_meadow_reference', $reference);
    update_post_meta($pay_id, '_meadow_kiosk_id', $kiosk_id);
    update_post_meta($pay_id, '_meadow_motor', $motor);
    update_post_meta($pay_id, '_meadow_slot_index', (int)$slot_index);
    update_post_meta($pay_id, '_meadow_product_id', $product_id);
    update_post_meta($pay_id, '_meadow_amount_minor', $amount_minor);
    update_post_meta($pay_id, '_meadow_currency', $currency);
    update_post_meta($pay_id, '_meadow_currency_num', $currency_num);
    update_post_meta($pay_id, '_meadow_order_id', $order_id);
    update_post_meta($pay_id, '_meadow_payment_status', 'pending');
    update_post_meta($pay_id, '_meadow_vend_status', 'pending');
    update_post_meta($pay_id, '_meadow_created_ts', time());

    // Use migrated state (single source of truth + revision)
    $this->screen_set_payload($kiosk->ID, 'payment', $order_id);

    return [
        'ok'           => true,
        'session_id'   => $session_id,
        'reference'    => $reference,
        'order_id'     => $order_id,
        'amount_minor' => $amount_minor,
        'currency'     => $currency,
        'currency_num' => $currency_num,
        'product_id'   => $product_id,
        'motor'        => $motor,
        'kiosk_id'     => $kiosk_id,
    ];
}


/* ---------------------------------------------
 * REST: payment-result / vend-result / heartbeat / ads
 * ------------------------------------------- */

private function get_payment_by_session_id( $session_id ) {
    $q = new WP_Query([
        'post_type'      => self::PAY_POST_TYPE,
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => '_meadow_session_id',
        'meta_value'     => $session_id,
        'no_found_rows'  => true,
    ]);
    return $q->posts[0] ?? null;
}

public function rest_payment_result( WP_REST_Request $req ) {
    $this->nocache();

    $kiosk_id   = (int) $req->get_param('kiosk_id');
    $key        = (string) $req->get_param('key');
    $session_id = (string) $req->get_param('session_id');

    if ( ! $kiosk_id || $key === '' || $session_id === '' ) {
        return new WP_Error('bad_request','Missing kiosk_id, key or session_id',[ 'status'=>400 ]);
    }

    $kiosk = meadow_kiosk_require_kiosk_auth_from_request( $req );
    if ( is_wp_error($kiosk) ) return $kiosk;

    $approved = $this->bool_param($req->get_param('approved'));
    if ( $approved === null ) $approved = false;

    $status  = (string) $req->get_param('status');
    $stage   = (string) $req->get_param('stage');
    $raw     = $req->get_param('raw');
    $receipt = $req->get_param('receipt');
    $txid    = (string) $req->get_param('txid');

    $pay_post = $this->get_payment_by_session_id( $session_id );
    if ( ! $pay_post ) {
        return new WP_Error('not_found','Payment session not found',[ 'status'=>404 ]);
    }

    $sess_kiosk = (int) get_post_meta($pay_post->ID, '_meadow_kiosk_id', true);
    if ( $sess_kiosk !== $kiosk_id ) {
        return new WP_Error('forbidden','Session does not belong to this kiosk',[ 'status'=>403 ]);
    }

    // Persist payment outcome on the payment-session post
    update_post_meta($pay_post->ID, '_meadow_payment_status', ($approved ? 'approved' : 'declined'));
    update_post_meta($pay_post->ID, '_meadow_sigma_status', $status);
    update_post_meta($pay_post->ID, '_meadow_sigma_stage', $stage);
    update_post_meta($pay_post->ID, '_meadow_sigma_txid', $txid);

    if ( $raw !== null ) update_post_meta($pay_post->ID, '_meadow_sigma_raw', wp_json_encode($raw));
    if ( $receipt )      update_post_meta($pay_post->ID, '_meadow_sigma_receipt', (string)$receipt);

    update_post_meta($pay_post->ID, '_meadow_payment_ts', time());

    // Update Woo order (tag as kiosk + set status)
    $order_id = (int) get_post_meta($pay_post->ID, '_meadow_order_id', true);

    if ( $order_id ) {

        $wc_ok = $this->ensure_wc_loaded();
        if ( ! is_wp_error($wc_ok) ) {

            try {
                $order = wc_get_order($order_id);

                if ( $order ) {

                    // Tag as kiosk order so you can filter in admin
                    $order->update_meta_data('_meadow_order_type', 'kiosk');
                    $order->update_meta_data('_meadow_kiosk_id', (int)$kiosk_id);
                    $order->update_meta_data('_meadow_motor', (int) get_post_meta($pay_post->ID, '_meadow_motor', true));
                    $order->update_meta_data('_meadow_session_id', (string)$session_id);
                    $order->update_meta_data('_meadow_reference', (string) get_post_meta($pay_post->ID, '_meadow_reference', true));
                    $order->update_meta_data('_meadow_slot_index', get_post_meta($pay_post->ID, '_meadow_slot_index', true));
                    $order->update_meta_data('_meadow_product_id', (int) get_post_meta($pay_post->ID, '_meadow_product_id', true));
                    $order->save_meta_data();

                    if ( $approved ) {
                        $order->update_status('on-hold', 'Meadow: payment approved, awaiting vend confirmation.');
                    } else {
                        $order->update_status('failed', 'Meadow: payment declined.');
                    }
                }

            } catch ( \Throwable $e ) {
                error_log('[Meadow] rest_payment_result: order update failed: ' . $e->getMessage());
            }
        }
    }

    // Screen state
    $this->screen_set_payload($kiosk->ID, $approved ? 'vending' : 'payment_failed', $order_id);

    return [ 'ok' => true ];
}

public function rest_vend_result( WP_REST_Request $req ) {
    $this->nocache();

    try {
        $kiosk_id   = (int) $req->get_param('kiosk_id');
        $key        = (string) $req->get_param('key');
        $session_id = (string) $req->get_param('session_id');

        if ( ! $kiosk_id || $key === '' || $session_id === '' ) {
            return new WP_Error('bad_request','Missing kiosk_id, key or session_id',[ 'status'=>400 ]);
        }

        // Auth
        $kiosk = meadow_kiosk_require_kiosk_auth_from_request( $req );
        if ( is_wp_error($kiosk) ) return $kiosk;

        $success = $this->bool_param($req->get_param('success'));
        if ( $success === null ) $success = false;

        $error   = (string) $req->get_param('error');
        $details = $req->get_param('details');

        // Payment session post
        $pay_post = $this->get_payment_by_session_id( $session_id );
        if ( ! $pay_post ) {
            return new WP_Error('not_found','Payment session not found',[ 'status'=>404 ]);
        }

        $sess_kiosk = (int) get_post_meta($pay_post->ID, '_meadow_kiosk_id', true);
        if ( $sess_kiosk !== $kiosk_id ) {
            return new WP_Error('forbidden','Session does not belong to this kiosk',[ 'status'=>403 ]);
        }

        // Ensure payment was approved
        $pay_status = (string) get_post_meta($pay_post->ID, '_meadow_payment_status', true);
        if ( $pay_status !== 'approved' ) {
            update_post_meta($pay_post->ID, '_meadow_vend_status', 'blocked_no_payment');
            return new WP_Error('forbidden','Payment not approved for this session',[ 'status'=>403 ]);
        }

        // Persist vend result on the payment post
        update_post_meta($pay_post->ID, '_meadow_vend_status', $success ? 'vended' : 'vend_failed');
        update_post_meta($pay_post->ID, '_meadow_vend_ts', time());
        if ( $error !== '' ) update_post_meta($pay_post->ID, '_meadow_vend_error', $error);

        if ( $details !== null ) {
            $encoded = wp_json_encode($details);
            if ( $encoded === false ) {
                $encoded = wp_json_encode([ 'note' => 'details_encode_failed', 'raw_type' => gettype($details) ]);
            }
            update_post_meta($pay_post->ID, '_meadow_vend_details', $encoded);
        }

        $order_id = (int) get_post_meta($pay_post->ID, '_meadow_order_id', true);

        // Also persist vend outcome onto the order post (handy in admin lists)
        if ( $order_id ) {
            update_post_meta($order_id, '_meadow_order_type', 'kiosk');
            update_post_meta($order_id, '_meadow_kiosk_id', (int)$kiosk_id);
            update_post_meta($order_id, '_meadow_session_id', (string)$session_id);
            update_post_meta($order_id, '_meadow_reference', (string) get_post_meta($pay_post->ID, '_meadow_reference', true));
            update_post_meta($order_id, '_meadow_vend_status', $success ? 'vended' : 'vend_failed');
            update_post_meta($order_id, '_meadow_vend_ts', time());
        }

        // If vend failed, mark order failed + show error screen (no stock decrement)
        if ( ! $success ) {
            if ( $order_id && function_exists('wc_get_order') ) {
                $order = wc_get_order($order_id);
                if ( $order ) $order->update_status('failed', 'Meadow: vend failed after approved payment.');
            }

            $this->screen_set_payload($kiosk->ID, 'error', $order_id);

            return [ 'ok' => true, 'vend' => 'failed' ];
        }

        // Vend success -> decrement stock safely (MOTOR is source of truth)
        $motor      = (int) get_post_meta($pay_post->ID, '_meadow_motor', true);
        $product_id = (int) get_post_meta($pay_post->ID, '_meadow_product_id', true);

        // IMPORTANT: slot_index must be nullable — do NOT cast missing value to 0
        $slot_index_raw = get_post_meta($pay_post->ID, '_meadow_slot_index', true);
        $slot_index = (is_numeric($slot_index_raw) && $slot_index_raw !== '') ? (int)$slot_index_raw : null;

        $slots = get_post_meta($kiosk->ID, self::SLOT_REPEATER_META_KEY, true);

        // Normalize slots to prevent "ghost rows"
        $before_count = is_array($slots) ? count($slots) : 0;
        $before_keys  = is_array($slots) ? implode(',', array_keys($slots)) : '';
        $slots = $this->normalize_slots_array( $slots );

        if ( $before_count && (count($slots) !== $before_count || ($before_keys !== '' && $before_keys !== implode(',', array_keys($slots))) ) ) {
            error_log('[Meadow] rest_vend_result: normalized slots (count/keys changed) kiosk_id=' . $kiosk_id . ' before_count=' . $before_count . ' after_count=' . count($slots) . ' before_keys=' . $before_keys . ' after_keys=' . implode(',', array_keys($slots)));
        }

        $new_stock  = null;
        $prev_stock = null;

        // Helper: validate a candidate slot index matches expected motor/product (if provided)
        $slot_matches = function($idx) use ($slots, $motor, $product_id) {
            if ($idx === null) return false;
            if (!isset($slots[$idx]) || !is_array($slots[$idx])) return false;

            $row_motor   = (int)($slots[$idx][self::SLOT_FIELD_MOTOR] ?? 0);
            $row_product = (int)($slots[$idx][self::SLOT_FIELD_PRODUCT] ?? 0);

            if ($motor > 0 && $row_motor !== $motor) return false;
            if ($product_id > 0 && $row_product !== $product_id) return false;

            return true;
        };

        // 1) Prefer motor lookup (source of truth)
        $chosen_idx = null;
        if ($motor > 0) {
            foreach ($slots as $idx => $row) {
                if (!is_array($row)) continue;
                if ((int)($row[self::SLOT_FIELD_MOTOR] ?? 0) === $motor) {
                    $chosen_idx = (int)$idx;
                    break;
                }
            }
        }

        // 2) If motor not found, try slot_index ONLY if it matches expectations
        if ($chosen_idx === null && $slot_matches($slot_index)) {
            $chosen_idx = (int)$slot_index;
        }

        // 3) If still not found, try product lookup (only if exactly one match)
        if ($chosen_idx === null && $product_id > 0) {
            $matches = [];
            foreach ($slots as $idx => $row) {
                if (!is_array($row)) continue;
                if ((int)($row[self::SLOT_FIELD_PRODUCT] ?? 0) === $product_id) {
                    $matches[] = (int)$idx;
                }
            }
            if (count($matches) === 1) {
                $chosen_idx = $matches[0];
            } elseif (count($matches) > 1) {
                update_post_meta($pay_post->ID, '_meadow_stock_decrement', 'skipped_ambiguous_product');
                error_log('[Meadow] rest_vend_result: stock decrement skipped (ambiguous product) kiosk_id=' . $kiosk_id . ' order_id=' . (int)$order_id . ' motor=' . $motor . ' product_id=' . $product_id . ' matches=' . implode(',', $matches));
            }
        }

        // Forensic trail if chosen differs
        if ($chosen_idx !== null) {
            if ($slot_index !== null && $slot_index !== $chosen_idx) {
                $stored_row = (isset($slots[$slot_index]) && is_array($slots[$slot_index])) ? $slots[$slot_index] : null;
                $stored_motor   = $stored_row ? (int)($stored_row[self::SLOT_FIELD_MOTOR] ?? 0) : 0;
                $stored_product = $stored_row ? (int)($stored_row[self::SLOT_FIELD_PRODUCT] ?? 0) : 0;

                error_log('[Meadow] rest_vend_result: slot_index mismatch; using chosen_idx. kiosk_id=' . $kiosk_id .
                    ' order_id=' . (int)$order_id .
                    ' motor=' . $motor .
                    ' product_id=' . $product_id .
                    ' stored_slot_index=' . (int)$slot_index .
                    ' stored_motor=' . $stored_motor .
                    ' stored_product=' . $stored_product .
                    ' chosen_idx=' . (int)$chosen_idx
                );
            } elseif ($slot_index === null) {
                error_log('[Meadow] rest_vend_result: slot_index missing; using chosen_idx. kiosk_id=' . $kiosk_id .
                    ' order_id=' . (int)$order_id .
                    ' motor=' . $motor .
                    ' product_id=' . $product_id .
                    ' chosen_idx=' . (int)$chosen_idx
                );
            }
        }

        // Decrement only if we found a valid slot row
        if ($chosen_idx !== null && isset($slots[$chosen_idx]) && is_array($slots[$chosen_idx])) {

            $prev_stock = (int) ($slots[$chosen_idx][self::SLOT_FIELD_STOCK] ?? 0);
            $new_stock  = max(0, $prev_stock - 1);
            $slots[$chosen_idx][self::SLOT_FIELD_STOCK] = $new_stock;

            $slots = $this->normalize_slots_array( $slots );
            update_post_meta($kiosk->ID, self::SLOT_REPEATER_META_KEY, $slots);
            update_post_meta($kiosk->ID, '_meadow_config_version', time());

            // Alerts (optional — must never break vend flow)
            try {
                if ($prev_stock > 2 && $new_stock === 2) {
                    $this->send_stock_alert(
                        $kiosk_id,
                        $motor,
                        $new_stock,
                        'low',
                        [
                            'order_id'   => (int) $order_id,
                            'product_id' => (int) $product_id,
                        ]
                    );
                } elseif ($prev_stock > 0 && $new_stock === 0) {
                    $this->send_stock_alert(
                        $kiosk_id,
                        $motor,
                        $new_stock,
                        'out',
                        [
                            'order_id'   => (int) $order_id,
                            'product_id' => (int) $product_id,
                        ]
                    );
                }
            } catch ( \Throwable $e ) {
                // never break vend flow
            }

        } else {

            update_post_meta($pay_post->ID, '_meadow_stock_decrement', 'skipped_no_slot');
            error_log('[Meadow] rest_vend_result: stock decrement skipped (no slot found) kiosk_id=' . $kiosk_id .
                ' order_id=' . (int)$order_id .
                ' motor=' . $motor .
                ' product_id=' . $product_id .
                ' stored_slot_index=' . (is_null($slot_index) ? 'null' : (string)$slot_index)
            );
        }

        // Complete order (and mirror meta onto WC object too)
        if ( $order_id ) {
            $wc_ok = $this->ensure_wc_loaded();

            // If Woo isn't fully available in this REST context, still complete the order (post_status)
            if ( is_wp_error($wc_ok) || ! function_exists('wc_get_order') ) {
                error_log('[Meadow] rest_vend_result: Woo not available, fallback completing order_id=' . (int)$order_id);

                // Mirror key meta even without WC helpers
                update_post_meta($order_id, '_meadow_order_type', 'kiosk');
                update_post_meta($order_id, '_meadow_kiosk_id', (int)$kiosk_id);
                update_post_meta($order_id, '_meadow_session_id', (string)$session_id);
                update_post_meta($order_id, '_meadow_reference', (string) get_post_meta($pay_post->ID, '_meadow_reference', true));
                update_post_meta($order_id, '_meadow_vend_status', 'vended');

                // Woo order status is stored as the post_status
                wp_update_post([ 'ID' => $order_id, 'post_status' => 'wc-completed' ]);

            } else {
                try {
                    $order = wc_get_order($order_id);
                    if ( $order ) {
                        $order->update_meta_data('_meadow_order_type', 'kiosk');
                        $order->update_meta_data('_meadow_kiosk_id', (int)$kiosk_id);
                        $order->update_meta_data('_meadow_session_id', (string)$session_id);
                        $order->update_meta_data('_meadow_reference', (string) get_post_meta($pay_post->ID, '_meadow_reference', true));
                        $order->update_meta_data('_meadow_vend_status', 'vended');
                        $order->save_meta_data();

                        $order->update_status('completed', 'Meadow: vend confirmed.');
                    } else {
                        // Very defensive: if wc_get_order returns null, still complete status
                        wp_update_post([ 'ID' => $order_id, 'post_status' => 'wc-completed' ]);
                    }
                } catch ( \Throwable $e ) {
                    error_log('[Meadow] rest_vend_result: order update failed: ' . $e->getMessage());
                    // Final fallback to avoid stuck on-hold
                    wp_update_post([ 'ID' => $order_id, 'post_status' => 'wc-completed' ]);
                }
            }
        }

        // Screen -> thankyou
        $this->screen_set_payload($kiosk->ID, 'thankyou', $order_id);

        return [
            'ok'        => true,
            'vend'      => 'success',
            'order_id'  => $order_id,
            'new_stock' => $new_stock,
        ];

    } catch ( \Throwable $e ) {
        error_log("[Meadow rest_vend_result] " . $e->getMessage() . "\n" . $e->getTraceAsString());

        return new WP_REST_Response([
            'ok' => false,
            'error' => 'vend_result_exception',
            'message' => substr($e->getMessage(), 0, 200),
        ], 500);
    }
}

    public function rest_kiosk_heartbeat( WP_REST_Request $req ) {
        $this->nocache();

        $kiosk_id = (int) $req->get_param('kiosk_id');
        $key      = trim((string)($req->get_param('key') ?? ''));

        if (!$kiosk_id || $key === '') {
            return new WP_Error('bad_request','Missing kiosk_id or key',[ 'status'=>400 ]);
        }

        $kiosk = meadow_kiosk_require_kiosk_auth_from_request($req);
        if (is_wp_error($kiosk)) return $kiosk;

        $git      = (string) $req->get_param('git');
        $sigma_terminal_id = (string) $req->get_param('sigma_terminal_id');
        $sigma_imei        = (string) $req->get_param('sigma_imei');

        $this->heartbeat_touch($kiosk->ID, $git, $sigma_terminal_id, $sigma_imei);
        return [ 'ok' => true ];
    }

    public function rest_ad_impression( WP_REST_Request $req ) {
        $this->nocache();

        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];

        $kiosk_id = (int) ($body['kiosk_id'] ?? 0);
        $key      = (string) ($body['key'] ?? '');
        $items    = (array)  ($body['items'] ?? []);

        if (!$kiosk_id || $key === '' || empty($items)) {
            return new WP_Error('bad_request','Missing kiosk_id, key or items',[ 'status'=>400 ]);
        }

        $kiosk = meadow_kiosk_require_kiosk_auth_from_values($kiosk_id, $key);
        if (is_wp_error($kiosk)) return $kiosk;

        foreach ($items as $it) {
            $ad_id = (int) ($it['ad_id'] ?? 0);
            $n     = (int) ($it['n'] ?? 0);
            if (!$ad_id || $n <= 0) continue;

            $cur = (int) get_post_meta($ad_id, '_meadow_impressions_total', true);
            update_post_meta($ad_id, '_meadow_impressions_total', $cur + $n);

            $map = get_post_meta($ad_id, '_meadow_impressions_by_kiosk', true);
            if (!is_array($map)) $map = [];
            $map[(string)$kiosk_id] = (int)($map[(string)$kiosk_id] ?? 0) + $n;
            update_post_meta($ad_id, '_meadow_impressions_by_kiosk', $map);
        }

        return [ 'ok' => true ];
    }


public function rest_kiosk_ads( WP_REST_Request $req ) {
    $this->nocache();

    // --------------------------------------------------
    // DEBUG (token-gated; works even if REST cookies aren't auth'd)
    // --------------------------------------------------
    $debug     = (int) $req->get_param('debug');
    $debug_key = (string) $req->get_param('debug_key');

    $resp_debug = null;

    if ( $debug === 1 && hash_equals('abcd1234', $debug_key) ) {

        $resp_debug = [
            'now_utc' => gmdate('c'),
            'route'   => $req->get_route(),
            'is_admin' => current_user_can('manage_options') ? 1 : 0,
            'wcs_users_subs_available' => function_exists('wcs_get_users_subscriptions'),
            'wcs_get_subscription_available' => function_exists('wcs_get_subscription'),
            'ads_checked' => [],
            'gate' => [],          // per-ad gate result
            'gate_detail' => [],   // per-ad detail
        ];

        // Inspect all ACTIVE ads directly (ground truth)
        $q = new WP_Query([
            'post_type'      => self::AD_POST_TYPE, // 'ad'
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => [
                [ 'key' => '_meadow_status', 'value' => 'active', 'compare' => '=' ],
            ],
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        foreach ( $q->posts as $ad_id ) {
            $billing = (string) get_post_meta($ad_id, '_meadow_billing_model', true);
            $adv_uid = (int) get_post_meta($ad_id, '_meadow_advertiser_user_id', true);
            $sub_id  = (int) get_post_meta($ad_id, '_meadow_subscription_id', true);

            $sub_exists = false;
            $sub_uid = 0;
            $sub_status = '';
            $sub_ok = false;

            if ( $billing === 'flat' && function_exists('wcs_get_subscription') && $sub_id ) {
                $sub = wcs_get_subscription($sub_id);
                if ( $sub ) {
                    $sub_exists = true;
                    $sub_uid = (int) $sub->get_user_id();
                    $sub_status = (string) $sub->get_status();
                    $sub_ok = ( $sub_uid === $adv_uid ) && $sub->has_status('active');
                }
            }

            $resp_debug['ads_checked'][] = [
                'ad_id' => (int) $ad_id,
                'billing' => $billing,
                'adv_user_id' => $adv_uid,
                'sub_id' => $sub_id,
                'sub_exists' => $sub_exists,
                'sub_user_id' => $sub_uid,
                'sub_status' => $sub_status,
                'sub_ok_strict' => $sub_ok,
            ];
        }
    }

    // --------------------------------------------------
    // EXISTING CORE
    // --------------------------------------------------
    $kiosk_id = (int) $req->get_param('kiosk_id');
    if ( ! $kiosk_id ) {
        return new WP_Error('bad_request','Missing kiosk_id',[ 'status'=>400 ]);
    }

    $kiosk = meadow_kiosk_get_kiosk_by_kiosk_id($kiosk_id);
    if ( ! $kiosk ) {
        return new WP_Error('not_found','Kiosk not found',[ 'status'=>404 ]);
    }

    $now = time();

    // Fetch kiosk segments only for debug/visibility now (NOT used for matching)
    $kiosk_segments = wp_get_post_terms(
        $kiosk->ID,
        self::TAX_KIOSK_SEGMENT,
        [ 'fields' => 'slugs' ]
    );
    if ( ! is_array($kiosk_segments) ) $kiosk_segments = [];

    // Load ads
    $house = $this->query_ads_for_playlist([
        'billing_model' => 'free',
        'now' => $now,
    ]);

    $paid = $this->query_ads_for_playlist([
        'billing_model' => 'flat',
        'now' => $now,
    ]);

    $out = [];

    // --------------------------------------------------
    // FREE ADS: only show if assigned to kiosk
    // --------------------------------------------------
    foreach ( $house as $p ) {

        $assigned = $this->ad_assigned_to_kiosk((int)$p->ID, (int)$kiosk->ID);

        if ( $resp_debug ) {
            $resp_debug['gate'][(string)$p->ID] = $assigned ? 'free_included' : 'free_not_assigned';
            $resp_debug['gate_detail'][(string)$p->ID] = [
                'billing' => 'free',
                'kiosk_post_id' => (int)$kiosk->ID,
                'relation_id' => (int) self::RELATION_AD_TO_KIOSK_ID,
                'assigned' => (bool)$assigned,
                'kiosk_segments' => $kiosk_segments,
            ];
        }

        if ( ! $assigned ) continue;

        $item = $this->format_ad_for_playlist($p);
        if ( $item ) $out[] = $item;
    }

    // --------------------------------------------------
    // PAID ADS: subscription active + assigned + not blocked by kiosk
    // --------------------------------------------------
    foreach ( $paid as $p ) {

        $adv_user_id = (int) get_post_meta($p->ID, '_meadow_advertiser_user_id', true);
        if ( ! $adv_user_id ) {
            if ( $resp_debug ) $resp_debug['gate'][(string)$p->ID] = 'paid_no_adv_user';
            continue;
        }

        $sub_id = (int) get_post_meta($p->ID, '_meadow_subscription_id', true);
        if ( ! $this->subscription_is_active_for_user($adv_user_id, $sub_id) ) {
            if ( $resp_debug ) $resp_debug['gate'][(string)$p->ID] = 'paid_sub_not_active_or_owner_mismatch';
            continue;
        }

        $assigned = $this->ad_assigned_to_kiosk((int)$p->ID, (int)$kiosk->ID);
        if ( ! $assigned ) {
            if ( $resp_debug ) $resp_debug['gate'][(string)$p->ID] = 'paid_not_assigned';
            continue;
        }

        $allowed = $this->kiosk_allows_ad_by_segment_blocklist((int)$kiosk->ID, (int)$p->ID);
        if ( ! $allowed ) {
            if ( $resp_debug ) $resp_debug['gate'][(string)$p->ID] = 'paid_blocked_by_kiosk_segments';
            continue;
        }

        if ( $resp_debug ) {
            $resp_debug['gate'][(string)$p->ID] = 'paid_included';
            $resp_debug['gate_detail'][(string)$p->ID] = [
                'billing' => 'flat',
                'kiosk_post_id' => (int)$kiosk->ID,
                'relation_id' => (int) self::RELATION_AD_TO_KIOSK_ID,
                'assigned' => (bool)$assigned,
                'kiosk_segments' => $kiosk_segments,
                'blocked_meta' => get_post_meta((int)$kiosk->ID, '_meadow_blocked_ad_segments', true),
                'ad_segment_ids' => wp_get_post_terms((int)$p->ID, 'ad_segment', [ 'fields' => 'ids' ]),
            ];
        }

        $item = $this->format_ad_for_playlist($p);
        if ( $item ) $out[] = $item;
    }

    if ( count($out) > 1 ) shuffle($out);

    $resp = [
        'kiosk_id' => $kiosk_id,
        'default_duration' => 10,
        'ads' => $out,
    ];

    if ( $resp_debug ) {
        $resp['__debug'] = $resp_debug;
    }

    return $resp;
}




    /* ---------------------------------------------
     * Legacy Woo completion
     * ------------------------------------------- */

    public function handle_order_completed( $order_id ) {
    $order = wc_get_order($order_id);
    if ( ! $order ) return;

    $kiosk_id = (int) $order->get_meta('_meadow_kiosk_id');
    $motor    = (int) $order->get_meta('_meadow_motor');
    if ( ! $kiosk_id || ! $motor ) return;

    $kiosk = meadow_kiosk_get_kiosk_by_kiosk_id($kiosk_id);
    if ( ! $kiosk ) return;

    $slots = get_post_meta($kiosk->ID, self::SLOT_REPEATER_META_KEY, true);

    // Normalize slots to prevent "ghost rows" caused by gappy numeric keys / non-array rows
    $before_count = is_array($slots) ? count($slots) : 0;
    $before_keys  = is_array($slots) ? implode(',', array_keys($slots)) : '';
    $slots = $this->normalize_slots_array( $slots );

    // If no slots, bail
    if ( ! is_array($slots) || empty($slots) ) return;

    // Optional: log if normalization changed something (safe to remove later)
    if ( $before_count && (count($slots) !== $before_count || ($before_keys !== '' && $before_keys !== implode(',', array_keys($slots))) ) ) {
        error_log('[Meadow] handle_order_completed: normalized slots (count/keys changed) kiosk_id=' . $kiosk_id . ' order_id=' . $order_id . ' before_count=' . $before_count . ' after_count=' . count($slots) . ' before_keys=' . $before_keys . ' after_keys=' . implode(',', array_keys($slots)));
    }

    $slot_index = null;
    $slot_data  = null;

    foreach ( $slots as $index => $row ) {
        if ( is_array($row) && (int) ($row[self::SLOT_FIELD_MOTOR] ?? 0) === $motor ) {
            $slot_index = (int)$index;
            $slot_data  = $row;
            break;
        }
    }
    if ( $slot_index === null ) return;

    $enabled = $this->slot_enabled($slot_data[self::SLOT_FIELD_ENABLED] ?? null);
    if ( ! $enabled ) return;

    $current    = (int) ($slot_data[self::SLOT_FIELD_STOCK] ?? 0);
    $product_id = (int) ($slot_data[self::SLOT_FIELD_PRODUCT] ?? 0);

    $new = max(0, $current - 1);
    $slots[$slot_index][self::SLOT_FIELD_STOCK] = $new;

    // Normalize again right before save (belt & braces)
    $slots = $this->normalize_slots_array( $slots );

    update_post_meta($kiosk->ID, self::SLOT_REPEATER_META_KEY, $slots);

    try {
    if ($current > 2 && $new === 2) {
        $this->send_stock_alert(
            $kiosk_id,
            $motor,
            $new,
            'low',
            [
                'order_id'   => (int) $order_id,
                'product_id' => (int) $product_id,
            ]
        );
    } elseif ($current > 0 && $new === 0) {
        $this->send_stock_alert(
            $kiosk_id,
            $motor,
            $new,
            'out',
            [
                'order_id'   => (int) $order_id,
                'product_id' => (int) $product_id,
            ]
        );
    }
} catch (\Throwable $e) {
    // never break order completion flow
}

}


    /* ---------------------------------------------
     * Admin filters
     * ------------------------------------------- */

    public function filter_kiosk_list_for_venues($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'kiosk') return;

        $user = wp_get_current_user();
        if (!in_array('venue',$user->roles,true)) return;

        $query->set('meta_query', [
            [
                'key' => '_meadow_venue_user_id',
                'value' => $user->ID
            ]
        ]);
    }

    public function filter_ads_for_advertisers($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'ad') return;

        $user = wp_get_current_user();
        if (!in_array('advertiser',$user->roles,true)) return;

        $query->set('meta_query', [
            [
                'key' => '_meadow_advertiser_user_id',
                'value' => $user->ID
            ]
        ]);
    }

    /* ---------------------------------------------
     * Ads admin UI (unchanged)
     * ------------------------------------------- */

    public function add_ad_subscription_metabox() {
        add_meta_box(
            'meadow_ad_subscription',
            'Meadow — Subscription Link',
            [ $this, 'render_ad_subscription_metabox' ],
            self::AD_POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_ad_subscription_metabox( $post ) {
        if ( ! $post || $post->post_type !== self::AD_POST_TYPE ) return;

        $adv_user_id = (int) get_post_meta($post->ID, '_meadow_advertiser_user_id', true);
        $selected_sub_id = (int) get_post_meta($post->ID, '_meadow_subscription_id', true);

        wp_nonce_field('meadow_ad_subscription_save', 'meadow_ad_subscription_nonce');

        echo '<p style="margin:0 0 8px;">Link this Ad to a Woo Subscription. This lets Meadow auto-fill dates and enforce venue limits.</p>';

        if ( ! $adv_user_id ) {
            echo '<p><em>Set <code>_meadow_advertiser_user_id</code> first.</em></p>';
            return;
        }

        if ( ! function_exists('wcs_get_users_subscriptions') ) {
            echo '<p><strong>Woo Subscriptions not available.</strong></p>';
            return;
        }

        $subs = wcs_get_users_subscriptions($adv_user_id);
        if ( empty($subs) ) {
            echo '<p><em>No subscriptions found for user ID ' . esc_html($adv_user_id) . '.</em></p>';
            return;
        }

        echo '<label for="meadow_subscription_id" style="display:block;font-weight:600;margin:0 0 6px;">Subscription</label>';
        echo '<select name="meadow_subscription_id" id="meadow_subscription_id" style="width:100%;">';
        echo '<option value="">— Select —</option>';

        foreach ( $subs as $sub_id => $sub ) {
            if ( ! $sub ) continue;
            $status = $sub->get_status();
            $label  = '#' . $sub->get_id() . ' — ' . ucfirst($status);

            $items = $sub->get_items();
            $names = [];
            foreach ($items as $item) {
                $p = $item->get_product();
                if ($p) $names[] = $p->get_name();
            }
            if ($names) $label .= ' — ' . implode(', ', array_slice($names, 0, 2));

            $sel = selected($selected_sub_id, (int)$sub->get_id(), false);
            echo '<option value="' . esc_attr($sub->get_id()) . '" ' . $sel . '>' . esc_html($label) . '</option>';
        }

        echo '</select>';

        if ( $selected_sub_id ) {
            $limit = $this->get_kiosk_limit_for_subscription($selected_sub_id);
            echo '<p style="margin-top:8px;"><strong>Venue limit:</strong> ' . esc_html($limit ?: '—') . '</p>';
        }
    }

    public function save_ad_subscription_meta( $post_id, $post ) {
        if ( ! $post || $post->post_type !== self::AD_POST_TYPE ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;

        if ( ! isset($_POST['meadow_ad_subscription_nonce']) || ! wp_verify_nonce($_POST['meadow_ad_subscription_nonce'], 'meadow_ad_subscription_save') ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        $sub_id = isset($_POST['meadow_subscription_id']) ? (int) $_POST['meadow_subscription_id'] : 0;
        if ( $sub_id ) update_post_meta($post_id, '_meadow_subscription_id', $sub_id);
        else delete_post_meta($post_id, '_meadow_subscription_id');

        $this->sync_ad_dates_from_subscription($post_id);
        $this->enforce_ad_kiosk_limit($post_id);
    }

    public function render_product_kiosk_limit_field() {
        woocommerce_wp_text_input([
            'id' => '_meadow_ad_kiosk_limit',
            'label' => 'Meadow ad venue limit',
            'desc_tip' => true,
            'description' => 'Number of venues/kiosks this subscription plan allows the advertiser to run their single ad on.',
            'type' => 'number',
            'custom_attributes' => [
                'min' => '0',
                'step' => '1',
            ],
        ]);
    }

    public function save_product_kiosk_limit_field( $product ) {
        if ( ! $product || ! is_a($product, 'WC_Product') ) return;
        $val = isset($_POST['_meadow_ad_kiosk_limit']) ? (int) $_POST['_meadow_ad_kiosk_limit'] : 0;
        $product->update_meta_data('_meadow_ad_kiosk_limit', max(0, $val));
    }

    public function on_subscription_status_updated( $subscription, $new_status, $old_status ) {
        if ( ! $subscription || ! is_object($subscription) ) return;
        $sub_id = method_exists($subscription,'get_id') ? (int) $subscription->get_id() : 0;
        if ( ! $sub_id ) return;

        $ads = $this->get_ads_linked_to_subscription($sub_id);
        foreach ($ads as $ad_id) {
            $this->sync_ad_dates_from_subscription((int)$ad_id);
            if ( $new_status !== 'active' ) update_post_meta((int)$ad_id, '_meadow_status', 'paused');
            $this->enforce_ad_kiosk_limit((int)$ad_id);
        }
    }

    public function on_subscription_renewal_payment_complete( $subscription, $last_order ) {
        if ( ! $subscription || ! is_object($subscription) ) return;
        $sub_id = method_exists($subscription,'get_id') ? (int) $subscription->get_id() : 0;
        if ( ! $sub_id ) return;

        $ads = $this->get_ads_linked_to_subscription($sub_id);
        foreach ($ads as $ad_id) {
            $this->sync_ad_dates_from_subscription((int)$ad_id);
            $this->enforce_ad_kiosk_limit((int)$ad_id);
        }
    }

    private function subscription_is_active_for_user( int $user_id, int $subscription_id = 0 ): bool {
        if ( ! function_exists('wcs_get_users_subscriptions') ) return false;

        if ( $subscription_id > 0 ) {
            $sub = function_exists('wcs_get_subscription') ? wcs_get_subscription($subscription_id) : null;
            if ( ! $sub ) return false;
            if ( (int) $sub->get_user_id() !== (int) $user_id ) return false;
            return $sub->has_status('active');
        }

        $subs = wcs_get_users_subscriptions($user_id);
        foreach ($subs as $sub) {
            if ($sub && $sub->has_status('active')) return true;
        }
        return false;
    }

    /**
 * Robust assignment check (JetEngine relations can be directional)
 */
private function ad_assigned_to_kiosk( int $ad_id, int $kiosk_post_id ): bool {
    return $this->jet_relation_has_link(self::RELATION_AD_TO_KIOSK_ID, $ad_id, $kiosk_post_id)
        || $this->jet_relation_has_link(self::RELATION_AD_TO_KIOSK_ID, $kiosk_post_id, $ad_id);
}

/**
 * Kiosk denylist: block ads whose ad_segment terms intersect kiosk's blocked segments.
 * Kiosk meta `_meadow_blocked_ad_segments` stores TERM IDs (checkbox field).
 */
private function kiosk_allows_ad_by_segment_blocklist( int $kiosk_post_id, int $ad_id ): bool {

    // Ad content segments (taxonomy)
    $ad_seg_ids = wp_get_post_terms($ad_id, 'ad_segment', [ 'fields' => 'ids' ]);
    if ( ! is_array($ad_seg_ids) ) $ad_seg_ids = [];
    $ad_seg_ids = array_map('intval', $ad_seg_ids);

    // If ad has no segments, nothing to block
    if ( empty($ad_seg_ids) ) return true;

    // Kiosk blocked segments (TERM IDs from checkbox field)
    $blocked = get_post_meta($kiosk_post_id, '_meadow_blocked_ad_segments', true);

    if ( ! is_array($blocked) ) {
        if ( is_string($blocked) && $blocked !== '' ) {
            $blocked = array_filter(array_map('trim', explode(',', $blocked)));
        } else {
            $blocked = [];
        }
    }

    $blocked_ids = array_map('intval', $blocked);

    if ( empty($blocked_ids) ) return true;

    // Any intersection = blocked
    return ! (bool) array_intersect($ad_seg_ids, $blocked_ids);
}


    private function get_ads_linked_to_subscription( int $subscription_id ): array {
        if ( ! $subscription_id ) return [];
        $q = new WP_Query([
            'post_type'      => self::AD_POST_TYPE,
            'posts_per_page' => 200,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_key'       => '_meadow_subscription_id',
            'meta_value'     => (string) $subscription_id,
            'no_found_rows'  => true,
        ]);
        return is_array($q->posts) ? array_values(array_map('intval', $q->posts)) : [];
    }

    private function get_kiosk_limit_for_subscription( int $subscription_id ): int {
        if ( ! function_exists('wcs_get_subscription') ) return 0;
        $sub = wcs_get_subscription($subscription_id);
        if ( ! $sub ) return 0;

        $limit = 0;
        foreach ( $sub->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $val = (int) $product->get_meta('_meadow_ad_kiosk_limit');
            if ( $val > $limit ) $limit = $val;
        }
        return $limit;
    }

    private function sync_ad_dates_from_subscription( int $ad_post_id ) {
        $adv_user_id = (int) get_post_meta($ad_post_id, '_meadow_advertiser_user_id', true);
        $sub_id = (int) get_post_meta($ad_post_id, '_meadow_subscription_id', true);
        if ( ! $adv_user_id || ! $sub_id ) return;
        if ( ! function_exists('wcs_get_subscription') ) return;

        $sub = wcs_get_subscription($sub_id);
        if ( ! $sub ) return;

        $start_ts = $sub->get_time('start');
        $next_ts  = $sub->get_time('next_payment');
        if ( ! $next_ts ) $next_ts = $sub->get_time('end');

        if ( $start_ts ) update_post_meta($ad_post_id, '_meadow_start_date', $start_ts);
        if ( $next_ts )  update_post_meta($ad_post_id, '_meadow_end_date', $next_ts);
    }

    private function enforce_ad_kiosk_limit( int $ad_post_id ) {
        $sub_id = (int) get_post_meta($ad_post_id, '_meadow_subscription_id', true);
        if ( ! $sub_id ) return;

        $limit = $this->get_kiosk_limit_for_subscription($sub_id);
        if ( $limit <= 0 ) return;

        $children = $this->jet_relation_get_children(self::RELATION_AD_TO_KIOSK_ID, $ad_post_id);
        if ( count($children) <= $limit ) return;

        $to_remove = array_slice($children, $limit);
        foreach ($to_remove as $kid) {
            $this->jet_relation_delete_link(self::RELATION_AD_TO_KIOSK_ID, $ad_post_id, (int)$kid);
        }

        update_post_meta($ad_post_id, '_meadow_last_limit_trim', [
            'ts' => time(),
            'limit' => $limit,
            'removed' => array_values(array_map('intval', $to_remove)),
        ]);
    }

    private function query_ads_for_playlist( array $args ): array {
        $billing = (string) ($args['billing_model'] ?? '');
        $now     = (int)    ($args['now'] ?? time());

        $q = new WP_Query([
            'post_type'      => self::AD_POST_TYPE,
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [ 'key' => '_meadow_billing_model', 'value' => $billing, 'compare' => '=' ],
                [ 'key' => '_meadow_status', 'value' => 'active', 'compare' => '=' ],
            ],
            'no_found_rows'  => true,
        ]);

        $out = [];
        foreach ( $q->posts as $p ) {
            $start = get_post_meta($p->ID, '_meadow_start_date', true);
            $end   = get_post_meta($p->ID, '_meadow_end_date', true);
            $start_ts = $start ? $this->parse_date_to_ts($start) : 0;
            $end_ts   = $end   ? $this->parse_date_to_ts($end)   : 0;

            if ( $start_ts && $start_ts > $now ) continue;
            if ( $end_ts   && $end_ts < $now ) continue;

            $out[] = $p;
        }
        return $out;
    }

    private function parse_date_to_ts( $val ): int {
        if (is_numeric($val)) return (int)$val;
        $t = strtotime((string)$val);
        return $t ? (int)$t : 0;
    }

    private function format_ad_for_playlist( WP_Post $ad_post ) {
    $type = (string) get_post_meta($ad_post->ID, '_meadow_creative_type', true);

    // Duration (keep existing typo key, but add a correct fallback too)
    $dur  = (int) get_post_meta($ad_post->ID, '_meadow_ad_roation_time', true);
    if ( ! $dur ) {
        $dur = (int) get_post_meta($ad_post->ID, '_meadow_ad_rotation_time', true);
    }
    if ( ! $dur ) $dur = 10;

    // Pull the raw field (JetEngine/ACF may store ID, URL, or array)
    if ( $type === 'image' ) {
        $raw = get_post_meta($ad_post->ID, '_meadow_image_file', true);
    } elseif ( $type === 'video' ) {
        $raw = get_post_meta($ad_post->ID, '_meadow_video_file', true);
    } else {
        return null;
    }

    if ( empty($raw) ) return null;

    // Normalise to URL:
    $url = '';

    // If JetEngine stored an array
    if ( is_array($raw) ) {
        if ( !empty($raw['url']) ) {
            $url = (string) $raw['url'];
        } elseif ( !empty($raw['id']) ) {
            $url = (string) wp_get_attachment_url( (int) $raw['id'] );
        } else {
            // Sometimes it’s like [0 => <something>]
            $first = reset($raw);
            if ( is_numeric($first) ) $url = (string) wp_get_attachment_url( (int) $first );
            elseif ( is_string($first) ) $url = $first;
        }
    }
    // If stored as an attachment ID (string/int)
    elseif ( is_numeric($raw) ) {
        $url = (string) wp_get_attachment_url( (int) $raw );
    }
    // If stored as a direct URL
    elseif ( is_string($raw) ) {
        $url = $raw;
    }

    $url = trim($url);
    if ( $url === '' ) return null;

    return [
        'ad_id'    => (int) $ad_post->ID,
        'type'     => $type,
        'url'      => esc_url_raw($url),
        'duration' => (int) $dur,
    ];
}


    private function ad_matches_kiosk_segments( int $ad_post_id, array $kiosk_segments ): bool {
        if ( empty($kiosk_segments) ) return false;
        $ad_segments = wp_get_post_terms($ad_post_id, self::TAX_AD_SEGMENT, [ 'fields' => 'slugs' ]);
        if ( ! is_array($ad_segments) || empty($ad_segments) ) return false;
        return (bool) array_intersect($ad_segments, $kiosk_segments);
    }

    private function jet_relation_get_children( int $relation_id, int $parent_id ): array {
        if ( class_exists('\\Jet_Engine\\Relations\\Relations') ) {
            try {
                $rels = \Jet_Engine\Relations\Relations::instance();
                $rel = null;
                if (method_exists($rels, 'get_relation')) $rel = $rels->get_relation($relation_id);
                elseif (method_exists($rels, 'get_relation_by_id')) $rel = $rels->get_relation_by_id($relation_id);

                if ( $rel && method_exists($rel, 'get_children') ) {
                    $ids = $rel->get_children($parent_id, 'ids');
                    if (is_array($ids)) return array_values(array_map('intval', $ids));
                }
            } catch (\Throwable $e) {}
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jet_relations';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ( $exists !== $table ) return [];

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if ( ! in_array('relation_id', $cols, true) ) return [];

        $parent_col = in_array('parent_object_id', $cols, true) ? 'parent_object_id' : (in_array('parent_id', $cols, true) ? 'parent_id' : 'parent_object_id');
        $child_col  = in_array('child_object_id',  $cols, true) ? 'child_object_id'  : (in_array('child_id',  $cols, true) ? 'child_id'  : 'child_object_id');

        $sql = $wpdb->prepare("SELECT {$child_col} FROM {$table} WHERE relation_id=%d AND {$parent_col}=%d ORDER BY id ASC", $relation_id, $parent_id);
        $ids = $wpdb->get_col($sql);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    private function jet_relation_has_link( int $relation_id, int $parent_id, int $child_id ): bool {
        $kids = $this->jet_relation_get_children($relation_id, $parent_id);
        return in_array((int)$child_id, $kids, true);
    }

    private function jet_relation_delete_link( int $relation_id, int $parent_id, int $child_id ): bool {
        if ( class_exists('\\Jet_Engine\\Relations\\Relations') ) {
            try {
                $rels = \Jet_Engine\Relations\Relations::instance();
                $rel = null;
                if (method_exists($rels, 'get_relation')) $rel = $rels->get_relation($relation_id);
                elseif (method_exists($rels, 'get_relation_by_id')) $rel = $rels->get_relation_by_id($relation_id);

                if ( $rel ) {
                    if ( method_exists($rel, 'delete_rows') ) { $rel->delete_rows($parent_id, $child_id); return true; }
                    if ( method_exists($rel, 'remove') ) { $rel->remove($parent_id, $child_id); return true; }
                }
            } catch (\Throwable $e) {}
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jet_relations';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ( $exists !== $table ) return false;

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if ( ! in_array('relation_id', $cols, true) ) return false;

        $parent_col = in_array('parent_object_id', $cols, true) ? 'parent_object_id' : (in_array('parent_id', $cols, true) ? 'parent_id' : 'parent_object_id');
        $child_col  = in_array('child_object_id',  $cols, true) ? 'child_object_id'  : (in_array('child_id',  $cols, true) ? 'child_id'  : 'child_object_id');

        $wpdb->delete($table, [
            'relation_id' => $relation_id,
            $parent_col   => $parent_id,
            $child_col    => $child_id,
        ]);
        return true;
    }

    /* ---------------------------------------------
     * Kiosk page globals + no-cache
     * ------------------------------------------- */

    public function inject_kiosk_globals() {
        if ( ! function_exists('meadow_get_current_kiosk_from_page') ) return;

        [ $kiosk_post, $kiosk_id ] = meadow_get_current_kiosk_from_page();
        if ( ! $kiosk_post || ! $kiosk_id ) return;

        $api_key = (string) get_post_meta($kiosk_post->ID, '_meadow_api_key', true);

        $pi_base = (string) get_post_meta($kiosk_post->ID, '_meadow_pi_base_url', true);
        if ( ! $pi_base ) $pi_base = 'https://kiosk' . (int)$kiosk_id . '-pi.meadowvending.com';
        $pi_base = rtrim(trim($pi_base), '/');

        echo "<script>\n";
        echo "window.MEADOW_KIOSK_ID = " . (int)$kiosk_id . ";\n";
        echo "window.MEADOW_API_KEY  = " . wp_json_encode($api_key) . ";\n";
        echo "window.MEADOW_PI_BASE  = " . wp_json_encode($pi_base) . ";\n";
        echo "</script>\n";
    }

    public function nocache_kiosk_paths() {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        if ( strpos($path, '/kiosk') === 0 ) {
            $this->nocache();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    /* ---------------------------------------------
     * Virtual meta bridge + write-through
     * ------------------------------------------- */

    public function filter_virtual_kiosk_meta($value, $object_id, $meta_key, $single) {
        $keys = [
            '_meadow_screen_mode',
            '_meadow_screen_order_id',
            '_meadow_idle_timeout',
            '_meadow_thankyou_timeout',
            '_meadow_last_seen',
            '_meadow_config_version',
            '_meadow_sigma_terminal_id',
            '_meadow_sigma_imei',
        ];

        if ( ! $meta_key || ! in_array( $meta_key, $keys, true ) ) return $value;
        if ( get_post_type($object_id) !== 'kiosk' ) return $value;

        global $wpdb;
        $table = $this->table_name();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE kiosk_post_id=%d", $object_id), ARRAY_A);
        if ( ! $row ) return $value;

        switch ($meta_key) {
            case '_meadow_screen_mode':        $out = (string) ($row['screen_mode'] ?? ''); break;
            case '_meadow_screen_order_id':    $out = (string) ($row['screen_order_id'] ?? '0'); break;
            case '_meadow_idle_timeout':       $out = (string) ($row['idle_timeout'] ?? '0'); break;
            case '_meadow_thankyou_timeout':   $out = (string) ($row['thankyou_timeout'] ?? '0'); break;
            case '_meadow_last_seen':
                $out = ! empty($row['last_seen_utc']) ? (string) strtotime($row['last_seen_utc'] . ' UTC') : '';
                break;
            case '_meadow_config_version':     $out = (string) ($row['config_version'] ?? ''); break;
            case '_meadow_sigma_terminal_id':  $out = (string) ($row['sigma_terminal_id'] ?? ''); break;
            case '_meadow_sigma_imei':         $out = (string) ($row['sigma_imei'] ?? ''); break;
            default: $out = '';
        }

        return $single ? $out : [ $out ];
    }

    public function filter_update_kiosk_meta($check, $object_id, $meta_key, $meta_value, $prev_value) {
        $map = [
            '_meadow_screen_mode'        => 'screen_mode',
            '_meadow_screen_order_id'    => 'screen_order_id',
            '_meadow_idle_timeout'       => 'idle_timeout',
            '_meadow_thankyou_timeout'   => 'thankyou_timeout',
            '_meadow_last_seen'          => 'last_seen_utc',
            '_meadow_config_version'     => 'config_version',
            '_meadow_sigma_terminal_id'  => 'sigma_terminal_id',
            '_meadow_sigma_imei'         => 'sigma_imei',
        ];

        if (!$meta_key || !isset($map[$meta_key])) return $check;
        if (get_post_type($object_id) !== 'kiosk') return $check;

        $col = $map[$meta_key];

        if ($col === 'last_seen_utc') {
            $value = $this->utc_now_mysql();
        } else {
            $value = is_scalar($meta_value) ? (string)$meta_value : maybe_serialize($meta_value);
        }

        $this->upsert_kiosk_row((int)$object_id, [ $col => $value ]);
        return true;
    }
}
