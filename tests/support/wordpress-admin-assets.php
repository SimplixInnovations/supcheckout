<?php

/*
 * Recording stubs for Simplixi\SUPCheckout\Admin\AdminAssets.
 *
 * wp_enqueue_script / wp_enqueue_style, add_action / add_filter, __ and
 * esc_attr come from the shared bootstrap stubs (wordpress-gateway-settings.php
 * and wordpress-migration-bootstrap.php); this file records the registration,
 * RTL, translation and inline-script calls those stubs do not cover.
 */

$GLOBALS['supcheckout_test_admin_assets'] = array();

function supcheckout_test_reset_admin_assets() {
    $GLOBALS['supcheckout_test_admin_assets'] = array(
        'scripts'             => array(),
        'styles'              => array(),
        'style_data'          => array(),
        'script_translations' => array(),
        'textdomain_calls'    => array(),
        'textdomain_json'     => array(),
        'inline_scripts'      => array(),
    );
    supcheckout_test_reset_gateway_settings();
    supcheckout_test_reset_subscription_composition();
    unset($GLOBALS['hook_suffix']);
}

/**
 * @return list<string> Every enqueued script and style handle, scripts first.
 */
function supcheckout_test_enqueued() {
    $handles = array();
    foreach ($GLOBALS['supcheckout_test_gateway_settings']['scripts'] as $script) {
        $handles[] = 'script:' . $script['handle'];
    }
    foreach ($GLOBALS['supcheckout_test_gateway_settings']['styles'] as $style) {
        $handles[] = 'style:' . $style['handle'];
    }
    return $handles;
}

/**
 * @param string $handle Script handle.
 * @return array{src: string, deps: array, ver: mixed, args: mixed}|null
 */
function supcheckout_test_script($handle) {
    return $GLOBALS['supcheckout_test_admin_assets']['scripts'][$handle] ?? null;
}

/**
 * @param string $handle Style handle.
 * @return array{src: string, deps: array, ver: mixed, media: string}|null
 */
function supcheckout_test_style($handle) {
    return $GLOBALS['supcheckout_test_admin_assets']['styles'][$handle] ?? null;
}

/**
 * @param string $handle Style handle.
 * @return array{0: string, 1: mixed}|null The first recorded key and value.
 */
function supcheckout_test_style_data($handle) {
    return $GLOBALS['supcheckout_test_admin_assets']['style_data'][$handle][0] ?? null;
}

/**
 * @return list<array{handle: string, data: string, position: string}>
 */
function supcheckout_test_inline_scripts() {
    return $GLOBALS['supcheckout_test_admin_assets']['inline_scripts'];
}

if (!function_exists('wp_register_script')) {
    function wp_register_script($handle, $src, $deps = array(), $ver = false, $args = array()) {
        $GLOBALS['supcheckout_test_admin_assets']['scripts'][$handle] = array(
            'src'  => $src,
            'deps' => $deps,
            'ver'  => $ver,
            'args' => $args,
        );
        return true;
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src, $deps = array(), $ver = false, $media = 'all') {
        $GLOBALS['supcheckout_test_admin_assets']['styles'][$handle] = array(
            'src'   => $src,
            'deps'  => $deps,
            'ver'   => $ver,
            'media' => $media,
        );
        return true;
    }
}

if (!function_exists('wp_style_add_data')) {
    function wp_style_add_data($handle, $key, $value) {
        $GLOBALS['supcheckout_test_admin_assets']['style_data'][$handle][] = array($key, $value);
        return true;
    }
}

if (!function_exists('wp_set_script_translations')) {
    function wp_set_script_translations($handle, $domain = 'default', $path = '') {
        $GLOBALS['supcheckout_test_admin_assets']['script_translations'][] = array($handle, $domain, $path);
        return true;
    }
}

if (!function_exists('load_script_textdomain')) {
    /** Returns the JSON a test seeded for the handle, or false like WordPress. */
    function load_script_textdomain($handle, $domain = 'default', $path = '') {
        $GLOBALS['supcheckout_test_admin_assets']['textdomain_calls'][] = array($handle, $domain, $path);
        return $GLOBALS['supcheckout_test_admin_assets']['textdomain_json'][$handle] ?? false;
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $data, $position = 'after') {
        $GLOBALS['supcheckout_test_admin_assets']['inline_scripts'][] = array(
            'handle'   => $handle,
            'data'     => $data,
            'position' => $position,
        );
        return true;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') {
        unset($plugin);
        return 'https://shop.example.test/wp-content/plugins/supcheckout/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_json_file_decode')) {
    /** Mirrors WordPress: null for a missing file or invalid JSON. */
    function wp_json_file_decode($filename, $options = array()) {
        if (!is_string($filename) || !is_file($filename)) {
            return null;
        }
        $associative = isset($options['associative']) && true === $options['associative'];
        $decoded = json_decode((string) file_get_contents($filename), $associative);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return null;
        }
        return $decoded;
    }
}

supcheckout_test_reset_admin_assets();
