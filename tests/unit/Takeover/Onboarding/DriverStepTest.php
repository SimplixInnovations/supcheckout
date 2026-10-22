<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Cutover;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Onboarding\Checks;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverHints;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\CutoverTest;
use Simplixi\SUPCheckout\Tests\Takeover\FakeBudgetedSystem;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCopyStore;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCrudObject;
use Simplixi\SUPCheckout\Tests\Takeover\FakeStaleClaimStore;

/**
 * 0c Task 6: one bounded driver tick per decision-table row, on the real Onboarding,
 * TakeoverState, Quiesce and engines with an in-memory store and a budgeted fake system.
 * Each tick's progress fields are applied to the operation as AsyncDriver persists them.
 */
final class DriverStepTest extends TestCase {
    private const T0 = 1800000000;
    private const OFFICIAL = 'upayments/UPayments.php';
    private const SECRET_MESSAGE = 'db password is hunter2';

    /** @var FakeCopyStore */
    private $store;
    /** @var FakeBudgetedSystem */
    private $system;
    /** @var FakeStaleClaimStore */
    private $claims;
    /** @var list<array<string, mixed>> Preflight reports, the last one repeating. */
    private $preflight = array();
    /** @var array<string, mixed> */
    private $self_test_report = array();
    /** @var bool */
    private $checks_throw = false;
    /** @var int Self-test / setup-check runs. */
    private $check_runs = 0;
    /** @var int */
    private $clock = self::T0;
    /** @var int Seconds every clock read advances. */
    private $clock_step = 0;
    /** @var array<string, mixed> */
    private $op = array();
    /** @var array<string, mixed> */
    private $last = array();

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        TakeoverState::flush_cache();
        $this->store = new FakeCopyStore();
        $this->system = new FakeBudgetedSystem();
        $this->system->now = self::T0;
        $this->claims = new FakeStaleClaimStore();
        $this->preflight = array(self::clean_preflight());
        $this->self_test_report = self::self_test(true);
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
    }

    // --- Fixtures. ---

    private function driver(): DriverStep {
        $onboarding = new Onboarding(
            function () {
                return count($this->preflight) > 1 ? array_shift($this->preflight) : $this->preflight[0];
            },
            $this->store,
            $this->system,
            $this->claims
        );
        $checks = function () {
            ++$this->check_runs;
            if ($this->checks_throw) {
                throw new \RuntimeException(self::SECRET_MESSAGE);
            }
            return $this->self_test_report;
        };
        return new DriverStep($onboarding, $this->system, $checks, $checks, function () {
            $now = $this->clock;
            $this->clock += $this->clock_step;
            return $now;
        });
    }

    /**
     * @param array<string, bool>  $consent
     * @param array<string, mixed> $fields
     */
    private function start(string $kind, array $consent = array(), array $fields = array()): void {
        $op = Operation::start($kind, $consent, 1, self::T0);
        self::assertNotNull($op);
        $this->op = array_merge($op, $fields);
    }

    /** @return list<array<string, mixed>> */
    private function ticks(int $count): array {
        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $result = $this->driver()->tick($this->op);
            $this->op = array_merge($this->op, DriverStep::progress_fields($this->op, $result));
            if ($result['next_delay'] === null) {
                $this->op['outcome'] = $result['outcome'];
            }
            $this->last = $result;
            $out[] = $result;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function tick(): array {
        return $this->ticks(1)[0];
    }

    /** @return array<string, mixed> */
    private static function clean_preflight(): array {
        return array('blocking' => array(), 'warnings' => array(), 'facts' => array());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function blocking(string $code, array $data = array()): array {
        return array('blocking' => array(array('severity' => 'blocking', 'code' => $code, 'message' => $code . ' message', 'data' => $data)), 'warnings' => array(), 'facts' => array());
    }

    /** @return array<string, mixed> */
    private static function self_test(bool $ok, bool $cooldown = false): array {
        return Checks::report(array(
            Checks::check('takeover_state', Checks::PASS, null, 'switched'),
            Checks::check('callback_reachable', Checks::PASS, null, 'ok'),
            Checks::check('cache_exclusion', Checks::PASS, null, 'ok'),
            $cooldown ? Checks::check('credential', Checks::WARN, 'cooldown', 'later', array('reason' => 'cooldown')) : Checks::check('credential', Checks::PASS, null, 'ok'),
            Checks::check('gateway_registered', Checks::PASS, null, 'ok'),
            Checks::check('gateway_enabled', $ok ? Checks::PASS : Checks::FAIL, $ok ? null : 'gateway_disabled', 'enabled'),
            Checks::check('currency_supported', Checks::PASS, null, 'ok'),
            Checks::check('multilingual', Checks::SKIPPED, null, 'none'),
            Checks::check('event_journal', Checks::DEFERRED, 'event_journal_not_built', 'later'),
        ), self::T0);
    }

    /** A verified store as the copy runner leaves it. */
    private function seed_verified(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = hash('sha256', 'secret');
        CutoverTest::state('verified');
    }

    /** Cut over through the real Cutover. */
    private function seed_cut_over(): void {
        $this->seed_verified();
        $this->system->official = null;
        $result = (new Cutover($this->store, $this->system))->run(false);
        self::assertTrue($result['ok'], (string) json_encode($result));
        $this->system->log = array();
    }

    /**
     * Make TakeoverState compare-and-swaps lose: before an UPDATE of the state option,
     * another process rewrites the record (same status, another field).
     *
     * @param int $times Lost swaps; PHP_INT_MAX for every one.
     */
    private static function lose_state_swaps(int $times): void {
        $left = $times;
        $GLOBALS['wpdb']->before_query = static function (array $statement) use (&$left) {
            if ($left <= 0 || strpos($statement['query'], 'UPDATE ') !== 0 || $statement['args'][1] !== TakeoverState::OPTION) {
                return;
            }
            --$left;
            $state = json_decode((string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], true);
            $state['noise'] = isset($state['noise']) ? $state['noise'] + 1 : 1;
            $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode($state);
        };
    }

    private static function takeover_status(): string {
        TakeoverState::refresh();
        return TakeoverState::get()['status'];
    }

    // --- switch / preflight. ---

    public function test_switch_preflight_blocking_finishes_blocked_with_the_report(): void {
        $this->preflight = array(self::blocking('callback_unreachable'));
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true));

        $result = $this->tick();

        self::assertSame(array('blocked', 'preflight_blocking', null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertSame('callback_unreachable', $result['report']['blocking'][0]['code']);
        self::assertSame(TakeoverState::IDLE, self::takeover_status(), 'nothing changed');
    }

    public function test_a5_04_a_foreign_settings_target_blocks_the_switch_with_the_settings_hint(): void {
        $this->preflight = array(self::blocking('settings_target_foreign', array('targets' => array(Keys::SETTINGS_OPTION), 'journal_unreadable' => false)));
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true));
        $this->system->official = self::OFFICIAL;

        $result = $this->tick();

        self::assertSame(array('blocked', 'preflight_blocking', DriverHints::SETTINGS_KEPT), array($result['outcome'], $result['error'], $result['hint']));
        self::assertSame(array(), $this->system->deactivated, 'blocked before the copy and before the official plugin is touched');
        self::assertSame(TakeoverState::IDLE, self::takeover_status(), 'nothing changed');
    }

    public function test_switch_retries_a_credential_cooldown_three_times_then_blocks(): void {
        // Review Focus 2.
        $this->preflight = array(self::blocking('credential_unverified', array('reason' => 'cooldown')));
        $this->start(Operation::KIND_SWITCH);

        self::assertSame(array(65, 65, 65, null), array_column($this->ticks(4), 'next_delay'));
        self::assertSame('blocked', $this->last['outcome']);
        self::assertSame('preflight_blocking', $this->last['error']);
        self::assertSame(3, $this->last['cooldown_retries']);
    }

    public function test_a_cooldown_beside_another_blocking_finding_blocks_at_once(): void {
        $report = self::blocking('credential_unverified', array('reason' => 'cooldown'));
        $report['blocking'][] = array('severity' => 'blocking', 'code' => 'callback_unreachable', 'message' => 'x', 'data' => array());
        $this->preflight = array($report);
        $this->start(Operation::KIND_SWITCH);

        self::assertSame(array('blocked', null), array($this->tick()['outcome'], $this->last['next_delay']));
    }

    public function test_a_cooldown_that_clears_continues_into_the_copy(): void {
        $this->preflight = array(self::blocking('credential_unverified', array('reason' => 'cooldown')), self::clean_preflight());
        $this->start(Operation::KIND_SWITCH);

        self::assertSame(array(65, 0), array_column($this->ticks(2), 'next_delay'));
        self::assertSame('copy', $this->last['phase']);
        self::assertSame(0, $this->last['cooldown_retries'], 'reset once the preflight passed');
        self::assertSame(TakeoverState::COPYING, self::takeover_status());
    }

    public function test_network_active_official_blocks_the_switch(): void {
        $this->preflight = array(self::blocking('official_network_active'));
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true));

        $result = $this->tick();

        self::assertSame(array('blocked', 'preflight_blocking'), array($result['outcome'], $result['error']));
        self::assertSame('official_network_active', $result['report']['blocking'][0]['code']);
        self::assertSame(array(), $this->system->deactivated, 'nothing deactivated');
    }

    public function test_a_refused_plan_fails_with_its_reason(): void {
        // A state run cannot start from (a finalized store goes to the self-test instead).
        CutoverTest::state('rolled_back');
        $this->start(Operation::KIND_SWITCH);

        self::assertSame(array('failed', 'state'), array($this->tick()['outcome'], $this->last['error']));
    }

    public function test_a_cut_over_store_with_the_official_plugin_active_again_and_no_consent_is_blocked(): void {
        CutoverTest::state('cut_over');
        $this->start(Operation::KIND_SWITCH);

        self::assertSame(array('blocked', 'official_still_active'), array($this->tick()['outcome'], $this->last['error']));
    }

    // --- switch / copy. ---

    public function test_switch_copies_within_the_tick_budget_then_continues_immediately(): void {
        $this->start(Operation::KIND_SWITCH);
        $this->tick();
        self::assertSame('copy', $this->last['phase']);

        $this->clock_step = 25;
        $result = $this->tick();

        self::assertSame(array('running', 'copy', 0), array($result['outcome'], $result['phase'], $result['next_delay']));
        self::assertSame(TakeoverState::COPYING, self::takeover_status(), 'one copy step, then the budget was spent');
    }

    public function test_a5_02_the_time_boxed_copy_loop_flushes_the_runtime_cache_after_every_copy_step(): void {
        for ($id = 1; $id <= 120; $id++) {
            $GLOBALS['supcheckout_test_status_orders'][$id] = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $this->start(Operation::KIND_SWITCH);
        $this->tick();
        self::assertSame('copy', $this->last['phase']);
        $before = $this->store->runtime_flushes;

        // The clock never advances: the whole copy runs inside this one tick.
        $result = $this->tick();

        self::assertSame(TakeoverState::VERIFIED, self::takeover_status(), (string) json_encode($result));
        // Each copy step is one bounded batch: three order batches (50, 50, 20), then one
        // step each for the users, the products, the table, its verification and the finish.
        self::assertSame(3 + 5, $this->store->runtime_flushes - $before, 'one runtime-cache flush after every copy step of the tick');
        self::assertSame(array('pay-120'), $GLOBALS['supcheckout_test_status_orders'][120]->persisted_values('_supcheckout_payment_id'), 'every order is still copied');
    }

    public function test_a_copy_that_keeps_stopping_retries_every_60_seconds_17_times_then_fails_busy(): void {
        // Fix round 1: a failing progress save while still copying must never re-tick at once forever.
        CutoverTest::state('copying');
        $GLOBALS['wpdb']->before_query = static function (array $statement) {
            if (strpos($statement['query'], 'UPDATE ') === 0 && $statement['args'][1] === TakeoverState::OPTION) {
                // Someone else rewrites the record (same status) first: every progress save loses its CAS.
                $state = json_decode((string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], true);
                $state['noise'] = isset($state['noise']) ? $state['noise'] + 1 : 1;
                $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode($state);
            }
        };
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy'));

        $ticks = $this->ticks(18);

        self::assertSame(array_fill(0, 17, 60), array_column(array_slice($ticks, 0, 17), 'next_delay'), (string) json_encode($ticks[0]));
        self::assertSame(array('failed', 'busy', null), array($this->last['outcome'], $this->last['error'], $this->last['next_delay']));
        self::assertSame(TakeoverState::COPYING, self::takeover_status(), 'resumable');
    }

    /**
     * A5-05: an order whose lock record is unparseable (truncated, or edited by an options
     * cleaner) is held forever (OrderLock fails closed). The copy used to retry it every 60
     * seconds with no bound; it now counts as a busy retry and ends `order_locked`, naming
     * the order and how to clear its lock.
     */
    public function test_a5_05_a_permanently_locked_order_retries_17_times_then_fails_order_locked(): void {
        $GLOBALS['supcheckout_test_status_orders'][1] = new FakeCrudObject(1, 'upayments', array('UPayments_PaymentID' => array('pay-1')));
        $GLOBALS['supcheckout_test_options']['simplixpay_upay_order_lock_v1_1'] = 'corrupt';
        CutoverTest::state('copying');
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy'));

        $ticks = $this->ticks(18);

        self::assertSame(array_fill(0, 17, 60), array_column(array_slice($ticks, 0, 17), 'next_delay'), (string) json_encode($ticks[0]));
        self::assertSame(1, $ticks[0]['report']['locked_order'], 'the copy progress names the locked order');
        self::assertSame(array('failed', 'order_locked', null), array($this->last['outcome'], $this->last['error'], $this->last['next_delay']));
        self::assertSame(DriverHints::order_locked(1), $this->last['hint']);
        self::assertStringContainsString('order_lock_v1_1', $this->last['hint']);
        self::assertSame(1, $this->last['report']['locked_order']);
        self::assertSame(TakeoverState::COPYING, self::takeover_status(), 'resumable: nothing was skipped');
        self::assertSame('corrupt', $GLOBALS['supcheckout_test_options']['simplixpay_upay_order_lock_v1_1'], 'a payment lock is never broken by the takeover');
    }

    public function test_a5_05_copy_progress_resets_the_busy_retry_count(): void {
        for ($id = 1; $id <= 3; $id++) {
            $GLOBALS['supcheckout_test_status_orders'][$id] = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $GLOBALS['supcheckout_test_options']['simplixpay_upay_order_lock_v1_3'] = 'corrupt';
        CutoverTest::state('copying');
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy', 'busy_retries' => 16));

        $result = $this->tick();

        self::assertSame(array('running', 60, 1), array($result['outcome'], $result['next_delay'], $result['busy_retries']), 'orders 1 and 2 were copied: only the lock on order 3 counts');
        self::assertSame(3, $result['report']['locked_order']);
    }

    public function test_a_finished_copy_moves_to_retire_and_the_official_plugin_goes_first(): void {
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true));

        $this->ticks(2);

        self::assertSame(array('retire', 0), array($this->last['phase'], $this->last['next_delay']), (string) json_encode($this->last));
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status());
        self::assertContains('copy_verified', $this->op['steps']);
    }

    public function test_a_copy_mismatch_fails_verify_mismatch_with_the_mismatches(): void {
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy'));
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array(), 'mismatches' => array('orders' => array('count' => 2, 'keys' => array('UPayments_PaymentID')))));
        TakeoverState::flush_cache();

        $result = $this->tick();

        self::assertSame(array('failed', 'verify_mismatch'), array($result['outcome'], $result['error']));
        self::assertSame(2, $result['report']['mismatches']['orders']['count']);
    }

    public function test_a_state_moved_by_the_cli_is_followed(): void {
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy'));
        CutoverTest::state('cut_over');

        $result = $this->tick();

        self::assertSame(array('running', 'self_test', 0), array($result['outcome'], $result['phase'], $result['next_delay']));
    }

    public function test_a_state_the_switch_cannot_continue_from_fails_state_changed(): void {
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'retire'));
        CutoverTest::state('rolled_back');

        self::assertSame(array('failed', 'state_changed'), array($this->tick()['outcome'], $this->last['error']));
    }

    public function test_an_unreadable_takeover_state_fails_state_changed(): void {
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'copy'));
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = '{not json';
        TakeoverState::flush_cache();

        self::assertSame(array('failed', 'state_changed'), array($this->tick()['outcome'], $this->last['error']));
    }

    // --- switch / retire. ---

    public function test_switch_deactivates_the_official_plugin_then_yields_before_cutover(): void {
        $this->seed_verified();
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true), array('phase' => 'retire'));

        $result = $this->tick();

        self::assertSame(array(self::OFFICIAL), $this->system->deactivated);
        self::assertSame(array('running', 'cutover', 0), array($result['outcome'], $result['phase'], $result['next_delay']));
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status(), 'Cutover did not run in this tick');
        self::assertNotContains('quiesced', $this->system->log);
        self::assertSame(array(), $result['attempts'], 'no cutover attempt was used');
    }

    public function test_a5_07_an_unrecorded_deactivation_is_a_step_of_the_switch(): void {
        $this->seed_verified();
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true), array('phase' => 'retire'));
        self::lose_state_swaps(PHP_INT_MAX);

        $result = $this->tick();

        self::assertSame(array('running', 'cutover', 0), array($result['outcome'], $result['phase'], $result['next_delay']));
        self::assertSame(array('official_deactivated', 'official_deactivation_unrecorded'), $result['steps']);
    }

    public function test_switch_without_consent_is_blocked_official_active_and_stays_verified(): void {
        $this->seed_verified();
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'retire'));

        $result = $this->tick();

        self::assertSame(array('blocked', 'official_active', null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status());
        self::assertSame(array(), $this->system->deactivated);
    }

    public function test_a_failed_deactivation_fails_deactivation_failed(): void {
        $this->seed_verified();
        $this->system->deactivate_fails = true;
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true), array('phase' => 'retire'));

        self::assertSame(array('failed', 'deactivation_failed'), array($this->tick()['outcome'], $this->last['error']));
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status());
    }

    public function test_an_already_inactive_official_plugin_continues_to_cutover_in_the_same_tick(): void {
        $this->seed_verified();
        $this->system->official = null;
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'retire'));

        $result = $this->tick();

        self::assertSame(array('running', 'self_test', 0), array($result['outcome'], $result['phase'], $result['next_delay']), (string) json_encode($result));
        self::assertSame(TakeoverState::CUT_OVER, self::takeover_status());
        self::assertSame(array('cutover' => 1), $result['attempts']);
        self::assertTrue($result['long']);
    }

    // --- switch / cutover. ---

    public function test_a_lost_lease_retries_every_60_seconds_17_times_then_fails_busy(): void {
        // Review Focus 3: a killed holder's lease stays until Quiesce::TTL.
        $this->seed_verified();
        $this->system->official = null;
        self::assertNotNull(Quiesce::acquire(self::T0));
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'cutover'));

        $ticks = $this->ticks(18);

        self::assertSame(array_fill(0, 17, 60), array_column(array_slice($ticks, 0, 17), 'next_delay'));
        self::assertSame(array('failed', 'busy', null), array($this->last['outcome'], $this->last['error'], $this->last['next_delay']));
        self::assertSame(array('cutover' => 0), $this->last['attempts'], 'a busy lease never uses up a cutover attempt');
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status());
    }

    public function test_a_cutover_reentered_after_a_killed_tick_fails_driver_exhausted_after_3(): void {
        $this->seed_verified();
        $this->system->official = null;
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true), array('phase' => 'cutover', 'attempts' => array('cutover' => 3)));

        $result = $this->tick();

        self::assertSame(array('failed', 'driver_exhausted', null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertStringContainsString('wp supcheckout takeover run', (string) $result['hint']);
        self::assertSame(TakeoverState::VERIFIED, self::takeover_status());
        self::assertNotContains('quiesced', $this->system->log, 'Cutover was not entered a fourth time');
    }

    public function test_stale_claims_fail_with_the_release_hint(): void {
        $this->seed_verified();
        $this->system->official = null;
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('1', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'claimed', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', self::T0 - 3600)));
        $this->store->columns[] = 'updated_gmt';
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'cutover'));

        $result = $this->tick();

        self::assertSame(array('failed', 'stale_claims'), array($result['outcome'], $result['error']), (string) json_encode($result));
        self::assertStringContainsString('stale-claims/release', (string) $result['hint']);
    }

    public function test_a_cutover_refused_for_an_active_official_plugin_goes_back_to_retire(): void {
        $this->seed_verified();
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true), array('phase' => 'cutover'));

        $result = $this->tick();

        self::assertSame(array('running', 'retire', 0), array($result['outcome'], $result['phase'], $result['next_delay']));
    }

    // --- switch / self_test. ---

    public function test_a_cut_over_store_runs_the_self_test_and_finishes_done(): void {
        CutoverTest::state('cut_over');
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'cutover'));

        $first = $this->tick();
        self::assertSame(array('self_test', 0), array($first['phase'], $first['next_delay']));

        $result = $this->tick();

        self::assertSame(array('done', null, null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertSame('deferred', $result['report']['self_test']['checks'][8]['result']);
        self::assertSame(array(), $result['report']['warnings']);
    }

    public function test_a_switch_still_in_preflight_when_the_cli_cut_over_runs_and_stores_the_self_test(): void {
        // PR #194 review: a concurrent `wp supcheckout takeover run` completed the cutover
        // while the REST switch was still in its preflight phase.
        $this->seed_cut_over();
        $this->start(Operation::KIND_SWITCH);

        $first = $this->tick();

        self::assertSame(array('running', 'self_test', 0), array($first['outcome'], $first['phase'], $first['next_delay']), 'never finished without the self-test');
        self::assertSame(0, $this->check_runs);

        $result = $this->tick();

        self::assertSame(array('done', null), array($result['outcome'], $result['error']));
        self::assertSame(1, $this->check_runs);
        self::assertSame($result['report']['self_test'], get_option(DriverStep::last_report_option(Operation::KIND_SELF_TEST), false), 'the post-switch self-test is stored');
        self::assertContains('self_test_ran', $this->op['steps']);
    }

    public function test_a_switch_in_preflight_retries_the_deactivation_tail_then_runs_the_self_test(): void {
        $this->seed_cut_over();
        $this->system->official = self::OFFICIAL;
        $this->start(Operation::KIND_SWITCH, array('deactivate_official' => true));

        $first = $this->tick();

        self::assertSame(array('running', 'self_test'), array($first['outcome'], $first['phase']), (string) json_encode($first));
        self::assertNull($this->system->official, 'the tail deactivated the official plugin');

        $result = $this->tick();

        self::assertSame('done', $result['outcome']);
        self::assertIsArray(get_option(DriverStep::last_report_option(Operation::KIND_SELF_TEST), false));
    }

    public function test_a_switch_on_a_finalized_store_goes_to_the_self_test_from_any_phase(): void {
        foreach (array('preflight', 'copy', 'retire', 'cutover') as $phase) {
            \supcheckout_test_reset_wp_options();
            CutoverTest::state('finalized');
            $this->start(Operation::KIND_SWITCH, array(), array('phase' => $phase));

            $first = $this->tick();

            self::assertSame(array('running', 'self_test'), array($first['outcome'], $first['phase']), $phase);
            self::assertSame('self_test', DriverStep::upcoming(array('phase' => $phase) + $this->op)['phase'], $phase);
            self::assertSame('done', $this->tick()['outcome'], $phase);
        }
    }

    public function test_the_switch_self_test_is_stored_as_the_last_self_test_report(): void {
        // The post-switch self-test is the merchant's first self-test: GET /onboarding/self-test
        // and status.last.self_test_at read it, as after a self_test operation.
        CutoverTest::state('cut_over');
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'self_test'));

        $result = $this->tick();

        self::assertSame('done', $result['outcome']);
        $stored = get_option(DriverStep::last_report_option(Operation::KIND_SELF_TEST), false);
        self::assertIsArray($stored, 'the switch stored its self-test report');
        self::assertSame($result['report']['self_test'], $stored);
        self::assertIsInt($stored['ran_at']);
    }

    public function test_a_throwing_switch_self_test_is_stored_with_its_ran_at(): void {
        CutoverTest::state('cut_over');
        $this->checks_throw = true;
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'self_test'));

        $this->tick();

        $stored = get_option(DriverStep::last_report_option(Operation::KIND_SELF_TEST), false);
        self::assertIsArray($stored);
        self::assertSame(array('ok' => false, 'ran_at' => self::T0, 'error' => 'self_test_error'), array_intersect_key($stored, array_flip(array('ok', 'ran_at', 'error'))));
        self::assertStringNotContainsString(self::SECRET_MESSAGE, (string) json_encode($stored));
    }

    public function test_a_failing_self_test_is_a_warning_not_a_failure(): void {
        CutoverTest::state('cut_over');
        $this->self_test_report = self::self_test(false);
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'self_test'));

        $result = $this->tick();

        self::assertSame('done', $result['outcome']);
        self::assertSame(array('self_test_failed'), $result['report']['warnings']);
    }

    public function test_a_self_test_cooldown_retries_once_then_is_stored_as_it_is(): void {
        CutoverTest::state('cut_over');
        $this->self_test_report = self::self_test(true, true);
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'self_test'));

        self::assertSame(array(65, null), array_column($this->ticks(2), 'next_delay'));
        self::assertSame('done', $this->last['outcome']);
        self::assertSame('cooldown', $this->last['report']['self_test']['checks'][3]['code']);
        self::assertSame(array(), $this->last['report']['warnings'], 'a cooldown warning is not a failure');
    }

    public function test_a_throwing_self_test_after_the_switch_is_a_warning_without_its_message(): void {
        // Carry-in 3: the store is switched; the exception message is never recorded.
        CutoverTest::state('cut_over');
        $this->checks_throw = true;
        $this->start(Operation::KIND_SWITCH, array(), array('phase' => 'self_test'));

        $result = $this->tick();

        self::assertSame('done', $result['outcome']);
        self::assertSame(array('self_test_failed'), $result['report']['warnings']);
        self::assertSame(array('ok' => false, 'error' => 'self_test_error', 'exception' => 'RuntimeException'), array_intersect_key($result['report']['self_test'], array_flip(array('ok', 'error', 'exception'))));
        self::assertStringNotContainsString(self::SECRET_MESSAGE, (string) json_encode($result));
    }

    // --- rollback / abort. ---

    public function test_rollback_yields_until_the_settle_deadline_then_completes(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $this->start(Operation::KIND_ROLLBACK);

        $guard = $this->tick();
        self::assertSame(array('rewind', 0), array($guard['phase'], $guard['next_delay']));

        $yield = $this->tick();
        self::assertSame(120, $yield['next_delay'], (string) json_encode($yield));
        self::assertSame(array('running', 'rewind'), array($yield['outcome'], $yield['phase']));
        self::assertTrue($yield['long'], 'the pre-transition part ran in this tick');
        self::assertSame(TakeoverState::ROLLED_BACK, self::takeover_status());

        $this->system->now = 5120;
        $done = $this->tick();

        self::assertSame(array('done', null, null), array($done['outcome'], $done['error'], $done['next_delay']), (string) json_encode($done));
        self::assertFalse($done['long'], 'a rolled-back store resumes in a short tick');
        self::assertSame(TakeoverState::PREFLIGHT_OK, self::takeover_status());
    }

    public function test_steps_replayed_after_a_settle_yield_are_not_new_progress(): void {
        // Carry-in 2.
        $this->system->now = 5000;
        $this->seed_cut_over();
        $this->start(Operation::KIND_ROLLBACK);
        $this->ticks(2);
        $steps = $this->op['steps'];
        self::assertContains('copies_removed', $steps);
        self::assertNotContains('yielded', $steps, 'a yield is not progress');
        $this->op['busy_retries'] = 4;

        $this->system->now = 5060;
        $early = $this->tick();

        self::assertSame(60, $early['next_delay']);
        self::assertContains('copies_removed', $early['steps'], 'the engine replayed it');
        self::assertSame($steps, $this->op['steps'], 'no step is counted twice');
        self::assertSame(4, $early['busy_retries'], 'a replay is not new progress');

        $this->system->now = 5120;
        $done = $this->tick();
        self::assertSame('done', $done['outcome']);
        self::assertSame(0, $done['busy_retries'], 'new steps are progress');
        self::assertSame(1, count(array_keys($this->op['steps'], 'copies_removed', true)));
    }

    public function test_rollback_conflicts_finish_done_with_the_conflicts_warning(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'Edited');
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'no', 'api_key' => 'test-api-key-123');
        $this->start(Operation::KIND_ROLLBACK);
        $this->ticks(2);

        $this->system->now = 5120;
        $result = $this->tick();

        self::assertSame(array('done', null), array($result['outcome'], $result['next_delay']), (string) json_encode($result));
        self::assertSame(array('conflicts'), $result['report']['warnings']);
        self::assertSame('settings_diverged', $result['report']['conflicts'][0]['target']);
        self::assertSame(DriverHints::SETTINGS_KEPT, $result['hint'], 'A5-04: how to clear the kept settings before the next switch');
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode($result));
    }

    public function test_rollback_with_the_official_plugin_active_deactivates_it_in_its_own_tick(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $this->system->official = self::OFFICIAL;
        $this->start(Operation::KIND_ROLLBACK, array('deactivate_official' => true));

        $result = $this->tick();

        self::assertSame(array(self::OFFICIAL), $this->system->deactivated);
        self::assertSame(array('rewind', 0), array($result['phase'], $result['next_delay']));
        self::assertSame(TakeoverState::CUT_OVER, self::takeover_status(), 'the rollback runs in the next (fresh) process');
    }

    public function test_rollback_without_consent_and_the_official_plugin_active_is_blocked(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $this->system->official = self::OFFICIAL;
        $this->start(Operation::KIND_ROLLBACK);

        self::assertSame(array('blocked', 'official_active'), array($this->tick()['outcome'], $this->last['error']));
    }

    public function test_a_rollback_of_a_store_that_is_not_cut_over_fails_with_the_state_reason(): void {
        $this->start(Operation::KIND_ROLLBACK);

        self::assertSame(array('failed', 'state'), array($this->tick()['outcome'], $this->last['error']));
    }

    public function test_an_abort_of_a_verified_store_completes(): void {
        $this->system->now = 5000;
        $this->seed_verified();
        $this->start(Operation::KIND_ABORT);

        $ticks = $this->ticks(2);
        self::assertSame(array('rewind', 'rewind'), array_column($ticks, 'phase'));
        self::assertSame(120, $this->last['next_delay'], (string) json_encode($this->last));

        $this->system->now = 5120;
        self::assertSame('done', $this->tick()['outcome'], (string) json_encode($this->last));
        self::assertSame(TakeoverState::PREFLIGHT_OK, self::takeover_status());
    }

    // --- finalize. ---

    public function test_finalize_too_early_without_force_fails_state_without_a_lease(): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => time() - 86400, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
        $this->system->official = null;
        $this->start(Operation::KIND_FINALIZE);

        $result = $this->tick();

        self::assertSame(array('failed', 'too_early', null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertFalse(get_option(Quiesce::OPTION, false), 'Quiesce never acquired');
        self::assertSame(array(), $this->system->log);
    }

    public function test_finalize_yields_at_the_settle_barrier_then_finishes_done(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $state = json_decode((string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], true);
        $state['cut_over_at'] = time() - 31 * 86400;
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode($state);
        TakeoverState::flush_cache();
        $this->start(Operation::KIND_FINALIZE);

        $first = $this->tick();
        self::assertSame(array('finalize', 120, true), array($first['phase'], $first['next_delay'], $first['long']), (string) json_encode($first));
        self::assertSame(TakeoverState::FINALIZED, self::takeover_status());

        $this->system->now = 5120;
        $done = $this->tick();
        self::assertSame(array('done', false), array($done['outcome'], $done['long']), (string) json_encode($done));
    }

    public function test_finalize_dry_run_is_done_with_the_plan(): void {
        $this->system->now = 5000;
        $this->seed_cut_over();
        $this->start(Operation::KIND_FINALIZE_DRY_RUN);

        $result = $this->tick();

        self::assertSame(array('done', true), array($result['outcome'], $result['long']), (string) json_encode($result));
        self::assertArrayHasKey('plan', $result['report']);
        self::assertSame(array('plan' => 1), $result['attempts']);
        self::assertSame(TakeoverState::CUT_OVER, self::takeover_status(), 'read-only');
    }

    // --- preflight / self_test / setup_checks. ---

    public function test_a_preflight_operation_is_done_and_stores_its_bounded_report_non_autoloaded(): void {
        $this->preflight = array(self::blocking('callback_unreachable'));
        $this->start(Operation::KIND_PREFLIGHT);

        $result = $this->tick();

        self::assertSame(array('done', null), array($result['outcome'], $result['next_delay']));
        self::assertSame('callback_unreachable', $result['report']['blocking'][0]['code']);
        $stored = get_option('supcheckout_onboarding_last_preflight_v1');
        self::assertSame('callback_unreachable', $stored['blocking'][0]['code']);
        $writes = array_values(array_filter($GLOBALS['supcheckout_test_option_calls'], static function ($call) {
            return $call[1] === 'supcheckout_onboarding_last_preflight_v1';
        }));
        self::assertSame(false, $writes[0][3], 'not autoloaded');
    }

    public function test_a_stored_preflight_report_carries_when_it_ran(): void {
        // 0c Task 7: GET /onboarding/status reports last.preflight_at from it, like the
        // self-test and setup-check reports' own ran_at.
        $this->start(Operation::KIND_PREFLIGHT);

        $result = $this->tick();

        self::assertSame(self::T0, get_option('supcheckout_onboarding_last_preflight_v1')['ran_at']);
        self::assertSame(self::T0, $result['report']['ran_at']);
        self::assertSame(array(), $result['report']['blocking']);
    }

    public function test_a_preflight_operation_retries_a_cooldown_up_to_three_times(): void {
        $this->preflight = array(self::blocking('credential_unverified', array('reason' => 'cooldown')));
        $this->start(Operation::KIND_PREFLIGHT);

        self::assertSame(array(65, 65, 65, null), array_column($this->ticks(4), 'next_delay'));
        self::assertSame('done', $this->last['outcome']);
    }

    public function test_setup_checks_are_done_and_stored(): void {
        $this->start(Operation::KIND_SETUP_CHECKS);

        $result = $this->tick();

        self::assertSame('done', $result['outcome']);
        self::assertSame(1, $this->check_runs);
        self::assertSame($result['report']['checks'], get_option('supcheckout_onboarding_last_setup_checks_v1')['checks']);
    }

    public function test_a_throwing_self_test_operation_fails_generically_without_its_message(): void {
        // Carry-in 3.
        $this->checks_throw = true;
        $this->start(Operation::KIND_SELF_TEST);

        $result = $this->tick();

        self::assertSame(array('failed', 'error', null), array($result['outcome'], $result['error'], $result['next_delay']));
        self::assertSame(array('exception' => 'RuntimeException'), $result['report']);
        self::assertStringContainsString('restart the operation', (string) $result['hint']);
        self::assertStringNotContainsString(self::SECRET_MESSAGE, (string) json_encode($result));
        self::assertFalse(get_option('supcheckout_onboarding_last_self_test_v1', false), 'nothing stored');
    }

    public function test_a_throwing_preflight_in_a_switch_fails_generically(): void {
        $this->start(Operation::KIND_SWITCH);
        $onboarding = new Onboarding(static function () {
            throw new \RuntimeException(self::SECRET_MESSAGE);
        }, $this->store, $this->system, $this->claims);
        $driver = new DriverStep($onboarding, $this->system, 'time', 'time', 'time');

        $result = $driver->tick($this->op);

        self::assertSame(array('failed', 'error'), array($result['outcome'], $result['error']));
        self::assertStringNotContainsString(self::SECRET_MESSAGE, (string) json_encode($result));
    }

    // --- upcoming. ---

    public function test_upcoming_names_the_phase_and_whether_it_is_long(): void {
        $this->seed_verified();
        $this->start(Operation::KIND_SWITCH);
        self::assertSame(array('phase' => 'preflight', 'long' => false, 'attempt' => null), DriverStep::upcoming($this->op));
        self::assertSame(array('phase' => 'retire', 'long' => true, 'attempt' => 'cutover'), DriverStep::upcoming(array('phase' => 'retire') + $this->op));
        self::assertSame(array('phase' => 'cutover', 'long' => true, 'attempt' => 'cutover'), DriverStep::upcoming(array('phase' => 'cutover') + $this->op));

        CutoverTest::state('cut_over');
        self::assertSame(array('phase' => 'self_test', 'long' => false, 'attempt' => null), DriverStep::upcoming(array('phase' => 'cutover') + $this->op), 'a cut-over store goes to the self-test');
        $rollback = array('kind' => Operation::KIND_ROLLBACK, 'phase' => 'rewind') + $this->op;
        self::assertSame(array('phase' => 'rewind', 'long' => true, 'attempt' => 'rewind'), DriverStep::upcoming($rollback));
        self::assertSame(array('phase' => 'finalize', 'long' => true, 'attempt' => 'finalize'), DriverStep::upcoming(array('kind' => Operation::KIND_FINALIZE, 'phase' => 'started') + $this->op));

        CutoverTest::state('rolled_back');
        self::assertSame(array('phase' => 'rewind', 'long' => false, 'attempt' => null), DriverStep::upcoming($rollback), 'after the transition the removal yields');
        self::assertSame(array('phase' => 'plan', 'long' => true, 'attempt' => 'plan'), DriverStep::upcoming(array('kind' => Operation::KIND_FINALIZE_DRY_RUN, 'phase' => 'started') + $this->op));
        self::assertSame(array('phase' => 'setup_checks', 'long' => false, 'attempt' => null), DriverStep::upcoming(array('kind' => Operation::KIND_SETUP_CHECKS, 'phase' => 'started') + $this->op));
    }

    public function test_progress_fields_merge_steps_once_and_drop_transient_ones(): void {
        $op = array('steps' => array('a', 'b'));
        $result = array('phase' => 'p', 'steps' => array('b', 'yielded', 'settling', 'c', 'c'), 'attempts' => array('x' => 1), 'busy_retries' => 2, 'cooldown_retries' => 1, 'error' => null, 'hint' => null, 'report' => array('k' => 'v'));

        self::assertSame(array(
            'phase' => 'p',
            'steps' => array('a', 'b', 'c'),
            'attempts' => array('x' => 1),
            'busy_retries' => 2,
            'cooldown_retries' => 1,
            'error' => null,
            'hint' => null,
            'report' => array('k' => 'v'),
        ), DriverStep::progress_fields($op, $result));
    }

    public function test_the_constants_match_the_plan(): void {
        self::assertSame(array(20, 3, 60, 17, 65, 3), array(DriverStep::TICK_BUDGET, DriverStep::MAX_LONG_ATTEMPTS, DriverStep::BUSY_RETRY_DELAY, DriverStep::MAX_BUSY_RETRIES, DriverStep::COOLDOWN_RETRY_DELAY, DriverStep::MAX_COOLDOWN_RETRIES));
        self::assertSame(\Simplixi\SUPCheckout\Takeover\CopyRunner::ACTION_TIME_BUDGET, DriverStep::TICK_BUDGET);
    }
}
