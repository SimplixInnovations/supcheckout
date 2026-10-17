<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * `wp supcheckout takeover finalize` (Sub-project 0b Task 11), without WP-CLI: CliCommands
 * delegates here and Cli turns the exit code into the process exit status.
 *
 * Exit codes (CliCommands' table):
 *  0 OK         finalized (or swept again), or a dry run printed;
 *  1 ERROR      declined confirmation, or an unexpected failure; rerun to resume;
 *  2 BLOCKED    the official plugin is active (it writes legacy names and ignores the lease);
 *  3 MISMATCH   verification refused before the transition: unreadable journal, disagreeing
 *               token-identity secrets, or no new billing table; nothing changed;
 *  4 BUSY       the takeover lease is held or was lost, the state changed meanwhile, or an
 *               order stayed locked by a payment request; rerun later (it resumes);
 *  6 STATE      not cut over, or fewer than 30 days since the cutover without
 *               --force-after-review;
 *  8 CONFLICTS  finalized, but values that did not verify equal (and a legacy billing table
 *               that could not be retired) were kept; review the printed names.
 *
 * Irreversible: the confirmation says so, and only an explicit "y"/"yes" on a terminal (or
 * --yes) confirms. A dry run (--dry-run) asks nothing and writes nothing.
 */
final class FinalizeCommand {
    /** Finalize error code => exit code. Anything unlisted is ERROR. */
    private const EXIT = array(
        'busy' => CliCommands::BUSY,
        'lease_lost' => CliCommands::BUSY,
        'transition_refused' => CliCommands::BUSY,
        'removal_busy' => CliCommands::BUSY,
        'official_active' => CliCommands::BLOCKED,
        'not_cut_over' => CliCommands::STATE,
        'too_early' => CliCommands::STATE,
        'journal_unreadable' => CliCommands::MISMATCH,
        'options_mismatch' => CliCommands::MISMATCH,
        'new_table_missing' => CliCommands::MISMATCH,
    );

    /** @var CopyStore */
    private $store;
    /** @var CutoverSystem */
    private $system;
    /** @var callable */
    private $out;
    /** @var callable */
    private $confirm;

    /**
     * @param CopyStore     $store   Store (a FinalizeStore to run).
     * @param CutoverSystem $system  System (a RollbackSystem to run).
     * @param callable      $out     function (string $line): void.
     * @param callable      $confirm function (string $question): bool.
     */
    public function __construct(CopyStore $store, CutoverSystem $system, $out, $confirm) {
        $this->store = $store;
        $this->system = $system;
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
        $finalize = $this->finalizer();
        if ($finalize === null) {
            return $this->fail(CliCommands::ERROR, 'finalize needs a finalize-capable store and system');
        }
        if ($dry_run) {
            return $this->print_plan($finalize->plan(), (bool) $json);
        }
        TakeoverState::refresh();
        $state = TakeoverState::get();
        $question = $this->question($state, (bool) $force_after_review);
        if (is_int($question)) {
            return $question;
        }
        if (!$yes && !call_user_func($this->confirm, $question)) {
            return $this->fail(CliCommands::ERROR, 'aborted; nothing changed');
        }
        return $this->print_result($finalize->run((bool) $force_after_review));
    }

    /**
     * The status field: days until finalize is allowed and, while cut over, the planned
     * removal (a dry run; read-only).
     *
     * @param array<string, mixed> $state TakeoverState::get().
     * @return array{due_at:int, days_left:int, allowed:bool, forced:bool, pending:bool, planned:array<string,int>|null, kept:int}
     */
    public function status_field(array $state) {
        $finalize = $this->finalizer();
        $planned = null;
        $kept = 0;
        if ($finalize !== null && $state['status'] === TakeoverState::CUT_OVER) {
            $plan = $finalize->plan();
            $planned = $plan['removed'];
            $kept = count($plan['kept']);
        } elseif ($state['status'] === TakeoverState::FINALIZED) {
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
        );
    }

    /** @return Finalize|null */
    private function finalizer() {
        if (!$this->store instanceof FinalizeStore || !$this->system instanceof RollbackSystem) {
            return null;
        }
        return new Finalize($this->store, $this->system);
    }

    /**
     * @param array<string, mixed> $state State.
     * @param bool                 $force Owner consent.
     * @return string|int The confirmation question, or the exit code of a refusal.
     */
    private function question(array $state, $force) {
        if ($state['status'] === TakeoverState::FINALIZED) {
            return 'Sweep the remaining legacy UPayments names again (only values verified equal to their SUPCheckout copies are deleted)?';
        }
        if ($state['status'] !== TakeoverState::CUT_OVER) {
            return $this->fail(CliCommands::STATE, 'state "' . $state['status'] . '" does not allow finalize (it needs a cut-over store)');
        }
        $days = Finalize::days_left($state, time());
        if ($days > 0 && !$force) {
            return $this->fail(CliCommands::STATE, 'finalize is allowed in ' . $days . ' day(s) (' . gmdate('Y-m-d', Finalize::due_at($state)) . ' UTC); review `wp supcheckout takeover finalize --dry-run`, then rerun with --force-after-review to finalize earlier');
        }
        $official = $this->system->official_plugin();
        if ($official !== null) {
            return $this->fail(CliCommands::BLOCKED, 'the official UPayments plugin (' . $official . ') is active; deactivate it, then rerun');
        }
        return 'Finalize is IRREVERSIBLE: rollback will no longer be possible, and every legacy UPayments name verified equal to its SUPCheckout copy is deleted (values that differ are kept and reported). Finalize now?';
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
        $this->line('dry run: nothing is written');
        foreach ($plan['would_refuse'] as $code) {
            $this->line('would refuse: ' . $code);
        }
        if ($plan['status'] === TakeoverState::CUT_OVER) {
            $this->line('finalize allowed in ' . $plan['days_left'] . ' day(s)');
        }
        $this->line('would remove: ' . self::counts($plan['removed']));
        $this->print_kept($plan['kept'], 'would keep');
        $this->line('legacy billing table: ' . $plan['table']);
        $this->print_reported($plan['reported']);
        return CliCommands::OK;
    }

    /**
     * Exit-code precedence: an error first (its own code), then kept values (8).
     *
     * @param array<string, mixed> $result Finalize::run().
     * @return int Exit code.
     */
    private function print_result(array $result) {
        $this->line('takeover finalize steps: ' . implode(', ', $result['steps']));
        $this->line('removed: ' . self::counts($result['removed']));
        $this->print_kept($result['kept'], 'kept');
        $this->print_reported($result['reported']);
        if ($result['error'] !== null) {
            $error = (string) $result['error'];
            $this->line('finalize report: ' . (string) wp_json_encode($result['report']));
            $code = isset(self::EXIT[$error]) ? self::EXIT[$error] : CliCommands::ERROR;
            return $this->fail($code, 'finalize stopped: ' . $error . '; rerun to resume');
        }
        if ($result['kept'] !== array()) {
            return $this->fail(CliCommands::CONFLICTS, 'finalized, but ' . count($result['kept']) . ' group(s) of legacy values were kept; review them');
        }
        return $this->done('finalized' . ($result['forced'] ? ' (forced after review)' : ''));
    }

    /**
     * @param list<array<string, mixed>> $kept  Kept groups.
     * @param string                     $label Line label.
     * @return void
     */
    private function print_kept(array $kept, $label) {
        foreach ($kept as $group) {
            $ids = $group['ids'] === array() ? '' : ' (ids ' . implode(',', array_map('intval', $group['ids'])) . ($group['count'] > count($group['ids']) ? ',...' : '') . ')';
            $this->line($label . ': ' . $group['kind'] . ' ' . $group['name'] . ' ' . $group['reason'] . ' x' . (int) $group['count'] . $ids);
        }
    }

    /**
     * @param array<string, mixed> $reported Finalize's reported-only items.
     * @return void
     */
    private function print_reported(array $reported) {
        if (isset($reported['block_checkout_page']['page_id'])) {
            $this->line('reported (never removed): the official "Checkout (Block)" page ' . (int) $reported['block_checkout_page']['page_id'] . ' (' . (string) $reported['block_checkout_page']['status'] . '); delete it yourself if unused');
        }
        foreach (isset($reported['official_tables']) && is_array($reported['official_tables']) ? $reported['official_tables'] : array() as $table) {
            $this->line('reported (never removed): table ' . (string) $table);
        }
        if (isset($reported['callback_route'])) {
            $this->line('kept forever: the legacy callback route ' . (string) $reported['callback_route']);
        }
    }

    /**
     * @param array<string, int> $counts Counter => count.
     * @return string
     */
    private static function counts(array $counts) {
        $parts = array();
        foreach ($counts as $name => $count) {
            $parts[] = $name . '=' . (int) $count;
        }
        return implode(' ', $parts);
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
