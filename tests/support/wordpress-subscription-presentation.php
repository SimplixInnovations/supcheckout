<?php

/**
 * Deterministic WordPress/WooCommerce presentation fixture.
 *
 * Runtime classes are loaded per test from the matching fixture so the guarded
 * WCProductCustomType tests remain isolated from WooCommerce.
 */

function supcheckout_test_reset_subscription_presentation() {
    $GLOBALS['supcheckout_test_subscription_presentation_active'] = true;
    $GLOBALS['supcheckout_test_subscription_presentation'] = array(
        'capability_allowed' => true,
        'capability_calls' => array(),
        'field_args' => array(),
        'meta' => array(),
        'meta_writes' => array(),
        'nonce_fields' => array(),
        'nonce_valid' => true,
        'notices' => array(),
        'post_type' => 'product',
        'product' => null,
        'wc' => (object) array('cart' => (object) array()),
    );
    $GLOBALS['supcheckout_test_status_logged_in'] = true;
    $GLOBALS['supcheckout_test_status_user_id'] = 7;
    $_GET = array();
    $_POST = array();
}

function absint($value) {
    return abs((int) $value);
}

function wp_verify_nonce($nonce, $action) {
    return $GLOBALS['supcheckout_test_subscription_presentation']['nonce_valid'] === true;
}

function update_post_meta($post_id, $key, $value) {
    $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes'][] = array($post_id, $key, $value);
    return true;
}

function get_post_meta($post_id, $key, $single = false) {
    return isset($GLOBALS['supcheckout_test_subscription_presentation']['meta'][$post_id][$key])
        ? $GLOBALS['supcheckout_test_subscription_presentation']['meta'][$post_id][$key]
        : '';
}

function get_post_type() {
    return $GLOBALS['supcheckout_test_subscription_presentation']['post_type'];
}

function woocommerce_wp_text_input($args) {
    $GLOBALS['supcheckout_test_subscription_presentation']['field_args'][] = $args;
}

function WC() {
    return $GLOBALS['supcheckout_test_subscription_presentation']['wc'];
}

function wc_get_product($product_id) {
    return $GLOBALS['supcheckout_test_subscription_presentation']['product'];
}

function wc_add_notice($message, $type) {
    $GLOBALS['supcheckout_test_subscription_presentation']['notices'][] = array($message, $type);
}

function wp_timezone() {
    return new DateTimeZone('UTC');
}

function wc_get_account_endpoint_url($endpoint) {
    return 'https://example.test/account/' . $endpoint . '/';
}

function add_query_arg($key, $value = false, $url = false) {
    if (is_array($key)) {
        $params = $key;
        $url = (string) $value;
    } else {
        $params = array((string) $key => (string) $value);
        $url = (string) $url;
    }

    $fragment = '';
    $hash_pos = strpos($url, '#');
    if ($hash_pos !== false) {
        $fragment = substr($url, $hash_pos);
        $url = substr($url, 0, $hash_pos);
    }

    $query = array();
    $query_pos = strpos($url, '?');
    if ($query_pos !== false) {
        parse_str(substr($url, $query_pos + 1), $query);
        $url = substr($url, 0, $query_pos);
    }

    foreach ($params as $param_key => $param_value) {
        if ($param_value === null || $param_value === false) {
            unset($query[$param_key]);
            continue;
        }
        $query[$param_key] = $param_value;
    }

    $encoded = array();
    foreach ($query as $param_key => $param_value) {
        $encoded[] = rawurlencode((string) $param_key) . '=' . rawurlencode((string) $param_value);
    }

    return $url . (empty($encoded) ? '' : '?' . implode('&', $encoded)) . $fragment;
}

function esc_url($value) {
    return esc_attr($value);
}

function esc_js($value) {
    return addslashes((string) $value);
}

$GLOBALS['supcheckout_test_subscription_presentation_active'] = false;
