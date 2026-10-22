<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Gateway\OfficialPluginConflict;

defined('ABSPATH') || exit;

/**
 * Refuses to activate the official UPayments plugin where it would strand payments (A5-01).
 *
 * Once a site is cut_over or finalized, every Charge started after the switch calls back on
 * the `supcheckout` route. An active official plugin pauses SUPCheckout
 * (OfficialPluginConflict), and a paused SUPCheckout answers only the preflight probe there,
 * so those browser returns and webhooks would be dropped. The official plugin would also run
 * on the legacy settings from before the switch.
 *
 * Two refusals:
 * - a site activation (`active_plugins`) while that site is cut_over or finalized;
 * - on multisite, any network activation (`active_sitewide_plugins`), whatever the state. It
 *   fails closed: a network-activated official plugin pauses SUPCheckout on every site,
 *   Preflight already treats it as unsupported, and a network-admin request would only see
 *   the main site's takeover state.
 *
 * WordPress includes the plugin and runs its activation hooks before it writes the option.
 * So before_activation() (on `activate_plugin`, priority 0) first removes the refused
 * plugin's `activate_{plugin}` callbacks and the `activated_plugin` callbacks defined in its
 * own directory: its activation code (creating or overwriting the checkout page, for
 * example) never runs. The option filters are the backstop that also covers bulk
 * activation, WP-CLI and direct option writes. They remove only an official main file that
 * the write adds (one that would pause SUPCheckout, by the same test OfficialPluginConflict
 * uses), keep every other entry, and queue one admin error.
 *
 * A site activation is never refused in any other state: Rollback and Abort reactivate the
 * official plugin in preflight_ok, after leaving cut_over, and an administrator may
 * reactivate it by hand after a rollback (rolled_back). The way back from cut_over is
 * Rollback; a finalized switch is final.
 *
 * The guard acts only in a request where SUPCheckout itself is loaded and booted (it is
 * registered by Plugin::boot(); a paused SUPCheckout has nothing to guard). An activation
 * from a request where SUPCheckout is not loaded, for example the network admin of a
 * network where SUPCheckout is not active on the main site, or a write that bypasses the
 * options API (a database restore), is not refused; the state-aware paused notice then says
 * what happened.
 */
final class ReactivationGuard {
    /** Basenames refused since an administrator last saw the notice, by reason. */
    public const NOTICE_TRANSIENT = 'supcheckout_reactivation_blocked';

    /** Refused because the site has switched (cut_over or finalized). */
    private const REASON_SWITCHED = 'switched';

    /** Refused because network activation is unsupported while SUPCheckout runs. */
    private const REASON_NETWORK = 'network';

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
        add_action('activate_plugin', array(self::class, 'before_activation'), 0, 2);
        add_action('admin_notices', array(self::class, 'render_notice'));
        add_action('network_admin_notices', array(self::class, 'render_notice'));
    }

    /**
     * `activate_plugin` (priority 0): WordPress has included the plugin but not yet run its
     * activation hooks. For a refused official plugin, remove them.
     *
     * @param mixed $plugin       Plugin basename.
     * @param mixed $network_wide Whether this is a network activation.
     * @return void
     */
    public static function before_activation($plugin, $network_wide = false) {
        if (!is_string($plugin) || self::refusal_reason($plugin, (bool) $network_wide) === null) {
            return;
        }
        remove_all_actions('activate_' . $plugin);
        self::remove_callbacks_defined_in('activated_plugin', $plugin);
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
        $refused = self::refuse($added, false);
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
        foreach (self::refuse($added, true) as $basename) {
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
        $queued = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($queued) || $queued === array()) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);
        foreach (array(self::REASON_SWITCHED, self::REASON_NETWORK) as $reason) {
            if (!isset($queued[$reason]) || !is_array($queued[$reason]) || $queued[$reason] === array()) {
                continue;
            }
            $names = array();
            foreach ($queued[$reason] as $basename) {
                if (is_string($basename)) {
                    $names[] = '<code>' . esc_html($basename) . '</code>';
                }
            }
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html(self::heading($reason))
                . '</strong> '
                . esc_html(self::explanation($reason))
                . ' ' . implode(', ', $names)
                . '</p></div>';
        }
    }

    /**
     * Refuse the added official entries, queue the notice, and return them.
     *
     * @param list<mixed> $added   Entries the write adds.
     * @param bool        $network Whether this is the network option.
     * @return list<string>
     */
    private static function refuse(array $added, $network) {
        $refused = array();
        $reason = null;
        foreach ($added as $basename) {
            if (!is_string($basename)) {
                continue;
            }
            $basename_reason = self::refusal_reason($basename, $network);
            if ($basename_reason !== null) {
                $refused[] = $basename;
                $reason = $basename_reason;
            }
        }
        if ($reason !== null) {
            self::queue_notice($refused, $reason);
        }
        return $refused;
    }

    /**
     * @param string $basename Plugin basename.
     * @param bool   $network  Whether this is a network activation.
     * @return string|null The reason to refuse, or null to allow.
     */
    private static function refusal_reason($basename, $network) {
        if (!OfficialPluginConflict::is_conflicting_entry($basename, (string) self::$main_file)) {
            return null;
        }
        if ($network && function_exists('is_multisite') && is_multisite()) {
            return self::REASON_NETWORK;
        }
        return self::has_switched() ? self::REASON_SWITCHED : null;
    }

    /**
     * @return bool True while this site is cut_over or finalized, read past any cached state
     *              (a rollback in another process may have moved it).
     */
    private static function has_switched() {
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        return $status === TakeoverState::CUT_OVER || $status === TakeoverState::FINALIZED;
    }

    /**
     * Remove the $hook callbacks whose code lives in $plugin's own directory, keeping every
     * other plugin's.
     *
     * @param string $hook   Hook name.
     * @param string $plugin Plugin basename.
     * @return void
     */
    private static function remove_callbacks_defined_in($hook, $plugin) {
        $registry = isset($GLOBALS['wp_filter'][$hook]) ? $GLOBALS['wp_filter'][$hook] : null;
        if (!defined('WP_PLUGIN_DIR') || !is_object($registry) || !isset($registry->callbacks) || !is_array($registry->callbacks)) {
            return;
        }
        // A plugin in its own directory owns that directory. One installed at the plugins root
        // (dirname '.') owns only its main file: a directory prefix there would be the whole
        // plugins directory, so the file is compared for equality instead.
        $root_level = dirname($plugin) === '.';
        $owned = $root_level ? constant('WP_PLUGIN_DIR') . '/' . $plugin : constant('WP_PLUGIN_DIR') . '/' . dirname($plugin);
        $paths = array(str_replace('\\', '/', $owned));
        $real = realpath($owned);
        if (is_string($real)) {
            $paths[] = str_replace('\\', '/', $real);
        }
        foreach ($registry->callbacks as $priority => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $function = is_array($callback) && isset($callback['function']) ? $callback['function'] : null;
                $file = self::defining_file($function);
                if ($file !== null && self::is_owned($file, $paths, $root_level)) {
                    remove_action($hook, $function, $priority);
                }
            }
        }
    }

    /**
     * @param mixed $callable A hook callback.
     * @return string|null The file that defines it, or null when unknown.
     */
    private static function defining_file($callable) {
        try {
            if (is_string($callable) && strpos($callable, '::') !== false) {
                $callable = explode('::', $callable, 2);
            }
            if (is_array($callable) && count($callable) === 2 && (is_object($callable[0]) || is_string($callable[0])) && is_string($callable[1])) {
                $reflection = new \ReflectionMethod($callable[0], $callable[1]);
            } elseif ($callable instanceof \Closure || (is_string($callable) && function_exists($callable))) {
                $reflection = new \ReflectionFunction($callable);
            } elseif (is_object($callable) && method_exists($callable, '__invoke')) {
                $reflection = new \ReflectionMethod($callable, '__invoke');
            } else {
                return null;
            }
        } catch (\ReflectionException $e) {
            return null;
        }
        $file = $reflection->getFileName();
        return is_string($file) ? str_replace('\\', '/', $file) : null;
    }

    /**
     * @param string       $file       Normalised file path.
     * @param list<string> $paths      Normalised owned directory (or, root level, main file) paths.
     * @param bool         $root_level Whether the paths are files to compare for equality.
     * @return bool
     */
    private static function is_owned($file, array $paths, $root_level) {
        foreach ($paths as $path) {
            if ($root_level ? $file === $path : strpos($file, $path . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $reason Refusal reason.
     * @return string
     */
    private static function heading($reason) {
        return $reason === self::REASON_NETWORK
            ? __('SUPCheckout kept the UPayments plugin switched off for the network.', 'supcheckout')
            : __('SUPCheckout kept the UPayments plugin switched off.', 'supcheckout');
    }

    /**
     * The way back depends on the state: rollback from cut_over, none from finalized.
     *
     * @param string $reason Refusal reason.
     * @return string
     */
    private static function explanation($reason) {
        if ($reason === self::REASON_NETWORK) {
            return __('A network-activated UPayments plugin would pause SUPCheckout on every site of this network, which SUPCheckout does not support. Activate UPayments on individual sites instead.', 'supcheckout');
        }
        $switched = __('This store has already switched to SUPCheckout. Activating the UPayments plugin again would pause SUPCheckout and strand the payments started after the switch.', 'supcheckout');
        $way_back = TakeoverState::get()['status'] === TakeoverState::FINALIZED
            ? __('The switch is final, so the UPayments plugin cannot take this store back.', 'supcheckout')
            : __('To go back to UPayments, roll back the switch in SUPCheckout instead.', 'supcheckout');
        return $switched . ' ' . $way_back;
    }

    /**
     * @param list<string> $refused Refused basenames.
     * @param string       $reason  Refusal reason.
     * @return void
     */
    private static function queue_notice(array $refused, $reason) {
        $queued = get_transient(self::NOTICE_TRANSIENT);
        $queued = is_array($queued) ? $queued : array();
        $existing = isset($queued[$reason]) && is_array($queued[$reason]) ? $queued[$reason] : array();
        $queued[$reason] = array_values(array_unique(array_merge($existing, $refused)));
        set_transient(self::NOTICE_TRANSIENT, $queued, self::NOTICE_TTL);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI') && method_exists('\WP_CLI', 'warning')) {
            \WP_CLI::warning(self::heading($reason) . ' ' . self::explanation($reason));
        }
    }

    private function __construct() {}
}
