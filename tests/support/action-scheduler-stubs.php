<?php
/**
 * Action Scheduler function stubs for R4 unit tests.
 * Mirrors bundled AS contracts: 5th unique flag, has_scheduled includes in-progress.
 */

if (!class_exists('Action_Scheduler', false)) {
    class Action_Scheduler
    {
        public static $initialized = true;

        public static function is_initialized(): bool
        {
            return self::$initialized;
        }
    }
}

if (!function_exists('did_action')) {
    function did_action($hook)
    {
        return empty($GLOBALS['supcheckout_as_calls']['did_action'][$hook])
            ? 0
            : (int) $GLOBALS['supcheckout_as_calls']['did_action'][$hook];
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false)
    {
        $args = (array) $args;
        if ($unique) {
            foreach ($GLOBALS['supcheckout_as_calls']['scheduled'] as $row) {
                if ($row['hook'] === $hook && $row['args'] === $args && $row['group'] === $group) {
                    return 0;
                }
            }
        }
        $GLOBALS['supcheckout_as_calls']['scheduled'][] = array(
            'timestamp' => (int) $timestamp,
            'hook' => (string) $hook,
            'args' => $args,
            'group' => (string) $group,
            'unique' => (bool) $unique,
        );
        return 1000 + count($GLOBALS['supcheckout_as_calls']['scheduled']);
    }
}

if (!function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action($hook, $args = null, $group = '')
    {
        foreach ($GLOBALS['supcheckout_as_calls']['scheduled'] as $row) {
            if ($row['hook'] !== $hook || $row['group'] !== $group) {
                continue;
            }
            if ($args === null || $row['args'] === (array) $args) {
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
        $GLOBALS['supcheckout_as_calls']['scheduled'] = array_values(array_filter(
            $GLOBALS['supcheckout_as_calls']['scheduled'],
            static function ($row) use ($hook, $args, $group) {
                if ($row['hook'] !== $hook || $row['group'] !== $group) {
                    return true;
                }
                return $args !== null && $row['args'] !== (array) $args;
            }
        ));
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook, $args = null, $group = '')
    {
        $GLOBALS['supcheckout_as_calls']['unscheduled_all'][] = array($hook, $args, $group);
        $GLOBALS['supcheckout_as_calls']['scheduled'] = array_values(array_filter(
            $GLOBALS['supcheckout_as_calls']['scheduled'],
            static function ($row) use ($hook, $args, $group) {
                if ($row['hook'] !== $hook || $row['group'] !== $group) {
                    return true;
                }
                // Exact args match only. Null args would be group-wide — forbidden.
                return $args !== null && $row['args'] !== (array) $args;
            }
        ));
    }
}

if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = array())
    {
        $orders = array_values($GLOBALS['supcheckout_test_status_orders'] ?? array());
        // Stable ID ASC matching set (already filtered by test fixtures).
        usort($orders, static function ($a, $b) {
            return (int) $a->get_id() <=> (int) $b->get_id();
        });
        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
        return array_slice($orders, $offset, $limit > 0 ? $limit : count($orders));
    }
}
