<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * What `wp supcheckout takeover` does (Sub-project 0b Task 9), without WP-CLI: Cli binds
 * it to WordPress and turns the returned exit code into the process exit status.
 *
 * Exit codes (stable; 0c and scripts rely on them):
 *  0 OK                    done (or nothing to do);
 *  1 ERROR                 declined confirmation, or an unexpected failure; rerun;
 *  2 BLOCKED               Preflight reported a blocking finding, or the official plugin is
 *                          active (without --deactivate-official, or reactivated during the
 *                          cutover: Cutover's `official_active`); not cut over;
 *  3 MISMATCH              verification found a mismatch (copy runner or cutover); the
 *                          store is not cut over; fix the reported names and rerun;
 *  4 BUSY                  another process holds the copy-runner lock or the takeover
 *                          lease, the lease was lost, or claims stayed in flight; rerun later;
 *  5 STALE_CLAIMS          a billing claim is stuck in `claimed` past 600 s; run
 *                          `release-stale-claims`, then rerun;
 *  6 STATE                 the takeover state does not allow this (rolled_back, finalized,
 *                          unreadable, or changed by another process);
 *  7 OFFICIAL_STILL_ACTIVE a store already cut over whose official plugin is active again:
 *                          without --deactivate-official, or the deactivation failed;
 *                          SUPCheckout stays paused; deactivate it and rerun;
 *  8 CONFLICTS             `rollback`/`abort` completed, but later edits were kept
 *                          (journal "later edits win"); review the printed paths;
 *  9 OFFICIAL_NOT_REACTIVATED `rollback`/`abort --reactivate-official` completed, but the
 *                          official plugin could not be reactivated (none or several
 *                          installed, or activation failed); activate it yourself.
 *
 * `rollback` (cut_over → rolled_back → preflight_ok) and `abort` (verified → preflight_ok)
 * run Rollback; both are rerunnable after a crash at any step. Their errors map to the
 * codes above: busy, lost lease, claims in flight, a contended order lock or late writers
 * still recreating copies after the settle time (`removal_unsettled`; rerun later) → 4, stale
 * claims → 5, the wrong state → 6, a back-sync or table mismatch → 3, anything else → 1.
 * After their transition both wait in-process for CopyRemoval::SETTLE_SECONDS (120 s, a
 * `settling` line every 10 s) before the final rescans, so a late writer's copy is removed
 * before completion is recorded.
 *
 * `run` is rerunnable after a crash at any step: every step resumes from persisted state.
 * idle → preflight_ok → copying (CopyRunner, cursor per object) → verified → official
 * plugin deactivated → cut_over (Cutover, idempotent before its compare-and-swap).
 * Preflight runs again on every rerun before cutover, so a store that became blocked
 * meanwhile is never switched.
 *
 * Official plugin (amended after review): never cut over while it is active. It runs its
 * own renewal cron and callbacks, ignores the Quiesce lease and writes legacy-only meta
 * (`_upay_last_billed_at`, billing state); a renewal it charged between Cutover's final
 * verify and a later deactivation would be read from the stale new-name copy after cutover
 * and could be charged again. So, once the copy is `verified` and BEFORE Cutover takes the
 * lease and runs its final verify:
 * - active and --deactivate-official given: deactivate it here. From the next request on,
 *   SUPCheckout's full runtime boots in `verified`: it reads legacy, writes both names,
 *   respects the quiesce and serves the permanent `wc_upayments` route, so callbacks keep
 *   being served with no gap, and Cutover's final verify runs with only lock-respecting
 *   writers. A failed deactivation exits ERROR with nothing cut over;
 * - active without --deactivate-official: refuse with BLOCKED (copying, read-only towards
 *   legacy, has still run and stays `verified` for the rerun);
 * - already inactive (a rerun after a crash past this step): nothing to do.
 * Defense in depth: Cutover itself refuses to switch while the official plugin is active
 * (`official_active`, checked in its recheck and right before the swap), so a plugin
 * reactivated during the cutover leaves the store `verified` (exit 2; a rerun deactivates
 * it again). Deactivation after a switch exists only for a store already cut over.
 *
 * In this CLI process the official plugin's code stays loaded after deactivate_plugins():
 * the cutover path never calls do_action() itself, writes only through the CopyStore,
 * options and the CutoverSystem, takes no billing claim, and only schedules cron twins
 * (no cron callback runs in a WP-CLI process); billing claims and cron run in later
 * requests, which boot without the official plugin.
 */
final class CliCommands {
    public const OK = 0;
    public const ERROR = 1;
    public const BLOCKED = 2;
    public const MISMATCH = 3;
    public const BUSY = 4;
    public const STALE_CLAIMS = 5;
    public const STATE = 6;
    public const OFFICIAL_STILL_ACTIVE = 7;
    public const CONFLICTS = 8;
    public const OFFICIAL_NOT_REACTIVATED = 9;

    /** Rollback / abort error code => exit code. Anything unlisted is ERROR. */
    private const ROLLBACK_EXIT = array(
        'busy' => self::BUSY,
        'lease_lost' => self::BUSY,
        'claims_in_flight' => self::BUSY,
        'transition_refused' => self::BUSY,
        'removal_busy' => self::BUSY,
        'removal_unsettled' => self::BUSY,
        'official_active' => self::BLOCKED,
        'stale_claims' => self::STALE_CLAIMS,
        'not_cut_over' => self::STATE,
        'not_verified' => self::STATE,
        'back_sync_mismatch' => self::MISMATCH,
        'options_mismatch' => self::MISMATCH,
        'table_mismatch' => self::MISMATCH,
    );

    /** Seconds between retries while another process holds the copy-runner lock. */
    public const WAIT_POLL = 5;
    /** Seconds `run` waits per stall (no progress) for a busy copy runner before exiting BUSY. */
    public const MAX_WAIT = 300;
    /** Consecutive STOPPED steps with the state still `copying` before `run` exits ERROR. */
    public const MAX_STOPPED = 3;

    /** Cutover error code => exit code. Anything unlisted is ERROR. */
    private const CUTOVER_EXIT = array(
        'verify_mismatch' => self::MISMATCH,
        'table_mismatch' => self::MISMATCH,
        'settings_mismatch' => self::MISMATCH,
        'busy' => self::BUSY,
        'lease_lost' => self::BUSY,
        'claims_in_flight' => self::BUSY,
        'transition_refused' => self::BUSY,
        'stale_claims' => self::STALE_CLAIMS,
        'not_verified' => self::STATE,
        'official_active' => self::BLOCKED,
    );

    /** Statuses `run` starts or resumes from (before cutover). */
    private const RUNNABLE = array(TakeoverState::IDLE, TakeoverState::PREFLIGHT_OK, TakeoverState::COPYING, TakeoverState::VERIFIED);

    /** @var callable */
    private $preflight;
    /** @var CopyStore */
    private $store;
    /** @var CutoverSystem */
    private $system;
    /** @var StaleClaimStore */
    private $claims;
    /** @var callable */
    private $out;
    /** @var callable */
    private $confirm;
    /** @var callable|null */
    private $copy_step;

    /**
     * @param callable        $preflight function (): array, Preflight::run()'s report.
     * @param CopyStore       $store     Orders, users, products and billing tables.
     * @param CutoverSystem   $system    Cron, official plugin, clock.
     * @param StaleClaimStore $claims    Billing-attempts table in use.
     * @param callable        $out       function (string $line): void.
     * @param callable        $confirm   function (string $question): bool.
     * @param callable|null   $copy_step function (): string, one copy step returning a CopyRunner
     *                                   status; a CopyRunner over $store when omitted.
     */
    public function __construct($preflight, CopyStore $store, CutoverSystem $system, StaleClaimStore $claims, $out, $confirm, $copy_step = null) {
        $this->preflight = $preflight;
        $this->store = $store;
        $this->system = $system;
        $this->claims = $claims;
        $this->out = $out;
        $this->confirm = $confirm;
        $this->copy_step = is_callable($copy_step) ? $copy_step : null;
    }

    /**
     * @param bool $json Print the report as one JSON document.
     * @return int Exit code.
     */
    public function preflight($json) {
        $report = $this->run_preflight();
        if ($json) {
            $this->line((string) wp_json_encode($report));
        } else {
            $this->print_report($report);
        }
        return $report['blocking'] === array() ? self::OK : self::BLOCKED;
    }

    /**
     * @param bool $yes                 Skip the confirmation.
     * @param bool $deactivate_official Owner consent to deactivate the official plugin before cutover.
     * @return int Exit code.
     */
    public function run($yes, $deactivate_official) {
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        if ($status === TakeoverState::CUT_OVER) {
            if ($deactivate_official) {
                return $this->cutover(true);
            }
            $official = $this->system->official_plugin();
            if ($official !== null) {
                return $this->fail(self::OFFICIAL_STILL_ACTIVE, 'cut over, but the official UPayments plugin (' . $official . ') is active again; SUPCheckout stays paused. Rerun with --deactivate-official');
            }
            return $this->done('already cut over; nothing to do');
        }
        if (Rollback::removal_pending(TakeoverState::get())) {
            $command = Rollback::mode_of(TakeoverState::get()) === Rollback::MODE_ABORT ? 'abort' : 'rollback';
            return $this->fail(self::STATE, 'an interrupted ' . $command . ' is still removing the previous copies; rerun `wp supcheckout takeover ' . $command . '` first');
        }
        if (!in_array($status, self::RUNNABLE, true)) {
            return $this->fail(self::STATE, 'state "' . $status . '" does not allow run');
        }
        $report = $this->run_preflight();
        if ($report['blocking'] !== array()) {
            $this->print_report($report);
            return $this->fail(self::BLOCKED, 'blocked by preflight; nothing changed');
        }
        $question = $deactivate_official
            ? 'Copy every UPayments record, switch the store to SUPCheckout and deactivate the official UPayments plugin?'
            : 'Copy every UPayments record and switch the store to SUPCheckout?';
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing changed');
        }
        $started = $this->start_copy($status);
        if ($started !== self::OK) {
            return $started;
        }
        $copied = $this->drive_copy();
        if ($copied !== self::OK) {
            return $copied;
        }
        $official = $this->retire_official((bool) $deactivate_official);
        if ($official !== self::OK) {
            return $official;
        }
        return $this->cutover((bool) $deactivate_official);
    }

    /**
     * The official plugin must be inactive before Cutover takes the lease (see the class
     * docblock): deactivate it with consent, refuse without.
     *
     * @param bool $deactivate_official Consent.
     * @return int Exit code.
     */
    private function retire_official($deactivate_official) {
        $official = $this->system->official_plugin();
        if ($official === null) {
            return self::OK;
        }
        if (!$deactivate_official) {
            return $this->fail(self::BLOCKED, 'the official UPayments plugin (' . $official . ') is active; the copy is verified but the store was not switched. Rerun with --deactivate-official, or deactivate it first');
        }
        if (!$this->system->deactivate_plugin($official)) {
            return $this->fail(self::ERROR, 'the official UPayments plugin (' . $official . ') could not be deactivated; the store was not switched');
        }
        Rollback::record_official_deactivated($official);
        $this->line('official plugin deactivated: ' . $official . ' (SUPCheckout serves the store from the next request)');
        return self::OK;
    }

    /**
     * @param bool $json Print one JSON document.
     * @return int Exit code.
     */
    public function status($json) {
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $official = $this->system->official_plugin();
        $stale = StaleClaims::report($this->claims, $this->system->now());
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
        $rollback = self::rollback_summary($state);
        if ($rollback !== null && $rollback['removal_pending']) {
            $warnings[] = 'removal_pending';
        }
        if ($rollback !== null && $rollback['conflicts'] !== array()) {
            $warnings[] = 'rollback_conflicts';
        }
        $status = array(
            'status' => $state['status'],
            'cut_over_at' => $state['cut_over_at'],
            'rolled_back_at' => self::field($state, 'rolled_back_at', 0),
            'rollback' => $rollback,
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
        if ($json) {
            $this->line((string) wp_json_encode($status));
            return self::OK;
        }
        foreach (array('status', 'phase', 'cursor', 'quiesced', 'official_plugin') as $field) {
            $this->line($field . ': ' . self::scalar($status[$field]));
        }
        foreach (array('cursors', 'totals', 'mismatches', 'anomalies', 'journal', 'rollback') as $field) {
            $this->line($field . ': ' . (string) wp_json_encode($status[$field]));
        }
        foreach ($stale as $claim) {
            $this->line('stale claim: ' . $claim['cycle_key'] . ' age ' . $claim['age_seconds'] . ' s');
        }
        foreach ($warnings as $warning) {
            $this->line('warning: ' . $warning);
        }
        return self::OK;
    }

    /**
     * @param bool $yes Skip the confirmation.
     * @return int Exit code.
     */
    public function release_stale_claims($yes) {
        $stale = StaleClaims::report($this->claims, $this->system->now());
        if ($stale === array()) {
            return $this->done('no stale claims');
        }
        $question = 'Release ' . count($stale) . ' billing claim(s) stuck in "claimed" for over ' . StaleClaims::STALE_SECONDS . ' s? No provider request was sent for them.';
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing released');
        }
        $result = StaleClaims::release($this->claims, $this->system->now());
        foreach ($result['released'] as $key) {
            $this->line('released: ' . $key);
        }
        foreach ($result['skipped'] as $key) {
            $this->line('kept (its worker moved it first): ' . $key);
        }
        if (!$result['ok']) {
            return $this->fail(self::BUSY, 'release stopped: ' . (string) $result['error']);
        }
        return $this->done('released ' . count($result['released']) . ', kept ' . count($result['skipped']));
    }

    /**
     * cut_over → rolled_back → preflight_ok (Task 10); resumes an interrupted rollback.
     *
     * @param bool $yes                 Skip the confirmation.
     * @param bool $reactivate_official Owner consent to reactivate the official install the takeover deactivated.
     * @param bool $deactivate_official Owner consent to deactivate an active official plugin first.
     * @return int Exit code.
     */
    public function rollback($yes, $reactivate_official, $deactivate_official = false) {
        $question = 'Roll the store back to the UPayments identities' . ($reactivate_official ? ' and reactivate the official UPayments plugin' : '')
            . '? Orders paid through SUPCheckout stay paid and keep their payment IDs.';
        return $this->rewind(Rollback::MODE_ROLLBACK, (bool) $yes, (bool) $reactivate_official, $question, (bool) $deactivate_official);
    }

    /**
     * verified → preflight_ok (Task 10); resumes an interrupted abort.
     *
     * @param bool $yes                 Skip the confirmation.
     * @param bool $reactivate_official Owner consent to reactivate the official plugin `run` deactivated.
     * @return int Exit code.
     */
    public function abort($yes, $reactivate_official) {
        $question = 'Abort the verified takeover and remove its copies' . ($reactivate_official ? ' and reactivate the official UPayments plugin' : '') . '?';
        return $this->rewind(Rollback::MODE_ABORT, (bool) $yes, (bool) $reactivate_official, $question);
    }

    /**
     * @param string $mode       Rollback::MODE_ROLLBACK or Rollback::MODE_ABORT.
     * @param bool   $yes        Skip the confirmation.
     * @param bool   $reactivate Consent.
     * @param string $question   Confirmation question.
     * @param bool   $deactivate Consent to deactivate an active official plugin (rollback only).
     * @return int Exit code.
     */
    private function rewind($mode, $yes, $reactivate, $question, $deactivate = false) {
        if (!$this->store instanceof RollbackStore || !$this->system instanceof RollbackSystem) {
            return $this->fail(self::ERROR, $mode . ' needs a rollback-capable store and system');
        }
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $allowed = $mode === Rollback::MODE_ROLLBACK
            ? array(TakeoverState::CUT_OVER, TakeoverState::ROLLED_BACK)
            : array(TakeoverState::VERIFIED);
        $resuming = $state['status'] === TakeoverState::PREFLIGHT_OK && Rollback::mode_of($state) === $mode;
        if (!$resuming && !in_array($state['status'], $allowed, true)) {
            return $this->fail(self::STATE, 'state "' . $state['status'] . '" does not allow ' . $mode);
        }
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing changed');
        }
        if ($mode === Rollback::MODE_ROLLBACK && $state['status'] === TakeoverState::CUT_OVER) {
            $official = $this->retire_official_for_rollback($deactivate);
            if ($official !== self::OK) {
                return $official;
            }
        }
        $rollback = new Rollback($this->store, $this->system);
        $result = $mode === Rollback::MODE_ROLLBACK ? $rollback->run($reactivate) : $rollback->abort($reactivate);
        return $this->rewind_result($mode, $result);
    }

    /**
     * A rollback back-syncs legacy names, which a running official plugin writes too (its
     * own renewal cron ignores the Quiesce lease): it must be inactive first. Deactivated
     * with consent (every install, this site only) and recorded for a later reactivation.
     *
     * @param bool $deactivate Consent.
     * @return int Exit code.
     */
    private function retire_official_for_rollback($deactivate) {
        $official = $this->system->official_plugin();
        if ($official === null) {
            return self::OK;
        }
        if (!$deactivate) {
            return $this->fail(self::BLOCKED, 'the official UPayments plugin (' . $official . ') is active; nothing was rolled back. Rerun with --deactivate-official, or deactivate it first');
        }
        if (!$this->system->deactivate_plugin($official)) {
            return $this->fail(self::ERROR, 'the official UPayments plugin (' . $official . ') could not be deactivated; nothing was rolled back');
        }
        Rollback::record_official_deactivated($official);
        $this->line('official plugin deactivated: ' . $official);
        return self::OK;
    }

    /**
     * Exit-code precedence: an error first (its own code), then conflicts (8), then a failed
     * reactivation (9). Every conflict and the reactivation warning are printed first.
     *
     * @param string               $mode   Mode.
     * @param array<string, mixed> $result Rollback result.
     * @return int Exit code.
     */
    private function rewind_result($mode, array $result) {
        $this->line('takeover ' . $mode . ' steps: ' . implode(', ', $result['steps']));
        foreach ($result['conflicts'] as $conflict) {
            $this->line('conflict: seq ' . $conflict['seq'] . ' ' . $conflict['target'] . ' ' . $conflict['path'] . ' (kept; the owner decides)');
        }
        $not_reactivated = in_array('official_not_reactivated', $result['warnings'], true);
        if ($not_reactivated) {
            $this->line('warning: official_not_reactivated');
        }
        if ($result['error'] !== null) {
            $error = (string) $result['error'];
            $this->line($mode . ' report: ' . (string) wp_json_encode($result['report']));
            if ($error === 'stale_claims') {
                $this->line('run `wp supcheckout takeover release-stale-claims`, then rerun');
            }
            if ($error === 'official_active') {
                $this->line('deactivate the official UPayments plugin (or rerun rollback with --deactivate-official), then rerun');
            }
            $code = isset(self::ROLLBACK_EXIT[$error]) ? self::ROLLBACK_EXIT[$error] : self::ERROR;
            return $this->fail($code, $mode . ' stopped: ' . $error . '; rerun to resume');
        }
        $done = $mode === Rollback::MODE_ROLLBACK ? 'rolled back' : 'aborted';
        if ($result['conflicts'] !== array()) {
            return $this->fail(self::CONFLICTS, $done . ', but ' . count($result['conflicts']) . ' conflict(s) were kept; review them');
        }
        if ($not_reactivated) {
            return $this->fail(self::OFFICIAL_NOT_REACTIVATED, $done . ', but the official UPayments plugin was not reactivated; activate it yourself');
        }
        return $this->done($done . ' (restored ' . $result['restored'] . ')');
    }

    /**
     * idle → preflight_ok → copying; a store already copying or verified resumes as it is.
     *
     * @param string $status Current status.
     * @return int Exit code.
     */
    private function start_copy($status) {
        if ($status === TakeoverState::IDLE && !TakeoverState::transition(TakeoverState::PREFLIGHT_OK)) {
            return $this->fail(self::STATE, 'the takeover state changed meanwhile; rerun');
        }
        if (($status === TakeoverState::IDLE || $status === TakeoverState::PREFLIGHT_OK) && !CopyRunner::begin()) {
            return $this->fail(self::STATE, 'the takeover state changed meanwhile; rerun');
        }
        return self::OK;
    }

    /**
     * Run copy steps in this process until the state is verified. Each step is bounded
     * (at most 50 objects) and persists its cursor, so a killed run resumes on the next.
     *
     * @return int Exit code.
     */
    private function drive_copy() {
        $runner = $this->copy_step !== null ? $this->copy_step : array(new CopyRunner($this->store, array($this->system, 'now')), 'run');
        // Both budgets are per stall: any progress resets them.
        $waited = 0;
        $stopped = 0;
        while (true) {
            TakeoverState::refresh();
            $state = TakeoverState::get();
            if ($state['status'] === TakeoverState::VERIFIED) {
                $this->line('takeover copy: verified');
                return self::OK;
            }
            if ($state['status'] !== TakeoverState::COPYING) {
                return $this->copy_ended($state);
            }
            $result = call_user_func($runner);
            if ($result === CopyRunner::CONTINUE_RUN) {
                $waited = 0;
                $stopped = 0;
                $this->print_progress();
                continue;
            }
            if ($result === CopyRunner::STOPPED) {
                TakeoverState::refresh();
                if (TakeoverState::get()['status'] !== TakeoverState::COPYING) {
                    continue; // Another process moved the state: resolved on the next pass.
                }
                // Still copying, so the runner could not persist its progress (a failing
                // save or state patch). Back off; give up after MAX_STOPPED in a row.
                if (++$stopped >= self::MAX_STOPPED) {
                    return $this->fail(self::ERROR, 'the copy runner stopped ' . $stopped . ' times in a row without progress (its progress could not be saved); check the database and rerun to resume');
                }
            } elseif ($result !== CopyRunner::BUSY && $result !== CopyRunner::RETRY) {
                continue; // VERIFIED, MISMATCH and IDLE are resolved from the state on the next pass.
            }
            if ($waited >= self::MAX_WAIT) {
                return $this->fail(self::BUSY, 'the copy runner stayed busy (another process holds it or an order stayed locked); rerun to resume');
            }
            $this->system->sleep(self::WAIT_POLL);
            $waited += self::WAIT_POLL;
        }
    }

    /**
     * @param array<string, mixed> $state A state that is neither copying nor verified.
     * @return int Exit code.
     */
    private function copy_ended(array $state) {
        if ($state['status'] === TakeoverState::PREFLIGHT_OK && self::mismatch_count($state) > 0) {
            $this->line('mismatches: ' . (string) wp_json_encode($state['mismatches']));
            return $this->fail(self::MISMATCH, 'copy verification found mismatches; the store was not switched');
        }
        return $this->fail(self::STATE, 'the takeover state changed to "' . (string) $state['status'] . '" meanwhile');
    }

    /**
     * @param bool $deactivate_official Consent.
     * @return int Exit code.
     */
    private function cutover($deactivate_official) {
        $cutover = new Cutover($this->store, $this->system);
        $result = $cutover->run($deactivate_official);
        if (!$result['ok']) {
            $error = (string) $result['error'];
            $this->line('cutover report: ' . (string) wp_json_encode($result['report']));
            if ($error === 'stale_claims') {
                $this->line('run `wp supcheckout takeover release-stale-claims`, then rerun');
            }
            $code = isset(self::CUTOVER_EXIT[$error]) ? self::CUTOVER_EXIT[$error] : self::ERROR;
            return $this->fail($code, 'cutover refused: ' . $error . '; the store was not switched');
        }
        $this->line('takeover cutover steps: ' . implode(', ', $result['steps']));
        if (in_array('official_still_active', $result['warnings'], true)) {
            $this->line('warning: official_still_active');
            return $this->fail(self::OFFICIAL_STILL_ACTIVE, 'cut over, but the official UPayments plugin is still active; deactivate it (SUPCheckout stays paused until then)');
        }
        return $this->done('cut over');
    }

    /** @return array{blocking:list<array<string,mixed>>, warnings:list<array<string,mixed>>, facts:list<array<string,mixed>>} */
    private function run_preflight() {
        $report = call_user_func($this->preflight);
        $report = is_array($report) ? $report : array();
        foreach (array('blocking', 'warnings', 'facts') as $list) {
            $report[$list] = isset($report[$list]) && is_array($report[$list]) ? array_values($report[$list]) : array();
        }
        return $report;
    }

    /**
     * @param array<string, list<array<string,mixed>>> $report Preflight report.
     * @return void
     */
    private function print_report(array $report) {
        foreach (array('blocking' => 'BLOCKING', 'warnings' => 'warning', 'facts' => 'fact') as $list => $label) {
            foreach ($report[$list] as $finding) {
                $code = isset($finding['code']) ? (string) $finding['code'] : '';
                $message = isset($finding['message']) ? (string) $finding['message'] : '';
                $this->line($label . ': ' . $code . ': ' . $message);
            }
        }
    }

    /** @return void */
    private function print_progress() {
        $state = TakeoverState::get();
        $totals = $state['totals'];
        $parts = array('phase=' . self::scalar(self::field($state, 'phase', '')), 'cursor=' . $state['cursor']);
        foreach (array('orders', 'users', 'products', 'table_rows') as $total) {
            if (isset($totals[$total])) {
                $parts[] = $total . '=' . self::scalar($totals[$total]);
            }
        }
        $this->line('takeover copy: ' . implode(' ', $parts));
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

    /**
     * @param mixed $value Value.
     * @return string
     */
    private static function scalar($value) {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
    }

    /**
     * @param string $line Line.
     * @return void
     */
    private function line($line) {
        call_user_func($this->out, (string) $line);
    }

    /**
     * @param string $message Message.
     * @return int OK.
     */
    private function done($message) {
        $this->line('Success: ' . $message);
        return self::OK;
    }

    /**
     * @param int    $code    Exit code.
     * @param string $message Message.
     * @return int $code.
     */
    private function fail($code, $message) {
        $this->line('Error: ' . $message . ' (exit ' . $code . ')');
        return $code;
    }
}
