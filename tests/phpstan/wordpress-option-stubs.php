<?php

/**
 * Development-only WordPress option/database symbols for bounded PHPStan scope.
 */

class wpdb {
    /** @var string */
    public $options;

    /** @var string */
    public $usermeta;

    /** @var string */
    public $prefix;

    /** @return string */
    public function prepare($query, ...$args) {}

    /** @return int|bool */
    public function query($query) {}

    /** @return string */
    public function esc_like($value) {}

    /** @return mixed */
    public function get_results($query) {}

    /**
     * @param string $query
     * @return mixed
     */
    public function get_var($query) {}
}

/** @var wpdb $wpdb */
$wpdb = new wpdb();

/** @return string */
function wp_salt($scheme = 'auth') {}

/** @return bool */
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {}

/** @return mixed */
function get_option($name, $default = false) {}

/** @return bool */
function update_option($name, $value, $autoload = null) {}

/** @return bool */
function delete_option($name) {}

/** @return bool */
function wp_cache_delete($key, $group = '') {}
/** @return mixed */
function wp_cache_get($key, $group = '') {}
/** @return bool */
function wp_cache_set($key, $data, $group = '', $expire = 0) {}
function wp_remote_get($url, $args = array()) {}
function is_wp_error($value) {}
function wp_remote_retrieve_response_code($response) {}
function wp_remote_retrieve_body($response) {}

/**
 * @param string $transient
 * @return mixed
 */
function get_transient($transient) {}

/**
 * @param string $transient
 * @param mixed $value
 * @param int $expiration
 * @return bool
 */
function set_transient($transient, $value, $expiration = 0) {}

/** @return string */
function __($text, $domain = 'default') {}

/** @return string */
function sanitize_text_field($value) {}

/** @return string */
function sanitize_key($value) {}

/** @return string */
function wc_clean($value) {}

/** @return string */
function esc_html($value) {}

/** @return string */
function esc_attr($value) {}

/** @return string */
function wp_kses_post($value) {}

/** @return string */
function sanitize_title($value) {}

/** @return void */
function esc_html_e($text, $domain = 'default') {}

/** @return string */
function selected($selected, $current = true, $display = true) {}

/** @return void */
function wp_enqueue_style($handle, $source, $dependencies = array(), $version = false, $media = 'all') {}

/** @return void */
function wp_enqueue_script($handle, $source = '', $dependencies = array(), $version = false, $in_footer = false) {}

/** @return bool */
function wp_add_inline_style($handle, $css) {}

/** @return mixed */
function wc_get_order($order_id) {}

/** @return bool */
function is_user_logged_in() {}

/** @return int */
function get_current_user_id() {}

/** @return mixed */
function wp_unslash($value) {}

/**
 * @param mixed $value
 * @return mixed
 */
function wp_slash($value) {}

/** @return void */
function wp_send_json($response, $status_code = null, $flags = 0) {}

/** @return void */
function status_header($code, $description = '') {}

/** @return string|false */
function wp_json_encode($data, $options = 0, $depth = 512) {}

/** @return int */
function get_current_blog_id() {}

/** @return mixed */
function get_user_meta($user_id, $key, $single = false) {}

/** @return int|bool */
function update_user_meta($user_id, $key, $value, $prev_value = '') {}

/** @return mixed */
function wc_get_orders($args = array()) {}

/** @return mixed */
function maybe_unserialize($value) {}
