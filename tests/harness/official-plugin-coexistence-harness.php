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

// A minimal stand-in for the official 3.1.1 main file: same global names, same constants.
$official_stub = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'supcheckout-official-upayments-stub-' . getmypid() . '.php';
file_put_contents($official_stub, <<<'PHP'
<?php
define("UP_PLUGIN_URL", 'https://merchant.example.test/wp-content/plugins/upayments/');
function woocommerceUpaymentsInit() {}
function upaymentsMissingWcNotice() {}
function addUpaymentsGatewayClass($methods) { return $methods; }
echo "OFFICIAL_LOADED\n";
PHP
);

$alone = coexistence_run($root, 'alone', $official_stub);
coexistence_assert($alone['code'] === 0, 'SUPCheckout alone loads without error');
coexistence_assert(strpos($alone['out'], 'DECLARED:yes') !== false, 'SUPCheckout alone declares its gateway bootstrap');
coexistence_assert(strpos($alone['out'], 'NOTICES:0') !== false, 'SUPCheckout alone registers no conflict notice');

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

@unlink($official_stub);
@unlink($plugins_dir . '/upayments/UPayments.php');
@rmdir($plugins_dir . '/upayments');
@unlink($plugins_dir . '/supcheckout/UPayments.php');
@rmdir($plugins_dir . '/supcheckout');
@rmdir($plugins_dir);

echo "\nOfficial plugin coexistence: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

/**
 * Child process: minimal WordPress surface, then load the plugin main file(s).
 */
function coexistence_child($root, $scenario, $official_stub) {
    define('ABSPATH', __DIR__ . '/');
    define('WP_PLUGIN_DIR', (string) getenv('SUPCHECKOUT_COEXISTENCE_PLUGIN_DIR'));
    $own = 'supcheckout/supcheckout.php';
    $active = array($own);
    $sitewide = array();
    if (in_array($scenario, array('official_first', 'ours_first', 'notice_admin', 'notice_shopper'), true)) {
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
        'options' => array('active_plugins' => $active),
        'site_options' => array('active_sitewide_plugins' => $sitewide),
        'multisite' => $scenario === 'sitewide' || $scenario === 'stale_sitewide',
        'can_manage' => $scenario !== 'notice_shopper',
        'actions' => array(),
    );

    coexistence_define_wordpress_stubs($root);

    if ($scenario === 'official_first') {
        require $official_stub;
    }
    if ($scenario === 'shim' || $scenario === 'shim_official') {
        require $root . '/UPayments.php';
    } else {
        require $root . '/supcheckout.php';
    }
    // Observed before any official stub loads, so only SUPCheckout's own declarations count.
    $declared = function_exists('woocommerceUpaymentsInit') && $scenario !== 'official_first';
    if ($scenario === 'ours_first') {
        require $official_stub;
    }

    $notices = 0;
    foreach ($GLOBALS['coexistence']['actions'] as $action) {
        if ($action[0] === 'admin_notices') {
            $notices++;
            if (strpos($scenario, 'notice_') === 0) {
                call_user_func($action[1]);
            }
        }
    }
    echo "\nNOTICES:{$notices}\n";
    echo 'DECLARED:' . ($declared ? 'yes' : 'no') . "\n";
    echo 'UP_PLUGIN_URL:' . (defined('UP_PLUGIN_URL') && strpos(UP_PLUGIN_URL, '/upayments/') !== false ? 'official' : 'other') . "\n";
    echo 'SUPCHECKOUT_VERSION:' . (defined('SUPCHECKOUT_VERSION') ? 'defined' : 'undefined') . "\n";
    echo 'ACTIVE:' . json_encode(array_values((array) $GLOBALS['coexistence']['options']['active_plugins']), JSON_UNESCAPED_SLASHES) . "\n";
}

function coexistence_define_wordpress_stubs($root) {
    eval(<<<'PHP'
function plugin_dir_url($file) { return 'https://merchant.example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/'; }
function plugin_dir_path($file) { return dirname((string) $file) . DIRECTORY_SEPARATOR; }
function plugin_basename($file) { return 'supcheckout/' . basename((string) $file); }
function update_option($name, $value, $autoload = null) { $GLOBALS['coexistence']['options'][$name] = $value; return true; }
function update_site_option($name, $value) { $GLOBALS['coexistence']['site_options'][$name] = $value; return true; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['coexistence']['options']) ? $GLOBALS['coexistence']['options'][$name] : $default; }
function get_site_option($name, $default = false) { return array_key_exists($name, $GLOBALS['coexistence']['site_options']) ? $GLOBALS['coexistence']['site_options'][$name] : $default; }
function is_multisite() { return $GLOBALS['coexistence']['multisite']; }
function current_user_can($capability) { return $GLOBALS['coexistence']['can_manage'] && $capability === 'activate_plugins'; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['coexistence']['actions'][] = array($hook, $callback); return true; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
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
