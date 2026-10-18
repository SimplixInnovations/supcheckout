<?php

namespace Simplixi\SUPCheckout\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;
use Simplixi\SUPCheckout\Takeover\Rollback;

defined('ABSPATH') || exit;

/**
 * The takeover routes of supcheckout/v1 (Sub-project 0c Task 7). They validate, start one
 * single-flight Operation and hand it to Action Scheduler, or report a bounded status;
 * they call nothing but Onboarding, Operation and AsyncDriver, and never run long work
 * inline (stale-claims/release excepted: at most 500 rows, under the lease).
 *
 * A start, after RequestGuard's checks and the rate slot: the cheap synchronous plan
 * (plan_run / plan_rewind / plan_finalize) → Operation::start() (null: 409
 * supcheckout_operation_in_progress) → AsyncDriver::enqueue(): true is 202 with the
 * operation and a Location to the status route; false or a throw finishes the operation `failed` /
 * `scheduler_unavailable` (a compare-and-swap on the record just created, so no running
 * operation is left without an action), gives the rate slot back and answers 503.
 *
 * Responses carry allow-listed projections only: never the user ID, never Outcome::data()'s
 * raw engine results, never a setting (the API key is write-only and never read here).
 */
final class OnboardingController {
    public const HINT_SCHEDULER = 'Action Scheduler is not available; retry, or use WP-CLI (wp supcheckout takeover).';
    /** A \Throwable is answered with this and its message is never sent (it may hold anything). */
    private const MESSAGE_UNEXPECTED = 'The request failed unexpectedly; check the status, then retry.';
    private const HINT_STALLED = 'Action Scheduler has not run the operation on time: WP-Cron may be disabled. Open any wp-admin page, run `wp action-scheduler run`, or enable WP-Cron.';

    /** Operation record fields a response may carry. */
    private const OPERATION_FIELDS = array('id', 'kind', 'outcome', 'phase', 'consent', 'started_at', 'updated_at', 'next_at', 'error', 'hint', 'steps', 'attempts', 'busy_retries', 'cooldown_retries', 'report', 'self_test');

    /** Onboarding::status() fields a response may carry. */
    private const STATUS_FIELDS = array('status', 'cut_over_at', 'rolled_back_at', 'rollback', 'finalize', 'phase', 'cursor', 'cursors', 'totals', 'mismatches', 'anomalies', 'journal', 'quiesced', 'official_plugin', 'stale_claims', 'warnings');

    /** Stored check reports, by `last` field. */
    private const REPORTS = array(
        'preflight_at' => Operation::KIND_PREFLIGHT,
        'self_test_at' => Operation::KIND_SELF_TEST,
        'setup_checks_at' => Operation::KIND_SETUP_CHECKS,
    );

    /** @var callable function (): Onboarding */
    private $factory;

    /** @param callable|null $factory function (): Onboarding; Onboarding::from_wordpress() by default. */
    public function __construct($factory = null) {
        $this->factory = is_callable($factory) ? $factory : array(Onboarding::class, 'from_wordpress');
    }

    // --- Reads. ---

    /**
     * GET /onboarding/status. Runs the watchdog first: a poll re-queues an operation that is
     * due with nothing pending (Action Scheduler never dispatches its runner from REST).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function status(\WP_REST_Request $request) {
        unset($request);
        try {
            return $this->status_response();
        } catch (\Throwable $e) {
            return self::unexpected();
        }
    }

    /** @return \WP_REST_Response */
    private function status_response() {
        // Best effort: a scheduler that throws must not hide runner.stalled from the poll.
        self::run_watchdog();
        $op = Operation::current();
        $runner = AsyncDriver::runner_health($op, time());
        if ($runner['stalled']) {
            $runner['hint'] = self::HINT_STALLED;
        }
        $last = array();
        foreach (self::REPORTS as $field => $kind) {
            $report = self::stored_report($kind);
            $last[$field] = is_array($report) && isset($report['ran_at']) && is_int($report['ran_at']) ? $report['ran_at'] : null;
        }
        return Responses::ok(
            array(
                'takeover' => Bounded::document(Responses::cap(Responses::project($this->onboarding()->status(), self::STATUS_FIELDS))),
                'operation' => self::operation_view($op),
                'runner' => $runner,
                'last' => $last,
            )
        );
    }

    /**
     * GET /onboarding/preflight.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_preflight(\WP_REST_Request $request) {
        unset($request);
        return self::report_response(Operation::KIND_PREFLIGHT);
    }

    // --- Starts. ---

    /**
     * POST /onboarding/preflight.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_preflight(\WP_REST_Request $request) {
        return $this->start($request, '/onboarding/preflight', 'provider_probe', Operation::KIND_PREFLIGHT, array());
    }

    /**
     * POST /onboarding/switch: 202, 200 `already_cut_over`, or the plan's refusal.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_switch(\WP_REST_Request $request) {
        $deactivate = self::flag($request, 'deactivate_official');
        return $this->start($request, '/onboarding/switch', 'switch', Operation::KIND_SWITCH, array('deactivate_official' => $deactivate), function () use ($deactivate) {
            $plan = $this->onboarding()->plan_run($deactivate);
            if ($plan->is_ok() && isset($plan->data()['next']) && $plan->data()['next'] === 'done') {
                return Responses::ok(array('result' => 'already_cut_over', 'status' => $plan->data()['status'], 'message' => $plan->message()));
            }
            return $plan;
        });
    }

    /**
     * POST /onboarding/rollback.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_rollback(\WP_REST_Request $request) {
        $consent = array('deactivate_official' => self::flag($request, 'deactivate_official'), 'reactivate_official' => self::flag($request, 'reactivate_official'));
        return $this->start($request, '/onboarding/rollback', 'rollback', Operation::KIND_ROLLBACK, $consent, function () {
            return $this->onboarding()->plan_rewind(Rollback::MODE_ROLLBACK);
        });
    }

    /**
     * POST /onboarding/abort.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_abort(\WP_REST_Request $request) {
        $consent = array('reactivate_official' => self::flag($request, 'reactivate_official'));
        return $this->start($request, '/onboarding/abort', 'abort', Operation::KIND_ABORT, $consent, function () {
            return $this->onboarding()->plan_rewind(Rollback::MODE_ABORT);
        });
    }

    /**
     * POST /onboarding/finalize: a dry run (read-only plan, like the CLI's --dry-run, which
     * reports its would-refuse reasons) or the irreversible finalize after plan_finalize().
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_finalize(\WP_REST_Request $request) {
        if (self::flag($request, 'dry_run')) {
            return $this->start($request, '/onboarding/finalize', 'finalize', Operation::KIND_FINALIZE_DRY_RUN, array());
        }
        $force = self::flag($request, 'force_after_review');
        return $this->start($request, '/onboarding/finalize', 'finalize', Operation::KIND_FINALIZE, array('force_after_review' => $force), function () use ($force) {
            return $this->onboarding()->plan_finalize($force);
        });
    }

    /**
     * POST /onboarding/stale-claims/release: inline, at most 500 rows under the lease, with
     * the consent the guard validated.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function release_stale_claims(\WP_REST_Request $request) {
        $route = '/onboarding/stale-claims/release';
        $refused = $this->admit($request, $route, 'stale_claims', time());
        if ($refused !== null) {
            return $refused;
        }
        try {
            return Responses::from_outcome($this->onboarding()->release_stale_claims(self::flag($request, 'confirm')));
        } catch (\Throwable $e) {
            return self::unexpected();
        }
    }

    /**
     * The start pipeline every operation route shares (SetupController too).
     *
     * @param \WP_REST_Request     $request Request.
     * @param string               $route   Route relative to the namespace.
     * @param string               $bucket  Rate bucket.
     * @param string               $kind    Operation kind.
     * @param array<string, bool>  $consent Consent from validated flags.
     * @param callable|null        $plan    function (): Outcome|\WP_REST_Response, the cheap synchronous plan; a response ends the start.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start(\WP_REST_Request $request, $route, $bucket, $kind, array $consent, $plan = null) {
        $now = time();
        $refused = $this->admit($request, $route, $bucket, $now);
        if ($refused !== null) {
            return $refused;
        }
        try {
            $planned = $plan === null ? Outcome::ok('no plan') : call_user_func($plan);
            if ($planned instanceof \WP_REST_Response) {
                return $planned;
            }
            if (!$planned instanceof Outcome) {
                return self::unexpected();
            }
            if (!$planned->is_ok()) {
                return Responses::from_outcome($planned);
            }
            return $this->launch($kind, $consent, $bucket, $now);
        } catch (\Throwable $e) {
            // An operation already started stays running and the next status poll's watchdog
            // queues it (it is due since its start), so nothing is orphaned.
            return self::unexpected();
        }
    }

    /**
     * AsyncDriver::enqueue(), with a throw (Action Scheduler's "Error saving action" when its
     * store write fails) treated as not scheduled.
     *
     * @param string $op_id Operation ID.
     * @return bool True when an action is queued.
     */
    private static function enqueue($op_id) {
        try {
            return AsyncDriver::enqueue($op_id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return void AsyncDriver::watchdog(), best effort: a throwing scheduler is ignored here. */
    private static function run_watchdog() {
        try {
            AsyncDriver::watchdog();
        } catch (\Throwable $e) {
            // runner_health() still reports the operation stalled once it is past due.
            return;
        }
    }

    /** @return \WP_Error A generic 500: the exception's message is never sent. */
    private static function unexpected() {
        return Responses::error('supcheckout_error', self::MESSAGE_UNEXPECTED, 500);
    }

    /**
     * RequestGuard steps 2-3 (again: Routes::guard() may be removed by another plugin), 5
     * and 6.
     *
     * @param \WP_REST_Request $request Request.
     * @param string           $route   Route.
     * @param string           $bucket  Rate bucket.
     * @param int              $now     Unix time.
     * @return \WP_Error|null Null when admitted (the rate slot is then spent).
     */
    private function admit(\WP_REST_Request $request, $route, $bucket, $now) {
        $result = RequestGuard::check_write($request, array_keys(Routes::args($route)));
        if ($result === true) {
            $result = RequestGuard::check_consent($request, $route);
        }
        if ($result === true) {
            $result = RequestGuard::check_rate($bucket, Routes::window($bucket), $now);
        }
        return $result === true ? null : $result;
    }

    /**
     * @param string              $kind    Operation kind.
     * @param array<string, bool> $consent Consent.
     * @param string              $bucket  Rate bucket the start spent.
     * @param int                 $now     The time the slot was taken at.
     * @return \WP_REST_Response|\WP_Error
     */
    private function launch($kind, array $consent, $bucket, $now) {
        self::run_watchdog();
        try {
            $op = Operation::start($kind, $consent, get_current_user_id(), $now);
        } catch (\Throwable $e) {
            // random_bytes() failed: a refused start.
            $op = null;
        }
        if ($op === null) {
            $current = Operation::current();
            $running = Operation::is_running($current);
            return Responses::error(
                'supcheckout_operation_in_progress',
                $running ? 'Another onboarding operation is running; follow it on the status route.' : 'The operation could not be started; check the status route, then retry.',
                409,
                array('operation_id' => $running ? (string) $current['id'] : null, 'kind' => $running ? (string) $current['kind'] : null)
            );
        }
        if (!self::enqueue($op['id'])) {
            // enqueue() already finished it when it returned false (this compare-and-swap is
            // then a no-op); after a throw this finishes it, so nothing is left running.
            Operation::finish($op['id'], Operation::FAILED, 'scheduler_unavailable', self::HINT_SCHEDULER, array(), time());
            RateLimiter::release($bucket, $now + Routes::window($bucket));
            return Responses::error('supcheckout_scheduler_unavailable', 'The operation could not be scheduled; nothing was changed.', 503, array('operation_id' => (string) $op['id'], 'hint' => self::HINT_SCHEDULER));
        }
        $response = Responses::ok(array('operation' => self::operation_view($op)), 202);
        $response->header('Location', rest_url(Routes::NAMESPACE . '/onboarding/status'));
        return $response;
    }

    // --- Shared helpers. ---

    /**
     * @param array<string, mixed>|null $op A record from Operation.
     * @return array<string, mixed>|null The allow-listed, bounded record.
     */
    public static function operation_view($op) {
        if (!is_array($op)) {
            return null;
        }
        return Bounded::document(Responses::cap(Responses::project($op, self::OPERATION_FIELDS)));
    }

    /**
     * @param string $kind A check kind.
     * @return \WP_REST_Response 200 `{report}`, null when it never ran.
     */
    public static function report_response($kind) {
        $report = self::stored_report($kind);
        return Responses::ok(array('report' => is_array($report) ? Bounded::document(Responses::cap($report)) : null));
    }

    /**
     * @param string $kind A check kind.
     * @return array<array-key, mixed>|null
     */
    private static function stored_report($kind) {
        $report = get_option(DriverStep::last_report_option($kind), null);
        return is_array($report) ? $report : null;
    }

    /**
     * @param \WP_REST_Request $request Request.
     * @param string           $name    A boolean arg.
     * @return bool True only when core sanitized it to true (a string "false" is false).
     */
    public static function flag(\WP_REST_Request $request, $name) {
        return $request->get_param($name) === true;
    }

    /** @return Onboarding A fresh service per call: it holds no request state. */
    private function onboarding() {
        return call_user_func($this->factory);
    }
}
