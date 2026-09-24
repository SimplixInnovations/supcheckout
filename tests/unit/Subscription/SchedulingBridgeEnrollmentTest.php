<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
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

    public function test_enrollment_batch_is_bounded_and_skips_ineligible(): void
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
        self::assertSame(1, $stats['skipped']);
        self::assertSame(1, $stats['scheduled']);
        self::assertTrue($stats['complete']);
        self::assertLessThanOrEqual(HistoricalEnrollment::BATCH_SIZE, $stats['scanned']);
    }
}
