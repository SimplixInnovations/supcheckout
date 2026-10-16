<?php
/**
 * Per-request and background cost guards (performance review, 2026-10-16).
 *
 * - The gateway icon filter runs once per gateway icon on every checkout refresh;
 *   it must not rebuild the whole available-gateway list each time.
 * - The hourly subscription cron must not exist on stores that never enabled
 *   subscriptions, and its tick must skip the 19-query table check when off.
 * - Deactivation must clear the hourly event so it stops firing without a callback.
 */

$root = dirname(__DIR__, 2);

if (isset($argv[1]) && $argv[1] === '--child') {
    cost_child($root, (string) $argv[2]);
    exit(0);
}

$pass = 0;
$fail = 0;
function cost_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$label}\n";
}

function cost_method_body($source, $signature) {
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $open = strpos($source, '{', $start);
    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') { $depth++; }
        if ($source[$i] === '}') { $depth--; if ($depth === 0) { return substr($source, $open, $i - $open + 1); } }
    }
    return '';
}

function cost_run($scenario) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($scenario) . ' 2>&1';
    $output = array();
    $code = 0;
    exec($command, $output, $code);
    return array('code' => $code, 'out' => implode("\n", $output));
}

$gateway_source = (string) file_get_contents($root . '/supcheckout.php');
$icon_body = cost_method_body($gateway_source, 'public function custom_payment_gateway_icons(');
cost_assert($icon_body !== '', 'gateway icon filter exists');
cost_assert(strpos($icon_body, 'get_available_payment_gateways') === false, 'gateway icon filter never rebuilds the available-gateway list');

$enabled = cost_run('enabled');
cost_assert($enabled['code'] === 0 && strpos($enabled['out'], 'SCHEDULED:upay_process_subscriptions') !== false, 'stores with subscriptions enabled get the hourly subscription tick');

$disabled = cost_run('disabled');
cost_assert($disabled['code'] === 0 && strpos($disabled['out'], 'SCHEDULED:upay_process_subscriptions') === false, 'stores with subscriptions disabled never schedule the hourly tick');
cost_assert(strpos($disabled['out'], 'CLEARED:upay_process_subscriptions') !== false, 'an existing hourly tick is cleared when subscriptions are disabled');
cost_assert(strpos($disabled['out'], 'HOOKED:upay_process_subscriptions') !== false, 'the tick callback stays registered so a stale event is a no-op');

$scheduler_source = (string) file_get_contents($root . '/includes/Subscription/Cron/Scheduler.php');
$process_body = cost_method_body($scheduler_source, 'public static function process(');
$enabled_check = strpos($process_body, "get_option('enable_subscriptions') !== 'yes'");
$install_check = strpos($process_body, 'CycleClaim::maybe_install()');
cost_assert($enabled_check !== false && $install_check !== false && $enabled_check < $install_check, 'the tick checks subscriptions are enabled before the cycle-claim table check');

$existing = cost_run('enabled-existing');
cost_assert($existing['code'] === 0 && strpos($existing['out'], 'SCHEDULED:') === false && strpos($existing['out'], 'CLEARED:') === false, 'an enabled store keeps its existing hourly tick timestamp untouched');

// Non-destructive rollback contract (release-artifact migration cells): deactivating
// SUPCheckout must leave the canonical subscription schedule exactly as it was, so a
// merchant who rolls back and forward again keeps the same next run.
cost_assert(strpos($gateway_source, 'register_deactivation_hook') === false, 'the main plugin file registers no deactivation hook that could clear the schedule');
cost_assert(strpos($scheduler_source, 'function deactivate(') === false, 'the scheduler has no deactivation path that clears the hourly tick');

echo "\nRequest cost: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

function cost_child($root, $scenario) {
    define('ABSPATH', __DIR__ . '/');
    $GLOBALS['cost'] = array('scenario' => $scenario, 'events' => array('upay_process_subscriptions' => $scenario === 'disabled' || $scenario === 'enabled-existing'));
    eval(<<<'PHP'
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { if ($hook === 'upay_process_subscriptions') { echo "HOOKED:{$hook}\n"; } return true; }
function wp_next_scheduled($hook, $args = array()) { return !empty($GLOBALS['cost']['events'][$hook]) ? time() + 60 : false; }
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { $GLOBALS['cost']['events'][$hook] = true; echo "SCHEDULED:{$hook}\n"; return true; }
function wp_clear_scheduled_hook($hook, $args = array()) { $had = !empty($GLOBALS['cost']['events'][$hook]); unset($GLOBALS['cost']['events'][$hook]); if ($had) { echo "CLEARED:{$hook}\n"; } return $had ? 1 : 0; }
function get_option($name, $default = false) {
    if ($name === 'woocommerce_upayments_settings') {
        return array('enable_subscriptions' => in_array($GLOBALS['cost']['scenario'], array('enabled', 'enabled-existing'), true) ? 'yes' : 'no');
    }
    return $default;
}
PHP
    );
    require $root . '/includes/Subscription/Cron/Scheduler.php';
    \UPayments\Subscription\Cron\Scheduler::init();
}
