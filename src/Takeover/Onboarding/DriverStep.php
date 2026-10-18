<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\BudgetedSystem;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

defined('ABSPATH') || exit;

/**
 * One bounded tick of the asynchronous onboarding driver (Sub-project 0c Task 6).
 *
 * State-driven: every tick re-reads TakeoverState and picks the next step from it and the
 * operation's phase, so a WP-CLI command run meanwhile only moves the state and the next
 * tick follows it. It calls only Onboarding (the takeover engines and read-only probes) and
 * the self-test / setup-check runners; it never marks, unmarks or re-verifies a payment.
 *
 * Phases: switch preflight → copy → retire → cutover → self_test; rollback and abort guard →
 * rewind; finalize; finalize_dry_run plan; preflight, self_test and setup_checks run once.
 * The official plugin is deactivated in its own tick (next_delay 0), so Cutover and a
 * rollback run in a fresh process where its code is not loaded.
 *
 * Long work (Cutover, the pre-transition part of Rollback and Finalize, the Finalize plan)
 * uses one attempt per entry, at most MAX_LONG_ATTEMPTS: a host that kills the tick leaves
 * the state as it was, and the driver re-enters at most that often (AsyncDriver stores the
 * attempt before the work starts). A held lease (`busy`) costs no attempt. BUSY outcomes
 * retry every BUSY_RETRY_DELAY seconds, at most MAX_BUSY_RETRIES in a row without new
 * progress; a provider cooldown retries every COOLDOWN_RETRY_DELAY seconds.
 *
 * Progress is the set of step names: a step an engine replays after a yield (for example
 * `copies_removed` or `legacy_swept` after the settle barrier) is not new progress, and the
 * transient steps `yielded` and `settling` never are.
 *
 * Any \Throwable ends the operation `failed` with error `error`, the exception's class name
 * only (never its message) and a restart hint. Reports go through Bounded::document().
 */
final class DriverStep {
    /** Seconds of copy work per tick (CopyRunner::ACTION_TIME_BUDGET). */
    public const TICK_BUDGET = 20;
    public const MAX_LONG_ATTEMPTS = 3;
    public const BUSY_RETRY_DELAY = 60;
    /** Quiesce::TTL / BUSY_RETRY_DELAY + 2: a dead holder's lease expires within them. */
    public const MAX_BUSY_RETRIES = 17;
    public const COOLDOWN_RETRY_DELAY = 65;
    public const MAX_COOLDOWN_RETRIES = 3;

    /** Option name prefix of the stored check reports: <prefix><kind>_v1. */
    public const LAST_REPORT_PREFIX = 'supcheckout_onboarding_last_';

    public const HINT_ERROR = 'restart the operation; every step resumes from the saved state';
    private const HINT_STATE = 'the takeover state changed meanwhile; check the status, then start again';
    private const HINT_BUSY = 'another takeover step holds the store; wait until it ends (at most 15 minutes), then start again';
    private const HINT_PREFLIGHT = 'fix the blocking preflight findings, then start again';
    private const HINT_OFFICIAL = 'deactivate the official UPayments plugin, or start again with deactivate_official';
    private const HINT_MISMATCH = 'review the mismatches; the store was not switched';

    /** Times the switch's self-test retries a provider cooldown. */
    private const SELF_TEST_COOLDOWN_RETRIES = 1;

    /** Engine errors that retry after BUSY_RETRY_DELAY. */
    private const BUSY_REASONS = array('busy', 'lease_lost', 'claims_in_flight', 'transition_refused', 'removal_busy', 'removal_unsettled');

    /** Steps that never count as progress. */
    private const TRANSIENT_STEPS = array('yielded', 'settling');

    /** First phase of each kind. */
    private const INITIAL = array(
        Operation::KIND_SWITCH => 'preflight',
        Operation::KIND_ROLLBACK => 'guard',
        Operation::KIND_ABORT => 'guard',
        Operation::KIND_FINALIZE => 'finalize',
        Operation::KIND_FINALIZE_DRY_RUN => 'plan',
        Operation::KIND_PREFLIGHT => Operation::KIND_PREFLIGHT,
        Operation::KIND_SELF_TEST => Operation::KIND_SELF_TEST,
        Operation::KIND_SETUP_CHECKS => Operation::KIND_SETUP_CHECKS,
    );

    /** @var Onboarding */
    private $onboarding;
    /** @var BudgetedSystem */
    private $system;
    /** @var callable */
    private $self_test;
    /** @var callable */
    private $setup_checks;
    /** @var callable */
    private $clock;
    /** @var array<string, mixed> The operation being advanced. */
    private $op = array();
    /** @var array<string, mixed> The tick result being built. */
    private $result = array();
    /** @var string|null Attempt counted in this tick. */
    private $counted = null;
    /** @var bool This tick is a busy retry. */
    private $busy = false;

    /**
     * @param Onboarding     $onboarding   Takeover decisions, bound to the budgeted system.
     * @param BudgetedSystem $system       The tick's budgeted system.
     * @param callable       $self_test    function (): array, SelfTest::run().
     * @param callable       $setup_checks function (): array, SetupChecks::run().
     * @param callable       $clock        function (): int.
     */
    public function __construct(Onboarding $onboarding, BudgetedSystem $system, $self_test, $setup_checks, $clock) {
        $this->onboarding = $onboarding;
        $this->system = $system;
        $this->self_test = $self_test;
        $this->setup_checks = $setup_checks;
        $this->clock = $clock;
    }

    /**
     * Advance the operation by one tick. A `next_delay` of null means terminal.
     *
     * @param array<string, mixed> $op A running record from Operation.
     * @return array{outcome:string, phase:string, steps:list<string>, error:?string, hint:?string, report:array<string,mixed>, next_delay:?int, long:bool, attempts:array<string,int>, busy_retries:int, cooldown_retries:int}
     */
    public function tick(array $op) {
        $this->op = $op;
        $this->counted = null;
        $this->busy = false;
        $this->result = array(
            'outcome' => Operation::RUNNING,
            'phase' => self::phase_of($op),
            'steps' => array(),
            'error' => null,
            'hint' => null,
            'report' => array(),
            'next_delay' => 0,
            'long' => false,
            'attempts' => isset($op['attempts']) && is_array($op['attempts']) ? $op['attempts'] : array(),
            'busy_retries' => isset($op['busy_retries']) ? (int) $op['busy_retries'] : 0,
            'cooldown_retries' => isset($op['cooldown_retries']) ? (int) $op['cooldown_retries'] : 0,
        );
        try {
            TakeoverState::refresh();
            $status = (string) TakeoverState::get()['status'];
            if ($status === TakeoverState::INVALID) {
                $this->state_changed($status);
            } else {
                $this->dispatch((string) $op['kind'], $status);
            }
        } catch (\Throwable $e) {
            $this->end(Operation::FAILED, 'error', self::HINT_ERROR, array('exception' => get_class($e)));
        }
        if (!$this->busy && self::new_steps(self::stored_steps($op), $this->result['steps']) !== array()) {
            $this->result['busy_retries'] = 0;
        }
        $this->result['report'] = Bounded::document($this->result['report']);
        return $this->result;
    }

    /**
     * What the next tick runs, without running it (AsyncDriver sets the watchdog, the time
     * limit and the stored attempt from it). Re-reads TakeoverState.
     *
     * @param array<string, mixed> $op A record from Operation.
     * @return array{phase:string, long:bool, attempt:?string}
     */
    public static function upcoming(array $op) {
        $phase = self::phase_of($op);
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        $long = null;
        switch (isset($op['kind']) ? $op['kind'] : '') {
            case Operation::KIND_SWITCH:
                if ($status === TakeoverState::CUT_OVER && in_array($phase, array('copy', 'retire', 'cutover'), true)) {
                    $phase = 'self_test';
                } elseif ($status === TakeoverState::VERIFIED && in_array($phase, array('retire', 'cutover'), true)) {
                    $long = 'cutover';
                }
                break;
            case Operation::KIND_ROLLBACK:
            case Operation::KIND_ABORT:
                if ($phase === 'rewind' && $status === self::rewind_long_status((string) $op['kind'])) {
                    $long = 'rewind';
                }
                break;
            case Operation::KIND_FINALIZE:
                if ($status === TakeoverState::CUT_OVER) {
                    $long = 'finalize';
                }
                break;
            case Operation::KIND_FINALIZE_DRY_RUN:
                $long = 'plan';
                break;
        }
        return array('phase' => $phase, 'long' => $long !== null, 'attempt' => $long);
    }

    /**
     * The fields AsyncDriver persists with Operation::update() after a tick: steps are the
     * stored ones plus every new, non-transient step of the tick, each once.
     *
     * @param array<string, mixed> $op     The record the tick advanced.
     * @param array<string, mixed> $result tick()'s result.
     * @return array<string, mixed>
     */
    public static function progress_fields(array $op, array $result) {
        $stored = self::stored_steps($op);
        return array(
            'phase' => (string) $result['phase'],
            'steps' => array_merge($stored, self::new_steps($stored, $result['steps'])),
            'attempts' => $result['attempts'],
            'busy_retries' => (int) $result['busy_retries'],
            'cooldown_retries' => (int) $result['cooldown_retries'],
            'error' => $result['error'],
            'hint' => $result['hint'],
            'report' => $result['report'],
        );
    }

    /**
     * @param string $kind A check kind.
     * @return string The non-autoloaded option holding its last report.
     */
    public static function last_report_option($kind) {
        return self::LAST_REPORT_PREFIX . $kind . '_v1';
    }

    // --- Dispatch. ---

    /**
     * @param string $kind   Operation kind.
     * @param string $status TakeoverState status.
     * @return void
     */
    private function dispatch($kind, $status) {
        switch ($kind) {
            case Operation::KIND_SWITCH:
                $this->switch_tick($status);
                return;
            case Operation::KIND_ROLLBACK:
            case Operation::KIND_ABORT:
                $this->rewind_tick($kind, $status);
                return;
            case Operation::KIND_FINALIZE:
                $this->finalize_tick($status);
                return;
            case Operation::KIND_FINALIZE_DRY_RUN:
                if ($this->count_attempt('plan')) {
                    $this->end(Operation::DONE, null, null, array('plan' => $this->onboarding->finalize_plan()));
                }
                return;
            case Operation::KIND_PREFLIGHT:
            case Operation::KIND_SELF_TEST:
            case Operation::KIND_SETUP_CHECKS:
                $this->check_tick($kind);
                return;
        }
        $this->state_changed($status);
    }

    // --- switch. ---

    /**
     * @param string $status TakeoverState status.
     * @return void
     */
    private function switch_tick($status) {
        $phase = $this->result['phase'];
        if ($status === TakeoverState::CUT_OVER && in_array($phase, array('copy', 'retire', 'cutover'), true)) {
            // Moved by the CLI, or a crash after the swap.
            $this->next('self_test', 0);
            return;
        }
        if ($phase === 'preflight') {
            $this->switch_preflight();
        } elseif ($phase === 'copy') {
            $this->switch_copy($status);
        } elseif ($phase === 'retire' && $status === TakeoverState::VERIFIED) {
            $this->switch_retire();
        } elseif ($phase === 'cutover' && $status === TakeoverState::VERIFIED) {
            $this->switch_cutover();
        } elseif ($phase === 'self_test' && in_array($status, array(TakeoverState::CUT_OVER, TakeoverState::FINALIZED), true)) {
            $this->switch_self_test();
        } else {
            $this->state_changed($status);
        }
    }

    /** @return void */
    private function switch_preflight() {
        $plan = $this->onboarding->plan_run($this->consent('deactivate_official'));
        if (!$plan->is_ok()) {
            $this->refused($plan);
            return;
        }
        $next = $plan->data()['next'];
        if ($next === 'done') {
            $this->end(Operation::DONE, null, null, array('message' => $plan->message()));
            return;
        }
        if ($next === 'cutover_tail') {
            $tail = $this->onboarding->cutover(true);
            $this->engine_steps($tail);
            if ($tail->is_ok()) {
                $this->end(Operation::DONE, null, null, self::outcome_report($tail));
                return;
            }
            $this->refused($tail);
            return;
        }
        $preflight = $this->onboarding->check_preflight();
        $report = $preflight->data()['report'];
        if (!$preflight->is_ok()) {
            if (self::only_cooldown($report) && $this->result['cooldown_retries'] < self::MAX_COOLDOWN_RETRIES) {
                ++$this->result['cooldown_retries'];
                $this->next('preflight', self::COOLDOWN_RETRY_DELAY, $report);
                return;
            }
            $this->end(Operation::BLOCKED, 'preflight_blocking', self::HINT_PREFLIGHT, $report);
            return;
        }
        $copy = $this->onboarding->start_copy();
        if (!$copy->is_ok()) {
            $this->refused($copy);
            return;
        }
        $this->result['cooldown_retries'] = 0;
        $this->add_steps(array('preflight_passed', 'copy_started'));
        $this->next('copy', 0, $report);
    }

    /**
     * @param string $status TakeoverState status.
     * @return void
     */
    private function switch_copy($status) {
        if ($status === TakeoverState::COPYING) {
            $deadline = $this->clock() + self::TICK_BUDGET;
            do {
                $step = $this->onboarding->copy_step();
            } while ($step === CopyRunner::CONTINUE_RUN && $this->clock() < $deadline);
            if ($step === CopyRunner::CONTINUE_RUN) {
                $this->next('copy', 0);
                return;
            }
            if ($step === CopyRunner::RETRY || $step === CopyRunner::BUSY) {
                $this->next('copy', self::BUSY_RETRY_DELAY);
                return;
            }
        }
        $result = $this->onboarding->copy_result();
        if ($result === null) {
            $this->next('copy', 0);
            return;
        }
        if ($result->is_ok()) {
            $this->add_steps(array('copy_verified'));
            $this->next('retire', 0);
            return;
        }
        if ($result->code() === Outcome::MISMATCH) {
            $data = $result->data();
            $this->end(Operation::FAILED, 'verify_mismatch', self::HINT_MISMATCH, array('mismatches' => isset($data['mismatches']) ? $data['mismatches'] : array()));
            return;
        }
        $this->state_changed($status);
    }

    /** @return void */
    private function switch_retire() {
        $retired = $this->onboarding->retire_official($this->consent('deactivate_official'), 'switch');
        if (!$retired->is_ok()) {
            $this->retire_refused($retired);
            return;
        }
        if ($retired->data()['deactivated'] !== null) {
            // Cutover runs in the next action: a fresh process without the official plugin's code.
            $this->add_steps(array('official_deactivated'));
            $this->next('cutover', 0);
            return;
        }
        $this->switch_cutover();
    }

    /** @return void */
    private function switch_cutover() {
        $this->result['phase'] = 'cutover';
        if (!$this->count_attempt('cutover')) {
            return;
        }
        $cutover = $this->onboarding->cutover($this->consent('deactivate_official'));
        $this->engine_steps($cutover);
        $report = self::outcome_report($cutover);
        if ($cutover->is_ok()) {
            $this->next('self_test', 0, $report);
            return;
        }
        $reason = (string) $cutover->reason();
        if (in_array($reason, self::BUSY_REASONS, true)) {
            $this->busy_retry('cutover', $reason, $report);
        } elseif ($reason === 'stale_claims') {
            $this->end(Operation::FAILED, 'stale_claims', $this->stale_claims_hint(), $report);
        } elseif ($reason === 'official_active') {
            $this->next('retire', 0, $report);
        } elseif ($cutover->code() === Outcome::OFFICIAL_STILL_ACTIVE) {
            $this->end(Operation::BLOCKED, 'official_still_active', self::HINT_OFFICIAL, $report);
        } elseif ($cutover->code() === Outcome::STATE) {
            TakeoverState::refresh();
            $status = (string) TakeoverState::get()['status'];
            if ($status === TakeoverState::CUT_OVER) {
                $this->next('self_test', 0, $report);
            } else {
                $this->state_changed($status);
            }
        } else {
            $this->end(Operation::FAILED, $reason, $cutover->code() === Outcome::MISMATCH ? self::HINT_MISMATCH : null, $report);
        }
    }

    /** @return void */
    private function switch_self_test() {
        $report = null;
        $exception = null;
        try {
            $report = call_user_func($this->self_test);
        } catch (\Throwable $e) {
            $exception = get_class($e);
        }
        if (is_array($report) && self::check_cooldown($report) && $this->result['cooldown_retries'] < self::SELF_TEST_COOLDOWN_RETRIES) {
            ++$this->result['cooldown_retries'];
            $this->next('self_test', self::COOLDOWN_RETRY_DELAY);
            return;
        }
        if (!is_array($report)) {
            // The store is switched: an unusable self-test is a warning, never the switch's failure.
            $report = array('ok' => false, 'ran_at' => $this->clock(), 'checks' => array(), 'error' => 'self_test_error', 'exception' => $exception);
        }
        $this->add_steps(array('self_test_ran'));
        $warnings = empty($report['ok']) ? array('self_test_failed') : array();
        $this->end(Operation::DONE, null, null, array('self_test' => $report, 'warnings' => $warnings));
    }

    // --- rollback / abort. ---

    /**
     * @param string $kind   KIND_ROLLBACK or KIND_ABORT.
     * @param string $status TakeoverState status.
     * @return void
     */
    private function rewind_tick($kind, $status) {
        $mode = $kind === Operation::KIND_ROLLBACK ? Rollback::MODE_ROLLBACK : Rollback::MODE_ABORT;
        $phase = $this->result['phase'];
        if ($phase === 'guard') {
            $plan = $this->onboarding->plan_rewind($mode);
            if (!$plan->is_ok()) {
                $this->refused($plan);
                return;
            }
            if (!empty($plan->data()['retire_official'])) {
                $retired = $this->onboarding->retire_official($this->consent('deactivate_official'), 'rollback');
                if (!$retired->is_ok()) {
                    $this->retire_refused($retired);
                    return;
                }
                if ($retired->data()['deactivated'] !== null) {
                    $this->add_steps(array('official_deactivated'));
                }
            }
            // The rewind runs in the next action (a fresh process, and its own watchdog).
            $this->next('rewind', 0);
            return;
        }
        if ($phase !== 'rewind') {
            $this->state_changed($status);
            return;
        }
        if ($status === self::rewind_long_status($kind) && !$this->count_attempt('rewind')) {
            return;
        }
        $this->engine_result('rewind', $this->onboarding->rewind($mode, $this->consent('reactivate_official'), $this->system), 'conflicts');
    }

    // --- finalize. ---

    /**
     * @param string $status TakeoverState status.
     * @return void
     */
    private function finalize_tick($status) {
        if ($this->result['phase'] !== 'finalize') {
            $this->state_changed($status);
            return;
        }
        $force = $this->consent('force_after_review');
        $plan = $this->onboarding->plan_finalize($force);
        if (!$plan->is_ok()) {
            $this->refused($plan);
            return;
        }
        if ($status === TakeoverState::CUT_OVER && !$this->count_attempt('finalize')) {
            return;
        }
        $this->engine_result('finalize', $this->onboarding->finalize($force, $this->system), 'kept');
    }

    /**
     * Map a Rollback / Finalize outcome.
     *
     * @param string  $phase       Phase to retry in.
     * @param Outcome $outcome     Onboarding::rewind() / finalize().
     * @param string  $conflict_as Warning (and report key) for kept values: 'conflicts' or 'kept'.
     * @return void
     */
    private function engine_result($phase, Outcome $outcome, $conflict_as) {
        $this->engine_steps($outcome);
        $data = $outcome->data();
        $report = self::outcome_report($outcome);
        $engine = isset($data['result']) && is_array($data['result']) ? $data['result'] : array();
        $warnings = isset($engine['warnings']) && is_array($engine['warnings']) && in_array('official_not_reactivated', $engine['warnings'], true) ? array('official_not_reactivated') : array();
        if ($outcome->is_ok()) {
            $this->end(Operation::DONE, null, null, $report + array('warnings' => array()));
            return;
        }
        $reason = (string) $outcome->reason();
        if ($reason === 'yielded') {
            $resume_at = isset($data['resume_at']) ? (int) $data['resume_at'] : 0;
            $this->next($phase, max(0, $resume_at - (int) $this->system->now()), $report);
        } elseif (in_array($reason, self::BUSY_REASONS, true)) {
            $this->busy_retry($phase, $reason, $report);
        } elseif ($outcome->code() === Outcome::CONFLICTS) {
            $list = $conflict_as === 'kept' ? 'kept' : 'conflicts';
            $report[$conflict_as] = isset($engine[$list]) && is_array($engine[$list]) ? $engine[$list] : array();
            $this->end(Operation::DONE, null, null, $report + array('warnings' => array_merge(array($conflict_as), $warnings)));
        } elseif ($outcome->code() === Outcome::OFFICIAL_NOT_REACTIVATED) {
            $this->end(Operation::DONE, null, null, $report + array('warnings' => array('official_not_reactivated')));
        } elseif ($outcome->code() === Outcome::BLOCKED) {
            $this->end(Operation::BLOCKED, $reason, self::HINT_OFFICIAL, $report);
        } elseif ($reason === 'stale_claims') {
            $this->end(Operation::FAILED, 'stale_claims', $this->stale_claims_hint(), $report);
        } else {
            $this->end(Operation::FAILED, $reason, null, $report);
        }
    }

    // --- preflight / self_test / setup_checks. ---

    /**
     * @param string $kind Check kind.
     * @return void
     */
    private function check_tick($kind) {
        if ($kind === Operation::KIND_PREFLIGHT) {
            $report = $this->onboarding->preflight();
            $cooldown = self::has_cooldown($report);
        } else {
            $report = call_user_func($kind === Operation::KIND_SELF_TEST ? $this->self_test : $this->setup_checks);
            if (!is_array($report)) {
                throw new \UnexpectedValueException('check report');
            }
            $cooldown = self::check_cooldown($report);
        }
        if ($cooldown && $this->result['cooldown_retries'] < self::MAX_COOLDOWN_RETRIES) {
            ++$this->result['cooldown_retries'];
            $this->next($kind, self::COOLDOWN_RETRY_DELAY);
            return;
        }
        $report = Bounded::document($report);
        update_option(self::last_report_option($kind), $report, false);
        $this->end(Operation::DONE, null, null, $report);
    }

    // --- Result helpers. ---

    /**
     * @param string               $phase  Phase of the next tick.
     * @param int                  $delay  Seconds until it.
     * @param array<string, mixed> $report Report.
     * @return void
     */
    private function next($phase, $delay, array $report = array()) {
        $this->result['phase'] = (string) $phase;
        $this->result['next_delay'] = max(0, (int) $delay);
        $this->result['report'] = $report;
    }

    /**
     * @param string               $outcome DONE, BLOCKED or FAILED.
     * @param string|null          $error   Error code.
     * @param string|null          $hint    What the owner does next.
     * @param array<string, mixed> $report  Report.
     * @return void
     */
    private function end($outcome, $error, $hint, array $report) {
        $this->result['outcome'] = $outcome;
        $this->result['error'] = $error;
        $this->result['hint'] = $hint;
        $this->result['report'] = $report;
        $this->result['next_delay'] = null;
    }

    /**
     * @param string $status TakeoverState status.
     * @return void
     */
    private function state_changed($status) {
        $this->end(Operation::FAILED, 'state_changed', self::HINT_STATE, array('status' => (string) $status));
    }

    /**
     * A refusal from a plan or service call: blocked for BLOCKED / OFFICIAL_STILL_ACTIVE,
     * failed otherwise, with its reason.
     *
     * @param Outcome $outcome Refusal.
     * @return void
     */
    private function refused(Outcome $outcome) {
        $blocked = in_array($outcome->code(), array(Outcome::BLOCKED, Outcome::OFFICIAL_STILL_ACTIVE), true);
        $reason = $outcome->reason() === null ? 'error' : (string) $outcome->reason();
        $hint = $blocked ? self::HINT_OFFICIAL : ($reason === 'stale_claims' ? $this->stale_claims_hint() : null);
        $this->end($blocked ? Operation::BLOCKED : Operation::FAILED, $reason, $hint, self::outcome_report($outcome));
    }

    /**
     * @param Outcome $retired A refused retire_official().
     * @return void
     */
    private function retire_refused(Outcome $retired) {
        if ($retired->reason() === 'official_active') {
            $this->end(Operation::BLOCKED, 'official_active', self::HINT_OFFICIAL, self::outcome_report($retired));
            return;
        }
        $this->end(Operation::FAILED, 'deactivation_failed', self::HINT_OFFICIAL, self::outcome_report($retired));
    }

    /**
     * A BUSY engine outcome: retry after BUSY_RETRY_DELAY, at most MAX_BUSY_RETRIES in a row.
     * A held lease (`busy`) gives back the attempt this tick counted: nothing was entered.
     *
     * @param string               $phase  Phase to retry in.
     * @param string               $reason Engine error.
     * @param array<string, mixed> $report Report.
     * @return void
     */
    private function busy_retry($phase, $reason, array $report) {
        if ($reason === 'busy' && $this->counted !== null) {
            $this->result['attempts'][$this->counted] = max(0, (int) $this->result['attempts'][$this->counted] - 1);
        }
        $this->busy = true;
        if (++$this->result['busy_retries'] > self::MAX_BUSY_RETRIES) {
            $this->end(Operation::FAILED, 'busy', self::HINT_BUSY, $report);
            return;
        }
        $this->next($phase, self::BUSY_RETRY_DELAY, $report);
    }

    /**
     * Count one entry into long work; above MAX_LONG_ATTEMPTS end `driver_exhausted`.
     *
     * @param string $key Attempt key.
     * @return bool True when the work may run.
     */
    private function count_attempt($key) {
        $this->result['long'] = true;
        $attempts = isset($this->result['attempts'][$key]) ? (int) $this->result['attempts'][$key] : 0;
        $this->result['attempts'][$key] = $attempts + 1;
        $this->counted = $key;
        if ($attempts + 1 > self::MAX_LONG_ATTEMPTS) {
            $this->end(Operation::FAILED, 'driver_exhausted', $this->exhausted_hint(), array('attempts' => $attempts + 1));
            return false;
        }
        return true;
    }

    /**
     * @param Outcome $outcome Engine outcome.
     * @return void
     */
    private function engine_steps(Outcome $outcome) {
        $data = $outcome->data();
        if (isset($data['result']['steps']) && is_array($data['result']['steps'])) {
            $this->add_steps($data['result']['steps']);
        }
    }

    /**
     * @param array<int, mixed> $steps Step names.
     * @return void
     */
    private function add_steps(array $steps) {
        foreach ($steps as $step) {
            $this->result['steps'][] = (string) $step;
        }
    }

    /**
     * @param Outcome $outcome Outcome.
     * @return array<string, mixed> The message plus the engine result or preflight report.
     */
    private static function outcome_report(Outcome $outcome) {
        $data = $outcome->data();
        $report = array('message' => $outcome->message());
        foreach (array('result', 'report', 'mismatches') as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $report[$key] = $data[$key];
            }
        }
        return $report;
    }

    /**
     * @param string $key Consent key.
     * @return bool
     */
    private function consent($key) {
        return isset($this->op['consent'][$key]) && $this->op['consent'][$key] === true;
    }

    /** @return string */
    private function exhausted_hint() {
        switch ($this->op['kind']) {
            case Operation::KIND_ROLLBACK:
                return 'run "wp supcheckout takeover rollback" on the server';
            case Operation::KIND_ABORT:
                return 'run "wp supcheckout takeover abort" on the server';
            case Operation::KIND_FINALIZE:
                return 'run "wp supcheckout takeover finalize' . ($this->consent('force_after_review') ? ' --force-after-review' : '') . '" on the server';
            case Operation::KIND_FINALIZE_DRY_RUN:
                return 'run "wp supcheckout takeover finalize --dry-run" on the server';
        }
        return 'run "wp supcheckout takeover run --deactivate-official" on the server';
    }

    /** @return string */
    private function stale_claims_hint() {
        return 'release stale claims (POST /onboarding/stale-claims/release), then start the ' . (string) $this->op['kind'] . ' again';
    }

    /** @return int */
    private function clock() {
        return (int) call_user_func($this->clock);
    }

    // --- Pure helpers. ---

    /**
     * @param array<string, mixed> $op Record.
     * @return string The phase, the kind's first one for a record that has not ticked yet.
     */
    private static function phase_of(array $op) {
        $phase = isset($op['phase']) ? (string) $op['phase'] : '';
        $kind = isset($op['kind']) ? (string) $op['kind'] : '';
        if (($phase === '' || $phase === 'started') && isset(self::INITIAL[$kind])) {
            return self::INITIAL[$kind];
        }
        return $phase;
    }

    /**
     * @param string $kind KIND_ROLLBACK or KIND_ABORT.
     * @return string The status in which the rewind still runs its long, pre-transition part.
     */
    private static function rewind_long_status($kind) {
        return $kind === Operation::KIND_ROLLBACK ? TakeoverState::CUT_OVER : TakeoverState::VERIFIED;
    }

    /**
     * @param array<string, mixed> $op Record.
     * @return list<string>
     */
    private static function stored_steps(array $op) {
        return isset($op['steps']) && is_array($op['steps']) ? array_values(array_map('strval', $op['steps'])) : array();
    }

    /**
     * @param list<string>      $stored Steps already recorded.
     * @param array<int, mixed> $steps  The tick's steps.
     * @return list<string> Steps not recorded yet, each once, transient ones dropped.
     */
    private static function new_steps(array $stored, array $steps) {
        $new = array();
        foreach ($steps as $step) {
            $step = (string) $step;
            if (!in_array($step, self::TRANSIENT_STEPS, true) && !in_array($step, $stored, true) && !in_array($step, $new, true)) {
                $new[] = $step;
            }
        }
        return $new;
    }

    /**
     * @param array<string, mixed> $report Preflight report.
     * @return bool Every blocking finding is the provider cooldown (and there is one).
     */
    private static function only_cooldown(array $report) {
        $blocking = isset($report['blocking']) && is_array($report['blocking']) ? $report['blocking'] : array();
        foreach ($blocking as $finding) {
            if (!self::is_cooldown_finding($finding)) {
                return false;
            }
        }
        return $blocking !== array();
    }

    /**
     * @param array<string, mixed> $report Preflight report.
     * @return bool Some blocking finding is the provider cooldown.
     */
    private static function has_cooldown(array $report) {
        foreach (isset($report['blocking']) && is_array($report['blocking']) ? $report['blocking'] : array() as $finding) {
            if (self::is_cooldown_finding($finding)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $finding Preflight finding.
     * @return bool
     */
    private static function is_cooldown_finding($finding) {
        return is_array($finding) && isset($finding['code'], $finding['data']['reason'])
            && $finding['code'] === 'credential_unverified' && $finding['data']['reason'] === 'cooldown';
    }

    /**
     * @param array<string, mixed> $report SelfTest / SetupChecks report.
     * @return bool The credential check is the provider cooldown warning.
     */
    private static function check_cooldown(array $report) {
        foreach (isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : array() as $check) {
            if (is_array($check) && isset($check['id'], $check['result'], $check['code'])
                && $check['id'] === 'credential' && $check['result'] === Checks::WARN && $check['code'] === 'cooldown'
            ) {
                return true;
            }
        }
        return false;
    }
}
