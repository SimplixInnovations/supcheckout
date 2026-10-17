<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 7: cron hooks are scheduled under the new names after cutover, and listened to
 * (and checked, and unscheduled) under both names from `copying` on, so an event kept
 * under the legacy name until Finalize still runs.
 */
final class CronRoutingTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    public function test_schedule_and_listen_names_follow_the_takeover_status(): void {
        $expected = array(
            'idle' => array('upay_process_subscriptions', array('upay_process_subscriptions')),
            'preflight_ok' => array('upay_process_subscriptions', array('upay_process_subscriptions')),
            'copying' => array('upay_process_subscriptions', array('upay_process_subscriptions', 'supcheckout_process_subscriptions')),
            'verified' => array('upay_process_subscriptions', array('upay_process_subscriptions', 'supcheckout_process_subscriptions')),
            'cut_over' => array('supcheckout_process_subscriptions', array('upay_process_subscriptions', 'supcheckout_process_subscriptions')),
            'rolled_back' => array('upay_process_subscriptions', array('upay_process_subscriptions', 'supcheckout_process_subscriptions')),
            'finalized' => array('supcheckout_process_subscriptions', array('upay_process_subscriptions', 'supcheckout_process_subscriptions')),
        );
        foreach ($expected as $status => $names) {
            $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
            TakeoverState::flush_cache();
            self::assertSame($names[0], Keys::cron_hook('upay_process_subscriptions'), $status . ' schedules');
            self::assertSame($names[1], Keys::cron_hook_names('upay_process_subscriptions'), $status . ' listens');
        }
    }

    public function test_only_registered_cron_hooks_are_routed(): void {
        foreach (array('simplixpay_upayments_reconcile_order' => 'supcheckout_reconcile_order', 'simplixpay_upayments_reconcile_secondary' => 'supcheckout_reconcile_secondary') as $legacy => $new) {
            self::assertSame(array($legacy), Keys::cron_hook_names($legacy));
            self::assertSame($new, Keys::cron_hooks()[$legacy]);
        }
        $this->expectException(\InvalidArgumentException::class);
        Keys::cron_hook('UPayments_PaymentID');
    }
}
