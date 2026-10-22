<?php
/**
 * Plugin Name: UPayments certification stand-in
 * Description: Certification-only stand-in for the official UPayments plugin. Never shipped.
 * Version: 3.1.1
 *
 * Copied into wp-content/plugins/upayments/UPayments.php and activated by the
 * compatibility workflow's takeover CLI steps (tests/integration/TakeoverCliTest.php).
 * The official plugin's source is not vendored in this repository, and the real 3.1.1
 * and 2.x refs are the Sub-project 0b Task 12 migration matrix. This file reproduces
 * only what SUPCheckout's detection and the takeover depend on: an active plugin in
 * another directory whose main file is UPayments.php (OfficialPluginConflict), a 3.1.1
 * version header (Preflight's official_plugin_active fact) and ownership of the legacy
 * `wc_upayments` WC-API route, so the test can show the paused SUPCheckout never
 * registers on it. It declares no global function or class (one loop variable, unset).
 */

defined('ABSPATH') || exit;

// The official plugin's callback, renewal cron and payment hooks. After the takeover
// deactivates the stand-in, its code stays loaded in that WP-CLI process: if anything in
// the cutover path fired one of these there, the hook name is recorded and the verify
// phase fails.
foreach (array('woocommerce_api_wc_upayments', 'upay_process_subscriptions', 'woocommerce_payment_complete', 'woocommerce_order_status_changed') as $supcheckout_cert_hook) {
    add_action($supcheckout_cert_hook, static function () use ($supcheckout_cert_hook) {
        if (!in_array('upayments/UPayments.php', (array) get_option('active_plugins', array()), true)) {
            $fired = (array) get_option('supcheckout_cert_official_stub_fired', array());
            $fired[] = $supcheckout_cert_hook;
            update_option('supcheckout_cert_official_stub_fired', $fired, false);
        }
    });
}
unset($supcheckout_cert_hook);

// The official plugin's activation hook (the real one can create or overwrite the checkout
// page). TakeoverCliTest's verify phase activates this stand-in on the cut-over store with
// activate_plugin() and asserts that this callback never runs (A5-01 review round 2).
register_activation_hook(__FILE__, static function () {
    update_option('supcheckout_cert_official_activation_ran', time(), false);
});
