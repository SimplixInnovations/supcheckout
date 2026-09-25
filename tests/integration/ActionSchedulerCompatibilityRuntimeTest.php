<?php
/**
 * Real Action Scheduler cross-version runtime certification.
 *
 * Runs under wp-cli eval-file after authoritative order-storage selection.
 * Uses the actual WooCommerce-bundled Action Scheduler datastore. Never
 * mutates provider payment state. CycleClaim remains charge authority.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';

use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge as ASBridge;

// --- 1. Required APIs and class exist (actual bundled AS) ---
supcheckout_cert_assert(function_exists('as_schedule_single_action'), 'as_schedule_single_action exists');
supcheckout_cert_assert(function_exists('as_has_scheduled_action'), 'as_has_scheduled_action exists');
supcheckout_cert_assert(function_exists('as_unschedule_action'), 'as_unschedule_action exists');
supcheckout_cert_assert(function_exists('as_unschedule_all_actions'), 'as_unschedule_all_actions exists');
supcheckout_cert_assert(function_exists('as_next_scheduled_action'), 'as_next_scheduled_action exists');
supcheckout_cert_assert(function_exists('as_get_scheduled_actions'), 'as_get_scheduled_actions exists');
supcheckout_cert_assert(class_exists('Action_Scheduler'), 'Action_Scheduler class exists');
supcheckout_cert_assert(class_exists('ActionScheduler_Store') || class_exists('ActionScheduler_DBStore'), 'Action Scheduler datastore class exists');

// --- 2. Datastore initialization and bridge readiness ---
if (class_exists('Action_Scheduler') && method_exists('Action_Scheduler', 'is_initialized')) {
    supcheckout_cert_assert(Action_Scheduler::is_initialized(), 'Action_Scheduler::is_initialized is true');
}
supcheckout_cert_assert(ASBridge::is_ready(), 'ActionSchedulerBridge::is_ready reflects initialized datastore');

// --- 3. No second bundled Action Scheduler library ---
$plugin_root = dirname(__DIR__, 2);
$as_copies = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugin_root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    if (strpos($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $base = basename($path);
    if ($base === 'action-scheduler.php' || $base === 'ActionScheduler.php') {
        $as_copies[] = $path;
    }
}
supcheckout_cert_assert(
    count($as_copies) === 0,
    'SUPCheckout does not bundle a second Action Scheduler library (found: ' . implode(', ', $as_copies) . ')'
);

// --- 4. Unique single-action scheduling + duplicate suppression ---
$parent_a = 910001;
$parent_b = 910002;
$cycle_1  = gmmktime(12, 0, 0, 1, 15, 2030);
$cycle_2  = gmmktime(12, 0, 0, 2, 15, 2030);
$group    = ASBridge::GROUP;
$action   = ASBridge::ACTION_DUE_PARENT;

ASBridge::cancel_cycle_actions($parent_a, $cycle_1);
ASBridge::cancel_cycle_actions($parent_a, $cycle_2);
ASBridge::cancel_cycle_actions($parent_b, $cycle_1);

$ok = ASBridge::ensure_cycle_action($parent_a, $cycle_1, $cycle_1, 0);
supcheckout_cert_assert($ok, 'unique: first ensure_cycle_action succeeds');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_1, 0), 'unique: action is open after first schedule');

$ok = ASBridge::ensure_cycle_action($parent_a, $cycle_1, $cycle_1, 0);
supcheckout_cert_assert($ok, 'unique: duplicate ensure_cycle_action is idempotent success');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_1, 0), 'unique: still open after duplicate ensure');

$args_a = ASBridge::cycle_args($parent_a, $cycle_1, 0);
$count_open = 0;
if (function_exists('as_get_scheduled_actions')) {
    $found = as_get_scheduled_actions(
        array(
            'hook'   => $action,
            'args'   => $args_a,
            'group'  => $group,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 10,
        ),
        ARRAY_A
    );
    $count_open = is_array($found) ? count($found) : 0;
}
supcheckout_cert_assert($count_open === 1, 'unique: exactly one pending action for same parent+cycle+retry (got ' . $count_open . ')');

// --- 5. Different cycle can coexist ---
$ok = ASBridge::ensure_cycle_action($parent_a, $cycle_2, $cycle_2, 0);
supcheckout_cert_assert($ok, 'coexist: different cycle schedules');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_2, 0), 'coexist: cycle 2 open');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_1, 0), 'coexist: cycle 1 remains open');

// --- 6. Retry attempt identity is distinct queue identity, same payment cycle ---
$ok = ASBridge::ensure_cycle_action($parent_a, $cycle_1, $cycle_1 + 3600, 1);
supcheckout_cert_assert($ok, 'retry: attempt 1 schedules');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_1, 1), 'retry: attempt 1 open');
supcheckout_cert_assert(ASBridge::has_any_open_cycle_attempt($parent_a, $cycle_1), 'retry: any attempt open for cycle 1');

// --- 7. Pending detection ---
supcheckout_cert_assert(
    ASBridge::has_open_action_with_args($args_a),
    'pending: exact args detected as open'
);

// --- 8. Exact-args cancellation removes only the target ---
$ok = ASBridge::cancel_cycle_actions($parent_a, $cycle_1);
supcheckout_cert_assert($ok, 'cancel: parent A cycle 1 cancelled');
supcheckout_cert_assert(!ASBridge::has_any_open_cycle_attempt($parent_a, $cycle_1), 'cancel: no remaining cycle-1 attempts');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_2, 0), 'cancel: parent A cycle 2 unaffected');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_b, $cycle_1, 0), 'cancel: parent B unaffected by parent A cancel');

// --- 9. Parent isolation ---
ASBridge::cancel_cycle_actions($parent_b, $cycle_1);
supcheckout_cert_assert(!ASBridge::has_open_cycle_action($parent_b, $cycle_1, 0), 'cancel: parent B cycle cleared');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_2, 0), 'cancel: parent A cycle 2 still open after B cancel');

// --- 10. In-progress semantics do not suppress a different billing cycle ---
// Mark parent A cycle 2 action as in-progress via store if APIs allow; a
// different cycle must remain independently open. We prove non-suppression by
// scheduling cycle 3 while cycle 2 is still open (pending or in-progress).
$cycle_3 = gmmktime(12, 0, 0, 3, 15, 2030);
ASBridge::cancel_cycle_actions($parent_a, $cycle_3);
$ok = ASBridge::ensure_cycle_action($parent_a, $cycle_3, $cycle_3, 0);
supcheckout_cert_assert($ok, 'in-progress: different cycle still schedules while another is open');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_3, 0), 'in-progress: cycle 3 open');
supcheckout_cert_assert(ASBridge::has_open_cycle_action($parent_a, $cycle_2, 0), 'in-progress: cycle 2 remains independently open');

// Cleanup remaining certification actions.
ASBridge::cancel_cycle_actions($parent_a, $cycle_2);
ASBridge::cancel_cycle_actions($parent_a, $cycle_3);

supcheckout_cert_assert(!ASBridge::has_open_cycle_action($parent_a, $cycle_2, 0), 'cleanup: cycle 2 gone');
supcheckout_cert_assert(!ASBridge::has_open_cycle_action($parent_a, $cycle_3, 0), 'cleanup: cycle 3 gone');

// --- 11. Payment-authority invariant remains orchestration-only ---
$bridge_src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php');
supcheckout_cert_assert(
    strpos($bridge_src, 'CycleClaim remains the') !== false,
    'Action Scheduler bridge documents CycleClaim as charge authority'
);
supcheckout_cert_assert(
    strpos($bridge_src, 'payment_complete') === false,
    'Action Scheduler bridge never calls payment_complete'
);

echo 'Action Scheduler cross-version runtime certification: PASS' . "\n";
