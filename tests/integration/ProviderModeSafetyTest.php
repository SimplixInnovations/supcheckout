<?php
/**
 * Real WooCommerce provider-environment fail-safe certification.
 *
 * Only the canonical WooCommerce checkbox value "yes" may select UPayments
 * sandbox. Missing, disabled, malformed, or legacy-corrupted values must fail
 * safe to the live provider environment so PHP type-coercion changes cannot
 * silently reroute payment traffic.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Provider\EndpointResolver;

$original_settings = get_option('woocommerce_upayments_settings');

$cases = array(
    'explicit-sandbox' => array('present' => true, 'value' => 'yes', 'sandbox' => true),
    'explicit-live' => array('present' => true, 'value' => 'no', 'sandbox' => false),
    'missing' => array('present' => false, 'value' => null, 'sandbox' => false),
    'blank-string' => array('present' => true, 'value' => '', 'sandbox' => false),
    'string-zero' => array('present' => true, 'value' => '0', 'sandbox' => false),
    'string-one' => array('present' => true, 'value' => '1', 'sandbox' => false),
    'string-true' => array('present' => true, 'value' => 'true', 'sandbox' => false),
    'string-false' => array('present' => true, 'value' => 'false', 'sandbox' => false),
    'integer-zero' => array('present' => true, 'value' => 0, 'sandbox' => false),
    'integer-one' => array('present' => true, 'value' => 1, 'sandbox' => false),
    'boolean-false' => array('present' => true, 'value' => false, 'sandbox' => false),
    'boolean-true' => array('present' => true, 'value' => true, 'sandbox' => false),
    'null' => array('present' => true, 'value' => null, 'sandbox' => false),
    'array' => array('present' => true, 'value' => array('yes'), 'sandbox' => false),
);

foreach ($cases as $label => $case) {
    $settings = array(
        'enabled' => 'yes',
        'api_key' => 'provider-mode-certification-key',
    );
    if ($case['present']) {
        $settings['test_mode'] = $case['value'];
    }

    supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $settings);
    $gateway = new WC_Upayments();
    $expected_mode = $case['sandbox'];
    $expected_base = $expected_mode ? EndpointResolver::SANDBOX_BASE : EndpointResolver::LIVE_BASE;

    supcheckout_cert_assert(
        $gateway->getMode() === $expected_mode,
        'Provider mode is fail-safe for case ' . $label
    );
    supcheckout_cert_assert(
        $gateway->getAPIUrl('provider-mode-sentinel') === $expected_base . 'provider-mode-sentinel',
        'Provider endpoint routing is fail-safe for case ' . $label
    );
}

supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $original_settings);
supcheckout_cert_note('Provider environment fail-safe certification complete');
