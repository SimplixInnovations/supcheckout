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
release_assert(strpos($identity, "public const MAIN_FILE = 'supcheckout.php';") !== false, 'canonical identity names the supcheckout.php bootstrap');
release_assert(strpos($identity, "public const LEGACY_MAIN_FILE = 'UPayments.php';") !== false, 'pre-rename UPayments.php main file stays recorded as legacy identity');
release_assert(strpos($identity, "public const TARGET_MAIN_FILE = 'supcheckout.php';") !== false, 'packaging target main file remains supcheckout.php');

// Sub-project 0a Task 9: the changelog records the main-file rename, the automatic
// basename migration and the one update path that ends deactivated.
$changelog = (string) file_get_contents($root . '/CHANGELOG.md');
$unreleased = '';
if (preg_match('/^## \[Unreleased\]\R(.*?)(?=^## \[|\z)/ms', $changelog, $unreleased_match) === 1) {
    $unreleased = $unreleased_match[1];
}
release_assert(strpos($unreleased, 'The main file is now `supcheckout.php`') !== false, 'CHANGELOG records the main-file rename');
release_assert(strpos($unreleased, 'moves the active entry to `supcheckout.php`') !== false, 'CHANGELOG records the automatic basename migration');
release_assert(strpos($unreleased, '`update.php?action=upgrade-plugin`') !== false, 'CHANGELOG records the classic Update-now exception');

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

// Sub-project 1: the admin UI toolchain (npm manifests, build/lint/type/test
// configs, tools/ and node_modules/) is development-only. The committed build
// under assets/admin/build and the source under assets/admin/src ship; nothing
// else from the toolchain may enter the package.
$admin_ui_toolchain = array(
    '/node_modules/', '/tools/', '/package.json', '/package-lock.json', '/.nvmrc', '/.npmrc',
    '/webpack.config.js', '/tsconfig.json', '/tsconfig.tests.json', '/eslint.config.mjs', '/.stylelintrc.json', '/jest.config.js',
);
$distignore_rules = preg_split('/\R/', $distignore);
foreach ($admin_ui_toolchain as $excluded) {
    release_assert(in_array($excluded, $distignore_rules, true), 'distribution excludes admin UI toolchain path: ' . $excluded);
}
foreach (array('"package.json"', '"package-lock.json"', '".nvmrc"', '".npmrc"', '"webpack.config.js"', '"tsconfig.json"', '"tsconfig.tests.json"', '"eslint.config.mjs"', '".stylelintrc.json"', '"jest.config.js"') as $forbidden) {
    release_assert(strpos($verify, $forbidden) !== false, 'verifier forbids admin UI toolchain file: ' . $forbidden);
}
release_assert(
    strpos($verify, '"node_modules/", "tools/"') !== false && strpos($verify, 'forbidden_suffixes = (".map",)') !== false,
    'verifier forbids node_modules/, tools/ and source maps'
);
release_assert(
    strpos($verify, 'allowed_prefixes = ("src/", "assets/", "templates/", "languages/")') !== false,
    'verifier allows exactly the runtime subtrees, including the bundled languages/ directory (A4-01)'
);
release_assert(
    strpos($verify, 'language_suffixes = (".pot", ".po", ".mo", ".json", ".l10n.php")') !== false,
    'verifier holds languages/ to translation-data suffixes only (A4-01)'
);

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
release_assert(strpos($workflow, 'actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9') !== false, 'artifact upload action is immutably pinned');
release_assert(strpos($workflow, 'actions/download-artifact@9000827ccba6bdab643e8b6fd33ac0654aef8333') !== false, 'artifact download action is immutably pinned');
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
                release_assert(in_array($prefix . 'supcheckout.php', $names, true), 'canonical package ships the supcheckout.php bootstrap');
                release_assert(in_array($prefix . 'UPayments.php', $names, true), 'canonical package ships the transitional UPayments.php basename-migration shim');
                release_assert(in_array($prefix . 'src/Bootstrap/BasenameMigration.php', $names, true), 'canonical package ships the basename migration the shim requires');
                $shim = $zip->getFromName($prefix . 'UPayments.php');
                $shim = is_string($shim) ? $shim : '';
                release_assert($shim !== '' && stripos($shim, 'Plugin Name:') === false, 'packaged shim has no Plugin Name header, so WordPress never lists a second plugin');
                release_assert($shim !== '' && count(preg_split('/\r\n|\n|\r/', rtrim($shim, "\r\n"))) <= 12, 'packaged shim is at most 12 lines');
                release_assert(strpos($shim, "defined('ABSPATH') || exit;") !== false, 'packaged shim exits on direct access');
                $shim_requires = array();
                preg_match_all('/\b(?:require|require_once|include|include_once)\b\s*\(?\s*([^;]+);/', $shim, $shim_matches);
                foreach ($shim_matches[1] as $shim_target) {
                    $shim_requires[] = trim($shim_target, " \t()");
                }
                release_assert(
                    $shim_requires === array("__DIR__ . '/src/Bootstrap/BasenameMigration.php'", "__DIR__ . '/supcheckout.php'"),
                    'packaged shim requires only src/Bootstrap/BasenameMigration.php and then supcheckout.php'
                );
                release_assert(in_array($prefix . 'readme.txt', $names, true), 'canonical package contains WordPress.org readme');
                release_assert(!in_array($prefix . 'composer.json', $names, true), 'canonical package excludes Composer controls');
                // A4-01 (U7): the bundled translations the Domain Path header names.
                $language_files = array('supcheckout.pot', 'supcheckout-ar.po', 'supcheckout-ar.mo');
                // The admin script translations, named by md5 of each script's package path.
                foreach (array('assets/admin/build/app.js', 'assets/admin/build/chunks/command-palette.a159e014.js') as $translated_script) {
                    $language_files[] = 'supcheckout-ar-' . md5($translated_script) . '.json';
                }
                foreach ($language_files as $language_file) {
                    release_assert(in_array($prefix . 'languages/' . $language_file, $names, true), 'canonical package ships the bundled translation file: ' . $language_file);
                }

                $contains_screenshots = false;
                foreach ($names as $name) {
                    if (strpos($name, $prefix . 'assets/screenshots/') === 0) {
                        $contains_screenshots = true;
                        break;
                    }
                }
                release_assert(!$contains_screenshots, 'canonical package excludes WordPress.org screenshot source material');

                foreach (array('app.js', 'app.asset.json', 'app.css', 'app-rtl.css', 'THIRD-PARTY-NOTICES.txt') as $admin_build_file) {
                    release_assert(in_array($prefix . 'assets/admin/build/' . $admin_build_file, $names, true), 'canonical package ships the committed admin build: ' . $admin_build_file);
                }
                release_assert(in_array($prefix . 'assets/admin/src/app/index.tsx', $names, true), 'canonical package ships the human-readable admin source');
                $admin_dev_entries = array();
                foreach ($names as $name) {
                    $rel = substr($name, strlen($prefix));
                    if (substr($rel, -4) === '.map'
                        || strpos('/' . $rel, '/__tests__/') !== false
                        || strpos($rel, 'tools/') === 0
                        || strpos($rel, 'node_modules/') === 0
                        || strpos($rel, 'tests/admin-ui/') === 0) {
                        $admin_dev_entries[] = $rel;
                    }
                }
                release_assert($admin_dev_entries === array(), 'canonical package has no source map, __tests__, tools/, node_modules/ or tests/admin-ui/ entry');
                $font_entries = array();
                foreach ($names as $name) {
                    if (preg_match('/\.(woff2?|ttf|otf|eot)$/i', $name) === 1) {
                        $font_entries[] = substr($name, strlen($prefix));
                    }
                }
                release_assert($font_entries === array(), 'canonical package ships no web font (the visual-regression test fonts stay in the gallery build)');

                $plugin = $zip->getFromName($prefix . 'supcheckout.php');
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

        // A4-01 (U7): a stray PHP file in languages/ is rejected by the languages/ suffix rule
        // itself. The probe ZIP keeps every deterministic container property (sorted paths,
        // stored entries, fixed timestamp, mode and creator) so only that rule can fail it.
        $stray_dir = $tmp . '/stray-language';
        @mkdir($stray_dir, 0777, true);
        $stray_zip = $stray_dir . '/' . $zip_name;
        $stray_script = $tmp . '/stray-language.py';
        $stray_ready = false !== file_put_contents($stray_script, implode("\n", array(
            'import sys, zipfile',
            'with zipfile.ZipFile(sys.argv[1]) as zin:',
            '    entries = [(i.filename, zin.read(i.filename)) for i in zin.infolist()]',
            'entries.append(("supcheckout/languages/x.php", b"<?php\n"))',
            'entries.sort(key=lambda e: e[0])',
            'with zipfile.ZipFile(sys.argv[2], "w", compression=zipfile.ZIP_STORED) as zout:',
            '    for name, data in entries:',
            '        info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))',
            '        info.create_system = 3',
            '        info.external_attr = 0o100644 << 16',
            '        info.compress_type = zipfile.ZIP_STORED',
            '        zout.writestr(info, data)',
            '',
        )));
        $stray_output = '';
        $stray_ready = $stray_ready
            && 0 === release_run('python3 ' . escapeshellarg($stray_script) . ' ' . escapeshellarg($first_zip) . ' ' . escapeshellarg($stray_zip), $stray_output)
            && release_rewrite_evidence_for_zip($stray_zip, $stray_zip . '.sha256', $stray_dir . '/' . $manifest_name);
        release_assert($stray_ready, 'negative probe creates a deterministic artifact with a stray languages/x.php: ' . $stray_output);
        if ($stray_ready) {
            $stray_code = release_run('cd ' . escapeshellarg($root) . ' && bash scripts/verify-release.sh ' . escapeshellarg($stray_zip), $stray_output);
            release_assert(
                $stray_code !== 0 && strpos($stray_output, 'Unexpected file in languages/: languages/x.php') !== false,
                'verifier rejects a stray languages/x.php through the languages/ suffix rule: ' . $stray_output
            );
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
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
