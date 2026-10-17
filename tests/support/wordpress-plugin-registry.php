<?php

/**
 * Deterministic plugin-registry fixture (plugin_basename, multisite and network
 * options) for the pure basename-migration tests.
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!function_exists('supcheckout_test_reset_plugin_registry')) {
    function supcheckout_test_reset_plugin_registry($plugin_dir = '') {
        $GLOBALS['supcheckout_test_plugin_dir'] = (string) $plugin_dir;
        $GLOBALS['supcheckout_test_multisite'] = false;
        $GLOBALS['supcheckout_test_site_options'] = array();
        $GLOBALS['supcheckout_test_site_option_calls'] = array();
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        $file = str_replace('\\', '/', (string) $file);
        $dir = str_replace('\\', '/', rtrim((string) $GLOBALS['supcheckout_test_plugin_dir'], '/\\')) . '/';
        if ($dir !== '/' && strpos($file, $dir) === 0) {
            return substr($file, strlen($dir));
        }
        return basename($file);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite() {
        return !empty($GLOBALS['supcheckout_test_multisite']);
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['supcheckout_test_site_options'])
            ? $GLOBALS['supcheckout_test_site_options'][$name]
            : $default;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option($name, $value) {
        $GLOBALS['supcheckout_test_site_option_calls'][] = array('update', $name, $value);
        $GLOBALS['supcheckout_test_site_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('get_file_data')) {
    /**
     * WordPress get_file_data(): header values from the first 8 KB of a file.
     */
    function get_file_data($file, $default_headers, $context = '') {
        $data = (string) file_get_contents($file, false, null, 0, 8192);
        $values = array();
        foreach ($default_headers as $field => $regex) {
            $values[$field] = preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $data, $match) === 1
                ? trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]))
                : '';
        }
        return $values;
    }
}

supcheckout_test_reset_plugin_registry();
