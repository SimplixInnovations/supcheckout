<?php

$q10_pass = 0;
$q10_fail = 0;
$q10_root = dirname(__DIR__, 2);

function q10_assert($condition, $label) {
    global $q10_pass, $q10_fail;
    if ($condition) { ++$q10_pass; echo "PASS: {$label}\n"; return; }
    ++$q10_fail; echo "FAIL: {$label}\n";
}

function q10_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q10_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q10_contains($source, $needle) { return strpos($source, $needle) !== false; }

$phpstan = q10_read($q10_root, 'phpstan.neon.dist');
$phpcs = q10_read($q10_root, 'phpcs.xml.dist');
$source = q10_read($q10_root, 'src/Migration/MigrationBootstrap.php');
$tests = q10_read($q10_root, 'tests/unit/Migration/MigrationBootstrapTest.php');
$fixture = q10_read($q10_root, 'tests/support/wordpress-migration-bootstrap.php');
$stubs = q10_read($q10_root, 'tests/phpstan/migration-bootstrap-stubs.php');
$test_bootstrap = q10_read($q10_root, 'tests/bootstrap.php');
$workflow = q10_read($q10_root, '.github/workflows/quality-gates.yml');
$quality = q10_read($q10_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q10_read($q10_root, 'docs/project/PROJECT-STATUS.md');
$readme = q10_read($q10_root, 'README.md');
$handoff = q10_read($q10_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q10_read($q10_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q10_assert(q10_contains($phpstan, 'src/Migration/MigrationBootstrap.php'), 'PHPStan owns migration bootstrap');
q10_assert(q10_contains($phpcs, 'src/Migration/MigrationBootstrap.php'), 'PHPCS owns migration bootstrap');
q10_assert(q10_contains($phpstan, 'tests/phpstan/migration-bootstrap-stubs.php'), 'analysis loads bounded bootstrap stubs');
q10_assert(!q10_contains($phpstan, 'baseline'), 'Q10 remains baseline-free');
q10_assert(!q10_contains($phpstan, 'ignoreErrors'), 'Q10 introduces no ignored analyzer errors');

q10_assert(q10_contains($source, "defined('WP_CLI') && WP_CLI"), 'CLI context requires the exact WordPress CLI constant');
q10_assert(q10_contains($source, "function_exists('is_admin') && is_admin()"), 'admin context requires the guarded WordPress predicate');
q10_assert(q10_contains($source, 'self::bootForContext($is_cli, $is_admin)'), 'public boot delegates only resolved context booleans');
q10_assert(q10_contains($source, 'if (!$is_cli && !$is_admin)'), 'frontend context returns before operational loading');
$return_pos = strpos($source, 'if (!$is_cli && !$is_admin)');
$first_require_pos = strpos($source, "require_once __DIR__ . '/MigrationPreflight.php'");
q10_assert($return_pos !== false && $first_require_pos !== false && $return_pos < $first_require_pos, 'frontend guard precedes every dependency load');
foreach (array(
    'MigrationPreflight.php',
    'MigrationExecutor.php',
    'MigrationSettings.php',
    'MigrationBatch.php',
    'MigrationAdmin.php',
    'MigrationCliCommand.php',
) as $dependency) {
    q10_assert(substr_count($source, "require_once __DIR__ . '/{$dependency}'") === 1, "dependency is loaded exactly once: {$dependency}");
}
q10_assert(q10_contains($source, "add_action('admin_menu', array(MigrationAdmin::class, 'register'))"), 'admin context registers only the canonical menu callback');
q10_assert(q10_contains($source, "if (\$is_cli && class_exists('WP_CLI'))"), 'CLI registration requires the command class to exist');
q10_assert(q10_contains($source, "add_command('simplixpay-upayments migration', MigrationCliCommand::class)"), 'CLI context registers the canonical namespace');
foreach (array('supcheckout.php', 'wp_enqueue_scripts', 'woocommerce_checkout', 'wp_remote_', 'curl_') as $forbidden) {
    q10_assert(!q10_contains($source, $forbidden), "bootstrap excludes frontend/payment/provider path: {$forbidden}");
}

q10_assert(substr_count($tests, 'public function test_') >= 6, 'MigrationBootstrap has focused PHPUnit characterization');
foreach (array(
    'public_boot_is_inert_outside_admin_and_cli_contexts',
    'public_boot_registers_only_the_admin_menu_in_admin_context',
    'cli_context_registers_only_the_canonical_command',
    'combined_context_registers_each_operational_surface_once',
    'bootstrap_loads_only_bounded_operational_dependencies',
    'bootstrap_is_final_and_non_instantiable',
) as $name) {
    q10_assert(q10_contains($tests, $name), "migration-bootstrap test exists: {$name}");
}
q10_assert(q10_contains($tests, "array('admin_menu', array(MigrationAdmin::class, 'register'), 10, 1)"), 'admin registration is asserted exactly');
q10_assert(q10_contains($tests, "array('simplixpay-upayments migration', MigrationCliCommand::class)"), 'CLI registration is asserted exactly');
q10_assert(q10_contains($fixture, 'function is_admin()'), 'unit fixture provides deterministic admin context');
q10_assert(q10_contains($fixture, 'function add_action('), 'unit fixture records admin hooks');
q10_assert(q10_contains($fixture, 'final class WP_CLI'), 'unit fixture records CLI commands');
q10_assert(q10_contains($stubs, 'class WP_CLI'), 'analysis stubs declare the CLI boundary');
q10_assert(q10_contains($test_bootstrap, "wordpress-migration-bootstrap.php"), 'unit bootstrap loads migration context fixtures');

q10_assert(q10_contains($workflow, 'quality-platform-migration-bootstrap-harness.php'), 'Q10 harness is mandatory in Quality Gates');
q10_assert(q10_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '41b0d6d03af91b1e811562d609cf809345a221df',
    'eae2fe0d0f0f54bef793ed6e58c9837bd01403ab',
    'Quality Gates run #226',
    '02a1ad24d262c3cb6d14653bf48aa31c3796ae4e',
    'Quality Gates run #227',
    'implementation branch deleted',
) as $evidence) {
    q10_assert(q10_contains($quality, $evidence), "Q10 closure evidence is pinned: {$evidence}");
}
q10_assert(q10_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q10');
q10_assert(q10_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q10_assert(q10_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q10_assert(!q10_contains($playbook, 'Last verified implementation main SHA: 02a1ad24d262c3cb6d14653bf48aa31c3796ae4e'), 'playbook does not mistake verified Q10 merge for current main');
q10_assert(!q10_contains($playbook, 'Canonical implementation tree: eae2fe0d0f0f54bef793ed6e58c9837bd01403ab'), 'playbook does not mistake verified Q10 tree for current tree');
q10_assert(!q10_contains($handoff, 'CURRENT / Q10'), 'handoff rejects stale current-Q10 marker');
q10_assert(!q10_contains($playbook, 'CURRENT / Q10'), 'playbook rejects stale current-Q10 marker');
q10_assert(q10_contains($workflow, "reject_across_live_records 'CURRENT / Q10'"), 'Governance rejects stale current-Q10 markers');

echo "\nQ10 Migration Bootstrap Analysis: {$q10_pass} PASS / {$q10_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q10_fail === 0 ? 0 : 1);
