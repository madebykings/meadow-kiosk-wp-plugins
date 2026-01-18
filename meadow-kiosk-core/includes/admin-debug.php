<?php
/**
 * Meadow Kiosk Core - Admin Debug UI
 *
 * Shows Meadow meta on Woo orders (HPOS-safe) and Meadow Payment CPT.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Woo Order: Show Meadow meta on order edit screen (HPOS-safe).
 */
add_action('woocommerce_admin_order_data_after_order_details', function($order){
  if ( ! $order || ! is_a($order, 'WC_Order') ) return;

  // Only show on Meadow kiosk orders (comment out to show always)
  $order_type = (string) $order->get_meta('_meadow_order_type', true);
  if ($order_type !== '' && $order_type !== 'kiosk') return;

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

  echo '<div class="order_data_column" style="padding:12px 0;">';
  echo '<h3>Meadow Debug</h3>';
  echo '<table class="widefat striped" style="max-width:980px;">';
  echo '<thead><tr><th style="width:320px;">Key</th><th>Value</th></tr></thead><tbody>';

  foreach ($keys as $k) {
    $v = $order->get_meta($k, true);
    if (is_array($v) || is_object($v)) $v = wp_json_encode($v);
    $v = (string) $v;
    echo '<tr><td><code>' . esc_html($k) . '</code></td><td><code>' . esc_html($v !== '' ? $v : '—') . '</code></td></tr>';
  }

  echo '</tbody></table>';

  // All Meadow-ish meta keys for sanity checking
  echo '<details style="margin-top:10px;"><summary>Show all Meadow meta (_meadow_*)</summary>';
  echo '<pre style="white-space:pre-wrap;max-width:980px;">';
  $all = $order->get_meta_data();
  foreach ($all as $m) {
    $key = method_exists($m, 'get_data') ? ($m->get_data()['key'] ?? '') : '';
    if (!is_string($key) || strpos($key, '_meadow_') !== 0) continue;
    $val = method_exists($m, 'get_data') ? ($m->get_data()['value'] ?? '') : '';
    if (is_array($val) || is_object($val)) $val = wp_json_encode($val);
    echo esc_html($key . ' = ' . (string)$val) . "\n";
  }
  echo '</pre></details>';

  echo '</div>';
}, 20);

/**
 * meadow_payment: Meta box showing key fields + link to Woo order.
 */
add_action('add_meta_boxes', function() {
  add_meta_box(
    'meadow_payment_debug',
    'Meadow Payment Debug',
    function($post) {
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

        // Link order
        if ($k === '_meadow_order_id' && ctype_digit($v) && (int)$v > 0) {
          $order_id = (int)$v;
          $url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
          echo '<tr><td><code>' . esc_html($k) . '</code></td><td><a href="' . esc_url($url) . '"><code>#' . esc_html($order_id) . '</code></a></td></tr>';
          continue;
        }

        echo '<tr><td><code>' . esc_html($k) . '</code></td><td><code>' . esc_html($v !== '' ? $v : '—') . '</code></td></tr>';
      }

      echo '</tbody></table>';

      // All meta (for when keys change)
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
    },
    'meadow_payment',
    'normal',
    'high'
  );
});

/**
 * meadow_payment list columns: show linked order + key statuses.
 */
add_filter('manage_meadow_payment_posts_columns', function($cols){
  $cols['meadow_order']   = 'Order';
  $cols['meadow_ref']     = 'Reference';
  $cols['meadow_pay']     = 'Payment';
  $cols['meadow_vend']    = 'Vend';
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
    echo esc_html((string) get_post_meta($post_id, '_meadow_payment_status', true));
    return;
  }
  if ($col === 'meadow_vend') {
    echo esc_html((string) get_post_meta($post_id, '_meadow_vend_status', true));
    return;
  }
}, 10, 2);
