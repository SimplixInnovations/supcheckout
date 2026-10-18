<?php

/**
 * Minimal hook API and object-cache flush for the CachePurger unit tests. Loaded only
 * inside separate processes (a global has_action()/do_action() would change how other
 * suites see WordPress). add_action() is the bootstrap recorder; these read what it
 * recorded.
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!function_exists('has_action')) {
    function has_action($hook_name, $callback = false) {
        foreach ((array) ($GLOBALS['supcheckout_test_hook_calls'] ?? array()) as $call) {
            if ($call[0] === 'action' && $call[1] === $hook_name) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        foreach ((array) ($GLOBALS['supcheckout_test_hook_calls'] ?? array()) as $call) {
            if ($call[0] === 'action' && $call[1] === $hook_name) {
                call_user_func_array($call[2], array_slice($args, 0, (int) $call[4]));
            }
        }
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {
        $GLOBALS['supcheckout_test_object_flushes'] = (int) ($GLOBALS['supcheckout_test_object_flushes'] ?? 0) + 1;
        return true;
    }
}
