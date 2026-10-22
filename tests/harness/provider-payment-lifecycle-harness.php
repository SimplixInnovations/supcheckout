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
            'schedule_calls' => array(),
            'filters' => array(),
            'actions' => array(),
            'remote_get_calls' => 0,
            'remote_response' => array('code' => 201, 'body' => ''),
            'remote_mutator' => null,
            'db_query_mutator' => null,
            // WP-Cron refusing an event (pre_schedule_event filter, cron option write failure).
            'schedule_fails' => false,
            // Real WordPress add_option() is check-then-upsert (INSERT ... ON DUPLICATE KEY
            // UPDATE): two racing callers can both "succeed". 'wp_add_option_race' makes the
            // fake behave like the losing side of that race.
            'wp_add_option_race' => false,
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
        if (state()['wp_add_option_race']) {
            state()['options'][$name] = $value;
            return true;
        }
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

    // Minimal wc_get_orders() for the reconciliation sweep: honours status,
    // payment_method, limit and an OR of meta EXISTS clauses over in-memory orders.
    function wc_get_orders($args = array()) {
        $ids = array();
        foreach (state()['orders'] as $id => $order) {
            if (isset($args['status']) && !in_array($order->status, (array) $args['status'], true)) { continue; }
            if (isset($args['payment_method']) && $order->get_payment_method() !== $args['payment_method']) { continue; }
            if (isset($args['meta_query'])) {
                $matched = false;
                foreach ($args['meta_query'] as $clause) {
                    if (is_array($clause) && isset($clause['key']) && (string) $order->get_meta($clause['key']) !== '') { $matched = true; }
                }
                if (!$matched) { continue; }
            }
            $ids[] = $id;
        }
        return array_slice($ids, 0, isset($args['limit']) ? (int) $args['limit'] : 10);
    }
    function wp_next_scheduled($hook, $args = array()) {
        $key = $hook . '|' . json_encode(array_values($args));
        return isset(state()['scheduled'][$key]) ? state()['scheduled'][$key] : false;
    }
    function wp_schedule_single_event($timestamp, $hook, $args = array()) {
        state()['schedule_calls'][] = array($hook, $args);
        $key = $hook . '|' . json_encode(array_values($args));
        if (!empty(state()['schedule_fails'])) { return false; }
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
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
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
            if (stripos($query, 'INSERT IGNORE ') === 0 && count($args) === 3) {
                list($name, $value) = $args;
                if (array_key_exists($name, state()['options'])) { return 0; }
                state()['options'][$name] = $value;
                return 1;
            }
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

namespace Simplixi\SUPCheckout\Gateway {
    // Stand-in for the registered gateway class (scenario T1). After cutover
    // Keys::find_gateway() accepts only an instance of this class (0b Task 2), never
    // another plugin's object registered under 'upayments'.
    class Gateway extends \Simplixi\SUPCheckout\Payment\FakeGateway {
        public function get_return_url($order = null) {
            return 'https://shop.test/checkout/order-received/' . (is_object($order) ? (int) $order->get_id() : 0) . '/';
        }
    }
}

namespace {
    function WC() { return new \Simplixi\SUPCheckout\Payment\FakeWooRuntime(); }
    function wp_cache_delete($key, $group = '') { return true; }
    function wc_get_price_decimals() { return 3; }
    // PaymentLifecycle::log() guards on the global function, so the namespaced fake
    // above is never reached without this. Records into state()['logs'] (A6-05).
    function wc_get_logger() { return new \Simplixi\SUPCheckout\Payment\FakeLogger(); }
    // StatusVerifier reads the stored order total on every bind (R-1). The stored order
    // is the one in the fake order store, which a test may change under the lock.
    function get_post_meta($id, $key = '', $single = false) {
        $orders = \Simplixi\SUPCheckout\Payment\state()['orders'];
        return ($key === '_order_total' && isset($orders[(int) $id])) ? (string) $orders[(int) $id]->total : '';
    }
    // Takeover\TakeoverState and Takeover\LegacyCallbackListener call the global WordPress
    // functions; both read and record through the same fake store as the payment code.
    function get_option($name, $default = false) { return \Simplixi\SUPCheckout\Payment\get_option($name, $default); }
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return \Simplixi\SUPCheckout\Payment\add_action($hook, $callback, $priority, $accepted_args); }

    require_once __DIR__ . '/../../src/Takeover/TakeoverState.php';
    require_once __DIR__ . '/../../src/Takeover/Keys.php';
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
        \Simplixi\SUPCheckout\Takeover\TakeoverState::flush_cache();
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

    // Scenario T1 (0b Task 8, Review Focus 3): a pre-switch order, legacy keys only and
    // payment_method 'upayments', paid by a webhook that arrives after cutover.
    const T1_ORDER = 600;
    const T1_TRACK = 'track-t1';
    const T1_PAYMENT = 'pay-t1';

    function t1_fixture() {
        list(, $order) = reset_fixture(T1_ORDER);
        $gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();
        \Simplixi\SUPCheckout\Payment\state()['gateway'] = $gateway;
        \Simplixi\SUPCheckout\Payment\state()['options'][\Simplixi\SUPCheckout\Takeover\TakeoverState::OPTION] = json_encode(array(
            'status' => 'cut_over',
            'cut_over_at' => 1700000000,
            'cursor' => 0,
            'totals' => array(),
        ));
        \Simplixi\SUPCheckout\Takeover\TakeoverState::flush_cache();
        set_provider_transaction(transaction_for($order, 'CAPTURED', T1_TRACK, T1_PAYMENT));
        return array($gateway, $order);
    }

    /**
     * T1 route child: one real WC-API dispatch of woocommerce_api_{route}, every listener
     * PaymentLifecycle::bootstrap() attached in priority order, through the production
     * exit(). The response lines and, at shutdown, the order state are printed so the
     * parent can compare the two routes byte for byte.
     *
     * @param string $route      wc_upayments or supcheckout.
     * @param string $mode       webhook (POST) or browser (GET with the page marker).
     * @param string $state_file JSON order state of an earlier child, '' for a fresh order.
     */
    function t1_route_child($route, $mode, $state_file) {
        function status_header($code) { echo 'STATUS:' . (int) $code . "\n"; }
        function wp_safe_redirect($url) { echo 'REDIRECT:' . $url . "\n"; return true; }
        function wc_nocache_headers() { echo "NOCACHE_HEADERS\n"; }
        function wp_unslash($value) { return $value; }
        function is_user_logged_in() { return false; }
        function home_url($path = '') { return 'https://shop.test' . $path; }
        function wc_get_page_permalink($page) { return 'https://shop.test/' . $page . '/'; }
        function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
        eval('namespace Simplixi\SUPCheckout\Takeover; function header($line) { echo "HEADER:" . $line . "\n"; } function headers_sent() { return false; }');

        list(, $order) = t1_fixture();
        if ($state_file !== '') {
            $saved = json_decode((string) file_get_contents($state_file), true);
            $order->status = $saved['status'];
            $order->transaction_id = $saved['transaction_id'];
            $order->payment_complete_calls = $saved['payment_complete_calls'];
            $order->notes = $saved['notes'];
            $order->meta = $saved['meta'];
        }
        register_shutdown_function(function () use ($order) {
            $meta = $order->meta;
            ksort($meta);
            foreach ($meta as $key => $value) {
                // The per-minute callback lookup bucket (gmdate('YmdHi')) is the only clock value.
                $meta[$key] = is_string($value) ? preg_replace('/^[0-9]{12}:/', 'MINUTE:', $value) : $value;
            }
            echo 'REMOTE_CALLS:' . \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'] . "\n";
            echo 'ORDER_STATE:' . json_encode(array(
                'status' => $order->status,
                'transaction_id' => $order->transaction_id,
                'payment_complete_calls' => $order->payment_complete_calls,
                'notes' => $order->notes,
                'meta' => $meta,
            )) . "\n";
        });

        PaymentLifecycle::bootstrap();
        $keys = array('wc_order_id' => (string) T1_ORDER, 'track_id' => T1_TRACK, 'requested_order_id' => 'merchant-order-' . T1_ORDER);
        if ($mode === 'webhook') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = array();
            $_POST = $keys;
        } else {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = array('page' => 'success') + $keys;
            $_POST = array();
        }

        $queue = array();
        foreach (\Simplixi\SUPCheckout\Payment\state()['actions'] as $index => $action) {
            if ($action[0] === 'woocommerce_api_' . $route) {
                $queue[] = array((int) $action[2], $index, $action[1]);
            }
        }
        usort($queue, function ($left, $right) {
            return $left[0] === $right[0] ? $left[1] <=> $right[1] : $left[0] <=> $right[0];
        });
        echo 'LISTENERS:' . count($queue) . "\n";
        foreach ($queue as $entry) {
            call_user_func($entry[2]);
        }
        echo "ROUTE_RETURNED_UNEXPECTEDLY\n";
        exit(65);
    }

    function t1_run_route_child($route, $mode, $state_file = '') {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d display_errors=1 ' . escapeshellarg(__FILE__)
            . ' --t1-route ' . escapeshellarg($route) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($state_file);
        $pipes = array();
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            return array('exit' => 127, 'stdout' => '', 'stderr' => 'proc_open failed', 'state' => null);
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $state = null;
        if (preg_match('/^ORDER_STATE:(.*)$/m', $stdout, $match) === 1) {
            $state = json_decode($match[1], true);
        }
        return array('exit' => (int) $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'state' => $state);
    }

    if (isset($argv[1]) && $argv[1] === '--t1-route') {
        t1_route_child((string) $argv[2], (string) $argv[3], isset($argv[4]) ? (string) $argv[4] : '');
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

    // UPayments appends its return query with "?" to a URL that already has one,
    // so wc_order_id arrives as "<id>?payment_id=..." (sandbox, 2026-10-15).
    same(PaymentLifecycle::parse_callback_order_id('11577'), 11577, 'callback order id: plain digits');
    same(PaymentLifecycle::parse_callback_order_id('11577?payment_id=100628810000033571&result=CAPTURED'), 11577, 'callback order id: provider-glued query keeps the leading id');
    same(PaymentLifecycle::parse_callback_order_id('11577?'), 11577, 'callback order id: bare trailing question mark');
    same(PaymentLifecycle::parse_callback_order_id('11577x'), null, 'callback order id: other trailing text still rejected');
    same(PaymentLifecycle::parse_callback_order_id('?11577'), null, 'callback order id: leading question mark rejected');
    same(PaymentLifecycle::parse_callback_order_id('0?payment_id=1'), null, 'callback order id: zero rejected');
    same(PaymentLifecycle::parse_callback_order_id('1157 7?x'), null, 'callback order id: spaces rejected');
    same(PaymentLifecycle::parse_callback_order_id(array('11577')), null, 'callback order id: array rejected');
    same(PaymentLifecycle::parse_callback_order_id(str_repeat('9', 19) . '?x'), null, 'callback order id: over-long id rejected');
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

    // R-1: a stored total changed while the status request was in flight is caught by
    // the first bind, which reads the persisted total after the provider response.
    list($gateway, $order) = reset_fixture(549); set_provider_transaction(transaction_for($order));
    \Simplixi\SUPCheckout\Payment\state()['remote_mutator'] = function () use ($order) {
        $fresh = clone $order; $fresh->total = '11.000';
        \Simplixi\SUPCheckout\Payment\state()['orders'][$order->get_id()] = $fresh;
    };
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out['reason'], 'binding_amount', 'R-1 the first bind sees a stored total changed during the status request');
    same($order->payment_complete_calls, 0, 'R-1 a stored-total change never completes the original order');

    // TOCTOU: provider binds original snapshot, fresh order changes under lock (after
    // the first bind, as the lock is taken).
    list($gateway, $order) = reset_fixture(550); set_provider_transaction(transaction_for($order));
    \Simplixi\SUPCheckout\Payment\state()['remote_mutator'] = function () use ($order) {
        \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = function () use ($order) {
            $fresh = clone $order; $fresh->total = '11.000';
            \Simplixi\SUPCheckout\Payment\state()['orders'][$order->get_id()] = $fresh;
        };
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
    same($out['state'], 'capture_in_progress', 'B2 lock contention after a verified CAPTURED reports a capture in progress');
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

    // C1: acquisition must be atomic even where WordPress add_option() is not.
    \Simplixi\SUPCheckout\Payment\reset_state();
    \Simplixi\SUPCheckout\Payment\state()['wp_add_option_race'] = true;
    $first = OrderLock::acquire(880);
    ok(is_string($first) && $first !== '', 'C1 first worker acquires the order lock');
    same(OrderLock::acquire(880), null, 'C1 a racing second worker never acquires a held order lock');
    OrderLock::release(880, $first);
    ok(is_string(OrderLock::acquire(880)), 'C1 the lock is acquirable again after release');
    \Simplixi\SUPCheckout\Payment\reset_state();
    \Simplixi\SUPCheckout\Payment\state()['wp_add_option_race'] = true;
    $race_gateway = new FakeGateway();
    $granted = 0; for ($i = 0; $i < 40; $i++) { if (StatusRateGate::acquire($race_gateway)) { $granted++; } }
    same($granted, 30, 'C1 the status rate gate grants exactly 30 slots under add_option races');
    \Simplixi\SUPCheckout\Payment\reset_state();

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
    same(\Simplixi\SUPCheckout\Takeover\TakeoverState::get()['status'], 'idle', 'R2 runs on an idle store');
    same(\Simplixi\SUPCheckout\Payment\state()['schedule_calls'], array(array('simplixpay_upayments_reconcile_secondary', array(594))), 'R2 idle schedule_secondary schedules the legacy hook with one int order id');
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

    // R2b: a callback track is untrusted input. Markup in it must reach the admin
    // order note as inert text (WooCommerce renders notes through wp_kses_post).
    list($gateway, $order) = reset_fixture(598);
    $rotate($order, 'merchant-order-598-b');
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    $hostile_track = '<a/href=//evil.example>refund</a>';
    PaymentLifecycle::route_callback($gateway, $order, $hostile_track, 'merchant-order-598', 'webhook');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
        PaymentLifecycle::reconcile_secondary(598);
    }
    same(count($order->notes), 1, 'R2b hostile-track exhaustion adds one note');
    ok(isset($order->notes[0]) && strpos($order->notes[0], '<a') === false, 'R2b exhaustion note never carries markup from a callback track');
    ok(isset($order->notes[0]) && strpos($order->notes[0], '&lt;a/href=//evil.example&gt;') !== false, 'R2b exhaustion note shows the track as escaped text');

    // R4: races between provider verification and the order lock. The mutator runs
    // just before OrderLock's INSERT, i.e. after this callback verified its track.
    // R4a: another track completes the order first; this second CAPTURED is a
    // duplicate charge and must be recorded, not dropped as "already verified".
    list($gateway, $order) = reset_fixture(610);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-2', 'pay-2'));
    \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = function () use ($order) {
        $order->meta['_upay_verified_capture'] = '1';
        $order->meta['UPayments_TrackID'] = 'track-1';
        $order->transaction_id = 'pay-1';
        $order->status = 'processing';
    };
    PaymentLifecycle::route_callback($gateway, $order, 'track-2', 'merchant-order-610', 'webhook');
    $dup_notes = array_values(array_filter($order->notes, function ($note) { return strpos($note, 'pay-2') !== false; }));
    same(count($dup_notes), 1, 'R4a a second capture landing just after the first is recorded as a duplicate charge');
    same($order->get_transaction_id(), 'pay-1', 'R4a the canonical transaction is unchanged');

    // R4b: another track became the trusted cursor first (still pending); this
    // track's verified CAPTURED must go to the secondary path and complete the order.
    list($gateway, $order) = reset_fixture(611);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-2', 'pay-2'));
    \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = function () use ($order) {
        $order->meta['_simplixpay_upayments_status_track_v1'] = 'track-1';
        $order->meta['_simplixpay_upayments_status_requested_v1'] = 'merchant-order-611';
    };
    PaymentLifecycle::route_callback($gateway, $order, 'track-2', 'merchant-order-611', 'webhook');
    same($order->get_status(), 'processing', 'R4b a verified capture on a second track of the same attempt is not lost to a cursor conflict');
    same($order->get_transaction_id(), 'pay-2', 'R4b the order records the captured payment');

    // R5: reconciliation that finds the attempt rotated must not forget the
    // pending verification of the earlier attempt.
    list($gateway, $order) = reset_fixture(612);
    $order->meta['_simplixpay_upayments_unverified_track_v1'] = 'track-a';
    $order->meta['_simplixpay_upayments_unverified_requested_v1'] = 'merchant-order-612';
    $rotate($order, 'merchant-order-612-b');
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    PaymentLifecycle::reconcile_order(612);
    $pending = $order->get_meta('_simplixpay_upayments_secondary_pending_v1');
    ok(is_array($pending) && count($pending) === 1 && $pending[0]['track'] === 'track-a' && $pending[0]['requested'] === 'merchant-order-612', 'R5 the rotated attempt\'s track is queued for secondary verification');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array(612)) !== false, 'R5 secondary verification is scheduled');
    // R6: a secondary capture WooCommerce cannot record (a payment_complete hook
    // throws) is queued and retried, not silently dropped.
    list($gateway, $order) = reset_fixture(613);
    $rotate($order, 'merchant-order-613-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-613', 'CAPTURED', 'track-a', 'pay-a'));
    \Simplixi\SUPCheckout\Payment\state()['filters']['woocommerce_payment_complete_order_status'][] = array(function () { throw new \RuntimeException('third-party hook failure'); }, 10, 1);
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-613', 'webhook');
    $pending = $order->get_meta('_simplixpay_upayments_secondary_pending_v1');
    ok(is_array($pending) && count($pending) === 1 && $pending[0]['track'] === 'track-a', 'R6 an unrecordable secondary capture is queued for retry');
    ok(\Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array(613)) !== false, 'R6 the retry is scheduled');
    \Simplixi\SUPCheckout\Payment\state()['filters'] = array();
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    PaymentLifecycle::reconcile_secondary(613);
    same($order->get_status(), 'processing', 'R6 the retry completes the order once the hook recovers');

    // R7: a webhook that queues another track while reconciliation is mid-run must
    // not have its entry overwritten by the reconciliation's final write.
    list($gateway, $order) = reset_fixture(614);
    $rotate($order, 'merchant-order-614-b');
    $order->meta['_simplixpay_upayments_secondary_pending_v1'] = array(array('track' => 'track-a', 'requested' => 'merchant-order-614'));
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = function () use ($gateway, $order) {
        // Disarm first: the simulated webhook makes database calls of its own.
        \Simplixi\SUPCheckout\Payment\state()['db_query_mutator'] = null;
        PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-b', 'merchant-order-614', 'webhook');
    };
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    PaymentLifecycle::reconcile_secondary(614);
    $pending = $order->get_meta('_simplixpay_upayments_secondary_pending_v1');
    $tracks = is_array($pending) ? array_map(function ($entry) { return $entry['track']; }, $pending) : array();
    sort($tracks);
    same($tracks, array('track-a', 'track-b'), 'R7 a track queued during reconciliation survives its final write');

    // R8: when WP-Cron refuses the reconciliation event, the attempt is not consumed
    // and the merchant is told once to verify the payment manually.
    list($gateway, $order) = reset_fixture(615);
    \Simplixi\SUPCheckout\Payment\state()['remote_response'] = array('code' => 500, 'body' => '');
    \Simplixi\SUPCheckout\Payment\state()['schedule_fails'] = true;
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-615', 'webhook');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 0, 'R8 a refused reconciliation event does not consume an attempt');
    $schedule_notes = array_values(array_filter($order->notes, function ($note) { return strpos($note, 'could not schedule') !== false; }));
    same(count($schedule_notes), 1, 'R8 the merchant is told once that the automatic payment check could not be scheduled');
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-615', 'webhook');
    $schedule_notes = array_values(array_filter($order->notes, function ($note) { return strpos($note, 'could not schedule') !== false; }));
    same(count($schedule_notes), 1, 'R8 the scheduling note is not repeated');
    \Simplixi\SUPCheckout\Payment\state()['schedule_fails'] = false;
    PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-615', 'webhook');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'R8 once WP-Cron accepts the event the first attempt is counted');

    // S1: the hourly safety-net sweep re-checks pending orders whose reconciliation
    // event was lost (low-traffic WP-Cron, a refused event) and leaves alone orders
    // that already have a check scheduled or are already paid.
    list($gateway, $lost) = reset_fixture(620);
    $lost->meta['_simplixpay_upayments_unverified_track_v1'] = 'track-lost';
    $lost->meta['_simplixpay_upayments_unverified_requested_v1'] = 'merchant-order-620';
    $waiting = new FakeOrder(621, 'merchant-order-621');
    $waiting->meta['_simplixpay_upayments_unverified_track_v1'] = 'track-waiting';
    $waiting->meta['_simplixpay_upayments_unverified_requested_v1'] = 'merchant-order-621';
    \Simplixi\SUPCheckout\Payment\state()['orders'][621] = $waiting;
    \Simplixi\SUPCheckout\Payment\state()['scheduled']['simplixpay_upayments_reconcile_order|[621]'] = time() + 60;
    set_provider_transaction(transaction_for($lost, 'CAPTURED', 'track-lost', 'pay-lost'));
    $calls_before = \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'];
    \Simplixi\SUPCheckout\Payment\ReconciliationSweep::run();
    same($lost->get_status(), 'processing', 'S1 a pending order whose check was lost is completed by the sweep');
    same($lost->get_transaction_id(), 'pay-lost', 'S1 the sweep records the verified payment');
    same($waiting->get_status(), 'pending', 'S1 an order that already has a check scheduled is left to it');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'] - $calls_before, 1, 'S1 the sweep makes exactly one provider lookup for one candidate');
    $calls_before = \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'];
    \Simplixi\SUPCheckout\Payment\ReconciliationSweep::run();
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'] - $calls_before, 0, 'S1 a paid order is never swept again');

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

    // B1: where a returning browser lands. A verified failure or cancellation sends the
    // customer back to pay the same order with a message, not to the home page.
    if (!function_exists('home_url')) { function home_url($path = '') { return 'https://shop.test' . $path; } }
    if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
    if (!function_exists('wc_get_page_permalink')) { function wc_get_page_permalink($page) { return 'https://shop.test/' . $page . '/'; } }
    if (!function_exists('wc_get_checkout_url')) { function wc_get_checkout_url() { return 'https://shop.test/checkout/'; } }
    if (!function_exists('add_query_arg')) { function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; } }
    $payable = new class(595, 'merchant-order-595') extends FakeOrder {
        public $needs = true;
        public function needs_payment() { return $this->needs; }
        public function get_checkout_payment_url() { return 'https://shop.test/checkout/order-pay/595/?pay_for_order=true&key=wc_order_k'; }
    };
    $gw_with_return = new class extends FakeGateway {
        public function get_return_url($order) { return 'https://shop.test/checkout/order-received/595/'; }
    };
    $r = PaymentLifecycle::browser_redirect(true, array('state' => 'captured', 'reason' => 'captured'), $gw_with_return, $payable);
    same($r['url'], 'https://shop.test/checkout/order-received/595/', 'B1 captured return lands on order-received');
    same($r['notice'], null, 'B1 captured return adds no notice');
    $r = PaymentLifecycle::browser_redirect(false, array('state' => 'failed', 'reason' => 'provider_failed'), $gw_with_return, $payable);
    same($r['url'], 'https://shop.test/checkout/order-pay/595/?pay_for_order=true&key=wc_order_k', 'B1 verified failure goes back to pay the same order');
    same(is_array($r['notice']) ? $r['notice']['type'] : null, 'error', 'B1 verified failure shows an error notice');
    // A cancelled WooCommerce order no longer needs payment; like official 3.1.1, the
    // customer returns to checkout (the cart is still there) with a notice.
    $cancelled = new class(596, 'merchant-order-596') extends FakeOrder {
        public $status = 'cancelled';
        public function needs_payment() { return false; }
        public function get_checkout_payment_url() { return 'https://shop.test/checkout/order-pay/596/'; }
    };
    $r = PaymentLifecycle::browser_redirect(false, array('state' => 'cancelled', 'reason' => 'provider_cancelled'), $gw_with_return, $cancelled);
    same($r['url'], 'https://shop.test/checkout/', 'B1 verified cancellation returns to checkout');
    same(is_array($r['notice']) ? $r['notice']['type'] : null, 'notice', 'B1 verified cancellation shows a notice');
    $r = PaymentLifecycle::browser_redirect(false, array('state' => 'capture_in_progress', 'reason' => 'order_lock_contention'), $gw_with_return, $payable);
    same($r['url'], 'https://shop.test/checkout/order-received/595/', 'B2 verified capture still completing lands on order-received');
    same($r['notice'], null, 'B2 capture in progress adds no notice');
    $r = PaymentLifecycle::browser_redirect(false, array('state' => 'pending', 'reason' => 'pending'), $gw_with_return, $payable);
    same($r['url'], 'https://shop.test/?upayments_verification=pending', 'B1 pending return keeps the neutral page');
    same(is_array($r['notice']) ? $r['notice']['type'] : null, 'notice', 'B1 provider-pending return says the payment is being confirmed');
    $payable->needs = false;
    $r = PaymentLifecycle::browser_redirect(false, array('state' => 'failed', 'reason' => 'provider_failed'), $gw_with_return, $payable);
    same($r['url'], 'https://shop.test/?upayments_verification=pending', 'B1 failed attempt on an order that no longer needs payment stays neutral');
    $r = PaymentLifecycle::browser_redirect(false, null, null, null);
    same($r['url'], 'https://shop.test/?upayments_verification=pending', 'B1 unrouted return stays neutral');
    same($r['notice'], null, 'B1 unrouted return reveals nothing');

    // H6: authenticated terminal results reach the historical public status poll.
    list($gateway, $order) = reset_fixture(590); set_provider_transaction(transaction_for($order, 'NOT CAPTURED'));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($order->get_meta('UPayments_WHS'), 'failed', 'H6 failed result reaches status poll');
    list($gateway, $order) = reset_fixture(591); set_provider_transaction(transaction_for($order, 'CANCELED'));
    PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($order->get_meta('UPayments_WHS'), 'cancelled', 'H6 cancelled result reaches status poll');

    // T1 (0b Task 8, Review Focus 3): a webhook for a pre-switch payment arrives after
    // cutover. The order holds only legacy keys and payment_method 'upayments'. The
    // authenticated CAPTURED binds through the legacy identity (read with fallback), the
    // order completes once, and every runtime write follows the window model: in cut_over
    // both names are written (Keys::write_keys()), the legacy identity is never rewritten
    // and payment_method stays 'upayments'.
    list($gateway, $order) = t1_fixture();
    ok(\Simplixi\SUPCheckout\Takeover\TakeoverState::is_cut_over(), 'T1 the store is cut over');
    same($order->get_meta('_supcheckout_provider_order_id'), '', 'T1 the pre-switch order has no new provider identity');
    $out = PaymentLifecycle::route_callback($gateway, $order, T1_TRACK, 'merchant-order-' . T1_ORDER, 'webhook');
    same($out['state'], 'captured', 'T1 the provider CAPTURED binds to the pre-switch order');
    same($order->payment_complete_calls, 1, 'T1 the order completes once');
    same($order->get_status(), 'processing', 'T1 the order is paid');
    same($order->get_transaction_id(), T1_PAYMENT, 'T1 the provider payment ID is the transaction ID');
    same($order->get_meta('_supcheckout_payment_id'), T1_PAYMENT, 'T1 the payment ID is written under the new key');
    same($order->get_meta('UPayments_PaymentID'), T1_PAYMENT, 'T1 the window model mirrors the payment ID under the legacy key');
    same((string) $order->get_meta('_supcheckout_verified_capture'), '1', 'T1 the verified-capture barrier is written under the new key');
    same((string) $order->get_meta('_upay_verified_capture'), '1', 'T1 and mirrored under the legacy key');
    same($order->get_meta('UPayments_order_id'), 'merchant-order-' . T1_ORDER, 'T1 the legacy provider identity is unchanged');
    same($order->get_meta('_supcheckout_provider_order_id'), '', 'T1 the provider identity is read through the legacy fallback, never copied by the callback');
    same($order->get_payment_method(), 'upayments', 'T1 historical payment_method is never rewritten');
    $t1_calls = \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'];
    same($t1_calls, 1, 'T1 exactly one authenticated status lookup');
    $replay = PaymentLifecycle::route_callback($gateway, $order, T1_TRACK, 'merchant-order-' . T1_ORDER, 'webhook');
    same($replay['state'], 'captured', 'T1 a replayed webhook sees the verified capture');
    same($order->payment_complete_calls, 1, 'T1 a replayed webhook never completes the order again');
    same(\Simplixi\SUPCheckout\Payment\state()['remote_get_calls'], $t1_calls, 'T1 a replayed webhook makes no provider call');

    // T1 route parity: the same webhook, its replay and the browser return, dispatched
    // through the real woocommerce_api_{route} listeners on each route, produce
    // byte-identical responses and order state; a webhook on one route and its replay on
    // the other still complete the order once.
    $t1_dir = sys_get_temp_dir() . '/supcheckout-t1-' . getmypid();
    @mkdir($t1_dir, 0777, true);
    $t1_save = function ($name, $state) use ($t1_dir) {
        $path = $t1_dir . '/' . $name . '.json';
        file_put_contents($path, json_encode($state));
        return $path;
    };
    $t1_runs = array();
    foreach (array('wc_upayments', 'supcheckout') as $route) {
        $first = t1_run_route_child($route, 'webhook');
        $again = t1_run_route_child($route, 'webhook', $t1_save($route . '-first', $first['state']));
        $browser = t1_run_route_child($route, 'browser', $t1_save($route . '-again', $again['state']));
        $t1_runs[$route] = array($first, $again, $browser);
        foreach (array('webhook' => $first, 'replayed webhook' => $again, 'browser return' => $browser) as $label => $run) {
            if ($run['stderr'] !== '' || $run['exit'] !== 0) {
                echo '  child [' . $route . ' ' . $label . '] exit=' . $run['exit'] . ' stderr: ' . trim($run['stderr']) . "\n  stdout: " . trim($run['stdout']) . "\n";
            }
            ok($run['exit'] === 0 && $run['stderr'] === '', "T1 {$route} {$label} terminates through the production exit");
            ok(strpos($run['stdout'], "LISTENERS:1\n") !== false, "T1 {$route} {$label} has one priority-5 lifecycle listener (the gateway's priority-10 fallback is not constructed here)");
            ok(strpos($run['stdout'], 'ROUTE_RETURNED_UNEXPECTEDLY') === false && strpos($run['stdout'], 'HEADER:') === false, "T1 {$route} {$label} is a callback, not a probe");
            ok(is_array($run['state']), "T1 {$route} {$label} reports the order state");
        }
        ok(strpos($first['stdout'], "NOCACHE_HEADERS\nSTATUS:200\n") !== false, "T1 {$route} webhook answers 200 under the no-cache policy");
        same(is_array($first['state']) ? $first['state']['status'] : null, 'processing', "T1 {$route} webhook pays the pre-switch order");
        same(is_array($first['state']) ? $first['state']['payment_complete_calls'] : null, 1, "T1 {$route} webhook completes it once");
        same(is_array($first['state']) ? $first['state']['meta']['_supcheckout_payment_id'] : null, T1_PAYMENT, "T1 {$route} webhook writes the new payment ID");
        same(is_array($first['state']) ? $first['state']['meta']['UPayments_PaymentID'] : null, T1_PAYMENT, "T1 {$route} webhook mirrors the legacy payment ID");
        same(is_array($first['state']) ? $first['state']['meta']['UPayments_order_id'] : null, 'merchant-order-' . T1_ORDER, "T1 {$route} webhook keeps the legacy provider identity");
        ok(strpos($first['stdout'], 'REMOTE_CALLS:1') !== false, "T1 {$route} webhook makes one authenticated status lookup");
        ok(strpos($again['stdout'], "NOCACHE_HEADERS\nSTATUS:200\n") !== false && strpos($again['stdout'], 'REMOTE_CALLS:0') !== false, "T1 {$route} replayed webhook answers locally with no provider call");
        same(is_array($again['state']) ? $again['state']['payment_complete_calls'] : null, 1, "T1 {$route} replayed webhook never completes again");
        ok(strpos($browser['stdout'], "NOCACHE_HEADERS\nREDIRECT:https://shop.test/checkout/order-received/600/\n") !== false, "T1 {$route} browser return reaches order-received under the no-cache policy");
        same($again['state'], $browser['state'], "T1 {$route} browser return leaves the paid order unchanged");
    }
    foreach (array(0 => 'webhook', 1 => 'replayed webhook', 2 => 'browser return') as $step => $label) {
        same($t1_runs['supcheckout'][$step]['stdout'], $t1_runs['wc_upayments'][$step]['stdout'], "T1 route parity: the {$label} is byte-identical on wc_upayments and supcheckout (response and order state)");
    }
    $cross = t1_run_route_child('supcheckout', 'webhook', $t1_save('cross', $t1_runs['wc_upayments'][0]['state']));
    same(is_array($cross['state']) ? $cross['state']['payment_complete_calls'] : null, 1, 'T1 a webhook paid on wc_upayments and replayed on supcheckout completes the order once');
    ok(strpos($cross['stdout'], 'REMOTE_CALLS:0') !== false, 'T1 the cross-route replay makes no provider call');
    array_map('unlink', (array) glob($t1_dir . '/*.json'));
    @rmdir($t1_dir);
    \Simplixi\SUPCheckout\Payment\reset_state();
    \Simplixi\SUPCheckout\Takeover\TakeoverState::flush_cache();

    // ---- A6-05: characterization of the untested money-bearing branches ----
    // These cases pin TODAY's behaviour, defects included, so the U1/U2 fixes
    // (A1-04, A1-05, A1-06, A3-03, A6-03) change it deliberately. A case named
    // "characterizes A1-0x/A6-03 ... (to be changed by U1)" is expected to flip.
    $c_state = function () { return \Simplixi\SUPCheckout\Payment\state(); };
    $c_logged = function ($code) {
        foreach (\Simplixi\SUPCheckout\Payment\state()['logs'] as $entry) {
            if ($entry[1] === 'Payment lifecycle: ' . $code) { return true; }
        }
        return false;
    };
    $c_reconcile_scheduled = function ($id) {
        return \Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_order', array((int) $id)) !== false;
    };
    $c_secondary_scheduled = function ($id) {
        return \Simplixi\SUPCheckout\Payment\wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array((int) $id)) !== false;
    };
    $c_lock_free = function ($id) {
        return !array_key_exists('simplixpay_upay_order_lock_v1_' . (int) $id, \Simplixi\SUPCheckout\Payment\state()['options']);
    };
    $c_hold_lock = function ($id) {
        $record = private_call(OrderLock::class, 'encode_record', array(str_repeat('e', 32), time() + 30));
        \Simplixi\SUPCheckout\Payment\state()['options']['simplixpay_upay_order_lock_v1_' . (int) $id] = $record;
        return $record;
    };
    $c_trust = function (FakeOrder $order, $track, $requested) {
        $order->meta['_simplixpay_upayments_status_track_v1'] = $track;
        $order->meta['_simplixpay_upayments_status_requested_v1'] = $requested;
    };

    // C1 (PaymentLifecycle reconcile_order, trusted branch): the cursor is trusted
    // for an earlier attempt, so that attempt's track is queued for the
    // capture-only path and the attempt cursor state is reset.
    list($gateway, $order) = reset_fixture(700);
    $c_trust($order, 'track-a', 'merchant-order-700');
    $order->meta['_simplixpay_upayments_provider_result_v1'] = 'NULL';
    $order->meta['_simplixpay_upayments_reconcile_attempt_v1'] = 2;
    $order->meta['_simplixpay_upayments_reconcile_reason_v1'] = 'indeterminate';
    $rotate($order, 'merchant-order-700-b');
    \Simplixi\SUPCheckout\Payment\state()['scheduled']['simplixpay_upayments_reconcile_order|[700]'] = time() + 60;
    PaymentLifecycle::reconcile_order(700);
    $pending = $order->get_meta('_simplixpay_upayments_secondary_pending_v1');
    same($pending, array(array('track' => 'track-a', 'requested' => 'merchant-order-700')), 'A6-05 C1 reconcile_stale_trusted_attempt queues the earlier trusted track for the capture-only path');
    ok($c_secondary_scheduled(700), 'A6-05 C1 reconcile_stale_trusted_attempt schedules the secondary check');
    ok(!$c_reconcile_scheduled(700), 'A6-05 C1 reconcile_stale_trusted_attempt unschedules the primary reconciliation');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), '', 'A6-05 C1 reconcile_stale_trusted_attempt resets the trusted track');
    same($order->get_meta('_simplixpay_upayments_status_requested_v1'), '', 'A6-05 C1 reconcile_stale_trusted_attempt resets the trusted requested identity');
    same($order->get_meta('_simplixpay_upayments_provider_result_v1'), '', 'A6-05 C1 reconcile_stale_trusted_attempt resets the stored provider result');
    same($order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), '', 'A6-05 C1 reconcile_stale_trusted_attempt resets the attempt counter');
    same($c_state()['remote_get_calls'], 0, 'A6-05 C1 reconcile_stale_trusted_attempt makes no provider call');
    same(array($order->get_status(), count($order->notes), $order->payment_complete_calls), array('pending', 0, 0), 'A6-05 C1 reconcile_stale_trusted_attempt leaves the order pending with no note');
    ok($c_logged('reconcile_stale_trusted_attempt'), 'A6-05 C1 reconcile_stale_trusted_attempt is logged');

    // C1b: a trusted track with no requested identity is reset without queueing.
    list($gateway, $order) = reset_fixture(701);
    $order->meta['_simplixpay_upayments_status_track_v1'] = 'track-a';
    PaymentLifecycle::reconcile_order(701);
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), '', 'A6-05 C1b a trusted track without a requested identity is not queued');
    ok(!$c_secondary_scheduled(701), 'A6-05 C1b no secondary check is scheduled');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), '', 'A6-05 C1b the orphan trusted track is reset');
    ok($c_logged('reconcile_stale_trusted_attempt'), 'A6-05 C1b reconcile_stale_trusted_attempt is logged');

    // C1c characterizes A1-05: the queued earlier attempt gets ONE lookup; a NULL
    // result is dropped with no note and nothing rescheduled (to be changed by U1).
    \Simplixi\SUPCheckout\Payment\state()['orders'][700] = $order700 = new FakeOrder(700, 'merchant-order-700-b');
    $order700->meta[$prior_meta] = array('merchant-order-700');
    $order700->meta['_simplixpay_upayments_secondary_pending_v1'] = array(array('track' => 'track-a', 'requested' => 'merchant-order-700'));
    \Simplixi\SUPCheckout\Payment\state()['scheduled'] = array();
    \Simplixi\SUPCheckout\Payment\state()['remote_get_calls'] = 0;
    set_provider_transaction($prior_tx($order700, 'merchant-order-700', null, 'track-a', null));
    PaymentLifecycle::reconcile_secondary(700);
    same($c_state()['remote_get_calls'], 1, 'A6-05 C1c characterizes A1-05: a queued earlier-attempt track is looked up once');
    same($order700->get_meta('_simplixpay_upayments_secondary_pending_v1'), '', 'A6-05 C1c characterizes A1-05: a NULL earlier-attempt track is dropped from the queue (to be changed by U1)');
    ok(!$c_secondary_scheduled(700), 'A6-05 C1c characterizes A1-05: a NULL earlier-attempt track is not rescheduled (to be changed by U1)');
    same(array($order700->get_status(), count($order700->notes)), array('pending', 0), 'A6-05 C1c characterizes A1-05: the drop leaves the order pending without a note (to be changed by U1)');

    // C2 (process_order_status :378-381): lock contention with a matching cursor
    // re-schedules reconciliation; a verified CAPTURED reports capture in progress.
    list($gateway, $order) = reset_fixture(702);
    private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-abc', 'merchant-order-702'));
    set_provider_transaction(transaction_for($order));
    $held = $c_hold_lock(702);
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out, array('state' => 'capture_in_progress', 'reason' => 'order_lock_contention'), 'A6-05 C2 contention after a verified CAPTURED reports a capture in progress');
    ok($c_reconcile_scheduled(702), 'A6-05 C2 contention with a matching cursor schedules reconciliation');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'A6-05 C2 contention consumes the first reconciliation attempt');
    same($order->get_meta('_simplixpay_upayments_reconcile_reason_v1'), 'order_lock_contention', 'A6-05 C2 contention records its reconciliation reason');
    same(array($order->get_status(), $order->payment_complete_calls, count($order->notes)), array('pending', 0, 0), 'A6-05 C2 contention never completes the order and adds no note');
    same($c_state()['options']['simplixpay_upay_order_lock_v1_702'], $held, 'A6-05 C2 contention never touches the other worker\'s lock');
    // C2b: without a matching cursor nothing is scheduled; PENDING reports unchanged.
    list($gateway, $order) = reset_fixture(703);
    set_provider_transaction(transaction_for($order, 'PENDING', 'track-abc', null));
    $c_hold_lock(703);
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'order_lock_contention'), 'A6-05 C2b contention after a PENDING result reports unchanged');
    ok(!$c_reconcile_scheduled(703), 'A6-05 C2b contention without a matching cursor schedules nothing');
    same($order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), '', 'A6-05 C2b contention without a matching cursor consumes no attempt');

    // C3 (route_callback :254-258): the unverified cursor cannot be stored and no
    // trusted cursor exists, so the callback stops at callback_cursor_conflict.
    list($gateway, $order) = reset_fixture(704);
    $unsaved = new class(704, 'merchant-order-704') extends FakeOrder {
        public function save() { $this->save_calls++; return 0; }
    };
    \Simplixi\SUPCheckout\Payment\state()['orders'][704] = $unsaved;
    set_provider_transaction(transaction_for($unsaved));
    $out = PaymentLifecycle::route_callback($gateway, $unsaved, 'track-abc', 'merchant-order-704', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'callback_cursor_conflict'), 'A6-05 C3 an unstorable cursor without a trusted cursor stops at callback_cursor_conflict');
    same($c_state()['remote_get_calls'], 0, 'A6-05 C3 callback_cursor_conflict makes no provider call');
    same(array($unsaved->get_status(), $unsaved->payment_complete_calls, count($unsaved->notes)), array('pending', 0, 0), 'A6-05 C3 callback_cursor_conflict leaves the order pending with no note');
    ok(!$c_reconcile_scheduled(704) && !$c_secondary_scheduled(704), 'A6-05 C3 callback_cursor_conflict schedules nothing');
    ok($c_logged('callback_cursor_conflict'), 'A6-05 C3 callback_cursor_conflict is logged');
    // C3b: a requested identity that parses as invalid also stops there.
    list($gateway, $order) = reset_fixture(705);
    $order->meta['UPayments_order_id'] = 'merchant order 705';
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-abc', 'merchant order 705', 'webhook');
    same($out['reason'], 'callback_cursor_conflict', 'A6-05 C3b an unparseable requested identity stops at callback_cursor_conflict');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), '', 'A6-05 C3b no unverified cursor is stored');

    // C4 characterizes A1-05 (route_callback :254-256): a second track of an
    // attempt with a trusted cursor goes to the capture-only path; its NULL result
    // is looked up once and never queued (to be changed by U1).
    list($gateway, $order) = reset_fixture(706);
    set_provider_transaction(transaction_for($order, 'NOT CAPTURED', 'track-1', null));
    PaymentLifecycle::route_callback($gateway, $order, 'track-1', 'merchant-order-706', 'webhook');
    same($order->get_status(), 'failed', 'A6-05 C4 the trusted first track failed the order');
    $calls_before = $c_state()['remote_get_calls'];
    set_provider_transaction(transaction_for($order, null, 'track-2', null));
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-2', 'merchant-order-706', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'secondary_not_captured'), 'A6-05 C4 characterizes A1-05: a NULL second track under a trusted cursor reports secondary_not_captured');
    same($c_state()['remote_get_calls'] - $calls_before, 1, 'A6-05 C4 characterizes A1-05: the second track is looked up once');
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), '', 'A6-05 C4 characterizes A1-05: a NULL second track is not queued (to be changed by U1)');
    ok(!$c_secondary_scheduled(706), 'A6-05 C4 characterizes A1-05: a NULL second track schedules no secondary check (to be changed by U1)');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-1', 'A6-05 C4 the trusted cursor stays on the first track');
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-2', 'pay-2'));
    PaymentLifecycle::reconcile_secondary(706);
    $calls_before = $c_state()['remote_get_calls'];
    \Simplixi\SUPCheckout\Payment\ReconciliationSweep::run();
    same($c_state()['remote_get_calls'] - $calls_before, 0, 'A6-05 C4 characterizes A1-06(b): the sweep never looks at the failed order (to be changed by U2)');
    same(array($order->get_status(), $order->payment_complete_calls, count($order->notes)), array('failed', 0, 1), 'A6-05 C4 characterizes A1-05: a later capture of the second track is never seen; the order stays failed with only the failure note (to be changed by U1)');

    // C5 (process_order_status :394-395): the fresh read under the lock is no
    // longer a UPayments order.
    list($gateway, $order) = reset_fixture(707);
    set_provider_transaction(transaction_for($order));
    $fresh707 = null;
    \Simplixi\SUPCheckout\Payment\state()['remote_mutator'] = function () use ($order, &$fresh707) {
        $fresh707 = clone $order; $fresh707->payment_method = 'cod';
        \Simplixi\SUPCheckout\Payment\state()['orders'][$order->get_id()] = $fresh707;
    };
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'fresh_order_preflight_failed'), 'A6-05 C5 fresh_order_preflight_failed when the order changed gateway under the lock');
    same(array($order->payment_complete_calls, $fresh707->payment_complete_calls), array(0, 0), 'A6-05 C5 fresh_order_preflight_failed completes neither snapshot');
    same($fresh707->get_meta('_simplixpay_upayments_status_track_v1'), '', 'A6-05 C5 fresh_order_preflight_failed promotes no trusted cursor');
    ok($c_lock_free(707), 'A6-05 C5 fresh_order_preflight_failed releases the order lock');
    ok(!$c_reconcile_scheduled(707), 'A6-05 C5 fresh_order_preflight_failed schedules nothing');

    // C6 (:409-411): refunded under the lock clears reconciliation, never pays.
    list($gateway, $order) = reset_fixture(708);
    private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-abc', 'merchant-order-708'));
    \Simplixi\SUPCheckout\Payment\state()['scheduled']['simplixpay_upayments_reconcile_order|[708]'] = time() + 60;
    set_provider_transaction(transaction_for($order));
    $fresh708 = null;
    \Simplixi\SUPCheckout\Payment\state()['remote_mutator'] = function () use ($order, &$fresh708) {
        $fresh708 = clone $order; $fresh708->status = 'refunded';
        \Simplixi\SUPCheckout\Payment\state()['orders'][$order->get_id()] = $fresh708;
    };
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'refunded'), 'A6-05 C6 an order refunded under the lock reports refunded');
    same(array($fresh708->get_status(), $fresh708->payment_complete_calls), array('refunded', 0), 'A6-05 C6 the refunded order is never completed');
    same($fresh708->get_meta('_simplixpay_upayments_unverified_track_v1'), '', 'A6-05 C6 refunded under the lock clears the unverified cursor');
    ok(!$c_reconcile_scheduled(708), 'A6-05 C6 refunded under the lock unschedules reconciliation');
    ok($c_lock_free(708), 'A6-05 C6 refunded under the lock releases the order lock');

    // C7 (:424-426): the fresh order's current requested identity does not parse.
    list($gateway, $order) = reset_fixture(709);
    $order->meta['UPayments_order_id'] = 'merchant order 709';
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'reconcile');
    same($out, array('state' => 'unchanged', 'reason' => 'fresh_requested_order_invalid'), 'A6-05 C7 fresh_requested_order_invalid for an unparseable current requested identity');
    same(array($order->get_status(), $order->payment_complete_calls), array('pending', 0), 'A6-05 C7 fresh_requested_order_invalid never completes the order');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), '', 'A6-05 C7 fresh_requested_order_invalid promotes no trusted cursor');
    ok($c_lock_free(709) && !$c_reconcile_scheduled(709), 'A6-05 C7 fresh_requested_order_invalid releases the lock and schedules nothing');

    // C8 (:430-434): a trusted track without its requested identity fails closed.
    list($gateway, $order) = reset_fixture(710);
    $order->meta['_simplixpay_upayments_status_track_v1'] = 'track-abc';
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'reconcile');
    same($out, array('state' => 'unchanged', 'reason' => 'trusted_cursor_requested_missing'), 'A6-05 C8 trusted_cursor_requested_missing fails closed');
    same(array($order->get_status(), $order->payment_complete_calls, $order->get_transaction_id()), array('pending', 0, ''), 'A6-05 C8 trusted_cursor_requested_missing never completes the order');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-abc', 'A6-05 C8 trusted_cursor_requested_missing keeps the trusted track as it was');
    ok($c_logged('trusted_cursor_requested_missing') && $c_lock_free(710), 'A6-05 C8 trusted_cursor_requested_missing is logged and the lock released');

    // C9 (:435-440): an earlier attempt's trusted cursor found under the lock is
    // reset; the current attempt's track is promoted and starts a fresh schedule.
    // The earlier attempt's track is NOT queued for the capture-only path here.
    list($gateway, $order) = reset_fixture(711);
    $c_trust($order, 'track-old', 'merchant-order-711-old');
    $order->meta['_simplixpay_upayments_reconcile_attempt_v1'] = 3;
    $order->meta['_simplixpay_upayments_reconcile_exhausted_v1'] = 1;
    set_provider_transaction(transaction_for($order, 'PENDING', 'track-new', null));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-new', 'reconcile');
    same($out, array('state' => 'pending', 'reason' => 'pending'), 'A6-05 C9 a stale-attempt trusted cursor under the lock does not block the current attempt');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-new', 'A6-05 C9 the current track becomes trusted');
    same($order->get_meta('_simplixpay_upayments_status_requested_v1'), 'merchant-order-711', 'A6-05 C9 the trusted cursor is paired to the current attempt');
    same($order->get_meta('_simplixpay_upayments_reconcile_exhausted_v1'), '', 'A6-05 C9 the stale attempt\'s exhaustion flag is reset');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'A6-05 C9 the current attempt starts its own first reconciliation attempt');
    ok($c_reconcile_scheduled(711), 'A6-05 C9 the current attempt is scheduled for reconciliation');
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), '', 'A6-05 C9 the earlier attempt\'s track is not queued for the capture-only path');

    // C10 characterizes A6-03 (:489-499): a non-capture exception under the lock
    // returns lifecycle_exception and schedules no retry (to be changed by U1).
    list($gateway, $order) = reset_fixture(712);
    $throwing_save = new class(712, 'merchant-order-712') extends FakeOrder {
        public $throw_on_save = false;
        public function save() {
            if ($this->throw_on_save) { throw new \RuntimeException('simulated save failure'); }
            return parent::save();
        }
    };
    \Simplixi\SUPCheckout\Payment\state()['orders'][712] = $throwing_save;
    private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($throwing_save, 'track-abc', 'merchant-order-712'));
    $throwing_save->throw_on_save = true;
    set_provider_transaction(transaction_for($throwing_save, 'PENDING', 'track-abc', null));
    $out = PaymentLifecycle::process_order_status($gateway, $throwing_save, 'track-abc', 'reconcile');
    same($out, array('state' => 'unchanged', 'reason' => 'lifecycle_exception'), 'A6-05 C10 a throw under the lock reports lifecycle_exception');
    ok(!$c_reconcile_scheduled(712), 'A6-05 C10 characterizes A6-03: a non-capture exception schedules no retry (to be changed by U1)');
    same(array($throwing_save->get_status(), count($throwing_save->notes), $throwing_save->payment_complete_calls), array('pending', 0, 0), 'A6-05 C10 characterizes A6-03: the exception leaves the order pending without a note (to be changed by U1)');
    ok($c_logged('lifecycle_exception') && $c_lock_free(712), 'A6-05 C10 lifecycle_exception is logged and the lock released');

    // C11 (attempt_secondary_capture :775-776): secondary lock contention is
    // re-queued for the bounded secondary retry.
    list($gateway, $order) = reset_fixture(713);
    $rotate($order, 'merchant-order-713-b');
    set_provider_transaction($prior_tx($order, 'merchant-order-713', 'CAPTURED', 'track-a', 'pay-a'));
    $c_hold_lock(713);
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-713', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'order_lock_contention'), 'A6-05 C11 secondary lock contention reports order_lock_contention');
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), array(array('track' => 'track-a', 'requested' => 'merchant-order-713')), 'A6-05 C11 secondary lock contention re-queues the track');
    ok($c_secondary_scheduled(713), 'A6-05 C11 secondary lock contention schedules the secondary retry');
    same(array($order->get_status(), $order->payment_complete_calls, $order->get_transaction_id()), array('pending', 0, ''), 'A6-05 C11 secondary lock contention never completes the order');

    // C12 (:807-809): a second track carrying the paid order's own payment ID is
    // answered as already verified, with no note and no unapplied record.
    list($gateway, $order) = reset_fixture(714);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-1', 'pay-a'));
    PaymentLifecycle::route_callback($gateway, $order, 'track-1', 'merchant-order-714', 'webhook');
    $notes_before = count($order->notes);
    set_provider_transaction(transaction_for($order, 'CAPTURED', 'track-2', 'pay-a'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-2', 'merchant-order-714', 'webhook');
    same($out, array('state' => 'captured', 'reason' => 'already_verified'), 'A6-05 C12 the same payment ID on a paid order is answered as already verified');
    same(array(count($order->notes) - $notes_before, $order->payment_complete_calls, $order->get_transaction_id()), array(0, 1, 'pay-a'), 'A6-05 C12 the same payment ID adds no note and never re-completes');
    same($order->get_meta('_simplixpay_upayments_unapplied_captures_v1'), '', 'A6-05 C12 the same payment ID records no unapplied capture');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-1', 'A6-05 C12 the paid order keeps its trusted track');

    // C13 (:815-817): an UNPAID order already carrying a different transaction ID
    // records the capture as a duplicate and stays unpaid.
    list($gateway, $order) = reset_fixture(715);
    $rotate($order, 'merchant-order-715-b');
    $order->transaction_id = 'pay-other';
    set_provider_transaction($prior_tx($order, 'merchant-order-715', 'CAPTURED', 'track-a', 'pay-a'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-715', 'webhook');
    same($out, array('state' => 'duplicate_capture', 'reason' => 'duplicate_capture'), 'A6-05 C13 an unpaid order with a different transaction ID gets duplicate_capture');
    same(array($order->get_status(), $order->payment_complete_calls, $order->get_transaction_id()), array('pending', 0, 'pay-other'), 'A6-05 C13 duplicate_capture leaves the unpaid order and its transaction ID unchanged');
    same($order->get_meta('_simplixpay_upayments_unapplied_captures_v1'), array('pay-a'), 'A6-05 C13 duplicate_capture records the payment ID once');
    ok(count($order->notes) === 1 && strpos($order->notes[0], 'pay-a') !== false && strpos($order->notes[0], 'already-paid') !== false, 'A6-05 C13 duplicate_capture adds one note naming the payment, in the already-paid wording');
    same((string) $order->get_meta('_upay_verified_capture'), '', 'A6-05 C13 duplicate_capture never sets the verified barrier');
    PaymentLifecycle::process_secondary_capture($gateway, $order, 'track-a', 'merchant-order-715', 'webhook');
    same(count($order->notes), 1, 'A6-05 C13 a replayed duplicate adds no second note');

    // C14 (:820-822): a secondary postcondition failure WITHOUT a throw (payment
    // complete leaves the order unpaid) is surfaced once and re-queued.
    list($gateway, $order) = reset_fixture(716);
    $silent = new class(716, 'merchant-order-716-b') extends FakeOrder {
        public function payment_complete($transaction_id = '') { $this->payment_complete_calls++; }
    };
    $silent->meta[$prior_meta] = array('merchant-order-716');
    \Simplixi\SUPCheckout\Payment\state()['orders'][716] = $silent;
    set_provider_transaction($prior_tx($silent, 'merchant-order-716', 'CAPTURED', 'track-a', 'pay-a'));
    $out = PaymentLifecycle::process_secondary_capture($gateway, $silent, 'track-a', 'merchant-order-716', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'payment_complete_failed'), 'A6-05 C14 a silent secondary postcondition failure reports payment_complete_failed');
    same(array($silent->get_status(), $silent->payment_complete_calls), array('pending', 1), 'A6-05 C14 the order stays unpaid after one completion call');
    same((string) $silent->get_meta('_simplixpay_upayments_capture_apply_failed_v1'), '1', 'A6-05 C14 the apply failure is recorded');
    same(count($silent->notes), 1, 'A6-05 C14 the apply failure adds one note');
    same(array($silent->get_meta('UPayments_Result'), $silent->get_meta('UPayments_PaymentID'), (string) $silent->get_meta('_upay_verified_capture')), array('', '', ''), 'A6-05 C14 staged legacy capture meta is removed and no verified barrier is set');
    same($silent->get_meta('_simplixpay_upayments_secondary_pending_v1'), array(array('track' => 'track-a', 'requested' => 'merchant-order-716')), 'A6-05 C14 the failed secondary capture is re-queued');
    ok($c_secondary_scheduled(716) && !$c_reconcile_scheduled(716), 'A6-05 C14 only the secondary retry is scheduled');

    // C15 (apply_captured :1185-1192): a primary postcondition failure restores
    // legacy capture meta that existed before the attempt to its exact old values.
    list($gateway, $order) = reset_fixture(717);
    $silent_primary = new class(717, 'merchant-order-717') extends FakeOrder {
        public function payment_complete($transaction_id = '') { $this->payment_complete_calls++; }
    };
    $old_legacy = array(
        'UPayments_Result' => 'NOT CAPTURED',
        'UPayments_PaymentID' => 'pay-old',
        'UPayments_TrackID' => 'track-old',
        'UPayments_Ref' => 'ref-old',
        'UPayments_payment_type' => 'CC',
    );
    foreach ($old_legacy as $key => $value) { $silent_primary->meta[$key] = $value; }
    \Simplixi\SUPCheckout\Payment\state()['orders'][717] = $silent_primary;
    set_provider_transaction(transaction_for($silent_primary, 'CAPTURED', 'track-abc', 'pay-new'));
    $out = PaymentLifecycle::process_order_status($gateway, $silent_primary, 'track-abc', 'reconcile');
    same($out['reason'], 'payment_complete_failed', 'A6-05 C15 a primary postcondition failure reports payment_complete_failed');
    $restored = array();
    foreach (array_keys($old_legacy) as $key) { $restored[$key] = $silent_primary->get_meta($key); }
    same($restored, $old_legacy, 'A6-05 C15 pre-existing legacy capture meta is restored to its exact prior values');
    same((string) $silent_primary->get_meta('_upay_verified_capture'), '', 'A6-05 C15 no verified barrier survives the failure');
    same(array($silent_primary->get_status(), count($silent_primary->notes)), array('pending', 1), 'A6-05 C15 the order stays unpaid with one apply-failure note');

    // C16 characterizes A6-03 (schedule_secondary :905-907): a refused secondary
    // schedule is only logged; the queue is written but nothing re-checks it and
    // the merchant gets no note (to be changed by U1).
    list($gateway, $order) = reset_fixture(718);
    $rotate($order, 'merchant-order-718-b');
    $order->meta['_simplixpay_upayments_callback_lookups_v1'] = gmdate('YmdHi') . ':3';
    \Simplixi\SUPCheckout\Payment\state()['schedule_fails'] = true;
    $out = PaymentLifecycle::route_callback($gateway, $order, 'track-a', 'merchant-order-718', 'webhook');
    same($out['reason'], 'callback_rate_limited', 'A6-05 C16 the throttled secondary lookup is deferred');
    same($order->get_meta('_simplixpay_upayments_secondary_pending_v1'), array(array('track' => 'track-a', 'requested' => 'merchant-order-718')), 'A6-05 C16 the deferred track is still queued');
    ok(!$c_secondary_scheduled(718), 'A6-05 C16 the refused secondary schedule leaves nothing scheduled');
    ok($c_logged('secondary_schedule_failed'), 'A6-05 C16 secondary_schedule_failed is logged');
    same(count($order->notes), 0, 'A6-05 C16 characterizes A6-03: a refused secondary schedule adds no merchant note (to be changed by U1)');

    // C17 (StatusVerifier :55-57): status_rate_limited from verify() is a
    // retryable reason, so a matching cursor schedules reconciliation.
    list($gateway, $order) = reset_fixture(719);
    private_call(PaymentLifecycle::class, 'remember_unverified_cursor', array($order, 'track-abc', 'merchant-order-719'));
    for ($i = 0; $i < 24; $i++) { StatusRateGate::acquire($gateway, 'webhook'); }
    set_provider_transaction(transaction_for($order));
    $out = PaymentLifecycle::process_order_status($gateway, $order, 'track-abc', 'webhook');
    same($out, array('state' => 'unchanged', 'reason' => 'status_rate_limited'), 'A6-05 C17 an exhausted global rate gate reports status_rate_limited');
    same($c_state()['remote_get_calls'], 0, 'A6-05 C17 status_rate_limited makes no provider call');
    ok($c_reconcile_scheduled(719), 'A6-05 C17 status_rate_limited schedules reconciliation for the matching cursor');
    same((int) $order->get_meta('_simplixpay_upayments_reconcile_attempt_v1'), 1, 'A6-05 C17 status_rate_limited consumes the first attempt');
    same($order->get_meta('_simplixpay_upayments_reconcile_reason_v1'), 'status_rate_limited', 'A6-05 C17 status_rate_limited is recorded as the reconciliation reason');
    same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), 'track-abc', 'A6-05 C17 status_rate_limited keeps the unverified cursor');

    // C18 characterizes A1-04: an authenticated amount or currency mismatch on the
    // current attempt leaves the order pending with no note and no re-check.
    foreach (array('amount' => array('total_price', '10.001', 'binding_amount'), 'currency' => array('currency_type', 'AED', 'binding_currency')) as $label => $case) {
        list($gateway, $order) = reset_fixture($label === 'amount' ? 720 : 721);
        $tx = transaction_for($order);
        $tx[$case[0]] = $case[1];
        set_provider_transaction($tx);
        $first = PaymentLifecycle::route_callback($gateway, $order, 'track-abc', $order->get_meta('UPayments_order_id'), 'webhook');
        $second = PaymentLifecycle::route_callback($gateway, $order, 'track-abc', $order->get_meta('UPayments_order_id'), 'webhook');
        same(array($first['reason'], $second['reason']), array($case[2], $case[2]), "A6-05 C18 characterizes A1-04: an authenticated {$label} mismatch reports {$case[2]}");
        same(array($order->get_status(), $order->payment_complete_calls), array('pending', 0), "A6-05 C18 characterizes A1-04: the {$label} mismatch leaves the order pending");
        same(count($order->notes), 0, "A6-05 C18 characterizes A1-04: the {$label} mismatch leaves no note (to be changed by U1)");
        same($order->get_meta('_simplixpay_upayments_unverified_track_v1'), '', "A6-05 C18 characterizes A1-04: the {$label} mismatch clears the unverified cursor");
        ok(!$c_reconcile_scheduled($order->get_id()), "A6-05 C18 characterizes A1-04: the {$label} mismatch schedules no re-check (to be changed by U1)");
    }
    // C18b: the trusted-cursor variant through reconcile_order keeps the cursor,
    // schedules nothing and writes no note.
    list($gateway, $order) = reset_fixture(722);
    set_provider_transaction(transaction_for($order, 'PENDING', 'track-t', null));
    PaymentLifecycle::route_callback($gateway, $order, 'track-t', 'merchant-order-722', 'webhook');
    \Simplixi\SUPCheckout\Payment\clear_scheduled_for_order(722);
    $tx = transaction_for($order, 'CAPTURED', 'track-t', 'pay-t');
    $tx['total_price'] = '10.001';
    set_provider_transaction($tx);
    PaymentLifecycle::reconcile_order(722);
    same(array($order->get_status(), $order->payment_complete_calls, count($order->notes)), array('pending', 0, 0), 'A6-05 C18b characterizes A1-04: a trusted-cursor amount mismatch leaves the order pending without a note (to be changed by U1)');
    same($order->get_meta('_simplixpay_upayments_status_track_v1'), 'track-t', 'A6-05 C18b characterizes A1-04: the trusted cursor is kept');
    ok(!$c_reconcile_scheduled(722), 'A6-05 C18b characterizes A1-04: a trusted-cursor mismatch schedules no re-check (to be changed by U1)');
    \Simplixi\SUPCheckout\Payment\reset_state();

    // Static architecture/safety guards.
    $root = dirname(__DIR__, 2);
    $identity_source = @file_get_contents($root . '/src/Release/Identity.php');
    $lifecycle_source = file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
    $verifier_source = file_get_contents($root . '/src/Payment/StatusVerifier.php');
    $rate_source = file_get_contents($root . '/src/Payment/StatusRateGate.php');
    $lock_source = file_get_contents($root . '/src/Payment/OrderLock.php');
    // The gateway source is the main file plus the gateway class it registers
    // (src/Gateway/Gateway.php since Sub-project 0a Task 3).
    $gateway_main = @file_get_contents($root . '/supcheckout.php');
    $gateway_class = @file_get_contents($root . '/src/Gateway/Gateway.php');
    $gateway_source = is_string($gateway_main) && is_string($gateway_class) ? $gateway_main . "\n" . $gateway_class : false;

    // H1: the Charge path records the superseded provider identity before rotating it.
    $orchestrator_source = file_get_contents($root . '/src/Payment/CheckoutOrchestrator.php');
    ok(strpos($lifecycle_source, "PRIOR_REQUESTED_META = '_simplixpay_upayments_prior_requested_v1'") !== false, 'H1 lifecycle reads the prior-attempt history key');
    $prior_write = strpos($orchestrator_source, "'_simplixpay_upayments_prior_requested_v1'");
    // 0b Task 2: the rotation deletes through Keys (the legacy name until cutover).
    $rotation = strpos($orchestrator_source, 'Keys::delete_order_meta($order, "UPayments_order_id");');
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
    echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
    exit($fail === 0 ? 0 : 1);
}
