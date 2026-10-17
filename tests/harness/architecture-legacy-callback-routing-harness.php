<?php
/**
 * Approach 3 T2 direct legacy callback routing regression.
 *
 * This harness invokes the real Simplixi\SUPCheckout\Gateway\Gateway::check_ipn_response() method in
 * child PHP processes while replacing the three legacy routing targets with
 * sentinels. On the pre-T2 base those sentinels are expected to win, making
 * this harness RED. After the bounded T2 implementation the real
 * PaymentLifecycle/PublicOrderStatus path must terminate first.
 *
 * 0b Task 8 extends it to both WC-API routes: the same requests dispatched through
 * woocommerce_api_wc_upayments and woocommerce_api_supcheckout (all listeners, and the
 * gateway's priority-10 fallback alone) must be byte-identical, and only the supcheckout
 * route answers the preflight probe, before any callback processing.
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

    require_once dirname(__DIR__, 2) . '/supcheckout.php';

    // Run the plugins_loaded requirement check (was woocommerceUpaymentsInit())
    // without constructing a live WooCommerce gateway instance.
    \Simplixi\SUPCheckout\Bootstrap\Requirements::check_woocommerce();

    class T2LegacyGatewayProbe extends \Simplixi\SUPCheckout\Gateway\Gateway {
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

/**
 * 0b Task 8 route parity. Boots the real main file, attaches the gateway's two route
 * registrations exactly as its constructor does (pinned by the source checks below),
 * then dispatches one WC-API route like WooCommerce's do_action('woocommerce_api_{route}'):
 * every listener of that hook in priority order, registration order within a priority.
 *
 * @param string $route     wc_upayments or supcheckout.
 * @param string $scenario  Request shape.
 * @param string $listeners 'all', or 'p10' for the gateway's priority-10 fallback alone.
 */
function t2_child_route($route, $scenario, $listeners) {
    $_GET = array();
    $_POST = array();
    $_SERVER = array('REQUEST_METHOD' => 'GET');

    require_once dirname(__DIR__, 2) . '/supcheckout.php';
    \Simplixi\SUPCheckout\Bootstrap\Requirements::check_woocommerce();

    // Only this child needs them: an order read and a WooCommerce object without gateways.
    function wc_get_order($order_id) {
        echo 'ORDER_READ:' . (string) $order_id . "\n";
        return false;
    }

    function WC() {
        return new WooCommerce();
    }

    // The probe's own headers are invisible to the CLI SAPI; record them.
    eval('namespace Simplixi\SUPCheckout\Takeover; function header($line) { echo "HEADER:" . $line . "\n"; } function headers_sent() { return false; }');

    class T2RouteGatewayProbe extends \Simplixi\SUPCheckout\Gateway\Gateway {
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

    $reflection = new ReflectionClass(T2RouteGatewayProbe::class);
    $gateway = $reflection->newInstanceWithoutConstructor();
    // Gateway::__construct(), verbatim: the historical route, then the new route.
    add_action("woocommerce_api_" . strtolower("WC_UPayments"), [$gateway, "check_ipn_response", ]);
    \Simplixi\SUPCheckout\Takeover\LegacyCallbackListener::register_gateway($gateway);

    $requests = array(
        'browser-invalid' => array('page' => 'return'),
        'webhook-invalid' => array(),
        'public-status-invalid' => array('get_order_status' => '1'),
        'browser-routed' => array('page' => 'return', 'wc_order_id' => '42', 'track_id' => 'track-42', 'requested_order_id' => 'merchant-42'),
        'probe' => array('supcheckout_probe' => '1'),
        'probe-with-callback-keys' => array('supcheckout_probe' => '1', 'page' => 'return', 'wc_order_id' => '42', 'track_id' => 'track-42', 'requested_order_id' => 'merchant-42'),
        'probe-other-value' => array('supcheckout_probe' => '2'),
    );
    if (!isset($requests[$scenario]) || !in_array($route, array('wc_upayments', 'supcheckout'), true)) {
        echo "UNKNOWN_SCENARIO\n";
        exit(64);
    }
    $_GET = $requests[$scenario];
    if ($scenario === 'webhook-invalid') {
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    $hook = 'woocommerce_api_' . $route;
    $queue = array();
    foreach ($GLOBALS['t2_actions'] as $index => $action) {
        if ($action[0] === $hook && ($listeners === 'all' || $action[2] === 10)) {
            $queue[] = array($action[2], $index, $action[1]);
        }
    }
    usort($queue, function ($left, $right) {
        return $left[0] === $right[0] ? $left[1] <=> $right[1] : $left[0] <=> $right[0];
    });
    $priorities = array();
    foreach ($queue as $entry) {
        $priorities[] = $entry[0];
    }
    echo 'LISTENER_PRIORITIES:' . implode(',', $priorities) . "\n";
    foreach ($queue as $entry) {
        call_user_func($entry[2]);
    }

    echo "ROUTE_RETURNED_UNEXPECTEDLY\n";
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
if (isset($argv[1]) && $argv[1] === '--route-child') {
    t2_child_route((string) $argv[2], (string) $argv[3], (string) $argv[4]);
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

// 0b Task 8: both WC-API routes carry the same listener pair in the same priority order
// and answer every callback identically. Each request is dispatched through all the
// route's listeners and through the gateway's priority-10 fallback alone.
function t2_run_route_child($route, $scenario, $listeners) {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__FILE__)
        . ' --route-child ' . escapeshellarg((string) $route)
        . ' ' . escapeshellarg((string) $scenario)
        . ' ' . escapeshellarg((string) $listeners);
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        return array('exit' => 127, 'stdout' => '', 'stderr' => 'proc_open failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return array('exit' => (int) $exit, 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : '');
}

$route_forbidden = array(
    'LEGACY_BROWSER_SENTINEL',
    'LEGACY_WEBHOOK_SENTINEL',
    'LEGACY_STATUS_SENTINEL',
    'ROUTE_RETURNED_UNEXPECTEDLY',
    'HEADER:',
);
$route_cases = array(
    'browser-invalid' => array('REDIRECT:https://merchant.example.test/?upayments_verification=pending'),
    'webhook-invalid' => array('STATUS:200'),
    'public-status-invalid' => array('STATUS:404', 'Order status unavailable.'),
    'browser-routed' => array('ORDER_READ:42', 'REDIRECT:https://merchant.example.test/?upayments_verification=pending'),
    // Any other value is no probe: an ordinary (invalid) webhook on both routes.
    'probe-other-value' => array('STATUS:200'),
);
foreach ($route_cases as $scenario => $must) {
    foreach (array('all' => '5,10', 'p10' => '10') as $listeners => $priorities) {
        $by_route = array();
        foreach (array('wc_upayments', 'supcheckout') as $route) {
            $result = t2_run_route_child($route, $scenario, $listeners);
            $by_route[$route] = $result;
            $label = "route {$route} {$scenario} ({$listeners} listeners)";
            if ($result['stderr'] !== '' || $result['exit'] !== 0) {
                echo '  child stderr [' . $label . ']: ' . trim($result['stderr']) . "\n";
                echo '  child stdout [' . $label . ']: ' . trim($result['stdout']) . "\n";
            }
            t2_assert($result['stderr'] === '' && $result['exit'] === 0, $label . ' terminates through the verified lifecycle');
            t2_assert(t2_contains($result['stdout'], 'LISTENER_PRIORITIES:' . $priorities . "\n"), $label . ' has listener priorities ' . $priorities);
            foreach ($must as $needle) {
                t2_assert(t2_contains($result['stdout'], $needle), $label . ' emits ' . $needle);
            }
            foreach ($route_forbidden as $needle) {
                t2_assert(t2_excludes($result['stdout'], $needle), $label . ' excludes ' . $needle);
            }
        }
        t2_assert(
            $by_route['wc_upayments'] === $by_route['supcheckout'],
            "route parity {$scenario} ({$listeners} listeners): wc_upayments and supcheckout produce byte-identical output and exit"
        );
    }
}

// The preflight probe: answered on the new route only, before any callback processing.
$probe_ack = array(
    'HEADER:Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private',
    'HEADER:X-SUPCheckout-Probe: 1',
    'STATUS:200',
);
foreach (array('probe', 'probe-with-callback-keys') as $scenario) {
    $probe = t2_run_route_child('supcheckout', $scenario, 'all');
    t2_assert($probe['stderr'] === '' && $probe['exit'] === 0, "supcheckout {$scenario} exits cleanly");
    t2_assert(trim($probe['stdout']) === "LISTENER_PRIORITIES:5,10\n" . implode("\n", $probe_ack), "supcheckout {$scenario} is a no-op 200 with no-store and the acknowledgement, and nothing else");
    foreach (array('ORDER_READ', 'REDIRECT:', 'Order status unavailable.') as $needle) {
        t2_assert(t2_excludes($probe['stdout'], $needle), "supcheckout {$scenario} reads no order and runs no callback ({$needle})");
    }
    $legacy = t2_run_route_child('wc_upayments', $scenario, 'all');
    t2_assert($legacy['stderr'] === '' && $legacy['exit'] === 0, "wc_upayments {$scenario} exits cleanly");
    t2_assert(t2_excludes($legacy['stdout'], 'HEADER:'), "wc_upayments does not answer the probe ({$scenario})");
}
$legacy_probe = t2_run_route_child('wc_upayments', 'probe', 'all');
t2_assert(t2_contains($legacy_probe['stdout'], 'STATUS:200'), 'wc_upayments treats the probe argument as an ordinary invalid webhook, as before');
$legacy_keyed_probe = t2_run_route_child('wc_upayments', 'probe-with-callback-keys', 'all');
t2_assert(
    t2_contains($legacy_keyed_probe['stdout'], 'ORDER_READ:42')
        && t2_contains($legacy_keyed_probe['stdout'], 'REDIRECT:https://merchant.example.test/?upayments_verification=pending'),
    'wc_upayments ignores the probe argument and routes the callback keys through the lifecycle, as before'
);
// Only the priority-5 listener answers the probe. The gateway's fallback alone treats it
// as an invalid webhook without the acknowledgement, so Preflight still blocks (fail closed).
$fallback_probe = t2_run_route_child('supcheckout', 'probe', 'p10');
t2_assert(
    $fallback_probe['exit'] === 0
        && t2_contains($fallback_probe['stdout'], 'STATUS:200')
        && t2_excludes($fallback_probe['stdout'], 'HEADER:X-SUPCheckout-Probe'),
    'the priority-10 fallback never acknowledges a probe'
);

$lifecycle_source = file_get_contents(dirname(__DIR__, 2) . '/src/Payment/PaymentLifecycle.php');
t2_assert(
    is_string($lifecycle_source)
        && preg_match(
            "/add_action\\(self::CALLBACK_HOOK, array\\(__CLASS__, 'handle_callback'\\), 5\\);\\s*LegacyCallbackListener::register\\(\\);/",
            $lifecycle_source
        ) === 1,
    'PaymentLifecycle::bootstrap() keeps wc_upayments at priority 5 and adds the supcheckout lifecycle listener right after it, unconditionally'
);

// The gateway source is the main file plus the gateway class it registers
// (src/Gateway/Gateway.php since Sub-project 0a Task 3).
$gateway_main = file_get_contents(dirname(__DIR__, 2) . '/supcheckout.php');
$gateway_class = file_get_contents(dirname(__DIR__, 2) . '/src/Gateway/Gateway.php');
$gateway_source = is_string($gateway_main) && is_string($gateway_class) ? $gateway_main . "\n" . $gateway_class : false;
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
    t2_assert(
        preg_match(
            '/add_action\\s*\\(\\s*"woocommerce_api_"\\s*\\.\\s*strtolower\\s*\\(\\s*"WC_UPayments"\\s*\\)\\s*,\\s*\\[\\s*\\$this\\s*,\\s*"check_ipn_response"\\s*,?\\s*\\]\\s*\\)\\s*;\\s*LegacyCallbackListener::register_gateway\\(\\$this\\);/',
            $gateway_source
        ) === 1,
        'the gateway constructor attaches check_ipn_response to supcheckout right after wc_upayments, as the route child does'
    );
}

echo "\n--- Approach 3 T2 Legacy Callback Routing Report ---\n";
echo "PASS: $t2_pass\n";
echo "FAIL: $t2_fail\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($t2_fail === 0 ? 0 : 1);
