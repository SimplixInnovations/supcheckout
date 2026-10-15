<?php

namespace Simplixi\SUPCheckout\Gateway;

defined('ABSPATH') || exit;

/**
 * Keeps SUPCheckout and the official UPayments plugin from running together.
 *
 * Both plugins declare the same global functions and classes (protected legacy
 * identities such as woocommerceUpaymentsInit() and WC_Upayments), so whichever
 * main file loads second would die with "Cannot redeclare". While another active
 * plugin's main file is UPayments.php, SUPCheckout declares nothing global and
 * shows administrators how to switch. The other plugin keeps processing payments
 * until it is deactivated.
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
        if (!function_exists('get_option') || !function_exists('plugin_basename')) {
            return null;
        }
        $own = plugin_basename((string) $plugin_file);
        $active = get_option('active_plugins', array());
        $active = is_array($active) ? $active : array();
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $sitewide = get_site_option('active_sitewide_plugins', array());
            if (is_array($sitewide)) {
                $active = array_merge($active, array_keys($sitewide));
            }
        }
        foreach ($active as $basename) {
            if (is_string($basename) && $basename !== $own && basename($basename) === self::MAIN_FILE) {
                return $basename;
            }
        }
        return null;
    }

    /**
     * @return void
     */
    public static function render_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('SUPCheckout is paused.', 'supcheckout')
            . '</strong> '
            . esc_html__('The UPayments plugin is also active, and the two cannot run at the same time. UPayments keeps processing payments until you switch. Deactivate the UPayments plugin to switch to SUPCheckout; your gateway settings stay in place.', 'supcheckout')
            . '</p></div>';
    }

    private function __construct() {}
}
