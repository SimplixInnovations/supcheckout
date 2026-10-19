<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\BudgetedWordPressSystem;
use Simplixi\SUPCheckout\Takeover\Onboarding\Checks;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\CutoverTest;
use Simplixi\SUPCheckout\Tests\Takeover\FakeBudgetedSystem;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCopyStore;
use Simplixi\SUPCheckout\Tests\Takeover\FakeStaleClaimStore;

/**
 * 0c Task 6: the Action Scheduler side of the driver. Hooks only in contexts that run
 * Action Scheduler, one unique action per operation in the onboarding group, a dead-man
 * watchdog replaced on every tick, stalled-runner detection and generic failure records.
 */
final class AsyncDriverTest extends TestCase {
    private const SECRET_MESSAGE = 'db password is hunter2';

    /** @var list<array{0:string, 1:int}> Continuations deferred to shutdown. */
    private $deferred = array();
    /** @var array<string, mixed> */
    private $self_test_report = array();
    /** @var BudgetedWordPressSystem|null The system drive() built for the last tick. */
    private $built = null;

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 3) . '/support/onboarding-driver-stubs.php';
    }

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        TakeoverState::flush_cache();
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
        $GLOBALS['supcheckout_test_time_limits'] = array();
        \Action_Scheduler::$initialized = true;
        // Real Action Scheduler: `unique` compares only hook and group.
        $GLOBALS['supcheckout_test_unique_hook_group_only'] = true;
        $this->deferred = array();
        $this->self_test_report = Checks::report(array(Checks::check('event_journal', Checks::DEFERRED, 'event_journal_not_built', 'later')), 1);
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
        \Action_Scheduler::$initialized = true;
        $GLOBALS['supcheckout_test_unique_hook_group_only'] = false;
    }

    // --- Fixtures. ---

    /**
     * Record every get_option() name from now on (the option store's read filter).
     *
     * @return \stdClass {names: list<string>}
     */
    private static function spy_option_reads(): \stdClass {
        $reads = new \stdClass();
        $reads->names = array();
        $GLOBALS['supcheckout_test_get_option_filter'] = static function ($name, $value) use ($reads) {
            $reads->names[] = (string) $name;
            return $value;
        };
        return $reads;
    }

    /** @param array<string, bool> $consent */
    private static function start(string $kind, array $consent = array()): array {
        $op = Operation::start($kind, $consent, 1, time());
        self::assertNotNull($op);
        return $op;
    }

    /** @param array<string, mixed> $fields */
    private static function update(array $op, array $fields): void {
        self::assertTrue(Operation::update($op['id'], $fields, time()));
    }

    /** @return callable function (BudgetedWordPressSystem $system): DriverStep, bound to fakes. */
    private function fake_step(FakeCopyStore $store, FakeBudgetedSystem $system): callable {
        return function (BudgetedWordPressSystem $built) use ($store, $system) {
            $this->built = $built;
            $onboarding = new Onboarding(static function () {
                return array('blocking' => array(), 'warnings' => array(), 'facts' => array());
            }, $store, $system, new FakeStaleClaimStore());
            $self_test = function () {
                return $this->self_test_report;
            };
            return new DriverStep($onboarding, $system, $self_test, $self_test, 'time');
        };
    }

    private function defer(): callable {
        return function ($op_id, $delay) {
            $this->deferred[] = array($op_id, $delay);
        };
    }

    /** @return list<array<string, mixed>> */
    private static function scheduled(string $hook): array {
        return array_values(array_filter($GLOBALS['supcheckout_as_calls']['scheduled'], static function ($row) use ($hook) {
            return $row['hook'] === $hook;
        }));
    }

    private function seed_verified(FakeBudgetedSystem $system): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = hash('sha256', 'secret');
        CutoverTest::state('verified');
        $system->official = null;
    }

    // --- register / enqueue. ---

    public function test_register_adds_both_hooks_only_in_cron_ajax_or_cli_contexts(): void {
        $reads = self::spy_option_reads();
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
        AsyncDriver::register();
        self::assertSame(array(
            array('action_scheduler_before_process_queue', array(AsyncDriver::class, 'register_hooks'), 10, 1),
        ), $GLOBALS['supcheckout_test_action_calls'], 'web request: only the lazy Action Scheduler registration');

        foreach (array('cron', 'ajax') as $context) {
            \supcheckout_test_reset_subscription_composition();
            $GLOBALS['supcheckout_test_request_context'] = array('cron' => $context === 'cron', 'ajax' => $context === 'ajax');

            AsyncDriver::register();

            self::assertSame(array(
                array(AsyncDriver::HOOK, array(AsyncDriver::class, 'handle'), 10, 1),
                array(AsyncDriver::WATCHDOG_HOOK, array(AsyncDriver::class, 'watchdog'), 10, 2),
            ), $GLOBALS['supcheckout_test_action_calls'], $context);
        }
        self::assertSame(array(), $reads->names, 'no option read to register');
        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls'], 'no option written to register');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_register_adds_both_hooks_under_wp_cli(): void {
        require_once dirname(__DIR__, 3) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 3) . '/support/onboarding-driver-stubs.php';
        define('WP_CLI', true);
        \supcheckout_test_reset_subscription_composition();

        AsyncDriver::register();

        self::assertSame(array(AsyncDriver::HOOK, AsyncDriver::WATCHDOG_HOOK), array_column($GLOBALS['supcheckout_test_action_calls'], 0));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_queue_start_registers_both_hooks_once_in_any_context(): void {
        // Controller carry-in: under ALTERNATE_WP_CRON (wp-cron runs inside a normal request,
        // after DOING_CRON would have been read) the queue would run the actions with no
        // callback, which Action Scheduler marks failed. Every queue runner fires this action
        // before it processes its queue (ActionScheduler_QueueRunner::run() and the WP-CLI
        // runner); the admin "Run" row action calls process_action() directly and does not.
        require_once dirname(__DIR__, 3) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 3) . '/support/onboarding-driver-stubs.php';
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_wp_options();
        $reads = self::spy_option_reads();
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
        AsyncDriver::register();
        $GLOBALS['supcheckout_test_action_calls'] = array();

        AsyncDriver::register_hooks();
        AsyncDriver::register_hooks();

        self::assertSame(array(
            array(AsyncDriver::HOOK, array(AsyncDriver::class, 'handle'), 10, 1),
            array(AsyncDriver::WATCHDOG_HOOK, array(AsyncDriver::class, 'watchdog'), 10, 2),
        ), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(), $reads->names, 'a pure add_action: no option read');
        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls'], 'and no option written');
    }

    public function test_the_constants_match_the_plan(): void {
        self::assertSame(
            array('supcheckout_onboarding_drive', 'supcheckout_onboarding_watchdog', 'supcheckout-onboarding', 300, 960, 60),
            array(AsyncDriver::HOOK, AsyncDriver::WATCHDOG_HOOK, AsyncDriver::GROUP, AsyncDriver::WATCHDOG_DELAY, AsyncDriver::LONG_WATCHDOG_DELAY, AsyncDriver::RUNNER_STALL_SECONDS)
        );
        self::assertSame(Quiesce::TTL + 60, AsyncDriver::LONG_WATCHDOG_DELAY);
    }

    public function test_enqueue_is_unique_in_the_onboarding_group(): void {
        $op = self::start(Operation::KIND_PREFLIGHT);
        $before = time();

        self::assertTrue(AsyncDriver::enqueue($op['id'], 30));
        self::assertTrue(AsyncDriver::enqueue($op['id']), 'an action already queued counts');

        $scheduled = self::scheduled(AsyncDriver::HOOK);
        self::assertCount(1, $scheduled);
        self::assertSame(array($op['id']), $scheduled[0]['args']);
        self::assertSame('supcheckout-onboarding', $scheduled[0]['group']);
        self::assertTrue($scheduled[0]['unique']);
        self::assertGreaterThanOrEqual($before + 30, $scheduled[0]['timestamp']);
        self::assertLessThanOrEqual(time() + 30, $scheduled[0]['timestamp']);
    }

    public function test_an_unavailable_scheduler_finishes_the_operation_scheduler_unavailable(): void {
        // Plan review amendment: never an orphaned running operation (the REST layer answers 503).
        $op = self::start(Operation::KIND_SWITCH);
        \Action_Scheduler::$initialized = false;

        self::assertFalse(AsyncDriver::enqueue($op['id']));

        $current = Operation::current();
        self::assertSame(array('failed', 'scheduler_unavailable'), array($current['outcome'], $current['error']));
        self::assertNotNull($current['hint']);
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_a_unique_slot_held_by_another_action_is_not_a_failure(): void {
        $op = self::start(Operation::KIND_PREFLIGHT);
        $GLOBALS['supcheckout_as_calls']['scheduled'][] = array('timestamp' => time(), 'hook' => AsyncDriver::HOOK, 'args' => array('stale-id'), 'group' => AsyncDriver::GROUP, 'unique' => true);

        $before = time();

        self::assertTrue(AsyncDriver::enqueue($op['id']));
        self::assertTrue(Operation::is_running(Operation::current()));

        // Fix round 1 (option b): recovery never depends on the stale action alone.
        $watchdogs = self::scheduled(AsyncDriver::WATCHDOG_HOOK);
        self::assertCount(1, $watchdogs, 'this operation\'s own watchdog');
        self::assertSame(array($op['id'], 'preflight'), $watchdogs[0]['args'], 'the upcoming phase, as drive() sets it');
        self::assertFalse($watchdogs[0]['unique']);
        self::assertGreaterThanOrEqual($before + AsyncDriver::WATCHDOG_DELAY, $watchdogs[0]['timestamp']);
        self::assertLessThanOrEqual(time() + AsyncDriver::WATCHDOG_DELAY, $watchdogs[0]['timestamp']);
        self::assertCount(1, self::scheduled(AsyncDriver::HOOK), 'still only the stale action');

        // Once the stale action is gone, that watchdog re-queues this operation.
        $GLOBALS['supcheckout_as_calls']['scheduled'] = array();
        self::assertTrue(AsyncDriver::watchdog($op['id'], 'started'));
        self::assertSame(array($op['id']), self::scheduled(AsyncDriver::HOOK)[0]['args']);
    }

    public function test_an_enqueue_that_takes_the_slot_schedules_no_extra_watchdog(): void {
        $op = self::start(Operation::KIND_PREFLIGHT);

        self::assertTrue(AsyncDriver::enqueue($op['id']));

        self::assertSame(array(), self::scheduled(AsyncDriver::WATCHDOG_HOOK), 'the tick itself replaces the watchdog');
    }

    // --- handle. ---

    public function test_a_stale_action_requeues_the_current_running_operation_without_advancing_it(): void {
        // Fix round 1 (controller ruling, option a): the stale action held the unique slot the
        // current operation's enqueue was refused for; it hands the slot on instead of dropping it.
        $op = self::start(Operation::KIND_PREFLIGHT);
        $calls = 0;

        AsyncDriver::drive('another-operation-id', function () use (&$calls) {
            ++$calls;
            throw new \LogicException('never built');
        }, $this->defer());

        self::assertSame(0, $calls, 'the current operation does not advance in this tick');
        self::assertSame(array(array($op['id'], 0)), $this->deferred, 're-queued at once (enqueue is unique)');
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled'], 'no watchdog replaced');
        self::assertSame($op, Operation::current(), 'untouched');
    }

    public function test_a_stale_action_with_no_running_operation_does_nothing(): void {
        AsyncDriver::drive('another-operation-id', function () {
            throw new \LogicException('never built');
        }, $this->defer());
        self::assertSame(array(), $this->deferred, 'no operation at all');

        $op = self::start(Operation::KIND_PREFLIGHT);
        self::assertTrue(Operation::finish($op['id'], Operation::DONE, null, null, array(), time()));

        AsyncDriver::drive('another-operation-id', function () {
            throw new \LogicException('never built');
        }, $this->defer());

        self::assertSame(array(), $this->deferred, 'a finished operation is not re-queued');
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_a_finished_operation_does_nothing(): void {
        $op = self::start(Operation::KIND_PREFLIGHT);
        self::assertTrue(Operation::finish($op['id'], Operation::DONE, null, null, array(), time()));

        AsyncDriver::drive($op['id'], function () {
            throw new \LogicException('never built');
        }, $this->defer());

        self::assertSame(array(), $this->deferred);
    }

    public function test_a_tick_is_persisted_and_its_continuation_deferred_to_shutdown(): void {
        $store = new FakeCopyStore();
        $system = new FakeBudgetedSystem();
        $op = self::start(Operation::KIND_SWITCH);
        $before = time();

        AsyncDriver::drive($op['id'], $this->fake_step($store, $system), $this->defer());

        $current = Operation::current();
        self::assertSame(array('running', 'copy'), array($current['outcome'], $current['phase']));
        self::assertSame(array(array($op['id'], 0)), $this->deferred);
        self::assertGreaterThanOrEqual($before, $current['next_at']);
        self::assertContains('copy_started', $current['steps']);
        self::assertNotNull($this->built);
        self::assertFalse($this->built->budget_spent(), 'a fresh 20 s budget');
    }

    public function test_a_terminal_tick_finishes_the_operation_and_queues_nothing(): void {
        $op = self::start(Operation::KIND_SELF_TEST);

        AsyncDriver::drive($op['id'], $this->fake_step(new FakeCopyStore(), new FakeBudgetedSystem()), $this->defer());

        $current = Operation::current();
        self::assertSame('done', $current['outcome']);
        self::assertSame('deferred', $current['report']['checks'][0]['result']);
        self::assertSame(array(), $this->deferred);
    }

    public function test_a_throwable_finishes_the_operation_failed_with_the_class_name_only(): void {
        $op = self::start(Operation::KIND_SWITCH);

        AsyncDriver::drive($op['id'], static function () {
            throw new \RuntimeException(self::SECRET_MESSAGE);
        }, $this->defer());

        $current = Operation::current();
        self::assertSame(array('failed', 'error'), array($current['outcome'], $current['error']));
        self::assertSame(array('exception' => 'RuntimeException'), $current['report']);
        self::assertSame('restart the operation; every step resumes from the saved state', $current['hint']);
        self::assertStringNotContainsString(self::SECRET_MESSAGE, (string) get_option(Operation::OPTION));
        self::assertSame(array(), $this->deferred);
    }

    public function test_a_long_phase_schedules_the_dead_mans_watchdog_at_960_seconds(): void {
        $store = new FakeCopyStore();
        $system = new FakeBudgetedSystem();
        $this->seed_verified($system);
        $op = self::start(Operation::KIND_SWITCH);
        self::update($op, array('phase' => 'cutover'));
        $before = time();

        AsyncDriver::drive($op['id'], $this->fake_step($store, $system), $this->defer());

        $watchdogs = self::scheduled(AsyncDriver::WATCHDOG_HOOK);
        self::assertCount(1, $watchdogs);
        self::assertSame(array($op['id'], 'cutover'), $watchdogs[0]['args']);
        self::assertSame(AsyncDriver::GROUP, $watchdogs[0]['group']);
        self::assertFalse($watchdogs[0]['unique']);
        self::assertGreaterThanOrEqual($before + 960, $watchdogs[0]['timestamp']);
        self::assertLessThanOrEqual(time() + 960, $watchdogs[0]['timestamp']);
        self::assertSame(array(0), $GLOBALS['supcheckout_test_time_limits'], 'the time limit is raised before the long phase');
        self::assertSame(array(AsyncDriver::WATCHDOG_HOOK, null, AsyncDriver::GROUP), $GLOBALS['supcheckout_as_calls']['unscheduled_all'][0]);
    }

    public function test_a_short_phase_after_a_long_one_replaces_the_watchdog_at_its_own_deadline(): void {
        $store = new FakeCopyStore();
        $system = new FakeBudgetedSystem();
        $this->seed_verified($system);
        $op = self::start(Operation::KIND_SWITCH);
        self::update($op, array('phase' => 'cutover'));

        AsyncDriver::drive($op['id'], $this->fake_step($store, $system), $this->defer());
        self::assertSame('self_test', Operation::current()['phase'], (string) json_encode(Operation::current()));
        self::assertSame(array('cutover' => 1), Operation::current()['attempts']);
        $GLOBALS['supcheckout_test_time_limits'] = array();
        $t = time();

        AsyncDriver::drive($op['id'], $this->fake_step($store, $system), $this->defer());

        $watchdogs = self::scheduled(AsyncDriver::WATCHDOG_HOOK);
        self::assertCount(1, $watchdogs, 'every older watchdog was unscheduled');
        self::assertSame(array($op['id'], 'self_test'), $watchdogs[0]['args']);
        self::assertGreaterThanOrEqual($t + 300, $watchdogs[0]['timestamp']);
        self::assertLessThanOrEqual(time() + 300, $watchdogs[0]['timestamp']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_time_limits'], 'a short phase keeps the time limit');
        self::assertSame('done', Operation::current()['outcome']);
    }

    public function test_a_long_attempt_is_counted_before_the_work_starts(): void {
        // A tick killed inside Cutover persists nothing afterwards: the attempt must already be stored.
        $system = new FakeBudgetedSystem();
        $this->seed_verified($system);
        $op = self::start(Operation::KIND_SWITCH);
        self::update($op, array('phase' => 'cutover'));
        $seen = null;

        AsyncDriver::drive($op['id'], static function () use (&$seen) {
            $seen = Operation::current()['attempts'];
            throw new \RuntimeException('killed');
        }, $this->defer());

        self::assertSame(array('cutover' => 1), $seen);
    }

    public function test_a_stored_long_attempt_does_not_read_as_a_stalled_runner(): void {
        // A long tick may legitimately run for minutes: while it runs, a status poll must not
        // report the runner stalled. The stored attempt moves next_at to the long watchdog.
        $system = new FakeBudgetedSystem();
        $this->seed_verified($system);
        $op = self::start(Operation::KIND_SWITCH);
        self::update($op, array('phase' => 'cutover'));
        $seen = null;
        $before = time();

        AsyncDriver::drive($op['id'], static function () use (&$seen) {
            $seen = Operation::current();
            throw new \RuntimeException('killed');
        }, $this->defer());

        self::assertGreaterThanOrEqual($before + AsyncDriver::LONG_WATCHDOG_DELAY, $seen['next_at']);
        self::assertLessThanOrEqual(time() + AsyncDriver::LONG_WATCHDOG_DELAY, $seen['next_at']);
        foreach (array(120, 600, AsyncDriver::LONG_WATCHDOG_DELAY) as $elapsed) {
            self::assertFalse(AsyncDriver::runner_health($seen, time() + $elapsed)['stalled'], $elapsed . ' seconds into the long attempt');
        }
        self::assertTrue(AsyncDriver::runner_health($seen, (int) $seen['next_at'] + AsyncDriver::RUNNER_STALL_SECONDS + 1)['stalled'], 'a killed long tick still reads stalled once its watchdog is overdue');
    }

    public function test_a_long_phase_waits_for_real_and_a_short_one_within_its_budget(): void {
        $system = new FakeBudgetedSystem();
        $this->seed_verified($system);
        $op = self::start(Operation::KIND_SWITCH);
        self::update($op, array('phase' => 'cutover'));

        AsyncDriver::drive($op['id'], $this->fake_step(new FakeCopyStore(), $system), $this->defer());
        self::assertTrue($this->built->real_waits(), 'Cutover\'s bounded drain sleeps for real under a raised time limit');

        AsyncDriver::drive($op['id'], $this->fake_step(new FakeCopyStore(), $system), $this->defer());
        self::assertFalse($this->built->real_waits());
    }

    // --- watchdog. ---

    public function test_watchdog_requeues_only_a_running_operation_without_a_pending_action(): void {
        self::assertFalse(AsyncDriver::watchdog(), 'no operation');

        $op = self::start(Operation::KIND_PREFLIGHT);
        self::assertTrue(AsyncDriver::watchdog($op['id'], 'started'));
        self::assertCount(1, self::scheduled(AsyncDriver::HOOK));
        self::assertFalse(AsyncDriver::watchdog(), 'an action is pending (or running)');
        self::assertCount(1, self::scheduled(AsyncDriver::HOOK));

        $GLOBALS['supcheckout_as_calls']['scheduled'] = array();
        self::assertFalse(AsyncDriver::watchdog('another-operation-id', 'copy'), 'another operation\'s watchdog');

        self::update($op, array('next_at' => time() + 600));
        self::assertFalse(AsyncDriver::watchdog(), 'not due yet');

        self::update($op, array('next_at' => time() - 1));
        self::assertTrue(Operation::finish($op['id'], Operation::DONE, null, null, array(), time()));
        self::assertFalse(AsyncDriver::watchdog(), 'finished');
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    // --- runner health. ---

    public function test_runner_health_reports_stalled_after_60_seconds_past_due(): void {
        // Review Focus 1.
        self::assertSame(array('stalled' => true, 'past_due_seconds' => 61), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 1000), 1061));
        self::assertSame(array('stalled' => false, 'past_due_seconds' => 60), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 1000), 1060));
    }

    public function test_runner_health_of_a_fresh_finished_or_missing_operation(): void {
        self::assertSame(array('stalled' => false, 'past_due_seconds' => 0), AsyncDriver::runner_health(null, 5000));
        self::assertSame(array('stalled' => false, 'past_due_seconds' => 0), AsyncDriver::runner_health(array('outcome' => 'done', 'next_at' => 1000), 5000));
        self::assertSame(array('stalled' => false, 'past_due_seconds' => 0), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 6000), 5000));
        self::assertSame(array('stalled' => false, 'past_due_seconds' => 10), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 0, 'started_at' => 4990), 5000), 'never ticked: due since its start');
    }

    // --- WordPress bindings. ---

    public function test_the_gateway_state_reads_enabled_from_the_settings_option(): void {
        // Carry-in 4. No WooCommerce container: nothing is registered.
        $saved = isset($GLOBALS['supcheckout_test_subscription_presentation']) ? $GLOBALS['supcheckout_test_subscription_presentation'] : null;
        $GLOBALS['supcheckout_test_subscription_presentation'] = array('wc' => null);
        try {
            $this->assert_enabled_from_settings();
        } finally {
            $GLOBALS['supcheckout_test_subscription_presentation'] = $saved;
        }
    }

    private function assert_enabled_from_settings(): void {
        self::assertSame(array('registered' => false, 'enabled' => false), AsyncDriver::gateway_state());

        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = array('enabled' => 'yes');
        self::assertTrue(AsyncDriver::gateway_state()['enabled']);
        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = array('enabled' => 'no');
        self::assertFalse(AsyncDriver::gateway_state()['enabled']);
        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = array('enabled' => true);
        self::assertFalse(AsyncDriver::gateway_state()['enabled'], 'only the string "yes" is enabled');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_gateway_state_reads_registered_from_the_live_gateway_registry(): void {
        CutoverTest::state('cut_over');
        if (!class_exists('WC_Payment_Gateway', false)) {
            eval('class WC_Payment_Gateway { public $id = ""; }');
        }
        $ours = (new \ReflectionClass(\Simplixi\SUPCheckout\Gateway\Gateway::class))->newInstanceWithoutConstructor();
        $registry = new class {
            /** @var array<string, mixed> */
            public $gateways = array();
            /** @return array<string, mixed> */
            public function payment_gateways() {
                return $this->gateways;
            }
        };
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = new class($registry) {
            /** @var object */
            private $registry;
            public function __construct(object $registry) {
                $this->registry = $registry;
            }
            /** @return object */
            public function payment_gateways() {
                return $this->registry;
            }
        };

        self::assertFalse(AsyncDriver::gateway_state()['registered']);
        $registry->gateways = array('upayments' => (object) array('id' => 'upayments'));
        self::assertFalse(AsyncDriver::gateway_state()['registered'], 'the official plugin\'s object is not ours');
        $registry->gateways = array('supcheckout' => $ours);
        self::assertTrue(AsyncDriver::gateway_state()['registered']);
    }
}
