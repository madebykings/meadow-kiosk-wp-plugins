<?php
/**
 * Plugin Name: Meadow Kiosk Admin Tools
 * Description: Admin metabox controls for Meadow kiosks (reset screen, enter/exit/reboot, motor tests). Depends on Meadow Kiosk Core + Pi Bridge.
 * Version: 1.0.1
 */

if ( ! defined('ABSPATH') ) exit;

define('MEADOW_KIOSK_ADMIN_TOOLS_VERSION', '1.0.1');
define('MEADOW_KIOSK_ADMIN_TOOLS_PATH', plugin_dir_path(__FILE__));
define('MEADOW_KIOSK_ADMIN_TOOLS_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function(){

    if ( ! defined('MEADOW_KIOSK_CORE_VERSION') ) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>Meadow Kiosk Admin Tools</strong> requires <strong>Meadow Kiosk Core</strong> to be active.</p></div>';
        });
        return;
    }

    add_action('add_meta_boxes', function(){
        add_meta_box(
            'meadow_kiosk_controls',
            'Meadow — Kiosk Controls',
            'meadow_admin_tools_render_metabox',
            'kiosk',
            'side',
            'high'
        );
    });

    add_action('admin_enqueue_scripts', function($hook){
        if ( ! in_array($hook, ['post.php','post-new.php'], true) ) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'kiosk' ) return;

        $post_id = isset($_GET['post']) ? (int)$_GET['post'] : 0;
        if (!$post_id) return;

        wp_enqueue_script('meadow-kiosk-admin-tools', MEADOW_KIOSK_ADMIN_TOOLS_URL . 'assets/admin-tools.js', ['jquery'], MEADOW_KIOSK_ADMIN_TOOLS_VERSION, true);

        $kiosk_id = (int) get_post_meta($post_id, '_meadow_kiosk_id', true);

        wp_localize_script('meadow-kiosk-admin-tools', 'MEADOW_ADMIN_TOOLS', [
            'postId'  => $post_id,
            'kioskId' => $kiosk_id,
            'nonce'   => wp_create_nonce('wp_rest'),
            'rest'    => [
                'screenReset' => rest_url('meadow/v1/admin/screen-reset'),
                'piControl'   => rest_url('meadow/v1/admin/pi/control'),
                'vendTest'    => rest_url('meadow/v1/admin/pi/vend-test'),
            ],
        ]);
    });

    // Admin REST: reset screen (kept separate from Core)
    add_action('rest_api_init', function(){

        add_action('rest_api_init', function () {

  $perm = function(WP_REST_Request $req){
    return is_user_logged_in() && current_user_can('edit_posts');
  };

  register_rest_route('meadow/v1', '/admin/kiosk-enter', [
    'methods' => 'POST',
    'permission_callback' => $perm,
    'callback' => function(WP_REST_Request $req){
      return meadow_admin_tools_pi_control_action($req, 'enter_kiosk');
    }
  ]);

  register_rest_route('meadow/v1', '/admin/kiosk-exit', [
    'methods' => 'POST',
    'permission_callback' => $perm,
    'callback' => function(WP_REST_Request $req){
      return meadow_admin_tools_pi_control_action($req, 'exit_kiosk');
    }
  ]);
});

/**
 * Calls the Pi tunnel /admin/control with kiosk_id+key.
 */
function meadow_admin_tools_pi_control_action(WP_REST_Request $req, $action) {

  if (function_exists('meadow_kiosk_nocache_headers')) {
    meadow_kiosk_nocache_headers();
  }

  $data = (array) $req->get_json_params();
  $post_id = (int)($data['kiosk_post_id'] ?? 0);

  if (!$post_id || get_post_type($post_id) !== 'kiosk') {
    return new WP_Error('bad_request', 'Missing kiosk_post_id', ['status' => 400]);
  }
  if (!current_user_can('edit_post', $post_id)) {
    return new WP_Error('forbidden', 'Not allowed', ['status' => 403]);
  }

  $kiosk_id = (int) get_post_meta($post_id, '_meadow_kiosk_id', true);
  if (!$kiosk_id) {
    return new WP_Error('bad_request', 'Missing _meadow_kiosk_id', ['status' => 400]);
  }

  // ✅ Use the real place your key lives
  $api_key = (string) get_post_meta($post_id, '_meadow_api_key', true);
  if (!$api_key) {
    return new WP_Error('bad_request', 'Missing _meadow_api_key for kiosk', ['status' => 400]);
  }

  $pi_base = (string) get_post_meta($post_id, '_meadow_pi_base', true);
  if (!$pi_base) {
    $pi_base = "https://kiosk{$kiosk_id}-pi.meadowvending.com";
  }

  $payload = [
    'kiosk_id' => $kiosk_id,
    'key'      => $api_key,
    'action'   => (string)$action,
    'payload'  => (object)[],
  ];

  $resp = wp_remote_post(rtrim($pi_base, '/') . '/admin/control', [
    'timeout' => 10,
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => wp_json_encode($payload),
  ]);

  if (is_wp_error($resp)) {
    return new WP_Error('pi_error', $resp->get_error_message(), ['status' => 502]);
  }

  $code = (int) wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);

  return [
    'ok'        => ($code >= 200 && $code < 300),
    'pi_status' => $code,
    'pi_base'   => $pi_base,
    'sent'      => $payload,
    'pi'        => is_array($json) ? $json : $body,
  ];
}



        register_rest_route('meadow/v1', '/admin/screen-reset', [
            'methods' => 'POST',
            'permission_callback' => function(WP_REST_Request $req){
                return is_user_logged_in() && current_user_can('edit_posts');
            },
            'callback' => function(WP_REST_Request $req){
                meadow_kiosk_nocache_headers();

                $data = (array) $req->get_json_params();
                $post_id = (int)($data['kiosk_post_id'] ?? 0);
                $mode = isset($data['mode']) ? strtolower(trim((string)$data['mode'])) : 'ads';

                $allowed = ['ads','browse','payment','paid','vending','thankyou','error','payment_failed'];
                if ( ! in_array($mode, $allowed, true) ) $mode = 'ads';

                if (!$post_id || get_post_type($post_id) !== 'kiosk') {
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
});

/**
 * Metabox renderer
 */
function meadow_admin_tools_render_metabox($post) {

    if ( ! $post || $post->post_type !== 'kiosk' ) return;

    $kiosk_id = (int) get_post_meta($post->ID, '_meadow_kiosk_id', true);

    // Read from migrated table via virtual meta / table if present
    global $wpdb;
    $table = meadow_kiosk_table_name();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE kiosk_post_id=%d", $post->ID), ARRAY_A);

    $mode = $row && $row['screen_mode'] !== '' ? (string)$row['screen_mode'] : (string)(get_post_meta($post->ID,'_meadow_screen_mode',true) ?: 'ads');
    $last = $row && !empty($row['last_seen_utc']) ? (string)$row['last_seen_utc'] : '';
    $git  = $row && !empty($row['config_version']) ? (string)$row['config_version'] : '';
    $rev  = $row ? (int)$row['revision'] : 0;

    echo '<div class="meadow-kiosk-controls" style="font-size:12px; line-height:1.45;">';
    echo '<div><strong>Kiosk ID:</strong> ' . esc_html($kiosk_id ?: '—') . '</div>';
    echo '<div><strong>Screen:</strong> ' . esc_html($mode) . '</div>';
    echo '<div><strong>Last seen (UTC):</strong> ' . esc_html($last ?: '—') . '</div>';
    echo '<div><strong>Revision:</strong> ' . esc_html($rev) . '</div>';
    echo '<div><strong>Pi git:</strong> ' . esc_html($git ?: '—') . '</div>';
    echo '<div class="meadow-ctrl-status" style="margin-top:8px; color:#555;"></div>';
    echo '</div>';

    echo '<hr style="margin:12px 0;" />';

    echo '<p style="margin-top:8px;">
  <button type="button" class="button button-primary" id="meadowEnterKiosk">Enter kiosk</button>
  <button type="button" class="button" id="meadowExitKiosk" style="margin-left:6px;">Exit kiosk</button>
</p>
<pre id="meadowKioskCtlOut" style="white-space:pre-wrap; background:#111; color:#0f0; padding:8px; max-height:160px; overflow:auto;"></pre>';

    //echo '<div style="display:grid; gap:6px;">';
    //echo '<a href="#" class="button button-secondary" style="width:100%;" data-meadow-reset="ads">Reset screen → Ads</a>';
    //echo '<a href="#" class="button button-secondary" style="width:100%;" data-meadow-reset="browse">Reset screen → Browse</a>';
    //echo '</div>';

    echo '<hr style="margin:12px 0;" />';

    // Device ops (Pi Bridge required)
    //echo '<div style="display:grid; gap:6px;">';
    //echo '<a href="#" class="button" style="width:100%;" data-meadow-pi-action="enter_kiosk">Enter kiosk</a>';
    //echo '<a href="#" class="button" style="width:100%;" data-meadow-pi-action="exit_kiosk">Exit kiosk</a>';
    //echo '<a href="#" class="button" style="width:100%;" data-meadow-pi-action="reload_kiosk">Reload kiosk</a>';
    //echo '<a href="#" class="button" style="width:100%;" data-meadow-pi-action="reboot">Reboot Pi</a>';
    //echo '<a href="#" class="button" style="width:100%;" data-meadow-pi-action="shutdown">Shutdown Pi</a>';
    //echo '</div>';

    echo '<hr style="margin:12px 0;" />';

    $enter_url = esc_js(rest_url('meadow/v1/admin/kiosk-enter'));
$exit_url  = esc_js(rest_url('meadow/v1/admin/kiosk-exit'));
$nonce     = esc_js(wp_create_nonce('wp_rest'));
$post_id_js = (int)$post->ID;

echo "<script>
(() => {
  const out = document.getElementById('meadowKioskCtlOut');
  const postId = {$post_id_js};

  async function call(url){
    out.textContent = 'Sending...\\n';
    const r = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': '{$nonce}'
      },
      body: JSON.stringify({ kiosk_post_id: postId })
    });
    const j = await r.json().catch(() => ({}));
    out.textContent = 'HTTP ' + r.status + '\\n' + JSON.stringify(j, null, 2);
  }

  document.getElementById('meadowEnterKiosk')?.addEventListener('click', () => call('{$enter_url}'));
  document.getElementById('meadowExitKiosk')?.addEventListener('click',  () => call('{$exit_url}'));
})();
</script>";


    // Motors from repeater
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

    echo '<div style="font-size:12px; margin-bottom:6px;"><strong>Test vend motors</strong></div>';
    if ( empty($enabled_motors) ) {
        echo '<div style="font-size:12px; color:#666;">No enabled motors found in repeater.</div>';
    } else {
        foreach ($enabled_motors as $m) {
            echo '<a href="#" class="button" style="width:100%; margin:0 0 6px 0;" data-meadow-motor="' . (int)$m . '">Spin motor ' . (int)$m . '</a>';
        }
    }
}
