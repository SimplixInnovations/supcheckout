<?php
/**
 * Request-context and Action Scheduler stubs for ReconciliationSweepTest (A3-06).
 * The production guards use function_exists() on the global names, so these are
 * global. Load with require_once from a test that runs in a separate process, so no
 * other stub of the same name (tests/support/action-scheduler-stubs.php) is loaded.
 *
 * $GLOBALS['supcheckout_sweep_test'] holds the request context ('ajax', 'cron'),
 * the recorded Action Scheduler calls and what the stubs return.
 */

function supcheckout_sweep_test_reset() {
    $GLOBALS['supcheckout_sweep_test'] = array(
        'ajax' => false,
        'cron' => false,
        'has_calls' => 0,
        'has_result' => false,
        'schedule_calls' => array(),
        'schedule_result' => 1001,
        'unschedule_calls' => array(),
        // Action Scheduler initialises at init:1; WooCommerce fires woocommerce_init at init:0.
        'as_initialized' => true,
    );
}

if (!class_exists('ActionScheduler', false)) {
    // The real ActionScheduler::is_initialized( $function_name = null ).
    final class ActionScheduler {
        public static function is_initialized($function_name = null) {
            return !empty($GLOBALS['supcheckout_sweep_test']['as_initialized']);
        }
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['supcheckout_sweep_test']['ajax']);
    }
}

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        return !empty($GLOBALS['supcheckout_sweep_test']['cron']);
    }
}

if (!function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action($hook, $args = null, $group = '') {
        $GLOBALS['supcheckout_sweep_test']['has_calls']++;
        return (bool) $GLOBALS['supcheckout_sweep_test']['has_result'];
    }
}

if (!function_exists('as_schedule_recurring_action')) {
    function as_schedule_recurring_action($timestamp, $interval, $hook, $args = array(), $group = '') {
        $GLOBALS['supcheckout_sweep_test']['schedule_calls'][] = array((int) $timestamp, (int) $interval, (string) $hook, $args, (string) $group);
        return $GLOBALS['supcheckout_sweep_test']['schedule_result'];
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook, $args = null, $group = '') {
        $GLOBALS['supcheckout_sweep_test']['unschedule_calls'][] = array((string) $hook, $args, (string) $group);
        $GLOBALS['supcheckout_sweep_test']['has_result'] = false;
    }
}

supcheckout_sweep_test_reset();
