<?php
/**
 * E3 analytics / browser-return replay characterization.
 *
 * This harness intentionally does not emulate GA4, Meta, GTM, or another
 * analytics implementation. SUPCheckout does not own those trackers. Instead,
 * it executes the real PaymentLifecycle browser callback against an already
 * verified captured order twice and locks the browser-visible contract that
 * downstream trackers observe.
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['e3_analytics_order'] = null;
    $GLOBALS['e3_analytics_gateway'] = null;
    $GLOBALS['e3_analytics_woo'] = null;

    function wc_get_order($order_id) {
        $order = $GLOBALS['e3_analytics_order'];
        return is_object($order) && (int) $order->get_id() === (int) $order_id ? $order : false;
    }

    function WC() {
        return $GLOBALS['e3_analytics_woo'];
    }

    function wp_unslash($value) {
        return $value;
    }

    function wp_safe_redirect($url) {
        echo 'REDIRECT:' . (string) $url . "\n";
        return true;
    }

    function status_header($code) {
        echo 'STATUS:' . (int) $code . "\n";
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
        return (string) $url
            . $separator
            . rawurlencode((string) $key)
            . '='
            . rawurlencode((string) $value);
    }

    function wc_get_logger() {
        return new E3AnalyticsLogger();
    }

    class E3AnalyticsLogger {
        public function warning($message, $context = array()) {
            echo 'LOG:' . (string) $message . "\n";
        }

        public function info($message, $context = array()) {
            echo 'LOG:' . (string) $message . "\n";
        }
    }

    class E3AnalyticsCart {
        public $empty_count = 0;

        public function empty_cart() {
            $this->empty_count++;
        }
    }

    class E3AnalyticsGateway {
        public function get_return_url($order) {
            return 'https://merchant.example.test/checkout/order-received/'
                . (int) $order->get_id()
                . '/?key='
                . rawurlencode((string) $order->get_order_key());
        }
    }

    class E3AnalyticsGatewayRegistry {
        public function payment_gateways() {
            return array('upayments' => $GLOBALS['e3_analytics_gateway']);
        }
    }

    class E3AnalyticsWooRuntime {
        public $cart;

        public function __construct() {
            $this->cart = new E3AnalyticsCart();
        }

        public function payment_gateways() {
            return new E3AnalyticsGatewayRegistry();
        }
    }

    class E3AnalyticsOrder {
        private $id;
        private $order_key;
        private $meta;
        public $save_count = 0;

        public function __construct($id, $order_key) {
            $this->id = (int) $id;
            $this->order_key = (string) $order_key;
            $this->meta = array(
                'UPayments_order_id' => 'merchant-order-42',
                '_upay_verified_capture' => '1',
                '_simplixpay_upayments_status_track_v1' => 'track-42',
                '_simplixpay_upayments_status_requested_v1' => 'merchant-order-42',
            );
        }

        public function get_id() {
            return $this->id;
        }

        public function get_order_key() {
            return $this->order_key;
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
            $this->save_count++;
            return $this->id;
        }

        public function has_status($status) {
            return false;
        }
    }

    function e3_analytics_child() {
        $_GET = array(
            'page' => 'return',
            'wc_order_id' => '42',
            'track_id' => 'track-42',
            'requested_order_id' => 'merchant-order-42',
        );
        $_POST = array();
        $_SERVER = array('REQUEST_METHOD' => 'GET');

        $GLOBALS['e3_analytics_order'] = new E3AnalyticsOrder(42, 'wc_order_key_42');
        $GLOBALS['e3_analytics_gateway'] = new E3AnalyticsGateway();
        $GLOBALS['e3_analytics_woo'] = new E3AnalyticsWooRuntime();

        register_shutdown_function(function () {
            $order = $GLOBALS['e3_analytics_order'];
            $woo = $GLOBALS['e3_analytics_woo'];
            echo 'SHUTDOWN:SAVES:' . (int) $order->save_count . "\n";
            echo 'SHUTDOWN:CART_EMPTY:' . (int) $woo->cart->empty_count . "\n";
            echo 'SHUTDOWN:VERIFIED_CAPTURE:' . (string) $order->get_meta('_upay_verified_capture') . "\n";
            echo 'SHUTDOWN:TRACK:' . (string) $order->get_meta('_simplixpay_upayments_status_track_v1') . "\n";
        });

        require_once dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php';
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_callback();

        echo "CALLBACK_RETURNED_UNEXPECTEDLY\n";
        exit(65);
    }

    function e3_analytics_run_child() {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__FILE__)
            . ' --child';
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

    function e3_analytics_has($haystack, $needle) {
        return strpos((string) $haystack, (string) $needle) !== false;
    }

    function e3_analytics_count($haystack, $needle) {
        return substr_count((string) $haystack, (string) $needle);
    }

    function e3_analytics_assert($condition, $label) {
        global $e3_analytics_pass, $e3_analytics_fail;
        if ($condition) {
            echo "PASS: $label\n";
            $e3_analytics_pass++;
            return;
        }
        echo "FAIL: $label\n";
        $e3_analytics_fail++;
    }

    if (isset($argv[1]) && $argv[1] === '--child') {
        e3_analytics_child();
    }

    $e3_analytics_pass = 0;
    $e3_analytics_fail = 0;

    $first = e3_analytics_run_child();
    $replay = e3_analytics_run_child();
    $return_url = 'https://merchant.example.test/checkout/order-received/42/?key=wc_order_key_42';

    foreach (array('first captured browser return' => $first, 'captured browser replay' => $replay) as $label => $result) {
        e3_analytics_assert($result['exit'] === 0, $label . ' terminates through the real callback boundary');
        e3_analytics_assert($result['stderr'] === '', $label . ' produces no stderr diagnostics');
        e3_analytics_assert(
            e3_analytics_count($result['stdout'], 'REDIRECT:' . $return_url) === 1,
            $label . ' redirects exactly once to the stable Woo order-received identity'
        );
        e3_analytics_assert(
            e3_analytics_has($result['stdout'], 'SHUTDOWN:SAVES:0'),
            $label . ' does not mutate or resave already-verified payment state'
        );
        e3_analytics_assert(
            e3_analytics_has($result['stdout'], 'SHUTDOWN:VERIFIED_CAPTURE:1'),
            $label . ' preserves verified-capture authority'
        );
        e3_analytics_assert(
            e3_analytics_has($result['stdout'], 'SHUTDOWN:TRACK:track-42'),
            $label . ' preserves the trusted provider track identity'
        );
        e3_analytics_assert(
            e3_analytics_has($result['stdout'], 'SHUTDOWN:CART_EMPTY:1'),
            $label . ' empties the Woo cart once before confirmation navigation'
        );
        e3_analytics_assert(
            !e3_analytics_has($result['stdout'], 'CALLBACK_RETURNED_UNEXPECTEDLY'),
            $label . ' cannot fall through after redirect'
        );
    }

    e3_analytics_assert(
        $first['stdout'] === $replay['stdout'],
        'replaying the same captured browser callback exposes the same stable confirmation navigation contract'
    );

    $root = dirname(__DIR__, 2);
    $owned_surface = '';
    foreach (array(
        '/UPayments.php',
        '/src/Payment/PaymentLifecycle.php',
        '/assets/js/new-upay.js',
        '/assets/js/upayments-block.js',
        '/templates/new-design-form.php',
    ) as $relative_path) {
        $source = file_get_contents($root . $relative_path);
        e3_analytics_assert(is_string($source), 'analytics-owned surface is readable: ' . $relative_path);
        if (is_string($source)) {
            $owned_surface .= "\n" . $source;
        }
    }

    foreach (array('gtag(', 'fbq(', 'dataLayer.push(', 'analytics.track(') as $tracker_emitter) {
        e3_analytics_assert(
            strpos($owned_surface, $tracker_emitter) === false,
            'SUPCheckout does not emit third-party purchase analytics through ' . $tracker_emitter
        );
    }

    e3_analytics_assert(
        e3_analytics_count($first['stdout'], 'REDIRECT:' . $return_url) === 1
            && e3_analytics_count($replay['stdout'], 'REDIRECT:' . $return_url) === 1,
        'captured callback replay risk is explicit: downstream order-received trackers may observe confirmation navigation again'
    );

    echo "\nE3 analytics-return characterization: {$e3_analytics_pass} PASS / {$e3_analytics_fail} FAIL\n";
    exit($e3_analytics_fail === 0 ? 0 : 1);
}
