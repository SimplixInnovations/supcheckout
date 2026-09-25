<?php
/**
 * Real-Woo R5 callback lifecycle certification (no live provider).
 *
 * Exact postcondition assertions — no OR shortcuts. Termination and
 * REQUEST-only adapter shape remain owned by the T3 child-process harness.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Payment\PaymentLifecycle;

if (!class_exists('WC_Upayments')) {
    require_once dirname(__DIR__, 2) . '/UPayments.php';
    woocommerceUpaymentsInit();
}

function supcheckout_r5_make_order($user_id, $suffix = '1') {
    $order = wc_create_order(array('customer_id' => $user_id));
    $order->set_payment_method('upayments');
    $order->set_billing_email('r5-' . $suffix . '@example.invalid');
    $order->set_currency('KWD');
    $order->calculate_totals();
    $order->update_meta_data('UPayments_order_id', 'merchant-r5-' . $suffix);
    $order->update_status('pending');
    $order->save();
    $fresh = wc_get_order($order->get_id());
    return $fresh instanceof WC_Order ? $fresh : $order;
}

function supcheckout_r5_process($order, $result) {
    $GLOBALS['supcheckout_r5_result'] = $result;
    $GLOBALS['supcheckout_r5_requested'] = (string) $order->get_meta('UPayments_order_id');
    $GLOBALS['supcheckout_r5_order_id'] = (int) $order->get_id();
    $GLOBALS['supcheckout_r5_amount'] = wc_format_decimal($order->get_total(), 3);
    $GLOBALS['supcheckout_r5_currency'] = (string) $order->get_currency();

    add_filter('pre_http_request', function ($preempt, $args, $url) {
        $tx = array(
            'result' => $GLOBALS['supcheckout_r5_result'],
            'track_id' => 'track-r5',
            'merchant_requested_order_id' => $GLOBALS['supcheckout_r5_requested'],
            'total_price' => $GLOBALS['supcheckout_r5_amount'],
            'currency_type' => $GLOBALS['supcheckout_r5_currency'],
            'payment_id' => 'pay-r5-1',
            'payment_type' => 'cc',
            'reference' => (string) $GLOBALS['supcheckout_r5_order_id'],
        );
        return array(
            'headers' => array(),
            'body' => json_encode(array('status' => true, 'data' => array('transaction' => $tx))),
            'response' => array('code' => 201, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }, 10, 3);

    $gateway = null;
    if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
        $registry = WC()->payment_gateways();
        if (is_object($registry) && method_exists($registry, 'payment_gateways')) {
            $gateways = $registry->payment_gateways();
            if (isset($gateways['upayments'])) {
                $gateway = $gateways['upayments'];
            }
        }
    }
    if (!$gateway instanceof WC_Upayments) {
        $gateway = new WC_Upayments();
    }

    $m = new ReflectionMethod(PaymentLifecycle::class, 'process_order_status');
    return $m->invoke(null, $gateway, $order, 'track-r5', 'webhook');
}

$user_id = wp_insert_user(array(
    'user_login' => 'r5c-' . wp_generate_password(8, false, false),
    'user_pass'  => wp_generate_password(16, true, true),
    'user_email' => 'r5c-' . wp_generate_password(6, false, false) . '@example.invalid',
));
supcheckout_cert_assert(!is_wp_error($user_id), 'R5 user created');
$user_id = (int) $user_id;

$payment_complete_count = 0;
$captured_order_id = 0;
add_action('woocommerce_payment_complete', function ($oid) use (&$payment_complete_count, &$captured_order_id) {
    if ((int) $oid === (int) $captured_order_id) {
        $payment_complete_count++;
    }
}, 10, 1);

// --- CAPTURED exact postconditions ---
$order = supcheckout_r5_make_order($user_id, 'cap');
$order_id = (int) $order->get_id();
$captured_order_id = $order_id;
$outcome = supcheckout_r5_process($order, 'CAPTURED');
supcheckout_cert_note('captured outcome=' . json_encode($outcome));
supcheckout_cert_assert(is_array($outcome) && ($outcome['state'] ?? '') === 'captured', 'CAPTURED outcome.state=captured');
supcheckout_cert_assert(is_array($outcome) && ($outcome['reason'] ?? '') === 'captured', 'CAPTURED outcome.reason=captured');

$fresh = wc_get_order($order_id);
supcheckout_cert_assert($fresh instanceof WC_Order, 'CAPTURED order reloads');
if ($fresh instanceof WC_Order) {
    supcheckout_cert_assert($fresh->is_paid() || $fresh->has_status(array('processing', 'completed')), 'CAPTURED order is paid');
    supcheckout_cert_assert((string) $fresh->get_transaction_id() === 'pay-r5-1', 'transaction ID = pay-r5-1');
    supcheckout_cert_assert((string) $fresh->get_meta('UPayments_Result') === 'CAPTURED', 'UPayments_Result=CAPTURED');
    supcheckout_cert_assert((string) $fresh->get_meta('UPayments_PaymentID') === 'pay-r5-1', 'UPayments_PaymentID=pay-r5-1');
    supcheckout_cert_assert((string) $fresh->get_meta('UPayments_TrackID') === 'track-r5', 'UPayments_TrackID=track-r5');
    supcheckout_cert_assert((string) $fresh->get_meta('UPayments_Ref') === (string) $order_id, 'UPayments_Ref matches order reference');
    supcheckout_cert_assert((string) $fresh->get_meta('_upay_verified_capture') === '1', '_upay_verified_capture=1');
    supcheckout_cert_assert((string) $fresh->get_meta('UPayments_webhook_triggered') === '1', 'UPayments_webhook_triggered=1');
}
supcheckout_cert_assert($payment_complete_count === 1, 'payment_complete effect count = 1 after first CAPTURED');

// Duplicate CAPTURED
$before_status = $fresh->get_status();
$before_txn = (string) $fresh->get_transaction_id();
supcheckout_r5_process($fresh, 'CAPTURED');
$again = wc_get_order($order_id);
if ($again instanceof WC_Order) {
    supcheckout_cert_assert((string) $again->get_transaction_id() === $before_txn, 'duplicate CAPTURED keeps transaction identity');
    supcheckout_cert_assert($again->get_status() === $before_status, 'duplicate CAPTURED keeps paid status');
    supcheckout_cert_assert((string) $again->get_meta('_upay_verified_capture') === '1', 'duplicate CAPTURED keeps verified capture');
}
supcheckout_cert_assert($payment_complete_count === 1, 'payment_complete effect count remains 1 after duplicate CAPTURED');

// Post-capture FAILED / CANCELED protection
$outcome = supcheckout_r5_process(wc_get_order($order_id), 'FAILED');
$still = wc_get_order($order_id);
if ($still instanceof WC_Order) {
    supcheckout_cert_assert($still->is_paid() || $still->has_status(array('processing', 'completed')), 'post-capture FAILED remains paid');
    supcheckout_cert_assert((string) $still->get_transaction_id() === $before_txn, 'post-capture FAILED keeps transaction ID');
    supcheckout_cert_assert((string) $still->get_meta('_upay_verified_capture') === '1', 'post-capture FAILED remains verified');
    supcheckout_cert_assert(!$still->has_status('failed') && !$still->has_status('cancelled'), 'post-capture FAILED does not demote');
}
$outcome = supcheckout_r5_process(wc_get_order($order_id), 'CANCELED');
$still = wc_get_order($order_id);
if ($still instanceof WC_Order) {
    supcheckout_cert_assert($still->is_paid() || $still->has_status(array('processing', 'completed')), 'post-capture CANCELED remains paid');
    supcheckout_cert_assert((string) $still->get_meta('_upay_verified_capture') === '1', 'post-capture CANCELED remains verified');
}

// --- FAILED on unpaid ---
$failed = supcheckout_r5_make_order($user_id, 'fail');
$outcome = supcheckout_r5_process($failed, 'FAILED');
supcheckout_cert_note('failed outcome=' . json_encode($outcome));
supcheckout_cert_assert(is_array($outcome) && ($outcome['state'] ?? '') === 'failed', 'FAILED outcome.state=failed');
supcheckout_cert_assert(is_array($outcome) && ($outcome['reason'] ?? '') === 'provider_failed', 'FAILED outcome.reason=provider_failed');
$fresh_failed = wc_get_order($failed->get_id());
if ($fresh_failed instanceof WC_Order) {
    supcheckout_cert_assert($fresh_failed->has_status('failed'), 'FAILED persists Woo status failed');
    supcheckout_cert_assert(!$fresh_failed->is_paid(), 'FAILED is not paid');
    supcheckout_cert_assert((string) $fresh_failed->get_meta('_upay_verified_capture') !== '1', 'FAILED has no verified capture');
}

// --- CANCELED on unpaid ---
$cancelled = supcheckout_r5_make_order($user_id, 'can');
$outcome = supcheckout_r5_process($cancelled, 'CANCELED');
supcheckout_cert_note('canceled outcome=' . json_encode($outcome));
supcheckout_cert_assert(is_array($outcome) && ($outcome['state'] ?? '') === 'cancelled', 'CANCELED outcome.state=cancelled');
supcheckout_cert_assert(is_array($outcome) && ($outcome['reason'] ?? '') === 'provider_cancelled', 'CANCELED outcome.reason=provider_cancelled');
$fresh_can = wc_get_order($cancelled->get_id());
if ($fresh_can instanceof WC_Order) {
    supcheckout_cert_assert($fresh_can->has_status('cancelled'), 'CANCELED persists Woo status cancelled');
    supcheckout_cert_assert(!$fresh_can->is_paid(), 'CANCELED is not paid');
    supcheckout_cert_assert((string) $fresh_can->get_meta('_upay_verified_capture') !== '1', 'CANCELED has no verified capture');
}

// --- PENDING ---
$pending = supcheckout_r5_make_order($user_id, 'pend');
$outcome = supcheckout_r5_process($pending, 'PENDING');
supcheckout_cert_note('pending outcome=' . json_encode($outcome));
supcheckout_cert_assert(is_array($outcome) && ($outcome['state'] ?? '') === 'pending', 'PENDING outcome.state=pending');
supcheckout_cert_assert(is_array($outcome) && ($outcome['reason'] ?? '') === 'pending', 'PENDING outcome.reason=pending');
$fresh_pend = wc_get_order($pending->get_id());
if ($fresh_pend instanceof WC_Order) {
    supcheckout_cert_assert(!$fresh_pend->is_paid(), 'PENDING remains unpaid');
    supcheckout_cert_assert(!$fresh_pend->has_status('failed') && !$fresh_pend->has_status('cancelled'), 'PENDING is not terminal');
    supcheckout_cert_assert((string) $fresh_pend->get_meta('_upay_verified_capture') !== '1', 'PENDING has no verified capture');
}

// --- INDETERMINATE (unknown provider result) ---
$indet = supcheckout_r5_make_order($user_id, 'ind');
$outcome = supcheckout_r5_process($indet, 'FUTURE_UNKNOWN_RESULT');
supcheckout_cert_note('indeterminate outcome=' . json_encode($outcome));
supcheckout_cert_assert(is_array($outcome) && ($outcome['state'] ?? '') === 'pending', 'INDETERMINATE outcome.state=pending');
supcheckout_cert_assert(is_array($outcome) && ($outcome['reason'] ?? '') === 'indeterminate', 'INDETERMINATE outcome.reason=indeterminate');
$fresh_ind = wc_get_order($indet->get_id());
if ($fresh_ind instanceof WC_Order) {
    supcheckout_cert_assert(!$fresh_ind->is_paid(), 'INDETERMINATE remains unpaid');
    supcheckout_cert_assert(!$fresh_ind->has_status('failed') && !$fresh_ind->has_status('cancelled'), 'INDETERMINATE is not terminal');
    supcheckout_cert_assert((string) $fresh_ind->get_meta('_upay_verified_capture') !== '1', 'INDETERMINATE has no verified capture');
}

// --- Refunded protection: no identity/payment mutation ---
$refunded = supcheckout_r5_make_order($user_id, 'ref');
$refunded->update_status('refunded');
$refunded->save();
$ref_before_status = $refunded->get_status();
$ref_before_txn = (string) $refunded->get_transaction_id();
$ref_before_verified = (string) $refunded->get_meta('_upay_verified_capture');
$ref_before_result = (string) $refunded->get_meta('UPayments_Result');
$ref_before_payment = (string) $refunded->get_meta('UPayments_PaymentID');
$outcome = supcheckout_r5_process($refunded, 'CAPTURED');
$fresh_ref = wc_get_order($refunded->get_id());
if ($fresh_ref instanceof WC_Order) {
    supcheckout_cert_assert(
        $fresh_ref->get_status() === $ref_before_status,
        'refunded status unchanged'
    );
    supcheckout_cert_assert(
        (string) $fresh_ref->get_transaction_id() === $ref_before_txn,
        'refunded transaction ID unchanged'
    );
    supcheckout_cert_assert(
        (string) $fresh_ref->get_meta('UPayments_Result') === $ref_before_result,
        'refunded UPayments_Result unchanged'
    );
    supcheckout_cert_assert(
        (string) $fresh_ref->get_meta('UPayments_PaymentID') === $ref_before_payment,
        'refunded UPayments_PaymentID unchanged'
    );
    supcheckout_cert_assert(
        (string) $fresh_ref->get_meta('_upay_verified_capture') === $ref_before_verified,
        'refunded _upay_verified_capture unchanged'
    );
}

wp_set_current_user(0);
foreach (array($order, $failed, $cancelled, $pending, $indet, $refunded) as $o) {
    if ($o instanceof WC_Order) {
        $o->delete(true);
    }
}
wp_delete_user($user_id);

supcheckout_cert_note('R5 real-Woo callback lifecycle certification complete');
echo "R5 Callback Lifecycle Runtime Certification: PASS\n";
