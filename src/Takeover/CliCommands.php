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
 *  2 BLOCKED               Preflight reported a blocking finding; nothing changed;
 *  3 MISMATCH              verification found a mismatch (copy runner or cutover); the
 *                          store is not cut over; fix the reported names and rerun;
 *  4 BUSY                  another process holds the copy-runner lock or the takeover
 *                          lease, the lease was lost, or claims stayed in flight; rerun later;
 *  5 STALE_CLAIMS          a billing claim is stuck in `claimed` past 600 s; run
 *                          `release-stale-claims`, then rerun;
 *  6 STATE                 the takeover state does not allow this (rolled_back, finalized,
 *                          unreadable, or changed by another process);
 *  7 OFFICIAL_STILL_ACTIVE cut over, but the official plugin could not be deactivated:
 *                          SUPCheckout stays paused until it is; deactivate it or rerun.
 *
 * `run` is rerunnable after a crash at any step: every step resumes from persisted state.
 * idle → preflight_ok → copying (CopyRunner, cursor per object) → verified → cut_over
 * (Cutover, which is idempotent before its compare-and-swap and only retries the
 * deactivation after it). Preflight runs again on every rerun before cutover, so a store
 * that became blocked meanwhile is never switched.
 *
 * Official-plugin deactivation timing (--deactivate-official): exactly where the plan puts
 * it, Cutover's last step, after the verified → cut_over compare-and-swap. Until then the
 * official plugin is the only runtime serving the shared `upayments` gateway and its
 * `wc_upayments` callback route (SUPCheckout is paused in every web request), so no two
 * runtimes ever answer one callback. Deactivating earlier would hand checkout to a runtime
 * still in the copy window for the whole copy; deactivating later would leave a cut-over
 * store whose reads prefer new names while the official plugin keeps writing legacy ones.
 * The remaining window is the final verify pass to the deactivation (one process,
 * seconds): payment truth is unaffected, because paid state is the WooCommerce order
 * status set only after authenticated provider status by whichever plugin handles the
 * callback, and that field is never renamed or copied; a legacy-only meta written in that
 * window is still read through Keys' legacy fallback when no new value exists.
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

    /** Seconds between retries while another process holds the copy-runner lock. */
    public const WAIT_POLL = 5;
    /** Seconds `run` waits for a busy copy runner in total before exiting BUSY. */
    public const MAX_WAIT = 300;

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

    /**
     * @param callable        $preflight function (): array, Preflight::run()'s report.
     * @param CopyStore       $store     Orders, users, products and billing tables.
     * @param CutoverSystem   $system    Cron, official plugin, clock.
     * @param StaleClaimStore $claims    Billing-attempts table in use.
     * @param callable        $out       function (string $line): void.
     * @param callable        $confirm   function (string $question): bool.
     */
    public function __construct($preflight, CopyStore $store, CutoverSystem $system, StaleClaimStore $claims, $out, $confirm) {
        $this->preflight = $preflight;
        $this->store = $store;
        $this->system = $system;
        $this->claims = $claims;
        $this->out = $out;
        $this->confirm = $confirm;
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
     * @param bool $deactivate_official Owner consent to deactivate the official plugin at cutover.
     * @return int Exit code.
     */
    public function run($yes, $deactivate_official) {
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        if ($status === TakeoverState::CUT_OVER) {
            return $deactivate_official ? $this->cutover(true) : $this->done('already cut over; nothing to do');
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
        return $this->cutover((bool) $deactivate_official);
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
        $status = array(
            'status' => $state['status'],
            'cut_over_at' => $state['cut_over_at'],
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
        foreach (array('cursors', 'totals', 'mismatches', 'anomalies', 'journal') as $field) {
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
        $runner = new CopyRunner($this->store, array($this->system, 'now'));
        $waited = 0;
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
            $result = $runner->run();
            if ($result === CopyRunner::CONTINUE_RUN) {
                $this->print_progress();
                continue;
            }
            if ($result === CopyRunner::BUSY || $result === CopyRunner::RETRY) {
                if ($waited >= self::MAX_WAIT) {
                    return $this->fail(self::BUSY, 'the copy runner stayed busy (another process holds it or an order stayed locked); rerun to resume');
                }
                $this->system->sleep(self::WAIT_POLL);
                $waited += self::WAIT_POLL;
            }
            // VERIFIED, MISMATCH, STOPPED and IDLE are resolved from the state on the next pass.
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
        foreach ($result['steps'] as $step) {
            if ($step === 'official_deactivated') {
                $this->line('takeover cutover: official_deactivated');
            }
        }
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
