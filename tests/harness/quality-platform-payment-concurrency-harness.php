<?php

$q3_pass = 0;
$q3_fail = 0;
$q3_root = dirname(__DIR__, 2);

function q3_assert($condition, $label) {
    global $q3_pass, $q3_fail;

    if ($condition) {
        ++$q3_pass;
        echo "PASS: {$label}\n";
        return;
    }

    ++$q3_fail;
    echo "FAIL: {$label}\n";
}

function q3_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q3_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q3_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$phpstan = q3_read($q3_root, 'phpstan.neon.dist');
$phpcs = q3_read($q3_root, 'phpcs.xml.dist');
$rate_gate = q3_read($q3_root, 'src/Payment/StatusRateGate.php');
$order_lock = q3_read($q3_root, 'src/Payment/OrderLock.php');
$rate_tests = q3_read($q3_root, 'tests/unit/Payment/StatusRateGateTest.php');
$lock_tests = q3_read($q3_root, 'tests/unit/Payment/OrderLockTest.php');
$option_fixture = q3_read($q3_root, 'tests/support/wordpress-option-store.php');
$analysis_stubs = q3_read($q3_root, 'tests/phpstan/wordpress-option-stubs.php');
$workflow = q3_read($q3_root, '.github/workflows/quality-gates.yml');
$quality_record = q3_read($q3_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q3_read($q3_root, 'docs/project/PROJECT-STATUS.md');
$readme = q3_read($q3_root, 'README.md');
$handoff = q3_read($q3_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q3_read($q3_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

foreach (array(
    'src/Payment/StatusRateGate.php',
    'src/Payment/OrderLock.php',
) as $path) {
    q3_assert(q3_contains($phpstan, $path), "PHPStan owns Q3 payment-safety module: {$path}");
    q3_assert(q3_contains($phpcs, $path), "PHPCS owns Q3 payment-safety module: {$path}");
}
q3_assert(q3_contains($phpstan, 'tests/phpstan/wordpress-option-stubs.php'), 'PHPStan scans the bounded WordPress option symbols');
q3_assert(!q3_contains($phpstan, 'baseline'), 'Q3 remains baseline-free');
q3_assert(!q3_contains($phpstan, 'ignoreErrors'), 'Q3 introduces no ignored analyzer errors');

q3_assert(q3_contains($rate_gate, '@param mixed $gateway'), 'rate gate documents its defensive mixed gateway boundary');
q3_assert(q3_contains($rate_gate, 'private const LIMIT_PER_MINUTE = 30;'), 'rate ceiling remains exactly 30 per minute');
q3_assert(q3_contains($rate_gate, "private const PREFIX = 'simplixpay_upay_status_v1_';"), 'rate-slot option identity remains frozen');
q3_assert(q3_contains($rate_gate, "hash_hmac('sha256', \$mode . '|' . \$gateway->apiKey, \$salt)"), 'credential/mode scope remains HMAC-derived');
q3_assert(q3_contains($rate_gate, 'OrderLock::insert_if_absent($option_name, (string) time())'), 'rate slots are acquired by the atomic INSERT IGNORE helper');
q3_assert(!q3_contains($rate_gate, 'if (add_option('), 'rate slots never trust non-atomic add_option() acquisition');
q3_assert(q3_contains($rate_gate, 'self::delete_bucket($prefix, $scope, $previous_bucket);'), 'valid previous bucket cleanup remains explicit');

q3_assert(
    substr_count($order_lock, '@param int') === 2 && !q3_contains($order_lock, '@param mixed $order_id'),
    'order lock retains its positive-integer caller contract'
);
q3_assert(q3_contains($order_lock, '@param mixed $token'), 'order lock documents its defensive mixed token boundary');
q3_assert(q3_contains($order_lock, 'private const TTL = 45;'), 'order lock TTL remains exactly 45 seconds');
q3_assert(q3_contains($order_lock, "private const PREFIX = 'simplixpay_upay_order_lock_v1_';"), 'order-lock option identity remains frozen');
q3_assert(q3_contains($order_lock, 'self::insert_if_absent($name, $record)'), 'first lock acquisition uses the atomic INSERT IGNORE helper');
q3_assert(!q3_contains($order_lock, 'if (add_option('), 'first lock acquisition never trusts non-atomic add_option()');
q3_assert(q3_contains($order_lock, 'INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)'), 'lock creation relies on the unique option_name index');
q3_assert(q3_contains($order_lock, '(int) $inserted !== 1'), 'only the worker whose insert created the row acquires');
q3_assert(q3_contains($order_lock, "wp_cache_get('notoptions', 'options')"), 'raw lock insert clears the cached option-absent marker');
q3_assert(q3_contains($order_lock, 'AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)'), 'takeover/release SQL remains exact-record, bytewise compare-and-swap');
q3_assert(!q3_contains($order_lock, 'delete_option($name)'), 'order lock never blindly deletes a contested lock');
q3_assert(q3_contains($order_lock, "wp_cache_delete(\$name, 'options')"), 'successful SQL mutation flushes the option cache');
q3_assert(substr_count($order_lock, 'phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set lock mutation uses freshly prepared SQL and must bypass caches.') === 2, 'only the two immediately prepared conditional queries carry exact-line atomic-lock PHPCS annotations');
q3_assert(!q3_contains($phpcs, '<exclude name="WordPress.DB.PreparedSQL'), 'PHPCS configuration does not disable prepared-SQL enforcement');

q3_assert(substr_count($rate_tests, 'public function test_') >= 4, 'StatusRateGate has focused PHPUnit characterization');
foreach (array(
    'exactly_thirty_atomic_slots_are_available_per_minute',
    'credential_and_mode_scopes_are_isolated_without_leaking_secrets',
    'previous_valid_bucket_slots_are_deleted_before_acquisition',
) as $test_name) {
    q3_assert(q3_contains($rate_tests, $test_name), "rate-gate test exists: {$test_name}");
}

q3_assert(substr_count($lock_tests, 'public function test_') >= 6, 'OrderLock has focused PHPUnit characterization');
foreach (array(
    'atomic_acquire_blocks_a_second_live_owner',
    'release_requires_the_exact_owner_token',
    'expired_record_is_replaced_only_by_compare_and_swap',
    'stale_takeover_cannot_replace_a_newer_competing_owner',
    // OL-1 (2026-10-22) deliberately replaced "malformed_existing_record_fails_closed_without_deletion":
    // it pinned a record no worker holds or can release, which blocked the order's
    // payment lifecycle forever. Recovery stays compare-and-swap only, never steals a
    // live lease, and has exactly one winner.
    'an_unparseable_record_is_recovered_by_compare_and_swap',
    'a_record_expiring_far_beyond_the_bound_is_recovered',
    'a_live_record_is_never_stolen',
    'a_hold_just_inside_the_implausible_expiry_bound_is_not_stolen',
    'two_concurrent_recoverers_of_one_corrupt_record_have_exactly_one_winner',
) as $test_name) {
    q3_assert(q3_contains($lock_tests, $test_name), "order-lock test exists: {$test_name}");
}
q3_assert(q3_contains($order_lock, 'self::replace_if_current($name, $expected, $record)'), 'stale, corrupt and implausible lock recovery is one exact-value compare-and-swap');
q3_assert(q3_contains($order_lock, 'private const MAX_CLOCK_AHEAD = 3600;'), 'implausible-expiry recovery bound stays far above the 45-second lease');

q3_assert(q3_contains($option_fixture, 'class SUPCheckout_Test_WPDB'), 'unit fixture provides a deterministic wpdb compare-and-swap seam');
q3_assert(q3_contains($option_fixture, "strpos(\$statement['query'], 'UPDATE ') === 0"), 'unit fixture executes conditional update semantics');
q3_assert(q3_contains($option_fixture, "strpos(\$statement['query'], 'DELETE ') === 0"), 'unit fixture executes conditional delete semantics');
q3_assert(q3_contains($analysis_stubs, 'class wpdb'), 'analysis stubs declare the bounded wpdb surface');
q3_assert(q3_contains($analysis_stubs, 'function add_option('), 'analysis stubs declare atomic option acquisition');
q3_assert(q3_contains($analysis_stubs, 'function wp_cache_delete('), 'analysis stubs declare option-cache invalidation');

q3_assert(q3_contains($workflow, 'quality-platform-payment-concurrency-harness.php'), 'Q3 harness is mandatory in Quality Gates');
q3_assert(q3_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
q3_assert(q3_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'protected H12 aggregator still reads quality result');
q3_assert(q3_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'protected H12 aggregator still reads syntax result');

foreach (array(
    'c2c30f90688747a523301cb776ed920ef39063f3',
    '3550fdbb0810af26808851e24e39a6130725e8db',
    'Quality Gates run #182',
    '356680b9fe8a2724e778d40386ca182247715249',
    'Quality Gates run #183',
    'implementation branch deleted',
) as $closure_evidence) {
    q3_assert(q3_contains($quality_record, $closure_evidence), "Q2 closure evidence is pinned: {$closure_evidence}");
}
q3_assert(
    q3_contains($quality_record, 'Q3 is DONE / VERIFIED') &&
    q3_contains($quality_record, 'Q16 is DONE / VERIFIED'),
    'quality record closes Q3 and advances beyond it'
);
q3_assert(q3_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q3_assert(q3_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q3_assert(!q3_contains($handoff, 'CURRENT / Q2'), 'handoff rejects the stale current-Q2 marker');
q3_assert(!q3_contains($playbook, 'CURRENT / Q2'), 'master playbook rejects the stale current-Q2 marker');
q3_assert(q3_contains($workflow, "reject_across_live_records 'CURRENT / Q2'"), 'Governance rejects stale current-Q2 markers');

echo "\nQ3 Payment Concurrency Analysis: {$q3_pass} PASS / {$q3_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q3_fail === 0 ? 0 : 1);
