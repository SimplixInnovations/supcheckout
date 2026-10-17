<?php
/**
 * R2 ecosystem callback/cache harness.
 *
 * Executes real callback/status response paths in child PHP processes so the
 * production exit() boundary and no-cache policy emission remain observable.
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    $r2_pass = 0;
    $r2_fail = 0;

    function r2_assert($condition, $label) {
        global $r2_pass, $r2_fail;
        if ($condition) {
            echo "PASS: {$label}\n";
            $r2_pass++;
            return;
        }
        echo "FAIL: {$label}\n";
        $r2_fail++;
    }

    function r2_contains($haystack, $needle) {
        return strpos((string) $haystack, (string) $needle) !== false;
    }

    function r2_not_contains($haystack, $needle) {
        return strpos((string) $haystack, (string) $needle) === false;
    }

    function r2_run_child($scenario) {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__FILE__)
            . ' --child '
            . escapeshellarg((string) $scenario);

        $descriptors = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return array('exit' => 127, 'stdout' => '', 'stderr' => 'proc_open failed');
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

    function r2_define_common_stubs() {
        function wc_nocache_headers() {
            echo "NOCACHE_HEADERS\n";
            $GLOBALS['r2_nocache_calls'] = (int) $GLOBALS['r2_nocache_calls'] + 1;
        }

        function plugin_dir_url($file) {
            return 'https://merchant.example.test/wp-content/plugins/supcheckout/';
        }

        function plugin_dir_path($file) {
            return dirname((string) $file) . DIRECTORY_SEPARATOR;
        }

        function plugins_url($asset, $file = '') {
            return 'https://merchant.example.test/wp-content/plugins/supcheckout/' . $asset;
        }

        function register_activation_hook($file, $callback) {
            return true;
        }

        function register_deactivation_hook($file, $callback) {
            return true;
        }

        function wp_salt($scheme = 'auth') {
            return 'test_salt_value_for_hmac';
        }

        function site_url($path = '', $scheme = null) {
            return 'https://merchant.example.test' . (string) $path;
        }

        function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
            $GLOBALS['r2_actions'][] = array((string) $hook, $callback, (int) $priority);
            return true;
        }

        function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }

        function sanitize_text_field($value) {
            return is_scalar($value) ? trim((string) $value) : '';
        }

        function wp_unslash($value) {
            return $value;
        }

        function absint($value) {
            return max(0, (int) $value);
        }

        function is_user_logged_in() {
            return false;
        }

        function get_current_user_id() {
            return 0;
        }

        function home_url($path = '/') {
            return 'https://merchant.example.test' . ($path === '' ? '/' : (string) $path);
        }

        function wc_get_page_permalink($page) {
            return 'https://merchant.example.test/' . rawurlencode((string) $page) . '/';
        }

        function add_query_arg($key, $value = false, $url = false) {
            if (is_array($key)) {
                $params = $key;
                $url = (string) $value;
            } else {
                $params = array((string) $key => (string) $value);
                $url = (string) $url;
            }
            $separator = strpos((string) $url, '?') === false ? '?' : '&';
            $parts = array();
            foreach ($params as $param_key => $param_value) {
                $parts[] = rawurlencode((string) $param_key) . '=' . rawurlencode((string) $param_value);
            }
            return (string) $url . $separator . implode('&', $parts);
        }

        function wp_safe_redirect($url) {
            echo 'REDIRECT:' . (string) $url . "\n";
            return true;
        }

        function status_header($code) {
            echo 'STATUS:' . (int) $code . "\n";
        }

        function wp_send_json($payload, $status_code = null) {
            if ($status_code !== null) {
                status_header((int) $status_code);
            }
            echo 'JSON:' . json_encode($payload) . "\n";
            exit(0);
        }

        function wp_json_encode($data, $options = 0, $depth = 512) {
            return json_encode($data, $options, $depth);
        }

        function __($text, $domain = null) {
            return (string) $text;
        }

        function wc_get_order($id) {
            $order = isset($GLOBALS['r2_order']) ? $GLOBALS['r2_order'] : null;
            return is_object($order) && (int) $order->get_id() === (int) $id ? $order : false;
        }

        function wc_get_logger() {
            return new class {
                public function warning($message, $context = array()) {
                    echo 'LOG:' . (string) $message . "\n";
                }

                public function info($message, $context = array()) {
                    echo 'LOG:' . (string) $message . "\n";
                }
            };
        }

        function WC() {
            static $wc = null;
            if ($wc === null) {
                $wc = new R2WooRuntime();
            }
            return $wc;
        }
    }

    class R2WooRuntime {
        public $cart = null;
    }

    class WooCommerce {
    }

    class R2Cart {
        public function empty_cart() {
            echo "CART_CLEARED\n";
        }
    }

    class R2Order {
        public $id = 42;
        public $payment_method = 'upayments';
        public $status = 'pending';
        public $meta = array('UPayments_order_id' => 'merchant-42');
        public $order_key = 'wc_order_key_test';

        public function get_id() {
            return $this->id;
        }

        public function get_payment_method() {
            return $this->payment_method;
        }

        public function get_status() {
            return $this->status;
        }

        public function get_meta($key, $single = true) {
            return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
        }

        public function get_order_key() {
            return $this->order_key;
        }

        public function get_user_id() {
            return 0;
        }

        public function has_status($status) {
            return $this->status === (string) $status;
        }

        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data($key) {
            unset($this->meta[$key]);
        }

        public function meta_exists($key) {
            return array_key_exists($key, $this->meta);
        }

        public function update_status($status, $note = '') {
            $this->status = (string) $status;
            return true;
        }

        public function save() {
            return $this->id;
        }

        public function get_transaction_id() {
            return '';
        }

        public function is_paid() {
            return false;
        }

        public function payment_complete($txn = '') {
            return true;
        }
    }

    class R2Gateway {
        public $id = 'upayments';

        public function get_return_url($order = null) {
            return 'https://merchant.example.test/order-received/42/?key=wc_order_key_test';
        }

        public function getIsOrderComplete() {
            return false;
        }

        public function getMode() {
            return true;
        }

        public function log($message, $level = 'info') {
            echo 'GATEWAY_LOG:' . (string) $message . "\n";
        }
    }

    function r2_child_lifecycle($scenario) {
        r2_define_common_stubs();
        $GLOBALS['r2_nocache_calls'] = 0;
        $GLOBALS['r2_order'] = new R2Order();
        WC()->cart = new R2Cart();

        $_GET = array();
        $_POST = array();
        $_SERVER = array('REQUEST_METHOD' => 'GET');

        if ($scenario === 'browser-invalid') {
            $_GET = array('page' => 'success');
        } elseif ($scenario === 'webhook-invalid') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        } elseif ($scenario === 'public-status-unavailable') {
            $_GET = array('get_order_status' => '1');
        } elseif ($scenario === 'public-status-success') {
            $_GET = array(
                'get_order_status' => '1',
                'wc_order_id' => '42',
                'key' => 'wc_order_key_test',
            );
            $GLOBALS['r2_order']->meta['UPayments_WHS'] = 'pending';
        } else {
            echo "UNKNOWN_SCENARIO\n";
            exit(64);
        }

        require_once dirname(__DIR__, 2) . '/src/Takeover/TakeoverState.php';
        require_once dirname(__DIR__, 2) . '/src/Takeover/Keys.php';
        require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::bootstrap();
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_callback();

        echo "CALLBACK_RETURNED_UNEXPECTEDLY\n";
        exit(65);
    }

    /**
     * 0b Task 8: one request dispatched through every listener PaymentLifecycle::bootstrap()
     * attached to woocommerce_api_{route}, in priority order (the gateway's priority-10
     * fallback is not constructed here; architecture-legacy-callback-routing-harness covers
     * it). The order's state is printed at shutdown, after the production exit().
     */
    function r2_child_route($route, $scenario) {
        r2_define_common_stubs();
        $GLOBALS['r2_actions'] = array();
        $GLOBALS['r2_nocache_calls'] = 0;
        $GLOBALS['r2_order'] = new R2Order();
        WC()->cart = new R2Cart();
        $_GET = array();
        $_POST = array();
        $_SERVER = array('REQUEST_METHOD' => 'GET');

        $requests = array(
            'browser-invalid' => array('page' => 'success'),
            'webhook-invalid' => array(),
            'public-status-unavailable' => array('get_order_status' => '1'),
            'public-status-success' => array('get_order_status' => '1', 'wc_order_id' => '42', 'key' => 'wc_order_key_test'),
            'probe' => array('supcheckout_probe' => '1'),
        );
        if (!isset($requests[$scenario]) || !in_array($route, array('wc_upayments', 'supcheckout'), true)) {
            echo "UNKNOWN_SCENARIO\n";
            exit(64);
        }
        $_GET = $requests[$scenario];
        if ($scenario === 'webhook-invalid') {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        }
        if ($scenario === 'public-status-success') {
            $GLOBALS['r2_order']->meta['UPayments_WHS'] = 'pending';
        }

        // The CLI SAPI keeps no headers; record the probe's own.
        eval('namespace Simplixi\SUPCheckout\Takeover; function header($line) { echo "HEADER:" . $line . "\n"; } function headers_sent() { return false; }');
        register_shutdown_function(function () {
            $order = $GLOBALS['r2_order'];
            echo 'ORDER_STATE:' . json_encode(array($order->status, $order->meta)) . "\n";
        });

        require_once dirname(__DIR__, 2) . '/src/Takeover/TakeoverState.php';
        require_once dirname(__DIR__, 2) . '/src/Takeover/Keys.php';
        require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::bootstrap();

        $queue = array();
        foreach ($GLOBALS['r2_actions'] as $index => $action) {
            if ($action[0] === 'woocommerce_api_' . $route) {
                $queue[] = array($action[2], $index, $action[1]);
            }
        }
        usort($queue, function ($left, $right) {
            return $left[0] === $right[0] ? $left[1] <=> $right[1] : $left[0] <=> $right[0];
        });
        echo 'LISTENERS:' . count($queue) . "\n";
        foreach ($queue as $entry) {
            call_user_func($entry[2]);
        }

        echo "CALLBACK_RETURNED_UNEXPECTEDLY\n";
        exit(65);
    }

    function r2_child_direct_return() {
        r2_define_common_stubs();
        $GLOBALS['r2_nocache_calls'] = 0;
        $GLOBALS['r2_order'] = new R2Order();
        WC()->cart = new R2Cart();
        $_GET = array();
        $_SERVER = array('REQUEST_METHOD' => 'GET');

        class WC_Payment_Gateway {
            public $id = 'upayments';

            public function get_return_url($order = null) {
                return 'https://merchant.example.test/order-received/42/?key=wc_order_key_test';
            }
        }

        require_once dirname(__DIR__, 2) . '/supcheckout.php';
        \Simplixi\SUPCheckout\Bootstrap\Requirements::check_woocommerce();

        $reflection = new ReflectionClass('Simplixi\SUPCheckout\Gateway\Gateway');
        /** @var Simplixi\SUPCheckout\Gateway\Gateway $gateway */
        $gateway = $reflection->newInstanceWithoutConstructor();
        $gateway->id = 'upayments';
        $gateway->return_from_upayments();
        echo "DIRECT_RETURN_UNEXPECTEDLY\n";
        exit(66);
    }

    function r2_child_direct_webhook() {
        r2_define_common_stubs();
        $GLOBALS['r2_nocache_calls'] = 0;
        $GLOBALS['r2_order'] = new R2Order();
        WC()->cart = new R2Cart();
        $_GET = array();
        $_POST = array();
        $_REQUEST = array();
        $_SERVER = array('REQUEST_METHOD' => 'POST');

        class WC_Payment_Gateway {
            public $id = 'upayments';

            public function get_return_url($order = null) {
                return 'https://merchant.example.test/order-received/42/?key=wc_order_key_test';
            }
        }

        require_once dirname(__DIR__, 2) . '/supcheckout.php';
        \Simplixi\SUPCheckout\Bootstrap\Requirements::check_woocommerce();

        $reflection = new ReflectionClass('Simplixi\SUPCheckout\Gateway\Gateway');
        /** @var Simplixi\SUPCheckout\Gateway\Gateway $gateway */
        $gateway = $reflection->newInstanceWithoutConstructor();
        $gateway->id = 'upayments';
        $gateway->web_hook_handler();
        echo "DIRECT_WEBHOOK_UNEXPECTEDLY\n";
        exit(67);
    }

    if (isset($argv[1]) && $argv[1] === '--child') {
        $scenario = isset($argv[2]) ? (string) $argv[2] : '';
        if ($scenario === 'direct-return') {
            r2_child_direct_return();
        }
        if ($scenario === 'direct-webhook') {
            r2_child_direct_webhook();
        }
        if (strpos($scenario, 'route:') === 0) {
            $parts = explode(':', $scenario, 3);
            r2_child_route(isset($parts[1]) ? $parts[1] : '', isset($parts[2]) ? $parts[2] : '');
        }
        r2_child_lifecycle($scenario);
    }

    $cases = array(
        'browser-invalid' => array(
            'must' => array(
                'NOCACHE_HEADERS',
                'REDIRECT:https://merchant.example.test/?upayments_verification=pending',
            ),
            'must_not' => array('CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'webhook-invalid' => array(
            'must' => array(
                'NOCACHE_HEADERS',
                'STATUS:200',
            ),
            'must_not' => array('CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'public-status-unavailable' => array(
            'must' => array(
                'NOCACHE_HEADERS',
                'STATUS:404',
                'Order status unavailable.',
            ),
            'must_not' => array('CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'public-status-success' => array(
            'must' => array(
                'NOCACHE_HEADERS',
                'STATUS:200',
                '"status":"pending"',
            ),
            'must_not' => array('CALLBACK_RETURNED_UNEXPECTEDLY'),
        ),
        'direct-return' => array(
            'must' => array(
                'NOCACHE_HEADERS',
                'REDIRECT:https://merchant.example.test/?upayments_verification=pending',
            ),
            'must_not' => array('DIRECT_RETURN_UNEXPECTEDLY'),
        ),
        'direct-webhook' => array(
            'must' => array(
                'NOCACHE_HEADERS',
            ),
            'must_not' => array('DIRECT_WEBHOOK_UNEXPECTEDLY'),
        ),
    );

    foreach ($cases as $scenario => $expectations) {
        $result = r2_run_child($scenario);
        if ($result['stderr'] !== '') {
            echo '  child stderr [' . $scenario . ']: ' . trim($result['stderr']) . "\n";
        }

        r2_assert($result['exit'] === 0, "child exits cleanly: {$scenario}");
        foreach ($expectations['must'] as $needle) {
            r2_assert(r2_contains($result['stdout'], $needle), "{$scenario} emits {$needle}");
        }
        foreach ($expectations['must_not'] as $needle) {
            r2_assert(r2_not_contains($result['stdout'], $needle), "{$scenario} omits {$needle}");
        }

        if ($scenario === 'browser-invalid' || $scenario === 'webhook-invalid') {
            $nocache_pos = strpos($result['stdout'], 'NOCACHE_HEADERS');
            $terminal_pos = strpos($result['stdout'], 'REDIRECT:');
            if ($terminal_pos === false) {
                $terminal_pos = strpos($result['stdout'], 'STATUS:');
            }
            r2_assert(
                $nocache_pos !== false
                && $terminal_pos !== false
                && $nocache_pos < $terminal_pos,
                "{$scenario} emits no-cache before terminal response"
            );
        }
    }

    // 0b Task 8: the same requests on both WC-API routes. Each keeps its no-cache policy
    // before the terminal response, leaves the order identical, and the two routes'
    // output (status, redirect, JSON, no-cache emission, order state) is byte-identical.
    foreach ($cases as $scenario => $expectations) {
        if (strpos($scenario, 'direct-') === 0) {
            continue;
        }
        $by_route = array();
        foreach (array('wc_upayments', 'supcheckout') as $route) {
            $result = r2_run_child('route:' . $route . ':' . $scenario);
            $by_route[$route] = $result;
            if ($result['stderr'] !== '') {
                echo '  child stderr [' . $route . ':' . $scenario . ']: ' . trim($result['stderr']) . "\n";
            }
            r2_assert($result['exit'] === 0 && $result['stderr'] === '', "{$route} {$scenario} exits cleanly through its route listener");
            r2_assert(r2_contains($result['stdout'], "LISTENERS:1\n"), "{$route} {$scenario} has exactly one lifecycle listener on woocommerce_api_{$route}");
            foreach ($expectations['must'] as $needle) {
                r2_assert(r2_contains($result['stdout'], $needle), "{$route} {$scenario} emits {$needle}");
            }
            r2_assert(r2_not_contains($result['stdout'], 'CALLBACK_RETURNED_UNEXPECTEDLY'), "{$route} {$scenario} terminates");
            r2_assert(r2_not_contains($result['stdout'], 'HEADER:'), "{$route} {$scenario} is not answered as a probe");
            $nocache_pos = strpos($result['stdout'], 'NOCACHE_HEADERS');
            $terminal_pos = strpos($result['stdout'], 'REDIRECT:');
            if ($terminal_pos === false) {
                $terminal_pos = strpos($result['stdout'], 'STATUS:');
            }
            r2_assert($nocache_pos !== false && $terminal_pos !== false && $nocache_pos < $terminal_pos, "{$route} {$scenario} emits no-cache before the terminal response");
        }
        r2_assert($by_route['wc_upayments'] === $by_route['supcheckout'], "route parity {$scenario}: wc_upayments and supcheckout are byte-identical, order state included");
    }

    // The preflight probe on the new route: no-cache, explicit no-store and the
    // acknowledgement before the 200, no log line, no order change.
    $probe = r2_run_child('route:supcheckout:probe');
    $untouched = 'ORDER_STATE:' . json_encode(array('pending', array('UPayments_order_id' => 'merchant-42')));
    r2_assert($probe['exit'] === 0 && $probe['stderr'] === '', 'supcheckout probe exits cleanly');
    r2_assert(
        trim($probe['stdout']) === implode("\n", array(
            'LISTENERS:1',
            'NOCACHE_HEADERS',
            'HEADER:Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private',
            'HEADER:X-SUPCheckout-Probe: 1',
            'STATUS:200',
            $untouched,
        )),
        'supcheckout probe: no-cache, no-store and the acknowledgement before a 200, nothing logged, the order untouched'
    );
    $legacy_probe = r2_run_child('route:wc_upayments:probe');
    r2_assert(
        r2_not_contains($legacy_probe['stdout'], 'HEADER:')
            && r2_contains($legacy_probe['stdout'], 'NOCACHE_HEADERS')
            && r2_contains($legacy_probe['stdout'], 'STATUS:200')
            && r2_contains($legacy_probe['stdout'], $untouched),
        'wc_upayments answers the probe argument as before: an invalid webhook under the no-cache policy, no acknowledgement'
    );

    echo "\nEcosystem callback/cache harness: {$r2_pass} PASS / {$r2_fail} FAIL\n";
    echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
    exit($r2_fail === 0 ? 0 : 1);
}
