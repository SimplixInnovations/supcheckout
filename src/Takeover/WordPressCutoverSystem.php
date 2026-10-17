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
final class WordPressCutoverSystem implements CutoverSystem {
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
        // This site only: the takeover is per site, and a network activation (which Preflight
        // blocks) must never be turned off for the whole network. The post-check still sees
        // a network activation, so that case is never reported as deactivated.
        deactivate_plugins((string) $basename, false, false);
        return $this->official_plugin() !== (string) $basename;
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
