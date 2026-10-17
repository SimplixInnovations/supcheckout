<?php
/**
 * Plugin Name: UPayments certification stand-in (global symbols)
 * Description: Certification-only stand-in for the official UPayments plugin. Never shipped.
 * Version: 3.1.1
 *
 * Variant of official-upayments-stub.php that the compatibility workflow installs before
 * the takeover rollback phases (tests/integration/TakeoverCliTest.php). Like the official
 * plugin it declares the global function woocommerceUpaymentsInit() and the global class
 * WC_Upayments. Loading the official main file into a PHP process in which SUPCheckout's
 * runtime is already booted is what fatals in the field ("Cannot redeclare", 0b Task 10
 * review). SUPCheckout no longer declares those two names itself, so this stand-in makes
 * that one case fatal explicitly: included after SUPCheckout booted in the same process, it
 * stops with the same kind of fatal. `rollback --reactivate-official` must therefore never
 * include it; the next request loads it and SUPCheckout pauses itself first.
 */

defined('ABSPATH') || exit;

if (defined('SUPCHECKOUT_VERSION')) {
    throw new Error('Cannot redeclare woocommerceUpaymentsInit() (the official plugin was loaded into a process where SUPCheckout is booted)');
}

/**
 * The official plugin's bootstrap function name (stand-in), declared unconditionally as
 * the official plugin does.
 *
 * @return void
 */
function woocommerceUpaymentsInit() {
}

/** The official plugin's gateway class name (stand-in). */
class WC_Upayments {
}

// The official plugin's callback, renewal cron and payment hooks, as in the plain stand-in.
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
