<?php
/** Phase 9I executor harness: real preflight + real executor + frozen H12 identity source. */
$root = realpath(__DIR__ . '/../..');
define('ABSPATH', $root . '/');

// Shaped like WooCommerce's real WC_Meta_Data (checked against 11.1.2): an
// id/key/value row behind __get/__isset and get_data(), with no get_value().
class WC_Meta_Data {
    protected $current_data;
    protected $data;
    public function __construct($meta = array()) { $this->current_data = $meta; $this->data = $meta; }
    public function __isset($key) { return array_key_exists($key, $this->current_data); }
    public function __get($key) { return array_key_exists($key, $this->current_data) ? $this->current_data[$key] : null; }
    public function get_data() { return $this->data; }
}
class P9EOrder {
    public $id;
    public $customer_id;
    public $meta = array();
    public $refresh_throws = false;
    public function __construct($id, $uid) { $this->id = $id; $this->customer_id = $uid; }
    public function meta_exists($key) { return isset($this->meta[$key]) && count($this->meta[$key]) > 0; }
    public function get_meta($key, $single = true, $context = 'view') {
        $values = isset($this->meta[$key]) ? $this->meta[$key] : array();
        if ($single) return count($values) ? $values[0] : '';
        // Like WC_Data::get_meta($key, false): rows keep their position in the
        // order's whole meta list (index 0 only for the order's first meta row).
        $out = array();
        $position = 0;
        foreach ($this->meta as $meta_key => $meta_values) {
            foreach ($meta_values as $meta_value) {
                if ((string) $meta_key === (string) $key) {
                    $out[$position] = new WC_Meta_Data(array('id' => $position + 1, 'key' => (string) $meta_key, 'value' => $meta_value));
                }
                $position++;
            }
        }
        return $out;
    }
    public function read_meta_data($force = false) {
        if ($this->refresh_throws) throw new RuntimeException('refresh failed');
        return true;
    }
    public function get_customer_id() { return $this->customer_id; }
}
$GLOBALS['p9e'] = array();
function &p9e_state() { return $GLOBALS['p9e']; }
function p9e_reset() {
    $GLOBALS['p9e'] = array(
        'options' => array(), 'usermeta' => array(), 'orders' => array(),
        'option_writes' => 0, 'usermeta_writes' => 0, 'order_writes' => 0, 'provider_calls' => 0,
        'lock_acquires' => 0, 'lock_releases' => 0, 'lock_fail' => false,
        'mutate_after_lock' => null, 'mutation_done' => false,
        'fail_provenance_add' => false, 'fail_ledger_write' => false,
        'force_user_refresh_failure' => false, 'db_failure' => false,
    );
}
function get_current_blog_id() { return 1; }
function get_option($name, $default = false) {
    $s =& p9e_state(); return array_key_exists($name, $s['options']) ? $s['options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') {
    $s =& p9e_state(); $s['option_writes']++;
    if (array_key_exists($name, $s['options'])) return false;
    $s['options'][$name] = $value; return true;
}
function update_option($name, $value, $autoload = null) {
    $s =& p9e_state(); $s['option_writes']++; $s['options'][$name] = $value; return true;
}
function get_user_meta($uid, $key, $single = false) {
    $s =& p9e_state(); $values = isset($s['usermeta'][$uid][$key]) ? $s['usermeta'][$uid][$key] : array();
    return $single ? (count($values) ? $values[0] : '') : $values;
}
function metadata_exists($type, $uid, $key) {
    $s =& p9e_state(); return $type === 'user' && isset($s['usermeta'][$uid][$key]) && count($s['usermeta'][$uid][$key]);
}
function add_user_meta($uid, $key, $value, $unique = false) {
    $s =& p9e_state();
    if ($s['fail_provenance_add'] && strpos($key, '_upay_customer_token_v2_b1_') === 0) return false;
    $s['usermeta_writes']++;
    if (!isset($s['usermeta'][$uid])) $s['usermeta'][$uid] = array();
    if (!isset($s['usermeta'][$uid][$key])) $s['usermeta'][$uid][$key] = array();
    if ($unique && count($s['usermeta'][$uid][$key])) return false;
    $s['usermeta'][$uid][$key][] = $value; return true;
}
function update_user_meta($uid, $key, $value, $prev = '') {
    $s =& p9e_state();
    if ($key === 'simplixpay_upayments_migration_v1' && $s['fail_ledger_write']) return false;
    $s['usermeta_writes']++;
    if (!isset($s['usermeta'][$uid])) $s['usermeta'][$uid] = array();
    $s['usermeta'][$uid][$key] = array($value);
    return true;
}
function delete_user_meta($uid, $key, $value = '') {
    $s =& p9e_state(); $s['usermeta_writes']++;
    if (!isset($s['usermeta'][$uid][$key])) return false;
    if ($value === '' || $value === null) { unset($s['usermeta'][$uid][$key]); return true; }
    $remaining = array(); $deleted = false;
    foreach ($s['usermeta'][$uid][$key] as $existing) {
        if (!$deleted && $existing === $value) { $deleted = true; continue; }
        $remaining[] = $existing;
    }
    if (!$deleted) return false;
    if ($remaining) $s['usermeta'][$uid][$key] = $remaining; else unset($s['usermeta'][$uid][$key]);
    return true;
}
function clean_user_cache($uid) {
    if (p9e_state()['force_user_refresh_failure']) throw new RuntimeException('cache refresh failed');
    return true;
}
function maybe_unserialize($value) {
    if (!is_string($value)) return $value;
    $decoded = @unserialize($value); return ($decoded === false && $value !== 'b:0;') ? $value : $decoded;
}
function wc_get_order($id) { $s =& p9e_state(); return isset($s['orders'][$id]) ? $s['orders'][$id] : null; }
function wc_get_orders($args) {
    $s =& p9e_state();
    if (isset($args['meta_query'])) {
        $token = $args['meta_query'][0]['value']; $ids = array();
        foreach ($s['orders'] as $id => $order) {
            $vals = isset($order->meta['_upay_customer_unique_token']) ? $order->meta['_upay_customer_unique_token'] : array();
            foreach ($vals as $v) if (is_string($v) && $v === $token) { $ids[] = $id; break; }
        }
    } else {
        $uid = isset($args['customer_id']) ? (int) $args['customer_id'] : 0; $ids = array();
        foreach ($s['orders'] as $id => $order) if ((int) $order->customer_id === $uid) $ids[] = $id;
    }
    rsort($ids, SORT_NUMERIC); $limit = isset($args['limit']) ? (int) $args['limit'] : 20; $page = isset($args['paged']) ? (int) $args['paged'] : 1;
    $obj = new stdClass(); $obj->total = count($ids); $obj->max_num_pages = $obj->total ? (int) ceil($obj->total / $limit) : 0;
    $obj->orders = array_slice($ids, ($page - 1) * $limit, $limit); return $obj;
}
class P9EWpdb {
    public $usermeta = 'wp_usermeta';
    private $last_keys = array();
    public function esc_like($v) { return addcslashes($v, '_%\\'); }
    public function prepare($sql) {
        $args = func_get_args(); array_shift($args);
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) {
        $s =& p9e_state(); if ($s['db_failure']) return false;
        if (stripos($sql, 'GET_LOCK') !== false) {
            $s['lock_acquires']++;
            if ($s['lock_fail']) return '0';
            if (!$s['mutation_done'] && is_callable($s['mutate_after_lock'])) {
                $s['mutation_done'] = true; call_user_func($s['mutate_after_lock']);
            }
            return '1';
        }
        if (stripos($sql, 'RELEASE_LOCK') !== false) { $s['lock_releases']++; return '1'; }
        foreach ($s['usermeta'] as $uid => $keys) {
            foreach ($keys as $key => $values) if (strpos($key, '_upay_customer_token_v2_b1_') === 0) return $key;
        }
        return null;
    }
    public function query($sql) {
        $s =& p9e_state(); if ($s['db_failure']) return false;
        $uid = 1; if (preg_match('/user_id\s*=\s*([0-9]+)/i', $sql, $m)) $uid = (int) $m[1];
        $this->last_keys = array();
        if (isset($s['usermeta'][$uid])) foreach ($s['usermeta'][$uid] as $key => $values) if (strpos($key, '_upay_customer_token_v2_b1_') === 0) $this->last_keys[] = $key;
        return count($this->last_keys);
    }
    public function get_col($sql = null) { return $this->last_keys; }
    public function get_results($sql) {
        $s =& p9e_state(); if ($s['db_failure']) return null; $rows = array();
        foreach ($s['usermeta'] as $uid => $keys) foreach ($keys as $key => $values) {
            if (strpos($key, '_upay_customer_token_v2_b1_') !== 0) continue;
            foreach ($values as $value) { $r = new stdClass(); $r->user_id = $uid; $r->meta_key = $key; $r->meta_value = serialize($value); $rows[] = $r; }
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new P9EWpdb();

require_once $root . '/src/Takeover/TakeoverState.php';
require_once $root . '/src/Takeover/Keys.php';
require_once $root . '/src/Token/CustomerTokenIdentity.php';
require_once $root . '/src/Migration/MigrationPreflight.php';
require_once $root . '/src/Migration/MigrationExecutor.php';
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;
use Simplixi\SUPCheckout\Migration\MigrationPreflight;
use Simplixi\SUPCheckout\Migration\MigrationExecutor;

$pass = 0; $fail = 0;
function p9e_assert($c, $label) { global $pass, $fail; if ($c) { $pass++; echo "PASS: $label\n"; } else { $fail++; echo "FAIL: $label\n"; } }
function p9e_eq($a, $e, $label) { p9e_assert($a === $e, $label . ' expected=' . var_export($e, true) . ' got=' . var_export($a, true)); }
function p9e_order($id, $uid, $token) { $o = new P9EOrder($id, $uid); $o->meta['_upay_customer_unique_token'] = array($token); $s =& p9e_state(); $s['orders'][$id] = $o; return $o; }
function p9e_secret() {
    $secret = str_repeat('a', 64); $gen = str_repeat('b', 32);
    return array('version'=>1,'secret'=>$secret,'generation_id'=>$gen,'verifier'=>hash_hmac('sha256', CustomerTokenIdentity::VERIFIER_DOMAIN . '|1|' . $gen, $secret));
}
function p9e_scope($secret) { return substr(hash_hmac('sha256', '1|live|api-key', $secret['secret']), 0, 32); }
function p9e_snapshot($o, $scope, $gen) {
    $o->meta['_upay_customer_token_kind_v1'] = array(CustomerTokenIdentity::KIND_LEGACY_COMPAT);
    $o->meta['_upay_customer_token_scope_v1'] = array($scope);
    $o->meta['_upay_customer_token_generation_v1'] = array($gen);
}
function p9e_counters() { $s =& p9e_state(); return array($s['option_writes'],$s['usermeta_writes'],$s['order_writes'],$s['provider_calls']); }
function p9e_no_order_provider($label) { $s =& p9e_state(); p9e_eq($s['order_writes'],0,$label.' order writes=0'); p9e_eq($s['provider_calls'],0,$label.' provider calls=0'); }

// 1. Dry-run unscoped legacy: no mutation at all.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678');
$r = MigrationExecutor::execute(1,'api-key',false,true);
p9e_eq($r['success'],true,'P9E-01 dry-run success'); p9e_eq($r['reason'],'dry_run_migratable','P9E-01 reason'); p9e_eq(p9e_counters(),array(0,0,0,0),'P9E-01 zero mutations/provider');
p9e_assert(!isset($r['preflight']['migration']['token']),'P9E-01 preflight token redacted');

// 2. Full migration from missing secret/unscoped token.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678');
$r = MigrationExecutor::execute(1,'api-key',false,false);
p9e_eq($r['success'],true,'P9E-02 migrate success'); p9e_eq($r['reason'],'migrated','P9E-02 reason'); p9e_eq($r['migrated'],true,'P9E-02 migrated flag');
p9e_eq($r['secret_created'],true,'P9E-02 secret created'); p9e_eq($r['provenance_created'],true,'P9E-02 provenance created'); p9e_eq($r['ledger_written'],true,'P9E-02 ledger written'); p9e_no_order_provider('P9E-02');
$secret = CustomerTokenIdentity::read_existing_secret_record(); p9e_eq($secret['state'],CustomerTokenIdentity::SECRET_VALID,'P9E-02 secret valid');
$ctx = CustomerTokenIdentity::read_existing_identity_context('api-key',false); $prov = CustomerTokenIdentity::read_provenance(1,$ctx['scope'],$ctx['generation_id']);
p9e_eq($prov['state'],CustomerTokenIdentity::STATE_VALID,'P9E-02 provenance valid'); p9e_eq($prov['record']['kind'],CustomerTokenIdentity::KIND_LEGACY_COMPAT,'P9E-02 legacy kind'); p9e_eq($prov['record']['source'],CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE,'P9E-02 legacy source'); p9e_eq($prov['record']['token'],'12345678','P9E-02 exact token');
$ledger = get_user_meta(1,MigrationExecutor::LEDGER_KEY,true); p9e_assert(is_array($ledger) && !isset($ledger['token']),'P9E-02 ledger has no raw token'); p9e_eq($ledger['token_digest'],hash('sha256','12345678'),'P9E-02 ledger digest exact');

// 3. Idempotent rerun adds no writes and remains clean.
$before = p9e_counters(); $r2 = MigrationExecutor::execute(1,'api-key',false,false); $after = p9e_counters();
p9e_eq($r2['success'],true,'P9E-03 rerun success'); p9e_eq($r2['reason'],'already_clean','P9E-03 already clean'); p9e_eq($after,$before,'P9E-03 no additional writes');

// 4. Existing secret/current-scope legacy orphan: provenance only, no option write.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); $sec=p9e_secret(); p9e_state()['options'][CustomerTokenIdentity::SECRET_OPTION]=$sec; $scope=p9e_scope($sec); $o=p9e_order(10,1,'12345678'); p9e_snapshot($o,$scope,$sec['generation_id']);
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],true,'P9E-04 current orphan migrated'); p9e_eq(p9e_state()['option_writes'],0,'P9E-04 no secret write'); p9e_eq($r['provenance_created'],true,'P9E-04 provenance created'); p9e_no_order_provider('P9E-04');

// 5. Malformed secret: no lock/mutation.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_state()['options'][CustomerTokenIdentity::SECRET_OPTION]=array('version'=>1); p9e_order(10,1,'12345678');
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],false,'P9E-05 malformed blocked'); p9e_eq($r['classification'],MigrationPreflight::BLOCKED,'P9E-05 BLOCKED'); p9e_eq(p9e_counters(),array(0,0,0,0),'P9E-05 zero mutations/provider');

// 6. Lock contention: preflight may read, but mutation is zero.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678'); p9e_state()['lock_fail']=true;
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['reason'],'lock_contention','P9E-06 lock contention'); p9e_eq(p9e_counters(),array(0,0,0,0),'P9E-06 zero mutations/provider');

// 7. Evidence changes after lock: locked preflight fails closed before writes.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678');
p9e_state()['mutate_after_lock']=function(){ p9e_order(11,2,'12345678'); };
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],false,'P9E-07 changed evidence fails'); p9e_eq($r['classification'],MigrationPreflight::BLOCKED,'P9E-07 becomes BLOCKED'); p9e_eq(p9e_state()['option_writes'],0,'P9E-07 no option writes'); p9e_eq(p9e_state()['usermeta_writes'],0,'P9E-07 no usermeta writes'); p9e_no_order_provider('P9E-07');

// 8. Provenance persistence failure after safe secret creation leaves retry-safe root.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678'); p9e_state()['fail_provenance_add']=true;
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],false,'P9E-08 persist failure'); p9e_eq($r['reason'],'provenance_persist_failed','P9E-08 reason'); p9e_eq(CustomerTokenIdentity::read_existing_secret_record()['state'],CustomerTokenIdentity::SECRET_VALID,'P9E-08 valid secret remains'); p9e_no_order_provider('P9E-08');
p9e_state()['fail_provenance_add']=false; $r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],true,'P9E-08 retry succeeds'); p9e_eq($r['reason'],'migrated','P9E-08 retry migrated');

// 9. Ledger failure never rolls back verified identity; result surfaces observability failure.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678'); p9e_state()['fail_ledger_write']=true;
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['success'],true,'P9E-09 identity success despite ledger'); p9e_eq($r['reason'],'migrated_ledger_write_failed','P9E-09 ledger failure surfaced'); p9e_eq($r['ledger_written'],false,'P9E-09 ledger false');
$ctx=CustomerTokenIdentity::read_existing_identity_context('api-key',false); $prov=CustomerTokenIdentity::read_provenance(1,$ctx['scope'],$ctx['generation_id']); p9e_eq($prov['state'],CustomerTokenIdentity::STATE_VALID,'P9E-09 provenance remains valid'); p9e_no_order_provider('P9E-09');

// 10. Cross-user conflict never locks or writes.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); p9e_order(10,1,'12345678'); p9e_order(11,2,'12345678');
$r=MigrationExecutor::execute(1,'api-key',false,false); p9e_eq($r['classification'],MigrationPreflight::BLOCKED,'P9E-10 conflict blocked'); p9e_eq(p9e_counters(),array(0,0,0,0),'P9E-10 zero mutations/provider');

// 11. Dry-run on already-clean authoritative provenance remains zero-write.
p9e_reset(); $GLOBALS['wpdb'] = new P9EWpdb(); $sec=p9e_secret(); p9e_state()['options'][CustomerTokenIdentity::SECRET_OPTION]=$sec; $scope=p9e_scope($sec); p9e_order(10,1,'12345678');
$record=array('version'=>CustomerTokenIdentity::SCHEMA_VERSION,'kind'=>CustomerTokenIdentity::KIND_LEGACY_COMPAT,'token'=>'12345678','source'=>CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE,'scope'=>$scope,'secret_generation_id'=>$sec['generation_id'],'established_at_gmt'=>1700000000); $key=CustomerTokenIdentity::get_user_meta_key('1',$scope); p9e_state()['usermeta'][1][$key]=array($record);
$r=MigrationExecutor::execute(1,'api-key',false,true); p9e_eq($r['success'],true,'P9E-11 clean dry run success'); p9e_eq($r['reason'],'already_clean','P9E-11 already clean'); p9e_eq(p9e_counters(),array(0,0,0,0),'P9E-11 zero mutations/provider');

// Final source-level guarantees: executor never writes order meta and never references provider transport APIs.
$source=file_get_contents($root.'/src/Migration/MigrationExecutor.php'); p9e_assert(strpos($source,'add_meta_data(')===false && strpos($source,'save_meta_data(')===false && strpos($source,'delete_meta_data(')===false,'P9E-STATIC no order meta mutation calls');
p9e_assert(strpos($source,'curl_')===false && strpos($source,'wp_remote_')===false && strpos($source,'getSavedCards')===false,'P9E-STATIC no provider transport calls');
p9e_assert(strpos($source,CustomerTokenIdentity::SOURCE_CREATE_201)===false,'P9E-STATIC executor never references create_201');

echo "\n--- Phase 9I Executor Report ---\nPASS: $pass\nFAIL: $fail\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail===0?0:1);
