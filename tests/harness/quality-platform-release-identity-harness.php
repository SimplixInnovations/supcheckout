<?php

$q8_pass = 0;
$q8_fail = 0;
$q8_root = dirname(__DIR__, 2);

function q8_assert($condition, $label) {
    global $q8_pass, $q8_fail;
    if ($condition) { ++$q8_pass; echo "PASS: {$label}\n"; return; }
    ++$q8_fail; echo "FAIL: {$label}\n";
}

function q8_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q8_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q8_contains($source, $needle) { return strpos($source, $needle) !== false; }

$phpstan = q8_read($q8_root, 'phpstan.neon.dist');
$phpcs = q8_read($q8_root, 'phpcs.xml.dist');
$source = q8_read($q8_root, 'src/Release/Identity.php');
$tests = q8_read($q8_root, 'tests/unit/Release/IdentityTest.php');
$workflow = q8_read($q8_root, '.github/workflows/quality-gates.yml');
$quality = q8_read($q8_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q8_read($q8_root, 'docs/project/PROJECT-STATUS.md');
$readme = q8_read($q8_root, 'README.md');
$handoff = q8_read($q8_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q8_read($q8_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q8_assert(q8_contains($phpstan, 'src/Release/Identity.php'), 'PHPStan owns release identity');
q8_assert(q8_contains($phpcs, 'src/Release/Identity.php'), 'PHPCS owns release identity');
q8_assert(!q8_contains($phpstan, 'baseline'), 'Q8 remains baseline-free');
q8_assert(!q8_contains($phpstan, 'ignoreErrors'), 'Q8 introduces no ignored analyzer errors');

foreach (array(
    "PRODUCT_NAME = 'SUPCheckout for UPayments'",
    "SHORT_NAME = 'SUPCheckout'",
    "VERSION = '0.1.0'",
    "SLUG = 'supcheckout'",
    "REPOSITORY = 'SimplixInnovations/supcheckout'",
    "UPDATE_CHANNEL = 'disabled'",
    "LEGACY_MAIN_FILE = 'UPayments.php'",
    "LEGACY_TEXT_DOMAIN = 'upayments'",
    "TARGET_MAIN_FILE = 'supcheckout.php'",
    "TEXT_DOMAIN = 'supcheckout'",
    "NAMESPACE_ROOT = 'Simplixi\\\\SUPCheckout'",
    "TARGET_TEXT_DOMAIN = 'supcheckout'",
) as $identity) {
    q8_assert(q8_contains($source, $identity), "release identity remains exact: {$identity}");
}
q8_assert(q8_contains($source, "function_exists('add_action')"), 'isolated identity loading retains conditional runtime bootstrap');
q8_assert(q8_contains($source, 'PaymentLifecycle::bootstrap()'), 'WordPress runtime still reaches the payment lifecycle bootstrap');
q8_assert(substr_count($tests, 'public function test_') >= 6, 'release identity has focused PHPUnit characterization');
q8_assert(q8_contains($tests, 'test_historical_install_identities_remain_exact'), 'historical install identities are unit protected');
q8_assert(q8_contains($tests, 'test_future_targets_are_frozen_but_not_current_identity'), 'future targets remain distinct from current identities');
q8_assert(q8_contains($tests, 'test_external_update_channel_is_explicitly_disabled'), 'disabled updater is unit protected');

q8_assert(q8_contains($workflow, 'quality-platform-release-identity-harness.php'), 'Q8 harness is mandatory in Quality Gates');
q8_assert(q8_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '458bf35b0cc60d78dc8f32d28605d1f60cbc501c',
    '109415fa6a4bc04bba60bb23275bc192dd232559',
    'Quality Gates run #218',
    'b59eb2d50b86a38d8ea130de63c38a672db86d32',
    'Quality Gates run #219',
    'implementation branch deleted',
) as $evidence) {
    q8_assert(q8_contains($quality, $evidence), "Q8 closure evidence is pinned: {$evidence}");
}
q8_assert(q8_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q8');
q8_assert(q8_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q8_assert(q8_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q8_assert(!q8_contains($playbook, 'Last verified implementation main SHA: b59eb2d50b86a38d8ea130de63c38a672db86d32'), 'playbook does not mistake verified Q8 merge for current main');
q8_assert(!q8_contains($playbook, 'Canonical implementation tree: 109415fa6a4bc04bba60bb23275bc192dd232559'), 'playbook does not mistake verified Q8 tree for current tree');
q8_assert(!q8_contains($handoff, 'CURRENT / Q8'), 'handoff rejects stale current-Q8 marker');
q8_assert(!q8_contains($playbook, 'CURRENT / Q8'), 'playbook rejects stale current-Q8 marker');
q8_assert(q8_contains($workflow, "reject_across_live_records 'CURRENT / Q8'"), 'Governance rejects stale current-Q8 markers');

echo "\nQ8 Release Identity Analysis: {$q8_pass} PASS / {$q8_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q8_fail === 0 ? 0 : 1);
