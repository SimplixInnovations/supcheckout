<?php
/**
 * Deterministic canonical SUPCheckout release-artifact certification.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function release_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

function release_read($root, $relative) {
    $value = @file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
}

function release_rm_tree($path) {
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        release_rm_tree($path . '/' . $item);
    }
    @rmdir($path);
}

function release_run($command, &$output = null) {
    $lines = array();
    exec($command . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    return $code;
}

function release_rewrite_evidence_for_zip($zip_path, $checksum_path, $manifest_path) {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return false;
    }
    $names = array();
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = $zip->getNameIndex($i);
        if (is_string($name) && substr($name, -1) !== '/') {
            $names[] = $name;
        }
    }
    sort($names, SORT_STRING);
    $manifest = '';
    foreach ($names as $name) {
        $bytes = $zip->getFromName($name);
        if (!is_string($bytes)) {
            $zip->close();
            return false;
        }
        $manifest .= hash('sha256', $bytes) . '  ' . $name . "\n";
    }
    $zip->close();
    $zip_hash = hash_file('sha256', $zip_path);
    if (!is_string($zip_hash)) {
        return false;
    }
    return file_put_contents($manifest_path, $manifest) !== false
        && file_put_contents($checksum_path, $zip_hash . '  ' . basename($zip_path) . "\n") !== false;
}

$build = release_read($root, 'scripts/build-release.sh');
$verify = release_read($root, 'scripts/verify-release.sh');
$installer = release_read($root, 'scripts/install-wp-test-environment.sh');
$workflow = release_read($root, '.github/workflows/release-artifact.yml');
$distignore = release_read($root, '.distignore');
$identity = release_read($root, 'src/Release/Identity.php');

release_assert($build !== '', 'canonical release builder exists');
release_assert($verify !== '', 'canonical release verifier exists');
release_assert($installer !== '', 'real WordPress/WooCommerce installer exists');
release_assert($workflow !== '', 'release workflow exists');
release_assert(
    preg_match('/pull_request:\\n    branches: \\[main\\]\\n    paths:/', $workflow) !== 1,
    'required Release Gate workflow is created for every pull request'
);
release_assert($distignore !== '', 'distribution exclusion contract exists');
release_assert(strpos($distignore, '/assets/screenshots/') !== false, 'distribution excludes WordPress.org screenshot source material');

$version = '';
if (preg_match("/public const VERSION = '([^']+)';/", $identity, $matches) === 1) {
    $version = $matches[1];
}
release_assert($version !== '', 'release version is readable from canonical identity');
release_assert(strpos($identity, "public const LEGACY_MAIN_FILE = 'UPayments.php';") !== false, 'qualified UPayments.php bootstrap is retained');
release_assert(strpos($identity, "public const TARGET_MAIN_FILE = 'supcheckout.php';") !== false, 'unsafe physical main-file rename remains an explicit future target');

foreach (array('/.github/', '/tests/', '/vendor/', '/composer.json', '/composer.lock', '/docs/', '/scripts/') as $excluded) {
    release_assert(strpos($distignore, $excluded) !== false, 'distribution excludes control/development path: ' . $excluded);
}

// Root-level governance/design control documents are repository evidence, never
// distributable plugin bytes. Both the exclusion contract and the verifier must
// reject them so a new control document cannot silently enter the package.
foreach (array('/DESIGN.md', '/UX-CONTRACT.md', '/premium-ui.json') as $excluded) {
    release_assert(strpos($distignore, $excluded) !== false, 'distribution excludes governance/design control document: ' . $excluded);
}

foreach (array('"DESIGN.md"', '"UX-CONTRACT.md"', '"premium-ui.json"') as $forbidden) {
    release_assert(strpos($verify, $forbidden) !== false, 'verifier forbids governance/design control document: ' . $forbidden);
}

release_assert(substr_count($build, 'supcheckout') >= 2, 'builder owns canonical SUPCheckout ZIP/root slug');
release_assert(strpos($build, 'slug = "simplixpay-upayments"') === false, 'builder contains no retired package-root slug');
release_assert(substr_count($verify, 'supcheckout') >= 2, 'verifier owns canonical SUPCheckout ZIP/root slug');
release_assert(strpos($verify, 'slug = "simplixpay-upayments"') === false, 'verifier contains no retired package-root slug');
release_assert(
    strpos($build, '"ls-tree", "-r", "-z", "HEAD"') !== false
        && strpos($build, 'HEAD:.distignore') !== false
        && strpos($build, 'cat-file", "blob"') !== false,
    'builder derives distribution paths and bytes from Git HEAD'
);
release_assert(
    strpos($verify, 'ZIP bytes do not match Git HEAD source') !== false
        && strpos($verify, 'HEAD:.distignore') !== false,
    'verifier binds packaged bytes to Git HEAD'
);
release_assert(
    strpos($build, 'compression=zipfile.ZIP_STORED') !== false
        && strpos($build, 'info.compress_type = zipfile.ZIP_STORED') !== false
        && strpos($build, 'ZIP_DEFLATED') === false,
    'builder uses cross-platform deterministic stored ZIP entries'
);
release_assert(
    strpos($verify, 'info.compress_type != zipfile.ZIP_STORED') !== false
        && strpos($verify, 'Non-deterministic ZIP timestamp') !== false
        && strpos($verify, 'Non-deterministic ZIP creator system') !== false
        && strpos($verify, 'Non-deterministic ZIP mode') !== false,
    'verifier enforces deterministic ZIP container metadata'
);
release_assert(strpos($installer, 'SUPCHECKOUT_PLUGIN_SLUG:-supcheckout') !== false, 'real installer defaults to canonical SUPCheckout root');

release_assert(strpos($workflow, "-name 'supcheckout-*.zip'") !== false, 'release workflow selects canonical SUPCheckout artifacts');
release_assert(strpos($workflow, 'name: supcheckout-release-${{ env.RELEASE_SOURCE_SHA }}') !== false, 'release workflow evidence is keyed by exact candidate SHA');
release_assert(strpos($workflow, 'ref: ${{ env.RELEASE_SOURCE_SHA }}') !== false, 'release workflow checks out exact candidate source SHA');
release_assert(strpos($workflow, 'storage: [legacy, hpos]') !== false, 'packaged runtime covers legacy and HPOS storage');
release_assert(strpos($workflow, 'plugin activate supcheckout') !== false, 'packaged runtime activates canonical plugin slug');
release_assert(strpos($workflow, "previous_slug: 'simplixpay-upayments'") !== false, 'migration matrix retains historical SimplixPay package root');
release_assert(strpos($workflow, "previous_slug: 'sucheckout-upayments'") !== false, 'migration matrix certifies transitional SUCheckout package root');
release_assert(strpos($workflow, "previous_sha: '54b1fbcc280b92372bd93baf929d6a746cfd3959'") !== false, 'historical SimplixPay migration source is immutable');
release_assert(strpos($workflow, "previous_sha: 'e9f953b0c3a881ad2d4b74390d0b5394b766a3fa'") !== false, 'transitional SUCheckout migration source is immutable');
release_assert(strpos($workflow, 'SUPCHECKOUT_PLUGIN_SLUG="$SUPCHECKOUT_LEGACY_SLUG"') !== false, 'migration job installs each matrix-selected pre-stable root');
release_assert(strpos($workflow, 'plugin deactivate "$SUPCHECKOUT_LEGACY_SLUG"') !== false, 'migration job explicitly deactivates each pre-stable root');
release_assert(strpos($workflow, 'plugin activate supcheckout') !== false, 'migration job explicitly activates canonical root');
release_assert(strpos($workflow, 'SUPCHECKOUT_UPGRADE_PHASE=verify-legacy-rollback') !== false, 'migration job proves legacy rollback is non-destructive');
release_assert(strpos($workflow, 'plugin delete "$SUPCHECKOUT_LEGACY_SLUG"') !== false, 'migration job ends with selected pre-stable package removed');
release_assert(strpos($workflow, 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a') !== false, 'artifact upload action is immutably pinned');
release_assert(strpos($workflow, 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c') !== false, 'artifact download action is immutably pinned');
release_assert(strpos($workflow, 'os: [ubuntu-latest, windows-latest]') !== false, 'release workflow certifies Linux and Windows artifact construction');
release_assert(strpos($workflow, 'name: Cross-platform deterministic release') !== false, 'release workflow compares cross-platform evidence');
release_assert(strpos($workflow, 'cmp "$CANONICAL_SIDECAR" linux/Linux.zip.sha256') !== false, 'release workflow binds Linux ZIP hash to canonical artifact');
release_assert(strpos($workflow, 'cmp "$CANONICAL_SIDECAR" windows/Windows.zip.sha256') !== false, 'release workflow binds Windows ZIP hash to canonical artifact');
release_assert(strpos($workflow, 'cmp "$CANONICAL_MANIFEST" linux/Linux.manifest.sha256') !== false, 'release workflow binds Linux manifest to canonical artifact');
release_assert(strpos($workflow, 'cmp "$CANONICAL_MANIFEST" windows/Windows.manifest.sha256') !== false, 'release workflow binds Windows manifest to canonical artifact');
release_assert(strpos($workflow, 'CROSS_PLATFORM_RESULT:') !== false, 'Release Gate consumes cross-platform determinism result');

if ($version !== '') {
    $tmp = sys_get_temp_dir() . '/supcheckout-release-' . getmypid() . '-' . substr(hash('sha256', __FILE__), 0, 8);
    $first = $tmp . '/first';
    $second = $tmp . '/second';
    @mkdir($first, 0777, true);
    @mkdir($second, 0777, true);

    $build_command = 'cd ' . escapeshellarg($root) . ' && bash scripts/build-release.sh ';
    $first_output = '';
    $first_code = release_run($build_command . escapeshellarg($first), $first_output);
    release_assert($first_code === 0, 'first deterministic canonical build exits zero');

    $zip_name = 'supcheckout-' . $version . '.zip';
    $manifest_name = 'supcheckout-' . $version . '.manifest.sha256';
    $first_zip = $first . '/' . $zip_name;
    $first_checksum = $first_zip . '.sha256';
    $first_manifest = $first . '/' . $manifest_name;
    release_assert(is_file($first_zip), 'first build emits canonical SUPCheckout ZIP');
    release_assert(is_file($first_checksum), 'first build emits ZIP checksum');
    release_assert(is_file($first_manifest), 'first build emits per-file manifest');

    if (is_file($first_zip)) {
        $verify_output = '';
        $verify_code = release_run('cd ' . escapeshellarg($root) . ' && bash scripts/verify-release.sh ' . escapeshellarg($first_zip), $verify_output);
        release_assert($verify_code === 0, 'canonical verifier accepts exact built artifact');

        release_assert(class_exists('ZipArchive'), 'ZipArchive is available for independent inspection');
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($first_zip);
            release_assert($opened === true, 'independent inspector opens canonical ZIP');
            if ($opened === true) {
                $names = array();
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }
                $prefix = 'supcheckout/';
                $safe = count($names) > 0;
                foreach ($names as $name) {
                    if (strpos($name, $prefix) !== 0 || strpos($name, 'simplixpay-upayments/') === 0 || substr($name, -1) === '/') {
                        $safe = false;
                    }
                }
                release_assert($safe, 'independent inspector sees one canonical SUPCheckout package root');
                release_assert(in_array($prefix . 'UPayments.php', $names, true), 'canonical package retains qualified UPayments.php bootstrap');
                release_assert(in_array($prefix . 'readme.txt', $names, true), 'canonical package contains WordPress.org readme');
                release_assert(!in_array($prefix . 'composer.json', $names, true), 'canonical package excludes Composer controls');

                $contains_screenshots = false;
                foreach ($names as $name) {
                    if (strpos($name, $prefix . 'assets/screenshots/') === 0) {
                        $contains_screenshots = true;
                        break;
                    }
                }
                release_assert(!$contains_screenshots, 'canonical package excludes WordPress.org screenshot source material');

                $plugin = $zip->getFromName($prefix . 'UPayments.php');
                release_assert(
                    is_string($plugin)
                        && strpos($plugin, 'Plugin Name: SUPCheckout for UPayments') !== false
                        && strpos($plugin, 'Text Domain: supcheckout') !== false
                        && strpos($plugin, 'Version: ' . $version) !== false,
                    'independent inspector confirms packaged SUPCheckout metadata'
                );
                $zip->close();
            }
        }

        $tampered_dir = $tmp . '/tampered';
        @mkdir($tampered_dir, 0777, true);
        $tampered_zip = $tampered_dir . '/' . $zip_name;
        $tampered_checksum = $tampered_zip . '.sha256';
        $tampered_manifest = $tampered_dir . '/' . $manifest_name;
        $tampered_ready = copy($first_zip, $tampered_zip);
        if ($tampered_ready && class_exists('ZipArchive')) {
            $tampered = new ZipArchive();
            if ($tampered->open($tampered_zip) === true) {
                $target = 'supcheckout/assets/css/customer.css';
                $bytes = $tampered->getFromName($target);
                $tampered_ready = is_string($bytes)
                    && $tampered->addFromString($target, $bytes . "\n/* git-head-mismatch-probe */\n");
                $tampered->close();
            } else {
                $tampered_ready = false;
            }
        }
        $tampered_ready = $tampered_ready
            && release_rewrite_evidence_for_zip($tampered_zip, $tampered_checksum, $tampered_manifest);
        release_assert($tampered_ready, 'negative probe creates self-consistent tampered evidence');
        if ($tampered_ready) {
            $tampered_output = '';
            $tampered_code = release_run('cd ' . escapeshellarg($root) . ' && bash scripts/verify-release.sh ' . escapeshellarg($tampered_zip), $tampered_output);
            release_assert($tampered_code !== 0, 'verifier rejects self-consistent artifact bytes that differ from Git HEAD');
        }
    }

    $second_output = '';
    $second_code = release_run($build_command . escapeshellarg($second), $second_output);
    release_assert($second_code === 0, 'second deterministic canonical build exits zero');
    $second_zip = $second . '/' . $zip_name;
    $second_checksum = $second_zip . '.sha256';
    $second_manifest = $second . '/' . $manifest_name;
    release_assert(is_file($second_zip) && is_file($second_checksum) && is_file($second_manifest), 'second build emits complete canonical evidence');
    if (is_file($first_zip) && is_file($second_zip)) {
        release_assert(hash_file('sha256', $first_zip) === hash_file('sha256', $second_zip), 'same Git HEAD builds byte-identical canonical ZIP twice');
        release_assert(file_get_contents($first_checksum) === file_get_contents($second_checksum), 'same Git HEAD emits identical checksum twice');
        release_assert(file_get_contents($first_manifest) === file_get_contents($second_manifest), 'same Git HEAD emits identical manifest twice');
    }

    release_rm_tree($tmp);
}

echo "\nSUPCheckout Release Artifact: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
