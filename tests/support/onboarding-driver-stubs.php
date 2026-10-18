<?php
/**
 * Request-context and time-limit stubs for the 0c onboarding driver unit tests
 * (AsyncDriverTest). Load with require_once alongside action-scheduler-stubs.php.
 *
 * $GLOBALS['supcheckout_test_request_context'] holds 'cron' and 'ajax' booleans;
 * $GLOBALS['supcheckout_test_time_limits'] records every wc_set_time_limit() argument.
 */

$GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
$GLOBALS['supcheckout_test_time_limits'] = array();

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        return !empty($GLOBALS['supcheckout_test_request_context']['cron']);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['supcheckout_test_request_context']['ajax']);
    }
}

if (!function_exists('wc_set_time_limit')) {
    function wc_set_time_limit($limit = 0) {
        $GLOBALS['supcheckout_test_time_limits'][] = (int) $limit;
    }
}
