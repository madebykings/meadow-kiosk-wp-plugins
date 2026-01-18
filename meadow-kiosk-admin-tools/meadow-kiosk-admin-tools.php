<?php
/**
 * Plugin Name: Meadow Kiosk Admin Tools
 * Description: Admin metabox + REST bridge tools for Meadow kiosks (Pi control, test vend, screen reset).
 * Version: 1.0.0
 */

if ( ! defined('ABSPATH') ) exit;

define('MEADOW_KIOSK_ADMIN_TOOLS_VERSION', '2026-01-18-1');
define('MEADOW_KIOSK_ADMIN_TOOLS_URL', plugin_dir_url(__FILE__));

/**
 * Require Meadow Kiosk Core (we use constants + helper funcs like meadow_kiosk_table_name()).
 */
add_action('plugins_loaded', function () {
    if ( ! class_exists('Meadow_Kiosk_Core') ) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Meadow Kiosk Admin Tools</strong> requires <strong>Meadow Kiosk Core</strong> to be active.</p></div>';
        });
        return;
    }
});

/**
 * Metabox
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'meadow_kiosk_controls',
        'Meadow — Kiosk Controls',
        'meadow_admin_tools_render_metabox',
        'kiosk',
        'side',
        'high'
    );
});

/**
 * Enqueue admin JS only on kiosk edit screen.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ( ! in_array($hook, ['post.php','post-new.php'], true) ) return;

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'kiosk' ) return;

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ( ! $post_id ) return;

    wp_enqueue_script(
        'meadow-kiosk-admin-tools',
        MEADOW_KIOSK_ADMIN_TOOLS_URL . 'assets/admin-tools.js',
        ['jquery'],
        MEADOW_KIOSK_ADMIN_TOOLS_VERSION,
        true
    );

    $kiosk_id = (int) get_post_meta($post_id, '_meadow_kiosk_id', true);

    wp_localize_script('meadow-kiosk-admin-tools', 'MEADOW_ADMIN_TOOLS', [
        'postId'  => $post_id,
        'kioskId' => $kiosk_id,
        'nonce'   => wp_create_nonce('wp_rest'),
        'rest'    => [
            'screenReset' => rest_url('meadow/v1/admin/screen-reset'),
            'piControl'   => rest_url('meadow/v1/admin/pi/control'),
            'vendTest'    => rest_url('meadow/v1/admin/pi/vend-test'),
            'piStatus'    => rest_url('meadow/v1/admin/pi/status'),
        ],
    ]);
});

/**
 * REST routes (WP-side). These are called by admin-tools.js.
 */
add_action('rest_api_init', function () {
    $perm = function (WP_REST_Request $req) {
        return is_user_logged_in() && current_user_can('edit_posts');
    };

    register_rest_route('meadow/v1', '/admin/pi/control', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => 'meadow_admin_tools_rest_pi_control',
    ]);

    register_rest_route('meadow/v1', '/admin/pi/vend-test', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => 'meadow_admin_tools_rest_pi_vend_test',
    ]);

    register_rest_route('meadow/v1', '/admin/pi/status', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => 'meadow_admin_tools_rest_pi_status',
    ]);

    // Keep screen reset here (separate from Core)
    register_rest_route('meadow/v1', '/admin/screen-reset', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            return is_user_logged_in() && current_user_can('edit_posts');
        },
        'callback'            => function (WP_REST_Request $req) {
            if (function_exists('meadow_kiosk_nocache_headers')) meadow_kiosk_nocache_headers();

            $data = (array) $req->get_json_params();
            $post_id = (int) ($data['kiosk_post_id'] ?? 0);

            $mode = isset($data['mode']) ? strtolower(trim((string) $data['mode'])) : 'ads';
            $allowed = ['ads','browse','payment','paid','vending','thankyou','error','payment_failed'];
            if ( ! in_array($mode, $allowed, true) ) $mode = 'ads';

            if ( ! $post_id || get_post_type($post_id) !== 'kiosk' ) {
                return new WP_Error('bad_request','Missing kiosk_post_id',[ 'status'=>400 ]);
            }
            if ( ! current_user_can('edit_post', $post_id) ) {
                return new WP_Error('forbidden','Not allowed',[ 'status'=>403 ]);
            }

            update_post_meta($post_id, '_meadow_screen_mode', $mode);
            delete_post_meta($post_id, '_meadow_screen_order_id');

            return [ 'ok'=>true, 'mode'=>$mode ];
        }
    ]);
});

/**
 * ---------- Pi bridge helpers ----------
 */

function meadow_admin_tools_get_pi_target_or_error(int $post_id) {
    if (!$post_id || get_post_type($post_id) !== 'kiosk') {
        return new WP_Error('bad_request', 'Missing kiosk_post_id', ['status' => 400]);
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
        return new WP_Error('forbidden', 'Not allowed', ['status' => 403]);
    }

    $kiosk_id = (int) get_post_meta($post_id, '_meadow_kiosk_id', true);

// key lives on kiosk post
$api_key = (string) get_post_meta($post_id, '_meadow_api_key', true);
if (!$api_key) {
    return new WP_Error('bad_request', 'Missing _meadow_api_key for kiosk', ['status' => 400]);
}

// optional override; otherwise default pattern
$pi_base = (string) get_post_meta($post_id, '_meadow_pi_base', true);
if (!$pi_base) {
    // default pattern
    $maybe = $kiosk_id ?: 0;
    $pi_base = $maybe ? "https://kiosk{$maybe}-pi.meadowvending.com" : "";
}

// If kiosk_id is missing/zero OR wrong, infer from pi_base hostname like kiosk1-pi.meadowvending.com
$pi_base = rtrim((string)$pi_base, '/');

if ((!$kiosk_id) && $pi_base) {
    if (preg_match('~//kiosk(\d+)-pi\.~i', $pi_base, $m)) {
        $kiosk_id = (int)$m[1];
    }
}

// If kiosk_id exists but doesn't match the pi_base hostname, trust pi_base.
// (This is exactly your case: you hit kiosk1-pi but sent a different kiosk_id.)
if ($kiosk_id && $pi_base) {
    if (preg_match('~//kiosk(\d+)-pi\.~i', $pi_base, $m)) {
        $host_id = (int)$m[1];
        if ($host_id && $host_id !== $kiosk_id) {
            $kiosk_id = $host_id;
        }
    }
}

if (!$kiosk_id) {
    return new WP_Error('bad_request', 'Missing kiosk_id (set _meadow_kiosk_id or _meadow_pi_base like https://kiosk1-pi...)', ['status' => 400]);
}

if (!$pi_base) {
    // if still empty, build it from kiosk_id
    $pi_base = "https://kiosk{$kiosk_id}-pi.meadowvending.com";
}


    return [
        'kiosk_id' => $kiosk_id,
        'api_key'  => $api_key,
        'pi_base'  => rtrim($pi_base, '/'),
    ];
}

/**
 * Calls Pi with headers (preferred, matches your updated pi_api.py).
 */
function meadow_admin_tools_call_pi(array $target, string $path, string $method = 'POST', array $body = null, int $timeout = 12) {
    $url = $target['pi_base'] . $path;

    $headers = [
        'Content-Type' => 'application/json',
        'X-Meadow-Key' => $target['api_key'],
        'X-Kiosk-Id'   => (string) $target['kiosk_id'],
        // Optional: also support Bearer style if you ever use it
        // 'Authorization' => 'Bearer ' . $target['api_key'],
    ];

    $args = [
        'timeout' => $timeout,
        'headers' => $headers,
        'method'  => $method,
    ];

    if ($method !== 'GET') {
        // ✅ include body auth too (backwards-compatible, prevents “missing id” bugs)
        $payload = is_array($body) ? $body : [];
        $payload['kiosk_id'] = (int) $target['kiosk_id'];
        $payload['key']      = (string) $target['api_key'];

        $args['body'] = wp_json_encode($payload);
    }

    $resp = wp_remote_request($url, $args);

    if (is_wp_error($resp)) {
        return new WP_Error('pi_error', $resp->get_error_message(), ['status' => 502]);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    return [
        'ok'        => ($code >= 200 && $code < 300),
        'pi_status' => $code,
        'pi_url'    => $url,
        'pi'        => is_array($json) ? $json : $raw,
    ];
}


/**
 * ---------- REST callbacks ----------
 */

function meadow_admin_tools_rest_pi_control(WP_REST_Request $req) {
    if (function_exists('meadow_kiosk_nocache_headers')) meadow_kiosk_nocache_headers();

    $data = (array) $req->get_json_params();
    $post_id = (int) ($data['kiosk_post_id'] ?? 0);
    $action  = (string) ($data['action'] ?? '');
    $payload = $data['payload'] ?? (object)[];

    $target = meadow_admin_tools_get_pi_target_or_error($post_id);
    if (is_wp_error($target)) return $target;

    if (!$action) {
        return new WP_Error('bad_request', 'Missing action', ['status' => 400]);
    }

    // Pi expects: { action, payload }
    return meadow_admin_tools_call_pi($target, '/admin/control', 'POST', [
        'action'  => $action,
        'payload' => is_array($payload) ? (object) $payload : $payload,
    ]);
}

function meadow_admin_tools_rest_pi_vend_test(WP_REST_Request $req) {
    if (function_exists('meadow_kiosk_nocache_headers')) meadow_kiosk_nocache_headers();

    $data = (array) $req->get_json_params();
    $post_id = (int) ($data['kiosk_post_id'] ?? 0);
    $motor   = (int) ($data['motor'] ?? 0);

    $target = meadow_admin_tools_get_pi_target_or_error($post_id);
    if (is_wp_error($target)) return $target;

    if ($motor <= 0) {
        return new WP_Error('bad_request', 'Missing motor', ['status' => 400]);
    }

    // Pi vend-test expects: { motor }
    return meadow_admin_tools_call_pi($target, '/admin/vend-test', 'POST', [
        'motor' => $motor,
    ]);
}

function meadow_admin_tools_rest_pi_status(WP_REST_Request $req) {
    if (function_exists('meadow_kiosk_nocache_headers')) meadow_kiosk_nocache_headers();

    $post_id = (int) $req->get_param('kiosk_post_id');

    $target = meadow_admin_tools_get_pi_target_or_error($post_id);
    if (is_wp_error($target)) return $target;

    return meadow_admin_tools_call_pi($target, '/admin/status', 'GET', null, 8);
}

/**
 * ---------- Metabox renderer ----------
 */
function meadow_admin_tools_render_metabox($post) {
    if ( ! $post || $post->post_type !== 'kiosk' ) return;

    $kiosk_id = (int) get_post_meta($post->ID, '_meadow_kiosk_id', true);

    // Read from migrated table if present (you already do this)
    global $wpdb;
    $mode = 'ads';
    $last = '';
    $git  = '';
    $rev  = 0;

    if (function_exists('meadow_kiosk_table_name')) {
        $table = meadow_kiosk_table_name();
        if ($table) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE kiosk_post_id=%d", $post->ID), ARRAY_A);
            if ($row) {
                $mode = ($row['screen_mode'] !== '' ? (string)$row['screen_mode'] : $mode);
                $last = (!empty($row['last_seen_utc']) ? (string)$row['last_seen_utc'] : '');
                $git  = (!empty($row['config_version']) ? (string)$row['config_version'] : '');
                $rev  = (int)($row['revision'] ?? 0);
            }
        }
    } else {
        $mode = (string)(get_post_meta($post->ID,'_meadow_screen_mode',true) ?: 'ads');
    }

    echo '<div class="meadow-admin-tools">';
    echo '<p><strong>Kiosk ID:</strong> ' . esc_html($kiosk_id ?: '—') . '</p>';
    echo '<p><strong>Screen:</strong> ' . esc_html($mode) . '</p>';
    echo '<p><strong>Last seen (UTC):</strong> ' . esc_html($last ?: '—') . '</p>';
    echo '<p><strong>Revision:</strong> ' . esc_html($rev) . '</p>';
    echo '<p><strong>Pi git:</strong> ' . esc_html($git ?: '—') . '</p>';

    echo '<hr/>';

    echo '<p style="margin:0 0 6px;"><strong>Pi controls</strong></p>';

    // Buttons (wired by JS)
    $btn = function($label, $action, $extra = '') use ($post) {
        $label_esc = esc_html($label);
        $action_esc = esc_attr($action);
        $extra_attr = $extra ? ' ' . $extra : '';
        echo "<button type=\"button\" class=\"button meadow-pi-action\" data-kiosk-post-id=\"" . (int)$post->ID . "\" data-action=\"{$action_esc}\"{$extra_attr}>{$label_esc}</button> ";
    };

    $btn('Enter kiosk',  'enter_kiosk');
    $btn('Exit kiosk',   'exit_kiosk');
    echo '<br/><br/>';
    $btn('Reload kiosk', 'reload_kiosk');
    $btn('Restart service', 'restart_service');
    echo '<br/><br/>';
    $btn('Reboot Pi',    'reboot');
    $btn('Shutdown Pi',  'shutdown');
    echo '<br/><br/>';
    $btn('Kill all',     'kill_all');
    echo '<div class="meadow-pi-status" style="margin-top:8px;font-size:12px;line-height:1.35;"></div>';

    echo '<hr/>';

    // Motors from repeater (same idea you already have)
    $slots = get_post_meta($post->ID, Meadow_Kiosk_Core::SLOT_REPEATER_META_KEY, true);
    if ( ! is_array($slots) ) $slots = [];
    $enabled_motors = [];

    foreach ($slots as $row2) {
        $raw = $row2[ Meadow_Kiosk_Core::SLOT_FIELD_ENABLED ] ?? null;
        $enabled = true;
        if ($raw !== '' && $raw !== null) {
            $s = strtolower(trim((string)$raw));
            $enabled = in_array($s, ['1','true','yes','y','on'], true);
        }
        if ( ! $enabled ) continue;

        $motor = (int) ($row2[ Meadow_Kiosk_Core::SLOT_FIELD_MOTOR ] ?? 0);
        if ($motor) $enabled_motors[] = $motor;
    }

    sort($enabled_motors);

    echo '<p style="margin:0 0 6px;"><strong>Test vend motors</strong></p>';

    if ( empty($enabled_motors) ) {
        echo '<p style="margin:0;color:#666;">No enabled motors found in repeater.</p>';
    } else {
        foreach ($enabled_motors as $m) {
            $m = (int) $m;
            echo '<button type="button" class="button meadow-vend-test" data-kiosk-post-id="' . (int)$post->ID . '" data-motor="' . $m . '">Spin motor ' . $m . '</button> ';
        }
    }

    echo '</div>';
}
