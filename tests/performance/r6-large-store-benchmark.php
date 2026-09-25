<?php
/**
 * R6 large-store HistoricalEnrollment benchmark (orchestration only).
 *
 * Disables LifecycleScheduler initial-scheduling hooks so the feeder is the
 * only creator of due actions. Proves every exact eligible parent receives
 * exactly one attempt-0 action. Never calls the provider.
 *
 * Usage: wp eval-file tests/performance/r6-large-store-benchmark.php --path=<wp>
 * Env: SUPCHECKOUT_BENCH_ORDERS, SUPCHECKOUT_BENCH_STORAGE, SUPCHECKOUT_BENCH_SEED
 */

require_once __DIR__ . '/../integration/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/LifecycleScheduler.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Presentation.php';

use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge as ASBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;

$total_orders = (int) (getenv('SUPCHECKOUT_BENCH_ORDERS') ?: 100);
$storage      = (string) (getenv('SUPCHECKOUT_BENCH_STORAGE') ?: 'legacy');
$seed         = (int) (getenv('SUPCHECKOUT_BENCH_SEED') ?: 20260925);

if (!in_array($total_orders, array(100, 1000, 5000, 10000), true)) {
    throw new RuntimeException('SUPCHECKOUT_BENCH_ORDERS must be 100, 1000, 5000 or 10000');
}
if (!in_array($storage, array('legacy', 'hpos'), true)) {
    throw new RuntimeException('SUPCHECKOUT_BENCH_STORAGE must be legacy or hpos');
}

// Provider-transport sentinel: only real provider hosts fail. Loopback allowed.
add_filter(
    'pre_http_request',
    static function ($preempt, $args, $url) {
        $host = parse_url((string) $url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        if ($host === '' || $host === '127.0.0.1' || $host === 'localhost') {
            return $preempt;
        }
        if (strpos($host, 'upayments') !== false || strpos($host, 'simplixpay') !== false) {
            throw new RuntimeException('PROVIDER_EGRESS_ATTEMPTED: ' . $url);
        }
        return $preempt;
    },
    1,
    3
);

// Test-only: stop LifecycleScheduler from creating due actions during fixtures.
remove_action('woocommerce_payment_complete', array(LifecycleScheduler::class, 'maybe_schedule_initial'), 20);
remove_action('woocommerce_order_status_completed', array(LifecycleScheduler::class, 'maybe_schedule_initial'), 20);
remove_action('woocommerce_order_status_processing', array(LifecycleScheduler::class, 'maybe_schedule_initial'), 20);

if (!class_exists('Action_Scheduler') && !class_exists('ActionScheduler') && class_exists('ActionScheduler_Versions')) {
    \ActionScheduler_Versions::instance()->initialize_latest_version();
}
if (!ASBridge::is_ready()) {
    throw new RuntimeException('ActionSchedulerBridge not ready');
}

Presentation::register_product_class();
if (!class_exists('WCProductCustomType')) {
    throw new RuntimeException('WCProductCustomType unavailable');
}

mt_srand($seed);

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
$ineligible_upay_ids = array();
$noise_count = 0;
$upayments_count = 0;

for ($i = 0; $i < $total_orders; $i++) {
    $bucket = $i % 20;
    $order  = wc_create_order(array('customer_id' => $user_id));

    if ($bucket < 2) {
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
        $ineligible_upay_ids[] = (int) $order->get_id();
        $upayments_count++;
        $noise_count++;
    } else {
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

// Before feeder: eligible parent actions must be zero (hooks disabled).
$pre_actions = 0;
foreach ($eligible_parent_ids as $pid) {
    if (ASBridge::has_any_open_cycle_attempt($pid, 0)) {
        $pre_actions++;
    }
}
if ($pre_actions !== 0) {
    throw new RuntimeException('HARD FAIL: pre-feeder eligible actions already exist: ' . $pre_actions);
}

$invocations = 0;
$max_loaded = 0;
$total_scanned = 0;
$total_scheduled = 0;
$total_skipped = 0;
$query_deltas = array();
$mem_deltas = array();
$peak_deltas = array();
$batch_walls = array();
$stuck = 0;
$last_sig = null;
$complete_seen = false;
$final_cursor = 0;

$max_invocations = (int) ceil($total_orders / max(1, HistoricalEnrollment::BATCH_SIZE)) + 10;

while ($invocations < $max_invocations) {
    global $wpdb;
    $q_before = (int) $wpdb->num_queries;
    $m_before = memory_get_usage(true);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $t0 = microtime(true);

    $stats = HistoricalEnrollment::run_batch();

    $t1 = microtime(true);
    $m_after = memory_get_usage(true);
    $q_after = (int) $wpdb->num_queries;
    $peak = function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : $m_after;

    $invocations++;
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
    $query_deltas[] = max(0, $q_after - $q_before);
    $mem_deltas[] = max(0, $m_after - $m_before);
    $peak_deltas[] = max(0, $peak - $m_before);
    $batch_walls[] = $t1 - $t0;
    $final_cursor = $cursor;

    $sig = $scanned . ':' . $scheduled . ':' . $skipped . ':' . $cursor;
    if ($last_sig === $sig && !$complete) {
        $stuck++;
    }
    $last_sig = $sig;

    if ($complete) {
        $complete_seen = true;
        break;
    }
}

if ($stuck > 0) {
    throw new RuntimeException('HARD FAIL: feeder stuck on same window');
}
if (!$complete_seen) {
    throw new RuntimeException('HARD FAIL: feeder incomplete');
}

// Exact per-eligible-parent identity proof.
$eligible_with_action = 0;
$missing_eligible = 0;
$duplicate_actions = 0;
foreach ($eligible_parent_ids as $pid) {
    $order = wc_get_order($pid);
    if (!$order instanceof WC_Order) {
        $missing_eligible++;
        continue;
    }
    $run_at = HistoricalEnrollment::next_run_at($order);
    if ($run_at === null) {
        $missing_eligible++;
        continue;
    }
    $open = 0;
    for ($attempt = 0; $attempt <= ASBridge::MAX_RETRY_ATTEMPT; $attempt++) {
        if (ASBridge::has_open_cycle_action($pid, $run_at, $attempt)) {
            $open++;
        }
    }
    if ($open === 1) {
        $eligible_with_action++;
    } elseif ($open === 0) {
        $missing_eligible++;
    } else {
        $duplicate_actions += ($open - 1);
    }
}

// Ineligible UPayments parents must not have feeder-created attempt-0 actions.
$noise_with_action = 0;
foreach ($ineligible_upay_ids as $pid) {
    $order = wc_get_order($pid);
    if (!$order instanceof WC_Order) {
        continue;
    }
    $run_at = HistoricalEnrollment::next_run_at($order);
    if ($run_at === null) {
        continue;
    }
    if (ASBridge::has_open_cycle_action($pid, $run_at, 0)) {
        $noise_with_action++;
    }
}

if ($missing_eligible > 0) {
    throw new RuntimeException('HARD FAIL: missing eligible actions=' . $missing_eligible);
}
if ($duplicate_actions > 0) {
    throw new RuntimeException('HARD FAIL: duplicate actions=' . $duplicate_actions);
}
if ($eligible_with_action !== $expected_eligible) {
    throw new RuntimeException('HARD FAIL: eligible_with_action ' . $eligible_with_action . ' != expected ' . $expected_eligible);
}

$total_feeder_queries = array_sum($query_deltas);
$max_queries = $query_deltas ? max($query_deltas) : 0;
$avg_queries = $query_deltas ? round($total_feeder_queries / count($query_deltas), 2) : 0;
$max_mem_delta = $mem_deltas ? max($mem_deltas) : 0;
$max_peak_delta = $peak_deltas ? max($peak_deltas) : 0;
$max_batch_wall = $batch_walls ? max($batch_walls) : 0;
$avg_batch_wall = $batch_walls ? round(array_sum($batch_walls) / count($batch_walls), 6) : 0;

$result = array(
    'storage' => $storage,
    'seed' => $seed,
    'total_orders' => $total_orders,
    'matching_upayments_orders' => $upayments_count,
    'eligible_parents' => $expected_eligible,
    'skipped_noise' => $noise_count,
    'feeder_invocations' => $invocations,
    'max_orders_loaded_per_request' => $max_loaded,
    'total_feeder_queries' => $total_feeder_queries,
    'max_queries_per_batch' => $max_queries,
    'average_queries_per_batch' => $avg_queries,
    'max_feeder_memory_delta' => $max_mem_delta,
    'max_feeder_peak_delta' => $max_peak_delta,
    'max_batch_wall_time' => round($max_batch_wall, 6),
    'average_batch_wall_time' => $avg_batch_wall,
    'total_feeder_wall_time' => round(array_sum($batch_walls), 6),
    'actions_expected' => $expected_eligible,
    'actions_found' => $eligible_with_action,
    'duplicates' => $duplicate_actions,
    'missing' => $missing_eligible,
    'noise_with_action' => $noise_with_action,
    'all_eligible_reached' => ($eligible_with_action === $expected_eligible && $missing_eligible === 0 && $duplicate_actions === 0),
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
