<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCopyStore;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCutoverSystem;
use Simplixi\SUPCheckout\Tests\Takeover\FakeStaleClaimStore;

/**
 * 0c Task 1: the UI-agnostic Onboarding service. Its decisions, codes and messages are the
 * ones `wp supcheckout takeover` printed before the extraction (CliCommandsTest keeps
 * proving the CLI end to end); here they are read from the returned Outcome.
 */
final class OnboardingTest extends TestCase {
    /** @var FakeCopyStore */
    private $store;
    /** @var FakeCutoverSystem */
    private $system;
    /** @var FakeStaleClaimStore */
    private $claims;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        TakeoverState::flush_cache();
        $this->store = new FakeCopyStore();
        $this->system = new FakeCutoverSystem();
        $this->claims = new FakeStaleClaimStore();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
    }

    /** @param array<string, mixed> $report Preflight report. */
    private function onboarding(array $report = array()): Onboarding {
        return new Onboarding(
            static function () use ($report) {
                return $report;
            },
            $this->store,
            $this->system,
            $this->claims
        );
    }

    private function seed_state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array(
            'status' => $status,
            'cut_over_at' => in_array($status, array('cut_over', 'finalized'), true) ? time() : 0,
            'cursor' => 0,
            'totals' => array(),
        ));
        TakeoverState::flush_cache();
    }

    // --- Outcome. ---

    public function test_outcome_factories_and_accessors(): void {
        $ok = Outcome::ok('cut over', array('a' => 1), array('line'));
        self::assertSame(array(Outcome::OK, null, 'cut over', array('a' => 1), array('line'), true), array($ok->code(), $ok->reason(), $ok->message(), $ok->data(), $ok->lines(), $ok->is_ok()));

        $fail = Outcome::fail(Outcome::BUSY, 'busy', 'try later');
        self::assertSame(array(Outcome::BUSY, 'busy', 'try later', array(), array(), false), array($fail->code(), $fail->reason(), $fail->message(), $fail->data(), $fail->lines(), $fail->is_ok()));
    }

    public function test_the_cli_exit_code_constants_alias_outcome(): void {
        self::assertSame(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9), array(CliCommands::OK, CliCommands::ERROR, CliCommands::BLOCKED, CliCommands::MISMATCH, CliCommands::BUSY, CliCommands::STALE_CLAIMS, CliCommands::STATE, CliCommands::OFFICIAL_STILL_ACTIVE, CliCommands::CONFLICTS, CliCommands::OFFICIAL_NOT_REACTIVATED));
        self::assertSame(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9), array(Outcome::OK, Outcome::ERROR, Outcome::BLOCKED, Outcome::MISMATCH, Outcome::BUSY, Outcome::STALE_CLAIMS, Outcome::STATE, Outcome::OFFICIAL_STILL_ACTIVE, Outcome::CONFLICTS, Outcome::OFFICIAL_NOT_REACTIVATED));
    }

    // --- plan_run. ---

    public function test_plan_run_refuses_a_finalized_store_with_the_state_code(): void {
        $this->seed_state('finalized');
        $outcome = $this->onboarding()->plan_run(false);
        self::assertSame(array(Outcome::STATE, 'state', 'state "finalized" does not allow run'), array($outcome->code(), $outcome->reason(), $outcome->message()));
    }

    public function test_plan_run_on_cut_over_with_official_active_and_no_consent_is_official_still_active(): void {
        $this->seed_state('cut_over');
        $this->system->official = 'upayments/UPayments.php';
        $outcome = $this->onboarding()->plan_run(false);
        self::assertSame(Outcome::OFFICIAL_STILL_ACTIVE, $outcome->code());
        self::assertSame('cut over, but the official UPayments plugin (upayments/UPayments.php) is active again; SUPCheckout stays paused. Rerun with --deactivate-official', $outcome->message());
    }

    public function test_plan_run_on_cut_over_with_consent_is_the_cutover_tail(): void {
        $this->seed_state('cut_over');
        $outcome = $this->onboarding()->plan_run(true);
        self::assertSame(array(Outcome::OK, 'cutover_tail'), array($outcome->code(), $outcome->data()['next']));
    }

    public function test_plan_run_on_cut_over_with_the_official_plugin_inactive_is_done(): void {
        $this->seed_state('cut_over');
        $this->system->official = null;
        $outcome = $this->onboarding()->plan_run(false);
        self::assertSame(array(Outcome::OK, 'done', 'already cut over; nothing to do'), array($outcome->code(), $outcome->data()['next'], $outcome->message()));
    }

    public function test_plan_run_on_an_idle_store_is_run(): void {
        $outcome = $this->onboarding()->plan_run(false);
        self::assertSame(array(Outcome::OK, 'run'), array($outcome->code(), $outcome->data()['next']));
    }

    // --- preflight. ---

    public function test_check_preflight_blocks_with_the_report_lines(): void {
        $outcome = $this->onboarding(array('blocking' => array(array('code' => 'credential_unverified', 'message' => 'x', 'data' => array()))))->check_preflight();
        self::assertSame(array(Outcome::BLOCKED, 'preflight_blocking', 'blocked by preflight; nothing changed'), array($outcome->code(), $outcome->reason(), $outcome->message()));
        self::assertSame(array('BLOCKING: credential_unverified: x'), $outcome->lines());
        self::assertSame(array('blocking', 'warnings', 'facts'), array_keys($outcome->data()['report']));
    }

    public function test_preflight_normalizes_a_partial_report(): void {
        $report = $this->onboarding(array('facts' => array('k' => array('code' => 'f', 'message' => 'm'))))->preflight();
        self::assertSame(array(), $report['blocking']);
        self::assertSame(array(), $report['warnings']);
        self::assertSame(array(array('code' => 'f', 'message' => 'm')), $report['facts'], 'reindexed');
    }

    // --- cutover. ---

    /** @return array<string, array{string, int}> */
    public static function cutover_errors(): array {
        return array(
            'verify_mismatch' => array('verify_mismatch', Outcome::MISMATCH),
            'table_mismatch' => array('table_mismatch', Outcome::MISMATCH),
            'settings_mismatch' => array('settings_mismatch', Outcome::MISMATCH),
            'busy' => array('busy', Outcome::BUSY),
            'lease_lost' => array('lease_lost', Outcome::BUSY),
            'claims_in_flight' => array('claims_in_flight', Outcome::BUSY),
            'transition_refused' => array('transition_refused', Outcome::BUSY),
            'stale_claims' => array('stale_claims', Outcome::STALE_CLAIMS),
            'not_verified' => array('not_verified', Outcome::STATE),
            'official_active' => array('official_active', Outcome::BLOCKED),
            'unlisted' => array('something_unexpected', Outcome::ERROR),
        );
    }

    #[DataProvider('cutover_errors')]
    public function test_cutover_maps_every_engine_error_like_the_cli(string $error, int $code): void {
        $outcome = Onboarding::cutover_outcome(array('ok' => false, 'error' => $error, 'steps' => array(), 'warnings' => array(), 'report' => array('x' => 1)));

        self::assertSame(array($code, $error, 'cutover refused: ' . $error . '; the store was not switched'), array($outcome->code(), $outcome->reason(), $outcome->message()));
        $lines = array('cutover report: {"x":1}');
        if ($error === 'stale_claims') {
            $lines[] = 'run `wp supcheckout takeover release-stale-claims`, then rerun';
        }
        self::assertSame($lines, $outcome->lines());
    }

    public function test_cutover_on_an_idle_store_is_refused_as_not_verified(): void {
        $outcome = $this->onboarding()->cutover(true);
        self::assertSame(array(Outcome::STATE, 'not_verified'), array($outcome->code(), $outcome->reason()));
    }

    // --- status. ---

    public function test_status_document_keeps_the_cli_json_keys_in_order(): void {
        self::assertSame(array('status', 'cut_over_at', 'rolled_back_at', 'rollback', 'finalize', 'phase', 'cursor', 'cursors', 'totals', 'mismatches', 'anomalies', 'journal', 'quiesced', 'official_plugin', 'stale_claims', 'warnings'), array_keys($this->onboarding()->status()));
    }

    // --- retire_official. ---

    public function test_retire_official_for_a_switch_without_consent_is_blocked_official_active(): void {
        $outcome = $this->onboarding()->retire_official(false, 'switch');
        self::assertSame(array(Outcome::BLOCKED, 'official_active'), array($outcome->code(), $outcome->reason()));
        self::assertSame('the official UPayments plugin (upayments/UPayments.php) is active; the copy is verified but the store was not switched. Rerun with --deactivate-official, or deactivate it first', $outcome->message());
        self::assertSame(array(), $this->system->deactivated);
    }

    public function test_retire_official_with_consent_deactivates_and_records_the_basename(): void {
        $outcome = $this->onboarding()->retire_official(true, 'rollback');
        self::assertTrue($outcome->is_ok());
        self::assertSame('upayments/UPayments.php', $outcome->data()['deactivated']);
        self::assertSame(array('official plugin deactivated: upayments/UPayments.php'), $outcome->lines());
        self::assertSame('upayments/UPayments.php', TakeoverState::get()[Rollback::OFFICIAL_RECORD]);
    }

    public function test_retire_official_with_nothing_active_deactivates_nothing(): void {
        $this->system->official = null;
        $outcome = $this->onboarding()->retire_official(false, 'switch');
        self::assertSame(array(Outcome::OK, null, array()), array($outcome->code(), $outcome->data()['deactivated'], $outcome->lines()));
    }

    // --- rewind. ---

    public function test_plan_rewind_from_cut_over_asks_for_retiring_the_official_plugin(): void {
        $this->seed_state('cut_over');
        $outcome = $this->onboarding()->plan_rewind(Rollback::MODE_ROLLBACK);
        self::assertSame(array(Outcome::OK, true), array($outcome->code(), $outcome->data()['retire_official']));
    }

    public function test_plan_rewind_refuses_an_abort_of_an_idle_store(): void {
        $outcome = $this->onboarding()->plan_rewind(Rollback::MODE_ABORT);
        self::assertSame(array(Outcome::STATE, 'state', 'state "idle" does not allow abort'), array($outcome->code(), $outcome->reason(), $outcome->message()));
    }

    // --- finalize. ---

    public function test_plan_finalize_before_30_days_without_force_is_too_early(): void {
        $this->system->official = null;
        $this->seed_state('cut_over');
        $outcome = $this->onboarding()->plan_finalize(false);
        self::assertSame(array(Outcome::STATE, 'too_early', 30), array($outcome->code(), $outcome->reason(), $outcome->data()['days_left']));
        self::assertStringStartsWith('finalize is allowed in 30 day(s) (', $outcome->message());
    }

    public function test_plan_finalize_with_force_is_allowed(): void {
        $this->system->official = null;
        $this->seed_state('cut_over');
        $outcome = $this->onboarding()->plan_finalize(true);
        self::assertTrue($outcome->is_ok());
        self::assertSame(30, $outcome->data()['days_left']);
        self::assertGreaterThan(0, $outcome->data()['due_at']);
    }

    public function test_finalize_exit_map_is_keyed_by_engine_error(): void {
        self::assertSame(Outcome::MISMATCH, Onboarding::FINALIZE_EXIT['cut_over_time_invalid']);
        self::assertSame(Outcome::BUSY, Onboarding::ROLLBACK_EXIT['removal_unsettled']);
        self::assertSame(Outcome::BLOCKED, Onboarding::CUTOVER_EXIT['official_active']);
    }

    // --- stale claims. ---

    public function test_release_stale_claims_with_none_is_ok(): void {
        $onboarding = $this->onboarding();
        self::assertSame(array(), $onboarding->stale_claims());
        $outcome = $onboarding->release_stale_claims();
        self::assertSame(array(Outcome::OK, 'no stale claims'), array($outcome->code(), $outcome->message()));
    }
}
