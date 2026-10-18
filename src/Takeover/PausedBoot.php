<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;

/**
 * The paused branch of the main file (Sub-project 0b Task 9).
 *
 * The takeover's primary scenario is the official UPayments plugin being active, and then
 * the main file returns from OfficialPluginConflict::pause_if_active() before
 * Plugin::boot(). It calls boot() instead, which registers only:
 *
 * - the full boot's WooCommerce feature declarations (HPOS and the Cart & Checkout blocks)
 *   on `before_woocommerce_init`, through the same Bootstrap\WooCommerceDeclarations
 *   callback and for the same main file. Without them WooCommerce shows the paused plugin
 *   as "Incompatible with WooCommerce features". Declaring compatibility registers no
 *   gateway and touches no order;
 * - the `wp supcheckout takeover` command, when running under WP-CLI (Cli::register());
 * - on a Preflight probe request alone (`supcheckout_probe=1`), the probe answer on the
 *   new, unshared `supcheckout` WC-API route (LegacyCallbackListener::handle_probe()).
 *   Preflight's blocking `callback_unreachable` check needs that answer before cutover,
 *   which is exactly when the official plugin is active. It answers the probe and exits;
 *   it never reaches the payment lifecycle, touches no order and writes nothing, and on
 *   any other request nothing is registered at all;
 * - the 0c onboarding/preflight admin entry point: Sub-project 0c does not exist yet, so
 *   nothing is registered for wp-admin (the existing paused notice comes from
 *   OfficialPluginConflict itself).
 *
 * It registers no gateway, no Blocks method, no payment callback (`wc_upayments` stays the
 * official plugin's alone), no cron or Action Scheduler hook and declares no global
 * symbol, so it can never collide with the official plugin. Only Takeover classes load
 * here; the command loads the services it needs when it runs. `run --deactivate-official`
 * deactivates the official plugin and the full runtime boots on the next request.
 */
final class PausedBoot {
    /**
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function boot($main_file) {
        WooCommerceDeclarations::register_paused($main_file);
        Cli::register();
        if (LegacyCallbackListener::is_probe()) {
            add_action(LegacyCallbackListener::HOOK, array(LegacyCallbackListener::class, 'handle_probe'), 5);
        }
    }

    private function __construct() {}
}
