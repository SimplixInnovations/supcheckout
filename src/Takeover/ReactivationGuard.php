<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Gateway\OfficialPluginConflict;

defined('ABSPATH') || exit;

/**
 * Refuses to reactivate the official UPayments plugin on a store that has switched (A5-01).
 *
 * Once the store is cut_over or finalized, every Charge started after the switch calls back
 * on the `supcheckout` route. An active official plugin pauses SUPCheckout
 * (OfficialPluginConflict), and a paused SUPCheckout answers only the preflight probe there,
 * so those browser returns and webhooks would be dropped. The official plugin would also run
 * on the legacy settings from before the switch.
 *
 * The guard filters the option write itself (`active_plugins`, and the network
 * `active_sitewide_plugins`), so the Plugins screen, bulk activation, WP-CLI and direct
 * option writes are all covered. It removes only an official main file that the write adds
 * (one that would pause SUPCheckout, by the same test OfficialPluginConflict uses), keeps
 * every other entry, and queues one admin error. The supported way back is Rollback.
 *
 * It never blocks in any other state. Rollback and Abort reactivate the official plugin in
 * preflight_ok, after leaving cut_over, and an administrator may reactivate it by hand
 * after a rollback (rolled_back).
 *
 * Registered only by the full boot (Plugin::boot()); a paused SUPCheckout has nothing to
 * guard, because the official plugin is already active.
 */
final class ReactivationGuard {
    /** Basenames refused since an administrator last saw the notice. */
    public const NOTICE_TRANSIENT = 'supcheckout_reactivation_blocked';

    /** Seconds the queued notice waits for an administrator's next admin page. */
    private const NOTICE_TTL = 600;

    /** @var string|null Absolute path of the SUPCheckout main file. */
    private static $main_file = null;

    /**
     * @param string $main_file Absolute path of the SUPCheckout main file.
     * @return void
     */
    public static function register($main_file) {
        self::$main_file = (string) $main_file;
        add_filter('pre_update_option_active_plugins', array(self::class, 'filter_site_plugins'), 10, 2);
        add_filter('pre_update_site_option_active_sitewide_plugins', array(self::class, 'filter_network_plugins'), 10, 2);
        add_action('admin_notices', array(self::class, 'render_notice'));
        add_action('network_admin_notices', array(self::class, 'render_notice'));
    }

    /**
     * `pre_update_option_active_plugins`: a list of basenames.
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Stored value.
     * @return mixed The new value, without a refused official entry.
     */
    public static function filter_site_plugins($value, $old_value) {
        if (!is_array($value)) {
            return $value;
        }
        $old = is_array($old_value) ? $old_value : array();
        $added = array();
        foreach ($value as $basename) {
            if (!in_array($basename, $old, true)) {
                $added[] = $basename;
            }
        }
        $refused = self::refused($added);
        if ($refused === array()) {
            return $value;
        }
        $kept = array();
        foreach ($value as $basename) {
            if (!in_array($basename, $refused, true)) {
                $kept[] = $basename;
            }
        }
        return $kept;
    }

    /**
     * `pre_update_site_option_active_sitewide_plugins`: basename => activation time.
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Stored value.
     * @return mixed The new value, without a refused official entry.
     */
    public static function filter_network_plugins($value, $old_value) {
        if (!is_array($value)) {
            return $value;
        }
        $old = is_array($old_value) ? $old_value : array();
        $added = array();
        foreach (array_keys($value) as $basename) {
            if (!array_key_exists($basename, $old)) {
                $added[] = $basename;
            }
        }
        $refused = self::refused($added);
        foreach ($refused as $basename) {
            unset($value[$basename]);
        }
        return $value;
    }

    /**
     * Show the queued refusal once, to a user who can manage plugins.
     *
     * @return void
     */
    public static function render_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $refused = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($refused) || $refused === array()) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);
        $names = array();
        foreach ($refused as $basename) {
            if (is_string($basename)) {
                $names[] = '<code>' . esc_html($basename) . '</code>';
            }
        }
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('SUPCheckout kept the UPayments plugin switched off.', 'supcheckout')
            . '</strong> '
            . esc_html__('This store has already switched to SUPCheckout. Activating the UPayments plugin again would pause SUPCheckout and strand the payments started after the switch. To go back to UPayments, roll back the switch in SUPCheckout instead.', 'supcheckout')
            . ' ' . implode(', ', $names)
            . '</p></div>';
    }

    /**
     * The added entries to refuse: official main files, only while the store has switched.
     *
     * @param list<mixed> $added Entries the write adds.
     * @return list<string>
     */
    private static function refused(array $added) {
        $official = array();
        foreach ($added as $basename) {
            if (is_string($basename) && OfficialPluginConflict::is_conflicting_entry($basename, (string) self::$main_file)) {
                $official[] = $basename;
            }
        }
        if ($official === array()) {
            return array();
        }
        // Read past any cached state: a rollback in another process may have moved it.
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        if ($status !== TakeoverState::CUT_OVER && $status !== TakeoverState::FINALIZED) {
            return array();
        }
        self::queue_notice($official);
        return $official;
    }

    /**
     * @param list<string> $refused Refused basenames.
     * @return void
     */
    private static function queue_notice(array $refused) {
        $queued = get_transient(self::NOTICE_TRANSIENT);
        $queued = is_array($queued) ? $queued : array();
        set_transient(self::NOTICE_TRANSIENT, array_values(array_unique(array_merge($queued, $refused))), self::NOTICE_TTL);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI') && method_exists('\WP_CLI', 'warning')) {
            \WP_CLI::warning('SUPCheckout kept the UPayments plugin switched off: this store has already switched to SUPCheckout. Use `wp supcheckout takeover rollback` to go back.');
        }
    }

    private function __construct() {}
}
