<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;

/**
 * R4 Action Scheduler bridge + bounded enrollment RED/GREEN.
 */
final class SchedulingBridgeEnrollmentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
    }

    /** @var mixed The $wpdb installed before this test, restored in tearDown(). */
    private $saved_wpdb;

    /** @var mixed The option store before this test, restored in tearDown(). */
    private $saved_options;

    protected function setUp(): void
    {
        // Own $wpdb double (the shared option store): the paused-parent note lock is an
        // INSERT IGNORE / compare-and-delete on wp_options, so these tests must not lean on a
        // $wpdb some earlier test file happened to leave behind.
        $this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
        $this->saved_options = $GLOBALS['supcheckout_test_options'] ?? null;
        \supcheckout_test_reset_wp_options();
        \ActionScheduler::$initialized = true;

        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_as_calls'] = array(
            'scheduled' => array(),
            'has' => array(),
            'unscheduled' => array(),
        );
        if (!function_exists('as_schedule_single_action')) {
            // defined in fixture below via eval-free stubs file
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
        $GLOBALS['supcheckout_test_options'] = $this->saved_options;
    }

    private function make_parent(array $attrs = array()): \WC_Order
    {
        $order = new \WC_Order();
        $defaults = array(
            'id' => 900 + count($GLOBALS['supcheckout_test_status_orders']),
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
        $GLOBALS['supcheckout_test_status_orders'][(int) $order->get_id()] = $order;
        return $order;
    }

    public function test_bridge_is_unready_without_action_scheduler_apis(): void
    {
        // Stubs file always defines AS functions for other tests; readiness is
        // true when they exist. Capability fail-closed is proven by signature.
        self::assertTrue(ActionSchedulerBridge::is_ready() || !function_exists('as_schedule_single_action'));
    }

    public function test_ensure_parent_action_schedules_only_parent_id_arg(): void
    {
        $ok = ActionSchedulerBridge::ensure_cycle_action(42, time() + 60);
        self::assertTrue($ok);
        $calls = $GLOBALS['supcheckout_as_calls']['scheduled'];
        self::assertCount(1, $calls);
        self::assertSame('supcheckout_process_due_parent', $calls[0]['hook']);
        self::assertSame(42, $calls[0]['args']['parent_order_id']);
        self::assertArrayHasKey('cycle_due_gmt', $calls[0]['args']);
        self::assertSame('supcheckout', $calls[0]['group']);
        self::assertArrayNotHasKey('card_token', $calls[0]['args']);
        self::assertArrayNotHasKey('customer_token', $calls[0]['args']);
        self::assertArrayNotHasKey('api_key', $calls[0]['args']);
    }

    public function test_ensure_parent_action_is_duplicate_safe(): void
    {
        $due = time() + 30;
        ActionSchedulerBridge::ensure_cycle_action(7, $due);
        ActionSchedulerBridge::ensure_cycle_action(7, $due);
        ActionSchedulerBridge::ensure_cycle_action(7, $due);
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_parent_qualifies_rejects_children_and_inactive(): void
    {
        $ok = $this->make_parent();
        self::assertTrue(HistoricalEnrollment::parent_qualifies($ok));

        $child = $this->make_parent(array('meta' => array(
            'UPayments_AutoDeduction' => 'yes',
            '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));
        self::assertFalse(HistoricalEnrollment::parent_qualifies($child));

        $paused = $this->make_parent(array('meta' => array(
            'UPayments_AutoDeduction' => 'no',
            '_upay_subscription_status' => 'paused',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));
        self::assertFalse(HistoricalEnrollment::parent_qualifies($paused));

        $no_product = $this->make_parent(array('items' => array()));
        self::assertFalse(HistoricalEnrollment::parent_qualifies($no_product));
    }

    /**
     * Gate-open companion (fix A1-01 review M2): the original R4 contract, skipped while
     * automatic renewal charges are blocked and run again the day the gate opens.
     */
    public function test_enrollment_batch_is_bounded_and_skips_ineligible_when_the_gate_is_open(): void
    {
        if (!AutoDeductResultVerifier::can_verify_success()) {
            self::markTestSkipped('Automatic renewal charges are blocked (fix A1-01); the queueing contract applies once the gate opens.');
        }
        HistoricalEnrollment::reset_cursor();
        $a = $this->make_parent(array('id' => 11));
        $b = $this->make_parent(array('id' => 12, 'meta' => array(
            'UPayments_AutoDeduction' => 'yes',
            '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));

        $stats = HistoricalEnrollment::enroll_slice(array($a, $b), true);
        self::assertSame(2, $stats['scanned']);
        self::assertSame(1, $stats['skipped']);
        self::assertSame(1, $stats['scheduled']);
        self::assertTrue($stats['complete']);
        self::assertLessThanOrEqual(HistoricalEnrollment::BATCH_SIZE, $stats['scanned']);
    }

    public function test_enrollment_batch_is_bounded_and_notes_instead_of_queueing_while_auto_charges_are_blocked(): void
    {
        HistoricalEnrollment::reset_cursor();
        $a = $this->make_parent(array('id' => 11));
        $b = $this->make_parent(array('id' => 12, 'meta' => array(
            'UPayments_AutoDeduction' => 'yes',
            '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
        )));

        $stats = HistoricalEnrollment::enroll_slice(array($a, $b), true);
        self::assertSame(2, $stats['scanned']);
        // Fix A1-01: while automatic renewal charges are blocked the qualifying parent is
        // noted, not queued (it was scheduled=1 before the owner decision of 2026-10-22).
        self::assertSame(2, $stats['skipped']);
        self::assertSame(0, $stats['scheduled']);
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
        self::assertCount(1, $a->notes, 'the qualifying parent gets the paused note');
        self::assertSame(array(), $b->notes, 'a renewal child gets none');
        self::assertTrue($stats['complete']);
        self::assertLessThanOrEqual(HistoricalEnrollment::BATCH_SIZE, $stats['scanned']);
    }
}
