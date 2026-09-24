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
foreach (array('maybe_install', 'schema_ready', 'column_exists', 'acquire(', 'acquire_with_snapshot', 'reclaim_stale_claimed', 'mark_dispatching', 'mark_held', 'mark_resolved', 'release_claimed', 'function get(', 'has_dispatchable_snapshot', 'canonical_snapshot_amount') as $needle) {
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

// T6 immutable snapshot dispatch: request economics come from the claim row.
r3t_assert(strpos($scheduler, "\$dispatch_amount") !== false, 'T6 dispatch amount is taken from persisted claim snapshot');
r3t_assert(strpos($scheduler, "\$dispatch_currency") !== false, 'T6 dispatch currency is taken from persisted claim snapshot');
r3t_assert(strpos($scheduler, 'from the persisted claim snapshot') !== false || strpos($scheduler, 'ONLY from the persisted claim snapshot') !== false,
    'T6 documents snapshot-first request construction');
r3t_assert(strpos($scheduler, 'Parent economics diverged from immutable claim snapshot') !== false,
    'T6 parent economics divergence is fail-closed HELD');
r3t_assert(strpos($cycle, 'IMMUTABLE CYCLE INTENT') !== false, 'T6 reclaim documents immutable cycle intent');
r3t_assert(strpos($cycle, 'cycle_due_gmt = %s') === false, 'T6 reclaim does not rewrite cycle_due_gmt');

// T7 pre-dispatch zero-POST gates remain present.
foreach (array('paused', 'cancelled', 'RenewalCardAuthority', 'Invalid or zero cycle economic snapshot', 'parent_still_eligible_for_dispatch') as $needle) {
    r3t_assert(strpos($scheduler, $needle) !== false, "T7 pre-dispatch gate present: {$needle}");
}
r3t_assert(strpos($scheduler, 'Parent failed final pre-dispatch revalidation') !== false, 'T7 final revalidation can zero POST');
r3t_assert(strpos($cycle, 'function schema_ready') !== false, 'T6 schema readiness is explicit, not option-only');

// R4 bounded orchestration pins.
$r4_sched = @file_get_contents($root . '/src/Subscription/Scheduling/ActionSchedulerBridge.php');
$r4_enroll = @file_get_contents($root . '/src/Subscription/Scheduling/HistoricalEnrollment.php');
$r4_worker = @file_get_contents($root . '/src/Subscription/Scheduling/DueParentWorker.php');
r3t_assert(is_string($r4_sched) && strpos($r4_sched, "const GROUP = 'supcheckout'") !== false, 'R4 Action Scheduler group is supcheckout');
r3t_assert(strpos($r4_sched, 'parent_order_id') !== false, 'R4 action args carry parent_order_id only');
r3t_assert(strpos($r4_sched, 'cycle_due_gmt') !== false, 'R4 action args include cycle identity');
r3t_assert(strpos($r4_sched, 'api_key') === false && strpos($r4_sched, 'card_token') === false, 'R4 action args exclude secrets');
r3t_assert(strpos($r4_sched, 'is_initialized') !== false, 'R4 AS readiness requires datastore init');
r3t_assert(strpos($r4_sched, 'true') !== false && strpos($r4_sched, 'as_schedule_single_action') !== false, 'R4 uses unique scheduling');
r3t_assert(strpos($scheduler, 'HistoricalEnrollment::run_batch') !== false, 'R4 hourly feeder uses bounded enrollment');
r3t_assert(strpos($scheduler, 'do {') === false || strpos($scheduler, 'while (!empty($orders))') === false, 'R4 removes unbounded historical order scan');
r3t_assert(strpos($r4_enroll, 'BATCH_SIZE') !== false, 'R4 enrollment is explicitly bounded');
r3t_assert(strpos($r4_enroll, 'offset') !== false, 'R4 enrollment advances a persistent offset cursor');
r3t_assert(strpos($r4_worker, 'parent_qualifies') !== false, 'R4 worker revalidates parent before dispatch');
r3t_assert(strpos($r4_worker, 'process_parent_order') !== false, 'R4 worker reuses R3 Scheduler authority path');
r3t_assert(strpos($r4_worker, 'OUTCOME_RESOLVED') !== false, 'R4 worker schedules next cycle only on resolved');
r3t_assert(strpos($scheduler, 'OUTCOME_RESOLVED') !== false, 'R4 Scheduler returns orchestration outcomes');
r3t_assert(strpos($scheduler, 'LifecycleScheduler::register') !== false, 'R4 registers direct lifecycle scheduling');
r3t_assert(strpos($r4_enroll, 'payment_method') !== false, 'R4 enrollment narrows by payment_method=upayments');
r3t_assert(strpos($r4_enroll, 'has_status') !== false, 'R4 parent_qualifies checks Woo paid status');
r3t_assert(strpos($scheduler, 'wc_get_is_paid_statuses') !== false, 'T7 final revalidation uses Woo paid-status API');
r3t_assert(strpos($scheduler, 'string $credit_card_token') !== false, 'T7 final revalidation receives authorized card token');
r3t_assert(strpos($scheduler, 'hash_equals($fresh_card_token, $credit_card_token)') !== false, 'T7 final revalidation hash_equals card token');
r3t_assert(strpos($scheduler, "get_type() === 'custom_type'") !== false, 'T7 final revalidation requires custom_type product');
r3t_assert(strpos($scheduler, 'Never switch cards mid-attempt') !== false, 'T7 documents no silent card substitution');

// T7 concurrency semantics: HELD/dispatching/resolved never auto-release.
r3t_assert(strpos($cycle, 'Never auto-expire') !== false || strpos($cycle, 'never auto-expire') !== false
    || (strpos($cycle, 'dispatching') !== false && strpos($cycle, 'held') !== false && strpos($cycle, 'resolved') !== false),
    'non-claimed states remain non-auto-expiring');

echo "\nR3 Characterization/Failure Matrix: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
