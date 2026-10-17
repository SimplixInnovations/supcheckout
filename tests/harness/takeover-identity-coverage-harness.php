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
 * Rule 3 (order-ownership reads, shipped PHP). Rule 2 sees only literals, so a check
 * such as `$order->get_payment_method() === $this->id` would escape it and, after
 * cutover, reject historical `upayments` orders. Every `->get_payment_method()` call,
 * `->payment_method` read, `['payment_method']` read, `get_meta()`/`get_post_meta()` of
 * `payment_method`/`_payment_method`, and every `$gateway_id` or `->id` operand of a
 * comparison (`===`, `!==`, `==`, `!=`) or of `in_array()`/`array_search()` must sit
 * inside `Keys::is_our_gateway(...)`, inside a call whose own arguments include
 * `Keys::gateway_ids()`, or be listed in the file-keyed, count-exact $ownership_sites
 * inventory with a category. New, disappeared and uncategorized sites fail.
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
    'src/Takeover/Preflight.php' => array(
        'upay_subscriptions_table_present' => array(1, 'Preflight report code (plan Task 4 table) the 0c UI keys on; a report string, never a storage name'),
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
    'ownership_check' => 'order / selected / filtered gateway comparison; use Keys::is_our_gateway() (client scripts: the localized current id, Task 7)',
    'renewal_identity' => 'payment method written on a NEW renewal order; use the current gateway id',
    'session_choice' => 'WC session chosen_payment_method comparison; Keys::is_our_gateway() (stale sessions hold upayments for up to 48 h)',
    'default_gateway' => 'woocommerce_default_gateway value; the current gateway id',
    'extension_namespace' => 'Store API extensions namespace; PHP reads Keys::EXTENSION_NAMESPACE then _LEGACY, JS posts the current namespace from localized data (Task 7, with the gateway id)',
    'callback_route' => 'callback route / hook; new Charges use Keys::CALLBACK_ROUTE, the LegacyCallbackListener (Task 8) keeps wc_upayments forever',
    'settings_option' => 'gateway settings option read; Keys::settings_sources() precedence into woocommerce_supcheckout_settings',
    'compat_constant' => 'historical option-name constant kept for compatibility and its pins; the runtime read goes through Keys::get_settings()',
    'snapshot_hook' => 'hook name derived from the gateway id and frozen in the public-surface snapshot; derived from the current id at cutover (Task 7), when the gateway id and the snapshot change together',
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
    // Task 2 routed every order query (Keys::payment_method_query()), gateway lookup
    // (Keys::find_gateway()), ownership/session comparison (Keys::is_our_gateway()),
    // renewal identity and default gateway (Keys::gateway_id()), settings read
    // (Keys::get_settings()), Store API namespace read and new-Charge callback route
    // (Keys::callback_route()) through Keys, so those sites no longer hold the literal.
    // What remains is either permanent or derives from the gateway id itself, which
    // switches at cutover (Task 7): the gateway declarations, the Settings-API names and
    // classes, and the client scripts that post or compare that id.
    // Task 7 derived every WC_Settings_API name (GatewaySettings POST fields, row inputs and
    // admin selectors; the sanitized-fields hook) and the gateway / Blocks registration id
    // from the current gateway id (Keys::gateway_id()), so those literals are gone. The
    // client scripts keep only their pre-cutover defaults and the legacy lookup.
    'src/Blocks/PaymentMethodType.php' => array(
        'upayments' => array('gateway_declaration', 'logger_source'),
    ),
    'src/Gateway/Gateway.php' => array(
        'upayments' => array('legacy_text_domain', 'logger_source'),
    ),
    'src/Migration/MigrationSettings.php' => array('woocommerce_upayments_settings' => array('compat_constant')),
    'src/Payment/CheckoutPayload.php' => array('upayments' => array('payment_type_sentinel')),
    'src/Payment/PaymentLifecycle.php' => array(
        'woocommerce_api_wc_upayments' => array('callback_route'),
    ),
    'src/Payment/StatusVerifier.php' => array('upayments' => array('provider_host', 'provider_host')),
    'src/Provider/EndpointResolver.php' => array('upayments' => array('provider_host', 'provider_host')),
    'src/Release/Identity.php' => array(
        'upayments' => array('registry', 'registry'),
        'woocommerce_upayments_settings' => array('registry'),
        'wc_upayments' => array('registry'),
    ),
    'templates/new-design-form.php' => array('upayments' => array('payment_type_sentinel')),
    'assets/js/admin-settings.js' => array(
        'woocommerce_upayments_' => array('settings_field'),
    ),
    'assets/js/new-upay.js' => array(
        'upayments' => array('ownership_check'),
    ),
    'assets/js/upayments-block.js' => array('upayments' => array('gateway_declaration', 'gateway_declaration', 'extension_namespace')),
);

/**
 * Order-ownership read categories => the Task 2 action (or why the site stays).
 *
 * @var array<string, string>
 */
$ownership_categories = array(
    'ownership_compare_task2' => 'compares an order / gateway identity with one gateway id; Task 2 must make it Keys::is_our_gateway() so historical upayments orders still match',
    'captured_for_check' => 'reads the identity into a variable that a separately inventoried check compares; the read itself stays',
    'current_id_compare' => "compares a request or screen value with the gateway's own current id, not an order's; correct for either identity when the caller passes the current id",
    'display_only' => 'only shows the payment method; no ownership decision',
    'third_party_gateway' => 'intentionally compares with another gateway',
);

/**
 * Every order-ownership read: file => kind => one category per occurrence, in source order.
 *
 * @var array<string, array<string, list<string>>>
 */
$ownership_sites = array(
    // Task 2 converted every ownership_compare_task2 site to Keys::is_our_gateway()
    // (SubscriptionActions, Gateway::admin_order_details() and the icon filter,
    // OrderPresentation, PaymentLifecycle x2, PublicOrderStatus, Scheduler,
    // HistoricalEnrollment); the harness exempts reads inside those calls.
    'src/Admin/GatewaySettings.php' => array('$gateway_id' => array('current_id_compare')),
    'src/Subscription/Checkout/Fields.php' => array("['payment_method']" => array_fill(0, 3, 'captured_for_check')),
    'src/Subscription/Cron/Scheduler.php' => array('get_payment_method()' => array('captured_for_check')),
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
 * True when the token is the class name `Keys` (bare or namespace-qualified).
 *
 * @param mixed $token Token.
 * @return bool
 */
function tic_is_keys_name($token) {
    if (!is_array($token)) {
        return false;
    }
    $names = array(T_STRING);
    foreach (array('T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED') as $name) {
        if (defined($name)) {
            $names[] = constant($name);
        }
    }
    return in_array($token[0], $names, true) && ($token[1] === 'Keys' || substr($token[1], -5) === '\\Keys');
}

/**
 * Index of the token that closes the group opened at $open, or null.
 *
 * @param array<int, mixed> $tokens Significant tokens.
 * @return int|null
 */
function tic_group_close(array $tokens, $open) {
    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i] === '(') {
            $depth++;
        } elseif ($tokens[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

/**
 * True when token $k is inside Keys::is_our_gateway(...), or inside a call whose own
 * argument list contains Keys::gateway_ids at its top level.
 *
 * @param array<int, mixed> $tokens Significant tokens.
 * @return bool
 */
function tic_is_keys_wrapped(array $tokens, $k) {
    $innermost = true;
    for ($open = $k - 1; $open >= 0; $open--) {
        if ($tokens[$open] !== '(') {
            continue;
        }
        $close = tic_group_close($tokens, $open);
        if ($close === null || $close < $k) {
            continue;
        }
        $callee = $open >= 1 && is_array($tokens[$open - 1]) && $tokens[$open - 1][0] === T_STRING ? $tokens[$open - 1][1] : null;
        if ($callee === 'is_our_gateway' && $open >= 3 && is_array($tokens[$open - 2]) && $tokens[$open - 2][0] === T_DOUBLE_COLON && tic_is_keys_name($tokens[$open - 3])) {
            return true;
        }
        if ($innermost) {
            $innermost = false;
            $depth = 0;
            for ($i = $open + 1; $i < $close; $i++) {
                if ($tokens[$i] === '(') {
                    $depth++;
                } elseif ($tokens[$i] === ')') {
                    $depth--;
                } elseif ($depth === 0 && tic_is_keys_name($tokens[$i]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($tokens[$i + 2]) && $tokens[$i + 2][1] === 'gateway_ids'
                ) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * True when the expression spanning significant tokens [$start, $end] is an operand of
 * a comparison or the first argument of in_array()/array_search().
 *
 * @param array<int, mixed> $tokens Significant tokens.
 * @return bool
 */
function tic_is_compared(array $tokens, $start, $end) {
    $comparisons = array(T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL);
    $after = $end + 1;
    while (isset($tokens[$after]) && $tokens[$after] === ')') {
        $after++;
    }
    if (isset($tokens[$after]) && is_array($tokens[$after]) && in_array($tokens[$after][0], $comparisons, true)) {
        return true;
    }
    $before = $start - 1;
    while ($before >= 0 && ($tokens[$before] === '(' || (is_array($tokens[$before]) && $tokens[$before][0] === T_STRING_CAST))) {
        if ($tokens[$before] === '(' && $before >= 1 && is_array($tokens[$before - 1]) && $tokens[$before - 1][0] === T_STRING
            && in_array(strtolower($tokens[$before - 1][1]), array('in_array', 'array_search'), true)
        ) {
            return true;
        }
        $before--;
    }
    return $before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], $comparisons, true);
}

/**
 * Rule 3 occurrences in one PHP source that are not wrapped by Keys.
 *
 * @param string $source PHP source.
 * @return list<array{0: string, 1: int}> [kind, line].
 */
function tic_ownership_occurrences($source) {
    $tokens = array_values(array_filter(token_get_all($source), function ($token) {
        return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
    }));
    $object_operators = array(T_OBJECT_OPERATOR);
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
        $object_operators[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
    }
    $found = array();
    $count = count($tokens);
    for ($k = 0; $k < $count; $k++) {
        $token = $tokens[$k];
        if (!is_array($token)) {
            continue;
        }
        $next = isset($tokens[$k + 1]) ? $tokens[$k + 1] : null;
        $after_object = $k >= 1 && is_array($tokens[$k - 1]) && in_array($tokens[$k - 1][0], $object_operators, true);
        $kind = null;
        if ($token[0] === T_STRING && $after_object && $token[1] === 'get_payment_method' && $next === '(') {
            $kind = 'get_payment_method()';
        } elseif ($token[0] === T_STRING && $after_object && $token[1] === 'payment_method' && $next !== '(') {
            $kind = '->payment_method';
        } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(substr($token[1], 1, -1), array('payment_method', '_payment_method'), true)) {
            $value = substr($token[1], 1, -1);
            if ($k >= 1 && $tokens[$k - 1] === '[' && $next === ']') {
                $kind = "['payment_method']";
            } elseif ($k >= 2 && $tokens[$k - 1] === '(' && is_array($tokens[$k - 2]) && in_array($tokens[$k - 2][1], array('get_meta', 'get_post_meta'), true)) {
                $kind = "get_meta('{$value}')";
            } elseif ($k >= 4 && $tokens[$k - 1] === ',' && $tokens[$k - 3] === '(' && is_array($tokens[$k - 4]) && $tokens[$k - 4][1] === 'get_post_meta') {
                $kind = "get_meta('{$value}')";
            } elseif ($value === '_payment_method') {
                $kind = "'_payment_method'";
            }
        } elseif ($token[0] === T_VARIABLE && $token[1] === '$gateway_id' && tic_is_compared($tokens, $k, $k)) {
            $kind = '$gateway_id';
        } elseif ($token[0] === T_STRING && $after_object && $token[1] === 'id' && $next !== '(') {
            $start = $k - 1;
            while ($start >= 1 && is_array($tokens[$start - 1]) && (in_array($tokens[$start - 1][0], array(T_VARIABLE, T_STRING), true) || in_array($tokens[$start - 1][0], $object_operators, true))) {
                $start--;
            }
            if (tic_is_compared($tokens, $start, $k)) {
                $kind = '->id';
            }
        }
        if ($kind !== null && !tic_is_keys_wrapped($tokens, $k)) {
            $found[] = array($kind, $token[2]);
        }
    }
    return $found;
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
    $ownership = array();
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
        if (substr($relative, -4) === '.php') {
            foreach (tic_ownership_occurrences($source) as $site) {
                $ownership[$relative][$site[0]][] = $site[1];
            }
        }
    }
    return array('files' => $files, 'unclassified' => $unclassified, 'allowlisted' => $allowlisted, 'gateway' => $gateway, 'ownership' => $ownership);
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

/**
 * New, disappeared or miscategorized inventoried sites (rules 2 and 3).
 *
 * @param string $label Site label in messages.
 * @return list<string>
 */
function tic_gateway_problems(array $inventory, array $found, array $categories, $label = 'GATEWAY SITE') {
    $problems = array();
    foreach ($found as $file => $tokens) {
        foreach ($tokens as $token => $lines) {
            $listed = isset($inventory[$file][$token]) ? count($inventory[$file][$token]) : 0;
            if (count($lines) > $listed) {
                $problems[] = "NEW {$label}: {$file}:" . implode(',', $lines) . " {$token} (inventoried {$listed}, found " . count($lines) . ')';
            }
        }
    }
    foreach ($inventory as $file => $tokens) {
        foreach ($tokens as $token => $sites) {
            $seen = isset($found[$file][$token]) ? count($found[$file][$token]) : 0;
            if ($seen < count($sites)) {
                $problems[] = "MISSING {$label}: {$file} {$token} (inventoried " . count($sites) . ", found {$seen})";
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

    $bad_ownership_categories = array_filter($ownership_categories, function ($reason) {
        return !is_string($reason) || trim($reason) === '';
    });
    tic_assert($bad_ownership_categories === array() && isset($ownership_categories['ownership_compare_task2']), 'every ownership category states its Task 2 action');
    $ownership_probe = "<?php\n"
        . "if (\$order->get_payment_method() === \$this->id) {}\n"                                       // 2: call + ->id
        . "if ((string) \$order->get_payment_method() !== (string) \$gateway_id) {}\n"                    // 3: call + $gateway_id
        . "\$m = \$order->payment_method; \$p = \$_POST['payment_method'];\n"                             // 4: property + subscript
        . "\$x = get_post_meta(\$id, '_payment_method', true); \$y = \$order->get_meta('payment_method');\n" // 5: two meta reads
        . "if (in_array(\$gateway->id, \$list, true)) {}\n"                                                // 6: in_array ->id
        . "if (Keys::is_our_gateway(\$order->get_payment_method())) {}\n"                                 // 7: wrapped
        . "if (\\Simplixi\\SUPCheckout\\Takeover\\Keys::is_our_gateway(strtolower(\$o->get_payment_method()))) {}\n" // 8: wrapped, nested
        . "if (in_array(\$order->get_payment_method(), Keys::gateway_ids(), true)) {}\n"                  // 9: wrapped by gateway_ids
        . "\$this->id = 'x'; \$hook = 'h_' . \$this->id; function f(\$gateway_id) { echo \$gateway_id; }\n" // 10: not comparisons
        . "if (\$a === \$b) { \$c = foo(\$order->get_payment_method(), Keys::gateway_ids()); }\n";        // 11: gateway_ids at top level of the same call
    $ownership_found = array_map(function ($site) {
        return $site[0] . '@' . $site[1];
    }, tic_ownership_occurrences($ownership_probe));
    tic_assert(
        $ownership_found === array(
            'get_payment_method()@2', '->id@2',
            'get_payment_method()@3', '$gateway_id@3',
            '->payment_method@4', "['payment_method']@4",
            "get_meta('_payment_method')@5", "get_meta('payment_method')@5",
            '->id@6',
        ),
        'rule 3 finds every ownership read and comparison operand, and exempts Keys-wrapped reads, assignments, hook names and parameters (' . implode(', ', $ownership_found) . ')'
    );
    tic_assert(
        tic_gateway_problems(
            array('src/A.php' => array('get_payment_method()' => array('ownership_compare_task2')), 'src/B.php' => array('->id' => array('ownership_compare_task2'))),
            array('src/A.php' => array('get_payment_method()' => array(3, 9))),
            $ownership_categories,
            'OWNERSHIP SITE'
        ) === array(
            'NEW OWNERSHIP SITE: src/A.php:3,9 get_payment_method() (inventoried 1, found 2)',
            'MISSING OWNERSHIP SITE: src/B.php ->id (inventoried 1, found 0)',
        ),
        'a new ownership site and a disappeared one both fail, naming file:line'
    );
    tic_assert(
        tic_gateway_problems(array('src/A.php' => array('->id' => array('gateway_lookup'))), array('src/A.php' => array('->id' => array(1))), $ownership_categories, 'OWNERSHIP SITE') === array('UNKNOWN CATEGORY: src/A.php ->id gateway_lookup'),
        'an uncategorized ownership site fails'
    );

    // The real failing exit path: a child run over a tree with a new name and new sites.
    $fixture = rtrim(sys_get_temp_dir(), '/\\') . '/supcheckout-tic-' . getmypid() . '-' . bin2hex(random_bytes(4));
    @mkdir($fixture . '/src', 0700, true);
    file_put_contents($fixture . '/src/Probe.php', "<?php\n\$a = '_upay_brand_new_key';\n\$b = array('payment_method' => 'upayments');\nif (\$order->get_payment_method() === \$foo) {}\n");
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
        && strpos($child_output, 'NEW OWNERSHIP SITE: src/Probe.php:4 get_payment_method()') !== false
        && end($child_lines) === 'HARNESS-COMPLETE: ' . basename(__FILE__),
        'the harness exits 1 on a new legacy name, gateway site and ownership site, naming file:line (status ' . ($child_result === null ? 'n/a' : $child_result['status']) . ')'
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

$ownership_problems = tic_gateway_problems($ownership_sites, $scan['ownership'], $ownership_categories, 'OWNERSHIP SITE');
foreach ($ownership_problems as $problem) {
    echo "{$problem}\n";
}
$ownership_count = 0;
foreach ($scan['ownership'] as $kinds) {
    foreach ($kinds as $lines) {
        $ownership_count += count($lines);
    }
}
tic_assert($ownership_problems === array(), "rule 3: all {$ownership_count} order-ownership reads match the inventory");

echo "\nTakeover identity coverage: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
