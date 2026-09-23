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

        require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::bootstrap();
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_callback();

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

        require_once dirname(__DIR__, 2) . '/UPayments.php';
        woocommerceUpaymentsInit();

        $reflection = new ReflectionClass('WC_Upayments');
        /** @var WC_Upayments $gateway */
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

        require_once dirname(__DIR__, 2) . '/UPayments.php';
        woocommerceUpaymentsInit();

        $reflection = new ReflectionClass('WC_Upayments');
        /** @var WC_Upayments $gateway */
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

    echo "\nEcosystem callback/cache harness: {$r2_pass} PASS / {$r2_fail} FAIL\n";
    exit($r2_fail === 0 ? 0 : 1);
}
