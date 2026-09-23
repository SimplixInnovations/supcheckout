<?php

$pass = 0;
$fail = 0;
$root = dirname(__DIR__, 2);

function q16_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) { ++$pass; echo "PASS: " . $label . "\n"; return; }
    ++$fail; echo "FAIL: " . $label . "\n";
}
function q16_read($root, $relative) {
    $value = file_get_contents($root . '/' . $relative);
    q16_assert(is_string($value), 'source readable: ' . $relative);
    return is_string($value) ? $value : '';
}
function q16_has($source, $needle) { return strpos($source, $needle) !== false; }
function q16_blob($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q16_read($root, 'phpstan.neon.dist');
$phpcs = q16_read($root, 'phpcs.xml.dist');
$preflight = q16_read($root, 'src/Migration/MigrationPreflight.php');
$batch = q16_read($root, 'src/Migration/MigrationBatch.php');
$executor = q16_read($root, 'src/Migration/MigrationExecutor.php');
$preflight_tests = q16_read($root, 'tests/unit/Migration/MigrationPreflightTest.php');
$batch_tests = q16_read($root, 'tests/unit/Migration/MigrationBatchTest.php');
$executor_tests = q16_read($root, 'tests/unit/Migration/MigrationExecutorTest.php');
$fixture = q16_read($root, 'tests/support/wordpress-migration-core.php');
$stubs = q16_read($root, 'tests/phpstan/migration-core-stubs.php');
$bootstrap = q16_read($root, 'tests/bootstrap.php');
$workflow = q16_read($root, '.github/workflows/quality-gates.yml');
$quality = q16_read($root, 'docs/project/QUALITY-PLATFORM.md');
$status = q16_read($root, 'docs/project/PROJECT-STATUS.md');
$readme = q16_read($root, 'README.md');
$agents = q16_read($root, 'AGENTS.md');
$playbook = q16_read($root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');
$audit = q16_read($root, 'docs/project/REPOSITORY-AUDIT.md');
$handoff = q16_read($root, 'docs/project/NEW-CHAT-HANDOFF.md');

foreach (array('src/Migration/MigrationPreflight.php','src/Migration/MigrationBatch.php','src/Migration/MigrationExecutor.php') as $path) {
    q16_assert(q16_has($phpstan, $path), 'PHPStan owns migration core: ' . $path);
    q16_assert(q16_has($phpcs, $path), 'PHPCS owns migration core: ' . $path);
}
q16_assert(q16_has($phpstan, 'tests/phpstan/migration-core-stubs.php'), 'PHPStan loads migration-core stubs');
q16_assert(!q16_has($phpstan, 'baseline'), 'Q16 remains baseline-free');
q16_assert(!q16_has($phpstan, 'ignoreErrors'), 'Q16 has no ignored analyzer errors');

q16_assert(q16_has($preflight, 'const MAX_ORDERS = 200;'), 'preflight scan remains bounded');
q16_assert(q16_has($preflight, 'const GLOBAL_PROVENANCE_LIMIT = 200;'), 'provenance scan remains bounded');
q16_assert(q16_has($preflight, "preg_match('/^(?:0|[1-9][0-9]*)\\z/', \$value)"), 'preflight numeric IDs use absolute end anchor');
q16_assert(q16_has($preflight, "preg_match('/^[0-9a-f]{32}\\z/', \$value)"), 'preflight generation uses absolute end anchor');
q16_assert(q16_has($preflight, 'LIMIT %d'), 'provenance scan limit is prepared as data');
q16_assert(q16_has($preflight, 'KIND_LEGACY_COMPAT') && q16_has($preflight, 'SOURCE_LEGACY_VERIFIED_CAPTURE'), 'preflight preserves legacy-only migration provenance');
q16_assert(!q16_has($preflight, 'wp_remote_') && !q16_has($preflight, 'curl_'), 'preflight performs no provider transport');

q16_assert(q16_has($batch, 'const MAX_INPUT_USERS = 500;'), 'batch input cap remains exact');
q16_assert(q16_has($batch, 'const MAX_LIMIT = 50;'), 'batch page cap remains exact');
q16_assert(q16_has($batch, "RESULT_LEDGER_KEY = '_simplixpay_upayments_migration_result_v1'"), 'operations ledger identity remains separate');
q16_assert(q16_has($batch, "hash_hmac('sha256'"), 'resume fingerprint remains HMAC scoped');
q16_assert(q16_has($batch, "preg_match('/^[a-z0-9_]+\\z/', \$reason)"), 'durable reason tokens use absolute end anchor');
q16_assert(q16_has($batch, 'operations_result_ledger_write_failed') && q16_has($batch, 'batch_checkpoint_failed'), 'checkpoint uncertainty remains fail closed');

q16_assert(q16_has($executor, 'MigrationPreflight::inspect('), 'executor reuses preflight contract');
q16_assert(q16_has($executor, 'SELECT GET_LOCK(%s, 5)') && q16_has($executor, 'SELECT RELEASE_LOCK(%s)'), 'executor lock contract remains exact');
q16_assert(q16_has($executor, 'KIND_LEGACY_COMPAT') && q16_has($executor, 'SOURCE_LEGACY_VERIFIED_CAPTURE'), 'executor preserves legacy provenance contract');
q16_assert(!q16_has($executor, 'SOURCE_CREATE_201'), 'executor cannot fabricate Create-201 provenance');
foreach (array('wp_remote_','curl_','process_payment','add_meta_data(','save_meta_data(','delete_meta_data(','Scheduler','CycleClaim') as $needle) {
    q16_assert(!q16_has($executor, $needle), 'executor excludes unrelated ownership: ' . $needle);
}

foreach (array('invalid_inputs_fail_closed_before_history_access','fresh_user_is_clean_and_unscoped_legacy_history_is_migratable','cross_user_token_conflict_remains_blocked','terminal_newline_order_identifier_is_rejected_as_indeterminate','terminal_newline_generation_is_not_accepted_as_identity_context') as $name) q16_assert(q16_has($preflight_tests, $name), 'preflight test: ' . $name);
foreach (array('user_id_parser_preserves_exact_canonical_contract','bounded_clean_page_writes_redacted_checkpoint_and_resumes','trailing_newline_reason_cannot_be_reused_as_durable_checkpoint','invalid_windows_fail_before_execution_or_checkpoint_mutation') as $name) q16_assert(q16_has($batch_tests, $name), 'batch test: ' . $name);
foreach (array('clean_user_is_idempotent_without_lock_or_mutation','dry_run_migratable_user_performs_no_identity_mutation','existing_secret_legacy_orphan_migrates_to_exact_legacy_provenance','invalid_inputs_fail_closed_before_preflight') as $name) q16_assert(q16_has($executor_tests, $name), 'executor test: ' . $name);
q16_assert(q16_has($fixture, 'SUPCheckout_Test_Migration_Core_WPDB'), 'fixture models deterministic DB boundary');
q16_assert(q16_has($stubs, 'namespace UPayments\\Token'), 'stub models H12 namespace');
q16_assert(q16_has($bootstrap, "require __DIR__ . '/support/wordpress-migration-core.php';"), 'PHPUnit bootstrap loads migration fixture');

q16_assert(q16_blob($root . '/includes/Subscription/Cron/Scheduler.php') === '79aa9ec35264cfbbeb1c14e87ab9e204d9a028b5', 'protected Scheduler blob remains exact');
q16_assert(q16_blob($root . '/includes/Subscription/Cron/CycleClaim.php') === '0ae25b176ce622f0500fb279a40ab60275a72705', 'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening');
foreach (array('phase-9i-preflight-harness.php','phase-9i-executor-harness.php','phase-9i-operations-harness.php') as $name) q16_assert(q16_has($workflow, 'tests/harness/' . $name), 'Phase 9I regression remains mandatory: ' . $name);
q16_assert(q16_has($workflow, 'tests/harness/quality-platform-migration-core-harness.php'), 'Q16 harness is mandatory');
q16_assert(q16_has($workflow, 'if: ${{ always() }}'), 'H12 aggregator always runs');
q16_assert(q16_has($agents, 'Quality Platform Q1-Q19 harnesses;'), 'AGENTS keeps the closed Quality Platform harness set mandatory');

foreach (array(
    '3cff2fcc64053d79be7427696c86039f1b52bbfd',
    'b9cc6eafb3c7f8df36b9c5db8b2e45bb330688d2',
    'Quality Gates run #315',
    '160 tests / 987 assertions',
    'Q16 **120/0**',
    'CodeQL PR scan #83',
    '06a9ebd732c7cc3f062d4bb361aaef4054a1dfa3',
    'Quality Gates run #316',
    'main security run #84',
    'implementation branch deleted'
) as $evidence) {
    q16_assert(q16_has($quality, $evidence), 'Q16 closure evidence pinned: ' . $evidence);
}
q16_assert(q16_has($quality, '## Closed Q16 contract'), 'quality record preserves closed Q16 contract');
q16_assert(q16_has($quality, '**Status:** DONE / VERIFIED (Q1-Q19)') && q16_has($quality, 'Q19 is DONE / VERIFIED'), 'quality record advances beyond Q16 and closes the numbered platform');
q16_assert(
    q16_has($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'),
    'project status records the closed Quality Platform Q1-Q19'
);
q16_assert(
    q16_has($status, '| Final pre-clone runtime/QA closure | **DONE / VERIFIED — PR #75** |'),
    'project status advances beyond historical Q16 into the certified pre-clone runtime closure'
);
q16_assert(
    q16_has($handoff, '- Quality Platform Q1-Q19 — **DONE / VERIFIED; permanently closed at Q19**'),
    'handoff records the closed Quality Platform'
);
q16_assert(
    q16_has($handoff, '- Enterprise Tasks 1-8 — **DONE / VERIFIED**'),
    'handoff records the historical enterprise task closure'
);
q16_assert(
    q16_has($readme, 'docs/project/PROJECT-STATUS.md'),
    'README delegates current engineering state to project status'
);
q16_assert(q16_has($playbook, 'Quality Platform Q16: DONE / VERIFIED; PR #42; merge 06a9ebd732c7cc3f062d4bb361aaef4054a1dfa3; tree b9cc6eafb3c7f8df36b9c5db8b2e45bb330688d2; Q16 120/0; post-merge Quality Gates #316 SUCCESS; main security #84 SUCCESS'), 'playbook restart snapshot records Q16 closure');
q16_assert(q16_has($playbook, 'Last verified implementation main SHA: 29ba16a1eabc00e25c3652ae838be9b9539b3a10'), 'playbook restart snapshot advances to Q19 closure main');
q16_assert(q16_has($playbook, 'Canonical implementation tree: 8230778e3313e4d201de48b1a5cf170c42f7178d'), 'playbook restart snapshot advances to Q19 closure tree');
q16_assert(q16_has($audit, '**Enterprise Compatibility Certification**') && q16_has($audit, 'every Q1-Q19 regression'), 'repository audit advances beyond Q16 into certification while preserving closed quality ownership');
q16_assert(q16_has($audit, 'every Q1-Q19 regression'), 'repository audit requires the complete closed Q1-Q19 regression platform');
q16_assert(q16_has($playbook, '- [x] Full Automated Quality Platform — **Q16 / DONE / VERIFIED** through PR #42 and post-merge Quality Gates #316.'), 'playbook preserves Q16 as completed');
q16_assert(q16_has($playbook, '7. Enterprise Compatibility Certification — **CURRENT**.'), 'playbook advances beyond Q16 into named certification');
q16_assert(!q16_has($audit, 'No Q18 is planned or authorized'), 'repository audit does not contradict the enterprise-risk extension policy');
q16_assert(q16_has($audit, 'new enterprise-critical evidence independently demonstrates another bounded risk'), 'repository audit preserves bounded enterprise-risk extension policy');

echo "\nQ16 Migration Core Analysis: " . $pass . " PASS / " . $fail . " FAIL\n";
exit($fail === 0 ? 0 : 1);
