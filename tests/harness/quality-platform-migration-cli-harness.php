<?php

$q13_pass = 0;
$q13_fail = 0;
$q13_root = dirname(__DIR__, 2);

function q13_assert($condition, $label) {
    global $q13_pass, $q13_fail;
    if ($condition) { ++$q13_pass; echo "PASS: {$label}\n"; return; }
    ++$q13_fail; echo "FAIL: {$label}\n";
}

function q13_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q13_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q13_contains($source, $needle) { return strpos($source, $needle) !== false; }

function q13_git_blob_sha($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q13_read($q13_root, 'phpstan.neon.dist');
$phpcs = q13_read($q13_root, 'phpcs.xml.dist');
$source = q13_read($q13_root, 'src/Migration/MigrationCliCommand.php');
$tests = q13_read($q13_root, 'tests/unit/Migration/MigrationCliCommandTest.php');
$fixture = q13_read($q13_root, 'tests/support/wordpress-migration-bootstrap.php');
$stubs = q13_read($q13_root, 'tests/phpstan/migration-bootstrap-stubs.php');
$workflow = q13_read($q13_root, '.github/workflows/quality-gates.yml');
$quality = q13_read($q13_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q13_read($q13_root, 'docs/project/PROJECT-STATUS.md');
$readme = q13_read($q13_root, 'README.md');
$handoff = q13_read($q13_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q13_read($q13_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');
$q11_harness = q13_read($q13_root, 'tests/harness/quality-platform-subscription-composition-harness.php');
$q12_harness = q13_read($q13_root, 'tests/harness/quality-platform-subscription-product-type-harness.php');

q13_assert(q13_contains($phpstan, 'src/Migration/MigrationCliCommand.php'), 'PHPStan owns migration CLI adapter');
q13_assert(q13_contains($phpcs, 'src/Migration/MigrationCliCommand.php'), 'PHPCS owns migration CLI adapter');
q13_assert(!q13_contains($phpstan, 'baseline'), 'Q13 remains baseline-free');
q13_assert(!q13_contains($phpstan, 'ignoreErrors'), 'Q13 introduces no ignored analyzer errors');

q13_assert(q13_contains($source, "if (!is_array(\$assoc_args) || !array_key_exists('yes', \$assoc_args))"), 'execute keeps explicit confirmation before parsing');
q13_assert(q13_contains($source, "array_key_exists('resume', \$assoc_args)"), 'resume flag remains explicit');
q13_assert(q13_contains($source, "if (\$resume && array_key_exists('offset', \$assoc_args))"), 'resume and offset remain mutually exclusive');
q13_assert(q13_contains($source, "return array('ok' => false, 'reason' => \$resume['reason']);"), 'resume failure preserves the exact batch-contract reason');
q13_assert(!q13_contains($source, "'resume_unavailable'"), 'analyzer-proven unreachable resume fallback is removed');
q13_assert(q13_contains($source, "preg_match('/^(?:0|[1-9][0-9]*)\\z/', \$value) === 1"), 'integer text parser uses an absolute canonical-decimal end anchor');
q13_assert(q13_contains($source, 'MigrationBatch::DEFAULT_LIMIT'), 'default batch limit remains centralized');
q13_assert(q13_contains($source, 'MigrationBatch::MAX_LIMIT'), 'maximum batch limit remains centralized');
q13_assert(q13_contains($source, 'MigrationSettings::resolve()'), 'credentials remain sourced only from existing settings');
q13_assert(q13_contains($source, 'MigrationSettings::redact($settings)'), 'CLI output keeps settings redaction');
q13_assert(q13_contains($source, "self::cliError('output_encode_failed')"), 'encoding failure remains fail closed');
q13_assert(q13_contains($source, "'SUPCheckout for UPayments migration: ' . \$reason"), 'CLI error prefix remains exact');
q13_assert(!q13_contains($source, "['api-key']"), 'CLI adapter exposes no API-key input');
q13_assert(!q13_contains($source, "array_key_exists('api-key'"), 'CLI adapter never parses API-key input');
foreach (array('wp_remote_', 'curl_', 'CURLOPT_', 'update_user_meta', 'process_payment', 'Scheduler', 'CycleClaim') as $forbidden) {
    q13_assert(!q13_contains($source, $forbidden), "CLI adapter excludes unrelated ownership: {$forbidden}");
}

foreach (array(
    'preflight_rejects_invalid_requests_with_exact_cli_error',
    'execute_requires_explicit_yes_before_request_parsing',
    'valid_request_parsing_preserves_exact_defaults_and_bounds',
    'strict_integer_parser_rejects_noncanonical_and_overflow_values',
    'emit_outputs_redacted_json_without_credentials',
    'cli_boundary_is_final_with_only_two_public_commands',
) as $name) {
    q13_assert(q13_contains($tests, $name), "migration CLI test exists: {$name}");
}
q13_assert(q13_contains($tests, "'resume with offset'"), 'invalid-request matrix covers resume/offset conflict');
q13_assert(q13_contains($tests, "'terminal-newline offset'"), 'invalid-request matrix rejects a terminal-newline offset');
q13_assert(q13_contains($tests, "'terminal-newline limit'"), 'invalid-request matrix rejects a terminal-newline limit');
q13_assert(q13_contains($tests, "'secret-api-key'"), 'redaction test uses a detectable secret fixture');
q13_assert(q13_contains($fixture, 'public static $lines'), 'CLI fixture records output lines');
q13_assert(q13_contains($fixture, 'public static $errors'), 'CLI fixture records errors');
q13_assert(q13_contains($stubs, 'static function line('), 'analysis stub declares CLI output boundary');
q13_assert(q13_contains($stubs, 'static function error('), 'analysis stub declares CLI error boundary');

q13_assert(q13_git_blob_sha($q13_root . '/includes/Subscription/Cron/Scheduler.php') === '67b2597404fafee241ebfb9996e4da08ee3e11d9', 'protected Scheduler blob remains exact');
q13_assert(q13_git_blob_sha($q13_root . '/includes/Subscription/Cron/CycleClaim.php') === 'a1ae04e3239e05c4894781480333fd5e747cefba', 'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening');

q13_assert(q13_contains($workflow, 'quality-platform-migration-cli-harness.php'), 'Q13 harness is mandatory in Quality Gates');
q13_assert(q13_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '302dcdf9c1bbd3a1d259790e8f9f9c2d694b74d7',
    'be7c52143d2085550790b742d164ecbec413377f',
    'Quality Gates run #236',
    '105 tests / 766 assertions',
    'Q13 **77/0**',
    'a744417e1ec2f40b4f59706df84589d8b18638cb',
    'Quality Gates run #237',
    'implementation branch deleted',
) as $evidence) {
    q13_assert(q13_contains($quality, $evidence), "Q13 closure evidence is pinned: {$evidence}");
}
q13_assert(q13_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q13');
q13_assert(q13_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q13_assert(q13_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q13_assert(q13_contains($playbook, 'Quality Platform Q13: DONE / VERIFIED; PR #39; merge a744417e1ec2f40b4f59706df84589d8b18638cb;'), 'playbook pins Q13 merge');
q13_assert(q13_contains($playbook, 'tree be7c52143d2085550790b742d164ecbec413377f; Q13 77/0; post-merge Quality Gates #237 SUCCESS'), 'playbook pins Q13 tree');
q13_assert(q13_contains($playbook, 'Quality Platform Q12: DONE / VERIFIED; PR #38; merge 6dc53bdaf60f12774d7516294d7004974be3874f;'), 'playbook pins Q12 merge');
q13_assert(q13_contains($playbook, 'tree b8a9f956e304fa9dba7658809207ddae14b1f4e1; Q12 63/0; post-merge Quality Gates #232 SUCCESS'), 'playbook pins Q12 tree');
q13_assert(q13_contains($q11_harness, 'Quality Platform Q11: DONE / VERIFIED; PR #37; merge e544a65130d4b009efea179038dd03275cd46897;'), 'Q11 harness uses its immutable closure row');
q13_assert(!q13_contains($q11_harness, 'Last verified implementation main SHA: e544a65130d4b009efea179038dd03275cd46897'), 'Q11 harness does not mistake a historical merge for current main');
q13_assert(q13_contains($q11_harness, 'tree f27880f5f2a93f1dfd6428619e5bffa75e0bd4aa; Q11 84/0; post-merge Quality Gates #230 SUCCESS'), 'Q11 harness pins its immutable closure tree');
q13_assert(q13_contains($q12_harness, 'Quality Platform Q12: DONE / VERIFIED; PR #38; merge 6dc53bdaf60f12774d7516294d7004974be3874f;'), 'Q12 harness uses its immutable closure row');
q13_assert(!q13_contains($q12_harness, 'Last verified implementation main SHA: 6dc53bdaf60f12774d7516294d7004974be3874f'), 'Q12 harness does not mistake a historical merge for current main');
q13_assert(q13_contains($q12_harness, 'tree b8a9f956e304fa9dba7658809207ddae14b1f4e1; Q12 63/0; post-merge Quality Gates #232 SUCCESS'), 'Q12 harness pins its immutable closure tree');
q13_assert(!q13_contains($handoff, 'CURRENT / Q13'), 'handoff rejects stale current-Q13 marker');
q13_assert(!q13_contains($playbook, 'CURRENT / Q13'), 'playbook rejects stale current-Q13 marker');
q13_assert(q13_contains($workflow, "reject_across_live_records 'CURRENT / Q13'"), 'Governance rejects stale current-Q13 markers');

echo "\nQ13 Migration CLI Analysis: {$q13_pass} PASS / {$q13_fail} FAIL\n";
exit($q13_fail === 0 ? 0 : 1);
