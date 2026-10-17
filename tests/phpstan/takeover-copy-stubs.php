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
