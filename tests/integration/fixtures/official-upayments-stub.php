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
 * registers on it. It declares no global function or class.
 */

defined('ABSPATH') || exit;

add_action('woocommerce_api_wc_upayments', static function () {
    // The official plugin's callback handler would run here; the stand-in only owns the route.
});
