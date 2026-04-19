<?php

// Source: D-01 + WP woocommerce_checkout_create_order action hook standard pattern
// Deploy as mu-plugin alongside the JS snippet
// File: wp-content/mu-plugins/ms-utm-persist.php
//
// Contract (MUST match ms-utm-capture.js + the Laravel-side UtmExtractor):
//   - JS snippet injects hidden form inputs with name="ms_utm_{key}"
//     (utm_source / utm_medium / utm_campaign / utm_term / utm_content / _ga)
//   - This hook reads those POST values on checkout and writes them as
//     Woo order meta keys: _ms_utm_source, _ms_utm_medium, ..., _ms_utm__ga
//     The Laravel-side UtmExtractor (Phase 4 Plan 03) reads meta_data[]
//     with EXACTLY these key names from the order.created webhook payload.

add_action('woocommerce_checkout_create_order', function ($order, $data) {
    $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_ga'];
    foreach ($keys as $k) {
        $field = 'ms_utm_' . $k;
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $order->update_meta_data('_ms_' . $field, sanitize_text_field($_POST[$field]));
        }
    }
}, 10, 2);
