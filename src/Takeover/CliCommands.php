<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;

defined('ABSPATH') || exit;

/**
 * What `wp supcheckout takeover` does (Sub-project 0b Task 9), without WP-CLI: Cli binds
 * it to WordPress and turns the returned exit code into the process exit status.
 *
 * The decisions, codes and messages live in Onboarding (Sub-project 0c); this class asks
 * the confirmation questions, drives the copy loop in process and prints each Outcome.
 *
 * Exit codes (stable; 0c and scripts rely on them):
 *  0 OK                    done (or nothing to do);
 *  1 ERROR                 declined confirmation, or an unexpected failure; rerun;
 *  2 BLOCKED               Preflight reported a blocking finding, or the official plugin is
 *                          active (without --deactivate-official, or reactivated during the
 *                          cutover: Cutover's `official_active`); not cut over;
 *  3 MISMATCH              verification found a mismatch (copy runner or cutover); the
 *                          store is not cut over; fix the reported names and rerun (for
 *                          `finalize`: its pre-transition verification refused);
 *  4 BUSY                  another process holds the copy-runner lock or the takeover
 *                          lease, the lease was lost, or claims stayed in flight; rerun later;
 *  5 STALE_CLAIMS          a billing claim is stuck in `claimed` past 600 s; run
 *                          `release-stale-claims`, then rerun;
 *  6 STATE                 the takeover state does not allow this (rolled_back, finalized,
 *                          unreadable, or changed by another process; `finalize` before
 *                          30 days without --force-after-review);
 *  7 OFFICIAL_STILL_ACTIVE a store already cut over whose official plugin is active again:
 *                          without --deactivate-official, or the deactivation failed;
 *                          SUPCheckout stays paused; deactivate it and rerun;
 *  8 CONFLICTS             `rollback`/`abort` completed, but later edits were kept
 *                          (journal "later edits win"), or `finalize` completed but kept
 *                          legacy values that did not verify equal; review the printed names;
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
 * `finalize` (cut_over → finalized, irreversible) runs FinalizeCommand / Finalize.
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
    public const OK = Outcome::OK;
    public const ERROR = Outcome::ERROR;
    public const BLOCKED = Outcome::BLOCKED;
    public const MISMATCH = Outcome::MISMATCH;
    public const BUSY = Outcome::BUSY;
    public const STALE_CLAIMS = Outcome::STALE_CLAIMS;
    public const STATE = Outcome::STATE;
    public const OFFICIAL_STILL_ACTIVE = Outcome::OFFICIAL_STILL_ACTIVE;
    public const CONFLICTS = Outcome::CONFLICTS;
    public const OFFICIAL_NOT_REACTIVATED = Outcome::OFFICIAL_NOT_REACTIVATED;

    /** Seconds between retries while another process holds the copy-runner lock. */
    public const WAIT_POLL = 5;
    /** Seconds `run` waits per stall (no progress) for a busy copy runner before exiting BUSY. */
    public const MAX_WAIT = 300;
    /** Consecutive STOPPED steps with the state still `copying` before `run` exits ERROR. */
    public const MAX_STOPPED = 3;

    /** @var Onboarding */
    private $onboarding;
    /** @var CutoverSystem */
    private $system;
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
        $this->onboarding = new Onboarding($preflight, $store, $system, $claims);
        $this->system = $system;
        $this->out = $out;
        $this->confirm = $confirm;
        $this->copy_step = is_callable($copy_step) ? $copy_step : null;
    }

    /**
     * @param bool $json Print the report as one JSON document.
     * @return int Exit code.
     */
    public function preflight($json) {
        $outcome = $this->onboarding->check_preflight();
        if ($json) {
            $this->line((string) wp_json_encode($outcome->data()['report']));
        } else {
            $this->print_lines($outcome);
        }
        return $outcome->code();
    }

    /**
     * @param bool $yes                 Skip the confirmation.
     * @param bool $deactivate_official Owner consent to deactivate the official plugin before cutover.
     * @return int Exit code.
     */
    public function run($yes, $deactivate_official) {
        $plan = $this->onboarding->plan_run((bool) $deactivate_official);
        if (!$plan->is_ok() || $plan->data()['next'] === 'done') {
            return $this->finish($plan);
        }
        if ($plan->data()['next'] === 'cutover_tail') {
            return $this->finish($this->onboarding->cutover(true));
        }
        $preflight = $this->onboarding->check_preflight();
        if (!$preflight->is_ok()) {
            return $this->finish($preflight);
        }
        $question = $deactivate_official
            ? 'Copy every UPayments record, switch the store to SUPCheckout and deactivate the official UPayments plugin?'
            : 'Copy every UPayments record and switch the store to SUPCheckout?';
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing changed');
        }
        $started = $this->step($this->onboarding->start_copy());
        if ($started !== self::OK) {
            return $started;
        }
        $copied = $this->drive_copy();
        if ($copied !== self::OK) {
            return $copied;
        }
        $official = $this->step($this->onboarding->retire_official((bool) $deactivate_official, 'switch'));
        if ($official !== self::OK) {
            return $official;
        }
        return $this->finish($this->onboarding->cutover((bool) $deactivate_official));
    }

    /**
     * @param bool $json Print one JSON document.
     * @return int Exit code.
     */
    public function status($json) {
        $status = $this->onboarding->status();
        if ($json) {
            $this->line((string) wp_json_encode($status));
            return self::OK;
        }
        foreach (array('status', 'phase', 'cursor', 'quiesced', 'official_plugin') as $field) {
            $this->line($field . ': ' . self::scalar($status[$field]));
        }
        foreach (array('cursors', 'totals', 'mismatches', 'anomalies', 'journal', 'rollback', 'finalize') as $field) {
            $this->line($field . ': ' . (string) wp_json_encode($status[$field]));
        }
        foreach ($status['stale_claims'] as $claim) {
            $this->line('stale claim: ' . $claim['cycle_key'] . ' age ' . $claim['age_seconds'] . ' s');
        }
        foreach ($status['warnings'] as $warning) {
            $this->line('warning: ' . $warning);
        }
        return self::OK;
    }

    /**
     * @param bool $yes Skip the confirmation.
     * @return int Exit code.
     */
    public function release_stale_claims($yes) {
        $stale = $this->onboarding->stale_claims();
        if ($stale === array()) {
            // Nothing observed, nothing confirmed: the release path never runs.
            return $this->done('no stale claims');
        }
        $question = 'Release ' . count($stale) . ' billing claim(s) stuck in "claimed" for over ' . StaleClaims::STALE_SECONDS . ' s? No provider request was sent for them.';
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing released');
        }
        return $this->finish($this->onboarding->release_stale_claims(true));
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
     * A rollback from cut_over first retires an active official plugin (with consent): it
     * writes the same legacy names the rollback back-syncs and ignores the Quiesce lease.
     *
     * @param string $mode       Rollback::MODE_ROLLBACK or Rollback::MODE_ABORT.
     * @param bool   $yes        Skip the confirmation.
     * @param bool   $reactivate Consent.
     * @param string $question   Confirmation question.
     * @param bool   $deactivate Consent to deactivate an active official plugin (rollback only).
     * @return int Exit code.
     */
    private function rewind($mode, $yes, $reactivate, $question, $deactivate = false) {
        $plan = $this->onboarding->plan_rewind($mode);
        if (!$plan->is_ok()) {
            return $this->finish($plan);
        }
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(self::ERROR, 'aborted; nothing changed');
        }
        if ($plan->data()['retire_official']) {
            $official = $this->step($this->onboarding->retire_official($deactivate, 'rollback'));
            if ($official !== self::OK) {
                return $official;
            }
        }
        return $this->finish($this->onboarding->rewind($mode, $reactivate));
    }

    /**
     * cut_over → finalized (Task 11, irreversible); resumes an interrupted finalize and
     * sweeps a finalized store again.
     *
     * @param bool $yes                Skip the confirmation.
     * @param bool $force_after_review Allow it before 30 days (owner consent after review).
     * @param bool $dry_run            Print what would be removed and kept; write nothing.
     * @param bool $json               Dry run only: print one JSON document.
     * @return int Exit code.
     */
    public function finalize($yes, $force_after_review, $dry_run, $json = false) {
        return (new FinalizeCommand($this->onboarding, $this->out, $this->confirm))->run((bool) $yes, (bool) $force_after_review, (bool) $dry_run, (bool) $json);
    }

    /**
     * Run copy steps in this process until the state is verified. Each step is bounded
     * (at most 50 objects) and persists its cursor, so a killed run resumes on the next.
     *
     * @return int Exit code.
     */
    private function drive_copy() {
        $runner = $this->copy_step !== null ? $this->copy_step : array($this->onboarding, 'copy_step');
        // Both budgets are per stall: any progress resets them.
        $waited = 0;
        $stopped = 0;
        while (true) {
            $ended = $this->onboarding->copy_result();
            if ($ended !== null) {
                return $this->step($ended);
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
     * An intermediate step: print its lines, and the error when it failed (no `Success:`
     * line on success; the command goes on).
     *
     * @param Outcome $outcome Outcome.
     * @return int Exit code.
     */
    private function step(Outcome $outcome) {
        $this->print_lines($outcome);
        return $outcome->is_ok() ? self::OK : $this->fail($outcome->code(), $outcome->message());
    }

    /**
     * The final step: print its lines, then `Success: ` or `Error: ` with its message.
     *
     * @param Outcome $outcome Outcome.
     * @return int Exit code.
     */
    private function finish(Outcome $outcome) {
        $this->print_lines($outcome);
        return $outcome->is_ok() ? $this->done($outcome->message()) : $this->fail($outcome->code(), $outcome->message());
    }

    /**
     * @param Outcome $outcome Outcome.
     * @return void
     */
    private function print_lines(Outcome $outcome) {
        foreach ($outcome->lines() as $line) {
            $this->line($line);
        }
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
