<?php
/**
 * Approach 3 T1 dependency/egress guardrails.
 *
 * Runtime-neutral architecture control. The accepted source is the baseline:
 * this harness forbids new provider HTTP egress and new ambient dependencies
 * beyond the explicitly characterized A5/PaymentLifecycle exception set.
 */

$pass = 0;
$fail = 0;

function t1_dep_ok($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        echo "PASS: $label\n";
        $pass++;
        return;
    }
    echo "FAIL: $label\n";
    $fail++;
}

function t1_dep_same($actual, $expected, $label) {
    t1_dep_ok($actual === $expected, $label);
    if ($actual !== $expected) {
        echo '  expected: ' . var_export($expected, true) . "\n";
        echo '  actual:   ' . var_export($actual, true) . "\n";
    }
}

function t1_dep_next_significant(array $tokens, $index) {
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $token;
    }
    return null;
}

function t1_dep_function_calls($source, array $names) {
    $lookup = array();
    foreach ($names as $name) {
        $lookup[strtolower($name)] = true;
    }

    $calls = array();
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($token[1]);
        if (!isset($lookup[$name])) {
            continue;
        }
        $next = t1_dep_next_significant($tokens, $i);
        if ($next === '(') {
            $calls[] = $name;
        }
    }
    return $calls;
}

function t1_dep_superglobals($source) {
    $out = array();
    $tokens = token_get_all($source);
    foreach ($tokens as $token) {
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }
        if (in_array($token[1], array('$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_FILES'), true)) {
            if (!isset($out[$token[1]])) {
                $out[$token[1]] = 0;
            }
            $out[$token[1]]++;
        }
    }
    ksort($out);
    return $out;
}

function t1_dep_php_files($directory) {
    $files = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
}

function t1_dep_rel($root, $path) {
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);
    return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
}

$root = dirname(__DIR__, 2);

$http_functions = array(
    'wp_remote_request',
    'wp_remote_get',
    'wp_remote_post',
    'wp_remote_head',
    'wp_safe_remote_request',
    'wp_safe_remote_get',
    'wp_safe_remote_post',
    'wp_safe_remote_head',
);

// Scanner sensitivity: comments/strings are ignored; real calls are detected.
$fixture = <<<'PHP'
<?php
// wp_remote_get('comment-only');
$fake = "wp_remote_post('string-only')";
wp_remote_request('https://example.test');
PHP;
t1_dep_same(
    t1_dep_function_calls($fixture, $http_functions),
    array('wp_remote_request'),
    'HTTP scanner ignores comments/strings and detects executable calls'
);
$fixture_extra = $fixture . "\nwp_remote_post('https://example.test');\n";
t1_dep_same(
    t1_dep_function_calls($fixture_extra, $http_functions),
    array('wp_remote_request', 'wp_remote_post'),
    'HTTP scanner sensitivity fixture detects an added provider egress call'
);

$production_files = array(
    $root . '/supcheckout.php',
    $root . '/index.php',
    $root . '/uninstall.php',
);
$production_files = array_merge(
    $production_files,
    // includes/ moved into src/ in Sub-project 0a Task 6.
    t1_dep_php_files($root . '/src')
);
$production_files = array_values(array_unique($production_files));
sort($production_files);

$egress = array();
foreach ($production_files as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        t1_dep_ok(false, 'read production source ' . t1_dep_rel($root, $path));
        continue;
    }
    foreach (t1_dep_function_calls($source, $http_functions) as $call) {
        $egress[] = t1_dep_rel($root, $path) . ':' . $call;
    }
}
sort($egress);

$expected_egress = array(
    'src/Gateway/Gateway.php:wp_remote_request',
    'src/Subscription/Cron/Scheduler.php:wp_remote_request',
    'src/Payment/StatusVerifier.php:wp_remote_get',
);
sort($expected_egress);
t1_dep_same(
    $egress,
    $expected_egress,
    'provider HTTP egress set remains exactly the accepted three implementations'
);

// Pin the real callback/bootstrap topology without line-number coupling.
$identity = file_get_contents($root . '/src/Release/Identity.php');
$lifecycle = file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
// The gateway source is the main file plus the gateway class it registers
// (src/Gateway/Gateway.php since Sub-project 0a Task 3).
$gateway_main = file_get_contents($root . '/supcheckout.php');
$gateway_class = file_get_contents($root . '/src/Gateway/Gateway.php');
$gateway = is_string($gateway_main) && is_string($gateway_class) ? $gateway_main . "\n" . $gateway_class : false;

t1_dep_ok(is_string($identity), 'Release Identity source readable');
t1_dep_ok(is_string($lifecycle), 'PaymentLifecycle source readable');
t1_dep_ok(is_string($gateway), 'gateway source readable');

if (is_string($identity)) {
    t1_dep_ok(
        strpos($identity, "require_once dirname(__DIR__) . '/Payment/PaymentLifecycle.php';") !== false
        && strpos($identity, '\\Simplixi\\SUPCheckout\\Payment\\PaymentLifecycle::bootstrap();') !== false,
        'Release Identity loads and bootstraps PaymentLifecycle'
    );
}

if (is_string($lifecycle)) {
    t1_dep_ok(
        strpos($lifecycle, "private const CALLBACK_HOOK = 'woocommerce_api_wc_upayments';") !== false,
        'PaymentLifecycle owns the canonical wc_upayments callback hook identity'
    );
    t1_dep_ok(
        preg_match(
            "/add_action\\s*\\(\\s*self::CALLBACK_HOOK\\s*,\\s*array\\s*\\(\\s*__CLASS__\\s*,\\s*'handle_callback'\\s*\\)\\s*,\\s*5\\s*\\)/",
            $lifecycle
        ) === 1,
        'PaymentLifecycle callback remains registered at priority 5'
    );
}

if (is_string($gateway)) {
    t1_dep_ok(
        preg_match(
            '/add_action\\s*\\(\\s*"woocommerce_api_"\\s*\\.\\s*strtolower\\s*\\(\\s*"WC_UPayments"\\s*\\)\\s*,\\s*\\[\\s*\\$this\\s*,\\s*"check_ipn_response"\\s*,?\\s*\\]\\s*\\)\\s*;/',
            $gateway
        ) === 1,
        'legacy gateway check_ipn_response callback remains registered with default priority 10'
    );
}

// Freeze the accepted ambient-dependency exception set in src/Payment.
// Reductions are allowed; additions/new files/new superglobal kinds are not.
$payment_files = t1_dep_php_files($root . '/src/Payment');
$ambient = array();
foreach ($payment_files as $path) {
    $relative = t1_dep_rel($root, $path);
    $source = file_get_contents($path);
    if (!is_string($source)) {
        continue;
    }

    $superglobals = t1_dep_superglobals($source);
    if ($superglobals) {
        $ambient[$relative]['superglobals'] = $superglobals;
    }

    $wc_count = count(t1_dep_function_calls($source, array('WC')));
    if ($wc_count > 0) {
        $ambient[$relative]['wc_calls'] = $wc_count;
    }
}
ksort($ambient);

$allowed = array(
    'src/Payment/CheckoutOrchestrator.php' => array(
        'superglobals' => array('$_POST' => 2),
        'wc_calls' => 20,
    ),
    'src/Payment/CheckoutPayload.php' => array(
        'superglobals' => array('$_SERVER' => 4),
        'wc_calls' => 0,
    ),
    'src/Payment/PaymentLifecycle.php' => array(
        'superglobals' => array('$_GET' => 1, '$_POST' => 1),
        'wc_calls' => 2,
    ),
);

$ambient_ok = true;
foreach ($ambient as $file => $observed) {
    if (!isset($allowed[$file])) {
        $ambient_ok = false;
        echo "  unexpected ambient dependency file: $file\n";
        continue;
    }

    $allowed_superglobals = $allowed[$file]['superglobals'];
    $observed_superglobals = isset($observed['superglobals']) ? $observed['superglobals'] : array();
    foreach ($observed_superglobals as $name => $count) {
        if (!isset($allowed_superglobals[$name]) || $count > $allowed_superglobals[$name]) {
            $ambient_ok = false;
            echo "  unexpected ambient dependency: $file $name x$count\n";
        }
    }

    $wc_count = isset($observed['wc_calls']) ? $observed['wc_calls'] : 0;
    if ($wc_count > $allowed[$file]['wc_calls']) {
        $ambient_ok = false;
        echo "  unexpected WC() growth: $file x$wc_count\n";
    }
}
t1_dep_ok(
    $ambient_ok,
    'src/Payment ambient WordPress/WooCommerce dependencies do not grow beyond the accepted A5/lifecycle exception set'
);

echo "\n--- Approach 3 T1 Dependency Boundaries Report ---\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
