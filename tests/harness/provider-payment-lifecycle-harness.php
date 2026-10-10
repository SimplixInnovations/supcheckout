<?php

namespace Simplixi\SUPCheckout\Payment {
    \define('ABSPATH', __DIR__ . '/');

    $GLOBALS['splx_state'] = array();

    function &state() { return $GLOBALS['splx_state']; }

    function reset_state() {
        $GLOBALS['splx_state'] = array(
            'options' => array(),
            'orders' => array(),
            'gateway' => null,
            'scheduled' => array(),
            'filters' => array(),
            'actions' => array(),
            'remote_get_calls' => 0,
            'remote_response' => array('code' => 201, 'body' => ''),
            'remote_mutator' => null,
            'db_query_mutator' => null,
            'logs' => array(),
        );
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        state()['actions'][] = array($hook, $callback, $priority, $accepted_args);
        return true;
    }
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        if (!isset(state()['filters'][$hook])) { state()['filters'][$hook] = array(); }
        state()['filters'][$hook][] = array($callback, $priority, $accepted_args);
        return true;
    }
    function remove_filter($hook, $callback, $priority = 10) {
        if (empty(state()['filters'][$hook])) { return false; }
        foreach (state()['filters'][$hook] as $i => $entry) {
            if ($entry[0] === $callback && $entry[1] === $priority) {
                unset(state()['filters'][$hook][$i]);
                return true;
            }
        }
        return false;
    }
    function apply_test_filter($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        if (empty(state()['filters'][$hook])) { return $value; }
        usort(state()['filters'][$hook], function ($a, $b) { return $a[1] <=> $b[1]; });
        foreach (state()['filters'][$hook] as $entry) {
            $call_args = array_slice($args, 0, $entry[2]);
            $value = call_user_func_array($entry[0], $call_args);
            $args[0] = $value;
        }
        return $value;
    }

    function get_option($name, $default = false) {
        return array_key_exists($name, state()['options']) ? state()['options'][$name] : $default;
    }
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
        if (array_key_exists($name, state()['options'])) { return false; }
        state()['options'][$name] = $value;
        return true;
    }
    function update_option($name, $value, $autoload = null) {
        state()['options'][$name] = $value;
        return true;
    }
    function delete_option($name) {
        unset(state()['options'][$name]);
        return true;
    }
    function wp_salt($scheme = 'auth') { return 'unit-test-wordpress-salt'; }
    function wp_parse_url($url, $component = -1) { return \parse_url((string) $url, $component); }

    function wp_remote_get($url, $args = array()) {
        state()['remote_get_calls']++;
        if (is_callable(state()['remote_mutator'])) {
            call_user_func(state()['remote_mutator']);
            state()['remote_mutator'] = null;
        }
        return array(
            'response' => array('code' => (int) state()['remote_response']['code']),
            'body' => (string) state()['remote_response']['body'],
            'request_url' => $url,
            'request_args' => $args,
        );
    }
    function is_wp_error($value) { return $value instanceof FakeWpError; }
    function wp_remote_retrieve_response_code($response) { return isset($response['response']['code']) ? (int) $response['response']['code'] : 0; }
    function wp_remote_retrieve_body($response) { return isset($response['body']) ? (string) $response['body'] : ''; }

    function wc_get_price_decimals() { return 3; }
    function wc_format_decimal($value, $decimals = false) {
        if (!is_string($value) && !is_int($value) && !is_float($value)) { return ''; }
        $decimals = ($decimals === false) ? 3 : (int) $decimals;
        return number_format((float) $value, $decimals, '.', '');
    }
    function wc_get_order($id) {
        $id = (int) $id;
        return isset(state()['orders'][$id]) ? state()['orders'][$id] : false;
    }

    function wp_next_scheduled($hook, $args = array()) {
        $key = $hook . '|' . json_encode(array_values($args));
        return isset(state()['scheduled'][$key]) ? state()['scheduled'][$key] : false;
    }
    function wp_schedule_single_event($timestamp, $hook, $args = array()) {
        $key = $hook . '|' . json_encode(array_values($args));
        if (isset(state()['scheduled'][$key])) { return false; }
        state()['scheduled'][$key] = (int) $timestamp;
        return true;
    }
    function wp_unschedule_event($timestamp, $hook, $args = array()) {
        $key = $hook . '|' . json_encode(array_values($args));
        unset(state()['scheduled'][$key]);
        return true;
    }
    function clear_scheduled_for_order($order_id) {
        $key = 'simplixpay_upayments_reconcile_order|' . json_encode(array((int) $order_id));
        unset(state()['scheduled'][$key]);
    }

    function __($text, $domain = 'default') { return $text; }
    function wc_get_logger() { return new FakeLogger(); }

    class FakeWpError {}
    class FakeLogger {
        public function info($message, $context = array()) { state()['logs'][] = array('info', $message); }
        public function warning($message, $context = array()) { state()['logs'][] = array('warning', $message); }
    }

    class FakeWpdb {
        public $options = 'wp_options';
        public function prepare($query) {
            $args = func_get_args();
            array_shift($args);
            return array('query' => $query, 'args' => $args);
        }
        public function query($prepared) {
            if (is_callable(state()['db_query_mutator'])) {
                call_user_func(state()['db_query_mutator']);
                state()['db_query_mutator'] = null;
            }
            if (!is_array($prepared) || !isset($prepared['query'], $prepared['args'])) { return false; }
            $query = ltrim((string) $prepared['query']);
            $args = $prepared['args'];
            if (stripos($query, 'UPDATE ') === 0 && count($args) === 3) {
                list($replacement, $name, $expected) = $args;
                if (array_key_exists($name, state()['options']) && state()['options'][$name] === $expected) {
                    state()['options'][$name] = $replacement;
                    return 1;
                }
                return 0;
            }
            if (stripos($query, 'DELETE FROM ') === 0 && count($args) === 2) {
                list($name, $expected) = $args;
                if (array_key_exists($name, state()['options']) && state()['options'][$name] === $expected) {
                    unset(state()['options'][$name]);
                    return 1;
                }
                return 0;
            }
            return false;
        }
    }

    class FakeGateway {
        public $apiKey = 'test-api-key';
        public $test_mode = true;
        public $force_complete = false;
        public $host = 'sandboxapi.upayments.com';
        public $url_suffix = '';
        public function getMode() { return $this->test_mode; }
        public function getAPIUrl($route = '') { return 'https://' . $this->host . '/api/v1/' . $route . $this->url_suffix; }
        public function getCurrencyCode($currency) { return strtoupper((string) $currency); }
        public function getIsOrderComplete() { return $this->force_complete; }
    }

    class FakePaymentGateways {
        public function payment_gateways() {
            return is_object(state()['gateway']) ? array('upayments' => state()['gateway']) : array();
        }
    }
    class FakeWooRuntime {
        public $cart = null;
        public function payment_gateways() { return new FakePaymentGateways(); }
    }

    class FakeOrder {
        public $id;
        public $status = 'pending';
        public $payment_method = 'upayments';
        public $currency = 'KWD';
        public $total = '10.000';
        public $meta = array();
        public $transaction_id = '';
        public $payment_complete_calls = 0;
        public $update_status_calls = 0;
        public $save_calls = 0;
        public $notes = array();
        public function __construct($id, $upay_order_id) {
            $this->id = (int) $id;
            $this->meta['UPayments_order_id'] = $upay_order_id;
        }
        public function get_id() { return $this->id; }
        public function get_status() { return $this->status; }
        public function get_payment_method() { return $this->payment_method; }
        public function get_currency() { return $this->currency; }
        public function get_total() { return $this->total; }
        public function get_meta($key) { return array_key_exists($key, $this->meta) ? $this->meta[$key] : ''; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function delete_meta_data($key) { unset($this->meta[$key]); }
        public function save() { $this->save_calls++; return $this->id; }
        public function has_status($status) { return $this->status === $status; }
        public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
        public function get_transaction_id() { return $this->transaction_id; }
        public function set_transaction_id($id) { $this->transaction_id = (string) $id; }
        public function payment_complete($transaction_id = '') {
            $this->payment_complete_calls++;
            if ($transaction_id !== '') { $this->transaction_id = (string) $transaction_id; }
            $this->status = (string) apply_test_filter('woocommerce_payment_complete_order_status', 'processing', $this->id, $this);
            $this->save();
        }
        public function update_status($status, $note = '') {
            $this->update_status_calls++;
            $this->status = (string) $status;
            if ($note !== '') { $this->notes[] = (string) $note; }
            $this->save();
            return true;
        }
        public function add_order_note($note) { $this->notes[] = (string) $note; return true; }
    }

    reset_state();
}

namespace {
    function WC() { return new \Simplixi\SUPCheckout\Payment\FakeWooRuntime(); }
    function wp_cache_delete($key, $group = '') { return true; }
    function wc_get_price_decimals() { return 3; }

    require_once __DIR__ . '/../../src/Payment/ProviderResult.php';
    require_once __DIR__ . '/../../src/Payment/StatusRateGate.php';
    require_once __DIR__ . '/../../src/Payment/OrderLock.php';
    require_once __DIR__ . '/../../src/Payment/StatusVerifier.php';
    require_once __DIR__ . '/../../src/Payment/PaymentLifecycle.php';

    use Simplixi\SUPCheckout\Payment\FakeGateway;
    use Simplixi\SUPCheckout\Payment\FakeOrder;
    use Simplixi\SUPCheckout\Payment\OrderLock;
    use Simplixi\SUPCheckout\Payment\PaymentLifecycle;
    use Simplixi\SUPCheckout\Payment\ProviderResult;
    use Simplixi\SUPCheckout\Payment\StatusRateGate;
    use Simplixi\SUPCheckout\Payment\StatusVerifier;

    $pass = 0;
    $fail = 0;

    function ok($condition, $description) {
        global $pass, $fail;
        if ($condition) { $pass++; echo "PASS: $description\n"; }
        else { $fail++; echo "FAIL: $description\n"; }
    }
    function same($actual, $expected, $description) {
        ok($actual === $expected, $description . ' expected=' . var_export($expected, true) . ' got=' . var_export($actual, true));
    }
    function reset_fixture($id = 501) {
        \Simplixi\SUPCheckout\Payment\reset_state();
        $order = new FakeOrder($id, 'merchant-order-' . $id);
        $gateway = new FakeGateway();
        \Simplixi\SUPCheckout\Payment\state()['orders'][$id] = $order;
        \Simplixi\SUPCheckout\Payment\state()['gateway'] = $gateway;
        return array($gateway, $order);
    }
    function transaction_for(FakeOrder $order, $result = 'CAPTURED', $track = 'track-abc', $payment_id = 'pay-123') {
        $tx = array(
            'result' => $result,
            'track_id' => $track,
            'merchant_requested_order_id' => $order->get_meta('UPayments_order_id'),
            'total_price' => $order->get_total(),
            'currency_type' => $order->get_currency(),
            'reference' => (string) $order->get_id(),
            'payment_type' => 'KNET',
        );
        if ($payment_id !== null) { $tx['payment_id'] = $payment_id; }
        return $tx;
    }
    function set_provider_transaction(array $tx, $code = 201) {
        \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array(
            'code' => $code,
            'body' => json_encode(array('status' => true, 'data' => array('transaction' => $tx))),
        );
    }
    function private_call($class, $method, array $args = array()) {
        $r = new \ReflectionMethod($class, $method);
        if (\PHP_VERSION_ID < 80100) {
            $r->setAccessible(true);
        }
        return $r->invokeArgs(null, $args);
    }

    // Exact provider result table, including fail-closed future values.
    $class_cases = array(
        'CAPTURED' => ProviderResult::CAPTURED,
        'PENDING' => ProviderResult::PENDING,
        'AUTHORIZED' => ProviderResult::PENDING,
        'APPROVED' => ProviderResult::PENDING,
        'NOT CAPTURED' => ProviderResult::FAILED,
        'FAILED' => ProviderResult::FAILED,
        'ERROR' => ProviderResult::FAILED,
        'CANCELED' => ProviderResult::CANCELLED,
        'REFUND' => ProviderResult::INDETERMINATE,
        'VOIDED' => ProviderResult::INDETERMINATE,
        'Processing' => ProviderResult::INDETERMINATE,
        'captured' => ProviderResult::INDETERMINATE,
        'FUTURE_STATUS' => ProviderResult::INDETERMINATE,
    );
    foreach ($class_cases as $input => $expected) { same(ProviderResult::classify($input), $expected, 'classifier ' . $input); }
    same(ProviderResult::classify(null), ProviderResult::INDETERMINATE, 'classifier NULL fail closed');
    same(ProviderResult::classify(''), ProviderResult::INDETERMINATE, 'classifier empty fail closed');

    // Conflict-safe GET/POST merge.
    same(PaymentLifecycle::merge_request_value(array('x' => '1'), array(), 'x')['value'], '1', 'GET-only request value');
    same(PaymentLifecycle::merge_request_value(array(), array('x' => '2'), 'x')['value'], '2', 'POST-only request value');
    ok(PaymentLifecycle::merge_request_value(array('x' => '1'), array('x' => '1'), 'x')['valid'], 'identical GET/POST accepted');
    ok(!PaymentLifecycle::merge_request_value(array('x' => '1'), array('x' => '2'), 'x')['valid'], 'conflicting GET/POST rejected');
    ok(!PaymentLifecycle::merge_request_value(array('x' => array('1')), array(), 'x')['valid'], 'array GET rejected');
    ok(!PaymentLifecycle::merge_request_value(array(), array('x' => array('1')), 'x')['valid'], 'array POST rejected');
    ok(!PaymentLifecycle::merge_request_value(array(), array(), 'x')['present'], 'missing request field remains missing');

    // Binding contract and null/processing semantics.
    list($gateway, $order) = reset_fixture(510);
    $base_tx = transaction_for($order);
    $bound = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $base_tx);
    ok($bound['bound'], 'captured transaction binds');
    same($bound['classification'], ProviderResult::CAPTURED, 'captured classification after bind');
    $variants = array(
        'track_id' => array('different', 'binding_track_id'),
        'merchant_requested_order_id' => array('different', 'binding_merchant_requested_order_id'),
        'reference' => array('999', 'binding_reference'),
        'currency_type' => array('USD', 'binding_currency'),
        'total_price' => array('9.000', 'binding_amount'),
    );
    foreach ($variants as $field => $case) {
        $tx = $base_tx; $tx[$field] = $case[0];
        $r = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $tx);
        ok(!$r['bound'], 'binding mismatch rejects ' . $field);
        same($r['reason'], $case[1], 'binding mismatch reason ' . $field);
    }
    $tx = $base_tx; unset($tx['payment_id']);
    same(StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $tx)['reason'], 'captured_payment_id_missing', 'CAPTURED requires payment id');
    $pending = transaction_for($order, 'PENDING', 'track-abc', null);
    $r = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $pending);
    ok($r['bound'], 'PENDING binds without payment id');
    same($r['classification'], ProviderResult::PENDING, 'PENDING remains pending');
    $null_result = transaction_for($order, null, 'track-abc', null);
    $r = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $null_result);
    ok($r['bound'], 'documented NULL result still binds identity');
    same($r['classification'], ProviderResult::INDETERMINATE, 'documented NULL result is indeterminate');
    $processing = transaction_for($order, 'Processing', 'track-abc', null);
    $r = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $processing);
    ok($r['bound'], 'Processing result binds identity');
    same($r['classification'], ProviderResult::INDETERMINATE, 'Processing remains indeterminate');
    $bad_result = $base_tx; $bad_result['result'] = array('CAPTURED');
    same(StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $bad_result)['reason'], 'result_not_string_or_null', 'non-scalar result rejected');
    $missing_result = $base_tx; unset($missing_result['result']);
    same(StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $missing_result)['reason'], 'missing_field_result', 'missing result rejected');
    $exp = $base_tx; $exp['total_price'] = '1e1';
    same(StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $exp)['reason'], 'amount_invalid', 'exponent amount rejected');

    // Exact provider host/path validation and 30/min gate.
    list($gateway, $order) = reset_fixture(520);
    $gateway->host = 'attacker.example'; set_provider_transaction(transaction_for($order));
    $r = StatusVerifier::verify($gateway, $order, 'track-abc');
    same($r['reason'], 'status_url_invalid', 'non-UPayments host rejected');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 0, 'invalid host makes zero HTTP calls');
    same(count(\Simplixi\SUPCheckout\Payment\state()['options']), 0, 'invalid host consumes zero rate slots');
    list($gateway, $order) = reset_fixture(521);
    $gateway->url_suffix = '?leak=1'; set_provider_transaction(transaction_for($order));
    same(StatusVerifier::verify($gateway, $order, 'track-abc')['reason'], 'status_url_invalid', 'query-bearing status URL rejected');
    list($gateway, $order) = reset_fixture(522);
    $acquired = 0; for ($i = 0; $i < 31; $i++) { if (StatusRateGate::acquire($gateway)) { $acquired++; } }
    same($acquired, 30, 'status rate gate allows exactly 30 slots');
    same(StatusRateGate::limit_per_minute(), 30, 'status rate contract is 30/min');

    // CAPTURED canonical Woo completion + replay barrier.
    list($gateway, $order) = reset_fixture(530);
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'captured', 'CAPTURED outcome');
    same($order->payment_complete_calls, 1, 'CAPTURED calls payment_complete once');
    same($order->update_status_calls, 0, 'CAPTURED does not direct-update paid status');
    same($order->get_transaction_id(), 'pay-123', 'Woo transaction ID is provider payment ID');
    same($order->get_status(), 'processing', 'Woo default paid status retained');
    same((string) $order->get_meta('_upay_verified_capture'), '1', 'verified capture flag set');
    same($order->get_meta('UPayments_PaymentID'), 'pay-123', 'legacy payment ID retained');
    same($order->get_meta('UPayments_TrackID'), 'track-abc', 'legacy track retained');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-abc', 'trusted cursor retained');
    $calls = \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'];
    $out2 = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out2['state'], 'captured', 'duplicate CAPTURED sees verified state');
    same($order->payment_complete_calls, 1, 'duplicate does not re-fire payment_complete');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], $calls, 'duplicate makes zero provider calls');

    // Merchant force-complete still uses Woo filter.
    list($gateway, $order) = reset_fixture(531); $gateway->force_complete = true;
    set_provider_transaction(transaction_for($order));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'browser');
    same($order->get_status(), 'completed', 'force-complete uses Woo completion filter');
    same($order->update_status_calls, 0, 'force-complete avoids direct paid update_status');

    // Existing paid state and transaction conflict.
    list($gateway, $order) = reset_fixture(532); $order->status = 'processing';
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'captured', 'existing paid order recognizes authenticated capture');
    same($order->payment_complete_calls, 0, 'existing paid order does not re-fire completion');
    same($order->get_transaction_id(), 'pay-123', 'existing paid order receives transaction ID');
    same((string) $order->get_meta('_upay_verified_capture'), '1', 'existing paid order gains verified barrier');
    list($gateway, $order) = reset_fixture(533); $order->status = 'processing'; $order->transaction_id = 'other-payment';
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'unchanged', 'transaction conflict fails closed');
    same((string) $order->get_meta('_upay_verified_capture'), '', 'transaction conflict never sets verified barrier');

    // Terminal and unresolved states.
    list($gateway, $order) = reset_fixture(540); set_provider_transaction(transaction_for($order, 'FAILED'));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'failed', 'authenticated FAILED becomes Woo failed');
    same($order->get_status(), 'failed', 'Woo failed status');
    same($order->payment_complete_calls, 0, 'FAILED never completes payment');
    list($gateway, $order) = reset_fixture(541); set_provider_transaction(transaction_for($order, 'CANCELED'));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'cancelled', 'authenticated CANCELED becomes Woo cancelled');
    same($order->get_status(), 'cancelled', 'Woo cancelled status');
    list($gateway, $order) = reset_fixture(542); set_provider_transaction(transaction_for($order, 'PENDING', 'track-pending', null));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-pending', 'webhook');
    same($out['state'], 'pending', 'PENDING remains unresolved');
    same($order->get_status(), 'pending', 'PENDING stays unpaid');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'PENDING schedules first reconciliation');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(542)) !== false, 'PENDING event scheduled');
    \Simplixi\SUPCheckout\Payment\clear_scheduled_for_order(542);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-pending', 'pay-final'));
    PaymentLifecycle::reconcile_order(542);
    same($order->get_status(), 'processing', 'reconciliation CAPTURED reaches paid state');
    same($order->get_transaction_id(), 'pay-final', 'reconciliation stores final payment ID');
    same((string) $order->get_meta('_upay_verified_capture'), '1', 'reconciliation sets verified capture');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(542)) === false, 'terminal capture clears reconciliation');

    // NULL result is persisted as non-terminal evidence and reconciled.
    list($gateway, $order) = reset_fixture(543); set_provider_transaction(transaction_for($order, null, 'track-null', null));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-null', 'webhook');
    same($out['state'], 'pending', 'NULL result remains unresolved');
    same($order->get_status(), 'pending', 'NULL result stays unpaid');
    same($order->get_meta('_simplixpay_upayments_provider_result_v1'), 'NULL', 'NULL evidence is explicit');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'NULL result schedules reconciliation');

    // Paid/refunded orders cannot be downgraded/resurrected.
    list($gateway, $order) = reset_fixture(544); $order->status = 'processing'; $order->transaction_id = 'pay-existing';
    set_provider_transaction(transaction_for($order, 'FAILED', 'track-abc', 'pay-existing'));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['state'], 'unchanged', 'terminal result cannot downgrade paid order');
    same($order->get_status(), 'processing', 'paid state preserved');
    list($gateway, $order) = reset_fixture(545); $order->status = 'refunded';
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['reason'], 'refunded', 'refunded preflight result');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 0, 'refunded order makes zero provider calls');

    // Initial transient status failure survives via separate unverified cursor.
    list($gateway, $order) = reset_fixture(546);
    ok(private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-transient', $order->get_meta('UPayments_order_id'))), 'locally preflighted callback cursor can be remembered');
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-transient', 'webhook');
    same($out['reason'], 'unexpected_http_500', 'initial transient status failure remains unpaid');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), 'track-transient', 'unverified cursor retained for retry');
    same($order->get_meta('_simplixpay_upayments_unverified_requested_v1'), $order->get_meta('UPayments_order_id'), 'unverified cursor is paired to current provider order identity');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'transient failure schedules reconciliation');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(546)) !== false, 'transient failure has scheduled retry');
    \Simplixi\SUPCheckout\Payment\clear_scheduled_for_order(546);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-transient', 'pay-recovered'));
    PaymentLifecycle::reconcile_order(546);
    same($order->get_status(), 'processing', 'unverified cursor reconciliation can recover capture');
    same($order->get_transaction_id(), 'pay-recovered', 'recovered capture stores payment ID');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), '', 'unverified cursor erased after authenticated bind');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-transient', 'cursor promoted to trusted after bind');
    same($order->get_meta('_simplixpay_upayments_status_requested_v1'), $order->get_meta('UPayments_order_id'), 'trusted cursor is paired to provider order identity');

    // Authenticated binding mismatch discards untrusted retry cursor.
    list($gateway, $order) = reset_fixture(547);
    private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-bad', $order->get_meta('UPayments_order_id')));
    $bad = transaction_for($order, 'PENDING', 'track-bad', null); $bad['reference'] = '999';
    set_provider_transaction($bad);
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-bad', 'webhook');
    same($out['reason'], 'binding_reference', 'authenticated binding mismatch rejected');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), '', 'binding mismatch clears unverified cursor');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(547)) === false, 'binding mismatch leaves no retry event');

    // A new Charge attempt on the same Woo order rotates provider order identity.
    // Stale unpaid cursor state must not pin the new attempt to the old track.
    list($gateway, $order) = reset_fixture(548);
    set_provider_transaction(transaction_for($order, 'PENDING', 'track-old', null));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-old', 'webhook');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-old', 'old attempt establishes trusted cursor');
    same($order->get_meta('_simplixpay_upayments_status_requested_v1'), 'merchant-order-548', 'old attempt trusted requested identity stored');
    $order->update_meta_data('UPayments_order_id', 'merchant-order-548-new');
    $order->save();
    ok(private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-new', 'merchant-order-548-new')), 'new Charge identity can rotate stale unpaid cursor state');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), '', 'old trusted track cleared for new Charge attempt');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), 'track-new', 'new attempt owns unverified cursor');
    $new_tx = transaction_for($order, 'CAPTURED', 'track-new', 'pay-new');
    set_provider_transaction($new_tx);
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-new', 'webhook');
    same($out['state'], 'captured', 'new same-order Charge attempt can capture');
    same($order->get_transaction_id(), 'pay-new', 'new attempt payment ID becomes canonical Woo transaction ID');

    // TOCTOU: provider binds original snapshot, fresh order changes under lock.
    list($gateway, $order) = reset_fixture(550); set_provider_transaction(transaction_for($order));
    \Simplixi\SUPCheckout\Payment\state()['remote_mutator'] = function () use ($order) {
        $fresh = clone $order; $fresh->total = '11.000';
        \Simplixi\SUPCheckout\Payment\state()['orders'][$order->get_id()] = $fresh;
    };
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['reason'], 'binding_changed_under_lock', 'fresh-order rebind catches TOCTOU total change');
    same($order->payment_complete_calls, 0, 'TOCTOU change never completes original order');

    // Atomic lock contention and stale-lock CAS recovery.
    list($gateway, $order) = reset_fixture(551); set_provider_transaction(transaction_for($order));
    $lock_record = private_call(OrderLock::class, 'encode_record', array(str_repeat('a', 32), time() + 30));
    \Simplixi\SUPCheckout\Payment\state()['options']['simplixpay_upay_order_lock_v1_551'] = $lock_record;
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['reason'], 'order_lock_contention', 'live order lock contention fails closed');
    same($order->payment_complete_calls, 0, 'lock contention prevents completion');

    \Simplixi\SUPCheckout\Payment\reset_state();
    $stale_name = 'simplixpay_upay_order_lock_v1_570';
    $stale_record = private_call(OrderLock::class, 'encode_record', array(str_repeat('b', 32), time() - 5));
    \Simplixi\SUPCheckout\Payment\state()['options'][$stale_name] = $stale_record;
    $token = OrderLock::acquire(570);
    ok(is_string($token) && $token !== '', 'stale lock is recovered atomically');
    OrderLock::release(570, $token);
    ok(!array_key_exists($stale_name, \Simplixi\SUPCheckout\Payment\state()['options']), 'owner releases exact recovered lock');

    \Simplixi\SUPCheckout\Payment\reset_state();
    $race_name = 'simplixpay_upay_order_lock_v1_571';
    $old = private_call(OrderLock::class, 'encode_record', array(str_repeat('c', 32), time() - 5));
    $new = private_call(OrderLock::class, 'encode_record', array(str_repeat('d', 32), time() + 30));
    \Simplixi\SUPCheckout\Payment\state()['options'][$race_name] = $old;
    \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = function () use ($race_name, $new) {
        \Simplixi\SUPCheckout\Payment\state()['options'][$race_name] = $new;
    };
    same(OrderLock::acquire(571), null, 'stale recovery loses CAS when newer owner wins');
    same(\Simplixi\SUPCheckout\Payment\state()['options'][$race_name], $new, 'stale recovery never deletes newer owner lock');

    // Bounded retry/exhaustion: initial schedule + four cron opportunities max.
    list($gateway, $order) = reset_fixture(560); set_provider_transaction(transaction_for($order, 'PENDING', 'track-retry', null));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-retry', 'webhook');
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        \Simplixi\SUPCheckout\Payment\clear_scheduled_for_order(560);
        PaymentLifecycle::reconcile_order(560);
    }
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 4, 'reconciliation attempts capped at four');
    same((string) $order->get_meta('_simplixpay_upayments_reconcile_exhausted_v1'), '1', 'reconciliation exhaustion is durable');
    ok(count($order->notes) === 1, 'reconciliation exhaustion note emitted once');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(560)) === false, 'no event remains after exhaustion');

    // ---- Lifecycle hardening (2026-10-10 review) ----
    $prior_meta = '_simplixpay_upayments_prior_requested_v1';
    $rotate = function (FakeOrder $order, $new_requested) use ($prior_meta) {
        $order->meta[$prior_meta] = array($order->get_meta('UPayments_order_id'));
        $order->meta['UPayments_order_id'] = $new_requested;
    };
    $prior_tx = function (FakeOrder $order, $requested, $result, $track, $payment_id) {
        $tx = transaction_for($order, $result, $track, $payment_id);
        $tx['merchant_requested_order_id'] = $requested;
        return $tx;
    };

    // H1: a capture on a superseded Charge attempt pays the still-unpaid order.
    list($gateway, $order) = reset_fixture(580);
    $rotate($order, 'merchant-order-580-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-580', 'CAPTURED', 'track-a', 'pay-a'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-580', 'webhook');
    same($out['state'], 'captured', 'H1 superseded-attempt capture completes unpaid order');
    same($order->get_status(), 'processing', 'H1 order reaches Woo paid state');
    same($order->get_transaction_id(), 'pay-a', 'H1 superseded payment ID becomes Woo transaction ID');
    same((string) $order->get_meta('_upay_verified_capture'), '1', 'H1 verified capture barrier set');
    same($order->get_meta('UPayments_WHS'), 'completed', 'H1 public status poll reports completed');
    same($order->get_meta('UPayments_order_id'), 'merchant-order-580-b', 'H1 current provider identity is not rewritten');

    // H1: an identity that never belonged to this order is refused before any provider call.
    list($gateway, $order) = reset_fixture(581);
    $rotate($order, 'merchant-order-581-b');
    set_provider_transaction($prior_tx($order, 'foreign-order', 'CAPTURED', 'track-x', 'pay-x'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-x', 'foreign-order', 'webhook');
    same($out['reason'], 'unknown_attempt', 'H1 foreign provider identity refused');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 0, 'H1 foreign identity makes zero provider calls');

    // H1: a non-captured result for a superseded attempt never changes the order.
    list($gateway, $order) = reset_fixture(582);
    $rotate($order, 'merchant-order-582-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-582', 'NOT CAPTURED', 'track-f', null));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-f', 'merchant-order-582', 'webhook');
    same($out['reason'], 'secondary_not_captured', 'H1 superseded failure is ignored');
    same($order->get_status(), 'pending', 'H1 superseded failure leaves order pending');
    same($order->update_status_calls, 0, 'H1 superseded failure makes no status change');

    // H1: a second capture on an already-paid order is flagged for manual refund, once.
    list($gateway, $order) = reset_fixture(583);
    $rotate($order, 'merchant-order-583-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-583-b', 'CAPTURED', 'track-b', 'pay-b'));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-b', 'webhook');
    same($order->get_transaction_id(), 'pay-b', 'H1 current attempt paid the order first');
    set_provider_transaction($prior_tx($order, 'merchant-order-583', 'CAPTURED', 'track-a', 'pay-a'));
    $notes_before = count($order->notes);
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-583', 'webhook');
    same($out['state'], 'duplicate_capture', 'H1 duplicate capture detected');
    same($order->get_transaction_id(), 'pay-b', 'H1 duplicate never replaces the canonical transaction');
    same($order->payment_complete_calls, 1, 'H1 duplicate never re-completes the order');
    same(count($order->notes), $notes_before + 1, 'H1 duplicate adds one admin note');
    ok(strpos(end($order->notes), 'pay-a') !== false, 'H1 duplicate note names the extra payment ID');
    PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-583', 'webhook');
    same(count($order->notes), $notes_before + 1, 'H1 repeated duplicate callback adds no further note');

    // H4: within one attempt, a later CAPTURED track overrides an earlier bound non-captured track.
    list($gateway, $order) = reset_fixture(584);
    set_provider_transaction(transaction_for($order, 'NOT CAPTURED', 'track-1', null));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-1', 'webhook');
    same($order->get_status(), 'failed', 'H4 first track failed the order');
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-2', 'pay-2'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-2', 'merchant-order-584', 'webhook');
    same($out['state'], 'captured', 'H4 later captured track in the same attempt is applied');
    same($order->get_transaction_id(), 'pay-2', 'H4 captured track payment ID recorded');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-2', 'H4 trusted cursor moves to the captured track');
    // A later non-captured track cannot downgrade a verified capture.
    set_provider_transaction(transaction_for($order, 'NOT CAPTURED', 'track-3', null));
    PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-3', 'merchant-order-584', 'webhook');
    same($order->get_status(), 'processing', 'H4 later failed track cannot downgrade paid order');

    // H2: callback lookups are throttled per order and cannot drain the reconcile reserve.
    list($gateway, $order) = reset_fixture(585);
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    $reasons = array();
    for ($i = 0; $i < 5; $i++) {
        $reasons[] = PaymentLifecycle::process_order_status($gateway, $order, 'track-h2', 'webhook')['reason'];
    }
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 3, 'H2 one order gets at most 3 callback lookups per minute');
    same(end($reasons), 'callback_rate_limited', 'H2 excess callback lookup is refused locally');
    list($gateway, $order) = reset_fixture(586);
    $callback_slots = 0;
    for ($i = 0; $i < 40; $i++) { if (StatusRateGate::acquire($gateway, 'webhook')) { $callback_slots++; } }
    same($callback_slots, 24, 'H2 callback sources may use at most 24 of 30 global slots');
    $reconcile_slots = 0;
    for ($i = 0; $i < 40; $i++) { if (StatusRateGate::acquire($gateway, 'reconcile')) { $reconcile_slots++; } }
    same($reconcile_slots, 6, 'H2 six global slots stay reserved for reconciliation');

    // H3: a verified CAPTURED that WooCommerce fails to record is retried and surfaced.
    list($gateway, $order) = reset_fixture(587);
    $throwing = new class(587, 'merchant-order-587') extends FakeOrder {
        public $throw = true;
        public function payment_complete($transaction_id = '') {
            if ($this->throw) { throw new \RuntimeException('simulated completion failure'); }
            parent::payment_complete($transaction_id);
        }
    };
    \Simplixi\SUPCheckout\Payment\state()['orders'][587] = $throwing;
    set_provider_transaction(transaction_for($throwing, 'CAPTURED', 'track-h3', 'pay-h3'));
    $out = PaymentLifecycle::process_order_status($gateway, $throwing, 'track-h3', 'webhook');
    same($out['state'], 'unchanged', 'H3 failed completion stays unpaid');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(587)) !== false, 'H3 failed completion schedules reconciliation');
    same(count($throwing->notes), 1, 'H3 failed completion adds one admin note');
    \Simplixi\SUPCheckout\Payment\clear_scheduled_for_order(587);
    $throwing->throw = false;
    PaymentLifecycle::reconcile_order(587);
    same($throwing->get_status(), 'processing', 'H3 reconciliation recovers the capture');
    same($throwing->get_transaction_id(), 'pay-h3', 'H3 recovered capture stores payment ID');

    list($gateway, $order) = reset_fixture(588);
    $no_paid = new class(588, 'merchant-order-588') extends FakeOrder {
        public function payment_complete($transaction_id = '') { $this->payment_complete_calls++; }
    };
    \Simplixi\SUPCheckout\Payment\state()['orders'][588] = $no_paid;
    set_provider_transaction(transaction_for($no_paid, 'CAPTURED', 'track-h3b', 'pay-h3b'));
    $out = PaymentLifecycle::process_order_status($gateway, $no_paid, 'track-h3b', 'webhook');
    same($out['reason'], 'payment_complete_failed', 'H3 completion postcondition failure reported');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array(588)) !== false, 'H3 postcondition failure schedules reconciliation');
    same(count($no_paid->notes), 1, 'H3 postcondition failure adds one admin note');

    // H1: a capture that arrives after the customer switched the order to another
    // gateway is surfaced for manual review and never marks the order paid.
    list($gateway, $order) = reset_fixture(589);
    $order->payment_method = 'cod';
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-sw', 'pay-sw'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-sw', 'merchant-order-589', 'webhook');
    same($out['state'], 'unapplied_capture', 'H1 capture after gateway switch is not applied');
    same($order->get_status(), 'pending', 'H1 gateway-switched order stays unpaid');
    same($order->payment_complete_calls, 0, 'H1 gateway-switched order is never completed');
    ok(count($order->notes) === 1 && strpos($order->notes[0], 'pay-sw') !== false, 'H1 gateway-switched capture adds one note naming the payment');
    list($gateway, $order) = reset_fixture(592);
    $order->payment_method = 'cod';
    unset($order->meta['UPayments_order_id']);
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-sw', 'merchant-order-592', 'webhook');
    same($out['reason'], 'local_preflight_failed', 'H1 non-UPayments order without provider identity is refused');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 0, 'H1 refused order makes zero provider calls');

    // R1: once an earlier attempt paid the order, a capture on the current attempt is still recorded.
    list($gateway, $order) = reset_fixture(593);
    $rotate($order, 'merchant-order-593-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-593', 'CAPTURED', 'track-a', 'pay-a'));
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-593', 'webhook');
    same($order->get_transaction_id(), 'pay-a', 'R1 earlier attempt paid the order');
    set_provider_transaction($prior_tx($order, 'merchant-order-593-b', 'CAPTURED', 'track-2', 'pay-2'));
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-2', 'merchant-order-593-b', 'webhook');
    same($out['state'], 'duplicate_capture', 'R1 current-attempt capture on paid order is recorded');
    ok(strpos(end($order->notes), 'pay-2') !== false, 'R1 duplicate note names the current-attempt payment');
    same($order->get_transaction_id(), 'pay-a', 'R1 canonical transaction unchanged');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-a', 'R1 paid order keeps its trusted cursor');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), '', 'R1 paid order gains no unverified cursor');
    // The paid track itself is answered locally.
    list($gateway, $order) = reset_fixture(597);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-p', 'pay-p'));
    PaymentLifecycle::route_callback($gateway, $order, 'track-p', 'merchant-order-597', 'webhook');
    $calls = \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'];
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-p', 'merchant-order-597', 'browser');
    same($out['state'], 'captured', 'R1 replay of the paid track reports captured');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], $calls, 'R1 replay of the paid track makes zero provider calls');

    // R2: a secondary capture whose lookup is deferred is retried by bounded reconciliation.
    list($gateway, $order) = reset_fixture(594);
    $rotate($order, 'merchant-order-594-b');
    $order->meta['_simplixpay_upayments_callback_lookups_v1'] = gmdate('YmdHi') . ':3';
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-594', 'webhook');
    same($out['reason'], 'callback_rate_limited', 'R2 throttled secondary lookup deferred');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array(594)) !== false, 'R2 deferred secondary lookup scheduled');
    set_provider_transaction($prior_tx($order, 'merchant-order-594', 'CAPTURED', 'track-a', 'pay-a'));
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    PaymentLifecycle::reconcile_secondary(594);
    same($order->get_status(), 'processing', 'R2 secondary reconciliation completes the order');
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), '', 'R2 resolved secondary cursor cleared');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array(594)) === false, 'R2 nothing left scheduled');

    list($gateway, $order) = reset_fixture(595);
    $rotate($order, 'merchant-order-595-b');
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-595', 'webhook');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
        PaymentLifecycle::reconcile_secondary(595);
    }
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], 5, 'R2 secondary retries are capped at four after the callback');
    same(count($order->notes), 1, 'R2 secondary exhaustion adds one note');
    ok(strpos($order->notes[0], 'track-a') !== false, 'R2 exhaustion note names the unverified track');
    same($order->get_status(), 'pending', 'R2 exhausted secondary leaves order unpaid');

    // R3: an authenticated capture that cannot bind to the current order economics is surfaced once.
    list($gateway, $order) = reset_fixture(596);
    $rotate($order, 'merchant-order-596-b');
    $mismatch = $prior_tx($order, 'merchant-order-596', 'CAPTURED', 'track-a', 'pay-a');
    $mismatch['total_price'] = '12.000';
    set_provider_transaction($mismatch);
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-596', 'webhook');
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-596', 'webhook');
    same($order->get_status(), 'pending', 'R3 unbound capture never pays the order');
    same(count($order->notes), 1, 'R3 unbound secondary capture adds one note');

    // H6: authenticated terminal results reach the historical public status poll.
    list($gateway, $order) = reset_fixture(590); set_provider_transaction(transaction_for($order, 'NOT CAPTURED'));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($order->get_meta('UPayments_WHS'), 'failed', 'H6 failed result reaches status poll');
    list($gateway, $order) = reset_fixture(591); set_provider_transaction(transaction_for($order, 'CANCELED'));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($order->get_meta('UPayments_WHS'), 'cancelled', 'H6 cancelled result reaches status poll');

    // Static architecture/safety guards.
    $root = dirname(__DIR__, 2);
    $identity_source = @file_get_contents($root . '/src/Release/Identity.php');
    $lifecycle_source = file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
    $verifier_source = file_get_contents($root . '/src/Payment/StatusVerifier.php');
    $rate_source = file_get_contents($root . '/src/Payment/StatusRateGate.php');
    $lock_source = file_get_contents($root . '/src/Payment/OrderLock.php');
    $gateway_source = @file_get_contents($root . '/UPayments.php');

    // H1: the Charge path records the superseded provider identity before rotating it.
    $orchestrator_source = file_get_contents($root . '/src/Payment/CheckoutOrchestrator.php');
    ok(strpos($lifecycle_source, "PRIOR_REQUESTED_META = '_simplixpay_upayments_prior_requested_v1'") !== false, 'H1 lifecycle reads the prior-attempt history key');
    $prior_write = strpos($orchestrator_source, "'_simplixpay_upayments_prior_requested_v1'");
    $rotation = strpos($orchestrator_source, '$order->delete_meta_data("UPayments_order_id");');
    ok($prior_write !== false && $rotation !== false && $prior_write < $rotation, 'H1 orchestrator records prior identity before rotation');
    ok(strpos($orchestrator_source, 'MAX_PRIOR_PROVIDER_ATTEMPTS = 5') !== false, 'H1 prior-attempt history is bounded');

    // In local isolated execution the repository-only files may be absent; CI has them.
    if (is_string($identity_source)) { ok(strpos($identity_source, 'PaymentLifecycle.php') !== false, 'release foothold loads payment lifecycle'); }
    ok(strpos($lifecycle_source, "add_action(self::CALLBACK_HOOK, array(__CLASS__, 'handle_callback'), 5)") !== false, 'new callback runs before inherited priority 10');
    ok(strpos($lifecycle_source, '$_REQUEST') === false, 'new lifecycle never uses $_REQUEST');
    ok(strpos($lifecycle_source, 'payment_complete($payment_id)') !== false, 'captured path uses Woo payment_complete');
    ok(strpos($lifecycle_source, "execute_upayments_request('charge'") === false && strpos($lifecycle_source, "getAPIUrl('charge") === false, 'reconciliation never dispatches Charge');
    ok(strpos($lifecycle_source, 'UNVERIFIED_TRACK_META') !== false, 'separate unverified callback cursor exists');
    ok(strpos($lifecycle_source, 'TRUSTED_REQUESTED_META') !== false && strpos($lifecycle_source, 'UNVERIFIED_REQUESTED_META') !== false, 'cursor state is scoped to provider Charge attempt identity');
    ok(preg_match("/'redirection'\\s*=>\\s*0/", $verifier_source) === 1, 'status lookup disables redirects');
    ok(preg_match("/'sslverify'\\s*=>\\s*true/", $verifier_source) === 1, 'status lookup enforces TLS');
    ok(preg_match("/'timeout'\\s*=>\\s*15/", $verifier_source) === 1, 'status lookup has finite timeout');
    ok(preg_match("/'limit_response_size'\\s*=>\\s*1048576/", $verifier_source) === 1, 'status lookup bounds provider responses to 1 MiB');
    ok(strpos($verifier_source, 'strlen($body) >= 1048576') !== false, 'status lookup rejects a body that reaches the read cap');
    ok(strpos($verifier_source, "result_not_string_or_null") !== false, 'NULL result contract is explicit');
    ok(strpos($rate_source, 'LIMIT_PER_MINUTE = 30') !== false, 'status query ceiling is 30/min');
    ok(strpos($lock_source, 'replace_if_current') !== false && strpos($lock_source, 'delete_if_current') !== false, 'order lock uses conditional stale recovery/release');
    ok(strpos($lock_source, 'delete_option($name)') === false, 'order lock never blindly deletes contested lock');
    if (is_string($gateway_source)) {
        ok(strpos($gateway_source, 'woocommerce_api_') !== false, 'historical wc_upayments route remains');
        ok(strpos($gateway_source, 'function process_refund') === false, 'automatic gateway refund remains unsupported');
        ok(strpos($gateway_source, "'refunds'") === false && strpos($gateway_source, '"refunds"') === false, 'gateway does not advertise refunds');
    }

    echo "\n--- Provider Payment Lifecycle Report ---\n";
    echo "PASS: $pass\n";
    echo "FAIL: $fail\n";
    exit($fail === 0 ? 0 : 1);
}
