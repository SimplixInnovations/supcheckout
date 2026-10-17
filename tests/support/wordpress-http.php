<?php

/**
 * Deterministic WordPress HTTP fixture for authenticated status-verifier tests.
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!class_exists('SUPCheckout_Test_WP_Error')) {
    final class SUPCheckout_Test_WP_Error {}
}

if (!function_exists('supcheckout_test_reset_wp_http')) {
    function supcheckout_test_reset_wp_http() {
        $GLOBALS['supcheckout_test_http_calls'] = array();
        $GLOBALS['supcheckout_test_http_mutator'] = null;
        $GLOBALS['supcheckout_test_http_response'] = array(
            'response' => array('code' => 201),
            'body'     => '',
        );
    }
}


if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        $GLOBALS['supcheckout_test_http_calls'][] = array(
            'url'  => $url,
            'args' => $args,
        );

        if (is_callable($GLOBALS['supcheckout_test_http_mutator'])) {
            call_user_func($GLOBALS['supcheckout_test_http_mutator']);
            $GLOBALS['supcheckout_test_http_mutator'] = null;
        }

        return $GLOBALS['supcheckout_test_http_response'];
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        $GLOBALS['supcheckout_test_http_calls'][] = array(
            'url'  => $url,
            'args' => $args,
        );

        return $GLOBALS['supcheckout_test_http_response'];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($value) {
        return $value instanceof SUPCheckout_Test_WP_Error;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return is_array($response)
            && isset($response['response'])
            && is_array($response['response'])
            && isset($response['response']['code'])
                ? (int) $response['response']['code']
                : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return is_array($response) && isset($response['body'])
            ? $response['body']
            : '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        return is_array($response) && isset($response['headers']) && is_array($response['headers'])
            ? $response['headers']
            : array();
    }
}

supcheckout_test_reset_wp_http();
