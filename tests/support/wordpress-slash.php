<?php

/**
 * wp_slash() as WordPress defines it (wp-includes/formatting.php): strings get
 * addslashes(), arrays are slashed recursively, anything else is returned unchanged.
 *
 * This file is development-only and is excluded from plugin distribution.
 */

if (!function_exists('wp_slash')) {
    function wp_slash($value) {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}
