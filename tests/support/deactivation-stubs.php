<?php
/**
 * WP-Cron stub for DeactivationTest (A3-08). Load after action-scheduler-stubs.php from a
 * test that runs in a separate process.
 *
 * $GLOBALS['supcheckout_test_cron'] lists the scheduled events as
 * array{hook:string, args:array, timestamp:int}; wp_unschedule_hook() removes every event of
 * a hook whatever its arguments, like WordPress, and records the call.
 */

function supcheckout_deactivation_test_reset() {
    $GLOBALS['supcheckout_test_cron'] = array();
    $GLOBALS['supcheckout_test_cron_unscheduled'] = array();
    $GLOBALS['supcheckout_test_cron_throws'] = false;
    $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
    \ActionScheduler::$initialized = true;
}
// ActionScheduler (the real class ReconciliationSweep and ActionSchedulerBridge check)
// comes from action-scheduler-stubs.php, which this file is loaded after.

if (!function_exists('wp_unschedule_hook')) {
    function wp_unschedule_hook($hook, $wp_error = false) {
        if (!empty($GLOBALS['supcheckout_test_cron_throws'])) {
            throw new RuntimeException('cron store unavailable');
        }
        $GLOBALS['supcheckout_test_cron_unscheduled'][] = (string) $hook;
        $before = count($GLOBALS['supcheckout_test_cron']);
        $GLOBALS['supcheckout_test_cron'] = array_values(array_filter(
            $GLOBALS['supcheckout_test_cron'],
            static function ($event) use ($hook) {
                return $event['hook'] !== $hook;
            }
        ));
        return $before - count($GLOBALS['supcheckout_test_cron']);
    }
}
