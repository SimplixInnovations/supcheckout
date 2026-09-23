<?php
/**
 * Real WooCommerce order-completion preference safety certification.
 *
 * The inherited setting defaults to "yes" for a genuinely missing setting,
 * preserving upgrade/fresh-install behavior. Only exact WooCommerce checkbox
 * tokens may otherwise affect paid-order finalization: exact "yes" requests
 * Completed, exact "no" retains Processing, and malformed persisted values
 * fail safe to Processing rather than prematurely finalizing fulfillment.
 */

require_once __DIR__ . '/bootstrap.php';

$original_settings = get_option('woocommerce_upayments_settings');

$cases = array(
    'explicit-completed' => array('present' => true, 'value' => 'yes', 'complete' => true),
    'explicit-processing' => array('present' => true, 'value' => 'no', 'complete' => false),
    'missing-preserves-declared-default' => array('present' => false, 'value' => null, 'complete' => true),
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
    $gateway = new WC_Upayments();

    supcheckout_cert_assert(
        $gateway->getIsOrderComplete() === $case['complete'],
        'Order completion preference is fail-safe for case ' . $label
    );
}

supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $original_settings);
supcheckout_cert_note('Order completion preference safety certification complete');
