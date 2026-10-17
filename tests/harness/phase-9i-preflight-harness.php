<?php
/**
 * Phase 9I migration preflight harness.
 *
 * Standalone by design: it loads the frozen H12 token-identity class plus the
 * new Simplix migration preflight, but does not load the payment gateway.
 * Every scenario asserts the preflight performs zero writes/provider calls.
 */

$root = realpath(__DIR__ . '/../..');
define('ABSPATH', $root . '/');

class WC_Meta_Data {
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function get_value() { return $this->value; }
}

class Phase9IFakeOrder {
    public $id;
    public $customer_id;
    public $meta = array();
    public $refresh_throws = false;
    public $save_calls = 0;

    public function __construct($id, $customer_id) {
        $this->id = $id;
        $this->customer_id = $customer_id;
    }
    public function meta_exists($key) {
        return isset($this->meta[$key]) && count($this->meta[$key]) > 0;
    }
    public function get_meta($key, $single = true, $context = 'view') {
        $values = isset($this->meta[$key]) ? $this->meta[$key] : array();
        if ($single) {
            return count($values) ? $values[0] : '';
        }
        $out = array();
        foreach ($values as $value) { $out[] = new WC_Meta_Data($value); }
        return $out;
    }
    public function read_meta_data($force = false) {
        if ($this->refresh_throws) { throw new RuntimeException('synthetic refresh failure'); }
        return true;
    }
    public function get_customer_id() { return $this->customer_id; }
    public function add_meta_data($key, $value, $unique = false) {
        if (!isset($this->meta[$key])) { $this->meta[$key] = array(); }
        if ($unique && count($this->meta[$key])) { return; }
        $this->meta[$key][] = $value;
        $GLOBALS['p9']['order_writes']++;
    }
    public function save() { $this->save_calls++; $GLOBALS['p9']['order_writes']++; return $this->id; }
}

$GLOBALS['p9'] = array();
function p9_reset() {
    $GLOBALS['p9'] = array(
        'options' => array(),
        'usermeta' => array(),
        'orders' => array(),
        'history_ids_override' => null,
        'query_exception' => false,
        'malformed_query' => false,
        'option_writes' => 0,
        'usermeta_writes' => 0,
        'order_writes' => 0,
        'provider_calls' => 0,
        'db_failure' => false,
    );
}
function &p9_state() { return $GLOBALS['p9']; }

function get_current_blog_id() { return 1; }
function get_option($name, $default = false) {
    $s =& p9_state();
    return array_key_exists($name, $s['options']) ? $s['options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') {
    $s =& p9_state(); $s['option_writes']++;
    if (array_key_exists($name, $s['options'])) return false;
    $s['options'][$name] = $value; return true;
}
function update_option($name, $value, $autoload = null) {
    $s =& p9_state(); $s['option_writes']++; $s['options'][$name] = $value; return true;
}
function get_user_meta($user_id, $key, $single = false) {
    $s =& p9_state();
    $values = isset($s['usermeta'][$user_id][$key]) ? $s['usermeta'][$user_id][$key] : array();
    return $single ? (count($values) ? $values[0] : '') : $values;
}
function metadata_exists($type, $user_id, $key) {
    $s =& p9_state();
    return $type === 'user' && isset($s['usermeta'][$user_id][$key]) && count($s['usermeta'][$user_id][$key]);
}
function add_user_meta($user_id, $key, $value, $unique = false) {
    $s =& p9_state(); $s['usermeta_writes']++;
    if (!isset($s['usermeta'][$user_id])) $s['usermeta'][$user_id] = array();
    if (!isset($s['usermeta'][$user_id][$key])) $s['usermeta'][$user_id][$key] = array();
    if ($unique && count($s['usermeta'][$user_id][$key])) return false;
    $s['usermeta'][$user_id][$key][] = $value; return true;
}
function delete_user_meta($user_id, $key, $value = '') { p9_state()['usermeta_writes']++; return false; }
function update_user_meta($user_id, $key, $value, $prev = '') { return add_user_meta($user_id, $key, $value, false); }
function clean_user_cache($user_id) { return true; }
function maybe_unserialize($value) {
    if (!is_string($value)) return $value;
    $decoded = @unserialize($value);
    return ($decoded === false && $value !== 'b:0;') ? $value : $decoded;
}
function wc_get_order($order_id) {
    $s =& p9_state();
    return isset($s['orders'][$order_id]) ? $s['orders'][$order_id] : null;
}
function wc_get_orders($args) {
    $s =& p9_state();
    if ($s['query_exception']) throw new RuntimeException('synthetic query failure');
    if ($s['malformed_query']) return null;

    if (isset($args['meta_query'])) {
        $token = $args['meta_query'][0]['value'];
        $ids = array();
        foreach ($s['orders'] as $id => $order) {
            $values = isset($order->meta['_upay_customer_unique_token']) ? $order->meta['_upay_customer_unique_token'] : array();
            foreach ($values as $value) {
                if (is_string($value) && $value === $token) { $ids[] = $id; break; }
            }
        }
    } elseif ($s['history_ids_override'] !== null) {
        $ids = $s['history_ids_override'];
    } else {
        $uid = isset($args['customer_id']) ? (int) $args['customer_id'] : 0;
        $ids = array();
        foreach ($s['orders'] as $id => $order) {
            if ((int) $order->customer_id === $uid) $ids[] = $id;
        }
    }

    rsort($ids, SORT_NUMERIC);
    $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
    $page = isset($args['paged']) ? (int) $args['paged'] : 1;
    $total = count($ids);
    $slice = array_slice($ids, ($page - 1) * $limit, $limit);
    $obj = new stdClass();
    $obj->orders = $slice;
    $obj->total = $total;
    $obj->max_num_pages = $total === 0 ? 0 : (int) ceil($total / $limit);
    return $obj;
}

class Phase9IWpdb {
    public $usermeta = 'wp_usermeta';
    private $last_keys = array();
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql) {
        $args = func_get_args(); array_shift($args);
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) {
        $s =& p9_state(); if ($s['db_failure']) return false;
        foreach ($s['usermeta'] as $uid => $keys) {
            foreach ($keys as $key => $values) {
                if (strpos($key, '_upay_customer_token_v2_b1_') === 0) return $key;
            }
        }
        return null;
    }
    public function query($sql) {
        $s =& p9_state(); if ($s['db_failure']) return false;
        $uid = 1;
        if (preg_match('/user_id\s*=\s*([0-9]+)/i', $sql, $m)) $uid = (int) $m[1];
        $this->last_keys = array();
        if (isset($s['usermeta'][$uid])) {
            foreach ($s['usermeta'][$uid] as $key => $values) {
                if (strpos($key, '_upay_customer_token_v2_b1_') === 0) $this->last_keys[] = $key;
            }
        }
        return count($this->last_keys);
    }
    public function get_col($sql = null) {
        if (is_string($sql) && $sql !== '') {
            $result = $this->query($sql);
            if ($result === false) {
                return null;
            }
        }
        return $this->last_keys;
    }
    public function get_results($sql) {
        $s =& p9_state(); if ($s['db_failure']) return null;
        $rows = array();
        foreach ($s['usermeta'] as $uid => $keys) {
            foreach ($keys as $key => $values) {
                if (strpos($key, '_upay_customer_token_v2_b1_') !== 0) continue;
                foreach ($values as $value) {
                    $row = new stdClass();
                    $row->user_id = $uid;
                    $row->meta_key = $key;
                    $row->meta_value = serialize($value);
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new Phase9IWpdb();

require_once $root . '/src/Takeover/TakeoverState.php';
require_once $root . '/src/Takeover/Keys.php';
require_once $root . '/src/Token/CustomerTokenIdentity.php';
require_once $root . '/src/Migration/MigrationPreflight.php';

use Simplixi\SUPCheckout\Migration\MigrationPreflight;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

$pass = 0; $fail = 0;
function p9_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$label}\n"; }
    else { $fail++; echo "FAIL: {$label}\n"; }
}
function p9_eq($actual, $expected, $label) { p9_assert($actual === $expected, $label . ' expected=' . var_export($expected, true) . ' got=' . var_export($actual, true)); }
function p9_no_writes($label) {
    $s =& p9_state();
    p9_eq($s['option_writes'], 0, $label . ' option writes=0');
    p9_eq($s['usermeta_writes'], 0, $label . ' usermeta writes=0');
    p9_eq($s['order_writes'], 0, $label . ' order writes=0');
    p9_eq($s['provider_calls'], 0, $label . ' provider calls=0');
}
function p9_secret($generation = null) {
    if ($generation === null) $generation = str_repeat('b', 32);
    $secret = str_repeat('a', 64);
    return array(
        'version' => 1,
        'secret' => $secret,
        'generation_id' => $generation,
        'verifier' => hash_hmac('sha256', CustomerTokenIdentity::VERIFIER_DOMAIN . '|1|' . $generation, $secret),
    );
}
function p9_scope($api_key, $is_test, $secret_record) {
    $mode = $is_test ? 'test' : 'live';
    return substr(hash_hmac('sha256', '1|' . $mode . '|' . $api_key, $secret_record['secret']), 0, 32);
}
function p9_order($id, $uid, $token = null) {
    $o = new Phase9IFakeOrder($id, $uid);
    if ($token !== null) $o->meta['_upay_customer_unique_token'] = array($token);
    p9_state()['orders'][$id] = $o;
    return $o;
}
function p9_snapshot($order, $kind, $scope, $generation) {
    $order->meta['_upay_customer_token_kind_v1'] = array($kind);
    $order->meta['_upay_customer_token_scope_v1'] = array($scope);
    $order->meta['_upay_customer_token_generation_v1'] = array($generation);
}
function p9_provenance($uid, $scope, $generation, $kind, $token) {
    $key = CustomerTokenIdentity::get_user_meta_key('1', $scope);
    $source = $kind === CustomerTokenIdentity::KIND_CANONICAL
        ? CustomerTokenIdentity::SOURCE_CREATE_201
        : CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE;
    p9_state()['usermeta'][$uid][$key] = array(array(
        'version' => CustomerTokenIdentity::SCHEMA_VERSION,
        'kind' => $kind,
        'token' => $token,
        'source' => $source,
        'scope' => $scope,
        'secret_generation_id' => $generation,
        'established_at_gmt' => 1700000000,
    ));
}
function p9_run($label, $setup, $expected_class, $expected_reason) {
    p9_reset(); $GLOBALS['wpdb'] = new Phase9IWpdb();
    $setup();
    $r = MigrationPreflight::inspect(1, 'api-key', false);
    p9_eq($r['classification'], $expected_class, $label . ' classification');
    p9_eq($r['reason'], $expected_reason, $label . ' reason');
    p9_no_writes($label);
    return $r;
}

// Fresh account: no secret, no history, no provenance.
p9_run('P9I-01 fresh', function () {}, MigrationPreflight::CLEAN, 'no_migration_required');

// #1 unscoped legacy token: attributable and unique -> MIGRATABLE.
$r = p9_run('P9I-02 unscoped legacy', function () { p9_order(10, 1, '12345678'); }, MigrationPreflight::MIGRATABLE, 'attributable_legacy_identity');
p9_assert(isset($r['migration']['token']) && $r['migration']['token'] === '12345678', 'P9I-02 migration token exact');
p9_assert($r['migration']['requires_secret_creation'] === true, 'P9I-02 requires secret creation');

// #3 cross-user order conflict.
p9_run('P9I-03 cross-user order conflict', function () {
    p9_order(10, 1, '12345678'); p9_order(11, 2, '12345678');
}, MigrationPreflight::BLOCKED, 'cross_user_token_conflict');

// #13 malformed secret is distinct from missing and never replaced.
p9_run('P9I-04 malformed secret', function () { p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = array('version' => 1); }, MigrationPreflight::BLOCKED, 'malformed_secret');

// Missing secret + provenance artifact blocks secret recreation.
p9_run('P9I-05 missing secret with provenance', function () {
    p9_state()['usermeta'][2]['_upay_customer_token_v2_b1_' . str_repeat('a', 32)] = array(array('token' => '12345678'));
}, MigrationPreflight::BLOCKED, 'missing_secret_with_provenance');

// #6 card-token-only history.
p9_run('P9I-06 card only', function () {
    $o = p9_order(10, 1, null); $o->meta['_upay_credit_card_token'] = array('card-token');
}, MigrationPreflight::BLOCKED, 'card_without_customer_identity');

// #7 prior-scope same-generation provenance.
p9_run('P9I-07 prior scope', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $old_scope = str_repeat('c', 32); p9_provenance(1, $old_scope, $secret['generation_id'], CustomerTokenIdentity::KIND_LEGACY_COMPAT, '12345678');
}, MigrationPreflight::BLOCKED, 'prior_scope_same_generation');

// #8 non-scalar historical evidence.
p9_run('P9I-08 non-scalar metadata', function () {
    $o = p9_order(10, 1, null); $o->meta['_upay_customer_unique_token'] = array(array('bad'));
}, MigrationPreflight::BLOCKED, 'non_scalar_or_duplicate_metadata');

// #9 orphan snapshot metadata without customer token.
p9_run('P9I-09 orphan metadata', function () {
    $o = p9_order(10, 1, null); $o->meta['_upay_customer_token_kind_v1'] = array(CustomerTokenIdentity::KIND_LEGACY_COMPAT);
}, MigrationPreflight::BLOCKED, 'orphan_snapshot_metadata');

// #10 >200 incomplete history.
p9_run('P9I-10 over cap', function () {
    $ids = array(); for ($i = 1; $i <= 201; $i++) $ids[] = $i; p9_state()['history_ids_override'] = $ids;
    for ($i = 1; $i <= 201; $i++) p9_order($i, 1, null);
}, MigrationPreflight::INDETERMINATE, 'incomplete_history_scan');

// #11 unloadable order.
p9_run('P9I-11 unloadable', function () { p9_state()['history_ids_override'] = array(999); }, MigrationPreflight::INDETERMINATE, 'unloadable_order');

// #12 force-refresh failure.
p9_run('P9I-12 refresh failure', function () { $o = p9_order(10, 1, '12345678'); $o->refresh_throws = true; }, MigrationPreflight::INDETERMINATE, 'force_refresh_failed');

// #5 secret generation mismatch in scoped history.
p9_run('P9I-13 generation mismatch', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret); $o = p9_order(10, 1, '12345678');
    p9_snapshot($o, CustomerTokenIdentity::KIND_LEGACY_COMPAT, $scope, str_repeat('d', 32));
}, MigrationPreflight::BLOCKED, 'secret_generation_mismatch');

// #2 current-scope orphan legacy snapshot is migratable.
$r = p9_run('P9I-14 current-scope legacy orphan', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret); $o = p9_order(10, 1, '12345678');
    p9_snapshot($o, CustomerTokenIdentity::KIND_LEGACY_COMPAT, $scope, $secret['generation_id']);
}, MigrationPreflight::MIGRATABLE, 'attributable_legacy_identity');
p9_assert($r['migration']['requires_secret_creation'] === false, 'P9I-14 existing secret retained');

// Orphan canonical snapshot cannot be promoted without Create-201 provenance.
p9_run('P9I-15 orphan canonical blocked', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret); $o = p9_order(10, 1, '12345678');
    p9_snapshot($o, CustomerTokenIdentity::KIND_CANONICAL, $scope, $secret['generation_id']);
}, MigrationPreflight::BLOCKED, 'orphan_canonical_without_provenance');

// Same-user conflicting historical tokens are blocked.
p9_run('P9I-16 conflicting tokens', function () { p9_order(10, 1, '12345678'); p9_order(11, 1, '87654321'); }, MigrationPreflight::BLOCKED, 'conflicting_customer_tokens');

// Cross-user provenance conflict is blocked.
p9_run('P9I-17 cross-user provenance conflict', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret); $o = p9_order(10, 1, '12345678');
    p9_snapshot($o, CustomerTokenIdentity::KIND_LEGACY_COMPAT, $scope, $secret['generation_id']);
    p9_provenance(2, str_repeat('e', 32), $secret['generation_id'], CustomerTokenIdentity::KIND_LEGACY_COMPAT, '12345678');
}, MigrationPreflight::BLOCKED, 'cross_user_token_conflict');

// Valid current provenance + consistent history is CLEAN.
p9_run('P9I-18 valid current provenance', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret);
    p9_provenance(1, $scope, $secret['generation_id'], CustomerTokenIdentity::KIND_LEGACY_COMPAT, '12345678');
    p9_order(10, 1, '12345678');
}, MigrationPreflight::CLEAN, 'current_provenance_valid');

// Valid provenance contradicted by history is blocked.
p9_run('P9I-19 provenance/history conflict', function () {
    $secret = p9_secret(); p9_state()['options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
    $scope = p9_scope('api-key', false, $secret);
    p9_provenance(1, $scope, $secret['generation_id'], CustomerTokenIdentity::KIND_LEGACY_COMPAT, '12345678');
    p9_order(10, 1, '87654321');
}, MigrationPreflight::BLOCKED, 'historical_token_conflicts_with_provenance');

// DB uncertainty is indeterminate and still write-free.
p9_run('P9I-20 DB uncertainty', function () { p9_state()['db_failure'] = true; }, MigrationPreflight::INDETERMINATE, 'provenance_query_failed');

echo "\n--- Phase 9I Preflight Report ---\nPASS: {$pass}\nFAIL: {$fail}\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
