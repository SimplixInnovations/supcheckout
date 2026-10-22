<?php

namespace Simplixi\SUPCheckout\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Bootstrap\Deactivation;
use Simplixi\SUPCheckout\Payment\ReconciliationSweep;
use Simplixi\SUPCheckout\Release\Identity;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * A3-08 (verify-P U8): deactivating SUPCheckout stops its own background schedules and keeps
 * everything else. It removes the hourly reconciliation sweep (and its U2 throttle marker, so
 * a reactivation schedules the sweep at once), the new-name twin of the subscription tick and,
 * only while no takeover work is in flight, stale onboarding driver and copy-runner actions.
 * It never touches the protected upay_process_subscriptions tick, never removes a per-order
 * reconcile check (a quick reactivation still runs it; a removed secondary check is never
 * re-armed) and never deletes data.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeactivationTest extends TestCase {
    private const MARKER = 'supcheckout_sweep_ensured_at';

    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/deactivation-stubs.php';
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_migration_bootstrap();
        \supcheckout_deactivation_test_reset();
    }

    /** @param list<mixed> $args */
    private static function action(string $hook, array $args, string $group): void {
        $GLOBALS['supcheckout_as_calls']['scheduled'][] = array('timestamp' => time() + 60, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => false);
    }

    /** @param list<mixed> $args */
    private static function event(string $hook, array $args = array()): void {
        $GLOBALS['supcheckout_test_cron'][] = array('hook' => $hook, 'args' => $args, 'timestamp' => time() + 600);
    }

    /** @return list<string> */
    private static function pending_actions(): array {
        return array_map(static function ($row) {
            return $row['group'] . ':' . $row['hook'];
        }, $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    /** @return list<string> */
    private static function pending_events(): array {
        return array_column($GLOBALS['supcheckout_test_cron'], 'hook');
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
    }

    private static function operation(string $outcome): void {
        $GLOBALS['supcheckout_test_options'][Operation::OPTION] = (string) json_encode(array('id' => 'op-1', 'kind' => Operation::KIND_SWITCH, 'outcome' => $outcome));
    }

    private static function seed_driver_actions(): void {
        self::action(AsyncDriver::HOOK, array('op-1'), AsyncDriver::GROUP);
        self::action(AsyncDriver::WATCHDOG_HOOK, array('op-1', 'copy'), AsyncDriver::GROUP);
        self::action(CopyRunner::HOOK, array(), CopyRunner::GROUP);
    }

    public function test_deactivation_unschedules_the_hourly_sweep_and_deletes_its_throttle_marker(): void {
        self::action(ReconciliationSweep::HOOK, array(), 'supcheckout');
        self::action('woocommerce_cleanup_draft_orders', array(), 'woocommerce-db-updates');
        $GLOBALS['supcheckout_test_options'][self::MARKER] = time() - 60;

        Deactivation::deactivate();

        self::assertSame(array('woocommerce-db-updates:woocommerce_cleanup_draft_orders'), self::pending_actions(), 'only the sweep is removed');
        self::assertContains(array(ReconciliationSweep::HOOK, array(), 'supcheckout'), $GLOBALS['supcheckout_as_calls']['unscheduled_all'], 'exact hook, arguments and group: never group-wide');
        self::assertArrayNotHasKey(self::MARKER, $GLOBALS['supcheckout_test_options'], 'a reactivation schedules the sweep at once');
    }

    public function test_deactivation_removes_the_new_name_subscription_twin_but_never_the_protected_tick_or_a_per_order_check(): void {
        self::state(TakeoverState::CUT_OVER);
        self::event('upay_process_subscriptions');
        self::event('supcheckout_process_subscriptions');
        self::event('simplixpay_upayments_reconcile_order', array(41));
        self::event('supcheckout_reconcile_order', array(42));
        self::event('simplixpay_upayments_reconcile_secondary', array(43));
        self::event('supcheckout_reconcile_secondary', array(44));
        self::event('woocommerce_scheduled_sales');

        Deactivation::deactivate();

        self::assertSame(array('supcheckout_process_subscriptions'), $GLOBALS['supcheckout_test_cron_unscheduled']);
        self::assertSame(array(
            'upay_process_subscriptions',
            'simplixpay_upayments_reconcile_order',
            'supcheckout_reconcile_order',
            'simplixpay_upayments_reconcile_secondary',
            'supcheckout_reconcile_secondary',
            'woocommerce_scheduled_sales',
        ), self::pending_events());
    }

    public function test_stale_onboarding_and_copy_actions_are_removed_when_no_takeover_work_is_in_flight(): void {
        foreach (array(TakeoverState::IDLE, TakeoverState::VERIFIED, TakeoverState::FINALIZED) as $status) {
            \supcheckout_deactivation_test_reset();
            \supcheckout_test_reset_wp_options();
            self::state($status);
            self::operation(Operation::DONE);
            self::seed_driver_actions();

            Deactivation::deactivate();

            self::assertSame(array(), self::pending_actions(), $status);
            self::assertContains(array(AsyncDriver::HOOK, null, AsyncDriver::GROUP), $GLOBALS['supcheckout_as_calls']['unscheduled_all'], $status);
            self::assertContains(array(AsyncDriver::WATCHDOG_HOOK, null, AsyncDriver::GROUP), $GLOBALS['supcheckout_as_calls']['unscheduled_all'], $status);
            self::assertContains(array(CopyRunner::HOOK, array(), CopyRunner::GROUP), $GLOBALS['supcheckout_as_calls']['unscheduled_all'], $status);
        }
    }

    public function test_takeover_work_in_flight_keeps_the_driver_the_copy_runner_and_the_cron_twin(): void {
        $cases = array(
            'a running operation' => array(TakeoverState::CUT_OVER, Operation::RUNNING),
            'the copying state' => array(TakeoverState::COPYING, Operation::DONE),
            'an unreadable takeover state' => array(null, Operation::DONE),
            'an unreadable operation record' => array(TakeoverState::CUT_OVER, null),
        );
        foreach ($cases as $label => $case) {
            \supcheckout_deactivation_test_reset();
            \supcheckout_test_reset_wp_options();
            if ($case[0] === null) {
                $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = '{not json';
            } else {
                self::state($case[0]);
            }
            if ($case[1] === null) {
                $GLOBALS['supcheckout_test_options'][Operation::OPTION] = '{not json';
            } else {
                self::operation($case[1]);
            }
            self::seed_driver_actions();
            self::action(ReconciliationSweep::HOOK, array(), 'supcheckout');
            self::event('supcheckout_process_subscriptions');
            $GLOBALS['supcheckout_test_options'][self::MARKER] = time();

            Deactivation::deactivate();

            self::assertSame(array(
                AsyncDriver::GROUP . ':' . AsyncDriver::HOOK,
                AsyncDriver::GROUP . ':' . AsyncDriver::WATCHDOG_HOOK,
                CopyRunner::GROUP . ':' . CopyRunner::HOOK,
            ), self::pending_actions(), $label . ': the takeover can resume after a quick reactivation');
            self::assertSame(array('supcheckout_process_subscriptions'), self::pending_events(), $label);
            self::assertArrayNotHasKey(self::MARKER, $GLOBALS['supcheckout_test_options'], $label . ': the sweep is still removed');
        }
    }

    public function test_a_paused_deactivation_registers_no_payment_runtime(): void {
        // While the official plugin is active (PausedBoot), the deactivation request must not
        // load Release/Identity.php: loading it runs PaymentLifecycle::bootstrap(), which adds
        // the payment callback and reconcile hooks the official plugin owns in that request.
        self::state(TakeoverState::CUT_OVER);
        self::operation(Operation::DONE);
        self::event('supcheckout_process_subscriptions');

        Deactivation::deactivate();

        self::assertSame(array('supcheckout_process_subscriptions'), $GLOBALS['supcheckout_test_cron_unscheduled']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_hook_calls'], 'no runtime hook is added');
        self::assertFalse(class_exists(Identity::class, false), 'Release/Identity.php is not loaded');
    }

    public function test_the_legacy_subscription_tick_name_is_the_release_identity_one(): void {
        self::assertSame(Identity::LEGACY_SUBSCRIPTION_HOOK, Deactivation::LEGACY_SUBSCRIPTION_HOOK);
    }

    public function test_deactivation_deletes_only_the_throttle_marker(): void {
        self::state(TakeoverState::CUT_OVER);
        self::operation(Operation::DONE);
        $GLOBALS['supcheckout_test_options'] += array(
            self::MARKER => time(),
            'woocommerce_upayments_settings' => array('enabled' => 'yes'),
            'woocommerce_supcheckout_settings' => array('enabled' => 'yes'),
            'upayments_token_identity_secret_v2' => 'secret',
        );
        $before = $GLOBALS['supcheckout_test_options'];
        unset($before[self::MARKER]);

        Deactivation::deactivate();

        self::assertSame($before, $GLOBALS['supcheckout_test_options']);
        $writes = array_values(array_filter($GLOBALS['supcheckout_test_option_calls'], static function ($call) {
            return $call[0] !== 'get';
        }));
        self::assertSame(array(array('delete', self::MARKER)), $writes, 'no other option is added, updated or deleted');
    }

    public function test_without_a_ready_action_scheduler_it_only_deletes_the_marker(): void {
        \Action_Scheduler::$initialized = false;
        self::action(ReconciliationSweep::HOOK, array(), 'supcheckout');
        $GLOBALS['supcheckout_test_options'][self::MARKER] = time();

        Deactivation::deactivate();

        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['unscheduled_all'], 'Action Scheduler is never called before it is ready');
        self::assertArrayNotHasKey(self::MARKER, $GLOBALS['supcheckout_test_options']);
    }

    public function test_a_failing_step_never_blocks_the_deactivation_or_the_other_steps(): void {
        $GLOBALS['supcheckout_test_cron_throws'] = true;
        $GLOBALS['supcheckout_test_as_unschedule_throws'] = true;
        self::state(TakeoverState::IDLE);
        self::seed_driver_actions();
        $GLOBALS['supcheckout_test_options'][self::MARKER] = time();

        Deactivation::deactivate();

        self::assertArrayNotHasKey(self::MARKER, $GLOBALS['supcheckout_test_options'], 'the marker is deleted even when Action Scheduler throws');
    }
}
