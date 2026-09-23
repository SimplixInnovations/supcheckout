<?php
/**
 * Real-runtime single additional-merchant allocation certification.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;

if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}

function supcheckout_cert_mm_order() {
    $product = new WC_Product_Simple();
    $product->set_name('Multi-merchant Certification Product');
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $product_id = $product->save();
    supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'multi-merchant certification product persists');

    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'multi-merchant certification order is created');
    $order->add_product($product, 1);
    $order->set_payment_method('upayments');
    $order->set_billing_first_name('Certification');
    $order->set_billing_last_name('Merchant');
    $order->set_billing_email('merchant@example.invalid');
    $order->set_billing_phone('50000000');
    $order->set_billing_country('KW');
    $order->set_currency('KWD');
    $order->calculate_totals();
    $order->save();

    return array($order, $product);
}

function supcheckout_cert_mm_gateway($iban) {
    $gateway = new WC_Upayments();
    $gateway->domain = 'upayments';
    $gateway->apiKey = 'certification-api-key';
    $gateway->testMode = 'yes';
    $gateway->autoDeduction = 'no';
    $gateway->saveCardEnabled = 'yes';
    $gateway->paymentData = array('whitelabled' => false, 'payment' => array());
    $gateway->multiMerchant = 'yes';
    $gateway->ibanNumber = $iban;
    $gateway->knetCharge = '0.900';
    $gateway->knetChargeType = 'fixed';
    $gateway->ccCharge = '0.750';
    $gateway->ccChargeType = 'percentage';
    return $gateway;
}

function supcheckout_cert_run_mm($order, $gateway, &$calls) {
    $_POST = array();
    wc_clear_notices();
    $calls = array();

    $orchestrator = new CheckoutOrchestrator(
        $gateway,
        function () {
            return '';
        },
        function ($route, $method, $body = null) use (&$calls) {
            $calls[] = array('route' => $route, 'method' => $method, 'body' => $body);
            return array(
                'transport_ok' => false,
                'http_status' => 0,
                'curl_errno' => 7,
                'body' => '',
            );
        }
    );

    return $orchestrator->process($order->get_id());
}

list($order, $product) = supcheckout_cert_mm_order();

$calls = array();
$gateway = supcheckout_cert_mm_gateway('KW81CBKU0000000000001234560101');
$result = supcheckout_cert_run_mm($order, $gateway, $calls);
supcheckout_cert_assert('failure' === $result['result'], 'bounded multi-merchant probe fails after deliberately unavailable Charge transport');
supcheckout_cert_assert(1 === count($calls), 'valid single-allocation configuration reaches exactly one provider request');
supcheckout_cert_assert('charge' === $calls[0]['route'], 'valid single-allocation configuration reaches only Charge');
supcheckout_cert_assert('POST' === $calls[0]['method'], 'multi-merchant Charge uses POST');
supcheckout_cert_assert(is_string($calls[0]['body']) && '' !== $calls[0]['body'], 'multi-merchant Charge body is captured for runtime inspection');

$payload = json_decode($calls[0]['body'], true);
supcheckout_cert_assert(is_array($payload), 'multi-merchant provider payload is valid JSON');
supcheckout_cert_assert(
    isset($payload['extraMerchantData']) && is_array($payload['extraMerchantData']) && 1 === count($payload['extraMerchantData']),
    'runtime payload contains exactly one additional-merchant allocation'
);
$allocation = $payload['extraMerchantData'][0];
supcheckout_cert_assert('KW81CBKU0000000000001234560101' === $allocation['ibanNumber'], 'additional-merchant IBAN is exact');
supcheckout_cert_assert('fixed' === $allocation['knetChargeType'], 'KNET charge type is exact');
supcheckout_cert_assert('percentage' === $allocation['ccChargeType'], 'credit-card charge type is exact');
supcheckout_cert_assert(
    isset($payload['order']['amount'], $allocation['amount']) && $payload['order']['amount'] === $allocation['amount'],
    'single additional-merchant allocation amount equals the exact order amount'
);

// R1 provider contract: main-merchant commission may be zero. Preserve the
// exact lexical decimal token in provider JSON without converting through a
// float, while the order amount remains strictly positive.
$calls = array();
$zero_gateway = supcheckout_cert_mm_gateway('KW81CBKU0000000000001234560101');
$zero_gateway->knetCharge = '0';
$zero_gateway->ccCharge = '0.000';
$result = supcheckout_cert_run_mm($order, $zero_gateway, $calls);
supcheckout_cert_assert('failure' === $result['result'], 'zero-commission probe fails only after deliberately unavailable Charge transport');
supcheckout_cert_assert(1 === count($calls), 'provider-permitted zero commission reaches exactly one Charge request');
supcheckout_cert_assert(
    1 === preg_match('/"knetCharge":0(?=[,}])/', $calls[0]['body']),
    'zero KNET commission is serialized as an unquoted JSON number'
);
supcheckout_cert_assert(
    1 === preg_match('/"ccCharge":0\.000(?=[,}])/', $calls[0]['body']),
    'zero credit-card commission preserves exact unquoted decimal token'
);

$calls = array();
$invalid_gateway = supcheckout_cert_mm_gateway('invalid iban');
$result = supcheckout_cert_run_mm($order, $invalid_gateway, $calls);
supcheckout_cert_assert('failure' === $result['result'], 'invalid multi-merchant configuration fails closed');
supcheckout_cert_assert(array() === $calls, 'invalid multi-merchant configuration rejects before provider transport');

// R1 settings persistence contract: an enabled additional-merchant allocation
// is atomic. A validation error must not partially rewrite the gateway option.
// Use WooCommerce's public Settings API set_post_data() so this exercises the
// real gateway save method without relying on ambient WP-CLI $_POST state.
if (!class_exists('WC_Admin_Settings', false)) {
    require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
}

$settings_key = 'woocommerce_upayments_settings';
$settings_before_admin_probe = get_option($settings_key);
$stable_settings = array(
    'enabled'              => 'yes',
    'title'                => 'Stable UPayments Title',
    'api_key'              => 'stable-certification-key',
    'enable_multimerchant' => 'yes',
    'iban_number'          => 'KW81CBKU0000000000001234560101',
    'cc_charge'            => '0.750',
    'cc_charge_type'       => 'percentage',
    'knet_charge'          => '0.900',
    'knet_charge_type'     => 'fixed',
);
supcheckout_cert_store_option_raw($settings_key, $stable_settings);

$admin_gateway = new WC_Upayments();
$invalid_post = array(
    'woocommerce_upayments_enabled'              => '1',
    'woocommerce_upayments_title'                => 'MUTATED TITLE MUST NOT PERSIST',
    'woocommerce_upayments_api_key'              => 'new-certification-key',
    'woocommerce_upayments_enable_multimerchant' => '1',
    'woocommerce_upayments_iban_number'          => '',
    'woocommerce_upayments_cc_charge'            => '1.000',
    'woocommerce_upayments_cc_charge_type'       => 'fixed',
    'woocommerce_upayments_knet_charge'          => '2.000',
    'woocommerce_upayments_knet_charge_type'     => 'percentage',
);
$admin_gateway->set_post_data($invalid_post);
$admin_save_result = $admin_gateway->process_admin_options();
$settings_after_invalid_admin_save = get_option($settings_key);

supcheckout_cert_assert(
    false === $admin_save_result,
    'incomplete enabled multi-merchant settings report an unsuccessful save'
);
supcheckout_cert_assert(
    $stable_settings === $settings_after_invalid_admin_save,
    'incomplete enabled multi-merchant settings leave persisted gateway configuration byte-equivalent'
);

// A present but noncanonical checkbox token is dangerous because WooCommerce's
// checkbox validator treats any non-null submitted value as enabled. SUPCheckout
// must reject this request before parent persistence rather than clear the
// allocation fields and later persist an enabled-but-empty configuration.
$malformed_checkbox_post = array(
    'woocommerce_upayments_enabled'              => '1',
    'woocommerce_upayments_title'                => 'MUTATED TITLE MUST NOT PERSIST',
    'woocommerce_upayments_api_key'              => 'new-certification-key',
    'woocommerce_upayments_enable_multimerchant' => '0',
    'woocommerce_upayments_iban_number'          => 'KW81CBKU0000000000001234560101',
    'woocommerce_upayments_cc_charge'            => '1.000',
    'woocommerce_upayments_cc_charge_type'       => 'fixed',
    'woocommerce_upayments_knet_charge'          => '2.000',
    'woocommerce_upayments_knet_charge_type'     => 'percentage',
);
$admin_gateway->set_post_data($malformed_checkbox_post);
$malformed_checkbox_save_result = $admin_gateway->process_admin_options();
$settings_after_malformed_checkbox_save = get_option($settings_key);

supcheckout_cert_assert(
    false === $malformed_checkbox_save_result,
    'noncanonical present multi-merchant checkbox token reports an unsuccessful save'
);
supcheckout_cert_assert(
    $stable_settings === $settings_after_malformed_checkbox_save,
    'noncanonical present checkbox token cannot mutate stable persisted gateway configuration'
);

supcheckout_cert_store_option_raw($settings_key, $settings_before_admin_probe);
$order->delete(true);
wp_delete_post($product->get_id(), true);
$_POST = array();
wc_clear_notices();

supcheckout_cert_note('single additional-merchant runtime certification complete');
