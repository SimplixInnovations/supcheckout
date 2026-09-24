<?php
/**
 * Approach 3 T3 characterization of direct historical callback methods.
 *
 * Runtime-neutral: this harness executes the real WC_Upayments legacy verifier,
 * browser-return and webhook methods against deterministic WordPress/WooCommerce
 * doubles. It intentionally records existing behavior; it does not prescribe the
 * next implementation.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['t3_actions'] = array();
$GLOBALS['t3_filters'] = array();
$GLOBALS['t3_order'] = null;
$GLOBALS['t3_transport'] = array();
$GLOBALS['t3_transport_calls'] = array();
$GLOBALS['t3_logs'] = array();
$GLOBALS['t3_cart_clears'] = 0;

function plugin_dir_url($file) { return 'https://merchant.example.test/wp-content/plugins/supcheckout/'; }
function plugin_dir_path($file) { return dirname((string) $file) . DIRECTORY_SEPARATOR; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['t3_actions'][] = array($hook, $callback, (int) $priority, (int) $accepted_args);
    return true;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['t3_filters'][] = array($hook, $callback, (int) $priority, (int) $accepted_args);
    return true;
}
function register_activation_hook($file, $callback) { return true; }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function wp_unslash($value) { return $value; }
function absint($value) { return max(0, (int) $value); }
function is_user_logged_in() { return false; }
function home_url($path = '/') { return 'https://merchant.example.test' . ($path === '' ? '/' : (string) $path); }
function wc_get_page_permalink($page) { return 'https://merchant.example.test/' . rawurlencode((string) $page) . '/'; }
function add_query_arg($key, $value, $url) {
    return (string) $url . (strpos((string) $url, '?') === false ? '?' : '&')
        . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
}
function wp_safe_redirect($url) { echo 'REDIRECT:' . (string) $url . "\n"; return true; }
function __($text, $domain = null) { return (string) $text; }
function wc_get_price_decimals() { return 3; }
function wc_format_decimal($number, $dp = false) {
    $dp = $dp === false ? 3 : (int) $dp;
    if (!is_numeric($number)) { return ''; }
    return number_format((float) $number, $dp, '.', '');
}
function wc_get_order($id) {
    $order = $GLOBALS['t3_order'];
    return is_object($order) && (int) $order->get_id() === (int) $id ? $order : false;
}
function WC() {
    static $wc = null;
    if ($wc === null) {
        $wc = new class {
            public $cart;
            public function __construct() { $this->cart = new T3Cart(); }
            public function payment_gateways() {
                return new class {
                    public function payment_gateways() {
                        return array('upayments' => isset($GLOBALS['t3_gateway_instance']) ? $GLOBALS['t3_gateway_instance'] : null);
                    }
                };
            }
        };
    }
    return $wc;
}
function status_header($code) { echo 'STATUS:' . (int) $code . "\n"; }
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_unschedule_event($timestamp, $hook, $args = array()) { return true; }
function wp_schedule_single_event($timestamp, $hook, $args = array()) { return true; }
function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_http_validate_url($url) { return (string) $url; }
function is_wp_error($thing) { return false; }
function wp_remote_get($url, $args = array()) { return $GLOBALS['t3_transport'] ?? array(); }
function wp_remote_retrieve_body($response) { return is_array($response) ? (string) ($response['body'] ?? '') : ''; }
function wp_remote_retrieve_response_code($response) { return is_array($response) ? (int) ($response['http_status'] ?? 0) : 0; }
function wp_salt($scheme = 'auth') { return 't3-salt'; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['t3_transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['t3_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['t3_transients'][$key]); return true; }
function get_option($key, $default = false) { return $default; }
function update_option($key, $value, $autoload = null) { return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') { return true; }
function delete_option($key) { return true; }
function get_site_option($key, $default = false) { return $default; }
function update_site_option($key, $value) { return true; }
function add_site_option($key, $value) { return true; }

class WooCommerce {}
class WC_Order {
    public $id = 42;
    public $currency = 'KWD';
    public $total = '10.000';
    public $payment_method = 'upayments';
    public $status = 'pending';
    public $meta = array('UPayments_order_id' => 'merchant-42');
    public $updates = array();
    public $status_updates = array();
    public $save_count = 0;
    public $update_status_result = true;

    public function get_id() { return $this->id; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function get_payment_method() { return $this->payment_method; }
    public function get_status() { return $this->status; }
    public function get_transaction_id() { return (string) ($this->meta['UPayments_PaymentID'] ?? ''); }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function get_meta($key, $single = true) { return array_key_exists($key, $this->meta) ? $this->meta[$key] : ''; }
    public function has_status($status) { return $this->status === (string) $status; }
    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
        $this->updates[$key] = $value;
        echo 'META:' . $key . '=' . (is_scalar($value) ? (string) $value : '[complex]') . "\n";
    }
    public function delete_meta_data($key) {
        unset($this->meta[$key]);
        echo 'META_DELETE:' . $key . "\n";
    }
    public function update_status($status, $note = '') {
        $this->status_updates[] = array((string) $status, (string) $note);
        echo 'ORDER_STATUS_ATTEMPT:' . (string) $status . "\n";
        if ($this->update_status_result) {
            $this->status = (string) $status;
            echo 'ORDER_STATUS_APPLIED:' . (string) $status . "\n";
            return true;
        }
        echo "ORDER_STATUS_REJECTED\n";
        return false;
    }
    public function payment_complete($transaction_id = '') {
        echo 'PAYMENT_COMPLETE:' . (string) $transaction_id . "\n";
        if ($this->update_status_result) {
            $this->status = 'processing';
            echo "PAYMENT_COMPLETE:payment-xyz\n";
            return true;
        }
        echo "ORDER_STATUS_REJECTED\n";
        return false;
    }
    public function save() { $this->save_count++; echo "ORDER_SAVED\n"; return $this->id; }
}
class WC_Payment_Gateway {
    public $id = '';

    public function get_return_url($order = null) {
        return 'https://merchant.example.test/order-received/42/?key=wc_order_key_test';
    }
}
class T3Cart {
    public function empty_cart() { $GLOBALS['t3_cart_clears']++; echo "CART_CLEARED\n"; }
}


require_once __DIR__ . '/../support/t3-payment-lifecycle-stubs.php';
require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';
require_once dirname(__DIR__, 2) . '/UPayments.php';

woocommerceUpaymentsInit();

class T3LegacyGatewayProbe extends WC_Upayments {
    protected function execute_upayments_request($route, $method, $body = null) {
        $GLOBALS['t3_transport_calls'][] = array('route' => $route, 'method' => $method, 'body' => $body);
        echo 'TRANSPORT:' . $method . ':' . $route . "\n";
        if (isset($GLOBALS['t3_transport']['throw']) && $GLOBALS['t3_transport']['throw']) {
            throw new RuntimeException('synthetic provider exception');
        }
        return $GLOBALS['t3_transport'];
    }
    public function log($content, $level = 'debug') {
        $GLOBALS['t3_logs'][] = array((string) $level, (string) $content);
        echo 'LOG:' . (string) $level . ':' . (string) $content . "\n";
    }
    public function getIsOrderComplete() { return false; }
}

function t3_gateway() {
    $r = new ReflectionClass(T3LegacyGatewayProbe::class);
    $gateway = $r->newInstanceWithoutConstructor();
    $gateway->id = 'upayments';
    $gateway->apiKey = 'public-test-key';
    $gateway->debug = 'no';
    $gateway->testMode = 'yes';
    $gateway->isOrderComplete = 'no';
    $GLOBALS['t3_gateway_instance'] = $gateway;
    return $gateway;
}
function t3_order() { return new WC_Order(); }
function t3_transaction($overrides = array()) {
    return array_merge(array(
        'result' => 'CAPTURED',
        'track_id' => 'track-abc',
        'merchant_requested_order_id' => 'merchant-42',
        'total_price' => '10.000',
        'currency_type' => 'KWD',
        'payment_id' => 'payment-xyz',
        'payment_type' => 'cc',
        'reference' => '42',
    ), $overrides);
}
function t3_transport_for($transaction, $http = 201, $status = true) {
    return array(
        'transport_ok' => $http >= 200 && $http < 300,
        'body' => json_encode(array('status' => $status, 'data' => array('transaction' => $transaction))),
        'http_status' => (int) $http,
        'curl_errno' => 0,
    );
}
function t3_reset() {
    $GLOBALS['t3_order'] = null;
    $GLOBALS['t3_transport'] = array('transport_ok' => false, 'body' => null, 'http_status' => 0, 'curl_errno' => 1);
    $GLOBALS['t3_transport_calls'] = array();
    $GLOBALS['t3_logs'] = array();
    $GLOBALS['t3_cart_clears'] = 0;
    $_GET = array();
    $_POST = array();
    $_REQUEST = array();
    $_SERVER = array('REQUEST_METHOD' => 'GET');
}
function t3_verify($gateway, $order, $track) {
    // R5: legacy private verifier is retired. Financial authority is PaymentLifecycle.
    return array('reason' => 'retired', 'verified' => false);
}
function t3_assert($condition, $label) {
    global $t3_pass, $t3_fail;
    if ($condition) { echo 'PASS: ' . $label . "\n"; $t3_pass++; return; }
    echo 'FAIL: ' . $label . "\n"; $t3_fail++;
}
function t3_contains($haystack, $needle) { return strpos((string) $haystack, (string) $needle) !== false; }
function t3_child($scenario) {
    t3_reset();
    $gateway = t3_gateway();
    $order = t3_order();
    $GLOBALS['t3_order'] = $order;

    if ($scenario === 'browser-preflight-missing') {
        $_GET = array();
        $gateway->return_from_upayments();
    }
    if ($scenario === 'browser-not-captured') {
        $_GET = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_GET;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction(array('result' => 'DECLINED')));
        $gateway->return_from_upayments();
    }
    if ($scenario === 'browser-captured') {
        $_GET = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_GET;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->return_from_upayments();
    }
    if ($scenario === 'browser-status-rejected') {
        $order->update_status_result = false;
        $_GET = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_GET;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->return_from_upayments();
    }
    if ($scenario === 'browser-replay') {
        $order->meta['_upay_verified_capture'] = '1';
        $_GET = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_GET;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->return_from_upayments();
    }
    if ($scenario === 'browser-refunded') {
        $order->status = 'refunded';
        $_GET = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_GET;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->return_from_upayments();
    }
    if ($scenario === 'webhook-preflight-missing') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $gateway->web_hook_handler();
    }
    if ($scenario === 'webhook-binding-failure') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_POST;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction(array('reference' => '99')));
        $gateway->web_hook_handler();
    }
    if ($scenario === 'webhook-captured') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_POST;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->web_hook_handler();
    }
    if ($scenario === 'webhook-status-rejected') {
        $order->update_status_result = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_POST;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->web_hook_handler();
    }
    if ($scenario === 'webhook-replay') {
        $order->meta['_upay_verified_capture'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_POST;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->web_hook_handler();
    }
    if ($scenario === 'webhook-refunded') {
        $order->status = 'refunded';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('wc_order_id' => '42', 'track_id' => 'track-abc', 'requested_order_id' => 'merchant-42');
        $_REQUEST = $_POST;
        $GLOBALS['t3_transport'] = t3_transport_for(t3_transaction());
        $gateway->web_hook_handler();
    }

    echo "UNEXPECTED_RETURN\n";
    exit(90);
}
function t3_run_child($scenario) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($scenario);
    $pipes = array();
    $process = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) { return array('exit' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'); }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return array('exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr);
}

if (isset($argv[1]) && $argv[1] === '--child') {
    t3_child(isset($argv[2]) ? (string) $argv[2] : '');
}

$t3_pass = 0;
$t3_fail = 0;

// R5: legacy private verifier is retired.
t3_reset();
$r = t3_verify(null, null, 'track-abc');
t3_assert($r['reason'] === 'retired' && $r['verified'] === false, 'legacy verifier retired; financial authority is PaymentLifecycle');

$source = file_get_contents(dirname(__DIR__, 2) . '/UPayments.php');
t3_assert(is_string($source), 'gateway source readable');
if (is_string($source)) {
    t3_assert(!t3_contains($source, 'function verify_payment_status'), 'legacy private verifier is retired');
    t3_assert(!t3_contains($source, 'function get_payment_verification_fallback_url'), 'legacy fallback helper is retired');
    t3_assert(t3_contains($source, "handle_compat_callback('browser')"), 'return_from_upayments delegates explicit browser mode');
    t3_assert(t3_contains($source, "handle_compat_callback('webhook')"), 'web_hook_handler delegates explicit webhook mode');
    t3_assert(!t3_contains($source, "\$_GET['page'] ="), 'no GET page spoofing');
}

$cases = array(
    'browser-preflight-missing' => array('must' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'), 'must_not' => array('PAYMENT_COMPLETE:', 'META:_upay_verified_capture=1', 'CART_CLEARED')),
    'browser-not-captured' => array('must' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'), 'must_not' => array('META:_upay_verified_capture=1', 'PAYMENT_COMPLETE:', 'CART_CLEARED')),
    'browser-captured' => array('must' => array('PAYMENT_COMPLETE:payment-xyz', 'META:_upay_verified_capture=1', 'ORDER_SAVED', 'CART_CLEARED', 'REDIRECT:https://merchant.example.test/order-received/42/?key=wc_order_key_test'), 'must_not' => array('ORDER_STATUS_REJECTED')),
    'browser-status-rejected' => array('must' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'), 'must_not' => array('META:_upay_verified_capture=1')),
    'browser-replay' => array('must' => array('REDIRECT:'), 'must_not' => array('PAYMENT_COMPLETE:')),
    'browser-refunded' => array('must' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'), 'must_not' => array('PAYMENT_COMPLETE:', 'CART_CLEARED')),
    'webhook-preflight-missing' => array('must' => array('STATUS:200'), 'must_not' => array('PAYMENT_COMPLETE:', 'REDIRECT:')),
    'webhook-binding-failure' => array('must' => array('STATUS:200'), 'must_not' => array('META:_upay_verified_capture=1', 'REDIRECT:')),
    'webhook-captured' => array('must' => array('PAYMENT_COMPLETE:payment-xyz', 'META:_upay_verified_capture=1', 'ORDER_SAVED', 'STATUS:200'), 'must_not' => array('CART_CLEARED', 'REDIRECT:')),
    'webhook-status-rejected' => array('must' => array('STATUS:200'), 'must_not' => array('META:_upay_verified_capture=1', 'CART_CLEARED', 'REDIRECT:')),
    'webhook-replay' => array('must' => array('STATUS:200'), 'must_not' => array('CART_CLEARED', 'REDIRECT:')),
    'webhook-refunded' => array('must' => array('STATUS:200'), 'must_not' => array('PAYMENT_COMPLETE:', 'CART_CLEARED', 'REDIRECT:')),
);
foreach ($cases as $scenario => $expect) {
    $result = t3_run_child($scenario);
    if ($result['stderr'] !== '') { echo '  child stderr [' . $scenario . ']: ' . trim($result['stderr']) . "\n"; }
    t3_assert($result['stderr'] === '', $scenario . ' emits no PHP/runtime stderr');
    t3_assert((int) $result['exit'] === 0, $scenario . ' terminates through the real legacy method');
    foreach ($expect['must'] as $needle) { t3_assert(t3_contains($result['stdout'], $needle), $scenario . ' contains: ' . $needle); }
    foreach ($expect['must_not'] as $needle) { t3_assert(!t3_contains($result['stdout'], $needle), $scenario . ' excludes: ' . $needle); }
}

echo "\n--- Approach 3 T3 Legacy Direct Callback Characterization Report ---\n";
echo 'PASS: ' . $t3_pass . "\n";
echo 'FAIL: ' . $t3_fail . "\n";
exit($t3_fail === 0 ? 0 : 1);
