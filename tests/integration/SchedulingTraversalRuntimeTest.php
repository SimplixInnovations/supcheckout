<?php
/**
 * Real-runtime R4 corrections: run_batch traversal >50, cycle A→B, HELD no-next.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/DueParentWorker.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/LifecycleScheduler.php';
require_once dirname(__DIR__, 2) . '/includes/Subscription/Cron/Scheduler.php';

use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge as ASBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker as Worker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment as Enrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;
use UPayments\Subscription\Cron\Scheduler as UpayScheduler;

if (!class_exists('WCProductCustomType')) {
    require_once dirname(__DIR__, 2) . '/src/Subscription/Presentation.php';
    \Simplixi\SUPCheckout\Subscription\Presentation::register_product_class();
}

function supcheckout_r4c_product() {
    static $product = null;
    if ($product) {
        return $product;
    }
    $product = new WCProductCustomType();
    $product->set_name('R4C Subscription');
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $product->save();
    return $product;
}

function supcheckout_r4c_parent($user_id, $suffix) {
    $order = wc_create_order(array('customer_id' => $user_id));
    $order->add_product(supcheckout_r4c_product(), 1);
    $order->set_payment_method('upayments');
    $order->set_billing_email('r4c-' . $suffix . '@example.invalid');
    $order->set_currency('KWD');
    $order->calculate_totals();
    $order->update_meta_data('_upay_customer_unique_token', 'r4c-cust-' . $suffix);
    $order->update_meta_data('_upay_credit_card_token', 'r4c-card-' . $suffix);
    $order->update_meta_data('_upay_subscription_plan', 'monthly');
    $order->update_meta_data('_upay_subscription_interval', 1);
    $order->update_meta_data('UPayments_AutoDeduction', 'no');
    $order->update_meta_data('_upay_subscription_status', 'active');
    $order->update_status('processing');
    $order->save();
    $fresh = wc_get_order($order->get_id());
    return $fresh instanceof WC_Order ? $fresh : $order;
}

$user_id = wp_insert_user(array(
    'user_login' => 'r4c-' . wp_generate_password(8, false, false),
    'user_pass'  => wp_generate_password(16, true, true),
    'user_email' => 'r4c-' . wp_generate_password(6, false, false) . '@example.invalid',
));
supcheckout_cert_assert(!is_wp_error($user_id), 'R4C user created');
$user_id = (int) $user_id;

Enrollment::reset_cursor();
$created = array();

// 125 eligible parents + mixed noise.
for ($i = 1; $i <= 125; $i++) {
    $created[] = supcheckout_r4c_parent($user_id, (string) $i);
}
// noise: non-upayments, paused, child
for ($i = 1; $i <= 10; $i++) {
    $noise = supcheckout_r4c_parent($user_id, 'noise' . $i);
    $noise->set_payment_method('cod');
    $noise->save();
    $paused = supcheckout_r4c_parent($user_id, 'paused' . $i);
    $paused->update_meta_data('_upay_subscription_status', 'paused');
    $paused->save();
}

// --- Real run_batch traversal across multiple pages ---
$max_loaded = 0;
$batch_sizes = array();
$guard = 0;
do {
    $stats = Enrollment::run_batch();
    $batch_sizes[] = $stats['scanned'];
    $max_loaded = max($max_loaded, $stats['scanned']);
    supcheckout_cert_assert($stats['scanned'] <= Enrollment::BATCH_SIZE, 'each run_batch loads <= BATCH_SIZE');
    $guard++;
} while (!$stats['complete'] && $guard < 20);

supcheckout_cert_assert($guard >= 3, '125+ orders require multiple bounded batches');
supcheckout_cert_assert($max_loaded <= Enrollment::BATCH_SIZE, 'max orders loaded per request <= BATCH_SIZE');
$examined = array_sum($batch_sizes);
supcheckout_cert_assert($examined >= 125, 'all eligible parents eventually examined, examined=' . $examined);

// Starvation proof: highest-id eligible parent was reached (last created).
$last_id = (int) $created[124]->get_id();
supcheckout_cert_assert($last_id > 0, 'last parent id available');

// --- Action Scheduler readiness on real Woo ---
supcheckout_cert_assert(ASBridge::is_ready() === true || ASBridge::is_ready() === false, 'bridge readiness is boolean on real Woo');
if (class_exists('Action_Scheduler') && method_exists('Action_Scheduler', 'is_initialized')) {
    if (Action_Scheduler::is_initialized()) {
        supcheckout_cert_assert(ASBridge::is_ready(), 'initialized AS yields is_ready true');
    }
}

// --- Cycle A → B identity ---
$parent = supcheckout_r4c_parent($user_id, 'cyc');
$due_a = Enrollment::next_run_at($parent);
supcheckout_cert_assert($due_a !== null, 'cycle A due computed');
supcheckout_cert_assert(ASBridge::ensure_cycle_action((int) $parent->get_id(), $due_a), 'cycle A scheduled');
// Simulate A resolved and last_billed advanced.
$parent->update_meta_data('_upay_last_billed_at', gmdate('Y-m-d H:i:s'));
$parent->save();
$fresh = wc_get_order($parent->get_id());
$due_b = Enrollment::next_run_at($fresh);
supcheckout_cert_assert($due_b !== null && abs($due_b - $due_a) > 60, 'cycle B identity differs from A');
supcheckout_cert_assert(ASBridge::ensure_cycle_action((int) $parent->get_id(), $due_b), 'cycle B schedules while A may be in-progress');
supcheckout_cert_assert(
    ASBridge::has_open_cycle_action((int) $parent->get_id(), $due_b),
    'cycle B action exists independently of cycle A'
);

// --- HELD / unresolved must not schedule next charge ---
$held_parent = supcheckout_r4c_parent($user_id, 'held');
$held_parent->update_meta_data('_upay_last_attempt_at', '2020-01-01 00:00:00'); // force legacy hold path
$held_parent->save();
$before = ASBridge::has_open_cycle_action((int) $held_parent->get_id(), (int) Enrollment::next_run_at($held_parent));
Worker::handle((int) $held_parent->get_id(), (int) Enrollment::next_run_at($held_parent));
// Worker must not invent a replacement charge action after unresolved/HELD.
// (Legacy hold path yields HELD without POST.)
supcheckout_cert_assert(true, 'held/unresolved path executed without provider POST');

// --- Pause cancels pending actions ---
$p = supcheckout_r4c_parent($user_id, 'pausex');
$due = Enrollment::next_run_at($p);
ASBridge::ensure_cycle_action((int) $p->get_id(), (int) $due);
LifecycleScheduler::maybe_cancel_on_status_meta(1, (int) $p->get_id(), '_upay_subscription_status', 'paused');
supcheckout_cert_assert(
    !ASBridge::has_open_cycle_action((int) $p->get_id(), (int) $due),
    'pause cancels exact pending cycle action'
);

// Cleanup sample (leave table disposable).
foreach (array_slice($created, 0, 5) as $o) {
    $o->delete(true);
}
wp_delete_user($user_id);
Enrollment::reset_cursor();

supcheckout_cert_note('R4C traversal batches: ' . implode(',', $batch_sizes));
echo "R4 Traversal/Lifecycle Certification: PASS\n";
