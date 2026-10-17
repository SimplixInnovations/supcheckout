<?php
/**
 * Takeover identity coverage (Sub-project 0b Task 1).
 *
 * The smart switch moves every persisted legacy name to its SUPCheckout name through
 * the single registry Simplixi\SUPCheckout\Takeover\Keys, and Task 2 retargets every
 * gateway-identity site. This harness makes a forgotten name or site fail CI.
 *
 * Rule 1 (legacy-family names, shipped PHP). A string literal whose value starts with
 * `upay_`, `_upay_`, `simplixpay_`, `_simplixpay_`, `UPayments_`, `upayments_`,
 * `woocommerce_upayments` or `woocommerce_your_gateway` must be:
 *  - a legacy key of a Keys copied map;
 *  - EXACTLY a Keys::user_meta_prefixes() or Keys::ephemeral_prefixes() key (code builds
 *    the full name dynamically, so a longer literal such as `upay_pm_foo` is a new name);
 *  - in Keys::settings_sources() or Keys::official_only() (the official update-checker
 *    names by prefix);
 *  - a gateway-identity site owned by rule 2;
 *  - or in the FILE-KEYED $not_persisted allowlist, with its exact occurrence count and
 *    a one-line reason.
 * Plain literals and the leading literal segment of interpolated strings and heredocs
 * are checked.
 *
 * Rule 2 (gateway identity, shipped PHP and assets/js). Every occurrence of the gateway
 * identity `upayments`, the callback route `wc_upayments`, or a name derived from them
 * (`woocommerce_upayments*`, `*_upayments`) in a PHP string, inline HTML or JS file is an
 * inventoried site: $gateway_sites lists, per file and token, one category per
 * occurrence. A new site, a disappeared site or an unknown category fails, so Task 2
 * cannot miss one.
 *
 * src/Takeover/Keys.php is the registry itself and is not scanned.
 *
 * `--scan-root=<dir>` scans another tree (used by the failing-path self-test).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

$harness_root = dirname(__DIR__, 2);
require_once $harness_root . '/src/Takeover/Keys.php';

use Simplixi\SUPCheckout\Takeover\Keys;

$scan_root = $harness_root;
$child = false;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--scan-root=') === 0) {
        $scan_root = substr($arg, strlen('--scan-root='));
        $child = true;
    }
}

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
const TIC_GATEWAY_PATTERN = '/(?<![\w-])(?:wc_upayments|woocommerce_upayments\w*|\w*_upayments|upayments)(?![\w-])/';
const TIC_REGISTRY = 'src/Takeover/Keys.php';

/**
 * Legacy-looking names that are never persisted, per file: value => [occurrences, reason].
 *
 * @var array<string, array<string, array{0: int, 1: string}>>
 */
$not_persisted = array(
    'src/Account/SubscriptionActions.php' => array(
        'upay_' => array(1, 'nonce action prefix upay_{action}_{order}'),
        'upay_unsubscribe_' => array(1, 'nonce action prefix upay_unsubscribe_{order}'),
        'upay_action' => array(3, 'My Account POST field name (inventory §5)'),
    ),
    'src/Subscription/Presentation.php' => array(
        'upay_' => array(1, 'nonce action prefix upay_{action}_{order} for wp_nonce_field()'),
        'upay_unsubscribe_' => array(1, 'nonce action prefix upay_unsubscribe_{order} for wp_nonce_field()'),
    ),
    'src/Subscription/Cron/CycleClaim.php' => array(
        'upay_' => array(1, 'uniqid() prefix of a claim-token value, never a storage name'),
    ),
    'src/Blocks/PaymentMethodType.php' => array(
        'upay_subscription_plan' => array(1, 'Blocks client-data default; Fields::save() persists the plan as _upay_subscription_plan'),
        'upay_subscription_interval' => array(1, 'Blocks client-data default; Fields::save() persists the interval as _upay_subscription_interval'),
    ),
    'src/Subscription/Checkout/Fields.php' => array(
        'upay_subscription_plan' => array(7, 'checkout field / POST name; Fields::save() persists it as _upay_subscription_plan'),
        'upay_subscription_interval' => array(7, 'checkout field / POST name; Fields::save() persists it as _upay_subscription_interval'),
    ),
    'src/Payment/CheckoutOrchestrator.php' => array(
        'upay_subscription_plan' => array(9, 'Blocks extension-data / classic POST field read; persisted as _upay_subscription_plan'),
        'upay_subscription_interval' => array(6, 'Blocks extension-data / classic POST field read; persisted as _upay_subscription_interval'),
    ),
    'src/Migration/MigrationAdmin.php' => array(
        'simplixpay_upayments_migration_run' => array(1, 'MigrationAdmin::NONCE_ACTION nonce action'),
        'simplixpay_upayments_nonce' => array(1, 'MigrationAdmin::NONCE_FIELD nonce form field name'),
    ),
    'src/Payment/PaymentLifecycle.php' => array(
        'upayments_verification' => array(1, 'query arg on the neutral post-callback redirect URL (neutral_url())'),
    ),
    'src/Subscription/Manager.php' => array(
        'upayments_payment_success' => array(1, 'action hook the dead Subscription\\Manager listens on; nothing fires it'),
    ),
    'src/Token/CustomerTokenIdentity.php' => array(
        'upayments_token_identity_secret_record_v1' => array(1, 'VERIFIER_DOMAIN HMAC domain separator built into stored secret verifiers; must never be renamed'),
    ),
);

/**
 * Gateway-identity site categories => the Task 2 action (or why the site stays).
 *
 * @var array<string, string>
 */
$gateway_categories = array(
    'registry' => 'Release\\Identity LEGACY_* record; never changes',
    'gateway_declaration' => 'gateway / Blocks registration identity; becomes Keys::GATEWAY at cutover',
    'order_query' => "wc_get_orders 'payment_method' filter; use Keys::gateway_ids()",
    'gateway_lookup' => 'available/registered gateways array lookup; look up the registered gateway under its current id',
    'ownership_check' => 'order / selected / filtered gateway comparison; use Keys::is_our_gateway()',
    'renewal_identity' => 'payment method written on a NEW renewal order; use the current gateway id',
    'session_choice' => 'WC session chosen_payment_method comparison; Keys::is_our_gateway() (stale sessions hold upayments for up to 48 h)',
    'default_gateway' => 'woocommerce_default_gateway value; the current gateway id',
    'extension_namespace' => 'Store API extensions namespace; PHP reads Keys::EXTENSION_NAMESPACE then _LEGACY, JS posts the current namespace from localized data',
    'callback_route' => 'callback route / hook; new Charges use Keys::CALLBACK_ROUTE, the LegacyCallbackListener (Task 8) keeps wc_upayments forever',
    'settings_option' => 'gateway settings option read; Keys::settings_sources() precedence into woocommerce_supcheckout_settings',
    'settings_field' => 'WC_Settings_API name derived from the gateway id (POST field, sanitize hook, admin selector); derive from the current id',
    'css_class' => 'WooCommerce payment_method_{id} class; derive from the current id',
    'provider_host' => 'UPayments API host name (provider terminology); never changes',
    'logger_source' =>'wc_get_logger() source name; a log-file label, not a persisted identity',
    'legacy_text_domain' => 'vestigial Gateway::$domain text-domain property passed to helpers that ignore it; not a gateway id',
    'payment_type_sentinel' => 'upayment_payment_type value meaning "no white-label method chosen" (templates/new-design-form.php <-> CheckoutPayload::classic_method_selected()); not a gateway id, the pair must change together',
);

/**
 * Every gateway-identity site: file => token => one category per occurrence, in source order.
 *
 * @var array<string, array<string, list<string>>>
 */
$gateway_sites = array(
    'src/Account/SubscriptionActions.php' => array('upayments' => array('ownership_check')),
    'src/Admin/GatewaySettings.php' => array(
        'woocommerce_upayments_api_key' => array_fill(0, 2, 'settings_field'),
        'woocommerce_upayments_enable_multimerchant' => array('settings_field'),
        'woocommerce_upayments_iban_number' => array_fill(0, 5, 'settings_field'),
        'woocommerce_upayments_cc_charge' => array_fill(0, 5, 'settings_field'),
        'woocommerce_upayments_cc_charge_type' => array_fill(0, 5, 'settings_field'),
        'woocommerce_upayments_knet_charge' => array_fill(0, 5, 'settings_field'),
        'woocommerce_upayments_knet_charge_type' => array_fill(0, 5, 'settings_field'),
    ),
    'src/Blocks/PaymentMethodType.php' => array(
        'upayments' => array('gateway_declaration', 'gateway_declaration', 'logger_source', 'gateway_lookup', 'gateway_lookup', 'gateway_lookup'),
        'woocommerce_upayments_settings' => array('settings_option'),
    ),
    'src/Gateway/Availability.php' => array(
        'upayments' => array('gateway_lookup', 'gateway_lookup', 'gateway_lookup', 'gateway_lookup', 'gateway_lookup', 'session_choice'),
        'woocommerce_upayments_settings' => array('settings_option'),
    ),
    'src/Gateway/Gateway.php' => array(
        'upayments' => array('legacy_text_domain', 'gateway_declaration', 'gateway_lookup', 'gateway_lookup', 'session_choice', 'default_gateway', 'ownership_check', 'logger_source'),
        'woocommerce_upayments_settings' => array('settings_option'),
        'woocommerce_settings_api_sanitized_fields_upayments' => array('settings_field'),
        'wc_upayments' => array('callback_route'),
    ),
    'src/Migration/MigrationPreflight.php' => array('upayments' => array('order_query', 'order_query')),
    'src/Migration/MigrationSettings.php' => array('woocommerce_upayments_settings' => array('settings_option')),
    'src/Payment/CheckoutOrchestrator.php' => array('upayments' => array_fill(0, 6, 'extension_namespace')),
    'src/Payment/CheckoutPayload.php' => array('upayments' => array('payment_type_sentinel')),
    'src/Payment/PaymentLifecycle.php' => array(
        'woocommerce_api_wc_upayments' => array('callback_route'),
        'upayments' => array('ownership_check', 'ownership_check', 'gateway_lookup', 'gateway_lookup'),
    ),
    'src/Payment/ReconciliationSweep.php' => array('upayments' => array('order_query')),
    'src/Payment/StatusVerifier.php' => array('upayments' => array('provider_host', 'provider_host')),
    'src/Provider/EndpointResolver.php' => array('upayments' => array('provider_host', 'provider_host')),
    'src/Release/Identity.php' => array(
        'upayments' => array('registry', 'registry'),
        'woocommerce_upayments_settings' => array('registry'),
        'wc_upayments' => array('registry'),
    ),
    'src/Security/PublicOrderStatus.php' => array('upayments' => array('ownership_check')),
    'src/Subscription/Checkout/Fields.php' => array('upayments' => array('ownership_check', 'gateway_lookup')),
    'src/Subscription/Cron/Scheduler.php' => array(
        'woocommerce_upayments_settings' => array('settings_option'),
        'upayments' => array('order_query', 'renewal_identity', 'ownership_check', 'ownership_check', 'gateway_lookup'),
    ),
    'src/Subscription/Scheduling/HistoricalEnrollment.php' => array('upayments' => array('order_query', 'ownership_check')),
    'src/Token/CustomerTokenIdentity.php' => array('upayments' => array('order_query', 'order_query')),
    'templates/new-design-form.php' => array('upayments' => array('payment_type_sentinel')),
    'assets/js/admin-settings.js' => array(
        'woocommerce_upayments_use_new_design' => array('settings_field'),
        'woocommerce_upayments_enable_save_card' => array('settings_field'),
        'woocommerce_upayments_enable_multimerchant' => array('settings_field'),
    ),
    'assets/js/new-upay.js' => array(
        'payment_method_upayments' => array('css_class'),
        'upayments' => array('ownership_check'),
    ),
    'assets/js/upayments-block.js' => array('upayments' => array('gateway_declaration', 'extension_namespace', 'gateway_declaration')),
);

/**
 * @param string $value Literal value.
 * @param string $file  Relative path of the file it occurs in.
 * @return string|null Classification, or null when unclassified.
 */
function tic_classify($value, $file, array $not_persisted) {
    $maps = array(
        'order_meta' => Keys::order_meta(),
        'user_meta' => Keys::user_meta(),
        'product_meta' => Keys::product_meta(),
        'options' => Keys::options(),
        'cron_hooks' => Keys::cron_hooks(),
        'tables' => Keys::tables(),
        'user_meta_prefixes' => Keys::user_meta_prefixes(),
        'ephemeral_prefixes' => Keys::ephemeral_prefixes(),
    );
    foreach ($maps as $name => $map) {
        if (isset($map[$value])) {
            return $name;
        }
    }
    if (in_array($value, Keys::settings_sources(), true)) {
        return 'settings_sources';
    }
    foreach (Keys::official_only() as $kind => $names) {
        foreach ($names as $name) {
            $is_prefix = substr($kind, -9) === '_prefixes';
            if ($value === $name || ($is_prefix && strpos($value, $name) === 0)) {
                return 'official_only.' . $kind;
            }
        }
    }
    if (tic_gateway_tokens($value) === array($value)) {
        return 'gateway_identity_site';
    }
    if (isset($not_persisted[$file][$value])) {
        return 'not_persisted';
    }
    return null;
}

/**
 * Gateway-identity tokens inside a piece of text.
 *
 * @param string $text Text.
 * @return list<string>
 */
function tic_gateway_tokens($text) {
    return preg_match_all(TIC_GATEWAY_PATTERN, $text, $matches) ? $matches[0] : array();
}

/**
 * Rule 1 and rule 2 occurrences in one PHP source.
 *
 * @param string $source PHP source.
 * @return array{legacy: list<array{0: string, 1: int}>, gateway: list<array{0: string, 1: int}>}
 */
function tic_php_occurrences($source) {
    $legacy = array();
    $gateway = array();
    $previous = null;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $previous = $token;
            continue;
        }
        $value = null;
        $leading = false;
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = stripcslashes(substr($token[1], 1, -1));
            $leading = true;
        } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $value = $token[1];
            $leading = $previous === '"' || (is_array($previous) && $previous[0] === T_START_HEREDOC);
        } elseif ($token[0] === T_INLINE_HTML) {
            $value = $token[1];
        }
        if ($value !== null) {
            if ($leading && preg_match(TIC_LEGACY_PATTERN, $value)) {
                $legacy[] = array($value, $token[2]);
            }
            foreach (preg_split('/\n/', $value) as $offset => $line_text) {
                foreach (tic_gateway_tokens($line_text) as $found) {
                    $gateway[] = array($found, $token[2] + $offset);
                }
            }
        }
        if ($token[0] !== T_WHITESPACE) {
            $previous = $token;
        }
    }
    return array('legacy' => $legacy, 'gateway' => $gateway);
}

/**
 * Rule 2 occurrences in one JS source (raw text, comments included).
 *
 * @param string $source JS source.
 * @return list<array{0: string, 1: int}>
 */
function tic_js_occurrences($source) {
    $found = array();
    foreach (explode("\n", $source) as $index => $line) {
        foreach (tic_gateway_tokens($line) as $token) {
            $found[] = array($token, $index + 1);
        }
    }
    return $found;
}

/** @return array{php: list<string>, js: list<string>} Shipped files, relative to the root. */
function tic_shipped_files($root) {
    $php = array();
    $js = array();
    foreach (array('supcheckout.php', 'UPayments.php', 'uninstall.php', 'index.php') as $file) {
        if (is_file($root . '/' . $file)) {
            $php[] = $file;
        }
    }
    foreach (array('src', 'templates', 'assets/js') as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
            if (substr($relative, -4) === '.php') {
                $php[] = $relative;
            } elseif (substr($relative, -3) === '.js') {
                $js[] = $relative;
            }
        }
    }
    sort($php);
    sort($js);
    return array('php' => $php, 'js' => $js);
}

/**
 * @return array{files: array{php: list<string>, js: list<string>}, unclassified: list<string>, allowlisted: array<string, array<string, int>>, gateway: array<string, array<string, list<int>>>}
 */
function tic_scan($root, array $not_persisted) {
    $files = tic_shipped_files($root);
    $unclassified = array();
    $allowlisted = array();
    $gateway = array();
    foreach (array_merge($files['php'], $files['js']) as $relative) {
        if ($relative === TIC_REGISTRY) {
            continue;
        }
        $source = (string) file_get_contents($root . '/' . $relative);
        if (substr($relative, -3) === '.js') {
            $occurrences = array('legacy' => array(), 'gateway' => tic_js_occurrences($source));
        } else {
            $occurrences = tic_php_occurrences($source);
        }
        foreach ($occurrences['legacy'] as $literal) {
            list($value, $line) = $literal;
            $class = tic_classify($value, $relative, $not_persisted);
            if ($class === null) {
                $unclassified[] = $relative . ':' . $line . ' ' . $value;
            } elseif ($class === 'not_persisted') {
                $allowlisted[$relative][$value] = (isset($allowlisted[$relative][$value]) ? $allowlisted[$relative][$value] : 0) + 1;
            }
        }
        foreach ($occurrences['gateway'] as $site) {
            $gateway[$relative][$site[0]][] = $site[1];
        }
    }
    return array('files' => $files, 'unclassified' => $unclassified, 'allowlisted' => $allowlisted, 'gateway' => $gateway);
}

/** @return list<string> Allowlist entries whose occurrence count is wrong or which are stale. */
function tic_allowlist_problems(array $not_persisted, array $allowlisted) {
    $problems = array();
    foreach ($not_persisted as $file => $entries) {
        foreach ($entries as $value => $entry) {
            $seen = isset($allowlisted[$file][$value]) ? $allowlisted[$file][$value] : 0;
            if ($seen !== $entry[0]) {
                $problems[] = "{$file} {$value}: allowlisted {$entry[0]}, found {$seen}";
            }
        }
    }
    return $problems;
}

/** @return list<string> New, disappeared or miscategorized gateway-identity sites. */
function tic_gateway_problems(array $inventory, array $found, array $categories) {
    $problems = array();
    foreach ($found as $file => $tokens) {
        foreach ($tokens as $token => $lines) {
            $listed = isset($inventory[$file][$token]) ? count($inventory[$file][$token]) : 0;
            if (count($lines) > $listed) {
                $problems[] = "NEW GATEWAY SITE: {$file}:" . implode(',', $lines) . " {$token} (inventoried {$listed}, found " . count($lines) . ')';
            }
        }
    }
    foreach ($inventory as $file => $tokens) {
        foreach ($tokens as $token => $sites) {
            $seen = isset($found[$file][$token]) ? count($found[$file][$token]) : 0;
            if ($seen < count($sites)) {
                $problems[] = "MISSING GATEWAY SITE: {$file} {$token} (inventoried " . count($sites) . ", found {$seen})";
            }
            foreach ($sites as $category) {
                if (!isset($categories[$category])) {
                    $problems[] = "UNKNOWN CATEGORY: {$file} {$token} {$category}";
                }
            }
        }
    }
    return $problems;
}

/**
 * @param array<int, string> $command Command and arguments.
 * @return array{status: int, output: string}|null
 */
function tic_run(array $command) {
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1));
    $process = @proc_open($command, $descriptors, $pipes, null, null, array('bypass_shell' => true));
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return array('status' => proc_close($process), 'output' => is_string($output) ? $output : '');
}

if (!$child) {
    // --- Registry shape and allowlist hygiene. ---

    tic_assert(class_exists(Keys::class, false), 'Keys registry loads');
    tic_assert(count(Keys::order_meta()) >= 40 && count(Keys::cron_hooks()) === 3 && count(Keys::tables()) === 1, 'Keys copied maps carry the audited rows');

    $bad_entries = array();
    foreach ($not_persisted as $file => $entries) {
        foreach ($entries as $value => $entry) {
            $reason_ok = is_array($entry) && is_int($entry[0]) && $entry[0] > 0 && is_string($entry[1]) && trim($entry[1]) !== '';
            if (!$reason_ok || tic_classify($value, '', array()) !== null) {
                $bad_entries[] = $file . ' ' . $value;
            }
        }
    }
    tic_assert($bad_entries === array(), 'every allowlist entry has a positive count and a reason, and is not also classified elsewhere' . ($bad_entries ? ': ' . implode(', ', $bad_entries) : ''));
    $bad_categories = array_filter($gateway_categories, function ($reason) {
        return !is_string($reason) || trim($reason) === '';
    });
    tic_assert($bad_categories === array(), 'every gateway-site category states its Task 2 action');

    // --- Self-tests: classification, extraction and comparison fail closed. ---

    tic_assert(tic_classify('_upay_customer_token_v2_b', 'src/X.php', $not_persisted) === 'user_meta_prefixes', 'a user-meta prefix literal equal to the registered prefix is classified');
    tic_assert(tic_classify('upay_pm_v3_', 'src/X.php', $not_persisted) === 'ephemeral_prefixes', 'an ephemeral prefix literal equal to the registered prefix is classified');
    tic_assert(
        tic_classify('upay_pm_foo', 'src/X.php', $not_persisted) === null
        && tic_classify('upay_ctk_foo', 'src/X.php', $not_persisted) === null
        && tic_classify('_upay_customer_token_v2_b_x', 'src/X.php', $not_persisted) === null,
        'a longer literal under a registered prefix is a new name, not silently ephemeral'
    );
    tic_assert(
        tic_classify('upay_', 'src/Account/SubscriptionActions.php', $not_persisted) === 'not_persisted'
        && tic_classify('upay_', 'src/Payment/OrderLock.php', $not_persisted) === null,
        'allowlist entries apply only in their own file'
    );
    tic_assert(
        tic_classify('external_updates-upayments-V9.9.9', 'src/X.php', $not_persisted) === 'official_only.option_prefixes'
        && tic_classify('woocommerce_upayments_api_key', 'src/X.php', $not_persisted) === 'gateway_identity_site',
        'official update-checker names match by prefix; gateway-derived names belong to rule 2'
    );

    $probe = "<?php\n\$a = '_upay_brand_new_key';\nconst X = \"UPayments_NewField\";\n\$b = \"upayments_new_{\$id}\";\n\$c = 'UPayments_PaymentID';\n\$d = 'not_legacy';\n"
        . "\$e = 'upayments'; \$f = \"x {\$g} payment_method_upayments\"; \$h = 'upayments_token_identity_secret_v2';\n?>\n<input value=\"upayments\" class=\"x-upayments-button\"/>\n";
    $probe_found = tic_php_occurrences($probe);
    $probe_unclassified = array();
    foreach ($probe_found['legacy'] as $literal) {
        if (tic_classify($literal[0], 'src/Probe.php', $not_persisted) === null) {
            $probe_unclassified[] = $literal[0] . '@' . $literal[1];
        }
    }
    tic_assert(
        $probe_unclassified === array('_upay_brand_new_key@2', 'UPayments_NewField@3', 'upayments_new_@4'),
        'rule 1 reports unclassified inline, constant and interpolated literals with their lines (' . implode(', ', $probe_unclassified) . ')'
    );
    $probe_sites = array_map(function ($site) {
        return $site[0] . '@' . $site[1];
    }, $probe_found['gateway']);
    tic_assert(
        $probe_sites === array('upayments@7', 'payment_method_upayments@7', 'upayments@9'),
        'rule 2 sees exact and derived identities in literals, interpolations and inline HTML, not prefixed names or class fragments (' . implode(', ', $probe_sites) . ')'
    );
    $js_sites = array_map(function ($site) {
        return $site[0] . '@' . $site[1];
    }, tic_js_occurrences("const a = 'upayments';\n$('#woocommerce_upayments_api_key');\nimport x from './upayments-block';\n"));
    tic_assert($js_sites === array('upayments@1', 'woocommerce_upayments_api_key@2'), 'rule 2 scans JS selectors and identifiers but not file names (' . implode(', ', $js_sites) . ')');

    $fake_allow = array('src/A.php' => array('upay_' => array(2, 'reason'), 'upay_gone' => array(1, 'reason')));
    tic_assert(
        tic_allowlist_problems($fake_allow, array('src/A.php' => array('upay_' => 3))) === array('src/A.php upay_: allowlisted 2, found 3', 'src/A.php upay_gone: allowlisted 1, found 0'),
        'an extra same-file occurrence and a stale allowlist entry both fail'
    );
    $fake_inventory = array('src/A.php' => array('upayments' => array('order_query')), 'src/B.php' => array('wc_upayments' => array('callback_route')));
    $fake_found = array('src/A.php' => array('upayments' => array(10, 20)), 'src/C.php' => array('upayments' => array(5)));
    tic_assert(
        tic_gateway_problems($fake_inventory, $fake_found, $gateway_categories) === array(
            'NEW GATEWAY SITE: src/A.php:10,20 upayments (inventoried 1, found 2)',
            'NEW GATEWAY SITE: src/C.php:5 upayments (inventoried 0, found 1)',
            'MISSING GATEWAY SITE: src/B.php wc_upayments (inventoried 1, found 0)',
        ),
        'a new gateway site and a disappeared one both fail, naming file:line'
    );
    tic_assert(
        tic_gateway_problems(array('src/A.php' => array('upayments' => array('nonsense'))), array('src/A.php' => array('upayments' => array(1))), $gateway_categories) === array('UNKNOWN CATEGORY: src/A.php upayments nonsense'),
        'an uncategorized gateway site fails'
    );

    // The real failing exit path: a child run over a tree with a new name and a new site.
    $fixture = rtrim(sys_get_temp_dir(), '/\\') . '/supcheckout-tic-' . getmypid() . '-' . bin2hex(random_bytes(4));
    @mkdir($fixture . '/src', 0700, true);
    file_put_contents($fixture . '/src/Probe.php', "<?php\n\$a = '_upay_brand_new_key';\n\$b = array('payment_method' => 'upayments');\n");
    $child_result = tic_run(array(PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', __FILE__, '--scan-root=' . $fixture));
    @unlink($fixture . '/src/Probe.php');
    @rmdir($fixture . '/src');
    @rmdir($fixture);
    $child_output = $child_result === null ? '' : str_replace("\r\n", "\n", $child_result['output']);
    $child_lines = explode("\n", rtrim($child_output));
    tic_assert(
        $child_result !== null && $child_result['status'] === 1
        && strpos($child_output, 'UNCLASSIFIED: src/Probe.php:2 _upay_brand_new_key') !== false
        && strpos($child_output, 'NEW GATEWAY SITE: src/Probe.php:3 upayments') !== false
        && end($child_lines) === 'HARNESS-COMPLETE: ' . basename(__FILE__),
        'the harness exits 1 on a new legacy name and a new gateway site, naming file:line (status ' . ($child_result === null ? 'n/a' : $child_result['status']) . ')'
    );
}

// --- The shipped tree. ---

$scan = tic_scan($scan_root, $not_persisted);
if (!$child) {
    tic_assert(in_array('supcheckout.php', $scan['files']['php'], true) && in_array('UPayments.php', $scan['files']['php'], true), 'bootstrap and transitional shim are scanned');
    tic_assert(in_array(TIC_REGISTRY, $scan['files']['php'], true) && count($scan['files']['php']) >= 60, 'src/ and templates/ are scanned (' . count($scan['files']['php']) . ' PHP files)');
    tic_assert(count($scan['files']['js']) >= 4, 'assets/js is scanned (' . count($scan['files']['js']) . ' JS files)');
}

foreach ($scan['unclassified'] as $entry) {
    echo "UNCLASSIFIED: {$entry}\n";
}
tic_assert($scan['unclassified'] === array(), 'rule 1: every legacy-looking literal in shipped PHP is classified' . ($scan['unclassified'] ? ' (' . count($scan['unclassified']) . ' unclassified)' : ''));

$allow_problems = tic_allowlist_problems($not_persisted, $scan['allowlisted']);
foreach ($allow_problems as $problem) {
    echo "ALLOWLIST: {$problem}\n";
}
tic_assert($allow_problems === array(), 'rule 1: every file-keyed allowlist count matches the shipped code');

$gateway_problems = tic_gateway_problems($gateway_sites, $scan['gateway'], $gateway_categories);
foreach ($gateway_problems as $problem) {
    echo "{$problem}\n";
}
$site_count = 0;
foreach ($scan['gateway'] as $tokens) {
    foreach ($tokens as $lines) {
        $site_count += count($lines);
    }
}
tic_assert($gateway_problems === array(), "rule 2: all {$site_count} gateway-identity sites match the inventory");

echo "\nTakeover identity coverage: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
