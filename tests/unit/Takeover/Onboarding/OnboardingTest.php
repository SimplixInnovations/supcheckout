<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\Finalize;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\StaleClaims;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\FakeBudgetedSystem;
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

    // --- the cache-purge CLI line (0c Task 2). ---

    public function test_the_cache_purge_line_follows_the_steps_line_of_every_engine_outcome(): void {
        $purge = array('ran' => array('litespeed', 'object_cache'), 'manual' => array('cloudflare'), 'failed' => array());
        $expected = 'cache purge: ran=litespeed,object_cache manual=cloudflare failed=-';

        $cutover = Onboarding::cutover_outcome(array('ok' => true, 'error' => null, 'steps' => array('cut_over', 'caches_flushed'), 'warnings' => array(), 'report' => array('cache_purge' => $purge)));
        self::assertSame(array('takeover cutover steps: cut_over, caches_flushed', $expected), $cutover->lines());

        $rollback = Onboarding::rewind_outcome(Rollback::MODE_ROLLBACK, array('ok' => true, 'restored' => 0, 'conflicts' => array(), 'error' => null, 'steps' => array('rolled_back'), 'warnings' => array(), 'report' => array('cache_purge' => $purge)));
        self::assertSame(array('takeover rollback steps: rolled_back', $expected), $rollback->lines());

        $finalize = Onboarding::finalize_outcome(array('ok' => true, 'removed' => array('orders' => 2), 'kept' => array(), 'reported' => array(), 'forced' => false, 'error' => null, 'steps' => array('finalized'), 'report' => array('cache_purge' => $purge)));
        self::assertSame(array('takeover finalize steps: finalized', $expected, 'removed: orders=2'), array_slice($finalize->lines(), 0, 3));
    }

    public function test_a5_04_a_settings_diverged_rollback_prints_how_to_clear_the_kept_settings(): void {
        $outcome = Onboarding::rewind_outcome(Rollback::MODE_ROLLBACK, array(
            'ok' => false, 'restored' => 0, 'error' => null, 'steps' => array('rolled_back'), 'warnings' => array(),
            'conflicts' => array(array('seq' => -1, 'target' => 'settings_diverged', 'path' => 'woocommerce_upayments_settings')),
            'report' => array('settings_diverged' => array('kept' => array('woocommerce_supcheckout_settings'), 'legacy' => 'woocommerce_upayments_settings', 'hint' => Rollback::SETTINGS_DIVERGED_HINT)),
        ));

        self::assertSame(Outcome::CONFLICTS, $outcome->code());
        self::assertContains('next: ' . Rollback::SETTINGS_DIVERGED_HINT, $outcome->lines());
    }

    public function test_a_failed_purge_is_listed_by_name_and_no_purge_means_no_line(): void {
        $purge = array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array('w3tc', 'system'));
        $with = Onboarding::cutover_outcome(array('ok' => true, 'error' => null, 'steps' => array('cut_over'), 'warnings' => array(), 'report' => array('cache_purge' => $purge)));
        self::assertSame('cache purge: ran=object_cache manual=- failed=w3tc,system', $with->lines()[1]);

        $without = Onboarding::cutover_outcome(array('ok' => true, 'error' => null, 'steps' => array('cut_over'), 'warnings' => array(), 'report' => array()));
        self::assertSame(array('takeover cutover steps: cut_over'), $without->lines());
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

    // --- yielded (0c Task 3). ---

    /** @param array<string, mixed> $fields State fields beyond the lifecycle ones. */
    private function seed_progress(string $status, int $cut_over_at, array $fields): FakeBudgetedSystem {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array_merge(array(
            'status' => $status,
            'cut_over_at' => $cut_over_at,
            'cursor' => 0,
            'totals' => array(),
        ), $fields));
        TakeoverState::flush_cache();
        $system = new FakeBudgetedSystem();
        $system->now = 5000;
        $system->official = null;
        return $system;
    }

    public function test_rewind_maps_yielded_to_busy_with_resume_at(): void {
        $system = $this->seed_progress('rolled_back', 0, array(Rollback::PROGRESS => array(
            'mode' => Rollback::MODE_ROLLBACK, 'conflicts' => array(), 'restored' => 0, 'removal' => array('phase' => 'done', 'cursor' => 0),
            'removal_pending' => true, 'transitioned_at' => 5000, 'pass' => 1, 'found' => 0,
        )));

        $outcome = $this->onboarding()->rewind(Rollback::MODE_ROLLBACK, false, $system);

        self::assertSame(array(4, 'yielded'), array($outcome->code(), $outcome->reason()));
        self::assertSame(5120, $outcome->data()['resume_at']);
        self::assertSame('yielded', $outcome->data()['result']['error']);
        self::assertSame(0, $system->sleeps);
    }

    public function test_finalize_maps_yielded_to_busy_with_resume_at(): void {
        $system = $this->seed_progress('finalized', time() - 31 * 86400, array(Finalize::PROGRESS => array(
            'removal' => array('phase' => 'table', 'cursor' => 0), 'removed' => array(), 'kept' => array(), 'reported' => array(), 'pending' => true,
            'transitioned_at' => 5000, 'pass' => 1, 'found' => 0, 'settled' => false,
        )));

        $outcome = $this->onboarding()->finalize(false, $system);

        self::assertSame(array(Outcome::BUSY, 'yielded'), array($outcome->code(), $outcome->reason()));
        self::assertSame(5120, $outcome->data()['resume_at']);
        self::assertSame(0, $system->sleeps);
    }

    public function test_the_exit_maps_list_yielded_as_busy(): void {
        self::assertSame(Outcome::BUSY, Onboarding::ROLLBACK_EXIT['yielded']);
        self::assertSame(Outcome::BUSY, Onboarding::FINALIZE_EXIT['yielded']);
    }

    // --- stale claims. ---

    public function test_release_stale_claims_with_consent_and_none_releases_nothing(): void {
        $onboarding = $this->onboarding();
        self::assertSame(array(), $onboarding->stale_claims());
        $outcome = $onboarding->release_stale_claims(true);
        self::assertSame(array(Outcome::OK, 'released 0, kept 0'), array($outcome->code(), $outcome->message()));
    }

    public function test_release_stale_claims_without_consent_releases_nothing(): void {
        $this->claims->add(str_repeat('a', 64), 'claimed', 'owner-a', $this->system->now - 3600);
        $releases = 0;
        $this->claims->on_release = static function () use (&$releases) {
            ++$releases;
        };

        $outcome = $this->onboarding()->release_stale_claims(false);

        self::assertSame(array(Outcome::ERROR, 'consent_required'), array($outcome->code(), $outcome->reason()));
        self::assertSame(0, $releases, 'release() is never called');
        self::assertSame(array(str_repeat('a', 64)), array_keys($this->claims->rows));
    }

    public function test_the_cli_releases_nothing_when_its_report_was_empty_even_if_a_claim_turns_stale_meanwhile(): void {
        // Not stale at the CLI's report; stale from the next clock read on.
        $this->claims->add(str_repeat('a', 64), 'claimed', 'owner-a', $this->system->now - StaleClaims::STALE_SECONDS + 5);
        $reads = 0;
        $system = $this->system;
        $this->system->on_now = static function () use (&$reads, $system) {
            if (++$reads === 2) {
                $system->now += 10;
            }
        };
        $releases = 0;
        $this->claims->on_release = static function () use (&$releases) {
            ++$releases;
        };
        $out = array();
        $questions = array();
        $cli = new CliCommands(
            static function () {
                return array();
            },
            $this->store,
            $this->system,
            $this->claims,
            static function ($line) use (&$out) {
                $out[] = (string) $line;
            },
            static function ($question) use (&$questions) {
                $questions[] = (string) $question;
                return false;
            }
        );

        self::assertSame(CliCommands::OK, $cli->release_stale_claims(false));

        self::assertSame(array('Success: no stale claims'), $out);
        self::assertSame(array(), $questions, 'nothing to confirm was observed');
        self::assertSame(0, $releases, 'release() is never called');
        self::assertSame(array(), $this->claims->released);
        self::assertSame(array(str_repeat('a', 64)), array_keys($this->claims->rows));
    }
}
