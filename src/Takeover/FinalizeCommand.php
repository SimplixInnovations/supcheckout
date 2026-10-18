<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;

defined('ABSPATH') || exit;

/**
 * `wp supcheckout takeover finalize` (Sub-project 0b Task 11), without WP-CLI: CliCommands
 * delegates here and Cli turns the exit code into the process exit status. The guard, the
 * engine call and the exit-code map (Onboarding::FINALIZE_EXIT) live in Onboarding (0c);
 * this class asks the confirmation and prints.
 *
 * Exit codes (CliCommands' table):
 *  0 OK         finalized (or swept again), or a dry run printed: a dry run only reports
 *               (its would-refuse reasons included) and always exits 0;
 *  1 ERROR      declined confirmation, or an unexpected failure; rerun to resume;
 *  2 BLOCKED    the official plugin is active (it writes legacy names, ignores the lease and
 *               holds its own cron mutex), also on a resume or a sweep of a finalized store;
 *  3 MISMATCH   verification refused before the transition: unreadable journal, disagreeing
 *               token-identity secrets, no new billing table, or a missing or future
 *               cut_over_at (which --force-after-review never overrides); nothing changed;
 *  4 BUSY       the takeover lease is held or was lost, the state changed meanwhile, an
 *               order stayed locked by a payment request, or late writers still recreated
 *               legacy names after the settle time (`removal_unsettled`); rerun later (it
 *               resumes). After the transition the run waits in-process for
 *               CopyRemoval::SETTLE_SECONDS (a `settling` line every 10 s) before its final
 *               rescans, like a rollback;
 *  6 STATE      not cut over, or fewer than 30 days since the cutover without
 *               --force-after-review;
 *  8 CONFLICTS  finalized, but values that did not verify equal (and a legacy billing table
 *               that could not be retired) were kept; review the printed names.
 *
 * Irreversible: the confirmation says so, and only an explicit "y"/"yes" on a terminal (or
 * --yes) confirms. A dry run (--dry-run) asks nothing and writes nothing.
 */
final class FinalizeCommand {
    /** @var Onboarding */
    private $onboarding;
    /** @var callable */
    private $out;
    /** @var callable */
    private $confirm;

    /**
     * @param Onboarding $onboarding The decisions (a finalize-capable store and system to run).
     * @param callable   $out        function (string $line): void.
     * @param callable   $confirm    function (string $question): bool.
     */
    public function __construct(Onboarding $onboarding, $out, $confirm) {
        $this->onboarding = $onboarding;
        $this->out = $out;
        $this->confirm = $confirm;
    }

    /**
     * @param bool $yes                Skip the confirmation.
     * @param bool $force_after_review Allow it before 30 days (owner consent after review).
     * @param bool $dry_run            Print what would happen; write nothing.
     * @param bool $json               Dry run only: print one JSON document.
     * @return int Exit code.
     */
    public function run($yes, $force_after_review, $dry_run, $json = false) {
        if (!$this->onboarding->finalize_capable()) {
            return $this->fail(CliCommands::ERROR, 'finalize needs a finalize-capable store and system');
        }
        if ($dry_run) {
            return $this->print_plan($this->onboarding->finalize_plan(), (bool) $json);
        }
        $guard = $this->onboarding->plan_finalize((bool) $force_after_review);
        if (!$guard->is_ok()) {
            return $this->finish($guard);
        }
        $question = $guard->data()['status'] === TakeoverState::FINALIZED
            ? 'Sweep the remaining legacy UPayments names again (only values verified equal to their SUPCheckout copies are deleted)?'
            : 'Finalize is IRREVERSIBLE: rollback will no longer be possible, and every legacy UPayments name verified equal to its SUPCheckout copy is deleted (values that differ are kept and reported). Finalize now?';
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(CliCommands::ERROR, 'aborted; nothing changed');
        }
        return $this->finish($this->onboarding->finalize((bool) $force_after_review));
    }

    /**
     * @param array<string, mixed> $plan Finalize::plan().
     * @param bool                 $json One JSON document.
     * @return int OK.
     */
    private function print_plan(array $plan, $json) {
        if ($json) {
            $this->line((string) wp_json_encode($plan));
            return CliCommands::OK;
        }
        foreach (Onboarding::finalize_plan_lines($plan) as $line) {
            $this->line($line);
        }
        return CliCommands::OK;
    }

    /**
     * @param Outcome $outcome Outcome.
     * @return int Exit code.
     */
    private function finish(Outcome $outcome) {
        foreach ($outcome->lines() as $line) {
            $this->line($line);
        }
        return $outcome->is_ok() ? $this->done($outcome->message()) : $this->fail($outcome->code(), $outcome->message());
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
        return CliCommands::OK;
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
