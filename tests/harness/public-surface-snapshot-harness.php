<?php
/**
 * Public WordPress surface of SUPCheckout, frozen as a snapshot.
 *
 * Records, without callables (responsibilities may move between classes; the
 * surface itself may not change):
 * - every add_action/add_filter/register_*_hook call: type, hook, priority,
 *   accepted_args and the bootstrap phase that registered it (file load, a
 *   bootstrap hook such as plugins_loaded/init, or the gateway constructor);
 * - WP-CLI commands, WC-API routes, the gateway identity and settings keys,
 *   the Blocks payment method and the WooCommerce feature declarations;
 * - cron / Action Scheduler hook names: those scheduled on the bootstrap path,
 *   plus a static scan of every literal hook passed to a scheduling call.
 *
 * Each request context (frontend, admin, cli, and a site without WooCommerce)
 * loads the real main file in its own child PHP process under a recording
 * WordPress stub.
 *
 * php tests/harness/public-surface-snapshot-harness.php           # verify
 * php tests/harness/public-surface-snapshot-harness.php --record  # rewrite fixture
 */

$root = dirname(__DIR__, 2);
$fixture = $root . '/tests/fixtures/public-surface.json';
$contexts = array('admin', 'cli', 'frontend', 'no_woocommerce');

if (isset($argv[1]) && $argv[1] === '--child') {
    surface_child($root, (string) $argv[2]);
    exit(0);
}

$by_context = array();
foreach ($contexts as $context) {
    $by_context[$context] = surface_run_child($context);
}
$entries = surface_merge_contexts($by_context);
foreach (surface_static_schedule_references($root) as $reference) {
    $entries[] = $reference + array('contexts' => 'static');
}
usort($entries, 'surface_compare');
$json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (in_array('--record', $argv, true)) {
    file_put_contents($fixture, $json);
    echo 'Recorded ' . count($entries) . " public surface entries.\n";
    exit(0);
}

$expected_json = is_file($fixture) ? (string) file_get_contents($fixture) : '';
if ($expected_json === $json) {
    echo 'Public surface: ' . count($entries) . " entries unchanged.\nPublic surface: PASS\n";
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
exit(1);

/**
 * @param string $context Request context.
 * @return array<int, array<string, mixed>>
 */
function surface_run_child($context) {
    $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=' . E_ALL . ' -d display_errors=1 '
        . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($context) . ' 2>&1';
    $output = array();
    $code = 0;
    exec($command, $output, $code);
    $text = implode("\n", $output);
    $begin = strpos($text, "SURFACE_JSON_BEGIN\n");
    $end = strpos($text, "\nSURFACE_JSON_END");
    $clean = $code === 0 && $begin !== false && $end !== false
        && trim(substr($text, 0, $begin) . substr($text, $end + 17)) === '';
    if (!$clean) {
        echo "FAIL: {$context} child did not produce a clean surface (exit {$code}):\n{$text}\n";
        exit(1);
    }
    $decoded = json_decode(substr($text, $begin + 19, $end - $begin - 19), true);
    if (!is_array($decoded)) {
        echo "FAIL: {$context} child emitted invalid JSON.\n";
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
 * Literal hook names passed to WP-Cron / Action Scheduler calls anywhere in the
 * shipped PHP, so hooks scheduled only on runtime paths are frozen too.
 *
 * @param string $root Repository root.
 * @return array<int, array<string, mixed>>
 */
function surface_static_schedule_references($root) {
    // function => array(hook argument index, group argument index or null)
    $calls = array(
        'wp_schedule_event' => array(2, null),
        'wp_schedule_single_event' => array(1, null),
        'wp_next_scheduled' => array(0, null),
        'wp_clear_scheduled_hook' => array(0, null),
        'wp_unschedule_hook' => array(0, null),
        'wp_unschedule_event' => array(1, null),
        'as_schedule_single_action' => array(1, 3),
        'as_schedule_recurring_action' => array(2, 4),
        'as_schedule_cron_action' => array(2, 4),
        'as_enqueue_async_action' => array(0, 2),
        'as_has_scheduled_action' => array(0, 2),
        'as_next_scheduled_action' => array(0, 2),
        'as_unschedule_action' => array(0, 2),
        'as_unschedule_all_actions' => array(0, 2),
    );
    $files = array(is_file($root . '/supcheckout.php') ? $root . '/supcheckout.php' : $root . '/UPayments.php');
    foreach (array('src', 'includes') as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    $parsed = array();
    $constants = array();
    foreach ($files as $path) {
        $tokens = token_get_all((string) file_get_contents($path));
        $parsed[$path] = $tokens;
        surface_collect_constants($tokens, $constants);
    }
    $references = array();
    foreach ($parsed as $tokens) {
        foreach (surface_find_calls($tokens, array_keys($calls)) as $call) {
            list($hook_index, $group_index) = $calls[$call['function']];
            $hook = surface_resolve_argument($call, $hook_index, $constants);
            if ($hook === null) {
                continue;
            }
            $reference = array('kind' => 'schedule_reference', 'function' => $call['function'], 'hook' => $hook);
            if ($group_index !== null) {
                $group = surface_resolve_argument($call, $group_index, $constants);
                $reference['group'] = $group === null ? '' : $group;
            }
            $references[json_encode($reference)] = $reference;
        }
    }
    return array_values($references);
}

/**
 * Collect "const NAME = 'literal';" per short class name.
 *
 * @param array<int, mixed> $tokens
 * @param array<string, array<string, string>> $constants
 * @return void
 */
function surface_collect_constants(array $tokens, array &$constants) {
    $class = '';
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS && !surface_prev_is($tokens, $i, T_DOUBLE_COLON)) {
            $name = surface_next_significant($tokens, $i);
            if ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
                $class = $tokens[$name][1];
            }
        }
        if ($class === '' || !is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
            continue;
        }
        $name = surface_next_significant($tokens, $i);
        $equals = $name === null ? null : surface_next_significant($tokens, $name);
        $value = $equals === null ? null : surface_next_significant($tokens, $equals);
        $end = $value === null ? null : surface_next_significant($tokens, $value);
        if ($end !== null && $tokens[$equals] === '=' && $tokens[$end] === ';'
            && is_array($tokens[$value]) && $tokens[$value][0] === T_CONSTANT_ENCAPSED_STRING
        ) {
            $constants[$class][$tokens[$name][1]] = surface_decode_literal($tokens[$value][1]);
        }
    }
}

/**
 * @param array<int, mixed> $tokens
 * @param array<int, string> $functions
 * @return array<int, array<string, mixed>> Each call: function, class, args (token lists).
 */
function surface_find_calls(array $tokens, array $functions) {
    $calls = array();
    $class = '';
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        if ($tokens[$i][0] === T_CLASS && !surface_prev_is($tokens, $i, T_DOUBLE_COLON)) {
            $name = surface_next_significant($tokens, $i);
            $class = ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) ? $tokens[$name][1] : $class;
        }
        $is_name = $tokens[$i][0] === T_STRING || (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED);
        $function = $is_name ? ltrim(strtolower($tokens[$i][1]), '\\') : '';
        if ($function === '' || !in_array($function, $functions, true)) {
            continue;
        }
        $open = surface_next_significant($tokens, $i);
        if ($open === null || $tokens[$open] !== '(' || surface_prev_is($tokens, $i, T_OBJECT_OPERATOR)
            || surface_prev_is($tokens, $i, T_DOUBLE_COLON) || surface_prev_is($tokens, $i, T_FUNCTION)
        ) {
            continue;
        }
        $args = array(array());
        $depth = 0;
        for ($j = $open + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, array('(', '[', '{'), true) || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                $depth++;
            } elseif (in_array($text, array(')', ']', '}'), true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $args[] = array();
                continue;
            }
            if (!is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $args[count($args) - 1][] = $token;
            }
        }
        $calls[] = array('function' => $function, 'class' => $class, 'args' => $args);
    }
    return $calls;
}

/**
 * Resolve a literal or class-constant argument; anything else is reported as such
 * so a dynamic hook name is visible in the snapshot instead of silently dropped.
 *
 * @param array<string, mixed> $call
 * @param int $index
 * @param array<string, array<string, string>> $constants
 * @return string|null Null when the argument is absent.
 */
function surface_resolve_argument(array $call, $index, array $constants) {
    if (!isset($call['args'][$index]) || $call['args'][$index] === array()) {
        return null;
    }
    $arg = $call['args'][$index];
    if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
        return surface_decode_literal($arg[0][1]);
    }
    // Owner::NAME, where Owner may tokenize as one name (PHP 8) or several (PHP 7.4).
    $text = '';
    $owner = '';
    $colon = null;
    foreach ($arg as $position => $token) {
        $text .= is_array($token) ? $token[1] : $token;
        if ($colon === null && is_array($token) && $token[0] === T_DOUBLE_COLON) {
            $colon = $position;
        } elseif ($colon === null) {
            $owner .= is_array($token) ? $token[1] : $token;
        }
    }
    if ($colon !== null && $colon === count($arg) - 2 && is_array($arg[$colon + 1]) && $arg[$colon + 1][0] === T_STRING) {
        $owner = in_array(strtolower($owner), array('self', 'static'), true) ? $call['class'] : substr((string) strrchr('\\' . $owner, '\\'), 1);
        if (isset($constants[$owner][$arg[$colon + 1][1]])) {
            return $constants[$owner][$arg[$colon + 1][1]];
        }
    }
    return '<dynamic:' . $text . '>';
}

/**
 * @param string $literal Quoted PHP string literal.
 * @return string
 */
function surface_decode_literal($literal) {
    $body = substr($literal, 1, -1);
    return $literal[0] === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), $body) : stripcslashes($body);
}

/**
 * @param array<int, mixed> $tokens
 * @param int $i
 * @return int|null
 */
function surface_next_significant(array $tokens, $i) {
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            return $j;
        }
    }
    return null;
}

/**
 * @param array<int, mixed> $tokens
 * @param int $i
 * @param int $type
 * @return bool
 */
function surface_prev_is(array $tokens, $i, $type) {
    for ($j = $i - 1; $j >= 0; $j--) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return is_array($tokens[$j]) && $tokens[$j][0] === $type;
    }
    return false;
}

/**
 * Child process: load the real main file under a recording stub for one context,
 * fire the bootstrap hooks in WordPress order, instantiate the registered gateway
 * and print the recorded surface as JSON.
 *
 * @param string $root Repository root.
 * @param string $context frontend, admin, cli or no_woocommerce.
 * @return void
 */
function surface_child($root, $context) {
    set_error_handler(static function ($errno, $message, $file, $line) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        fwrite(STDOUT, "PHP issue {$errno}: {$message} in {$file}:{$line}\n");
        exit(2);
    });
    $main = is_file($root . '/supcheckout.php') ? $root . '/supcheckout.php' : $root . '/UPayments.php';
    define('ABSPATH', __DIR__ . '/');
    if ($context === 'cli') {
        define('WP_CLI', true);
    }
    $GLOBALS['surface'] = array(
        'context' => $context,
        'main' => $main,
        'phase' => 'load',
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
            $bootstrap[] = 'admin_init';
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
    $hook = realpath((string) $file) === realpath($GLOBALS['surface']['main']) ? 'main_plugin_file' : basename((string) $file);
    return supcheckout_surface_record(array('kind' => $type, 'hook' => $hook, 'registered_in' => $GLOBALS['surface']['phase']));
}
function supcheckout_surface_schedule($function, $hook, array $extra) {
    return supcheckout_surface_record(array('kind' => 'schedule_call', 'function' => $function, 'hook' => (string) $hook) + $extra + array('registered_in' => $GLOBALS['surface']['phase']));
}
function supcheckout_surface_args_shape($args) {
    $shape = array();
    foreach ((array) $args as $key => $value) { $shape[] = $key . ':' . gettype($value); }
    return implode(',', $shape);
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return supcheckout_surface_hook('action', $hook, $callback, $priority, $accepted_args); }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return supcheckout_surface_hook('filter', $hook, $callback, $priority, $accepted_args); }
function register_activation_hook($file, $callback) { return supcheckout_surface_file_hook('activation', $file); }
function register_deactivation_hook($file, $callback) { return supcheckout_surface_file_hook('deactivation', $file); }
function register_uninstall_hook($file, $callback) { return supcheckout_surface_file_hook('uninstall', $file); }
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_clear_scheduled_hook($hook, $args = array()) { supcheckout_surface_schedule('wp_clear_scheduled_hook', $hook, array()); return 0; }
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return supcheckout_surface_schedule('wp_schedule_event', $hook, array('recurrence' => (string) $recurrence, 'args' => supcheckout_surface_args_shape($args))); }
function wp_schedule_single_event($timestamp, $hook, $args = array()) { return supcheckout_surface_schedule('wp_schedule_single_event', $hook, array('args' => supcheckout_surface_args_shape($args))); }
function as_has_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_next_scheduled_action($hook, $args = null, $group = '') { return false; }
function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false) { supcheckout_surface_schedule('as_schedule_single_action', $hook, array('group' => (string) $group, 'args' => supcheckout_surface_args_shape($args))); return 1; }
function as_schedule_recurring_action($timestamp, $interval, $hook, $args = array(), $group = '', $unique = false) { supcheckout_surface_schedule('as_schedule_recurring_action', $hook, array('group' => (string) $group, 'interval' => (int) $interval, 'args' => supcheckout_surface_args_shape($args))); return 1; }
function as_enqueue_async_action($hook, $args = array(), $group = '', $unique = false) { supcheckout_surface_schedule('as_enqueue_async_action', $hook, array('group' => (string) $group, 'args' => supcheckout_surface_args_shape($args))); return 1; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/supcheckout/'; }
function plugin_dir_path($file) { return dirname((string) $file) . '/'; }
function plugin_basename($file) { return 'supcheckout/' . basename((string) $file); }
function get_option($name, $default = false) { return $name === 'active_plugins' ? array(plugin_basename($GLOBALS['surface']['main'])) : $default; }
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
