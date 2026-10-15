<?php

/**
 * Deterministic WordPress option/database fixture for pure payment-safety tests.
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!class_exists('SUPCheckout_Test_WPDB')) {
    final class SUPCheckout_Test_WPDB {
        public $options = 'wp_options';
        public $before_query = null;

        public function prepare($query) {
            $args = func_get_args();
            array_shift($args);

            return serialize(array(
                'query' => (string) $query,
                'args'  => $args,
            ));
        }

        public function query($prepared) {
            $statement = @unserialize($prepared);
            if (!is_array($statement)
                || !isset($statement['query'], $statement['args'])
                || !is_string($statement['query'])
                || !is_array($statement['args'])
            ) {
                return false;
            }

            if (is_callable($this->before_query)) {
                call_user_func($this->before_query, $statement, $this);
            }

            $args = $statement['args'];
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0 && count($args) === 3) {
                list($name, $value) = $args;
                if (array_key_exists($name, $GLOBALS['supcheckout_test_options'])) {
                    return 0;
                }

                $GLOBALS['supcheckout_test_options'][$name] = $value;
                return 1;
            }

            if (strpos($statement['query'], 'UPDATE ') === 0 && count($args) === 3) {
                list($replacement, $name, $expected) = $args;
                if (!array_key_exists($name, $GLOBALS['supcheckout_test_options'])
                    || $GLOBALS['supcheckout_test_options'][$name] !== $expected
                ) {
                    return 0;
                }

                $GLOBALS['supcheckout_test_options'][$name] = $replacement;
                return 1;
            }

            if (strpos($statement['query'], 'DELETE ') === 0 && count($args) === 2) {
                list($name, $expected) = $args;
                if (!array_key_exists($name, $GLOBALS['supcheckout_test_options'])
                    || $GLOBALS['supcheckout_test_options'][$name] !== $expected
                ) {
                    return 0;
                }

                unset($GLOBALS['supcheckout_test_options'][$name]);
                return 1;
            }

            return false;
        }
    }
}

if (!function_exists('supcheckout_test_reset_wp_options')) {
    function supcheckout_test_reset_wp_options() {
        $GLOBALS['supcheckout_test_options'] = array();
        $GLOBALS['supcheckout_test_option_calls'] = array();
        $GLOBALS['supcheckout_test_cache_deletes'] = array();
        $GLOBALS['supcheckout_test_wp_salt'] = 'supcheckout-test-auth-salt';
        $GLOBALS['supcheckout_test_update_option_result'] = true;
        $GLOBALS['supcheckout_test_get_option_filter'] = null;
        $GLOBALS['wpdb'] = new SUPCheckout_Test_WPDB();
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return isset($GLOBALS['supcheckout_test_wp_salt'])
            ? $GLOBALS['supcheckout_test_wp_salt']
            : '';
    }
}

if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
        $GLOBALS['supcheckout_test_option_calls'][] = array('add', $name, $value, $autoload);
        if (array_key_exists($name, $GLOBALS['supcheckout_test_options'])) {
            return false;
        }

        $GLOBALS['supcheckout_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        $value = array_key_exists($name, $GLOBALS['supcheckout_test_options'])
            ? $GLOBALS['supcheckout_test_options'][$name]
            : $default;
        if (is_callable($GLOBALS['supcheckout_test_get_option_filter'])) {
            return call_user_func($GLOBALS['supcheckout_test_get_option_filter'], $name, $value, $default);
        }
        return $value;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['supcheckout_test_option_calls'][] = array('update', $name, $value, $autoload);
        if ($GLOBALS['supcheckout_test_update_option_result'] !== true) {
            return false;
        }
        $GLOBALS['supcheckout_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name) {
        $GLOBALS['supcheckout_test_option_calls'][] = array('delete', $name);
        if (!array_key_exists($name, $GLOBALS['supcheckout_test_options'])) {
            return false;
        }

        unset($GLOBALS['supcheckout_test_options'][$name]);
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        $GLOBALS['supcheckout_test_cache_deletes'][] = array($key, $group);
        return true;
    }
}

supcheckout_test_reset_wp_options();
