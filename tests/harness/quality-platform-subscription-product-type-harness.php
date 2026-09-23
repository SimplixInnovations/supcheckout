<?php

$q12_pass = 0;
$q12_fail = 0;
$q12_root = dirname(__DIR__, 2);

function q12_assert($condition, $label) {
    global $q12_pass, $q12_fail;
    if ($condition) { ++$q12_pass; echo "PASS: {$label}\n"; return; }
    ++$q12_fail; echo "FAIL: {$label}\n";
}

function q12_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q12_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q12_contains($source, $needle) { return strpos($source, $needle) !== false; }

function q12_git_blob_sha($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q12_read($q12_root, 'phpstan.neon.dist');
$phpcs = q12_read($q12_root, 'phpcs.xml.dist');
$source = q12_read($q12_root, 'src/Subscription/WCProductCustomType.php');
$tests = q12_read($q12_root, 'tests/unit/Subscription/WCProductCustomTypeTest.php');
$stubs = q12_read($q12_root, 'tests/phpstan/subscription-product-type-stubs.php');
$base_fixture = q12_read($q12_root, 'tests/fixtures/subscription-product-type-base.php');
$existing_fixture = q12_read($q12_root, 'tests/fixtures/subscription-product-type-existing.php');
$workflow = q12_read($q12_root, '.github/workflows/quality-gates.yml');
$quality = q12_read($q12_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q12_read($q12_root, 'docs/project/PROJECT-STATUS.md');
$readme = q12_read($q12_root, 'README.md');
$handoff = q12_read($q12_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q12_read($q12_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q12_assert(q12_contains($phpstan, 'src/Subscription/WCProductCustomType.php'), 'PHPStan owns subscription product type');
q12_assert(q12_contains($phpcs, 'src/Subscription/WCProductCustomType.php'), 'PHPCS owns subscription product type');
q12_assert(q12_contains($phpstan, 'tests/phpstan/subscription-product-type-stubs.php'), 'analysis loads bounded product-type stubs');
q12_assert(!q12_contains($phpstan, 'baseline'), 'Q12 remains baseline-free');
q12_assert(!q12_contains($phpstan, 'ignoreErrors'), 'Q12 introduces no ignored analyzer errors');

q12_assert(substr_count($source, "!class_exists('WCProductCustomType')") === 1, 'pre-existing global class guard remains exact');
q12_assert(substr_count($source, "class_exists('WC_Product_Simple')") === 1, 'WooCommerce base-class guard remains exact');
q12_assert(substr_count($source, 'class WCProductCustomType extends WC_Product_Simple') === 1, 'global compatibility class and parent remain exact');
q12_assert(substr_count($source, "return 'custom_type';") === 1, 'custom product type identity remains exact');
q12_assert(substr_count($source, 'function get_type()') === 1, 'product-type override remains singular');
foreach (array(
    'namespace ',
    'add_action(',
    'add_filter(',
    'Scheduler',
    'CycleClaim',
    'process_payment',
    'update_meta_data',
    'wp_remote_',
    'CURLOPT_',
) as $forbidden) {
    q12_assert(!q12_contains($source, $forbidden), "product-type shim excludes unrelated ownership: {$forbidden}");
}

q12_assert(substr_count($tests, '#[RunInSeparateProcess]') === 3, 'all load-state tests use isolated processes');
q12_assert(substr_count($tests, '#[PreserveGlobalState(false)]') === 3, 'all load-state tests disable inherited globals');
foreach (array(
    'is_inert_when_woocommerce_simple_product_base_is_absent',
    'declares_exact_global_child_and_product_type_when_base_exists',
    'preserves_a_preexisting_global_compatibility_class',
) as $name) {
    q12_assert(q12_contains($tests, $name), "subscription product-type test exists: {$name}");
}
q12_assert(q12_contains($tests, "fixtures/subscription-product-type-base.php"), 'base-present test loads a true global parent fixture');
q12_assert(q12_contains($tests, "fixtures/subscription-product-type-existing.php"), 'pre-existing-class test loads a true global compatibility fixture');
q12_assert(q12_contains($tests, "assertSame('custom_type'"), 'exact custom product type is asserted');
q12_assert(substr_count($base_fixture, 'class WC_Product_Simple') === 1, 'base fixture declares the exact global WooCommerce parent');
q12_assert(substr_count($existing_fixture, 'class WCProductCustomType') === 1, 'pre-existing fixture declares the exact global compatibility class');
q12_assert(q12_contains($existing_fixture, "return 'existing_type';"), 'pre-existing fixture exposes a distinguishable preserved behavior');
q12_assert(q12_contains($stubs, 'class WC_Product_Simple'), 'analysis stubs declare only the WooCommerce parent boundary');
q12_assert(!q12_contains($stubs, 'WCProductCustomType'), 'analysis stubs do not mask the production child class');

q12_assert(q12_git_blob_sha($q12_root . '/includes/Subscription/Cron/Scheduler.php') === '565b1a689eab05a29346ccf952cd5d60873d0466', 'protected Scheduler blob remains exact');
q12_assert(q12_git_blob_sha($q12_root . '/includes/Subscription/Cron/CycleClaim.php') === '0ae25b176ce622f0500fb279a40ab60275a72705', 'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening');

q12_assert(q12_contains($workflow, 'quality-platform-subscription-product-type-harness.php'), 'Q12 harness is mandatory in Quality Gates');
q12_assert(q12_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '4396b83ef67a90d6d12d1d761e6c071e601c235c',
    'b8a9f956e304fa9dba7658809207ddae14b1f4e1',
    'Quality Gates run #231',
    '6dc53bdaf60f12774d7516294d7004974be3874f',
    'Quality Gates run #232',
    'implementation branch deleted',
) as $evidence) {
    q12_assert(q12_contains($quality, $evidence), "Q12 closure evidence is pinned: {$evidence}");
}
q12_assert(q12_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q12');
q12_assert(q12_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q12_assert(q12_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q12_assert(q12_contains($playbook, 'Quality Platform Q12: DONE / VERIFIED; PR #38; merge 6dc53bdaf60f12774d7516294d7004974be3874f;'), 'playbook pins Q12 merge');
q12_assert(q12_contains($playbook, 'tree b8a9f956e304fa9dba7658809207ddae14b1f4e1; Q12 63/0; post-merge Quality Gates #232 SUCCESS'), 'playbook pins Q12 tree');
q12_assert(!q12_contains($handoff, 'CURRENT / Q12'), 'handoff rejects stale current-Q12 marker');
q12_assert(!q12_contains($playbook, 'CURRENT / Q12'), 'playbook rejects stale current-Q12 marker');
q12_assert(q12_contains($workflow, "reject_across_live_records 'CURRENT / Q12'"), 'Governance rejects stale current-Q12 markers');

echo "\nQ12 Subscription Product Type Analysis: {$q12_pass} PASS / {$q12_fail} FAIL\n";
exit($q12_fail === 0 ? 0 : 1);
