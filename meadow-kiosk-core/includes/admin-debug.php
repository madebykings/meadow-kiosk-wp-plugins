<?php
/**
 * Meadow Kiosk Core - Admin Debug UI
 *
 * - Right sidebar "Meadow Debug" meta box on Woo Orders (HPOS + classic)
 * - "Meadow Payment Debug" meta box on meadow_payment CPT
 * - Columns on meadow_payment list for Order/Ref/Payment/Vend
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------
 * 1) Woo Orders: Right-side Meadow Debug (HPOS + classic)
 * ----------------------------------------------------- */

add_action('add_meta_boxes', function() {

  // Classic order editor (post.php?post=ID)
  add_meta_box(
    'meadow_order_debug_side',
    'Meadow Debug',
    'meadow_render_order_debug_metabox',
    'shop_order',
    'side',
    'high'
  );

  // HPOS order editor (admin.php?page=wc-orders&action=edit&id=ID)
  add_meta_box(
    'meadow_order_debug_side_hpos',
    'Meadow Debug',
    'meadow_render_order_debug_metabox',
    'woocommerce_page_wc-orders',
    'side',
    'high'
  );
}, 20);

/**
 * Render Meadow Debug meta box for an order (HPOS-safe).
 */
function meadow_render_order_debug_metabox($post_or_null): void {
  if ( ! function_exists('wc_get_order') ) {
    echo '<p><em>WooCommerce not loaded.</em></p>';
    return;
  }

  // HPOS: ?page=wc-orders&action=edit&id=800
  $order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

  // Classic: post.php?post=800&action=edit
  if (!$order_id && is_object($post_or_null) && !empty($post_or_null->ID)) {
    $order_id = (int) $post_or_null->ID;
  }
  if (!$order_id && isset($_GET['post'])) {
    $order_id = (int) $_GET['post'];
  }

  if ( ! $order_id ) {
    echo '<p><em>No order found.</em></p>';
    return;
  }

  $order = wc_get_order($order_id);
  if ( ! $order ) {
    echo '<p><em>Order not found.</em></p>';
    return;
  }

  // Only show for kiosk orders (optional). Comment these 2 lines to show always.
  $order_type = (string) $order->get_meta('_meadow_order_type', true);
  if ($order_type !== '' && $order_type !== 'kiosk') {
    echo '<p><em>Not a Meadow kiosk order.</em></p>';
    return;
  }

  $keys = [
    '_meadow_order_type',
    '_meadow_kiosk_id',
    '_meadow_motor',
    '_meadow_slot_index',
    '_meadow_product_id',
    '_meadow_reference',
    '_meadow_session_id',
    '_meadow_amount_minor',
    '_meadow_currency_num',
  ];

  echo '<table class="widefat striped" style="margin-top:6px;">';
  echo '<thead><tr><th style="width:55%;">Key</th><th>Value</th></tr></thead><tbody>';

  foreach ($keys as $k) {
    $v = $order->get_meta($k, true);
    if (is_array($v) || is_object($v)) $v = wp_json_encode($v);
    $v = (string) $v;
    echo '<tr><td><code>' . esc_html($k) . '</code></td><td><code>' . esc_html($v !== '' ? $v : '—') . '</code></td></tr>';
  }

  echo '</tbody></table>';

  echo '<details style="margin-top:8px;"><summary>Show all Meadow meta (_meadow_*)</summary>';
  echo '<pre style="white-space:pre-wrap; margin-top:6px;">';

  foreach ($order->get_meta_data() as $m) {
    $data = method_exists($m, 'get_data') ? $m->get_data() : [];
    $key  = $data['key'] ?? '';
    if (!is_string($key) || strpos($key, '_meadow_') !== 0) continue;

    $val  = $data['value'] ?? '';
    if (is_array($val) || is_object($val)) $val = wp_json_encode($val);

    echo esc_html($key . ' = ' . (string)$val) . "\n";
  }

  echo '</pre></details>';
}

/* -------------------------------------------------------
 * 2) meadow_payment: Debug meta box
 * ----------------------------------------------------- */

add_action('add_meta_boxes', function() {
  add_meta_box(
    'meadow_payment_debug',
    'Meadow Payment Debug',
    'meadow_render_payment_debug_metabox',
    'meadow_payment',
    'normal',
    'high'
  );
}, 20);

function meadow_render_payment_debug_metabox(\WP_Post $post): void {
  if ( ! $post || $post->post_type !== 'meadow_payment' ) return;

  $keys = [
    '_meadow_order_id',
    '_meadow_kiosk_id',
    '_meadow_motor',
    '_meadow_slot_index',
    '_meadow_product_id',
    '_meadow_session_id',
    '_meadow_reference',
    '_meadow_amount_minor',
    '_meadow_currency',
    '_meadow_currency_num',
    '_meadow_payment_status',
    '_meadow_vend_status',
    '_meadow_sigma_status',
    '_meadow_sigma_stage',
    '_meadow_sigma_txid',
    '_meadow_payment_ts',
    '_meadow_created_ts',
  ];

  echo '<style>.meadow-debug-table code{white-space:pre-wrap}</style>';
  echo '<table class="widefat striped meadow-debug-table">';
  echo '<thead><tr><th style="width:320px;">Key</th><th>Value</th></tr></thead><tbody>';

  foreach ($keys as $k) {
    $v = get_post_meta($post->ID, $k, true);
    if (is_array($v) || is_object($v)) $v = wp_json_encode($v);
    $v = (string)$v;

    if ($k === '_meadow_order_id' && ctype_digit($v) && (int)$v > 0) {
      $order_id = (int)$v;
      $url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
      echo '<tr><td><code>' . esc_html($k) . '</code></td><td><a href="' . esc_url($url) . '"><code>#' . esc_html($order_id) . '</code></a></td></tr>';
      continue;
    }

    echo '<tr><td><code>' . esc_html($k) . '</code></td><td><code>' . esc_html($v !== '' ? $v : '—') . '</code></td></tr>';
  }

  echo '</tbody></table>';

  echo '<details style="margin-top:12px;"><summary>Show all meta</summary><pre style="white-space:pre-wrap;">';
  $meta = get_post_meta($post->ID);
  foreach ($meta as $mk => $vals) {
    $vals = array_map(function($x){
      $x = maybe_unserialize($x);
      if (is_array($x) || is_object($x)) return wp_json_encode($x);
      return (string)$x;
    }, $vals);
    echo esc_html($mk . ' = ' . implode(', ', $vals)) . "\n";
  }
  echo '</pre></details>';
}

/* -------------------------------------------------------
 * 3) meadow_payment list columns
 * ----------------------------------------------------- */

add_filter('manage_meadow_payment_posts_columns', function($cols){
  $cols['meadow_order'] = 'Order';
  $cols['meadow_ref']   = 'Reference';
  $cols['meadow_pay']   = 'Payment';
  $cols['meadow_vend']  = 'Vend';
  return $cols;
});

add_action('manage_meadow_payment_posts_custom_column', function($col, $post_id){
  if ($col === 'meadow_order') {
    $order_id = (int) get_post_meta($post_id, '_meadow_order_id', true);
    if (!$order_id) { echo '—'; return; }
    $url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
    echo '<a href="' . esc_url($url) . '">#' . esc_html($order_id) . '</a>';
    return;
  }
  if ($col === 'meadow_ref') {
    echo esc_html((string) get_post_meta($post_id, '_meadow_reference', true));
    return;
  }
  if ($col === 'meadow_pay') {
    // Your plugin tends to store status in _meadow_payment_status (per your debug box).
    // If you use _meadow_status instead, change it here.
    $v = get_post_meta($post_id, '_meadow_payment_status', true);
    if ($v === '') $v = get_post_meta($post_id, '_meadow_status', true);
    echo esc_html((string) $v);
    return;
  }
  if ($col === 'meadow_vend') {
    $v = get_post_meta($post_id, '_meadow_vend_status', true);
    echo esc_html((string) $v);
    return;
  }
}, 10, 2);
