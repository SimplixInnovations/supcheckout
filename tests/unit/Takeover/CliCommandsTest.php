<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 9: `wp supcheckout takeover` orchestration and exit codes, on the real
 * TakeoverState, CopyRunner, Cutover, Journal and Quiesce with an in-memory CopyStore,
 * CutoverSystem and stale-claim table, and a faked Preflight report.
 */
final class CliCommandsTest extends TestCase {
    /** @var FakeCopyStore */
    private $store;
    /** @var FakeCutoverSystem */
    private $system;
    /** @var FakeStaleClaimStore */
    private $claims;
    /** @var array{blocking:list<array<string,mixed>>, warnings:list<array<string,mixed>>, facts:list<array<string,mixed>>} */
    private $report;
    /** @var int */
    private $preflights = 0;
    /** @var list<string> */
    private $out = array();
    /** @var list<string> */
    private $questions = array();
    /** @var bool */
    private $answer = true;
    /** @var list<string> Cutover steps reached. */
    private $steps = array();
    /** @var callable|null Copy step override (function (): string, a CopyRunner status). */
    private $copy_step = null;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        TakeoverState::flush_cache();
        $this->store = new FakeCopyStore();
        $this->system = new FakeCutoverSystem();
        $this->claims = new FakeStaleClaimStore();
        $this->report = array('blocking' => array(), 'warnings' => array(), 'facts' => array(
            array('code' => 'official_plugin_active', 'message' => 'Official plugin active.', 'data' => array('version' => '3.1.1')),
        ));
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
    }

    private function cli(): CliCommands {
        return new CliCommands(
            function () {
                ++$this->preflights;
                return $this->report;
            },
            $this->store,
            $this->system,
            $this->claims,
            function ($line) {
                $this->out[] = (string) $line;
            },
            function ($question) {
                $this->questions[] = (string) $question;
                return $this->answer;
            },
            $this->copy_step
        );
    }

    /** @return list<FakeCrudObject> */
    private function seed(int $orders = 2): array {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $seeded = array();
        for ($id = 1; $id <= $orders; $id++) {
            $order = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
            $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
            $seeded[] = $order;
        }
        return $seeded;
    }

    private function blocking(): void {
        $this->report['blocking'][] = array('code' => 'callback_unreachable', 'message' => 'The new callback URL is not reachable.', 'data' => array());
    }

    private function printed(): string {
        return implode("\n", $this->out);
    }

    // --- preflight. ---

    public function test_preflight_prints_findings_and_exits_0_without_blocking_findings(): void {
        self::assertSame(CliCommands::OK, $this->cli()->preflight(false));
        self::assertStringContainsString('official_plugin_active', $this->printed());
        self::assertSame(TakeoverState::IDLE, TakeoverState::get()['status'], 'read-only');
    }

    public function test_preflight_with_a_blocking_finding_exits_2_and_json_is_the_report(): void {
        $this->blocking();

        self::assertSame(CliCommands::BLOCKED, $this->cli()->preflight(true));

        self::assertCount(1, $this->out, 'json mode prints exactly one document');
        self::assertSame($this->report, json_decode($this->out[0], true));
    }

    // --- run. ---

    public function test_run_blocked_by_preflight_exits_2_and_changes_nothing(): void {
        $orders = $this->seed();
        $this->blocking();

        self::assertSame(CliCommands::BLOCKED, $this->cli()->run(true, true));

        self::assertSame(TakeoverState::IDLE, TakeoverState::get()['status']);
        self::assertSame(0, $orders[0]->saves);
        self::assertSame(array(), $this->system->deactivated);
        self::assertStringContainsString('callback_unreachable', $this->printed());
    }

    public function test_run_asks_for_confirmation_unless_yes_and_a_no_changes_nothing(): void {
        $this->seed();
        $this->answer = false;

        self::assertSame(CliCommands::ERROR, $this->cli()->run(false, true));

        self::assertCount(1, $this->questions);
        self::assertSame(TakeoverState::IDLE, TakeoverState::get()['status']);
        self::assertSame(array(), $this->system->deactivated);
    }

    public function test_run_yes_copies_verifies_cuts_over_and_deactivates_the_official_plugin(): void {
        $orders = $this->seed();

        $code = $this->cli()->run(true, true);

        self::assertSame(CliCommands::OK, $code, $this->printed());
        self::assertSame(array(), $this->questions, '--yes asks nothing');
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        foreach ($orders as $i => $order) {
            self::assertSame(array('pay-' . ($i + 1)), $order->persisted_values('_supcheckout_payment_id'), 'new key present');
            self::assertSame(array('pay-' . ($i + 1)), $order->persisted_values('UPayments_PaymentID'), 'legacy key intact');
            self::assertSame('upayments', $order->get_payment_method());
        }
        self::assertSame(get_option('woocommerce_upayments_settings'), get_option(Keys::SETTINGS_OPTION));
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated);
        self::assertStringContainsString('phase=', $this->printed(), 'copy progress is shown');
        self::assertStringContainsString('official plugin deactivated', $this->printed());
    }

    public function test_the_official_plugin_is_deactivated_after_copy_verifies_and_before_the_final_verify(): void {
        $this->seed();
        $at_deactivation = array();
        $this->system->on_deactivate = function () use (&$at_deactivation) {
            TakeoverState::refresh();
            $at_deactivation = array(
                'status' => TakeoverState::get()['status'],
                'quiesced' => Quiesce::is_active($this->system->now),
                'steps' => $this->steps,
            );
        };
        $official_during = array();
        $this->system->on_step = function ($step) use (&$official_during) {
            $this->steps[] = $step;
            $official_during[$step] = $this->system->official;
        };

        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), $this->printed());

        self::assertSame(TakeoverState::VERIFIED, $at_deactivation['status'], 'deactivated once the copy verified');
        self::assertFalse($at_deactivation['quiesced'], 'before Cutover takes the lease');
        self::assertSame(array(), $at_deactivation['steps'], 'before any Cutover step');
        self::assertNull($official_during['quiesced'], 'the final verify runs with the official plugin inactive');
        self::assertNull($official_during['verified_objects']);
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated, 'exactly once');
        self::assertNotContains('official_deactivated', $this->steps, 'nothing left for the post-cutover tail');
    }

    public function test_run_without_consent_while_the_official_plugin_is_active_copies_but_refuses_to_cut_over(): void {
        $orders = $this->seed();

        self::assertSame(CliCommands::BLOCKED, $this->cli()->run(true, false));

        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status'], 'copying may proceed; cutover never happens');
        self::assertSame(array('pay-1'), $orders[0]->persisted_values('_supcheckout_payment_id'));
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false), 'Cutover never ran');
        self::assertSame(array(), $this->system->deactivated);
        self::assertStringContainsString('--deactivate-official', $this->printed());
    }

    public function test_run_without_consent_cuts_over_when_the_official_plugin_is_inactive(): void {
        $this->seed();
        $this->system->official = null;
        self::assertSame(CliCommands::OK, $this->cli()->run(true, false));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array(), $this->system->deactivated);
    }

    public function test_a_failed_deactivation_before_cutover_cuts_nothing_over(): void {
        $this->seed();
        $this->system->deactivate_fails = true;

        self::assertSame(CliCommands::ERROR, $this->cli()->run(true, true));

        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false), 'Cutover never ran');
    }

    public function test_a_run_killed_after_deactivation_and_before_cutover_resumes_on_rerun(): void {
        $this->seed();
        $this->system->on_deactivate = static function () {
            throw new \RuntimeException('process killed after deactivation');
        };
        try {
            $this->cli()->run(true, true);
            self::fail('the run must die right after the deactivation');
        } catch (\RuntimeException $e) {
            self::assertSame('process killed after deactivation', $e->getMessage());
        }
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertNull($this->system->official, 'the official plugin is inactive; SUPCheckout serves the store in verified state');
        $this->system->on_deactivate = null;

        // Rerun without the flag: the official plugin is already inactive, nothing blocks.
        self::assertSame(CliCommands::OK, $this->cli()->run(true, false), $this->printed());

        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated, 'never deactivated twice');
    }

    public function test_the_cutover_path_triggers_no_hook_of_the_still_loaded_official_plugin_in_process(): void {
        // After deactivate_plugins() the official plugin's callbacks are still registered in
        // this process. Every write the cutover path makes goes through the CopyStore,
        // options and the CutoverSystem; none of them may fire an official hook, and no
        // billing claim or cron callback may run here.
        $this->seed();
        $events_before = count($this->system->events);

        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), $this->printed());

        self::assertFalse(class_exists('Simplixi\\SUPCheckout\\Subscription\\Cron\\CycleClaim', false) && $this->claims->released !== array(), 'no claim is taken or released in process');
        self::assertSame(array(), $this->claims->released);
        foreach (array_slice($this->system->events, $events_before) as $event) {
            self::assertStringStartsWith('supcheckout_', $event['hook'], 'only new-name cron twins are scheduled, nothing runs in process');
        }
        self::assertSame(0, $this->system->sleeps, 'no wait on any in-process worker');
    }

    public function test_a_copy_verification_mismatch_exits_3_and_leaves_the_store_uncut(): void {
        $this->seed();
        $this->store->user_meta[3] = array(
            '_upay_customer_token_v2_b1_' . str_repeat('a', 32) => array('provenance-record'),
            '_supcheckout_customer_token_v2_b1_' . str_repeat('a', 32) => array('foreign'),
        );

        self::assertSame(CliCommands::MISMATCH, $this->cli()->run(true, true));

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array(), $this->system->deactivated);
        self::assertStringContainsString('users', $this->printed());
    }

    public function test_a_run_killed_mid_copy_resumes_at_the_cursor_on_rerun(): void {
        $orders = $this->seed(5);
        $killed = false;
        $this->store->on_load_order = static function ($order_id) use (&$killed) {
            if ($order_id === 3 && !$killed) {
                $killed = true;
                throw new \RuntimeException('process killed');
            }
        };

        try {
            $this->cli()->run(true, true);
            self::fail('the run must die at order 3');
        } catch (\RuntimeException $e) {
            self::assertSame('process killed', $e->getMessage());
        }
        self::assertSame(TakeoverState::COPYING, TakeoverState::get()['status']);
        self::assertSame(2, TakeoverState::get()['cursor']);
        // The dead process's runner lock expires after LOCK_TTL.
        $this->system->now += CopyRunner::LOCK_TTL + 1;

        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), $this->printed());

        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        foreach ($orders as $i => $order) {
            self::assertSame(1, $order->saves, 'order ' . ($i + 1) . ' is copied exactly once');
            self::assertSame(array('pay-' . ($i + 1)), $order->persisted_values('_supcheckout_payment_id'));
        }
    }

    public function test_a_cutover_that_fails_before_the_switch_is_rerun_from_verified(): void {
        $this->seed();
        $this->system->on_step = static function ($step) {
            if ($step === 'settings_copied') {
                throw new \RuntimeException('killed in cutover');
            }
        };
        self::assertSame(CliCommands::ERROR, $this->cli()->run(true, true));
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(Quiesce::is_active($this->system->now), 'the lease is released');

        $this->system->on_step = null;
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), $this->printed());
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated);
    }

    public function test_a_store_already_cut_over_only_retries_the_deactivation(): void {
        $this->seed();
        CutoverTest::state('cut_over');

        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));

        self::assertSame(0, $this->preflights, 'no preflight after cutover');
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated);
    }

    public function test_an_official_plugin_reactivated_during_cutover_refuses_the_switch_with_exit_2(): void {
        $this->seed();
        // Someone reactivates it after the pre-cutover deactivation: Cutover refuses to
        // switch (official_active) and the store stays verified for a rerun.
        $system = $this->system;
        $this->system->on_step = static function ($step) use ($system) {
            if ($step === 'quiesced') {
                $system->official = 'upayments/UPayments.php';
            }
        };

        self::assertSame(CliCommands::BLOCKED, $this->cli()->run(true, true));

        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertStringContainsString('official_active', $this->printed());
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated, 'only the pre-cutover deactivation');

        $this->system->on_step = null;
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), 'the rerun deactivates it again and switches');
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_a_cut_over_store_whose_official_plugin_cannot_be_deactivated_exits_7(): void {
        $this->seed();
        CutoverTest::state('cut_over');
        $this->system->deactivate_fails = true;

        self::assertSame(CliCommands::OFFICIAL_STILL_ACTIVE, $this->cli()->run(true, true));

        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertStringContainsString('official_still_active', $this->printed());
    }

    public function test_a_stale_claim_exits_5_and_a_held_lease_exits_4(): void {
        $this->seed();
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('6', 64), 'parent_order_id' => 1, 'owner_token' => 'o', 'state' => 'claimed', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', 1800000000 - 3600)));
        self::assertSame(CliCommands::STALE_CLAIMS, $this->cli()->run(true, true));
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertStringContainsString('release-stale-claims', $this->printed());

        unset($this->store->tables['legacy'][str_repeat('6', 64)]);
        $held = Quiesce::acquire($this->system->now);
        self::assertSame(CliCommands::BUSY, $this->cli()->run(true, true));
        Quiesce::release($held);
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
    }

    public function test_a_copy_runner_held_by_another_process_waits_bounded_then_exits_4(): void {
        $this->seed();
        CutoverTest::state('copying');
        $GLOBALS['supcheckout_test_options'][CopyRunner::LOCK_OPTION] = ($this->system->now + 100000) . ':' . str_repeat('a', 32);

        self::assertSame(CliCommands::BUSY, $this->cli()->run(true, true));

        self::assertGreaterThan(0, $this->system->sleeps);
        self::assertLessThanOrEqual(1800000000 + CliCommands::MAX_WAIT + CliCommands::WAIT_POLL, $this->system->now, 'the wait is bounded');
        self::assertSame(TakeoverState::COPYING, TakeoverState::get()['status'], 'resumable');
    }

    public function test_a_runner_that_keeps_stopping_without_progress_backs_off_then_exits_1(): void {
        $this->seed();
        CutoverTest::state('copying');
        $calls = 0;
        $this->copy_step = static function () use (&$calls) {
            ++$calls;
            if ($calls > 50) {
                throw new \RuntimeException('hot loop');
            }
            return CopyRunner::STOPPED;
        };

        self::assertSame(CliCommands::ERROR, $this->cli()->run(true, true));

        self::assertSame(CliCommands::MAX_STOPPED, $calls, 'capped at consecutive stops without progress');
        self::assertSame(CliCommands::MAX_STOPPED - 1, $this->system->sleeps, 'it backs off between stops');
        self::assertSame(TakeoverState::COPYING, TakeoverState::get()['status'], 'resumable');
        self::assertSame(array(), $this->system->deactivated);
        self::assertStringContainsString('stopped', $this->printed());
    }

    public function test_the_busy_wait_budget_resets_whenever_the_copy_makes_progress(): void {
        $this->seed();
        CutoverTest::state('copying');
        $stall = (int) (CliCommands::MAX_WAIT / CliCommands::WAIT_POLL);
        $script = array_merge(
            array_fill(0, $stall, CopyRunner::BUSY),
            array(CopyRunner::CONTINUE_RUN),
            array_fill(0, $stall, CopyRunner::RETRY),
            array(CopyRunner::CONTINUE_RUN, CopyRunner::STOPPED, CopyRunner::CONTINUE_RUN, CopyRunner::STOPPED, CopyRunner::STOPPED),
            array('verify')
        );
        $this->copy_step = static function () use (&$script) {
            $next = array_shift($script);
            if ($next === 'verify' || $next === null) {
                CutoverTest::state('verified', false);
                return CopyRunner::VERIFIED;
            }
            return $next;
        };

        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), $this->printed());
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_a_cut_over_store_with_the_official_plugin_active_again_exits_7_without_consent(): void {
        $this->seed();
        CutoverTest::state('cut_over');

        self::assertSame(CliCommands::OFFICIAL_STILL_ACTIVE, $this->cli()->run(true, false));

        self::assertSame(array(), $this->system->deactivated);
        self::assertStringContainsString('--deactivate-official', $this->printed());
        self::assertStringNotContainsString('Success', $this->printed());

        $this->system->official = null;
        $this->out = array();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, false), 'nothing to do once it is inactive');
    }

    public function test_states_run_cannot_continue_from_exit_6(): void {
        foreach (array('rolled_back', 'finalized') as $status) {
            CutoverTest::state($status);
            self::assertSame(CliCommands::STATE, $this->cli()->run(true, true), $status);
        }
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = 'not json';
        TakeoverState::flush_cache();
        self::assertSame(CliCommands::STATE, $this->cli()->run(true, true), 'invalid');
        self::assertSame(array(), $this->system->deactivated);
    }

    // --- status. ---

    public function test_status_reports_state_progress_journal_warnings_and_stale_claims(): void {
        $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $this->system->official = 'upayments/UPayments.php';
        $this->claims->add(str_repeat('a', 64), 'claimed', 'owner-a', $this->system->now - 3600);
        $this->claims->add(str_repeat('b', 64), 'dispatching', 'owner-b', $this->system->now - 3600);
        $this->out = array();

        self::assertSame(CliCommands::OK, $this->cli()->status(true));

        self::assertCount(1, $this->out);
        $status = json_decode($this->out[0], true);
        self::assertSame('cut_over', $status['status']);
        self::assertSame(2, $status['totals']['orders']);
        self::assertGreaterThan(0, $status['journal']['entries']);
        self::assertArrayHasKey('option', $status['journal']['by_target']);
        self::assertSame(array(array('cycle_key' => str_repeat('a', 64), 'age_seconds' => 3600)), $status['stale_claims']);
        self::assertContains('official_still_active', $status['warnings'], 'cut over while the official plugin is still active');
        self::assertContains('stale_claims', $status['warnings']);
        self::assertStringNotContainsString('owner-a', $this->out[0]);
        self::assertStringNotContainsString('test-api-key-123', $this->out[0]);
    }

    public function test_status_in_text_mode_on_an_idle_store(): void {
        self::assertSame(CliCommands::OK, $this->cli()->status(false));
        self::assertStringContainsString('idle', $this->printed());
    }

    // --- rollback and abort (Task 10). ---

    public function test_rollback_after_run_restores_the_store_and_reactivates_the_official_plugin(): void {
        $orders = $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $this->out = array();

        self::assertSame(CliCommands::OK, $this->cli()->rollback(true, true), $this->printed());

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated);
        self::assertSame(array(), $orders[0]->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array('pay-1'), $orders[0]->persisted_values('UPayments_PaymentID'));
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
        self::assertStringContainsString('Success: rolled back', $this->printed());
        self::assertStringContainsString('official_reactivated', $this->printed());
    }

    public function test_rollback_asks_for_confirmation_and_a_no_changes_nothing(): void {
        $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $this->answer = false;

        self::assertSame(CliCommands::ERROR, $this->cli()->rollback(false, true));

        self::assertCount(1, $this->questions);
        self::assertStringContainsString('reactivate the official UPayments plugin', $this->questions[0]);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_rollback_with_a_later_edit_exits_8_and_prints_the_conflict(): void {
        $this->seed();
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('supcheckout' => 0, 'bacs' => 1, 'upayments' => 2);
        $this->out = array();

        self::assertSame(CliCommands::CONFLICTS, $this->cli()->rollback(true, false));

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], 'rolled back regardless');
        self::assertMatchesRegularExpression('/conflict: seq [0-9]+ option woocommerce_gateway_order/', $this->printed());
    }

    public function test_rollback_exit_codes_for_state_busy_and_reactivation(): void {
        self::assertSame(CliCommands::STATE, $this->cli()->rollback(true, false), 'idle');
        $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $lease = Quiesce::acquire($this->system->now);
        self::assertSame(CliCommands::BUSY, $this->cli()->rollback(true, false));
        Quiesce::release($lease);
        self::assertSame(CliCommands::STATE, $this->cli()->abort(true, false), 'abort needs verified');
        $this->system->installed = array();
        self::assertSame(CliCommands::OFFICIAL_NOT_REACTIVATED, $this->cli()->rollback(true, true), 'nothing installed to reactivate');
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
    }

    public function test_rollback_refuses_an_active_official_plugin_without_consent_and_deactivates_it_with(): void {
        $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $this->system->official = 'upayments/UPayments.php';
        $this->system->deactivated = array();

        self::assertSame(CliCommands::BLOCKED, $this->cli()->rollback(true, false));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertStringContainsString('--deactivate-official', $this->printed());

        $this->out = array();
        self::assertSame(CliCommands::OK, $this->cli()->rollback(true, true, true), $this->printed());
        self::assertSame(array('upayments/UPayments.php'), $this->system->deactivated, 'deactivated before the lease');
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated, 'and the recorded install reactivated afterwards');
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
    }

    public function test_run_records_the_official_install_it_deactivated(): void {
        $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        self::assertSame('upayments/UPayments.php', TakeoverState::get()['official_deactivated']);
    }

    public function test_a_conflict_still_prints_the_reactivation_warning_before_exit_8(): void {
        $this->seed();
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('supcheckout' => 0);
        $this->system->activate_fails = true;
        $this->out = array();

        self::assertSame(CliCommands::CONFLICTS, $this->cli()->rollback(true, true));

        self::assertStringContainsString('warning: official_not_reactivated', $this->printed());
    }

    public function test_abort_moves_a_verified_store_back_to_preflight_ok(): void {
        $orders = $this->seed();
        self::assertSame(CliCommands::BLOCKED, $this->cli()->run(true, false), 'official active, no consent: verified');
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        $this->out = array();

        self::assertSame(CliCommands::OK, $this->cli()->abort(true, true), $this->printed());

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array(), $orders[0]->persisted_values('_supcheckout_payment_id'), 'copies removed');
        self::assertSame(array(), $this->system->activated, 'still active: nothing to reactivate');
        self::assertStringContainsString('Success: aborted', $this->printed());
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true), 'a later run starts clean');
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_run_refuses_while_an_abort_is_still_removing_copies(): void {
        $this->seed();
        self::assertSame(CliCommands::BLOCKED, $this->cli()->run(true, false));
        $this->system->on_step = static function ($step) {
            if ($step === 'aborted') {
                throw new \RuntimeException('killed');
            }
        };
        self::assertSame(CliCommands::ERROR, $this->cli()->abort(true, false));
        $this->system->on_step = null;
        $this->out = array();

        self::assertSame(CliCommands::STATE, $this->cli()->run(true, true));
        self::assertStringContainsString('wp supcheckout takeover abort', $this->printed());
        self::assertSame(CliCommands::OK, $this->cli()->abort(true, false));
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
    }

    public function test_status_reports_the_rollback(): void {
        $this->seed();
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('supcheckout' => 0);
        self::assertSame(CliCommands::CONFLICTS, $this->cli()->rollback(true, false));
        $this->out = array();

        self::assertSame(CliCommands::OK, $this->cli()->status(true));

        $status = json_decode($this->out[0], true);
        self::assertSame('preflight_ok', $status['status']);
        self::assertGreaterThan(0, $status['rolled_back_at']);
        self::assertSame('rollback', $status['rollback']['mode']);
        self::assertFalse($status['rollback']['removal_pending']);
        self::assertSame(array('woocommerce_gateway_order'), array_column($status['rollback']['conflicts'], 'path'));
        self::assertContains('rollback_conflicts', $status['warnings']);
    }

    // --- release-stale-claims. ---

    public function test_release_stale_claims_confirms_releases_and_reports(): void {
        $this->claims->add(str_repeat('a', 64), 'claimed', 'owner-a', $this->system->now - 3600);
        $this->claims->add(str_repeat('b', 64), 'dispatching', 'owner-b', $this->system->now - 3600);

        $this->answer = false;
        self::assertSame(CliCommands::ERROR, $this->cli()->release_stale_claims(false));
        self::assertCount(2, $this->claims->rows, 'declined: nothing released');

        self::assertSame(CliCommands::OK, $this->cli()->release_stale_claims(true));
        self::assertSame(array(str_repeat('b', 64)), array_keys($this->claims->rows));
        self::assertStringContainsString('released 1', $this->printed());
    }

    public function test_release_stale_claims_exits_4_while_the_lease_is_held(): void {
        $this->claims->add(str_repeat('a', 64), 'claimed', 'owner-a', $this->system->now - 3600);
        Quiesce::acquire($this->system->now);
        self::assertSame(CliCommands::BUSY, $this->cli()->release_stale_claims(true));
        self::assertCount(1, $this->claims->rows);
    }

    // --- finalize (Task 11). ---

    /** @return list<FakeCrudObject> A store cut over through `run`. */
    private function cut_over(): array {
        $orders = $this->seed();
        self::assertSame(CliCommands::OK, $this->cli()->run(true, true));
        $this->out = array();
        $this->questions = array();
        return $orders;
    }

    private static function age(int $days): void {
        $state = json_decode((string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], true);
        $state['cut_over_at'] = time() - $days * 86400;
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode($state);
        TakeoverState::flush_cache();
    }

    public function test_finalize_before_30_days_exits_6_with_the_days_left_and_changes_nothing(): void {
        $orders = $this->cut_over();
        self::age(12);

        self::assertSame(CliCommands::STATE, $this->cli()->finalize(true, false, false));

        self::assertStringContainsString('18 day(s)', $this->printed());
        self::assertStringContainsString('--force-after-review', $this->printed());
        self::assertSame(array(), $this->questions);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('pay-1'), $orders[0]->persisted_values('UPayments_PaymentID'));
    }

    public function test_finalize_warns_that_it_is_irreversible_and_a_no_changes_nothing(): void {
        $orders = $this->cut_over();
        $this->answer = false;

        self::assertSame(CliCommands::ERROR, $this->cli()->finalize(false, true, false));

        self::assertCount(1, $this->questions);
        self::assertStringContainsString('IRREVERSIBLE', $this->questions[0]);
        self::assertStringContainsString('rollback', $this->questions[0]);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('pay-1'), $orders[0]->persisted_values('UPayments_PaymentID'));
    }

    public function test_finalize_yes_after_review_finalizes_and_rollback_then_exits_6(): void {
        $orders = $this->cut_over();

        self::assertSame(CliCommands::OK, $this->cli()->finalize(true, true, false), $this->printed());

        self::assertSame(array(), $this->questions, '--yes asks nothing');
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
        self::assertSame(array(), $orders[0]->persisted_values('UPayments_PaymentID'));
        self::assertSame(array('pay-1'), $orders[0]->persisted_values('_supcheckout_payment_id'));
        self::assertStringContainsString('takeover finalize steps:', $this->printed());
        self::assertStringContainsString('Success: finalized', $this->printed());
        self::assertSame(CliCommands::STATE, $this->cli()->rollback(true, false), 'irreversible');
        self::assertSame(CliCommands::STATE, $this->cli()->run(true, false), 'run cannot continue from finalized');
    }

    public function test_finalize_exit_codes_for_official_busy_and_kept_values(): void {
        self::assertSame(CliCommands::STATE, $this->cli()->finalize(true, true, false), 'idle');
        $orders = $this->cut_over();

        $this->system->official = 'upayments/UPayments.php';
        self::assertSame(CliCommands::BLOCKED, $this->cli()->finalize(true, true, false));
        $this->system->official = null;

        $lease = Quiesce::acquire($this->system->now);
        self::assertSame(CliCommands::BUSY, $this->cli()->finalize(true, true, false));
        Quiesce::release($lease);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);

        $orders[1]->external_write('UPayments_PaymentID', array('pay-2-newer'));
        $this->out = array();
        self::assertSame(CliCommands::CONFLICTS, $this->cli()->finalize(true, true, false));
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status'], 'finalized regardless');
        self::assertStringContainsString('kept: order_meta UPayments_PaymentID unequal', $this->printed());
        self::assertStringNotContainsString('pay-2-newer', $this->printed(), 'never a value');
    }

    public function test_finalize_settles_with_progress_lines_and_a_writer_that_never_stops_exits_4(): void {
        $orders = $this->cut_over();
        $writes = 0;
        $this->system->on_step = static function ($step) use ($orders, &$writes) {
            if ($step === 'legacy_swept' || $step === 'rescanned') {
                ++$writes;
                $orders[0]->external_write('UPayments_TrackID', array('t-' . $writes));
                $orders[0]->external_write('_supcheckout_track_id', array('t-' . $writes));
            }
        };

        self::assertSame(CliCommands::BUSY, $this->cli()->finalize(true, true, false));

        self::assertStringContainsString('removal_unsettled', $this->printed());
        self::assertStringContainsString('settling', $this->printed(), 'the settle wait is visible');
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
        $this->system->on_step = null;
        self::assertSame(CliCommands::OK, $this->cli()->finalize(true, false, false), 'a rerun completes');
    }

    public function test_finalize_on_a_finalized_store_refuses_an_active_official_plugin_before_asking(): void {
        $this->cut_over();
        self::assertSame(CliCommands::OK, $this->cli()->finalize(true, true, false));
        $this->system->official = 'upayments/UPayments.php';
        $this->questions = array();
        $this->out = array();

        self::assertSame(CliCommands::BLOCKED, $this->cli()->finalize(false, false, false));

        self::assertSame(array(), $this->questions, 'refused before the confirmation');
        self::assertStringContainsString('official UPayments plugin', $this->printed());
    }

    public function test_finalize_dry_run_writes_nothing_and_prints_the_plan(): void {
        $this->cut_over();
        self::age(3);
        $options = $GLOBALS['supcheckout_test_options'];
        $GLOBALS['supcheckout_test_option_calls'] = array();

        self::assertSame(CliCommands::OK, $this->cli()->finalize(false, false, true));

        self::assertSame(array(), $this->questions, 'a dry run asks nothing');
        self::assertSame($options, $GLOBALS['supcheckout_test_options']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertStringContainsString('would refuse: too_early', $this->printed());
        self::assertStringContainsString('finalize allowed in 27 day(s)', $this->printed());
        self::assertStringContainsString('would remove: ', $this->printed());
        self::assertStringContainsString('order_meta=2', $this->printed());

        $this->out = array();
        self::assertSame(CliCommands::OK, $this->cli()->finalize(false, false, true, true));
        self::assertCount(1, $this->out, 'json mode prints exactly one document');
        $plan = json_decode($this->out[0], true);
        self::assertSame(array('too_early'), $plan['would_refuse']);
        self::assertSame(2, $plan['removed']['order_meta']);
    }

    public function test_status_shows_the_days_until_finalize_and_a_dry_run_hint_without_scanning(): void {
        $this->cut_over();
        self::age(25);
        $this->store->selections = array();

        self::assertSame(CliCommands::OK, $this->cli()->status(true));

        self::assertSame(array(), $this->store->selections, 'status scans no order, user, product or table rows');
        $status = json_decode($this->out[0], true);
        self::assertSame(5, $status['finalize']['days_left']);
        self::assertFalse($status['finalize']['allowed']);
        self::assertNull($status['finalize']['planned'], 'the plan is the dry run, not status');
        self::assertStringContainsString('finalize --dry-run', $status['finalize']['hint']);

        $this->out = array();
        self::assertSame(CliCommands::OK, $this->cli()->status(false));
        self::assertStringContainsString('finalize: ', $this->printed());
        self::assertSame(array(), $this->store->selections);
    }
}
