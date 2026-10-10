<?php

$q4_pass = 0;
$q4_fail = 0;
$q4_root = dirname(__DIR__, 2);

function q4_assert($condition, $label) {
    global $q4_pass, $q4_fail;

    if ($condition) {
        ++$q4_pass;
        echo "PASS: {$label}\n";
        return;
    }

    ++$q4_fail;
    echo "FAIL: {$label}\n";
}

function q4_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q4_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q4_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$phpstan = q4_read($q4_root, 'phpstan.neon.dist');
$phpcs = q4_read($q4_root, 'phpcs.xml.dist');
$verifier = q4_read($q4_root, 'src/Payment/StatusVerifier.php');
$tests = q4_read($q4_root, 'tests/unit/Payment/StatusVerifierTest.php');
$http_fixture = q4_read($q4_root, 'tests/support/wordpress-http.php');
$analysis_stubs = q4_read($q4_root, 'tests/phpstan/wordpress-option-stubs.php');
$workflow = q4_read($q4_root, '.github/workflows/quality-gates.yml');
$quality_record = q4_read($q4_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q4_read($q4_root, 'docs/project/PROJECT-STATUS.md');
$readme = q4_read($q4_root, 'README.md');
$audit = q4_read($q4_root, 'docs/project/REPOSITORY-AUDIT.md');
$handoff = q4_read($q4_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q4_read($q4_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q4_assert(q4_contains($phpstan, 'src/Payment/StatusVerifier.php'), 'PHPStan owns authenticated status verification');
q4_assert(q4_contains($phpcs, 'src/Payment/StatusVerifier.php'), 'PHPCS owns authenticated status verification');
q4_assert(!q4_contains($phpstan, 'baseline'), 'Q4 remains baseline-free');
q4_assert(!q4_contains($phpstan, 'ignoreErrors'), 'Q4 introduces no ignored analyzer errors');

q4_assert(q4_contains($verifier, '@param mixed $gateway'), 'verifier documents its defensive gateway boundary');
q4_assert(q4_contains($verifier, '@param mixed $order'), 'verifier documents its defensive order boundary');
q4_assert(q4_contains($verifier, "'sandboxapi.upayments.com'"), 'sandbox status host remains exact');
q4_assert(q4_contains($verifier, "'apiv2api.upayments.com'"), 'live status host remains exact');
q4_assert(q4_contains($verifier, "'/api/v1/get-payment-status/'"), 'status path remains exact');
q4_assert(
    q4_contains($verifier, "strtolower((string) \$parts['scheme']) !== 'https'")
        && q4_contains($tests, "'plaintext scheme' => array('scheme', 'http')"),
    'HTTPS-only destination enforcement has executable characterization'
);
q4_assert(
    q4_contains($tests, "\$gateway->host = 'apiv2api.upayments.com';")
        && q4_contains($tests, 'https://apiv2api.upayments.com/api/v1/get-payment-status/track-abc')
        && q4_contains($tests, "'user info'        => array('userinfo', 'user@')")
        && q4_contains($tests, "'password info'    => array('userinfo', 'user:password@')")
        && q4_contains($tests, "'explicit port'    => array('port', ':8443')")
        && q4_contains($tests, "'fragment'         => array('url_suffix', '#fragment')")
        && q4_contains($tests, "'wrong path'       => array('path_prefix', '/api/v2/')"),
    'sandbox/live allowlist and every forbidden URL component have executable characterization'
);
q4_assert(preg_match("/'redirection'\\s*=>\\s*0/", $verifier) === 1, 'status transport forbids redirects');
q4_assert(preg_match("/'limit_response_size'\\s*=>\\s*1048576/", $verifier) === 1, 'status transport bounds response bodies to 1 MiB');
q4_assert(q4_contains($verifier, 'strlen($body) >= 1048576'), 'status transport rejects a body that reaches the read cap');
q4_assert(q4_contains($verifier, "'sslverify'   => true"), 'status transport retains TLS verification');
q4_assert(q4_contains($verifier, "'Authorization' => 'Bearer ' . \$gateway->apiKey"), 'Bearer credential remains status-only transport state');
q4_assert(q4_contains($verifier, '$http_status !== 201'), 'status transport requires exact HTTP 201');
q4_assert(q4_contains($verifier, "\$decoded['status'] !== true"), 'provider envelope requires strict true status');

$url_check = strpos($verifier, 'if (!self::is_allowed_status_url($url, $track_id))');
$rate_check = strpos($verifier, 'if (!StatusRateGate::acquire($gateway, $source))');
$http_call = strpos($verifier, '$response = wp_remote_get($url');
q4_assert(
    $url_check !== false && $rate_check !== false && $http_call !== false
        && $url_check < $rate_check && $rate_check < $http_call,
    'destination validation precedes rate consumption and Bearer transport'
);

foreach (array(
    'track_id',
    'merchant_requested_order_id',
    'total_price',
    'currency_type',
    'reference',
) as $binding_field) {
    q4_assert(q4_contains($verifier, "'{$binding_field}'"), "strict binding field remains required: {$binding_field}");
}
q4_assert(q4_contains($verifier, "hash_equals(\$local_canonical, \$verified_canonical)"), 'amount binding remains exact and float-free');
q4_assert(q4_contains($verifier, "'captured_payment_id_missing'"), 'captured status still requires a provider payment ID');

q4_assert(substr_count($tests, 'public function test_') >= 7, 'StatusVerifier has focused PHPUnit characterization');
q4_assert(substr_count($tests, 'assert_unauthenticated_failure') === 7, 'every transport/envelope failure asserts unauthenticated and unbound state');
q4_assert(q4_contains($tests, "'track-oversized'"), 'oversized status response is explicitly characterized as unauthenticated failure');
foreach (array(
    'invalid_boundaries_fail_before_rate_or_http_mutation',
    'disallowed_destination_is_rejected_before_bearer_or_rate_slot',
    'hardened_transport_binds_an_exact_captured_transaction',
    'live_status_host_uses_the_same_exact_authenticated_contract',
    'network_and_protocol_failures_remain_unauthenticated',
    'binding_rejects_identity_currency_and_amount_mismatches',
    'capture_requires_payment_id_while_nonterminal_results_bind_fail_closed',
    'exact_decimal_binding_accepts_only_numeric_equality',
) as $test_name) {
    q4_assert(q4_contains($tests, $test_name), "status-verifier test exists: {$test_name}");
}

q4_assert(q4_contains($http_fixture, 'supcheckout_test_http_calls'), 'HTTP fixture records exact outbound calls');
q4_assert(q4_contains($http_fixture, 'SUPCheckout_Test_WP_Error'), 'HTTP fixture exposes deterministic network failure');
q4_assert(q4_contains($analysis_stubs, 'function wp_remote_get('), 'analysis stubs declare bounded WordPress HTTP transport');
q4_assert(q4_contains($analysis_stubs, 'function is_wp_error('), 'analysis stubs declare WordPress error classification');

q4_assert(q4_contains($workflow, 'quality-platform-authenticated-status-harness.php'), 'Q4 harness is mandatory in Quality Gates');
q4_assert(q4_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
q4_assert(q4_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'protected H12 aggregator still reads quality result');
q4_assert(q4_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'protected H12 aggregator still reads syntax result');

foreach (array(
    '8543bdfce1a4e216200791dc5637b646f49bcb59',
    'ad5ae98d5e935bb48d1441f94e130f5d3adb3ca9',
    'Quality Gates run #194',
    '4b3db92b0ded0c598bad0ab677babab9e6102811',
    'Quality Gates run #195',
    'implementation branch deleted',
) as $closure_evidence) {
    q4_assert(q4_contains($quality_record, $closure_evidence), "Q4 closure evidence is pinned: {$closure_evidence}");
}
q4_assert(
    q4_contains($quality_record, 'Q4 is DONE / VERIFIED')
        && q4_contains($quality_record, 'Q16 is DONE / VERIFIED'),
    'quality record closes Q4 and advances beyond it'
);
q4_assert(q4_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q4_assert(q4_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q4_assert(
    q4_contains($audit, 'Q1-Q16 progressively added locked toolchain and bounded module evidence')
        && q4_contains($audit, 'Q17 adds payment-runtime checkout/lifecycle characterization'),
    'repository audit retains historical closure and later-gate scope'
);
q4_assert(!q4_contains($handoff, 'CURRENT / Q3'), 'handoff rejects the stale current-Q3 marker');
q4_assert(!q4_contains($playbook, 'CURRENT / Q3'), 'master playbook rejects the stale current-Q3 marker');
q4_assert(q4_contains($workflow, "reject_across_live_records 'CURRENT / Q3'"), 'Governance rejects stale current-Q3 markers');

echo "\nQ4 Authenticated Status Analysis: {$q4_pass} PASS / {$q4_fail} FAIL\n";
exit($q4_fail === 0 ? 0 : 1);
