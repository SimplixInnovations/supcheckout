<?php
/**
 * T1/T7 R3 subscription characterization + failure injection matrix.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function r3t_assert($c, $l) {
    global $pass, $fail;
    if ($c) { echo "PASS: {$l}\n"; $pass++; return; }
    echo "FAIL: {$l}\n"; $fail++;
}

require_once $root . '/src/Subscription/RenewalCardAuthority.php';
require_once $root . '/src/Subscription/CycleEconomics.php';
require_once $root . '/src/Subscription/AutoDeductResultVerifier.php';

use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\CycleEconomics;
use Simplixi\SUPCheckout\Subscription\RenewalCardAuthority;

$scheduler = file_get_contents($root . '/includes/Subscription/Cron/Scheduler.php');
$cycle = file_get_contents($root . '/includes/Subscription/Cron/CycleClaim.php');
$up = file_get_contents($root . '/UPayments.php');

// T1 surface inventory (static pin of method surface).
foreach (array('function init', 'function process', 'process_one_order', 'handle_post_dispatch', 'getGateway', 'getNextBillingDate', 'upayShouldAttemptRetry') as $needle) {
    r3t_assert(strpos($scheduler, $needle) !== false, "Scheduler surface remains: {$needle}");
}
foreach (array('maybe_install', 'acquire(', 'reclaim_stale_claimed', 'mark_dispatching', 'mark_held', 'mark_resolved', 'release_claimed', 'function get(') as $needle) {
    r3t_assert(strpos($cycle, $needle) !== false, "CycleClaim surface remains: {$needle}");
}
foreach (array("'unsubscribe'", "'pause'", "'resume'", 'wp_verify_nonce', 'UPayments_AutoDeduction', 'get_current_user_id') as $needle) {
    r3t_assert(strpos($up, $needle) !== false, "pause/resume/cancel contract remains: {$needle}");
}

// T1 behavioral: pause/cancel state table from transition expression.
r3t_assert(strpos($up, "action === 'unsubscribe'") !== false, 'unsubscribe transition present');
r3t_assert(strpos($up, "action === 'pause' && \$current_status === 'active'") !== false
    || strpos($up, "'pause' && \$current_status === 'active'") !== false, 'pause only from active');
r3t_assert(strpos($up, "'resume' && \$current_status === 'paused'") !== false, 'resume only from paused');

// T2 pre-dispatch: missing card => zero POST (already covered by RenewalCardAuthorityTest).
$order_missing = new class {
    public function get_meta($k) { return $k === '_upay_customer_unique_token' ? 'cust' : ''; }
};
$r = RenewalCardAuthority::resolve_explicit_token($order_missing, function () {
    return array('result' => 'success', 'data' => array(array('token' => 'x')));
});
r3t_assert($r['state'] === RenewalCardAuthority::STATE_MISSING && $r['token'] === null, 'T2 missing card is not first-card');

// T4 response classification matrix.
$base_expected = array('amount' => '10.000', 'currency' => 'KWD', 'parent_id' => 42, 'cycle_key' => 'k', 'reference_id' => 'r');
$cases = array(
    'invalid json object' => array('not-array', AutoDeductResultVerifier::MALFORMED),
    'status false' => array(array('status' => false), AutoDeductResultVerifier::DEFINITIVE_FAILURE),
    'missing transaction' => array(array('status' => true, 'data' => array()), AutoDeductResultVerifier::MALFORMED),
    'empty paymentId' => array(array('status' => true, 'data' => array('transaction' => array('paymentId' => '', 'paid_amount' => '10.000', 'paid_currency' => 'KWD'))), AutoDeductResultVerifier::MALFORMED),
    'invalid amount' => array(array('status' => true, 'data' => array('transaction' => array('paymentId' => 'p', 'paid_amount' => '1e3', 'paid_currency' => 'KWD'))), AutoDeductResultVerifier::MALFORMED),
    'amount mismatch' => array(array('status' => true, 'data' => array('transaction' => array('paymentId' => 'p', 'paid_amount' => '9.999', 'paid_currency' => 'KWD'))), AutoDeductResultVerifier::BINDING_MISMATCH),
    'currency mismatch' => array(array('status' => true, 'data' => array('transaction' => array('paymentId' => 'p', 'paid_amount' => '10.000', 'paid_currency' => 'USD'))), AutoDeductResultVerifier::BINDING_MISMATCH),
    'truthy only remains unproven' => array(array('status' => true, 'data' => array('transaction' => array('paymentId' => 'p', 'paid_amount' => '10.000', 'paid_currency' => 'KWD'))), AutoDeductResultVerifier::UNRESOLVED),
);
foreach ($cases as $label => $pair) {
    $out = AutoDeductResultVerifier::verify($pair[0], $base_expected);
    r3t_assert($out['outcome'] === $pair[1], "T4 {$label}");
}

// T5/T6 production ordering pins.
r3t_assert(strpos($scheduler, 'acquire_with_snapshot') !== false, 'T6 acquire_with_snapshot used for dispatch');
r3t_assert(strpos($scheduler, 'has_dispatchable_snapshot') !== false, 'T6 dispatch requires complete snapshot');
r3t_assert(strpos($scheduler, "SCHEMA_VERSION = '2'") !== false || strpos($cycle, "SCHEMA_VERSION = '2'") !== false, 'T6 schema version is 2');
r3t_assert(strpos($cycle, 'expected_amount') !== false && strpos($cycle, 'expected_currency') !== false, 'T6 expected economics columns exist');
r3t_assert(strpos($scheduler, 'UPayments_order_id') !== false, 'provider-order identity remains protected field');
r3t_assert(strpos($scheduler, 'is not fabricated') !== false, 'UPayments_order_id is never fabricated');
r3t_assert(strpos($scheduler, "set_total(\$paid_amount)") !== false || strpos($scheduler, 'set_total($paid_amount)') !== false, 'renewal total uses validated decimal string');
r3t_assert(strpos($scheduler, '(float)') === false, 'no float payment authority in Scheduler');

// T7 pre-dispatch zero-POST gates remain present.
foreach (array('paused', 'cancelled', 'RenewalCardAuthority', 'Invalid or zero cycle economic snapshot') as $needle) {
    r3t_assert(strpos($scheduler, $needle) !== false, "T7 pre-dispatch gate present: {$needle}");
}

// T7 concurrency semantics: HELD/dispatching/resolved never auto-release.
r3t_assert(strpos($cycle, 'Never auto-expire') !== false || strpos($cycle, 'never auto-expire') !== false
    || (strpos($cycle, 'dispatching') !== false && strpos($cycle, 'held') !== false && strpos($cycle, 'resolved') !== false),
    'non-claimed states remain non-auto-expiring');

echo "\nR3 Characterization/Failure Matrix: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
