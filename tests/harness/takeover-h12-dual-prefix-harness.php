<?php
/**
 * H12 provenance under both prefixes (Sub-project 0b Task 2).
 *
 * The takeover copies `_upay_customer_token_v2_b{blog}_{scope}` user meta to
 * `_supcheckout_customer_token_v2_b{blog}_{scope}` and the token-identity secret to
 * `supcheckout_token_identity_secret_v2`; Finalize later deletes the legacy rows. The
 * legacy prefix is 25 characters and the new one 32, so every scope is sliced by the
 * prefix that matched. The presence probe must never conclude "absent" (and mint a new
 * secret, orphaning every saved-card provenance record) while either prefix has rows.
 *
 * These reads and the presence probe check BOTH names in every takeover state, on
 * purpose: a stale new-name row left by a rollback, or a legacy row Finalize removed,
 * must always push the decision toward fail-closed (never mint, census reports it).
 *
 * Runs the real CustomerTokenIdentity and the real TakeoverState inside the H12 sandbox
 * (_bootstrap.php); the takeover record is written to the sandbox option store.
 */

require __DIR__ . '/_bootstrap.php';

use Simplixi\SUPCheckout\Migration\MigrationPreflight;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

$pass = 0;
$fail = 0;

function h12dp_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

/**
 * The H12 WpdbStub only ever matches the legacy prefix. This one evaluates the LIKE
 * patterns the production SQL actually carries, so both prefixes are exercised.
 */
class Takeover_Dual_Prefix_Wpdb extends WpdbStub {
    public $last_error = '';
    public $sql_log = array();

    private function like_patterns($sql) {
        preg_match_all("/LIKE '((?:[^']|'')*)'/", (string) $sql, $matches);
        $patterns = array();
        foreach ($matches[1] as $raw) {
            $patterns[] = str_replace("''", "'", $raw);
        }
        return $patterns;
    }

    private function key_matches($key, array $patterns) {
        foreach ($patterns as $pattern) {
            if (supcheckout_h12dp_like($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    public function query($sql) {
        $this->sql_log[] = (string) $sql;
        if (is_string($sql) && stripos($sql, 'usermeta') !== false) {
            $patterns = $this->like_patterns($sql);
            $state =& upay_test_state();
            foreach ($state['usermeta'] as $keys) {
                foreach (array_keys($keys) as $key) {
                    if ($this->key_matches($key, $patterns)) {
                        return 1;
                    }
                }
            }
            return 0;
        }
        return parent::query($sql);
    }

    public function get_var($sql = null) {
        if (is_string($sql) && strpos($sql, 'SELECT meta_key FROM') !== false) {
            $this->sql_log[] = $sql;
            $patterns = $this->like_patterns($sql);
            $state =& upay_test_state();
            foreach ($state['usermeta'] as $keys) {
                foreach (array_keys($keys) as $key) {
                    if ($this->key_matches($key, $patterns)) {
                        return $key;
                    }
                }
            }
            return null;
        }
        return parent::get_var($sql);
    }

    public function get_col($sql = null) {
        $this->sql_log[] = (string) $sql;
        $patterns = $this->like_patterns($sql);
        $user_id = preg_match('/user_id = ([0-9]+)/', (string) $sql, $m) ? (int) $m[1] : 0;
        $state =& upay_test_state();
        $found = array();
        if (isset($state['usermeta'][$user_id])) {
            foreach (array_keys($state['usermeta'][$user_id]) as $key) {
                if ($this->key_matches($key, $patterns)) {
                    $found[] = $key;
                }
            }
        }
        return $found;
    }
}

function supcheckout_h12dp_like($pattern, $subject) {
    $regex = '';
    $length = strlen($pattern);
    for ($i = 0; $i < $length; $i++) {
        $char = $pattern[$i];
        if ($char === '\\' && $i + 1 < $length) {
            $regex .= preg_quote($pattern[++$i], '/');
        } elseif ($char === '%') {
            $regex .= '.*';
        } elseif ($char === '_') {
            $regex .= '.';
        } else {
            $regex .= preg_quote($char, '/');
        }
    }
    return preg_match('/^' . $regex . '$/s', (string) $subject) === 1;
}

function h12dp_secret() {
    $secret = str_repeat('ab', 32);
    $generation = str_repeat('c', 32);
    return array(
        'version' => 1,
        'secret' => $secret,
        'generation_id' => $generation,
        'verifier' => hash_hmac('sha256', 'upayments_token_identity_secret_record_v1|1|' . $generation, $secret),
    );
}

function h12dp_record($scope, $generation, $token = '12345678') {
    return array(
        'version' => CustomerTokenIdentity::SCHEMA_VERSION,
        'kind' => CustomerTokenIdentity::KIND_CANONICAL,
        'token' => $token,
        'source' => CustomerTokenIdentity::SOURCE_CREATE_201,
        'scope' => $scope,
        'secret_generation_id' => $generation,
        'established_at_gmt' => 1700000000,
    );
}

function h12dp_reset($status) {
    upay_reset_state();
    $GLOBALS['wpdb'] = new Takeover_Dual_Prefix_Wpdb();
    if ($status !== 'idle') {
        upay_test_state()['options'][TakeoverState::OPTION] = json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
    }
    TakeoverState::flush_cache();
}

function h12dp_private($method, array $args = array(), $class = CustomerTokenIdentity::class) {
    $reflection = new ReflectionMethod($class, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs(null, $args);
}

$api_key = 'h12-dual-prefix-key';
$secret = h12dp_secret();
$generation = $secret['generation_id'];
$legacy_prefix = '_upay_customer_token_v2_b1_';
$new_prefix = '_supcheckout_customer_token_v2_b1_';
h12dp_assert(strlen('_upay_customer_token_v2_b') === 25 && strlen('_supcheckout_customer_token_v2_b') === 32, 'the two provenance prefixes differ in length (25 vs 32)');

// --- 1. Never switched: legacy names only, exactly as before. ---
h12dp_reset('idle');
upay_test_state()['options']['upayments_token_identity_secret_v2'] = $secret;
$ctx = CustomerTokenIdentity::read_existing_identity_context($api_key, false);
$scope = $ctx['scope'];
h12dp_assert($ctx['state'] === 'valid' && is_string($scope), 'idle: the legacy secret derives the scope');
h12dp_assert(CustomerTokenIdentity::get_user_meta_key('1', $scope) === $legacy_prefix . $scope, 'idle: the exact key builder still writes the legacy key');
upay_test_state()['usermeta'][1][$legacy_prefix . $scope] = array(h12dp_record($scope, $generation));
h12dp_assert(CustomerTokenIdentity::read_provenance(1, $scope, $generation)['state'] === 'valid', 'idle: legacy provenance verifies');
h12dp_assert(CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation)['state'] === 'same_generation_only', 'idle: the scope scan reads the legacy record');

// --- 2. After Finalize: only the new names remain. ---
h12dp_reset('finalized');
upay_test_state()['options']['supcheckout_token_identity_secret_v2'] = $secret;
upay_test_state()['usermeta'][1][$new_prefix . $scope] = array(h12dp_record($scope, $generation));
$ctx = CustomerTokenIdentity::read_existing_identity_context($api_key, false);
h12dp_assert($ctx['state'] === 'valid' && $ctx['scope'] === $scope && $ctx['generation_id'] === $generation, 'finalized: the copied secret yields the same scope and generation');
h12dp_assert(CustomerTokenIdentity::get_user_meta_key('1', $scope) === $new_prefix . $scope, 'finalized: the exact key builder writes the new key');
h12dp_assert(CustomerTokenIdentity::read_provenance(1, $scope, $generation)['state'] === 'valid', 'finalized: saved-card provenance still verifies under the new prefix');
$scan = CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation);
h12dp_assert($scan['state'] === 'same_generation_only', 'finalized: the scope scan slices the 32-character prefix correctly (' . $scan['state'] . ')');
h12dp_assert(h12dp_private('get_or_create_secret_record') === $secret, 'finalized: the existing (new) secret is returned, never re-minted');

// --- 3. After Finalize with the new secret also gone: fail closed, mint nothing. ---
h12dp_reset('finalized');
upay_test_state()['usermeta'][1][$new_prefix . $scope] = array(h12dp_record($scope, $generation));
$creates_before = upay_test_state()['option_creates'];
h12dp_assert(h12dp_private('get_or_create_secret_record') === null, 'finalized, secret absent: the presence probe fails closed');
h12dp_assert(upay_test_state()['option_creates'] === $creates_before
    && !array_key_exists('supcheckout_token_identity_secret_v2', upay_test_state()['options'])
    && !array_key_exists('upayments_token_identity_secret_v2', upay_test_state()['options']), 'finalized, secret absent: NO new secret is minted under either name');
$probe_sql = end($GLOBALS['wpdb']->sql_log);
h12dp_assert(strpos($probe_sql, "LIKE '\\_upay\\_customer\\_token\\_v2\\_b1\\_%'") !== false
    && strpos($probe_sql, "LIKE '\\_supcheckout\\_customer\\_token\\_v2\\_b1\\_%'") !== false, 'the probe counts rows under both escaped prefixes');

// Idle with a stale new-name row (for example left by a rollback): the always-dual
// probe pushes toward fail-closed, never toward minting, and the census reports it.
h12dp_reset('idle');
upay_test_state()['usermeta'][1][$new_prefix . $scope] = array(h12dp_record($scope, $generation));
h12dp_assert(h12dp_private('get_or_create_secret_record') === null && upay_test_state()['option_creates'] === 0, 'idle with only new-prefix rows: still fails closed and mints nothing');
$census = h12dp_private('hasAnyProvenanceArtifact', array(), MigrationPreflight::class);
h12dp_assert(is_array($census) && $census['state'] === MigrationPreflight::CLEAN && $census['exists'] === true, 'idle: the migration census reports the stale new-name provenance row');
h12dp_assert(CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation)['state'] !== 'none', 'idle: the prior-provenance scan sees the stale new-name row');

// A truly empty store still initializes exactly one secret under today's name.
h12dp_reset('idle');
$minted = h12dp_private('get_or_create_secret_record');
h12dp_assert(is_array($minted) && upay_test_state()['options']['upayments_token_identity_secret_v2'] === $minted
    && !array_key_exists('supcheckout_token_identity_secret_v2', upay_test_state()['options']), 'idle, no provenance anywhere: one secret is minted under the legacy name, as before');

// --- 4. Mixed legacy/new scan during the switch window. ---
h12dp_reset('copying');
upay_test_state()['options']['upayments_token_identity_secret_v2'] = $secret;
$scope_a = str_repeat('a', 32);
$scope_b = str_repeat('b', 32);
$scope_c = str_repeat('d', 32);
upay_test_state()['usermeta'][1][$legacy_prefix . $scope_a] = array(h12dp_record($scope_a, $generation));
upay_test_state()['usermeta'][1][$new_prefix . $scope_b] = array(h12dp_record($scope_b, $generation));
upay_test_state()['usermeta'][1][$legacy_prefix . $scope_c] = array(h12dp_record($scope_c, $generation));
upay_test_state()['usermeta'][1][$new_prefix . $scope_c] = array(h12dp_record($scope_c, $generation));
$scan = CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation);
h12dp_assert($scan['state'] === 'same_generation_only', 'mixed scan: legacy-only, new-only and an agreeing copy are each one valid record (' . $scan['state'] . ')');

upay_test_state()['usermeta'][1][$new_prefix . $scope_c] = array(h12dp_record($scope_c, $generation, '87654321'));
$scan = CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation);
h12dp_assert($scan['state'] === 'invalid', 'mixed scan: a copy that disagrees with its legacy record fails closed');
h12dp_assert(CustomerTokenIdentity::read_provenance(1, $scope_c, $generation)['state'] === 'invalid', 'exact read: disagreeing legacy/new forms fail closed');
h12dp_assert(CustomerTokenIdentity::read_provenance(1, $scope_b, $generation)['state'] === 'valid', 'exact read before cutover: a scope held only under the new prefix is still found');

$other_generation = str_repeat('e', 32);
upay_test_state()['usermeta'][1][$new_prefix . $scope_c] = array(h12dp_record($scope_c, $generation));
upay_test_state()['usermeta'][1][$new_prefix . $scope_b] = array(h12dp_record($scope_b, $other_generation));
$scan = CustomerTokenIdentity::inspect_current_user_prior_provenance(1, $generation);
h12dp_assert($scan['state'] === 'secret_generation_mismatch', 'mixed scan: a new-prefix record of another generation is seen (' . $scan['state'] . ')');

// --- 5. Customer-token GET_LOCK names: one name at a time (MySQL < 5.7.5 holds one). ---
foreach (array('idle' => 'upay_ctk_bootstrap_b1', 'copying' => 'upay_ctk_bootstrap_b1', 'cut_over' => 'upay_ctk_bootstrap_b1', 'finalized' => 'sup_ctkboot_b1') as $status => $held) {
    h12dp_reset($status);
    h12dp_assert(CustomerTokenIdentity::acquire_lock('upay_ctk_bootstrap_b1') === true
        && array_keys(upay_test_state()['locks']) === array($held), "{$status}: the bootstrap lock takes exactly {$held}");
    CustomerTokenIdentity::release_lock('upay_ctk_bootstrap_b1');
    h12dp_assert(upay_test_state()['locks'] === array(), "{$status}: release frees it");
}

// --- 6. The Phase 9I executor takes the runtime's lock names. ---
foreach (array('copying' => 'upay_ctk_bootstrap_b1', 'finalized' => 'sup_ctkboot_b1') as $status => $held) {
    h12dp_reset($status);
    h12dp_assert(CustomerTokenIdentity::acquire_lock('upay_ctk_bootstrap_b1') === true, "{$status}: runtime holds the bootstrap lock");
    h12dp_assert(h12dp_private('acquireLock', array('upay_ctk_bootstrap_b1'), \Simplixi\SUPCheckout\Migration\MigrationExecutor::class) === false, "{$status}: the migration executor is excluded by the runtime's {$held}");
    CustomerTokenIdentity::release_lock('upay_ctk_bootstrap_b1');
    h12dp_assert(h12dp_private('acquireLock', array('upay_ctk_bootstrap_b1'), \Simplixi\SUPCheckout\Migration\MigrationExecutor::class) === true
        && array_keys(upay_test_state()['locks']) === array($held), "{$status}: the executor then takes exactly {$held}");
    h12dp_private('releaseLock', array('upay_ctk_bootstrap_b1'), \Simplixi\SUPCheckout\Migration\MigrationExecutor::class);
}

// --- 7. Window writes: a secret minted and provenance created in the window exist under both names. ---
h12dp_reset('copying');
$minted = h12dp_private('get_or_create_secret_record');
h12dp_assert(is_array($minted) && upay_test_state()['options']['upayments_token_identity_secret_v2'] === $minted
    && upay_test_state()['options']['supcheckout_token_identity_secret_v2'] === $minted, 'copying, empty store: the secret is minted under both names');
h12dp_reset('rolled_back');
$minted = h12dp_private('get_or_create_secret_record');
h12dp_assert(is_array($minted) && !array_key_exists('supcheckout_token_identity_secret_v2', upay_test_state()['options']), 'rolled_back: the secret is minted under the legacy name only');

foreach (array('copying' => array(true, true), 'idle' => array(true, false), 'cut_over' => array(true, true), 'finalized' => array(false, true)) as $status => $expect) {
    h12dp_reset($status);
    $option = ($status === 'finalized' || $status === 'cut_over') ? 'supcheckout_token_identity_secret_v2' : 'upayments_token_identity_secret_v2';
    upay_test_state()['options'][$option] = $secret;
    $created = CustomerTokenIdentity::create_provenance(1, $api_key, false, $scope, $generation,
        CustomerTokenIdentity::KIND_CANONICAL, '12345678', CustomerTokenIdentity::SOURCE_CREATE_201);
    $has_legacy = isset(upay_test_state()['usermeta'][1][$legacy_prefix . $scope]) && count(upay_test_state()['usermeta'][1][$legacy_prefix . $scope]) === 1;
    $has_new = isset(upay_test_state()['usermeta'][1][$new_prefix . $scope]) && count(upay_test_state()['usermeta'][1][$new_prefix . $scope]) === 1;
    h12dp_assert($created === true && $has_legacy === $expect[0] && $has_new === $expect[1]
        && CustomerTokenIdentity::read_provenance(1, $scope, $generation)['state'] === 'valid',
        "{$status}: new provenance is written under exactly the window names and verifies");
}

// cut_over: the primary key is the new name and the mirror is the legacy name.
h12dp_reset('cut_over');
h12dp_assert(CustomerTokenIdentity::get_user_meta_key('1', $scope) === $new_prefix . $scope, 'cut_over: the primary provenance key is the new name');

// A mirror that fails verification rolls back the mirror AND the primary: nothing of
// this call survives, and a concurrent writer's value is never deleted.
foreach (array('copying' => $new_prefix, 'cut_over' => $legacy_prefix) as $status => $mirror_prefix) {
    h12dp_reset($status);
    upay_test_state()['options'][$status === 'cut_over' ? 'supcheckout_token_identity_secret_v2' : 'upayments_token_identity_secret_v2'] = $secret;
    $concurrent = h12dp_record($scope, $generation, '23456789');
    upay_test_state()['inject_user_meta_after_add'] = array('user_id' => 1, 'key' => $mirror_prefix . $scope, 'value' => $concurrent);
    $created = CustomerTokenIdentity::create_provenance(1, $api_key, false, $scope, $generation,
        CustomerTokenIdentity::KIND_CANONICAL, '12345678', CustomerTokenIdentity::SOURCE_CREATE_201);
    $primary_prefix = $mirror_prefix === $new_prefix ? $legacy_prefix : $new_prefix;
    h12dp_assert($created === false, "{$status}: a mirror that fails readback fails the creation");
    h12dp_assert(!isset(upay_test_state()['usermeta'][1][$primary_prefix . $scope]), "{$status}: the primary record is rolled back exactly");
    h12dp_assert(isset(upay_test_state()['usermeta'][1][$mirror_prefix . $scope]) && upay_test_state()['usermeta'][1][$mirror_prefix . $scope] === array($concurrent), "{$status}: only this call's mirror is removed; the concurrent value stays");
}

$GLOBALS['wpdb'] = new WpdbStub();

echo "\nTakeover H12 dual prefix: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
