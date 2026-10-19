<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;
use Simplixi\SUPCheckout\Takeover\Onboarding\Checks;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\Onboarding\StaleObjectCache;

/**
 * 0c Task 7: the onboarding and setup routes end to end on the REST stubs: they validate,
 * start one single-flight operation and enqueue it, or report a bounded status. They never
 * run long work inline and never return raw engine results.
 */
final class OnboardingControllerTest extends RestTestCase {
    use StaleObjectCache;

    private const SECRET = 'sk-test-SECRET-0c';

    private static function drive_actions(): array {
        return self::scheduled(AsyncDriver::HOOK);
    }

    // --- switch. ---

    public function test_switch_starts_an_operation_202_with_location_and_one_action(): void {
        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        $op = self::body($response)['data']['operation'];
        self::assertSame(array('switch', 'running'), array($op['kind'], $op['outcome']));
        self::assertSame(Operation::current()['id'], $op['id']);
        self::assertArrayNotHasKey('user_id', $op);
        self::assertSame('https://shop.test/wp-json/supcheckout/v1/onboarding/status', $response->get_headers()['Location']);
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);
        self::assertCount(1, self::drive_actions());
        self::assertSame(array($op['id']), self::drive_actions()[0]['args']);
        self::assertSame(7, Operation::current()['user_id']);
        self::assertSame(TakeoverState::IDLE, self::takeover_status(), 'nothing ran inline');
    }

    public function test_switch_requires_confirm_true(): void {
        foreach (array(array(), array('confirm' => false), array('confirm' => 'false'), array('deactivate_official' => true)) as $json) {
            $response = self::serve('POST', '/onboarding/switch', $json);
            self::assertSame(array(400, 'supcheckout_confirmation_required'), array($response->get_status(), self::error_code($response)), (string) json_encode($json));
        }
        self::assertNull(Operation::current(), 'no operation created');
        self::assertSame(array(), self::drive_actions());
        self::assertSame(202, self::serve('POST', '/onboarding/switch', array('confirm' => true))->get_status(), 'no rate slot was spent by the refusals');
    }

    public function test_switch_string_false_consent_never_deactivates(): void {
        // Review Focus 5.
        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => 'false'));

        self::assertSame(202, $response->get_status());
        self::assertFalse(Operation::current()['consent']['deactivate_official']);
        self::assertSame(array('deactivate_official' => false, 'reactivate_official' => false, 'force_after_review' => false), self::body($response)['data']['operation']['consent']);
    }

    public function test_switch_consent_true_is_recorded(): void {
        self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true));

        self::assertTrue(Operation::current()['consent']['deactivate_official']);
    }

    public function test_a_consent_flag_that_is_not_a_boolean_is_400(): void {
        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => 'yes'));

        self::assertSame(array(400, 'rest_invalid_param'), array($response->get_status(), self::error_code($response)));
        self::assertNull(Operation::current());
    }

    public function test_switch_on_a_cut_over_store_is_200_already_cut_over(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(200, $response->get_status());
        self::assertSame('already_cut_over', self::body($response)['data']['result']);
        self::assertNull(Operation::current());
    }

    public function test_switch_refused_by_the_plan_maps_its_outcome(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);
        $this->system->official = 'upayments/UPayments.php';

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(array(409, 'supcheckout_official_still_active'), array($response->get_status(), self::error_code($response)));
        self::assertSame(array('status' => 409, 'exit_code' => 7, 'reason' => 'official_still_active'), self::body($response)['data']);
        self::assertNull(Operation::current());
    }

    public function test_a_failed_enqueue_leaves_no_running_operation_and_returns_503(): void {
        \Action_Scheduler::$initialized = false;

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(array(503, 'supcheckout_scheduler_unavailable'), array($response->get_status(), self::error_code($response)));
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);
        $failed = Operation::current();
        self::assertSame(array('failed', 'scheduler_unavailable'), array($failed['outcome'], $failed['error']));
        self::assertSame($failed['id'], self::body($response)['data']['operation_id']);

        \Action_Scheduler::$initialized = true;
        $retry = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(202, $retry->get_status(), 'the rate slot was released');
        self::assertNotSame($failed['id'], self::body($retry)['data']['operation']['id']);
    }

    public function test_a_throwing_enqueue_leaves_no_running_operation_and_returns_503(): void {
        // Action Scheduler throws "Error saving action" when its store write fails.
        $GLOBALS['supcheckout_test_as_schedule_throws'] = true;

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(array(503, 'supcheckout_scheduler_unavailable'), array($response->get_status(), self::error_code($response)), (string) json_encode($response->get_data()));
        self::assertStringNotContainsString('Error saving action', (string) wp_json_encode($response->get_data()));
        $failed = Operation::current();
        self::assertSame(array('failed', 'scheduler_unavailable'), array($failed['outcome'], $failed['error']));
        self::assertSame($failed['id'], self::body($response)['data']['operation_id']);
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);

        $GLOBALS['supcheckout_test_as_schedule_throws'] = false;
        $retry = self::serve('POST', '/onboarding/switch', array('confirm' => true));

        self::assertSame(202, $retry->get_status(), 'the rate slot was released and nothing is left running');
        self::assertNotSame($failed['id'], self::body($retry)['data']['operation']['id']);
    }

    public function test_status_renders_the_runner_while_the_watchdog_throws(): void {
        Operation::start(Operation::KIND_SWITCH, array(), 1, time() - 120);
        $GLOBALS['supcheckout_test_as_schedule_throws'] = true;

        $response = self::serve('GET', '/onboarding/status');

        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));
        $data = self::body($response)['data'];
        self::assertTrue($data['runner']['stalled']);
        self::assertNotSame('', $data['runner']['hint']);
        self::assertSame('running', $data['operation']['outcome']);
    }

    public function test_finalize_with_a_boolean_confirm_is_400_invalid_param(): void {
        self::state(TakeoverState::CUT_OVER, time() - 40 * 86400);

        $response = self::serve('POST', '/onboarding/finalize', array('confirm' => true));

        self::assertSame(array(400, 'rest_invalid_param'), array($response->get_status(), self::error_code($response)));
        self::assertNull(Operation::current());
    }

    public function test_a_second_start_is_429_then_another_kind_is_409(): void {
        // Review Focus 4: a double click, or two admins clicking Switch.
        self::state(TakeoverState::VERIFIED);
        $first = self::serve('POST', '/onboarding/switch', array('confirm' => true));
        $second = self::serve('POST', '/onboarding/switch', array('confirm' => true));
        $abort = self::serve('POST', '/onboarding/abort', array('confirm' => true));

        self::assertSame(202, $first->get_status());
        self::assertSame(array(429, 'supcheckout_rate_limited'), array($second->get_status(), self::error_code($second)));
        self::assertSame(10, self::body($second)['data']['retry_after']);
        self::assertSame('10', $second->get_headers()['Retry-After']);
        self::assertSame(array(409, 'supcheckout_operation_in_progress'), array($abort->get_status(), self::error_code($abort)));
        self::assertSame(self::body($first)['data']['operation']['id'], self::body($abort)['data']['operation_id']);
        self::assertSame('switch', Operation::current()['kind'], 'exactly one operation');
        self::assertCount(1, self::drive_actions());
    }

    public function test_a_start_runs_the_watchdog_for_a_stalled_operation(): void {
        $op = Operation::start(Operation::KIND_SETUP_CHECKS, array(), 1, time() - 600);
        self::assertTrue(Operation::update($op['id'], array('next_at' => time() - 300), time() - 300));

        $response = self::serve('POST', '/onboarding/preflight');

        self::assertSame(409, $response->get_status());
        self::assertSame(array(array($op['id'])), array_column(self::drive_actions(), 'args'), 'the stalled operation was re-queued');
    }

    // --- rollback / abort. ---

    public function test_rollback_records_both_consents(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);

        $response = self::serve('POST', '/onboarding/rollback', array('confirm' => true, 'deactivate_official' => true, 'reactivate_official' => true));

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertSame(array('rollback', true, true), array(Operation::current()['kind'], Operation::current()['consent']['deactivate_official'], Operation::current()['consent']['reactivate_official']));
    }

    public function test_abort_from_the_wrong_state_is_409_state(): void {
        $response = self::serve('POST', '/onboarding/abort', array('confirm' => true, 'reactivate_official' => true));

        self::assertSame(array(409, 'supcheckout_state', 6), array($response->get_status(), self::error_code($response), self::body($response)['data']['exit_code']));
        self::assertNull(Operation::current());
    }

    public function test_abort_does_not_accept_deactivate_official(): void {
        self::state(TakeoverState::VERIFIED);

        $response = self::serve('POST', '/onboarding/abort', array('confirm' => true, 'deactivate_official' => true));

        self::assertSame(array(400, 'supcheckout_unknown_parameter'), array($response->get_status(), self::error_code($response)));
    }

    // --- finalize. ---

    public function test_finalize_requires_the_typed_FINALIZE_unless_dry_run(): void {
        self::state(TakeoverState::CUT_OVER, time() - 40 * 86400);
        foreach (array(array(), array('force_after_review' => true), array('dry_run' => false)) as $json) {
            $response = self::serve('POST', '/onboarding/finalize', $json);
            self::assertSame(array(400, 'supcheckout_confirmation_required'), array($response->get_status(), self::error_code($response)), (string) json_encode($json));
        }
        self::assertNull(Operation::current(), 'no operation for the 400s');

        $dry = self::serve('POST', '/onboarding/finalize', array('dry_run' => true));

        self::assertSame(202, $dry->get_status(), (string) json_encode($dry->get_data()));
        self::assertSame('finalize_dry_run', self::body($dry)['data']['operation']['kind']);
    }

    public function test_finalize_confirm_must_be_exactly_FINALIZE(): void {
        $response = self::serve('POST', '/onboarding/finalize', array('confirm' => 'finalize'));

        self::assertSame(array(400, 'rest_invalid_param'), array($response->get_status(), self::error_code($response)));
    }

    public function test_finalize_before_30_days_without_force_is_409_state_with_days_left(): void {
        self::state(TakeoverState::CUT_OVER, time());

        $response = self::serve('POST', '/onboarding/finalize', array('confirm' => 'FINALIZE'));

        self::assertSame(array(409, 'supcheckout_state'), array($response->get_status(), self::error_code($response)));
        $data = self::body($response)['data'];
        self::assertSame(array('too_early', 30, 6), array($data['reason'], $data['days_left'], $data['exit_code']));
        self::assertNull(Operation::current());
    }

    public function test_finalize_with_force_after_review_starts_with_that_consent(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);

        $response = self::serve('POST', '/onboarding/finalize', array('confirm' => 'FINALIZE', 'force_after_review' => true));

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertSame(array('finalize', true), array(Operation::current()['kind'], Operation::current()['consent']['force_after_review']));
    }

    // --- stale claims. ---

    public function test_stale_claims_release_runs_inline_with_explicit_consent(): void {
        $this->claims->add('cycle-a', 'claimed', 'owner-token-SECRET', time() - 7200);

        $refused = self::serve('POST', '/onboarding/stale-claims/release', array());
        $released = self::serve('POST', '/onboarding/stale-claims/release', array('confirm' => true));

        self::assertSame(array(400, 'supcheckout_confirmation_required'), array($refused->get_status(), self::error_code($refused)));
        self::assertSame(array('cycle-a'), $this->claims->released);
        self::assertSame(200, $released->get_status(), (string) json_encode($released->get_data()));
        self::assertSame(array('cycle-a'), self::body($released)['data']['released']);
        self::assertStringNotContainsString('SECRET', (string) wp_json_encode($released->get_data()));
        self::assertNull(Operation::current(), 'inline, no operation');
    }

    // --- status and reports. ---

    public function test_status_reports_takeover_operation_runner_and_last_runs(): void {
        update_option(DriverStep::last_report_option('self_test'), array('ok' => true, 'ran_at' => 1700000000, 'checks' => array()), false);
        update_option(DriverStep::last_report_option('preflight'), array('blocking' => array(), 'warnings' => array(), 'facts' => array(), 'ran_at' => 1700000100), false);

        $response = self::serve('GET', '/onboarding/status', null, false);

        self::assertSame(200, $response->get_status());
        $data = self::body($response)['data'];
        self::assertSame(array('takeover', 'operation', 'runner', 'last'), array_keys($data));
        self::assertSame('idle', $data['takeover']['status']);
        self::assertNull($data['operation']);
        self::assertFalse($data['runner']['stalled']);
        self::assertSame(array('preflight_at' => 1700000100, 'self_test_at' => 1700000000, 'setup_checks_at' => null), $data['last']);
    }

    public function test_status_reports_a_stalled_runner_with_a_hint(): void {
        // Review Focus 1: WP-Cron disabled and only REST polling.
        \Action_Scheduler::$initialized = false;
        Operation::start(Operation::KIND_PREFLIGHT, array(), 1, time() - 120);

        $runner = self::body(self::serve('GET', '/onboarding/status'))['data']['runner'];

        self::assertTrue($runner['stalled']);
        self::assertGreaterThan(60, $runner['past_due_seconds']);
        self::assertNotSame('', $runner['hint']);
    }

    public function test_the_stalled_hint_asks_for_a_lasting_runner_and_names_the_status_command(): void {
        // One `wp action-scheduler run` advances a single tick; a switch needs many, so the
        // hint asks for WP-Cron (or a system cron for wp-cron.php) and names the status command.
        \Action_Scheduler::$initialized = false;
        Operation::start(Operation::KIND_SWITCH, array(), 1, time() - 120);

        $hint = (string) self::body(self::serve('GET', '/onboarding/status'))['data']['runner']['hint'];

        self::assertStringContainsString('enable WP-Cron', $hint);
        self::assertStringContainsString('wp-cron.php', $hint);
        self::assertStringContainsString('wp supcheckout takeover status', $hint);
        self::assertStringNotContainsString('wp action-scheduler run', $hint, 'one queue run does not complete an operation');
    }

    public function test_status_never_contains_the_api_key(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => self::SECRET);
        $GLOBALS['supcheckout_test_options']['supcheckout_settings'] = array('enabled' => 'yes', 'api_key' => self::SECRET);
        self::serve('POST', '/onboarding/switch', array('confirm' => true));

        $response = self::serve('GET', '/onboarding/status');

        self::assertFalse(strpos((string) wp_json_encode($response->get_data()), self::SECRET));
    }

    public function test_status_runs_the_watchdog(): void {
        $op = Operation::start(Operation::KIND_SWITCH, array(), 1, time() - 600);
        self::assertTrue(Operation::update($op['id'], array('next_at' => time() - 120), time() - 120));
        self::assertSame(array(), self::drive_actions());

        self::serve('GET', '/onboarding/status');

        self::assertCount(1, self::drive_actions());
        self::assertSame(array($op['id']), self::drive_actions()[0]['args']);
    }

    public function test_status_body_is_at_most_32768_bytes(): void {
        $mismatches = array();
        for ($i = 0; $i < 3000; $i++) {
            $mismatches['group_' . $i] = array('count' => 1, 'keys' => array_fill(0, 25, str_repeat('k', 20)));
        }
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array(), 'mismatches' => $mismatches, 'anomalies' => $mismatches));
        TakeoverState::flush_cache();
        $op = Operation::start(Operation::KIND_SWITCH, array(), 1, time());
        $report = array();
        for ($i = 0; $i < 300; $i++) {
            $report['entry_' . $i] = str_repeat('r', 400);
        }
        $steps = array();
        for ($i = 0; $i < 60; $i++) {
            $steps[] = str_repeat('s', 500);
        }
        self::assertTrue(Operation::update($op['id'], array('report' => $report, 'self_test' => $report, 'steps' => $steps, 'next_at' => time() + 60), time()));
        self::assertGreaterThan(Bounded::MAX_BYTES, strlen((string) get_option(Operation::OPTION)), 'the stored record alone exceeds the limit');

        $response = self::serve('GET', '/onboarding/status');

        self::assertSame(200, $response->get_status());
        self::assertLessThanOrEqual(Bounded::MAX_BYTES, strlen((string) wp_json_encode($response->get_data())));
    }

    public function test_preflight_get_returns_the_last_report_or_null(): void {
        self::assertSame(array('report' => null), self::body(self::serve('GET', '/onboarding/preflight'))['data']);

        update_option(DriverStep::last_report_option('preflight'), array('blocking' => array(array('code' => 'callback_unreachable', 'message' => 'm', 'data' => array())), 'warnings' => array(), 'facts' => array()), false);

        self::assertSame('callback_unreachable', self::body(self::serve('GET', '/onboarding/preflight'))['data']['report']['blocking'][0]['code']);
    }

    public function test_stored_reports_are_read_past_a_stale_object_cache(): void {
        $option = DriverStep::last_report_option('preflight');
        update_option($option, array('blocking' => array(), 'warnings' => array(), 'facts' => array(), 'ran_at' => 1700000100), false);
        self::plant_stale_cache_entry($option, null);
        self::assertNull(get_option($option, null), 'the planted cache entry is stale');

        self::assertSame(1700000100, self::body(self::serve('GET', '/onboarding/preflight'))['data']['report']['ran_at']);
        self::assertSame(1700000100, self::body(self::serve('GET', '/onboarding/status'))['data']['last']['preflight_at']);
    }

    public function test_preflight_start_uses_the_shared_provider_probe_bucket(): void {
        $preflight = self::serve('POST', '/onboarding/preflight');
        Operation::finish(Operation::current()['id'], Operation::DONE, null, null, array(), time());
        $checks = self::serve('POST', '/setup/checks');

        self::assertSame(202, $preflight->get_status());
        self::assertSame('preflight', self::body($preflight)['data']['operation']['kind']);
        self::assertSame(array(429, 'supcheckout_rate_limited'), array($checks->get_status(), self::error_code($checks)));
        self::assertGreaterThanOrEqual(64, self::body($checks)['data']['retry_after']);
    }

    // --- self-test and setup checks. ---

    public function test_self_test_is_409_state_unless_switched(): void {
        $refused = self::serve('POST', '/onboarding/self-test');

        self::assertSame(array(409, 'supcheckout_state'), array($refused->get_status(), self::error_code($refused)));
        self::assertNull(Operation::current());
    }

    public function test_self_test_starts_on_a_cut_over_store(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);

        $response = self::serve('POST', '/onboarding/self-test');

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertSame('self_test', self::body($response)['data']['operation']['kind']);
    }

    public function test_the_switch_self_test_reads_back_from_get_self_test(): void {
        self::state(TakeoverState::CUT_OVER, time() - 60);
        $op = Operation::start(Operation::KIND_SWITCH, array(), 7, time());
        self::assertNotNull($op);
        $report = Checks::report(array(Checks::check('event_journal', Checks::DEFERRED, 'event_journal_not_built', 'later')), 1700000200);
        $runner = static function () use ($report) {
            return $report;
        };
        $driver = new DriverStep($this->onboarding(), $this->system, $runner, $runner, 'time');

        $result = $driver->tick(array_merge($op, array('phase' => 'self_test')));

        self::assertSame('done', $result['outcome']);
        $read = self::body(self::serve('GET', '/onboarding/self-test'))['data']['report'];
        self::assertIsArray($read, 'GET /onboarding/self-test shows the self-test of the switch');
        self::assertSame(1700000200, $read['ran_at']);
        self::assertSame($result['report']['self_test']['checks'], $read['checks']);
        self::assertSame(1700000200, self::body(self::serve('GET', '/onboarding/status'))['data']['last']['self_test_at']);
    }

    public function test_setup_checks_start_and_the_reports_read_back(): void {
        $start = self::serve('POST', '/setup/checks');
        update_option(DriverStep::last_report_option('setup_checks'), array('ok' => true, 'ran_at' => 5, 'checks' => array()), false);

        self::assertSame(202, $start->get_status());
        self::assertSame('setup_checks', self::body($start)['data']['operation']['kind']);
        self::assertSame(5, self::body(self::serve('GET', '/setup/checks'))['data']['report']['ran_at']);
        self::assertSame(array('report' => null), self::body(self::serve('GET', '/onboarding/self-test'))['data']);
    }

    public function test_an_exception_becomes_a_generic_500_without_its_message(): void {
        \supcheckout_test_reset_rest();
        \supcheckout_test_reset_subscription_composition();
        \Simplixi\SUPCheckout\Rest\Routes::register_routes(false, static function () {
            throw new \RuntimeException('db password is hunter2');
        });

        $responses = array(
            self::serve('GET', '/onboarding/status'),
            self::serve('POST', '/onboarding/switch', array('confirm' => true)),
            self::serve('POST', '/onboarding/stale-claims/release', array('confirm' => true)),
        );

        foreach ($responses as $response) {
            self::assertSame(array(500, 'supcheckout_error'), array($response->get_status(), self::error_code($response)));
            self::assertStringNotContainsString('hunter2', (string) wp_json_encode($response->get_data()));
            self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);
        }
        self::assertNull(Operation::current());
    }

    // --- every response. ---

    public function test_every_response_is_no_store(): void {
        self::state(TakeoverState::VERIFIED);
        $responses = array(
            '200' => self::serve('GET', '/onboarding/status'),
            '202' => self::serve('POST', '/onboarding/switch', array('confirm' => true)),
            '429' => self::serve('POST', '/onboarding/switch', array('confirm' => true)),
            '409' => self::serve('POST', '/onboarding/abort', array('confirm' => true)),
            '400 unknown parameter' => self::serve('POST', '/onboarding/rollback', array('confirm' => true, 'x' => 1)),
            '400 rest_invalid_param' => self::serve('POST', '/onboarding/rollback', array('confirm' => 'x')),
            '400 confirmation' => self::serve('POST', '/onboarding/rollback', array()),
            '403 nonce' => self::serve('POST', '/onboarding/rollback', array('confirm' => true), false),
        );
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        $responses['403 capability'] = self::serve('GET', '/onboarding/status');
        $GLOBALS['supcheckout_test_status_logged_in'] = false;
        $responses['401'] = self::serve('GET', '/onboarding/status');

        foreach ($responses as $label => $response) {
            self::assertSame((int) $label, $response->get_status(), $label . ' ' . (string) json_encode($response->get_data()));
            self::assertSame('no-store, private', $response->get_headers()['Cache-Control'], $label);
            self::assertLessThanOrEqual(Bounded::MAX_BYTES, strlen((string) wp_json_encode($response->get_data())), $label);
        }
    }

    private static function takeover_status(): string {
        TakeoverState::refresh();
        return (string) TakeoverState::get()['status'];
    }

    public function test_rate_limiter_windows_are_the_contract(): void {
        self::assertSame(array(10, 65), array(RateLimiter::WRITE_WINDOW, RateLimiter::PROBE_WINDOW));
    }
}
