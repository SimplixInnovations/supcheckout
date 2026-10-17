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
