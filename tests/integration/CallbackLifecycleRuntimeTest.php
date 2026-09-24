<?php
/**
 * Real-Woo R5 callback lifecycle certification (no live provider).
 *
 * Proves canonical financial persistence on real WC_Order objects. Termination
 * and REQUEST-only adapter shape remain owned by the T3 child-process harness.
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
    // StatusVerifier uses WP HTTP (wp_remote_get). Stub via pre_http_request —
    // never contacts a live provider.
    $GLOBALS['supcheckout_r5_result'] = $result;
    $GLOBALS['supcheckout_r5_requested'] = (string) $order->get_meta('UPayments_order_id');
    $GLOBALS['supcheckout_r5_order_id'] = (int) $order->get_id();

    add_filter('pre_http_request', function ($preempt, $args, $url) {
        $tx = array(
            'result' => $GLOBALS['supcheckout_r5_result'],
            'track_id' => 'track-r5',
            'merchant_requested_order_id' => $GLOBALS['supcheckout_r5_requested'],
            'total_price' => '10.000',
            'currency_type' => 'KWD',
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

    $gateway = WC()->payment_gateways()->payment_gateways()['upayments'] ?? null;
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

// CAPTURED
$order = supcheckout_r5_make_order($user_id, 'cap');
$outcome = supcheckout_r5_process($order, 'CAPTURED');
supcheckout_cert_note('captured outcome=' . json_encode($outcome));
$fresh = wc_get_order($order->get_id());
supcheckout_cert_assert($fresh instanceof WC_Order, 'CAPTURED order reloads');
if ($fresh instanceof WC_Order) {
    supcheckout_cert_assert(
        $fresh->has_status(array('processing', 'completed')) || $fresh->is_paid(),
        'CAPTURED yields Woo paid state on real order'
    );
    $txn = (string) $fresh->get_transaction_id();
    supcheckout_cert_note('transaction_id=' . $txn);
    $result_meta = (string) $fresh->get_meta('UPayments_Result');
    $payment_id = (string) $fresh->get_meta('UPayments_PaymentID');
    $track = (string) $fresh->get_meta('UPayments_TrackID');
    $ref = (string) $fresh->get_meta('UPayments_Ref');
    $verified = (string) $fresh->get_meta('_upay_verified_capture');
    supcheckout_cert_note('meta result=' . $result_meta . ' payment=' . $payment_id . ' track=' . $track . ' ref=' . $ref . ' verified=' . $verified);
    supcheckout_cert_assert($payment_id === 'pay-r5-1' || $txn === 'pay-r5-1', 'payment identity persisted');
    supcheckout_cert_assert($verified === '1' || $result_meta === 'CAPTURED', 'capture evidence persisted');
}

// Duplicate CAPTURED
if ($fresh instanceof WC_Order) {
    $before_status = $fresh->get_status();
    $before_txn = (string) $fresh->get_transaction_id();
    supcheckout_r5_process($fresh, 'CAPTURED');
    $again = wc_get_order($order->get_id());
    if ($again instanceof WC_Order) {
        supcheckout_cert_assert(
            (string) $again->get_transaction_id() === $before_txn,
            'duplicate CAPTURED keeps transaction identity'
        );
        supcheckout_cert_assert(
            !$again->has_status('failed') && !$again->has_status('cancelled'),
            'duplicate CAPTURED does not demote paid state'
        );
    }
}

// FAILED on unpaid
$failed = supcheckout_r5_make_order($user_id, 'fail');
$failed->update_status('pending');
$failed->save();
$outcome = supcheckout_r5_process($failed, 'FAILED');
supcheckout_cert_note('failed outcome=' . json_encode($outcome));
$fresh_failed = wc_get_order($failed->get_id());
if ($fresh_failed instanceof WC_Order) {
    supcheckout_cert_assert(
        !$fresh_failed->is_paid() && (string) $fresh_failed->get_meta('_upay_verified_capture') !== '1',
        'FAILED does not mark paid or verified capture'
    );
}

// CANCELED on unpaid
$cancelled = supcheckout_r5_make_order($user_id, 'can');
$cancelled->update_status('pending');
$cancelled->save();
$outcome = supcheckout_r5_process($cancelled, 'CANCELED');
supcheckout_cert_note('canceled outcome=' . json_encode($outcome));
$fresh_can = wc_get_order($cancelled->get_id());
if ($fresh_can instanceof WC_Order) {
    supcheckout_cert_assert(
        !$fresh_can->is_paid() && (string) $fresh_can->get_meta('_upay_verified_capture') !== '1',
        'CANCELED does not mark paid or verified capture'
    );
}

// PENDING
$pending = supcheckout_r5_make_order($user_id, 'pend');
supcheckout_r5_process($pending, 'PENDING');
$fresh_pend = wc_get_order($pending->get_id());
if ($fresh_pend instanceof WC_Order) {
    supcheckout_cert_assert(
        !$fresh_pend->is_paid() && (string) $fresh_pend->get_meta('_upay_verified_capture') !== '1',
        'PENDING remains unpaid without verified capture'
    );
}

// Refunded protection
$refunded = supcheckout_r5_make_order($user_id, 'ref');
$refunded->update_status('refunded');
$refunded->save();
supcheckout_r5_process($refunded, 'CAPTURED');
$fresh_ref = wc_get_order($refunded->get_id());
if ($fresh_ref instanceof WC_Order) {
    supcheckout_cert_assert($fresh_ref->has_status('refunded'), 'refunded order is never resurrected');
}

wp_set_current_user(0);
foreach (array($order, $failed, $cancelled, $pending, $refunded) as $o) {
    if ($o instanceof WC_Order) {
        $o->delete(true);
    }
}
wp_delete_user($user_id);

supcheckout_cert_note('R5 real-Woo callback lifecycle certification complete');
echo "R5 Callback Lifecycle Runtime Certification: PASS\n";
