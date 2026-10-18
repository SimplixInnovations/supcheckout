<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;
use Simplixi\SUPCheckout\Rest\Routes;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;

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
 * - the takeover REST routes (`rest_api_init` -> Rest\Routes::register_takeover(): the
 *   eight onboarding routes, never the self-test or setup-check routes; the `::class`
 *   constant loads nothing, so a plain request loads no extra file) and the two onboarding
 *   driver hooks that Action Scheduler runs (AsyncDriver::HOOK and WATCHDOG_HOOK), added
 *   at once under cron, AJAX or WP-CLI and otherwise lazily on
 *   `action_scheduler_before_process_queue` (ALTERNATE_WP_CRON and Action Scheduler's
 *   admin "Run" action). Neither reads any state. The admin screens come in Sub-project 2
 *   (the existing paused notice comes from OfficialPluginConflict itself).
 *
 * It registers no gateway, no Blocks method, no payment callback (`wc_upayments` stays the
 * official plugin's alone), no payment or subscription cron or Action Scheduler hook and
 * declares no global symbol, so it can never collide with the official plugin. Only takeover
 * classes load here; the command loads the services it needs when it runs. `run --deactivate-official`
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
        add_action('rest_api_init', array(Routes::class, 'register_takeover'));
        if (self::driver_runs_in_this_request()) {
            AsyncDriver::register();
        } else {
            add_action('action_scheduler_before_process_queue', array(AsyncDriver::class, 'register_hooks'));
        }
        if (LegacyCallbackListener::is_probe()) {
            add_action(LegacyCallbackListener::HOOK, array(LegacyCallbackListener::class, 'handle_probe'), 5);
        }
    }

    /**
     * The contexts AsyncDriver::register() hooks at once. Repeated here so a plain request
     * never loads AsyncDriver; the lazy hook then covers every other context.
     *
     * @return bool
     */
    private static function driver_runs_in_this_request() {
        return (defined('WP_CLI') && constant('WP_CLI'))
            || (function_exists('wp_doing_cron') && wp_doing_cron())
            || (function_exists('wp_doing_ajax') && wp_doing_ajax());
    }

    private function __construct() {}
}
