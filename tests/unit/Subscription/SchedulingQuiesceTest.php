<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\Cron\Scheduler;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Takeover\Quiesce;

/**
 * 0b Task 7: while the takeover lease is live, the subscription engine defers instead of
 * claiming: the due-parent path returns pre_dispatch_no_attempt before any work, so the
 * worker queues its bounded retry of the same billing cycle, and the hourly tick skips.
 */
final class SchedulingQuiesceTest extends TestCase {
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_due_parent_defers_to_a_bounded_retry_while_quiesced(): void {
        self::boot();
        $parent = self::parent(70);
        $due = (int) HistoricalEnrollment::next_run_at($parent);
        Quiesce::acquire();

        self::assertSame(Scheduler::OUTCOME_PRE_DISPATCH_NO_ATTEMPT, Scheduler::process_parent_order($parent));

        DueParentWorker::handle(70, $due, 0);
        $retries = array();
        foreach ($GLOBALS['supcheckout_as_calls']['scheduled'] as $row) {
            $retries[] = $row['args']['retry_attempt'];
            self::assertSame($due, $row['args']['cycle_due_gmt'], 'the same billing cycle is retried');
        }
        self::assertSame(array(1), $retries, 'one bounded retry, nothing claimed');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_hourly_tick_skips_while_quiesced(): void {
        self::boot();
        $GLOBALS['supcheckout_test_logged'] = array();
        Quiesce::acquire();
        Scheduler::process();
        self::assertSame(array('Subscription tick skipped: a takeover operation holds the quiesce lease.'), $GLOBALS['supcheckout_test_logged']);
    }

    private static function boot(): void {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        \supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        \Action_Scheduler::$initialized = true;
        HistoricalEnrollment::reset_cursor();
        if (!function_exists('wc_get_logger')) {
            eval('function wc_get_logger() { return new class() {
                public function __call($level, $args) { $GLOBALS["supcheckout_test_logged"][] = $args[0]; }
            }; }');
        }
    }

    private static function parent(int $id): \WC_Order {
        $order = new \WC_Order();
        $attrs = array(
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
        foreach ($attrs as $k => $v) {
            $order->{"set_{$k}"}($v);
        }
        $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
        return $order;
    }
}
