<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Helper: detect kiosk from current page slug
 */
function meadow_get_current_kiosk_from_page(){
    if (!is_page()) return [null,0];

    global $post;
    if (!$post) return [null,0];

    $slug = $post->post_name;

    $q = new WP_Query([
        'post_type'      => 'kiosk',
        'meta_key'       => '_meadow_kiosk_page_slug',
        'meta_value'     => $slug,
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'no_found_rows'  => true,
    ]);

    if (!$q->have_posts()) return [null,0];

    $kiosk    = $q->posts[0];
    $kiosk_id = (int) get_post_meta($kiosk->ID,'_meadow_kiosk_id',true);

    return [$kiosk, $kiosk_id];
}

/**
 * Build Kiosk URL (new flow)
 */
function meadow_kiosk_build_kiosk_url( $motor, $kiosk_id_attr = 0, $extra_args = [] ) {

    $motor = (int) $motor;
    if ( ! $motor ) return '';

    $kiosk    = null;
    $kiosk_id = 0;

    if ( (int) $kiosk_id_attr ) {
        $q = new WP_Query( [
            'post_type'      => 'kiosk',
            'meta_key'       => '_meadow_kiosk_id',
            'meta_value'     => (int) $kiosk_id_attr,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'no_found_rows'  => true,
        ] );
        if ( $q->have_posts() ) {
            $kiosk    = $q->posts[0];
            $kiosk_id = (int) get_post_meta( $kiosk->ID, '_meadow_kiosk_id', true );
        }
        wp_reset_postdata();
    } else {
        list( $kiosk, $kiosk_id ) = meadow_get_current_kiosk_from_page();
    }

    if ( ! $kiosk || ! $kiosk_id ) return '';

    $domain    = (string) get_post_meta($kiosk->ID, '_meadow_domain', true);
    $page_slug = (string) get_post_meta($kiosk->ID, '_meadow_kiosk_page_slug', true);

    if ( ! $domain || ! $page_slug ) return '';

    $kiosk_url = rtrim($domain, '/') . '/' . ltrim($page_slug, '/');

    $args = array_merge([
        'kiosk_id' => $kiosk_id,
        'motor'    => $motor,
        'action'   => 'buy',
    ], is_array($extra_args) ? $extra_args : []);

    return add_query_arg( $args, $kiosk_url );
}

function meadow_kiosk_get_product_for_motor( $kiosk_post_id, $motor ) {

    $motor = (int) $motor;
    if ( ! $kiosk_post_id || ! $motor ) return 0;

    $slots = get_post_meta( $kiosk_post_id, Meadow_Kiosk_Core::SLOT_REPEATER_META_KEY, true );
    if ( ! is_array( $slots ) ) return 0;

    foreach ( $slots as $row ) {
        $row_motor = (int) ( $row[ Meadow_Kiosk_Core::SLOT_FIELD_MOTOR ] ?? 0 );
        if ( $row_motor !== $motor ) continue;

        $raw = $row[ Meadow_Kiosk_Core::SLOT_FIELD_ENABLED ] ?? null;
        $enabled = true;
        if ($raw !== '' && $raw !== null) {
            $s = strtolower(trim((string)$raw));
            $enabled = in_array($s, ['1','true','yes','y','on'], true);
        }
        if ( ! $enabled ) return 0;

        return (int) ( $row[ Meadow_Kiosk_Core::SLOT_FIELD_PRODUCT ] ?? 0 );
    }

    return 0;
}

function meadow_kiosk_link_shortcode( $atts ) {

    $atts = shortcode_atts( [
        'motor'    => 0,
        'label'    => '',
        'kiosk_id' => 0,
        'class'    => 'meadow-kiosk-link',
    ], $atts, 'meadow_kiosk_link' );

    $motor = (int) $atts['motor'];
    if ( ! $motor ) return '';

    $kiosk    = null;
    $kiosk_id = 0;

    if ( (int) $atts['kiosk_id'] ) {
        $q = new WP_Query( [
            'post_type'      => 'kiosk',
            'meta_key'       => '_meadow_kiosk_id',
            'meta_value'     => (int) $atts['kiosk_id'],
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'no_found_rows'  => true,
        ] );
        if ( $q->have_posts() ) {
            $kiosk    = $q->posts[0];
            $kiosk_id = (int) get_post_meta( $kiosk->ID, '_meadow_kiosk_id', true );
        }
        wp_reset_postdata();
    } else {
        list( $kiosk, $kiosk_id ) = meadow_get_current_kiosk_from_page();
    }

    if ( ! $kiosk || ! $kiosk_id ) return '';

    $product_id = meadow_kiosk_get_product_for_motor( $kiosk->ID, $motor );
    $url        = meadow_kiosk_build_kiosk_url( $motor, $kiosk_id );

    if ( ! $url ) return '';

    $label = trim((string) $atts['label']);
    if ( $label === '' ) {
        $label = $product_id ? get_the_title($product_id) : ('Motor ' . $motor);
    }

    return sprintf(
        '<a href="%s" class="%s" data-kiosk-id="%d" data-motor="%d" data-product-id="%d">%s</a>',
        esc_url( $url ),
        esc_attr( $atts['class'] ),
        (int) $kiosk_id,
        (int) $motor,
        (int) $product_id,
        esc_html( $label )
    );
}
add_shortcode( 'meadow_kiosk_link', 'meadow_kiosk_link_shortcode' );

function meadow_kiosk_link_url_shortcode( $atts ) {

    $atts = shortcode_atts( [
        'motor'    => 0,
        'kiosk_id' => 0,
    ], $atts, 'meadow_kiosk_link_url' );

    $motor = (int) $atts['motor'];
    if ( ! $motor ) return '';

    $url = meadow_kiosk_build_kiosk_url( $motor, (int) $atts['kiosk_id'] );
    if ( ! $url ) return '';

    return esc_url( $url );
}
add_shortcode( 'meadow_kiosk_link_url', 'meadow_kiosk_link_url_shortcode' );
