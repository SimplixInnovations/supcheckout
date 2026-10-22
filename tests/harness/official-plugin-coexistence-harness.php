<?php
/**
 * Official UPayments plugin coexistence.
 *
 * The official UPayments plugin declares the same global functions and classes as
 * SUPCheckout (woocommerceUpaymentsInit(), WC_Upayments, ...). If both are active,
 * whichever main file loads second dies with "Cannot redeclare". SUPCheckout must
 * pause itself, declaring nothing global, while another plugin whose main file is
 * UPayments.php is active, in either load order, and tell administrators why.
 *
 * Each scenario runs in a child PHP process so a real redeclaration fatal is
 * observed as a failed scenario instead of killing the harness.
 */

$root = dirname(__DIR__, 2);

if (isset($argv[1]) && $argv[1] === '--child') {
    coexistence_child($root, (string) $argv[2], (string) $argv[3]);
    exit(0);
}

$pass = 0;
$fail = 0;
function coexistence_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$label}\n";
}

function coexistence_run($root, $scenario, $official_stub) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child '
        . escapeshellarg($scenario) . ' ' . escapeshellarg($official_stub) . ' 2>&1';
    $output = array();
    $code = 0;
    exec($command, $output, $code);
    return array('code' => $code, 'out' => implode("\n", $output));
}

// Fake WP_PLUGIN_DIR: the official plugin's main file exists on disk, so only real
// installations count as conflicts (WordPress itself skips missing plugin files).
$plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'supcheckout-coexistence-plugins-' . getmypid();
@mkdir($plugins_dir . '/upayments', 0777, true);
file_put_contents($plugins_dir . '/upayments/UPayments.php', "<?php
");
// SUPCheckout's own transitional shim (Task 2b) is a real file inside its own directory.
@mkdir($plugins_dir . '/supcheckout', 0777, true);
file_put_contents($plugins_dir . '/supcheckout/UPayments.php', "<?php
");
putenv('SUPCHECKOUT_COEXISTENCE_PLUGIN_DIR=' . $plugins_dir);

// A stand-in for the official 3.1.1 plugin's global surface (Sub-project 0a Task 8): the
// path constants, the global bootstrap and product-data functions, the global gateway and
// Blocks classes, and the UPayments\Subscription classes from its includes/. The names are
// the ones SUPCheckout's pre-0a fork declared with the official plugin's naming
// (UPayments.php before 0a Task 2, includes/ before 0a Task 6); SUPCheckout declares none
// of them any more.
$official_stub = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'supcheckout-official-upayments-stub-' . getmypid() . '.php';
file_put_contents($official_stub, <<<'PHP'
<?php
namespace UPayments\Subscription {
    class Manager {}
}
namespace UPayments\Subscription\Checkout {
    class Fields {}
}
namespace UPayments\Subscription\Helpers {
    class Utils {}
}
namespace UPayments\Subscription\Cron {
    class Scheduler {}
}
namespace {
    define("UP_PLUGIN_URL", 'https://merchant.example.test/wp-content/plugins/upayments/');
    define("UP_PLUGIN_PATH", '/srv/www/wp-content/plugins/upayments/');
    define('UPAYMENTS_PLUGIN_FILE', '/srv/www/wp-content/plugins/upayments/UPayments.php');
    function woocommerceUpaymentsInit() {}
    function upaymentsMissingWcNotice() {}
    function addUpaymentsGatewayClass($methods) { return $methods; }
    function enableUpaymentsGateway($available_gateways) { return $available_gateways; }
    function addCustomProductType($types) { return $types; }
    function mapCustomProductClass($classname, $product_type) { return $classname; }
    function customProductTypes() {}
    function addCustomDataTab($tabs) { return $tabs; }
    function addCustomDataPanel() {}
    function saveCustomFieldData($post_id) {}
    function displayCustomFieldOnFrontend() {}
    function displayCustomDataInCart($item_data, $cart_item) { return $item_data; }
    function saveCustomDataToOrderItems($item, $cart_item_key, $values, $order) {}
    class WC_Upayments {}
    class WCGatewayUPaymentsBlocks {}
    echo "OFFICIAL_LOADED\n";
}
PHP
);

$alone = coexistence_run($root, 'alone', $official_stub);
coexistence_assert($alone['code'] === 0, 'SUPCheckout alone loads without error');
coexistence_assert(strpos($alone['out'], 'DECLARED:yes') !== false, 'SUPCheckout alone declares its gateway bootstrap');
coexistence_assert(strpos($alone['out'], 'NOTICES:0') !== false, 'SUPCheckout alone registers no conflict notice');
// Fix A1-01 review M3: the paused-renewal notice is counted on its own: one registration on
// a booted SUPCheckout, none while it is paused next to the official plugin.
coexistence_assert(strpos($alone['out'], 'PAUSED_RENEWAL_NOTICES:1') !== false, 'SUPCheckout alone registers the paused-renewal notice once');
// Hardening T2 (A5-01): the official-reactivation guard (two option filters and its notice
// on admin_notices and network_admin_notices) is a booted SUPCheckout's own and is counted
// on its own, never as a conflict notice; a paused SUPCheckout registers none of it.
coexistence_assert(strpos($alone['out'], 'REACTIVATION_GUARD_HOOKS:4') !== false, 'SUPCheckout alone registers the official-reactivation guard once');
foreach (array('stale', 'unrelated', 'shim') as $booted_scenario) {
    $booted = coexistence_run($root, $booted_scenario, $official_stub);
    coexistence_assert(strpos($booted['out'], 'PAUSED_RENEWAL_NOTICES:1') !== false, "booted ({$booted_scenario}): the paused-renewal notice is registered once");
    coexistence_assert(strpos($booted['out'], 'REACTIVATION_GUARD_HOOKS:4') !== false, "booted ({$booted_scenario}): the official-reactivation guard is registered once");
}

$official_first = coexistence_run($root, 'official_first', $official_stub);
coexistence_assert($official_first['code'] === 0, 'official plugin loaded first: SUPCheckout loads without a redeclaration fatal');
coexistence_assert(strpos($official_first['out'], 'UP_PLUGIN_URL:official') !== false, 'official plugin loaded first: its asset constants are untouched');
coexistence_assert(strpos($official_first['out'], 'SUPCHECKOUT_VERSION:undefined') !== false, 'official plugin loaded first: SUPCheckout stays paused');
coexistence_assert(strpos($official_first['out'], 'NOTICES:1') !== false, 'official plugin loaded first: one conflict notice is registered');

$ours_first = coexistence_run($root, 'ours_first', $official_stub);
coexistence_assert($ours_first['code'] === 0, 'SUPCheckout loaded first: the official plugin then loads without a redeclaration fatal');
coexistence_assert(strpos($ours_first['out'], 'DECLARED:no') !== false, 'SUPCheckout loaded first: it declares no global gateway bootstrap while paused');
coexistence_assert(strpos($ours_first['out'], 'OFFICIAL_LOADED') !== false, 'SUPCheckout loaded first: the official plugin keeps working');
coexistence_assert(strpos($ours_first['out'], 'NOTICES:1') !== false, 'SUPCheckout loaded first: one conflict notice is registered');

$notice_admin = coexistence_run($root, 'notice_admin', $official_stub);
coexistence_assert($notice_admin['code'] === 0, 'conflict notice renders without error');
coexistence_assert(strpos($notice_admin['out'], 'notice notice-warning') !== false, 'administrators see a warning notice');
coexistence_assert(strpos($notice_admin['out'], 'SUPCheckout is paused') !== false, 'notice says SUPCheckout is paused');
coexistence_assert(strpos($notice_admin['out'], 'Deactivate the UPayments plugin') !== false, 'notice tells the administrator how to switch');
coexistence_assert(strpos($notice_admin['out'], '<script') === false, 'notice output is escaped');

$notice_shopper = coexistence_run($root, 'notice_shopper', $official_stub);
coexistence_assert($notice_shopper['code'] === 0, 'conflict notice check runs for users without plugin rights');
coexistence_assert(strpos($notice_shopper['out'], 'notice notice-warning') === false, 'users who cannot manage plugins never see the notice');

$sitewide = coexistence_run($root, 'sitewide', $official_stub);
coexistence_assert($sitewide['code'] === 0 && strpos($sitewide['out'], 'DECLARED:no') !== false, 'a network-activated official plugin also pauses SUPCheckout');

$stale = coexistence_run($root, 'stale', $official_stub);
coexistence_assert(strpos($stale['out'], 'DECLARED:yes') !== false && strpos($stale['out'], 'NOTICES:0') !== false, 'a stale active_plugins entry whose UPayments.php file is gone never pauses SUPCheckout');

$stale_sitewide = coexistence_run($root, 'stale_sitewide', $official_stub);
coexistence_assert(strpos($stale_sitewide['out'], 'DECLARED:yes') !== false && strpos($stale_sitewide['out'], 'NOTICES:0') !== false, 'a stale network-activated entry whose file is gone never pauses SUPCheckout');

$traversal = coexistence_run($root, 'traversal', $official_stub);
coexistence_assert(strpos($traversal['out'], 'DECLARED:yes') !== false && strpos($traversal['out'], 'NOTICES:0') !== false, 'an invalid path-traversal entry never pauses SUPCheckout');

$unrelated = coexistence_run($root, 'unrelated', $official_stub);
coexistence_assert(strpos($unrelated['out'], 'DECLARED:yes') !== false && strpos($unrelated['out'], 'NOTICES:0') !== false, 'unrelated active plugins never pause SUPCheckout');

$own_entry = coexistence_run($root, 'own_pre_rename_entry', $official_stub);
coexistence_assert($own_entry['code'] === 0, 'own pre-rename entry: SUPCheckout loads without error');
coexistence_assert(strpos($own_entry['out'], 'DECLARED:yes') !== false && strpos($own_entry['out'], 'NOTICES:0') !== false, 'SUPCheckout\'s own supcheckout/UPayments.php shim entry never pauses SUPCheckout');

$shim = coexistence_run($root, 'shim', $official_stub);
coexistence_assert($shim['code'] === 0, 'loading through the shim: SUPCheckout loads without error');
coexistence_assert(strpos($shim['out'], 'DECLARED:yes') !== false && strpos($shim['out'], 'NOTICES:0') !== false, 'loading through the shim with the official plugin inactive is never a false pause');
coexistence_assert(strpos($shim['out'], 'ACTIVE:["supcheckout/supcheckout.php"]') !== false, 'loading through the shim moves the active entry to supcheckout/supcheckout.php');

$shim_official = coexistence_run($root, 'shim_official', $official_stub);
coexistence_assert($shim_official['code'] === 0 && strpos($shim_official['out'], 'DECLARED:no') !== false && strpos($shim_official['out'], 'NOTICES:1') !== false, 'loading through the shim while the official plugin is active still pauses SUPCheckout');
coexistence_assert(strpos($shim_official['out'], 'ACTIVE:["supcheckout/supcheckout.php","upayments/UPayments.php"]') !== false, 'the shim moves only SUPCheckout\'s entry; the official upayments/UPayments.php entry is untouched');

// Sub-project 0a Task 8: the official plugin's full global surface (constants, global
// functions and classes, UPayments\Subscription classes) next to SUPCheckout, in both
// load orders, while SUPCheckout is paused.
coexistence_assert(strpos($ours_first['out'], 'SUPCHECKOUT_VERSION:undefined') !== false, 'SUPCheckout loaded first: SUPCheckout stays paused');
$both_namespaces = coexistence_run($root, 'official_namespaces', $official_stub);
coexistence_assert($both_namespaces['code'] === 0, 'official UPayments\\Subscription classes never collide with SUPCheckout classes');
$both_namespaces_ours_first = coexistence_run($root, 'official_namespaces_ours_first', $official_stub);
coexistence_assert($both_namespaces_ours_first['code'] === 0, 'SUPCheckout loaded first: the official UPayments\\Subscription classes then load without a redeclaration fatal');
foreach (array(
    'official_first' => $official_first,
    'ours_first' => $ours_first,
    'official_namespaces' => $both_namespaces,
    'official_namespaces_ours_first' => $both_namespaces_ours_first,
) as $scenario => $result) {
    coexistence_assert(strpos($result['out'], 'OFFICIAL_LOADED') !== false, "paused ({$scenario}): the official plugin's global surface loads");
    coexistence_assert(strpos($result['out'], 'SUPCHECKOUT_VERSION:undefined') !== false && strpos($result['out'], 'NOTICES:1') !== false, "paused ({$scenario}): SUPCheckout stays paused with one conflict notice");
    coexistence_assert(strpos($result['out'], 'PAUSED_RENEWAL_NOTICES:0') !== false, "paused ({$scenario}): no paused-renewal notice is registered");
    coexistence_assert(strpos($result['out'], 'REACTIVATION_GUARD_HOOKS:0') !== false, "paused ({$scenario}): no official-reactivation guard is registered");
    coexistence_assert(strpos($result['out'], 'CLASSES:official=yes,ours=yes') !== false, "paused ({$scenario}): official UPayments\\Subscription and SUPCheckout Simplixi\\SUPCheckout\\Subscription classes resolve in one process");
    coexistence_assert(strpos($result['out'], 'UP_PLUGIN_URL:official') !== false && strpos($result['out'], 'UP_PLUGIN_PATH:official') !== false, "paused ({$scenario}): the official plugin's path constants are its own");
    coexistence_assert(strpos($result['out'], 'DIAGNOSTICS:[]') !== false, "paused ({$scenario}): no PHP warning, notice or deprecation");
}

// Characterization (not a support claim): the official plugin's files load while
// SUPCheckout is NOT paused, because no active entry ends in UPayments.php (for example a
// renamed or must-use copy, or the dual-load Sub-project 0b needs). With no shared global
// functions or classes left there is no redeclaration fatal in either order. Since 0b
// Task 2 SUPCheckout also shares no constant with the official plugin: its asset paths are
// SUPCHECKOUT_PLUGIN_URL / SUPCHECKOUT_PLUGIN_PATH and SUPCHECKOUT_PLUGIN_FILE replaces
// UPAYMENTS_PLUGIN_FILE, while the official plugin keeps UP_PLUGIN_URL, UP_PLUGIN_PATH and
// UPAYMENTS_PLUGIN_FILE (official 3.1.1 UPayments.php lines 21-23) in either load order.
foreach (array('both_running_official_first', 'both_running_ours_first') as $scenario) {
    $result = coexistence_run($root, $scenario, $official_stub);
    coexistence_assert($result['code'] === 0, "both running ({$scenario}): no redeclaration fatal");
    coexistence_assert(strpos($result['out'], 'OFFICIAL_LOADED') !== false && strpos($result['out'], 'SUPCHECKOUT_VERSION:defined') !== false, "both running ({$scenario}): both plugins load");
    coexistence_assert(strpos($result['out'], 'CLASSES:official=yes,ours=yes') !== false, "both running ({$scenario}): both class families resolve");
    coexistence_assert(
        strpos($result['out'], 'DIAGNOSTICS:[]') !== false && strpos($result['out'], 'already defined') === false,
        "both running ({$scenario}): no shared constants: nothing is defined twice"
    );
    coexistence_assert(
        strpos($result['out'], 'UP_PLUGIN_URL:official') !== false && strpos($result['out'], 'UP_PLUGIN_PATH:official') !== false
            && strpos($result['out'], 'UPAYMENTS_PLUGIN_FILE:official') !== false,
        "both running ({$scenario}): no shared constants: the official plugin keeps UP_PLUGIN_URL, UP_PLUGIN_PATH and UPAYMENTS_PLUGIN_FILE"
    );
    coexistence_assert(
        strpos($result['out'], 'SUPCHECKOUT_PLUGIN_URL:ours') !== false && strpos($result['out'], 'SUPCHECKOUT_PLUGIN_PATH:ours') !== false,
        "both running ({$scenario}): no shared constants: SUPCheckout's asset paths point at its own directory"
    );
}
$alone_constants = coexistence_run($root, 'alone', $official_stub);
coexistence_assert(
    strpos($alone_constants['out'], 'UP_PLUGIN_URL:undefined') !== false && strpos($alone_constants['out'], 'UP_PLUGIN_PATH:undefined') !== false
        && strpos($alone_constants['out'], 'UPAYMENTS_PLUGIN_FILE:undefined') !== false
        && strpos($alone_constants['out'], 'SUPCHECKOUT_PLUGIN_URL:ours') !== false,
    'SUPCheckout alone defines none of the official plugin\'s constants'
);
// The 'upayments' gateway identity is shared with the official plugin until 0b, so a
// paused SUPCheckout must register no gateway, availability or callback route hook, on
// either WC-API route (Plugin::boot() runs only after the pause early-return). A running
// one registers all four: both gateway filters, wc_upayments and, since 0b Task 8, supcheckout.
$all_gateway_hooks = 'GATEWAY_HOOKS:["woocommerce_api_supcheckout","woocommerce_api_wc_upayments","woocommerce_available_payment_gateways","woocommerce_payment_gateways"]';
foreach (array(
    'official_first' => $official_first,
    'ours_first' => $ours_first,
    'notice_admin' => $notice_admin,
    'notice_shopper' => $notice_shopper,
    'sitewide' => $sitewide,
    'shim_official' => $shim_official,
) as $scenario => $result) {
    coexistence_assert(
        strpos($result['out'], 'GATEWAY_HOOKS:[]') !== false,
        "paused ({$scenario}): SUPCheckout registers no woocommerce_payment_gateways, woocommerce_available_payment_gateways, woocommerce_api_wc_upayments or woocommerce_api_supcheckout hook"
    );
    coexistence_assert(
        strpos($result['out'], 'COPY_RUNNER_HOOKS:0') !== false,
        "paused ({$scenario}): even mid-takeover (copying), SUPCheckout registers neither the copy batch hook nor its watchdog"
    );
}
foreach (array(
    'alone' => $alone,
    'stale' => $stale,
    'stale_sitewide' => $stale_sitewide,
    'traversal' => $traversal,
    'unrelated' => $unrelated,
    'own_pre_rename_entry' => $own_entry,
    'shim' => $shim,
) as $scenario => $result) {
    coexistence_assert(
        strpos($result['out'], $all_gateway_hooks) !== false,
        "running ({$scenario}): SUPCheckout registers both gateway filters and both callback routes"
    );
    coexistence_assert(strpos($result['out'], 'COPY_RUNNER_HOOKS:2') !== false, "running ({$scenario}): copying registers the copy batch hook and its watchdog exactly once each");
    coexistence_assert(strpos($result['out'], 'DIAGNOSTICS:[]') !== false, "running ({$scenario}): no PHP warning, notice or deprecation");
}

// Sub-project 0b Task 9: the paused branch (PausedBoot) registers exactly the takeover CLI
// under WP-CLI and, on a Preflight probe request alone, the probe answer on the new route;
// nothing else, no global symbol, and only takeover classes load. It also registers the
// full boot's WooCommerce feature declarations (before_woocommerce_init, the same
// Bootstrap\WooCommerceDeclarations callback): without them WooCommerce lists the paused
// plugin as incompatible with HPOS and the Cart & Checkout blocks.
$paused_files = 'SRC_FILES:["Autoloader.php","Bootstrap/WooCommerceDeclarations.php","Gateway/OfficialPluginConflict.php","Takeover/Cli.php","Takeover/LegacyCallbackListener.php","Takeover/PausedBoot.php"]';
$paused_declarations = '"before_woocommerce_init=Simplixi\\\\SUPCheckout\\\\Bootstrap\\\\WooCommerceDeclarations::declare_compatibility"';
// 0c Task 8: the takeover REST routes, plus the two onboarding driver hooks: added at once
// under cron, AJAX and WP-CLI, and on every other request lazily on Action Scheduler's queue
// start (which also covers ALTERNATE_WP_CRON; Action Scheduler's admin "Run" row action calls
// process_action() directly and is not covered).
$paused_rest = '"rest_api_init=Simplixi\\\\SUPCheckout\\\\Rest\\\\Routes::register_takeover"';
$paused_lazy_driver = '"action_scheduler_before_process_queue=Simplixi\\\\SUPCheckout\\\\Takeover\\\\Onboarding\\\\AsyncDriver::register_hooks"';
$paused_driver = '"supcheckout_onboarding_drive=Simplixi\\\\SUPCheckout\\\\Takeover\\\\Onboarding\\\\AsyncDriver::handle","supcheckout_onboarding_watchdog=Simplixi\\\\SUPCheckout\\\\Takeover\\\\Onboarding\\\\AsyncDriver::watchdog"';
$paused_driver_files = 'SRC_FILES:["Autoloader.php","Bootstrap/WooCommerceDeclarations.php","Gateway/OfficialPluginConflict.php","Takeover/Cli.php","Takeover/LegacyCallbackListener.php","Takeover/Onboarding/AsyncDriver.php","Takeover/PausedBoot.php"]';
$cli_command = '"supcheckout takeover=Simplixi\\\\SUPCheckout\\\\Takeover\\\\Cli"';
foreach (array(
    'official_first' => $official_first,
    'ours_first' => $ours_first,
    'notice_admin' => $notice_admin,
    'notice_shopper' => $notice_shopper,
    'sitewide' => $sitewide,
    'shim_official' => $shim_official,
) as $scenario => $result) {
    coexistence_assert(strpos($result['out'], 'OTHER_HOOKS:[' . $paused_declarations . ',' . $paused_rest . ',' . $paused_lazy_driver . ']') !== false, "paused ({$scenario}): a web request registers nothing but the conflict notice, the WooCommerce feature declarations, the takeover REST routes and the lazy driver registration");
    coexistence_assert(strpos($result['out'], 'CLI_COMMANDS:[]') !== false, "paused ({$scenario}): no command outside WP-CLI");
    coexistence_assert(strpos($result['out'], 'GLOBALS_ADDED:[]') !== false, "paused ({$scenario}): no global function or class is declared");
}
coexistence_assert(strpos($official_first['out'], $paused_files) !== false, 'paused: only the autoloader, the conflict guard, the WooCommerce feature declarations and takeover classes load (the route callback and the driver are named by ::class, so neither loads)');
$paused_cli = coexistence_run($root, 'paused_cli', $official_stub);
coexistence_assert($paused_cli['code'] === 0 && strpos($paused_cli['out'], 'DIAGNOSTICS:[]') !== false, 'paused under WP-CLI: loads without error or diagnostic');
coexistence_assert(strpos($paused_cli['out'], 'CLI_COMMANDS:[' . $cli_command . ']') !== false, 'paused under WP-CLI: exactly the `wp supcheckout takeover` command is registered');
coexistence_assert(
    strpos($paused_cli['out'], 'OTHER_HOOKS:[' . $paused_declarations . ',' . $paused_rest . ',' . $paused_driver . ']') !== false && strpos($paused_cli['out'], 'GATEWAY_HOOKS:[]') !== false && strpos($paused_cli['out'], 'COPY_RUNNER_HOOKS:0') !== false,
    'paused under WP-CLI: no gateway, payment route, payment cron or other hook beyond the WooCommerce feature declarations, the takeover REST routes and the two onboarding driver hooks'
);
coexistence_assert(strpos($paused_cli['out'], 'GLOBALS_ADDED:[]') !== false && strpos($paused_cli['out'], 'SUPCHECKOUT_VERSION:undefined') !== false, 'paused under WP-CLI: no global symbol, the runtime stays unloaded');
coexistence_assert(strpos($paused_cli['out'], $paused_driver_files) !== false, 'paused under WP-CLI: only takeover classes load at boot (the driver adds Takeover/Onboarding/AsyncDriver.php)');
coexistence_assert(strpos($paused_cli['out'], 'OFFICIAL_LOADED') !== false, 'paused under WP-CLI: the official plugin keeps working');
$paused_cron = coexistence_run($root, 'paused_cron', $official_stub);
coexistence_assert($paused_cron['code'] === 0 && strpos($paused_cron['out'], 'DIAGNOSTICS:[]') !== false, 'paused cron request: loads without error or diagnostic');
coexistence_assert(
    strpos($paused_cron['out'], 'OTHER_HOOKS:[' . $paused_declarations . ',' . $paused_rest . ',' . $paused_driver . ']') !== false
        && strpos($paused_cron['out'], 'GATEWAY_HOOKS:[]') !== false && strpos($paused_cron['out'], 'COPY_RUNNER_HOOKS:0') !== false && strpos($paused_cron['out'], 'CLI_COMMANDS:[]') !== false,
    'paused cron request: the same hooks as under WP-CLI (no command), nothing else'
);
coexistence_assert(strpos($paused_cron['out'], $paused_driver_files) !== false && strpos($paused_cron['out'], 'GLOBALS_ADDED:[]') !== false, 'paused cron request: only takeover classes load, no global symbol');
// 0c Task 8: firing rest_api_init registers exactly the eight takeover endpoints and still
// no gateway, WC-API route, copy hook or global symbol.
$paused_rest_init = coexistence_run($root, 'paused_rest_init', $official_stub);
coexistence_assert($paused_rest_init['code'] === 0 && strpos($paused_rest_init['out'], 'DIAGNOSTICS:[]') !== false, 'paused rest_api_init: loads without error or diagnostic');
coexistence_assert(
    strpos($paused_rest_init['out'], 'REST_ENDPOINTS:8') !== false
        && strpos($paused_rest_init['out'], 'REST_ROUTES:["supcheckout/v1/onboarding/status","supcheckout/v1/onboarding/preflight","supcheckout/v1/onboarding/switch","supcheckout/v1/onboarding/rollback","supcheckout/v1/onboarding/abort","supcheckout/v1/onboarding/finalize","supcheckout/v1/onboarding/stale-claims/release"]') !== false,
    'paused rest_api_init: exactly the eight takeover endpoints, never the self-test or setup-check routes'
);
coexistence_assert(
    strpos($paused_rest_init['out'], 'GATEWAY_HOOKS:[]') !== false && strpos($paused_rest_init['out'], 'woocommerce_api_') === false && strpos($paused_rest_init['out'], 'COPY_RUNNER_HOOKS:0') !== false
        && strpos($paused_rest_init['out'], 'GLOBALS_ADDED:[]') !== false,
    'paused rest_api_init: no gateway hook, no woocommerce_api_* route, no copy hook and no global symbol'
);
$paused_probe = coexistence_run($root, 'paused_probe', $official_stub);
coexistence_assert(
    $paused_probe['code'] === 0
        && strpos($paused_probe['out'], 'OTHER_HOOKS:[' . $paused_declarations . ',' . $paused_rest . ',' . $paused_lazy_driver . ',"woocommerce_api_supcheckout=Simplixi\\\\SUPCheckout\\\\Takeover\\\\LegacyCallbackListener::handle_probe"]') !== false,
    'paused probe request: only the WooCommerce feature declarations, the takeover REST routes, the lazy driver registration and the probe answer on the new, unshared route (never woocommerce_api_wc_upayments, never the lifecycle)'
);
coexistence_assert(strpos($paused_probe['out'], 'GLOBALS_ADDED:[]') !== false && strpos($paused_probe['out'], 'CLI_COMMANDS:[]') !== false, 'paused probe request: no global symbol, no command');
$paused_environment = coexistence_run($root, 'paused_cli_environment', $official_stub);
coexistence_assert($paused_environment['code'] === 0 && strpos($paused_environment['out'], 'DIAGNOSTICS:[]') !== false, 'paused CLI reading its install directory: no error or diagnostic');
coexistence_assert(strpos($paused_environment['out'], 'OWN_DIR:supcheckout-main') !== false, 'paused CLI: the own install directory comes from plugin_basename(), whatever the directory is named');
coexistence_assert(
    strpos($paused_environment['out'], 'OTHER_HOOKS:[' . $paused_declarations . ',' . $paused_rest . ',' . $paused_driver . ']') !== false && strpos($paused_environment['out'], 'GATEWAY_HOOKS:[]') !== false,
    'paused CLI reading its install directory: no woocommerce_api_wc_upayments, reconcile cron or other hook beyond the WooCommerce feature declarations, the takeover REST routes and the driver hooks is registered (Release/Identity.php never loads)'
);
coexistence_assert(strpos($paused_environment['out'], 'Release/Identity.php') === false && strpos($paused_environment['out'], 'Payment/PaymentLifecycle.php') === false, 'paused CLI: neither Identity.php nor PaymentLifecycle.php is loaded');
$alone_cli = coexistence_run($root, 'alone_cli', $official_stub);
coexistence_assert(strpos($alone_cli['out'], $cli_command) !== false && strpos($alone_cli['out'], 'SUPCHECKOUT_VERSION:defined') !== false, 'running under WP-CLI: the full boot registers `wp supcheckout takeover` too');

@unlink($official_stub);
@unlink($plugins_dir . '/upayments/UPayments.php');
@rmdir($plugins_dir . '/upayments');
@unlink($plugins_dir . '/supcheckout/UPayments.php');
@rmdir($plugins_dir . '/supcheckout');
@rmdir($plugins_dir);

echo "\nOfficial plugin coexistence: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);

/**
 * Child process: minimal WordPress surface, then load the plugin main file(s).
 */
function coexistence_child($root, $scenario, $official_stub) {
    // Every PHP diagnostic (warning, notice, deprecation) is recorded and reported, so a
    // scenario cannot pass while PHP complains about a redefined constant or symbol.
    $GLOBALS['coexistence_diagnostics'] = array();
    set_error_handler(function ($errno, $errstr) {
        $GLOBALS['coexistence_diagnostics'][] = $errstr;
        return true;
    });
    define('ABSPATH', __DIR__ . '/');
    define('WP_PLUGIN_DIR', (string) getenv('SUPCHECKOUT_COEXISTENCE_PLUGIN_DIR'));
    $own = 'supcheckout/supcheckout.php';
    $active = array($own);
    $sitewide = array();
    $official_loads_first = in_array($scenario, array('official_first', 'official_namespaces', 'both_running_official_first', 'paused_cli', 'paused_cron', 'paused_rest_init', 'paused_probe', 'paused_cli_environment'), true);
    $official_loads_second = in_array($scenario, array('ours_first', 'official_namespaces_ours_first', 'both_running_ours_first'), true);
    if (in_array($scenario, array('official_first', 'ours_first', 'notice_admin', 'notice_shopper', 'official_namespaces', 'official_namespaces_ours_first', 'paused_cli', 'paused_cron', 'paused_rest_init', 'paused_probe', 'paused_cli_environment'), true)) {
        $active = array($own, 'upayments/UPayments.php');
    } elseif ($scenario === 'sitewide') {
        $sitewide = array('upayments/UPayments.php' => time());
    } elseif ($scenario === 'stale') {
        $active = array($own, 'removed-upayments/UPayments.php');
    } elseif ($scenario === 'stale_sitewide') {
        $sitewide = array('removed-upayments/UPayments.php' => time());
    } elseif ($scenario === 'traversal') {
        $active = array($own, '../upayments/UPayments.php');
    } elseif ($scenario === 'unrelated') {
        $active = array($own, 'woocommerce/woocommerce.php', 'funnel-builder/funnel-builder.php');
    } elseif ($scenario === 'own_pre_rename_entry' || $scenario === 'shim') {
        $active = array('supcheckout/UPayments.php');
    } elseif ($scenario === 'shim_official') {
        $active = array('supcheckout/UPayments.php', 'upayments/UPayments.php');
    }
    $GLOBALS['coexistence'] = array(
        'own' => $own,
        // Every scenario boots mid-takeover (copying): the one state that registers the
        // copy runner's Action Scheduler hook, so a paused boot proves it never does.
        'options' => array(
            'active_plugins' => $active,
            'supcheckout_takeover_state_v1' => json_encode(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array())),
        ),
        'site_options' => array('active_sitewide_plugins' => $sitewide),
        'multisite' => $scenario === 'sitewide' || $scenario === 'stale_sitewide',
        'can_manage' => $scenario !== 'notice_shopper',
        'actions' => array(),
        'filters' => array(),
    );

    coexistence_define_wordpress_stubs($root);
    // Sub-project 0b Task 9: WP-CLI (records commands) and the Preflight probe request.
    $GLOBALS['coexistence']['cli_commands'] = array();
    if ($scenario === 'paused_cli_environment') {
        $GLOBALS['coexistence']['install_dir'] = 'supcheckout-main';
    }
    if ($scenario === 'paused_cli' || $scenario === 'alone_cli' || $scenario === 'paused_cli_environment') {
        define('WP_CLI', true);
        eval('final class WP_CLI { public static function add_command($name, $callable, $args = array()) { $GLOBALS["coexistence"]["cli_commands"][] = array((string) $name, $callable); return true; } }');
    }
    if ($scenario === 'paused_cron') {
        define('DOING_CRON', true);
    }
    if ($scenario === 'paused_probe') {
        $_GET = array('wc-api' => 'supcheckout', 'supcheckout_probe' => '1');
    }
    if ($official_loads_first) {
        require $official_stub;
    }
    // Global symbols right before the main file loads, so the paused branch can be shown
    // to declare nothing global.
    $functions_before = get_defined_functions()['user'];
    $classes_before = array_merge(get_declared_classes(), get_declared_interfaces());
    if ($scenario === 'shim' || $scenario === 'shim_official') {
        require $root . '/UPayments.php';
    } else {
        require $root . '/supcheckout.php';
    }
    // 0c Task 8: fire `rest_api_init` the way WordPress does on a REST request, so the routes
    // the paused boot registered are the ones observed.
    $GLOBALS['coexistence']['rest_routes'] = array();
    if ($scenario === 'paused_rest_init') {
        foreach ($GLOBALS['coexistence']['actions'] as $registration) {
            if ($registration[0] === 'rest_api_init') {
                call_user_func($registration[1]);
            }
        }
    }
    // 0b Task 9 review: the paused CLI reads its own install directory for Preflight
    // (Environment::from_wordpress()). That must load nothing that registers payment hooks
    // and must follow the real install directory, here not named `supcheckout`.
    if ($scenario === 'paused_cli_environment') {
        $own_dir = new ReflectionMethod('Simplixi\SUPCheckout\Takeover\Environment', 'own_plugin_dir_from_wordpress');
        if (PHP_VERSION_ID < 80100) {
            $own_dir->setAccessible(true);
        }
        echo 'OWN_DIR:' . $own_dir->invoke(null) . "\n";
    }
    // Observed before any official stub loads, so only SUPCheckout's own declarations count.
    // The bootstrap is the plugins_loaded requirement check Plugin::boot() registers
    // (Bootstrap\Requirements::check_woocommerce, was the global woocommerceUpaymentsInit()).
    $declared = false;
    foreach ($GLOBALS['coexistence']['actions'] as $registration) {
        if ($registration[0] === 'plugins_loaded'
            && $registration[1] === array('Simplixi\SUPCheckout\Bootstrap\Requirements', 'check_woocommerce')
        ) {
            $declared = true;
        }
    }
    // Gateway-identity hooks SUPCheckout itself registered, also observed before any
    // official stub loads: a paused SUPCheckout must register none of them.
    $gateway_hooks = array();
    foreach (array_merge($GLOBALS['coexistence']['actions'], $GLOBALS['coexistence']['filters']) as $registration) {
        if (in_array($registration[0], array('woocommerce_payment_gateways', 'woocommerce_available_payment_gateways', 'woocommerce_api_wc_upayments', 'woocommerce_api_supcheckout'), true)) {
            $gateway_hooks[] = (string) $registration[0];
        }
    }
    $gateway_hooks = array_values(array_unique($gateway_hooks));
    sort($gateway_hooks);
    $copy_runner_hooks = 0;
    foreach ($GLOBALS['coexistence']['actions'] as $registration) {
        if ($registration[0] === 'supcheckout_takeover_copy_batch'
            || ($registration[0] === 'action_scheduler_init' && $registration[1] === array('Simplixi\SUPCheckout\Takeover\CopyRunner', 'watchdog'))
        ) {
            $copy_runner_hooks++;
        }
    }
    $globals_added = array();
    foreach (array_diff(get_defined_functions()['user'], $functions_before) as $function) {
        $globals_added[] = 'function:' . $function;
    }
    foreach (array_diff(array_merge(get_declared_classes(), get_declared_interfaces()), $classes_before) as $class) {
        if (strpos($class, 'Simplixi\\SUPCheckout\\') !== 0 && strpos($class, 'UPayments\\') !== 0 && !in_array($class, array('WC_Upayments', 'WCGatewayUPaymentsBlocks'), true)) {
            $globals_added[] = 'class:' . $class;
        }
    }
    // SUPCheckout files loaded while the main file ran (relative to src/).
    $src_files = array();
    $src = str_replace('\\', '/', $root) . '/src/';
    foreach (get_included_files() as $file) {
        $file = str_replace('\\', '/', $file);
        if (strpos($file, $src) === 0) {
            $src_files[] = substr($file, strlen($src));
        }
    }
    sort($src_files);
    // Every hook SUPCheckout registered other than the paused conflict notice.
    $other_hooks = array();
    foreach (array_merge($GLOBALS['coexistence']['actions'], $GLOBALS['coexistence']['filters']) as $registration) {
        if ($registration[0] !== 'admin_notices') {
            $other_hooks[] = (string) $registration[0] . '=' . (is_array($registration[1]) && is_string($registration[1][0]) ? $registration[1][0] . '::' . $registration[1][1] : 'callable');
        }
    }
    $cli_commands = array();
    foreach ($GLOBALS['coexistence']['cli_commands'] as $command) {
        $cli_commands[] = $command[0] . '=' . (is_string($command[1]) ? $command[1] : 'callable');
    }
    if ($official_loads_second) {
        require $official_stub;
    }

    $notices = 0;
    $paused_renewal_notices = 0;
    $reactivation_guard_hooks = 0;
    foreach (array_merge($GLOBALS['coexistence']['actions'], $GLOBALS['coexistence']['filters']) as $registration) {
        if (is_array($registration[1]) && $registration[1][0] === 'Simplixi\\SUPCheckout\\Takeover\\ReactivationGuard') {
            $reactivation_guard_hooks++;
        }
    }
    foreach ($GLOBALS['coexistence']['actions'] as $action) {
        // The paused-renewal notice (fix A1-01) is a booted SUPCheckout's own store notice,
        // registered on every boot and rendered only when subscriptions are enabled; it is
        // counted on its own, never as a conflict notice.
        $paused_renewal_notice = is_array($action[1]) && $action[1] === array('Simplixi\\SUPCheckout\\Subscription\\Presentation', 'render_auto_renewal_paused_notice');
        if ($action[0] === 'admin_notices' && $paused_renewal_notice) {
            $paused_renewal_notices++;
        }
        $reactivation_notice = is_array($action[1]) && $action[1] === array('Simplixi\\SUPCheckout\\Takeover\\ReactivationGuard', 'render_notice');
        if ($action[0] === 'admin_notices' && !$paused_renewal_notice && !$reactivation_notice) {
            $notices++;
            if (strpos($scenario, 'notice_') === 0) {
                call_user_func($action[1]);
            }
        }
    }
    echo "\nNOTICES:{$notices}\n";
    echo "PAUSED_RENEWAL_NOTICES:{$paused_renewal_notices}\n";
    echo "REACTIVATION_GUARD_HOOKS:{$reactivation_guard_hooks}\n";
    echo 'DECLARED:' . ($declared ? 'yes' : 'no') . "\n";
    echo 'GATEWAY_HOOKS:' . json_encode($gateway_hooks) . "\n";
    echo 'COPY_RUNNER_HOOKS:' . $copy_runner_hooks . "\n";
    echo 'OTHER_HOOKS:' . json_encode($other_hooks, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'CLI_COMMANDS:' . json_encode($cli_commands, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'REST_ENDPOINTS:' . array_sum(array_column($GLOBALS['coexistence']['rest_routes'], 1)) . "\n";
    echo 'REST_ROUTES:' . json_encode(array_column($GLOBALS['coexistence']['rest_routes'], 0), JSON_UNESCAPED_SLASHES) . "\n";
    echo 'GLOBALS_ADDED:' . json_encode(array_values($globals_added), JSON_UNESCAPED_SLASHES) . "\n";
    echo 'SRC_FILES:' . json_encode($src_files, JSON_UNESCAPED_SLASHES) . "\n";
    foreach (array('UP_PLUGIN_URL', 'UP_PLUGIN_PATH', 'UPAYMENTS_PLUGIN_FILE', 'SUPCHECKOUT_PLUGIN_URL', 'SUPCHECKOUT_PLUGIN_PATH') as $constant) {
        $owner = !defined($constant) ? 'undefined' : (strpos((string) constant($constant), '/upayments/') !== false ? 'official' : 'ours');
        echo $constant . ':' . $owner . "\n";
    }
    echo 'SUPCHECKOUT_VERSION:' . (defined('SUPCHECKOUT_VERSION') ? 'defined' : 'undefined') . "\n";
    // Both class families resolve in one process: the official UPayments\Subscription
    // classes and SUPCheckout's own (autoloaded) Simplixi\SUPCheckout classes.
    echo 'CLASSES:official=' . (class_exists('UPayments\Subscription\Manager', false) ? 'yes' : 'no')
        . ',ours=' . (class_exists('Simplixi\SUPCheckout\Subscription\Helpers\Utils') && class_exists('Simplixi\SUPCheckout\Subscription\Checkout\Fields') ? 'yes' : 'no') . "\n";
    echo 'DIAGNOSTICS:' . json_encode($GLOBALS['coexistence_diagnostics'], JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACTIVE:' . json_encode(array_values((array) $GLOBALS['coexistence']['options']['active_plugins']), JSON_UNESCAPED_SLASHES) . "\n";
}

function coexistence_define_wordpress_stubs($root) {
    eval(<<<'PHP'
function plugin_dir_url($file) { return 'https://merchant.example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/'; }
function plugin_dir_path($file) { return dirname((string) $file) . DIRECTORY_SEPARATOR; }
function plugin_basename($file) { return (isset($GLOBALS['coexistence']['install_dir']) ? $GLOBALS['coexistence']['install_dir'] : 'supcheckout') . '/' . basename((string) $file); }
function update_option($name, $value, $autoload = null) { $GLOBALS['coexistence']['options'][$name] = $value; return true; }
function update_site_option($name, $value) { $GLOBALS['coexistence']['site_options'][$name] = $value; return true; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['coexistence']['options']) ? $GLOBALS['coexistence']['options'][$name] : $default; }
function get_site_option($name, $default = false) { return array_key_exists($name, $GLOBALS['coexistence']['site_options']) ? $GLOBALS['coexistence']['site_options'][$name] : $default; }
function is_multisite() { return $GLOBALS['coexistence']['multisite']; }
function current_user_can($capability) { return $GLOBALS['coexistence']['can_manage'] && $capability === 'activate_plugins'; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['coexistence']['actions'][] = array($hook, $callback); return true; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['coexistence']['filters'][] = array($hook, $callback); return true; }
function wp_doing_cron() { return defined('DOING_CRON') && DOING_CRON; }
function wp_doing_ajax() { return false; }
function register_rest_route($route_namespace, $route, $args = array(), $override = false) { $GLOBALS['coexistence']['rest_routes'][] = array((string) $route_namespace . (string) $route, count($args)); return true; }
function register_activation_hook($file, $callback) { return true; }
function register_deactivation_hook($file, $callback) { return true; }
function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($text) { return (string) $text; }
function is_admin() { return true; }
PHP
    );
}
