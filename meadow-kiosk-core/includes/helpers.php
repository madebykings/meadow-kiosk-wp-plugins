<?php
/**
 * Meadow Kiosk Core - Helpers
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * DB table name for kiosk state.
 */
function meadow_kiosk_state_table_name(): string {
  global $wpdb;
  return $wpdb->prefix . 'meadow_kiosk_state';
}

/**
 * "Admin request" helper: allow admins to bypass kiosk key checks in some endpoints.
 * (Use manage_options, not just logged-in.)
 */
function meadow_kiosk_is_admin_request(): bool {
  return is_user_logged_in() && current_user_can('manage_options');
}

/**
 * Get kiosk post by numeric kiosk_id stored in meta _meadow_kiosk_id.
 */
function meadow_kiosk_get_kiosk_by_kiosk_id(int $kiosk_id) {
  $kiosk_id = (int) $kiosk_id;
  if ($kiosk_id <= 0) return null;

  $q = new WP_Query([
    'post_type'      => 'kiosk',
    'meta_key'       => '_meadow_kiosk_id',
    'meta_value'     => (string) $kiosk_id,
    'posts_per_page' => 1,
    'post_status'    => 'any',
    'no_found_rows'  => true,
  ]);

  return $q->posts[0] ?? null;
}

/**
 * Get kiosk post by provision token in meta _meadow_kiosk_token.
 */
function meadow_kiosk_get_kiosk_by_token(string $token) {
  $token = trim((string)$token);
  if ($token === '') return null;

  $q = new WP_Query([
    'post_type'      => 'kiosk',
    'meta_key'       => '_meadow_kiosk_token',
    'meta_value'     => $token,
    'posts_per_page' => 1,
    'post_status'    => 'any',
    'no_found_rows'  => true,
  ]);

  return $q->posts[0] ?? null;
}

/**
 * Require kiosk_id + key (api key) unless admin.
 * Returns WP_Post kiosk OR WP_Error.
 */
function meadow_kiosk_require_kiosk_auth_from_values(int $kiosk_id, string $key) {
  $kiosk_id = (int)$kiosk_id;
  $key = trim((string)$key);

  if ( $kiosk_id <= 0 || $key === '' ) {
    return new WP_Error('bad_request', 'Missing kiosk_id or key', [ 'status' => 400 ]);
  }

  $kiosk = meadow_kiosk_get_kiosk_by_kiosk_id($kiosk_id);
  if ( ! $kiosk ) {
    return new WP_Error('not_found', 'Kiosk not found (check _meadow_kiosk_id)', [ 'status' => 404 ]);
  }

  $expected = trim((string) get_post_meta($kiosk->ID, '_meadow_api_key', true));
  if ( $expected === '' ) {
    return new WP_Error('forbidden', 'Kiosk has no _meadow_api_key set', [ 'status' => 403 ]);
  }

  if ( ! hash_equals($expected, $key) ) {
    return new WP_Error('forbidden', 'Invalid kiosk api key', [ 'status' => 403 ]);
  }

  return $kiosk;
}

/**
 * Require kiosk auth from REST request.
 * Admins may bypass key requirement but must supply kiosk_id.
 */
function meadow_kiosk_require_kiosk_auth_from_request(WP_REST_Request $req) {
  $kiosk_id = (int) $req->get_param('kiosk_id');

  if ( meadow_kiosk_is_admin_request() ) {
    if ( $kiosk_id <= 0 ) {
      return new WP_Error('bad_request', 'Missing kiosk_id', [ 'status' => 400 ]);
    }
    $kiosk = meadow_kiosk_get_kiosk_by_kiosk_id($kiosk_id);
    if ( ! $kiosk ) {
      return new WP_Error('not_found', 'Kiosk not found (check _meadow_kiosk_id)', [ 'status' => 404 ]);
    }
    return $kiosk;
  }

  $key = $req->get_param('key');
  if ($key === null || $key === '') $key = $req->get_param('api_key');
  $key = is_string($key) ? trim($key) : '';

  return meadow_kiosk_require_kiosk_auth_from_values($kiosk_id, $key);
}

/**
 * Disable caching for REST endpoints and kiosk pages.
 */
function meadow_kiosk_nocache_headers(): void {
  if ( ! defined('DONOTCACHEPAGE') ) define('DONOTCACHEPAGE', true);
  nocache_headers();
}
