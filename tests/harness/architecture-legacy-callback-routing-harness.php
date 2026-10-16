<?php
/**
 * Approach 3 T2 direct legacy callback routing regression.
 *
 * This harness invokes the real WC_Upayments::check_ipn_response() method in
 * child PHP processes while replacing the three legacy routing targets with
 * sentinels. On the pre-T2 base those sentinels are expected to win, making
 * this harness RED. After the bounded T2 implementation the real
 * PaymentLifecycle/PublicOrderStatus path must terminate first.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['t2_actions'] = array();
$GLOBALS['t2_filters'] = array();

function plugin_dir_url($file) {
    return 'https://merchant.example.test/wp-content/plugins/supcheckout/';
}

function plugin_dir_path($file) {
    return dirname((string) $file) . DIRECTORY_SEPARATOR;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['t2_actions'][] = array($hook, $callback, (int) $priority, (int) $accepted_args);
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['t2_filters'][] = array($hook, $callback, (int) $priority, (int) $accepted_args);
    return true;
}

function register_activation_hook($file, $callback) {
    return true;
}

function register_deactivation_hook($file, $callback) {
    return true;
}

function sanitize_text_field($value) {
    return is_scalar($value) ? trim((string) $value) : '';
}

function wp_unslash($value) {
    return $value;
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

function wp_safe_redirect($url) {
    echo 'REDIRECT:' . (string) $url . "\n";
    return true;
}

function status_header($code) {
    echo 'STATUS:' . (int) $code . "\n";
}

class WooCommerce {}

class WC_Payment_Gateway {}

function t2_child_boot($scenario) {
    $_GET = array();
    $_POST = array();
    $_SERVER = array('REQUEST_METHOD' => 'GET');

    require_once dirname(__DIR__, 2) . '/UPayments.php';

    // Define the historical gateway class without constructing a live
    // WooCommerce gateway instance.
    woocommerceUpaymentsInit();

    class T2LegacyGatewayProbe extends WC_Upayments {
        public function get_payment_staus() {
            echo "LEGACY_STATUS_SENTINEL\n";
            exit(71);
        }

        public function return_from_upayments() {
            echo "LEGACY_BROWSER_SENTINEL\n";
            exit(72);
        }

        public function web_hook_handler() {
            echo "LEGACY_WEBHOOK_SENTINEL\n";
            exit(73);
        }
    }

    $reflection = new ReflectionClass(T2LegacyGatewayProbe::class);
    $gateway = $reflection->newInstanceWithoutConstructor();

    if ($scenario === 'browser-invalid') {
        $_GET = array('page' => 'return');
    } elseif ($scenario === 'webhook-invalid') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
    } elseif ($scenario === 'public-status-invalid') {
        $_GET = array('get_order_status' => '1');
        $_SERVER['REQUEST_METHOD'] = 'GET';
    } else {
        echo "UNKNOWN_SCENARIO\n";
        exit(64);
    }

    $gateway->check_ipn_response();

    echo "CHECK_IPN_RETURNED_UNEXPECTEDLY\n";
    exit(65);
}

function t2_run_child($scenario) {
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

function t2_assert($condition, $label) {
    global $t2_pass, $t2_fail;
    if ($condition) {
        echo "PASS: $label\n";
        $t2_pass++;
        return;
    }

    echo "FAIL: $label\n";
    $t2_fail++;
}

function t2_contains($haystack, $needle) {
    return strpos((string) $haystack, (string) $needle) !== false;
}

function t2_excludes($haystack, $needle) {
    return strpos((string) $haystack, (string) $needle) === false;
}

if (isset($argv[1]) && $argv[1] === '--child') {
    t2_child_boot(isset($argv[2]) ? (string) $argv[2] : '');
}

$t2_pass = 0;
$t2_fail = 0;

$cases = array(
    'browser-invalid' => array(
        'must' => array(
            'REDIRECT:https://merchant.example.test/?upayments_verification=pending',
        ),
        'must_not' => array(
            'LEGACY_BROWSER_SENTINEL',
            'LEGACY_WEBHOOK_SENTINEL',
            'LEGACY_STATUS_SENTINEL',
            'CHECK_IPN_RETURNED_UNEXPECTEDLY',
        ),
    ),
    'webhook-invalid' => array(
        'must' => array('STATUS:200'),
        'must_not' => array(
            'LEGACY_BROWSER_SENTINEL',
            'LEGACY_WEBHOOK_SENTINEL',
            'LEGACY_STATUS_SENTINEL',
            'CHECK_IPN_RETURNED_UNEXPECTEDLY',
        ),
    ),
    'public-status-invalid' => array(
        'must' => array(
            'STATUS:404',
            'Order status unavailable.',
        ),
        'must_not' => array(
            'LEGACY_BROWSER_SENTINEL',
            'LEGACY_WEBHOOK_SENTINEL',
            'LEGACY_STATUS_SENTINEL',
            'CHECK_IPN_RETURNED_UNEXPECTEDLY',
        ),
    ),
);

foreach ($cases as $scenario => $expectations) {
    $result = t2_run_child($scenario);

    if ($result['stderr'] !== '') {
        echo '  child stderr [' . $scenario . ']: ' . trim($result['stderr']) . "\n";
    }
    if ($result['stdout'] !== '' && $result['exit'] !== 0) {
        echo '  child stdout [' . $scenario . ']: ' . trim($result['stdout']) . "\n";
    }

    t2_assert(
        $result['stderr'] === '',
        $scenario . ' has no bootstrap/runtime stderr'
    );
    t2_assert(
        $result['exit'] === 0,
        $scenario . ' terminates through PaymentLifecycle/PublicOrderStatus'
    );

    foreach ($expectations['must'] as $needle) {
        t2_assert(
            t2_contains($result['stdout'], $needle),
            $scenario . ' emits expected evidence: ' . $needle
        );
    }

    foreach ($expectations['must_not'] as $needle) {
        t2_assert(
            t2_excludes($result['stdout'], $needle),
            $scenario . ' excludes forbidden legacy evidence: ' . $needle
        );
    }
}

$gateway_source = file_get_contents(dirname(__DIR__, 2) . '/UPayments.php');
t2_assert(is_string($gateway_source), 'gateway source readable');
if (is_string($gateway_source)) {
    t2_assert(
        strpos($gateway_source, 'function check_ipn_response') !== false,
        'historical check_ipn_response public method remains present'
    );
    t2_assert(
        preg_match(
            '/add_action\\s*\\(\\s*"woocommerce_api_"\\s*\\.\\s*strtolower\\s*\\(\\s*"WC_UPayments"\\s*\\)\\s*,\\s*\\[\\s*\\$this\\s*,\\s*"check_ipn_response"\\s*,?\\s*\\]\\s*\\)\\s*;/',
            $gateway_source
        ) === 1,
        'historical wc_upayments hook registration remains at default priority 10'
    );
}

echo "\n--- Approach 3 T2 Legacy Callback Routing Report ---\n";
echo "PASS: $t2_pass\n";
echo "FAIL: $t2_fail\n";
exit($t2_fail === 0 ? 0 : 1);
