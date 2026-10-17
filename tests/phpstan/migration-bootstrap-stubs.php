<?php

function is_admin() {
    return false;
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

class WP_CLI {
    public static function add_command($name, $callable) {
        return true;
    }

    public static function line($message) {
    }

    public static function error($message, $exit = true) {
    }

    /** @param string $question */
    public static function confirm($question, $assoc_args = array()) {
    }

    /** @param int $return_code */
    public static function halt($return_code) {
    }
}
