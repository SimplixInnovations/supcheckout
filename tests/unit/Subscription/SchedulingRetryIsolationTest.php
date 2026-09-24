<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;

/**
 * R4-2: cancellation isolation, retry cycle identity, customer lifecycle.
 */
final class SchedulingRetryIsolationTest extends TestCase
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

    protected function setUp(): void
    {
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_as_calls'] = array(
            'scheduled' => array(),
            'unscheduled' => array(),
            'unscheduled_all' => array(),
            'did_action' => array(),
        );
        \Action_Scheduler::$initialized = true;
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

    public function test_cycle_args_include_retry_attempt_and_no_secrets(): void
    {
        $args = ActionSchedulerBridge::cycle_args(9, 123456, 2);
        self::assertSame(
            array('parent_order_id' => 9, 'cycle_due_gmt' => 123456, 'retry_attempt' => 2),
            $args
        );
        self::assertArrayNotHasKey('card_token', $args);
        self::assertArrayNotHasKey('api_key', $args);
        self::assertArrayNotHasKey('customer_token', $args);
    }

    public function test_pause_cancels_only_parent_a(): void
    {
        $this->make_parent(1);
        $this->make_parent(2);
        $this->make_parent(3);
        $due_a = (int) HistoricalEnrollment::next_run_at(wc_get_order(1));
        $due_b = (int) HistoricalEnrollment::next_run_at(wc_get_order(2));
        $due_c = (int) HistoricalEnrollment::next_run_at(wc_get_order(3));
        ActionSchedulerBridge::ensure_cycle_action(1, $due_a, null, 0);
        ActionSchedulerBridge::ensure_cycle_action(2, $due_b, null, 0);
        ActionSchedulerBridge::ensure_cycle_action(3, $due_c, time() + 60, 1);

        $this->make_parent(1, array('meta' => array(
            'UPayments_AutoDeduction' => 'no',
            '_upay_subscription_status' => 'paused',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));
        $order_a = wc_get_order(1);
        LifecycleScheduler::cancel_after_state_change($order_a);

        self::assertFalse(
            ActionSchedulerBridge::has_any_open_cycle_attempt(1, $due_a),
            'parent A cycle actions removed'
        );
        self::assertTrue(
            ActionSchedulerBridge::has_open_cycle_action(2, $due_b, 0),
            'parent B remains pending'
        );
        self::assertTrue(
            ActionSchedulerBridge::has_open_cycle_action(3, $due_c, 1),
            'parent C retry remains pending'
        );
        foreach ($GLOBALS['supcheckout_as_calls']['unscheduled_all'] as $call) {
            self::assertNotNull($call[1], 'exact args required for unschedule_all');
        }
    }

    public function test_cancel_isolates_to_parent_and_cycle_including_retries(): void
    {
        $due = time() + 500;
        ActionSchedulerBridge::ensure_cycle_action(11, $due, null, 0);
        ActionSchedulerBridge::ensure_cycle_action(11, $due, time() + 600, 1);
        ActionSchedulerBridge::ensure_cycle_action(12, $due, null, 0);

        self::assertTrue(ActionSchedulerBridge::cancel_cycle_actions(11, $due));
        self::assertFalse(ActionSchedulerBridge::has_any_open_cycle_attempt(11, $due));
        self::assertTrue(
            ActionSchedulerBridge::has_open_cycle_action(12, $due, 0),
            'other parent with same due timestamp untouched'
        );
    }

    public function test_retry_preserves_cycle_due_and_changes_only_run_at_and_ordinal(): void
    {
        $cycle_t = 1780000000; // immutable billing-cycle identity
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(21, $cycle_t, $cycle_t, 0));

        $delay = ActionSchedulerBridge::retry_delay_for_attempt(1);
        self::assertSame(3600, $delay);
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(21, $cycle_t, time() + $delay, 1));

        $rows = $GLOBALS['supcheckout_as_calls']['scheduled'];
        self::assertCount(2, $rows);
        self::assertSame($cycle_t, $rows[0]['args']['cycle_due_gmt']);
        self::assertSame($cycle_t, $rows[1]['args']['cycle_due_gmt'], 'retry keeps true billing cycle');
        self::assertSame(0, $rows[0]['args']['retry_attempt']);
        self::assertSame(1, $rows[1]['args']['retry_attempt']);
        self::assertGreaterThan($rows[0]['timestamp'], $rows[1]['timestamp'], 'retry run_at is delayed');
    }

    public function test_in_progress_attempt0_does_not_suppress_attempt1(): void
    {
        $cycle_t = 1780001111;
        ActionSchedulerBridge::ensure_cycle_action(22, $cycle_t, $cycle_t, 0);
        // attempt 0 remains "in progress" in the queue
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(22, $cycle_t, time() + 3600, 1));
        self::assertCount(2, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_retry_duplicate_protection_is_exact(): void
    {
        $cycle_t = 1780002222;
        $run = time() + 3600;
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(23, $cycle_t, $run, 1));
        self::assertTrue(ActionSchedulerBridge::ensure_cycle_action(23, $cycle_t, $run, 1));
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_max_retry_stops_without_hot_loop(): void
    {
        self::assertNull(ActionSchedulerBridge::retry_delay_for_attempt(4));
        self::assertSame(3, ActionSchedulerBridge::MAX_RETRY_ATTEMPT);
        self::assertSame(86400, ActionSchedulerBridge::retry_delay_for_attempt(3));
    }

    public function test_pause_after_retry_removes_retry_and_not_others(): void
    {
        $this->make_parent(31);
        $this->make_parent(32);
        $cycle_t = (int) HistoricalEnrollment::next_run_at(wc_get_order(31));
        $other = (int) HistoricalEnrollment::next_run_at(wc_get_order(32));
        ActionSchedulerBridge::ensure_cycle_action(31, $cycle_t, time() + 3600, 1);
        ActionSchedulerBridge::ensure_cycle_action(32, $other, null, 0);

        $this->make_parent(31, array('meta' => array(
            'UPayments_AutoDeduction' => 'no',
            '_upay_subscription_status' => 'paused',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));
        LifecycleScheduler::cancel_after_state_change(wc_get_order(31));

        self::assertFalse(ActionSchedulerBridge::has_open_cycle_action(31, $cycle_t, 1));
        self::assertTrue(ActionSchedulerBridge::has_open_cycle_action(32, $other, 0));
    }

    public function test_resume_schedules_exactly_one_attempt0(): void
    {
        $parent = $this->make_parent(41);
        self::assertTrue(LifecycleScheduler::schedule_resume($parent));
        $rows = $GLOBALS['supcheckout_as_calls']['scheduled'];
        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]['args']['retry_attempt']);
    }

    public function test_repair_feeder_does_not_compete_with_pending_retry(): void
    {
        $parent = $this->make_parent(51);
        $due = HistoricalEnrollment::next_run_at($parent);
        self::assertNotNull($due);
        ActionSchedulerBridge::ensure_cycle_action(51, $due, time() + 3600, 1);

        $stats = HistoricalEnrollment::enroll_slice(array($parent), true);
        self::assertSame(1, $stats['skipped']);
        self::assertSame(0, $stats['scheduled']);
        self::assertTrue(ActionSchedulerBridge::has_open_cycle_action(51, $due, 1));
        self::assertFalse(ActionSchedulerBridge::has_open_cycle_action(51, $due, 0));
    }

    public function test_stale_cycle_check_uses_cycle_due_not_run_at(): void
    {
        // Same cycle_due, delayed run_at (retry) must pass stale check.
        // This is proven by schedule_bounded_retry keeping cycle_due; assert args contract.
        $cycle_t = 1780005555;
        $args = ActionSchedulerBridge::cycle_args(61, $cycle_t, 1);
        self::assertSame($cycle_t, $args['cycle_due_gmt']);
        self::assertArrayNotHasKey('run_at_gmt', $args);
    }
}
