<?php
if ( ! defined('ABSPATH') ) exit;

class Meadow_Kiosk_Pi_Bridge {

    public function __construct() {
        add_action('rest_api_init', [ $this, 'register_routes' ]);
    }

    public function register_routes() {

        // Kiosk-authenticated (used by kiosk UI)
        register_rest_route('meadow/v1', '/pi/purchase', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_pi_purchase' ],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('meadow/v1', '/pi/vend', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_pi_vend' ],
            'permission_callback' => '__return_true',
        ]);

        // Admin-only device ops (used by wp-admin tools)
        register_rest_route('meadow/v1', '/admin/pi/control', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_admin_pi_control' ],
            'permission_callback' => function() { return is_user_logged_in() && current_user_can('manage_options'); },
        ]);

        register_rest_route('meadow/v1', '/admin/pi/vend-test', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_admin_pi_vend_test' ],
            'permission_callback' => function() { return is_user_logged_in() && current_user_can('manage_options'); },
        ]);
    }

    private function get_pi_base_for_kiosk_post(int $kiosk_post_id): string {
        $explicit = (string) get_post_meta($kiosk_post_id, '_meadow_pi_base_url', true);
        $explicit = trim($explicit);
        if ( $explicit !== '' ) return rtrim($explicit, '/');

        $kiosk_id = (int) get_post_meta($kiosk_post_id, '_meadow_kiosk_id', true);
        if ( $kiosk_id > 0 ) return 'https://kiosk' . $kiosk_id . '-pi.meadowvending.com';

        return '';
    }

    private function proxy_to_pi(int $kiosk_post_id, string $path, array $payload, int $timeout = 15) {
        $base = $this->get_pi_base_for_kiosk_post($kiosk_post_id);
        if ( $base === '' ) {
            return new WP_Error('server_error','No Pi base URL configured for kiosk',[ 'status'=>500 ]);
        }

        $url = rtrim($base, '/') . '/' . ltrim($path, '/');

        $args = [
            'timeout' => max(5, $timeout),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ];

        $res = wp_remote_post($url, $args);
        if ( is_wp_error($res) ) {
            return new WP_Error('bad_gateway','Pi request failed: ' . $res->get_error_message(),[ 'status'=>502 ]);
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);

        $json = null;
        if ( $body !== '' ) {
            $decoded = json_decode($body, true);
            if ( json_last_error() === JSON_ERROR_NONE ) $json = $decoded;
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'bad_gateway',
                'Pi returned HTTP ' . $code,
                [ 'status'=>502, 'pi_http_code'=>$code, 'pi_body'=> ($json !== null ? $json : $body) ]
            );
        }

        return ($json !== null) ? $json : [ 'ok'=>true, 'raw'=>$body ];
    }

    /* -------------------------
     * Kiosk-auth endpoints
     * ----------------------- */

    public function rest_pi_purchase(WP_REST_Request $req) {
        meadow_kiosk_nocache_headers();

        $kiosk = meadow_kiosk_require_kiosk_auth_from_request($req);
        if ( is_wp_error($kiosk) ) return $kiosk;

        $amount_minor = (int) $req->get_param('amount_minor');
        $currency_num = (string) $req->get_param('currency_num');
        $reference    = (string) $req->get_param('reference');

        if ( $amount_minor <= 0 || $currency_num === '' || $reference === '' ) {
            return new WP_Error('bad_request','Missing amount_minor, currency_num or reference',[ 'status'=>400 ]);
        }

        $payload = [
            'amount_minor' => $amount_minor,
            'currency_num' => $currency_num,
            'reference'    => $reference,
        ];

        $timeout = 220; // sigma flow can be long
        return $this->proxy_to_pi((int)$kiosk->ID, '/sigma/purchase', $payload, $timeout);
    }

    public function rest_pi_vend(WP_REST_Request $req) {
        meadow_kiosk_nocache_headers();

        $kiosk = meadow_kiosk_require_kiosk_auth_from_request($req);
        if ( is_wp_error($kiosk) ) return $kiosk;

        $motor = (int) $req->get_param('motor');
        if ( $motor <= 0 ) return new WP_Error('bad_request','Missing motor',[ 'status'=>400 ]);

        $payload = [ 'motor' => $motor ];
        return $this->proxy_to_pi((int)$kiosk->ID, '/vend', $payload, 20);
    }

    /* -------------------------
     * Admin-only endpoints
     * ----------------------- */

    private function resolve_kiosk_post_from_admin_payload(array $data) {
        $kiosk_post_id = (int)($data['kiosk_post_id'] ?? 0);
        if ($kiosk_post_id > 0 && get_post_type($kiosk_post_id) === 'kiosk') {
            return get_post($kiosk_post_id);
        }

        $kiosk_id = (int)($data['kiosk_id'] ?? 0);
        if ($kiosk_id > 0) {
            return meadow_kiosk_get_kiosk_by_kiosk_id($kiosk_id);
        }

        return null;
    }

    public function rest_admin_pi_control(WP_REST_Request $req) {
        meadow_kiosk_nocache_headers();

        $data = (array) $req->get_json_params();
        $action = isset($data['action']) ? trim((string)$data['action']) : '';
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        if ($action === '') return new WP_Error('bad_request','Missing action',[ 'status'=>400 ]);

        $kiosk = $this->resolve_kiosk_post_from_admin_payload($data);
        if ( ! $kiosk ) return new WP_Error('not_found','Kiosk not found',[ 'status'=>404 ]);

        // Pi expects: { kiosk_id, key, action, payload }
        $kiosk_id = (int) get_post_meta($kiosk->ID, '_meadow_kiosk_id', true);
        $key      = (string) get_post_meta($kiosk->ID, '_meadow_api_key', true);
        if (!$kiosk_id || trim($key)==='') return new WP_Error('forbidden','Kiosk missing kiosk_id or api key',[ 'status'=>403 ]);

        $pi_payload = [
            'kiosk_id' => $kiosk_id,
            'key'      => $key,
            'action'   => $action,
            'payload'  => $payload,
        ];

        return $this->proxy_to_pi((int)$kiosk->ID, '/admin/control', $pi_payload, 30);
    }

    public function rest_admin_pi_vend_test(WP_REST_Request $req) {
        meadow_kiosk_nocache_headers();

        $data = (array) $req->get_json_params();
        $motor = (int)($data['motor'] ?? 0);
        if ($motor <= 0) return new WP_Error('bad_request','Missing motor',[ 'status'=>400 ]);

        $kiosk = $this->resolve_kiosk_post_from_admin_payload($data);
        if ( ! $kiosk ) return new WP_Error('not_found','Kiosk not found',[ 'status'=>404 ]);

        $kiosk_id = (int) get_post_meta($kiosk->ID, '_meadow_kiosk_id', true);
        $key      = (string) get_post_meta($kiosk->ID, '_meadow_api_key', true);
        if (!$kiosk_id || trim($key)==='') return new WP_Error('forbidden','Kiosk missing kiosk_id or api key',[ 'status'=>403 ]);

        $pi_payload = [
            'kiosk_id' => $kiosk_id,
            'key'      => $key,
            'motor'    => $motor,
        ];

        return $this->proxy_to_pi((int)$kiosk->ID, '/admin/vend-test', $pi_payload, 25);
    }
}
