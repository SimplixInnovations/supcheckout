<?php
/**
 * Machine-readable SUPCheckout retired-identity residue contract.
 *
 * This gate distinguishes current/shippable identity from historical,
 * test-only and explicitly bounded migration compatibility evidence.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function sur_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

function sur_read($root, $path) {
    $value = @file_get_contents($root . '/' . $path);
    return is_string($value) ? $value : '';
}

function sur_is_text_path($path) {
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, array('php','js','css','md','txt','json','yml','yaml','xml','sh'), true)
        || basename($path) === 'AGENTS.md';
}

$tracked_raw = shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files -z');
sur_assert(is_string($tracked_raw) && $tracked_raw !== '', 'tracked-file inventory is available');
$tracked = array_values(array_filter(explode("\0", (string) $tracked_raw), 'strlen'));

/*
 * Tests deliberately exercise legacy names and old package roots. Immutable
 * historical engineering records also preserve then-current identifiers.
 * Neither surface is a current product-identity declaration.
 */
$historical_prefixes = array(
    'docs/history/',
    'docs/superpowers/',
    'tests/',
);
$historical_files = array(
    'CHANGELOG.md',
    'docs/project/ARCHITECTURE-CODE-QUALITY.md',
    'docs/project/BASELINE-H12.md',
    'docs/project/MASTER-ENGINEERING-PLAYBOOK.md',
    'docs/project/PHASE-0-RELEASE-IDENTITY.md',
    'docs/project/PHASE-9I-MIGRATION.md',
    'docs/project/PROVIDER-PAYMENT-LIFECYCLE.md',
    'docs/project/QUALITY-PLATFORM.md',
    'docs/project/REPOSITORY-AUDIT.md',
    'docs/project/REPOSITORY-READINESS.md',
    'docs/project/SECURITY-THREAT-MODEL.md',
);

/*
 * Legacy package roots remain allowed only where migration, rollback,
 * workflow or provenance contracts must refer to them explicitly. The former
 * repository coordinate is no longer a living identity after the completed
 * GitHub rename and is separately rejected across current coordinate surfaces.
 */
$legacy_slug_files = array(
    '.github/ISSUE_TEMPLATE/config.yml',
    '.github/workflows/compatibility-certification.yml',
    '.github/workflows/provider-sandbox-certification.yml',
    '.github/workflows/quality-gates.yml',
    '.github/workflows/release-artifact.yml',
    '.github/workflows/wordpress-org-submission-check.yml',
    'AGENTS.md',
    'NOTICE.md',
    'README.md',
    'UPSTREAM.md',
    'docs/COMPATIBILITY.md',
    'docs/ENGINEERING-ROADMAP.md',
    'docs/project/ENTERPRISE-CERTIFICATION.md',
    'docs/project/NEW-CHAT-HANDOFF.md',
    'docs/project/OWNER-HANDOFF.md',
    'docs/project/PROJECT-STATUS.md',
    'docs/project/RELEASE-ENGINEERING.md',
    'scripts/install-wp-test-environment.sh',
    'src/Migration/MigrationAdmin.php',
    'src/Migration/MigrationBootstrap.php',
);

$unexpected = array();
foreach ($tracked as $path) {
    if (!is_file($root . '/' . $path) || !sur_is_text_path($path)) {
        continue;
    }

    $is_historical = in_array($path, $historical_files, true);
    foreach ($historical_prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $is_historical = true;
            break;
        }
    }
    if ($is_historical) {
        continue;
    }

    $source = sur_read($root, $path);

    // Retired human product names must not survive on current/live surfaces.
    foreach (array('SimplixPay for UPayments', 'SimplixPay UPayments', 'SUCheckout for UPayments') as $retired_human) {
        if (strpos($source, $retired_human) !== false) {
            $unexpected[] = $path . ' :: ' . $retired_human;
        }
    }

    // Retired first-party PHP namespace must not survive outside history/tests.
    foreach (array('Simplix\\Pay\\UPayments', 'Simplixi\\SUCheckout\\UPayments') as $retired_namespace) {
        if (strpos($source, $retired_namespace) !== false) {
            $unexpected[] = $path . ' :: ' . $retired_namespace;
        }
    }

    // Pre-rebrand first-party constant prefixes are branding residue, not
    // provider/persisted compatibility identifiers.
    if (strpos($source, 'SIMPLIXPAY_') !== false) {
        $unexpected[] = $path . ' :: SIMPLIXPAY_';
    }

    // The forbidden "for" technical form may appear only in the naming
    // standard that declares it forbidden and in this regression harness.
    if (strpos($source, 'supcheckout-for-upayments') !== false
        && $path !== 'docs/project/NAMING-IDENTITY-STANDARD.md'
        && $path !== 'tests/harness/supcheckout-residue-harness.php'
    ) {
        $unexpected[] = $path . ' :: supcheckout-for-upayments';
    }

    if (strpos($source, 'simplixpay-upayments') !== false
        && !in_array($path, $legacy_slug_files, true)
    ) {
        $unexpected[] = $path . ' :: simplixpay-upayments';
    }
}

$unexpected = array_values(array_unique($unexpected));
foreach ($unexpected as $item) {
    echo "UNEXPLAINED: {$item}\n";
}
sur_assert($unexpected === array(), 'no unexplained retired identity remains on live/shippable surfaces');

$current_identity_contracts = array(
    'README.md' => array('SUPCheckout for UPayments', 'supcheckout', 'SimplixInnovations/supcheckout'),
    'AGENTS.md' => array('SUPCheckout for UPayments', 'Simplixi\\SUPCheckout', 'SimplixInnovations/supcheckout'),
    'docs/project/PROJECT-STATUS.md' => array('SUPCheckout for UPayments', 'supcheckout', 'SimplixInnovations/supcheckout'),
    'docs/project/NAMING-IDENTITY-STANDARD.md' => array('SUPCheckout for UPayments', 'Simplixi\\SUPCheckout', 'SimplixInnovations/supcheckout'),
    'supcheckout.php' => array(
        'Plugin URI: https://github.com/SimplixInnovations/supcheckout',
    ),
    // Sub-project 0a Task 5: the canonical constants moved verbatim from the main file
    // into Plugin::load_runtime(); $main_file there is the main file passed to boot().
    'src/Plugin.php' => array(
        "define('SUPCHECKOUT_VERSION', Identity::VERSION);",
        "define('SUPCHECKOUT_SLUG', Identity::SLUG);",
        "define('SUPCHECKOUT_PLUGIN_FILE', \$main_file);",
        "define('SUPCHECKOUT_UPDATE_CHANNEL', Identity::UPDATE_CHANNEL);",
    ),
    'composer.json' => array('simplix-innovations/supcheckout', 'Simplixi\\\\SUPCheckout\\\\'),
    'src/Release/Identity.php' => array("REPOSITORY = 'SimplixInnovations/supcheckout'"),
);
foreach ($current_identity_contracts as $contract_path => $needles) {
    $source = sur_read($root, $contract_path);
    sur_assert($source !== '', 'current identity source readable: ' . $contract_path);
    foreach ($needles as $needle) {
        sur_assert(strpos($source, $needle) !== false, $contract_path . ' contains canonical identity: ' . $needle);
    }
}


$repository_coordinate_files = array(
    '.github/ISSUE_TEMPLATE/config.yml',
    'README.md',
    'AGENTS.md',
    'NOTICE.md',
    'UPSTREAM.md',
    'supcheckout.php',
    'docs/ENGINEERING-ROADMAP.md',
    'docs/project/ENTERPRISE-CERTIFICATION.md',
    'docs/project/NAMING-IDENTITY-STANDARD.md',
    'docs/project/NEW-CHAT-HANDOFF.md',
    'docs/project/PROJECT-STATUS.md',
    'docs/project/README.md',
    'docs/project/RELEASE-ENGINEERING.md',
    'src/Release/Identity.php',
);
$stale_repository_coordinates = array(
    'SimplixInnovations/simplixpay-upayments',
    'SimplixInnovations/sucheckout-upayments',
    'SimplixInnovations/sucheckout',
);
foreach ($repository_coordinate_files as $coordinate_path) {
    $source = sur_read($root, $coordinate_path);
    sur_assert($source !== '', 'repository-coordinate source readable: ' . $coordinate_path);
    foreach ($stale_repository_coordinates as $stale_coordinate) {
        sur_assert(
            strpos($source, $stale_coordinate) === false,
            $coordinate_path . ' excludes stale repository coordinate: ' . $stale_coordinate
        );
    }
}

/*
 * Living QA/control-plane identity is also current product identity. Historical
 * migration roots and frozen merchant/provider persistence are deliberately
 * outside this check; only test/control names with no compatibility surface are
 * canonicalized here.
 */
$retired_qa_paths = array(
    'tests/harness/sucheckout-frontend-identity-harness.php',
    'tests/harness/sucheckout-http-transport-harness.php',
    'tests/harness/sucheckout-identity-migration-harness.php',
    'tests/harness/sucheckout-namespace-migration-harness.php',
    'tests/harness/sucheckout-provenance-db-failure-harness.php',
    'tests/harness/sucheckout-residue-harness.php',
);
foreach ($retired_qa_paths as $retired_qa_path) {
    sur_assert(!is_file($root . '/' . $retired_qa_path), 'retired living QA path is absent: ' . $retired_qa_path);
}

$canonical_qa_paths = array(
    'tests/harness/supcheckout-frontend-identity-harness.php',
    'tests/harness/supcheckout-http-transport-harness.php',
    'tests/harness/supcheckout-identity-migration-harness.php',
    'tests/harness/supcheckout-namespace-migration-harness.php',
    'tests/harness/supcheckout-provenance-db-failure-harness.php',
    'tests/harness/supcheckout-residue-harness.php',
);
foreach ($canonical_qa_paths as $canonical_qa_path) {
    sur_assert(is_file($root . '/' . $canonical_qa_path), 'canonical living QA path exists: ' . $canonical_qa_path);
}

$qa_scan_prefixes = array(
    'tests/fixtures/',
    'tests/integration/',
    'tests/provider/',
    'tests/support/',
    'tests/unit/',
);
$qa_retired_needles = array(
    'SimplixPay_Test_',
    'simplixpay_test_',
    'sucheckout_cert_',
    '_sucheckout_certification_',
    '_sucheckout_feature_ops_',
    'sucheckout-cert-',
    'SUCHECKOUT_UPAYMENTS_SANDBOX_TOKEN',
);
$qa_unexpected = array();
foreach ($tracked as $path) {
    $scan_qa = false;
    foreach ($qa_scan_prefixes as $qa_prefix) {
        if (strpos($path, $qa_prefix) === 0) {
            $scan_qa = true;
            break;
        }
    }
    if (!$scan_qa || !is_file($root . '/' . $path) || !sur_is_text_path($path)) {
        continue;
    }
    $source = sur_read($root, $path);
    foreach ($qa_retired_needles as $qa_retired_needle) {
        if (strpos($source, $qa_retired_needle) !== false) {
            $qa_unexpected[] = $path . ' :: ' . $qa_retired_needle;
        }
    }
}
$qa_control_sources = array(
    '.github/ISSUE_TEMPLATE/compatibility-report.yml',
    '.github/workflows/provider-sandbox-certification.yml',
    '.github/workflows/quality-gates.yml',
    'phpcs.xml.dist',
    'phpunit.xml.dist',
    'scripts/install-wp-test-environment.sh',
    'tests/provider/sandbox-charge-smoke.php',
);
foreach ($qa_control_sources as $qa_control_path) {
    $source = sur_read($root, $qa_control_path);
    foreach (array('SUCheckout', 'sucheckout.test', 'SUCHECKOUT_UPAYMENTS_SANDBOX_TOKEN') as $qa_control_needle) {
        if (strpos($source, $qa_control_needle) !== false) {
            $qa_unexpected[] = $qa_control_path . ' :: ' . $qa_control_needle;
        }
    }
}
$qa_unexpected = array_values(array_unique($qa_unexpected));
foreach ($qa_unexpected as $qa_item) {
    echo "UNEXPLAINED QA IDENTITY: {$qa_item}\n";
}
sur_assert($qa_unexpected === array(), 'living QA/control-plane identity uses canonical SUPCheckout naming');

/*
 * Final living runtime residue contract. These are intentionally limited to
 * non-persisted presentation/internal identities; frozen migration, provider,
 * option, metadata and cron identities are excluded.
 */
$migration_admin = sur_read($root, 'src/Migration/MigrationAdmin.php');
$order_lock = sur_read($root, 'src/Payment/OrderLock.php');
$new_design_template = sur_read($root, 'templates/new-design-form.php');
$old_design_template = sur_read($root, 'templates/old-design-form.php');

foreach (array('sucheckout-user-ids', 'sucheckout-offset', 'sucheckout-limit') as $retired_dom_id) {
    sur_assert(strpos($migration_admin, $retired_dom_id) === false, 'retired non-persisted admin DOM identity is absent: ' . $retired_dom_id);
}
sur_assert(strpos($order_lock, 'simplixpay-upay-lock-') === false, 'retired non-persisted lock entropy label is absent');
sur_assert(strpos($new_design_template, 'WC_Gateway_Your_Gateway') === false, 'new-design template documents the real gateway type');
sur_assert(strpos($old_design_template, 'WC_Gateway_Your_Gateway') === false, 'old-design template documents the real gateway type');
sur_assert(!is_file($root . '/templates/order-details.php'), 'unreferenced legacy order-details template is absent');

/*
 * Google Pay and Samsung Pay icons are referenced through the dynamic
 * assets/images/<payment-method>.png template contract rather than literals.
 * Keep them even though literal-filename searches report no references.
 */
sur_assert(is_file($root . '/assets/images/google-pay.png'), 'dynamic Google Pay icon asset remains packaged');
sur_assert(is_file($root . '/assets/images/samsung-pay.png'), 'dynamic Samsung Pay icon asset remains packaged');

echo "\nSUPCheckout Residue: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
