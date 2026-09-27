<?php

$q1_pass = 0;
$q1_fail = 0;
$q1_root = dirname(__DIR__, 2);

function q1_assert($condition, $label) {
    global $q1_pass, $q1_fail;

    if ($condition) {
        ++$q1_pass;
        echo "PASS: {$label}\n";
        return;
    }

    ++$q1_fail;
    echo "FAIL: {$label}\n";
}

function q1_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q1_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q1_json($root, $relative) {
    $decoded = json_decode(q1_read($root, $relative), true);
    q1_assert(is_array($decoded), "valid JSON object: {$relative}");
    return is_array($decoded) ? $decoded : array();
}

function q1_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$composer = q1_json($q1_root, 'composer.json');
$lock = q1_json($q1_root, 'composer.lock');
$workflow = q1_read($q1_root, '.github/workflows/quality-gates.yml');
$phpunit = q1_read($q1_root, 'phpunit.xml.dist');
$phpstan = q1_read($q1_root, 'phpstan.neon.dist');
$phpcs = q1_read($q1_root, 'phpcs.xml.dist');
$distignore = q1_read($q1_root, '.distignore');
$gitignore = q1_read($q1_root, '.gitignore');
$quality_record = q1_read($q1_root, 'docs/project/QUALITY-PLATFORM.md');

q1_assert(isset($composer['name']) && $composer['name'] === 'simplix-innovations/supcheckout', 'Composer package identity is canonical');
q1_assert(isset($composer['type']) && $composer['type'] === 'wordpress-plugin', 'Composer package type is WordPress plugin');
q1_assert(isset($composer['license']) && $composer['license'] === 'MIT', 'Composer license matches repository');
q1_assert(isset($composer['require']) && $composer['require'] === array('php' => '>=7.4'), 'Composer has no production dependency beyond supported PHP runtime floor');
q1_assert(isset($composer['autoload']['psr-4']['Simplixi\\SUPCheckout\\']) && $composer['autoload']['psr-4']['Simplixi\\SUPCheckout\\'] === 'src/', 'Composer maps the canonical namespace to src');
q1_assert(isset($composer['autoload-dev']['psr-4']['Simplixi\\SUPCheckout\\Tests\\']) && $composer['autoload-dev']['psr-4']['Simplixi\\SUPCheckout\\Tests\\'] === 'tests/unit/', 'Composer maps only test namespace in autoload-dev');
q1_assert(isset($composer['config']['allow-plugins']) && $composer['config']['allow-plugins'] === false, 'Composer plugin execution is disabled');

$required_dev = array(
    'phpstan/phpstan' => '^2.2.13',
    'phpunit/phpunit' => '^13.3',
    'squizlabs/php_codesniffer' => '^3.13.6',
    'wp-coding-standards/wpcs' => '^3.4.1',
);
foreach ($required_dev as $package => $constraint) {
    q1_assert(isset($composer['require-dev'][$package]) && $composer['require-dev'][$package] === $constraint, "development dependency is constrained: {$package}");
}
q1_assert(isset($composer['require-dev']) && count($composer['require-dev']) === count($required_dev), 'Composer has no unreviewed direct development dependencies');

$required_scripts = array('test:unit', 'analyse', 'lint:phpcs', 'quality');
foreach ($required_scripts as $script) {
    q1_assert(isset($composer['scripts'][$script]), "Composer quality script exists: {$script}");
}

q1_assert(isset($lock['packages']) && $lock['packages'] === array(), 'lockfile contains no production packages');
q1_assert(isset($lock['packages-dev']) && is_array($lock['packages-dev']) && count($lock['packages-dev']) > 0, 'lockfile freezes development dependencies');
$locked_names = array();
$stable_versions = true;
foreach (isset($lock['packages-dev']) && is_array($lock['packages-dev']) ? $lock['packages-dev'] : array() as $package) {
    if (!isset($package['name'], $package['version'])) {
        $stable_versions = false;
        continue;
    }
    $locked_names[] = $package['name'];
    if (strpos($package['version'], 'dev-') === 0) {
        $stable_versions = false;
    }
}
foreach (array_keys($required_dev) as $package) {
    q1_assert(in_array($package, $locked_names, true), "direct development dependency is locked: {$package}");
}
q1_assert($stable_versions, 'lockfile contains no development branch versions');
q1_assert(isset($lock['content-hash']) && is_string($lock['content-hash']) && preg_match('/^[a-f0-9]{32}$/', $lock['content-hash']) === 1, 'lockfile has a Composer content hash');

q1_assert(q1_contains($phpunit, 'tests/unit'), 'PHPUnit scope is the pure unit-test tree');
q1_assert(q1_contains($phpunit, 'failOnRisky="true"'), 'PHPUnit fails risky tests');
q1_assert(q1_contains($phpunit, 'failOnWarning="true"'), 'PHPUnit fails warnings');
q1_assert(q1_contains($phpstan, 'level: 5'), 'PHPStan level is ratcheted explicitly');
q1_assert(q1_contains($phpstan, 'phpVersion: 70400'), 'PHPStan analyzes against the declared PHP floor');
q1_assert(q1_contains($phpstan, 'src/Payment/ProviderResult.php'), 'PHPStan owns provider result classification');
q1_assert(q1_contains($phpstan, 'src/Provider/EndpointResolver.php'), 'PHPStan owns provider endpoint resolution');
q1_assert(!q1_contains($phpstan, 'baseline'), 'PHPStan foundation has no suppression baseline');
q1_assert(q1_contains($phpcs, 'WordPress.Security.ValidatedSanitizedInput'), 'PHPCS enforces input validation on scoped modules');
q1_assert(q1_contains($phpcs, 'WordPress.Security.EscapeOutput'), 'PHPCS enforces output escaping on scoped modules');
q1_assert(q1_contains($phpcs, 'WordPress.DB.PreparedSQL'), 'PHPCS enforces prepared SQL on scoped modules');

q1_assert(q1_contains($workflow, 'quality-platform:'), 'Quality Gates has a dedicated quality-platform job');
q1_assert(q1_contains($workflow, 'php-syntax-compatibility:'), 'Quality Gates has a declared-PHP-floor syntax job');
q1_assert(q1_contains($workflow, 'composer:2.10.3'), 'CI pins the exact Composer tool version');
q1_assert(q1_contains($workflow, 'composer validate --strict'), 'CI validates Composer metadata strictly');
q1_assert(q1_contains($workflow, 'composer install --no-interaction --prefer-dist --no-progress'), 'CI installs the exact lockfile');
q1_assert(q1_contains($workflow, 'composer audit --locked --no-interaction'), 'CI audits the locked dependency graph');
q1_assert(q1_contains($workflow, 'composer quality'), 'CI runs unit, static-analysis and coding-standard gates');
q1_assert(q1_contains($workflow, "php: ['7.2', '8.2']"), 'CI syntax-checks the declared floor and regression runtime');
q1_assert(q1_contains($workflow, "git ls-files -z -- '*.php' ':(exclude)tests/**'"), 'declared-floor syntax scope matches distributed PHP and excludes development tests');
q1_assert(q1_contains($workflow, "needs:\n      - quality-platform\n      - php-syntax-compatibility"), 'required historical regression is blocked on both new quality jobs');
q1_assert(q1_contains($workflow, 'if: ${{ always() }}'), 'required H12 aggregator runs even when an upstream quality job fails');
q1_assert(q1_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'required H12 aggregator reads the quality-platform result');
q1_assert(q1_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'required H12 aggregator reads the syntax-matrix result');
q1_assert(q1_contains($workflow, 'Required quality prerequisite failed:'), 'required H12 aggregator explicitly rejects non-success prerequisites');
q1_assert(q1_contains($workflow, 'quality-platform-foundation-harness.php'), 'quality foundation harness is mandatory in the historical regression job');

foreach (array('/vendor/', '/tests/', '/composer.json', '/composer.lock', '/phpcs.xml.dist', '/phpstan.neon.dist', '/phpunit.xml.dist') as $entry) {
    q1_assert(q1_contains($distignore, $entry), "distribution excludes development artifact: {$entry}");
}
q1_assert(q1_contains($gitignore, '/vendor/'), 'Composer vendor directory is ignored');
q1_assert(!q1_contains(q1_read($q1_root, 'UPayments.php'), 'vendor/autoload.php'), 'plugin runtime does not load Composer vendor code');
q1_assert(q1_contains($quality_record, 'Q1 is DONE / VERIFIED'), 'quality control record closes the Q1 foundation');
q1_assert(
    q1_contains($quality_record, '**Status:** Q17 / IMPLEMENTATION')
    || q1_contains($quality_record, 'Q17 is DONE / VERIFIED'),
    'quality control record preserves later-gate progression beyond Q1'
);
q1_assert(
    q1_contains($quality_record, 'WordPress/WooCommerce/PHP') &&
    q1_contains($quality_record, 'PCI/compliance') &&
    q1_contains($quality_record, 'production readiness'),
    'quality record rejects certification overclaim'
);

echo "\nQ1 Quality Platform Foundation: {$q1_pass} PASS / {$q1_fail} FAIL\n";
exit($q1_fail === 0 ? 0 : 1);
