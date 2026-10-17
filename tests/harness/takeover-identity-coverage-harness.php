<?php
/**
 * Takeover identity coverage (Sub-project 0b Task 1).
 *
 * The smart switch copies every persisted legacy name to its SUPCheckout name through
 * the single registry Simplixi\SUPCheckout\Takeover\Keys. A legacy name that shipped
 * code persists but Keys does not know would be silently left behind by the switch,
 * so this harness tokenizes every shipped PHP file and fails when a legacy-looking
 * string literal is not classified.
 *
 * A literal is legacy-looking when its value starts with one of the legacy families
 * (`upay_`, `_upay_`, `simplixpay_`, `_simplixpay_`, `UPayments_`, `upayments_`,
 * `woocommerce_upayments`, `woocommerce_your_gateway`). It is classified when it is:
 *  - a legacy key of a Keys copied map;
 *  - a value starting with a Keys::user_meta_prefixes() or Keys::ephemeral_prefixes() key;
 *  - in Keys::settings_sources() or Keys::official_only();
 *  - in this harness's $not_persisted or $persisted_never_copied allowlist, each entry
 *    carrying a one-line reason.
 *
 * Plain literals (T_CONSTANT_ENCAPSED_STRING) are checked by exact value. The leading
 * literal segment of an interpolated string or heredoc is checked too, so
 * "upay_{$x}" cannot hide a new family member.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

$root = dirname(__DIR__, 2);
require_once $root . '/src/Takeover/Keys.php';

use Simplixi\SUPCheckout\Takeover\Keys;

$pass = 0;
$fail = 0;

function tic_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

const TIC_LEGACY_PATTERN = '/^(_?upay_|_?simplixpay_|UPayments_|upayments_|woocommerce_upayments|woocommerce_your_gateway)/';

/**
 * Names that look legacy but are never persisted: request fields, nonce actions,
 * hook names, cryptographic domain separators and form field names.
 *
 * @var array<string, string>
 */
$not_persisted = array(
    'upay_' => 'nonce action prefix upay_{action}_{order} (SubscriptionActions.php, Presentation.php) and uniqid() prefix of a claim token value (CycleClaim.php); never a storage name',
    'upay_action' => 'My Account POST field / hidden input name (SubscriptionActions.php, Presentation.php); inventory §5 URL shape',
    'upay_unsubscribe_' => 'nonce action prefix upay_unsubscribe_{order} (SubscriptionActions.php, Presentation.php)',
    'upay_subscription_plan' => 'checkout request field and Blocks extension-data key; Fields::save() persists it as _upay_subscription_plan',
    'upay_subscription_interval' => 'checkout request field and Blocks extension-data key; Fields::save() persists it as _upay_subscription_interval',
    'simplixpay_upayments_migration_run' => 'MigrationAdmin::NONCE_ACTION nonce action',
    'simplixpay_upayments_nonce' => 'MigrationAdmin::NONCE_FIELD nonce form field name',
    'upayments_verification' => 'query arg on the neutral post-callback redirect URL (PaymentLifecycle::neutral_url())',
    'upayments_payment_success' => 'action hook name the dead Subscription\\Manager listens on; nothing fires it',
    'upayments_token_identity_secret_record_v1' => 'CustomerTokenIdentity::VERIFIER_DOMAIN HMAC domain separator baked into stored secret verifiers; must never be renamed',
    'woocommerce_upayments_api_key' => 'WC_Settings_API POST field name (plugin_id . id . _field); the value persists inside the settings option',
    'woocommerce_upayments_enable_multimerchant' => 'WC_Settings_API POST field name; the value persists inside the settings option',
    'woocommerce_upayments_iban_number' => 'WC_Settings_API POST field name; the value persists inside the settings option',
    'woocommerce_upayments_cc_charge' => 'WC_Settings_API POST field name; the value persists inside the settings option',
    'woocommerce_upayments_cc_charge_type' => 'WC_Settings_API POST field name; the value persists inside the settings option',
    'woocommerce_upayments_knet_charge' => 'WC_Settings_API POST field name; the value persists inside the settings option',
    'woocommerce_upayments_knet_charge_type' => 'WC_Settings_API POST field name; the value persists inside the settings option',
);

/**
 * Persisted names the switch deliberately never copies.
 *
 * @var array<string, string>
 */
$persisted_never_copied = array(
    'upay_subscriptions' => 'table {prefix}upay_subscriptions written only by the dead Subscription\\Manager listener; Preflight reports it, the switch never touches it',
);

/**
 * @param string $value Literal value.
 * @return string|null Classification, or null when unclassified.
 */
function tic_classify($value, array $not_persisted, array $persisted_never_copied) {
    $maps = array(
        'order_meta' => Keys::order_meta(),
        'user_meta' => Keys::user_meta(),
        'product_meta' => Keys::product_meta(),
        'options' => Keys::options(),
        'cron_hooks' => Keys::cron_hooks(),
        'tables' => Keys::tables(),
    );
    foreach ($maps as $name => $map) {
        if (isset($map[$value])) {
            return $name;
        }
    }
    foreach (array('user_meta_prefixes' => Keys::user_meta_prefixes(), 'ephemeral_prefixes' => Keys::ephemeral_prefixes()) as $name => $map) {
        foreach (array_keys($map) as $prefix) {
            if (strpos($value, $prefix) === 0) {
                return $name;
            }
        }
    }
    if (in_array($value, Keys::settings_sources(), true)) {
        return 'settings_sources';
    }
    foreach (Keys::official_only() as $kind => $names) {
        if (in_array($value, $names, true)) {
            return 'official_only.' . $kind;
        }
    }
    if (isset($not_persisted[$value])) {
        return 'not_persisted';
    }
    if (isset($persisted_never_copied[$value])) {
        return 'persisted_never_copied';
    }
    return null;
}

/**
 * Legacy-looking literals in one PHP source, with line numbers.
 *
 * @param string $source PHP source.
 * @return array<int, array{0: string, 1: int}>
 */
function tic_literals($source) {
    $found = array();
    $tokens = token_get_all($source);
    $previous = null;
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $value = null;
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = stripcslashes(substr($token[1], 1, -1));
            } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE
                && ($previous === '"' || (is_array($previous) && $previous[0] === T_START_HEREDOC))
            ) {
                // Leading literal segment of an interpolated string or heredoc.
                $value = $token[1];
            }
            if ($value !== null && preg_match(TIC_LEGACY_PATTERN, $value)) {
                $found[] = array($value, $token[2]);
            }
            if ($token[0] !== T_WHITESPACE) {
                $previous = $token;
            }
            continue;
        }
        $previous = $token;
    }
    return $found;
}

/** @return array<int, string> Shipped PHP files, relative to the root. */
function tic_shipped_php_files($root) {
    $files = array();
    foreach (array('supcheckout.php', 'UPayments.php', 'uninstall.php', 'index.php') as $file) {
        if (is_file($root . '/' . $file)) {
            $files[] = $file;
        }
    }
    foreach (array('src', 'templates') as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (substr((string) $file, -4) === '.php') {
                $files[] = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
            }
        }
    }
    sort($files);
    return $files;
}

// --- Registry shape. ---

tic_assert(class_exists(Keys::class, false), 'Keys registry loads');
tic_assert(count(Keys::order_meta()) >= 40 && count(Keys::cron_hooks()) === 3 && count(Keys::tables()) === 1, 'Keys copied maps carry the audited rows');

// --- Allowlist hygiene: every entry has a reason and is not also classified by Keys. ---

$allowlists = array('not_persisted' => $not_persisted, 'persisted_never_copied' => $persisted_never_copied);
$bad_reasons = array();
$overlaps = array();
foreach ($allowlists as $list => $entries) {
    foreach ($entries as $name => $reason) {
        if (!is_string($reason) || trim($reason) === '') {
            $bad_reasons[] = $list . ':' . $name;
        }
        if (tic_classify($name, array(), array()) !== null) {
            $overlaps[] = $list . ':' . $name;
        }
    }
}
tic_assert($bad_reasons === array(), 'every allowlist entry carries a reason' . ($bad_reasons ? ': ' . implode(', ', $bad_reasons) : ''));
tic_assert($overlaps === array(), 'no allowlist entry is also classified by Keys' . ($overlaps ? ': ' . implode(', ', $overlaps) : ''));
tic_assert(array_intersect_key($not_persisted, $persisted_never_copied) === array(), 'the two allowlists do not overlap');

// --- Self-test: the scanner sees inline, constant and interpolated literals. ---

$probe = "<?php\n\$a = '_upay_brand_new_key';\nconst X = \"UPayments_NewField\";\n\$b = \"upayments_new_{\$id}\";\n\$c = 'UPayments_PaymentID';\n\$d = 'not_legacy';\n";
$probe_found = tic_literals($probe);
$probe_unclassified = array();
foreach ($probe_found as $literal) {
    if (tic_classify($literal[0], $not_persisted, $persisted_never_copied) === null) {
        $probe_unclassified[] = $literal[0] . '@' . $literal[1];
    }
}
tic_assert(
    $probe_unclassified === array('_upay_brand_new_key@2', 'UPayments_NewField@3', 'upayments_new_@4'),
    'scanner reports unclassified inline, constant and interpolated literals with their lines (' . implode(', ', $probe_unclassified) . ')'
);
tic_assert(count($probe_found) === 4, 'scanner ignores non-legacy literals and sees the classified one');

// --- The shipped tree. ---

$files = tic_shipped_php_files($root);
tic_assert(in_array('supcheckout.php', $files, true) && in_array('UPayments.php', $files, true), 'bootstrap and transitional shim are scanned');
tic_assert(in_array('src/Takeover/Keys.php', $files, true) && count($files) >= 60, 'src/ and templates/ are scanned (' . count($files) . ' files)');

$unclassified = array();
$seen = array();
foreach ($files as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    foreach (tic_literals($source) as $literal) {
        list($value, $line) = $literal;
        $seen[$value] = true;
        if ($relative === 'src/Takeover/Keys.php') {
            continue; // The registry itself is the classification.
        }
        if (tic_classify($value, $not_persisted, $persisted_never_copied) === null) {
            $unclassified[] = $relative . ':' . $line . ' ' . $value;
        }
    }
}
foreach ($unclassified as $entry) {
    echo "UNCLASSIFIED: {$entry}\n";
}
tic_assert($unclassified === array(), 'every legacy-looking literal in shipped code is classified in Keys or the harness allowlists' . ($unclassified ? ' (' . count($unclassified) . ' unclassified)' : ''));

$stale = array();
foreach ($allowlists as $list => $entries) {
    foreach (array_keys($entries) as $name) {
        if (!isset($seen[$name])) {
            $stale[] = $list . ':' . $name;
        }
    }
}
tic_assert($stale === array(), 'no allowlist entry is stale (each still occurs in shipped code)' . ($stale ? ': ' . implode(', ', $stale) : ''));

echo "\nTakeover identity coverage: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
