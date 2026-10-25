<?php
/**
 * Real WooCommerce order-completion preference safety certification.
 *
 * A1-11 (batch 5): a stored settings option without the key keeps "yes", as the
 * official 3.1.1 plugin read it, so upgraded and migrated stores are unchanged. A
 * genuinely fresh install (no settings option) is hydrated from the declared field
 * default, now "no" (Processing). Only exact WooCommerce checkbox
 * tokens may otherwise affect paid-order finalization: exact "yes" requests
 * Completed, exact "no" retains Processing, and malformed persisted values
 * fail safe to Processing rather than prematurely finalizing fulfillment.
 */

require_once __DIR__ . '/bootstrap.php';

$original_settings = get_option('woocommerce_upayments_settings');

$cases = array(
    'explicit-completed' => array('present' => true, 'value' => 'yes', 'complete' => true),
    'explicit-processing' => array('present' => true, 'value' => 'no', 'complete' => false),
    'missing-key-keeps-official-yes' => array('present' => false, 'value' => null, 'complete' => true),
    'blank-string' => array('present' => true, 'value' => '', 'complete' => false),
    'string-zero' => array('present' => true, 'value' => '0', 'complete' => false),
    'string-one' => array('present' => true, 'value' => '1', 'complete' => false),
    'string-true' => array('present' => true, 'value' => 'true', 'complete' => false),
    'string-false' => array('present' => true, 'value' => 'false', 'complete' => false),
    'integer-zero' => array('present' => true, 'value' => 0, 'complete' => false),
    'integer-one' => array('present' => true, 'value' => 1, 'complete' => false),
    'boolean-false' => array('present' => true, 'value' => false, 'complete' => false),
    'boolean-true' => array('present' => true, 'value' => true, 'complete' => false),
    'null' => array('present' => true, 'value' => null, 'complete' => false),
    'array' => array('present' => true, 'value' => array('yes'), 'complete' => false),
);

foreach ($cases as $label => $case) {
    $settings = array(
        'enabled' => 'yes',
        'api_key' => 'order-completion-certification-key',
    );
    if ($case['present']) {
        $settings['is_order_complete'] = $case['value'];
    }

    supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $settings);
    $gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();

    supcheckout_cert_assert(
        $gateway->getIsOrderComplete() === $case['complete'],
        'Order completion preference is fail-safe for case ' . $label
    );
}

// A1-11: no settings option at all is a fresh install, hydrated from the field default "no".
delete_option('woocommerce_upayments_settings');
wp_cache_delete('woocommerce_upayments_settings', 'options');
$fresh_gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();
supcheckout_cert_assert(
    $fresh_gateway->getIsOrderComplete() === false && $fresh_gateway->get_option('is_order_complete') === 'no',
    'A fresh install with no settings option sends paid orders to Processing'
);

// A1-11: a stored option without the key shows the "yes" it applies on the settings screen.
supcheckout_cert_store_option_raw('woocommerce_upayments_settings', array('enabled' => 'yes', 'api_key' => 'order-completion-certification-key'));
$missing_key_gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();
supcheckout_cert_assert(
    $missing_key_gateway->getIsOrderComplete() === true && $missing_key_gateway->get_option('is_order_complete') === 'yes',
    'A stored option without the key keeps the official "yes" on runtime and settings screen'
);

supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $original_settings);
supcheckout_cert_note('Order completion preference safety certification complete');
