<?php

/**
 * WooCommerce's request-context price precision, as WC_Abstract_Order::set_total()
 * applies it on every order load: wc_format_decimal($value, wc_get_price_decimals()).
 *
 * wc_format_decimal() with a fixed $dp is number_format(floatval($value), $dp, '.', '')
 * in WooCommerce 11.2.1 (includes/wc-formatting-functions.php). Tests set the request
 * context's decimals through $GLOBALS['supcheckout_test_price_decimals'] (default 2,
 * WooCommerce's own default).
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!function_exists('wc_get_price_decimals')) {
    function wc_get_price_decimals() {
        return isset($GLOBALS['supcheckout_test_price_decimals'])
            ? (int) $GLOBALS['supcheckout_test_price_decimals']
            : 2;
    }
}

if (!function_exists('wc_format_decimal')) {
    function wc_format_decimal($number, $dp = false, $trim_zeros = false) {
        $number = (string) $number;
        if ($dp !== false) {
            $number = number_format((float) $number, (int) $dp, '.', '');
        }
        if ($trim_zeros && strpos($number, '.') !== false) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        return $number;
    }
}
