<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Gateway\OfficialPluginConflict;

defined('ABSPATH') || exit;

/**
 * CutoverSystem bound to WordPress (Sub-project 0b Task 7).
 *
 * The official plugin is found exactly as OfficialPluginConflict finds it (another
 * active plugin whose main file is UPayments.php), relative to SUPCheckout's own main
 * file, so it works from the paused boot too, where Plugin::boot() never ran.
 */
final class WordPressCutoverSystem implements RollbackSystem {
    private const OFFICIAL_MAIN_FILE = 'UPayments.php';

    public function cron_events() {
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : array();
        $events = array();
        foreach (is_array($cron) ? $cron : array() as $timestamp => $hooks) {
            if (!is_numeric($timestamp) || !is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $instances) {
                foreach (is_array($instances) ? $instances : array() as $instance) {
                    if (!is_array($instance)) {
                        continue;
                    }
                    $events[] = array(
                        'timestamp' => (int) $timestamp,
                        'hook' => (string) $hook,
                        'args' => isset($instance['args']) && is_array($instance['args']) ? $instance['args'] : array(),
                        'schedule' => isset($instance['schedule']) && is_string($instance['schedule']) ? $instance['schedule'] : false,
                    );
                }
            }
        }
        return $events;
    }

    public function schedule_event($timestamp, $hook, array $args, $schedule) {
        if ($schedule === false) {
            return wp_schedule_single_event((int) $timestamp, (string) $hook, $args, true) === true;
        }
        return wp_schedule_event((int) $timestamp, (string) $schedule, (string) $hook, $args, true) === true;
    }

    public function official_plugin() {
        return OfficialPluginConflict::conflicting_plugin(dirname(__DIR__, 2) . '/supcheckout.php');
    }

    public function deactivate_plugin($basename) {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Every active official install, not only $basename: a second */UPayments.php copy
        // would keep serving the shared identity. This site only: the takeover is per site,
        // and a network activation (which Preflight blocks) must never be turned off for the
        // whole network. Success requires that no official install remains active, so a
        // network activation or a copy that stayed active is never reported as deactivated.
        $plugins = OfficialPluginConflict::conflicting_plugins(dirname(__DIR__, 2) . '/supcheckout.php');
        if (!in_array((string) $basename, $plugins, true)) {
            array_unshift($plugins, (string) $basename);
        }
        deactivate_plugins($plugins, false, false);
        return $this->official_plugin() === null;
    }

    public function unschedule_event($timestamp, $hook, array $args) {
        wp_unschedule_event((int) $timestamp, (string) $hook, $args);
        return wp_next_scheduled((string) $hook, $args) !== (int) $timestamp;
    }

    public function installed_official_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Every entry inside SUPCheckout's own directory is ours (the transitional
        // supcheckout/UPayments.php shim has no header, but never count it).
        $own_dir = dirname(str_replace('\\', '/', (string) plugin_basename(dirname(__DIR__, 2) . '/supcheckout.php')));
        $found = array();
        foreach (array_keys((array) get_plugins()) as $basename) {
            $basename = (string) $basename;
            if (basename($basename) === self::OFFICIAL_MAIN_FILE && dirname($basename) !== '.' && dirname($basename) !== $own_dir) {
                $found[] = $basename;
            }
        }
        sort($found);
        return $found;
    }

    public function activate_plugin($basename) {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // This site only, exactly as the owner would from wp-admin: never network-wide.
        $result = activate_plugin((string) $basename, '', false, false);
        return !is_wp_error($result) && $this->official_plugin() === (string) $basename;
    }

    public function now() {
        return time();
    }

    public function sleep($seconds) {
        sleep(max(0, (int) $seconds));
    }

    public function reached($step) {
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::line('takeover cutover: ' . (string) $step);
        }
    }
}
