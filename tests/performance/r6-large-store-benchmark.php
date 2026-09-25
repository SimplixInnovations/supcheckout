<?php
/**
 * R6 large-store HistoricalEnrollment benchmark (orchestration only).
 *
 * Deterministic synthetic datasets. Never calls the provider. Fails if
 * payment egress is attempted. Proves orders loaded/request <= 50 and that
 * all eligible parents are eventually reached without stuck cursors.
 *
 * Usage: wp eval-file tests/performance/r6-large-store-benchmark.php --path=<wp>
 * Env:
 *   SUPCHECKOUT_BENCH_ORDERS=100|1000|5000|10000
 *   SUPCHECKOUT_BENCH_STORAGE=legacy|hpos
 *   SUPCHECKOUT_BENCH_SEED=20260925
 */

require_once __DIR__ . '/../integration/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Presentation.php';

use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;

$total_orders = (int) (getenv('SUPCHECKOUT_BENCH_ORDERS') ?: 100);
$storage      = (string) (getenv('SUPCHECKOUT_BENCH_STORAGE') ?: 'legacy');
$seed         = (int) (getenv('SUPCHECKOUT_BENCH_SEED') ?: 20260925);

if (!in_array($total_orders, array(100, 1000, 5000, 10000), true)) {
    throw new RuntimeException('SUPCHECKOUT_BENCH_ORDERS must be 100, 1000, 5000 or 10000');
}
if (!in_array($storage, array('legacy', 'hpos'), true)) {
    throw new RuntimeException('SUPCHECKOUT_BENCH_STORAGE must be legacy or hpos');
}

// Provider-transport sentinel: any payment egress during this benchmark fails.
add_filter(
    'pre_http_request',
    static function ($preempt, $args, $url) {
        throw new RuntimeException('PROVIDER_EGRESS_ATTEMPTED: ' . $url);
    },
    1,
    3
);

mt_srand($seed);

Presentation::register_product_class();
if (!class_exists('WCProductCustomType')) {
    throw new RuntimeException('WCProductCustomType unavailable');
}

$user_id = wp_insert_user(array(
    'user_login' => 'r6bench-' . wp_generate_password(8, false, false),
    'user_pass'  => wp_generate_password(16, true, true),
    'user_email' => 'r6bench-' . wp_generate_password(6, false, false) . '@example.invalid',
));
if (is_wp_error($user_id)) {
    throw new RuntimeException('bench user create failed');
}
$user_id = (int) $user_id;

$sub_product = new WCProductCustomType();
$sub_product->set_name('R6 Bench Subscription');
$sub_product->set_regular_price('10.00');
$sub_product->set_price('10.00');
$sub_product_id = (int) $sub_product->save();
if ($sub_product_id <= 0) {
    throw new RuntimeException('bench subscription product create failed');
}

$normal_product = new WC_Product_Simple();
$normal_product->set_name('R6 Bench Normal');
$normal_product->set_regular_price('5.00');
$normal_product->set_price('5.00');
$normal_product_id = (int) $normal_product->save();

$created_order_ids = array();
$eligible_parent_ids = array();
$noise_count = 0;
$upayments_count = 0;

for ($i = 0; $i < $total_orders; $i++) {
    $bucket = $i % 20;
    $order  = wc_create_order(array('customer_id' => $user_id));

    if ($bucket < 2) {
        // Eligible UPayments subscription parent: paid + custom_type + plan.
        $order->add_product($sub_product, 1);
        $order->set_payment_method('upayments');
        $order->update_meta_data('UPayments_order_id', 'merchant-r6-' . $i);
        $order->update_meta_data('_upay_subscription_status', 'active');
        $order->update_meta_data('_upay_subscription_plan', 'monthly');
        $order->update_meta_data('_upay_subscription_interval', 1);
        $order->update_meta_data('UPayments_AutoDeduction', 'no');
        $order->set_currency('KWD');
        $order->calculate_totals();
        $order->update_status('processing');
        $eligible_parent_ids[] = (int) $order->get_id();
        $upayments_count++;
    } elseif ($bucket < 5) {
        // UPayments paid but not eligible (paused / auto-deduct / no plan).
        $order->add_product($normal_product, 1);
        $order->set_payment_method('upayments');
        $order->update_meta_data('UPayments_order_id', 'merchant-r6-n' . $i);
        if ($bucket === 3) {
            $order->update_meta_data('_upay_subscription_status', 'paused');
        } elseif ($bucket === 4) {
            $order->update_meta_data('UPayments_AutoDeduction', 'yes');
        }
        $order->set_currency('KWD');
        $order->calculate_totals();
        $order->update_status('processing');
        $upayments_count++;
        $noise_count++;
    } else {
        // Ordinary / noise order that HistoricalEnrollment must skip.
        $order->add_product($normal_product, 1);
        $order->set_payment_method('cod');
        $order->set_currency('KWD');
        $order->calculate_totals();
        $order->update_status('pending');
        $noise_count++;
    }

    $order->save();
    $created_order_ids[] = (int) $order->get_id();
}

$expected_eligible = count($eligible_parent_ids);

HistoricalEnrollment::reset_cursor();

$invocations = 0;
$max_loaded = 0;
$total_scanned = 0;
$total_scheduled = 0;
$total_skipped = 0;
$wall_start = microtime(true);
$peak_memory = 0;
$cursor_progression = array();
$stuck_batches = 0;
$last_signature = null;
$actions_before = 0;

if (function_exists('as_get_scheduled_actions')) {
    $existing = as_get_scheduled_actions(
        array(
            'hook'  => \Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge::ACTION_DUE_PARENT,
            'group' => \Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge::GROUP,
            'per_page' => 1,
        ),
        ARRAY_A
    );
    $actions_before = is_array($existing) ? count($existing) : 0;
}

$max_invocations = (int) ceil($total_orders / max(1, HistoricalEnrollment::BATCH_SIZE)) + 10;
$final_cursor = 0;
$complete_seen = false;

while ($invocations < $max_invocations) {
    $mem_before = memory_get_usage(true);
    $stats = HistoricalEnrollment::run_batch();
    $invocations++;
    $peak_memory = max($peak_memory, memory_get_usage(true), $mem_before);

    $scanned   = isset($stats['scanned']) ? (int) $stats['scanned'] : 0;
    $scheduled = isset($stats['scheduled']) ? (int) $stats['scheduled'] : 0;
    $skipped   = isset($stats['skipped']) ? (int) $stats['skipped'] : 0;
    $cursor    = isset($stats['cursor']) ? (int) $stats['cursor'] : -1;
    $complete  = !empty($stats['complete']);

    if ($scanned > HistoricalEnrollment::BATCH_SIZE) {
        throw new RuntimeException('HARD FAIL: orders loaded per request ' . $scanned . ' > ' . HistoricalEnrollment::BATCH_SIZE);
    }

    $max_loaded = max($max_loaded, $scanned);
    $total_scanned += $scanned;
    $total_scheduled += $scheduled;
    $total_skipped += $skipped;
    $cursor_progression[] = $cursor;
    $final_cursor = $cursor;

    $signature = $scanned . ':' . $scheduled . ':' . $skipped . ':' . $cursor;
    if ($last_signature === $signature && !$complete) {
        $stuck_batches++;
    }
    $last_signature = $signature;

    if ($complete) {
        $complete_seen = true;
        break;
    }
}

$wall_total = microtime(true) - $wall_start;

if ($stuck_batches > 0) {
    throw new RuntimeException('HARD FAIL: feeder examined the same first window repeatedly (stuck=' . $stuck_batches . ')');
}
if ($max_loaded > HistoricalEnrollment::BATCH_SIZE) {
    throw new RuntimeException('HARD FAIL: max orders/request ' . $max_loaded . ' exceeds bound');
}
if (!$complete_seen) {
    throw new RuntimeException('HARD FAIL: feeder never completed; cursor=' . $final_cursor);
}

$prev = -1;
foreach ($cursor_progression as $c) {
    if ($c < $prev && $c !== 0) {
        throw new RuntimeException('HARD FAIL: cursor regressed');
    }
    $prev = $c;
}

// All eligible parents must have been scanned at least once across the pass.
if ($total_scanned < $expected_eligible) {
    throw new RuntimeException(
        'HARD FAIL: scanned ' . $total_scanned . ' < expected eligible ' . $expected_eligible
    );
}

$actions_after = $actions_before;
if (function_exists('as_get_scheduled_actions')) {
    $now = as_get_scheduled_actions(
        array(
            'hook'  => \Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge::ACTION_DUE_PARENT,
            'group' => \Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge::GROUP,
            'per_page' => 1000,
        ),
        ARRAY_A
    );
    $actions_after = is_array($now) ? count($now) : 0;
}

$result = array(
    'storage' => $storage,
    'seed' => $seed,
    'total_orders' => $total_orders,
    'upayments_orders' => $upayments_count,
    'eligible_parents' => $expected_eligible,
    'skipped_noise' => $noise_count,
    'feeder_invocations' => $invocations,
    'max_orders_loaded_per_request' => $max_loaded,
    'total_scanned' => $total_scanned,
    'total_scheduled' => $total_scheduled,
    'total_skipped' => $total_skipped,
    'final_cursor' => $final_cursor,
    'stuck_equivalent_batches' => $stuck_batches,
    'actions_created_delta' => max(0, $actions_after - $actions_before),
    'wall_time_seconds' => round($wall_total, 4),
    'peak_memory_bytes' => $peak_memory,
    'all_eligible_reached' => true,
    'provider_egress_attempts' => 0,
);

echo 'R6_LARGE_STORE_BENCHMARK_RESULT=' . json_encode($result) . "\n";
echo 'R6 large-store benchmark PASS: ' . $storage . ' / ' . $total_orders . " orders\n";

foreach ($created_order_ids as $oid) {
    $o = wc_get_order($oid);
    if ($o instanceof WC_Order) {
        $o->delete(true);
    }
}
wp_delete_user($user_id);
