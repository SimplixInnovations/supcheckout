<?php
/**
 * Action Scheduler function stubs for R4 unit tests.
 */

if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = array())
    {
        $orders = array_values($GLOBALS['supcheckout_test_status_orders'] ?? array());
        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        return array_slice($orders, 0, $limit > 0 ? $limit : count($orders));
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '')
    {
        $GLOBALS['supcheckout_as_calls']['scheduled'][] = array(
            'timestamp' => (int) $timestamp,
            'hook' => (string) $hook,
            'args' => (array) $args,
            'group' => (string) $group,
        );
        return 1000 + count($GLOBALS['supcheckout_as_calls']['scheduled']);
    }
}

if (!function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action($hook, $args = null, $group = '')
    {
        foreach ($GLOBALS['supcheckout_as_calls']['scheduled'] as $row) {
            if ($row['hook'] === $hook && $row['args'] === (array) $args && $row['group'] === $group) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('as_unschedule_action')) {
    function as_unschedule_action($hook, $args = null, $group = '')
    {
        $GLOBALS['supcheckout_as_calls']['unscheduled'][] = array($hook, $args, $group);
        $before = count($GLOBALS['supcheckout_as_calls']['scheduled']);
        $GLOBALS['supcheckout_as_calls']['scheduled'] = array_values(array_filter(
            $GLOBALS['supcheckout_as_calls']['scheduled'],
            static function ($row) use ($hook, $args, $group) {
                return !($row['hook'] === $hook && $row['args'] === (array) $args && $row['group'] === $group);
            }
        ));
        return $before - count($GLOBALS['supcheckout_as_calls']['scheduled']);
    }
}
