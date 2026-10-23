<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\CopyStore;
use Simplixi\SUPCheckout\Takeover\Cutover;
use Simplixi\SUPCheckout\Takeover\CutoverSystem;
use Simplixi\SUPCheckout\Takeover\Finalize;
use Simplixi\SUPCheckout\Takeover\FinalizeStore;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Preflight;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\RollbackStore;
use Simplixi\SUPCheckout\Takeover\RollbackSystem;
use Simplixi\SUPCheckout\Takeover\StaleClaims;
use Simplixi\SUPCheckout\Takeover\StaleClaimStore;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Takeover\WordPressCopyStore;
use Simplixi\SUPCheckout\Takeover\WordPressCutoverSystem;
use Simplixi\SUPCheckout\Takeover\WordPressStaleClaimStore;

defined('ABSPATH') || exit;

/**
 * The takeover's decisions, independent of any UI (Sub-project 0c Task 1): every check,
 * engine call and exit-code mapping `wp supcheckout takeover` makes, returned as an Outcome
 * instead of printed. CliCommands (and later the 0c driver and REST API) only ask the
 * questions, print and loop; the codes and messages are CliCommands' contract.
 *
 * It calls only the takeover engines (Preflight, CopyRunner, Cutover, Rollback, Finalize,
 * StaleClaims) and read-only probes. Confirmation stays with the caller: a method that
 * changes the store runs when called, so the caller asks first.
 *
 * The switch sequence (see CliCommands' docblock for why the official plugin goes first):
 * plan_run() → check_preflight() → start_copy() → copy_step() until copy_result() is not
 * null → retire_official(…, 'switch') → cutover(). A rollback or abort: plan_rewind() →
 * retire_official(…, 'rollback') when `data['retire_official']` → rewind(). Finalize:
 * plan_finalize() → finalize(); finalize_plan() is the dry run.
 */
final class Onboarding {
    /** Cutover error code => exit code. Anything unlisted is ERROR. */
    public const CUTOVER_EXIT = array(
        'verify_mismatch' => Outcome::MISMATCH,
        'table_mismatch' => Outcome::MISMATCH,
        'settings_mismatch' => Outcome::MISMATCH,
        'busy' => Outcome::BUSY,
        'lease_lost' => Outcome::BUSY,
        'claims_in_flight' => Outcome::BUSY,
        'transition_refused' => Outcome::BUSY,
        'stale_claims' => Outcome::STALE_CLAIMS,
        'not_verified' => Outcome::STATE,
        'official_active' => Outcome::BLOCKED,
    );

    /** Rollback / abort error code => exit code. Anything unlisted is ERROR. */
    public const ROLLBACK_EXIT = array(
        'busy' => Outcome::BUSY,
        'lease_lost' => Outcome::BUSY,
        'claims_in_flight' => Outcome::BUSY,
        'transition_refused' => Outcome::BUSY,
        'removal_busy' => Outcome::BUSY,
        'removal_unsettled' => Outcome::BUSY,
        // Only with a BudgetedSystem (the 0c driver); WP-CLI never yields.
        'yielded' => Outcome::BUSY,
        'official_active' => Outcome::BLOCKED,
        'stale_claims' => Outcome::STALE_CLAIMS,
        'not_cut_over' => Outcome::STATE,
        'not_verified' => Outcome::STATE,
        'back_sync_mismatch' => Outcome::MISMATCH,
        'options_mismatch' => Outcome::MISMATCH,
        'table_mismatch' => Outcome::MISMATCH,
    );

    /** Finalize error code => exit code. Anything unlisted is ERROR. */
    public const FINALIZE_EXIT = array(
        'busy' => Outcome::BUSY,
        'lease_lost' => Outcome::BUSY,
        'transition_refused' => Outcome::BUSY,
        'removal_busy' => Outcome::BUSY,
        'removal_unsettled' => Outcome::BUSY,
        // Only with a BudgetedSystem (the 0c driver); WP-CLI never yields.
        'yielded' => Outcome::BUSY,
        'official_active' => Outcome::BLOCKED,
        'not_cut_over' => Outcome::STATE,
        'too_early' => Outcome::STATE,
        'journal_unreadable' => Outcome::MISMATCH,
        'options_mismatch' => Outcome::MISMATCH,
        'new_table_missing' => Outcome::MISMATCH,
        'cut_over_time_invalid' => Outcome::MISMATCH,
    );

    /** Statuses `run` starts or resumes from (before cutover). */
    private const RUNNABLE = array(TakeoverState::IDLE, TakeoverState::PREFLIGHT_OK, TakeoverState::COPYING, TakeoverState::VERIFIED);

    /** retire_official() purposes. */
    private const PURPOSES = array('switch', 'rollback');

    /** @var callable */
    private $preflight;
    /** @var CopyStore */
    private $store;
    /** @var CutoverSystem */
    private $system;
    /** @var StaleClaimStore */
    private $claims;
    /** @var CopyRunner|null */
    private $runner = null;

    /**
     * @param callable        $preflight function (): array, Preflight::run()'s report.
     * @param CopyStore       $store     Orders, users, products and billing tables.
     * @param CutoverSystem   $system    Cron, official plugin, clock.
     * @param StaleClaimStore $claims    Billing-attempts table in use.
     */
    public function __construct($preflight, CopyStore $store, CutoverSystem $system, StaleClaimStore $claims) {
        $this->preflight = $preflight;
        $this->store = $store;
        $this->system = $system;
        $this->claims = $claims;
    }

    /** @return self Bound to WordPress, with the collaborators Cli::commands() uses. */
    public static function from_wordpress() {
        return new self(
            array(Preflight::class, 'run'),
            new WordPressCopyStore(),
            new WordPressCutoverSystem(),
            new WordPressStaleClaimStore()
        );
    }

    /** @return array{blocking:list<array<string,mixed>>, warnings:list<array<string,mixed>>, facts:list<array<string,mixed>>} */
    public function preflight() {
        $report = call_user_func($this->preflight);
        $report = is_array($report) ? $report : array();
        foreach (array('blocking', 'warnings', 'facts') as $list) {
            $report[$list] = isset($report[$list]) && is_array($report[$list]) ? array_values($report[$list]) : array();
        }
        return $report;
    }

    /**
     * Run Preflight. `lines()` holds every finding as the CLI prints it, `data['report']`
     * the normalized report.
     *
     * @return Outcome OK, or BLOCKED (`preflight_blocking`) with a blocking finding.
     */
    public function check_preflight() {
        $report = $this->preflight();
        $lines = Lines::report_lines($report);
        if ($report['blocking'] !== array()) {
            return Outcome::fail(Outcome::BLOCKED, 'preflight_blocking', 'blocked by preflight; nothing changed', array('report' => $report), $lines);
        }
        return Outcome::ok('nothing blocking', array('report' => $report), $lines);
    }

    /**
     * The `status --format=json` document. Cheap: no order, user, product or table row is
     * scanned.
     *
     * @return array<string, mixed>
     */
    public function status() {
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $official = $this->system->official_plugin();
        $stale = $this->stale_claims();
        $journal = self::journal_summary();
        $warnings = array();
        if ($official !== null && in_array($state['status'], array(TakeoverState::CUT_OVER, TakeoverState::FINALIZED), true)) {
            $warnings[] = 'official_still_active';
        }
        if ($stale !== array()) {
            $warnings[] = 'stale_claims';
        }
        if (isset($journal['error'])) {
            $warnings[] = 'journal_unreadable';
        }
        if (self::mismatch_count($state) > 0) {
            $warnings[] = 'mismatches';
        }
        $finalize = self::finalize_status($state);
        if ($finalize['pending']) {
            $warnings[] = 'finalize_pending';
        }
        $rollback = self::rollback_summary($state);
        if ($rollback !== null && $rollback['removal_pending']) {
            $warnings[] = 'removal_pending';
        }
        if ($rollback !== null && $rollback['conflicts'] !== array()) {
            $warnings[] = 'rollback_conflicts';
        }
        return array(
            'status' => $state['status'],
            'cut_over_at' => $state['cut_over_at'],
            'rolled_back_at' => self::field($state, 'rolled_back_at', 0),
            'rollback' => $rollback,
            'finalize' => $finalize,
            'phase' => self::field($state, 'phase', null),
            'cursor' => $state['cursor'],
            'cursors' => self::field($state, 'cursors', array()),
            'totals' => $state['totals'],
            'mismatches' => self::field($state, 'mismatches', array()),
            'anomalies' => self::field($state, 'anomalies', array()),
            'journal' => $journal,
            'quiesced' => Quiesce::is_active($this->system->now()),
            'official_plugin' => $official,
            'stale_claims' => $stale,
            'warnings' => $warnings,
        );
    }

    /**
     * What `run` does next from the current state.
     *
     * @param bool $deactivate_official Owner consent to deactivate the official plugin.
     * @return Outcome OK with `data['next']` 'run' (preflight, copy, retire, cutover),
     *                 'cutover_tail' (cutover() on a cut-over store) or 'done'; or a refusal.
     */
    public function plan_run($deactivate_official) {
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $status = $state['status'];
        if ($status === TakeoverState::CUT_OVER) {
            if ($deactivate_official) {
                return Outcome::ok('retry the deactivation', array('next' => 'cutover_tail', 'status' => $status));
            }
            $official = $this->system->official_plugin();
            if ($official !== null) {
                return Outcome::fail(Outcome::OFFICIAL_STILL_ACTIVE, 'official_still_active', 'cut over, but the official UPayments plugin (' . $official . ') is active again; SUPCheckout stays paused. Rerun with --deactivate-official', array('status' => $status, 'official_plugin' => $official));
            }
            return Outcome::ok('already cut over; nothing to do', array('next' => 'done', 'status' => $status));
        }
        if (Rollback::removal_pending($state)) {
            $command = Rollback::mode_of($state) === Rollback::MODE_ABORT ? 'abort' : 'rollback';
            return Outcome::fail(Outcome::STATE, 'removal_pending', 'an interrupted ' . $command . ' is still removing the previous copies; rerun `wp supcheckout takeover ' . $command . '` first', array('status' => $status, 'mode' => $command));
        }
        if (!in_array($status, self::RUNNABLE, true)) {
            return Outcome::fail(Outcome::STATE, 'state', 'state "' . $status . '" does not allow run', array('status' => $status));
        }
        return Outcome::ok('ready to run', array('next' => 'run', 'status' => $status));
    }

    /**
     * idle → preflight_ok → copying; a store already copying or verified resumes as it is.
     * Reads the state plan_run() refreshed (no refresh here, like the CLI always did).
     *
     * @return Outcome
     */
    public function start_copy() {
        $status = TakeoverState::get()['status'];
        if ($status === TakeoverState::IDLE && !TakeoverState::transition(TakeoverState::PREFLIGHT_OK)) {
            return Outcome::fail(Outcome::STATE, 'state_changed', 'the takeover state changed meanwhile; rerun');
        }
        if (($status === TakeoverState::IDLE || $status === TakeoverState::PREFLIGHT_OK) && !CopyRunner::begin()) {
            return Outcome::fail(Outcome::STATE, 'state_changed', 'the takeover state changed meanwhile; rerun');
        }
        return Outcome::ok('copying', array('status' => $status));
    }

    /**
     * One bounded copy step (at most 50 objects); it persists its cursor.
     *
     * @return string A CopyRunner status.
     */
    public function copy_step() {
        if ($this->runner === null) {
            $this->runner = new CopyRunner($this->store, array($this->system, 'now'));
        }
        return $this->runner->run();
    }

    /** @return int|null The order the last copy_step() found locked (CopyRunner::RETRY), if any. */
    public function locked_order() {
        return $this->runner === null ? null : $this->runner->locked_order();
    }

    /** @return Outcome|null Null while copying; OK once verified; a refusal otherwise. */
    public function copy_result() {
        TakeoverState::refresh();
        $state = TakeoverState::get();
        if ($state['status'] === TakeoverState::COPYING) {
            return null;
        }
        if ($state['status'] === TakeoverState::VERIFIED) {
            return Outcome::ok('copy verified', array('status' => $state['status']), array('takeover copy: verified'));
        }
        if ($state['status'] === TakeoverState::PREFLIGHT_OK && self::mismatch_count($state) > 0) {
            $mismatches = self::field($state, 'mismatches', array());
            return Outcome::fail(Outcome::MISMATCH, 'copy_mismatch', 'copy verification found mismatches; the store was not switched', array('mismatches' => $mismatches), array('mismatches: ' . (string) wp_json_encode($mismatches)));
        }
        return Outcome::fail(Outcome::STATE, 'state_changed', 'the takeover state changed to "' . (string) $state['status'] . '" meanwhile', array('status' => $state['status']));
    }

    /**
     * The official plugin must be inactive before Cutover takes the lease, and before a
     * rollback back-syncs the legacy names it writes too: deactivate it with consent (this
     * site only; recorded for a later reactivation), refuse without.
     *
     * @param bool   $consent Owner consent to deactivate it.
     * @param string $purpose 'switch' or 'rollback'.
     * @return Outcome OK with `data['deactivated']` (the basename, or null when none was active).
     */
    public function retire_official($consent, $purpose) {
        if (!in_array($purpose, self::PURPOSES, true)) {
            return Outcome::fail(Outcome::ERROR, 'invalid_purpose', 'unknown purpose "' . (string) $purpose . '"', array('deactivated' => null));
        }
        $official = $this->system->official_plugin();
        if ($official === null) {
            return Outcome::ok('the official UPayments plugin is inactive', array('deactivated' => null));
        }
        $switch = $purpose === 'switch';
        if (!$consent) {
            $message = $switch
                ? 'the official UPayments plugin (' . $official . ') is active; the copy is verified but the store was not switched. Rerun with --deactivate-official, or deactivate it first'
                : 'the official UPayments plugin (' . $official . ') is active; nothing was rolled back. Rerun with --deactivate-official, or deactivate it first';
            return Outcome::fail(Outcome::BLOCKED, 'official_active', $message, array('deactivated' => null, 'official_plugin' => $official));
        }
        if (!$this->system->deactivate_plugin($official)) {
            $message = 'the official UPayments plugin (' . $official . ') could not be deactivated; ' . ($switch ? 'the store was not switched' : 'nothing was rolled back');
            return Outcome::fail(Outcome::ERROR, 'deactivate_failed', $message, array('deactivated' => null, 'official_plugin' => $official));
        }
        $recorded = Rollback::record_official_deactivated($official);
        $lines = array('official plugin deactivated: ' . $official . ($switch ? ' (SUPCheckout serves the store from the next request)' : ''));
        if (!$recorded) {
            // A5-07: the plugin is off either way; only the later consented reactivation is lost.
            $lines[] = 'warning: official_deactivation_not_recorded (a rollback will not reactivate ' . $official . '; activate it yourself afterwards)';
        }
        return Outcome::ok('official plugin deactivated', array('deactivated' => $official, 'recorded' => $recorded), $lines);
    }

    /**
     * verified → cut_over (Cutover, idempotent before its compare-and-swap); on a cut-over
     * store only the post-cutover tail (the deactivation retry).
     *
     * @param bool $consent Owner consent to deactivate the official plugin.
     * @return Outcome
     */
    public function cutover($consent) {
        return self::cutover_outcome((new Cutover($this->store, $this->system))->run((bool) $consent));
    }

    /**
     * @param array<string, mixed> $result Cutover::run().
     * @return Outcome
     */
    public static function cutover_outcome(array $result) {
        $data = array('result' => $result);
        if (!$result['ok']) {
            $error = (string) $result['error'];
            $lines = array('cutover report: ' . (string) wp_json_encode($result['report']));
            if ($error === 'stale_claims') {
                $lines[] = 'run `wp supcheckout takeover release-stale-claims`, then rerun';
            }
            $code = isset(self::CUTOVER_EXIT[$error]) ? self::CUTOVER_EXIT[$error] : Outcome::ERROR;
            return Outcome::fail($code, $error, 'cutover refused: ' . $error . '; the store was not switched', $data, $lines);
        }
        $lines = array_merge(array('takeover cutover steps: ' . implode(', ', $result['steps'])), Lines::cache_purge_lines($result));
        if (in_array('official_still_active', $result['warnings'], true)) {
            $lines[] = 'warning: official_still_active';
            return Outcome::fail(Outcome::OFFICIAL_STILL_ACTIVE, 'official_still_active', 'cut over, but the official UPayments plugin is still active; deactivate it (SUPCheckout stays paused until then)', $data, $lines);
        }
        return Outcome::ok('cut over', $data, $lines);
    }

    /**
     * Whether a rollback (cut_over → rolled_back → preflight_ok) or an abort (verified →
     * preflight_ok) may start or resume.
     *
     * @param string $mode Rollback::MODE_ROLLBACK or Rollback::MODE_ABORT.
     * @return Outcome OK with `data['retire_official']` true for a rollback from cut_over.
     */
    public function plan_rewind($mode) {
        $invalid = self::invalid_mode($mode);
        if ($invalid !== null) {
            return $invalid;
        }
        if ($this->rollback_engine(null) === null) {
            return self::rewind_unsupported($mode);
        }
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $allowed = $mode === Rollback::MODE_ROLLBACK
            ? array(TakeoverState::CUT_OVER, TakeoverState::ROLLED_BACK)
            : array(TakeoverState::VERIFIED);
        $resuming = $state['status'] === TakeoverState::PREFLIGHT_OK && Rollback::mode_of($state) === $mode;
        if (!$resuming && !in_array($state['status'], $allowed, true)) {
            return Outcome::fail(Outcome::STATE, 'state', 'state "' . $state['status'] . '" does not allow ' . $mode, array('status' => $state['status']));
        }
        $retire = $mode === Rollback::MODE_ROLLBACK && $state['status'] === TakeoverState::CUT_OVER;
        return Outcome::ok($mode . ' allowed', array('status' => $state['status'], 'resuming' => $resuming, 'retire_official' => $retire));
    }

    /**
     * Run (or resume) the rollback or abort.
     *
     * @param string              $mode       Rollback::MODE_ROLLBACK or Rollback::MODE_ABORT.
     * @param bool                $reactivate Owner consent to reactivate the official install the takeover deactivated.
     * @param RollbackSystem|null $system     Instead of the constructor's system.
     * @return Outcome
     */
    public function rewind($mode, $reactivate, $system = null) {
        $invalid = self::invalid_mode($mode);
        if ($invalid !== null) {
            return $invalid;
        }
        $rollback = $this->rollback_engine($system);
        if ($rollback === null) {
            return self::rewind_unsupported($mode);
        }
        $result = $mode === Rollback::MODE_ROLLBACK ? $rollback->run((bool) $reactivate) : $rollback->abort((bool) $reactivate);
        return self::rewind_outcome($mode, $result);
    }

    /**
     * Precedence: an error first (its own code), then conflicts (8), then a failed
     * reactivation (9). Every conflict and the reactivation warning are in `lines()`. A
     * budgeted run that yielded is BUSY (`yielded`) with `data['resume_at']`.
     *
     * @param string               $mode   Mode.
     * @param array<string, mixed> $result Rollback::run() / abort().
     * @return Outcome
     */
    public static function rewind_outcome($mode, array $result) {
        $data = self::engine_data($result);
        $lines = array_merge(array('takeover ' . $mode . ' steps: ' . implode(', ', $result['steps'])), Lines::cache_purge_lines($result));
        foreach ($result['conflicts'] as $conflict) {
            $lines[] = 'conflict: seq ' . $conflict['seq'] . ' ' . $conflict['target'] . ' ' . $conflict['path'] . ' (kept; the owner decides)';
        }
        if (isset($result['report']['settings_diverged']['hint'])) {
            // A5-04: the kept settings block the next switch's preflight until cleared.
            $lines[] = 'next: ' . (string) $result['report']['settings_diverged']['hint'];
        }
        $not_reactivated = in_array('official_not_reactivated', $result['warnings'], true);
        if ($not_reactivated) {
            $lines[] = 'warning: official_not_reactivated';
        }
        if ($result['error'] !== null) {
            $error = (string) $result['error'];
            $lines[] = $mode . ' report: ' . (string) wp_json_encode($result['report']);
            if ($error === 'stale_claims') {
                $lines[] = 'run `wp supcheckout takeover release-stale-claims`, then rerun';
            }
            if ($error === 'official_active') {
                $lines[] = 'deactivate the official UPayments plugin (or rerun rollback with --deactivate-official), then rerun';
            }
            $code = isset(self::ROLLBACK_EXIT[$error]) ? self::ROLLBACK_EXIT[$error] : Outcome::ERROR;
            return Outcome::fail($code, $error, $mode . ' stopped: ' . $error . '; rerun to resume', $data, $lines);
        }
        $done = $mode === Rollback::MODE_ROLLBACK ? 'rolled back' : 'aborted';
        if ($result['conflicts'] !== array()) {
            return Outcome::fail(Outcome::CONFLICTS, 'conflicts', $done . ', but ' . count($result['conflicts']) . ' conflict(s) were kept; review them', $data, $lines);
        }
        if ($not_reactivated) {
            return Outcome::fail(Outcome::OFFICIAL_NOT_REACTIVATED, 'official_not_reactivated', $done . ', but the official UPayments plugin was not reactivated; activate it yourself', $data, $lines);
        }
        return Outcome::ok($done . ' (restored ' . $result['restored'] . ')', $data, $lines);
    }

    /**
     * `data['result']`, plus `data['resume_at']` when the engine yielded (BudgetedSystem):
     * the Unix time from which rerunning the same call makes progress.
     *
     * @param array<string, mixed> $result Rollback or Finalize result.
     * @return array<string, mixed>
     */
    private static function engine_data(array $result) {
        $data = array('result' => $result);
        if ($result['error'] === 'yielded') {
            $data['resume_at'] = isset($result['report']['resume_at']) ? (int) $result['report']['resume_at'] : 0;
        }
        return $data;
    }

    /** @return bool The store and system can run Finalize. */
    public function finalize_capable() {
        return $this->store instanceof FinalizeStore && $this->system instanceof RollbackSystem;
    }

    /**
     * Whether finalize may run now. Cheap: no lease, no row scanned.
     *
     * @param bool $force Owner consent to finalize before 30 days (after review).
     * @return Outcome OK or a refusal, both with `data{due_at, days_left, status}`.
     */
    public function plan_finalize($force) {
        if (!$this->finalize_capable()) {
            return Outcome::fail(Outcome::ERROR, 'unsupported', 'finalize needs a finalize-capable store and system');
        }
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $days = Finalize::days_left($state, time());
        $data = array('due_at' => Finalize::due_at($state), 'days_left' => $days, 'status' => $state['status']);
        $official = $this->system->official_plugin();
        if ($official !== null && in_array($state['status'], array(TakeoverState::CUT_OVER, TakeoverState::FINALIZED), true)) {
            return Outcome::fail(Outcome::BLOCKED, 'official_active', 'the official UPayments plugin (' . $official . ') is active; deactivate it, then rerun', $data);
        }
        if ($state['status'] === TakeoverState::FINALIZED) {
            return Outcome::ok('sweep allowed', $data);
        }
        if ($state['status'] !== TakeoverState::CUT_OVER) {
            return Outcome::fail(Outcome::STATE, 'not_cut_over', 'state "' . $state['status'] . '" does not allow finalize (it needs a cut-over store)', $data);
        }
        if (!Finalize::cut_over_time_valid($state)) {
            return Outcome::fail(Outcome::MISMATCH, 'cut_over_time_invalid', 'the recorded cutover time is missing or in the future; --force-after-review never overrides it', $data);
        }
        if ($days > 0 && !$force) {
            return Outcome::fail(Outcome::STATE, 'too_early', 'finalize is allowed in ' . $days . ' day(s) (' . gmdate('Y-m-d', Finalize::due_at($state)) . ' UTC); review `wp supcheckout takeover finalize --dry-run`, then rerun with --force-after-review to finalize earlier', $data);
        }
        return Outcome::ok('finalize allowed', $data);
    }

    /**
     * cut_over → finalized (irreversible); resumes an interrupted finalize and sweeps a
     * finalized store again.
     *
     * @param bool                $force  Owner consent to finalize before 30 days.
     * @param RollbackSystem|null $system Instead of the constructor's system.
     * @return Outcome
     */
    public function finalize($force, $system = null) {
        $system = $system instanceof RollbackSystem ? $system : $this->system;
        if (!$this->store instanceof FinalizeStore || !$system instanceof RollbackSystem) {
            return Outcome::fail(Outcome::ERROR, 'unsupported', 'finalize needs a finalize-capable store and system');
        }
        return self::finalize_outcome((new Finalize($this->store, $system))->run((bool) $force));
    }

    /**
     * Precedence: an error first (its own code), then kept values (8). A budgeted run that
     * yielded is BUSY (`yielded`) with `data['resume_at']`.
     *
     * @param array<string, mixed> $result Finalize::run().
     * @return Outcome
     */
    public static function finalize_outcome(array $result) {
        $data = self::engine_data($result);
        $lines = array_merge(
            array('takeover finalize steps: ' . implode(', ', $result['steps'])),
            Lines::cache_purge_lines($result),
            array('removed: ' . Lines::counts($result['removed'])),
            Lines::kept_lines($result['kept'], 'kept'),
            Lines::reported_lines($result['reported'])
        );
        if ($result['error'] !== null) {
            $error = (string) $result['error'];
            $lines[] = 'finalize report: ' . (string) wp_json_encode($result['report']);
            $code = isset(self::FINALIZE_EXIT[$error]) ? self::FINALIZE_EXIT[$error] : Outcome::ERROR;
            return Outcome::fail($code, $error, 'finalize stopped: ' . $error . '; rerun to resume', $data, $lines);
        }
        if ($result['kept'] !== array()) {
            return Outcome::fail(Outcome::CONFLICTS, 'conflicts', 'finalized, but ' . count($result['kept']) . ' group(s) of legacy values were kept; review them', $data, $lines);
        }
        return Outcome::ok('finalized' . ($result['forced'] ? ' (forced after review)' : ''), $data, $lines);
    }

    /**
     * The dry run: Finalize::plan(). Read-only.
     *
     * @return array<string, mixed>
     * @throws \LogicException Without a finalize-capable store and system (finalize_capable()).
     */
    public function finalize_plan() {
        if (!$this->store instanceof FinalizeStore || !$this->system instanceof RollbackSystem) {
            throw new \LogicException('finalize needs a finalize-capable store and system');
        }
        return (new Finalize($this->store, $this->system))->plan();
    }

    /** @return list<array{cycle_key:string, age_seconds:int}> Billing claims stuck in `claimed`. */
    public function stale_claims() {
        return StaleClaims::report($this->claims, $this->system->now());
    }

    /**
     * Release the stale claims (no provider request was sent for them). Only with the
     * owner's explicit consent: the caller shows stale_claims() first and asks; nothing here
     * re-reads and releases on its own.
     *
     * @param bool $consent Owner consent to release.
     * @return Outcome
     */
    public function release_stale_claims($consent) {
        if ($consent !== true) {
            return Outcome::fail(Outcome::ERROR, 'consent_required', 'releasing stale claims needs the owner\'s consent; nothing released', array('released' => array(), 'skipped' => array()));
        }
        $result = StaleClaims::release($this->claims, $this->system->now());
        $data = array('released' => $result['released'], 'skipped' => $result['skipped']);
        $lines = array();
        foreach ($result['released'] as $key) {
            $lines[] = 'released: ' . $key;
        }
        foreach ($result['skipped'] as $key) {
            $lines[] = 'kept (its worker moved it first): ' . $key;
        }
        if (!$result['ok']) {
            $error = (string) $result['error'];
            return Outcome::fail(Outcome::BUSY, $error, 'release stopped: ' . $error, $data, $lines);
        }
        return Outcome::ok('released ' . count($result['released']) . ', kept ' . count($result['skipped']), $data, $lines);
    }

    /**
     * @param RollbackSystem|null $system Instead of the constructor's system.
     * @return Rollback|null Null without a rollback-capable store and system.
     */
    private function rollback_engine($system) {
        $system = $system instanceof RollbackSystem ? $system : $this->system;
        if (!$this->store instanceof RollbackStore || !$system instanceof RollbackSystem) {
            return null;
        }
        return new Rollback($this->store, $system);
    }

    /**
     * @param string $mode Mode.
     * @return Outcome|null A refusal for anything but Rollback::MODE_ROLLBACK / MODE_ABORT.
     */
    private static function invalid_mode($mode) {
        if ($mode === Rollback::MODE_ROLLBACK || $mode === Rollback::MODE_ABORT) {
            return null;
        }
        return Outcome::fail(Outcome::ERROR, 'invalid_mode', 'unknown mode "' . (string) $mode . '"');
    }

    /**
     * @param string $mode Mode.
     * @return Outcome
     */
    private static function rewind_unsupported($mode) {
        return Outcome::fail(Outcome::ERROR, 'unsupported', $mode . ' needs a rollback-capable store and system');
    }

    /**
     * The status field: days until finalize is allowed. Cheap on purpose: no row is scanned;
     * the planned removal is the dry run (`finalize --dry-run`). Once finalized, the recorded
     * removal counts.
     *
     * @param array<string, mixed> $state TakeoverState::get().
     * @return array{due_at:int, days_left:int, allowed:bool, forced:bool, pending:bool, planned:array<string,int>|null, kept:int, hint:string}
     */
    private static function finalize_status(array $state) {
        $planned = null;
        $kept = 0;
        if ($state['status'] === TakeoverState::FINALIZED) {
            $progress = Finalize::progress_of($state);
            $planned = isset($progress['removed']) && is_array($progress['removed']) ? array_map('intval', $progress['removed']) : null;
            $kept = isset($progress['kept']) && is_array($progress['kept']) ? count($progress['kept']) : 0;
        }
        $days = Finalize::days_left($state, time());
        return array(
            'due_at' => Finalize::due_at($state),
            'days_left' => $days,
            'allowed' => $state['status'] === TakeoverState::CUT_OVER && $days === 0,
            'forced' => Finalize::forced($state),
            'pending' => Finalize::pending($state),
            'planned' => $planned,
            'kept' => $kept,
            'hint' => $state['status'] === TakeoverState::CUT_OVER ? 'run `wp supcheckout takeover finalize --dry-run` for the planned removal' : '',
        );
    }

    /**
     * @param array<string, mixed> $state   TakeoverState::get().
     * @param string               $key     Progress field written with patch().
     * @param mixed                $default When absent.
     * @return mixed
     */
    private static function field(array $state, $key, $default) {
        return array_key_exists($key, $state) ? $state[$key] : $default;
    }

    /**
     * @param array<string, mixed> $state State.
     * @return int Objects with a blocking mismatch.
     */
    private static function mismatch_count(array $state) {
        $count = 0;
        foreach (isset($state['mismatches']) && is_array($state['mismatches']) ? $state['mismatches'] : array() as $group) {
            $count += is_array($group) && isset($group['count']) ? (int) $group['count'] : 0;
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $state State.
     * @return array{mode:string, removal_pending:bool, phase:string, conflicts:list<array<string, mixed>>}|null
     */
    private static function rollback_summary(array $state) {
        $progress = isset($state[Rollback::PROGRESS]) && is_array($state[Rollback::PROGRESS]) ? $state[Rollback::PROGRESS] : null;
        if ($progress === null || Rollback::mode_of($state) === null) {
            return null;
        }
        return array(
            'mode' => (string) Rollback::mode_of($state),
            'removal_pending' => Rollback::removal_pending($state),
            'phase' => isset($progress['removal']['phase']) ? (string) $progress['removal']['phase'] : '',
            'conflicts' => isset($progress['conflicts']) && is_array($progress['conflicts']) ? array_values($progress['conflicts']) : array(),
        );
    }

    /** @return array<string, mixed> Entry count and count per target, or an error marker. */
    private static function journal_summary() {
        try {
            $entries = Journal::entries();
        } catch (\Throwable $e) {
            return array('entries' => 0, 'by_target' => array(), 'error' => 'unreadable');
        }
        $by_target = array();
        foreach ($entries as $entry) {
            $by_target[$entry['target']] = isset($by_target[$entry['target']]) ? $by_target[$entry['target']] + 1 : 1;
        }
        return array('entries' => count($entries), 'by_target' => $by_target);
    }
}
