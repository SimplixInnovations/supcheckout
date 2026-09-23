<?php
/**
 * Real-runtime subscription eligibility and pre-dispatch certification.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;
use Simplixi\SUPCheckout\Subscription\Presentation;
use UPayments\Token\CustomerTokenIdentity;

supcheckout_cert_assert(class_exists(CheckoutOrchestrator::class), 'checkout orchestrator is loaded for subscription certification');
Presentation::register_product_class();
supcheckout_cert_assert(class_exists('WCProductCustomType'), 'subscription product class is available in real WooCommerce');

if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}

function supcheckout_cert_subscription_product($name, $restricted) {
    $product = new WCProductCustomType();
    $product->set_name($name);
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $product_id = $product->save();
    supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'subscription certification product persists: ' . $name);
    if ($restricted) {
        update_post_meta($product_id, '_upay_disable_subscription', 'yes');
    }
    return $product;
}

function supcheckout_cert_normal_product($name) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('5.00');
    $product->set_price('5.00');
    $product_id = $product->save();
    supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'normal certification product persists: ' . $name);
    return $product;
}

function supcheckout_cert_subscription_order($products, $user_id) {
    $order = wc_create_order(array('customer_id' => $user_id));
    supcheckout_cert_assert($order instanceof WC_Order, 'subscription certification order is created');
    foreach ($products as $product) {
        $order->add_product($product, 1);
    }
    $order->set_payment_method('upayments');
    $order->set_billing_first_name('Certification');
    $order->set_billing_last_name('User');
    $order->set_billing_email('subscription@example.invalid');
    $order->set_billing_phone('50000000');
    $order->set_billing_country('KW');
    $order->set_currency('KWD');
    $order->calculate_totals();
    $order->save();
    return $order;
}

function supcheckout_cert_degrade_product_descriptor($order, $product_id) {
    $matched = false;
    foreach ($order->get_items('line_item') as $line) {
        if (! $line instanceof WC_Order_Item_Product || (int) $line->get_product_id() !== (int) $product_id) {
            continue;
        }
        $line->set_quantity(3);
        $line->set_subtotal('10.000');
        $line->set_total('10.000');
        $line->save();
        $matched = true;
        break;
    }
    supcheckout_cert_assert($matched, 'descriptor-degradation fixture finds the intended order line');
    $order->calculate_totals(false);
    $order->save();
    supcheckout_cert_assert((float) $order->get_total() > 0, 'descriptor-degradation fixture retains positive finalized Woo economics');
}

function supcheckout_cert_subscription_gateway() {
    $gateway = new WC_Upayments();
    $gateway->domain = 'upayments';
    $gateway->apiKey = 'certification-api-key';
    $gateway->testMode = 'yes';
    $gateway->autoDeduction = 'yes';
    $gateway->saveCardEnabled = 'yes';
    $gateway->multiMerchant = 'no';
    $gateway->paymentData = array(
        'whitelabled' => true,
        'payment' => array('cc' => 'Credit Card'),
    );
    return $gateway;
}

function supcheckout_cert_run_subscription_case($order, $post, $user_id, &$routes) {
    wp_set_current_user($user_id);
    wc_clear_notices();
    $_POST = $post;
    $gateway = supcheckout_cert_subscription_gateway();
    $routes = array();

    $orchestrator = new CheckoutOrchestrator(
        $gateway,
        function () {
            return '';
        },
        function ($route, $method, $body = null) use (&$routes) {
            $routes[] = $route;
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

delete_option(CustomerTokenIdentity::SECRET_OPTION);

$user_id = wp_insert_user(array(
    'user_login' => 'supcheckout-cert-sub-' . wp_generate_password(12, false, false),
    'user_pass'  => wp_generate_password(24, true, true),
    'user_email' => 'subscription-' . wp_generate_password(8, false, false) . '@example.invalid',
));
supcheckout_cert_assert(!is_wp_error($user_id) && (int) $user_id > 0, 'subscription certification user is created');
$user_id = (int) $user_id;

$subscription = supcheckout_cert_subscription_product('Subscription Product', false);
$restricted = supcheckout_cert_subscription_product('Restricted Subscription Product', true);
$normal = supcheckout_cert_normal_product('Normal Product');

$base_post = array(
    'payment_method' => 'upayments',
    'upayment_payment_type' => 'cc',
    'save_card' => '1',
    'upay_subscription_plan' => 'monthly',
    'upay_subscription_interval' => '1',
);

$restricted_order = supcheckout_cert_subscription_order(array($restricted), $user_id);
$routes = array();
$result = supcheckout_cert_run_subscription_case($restricted_order, $base_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'product-level subscription opt-out rejects subscription checkout');
supcheckout_cert_assert(array() === $routes, 'product-level opt-out rejects before any provider transport');

$mixed_order = supcheckout_cert_subscription_order(array($subscription, $normal), $user_id);
$routes = array();
$result = supcheckout_cert_run_subscription_case($mixed_order, $base_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'mixed subscription/normal order is rejected');
supcheckout_cert_assert(array() === $routes, 'mixed-order rejection occurs before any provider transport');

$guest_order = supcheckout_cert_subscription_order(array($subscription), 0);
$routes = array();
$result = supcheckout_cert_run_subscription_case($guest_order, $base_post, 0, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'guest subscription checkout is rejected');
supcheckout_cert_assert(array() === $routes, 'guest subscription rejection occurs before token or Charge transport');

$invalid_plan_post = $base_post;
$invalid_plan_post['upay_subscription_plan'] = 'monthly ';
$strict_order = supcheckout_cert_subscription_order(array($subscription), $user_id);
$routes = array();
$result = supcheckout_cert_run_subscription_case($strict_order, $invalid_plan_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'whitespace-mutated subscription plan is rejected');
supcheckout_cert_assert(array() === $routes, 'invalid subscription plan rejects before provider transport');

$invalid_interval_post = $base_post;
$invalid_interval_post['upay_subscription_interval'] = '4';
$routes = array();
$result = supcheckout_cert_run_subscription_case($strict_order, $invalid_interval_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'out-of-contract subscription interval is rejected');
supcheckout_cert_assert(array() === $routes, 'invalid subscription interval rejects before provider transport');

$bootstrap_history = CustomerTokenIdentity::inspect_bootstrap_history($user_id);
supcheckout_cert_assert(
    CustomerTokenIdentity::HISTORY_NONE === $bootstrap_history['classification'],
    'clean subscription customer is eligible for token bootstrap before the positive checkout probe; reason='
        . (isset($bootstrap_history['reason']) ? $bootstrap_history['reason'] : 'missing')
);

$routes = array();
$result = supcheckout_cert_run_subscription_case($strict_order, $base_post, $user_id, $routes);
supcheckout_cert_note('eligible subscription bounded route trace: ' . wp_json_encode($routes));
supcheckout_cert_note('eligible subscription notices: ' . wp_json_encode(wc_get_notices()));
supcheckout_cert_assert('failure' === $result['result'], 'eligible subscription remains failed when bounded token transport is deliberately unavailable');
supcheckout_cert_assert(
    array('create-customer-unique-token') === $routes,
    'eligible Classic subscription reaches token initialization only after all local preflight gates pass; actual=' . wp_json_encode($routes)
);

// E2 safety invariant: an unrepresentable products[] descriptor must not erase the
// authoritative custom subscription classification. A valid subscription still reaches
// token initialization after its local subscription gates pass.
$degraded_subscription_order = supcheckout_cert_subscription_order(array($subscription), $user_id);
supcheckout_cert_degrade_product_descriptor($degraded_subscription_order, $subscription->get_id());
$routes = array();
$result = supcheckout_cert_run_subscription_case($degraded_subscription_order, $base_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'descriptor-degraded valid subscription remains bounded by unavailable token transport');
supcheckout_cert_assert(
    array('create-customer-unique-token') === $routes,
    'descriptor degradation preserves subscription classification and reaches only token initialization'
);

// Product-level opt-out is safety authority independent of products[] serialization.
$degraded_restricted_order = supcheckout_cert_subscription_order(array($restricted), $user_id);
supcheckout_cert_degrade_product_descriptor($degraded_restricted_order, $restricted->get_id());
$routes = array();
$result = supcheckout_cert_run_subscription_case($degraded_restricted_order, $base_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'descriptor-degraded restricted subscription remains rejected');
supcheckout_cert_assert(array() === $routes, 'descriptor degradation cannot bypass product-level subscription opt-out');

// Mixed custom/normal composition remains prohibited even when the custom line cannot
// be represented in provider products[]. Descriptor degradation cannot erase composition.
$degraded_mixed_order = supcheckout_cert_subscription_order(array($subscription, $normal), $user_id);
supcheckout_cert_degrade_product_descriptor($degraded_mixed_order, $subscription->get_id());
$routes = array();
$result = supcheckout_cert_run_subscription_case($degraded_mixed_order, $base_post, $user_id, $routes);
supcheckout_cert_assert('failure' === $result['result'], 'descriptor-degraded mixed subscription/normal order remains rejected');
supcheckout_cert_assert(array() === $routes, 'descriptor degradation cannot bypass mixed-order rejection');

wp_set_current_user(0);
foreach (array(
    $restricted_order,
    $mixed_order,
    $guest_order,
    $strict_order,
    $degraded_subscription_order,
    $degraded_restricted_order,
    $degraded_mixed_order,
) as $order) {
    $order->delete(true);
}
wp_delete_post($subscription->get_id(), true);
wp_delete_post($restricted->get_id(), true);
wp_delete_post($normal->get_id(), true);
wp_delete_user($user_id);
delete_option(CustomerTokenIdentity::SECRET_OPTION);
$_POST = array();
wc_clear_notices();

supcheckout_cert_note('subscription pre-dispatch runtime certification complete');
