<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 6: the copy runner's Action Scheduler wiring. The hook is registered only while
 * copying, the next batch is queued at most once, and only a continuing or retrying
 * runner queues one. Real boots in every takeover state are checked by
 * tests/harness/public-surface-snapshot-harness.php, and a paused boot by
 * tests/harness/official-plugin-coexistence-harness.php.
 */
final class CopyRunnerSchedulingTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
    }

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        TakeoverState::flush_cache();
    }

    public function test_only_a_copying_store_registers_the_batch_hook_and_exactly_once(): void {
        foreach (array('idle', 'preflight_ok', 'verified', 'cut_over', 'finalized', 'rolled_back', 'invalid', 'copying') as $status) {
            \supcheckout_test_reset_subscription_composition();
            self::state($status);

            CopyRunner::register();

            $registered = self::runner_registrations();
            if ($status === 'copying') {
                self::assertSame(array(
                    array(CopyRunner::HOOK, array(CopyRunner::class, 'handle_action'), 10, 1),
                    array('action_scheduler_init', array(CopyRunner::class, 'watchdog'), 10, 1),
                ), $registered, 'copying: the batch hook and the watchdog, once each');
            } else {
                self::assertSame(array(), $registered, "$status: nothing registered");
            }
        }
    }

    public function test_an_idle_store_without_a_takeover_record_registers_nothing(): void {
        CopyRunner::register();

        self::assertSame(array(), self::runner_registrations());
        self::assertSame(array(), $GLOBALS['supcheckout_test_action_calls'], 'no hook of any kind');
    }

    public function test_schedule_queues_one_unique_action_and_is_idempotent(): void {
        self::state('copying');

        self::assertTrue(CopyRunner::schedule());
        self::assertTrue(CopyRunner::schedule(), 'an already queued action counts');
        self::assertTrue(CopyRunner::schedule(60));

        $scheduled = $GLOBALS['supcheckout_as_calls']['scheduled'];
        self::assertCount(1, $scheduled);
        self::assertSame(CopyRunner::HOOK, $scheduled[0]['hook']);
        self::assertSame(CopyRunner::GROUP, $scheduled[0]['group']);
        self::assertSame(array(), $scheduled[0]['args']);
        self::assertTrue($scheduled[0]['unique']);
    }

    public function test_nothing_is_queued_outside_copying(): void {
        foreach (array('idle', 'preflight_ok', 'verified', 'cut_over') as $status) {
            self::state($status);
            self::assertFalse(CopyRunner::schedule(), $status);
        }
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_a_busy_runner_keeps_the_chain_alive(): void {
        // A killed action can hold the runner lock for up to LOCK_TTL; its retries see busy.
        self::assertSame(CopyRunner::RETRY_DELAY, CopyRunner::continuation_delay(CopyRunner::BUSY));
    }

    public function test_the_watchdog_requeues_a_dead_chain_once_and_only_while_copying(): void {
        self::state('copying');

        self::assertTrue(CopyRunner::watchdog());
        self::assertTrue(CopyRunner::watchdog(), 'an action already queued counts');
        self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled']);

        $GLOBALS['supcheckout_as_calls']['scheduled'] = array();
        foreach (array('idle', 'preflight_ok', 'verified', 'cut_over') as $status) {
            self::state($status);
            self::assertFalse(CopyRunner::watchdog(), $status);
        }
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_a_killed_chain_is_requeued_once_on_the_next_request_after_action_scheduler_init(): void {
        self::state('copying');
        // The killed action left nothing pending; the next request boots and registers.
        CopyRunner::register();
        $watchdog = null;
        foreach ($GLOBALS['supcheckout_test_action_calls'] as $call) {
            if ($call[0] === CopyRunner::WATCHDOG_HOOK) {
                $watchdog = $call[1];
            }
        }
        self::assertIsCallable($watchdog);

        // action_scheduler_init fires (twice: a second request, or a second plugin firing it).
        call_user_func($watchdog);
        call_user_func($watchdog);

        $scheduled = $GLOBALS['supcheckout_as_calls']['scheduled'];
        self::assertCount(1, $scheduled, 'one continuation, never a duplicate');
        self::assertSame(CopyRunner::HOOK, $scheduled[0]['hook']);
        self::assertTrue($scheduled[0]['unique']);
    }

    public function test_the_watchdog_waits_for_action_scheduler_to_be_initialized(): void {
        self::state('copying');
        \Action_Scheduler::$initialized = false;
        try {
            self::assertFalse(CopyRunner::watchdog());
            self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
        } finally {
            \Action_Scheduler::$initialized = true;
        }
    }

    public function test_only_a_finished_stopped_or_idle_runner_ends_the_chain(): void {
        self::assertSame(0, CopyRunner::continuation_delay(CopyRunner::CONTINUE_RUN));
        self::assertSame(CopyRunner::RETRY_DELAY, CopyRunner::continuation_delay(CopyRunner::RETRY));
        foreach (array(CopyRunner::VERIFIED, CopyRunner::MISMATCH, CopyRunner::STOPPED, CopyRunner::IDLE) as $status) {
            self::assertNull(CopyRunner::continuation_delay($status), $status);
        }
    }

    /** @return list<array<int, mixed>> */
    private static function runner_registrations(): array {
        return array_values(array_filter($GLOBALS['supcheckout_test_action_calls'], static function ($call) {
            return $call[0] === CopyRunner::HOOK || $call[0] === 'action_scheduler_init';
        }));
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = $status === 'invalid'
            ? '{not json'
            : (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }
}
