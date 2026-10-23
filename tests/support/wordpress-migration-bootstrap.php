<?php

$GLOBALS['supcheckout_test_admin_context'] = false;
$GLOBALS['supcheckout_test_action_calls'] = array();
$GLOBALS['supcheckout_test_filter_calls'] = array();
$GLOBALS['supcheckout_test_hook_calls'] = array();
$GLOBALS['supcheckout_test_file_hook_calls'] = array();

function supcheckout_test_reset_migration_bootstrap() {
    $GLOBALS['supcheckout_test_admin_context'] = false;
    $GLOBALS['supcheckout_test_action_calls'] = array();
    $GLOBALS['supcheckout_test_filter_calls'] = array();
    $GLOBALS['supcheckout_test_hook_calls'] = array();
    $GLOBALS['supcheckout_test_file_hook_calls'] = array();
    WP_CLI::$commands = array();
    WP_CLI::$lines = array();
    WP_CLI::$errors = array();
}

function supcheckout_test_reset_subscription_composition() {
    $GLOBALS['supcheckout_test_action_calls'] = array();
    $GLOBALS['supcheckout_test_filter_calls'] = array();
    $GLOBALS['supcheckout_test_hook_calls'] = array();
}

function is_admin() {
    return $GLOBALS['supcheckout_test_admin_context'] === true;
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['supcheckout_test_action_calls'][] = array($hook_name, $callback, $priority, $accepted_args);
    $GLOBALS['supcheckout_test_hook_calls'][] = array('action', $hook_name, $callback, $priority, $accepted_args);
    return true;
}

// register_*_hook() calls are kept apart from add_action()/add_filter(), so the exact
// hook lists the boot tests pin stay about runtime hooks.
function register_deactivation_hook($file, $callback) {
    $GLOBALS['supcheckout_test_file_hook_calls'][] = array('deactivation', $file, $callback);
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['supcheckout_test_filter_calls'][] = array($hook_name, $callback, $priority, $accepted_args);
    $GLOBALS['supcheckout_test_hook_calls'][] = array('filter', $hook_name, $callback, $priority, $accepted_args);
    return true;
}

final class WP_CLI {
    public static $commands = array();
    public static $lines = array();
    public static $errors = array();

    public static function add_command($name, $callable) {
        self::$commands[] = array($name, $callable);
        return true;
    }

    public static function line($message) {
        self::$lines[] = $message;
    }

    public static function error($message, $exit = true) {
        self::$errors[] = array($message, $exit);
        if ($exit) {
            throw new RuntimeException($message);
        }
    }

    private function __construct() {
    }
}
