<?php

namespace Simplixi\SUPCheckout\Gateway;

use Simplixi\SUPCheckout\Takeover\TakeoverState;

defined('ABSPATH') || exit;

/**
 * Keeps SUPCheckout and the official UPayments plugin from running together.
 *
 * Both plugins declare the same global functions and classes (protected legacy
 * identities such as woocommerceUpaymentsInit() and WC_Upayments), so whichever
 * main file loads second would die with "Cannot redeclare". While another active
 * plugin's main file is UPayments.php, SUPCheckout declares nothing global and
 * shows administrators how to switch. Before the switch the other plugin keeps
 * processing payments until it is deactivated. On a store that has already switched
 * (cut_over or finalized) the notice says so instead: payments started after the switch
 * call back on the `supcheckout` route, which a paused SUPCheckout does not answer
 * (A5-01). Takeover\ReactivationGuard refuses that reactivation while SUPCheckout runs.
 */
final class OfficialPluginConflict {
    private const MAIN_FILE = 'UPayments.php';

    /**
     * Register the admin notice and report true when SUPCheckout must stay paused.
     *
     * @param string $plugin_file Absolute path of the SUPCheckout main file.
     * @return bool
     */
    public static function pause_if_active($plugin_file) {
        $conflict = self::conflicting_plugin($plugin_file);
        if ($conflict === null) {
            return false;
        }
        add_action('admin_notices', array(self::class, 'render_notice'));
        return true;
    }

    /**
     * @param string $plugin_file Absolute path of the SUPCheckout main file.
     * @return string|null Basename of the conflicting active plugin, if any.
     */
    public static function conflicting_plugin($plugin_file) {
        $conflicts = self::conflicting_plugins($plugin_file);
        return $conflicts === array() ? null : $conflicts[0];
    }

    /**
     * Every conflicting active plugin, in activation order (site entries, then network).
     *
     * @param string $plugin_file Absolute path of the SUPCheckout main file.
     * @return list<string> Basenames.
     */
    public static function conflicting_plugins($plugin_file) {
        if (!function_exists('get_option') || !function_exists('plugin_basename')) {
            return array();
        }
        // Every entry inside SUPCheckout's own directory is ours, including the
        // transitional supcheckout/UPayments.php shim; only other directories count.
        $own_dir = self::own_dir($plugin_file);
        $active = get_option('active_plugins', array());
        $active = is_array($active) ? $active : array();
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $sitewide = get_site_option('active_sitewide_plugins', array());
            if (is_array($sitewide)) {
                $active = array_merge($active, array_keys($sitewide));
            }
        }
        $conflicts = array();
        foreach ($active as $basename) {
            if (self::matches($basename, $own_dir) && !in_array($basename, $conflicts, true)) {
                $conflicts[] = $basename;
            }
        }
        return $conflicts;
    }

    /**
     * True when $basename is an entry that would pause SUPCheckout if it were active: a
     * loadable UPayments.php main file outside SUPCheckout's own directory.
     *
     * @param mixed  $basename    Plugin basename (an active_plugins entry or a network key).
     * @param string $plugin_file Absolute path of the SUPCheckout main file.
     * @return bool
     */
    public static function is_conflicting_entry($basename, $plugin_file) {
        if (!function_exists('plugin_basename')) {
            return false;
        }
        return self::matches($basename, self::own_dir($plugin_file));
    }

    /**
     * @param string $plugin_file Absolute path of the SUPCheckout main file.
     * @return string SUPCheckout's own plugin directory, relative to the plugins directory.
     */
    private static function own_dir($plugin_file) {
        return dirname(str_replace('\\', '/', (string) plugin_basename((string) $plugin_file)));
    }

    /**
     * @param mixed  $basename Plugin basename.
     * @param string $own_dir  SUPCheckout's own directory.
     * @return bool
     */
    private static function matches($basename, $own_dir) {
        return is_string($basename)
            && $basename !== ''
            && ($own_dir === '.' || dirname($basename) !== $own_dir)
            && basename($basename) === self::MAIN_FILE
            && self::is_loadable($basename);
    }

    /**
     * Mirror WordPress's own active-plugin validation: a stale entry whose file is gone,
     * or an invalid path, never loads, so it must never pause SUPCheckout either.
     *
     * @param string $basename Plugin basename from active_plugins.
     * @return bool
     */
    private static function is_loadable($basename) {
        if (!defined('WP_PLUGIN_DIR')
            || strpos($basename, '..') !== false
            || strpos($basename, ':') !== false
            || strpos($basename, '\\') !== false
            || $basename[0] === '/'
        ) {
            return false;
        }
        return is_file(WP_PLUGIN_DIR . '/' . $basename);
    }

    /**
     * The paused notice, worded for the takeover state.
     *
     * @return void
     */
    public static function render_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $status = TakeoverState::get()['status'];
        if ($status !== TakeoverState::CUT_OVER && $status !== TakeoverState::FINALIZED) {
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html__('SUPCheckout is paused.', 'supcheckout')
                . '</strong> '
                . esc_html__('The UPayments plugin is also active, and the two cannot run at the same time. UPayments keeps processing payments until you switch. Deactivate the UPayments plugin to switch to SUPCheckout; your gateway settings stay in place.', 'supcheckout')
                . '</p></div>';
            return;
        }
        $way_back = $status === TakeoverState::CUT_OVER
            ? __('To go back to UPayments, deactivate it first and then roll back the switch in SUPCheckout.', 'supcheckout')
            : __('The switch is final, so the UPayments plugin cannot take this store back.', 'supcheckout');
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('SUPCheckout is paused.', 'supcheckout')
            . '</strong> '
            . esc_html__('This store has already switched to SUPCheckout, and the UPayments plugin is active again. Payments started after the switch cannot complete while SUPCheckout is paused, and UPayments does not have the gateway settings changed after the switch. Deactivate the UPayments plugin now to resume SUPCheckout.', 'supcheckout')
            . ' '
            . esc_html($way_back)
            . '</p></div>';
    }

    private function __construct() {}
}
