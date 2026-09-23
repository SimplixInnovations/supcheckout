<?php
/**
 * Real WooCommerce Classic + Blocks payment-method registration/availability certification.
 */

require_once __DIR__ . '/bootstrap.php';

supcheckout_cert_assert(
    class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry'),
    'WooCommerce Blocks PaymentMethodRegistry is available'
);
supcheckout_cert_assert(
    did_action('woocommerce_blocks_loaded') > 0,
    'WooCommerce Blocks loaded hook fired in the real runtime'
);
supcheckout_cert_assert(
    false !== has_action('woocommerce_blocks_payment_method_type_registration'),
    'SUPCheckout registered a server-side Blocks payment-method callback'
);

$original_settings = get_option('woocommerce_upayments_settings');
$original_currency = get_option('woocommerce_currency');

$cases = array(
    'configured-enabled' => array(
        'settings' => array('enabled' => 'yes', 'api_key' => 'certification-key'),
        'active'   => true,
    ),
    'configured-default-enabled' => array(
        'settings' => array('api_key' => 'certification-key'),
        'active'   => true,
    ),
    'disabled' => array(
        'settings' => array('enabled' => 'no', 'api_key' => 'certification-key'),
        'active'   => false,
    ),
    'fresh-unconfigured' => array(
        'settings' => array(),
        'active'   => false,
    ),
    'missing-api-key' => array(
        'settings' => array('enabled' => 'yes'),
        'active'   => false,
    ),
    'blank-api-key' => array(
        'settings' => array('enabled' => 'yes', 'api_key' => '   '),
        'active'   => false,
    ),
    'malformed-object' => array(
        'settings' => (object) array('enabled' => 'yes', 'api_key' => 'certification-key'),
        'active'   => false,
    ),
);

foreach ($cases as $label => $case) {
    supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $case['settings']);

    $registry = new Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry();
    $registry->initialize();

    supcheckout_cert_assert($registry->is_registered('upayments'), 'Blocks registry contains upayments for case ' . $label);

    $integration = $registry->get_registered('upayments');
    supcheckout_cert_assert($integration instanceof WCGatewayUPaymentsBlocks, 'Blocks registry returns SUPCheckout integration for case ' . $label);
    supcheckout_cert_assert($integration->is_active() === $case['active'], 'Blocks availability is exact for case ' . $label);
    supcheckout_cert_assert(
        array('products') === $integration->get_supported_features(),
        'Blocks supported-feature contract remains products-only for case ' . $label
    );
}

// E1: Classic availability must own the same deterministic local configuration
// boundary as Blocks. This assertion calls WC_Upayments::is_available() directly
// so admin-ajax/custom checkout code cannot depend on the outer
// woocommerce_available_payment_gateways filter having executed first.
$classic_cases = array(
    'configured-supported-currency' => array(
        'settings'  => array('enabled' => 'yes', 'api_key' => 'certification-key'),
        'currency'  => 'KWD',
        'available' => true,
    ),
    'disabled' => array(
        'settings'  => array('enabled' => 'no', 'api_key' => 'certification-key'),
        'currency'  => 'KWD',
        'available' => false,
    ),
    'missing-api-key' => array(
        'settings'  => array('enabled' => 'yes'),
        'currency'  => 'KWD',
        'available' => false,
    ),
    'blank-api-key' => array(
        'settings'  => array('enabled' => 'yes', 'api_key' => '   '),
        'currency'  => 'KWD',
        'available' => false,
    ),
    'unsupported-currency' => array(
        'settings'  => array('enabled' => 'yes', 'api_key' => 'certification-key'),
        'currency'  => 'JPY',
        'available' => false,
    ),
);

foreach ($classic_cases as $label => $case) {
    supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $case['settings']);
    update_option('woocommerce_currency', $case['currency'], false);
    wp_cache_delete('woocommerce_currency', 'options');

    $gateway = new WC_Upayments();
    supcheckout_cert_assert(
        $gateway->is_available() === $case['available'],
        'Classic gateway availability is exact for case ' . $label
    );
}

// WooCommerce gateway presentation must honor the merchant's configured title.
// This is an observable storefront contract and is intentionally characterized
// independently from provider mode or payment-state behavior.
supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array(
        'enabled'   => 'yes',
        'api_key'   => 'certification-key',
        'title'     => 'Configured SUPCheckout title',
        'test_mode' => 'no',
    )
);
$title_gateway = new WC_Upayments();
supcheckout_cert_assert(
    $title_gateway->get_title() === 'Configured SUPCheckout title',
    'Classic gateway exposes the merchant-configured checkout title'
);

// Preserve the inherited WooCommerce availability contract as SUPCheckout adds
// local API-key/currency eligibility. In particular, a gateway-level maximum
// transaction amount must still suppress availability when a cart exists.
if (!class_exists('SUPCheckoutAvailabilityLimitProbeGateway', false)) {
    class SUPCheckoutAvailabilityLimitProbeGateway extends WC_Upayments {
        protected function get_order_total() {
            return 200.0;
        }
    }
}

supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array('enabled' => 'yes', 'api_key' => 'certification-key')
);
update_option('woocommerce_currency', 'KWD', false);
wp_cache_delete('woocommerce_currency', 'options');

$original_cart = WC()->cart;
if (!$original_cart) {
    WC()->cart = new WC_Cart();
}
$limit_gateway = new SUPCheckoutAvailabilityLimitProbeGateway();
$limit_gateway->max_amount = 100;
supcheckout_cert_assert(
    $limit_gateway->is_available() === false,
    'Classic gateway preserves WooCommerce inherited max-amount availability'
);
WC()->cart = $original_cart;

// The public available-gateways filter is also called by custom integrations
// outside the normal checkout rendering path. A valid gateway must remain
// filterable when WooCommerce has not initialized a customer session; policy
// that requires session state must simply be skipped rather than fataling.
$original_session = WC()->session;
WC()->session = null;
$sessionless_gateways = array(
    'upayments' => (object) array('id' => 'upayments'),
    'cod'       => (object) array('id' => 'cod'),
);
$sessionless_result = enableUpaymentsGateway($sessionless_gateways);
supcheckout_cert_assert(
    isset($sessionless_result['upayments']),
    'Classic available-gateways filter preserves eligible UPayments without a WooCommerce session'
);
supcheckout_cert_assert(
    isset($sessionless_result['cod']),
    'Classic available-gateways filter does not mutate unrelated gateways without a WooCommerce session'
);
WC()->session = $original_session;

// Registry inspection outside checkout must be observational. A session-backed
// account/custom integration that checks available gateways must not erase the
// customer's already chosen checkout payment method as a side effect.
supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array(
        'enabled'              => 'yes',
        'api_key'              => 'certification-key',
        'make_default_gateway' => 'no',
    )
);
supcheckout_cert_assert(!is_checkout(), 'Session-isolation fixture executes outside checkout context');
if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
WC()->session->set('chosen_payment_method', 'upayments');
$non_checkout_gateways = array(
    'upayments' => (object) array('id' => 'upayments'),
    'cod'       => (object) array('id' => 'cod'),
);
$non_checkout_result = enableUpaymentsGateway($non_checkout_gateways);
supcheckout_cert_assert(
    isset($non_checkout_result['upayments']),
    'Non-checkout availability inspection preserves eligible UPayments'
);
supcheckout_cert_assert(
    WC()->session->get('chosen_payment_method') === 'upayments',
    'Non-checkout availability inspection does not mutate chosen payment method'
);
WC()->session = $original_session;

// Provider availability is one participant in WooCommerce checkout validation,
// not the owner of the shared notice queue. A deterministic live-mode cooldown
// produces the same gateway failure result without provider I/O; pre-existing
// notices from shipping/tax/address/fraud/funnel integrations must survive it.
$rate_gate_missing = '__supcheckout_rate_gate_missing__';
$original_rate_gate = get_option('upayments_payment_methods_rate_gate_live', $rate_gate_missing);
$notice_original_session = WC()->session;
if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array(
        'enabled'   => 'yes',
        'api_key'   => 'notice-isolation-certification-key',
        'test_mode' => 'no',
    )
);
$notice_gateway = new WC_Upayments();
supcheckout_cert_assert($notice_gateway->getMode() === false, 'Notice-isolation fixture uses live availability scope');
update_option('upayments_payment_methods_rate_gate_live', time() + 65, false);
wc_clear_notices();
$foreign_notice = 'Third-party checkout validation sentinel.';
wc_add_notice($foreign_notice, 'error');
$notice_result = $notice_gateway->getUpayPaymentMethods();
$notice_errors = wc_get_notices('error');
$notice_messages = array();
foreach ($notice_errors as $notice_error) {
    if (is_array($notice_error) && isset($notice_error['notice']) && is_string($notice_error['notice'])) {
        $notice_messages[] = $notice_error['notice'];
    }
}
wc_clear_notices();
if ($original_rate_gate === $rate_gate_missing) {
    delete_option('upayments_payment_methods_rate_gate_live');
} else {
    update_option('upayments_payment_methods_rate_gate_live', $original_rate_gate, false);
}
WC()->session = $notice_original_session;

supcheckout_cert_assert(
    is_array($notice_result)
        && isset($notice_result['result'])
        && $notice_result['result'] === 'failure',
    'Provider-method cooldown yields deterministic gateway availability failure'
);
supcheckout_cert_assert(
    in_array(__('Payment methods could not be loaded. Please try again.', 'supcheckout'), $notice_messages, true),
    'Provider availability failure still reports the SUPCheckout checkout error'
);
supcheckout_cert_assert(
    in_array($foreign_notice, $notice_messages, true),
    'Provider availability failure preserves pre-existing third-party WooCommerce notices'
);

supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $original_settings);
update_option('woocommerce_currency', $original_currency, false);
wp_cache_delete('woocommerce_currency', 'options');
supcheckout_cert_note('Classic and Blocks registration/availability certification complete');
