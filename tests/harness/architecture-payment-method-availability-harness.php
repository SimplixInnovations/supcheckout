<?php
/**
 * A2 payment-method availability client/cache characterization harness.
 */

use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;

$a2_state = array();

function a2_reset() {
    global $a2_state;
    $a2_state = array(
        'options' => array(),
        'transients' => array(),
        'transient_ttls' => array(),
        'lock_result' => '1',
        'locks' => array(),
        'lock_acquires' => 0,
        'lock_releases' => 0,
        'update_result' => true,
        'option_writes' => 0,
        'option_writes_by_name' => array(),
        'transport_calls' => 0,
        'lock_held_during_transport' => null,
        'gate_during_transport' => null,
        'populate_on_lock_failure' => null,
        'populate_on_lock_acquire' => null,
        'gate_verify_shortfall' => false,
    );
}

function a2_state() {
    global $a2_state;
    return $a2_state;
}

function get_option($name, $default = false) {
    global $a2_state;
    $value = array_key_exists($name, $a2_state['options']) ? $a2_state['options'][$name] : $default;
    if ($a2_state['gate_verify_shortfall'] && $a2_state['option_writes'] > 0
        && strpos($name, 'upayments_payment_methods_rate_gate_') === 0
    ) {
        return (int) $value - 1;
    }
    return $value;
}

function update_option($name, $value, $autoload = null) {
    global $a2_state;
    $a2_state['option_writes']++;
    $a2_state['option_writes_by_name'][$name] = (isset($a2_state['option_writes_by_name'][$name]) ? $a2_state['option_writes_by_name'][$name] : 0) + 1;
    if (!$a2_state['update_result']) {
        return false;
    }
    $a2_state['options'][$name] = $value;
    return true;
}

function get_transient($name) {
    global $a2_state;
    return array_key_exists($name, $a2_state['transients']) ? $a2_state['transients'][$name] : false;
}

function set_transient($name, $value, $ttl = 0) {
    global $a2_state;
    $a2_state['transients'][$name] = $value;
    $a2_state['transient_ttls'][$name] = $ttl;
    return true;
}

function wp_salt($scheme = 'auth') {
    return 'a2-auth-salt';
}

function get_current_blog_id() {
    return 7;
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'a2_database');
}

final class A2Wpdb {
    public $prefix = 'wp_7_';

    public function prepare($sql, ...$args) {
        foreach ($args as $argument) {
            $quoted = "'" . str_replace("'", "''", (string) $argument) . "'";
            $sql = preg_replace('/%s/', $quoted, $sql, 1);
        }
        return $sql;
    }

    public function get_var($sql) {
        global $a2_state;
        if (stripos($sql, 'GET_LOCK') !== false) {
            $a2_state['lock_acquires']++;
            if ($a2_state['lock_result'] !== '1' && is_callable($a2_state['populate_on_lock_failure'])) {
                call_user_func($a2_state['populate_on_lock_failure']);
                $a2_state['populate_on_lock_failure'] = null;
            }
            if ($a2_state['lock_result'] === '1' && is_callable($a2_state['populate_on_lock_acquire'])) {
                call_user_func($a2_state['populate_on_lock_acquire']);
                $a2_state['populate_on_lock_acquire'] = null;
            }
            if ($a2_state['lock_result'] === '1' && preg_match("/'([^']+)'/", $sql, $match)) {
                $a2_state['locks'][$match[1]] = true;
            }
            return $a2_state['lock_result'];
        }
        if (stripos($sql, 'RELEASE_LOCK') !== false) {
            $a2_state['lock_releases']++;
            if (preg_match("/'([^']+)'/", $sql, $match)) {
                unset($a2_state['locks'][$match[1]]);
            }
            return '1';
        }
        return null;
    }
}

$GLOBALS['wpdb'] = new A2Wpdb();

require_once dirname(__DIR__, 2) . '/src/Provider/PaymentMethodAvailability.php';

$pass = 0;
$fail = 0;

function a2_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

function a2_assert_same($expected, $actual, $message) {
    a2_assert($expected === $actual, $message);
}

function a2_call($object, $method, array $arguments = array()) {
    $reflection = new ReflectionMethod($object, $method);
    if (\PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs($object, $arguments);
}

function a2_canonical($white_label = true, $knet = 1) {
    return array(
        'schema' => 3,
        'result' => 'success',
        'isWhiteLabel' => $white_label,
        'payButtons' => array(
            'knet' => $knet,
            'credit_card' => 1,
            'apple_pay_knet' => 0,
            'apple_pay' => 0,
            'samsung_pay' => 0,
            'google_pay' => 0,
        ),
    );
}

function a2_transport($response) {
    return function () use ($response) {
        global $a2_state;
        $a2_state['transport_calls']++;
        $a2_state['lock_held_during_transport'] = !empty($a2_state['locks']);
        $gate_name = 'upayments_payment_methods_rate_gate_live';
        $a2_state['gate_during_transport'] = isset($a2_state['options'][$gate_name])
            ? $a2_state['options'][$gate_name]
            : null;
        return $response;
    };
}

function a2_envelope($data, $status = true, $http_status = 201) {
    return array(
        'transport_ok' => true,
        'http_status' => $http_status,
        'curl_errno' => 0,
        'body' => json_encode(array('status' => $status, 'data' => $data)),
    );
}

// Identity characterization: mode, credential, salt, site and lock scopes.
a2_reset();
$live = new PaymentMethodAvailability(false, 'live-secret', a2_transport(false));
$test = new PaymentMethodAvailability(true, 'live-secret', a2_transport(false));
$other_credential = new PaymentMethodAvailability(false, 'rotated-secret', a2_transport(false));
$expected_live_hash = substr(hash_hmac('sha256', 'live|live-secret', 'a2-auth-salt'), 0, 16);
$expected_lock_hash = substr(hash('sha256', 'a2_database|wp_7_|7|live'), 0, 16);
$live_gate_name = a2_call($live, 'rate_gate_option_name');
$test_gate_name = a2_call($test, 'rate_gate_option_name');
$live_transient = a2_call($live, 'transient_name');
$test_transient = a2_call($test, 'transient_name');
$other_transient = a2_call($other_credential, 'transient_name');
$live_lock = a2_call($live, 'lock_name');
$test_lock = a2_call($test, 'lock_name');
$other_lock = a2_call($other_credential, 'lock_name');
a2_assert_same('upayments_payment_methods_rate_gate_live', $live_gate_name, 'live durable gate name is preserved');
a2_assert_same('upayments_payment_methods_rate_gate_test', $test_gate_name, 'test durable gate name is isolated');
a2_assert_same('upay_pm_v3_' . $expected_live_hash, $live_transient, 'credential cache fingerprint formula is preserved');
a2_assert($live_transient !== $test_transient, 'test and live result caches are isolated');
a2_assert($live_transient !== $other_transient, 'credential rotation invalidates cached results');
a2_assert(strpos($live_transient, 'live-secret') === false, 'transient name does not expose API credential');
a2_assert_same('upay_pm_' . $expected_lock_hash, $live_lock, 'site and live-mode advisory lock formula is preserved');
a2_assert($live_lock !== $test_lock, 'test and live advisory locks are isolated');
a2_assert($live_lock === $other_lock, 'credential rotations share the site/mode advisory lock');
a2_assert(strlen($live_lock) <= 64, 'advisory lock remains within MySQL name limit');

// Strict schema-3 cache classifier.
$canonical = a2_canonical();
a2_assert_same('success', PaymentMethodAvailability::classify_cached($canonical), 'canonical success cache is accepted');
a2_assert_same('failure', PaymentMethodAvailability::classify_cached(array('schema' => 3, 'state' => 'failure')), 'canonical failure cache is accepted');
$cache_mutations = array(
    'non-array' => null,
    'wrong schema' => array_replace($canonical, array('schema' => 2)),
    'extra top key' => array_replace($canonical, array('extra' => 1)),
    'string white-label' => array_replace($canonical, array('isWhiteLabel' => '1')),
    'missing button' => array_replace($canonical, array('payButtons' => array_slice($canonical['payButtons'], 0, 5, true))),
    'extra button' => array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('future_pay' => 1)))),
    'string button' => array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => '1')))),
    'boolean button' => array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => true)))),
    'out-of-range button' => array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => 2)))),
    'failure extra key' => array('schema' => 3, 'state' => 'failure', 'extra' => 1),
);
foreach ($cache_mutations as $label => $mutation) {
    a2_assert_same(false, PaymentMethodAvailability::classify_cached($mutation), "malformed cache rejected: {$label}");
}

// Fresh success: gate before HTTP, lock released, full response returned,
// canonical cache stored and unknown provider button removed from cache/result.
a2_reset();
$provider_data = array(
    'isWhiteLabel' => '1',
    'payButtons' => array('knet' => true, 'credit_card' => '0', 'future_pay' => 1),
    'providerTrace' => 'retained-on-fresh-result',
);
$before = time();
$service = new PaymentMethodAvailability(false, 'fresh-key', a2_transport(a2_envelope($provider_data)));
$fresh = $service->fetch();
$after = time();
$state = a2_state();
a2_assert_same(1, $state['transport_calls'], 'fresh miss dispatches provider exactly once');
a2_assert_same(false, $state['lock_held_during_transport'], 'advisory lock is released before outbound HTTP');
a2_assert_same(1, $state['lock_acquires'], 'fresh miss acquires one advisory lock');
a2_assert_same(1, $state['lock_releases'], 'fresh miss releases the acquired advisory lock');
a2_assert_same(1, $state['option_writes_by_name']['upayments_payment_methods_rate_gate_live'] ?? 0, 'fresh miss persists durable gate exactly once');
$lkg_writes = array_sum(array_intersect_key($state['option_writes_by_name'], array_flip(preg_grep('/^supcheckout_pm_lkg_v1_[a-f0-9]{16}$/', array_keys($state['option_writes_by_name'])))));
a2_assert_same(1, $lkg_writes, 'fresh success records exactly one scoped last-known-good copy');
a2_assert_same(2, $state['option_writes'], 'fresh success writes only the gate and the last-known-good copy');
$gate = $state['options']['upayments_payment_methods_rate_gate_live'];
a2_assert($gate >= $before + 65 && $gate <= $after + 65, 'durable gate preserves the 65-second cooldown');
a2_assert_same($gate, $state['gate_during_transport'], 'durable gate is persisted before outbound HTTP begins');
a2_assert_same('retained-on-fresh-result', $fresh['providerTrace'], 'fresh success preserves unknown top-level provider data');
a2_assert_same(true, $fresh['isWhiteLabel'], 'fresh success normalizes white-label flag to boolean');
a2_assert_same(1, $fresh['payButtons']['knet'], 'fresh success normalizes true button to integer one');
a2_assert_same(0, $fresh['payButtons']['credit_card'], 'fresh success normalizes string zero button');
a2_assert_same(0, $fresh['payButtons']['google_pay'], 'fresh success fills missing known buttons with zero');
a2_assert(!array_key_exists('future_pay', $fresh['payButtons']), 'fresh result removes unknown provider buttons');
$service_transient = a2_call($service, 'transient_name');
$cached = $state['transients'][$service_transient];
a2_assert_same(a2_canonical(true, 1), array_replace($cached, array('payButtons' => array_replace($cached['payButtons'], array('credit_card' => 1)))), 'canonical cache has exact schema and known-button set');
a2_assert_same(0, $cached['payButtons']['credit_card'], 'canonical cache preserves normalized provider availability');
a2_assert(count($cached) === 4 && !array_key_exists('providerTrace', $cached), 'canonical cache excludes fresh provider extras');
$ttl = $state['transient_ttls'][$service_transient];
a2_assert($ttl >= 1 && $ttl <= 65, 'result cache TTL is the remaining cooldown window');

// Cache hits precede lock/gate/transport and return canonical cache shape.
$cached_hit = $service->fetch();
$state = a2_state();
a2_assert_same($cached, $cached_hit, 'fresh canonical cache hit is returned byte-for-byte');
a2_assert_same(1, $state['transport_calls'], 'cache hit does not dispatch another provider request');
a2_assert_same(1, $state['lock_acquires'], 'cache hit does not acquire advisory lock');

a2_reset();
$failure_hit = new PaymentMethodAvailability(false, 'failure-hit-key', a2_transport(a2_envelope($provider_data)));
$a2_state['transients'][a2_call($failure_hit, 'transient_name')] = array('schema' => 3, 'state' => 'failure');
a2_assert_same(array('result' => 'failure'), $failure_hit->fetch(), 'cached failure sentinel fails closed');
a2_assert_same(0, a2_state()['lock_acquires'], 'cached failure sentinel is handled before lock acquisition');
a2_assert_same(0, a2_state()['transport_calls'], 'cached failure sentinel prevents transport');

// Empty credentials and active cooldown fail closed without transport.
a2_reset();
$empty = new PaymentMethodAvailability(false, '', a2_transport(a2_envelope($provider_data)));
a2_assert_same(null, $empty->fetch(), 'empty API credential preserves null result');
a2_assert_same(0, a2_state()['lock_acquires'], 'empty API credential does not acquire lock');
a2_assert_same(0, a2_state()['transport_calls'], 'empty API credential does not dispatch transport');

a2_reset();
$cooldown = new PaymentMethodAvailability(false, 'cooldown-key', a2_transport(a2_envelope($provider_data)));
$a2_state['options'][a2_call($cooldown, 'rate_gate_option_name')] = time() + 30;
a2_assert_same(array('result' => 'failure'), $cooldown->fetch(), 'active durable cooldown fails closed');
a2_assert_same(0, a2_state()['transport_calls'], 'active durable cooldown prevents transport');
a2_assert_same(1, a2_state()['lock_releases'], 'active durable cooldown releases advisory lock');

// Lock failure performs the inherited one-time cache recheck.
a2_reset();
$contended = new PaymentMethodAvailability(false, 'contended-key', a2_transport(a2_envelope($provider_data)));
$a2_state['lock_result'] = '0';
$a2_state['populate_on_lock_failure'] = function () use ($contended) {
    global $a2_state;
    $a2_state['transients'][a2_call($contended, 'transient_name')] = a2_canonical(false, 0);
};
a2_assert_same(a2_canonical(false, 0), $contended->fetch(), 'lock contention rechecks and returns cache populated by another worker');
a2_assert_same(0, a2_state()['transport_calls'], 'lock-contention cache recheck avoids transport');

a2_reset();
$under_lock = new PaymentMethodAvailability(false, 'under-lock-key', a2_transport(a2_envelope($provider_data)));
$a2_state['populate_on_lock_acquire'] = function () use ($under_lock) {
    global $a2_state;
    $a2_state['transients'][a2_call($under_lock, 'transient_name')] = a2_canonical(false, 0);
};
a2_assert_same(a2_canonical(false, 0), $under_lock->fetch(), 'cache is rechecked under an acquired advisory lock');
a2_assert_same(0, a2_state()['transport_calls'], 'under-lock cache recheck avoids transport');
a2_assert_same(1, a2_state()['lock_releases'], 'under-lock cache hit releases advisory lock');

a2_reset();
$unavailable_lock = new PaymentMethodAvailability(false, 'lock-error-key', a2_transport(a2_envelope($provider_data)));
$a2_state['lock_result'] = null;
a2_assert_same(array('result' => 'failure'), $unavailable_lock->fetch(), 'unsupported/error advisory lock fails closed');
a2_assert_same(0, a2_state()['transport_calls'], 'lock error does not dispatch provider request');

// Gate persistence failure fails closed before transport and releases lock.
a2_reset();
$a2_state['update_result'] = false;
$gate_failure = new PaymentMethodAvailability(false, 'gate-write-key', a2_transport(a2_envelope($provider_data)));
a2_assert_same(array('result' => 'failure'), $gate_failure->fetch(), 'durable gate persistence failure fails closed');
a2_assert_same(0, a2_state()['transport_calls'], 'gate persistence failure prevents transport');
a2_assert_same(1, a2_state()['lock_releases'], 'gate persistence failure releases advisory lock');

a2_reset();
$a2_state['gate_verify_shortfall'] = true;
$gate_verify_failure = new PaymentMethodAvailability(false, 'gate-verify-key', a2_transport(a2_envelope($provider_data)));
a2_assert_same(array('result' => 'failure'), $gate_verify_failure->fetch(), 'durable gate verification shortfall fails closed');
a2_assert_same(0, a2_state()['transport_calls'], 'gate verification shortfall prevents transport');
a2_assert_same(1, a2_state()['lock_releases'], 'gate verification shortfall releases advisory lock');

a2_reset();
$malformed_cache = new PaymentMethodAvailability(false, 'malformed-cache-key', a2_transport(a2_envelope($provider_data)));
$a2_state['transients'][a2_call($malformed_cache, 'transient_name')] = array('schema' => 3, 'result' => 'success');
$malformed_result = $malformed_cache->fetch();
a2_assert_same('success', $malformed_result['result'], 'malformed cache is treated as a miss and refreshed');
a2_assert_same(1, a2_state()['transport_calls'], 'malformed cache refresh dispatches one provider request');
a2_assert_same(1, a2_state()['lock_releases'], 'malformed cache refresh retains then releases one acquired lock');

// Provider/transport failure matrix must cache the exact failure sentinel.
$failure_cases = array(
    'non-array transport' => false,
    'transport not ok' => array('transport_ok' => false, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{}'),
    'HTTP status not 201' => a2_envelope($provider_data, true, 200),
    'curl error' => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 7, 'body' => '{}'),
    'empty body' => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => ''),
    'invalid JSON' => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{'),
    'non-strict status' => a2_envelope($provider_data, 1),
    'missing data' => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => json_encode(array('status' => true))),
    'missing white-label' => a2_envelope(array('payButtons' => array())),
    'malformed white-label' => a2_envelope(array('isWhiteLabel' => 'true', 'payButtons' => array())),
    'malformed button' => a2_envelope(array('isWhiteLabel' => true, 'payButtons' => array('knet' => 2))),
);
foreach ($failure_cases as $label => $response) {
    a2_reset();
    $failure_service = new PaymentMethodAvailability(false, 'failure-' . $label, a2_transport($response));
    a2_assert_same(array('result' => 'failure'), $failure_service->fetch(), "provider failure fails closed: {$label}");
    $failure_state = a2_state();
    a2_assert_same(
        array('schema' => 3, 'state' => 'failure'),
        $failure_state['transients'][a2_call($failure_service, 'transient_name')],
        "provider failure caches canonical sentinel: {$label}"
    );
}

// Gateway/source boundary assertions freeze extraction ownership.
$service_source = file_get_contents(dirname(__DIR__, 2) . '/src/Provider/PaymentMethodAvailability.php');
$gateway_source = file_get_contents(dirname(__DIR__, 2) . '/UPayments.php');
a2_assert(is_string($service_source), 'availability service source is readable');
a2_assert(is_string($gateway_source), 'gateway source is readable');
a2_assert(strpos($service_source, 'namespace Simplixi\\SUPCheckout\\Provider;') !== false, 'availability service uses SUPCheckout Provider namespace');
a2_assert(strpos($service_source, 'wc_add_notice') === false && strpos($service_source, 'wc_get_checkout_url') === false, 'provider/cache service has no WooCommerce presentation dependency');
a2_assert(strpos($gateway_source, "require_once __DIR__ . '/src/Provider/PaymentMethodAvailability.php';") !== false, 'plugin bootstrap explicitly loads availability service');
a2_assert(strpos($gateway_source, 'new PaymentMethodAvailability(') !== false, 'legacy gateway entry point delegates to availability service');
a2_assert(strpos($gateway_source, "execute_upayments_request('check-payment-button-status', 'GET')") !== false, 'gateway preserves exact availability transport route and method');
a2_assert(substr_count($gateway_source, 'function get_payment_methods_transient_name') === 0, 'monolith no longer owns cache fingerprint implementation');
a2_assert(substr_count($gateway_source, 'SELECT GET_LOCK(%s, 0)') === 0, 'monolith no longer owns availability advisory lock implementation');
a2_assert(substr_count($gateway_source, "'upay_pm_v3_'") === 0, 'monolith no longer owns availability transient prefix');
a2_assert(substr_count($gateway_source, 'RATE_GATE_COOLDOWN') === 0, 'monolith no longer owns availability cooldown constant');
a2_assert(strpos($gateway_source, 'Payment methods could not be loaded. Please try again.') !== false, 'gateway preserves legacy failure notice text');
a2_assert(strpos($gateway_source, "array('result' => 'failure', 'redirect' => wc_get_checkout_url())") !== false, 'gateway preserves legacy failure redirect shape');

echo "\nArchitecture Payment-Method Availability: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
