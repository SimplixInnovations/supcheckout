<?php

/**
 * First-party PHP namespace/package migration guard for SUPCheckout.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function supcheckout_ns_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

$composer_path = $root . '/composer.json';
$composer = json_decode((string) file_get_contents($composer_path), true);
supcheckout_ns_assert(is_array($composer), 'composer.json decodes');

if (is_array($composer)) {
    supcheckout_ns_assert(
        isset($composer['name']) && $composer['name'] === 'simplix-innovations/supcheckout',
        'Composer package is canonical SUPCheckout package'
    );
    supcheckout_ns_assert(
        isset($composer['autoload']['psr-4']['Simplixi\\SUPCheckout\\'])
            && $composer['autoload']['psr-4']['Simplixi\\SUPCheckout\\'] === 'src/',
        'Composer production PSR-4 root is canonical SUPCheckout namespace'
    );
    supcheckout_ns_assert(
        isset($composer['autoload-dev']['psr-4']['Simplixi\\SUPCheckout\\Tests\\'])
            && $composer['autoload-dev']['psr-4']['Simplixi\\SUPCheckout\\Tests\\'] === 'tests/unit/',
        'Composer test PSR-4 root is canonical SUPCheckout namespace'
    );
}

$scan_roots = array($root . '/src', $root . '/tests');
$retired = array(
    'Simplix\\Pay\\UPayments',
    'Simplixi\\SUCheckout\\UPayments',
);
$canonical = 'Simplixi\\SUPCheckout';
$legacy_files = array();
$canonical_files = 0;

foreach ($scan_roots as $scan_root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scan_root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            continue;
        }

        $relative = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
        foreach ($retired as $retired_namespace) {
            if (strpos($source, $retired_namespace) !== false) {
                $legacy_files[] = $relative . ' :: ' . $retired_namespace;
            }
        }
        if (strpos($source, $canonical) !== false) {
            ++$canonical_files;
        }
    }
}

$bootstrap = (string) file_get_contents($root . '/supcheckout.php');
foreach ($retired as $retired_namespace) {
    if (strpos($bootstrap, $retired_namespace) !== false) {
        $legacy_files[] = 'supcheckout.php :: ' . $retired_namespace;
    }
}
if (strpos($bootstrap, $canonical) !== false) {
    ++$canonical_files;
}

sort($legacy_files);
foreach ($legacy_files as $path) {
    supcheckout_ns_assert(false, "retired first-party namespace remains: {$path}");
}
supcheckout_ns_assert(count($legacy_files) === 0, 'no retired first-party namespace remains in executable/test PHP');
supcheckout_ns_assert($canonical_files >= 20, 'canonical SUPCheckout namespace is established across first-party code');

echo "\nSUPCheckout Namespace Migration: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
