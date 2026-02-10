<?php
/**
 * Plugin Name: Meadow × LaMetric (Today)
 * Description: LaMetric HTTP Poll endpoint for TODAY’s Meadow kiosk sales (Europe/London, HPOS-safe).
 * Version: 2.3.0
 */

if ( ! defined('ABSPATH') ) exit;

add_action('rest_api_init', function () {
    register_rest_route('meadow-lametric/v1', '/today', [
        'methods'  => 'GET',
        'callback' => 'meadow_lametric_today',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Determine the "sale time" for a Meadow kiosk order.
 * Priority:
 *  1) date_paid      (matches Woo admin “Paid on …”)
 *  2) date_completed
 *  3) date_modified  (final fallback)
 */
function meadow_lametric_sale_datetime( WC_Order $order ): ?DateTimeImmutable {

    $tz = wp_timezone(); // Europe/London

    $dt = null;

    if ( method_exists($order, 'get_date_paid') ) {
        $dt = $order->get_date_paid();
    }
    if ( ! $dt && method_exists($order, 'get_date_completed') ) {
        $dt = $order->get_date_completed();
    }
    if ( ! $dt && method_exists($order, 'get_date_modified') ) {
        $dt = $order->get_date_modified();
    }

    if ( ! $dt ) return null;

    return (new DateTimeImmutable('@' . $dt->getTimestamp()))
        ->setTimezone($tz);
}

/**
 * Debounce LaMetric notifications:
 * - Only allow a "change" when the COUNT increases (new order).
 * - Then lock for 60s so repeated polls don't re-notify.
 * Returns true if we should emit the NEW payload.
 */
function meadow_lametric_should_emit( int $count ): bool {
    $key  = 'meadow_lametric_last_count';
    $prev = get_transient($key);

    $prev_i = ($prev === false) ? null : (int)$prev;

    // First run or count changed
    if ($prev_i === null || $prev_i !== $count) {
        set_transient($key, (int)$count, 60);
        return true;
    }

    return false;
}

function meadow_lametric_today( WP_REST_Request $req ) {

    if ( ! function_exists('wc_get_orders') ) {
        return new WP_Error('meadow_lametric_no_woo', 'WooCommerce not available', ['status' => 500]);
    }

    nocache_headers();

    $force = (bool) $req->get_param('nocache');

    $tz = wp_timezone();
    $start = new DateTimeImmutable('today', $tz);
    $end   = $start->modify('+1 day');

    $cache_key = 'meadow_lametric_today_' . $start->format('Y-m-d');

    if ( $force ) {
        delete_transient($cache_key);
    } else {
        $cached = get_transient($cache_key);
        if ( $cached !== false ) return $cached;
    }

    /**
     * Pull recent completed orders only, then do exact filtering in PHP.
     * 400/day is fine. We scan a small window (created in last 2 days) to keep it lean.
     */
    $orders = wc_get_orders([
        'status'       => ['completed'],
        'limit'        => 2000,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'return'       => 'objects',
        // Just to reduce scan size — not the source of truth
        'date_created' => '>' . $start->modify('-2 days')->format('Y-m-d H:i:s'),
    ]);

    $total = 0.0;
    $count = 0;

    foreach ( $orders as $order ) {
        if ( ! $order ) continue;

        // Kiosk-only
        if ( (string) $order->get_meta('_meadow_order_type', true) !== 'kiosk' ) continue;

        $sale_dt = meadow_lametric_sale_datetime($order);
        if ( ! $sale_dt ) continue;

        if ( $sale_dt < $start || $sale_dt >= $end ) continue;

        $total += (float) $order->get_total();
        $count++;
    }

    $payload = [
        'frames' => [
            [
                'icon' => 'i39850',
                'text' => '£' . number_format($total, 2) . ' (' . $count . ')'
            ]
        ]
    ];

    /**
     * If LaMetric polls repeatedly, we don't want repeated notifications.
     * We only "emit" a changed payload once per new count (then lock for 60s).
     */
    $existing = get_transient($cache_key);

    if ( meadow_lametric_should_emit($count) ) {
        // Cache for 30s (suggest setting LaMetric poll to 30s too)
        set_transient($cache_key, $payload, 30);
        return $payload;
    }

    // No change -> keep returning the cached payload (prevents notify spam)
    if ( $existing !== false ) return $existing;

    // Safety fallback if no cached value yet
    set_transient($cache_key, $payload, 30);
    return $payload;
}
