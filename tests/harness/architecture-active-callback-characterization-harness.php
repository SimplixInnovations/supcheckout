<?php
/**
 * Approach 3 T1 active callback characterization.
 *
 * Executes the real PaymentLifecycle in child PHP processes so the production
 * exit() boundary is observable without modifying runtime code. The synthetic
 * legacy callback is a sentinel only; it does not emulate the historical
 * gateway implementation.
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['t1_actions'] = array();
    $GLOBALS['t1_orders'] = array();
    $GLOBALS['t1_gateway'] = null;

    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        if (!isset($GLOBALS['t1_actions'][$hook])) {
            $GLOBALS['t1_actions'][$hook] = array();
        }
        $GLOBALS['t1_actions'][$hook][] = array(
            'callback' => $callback,
            'priority' => (int) $priority,
            'accepted_args' => (int) $accepted_args,
        );
        return true;
    }

    function t1_do_action($hook) {
        if (empty($GLOBALS['t1_actions'][$hook])) {
            return;
        }

        $callbacks = $GLOBALS['t1_actions'][$hook];
        usort($callbacks, function ($left, $right) {
            if ($left['priority'] === $right['priority']) {
                return 0;
            }
            return $left['priority'] < $right['priority'] ? -1 : 1;
        });

        foreach ($callbacks as $entry) {
            call_user_func($entry['callback']);
        }
    }

    function sanitize_text_field($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    function wc_get_order($order_id) {
        $order_id = (int) $order_id;
        return isset($GLOBALS['t1_orders'][$order_id]) ? $GLOBALS['t1_orders'][$order_id] : false;
    }

    function WC() {
        return new T1WooRuntime();
    }

    function wc_get_logger() {
        return new T1Logger();
    }

    function is_user_logged_in() {
        return false;
    }

    function home_url($path = '/') {
        return 'https://merchant.example.test' . ($path === '' ? '/' : (string) $path);
    }

    function wc_get_page_permalink($page) {
        return 'https://merchant.example.test/' . rawurlencode((string) $page) . '/';
    }

    function add_query_arg($key, $value, $url) {
        $separator = strpos((string) $url, '?') === false ? '?' : '&';
        return (string) $url . $separator . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    function wp_safe_redirect($url) {
        echo 'REDIRECT:' . (string) $url . "\n";
        return true;
    }

    function status_header($code) {
        echo 'STATUS:' . (int) $code . "\n";
    }

    function wp_unslash($value) {
        return $value;
    }

    class T1Logger {
        public function warning($message, $context = array()) {
            echo 'LOG:' . (string) $message . "\n";
        }

        public function info($message, $context = array()) {
            echo 'LOG:' . (string) $message . "\n";
        }
    }

    class T1Gateway {
        public function getAPIUrl($route = '') {
            return 'https://sandboxapi.upayments.com/api/v1/' . ltrim((string) $route, '/');
        }

        public function getCurrencyCode($currency) {
            return strtoupper((string) $currency);
        }

        public function getMode() {
            return true;
        }
    }

    class T1GatewayRegistry {
        public function payment_gateways() {
            return is_object($GLOBALS['t1_gateway'])
                ? array('upayments' => $GLOBALS['t1_gateway'])
                : array();
        }
    }

    class T1WooRuntime {
        public $cart = null;

        public function payment_gateways() {
            return new T1GatewayRegistry();
        }
    }

    class T1Order {
        private $id;
        private $meta;

        public function __construct($id, $provider_order_id) {
            $this->id = (int) $id;
            $this->meta = array(
                'UPayments_order_id' => (string) $provider_order_id,
            );
        }

        public function get_id() {
            return $this->id;
        }

        public function get_payment_method() {
            return 'upayments';
        }

        public function get_meta($key) {
            return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
        }

        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data($key) {
            unset($this->meta[$key]);
        }

        public function save() {
            return $this->id;
        }

        public function has_status($status) {
            return false;
        }

        public function get_currency() {
            return 'KWD';
        }

        public function get_total() {
            return '10.000';
        }
    }

    function t1_child_boot($scenario, $sentinel_priority) {
        $_GET = array();
        $_POST = array();
        $_SERVER = array('REQUEST_METHOD' => 'GET');

        $GLOBALS['t1_actions'] = array();
        $GLOBALS['t1_orders'] = array();
        $GLOBALS['t1_gateway'] = new T1Gateway();

        $order = new T1Order(42, 'merchant-order-42');
        $GLOBALS['t1_orders'][42] = $order;

        require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';

        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::bootstrap();

        add_action(
            'woocommerce_api_wc_upayments',
            function () {
                echo "LEGACY_SENTINEL\n";
            },
            (int) $sentinel_priority
        );

        if ($scenario === 'webhook-invalid') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        } elseif ($scenario === 'browser-invalid') {
            $_GET = array('page' => 'return');
        } elseif ($scenario === 'webhook-valid') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = array(
                'wc_order_id' => '42',
                'track_id' => 'track-42',
                'requested_order_id' => 'merchant-order-42',
            );
        } elseif ($scenario === 'browser-valid') {
            $_GET = array(
                'page' => 'return',
                'wc_order_id' => '42',
                'track_id' => 'track-42',
                'requested_order_id' => 'merchant-order-42',
            );
        } elseif ($scenario === 'public-status') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = array('get_order_status' => '1');
        } else {
            echo "UNKNOWN_SCENARIO\n";
            exit(64);
        }

        t1_do_action('woocommerce_api_wc_upayments');

        // The canonical priority-5 lifecycle must terminate every callback
        // scenario above before control can return here.
        echo "CALLBACK_RETURNED_UNEXPECTEDLY\n";
        exit(65);
    }

    function t1_run_child($scenario, $sentinel_priority) {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__FILE__)
            . ' --child '
            . escapeshellarg((string) $scenario)
            . ' '
            . escapeshellarg((string) $sentinel_priority);

        $descriptors = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return array(
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'proc_open failed',
            );
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return array(
            'exit' => (int) $exit,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        );
    }

    function t1_has($haystack, $needle) {
        return strpos((string) $haystack, (string) $needle) !== false;
    }

    function t1_not_has($haystack, $needle) {
        return strpos((string) $haystack, (string) $needle) === false;
    }

    function t1_assert($condition, $label) {
        global $t1_pass, $t1_fail;
        if ($condition) {
            echo "PASS: $label\n";
            $t1_pass++;
            return;
        }

        echo "FAIL: $label\n";
        $t1_fail++;
    }

    if (isset($argv[1]) && $argv[1] === '--child') {
        $scenario = isset($argv[2]) ? (string) $argv[2] : '';
        $priority = isset($argv[3]) ? (int) $argv[3] : 10;
        t1_child_boot($scenario, $priority);
    }

    $t1_pass = 0;
    $t1_fail = 0;

    $cases = array(
        'webhook-invalid' => array(
            'must' => array('STATUS:200'),
            'must_not' => array('LEGACY_SENTINEL', 'CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'browser-invalid' => array(
            'must' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'),
            'must_not' => array('LEGACY_SENTINEL', 'CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'webhook-valid' => array(
            'must' => array(
                'LOG:Payment lifecycle: status_credentials_missing',
                'STATUS:200',
            ),
            'must_not' => array('LEGACY_SENTINEL', 'CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'browser-valid' => array(
            'must' => array(
                'LOG:Payment lifecycle: status_credentials_missing',
                'REDIRECT:https://merchant.example.test/?upayments_verification=pending',
            ),
            'must_not' => array('LEGACY_SENTINEL', 'CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'public-status' => array(
            'must' => array(
                'STATUS:404',
                'Order status unavailable.',
            ),
            'must_not' => array(
                'status_credentials_missing',
                'LEGACY_SENTINEL',
                'CALLBACK_RETURNED_UNEXPECTEDLY',
            ),
        ),
    );

    foreach ($cases as $scenario => $expectations) {
        $result = t1_run_child($scenario, 10);

        t1_assert(
            $result['exit'] === 0,
            $scenario . ' child terminates through the production callback boundary'
        );
        t1_assert(
            $result['stderr'] === '',
            $scenario . ' produces no stderr diagnostics'
        );

        foreach ($expectations['must'] as $needle) {
            t1_assert(
                t1_has($result['stdout'], $needle),
                $scenario . ' emits expected evidence: ' . $needle
            );
        }
        foreach ($expectations['must_not'] as $needle) {
            t1_assert(
                t1_not_has($result['stdout'], $needle),
                $scenario . ' excludes forbidden evidence: ' . $needle
            );
        }
    }

    // Sensitivity proof: moving the synthetic legacy sentinel before priority 5
    // must make it visible. This demonstrates that the harness can detect a
    // precedence inversion rather than merely assuming the sentinel never runs.
    $sensitivity = t1_run_child('webhook-invalid', 4);
    t1_assert($sensitivity['exit'] === 0, 'priority sensitivity child terminates normally');
    t1_assert(
        t1_has($sensitivity['stdout'], 'LEGACY_SENTINEL'),
        'priority sensitivity detects a callback placed before PaymentLifecycle priority 5'
    );
    t1_assert(
        t1_has($sensitivity['stdout'], 'STATUS:200'),
        'priority sensitivity still reaches the real lifecycle after the earlier sentinel'
    );

    // Static compatibility characterization remains deliberately separate from
    // the active callback proof above.
    $root = dirname(__DIR__, 2);
    $gateway_source = file_get_contents($root . '/UPayments.php');
    $lifecycle_source = file_get_contents($root . '/src/Payment/PaymentLifecycle.php');

    t1_assert(is_string($gateway_source), 'legacy gateway source readable');
    t1_assert(is_string($lifecycle_source), 'active lifecycle source readable');

    if (is_string($gateway_source)) {
        t1_assert(
            strpos($gateway_source, 'function check_ipn_response') !== false,
            'legacy check_ipn_response compatibility method remains present'
        );
        t1_assert(
            strpos($gateway_source, 'function return_from_upayments') !== false,
            'legacy return_from_upayments compatibility method remains present'
        );
        t1_assert(
            strpos($gateway_source, 'function web_hook_handler') !== false,
            'legacy web_hook_handler compatibility method remains present'
        );
        t1_assert(
            strpos($gateway_source, 'function verify_payment_status') === false,
            'legacy verify_payment_status is retired after R5 consolidation'
        );
    }

    if (is_string($lifecycle_source)) {
        t1_assert(
            strpos(
                $lifecycle_source,
                "self::process_order_status(\$gateway, \$order, \$track_id, \$is_browser ? 'browser' : 'webhook')"
            ) !== false,
            'active callback routes valid browser/webhook input through process_order_status'
        );
        t1_assert(
            strpos($lifecycle_source, 'StatusVerifier::verify($gateway, $order, $track_id)') !== false,
            'active payment lifecycle delegates provider financial verification to StatusVerifier'
        );
        t1_assert(
            strpos($lifecycle_source, 'OrderLock::acquire($order_id)') !== false,
            'active payment lifecycle owns concurrency protection through OrderLock'
        );
    }

    echo "\n--- Approach 3 T1 Active Callback Characterization Report ---\n";
    echo "PASS: $t1_pass\n";
    echo "FAIL: $t1_fail\n";
    exit($t1_fail === 0 ? 0 : 1);
}
