<?php

$q5_pass = 0;
$q5_fail = 0;
$q5_root = dirname(__DIR__, 2);

function q5_assert($condition, $label) {
    global $q5_pass, $q5_fail;

    if ($condition) {
        ++$q5_pass;
        echo "PASS: {$label}\n";
        return;
    }

    ++$q5_fail;
    echo "FAIL: {$label}\n";
}

function q5_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q5_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q5_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$phpstan = q5_read($q5_root, 'phpstan.neon.dist');
$phpcs = q5_read($q5_root, 'phpcs.xml.dist');
$availability = q5_read($q5_root, 'src/Provider/PaymentMethodAvailability.php');
$tests = q5_read($q5_root, 'tests/unit/Provider/PaymentMethodAvailabilityTest.php');
$fixture = q5_read($q5_root, 'tests/support/wordpress-availability.php');
$option_fixture = q5_read($q5_root, 'tests/support/wordpress-option-store.php');
$analysis_stubs = q5_read($q5_root, 'tests/phpstan/wordpress-option-stubs.php');
$workflow = q5_read($q5_root, '.github/workflows/quality-gates.yml');
$quality_record = q5_read($q5_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q5_read($q5_root, 'docs/project/PROJECT-STATUS.md');
$readme = q5_read($q5_root, 'README.md');
$handoff = q5_read($q5_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q5_read($q5_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q5_assert(q5_contains($phpstan, 'src/Provider/PaymentMethodAvailability.php'), 'PHPStan owns payment-method availability');
q5_assert(q5_contains($phpcs, 'src/Provider/PaymentMethodAvailability.php'), 'PHPCS owns payment-method availability');
q5_assert(!q5_contains($phpstan, 'baseline'), 'Q5 remains baseline-free');
q5_assert(!q5_contains($phpstan, 'ignoreErrors'), 'Q5 introduces no ignored analyzer errors');

q5_assert(q5_contains($availability, 'private const CACHE_SCHEMA = 3;'), 'availability cache schema remains exactly three');
q5_assert(q5_contains($availability, 'private const RATE_GATE_COOLDOWN = 65;'), 'availability cooldown remains exactly 65 seconds');
foreach (array(
    'knet',
    'credit_card',
    'apple_pay_knet',
    'apple_pay',
    'samsung_pay',
    'google_pay',
) as $button) {
    q5_assert(q5_contains($availability, "'{$button}'"), "known payment button remains explicit: {$button}");
}
q5_assert(q5_contains($availability, "hash_hmac('sha256', \$mode . '|' . \$this->api_key, wp_salt('auth'))"), 'credential cache identity remains HMAC-derived');
q5_assert(q5_contains($availability, "'upay_pm_v3_' . \$this->fingerprint()") && q5_contains($availability, 'return substr($fingerprint, 0, 16);'), 'cache prefix and fingerprint width remain frozen');
q5_assert(q5_contains($availability, "\$db_name . '|' . \$wpdb->prefix . '|' . (string) get_current_blog_id() . '|' . \$mode"), 'advisory lock remains database/site/mode scoped');
q5_assert(q5_contains($availability, "'upay_pm_' . substr(hash('sha256', \$lock_input), 0, 16)"), 'advisory lock name remains hashed and bounded');
q5_assert(q5_contains($availability, "'SELECT GET_LOCK(%s, 0)'"), 'availability lock acquisition remains non-blocking');
q5_assert(q5_contains($availability, "'SELECT RELEASE_LOCK(%s)'"), 'availability lock release remains explicit');

$set_gate = strpos($availability, '$new_not_before = $now + self::RATE_GATE_COOLDOWN;');
$release = strpos($availability, '$this->release_lock();', $set_gate === false ? 0 : $set_gate);
$transport = strpos($availability, '$transport = call_user_func($this->transport);');
q5_assert(
    $set_gate !== false && $release !== false && $transport !== false
        && $set_gate < $release && $release < $transport,
    'durable gate is set and advisory lock released before transport'
);
q5_assert(q5_contains($availability, "\$verify_gate['not_before'] < \$new_not_before"), 'durable gate write is verified before transport');

q5_assert(q5_contains($availability, "count(\$cached) === 2"), 'failure cache requires its exact two-key shape');
q5_assert(q5_contains($availability, "\$cached_keys !== \$expected_keys"), 'success cache rejects extra or missing top-level keys');
q5_assert(q5_contains($availability, "\$button_keys !== \$expected_button_keys"), 'success cache rejects extra or missing button keys');
q5_assert(q5_contains($availability, "\$cached['payButtons'][\$button] !== 0"), 'cached payment buttons remain strict zero-or-one integers');
q5_assert(q5_contains($availability, "array('schema' => self::CACHE_SCHEMA, 'state' => 'failure')"), 'provider failures retain canonical negative cache sentinel');

q5_assert(q5_contains($availability, "\$transport['transport_ok'] !== true"), 'transport success remains strict true');
q5_assert(q5_contains($availability, "(int) \$transport['http_status'] !== 201"), 'availability transport requires exact HTTP 201');
q5_assert(q5_contains($availability, "(int) \$transport['curl_errno'] !== 0"), 'availability transport rejects curl errors');
q5_assert(q5_contains($availability, "\$result['status'] !== true"), 'provider envelope requires strict true status');
q5_assert(q5_contains($availability, "\$white_label === true || \$white_label === 1 || \$white_label === '1'"), 'white-label true normalization remains bounded');
q5_assert(q5_contains($availability, "\$value === false || \$value === 0 || \$value === '0'"), 'button false normalization remains bounded');
q5_assert(q5_contains($availability, "\$normalized_buttons[\$button] = 0;"), 'missing known buttons remain fail-closed unavailable');
q5_assert(q5_contains($availability, "\$payment_methods['payButtons'] = \$normalized_buttons;"), 'fresh provider result exposes only normalized known buttons');

q5_assert(substr_count($tests, 'public function test_') >= 8, 'PaymentMethodAvailability has focused PHPUnit characterization');
foreach (array(
    'cache_gate_and_lock_identities_are_scoped_without_leaking_credentials',
    'cache_classifier_accepts_only_exact_schema_three_shapes',
    'fresh_success_persists_gate_before_transport_and_caches_canonical_result',
    'cache_hits_failure_sentinels_and_empty_credentials_bypass_mutation',
    'lock_contention_rechecks_cache_and_lock_errors_fail_closed',
    'cooldown_and_gate_persistence_failures_prevent_transport',
    'malformed_cache_is_refreshed_instead_of_trusted',
    'every_transport_or_provider_failure_caches_the_exact_failure_sentinel',
) as $test_name) {
    q5_assert(q5_contains($tests, $test_name), "availability test exists: {$test_name}");
}
q5_assert(q5_contains($tests, "'failure extra key'" ) || q5_contains($tests, "'extra' => 1"), 'cache classifier tests reject expanded failure shapes');
q5_assert(q5_contains($tests, "'non-strict status'"), 'provider failure matrix covers non-boolean success');
q5_assert(q5_contains($tests, "'malformed button'"), 'provider failure matrix covers invalid button flags');

q5_assert(q5_contains($fixture, 'class SUPCheckout_Test_Availability_WPDB'), 'unit fixture provides deterministic advisory-lock database');
q5_assert(q5_contains($fixture, "strpos(\$statement['query'], 'SELECT GET_LOCK(') === 0"), 'unit fixture executes advisory-lock acquisition semantics');
q5_assert(q5_contains($fixture, "strpos(\$statement['query'], 'SELECT RELEASE_LOCK(') === 0"), 'unit fixture executes advisory-lock release semantics');
q5_assert(q5_contains($fixture, 'function get_transient(') && q5_contains($fixture, 'function set_transient('), 'unit fixture provides deterministic transient persistence');
q5_assert(q5_contains($option_fixture, 'supcheckout_test_update_option_result'), 'option fixture exposes deterministic gate-write failure');
q5_assert(q5_contains($analysis_stubs, 'public $prefix;'), 'analysis stubs declare the bounded wpdb prefix');
q5_assert(q5_contains($analysis_stubs, 'function get_transient('), 'analysis stubs declare transient reads');
q5_assert(q5_contains($analysis_stubs, 'function get_current_blog_id('), 'analysis stubs declare multisite lock scope');

q5_assert(q5_contains($workflow, 'quality-platform-payment-method-availability-harness.php'), 'Q5 harness is mandatory in Quality Gates');
q5_assert(q5_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
q5_assert(q5_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'protected H12 aggregator still reads quality result');
q5_assert(q5_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'protected H12 aggregator still reads syntax result');

foreach (array(
    'd4132b0caccaa6edc6d7421afcfd8e9694563224',
    'dee657b03f8d44670b0ae2501a40dabf718d4bb2',
    'Quality Gates run #197',
    '984053aee6bb50e62e457a639f44307e461f5e38',
    'Quality Gates run #198',
    'implementation branch deleted',
) as $closure_evidence) {
    q5_assert(q5_contains($quality_record, $closure_evidence), "Q5 closure evidence is pinned: {$closure_evidence}");
}
q5_assert(q5_contains($quality_record, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q5');
q5_assert(q5_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q5_assert(q5_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q5_assert(
    !q5_contains($playbook, 'Last verified implementation main SHA: 984053aee6bb50e62e457a639f44307e461f5e38')
        && !q5_contains($playbook, 'Canonical implementation tree: dee657b03f8d44670b0ae2501a40dabf718d4bb2'),
    'master playbook restart anchors do not mistake verified Q5 closure for current main'
);
q5_assert(!q5_contains($handoff, 'CURRENT / Q5'), 'handoff rejects the stale current-Q5 marker');
q5_assert(!q5_contains($playbook, 'CURRENT / Q5'), 'master playbook rejects the stale current-Q5 marker');
q5_assert(q5_contains($workflow, "reject_across_live_records 'CURRENT / Q5'"), 'Governance rejects stale current-Q5 markers');

echo "\nQ5 Payment-Method Availability Analysis: {$q5_pass} PASS / {$q5_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q5_fail === 0 ? 0 : 1);
