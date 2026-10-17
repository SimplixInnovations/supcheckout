<?php

$q9_pass = 0;
$q9_fail = 0;
$q9_root = dirname(__DIR__, 2);

function q9_assert($condition, $label) {
    global $q9_pass, $q9_fail;
    if ($condition) { ++$q9_pass; echo "PASS: {$label}\n"; return; }
    ++$q9_fail; echo "FAIL: {$label}\n";
}

function q9_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q9_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q9_contains($source, $needle) { return strpos($source, $needle) !== false; }

$phpstan = q9_read($q9_root, 'phpstan.neon.dist');
$phpcs = q9_read($q9_root, 'phpcs.xml.dist');
$source = q9_read($q9_root, 'src/Migration/MigrationSettings.php');
$tests = q9_read($q9_root, 'tests/unit/Migration/MigrationSettingsTest.php');
$fixture = q9_read($q9_root, 'tests/support/wordpress-option-store.php');
$stubs = q9_read($q9_root, 'tests/phpstan/wordpress-option-stubs.php');
$workflow = q9_read($q9_root, '.github/workflows/quality-gates.yml');
$quality = q9_read($q9_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q9_read($q9_root, 'docs/project/PROJECT-STATUS.md');
$readme = q9_read($q9_root, 'README.md');
$handoff = q9_read($q9_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q9_read($q9_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q9_assert(q9_contains($phpstan, 'src/Migration/MigrationSettings.php'), 'PHPStan owns migration settings');
q9_assert(q9_contains($phpcs, 'src/Migration/MigrationSettings.php'), 'PHPCS owns migration settings');
q9_assert(!q9_contains($phpstan, 'baseline'), 'Q9 remains baseline-free');
q9_assert(!q9_contains($phpstan, 'ignoreErrors'), 'Q9 introduces no ignored analyzer errors');

q9_assert(q9_contains($source, "OPTION_KEY = 'woocommerce_upayments_settings'"), 'resolver retains the historical Woo gateway option');
// 0b Task 2: Keys::get_settings(null) reads exactly OPTION_KEY with a null default before cutover.
q9_assert(q9_contains($source, 'Keys::get_settings(null)'), 'resolver reads only its exact option with a null default');
q9_assert(!q9_contains($source, 'update_option('), 'resolver never updates settings');
q9_assert(!q9_contains($source, 'add_option('), 'resolver never creates settings');
q9_assert(!q9_contains($source, 'delete_option('), 'resolver never deletes settings');
q9_assert(q9_contains($source, "!is_string(\$api_key) || \$api_key === '' || trim(\$api_key) === ''"), 'API key boundary rejects non-strings and blank values');
q9_assert(q9_contains($source, "array_key_exists('test_mode', \$settings) ? \$settings['test_mode'] : 'no'"), 'absent Woo checkbox defaults to live mode');
q9_assert(q9_contains($source, "\$test_mode !== 'yes' && \$test_mode !== 'no'"), 'mode parser accepts only exact Woo checkbox states');
q9_assert(q9_contains($source, "'api_key' => \$api_key"), 'successful internal result preserves the exact in-memory credential');
q9_assert(q9_contains($source, "'mode' => (\$test_mode === 'yes') ? 'test' : 'live'"), 'successful result derives the exact bounded mode');
q9_assert(q9_contains($source, 'public static function redact($resolved)'), 'redaction remains an explicit reporting boundary');
q9_assert(q9_contains($source, "in_array(\$reason, array('settings_missing', 'api_key_missing', 'test_mode_invalid'), true)"), 'redaction allowlists exact failure reasons');
q9_assert(q9_contains($source, "(\$mode === 'test' || \$mode === 'live')"), 'redaction allowlists exact reportable modes');
q9_assert(q9_contains($source, "array_key_exists('is_test_mode', \$resolved)"), 'redaction requires the canonical mode flag field');
q9_assert(q9_contains($source, "\$resolved['is_test_mode'] === (\$mode === 'test')"), 'redaction requires exact flag and mode correlation');
$redact_start = strpos($source, 'public static function redact');
$failure_start = strpos($source, 'private static function failure');
$redact_source = ($redact_start !== false && $failure_start !== false && $failure_start > $redact_start)
    ? substr($source, $redact_start, $failure_start - $redact_start)
    : '';
q9_assert($redact_source !== '' && !q9_contains($redact_source, "'api_key' =>"), 'redaction return path never includes the API key');

q9_assert(substr_count($tests, 'public function test_') >= 6, 'MigrationSettings has focused PHPUnit characterization');
foreach (array(
    'resolver_reads_only_the_historical_gateway_option',
    'missing_or_malformed_api_keys_fail_closed',
    'test_mode_accepts_only_exact_woocommerce_checkbox_states',
    'absent_no_and_yes_modes_resolve_exactly_without_mutation',
    'redaction_never_returns_the_api_key_or_unbounded_fields',
    'redaction_rejects_incomplete_or_inconsistent_success_shapes',
    'settings_boundary_is_final_and_non_instantiable',
) as $name) {
    q9_assert(q9_contains($tests, $name), "migration-settings test exists: {$name}");
}
q9_assert(q9_contains($tests, "'  exact-secret  '"), 'credential byte preservation is executable regression evidence');
q9_assert(q9_contains($tests, "'must-never-escape'"), 'redaction explicitly tests secret and extra-field exclusion');
q9_assert(q9_contains($tests, "'must-never-escape-' . str_repeat('x', 4096)"), 'redaction tests secret-bearing unbounded retained fields');
q9_assert(q9_contains($tests, 'assertStringNotContainsString($sentinel, serialize($redacted))'), 'redaction proves retained fields cannot emit the sentinel');
q9_assert(q9_contains($fixture, 'function get_option('), 'unit fixture provides deterministic option reads');
q9_assert(q9_contains($stubs, 'function get_option('), 'analysis stubs declare the bounded option read');

q9_assert(q9_contains($workflow, 'quality-platform-migration-settings-harness.php'), 'Q9 harness is mandatory in Quality Gates');
q9_assert(q9_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '01ca31ec3bf55f60dbec5f8293c73ab5bfbdc9a5',
    '96936981b8d3088a65c1d0917b7e5773952bc346',
    'Quality Gates run #223',
    'f63591188e232505f8307cb71fdbe4c32d2dc4c7',
    'Quality Gates run #224',
    'implementation branch deleted',
) as $evidence) {
    q9_assert(q9_contains($quality, $evidence), "Q9 closure evidence is pinned: {$evidence}");
}
q9_assert(q9_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q9');
q9_assert(q9_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q9_assert(q9_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q9_assert(!q9_contains($playbook, 'Last verified implementation main SHA: f63591188e232505f8307cb71fdbe4c32d2dc4c7'), 'playbook does not mistake verified Q9 merge for current main');
q9_assert(!q9_contains($playbook, 'Canonical implementation tree: 96936981b8d3088a65c1d0917b7e5773952bc346'), 'playbook does not mistake verified Q9 tree for current tree');
q9_assert(!q9_contains($handoff, 'CURRENT / Q9'), 'handoff rejects stale current-Q9 marker');
q9_assert(!q9_contains($playbook, 'CURRENT / Q9'), 'playbook rejects stale current-Q9 marker');
q9_assert(q9_contains($workflow, "reject_across_live_records 'CURRENT / Q9'"), 'Governance rejects stale current-Q9 markers');

echo "\nQ9 Migration Settings Analysis: {$q9_pass} PASS / {$q9_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q9_fail === 0 ? 0 : 1);
