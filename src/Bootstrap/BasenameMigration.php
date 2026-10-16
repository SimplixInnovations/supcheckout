<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

/**
 * Moves an install that was activated before the main-file rename from
 * <dir>/UPayments.php to <dir>/supcheckout.php, so WordPress never drops the
 * stored entry and silently deactivates the gateway.
 *
 * Only the entry whose basename is the shim's own basename moves; another
 * directory's UPayments.php (for example the official upayments/UPayments.php)
 * is never touched. Options are written only when their value changes, so the
 * migration is idempotent and costs two option reads once it has run.
 */
final class BasenameMigration {
    private const MAIN_FILE = 'supcheckout.php';

    /**
     * @param string $shim_file Absolute path of the transitional UPayments.php shim.
     * @return bool True when an option was changed.
     */
    public static function run($shim_file) {
        if (!function_exists('plugin_basename') || !function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        $shim = str_replace('\\', '/', (string) plugin_basename((string) $shim_file));
        $dir = dirname($shim);
        if ($dir === '.' || $dir === '' || strpos($dir, '/') !== false) {
            return false;
        }
        if (!is_file(dirname((string) $shim_file) . '/' . self::MAIN_FILE)) {
            return false;
        }
        $main = $dir . '/' . self::MAIN_FILE;

        $changed = false;
        $active = get_option('active_plugins', array());
        if (is_array($active) && self::migrate_list($active, $shim, $main) !== null) {
            // Re-read uncached right before writing, so an activation another request
            // made since the cached read is not lost.
            $active = self::fresh_option('active_plugins', 'options', 'active_plugins');
            $migrated = is_array($active) ? self::migrate_list($active, $shim, $main) : null;
            if ($migrated !== null) {
                update_option('active_plugins', $migrated);
                $changed = true;
            }
        }

        if (function_exists('is_multisite') && is_multisite()
            && function_exists('get_site_option') && function_exists('update_site_option')
        ) {
            $sitewide = get_site_option('active_sitewide_plugins', array());
            if (is_array($sitewide) && self::migrate_map($sitewide, $shim, $main) !== null) {
                $network = function_exists('get_current_network_id') ? (int) get_current_network_id() : 1;
                $sitewide = self::fresh_option('active_sitewide_plugins', 'site-options', $network . ':active_sitewide_plugins');
                $migrated = is_array($sitewide) ? self::migrate_map($sitewide, $shim, $main) : null;
                if ($migrated !== null) {
                    update_site_option('active_sitewide_plugins', $migrated);
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Replace the shim entry with the main entry in place, keeping the main entry once.
     *
     * @param array<int|string, mixed> $active
     * @param string $shim
     * @param string $main
     * @return array<int, mixed>|null Null when nothing changes.
     */
    private static function migrate_list(array $active, $shim, $main) {
        if (!in_array($shim, $active, true)) {
            return null;
        }
        $migrated = array();
        $has_main = false;
        foreach ($active as $entry) {
            if ($entry === $shim) {
                $entry = $main;
            }
            if ($entry === $main) {
                if ($has_main) {
                    continue;
                }
                $has_main = true;
            }
            $migrated[] = $entry;
        }
        return $migrated;
    }

    /**
     * Rename the shim key to the main key, keeping its activation timestamp, in place.
     * When both keys exist, the main key and its own timestamp win.
     *
     * @param array<int|string, mixed> $sitewide
     * @param string $shim
     * @param string $main
     * @return array<int|string, mixed>|null Null when nothing changes.
     */
    private static function migrate_map(array $sitewide, $shim, $main) {
        if (!array_key_exists($shim, $sitewide)) {
            return null;
        }
        $has_main = array_key_exists($main, $sitewide);
        $migrated = array();
        foreach ($sitewide as $basename => $value) {
            if ($basename === $shim) {
                if (!$has_main) {
                    $migrated[$main] = $value;
                }
                continue;
            }
            $migrated[$basename] = $value;
        }
        return $migrated;
    }

    /**
     * Drop the object-cache copies of an option, then read it again.
     *
     * @param string $name Option name.
     * @param string $group Object-cache group of the option.
     * @param string $key Object-cache key of the option.
     * @return mixed
     */
    private static function fresh_option($name, $group, $key) {
        if (function_exists('wp_cache_delete')) {
            if ($group === 'options') {
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
            }
            wp_cache_delete($key, $group);
        }
        if ($group === 'options') {
            return get_option($name, array());
        }
        return function_exists('get_site_option') ? get_site_option($name, array()) : array();
    }

    private function __construct() {}
}
