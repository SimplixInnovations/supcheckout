<?php

/**
 * Development-only WordPress symbols the takeover copy runner uses (0b Task 6).
 */

/**
 * @param int    $user_id
 * @param string $meta_key
 * @param mixed  $meta_value
 * @param bool   $unique
 * @return int|false
 */
function add_user_meta($user_id, $meta_key, $meta_value, $unique = false) {}

/**
 * @param string|string[] $queries
 * @param bool            $execute
 * @return array<string, string>
 */
function dbDelta($queries = '', $execute = true) {}

/**
 * @param string                 $hook
 * @param array<mixed>|null      $args
 * @param string                 $group
 * @return bool
 */
function as_has_scheduled_action($hook, $args = null, $group = '') {}

/**
 * @param int          $timestamp
 * @param string       $hook
 * @param array<mixed> $args
 * @param string       $group
 * @param bool         $unique
 * @return int
 */
function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false) {}

/**
 * Takeover cutover (0b Task 7).
 *
 * @param int          $timestamp
 * @param string       $recurrence
 * @param string       $hook
 * @param array<mixed> $args
 * @param bool         $wp_error
 * @return bool|\WP_Error
 */
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {}

/**
 * @param string|string[] $plugins
 * @param bool            $silent
 * @param bool|null       $network_wide
 * @return void
 */
function deactivate_plugins($plugins, $silent = false, $network_wide = null) {}

/**
 * 0b Task 10 rollback: user meta removal and the official plugin's reactivation.
 *
 * @param int    $user_id
 * @param string $meta_key
 * @param mixed  $meta_value
 * @return bool
 */
function delete_user_meta($user_id, $meta_key, $meta_value = '') {}

/**
 * @param string $file
 * @return string
 */
function plugin_basename($file) {}

/**
 * @param string $plugin_folder
 * @return array<string, array<string, string>>
 */
function get_plugins($plugin_folder = '') {}

/**
 * @param string $plugin
 * @param string $redirect
 * @param bool   $network_wide
 * @param bool   $silent
 * @return \WP_Error|null
 */
function activate_plugin($plugin, $redirect = '', $network_wide = false, $silent = false) {}

/**
 * @param string                $file
 * @param array<string, string> $default_headers
 * @param string                $context
 * @return mixed Header values (an array in WordPress).
 */
function get_file_data($file, $default_headers, $context = '') {}

/** wpdb result shape (0b Task 9 stale-claims store). */
define('ARRAY_A', 'ARRAY_A');

/**
 * @param string $transient
 * @return bool
 */
function delete_transient($transient) {}
