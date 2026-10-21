<?php
/**
 * Permanent WordPress.org submission-readiness contract for SUPCheckout.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function wporg_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$label}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$label}\n";
}

function wporg_read($root, $relative) {
    $value = @file_get_contents($root . '/' . $relative);
    wporg_assert(is_string($value) && $value !== '', 'submission control file exists: ' . $relative);
    return is_string($value) ? $value : '';
}

$readme = wporg_read($root, 'readme.txt');
$plugin = wporg_read($root, 'supcheckout.php');
$identity = wporg_read($root, 'src/Release/Identity.php');
$build = wporg_read($root, 'scripts/build-release.sh');
$verify = wporg_read($root, 'scripts/verify-release.sh');
$distignore = wporg_read($root, '.distignore');
$release_workflow = wporg_read($root, '.github/workflows/release-artifact.yml');
$submission_workflow = wporg_read($root, '.github/workflows/wordpress-org-submission-check.yml');

$version = '';
if (preg_match("/public const VERSION = '([^']+)';/", $identity, $matches) === 1) {
    $version = $matches[1];
}
wporg_assert($version !== '', 'submission harness reads canonical release version');

wporg_assert(strpos($readme, '=== SUPCheckout for UPayments ===') !== false, 'WordPress.org readme uses canonical human-facing product name');
wporg_assert(strlen($readme) > 0 && strlen($readme) < 10000, 'WordPress.org readme remains below 10 KB directory guidance');
wporg_assert($version !== '' && strpos($readme, 'Stable tag: ' . $version) !== false, 'readme Stable Tag matches canonical release version');
wporg_assert(strpos($readme, 'Requires at least: 6.9') !== false, 'readme preserves verified WordPress floor');
wporg_assert(strpos($readme, 'Tested up to: 7.1') !== false, 'readme preserves verified WordPress ceiling');
wporg_assert(strpos($readme, 'Requires PHP: 7.4') !== false, 'readme preserves verified PHP floor');
wporg_assert(strpos($readme, 'License: MIT') !== false, 'readme declares repository license consistently');
wporg_assert(strpos($readme, 'https://upayments.com/en/terms-of-service') !== false, 'readme documents external service terms');
wporg_assert(strpos($readme, 'developed and maintained by Simplix Innovations') !== false, 'readme identifies the plugin maintainer');
wporg_assert(
    strpos($readme, 'UPayments is the external payment provider') !== false
    && strpos($readme, 'names and trademarks remain the property of their respective owners') !== false,
    'readme clearly separates plugin maintenance from provider ownership'
);
wporg_assert(strpos($readme, 'Stable tag: trunk') === false, 'new-plugin readme never uses trunk as stable tag');
wporg_assert(
    strpos($readme, '== Source code and build ==') !== false
        && strpos($readme, 'https://github.com/SimplixInnovations/supcheckout') !== false
        && strpos($readme, 'npm ci --ignore-scripts && npm run build') !== false,
    'readme links the human-readable source and documents the admin build (guideline 4)'
);
$nvmrc = trim(wporg_read($root, '.nvmrc'));
$npmrc = wporg_read($root, '.npmrc');
$package_json = json_decode(wporg_read($root, 'package.json'), true);
$pinned_npm = is_array($package_json) && isset($package_json['engines']['npm']) && is_string($package_json['engines']['npm'])
    ? $package_json['engines']['npm']
    : '';
wporg_assert(
    $nvmrc !== ''
        && $pinned_npm !== ''
        && preg_match('/^engine-strict=true$/m', $npmrc) === 1
        && strpos($readme, 'Node.js ' . $nvmrc . ' and npm ' . $pinned_npm) !== false
        && strpos($readme, '`engine-strict`') !== false,
    'readme states the pinned Node.js/npm toolchain (.nvmrc, package.json engines) enforced by engine-strict'
);
wporg_assert(stripos($readme, 'guaranteed PCI') === false && stripos($readme, 'PCI compliant plugin') === false, 'readme makes no plugin-level PCI compliance guarantee');

wporg_assert(strpos($plugin, 'Plugin Name: SUPCheckout for UPayments') !== false, 'plugin header uses canonical product name');
wporg_assert(strpos($distignore, '/NOTICE.md') !== false, 'WordPress.org package excludes source-only NOTICE markdown');
wporg_assert(strpos($distignore, '/assets/screenshots/') !== false, 'installable package excludes WordPress.org screenshot source material');
wporg_assert(strpos($plugin, 'Text Domain: supcheckout') !== false, 'plugin header uses canonical WordPress.org text domain');
wporg_assert(strpos($build, 'SLUG="supcheckout"') !== false, 'release builder emits canonical WordPress.org slug');
wporg_assert(strpos($verify, 'slug = "supcheckout"') !== false, 'release verifier requires canonical WordPress.org slug');
wporg_assert(strpos($release_workflow, "-name 'supcheckout-*.zip'") !== false, 'release workflow produces canonical submission ZIP');

wporg_assert(
    strpos($submission_workflow, 'WordPress/plugin-check-action@10857da14b6c2246d15402b3e69f777edcf8c12e') !== false
        && strpos($submission_workflow, 'build-dir: ${{ runner.temp }}/plugin-check/supcheckout') !== false
        && strpos($submission_workflow, 'slug: supcheckout') !== false
        && strpos($submission_workflow, 'categories: plugin_repo') !== false
        && strpos($submission_workflow, 'strict: true') !== false,
    'official Plugin Check runs on unpacked canonical package with immutable action pin'
);
$plugin_check_at = strpos($submission_workflow, 'WordPress/plugin-check-action@');
$cooldown_at = strpos($submission_workflow, 'npm_config_before=');
wporg_assert(
    false !== $cooldown_at
        && false !== $plugin_check_at
        && $cooldown_at < $plugin_check_at
        && strpos($submission_workflow, "date -u -d '14 days ago'") !== false,
    'Plugin Check installs its unpinned wp-env toolchain under the 14-day npm dependency cooldown'
);
wporg_assert(
    strpos($submission_workflow, 'bash scripts/build-release.sh submission-output') !== false
        && strpos($submission_workflow, 'bash scripts/verify-release.sh "$ZIP"') !== false,
    'submission gate consumes deterministic verified release artifact'
);
wporg_assert(
    strpos($submission_workflow, "simplixpay-upayments-*.zip") === false
        && strpos($submission_workflow, "sucheckout-upayments-*.zip") === false,
    'submission workflow contains no retired release ZIP slug'
);

echo "\nWordPress.org Submission Readiness: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
