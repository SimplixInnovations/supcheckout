<?php
/**
 * Public WordPress surface of SUPCheckout, frozen as a snapshot.
 *
 * Records, without callables (responsibilities may move between classes; the
 * surface itself may not change):
 * - every add_action/add_filter/register_*_hook call: type, hook, priority,
 *   accepted_args and the bootstrap phase that registered it (file load, a
 *   bootstrap hook such as plugins_loaded/init, or the gateway constructor);
 * - WP-CLI commands, admin pages, WC-API routes, the gateway identity and
 *   settings keys, the Blocks payment method and WooCommerce feature declarations;
 * - cron / Action Scheduler hooks scheduled on the bootstrap path, with their
 *   argument shapes;
 * - a static scan (tests/support/public-surface-static-scan.php) of every
 *   add_action/add_filter call and every scheduling call with hook, group,
 *   argument shape and unique flag, so runtime-only paths are frozen too.
 *
 * Each request context (frontend, admin, cli, and a site without WooCommerce)
 * loads the real main file in its own child PHP process under a recording
 * WordPress stub. When both supcheckout.php and UPayments.php exist, each is
 * booted separately and must produce the same surface.
 *
 * php tests/harness/public-surface-snapshot-harness.php           # verify
 * php tests/harness/public-surface-snapshot-harness.php --record  # rewrite fixture
 */

$root = dirname(__DIR__, 2);
$fixture = $root . '/tests/fixtures/public-surface.json';
$contexts = array('admin', 'cli', 'frontend', 'no_woocommerce');

if (isset($argv[1]) && $argv[1] === '--child') {
    surface_child($root, (string) $argv[2], (string) $argv[3], isset($argv[4]) ? (string) $argv[4] : '');
    exit(0);
}

require_once dirname(__DIR__) . '/support/public-surface-static-scan.php';

surface_scanner_self_test();

$mains = array();
foreach (array('supcheckout.php', 'UPayments.php') as $candidate) {
    if (is_file($root . '/' . $candidate)) {
        $mains[] = $candidate;
    }
}
if ($mains === array()) {
    echo "FAIL: no plugin main file (supcheckout.php or UPayments.php) at the repository root.\n";
    exit(1);
}

$runtime = array();
$frontend = array();
foreach ($mains as $main) {
    $by_context = array();
    foreach ($contexts as $context) {
        $by_context[$context] = surface_run_child($context, $main);
    }
    $runtime[$main] = surface_merge_contexts($by_context);
    usort($runtime[$main], 'surface_compare');
    if ($frontend === array()) {
        $frontend = $by_context['frontend'];
    }
}
if (count($runtime) > 1 && $runtime[$mains[0]] !== $runtime[$mains[1]]) {
    echo "FAIL: {$mains[0]} and {$mains[1]} boot different surfaces (- {$mains[0]}, + {$mains[1]}):\n";
    foreach (surface_multiset_diff($runtime[$mains[0]], $runtime[$mains[1]]) as $line) {
        echo $line . "\n";
    }
    exit(1);
}

$main_paths = array();
foreach ($mains as $main) {
    $main_paths[] = $root . '/' . $main;
}
$static = surface_scan_static_entries($root, $main_paths);
$entries = $runtime[$mains[0]];
foreach ($static as $reference) {
    $entries[] = $reference + array('contexts' => 'static');
}
usort($entries, 'surface_compare');

$violations = surface_invariant_violations($frontend, $static);
if ($violations !== array()) {
    echo "FAIL: the booted surface is incomplete (plugin paused or bootstrap broken); nothing recorded or compared:\n";
    foreach ($violations as $violation) {
        echo '  - ' . $violation . "\n";
    }
    exit(1);
}
// The takeover copy runner (0b Task 6) registers its Action Scheduler hook and its watchdog
// (action_scheduler_init) only while the takeover is copying: every other state boots
// without them, so idle stores add nothing at runtime. These boots are checked, never recorded.
// ReconciliationSweep also hooks action_scheduler_init in every state (U2 review I-1: its
// background ensure must wait for Action Scheduler), so that one entry is discounted.
foreach (array('idle' => 0, 'preflight_ok' => 0, 'copying' => 2, 'verified' => 0, 'cut_over' => 0) as $takeover_status => $expected) {
    $registered = -1;
    foreach (surface_run_child('frontend', $mains[0], $takeover_status) as $entry) {
        if ($entry['kind'] === 'action' && in_array($entry['hook'], array('supcheckout_takeover_copy_batch', 'action_scheduler_init'), true)) {
            $registered++;
        }
    }
    if ($registered !== $expected) {
        echo "FAIL: a {$takeover_status} boot registers {$registered} copy runner hook(s), expected {$expected}.\n";
        echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
        exit(1);
    }
}
echo "Takeover copy runner: batch hook and watchdog registered once each while copying, never in idle, preflight_ok, verified or cut_over boots.\n";
// Both WC-API callback routes (0b Task 8) carry the same pair in every takeover state, the
// legacy route permanently (finalized included): the lifecycle at priority 5 from the
// load phase and the gateway's fallback at priority 10 from its constructor.
foreach (array('idle', 'copying', 'cut_over', 'finalized', 'rolled_back') as $takeover_status) {
    $pairs = array();
    foreach (surface_run_child('frontend', $mains[0], $takeover_status) as $entry) {
        if ($entry['kind'] === 'action' && in_array($entry['hook'], array('woocommerce_api_wc_upayments', 'woocommerce_api_supcheckout'), true)) {
            $pairs[$entry['hook']][] = $entry['priority'] . '@' . $entry['registered_in'];
        }
    }
    foreach (array('woocommerce_api_wc_upayments', 'woocommerce_api_supcheckout') as $hook) {
        $found = isset($pairs[$hook]) ? $pairs[$hook] : array();
        sort($found);
        if ($found !== array('10@gateway_constructor', '5@load')) {
            echo "FAIL: a {$takeover_status} boot registers " . json_encode($found) . " on {$hook}, expected the lifecycle at 5 (load) and the gateway fallback at 10 (gateway_constructor).\n";
            echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
            exit(1);
        }
    }
}
echo "Callback routes: wc_upayments and supcheckout each carry the lifecycle at 5 and the gateway fallback at 10 in idle, copying, cut_over, finalized and rolled_back boots.\n";
// Cron routing (0b Task 7): the static scan sees the hook names only as variables, so the
// routing is pinned per takeover state here (checked, never recorded). Before copying only
// the legacy names listen; from copying on both names run the same callbacks (the cutover
// keeps legacy events until Finalize); the hourly tick is scheduled under the current name.
$cron_pairs = array(
    'upay_process_subscriptions' => 'supcheckout_process_subscriptions',
    'simplixpay_upayments_reconcile_order' => 'supcheckout_reconcile_order',
    'simplixpay_upayments_reconcile_secondary' => 'supcheckout_reconcile_secondary',
);
foreach (array('idle' => false, 'preflight_ok' => false, 'copying' => true, 'verified' => true, 'cut_over' => true, 'rolled_back' => true, 'finalized' => true) as $takeover_status => $both) {
    $listened = array();
    $scheduled = array();
    $problems_shape = array();
    foreach (surface_run_child('frontend', $mains[0], $takeover_status) as $entry) {
        if ($entry['kind'] === 'action') {
            $listened[$entry['hook']] = isset($listened[$entry['hook']]) ? $listened[$entry['hook']] + 1 : 1;
        }
        if ($entry['kind'] === 'schedule_call' && $entry['function'] === 'wp_schedule_event') {
            $scheduled[] = $entry['hook'];
            if ($entry['recurrence'] !== 'hourly' || $entry['args'] !== '[]') {
                $problems_shape[] = "{$entry['hook']} scheduled with recurrence {$entry['recurrence']} and args {$entry['args']}";
            }
        }
    }
    $expected_schedule = in_array($takeover_status, array('cut_over', 'finalized'), true) ? array('supcheckout_process_subscriptions') : array('upay_process_subscriptions');
    $problems = $problems_shape;
    foreach ($cron_pairs as $legacy => $new) {
        if ((isset($listened[$legacy]) ? $listened[$legacy] : 0) !== 1) {
            $problems[] = "{$legacy} listened " . (isset($listened[$legacy]) ? $listened[$legacy] : 0) . ' time(s)';
        }
        if ((isset($listened[$new]) ? $listened[$new] : 0) !== ($both ? 1 : 0)) {
            $problems[] = "{$new} listened " . (isset($listened[$new]) ? $listened[$new] : 0) . ' time(s)';
        }
    }
    if ($scheduled !== $expected_schedule) {
        $problems[] = 'hourly tick scheduled as ' . json_encode($scheduled);
    }
    if ($problems !== array()) {
        echo "FAIL: cron routing in a {$takeover_status} boot: " . implode('; ', $problems) . "\n";
        echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
        exit(1);
    }
}
echo "Cron routing: legacy names only before copying, both names from copying on; the hourly tick is scheduled under the new name once cut over.\n";
// Gateway identity (0b Task 7): the gateway, its Settings-API hooks and the Blocks method
// carry the current gateway id: upayments in every state before cut_over, supcheckout
// from cut_over on (checked, never recorded).
foreach (array('copying' => 'upayments', 'verified' => 'upayments', 'rolled_back' => 'upayments', 'cut_over' => 'supcheckout', 'finalized' => 'supcheckout') as $takeover_status => $gateway_id) {
    $seen = array('gateway' => array(), 'blocks' => array(), 'fields' => 0, 'hooks' => array());
    foreach (surface_run_child('frontend', $mains[0], $takeover_status) as $entry) {
        if ($entry['kind'] === 'gateway') {
            $seen['gateway'][] = $entry['id'];
        } elseif ($entry['kind'] === 'blocks_payment_method') {
            $seen['blocks'][] = $entry['name'];
        } elseif ($entry['kind'] === 'gateway_form_field' && $entry['gateway'] === $gateway_id) {
            $seen['fields']++;
        } elseif (in_array($entry['kind'], array('action', 'filter'), true) && $entry['registered_in'] === 'gateway_constructor'
            && preg_match('/^woocommerce_(settings_api_sanitized_fields|update_options_payment_gateways|thankyou)_/', $entry['hook']) === 1
        ) {
            $seen['hooks'][] = $entry['hook'];
        }
    }
    sort($seen['hooks']);
    $expected_hooks = array(
        'woocommerce_settings_api_sanitized_fields_' . $gateway_id,
        'woocommerce_thankyou_' . $gateway_id,
        'woocommerce_update_options_payment_gateways_' . $gateway_id,
    );
    if ($seen['gateway'] !== array($gateway_id) || $seen['blocks'] !== array($gateway_id) || $seen['fields'] === 0 || $seen['hooks'] !== $expected_hooks) {
        echo "FAIL: gateway identity in a {$takeover_status} boot: " . json_encode($seen) . "\n";
        echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
        exit(1);
    }
}
echo "Gateway identity: upayments before cut_over, supcheckout (gateway, settings fields, Settings-API hooks, Blocks method) from cut_over on.\n";

$json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (in_array('--record', $argv, true)) {
    file_put_contents($fixture, $json);
    echo 'Recorded ' . count($entries) . " public surface entries.\n";
    exit(0);
}

$expected_json = is_file($fixture) ? (string) file_get_contents($fixture) : '';
if ($expected_json === $json) {
    echo 'Public surface: ' . count($entries) . " entries unchanged.\nPublic surface: PASS\n";
    echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
    exit(0);
}
$expected = json_decode($expected_json, true);
$expected = is_array($expected) ? $expected : array();
echo $expected_json === '' ? "FAIL: no public surface fixture at tests/fixtures/public-surface.json.\n" : "FAIL: public surface changed.\n";
echo "Diff (- recorded fixture, + current code):\n";
foreach (surface_multiset_diff($expected, $entries) as $line) {
    echo $line . "\n";
}
echo "Re-record only with a stated reason: php tests/harness/public-surface-snapshot-harness.php --record\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit(1);

/**
 * Anchors that every real boot produces, checked before recording or comparing,
 * so a paused or broken boot can never be frozen as the baseline.
 *
 * @param array<int, array<string, mixed>> $frontend Frontend-context entries.
 * @param array<int, array<string, mixed>> $static Static scan entries.
 * @return array<int, string>
 */
function surface_invariant_violations(array $frontend, array $static) {
    $required = array(
        'gateway id upayments' => array('kind' => 'gateway', 'id' => 'upayments'),
        'WC-API route wc_upayments' => array('kind' => 'wc_api_route', 'route' => 'wc_upayments'),
        'WC-API route supcheckout' => array('kind' => 'wc_api_route', 'route' => 'supcheckout'),
        'at least one gateway settings field' => array('kind' => 'gateway_form_field', 'gateway' => 'upayments'),
        'Blocks payment method upayments' => array('kind' => 'blocks_payment_method', 'name' => 'upayments'),
        'action supcheckout_process_due_parent with 3 accepted args' => array('kind' => 'action', 'hook' => 'supcheckout_process_due_parent', 'accepted_args' => 3),
        'subscription cron upay_process_subscriptions scheduled' => array('kind' => 'schedule_call', 'hook' => 'upay_process_subscriptions'),
    );
    $violations = array();
    foreach ($required as $label => $wanted) {
        if (!surface_contains($frontend, $wanted)) {
            $violations[] = 'frontend boot lacks ' . $label;
        }
    }
    if (!surface_contains($static, array('kind' => 'hook_reference'))
        || !surface_contains($static, array('kind' => 'schedule_reference', 'hook' => 'supcheckout_process_due_parent'))
    ) {
        $violations[] = 'static scan found no hook or Action Scheduler references';
    }
    return $violations;
}

/**
 * @param array<int, array<string, mixed>> $entries
 * @param array<string, mixed> $wanted
 * @return bool
 */
function surface_contains(array $entries, array $wanted) {
    foreach ($entries as $entry) {
        if (array_intersect_assoc($wanted, $entry) === $wanted) {
            return true;
        }
    }
    return false;
}

/**
 * @param string $context Request context.
 * @param string $main Main file basename to boot.
 * @return array<int, array<string, mixed>>
 */
function surface_run_child($context, $main, $takeover_status = '') {
    $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=' . E_ALL . ' -d display_errors=1 '
        . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($context) . ' ' . escapeshellarg($main)
        . ($takeover_status === '' ? '' : ' ' . escapeshellarg($takeover_status)) . ' 2>&1';
    $output = array();
    $code = 0;
    exec($command, $output, $code);
    $text = implode("\n", $output);
    $begin = strpos($text, "SURFACE_JSON_BEGIN\n");
    $end = strpos($text, "\nSURFACE_JSON_END");
    $clean = $code === 0 && $begin !== false && $end !== false
        && trim(substr($text, 0, $begin) . substr($text, $end + 17)) === '';
    if (!$clean) {
        echo "FAIL: {$context} child ({$main}) did not produce a clean surface (exit {$code}):\n{$text}\n";
        exit(1);
    }
    $decoded = json_decode(substr($text, $begin + 19, $end - $begin - 19), true);
    if (!is_array($decoded)) {
        echo "FAIL: {$context} child ({$main}) emitted invalid JSON.\n";
        exit(1);
    }
    return $decoded;
}

/**
 * Multiset merge: identical registrations stay separate entries, each naming the
 * contexts that produce at least that many copies.
 *
 * @param array<string, array<int, array<string, mixed>>> $by_context
 * @return array<int, array<string, mixed>>
 */
function surface_merge_contexts(array $by_context) {
    $counts = array();
    foreach ($by_context as $context => $context_entries) {
        foreach ($context_entries as $entry) {
            $key = json_encode($entry);
            $counts[$key][$context] = isset($counts[$key][$context]) ? $counts[$key][$context] + 1 : 1;
        }
    }
    $merged = array();
    foreach ($counts as $key => $per_context) {
        for ($copy = 1; $copy <= max($per_context); $copy++) {
            $present = array();
            foreach ($per_context as $context => $count) {
                if ($count >= $copy) {
                    $present[] = $context;
                }
            }
            sort($present);
            $merged[] = json_decode($key, true) + array('contexts' => implode(' ', $present));
        }
    }
    return $merged;
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @return int
 */
function surface_compare($a, $b) {
    return strcmp((string) json_encode($a), (string) json_encode($b));
}

/**
 * @param array<int, array<string, mixed>> $expected
 * @param array<int, array<string, mixed>> $actual
 * @return array<int, string>
 */
function surface_multiset_diff(array $expected, array $actual) {
    $balance = array();
    foreach ($expected as $entry) {
        $key = json_encode($entry, JSON_UNESCAPED_SLASHES);
        $balance[$key] = (isset($balance[$key]) ? $balance[$key] : 0) - 1;
    }
    foreach ($actual as $entry) {
        $key = json_encode($entry, JSON_UNESCAPED_SLASHES);
        $balance[$key] = (isset($balance[$key]) ? $balance[$key] : 0) + 1;
    }
    ksort($balance);
    $lines = array();
    foreach ($balance as $key => $delta) {
        for ($i = 0; $i < abs($delta); $i++) {
            $lines[] = ($delta < 0 ? '  - ' : '  + ') . $key;
        }
    }
    if ($lines === array()) {
        $lines[] = '  (same entries; fixture formatting or order differs, re-record)';
    }
    return $lines;
}

/**
 * Child process: load the real main file under a recording stub for one context,
 * fire the bootstrap hooks in WordPress order, instantiate the registered gateway
 * and print the recorded surface as JSON.
 *
 * @param string $root Repository root.
 * @param string $context frontend, admin, cli or no_woocommerce.
 * @param string $main_file Main file basename at the repository root.
 * @return void
 */
function surface_child($root, $context, $main_file, $takeover_status = '') {
    set_error_handler(static function ($errno, $message, $file, $line) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        fwrite(STDOUT, "PHP issue {$errno}: {$message} in {$file}:{$line}\n");
        exit(2);
    });
    $main = $root . '/' . basename($main_file);
    define('ABSPATH', __DIR__ . '/');
    if ($context === 'cli') {
        define('WP_CLI', true);
    }
    $GLOBALS['surface'] = array(
        'context' => $context,
        'main' => $main,
        'root' => $root,
        'phase' => 'load',
        'takeover_status' => (string) $takeover_status,
        'entries' => array(),
        'callbacks' => array(),
        'seq' => 0,
    );
    $with_woocommerce = $context !== 'no_woocommerce';
    surface_define_wordpress_stubs($with_woocommerce);

    ob_start();
    try {
        require $main;
        // WooCommerce fires its own hooks only while it is active.
        $bootstrap = $with_woocommerce
            ? array('plugins_loaded', 'woocommerce_blocks_loaded', 'before_woocommerce_init', 'woocommerce_init', 'init')
            : array('plugins_loaded', 'init');
        if ($context === 'admin') {
            // wp-admin fires admin_init, then admin_menu from menu.php.
            $bootstrap[] = 'admin_init';
            $bootstrap[] = 'admin_menu';
        }
        foreach ($bootstrap as $hook) {
            surface_fire($hook, array());
        }
        if ($with_woocommerce) {
            surface_record_gateways();
            surface_fire('woocommerce_blocks_payment_method_type_registration', array(new SupcheckoutSurfacePaymentMethodRegistry()));
        }
    } catch (Throwable $e) {
        ob_end_clean();
        echo 'Bootstrap failed in ' . $context . ': ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        exit(1);
    }
    $echoed = ob_get_clean();
    if ($echoed !== '') {
        echo 'Bootstrap echoed output in ' . $context . ': ' . $echoed . "\n";
        exit(1);
    }

    $entries = $GLOBALS['surface']['entries'];
    $routes = array();
    foreach ($entries as $entry) {
        if (in_array($entry['kind'], array('action', 'filter'), true) && strpos((string) $entry['hook'], 'woocommerce_api_') === 0) {
            $routes[substr((string) $entry['hook'], 16)] = true;
        }
    }
    foreach (array_keys($routes) as $route) {
        $entries[] = array('kind' => 'wc_api_route', 'route' => (string) $route);
    }
    echo "SURFACE_JSON_BEGIN\n" . json_encode($entries, JSON_UNESCAPED_SLASHES) . "\nSURFACE_JSON_END\n";
}

/**
 * Invoke every callback registered on a hook, nested registrations included,
 * in priority then registration order (WordPress semantics, simplified).
 *
 * @param string $hook
 * @param array<int, mixed> $args
 * @return void
 */
function surface_fire($hook, array $args) {
    $done = array();
    $previous = $GLOBALS['surface']['phase'];
    while (true) {
        $pending = array();
        foreach ($GLOBALS['surface']['callbacks'] as $seq => $callback) {
            if ($callback['hook'] === $hook && !isset($done[$seq])) {
                $pending[$seq] = $callback;
            }
        }
        if ($pending === array()) {
            break;
        }
        uasort($pending, static function ($a, $b) {
            return $a['priority'] === $b['priority'] ? $a['seq'] - $b['seq'] : $a['priority'] - $b['priority'];
        });
        $seq = (int) key($pending);
        $done[$seq] = true;
        $GLOBALS['surface']['phase'] = $hook;
        call_user_func_array($pending[$seq]['callable'], array_slice($args, 0, $pending[$seq]['accepted_args']));
    }
    $GLOBALS['surface']['phase'] = $previous;
}

/**
 * Instantiate each class the plugin registers on woocommerce_payment_gateways and
 * record its identity; constructor-time hooks are recorded in their own phase.
 *
 * @return void
 */
function surface_record_gateways() {
    $classes = array();
    foreach ($GLOBALS['surface']['callbacks'] as $callback) {
        if ($callback['hook'] === 'woocommerce_payment_gateways') {
            $classes = call_user_func($callback['callable'], $classes);
        }
    }
    foreach ((array) $classes as $class) {
        $GLOBALS['surface']['phase'] = 'gateway_constructor';
        $gateway = is_object($class) ? $class : new $class();
        $GLOBALS['surface']['phase'] = 'load';
        $supports = array_values((array) $gateway->supports);
        sort($supports);
        $GLOBALS['surface']['entries'][] = array(
            'kind' => 'gateway',
            'id' => (string) $gateway->id,
            'has_fields' => (bool) $gateway->has_fields,
            'method_title' => (string) $gateway->method_title,
            'supports' => implode(',', $supports),
        );
        $fields = array_keys((array) $gateway->form_fields);
        sort($fields);
        foreach ($fields as $field) {
            $GLOBALS['surface']['entries'][] = array('kind' => 'gateway_form_field', 'gateway' => (string) $gateway->id, 'key' => (string) $field);
        }
    }
}

/**
 * @param array<string, mixed> $entry
 * @return bool
 */
function supcheckout_surface_record(array $entry) {
    $GLOBALS['surface']['entries'][] = $entry;
    return true;
}

/**
 * Minimal recording WordPress / WooCommerce surface, declared only in the child.
 *
 * @param bool $with_woocommerce Whether WooCommerce is active.
 * @return void
 */
function surface_define_wordpress_stubs($with_woocommerce) {
    eval(<<<'PHP'
function supcheckout_surface_hook($type, $hook, $callable, $priority, $accepted_args) {
    $seq = $GLOBALS['surface']['seq']++;
    $GLOBALS['surface']['callbacks'][$seq] = array('hook' => (string) $hook, 'callable' => $callable, 'priority' => (int) $priority, 'accepted_args' => (int) $accepted_args, 'seq' => $seq);
    return supcheckout_surface_record(array('kind' => $type, 'hook' => (string) $hook, 'priority' => (int) $priority, 'accepted_args' => (int) $accepted_args, 'registered_in' => $GLOBALS['surface']['phase']));
}
function supcheckout_surface_file_hook($type, $file) {
    // Either root main file (supcheckout.php or a UPayments.php shim) is the plugin file.
    $is_main = realpath(dirname((string) $file)) === realpath($GLOBALS['surface']['root'])
        && in_array(basename((string) $file), array('supcheckout.php', 'UPayments.php'), true);
    $hook = $is_main ? 'main_plugin_file' : basename((string) $file);
    return supcheckout_surface_record(array('kind' => $type, 'hook' => $hook, 'registered_in' => $GLOBALS['surface']['phase']));
}
function supcheckout_surface_schedule($function, $hook, array $extra) {
    return supcheckout_surface_record(array('kind' => 'schedule_call', 'function' => $function, 'hook' => (string) $hook) + $extra + array('registered_in' => $GLOBALS['surface']['phase']));
}
function supcheckout_surface_args_shape($args) {
    $types = array('integer' => 'int', 'string' => 'string', 'boolean' => 'bool', 'double' => 'float', 'array' => 'array');
    $shape = array();
    foreach ((array) $args as $key => $value) { $shape[] = $key . ':' . (isset($types[gettype($value)]) ? $types[gettype($value)] : gettype($value)); }
    return '[' . implode(',', $shape) . ']';
}
function supcheckout_surface_admin_page($parent, $capability, $slug) {
    return supcheckout_surface_record(array('kind' => 'admin_page', 'parent' => (string) $parent, 'slug' => (string) $slug, 'capability' => (string) $capability, 'registered_in' => $GLOBALS['surface']['phase']));
}
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null) { supcheckout_surface_admin_page('', $capability, $menu_slug); return 'toplevel_page_' . $menu_slug; }
function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) { supcheckout_surface_admin_page($parent_slug, $capability, $menu_slug); return $parent_slug . '_page_' . $menu_slug; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return supcheckout_surface_hook('action', $hook, $callback, $priority, $accepted_args); }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return supcheckout_surface_hook('filter', $hook, $callback, $priority, $accepted_args); }
function register_activation_hook($file, $callback) { return supcheckout_surface_file_hook('activation', $file); }
function register_deactivation_hook($file, $callback) { return supcheckout_surface_file_hook('deactivation', $file); }
function register_uninstall_hook($file, $callback) { return supcheckout_surface_file_hook('uninstall', $file); }
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_clear_scheduled_hook($hook, $args = array(), $wp_error = false) { supcheckout_surface_schedule('wp_clear_scheduled_hook', $hook, array('args' => supcheckout_surface_args_shape($args))); return 0; }
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return supcheckout_surface_schedule('wp_schedule_event', $hook, array('recurrence' => (string) $recurrence, 'args' => supcheckout_surface_args_shape($args))); }
function wp_schedule_single_event($timestamp, $hook, $args = array()) { return supcheckout_surface_schedule('wp_schedule_single_event', $hook, array('args' => supcheckout_surface_args_shape($args))); }
function as_has_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_next_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10) { supcheckout_surface_schedule('as_schedule_single_action', $hook, array('group' => (string) $group, 'args' => supcheckout_surface_args_shape($args), 'unique' => (bool) $unique)); return 1; }
function as_schedule_recurring_action($timestamp, $interval, $hook, $args = array(), $group = '', $unique = false, $priority = 10) { supcheckout_surface_schedule('as_schedule_recurring_action', $hook, array('group' => (string) $group, 'interval' => (int) $interval, 'args' => supcheckout_surface_args_shape($args), 'unique' => (bool) $unique)); return 1; }
function as_enqueue_async_action($hook, $args = array(), $group = '', $unique = false, $priority = 10) { supcheckout_surface_schedule('as_enqueue_async_action', $hook, array('group' => (string) $group, 'args' => supcheckout_surface_args_shape($args), 'unique' => (bool) $unique)); return 1; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/supcheckout/'; }
function plugin_dir_path($file) { return dirname((string) $file) . '/'; }
function plugin_basename($file) { return 'supcheckout/' . basename((string) $file); }
function get_option($name, $default = false) {
    if ($name === 'active_plugins') {
        return array(plugin_basename($GLOBALS['surface']['main']));
    }
    // Extra boots (never recorded) seed a takeover state; the recorded boots have none (idle).
    if ($name === 'supcheckout_takeover_state_v1' && $GLOBALS['surface']['takeover_status'] !== '') {
        return json_encode(array('status' => $GLOBALS['surface']['takeover_status'], 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
    }
    // Boot a store with subscriptions enabled so the conditional hourly tick is part of the frozen surface.
    return $name === 'woocommerce_upayments_settings' ? array('enable_subscriptions' => 'yes') : $default;
}
// The sweep's ensure throttle marker (ReconciliationSweep::ensure_scheduled) is a write, not surface.
function update_option($name, $value, $autoload = null) { return true; }
function is_multisite() { return false; }
function is_admin() { return $GLOBALS['surface']['context'] === 'admin'; }
function wp_doing_ajax() { return false; }
function __($text, $domain = 'default') { return $text; }
class WP_CLI {
    public static function add_command($name, $callable, $args = array()) { return supcheckout_surface_record(array('kind' => 'cli_command', 'hook' => (string) $name, 'registered_in' => $GLOBALS['surface']['phase'])); }
}
PHP
    );
    if (!$with_woocommerce) {
        return;
    }
    eval(<<<'PHP'
class WooCommerce {}
abstract class WC_Payment_Gateway {
    public $id = '';
    public $has_fields = false;
    public $supports = array('products');
    public $form_fields = array();
    public $settings = array();
    public $method_title = '';
    public $method_description = '';
    public $title = '';
    public $description = '';
    public $icon = '';
    public function init_settings() { $this->settings = array(); }
    public function get_option($key, $empty_value = null) { return $empty_value === null ? '' : $empty_value; }
    public function is_available() { return true; }
}
class SupcheckoutSurfacePaymentMethodRegistry {
    public function register($method) {
        $features = array_values((array) $method->get_supported_features());
        sort($features);
        return supcheckout_surface_record(array('kind' => 'blocks_payment_method', 'name' => (string) $method->get_name(), 'supported_features' => implode(',', $features)));
    }
}
PHP
    );
    eval(<<<'PHP'
namespace Automattic\WooCommerce\Utilities;
final class FeaturesUtil {
    public static function declare_compatibility($feature_id, $plugin_file, $positive_compatibility = true) {
        return \supcheckout_surface_record(array('kind' => 'woocommerce_feature_compatibility', 'feature' => (string) $feature_id, 'compatible' => (bool) $positive_compatibility));
    }
}
namespace Automattic\WooCommerce\Blocks\Payments\Integrations;
abstract class AbstractPaymentMethodType {
    protected $name = '';
    protected $settings = array();
}
PHP
    );
}

/**
 * Scanner self-test. An argument array changed after its assignment ($args[] = ...,
 * $args['k'] = ..., compound assignment, array_push(), unset()) must never resolve to
 * the earlier literal: stored cron/Action Scheduler events match exact args, so such a
 * change has to stay visible in the snapshot as a dynamic shape.
 *
 * @return void
 */
function surface_scanner_self_test() {
    $dir = str_replace(chr(92), '/', sys_get_temp_dir()) . '/supcheckout-surface-probe-' . getmypid();
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        echo "FAIL: scanner self-test could not create {$dir}\n";
        exit(1);
    }
    $probe = $dir . '/Probe.php';
    file_put_contents($probe, <<<'PHP'
<?php
function probe_plain($id) { $args = array((int) $id); wp_schedule_single_event(1, 'probe_plain', $args); }
function probe_append($id) { $args = array((int) $id); $args[] = 'x'; wp_unschedule_event(1, 'probe_append', $args); }
function probe_offset($id) { $args = array((int) $id); $args['k'] = 1; wp_next_scheduled('probe_offset', $args); }
function probe_compound($id) { $args = array((int) $id); $args += array(1 => 2); wp_next_scheduled('probe_compound', $args); }
function probe_push($id) { $args = array((int) $id); array_push($args, 2); wp_next_scheduled('probe_push', $args); }
function probe_unset($id) { $args = array((int) $id, 2); unset($args[1]); wp_next_scheduled('probe_unset', $args); }
function probe_read($id) { $args = array((int) $id); $first = $args[0]; wp_next_scheduled('probe_read', $args); }
PHP
    );
    $index = array('files' => array(), 'constants' => array(), 'methods' => array());
    surface_scan_index_file($index, $probe);
    $shapes = array();
    foreach (surface_scan_file_entries($index, $probe) as $entry) {
        $shapes[$entry['hook']] = isset($entry['args']) ? $entry['args'] : '';
    }
    unlink($probe);
    rmdir($dir);

    $failures = array();
    foreach (array('probe_plain' => '[0:int]', 'probe_read' => '[0:int]') as $hook => $expected) {
        if (!isset($shapes[$hook]) || $shapes[$hook] !== $expected) {
            $failures[] = "{$hook} should resolve to {$expected}, got " . (isset($shapes[$hook]) ? $shapes[$hook] : '(missing)');
        }
    }
    foreach (array('probe_append', 'probe_offset', 'probe_compound', 'probe_push', 'probe_unset') as $hook) {
        if (!isset($shapes[$hook]) || strpos($shapes[$hook], '<dynamic:') !== 0) {
            $failures[] = "{$hook} mutates \$args after assignment and must be dynamic, got " . (isset($shapes[$hook]) ? $shapes[$hook] : '(missing)');
        }
    }
    if ($failures !== array()) {
        echo "FAIL: argument-shape scanner self-test:\n  " . implode("\n  ", $failures) . "\n";
        exit(1);
    }
}
