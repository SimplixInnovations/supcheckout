<?php

/**
 * Permanent SUPCheckout identity-migration contract.
 *
 * WordPress-independent by design: this harness guards canonical first-party
 * identity while explicitly documenting compatibility identities that are
 * allowed to retain inherited UPayments names.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function supcheckout_identity_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }

    ++$fail;
    echo "FAIL: {$message}\n";
}

function supcheckout_identity_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    supcheckout_identity_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

$identity = supcheckout_identity_read($root, 'src/Release/Identity.php');
$standard = supcheckout_identity_read($root, 'docs/project/NAMING-IDENTITY-STANDARD.md');
$spec = supcheckout_identity_read($root, 'docs/superpowers/specs/2026-09-06-sucheckout-upayments-identity-migration-design.md');

$canonical = array(
    "PRODUCT_NAME = 'SUPCheckout for UPayments'",
    "SHORT_NAME = 'SUPCheckout'",
    "SLUG = 'supcheckout'",
    "REPOSITORY = 'SimplixInnovations/supcheckout'",
    "TEXT_DOMAIN = 'supcheckout'",
    "NAMESPACE_ROOT = 'Simplixi\\\\SUPCheckout'",
    "TARGET_MAIN_FILE = 'supcheckout.php'",
);

foreach ($canonical as $needle) {
    supcheckout_identity_assert(strpos($identity, $needle) !== false, "canonical release identity present: {$needle}");
}

$legacy = array(
    "LEGACY_MAIN_FILE = 'UPayments.php'",
    "LEGACY_TEXT_DOMAIN = 'upayments'",
    "LEGACY_GATEWAY_ID = 'upayments'",
    "LEGACY_SETTINGS_OPTION = 'woocommerce_upayments_settings'",
    "LEGACY_CALLBACK_ROUTE = 'wc_upayments'",
    "LEGACY_SUBSCRIPTION_HOOK = 'upay_process_subscriptions'",
    "LEGACY_TOKEN_SECRET_OPTION = 'upayments_token_identity_secret_v2'",
    "LEGACY_BILLING_ATTEMPT_TABLE_SUFFIX = 'upayments_billing_attempts'",
);

foreach ($legacy as $needle) {
    supcheckout_identity_assert(strpos($identity, $needle) !== false, "legacy compatibility identity explicit: {$needle}");
}

supcheckout_identity_assert(strpos($identity, 'SimplixPay for UPayments') === false, 'retired SimplixPay product name is absent from canonical identity source');
supcheckout_identity_assert(strpos($identity, 'simplixpay-upayments') === false, 'retired SimplixPay slug is absent from canonical identity source');
supcheckout_identity_assert(strpos($identity, 'sucheckout-for-upayments') === false, 'technical identity never contains for');

supcheckout_identity_assert(strpos($standard, '# SUPCheckout for UPayments') === 0, 'canonical naming standard is SUPCheckout-owned');
supcheckout_identity_assert(strpos($standard, '**Canonical slug:** `supcheckout`') !== false, 'naming standard pins canonical technical slug');
supcheckout_identity_assert(strpos($standard, '`supcheckout-for-upayments`') !== false, 'naming standard explicitly documents the forbidden for-form');
supcheckout_identity_assert(strpos($spec, 'The word **for** is human-facing relationship copy only.') !== false, 'approved design records human-only for rule');

echo "\nSUPCheckout Identity Migration: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
