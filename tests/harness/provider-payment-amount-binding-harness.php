<?php

namespace {
    // The stored order total (CPT `_order_total`), which every bind also requires (R-1).
    function get_post_meta($id, $key = '', $single = false) {
        return ((int) $id === 9001 && $key === '_order_total') ? $GLOBALS['amount_binding_persisted_total'] : '';
    }
}

namespace Simplixi\SUPCheckout\Payment {
    \define('ABSPATH', __DIR__ . '/');
    require_once __DIR__ . '/../../src/Takeover/TakeoverState.php';
    require_once __DIR__ . '/../../src/Takeover/Keys.php';
    require_once __DIR__ . '/../../src/Payment/ProviderResult.php';
    require_once __DIR__ . '/../../src/Payment/StatusVerifier.php';

    final class AmountBindingGateway {
        public function getCurrencyCode($currency) { return strtoupper((string) $currency); }
    }

    final class AmountBindingOrder {
        private $total;
        public function __construct($total) { $this->total = $total; }
        public function get_id() { return 9001; }
        public function get_currency() { return 'KWD'; }
        public function get_total() { return $this->total; }
        public function get_meta($key) { return $key === 'UPayments_order_id' ? 'merchant-9001' : ''; }
    }

    $GLOBALS['amount_binding_persisted_total'] = '10.00';

    $gateway = new AmountBindingGateway();
    $order = new AmountBindingOrder('10.00');
    $base = array(
        'result' => 'CAPTURED',
        'track_id' => 'track-exact',
        'merchant_requested_order_id' => 'merchant-9001',
        'total_price' => '10.00',
        'currency_type' => 'KWD',
        'reference' => '9001',
        'payment_id' => 'pay-exact',
    );

    $pass = 0;
    $fail = 0;
    $check = function ($condition, $label) use (&$pass, &$fail) {
        if ($condition) { $pass++; echo "PASS: {$label}\n"; }
        else { $fail++; echo "FAIL: {$label}\n"; }
    };

    $exact = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $base);
    $check(!empty($exact['bound']), 'exact amount binds');

    $trailing = $base;
    $trailing['total_price'] = '10.0000';
    $trailing_result = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $trailing);
    $check(!empty($trailing_result['bound']), 'trailing-zero equivalent binds');

    $fraction = $base;
    $fraction['total_price'] = '10.004';
    $fraction_result = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $fraction);
    $check(empty($fraction_result['bound']) && $fraction_result['reason'] === 'binding_amount', 'extra precision cannot round into equality');

    $different = $base;
    $different['total_price'] = '10.01';
    $different_result = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $different);
    $check(empty($different_result['bound']) && $different_result['reason'] === 'binding_amount', 'different amount rejected');

    // R-1: a request-rounded total can hide an edit made at higher precision.
    $GLOBALS['amount_binding_persisted_total'] = '10.004';
    $edited = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $base);
    $check(empty($edited['bound']) && $edited['reason'] === 'binding_amount', 'a persisted total edited beyond the request precision rejects the bind');

    $GLOBALS['amount_binding_persisted_total'] = '';
    $unreadable = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $base);
    $check(empty($unreadable['bound']) && $unreadable['reason'] === 'binding_persisted_total_unreadable', 'an unreadable persisted total fails closed with its own retryable reason');

    $GLOBALS['amount_binding_persisted_total'] = '10.00000000';
    $hpos_form = StatusVerifier::bind_transaction($gateway, $order, 'track-exact', $base);
    $check(!empty($hpos_form['bound']), 'the stored decimal(26,8) form of the same total binds');

    echo "\n--- Provider Amount Binding Report ---\nPASS: {$pass}\nFAIL: {$fail}\n";
    echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
    exit($fail === 0 ? 0 : 1);
}
