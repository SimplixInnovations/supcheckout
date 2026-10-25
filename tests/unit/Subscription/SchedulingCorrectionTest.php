<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;

/**
 * R4 corrections: AS readiness, cycle identity, traversal progress, outcomes.
 */
final class SchedulingCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/DueParentWorker.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/LifecycleScheduler.php';
    }

    /** @var mixed The $wpdb installed before this test, restored in tearDown(). */
    private $saved_wpdb;

    /** @var mixed The option store before this test, restored in tearDown(). */
    private $saved_options;

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
        $GLOBALS['supcheckout_test_options'] = $this->saved_options;
    }

    protected function setUp(): void
    {
        // Own $wpdb double (the shared option store): the paused-parent note lock is an
        // INSERT IGNORE / compare-and-delete on wp_options, so these tests must not lean on a
        // $wpdb some earlier test file happened to leave behind.
        $this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
        $this->saved_options = $GLOBALS['supcheckout_test_options'] ?? null;
        \supcheckout_test_reset_wp_options();

        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_as_calls'] = array(
            'scheduled' => array(),
            'unscheduled' => array(),
            'unscheduled_all' => array(),
            'did_action' => array(),
        );
        \ActionScheduler::$initialized = true;
        HistoricalEnrollment::reset_cursor();
    }

    private function make_parent(int $id, array $attrs = array()): \WC_Order
    {
        $order = new \WC_Order();
        $defaults = array(
            'id' => $id,
            'status' => 'processing',
            'payment_method' => 'upayments',
            'total' => '10.000',
            'currency' => 'KWD',
            'meta' => array(
                'UPayments_AutoDeduction' => 'no',
                '_upay_subscription_status' => 'active',
                '_upay_subscription_plan' => 'monthly',
                '_upay_subscription_interval' => '1',
                '_upay_customer_unique_token' => 'cust-1',
                '_upay_credit_card_token' => 'card-A',
            ),
            'items' => array(array('type' => 'custom_type')),
        );
        $attrs = array_merge($defaults, $attrs);
        foreach ($attrs as $k => $v) {
            $order->{"set_{$k}"}($v);
        }
        $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
        return $order;
    }

    public function test_is_ready_false_when_functions_only_or_uninitialized(): void
    {
        \ActionScheduler::$initialized = false;
        $GLOBALS['supcheckout_as_calls']['did_action'] = array();
        self::assertFalse(
            ActionSchedulerBridge::is_ready(),
            'functions present but datastore not initialized must be false'
        );

        \ActionScheduler::$initialized = true;
        self::assertTrue(ActionSchedulerBridge::is_ready());
    }

    public function test_is_ready_reads_the_real_action_scheduler_class_without_the_init_hook(): void
    {
        // Bundled Action Scheduler's class is ActionScheduler; Action_Scheduler does not exist.
        self::assertFalse(class_exists('Action_Scheduler', false), 'no Action_Scheduler stand-in hides the real class name');
        self::assertTrue(class_exists('ActionScheduler', false));

        $GLOBALS['supcheckout_as_calls']['did_action'] = array();
        \ActionScheduler::$initialized = true;
        self::assertTrue(ActionSchedulerBridge::is_ready(), 'an initialized ActionScheduler datastore is ready without the init-hook fallback');

        \ActionScheduler::$initialized = false;
        self::assertFalse(ActionSchedulerBridge::is_ready(), 'an uninitialized ActionScheduler datastore without the init hook is not ready');
    }

    public function test_is_ready_uses_action_scheduler_init_hook_fallback(): void
    {
        \ActionScheduler::$initialized = false;
        $GLOBALS['supcheckout_as_calls']['did_action'] = array('action_scheduler_init' => 1);
        self::assertTrue(ActionSchedulerBridge::is_ready());
    }

    public function test_cycle_args_are_non_secret_and_scoped(): void
    {
        $args = ActionSchedulerBridge::cycle_args(9, 123456);
        self::assertSame(array('parent_order_id' => 9, 'cycle_due_gmt' => 123456, 'retry_attempt' => 0), $args);
        self::assertArrayNotHasKey('card_token', $args);
        self::assertArrayNotHasKey('api_key', $args);
        self::assertArrayNotHasKey('customer_token', $args);
    }

    public function test_unique_scheduling_is_idempotent_per_cycle(): void
    {
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(5, time() + 60));
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(5, time() + 60));
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
        self::assertTrue($GLOBALS['supcheckout_as_calls']['scheduled'][0]['unique']);
    }

    public function test_next_cycle_schedules_while_current_cycle_in_progress(): void
    {
        $due_a = time() + 10;
        $due_b = time() + 3600;
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(8, $due_a));
        // Simulate cycle A currently in-progress: still present in queue.
        self::assertTrue(ActionSchedulerBridge::has_open_cycle_action(8, $due_a));
        // Cycle B has a different identity and must schedule independently.
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(8, $due_b));
        self::assertTrue(ActionSchedulerBridge::has_open_cycle_action(8, $due_b));
        self::assertCount(2, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_run_batch_advances_offset_cursor_across_pages_and_notes_every_parent_while_auto_charges_are_blocked(): void
    {
        foreach (range(1, 75) as $i) {
            $this->make_parent($i);
        }
        $b1 = HistoricalEnrollment::run_batch();
        self::assertSame(50, $b1['scanned']);
        self::assertFalse($b1['complete']);
        self::assertSame(50, $b1['cursor']);

        $b2 = HistoricalEnrollment::run_batch();
        self::assertSame(25, $b2['scanned']);
        self::assertTrue($b2['complete']);
        self::assertSame(0, $b2['cursor'], 'cursor resets after a complete pass for repair');

        // Fix A1-01: the bounded feeder still reaches every parent (no starvation), but while
        // automatic renewal charges are blocked it notes each one instead of queueing it.
        self::assertSame(0, $b1['scheduled'] + $b2['scheduled']);
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
        foreach (range(1, 75) as $i) {
            self::assertCount(1, wc_get_order($i)->notes, "parent {$i} is noted once");
        }
    }

    /** Gate-open companion (fix A1-01 review M2): the original R4 contract. */
    public function test_run_batch_advances_offset_cursor_across_pages_when_the_gate_is_open(): void
    {
        self::require_gate_open();
        foreach (range(1, 75) as $i) {
            $this->make_parent($i);
        }
        $b1 = HistoricalEnrollment::run_batch();
        self::assertSame(50, $b1['scanned']);
        self::assertFalse($b1['complete']);
        self::assertSame(50, $b1['cursor']);

        $b2 = HistoricalEnrollment::run_batch();
        self::assertSame(25, $b2['scanned']);
        self::assertTrue($b2['complete']);
        self::assertSame(0, $b2['cursor'], 'cursor resets after a complete pass for repair');

        // No permanent starvation: all 75 eventually scheduled.
        self::assertSame(75, $b1['scheduled'] + $b2['scheduled']);
    }

    /** Gate-open companion (fix A1-01 review M2): the original R4 contract. */
    public function test_run_batch_matrix_counts_when_the_gate_is_open(): void
    {
        self::require_gate_open();
        foreach (array(0, 1, 49, 50, 51, 75, 100, 125) as $total) {
            HistoricalEnrollment::reset_cursor();
            $GLOBALS['supcheckout_test_status_orders'] = array();
            $GLOBALS['supcheckout_as_calls']['scheduled'] = array();
            foreach (range(1, max($total, 1)) as $i) {
                if ($total === 0) {
                    break;
                }
                $this->make_parent($i);
            }
            $seen = 0;
            $guard = 0;
            do {
                $stats = HistoricalEnrollment::run_batch();
                $seen += $stats['scanned'];
                $guard++;
            } while (!$stats['complete'] && $guard < 20);

            self::assertSame($total, $seen, "total={$total} must all be examined");
            self::assertSame($total === 0 ? 0 : $total, count($GLOBALS['supcheckout_as_calls']['scheduled']), "total={$total} scheduled count");
        }
    }

    /** Gate-open companion (fix A1-01 review M2): the original R4 contract. */
    public function test_worker_skips_stale_cycle_without_mutating_claims_when_the_gate_is_open(): void
    {
        self::require_gate_open();
        $this->make_parent(30);
        DueParentWorker::handle(30, time() - 86400);
        // Stale cycle must not remain the only action; current due is scheduled.
        self::assertNotEmpty($GLOBALS['supcheckout_as_calls']['scheduled']);
        foreach ($GLOBALS['supcheckout_as_calls']['scheduled'] as $row) {
            self::assertSame(30, $row['args']['parent_order_id']);
        }
    }

    /** Gate-open companion (fix A1-01 review M2): the original R4 contract. */
    public function test_resume_schedules_exactly_one_next_cycle_when_the_gate_is_open(): void
    {
        self::require_gate_open();
        $parent = $this->make_parent(50);
        self::assertTrue(LifecycleScheduler::schedule_resume($parent));
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    private static function require_gate_open(): void
    {
        if (!AutoDeductResultVerifier::can_verify_success()) {
            self::markTestSkipped('Automatic renewal charges are blocked (fix A1-01); the queueing contract applies once the gate opens.');
        }
    }

    public function test_run_batch_matrix_counts_examines_every_order_and_queues_nothing_while_auto_charges_are_blocked(): void
    {
        foreach (array(0, 1, 49, 50, 51, 75, 100, 125) as $total) {
            HistoricalEnrollment::reset_cursor();
            $GLOBALS['supcheckout_test_status_orders'] = array();
            $GLOBALS['supcheckout_as_calls']['scheduled'] = array();
            foreach (range(1, max($total, 1)) as $i) {
                if ($total === 0) {
                    break;
                }
                $this->make_parent($i);
            }
            if ($total === 0) {
                $GLOBALS['supcheckout_test_status_orders'] = array();
            }
            $seen = 0;
            $guard = 0;
            do {
                $stats = HistoricalEnrollment::run_batch();
                self::assertLessThanOrEqual(HistoricalEnrollment::BATCH_SIZE, $stats['scanned']);
                $seen += $stats['scanned'];
                $guard++;
            } while (!$stats['complete'] && $guard < 20);

            self::assertSame($total, $seen, "total={$total} must all be examined");
            // Fix A1-01: nothing is queued while automatic renewal charges are blocked.
            self::assertSame(0, count($GLOBALS['supcheckout_as_calls']['scheduled']), "total={$total} scheduled count");
        }
    }

    public function test_parent_qualifies_requires_paid_status(): void
    {
        $ok = $this->make_parent(1);
        self::assertTrue(HistoricalEnrollment::parent_qualifies($ok));

        $pending = $this->make_parent(2, array('status' => 'pending'));
        self::assertFalse(HistoricalEnrollment::parent_qualifies($pending));
    }

    public function test_worker_skips_stale_cycle_and_queues_no_replacement_while_auto_charges_are_blocked(): void
    {
        $parent = $this->make_parent(30);
        $stale_due = time() - 86400;
        DueParentWorker::handle(30, $stale_due);
        // Fix A1-01: while automatic renewal charges are blocked the stale action is not
        // replaced by one for the current cycle (it was before the owner decision).
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_pause_cancels_pending_supcheckout_actions(): void
    {
        $this->make_parent(40);
        $due = (int) HistoricalEnrollment::next_run_at(wc_get_order(40));
        ActionSchedulerBridge::ensure_cycle_action(40, $due);
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
        LifecycleScheduler::maybe_cancel_on_status_meta(1, 40, '_upay_subscription_status', 'paused');
        self::assertCount(0, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_resume_queues_nothing_and_notes_the_parent_while_auto_charges_are_blocked(): void
    {
        $parent = $this->make_parent(50);
        // Fix A1-01: resume queues nothing while automatic renewal charges are blocked.
        self::assertFalse(LifecycleScheduler::schedule_resume($parent));
        self::assertCount(0, $GLOBALS['supcheckout_as_calls']['scheduled']);
        self::assertCount(1, $parent->notes);
    }

    public function test_duplicate_concurrent_style_calls_yield_one_action(): void
    {
        $due = time() + 90;
        $a = ActionSchedulerBridge::ensure_cycle_action(60, $due);
        $b = ActionSchedulerBridge::ensure_cycle_action(60, $due);
        self::assertTrue($a);
        self::assertTrue($b);
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }
}
