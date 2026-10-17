<?php

/**
 * Deterministic WordPress transient/advisory-lock fixture for provider
 * payment-method availability tests. Development-only; excluded from builds.
 */

if (!class_exists('SUPCheckout_Test_Availability_WPDB')) {
    final class SUPCheckout_Test_Availability_WPDB {
        public $prefix = 'wp_7_';

        public function prepare($query) {
            $args = func_get_args();
            array_shift($args);

            return serialize(array(
                'query' => (string) $query,
                'args'  => $args,
            ));
        }

        public function get_var($prepared) {
            $statement = @unserialize($prepared);
            if (!is_array($statement)
                || !isset($statement['query'], $statement['args'])
                || !is_string($statement['query'])
                || !is_array($statement['args'])
                || count($statement['args']) !== 1
            ) {
                return null;
            }

            $lock_name = (string) $statement['args'][0];
            if (strpos($statement['query'], 'SELECT GET_LOCK(') === 0) {
                ++$GLOBALS['supcheckout_test_availability']['lock_acquires'];
                $callback = $GLOBALS['supcheckout_test_availability']['populate_on_lock'];
                if (is_callable($callback)) {
                    call_user_func($callback);
                    $GLOBALS['supcheckout_test_availability']['populate_on_lock'] = null;
                }
                $result = $GLOBALS['supcheckout_test_availability']['lock_result'];
                if ($result === '1' || $result === 1) {
                    $GLOBALS['supcheckout_test_availability']['locks'][$lock_name] = true;
                }
                return $result;
            }

            if (strpos($statement['query'], 'SELECT RELEASE_LOCK(') === 0) {
                ++$GLOBALS['supcheckout_test_availability']['lock_releases'];
                unset($GLOBALS['supcheckout_test_availability']['locks'][$lock_name]);
                return '1';
            }

            return null;
        }
    }
}

if (!function_exists('supcheckout_test_reset_availability')) {
    function supcheckout_test_reset_availability() {
        supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_availability'] = array(
            'transients'      => array(),
            'transient_ttls'  => array(),
            'lock_result'     => '1',
            'locks'           => array(),
            'lock_acquires'   => 0,
            'lock_releases'   => 0,
            'populate_on_lock' => null,
        );
        $GLOBALS['wpdb'] = new SUPCheckout_Test_Availability_WPDB();
    }
}

if (!function_exists('get_transient')) {
    function get_transient($name) {
        return array_key_exists($name, $GLOBALS['supcheckout_test_availability']['transients'])
            ? $GLOBALS['supcheckout_test_availability']['transients'][$name]
            : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($name, $value, $expiration = 0) {
        $GLOBALS['supcheckout_test_availability']['transients'][$name] = $value;
        $GLOBALS['supcheckout_test_availability']['transient_ttls'][$name] = $expiration;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($name) {
        $existed = array_key_exists($name, $GLOBALS['supcheckout_test_availability']['transients']);
        unset($GLOBALS['supcheckout_test_availability']['transients'][$name], $GLOBALS['supcheckout_test_availability']['transient_ttls'][$name]);
        return $existed;
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id() {
        return 7;
    }
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'supcheckout_test_database');
}

supcheckout_test_reset_availability();
