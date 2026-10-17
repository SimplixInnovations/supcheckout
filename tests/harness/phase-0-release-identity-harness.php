<?php

/**
 * Phase 0 release-identity/updater characterization harness.
 *
 * This is intentionally WordPress-independent. It verifies package/source
 * contracts that must hold before the canonical SUPCheckout release identity can merge.
 */

$root = dirname(__DIR__, 2);
$bootstrap_path = $root . '/supcheckout.php';
$identity_path = $root . '/src/Release/Identity.php';
$uninstall_path = $root . '/uninstall.php';

$pass = 0;
$fail = 0;

function p0_assert($condition, $message) {
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }

    $fail++;
    echo "FAIL: {$message}\n";
}

function p0_header_value($source, $field) {
    $pattern = '/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.*?)\s*$/mi';
    if (!preg_match($pattern, $source, $matches)) {
        return null;
    }
    return $matches[1];
}

function p0_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$bootstrap = file_get_contents($bootstrap_path);
$checkout_orchestrator = file_get_contents($root . '/src/Payment/CheckoutOrchestrator.php');
// The gateway class the bootstrap registers lives in src/Gateway/Gateway.php since Sub-project 0a Task 3.
$gateway_class_source = file_get_contents($root . '/src/Gateway/Gateway.php');
$gateway_registration_source = file_get_contents($root . '/src/Gateway/GatewayRegistration.php');
// The My Account subscription action handler left the bootstrap in Sub-project 0a Task 4.
$subscription_actions_source = file_get_contents($root . '/src/Account/SubscriptionActions.php');
// The main file's constants and requires moved verbatim into Plugin::load_runtime()
// in Sub-project 0a Task 5; the bootstrap rules below cover both files together.
$plugin_source = file_get_contents($root . '/src/Plugin.php');
$bootstrap_path_sources = $bootstrap . "\n" . $plugin_source;
$runtime_source = $bootstrap_path_sources . "\n" . $gateway_class_source . "\n" . $checkout_orchestrator;
$identity_source = file_get_contents($identity_path);
$uninstall = file_get_contents($uninstall_path);

p0_assert(is_string($bootstrap) && $bootstrap !== '', 'bootstrap is readable');
p0_assert(is_string($plugin_source) && p0_contains($plugin_source, 'final class Plugin'), 'composition root src/Plugin.php is readable');
p0_assert(is_string($identity_source) && $identity_source !== '', 'release identity source is readable');
p0_assert(is_string($uninstall) && $uninstall !== '', 'uninstall source is readable');

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $identity_path;

use Simplixi\SUPCheckout\Release\Identity;

// Public product header: canonical SUPCheckout identity on the independent 0.x line.
p0_assert(p0_header_value($bootstrap, 'Plugin Name') === 'SUPCheckout for UPayments', 'public header exposes canonical SUPCheckout product name');
p0_assert(p0_header_value($bootstrap, 'Plugin URI') === 'https://github.com/SimplixInnovations/supcheckout', 'plugin URI exposes canonical SUPCheckout repository target');
p0_assert(p0_header_value($bootstrap, 'Description') === 'Independently engineered UPayments payment integration for WooCommerce by Simplix Innovations.', 'plugin description remains provider-accurate during identity transition');
p0_assert(p0_header_value($bootstrap, 'Version') === Identity::VERSION, 'header version matches canonical release identity');
p0_assert(p0_header_value($bootstrap, 'Author') === 'Simplix Innovations', 'plugin author is Simplix Innovations');
p0_assert(p0_header_value($bootstrap, 'Author URI') === 'https://simplixi.com', 'author URI is Simplix');
p0_assert(p0_header_value($bootstrap, 'License') === 'MIT', 'plugin header keeps MIT license');
p0_assert(p0_header_value($bootstrap, 'Domain Path') === null, 'plugin header does not advertise a non-packaged languages directory');

p0_assert(p0_header_value($bootstrap, 'Text Domain') === Identity::TARGET_TEXT_DOMAIN, 'public text domain is the canonical SUPCheckout domain');
p0_assert(Identity::TARGET_TEXT_DOMAIN === 'supcheckout', 'target text domain remains frozen');

// Bootstrap exposes canonical SUPCheckout identity without destroying legacy plugin-file API.
p0_assert(p0_contains($plugin_source, "require_once __DIR__ . '/Release/Identity.php';"), 'bootstrap loads canonical release identity');
p0_assert(p0_contains($plugin_source, "define('SUPCHECKOUT_VERSION'"), 'bootstrap defines canonical version constant');
p0_assert(p0_contains($plugin_source, "define('SUPCHECKOUT_PLUGIN_FILE'"), 'bootstrap defines canonical plugin-file constant');
p0_assert(p0_contains($plugin_source, "define('UPAYMENTS_PLUGIN_FILE'"), 'legacy plugin-file constant remains available');

// No external self-update authority may remain after Phase 0A.
p0_assert(Identity::UPDATE_CHANNEL === 'disabled', 'release identity records disabled external update channel');
p0_assert(!p0_contains($bootstrap_path_sources, 'upaymentskwt/woocommerce'), 'upstream repository is not an update authority');
p0_assert(!p0_contains($bootstrap_path_sources, 'PucFactory'), 'bootstrap no longer instantiates Plugin Update Checker');
p0_assert(!p0_contains($bootstrap_path_sources, 'plugin-update-checker'), 'bootstrap no longer loads Plugin Update Checker');
p0_assert(!is_dir($root . '/vendor/plugin-update-checker'), 'bundled Plugin Update Checker is removed');

// Exactly one physical main file: the owner-approved supcheckout.php rename (Sub-project 0a, 2026-10-16).
p0_assert(basename($bootstrap_path) === Identity::MAIN_FILE, 'main filename is the approved supcheckout.php bootstrap');
$legacy_shim = (string) @file_get_contents($root . '/' . Identity::LEGACY_MAIN_FILE);
p0_assert(p0_header_value($legacy_shim, 'Plugin Name') === null, 'pre-rename UPayments.php is only a header-less shim, so supcheckout.php is the one plugin main file');

// Core compatibility identities must remain discoverable in the source tree.
// Sub-project 0a Task 3 namespaces the gateway class (spec: no legacy global class may
// collide with the official plugin's WC_Upayments); WooCommerce still receives it by name.
p0_assert(
    p0_contains($gateway_class_source, 'class Gateway extends \\WC_Payment_Gateway')
        && p0_contains($gateway_registration_source, '$methods[] = Gateway::class;'),
    'gateway class identity remains present and registered with WooCommerce'
);
p0_assert(p0_contains($runtime_source, 'wc_upayments'), 'legacy callback route remains present');
p0_assert(p0_contains($bootstrap_path_sources . "\n" . $subscription_actions_source, "'upayments'"), 'legacy upayments runtime identity remains present');

$token_source = file_get_contents($root . '/includes/Token/CustomerTokenIdentity.php');
p0_assert(p0_contains($token_source, 'upayments_token_identity_secret_v2'), 'H12 identity secret key remains protected');

$scheduler_source = file_get_contents($root . '/includes/Subscription/Cron/Scheduler.php');
p0_assert(p0_contains($scheduler_source, 'upay_process_subscriptions'), 'legacy subscription cron identity remains protected');

// Uninstall must preserve merchant/payment data until an explicit cleanup
// contract exists. No silent DROP/option deletion is allowed.
p0_assert(!preg_match('/\bDROP\s+TABLE\b/i', $uninstall), 'uninstall does not drop payment tables');
p0_assert(!p0_contains($uninstall, 'delete_option('), 'uninstall does not delete persisted options');
p0_assert(p0_contains($uninstall, 'WP_UNINSTALL_PLUGIN'), 'uninstall retains direct-access guard');

// Independent version line sanity.
p0_assert((bool) preg_match('/^0\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', Identity::VERSION), 'version is on independent pre-1.0 semantic line');
p0_assert(Identity::SLUG === 'supcheckout', 'canonical slug is exact');
p0_assert(Identity::REPOSITORY === 'SimplixInnovations/supcheckout', 'canonical repository is exact');

echo "\n--- Phase 0 Release Identity Report ---\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";

echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
