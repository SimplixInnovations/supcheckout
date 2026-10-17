<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Guarded, irreversible finalize (cut_over → finalized), Sub-project 0b Task 11. Never marks,
 * unmarks or re-verifies a payment and never rewrites an order's payment_method.
 *
 * run():
 *  1. only from cut_over, and only Keys/TakeoverState::FINALIZE_AFTER_DAYS after cut_over_at
 *     unless the owner forces it after review (`too_early` otherwise);
 *  2. take the Quiesce lease (claims and the scheduler defer) and re-read the state under it;
 *     an active official plugin refuses (`official_active`: it writes legacy names and
 *     ignores the lease, so a legacy value could change between verification and removal);
 *  3. verify: the journal is readable (`journal_unreadable`), the two token-identity secrets
 *     agree (`options_mismatch`), and the new billing table exists whenever the legacy one
 *     does (`new_table_missing`); record what is reported only (the official 3.0.0
 *     "Checkout (Block)" page, the official tables, the permanent legacy callback route);
 *  4. renew the lease, persist the removal start, check holds() right before the
 *     compare-and-swap, transition cut_over → finalized, flush caches, release the lease;
 *  5. remove the legacy names (FinalizeRemoval), persisting progress after every unit; then
 *     settle exactly as a rollback's copy removal does: wait, bounded with a `settling`
 *     progress line per poll, until CopyRemoval::SETTLE_SECONDS have passed since the
 *     transition (a request that read cut_over before it still dual-writes legacy names until
 *     it ends), and run full rescans (not cursor-bound) until one removes nothing, at most
 *     CopyRemoval::MAX_RESCANS (`removal_unsettled`, exit 4: rerun later);
 *  6. retire the legacy billing-attempts table under a fresh lease, after the transition:
 *     drain legacy claims (bounded); keep it while any legacy row is `claimed` or
 *     `dispatching`, any legacy row was updated after the cutover's sync (cut_over_at), or
 *     any legacy row is missing from the new table or further along than its new row
 *     (claimed < dispatching < held < resolved; unknown states fail closed); otherwise drop
 *     it. While cut over only the new table is written, so the legacy table is a stale
 *     subset and differing checksums are expected (reported for information only).
 *
 * Step order (why the transition comes first): from `finalized` on, runtime writes reach
 * the new names only, a runtime delete removes both names (Keys::delete_names()), lock
 * helpers stop dual-acquiring and GET_LOCK() moves to the new names. The removal then
 * deletes a legacy name only where the new name holds the identical value list, under the
 * order lock for orders, so every read (new name first, legacy fallback) returns the same
 * value before and after each deletion, and nothing recreates a removed legacy name. A crash
 * before step 4's compare-and-swap leaves `cut_over` with nothing removed (rerun verifies
 * again); after it, `finalized` with a pending removal that the next run resumes from the
 * persisted cursor. Removing first and transitioning last would instead let a dual-writing
 * cut-over runtime recreate legacy names behind the removal.
 *
 * Irreversible: TakeoverState allows nothing out of `finalized`, so Rollback refuses. A run
 * on a finalized store sweeps again (the same verified-equal removal; it picks up legacy
 * names a straggler wrote and retries a kept table).
 *
 * Never removed: the legacy callback route (served forever by LegacyCallbackListener),
 * generic gateway-template option names another plugin may own
 * (FinalizeRemoval::SHARED_OPTIONS, reported), the gateway settings sources
 * (woocommerce_upayments_settings is a protected identity and
 * keeping it is fail-safe: settings reads take the new option first), translated
 * third-party values, other plugins' data, the official tables and the "Checkout (Block)"
 * page (reported only). The takeover's own third-party backups exist only for Rollback and
 * are removed.
 *
 * Result: {ok, removed, kept, reported, forced, error, steps, report}; `ok` only with no
 * error and nothing kept. Reports carry names, IDs and counts, never a value.
 */
final class Finalize {
    /** State field holding the removal progress. */
    public const PROGRESS = 'finalize';

    /** Attempts on an order whose lock a payment request holds. */
    private const LOCK_ATTEMPTS = 3;
    private const SECONDS_PER_DAY = 86400;

    /** @var FinalizeStore */
    private $store;
    /** @var RollbackSystem */
    private $system;
    /** @var QuiesceLease|null */
    private $lease = null;
    /** @var list<string> */
    private $steps = array();
    /** @var array<string, mixed> */
    private $report = array();
    /** @var array<string, int> */
    private $removed;
    /** @var list<array<string, mixed>> */
    private $kept = array();
    /** @var array<string, mixed> */
    private $reported = array();
    /** @var int Removal pass: 1 is the cursor pass, 2.. are full rescans. */
    private $pass = 1;
    /** @var int Late legacy names the current rescan removed. */
    private $found = 0;
    /** @var int System time of the transition (start of the settle barrier). */
    private $transitioned_at = 0;
    /** @var bool A rescan came back clean: only the table retirement is left. */
    private $settled = false;

    /**
     * @param FinalizeStore|null  $store  Store; WordPress when omitted.
     * @param RollbackSystem|null $system System; WordPress when omitted.
     */
    public function __construct($store = null, $system = null) {
        $this->store = $store instanceof FinalizeStore ? $store : new WordPressCopyStore();
        $this->system = $system instanceof RollbackSystem ? $system : new WordPressCutoverSystem();
        $this->removed = FinalizeRemoval::no_removals();
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return int When finalize is allowed without --force-after-review (0 before any cutover).
     */
    public static function due_at(array $state) {
        $cut_over_at = isset($state['cut_over_at']) ? (int) $state['cut_over_at'] : 0;
        return $cut_over_at > 0 ? $cut_over_at + TakeoverState::FINALIZE_AFTER_DAYS * self::day() : 0;
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @param int                  $now   Unix time.
     * @return int Whole days until finalize is allowed (0 once it is).
     */
    public static function days_left(array $state, $now) {
        $due = self::due_at($state);
        if ($due === 0) {
            return TakeoverState::FINALIZE_AFTER_DAYS;
        }
        return max(0, (int) ceil(($due - (int) $now) / self::day()));
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return bool A finalized store whose legacy removal has not completed.
     */
    public static function pending(array $state) {
        return $state['status'] === TakeoverState::FINALIZED
            && isset($state[self::PROGRESS]['pending']) && $state[self::PROGRESS]['pending'] === true;
    }

    /**
     * @param bool $force_after_review Allow it before FINALIZE_AFTER_DAYS (owner consent after review).
     * @return array{ok:bool, removed:array<string,int>, kept:list<array<string,mixed>>, reported:array<string,mixed>, forced:bool, error:string|null, steps:list<string>, report:array<string,mixed>}
     */
    public function run($force_after_review) {
        TakeoverState::refresh();
        $state = TakeoverState::get();
        if ($state['status'] === TakeoverState::FINALIZED) {
            return $this->sweep($state);
        }
        if ($state['status'] !== TakeoverState::CUT_OVER) {
            return $this->result('not_cut_over');
        }
        if (!$this->allowed($state, (bool) $force_after_review)) {
            return $this->result('too_early');
        }
        $this->lease = QuiesceLease::acquire($this->system);
        if ($this->lease === null) {
            return $this->result('busy');
        }
        try {
            $error = $this->switch_state((bool) $force_after_review);
            if ($error !== null) {
                return $this->result($error);
            }
        } catch (\Throwable $e) {
            $this->report['exception'] = get_class($e);
            return $this->result('error');
        } finally {
            $this->lease->release();
            $this->lease = null;
        }
        return $this->resume();
    }

    /**
     * Dry run: what run() would refuse, remove and keep. Read-only: no lease, lock, journal
     * entry, state patch or delete.
     *
     * @return array{ok:bool, would_refuse:list<string>, status:string, due_at:int, days_left:int, removed:array<string,int>, kept:list<array<string,mixed>>, reported:array<string,mixed>, table:string}
     */
    public function plan() {
        $state = TakeoverState::get();
        $refuse = array();
        if ($state['status'] !== TakeoverState::CUT_OVER && $state['status'] !== TakeoverState::FINALIZED) {
            $refuse[] = 'not_cut_over';
        } elseif ($state['status'] === TakeoverState::CUT_OVER && !$this->allowed($state, false)) {
            $refuse[] = 'too_early';
        }
        $refuse = array_merge($refuse, $this->verification_failures());
        $this->reported = $this->capture_reported();
        $removal = FinalizeRemoval::start();
        while ($removal['phase'] !== FinalizeRemoval::PHASE_TABLE && $removal['phase'] !== FinalizeRemoval::PHASE_DONE) {
            $out = FinalizeRemoval::step($this->store, $this->system, $removal, false);
            $this->absorb($out);
            if ($out['status'] !== FinalizeRemoval::CONTINUE_RUN) {
                break;
            }
            $removal = $out['progress'];
        }
        $table = 'absent';
        if ($this->store->table_exists(CopyStore::TABLE_LEGACY)) {
            list($verdict, $rows) = $this->table_verdict($state);
            $table = $verdict === null ? 'drop' : 'keep';
            $this->retire_outcome($verdict, $rows, false);
        }
        return array(
            'ok' => $refuse === array(),
            'would_refuse' => $refuse,
            'status' => (string) $state['status'],
            'due_at' => self::due_at($state),
            'days_left' => self::days_left($state, time()),
            'removed' => $this->removed,
            'kept' => $this->kept,
            'reported' => $this->reported,
            'table' => $table,
        );
    }

    /**
     * @param array<string, mixed> $state State.
     * @param bool                 $force Owner consent.
     * @return bool
     */
    private function allowed(array $state, $force) {
        $this->report['due_at'] = self::due_at($state);
        $this->report['days_left'] = self::days_left($state, time());
        return $force || ($this->report['due_at'] > 0 && time() >= $this->report['due_at']);
    }

    /**
     * Steps 2-4 under the lease.
     *
     * @param bool $force Owner consent.
     * @return string|null Error code, or null once finalized.
     */
    private function switch_state($force) {
        // The status read above was unlocked: re-read it under the lease.
        TakeoverState::refresh();
        $state = TakeoverState::get();
        if ($state['status'] !== TakeoverState::CUT_OVER) {
            return 'not_cut_over';
        }
        if (!$this->allowed($state, $force)) {
            return 'too_early';
        }
        $this->step('quiesced');
        $failures = $this->verification_failures();
        if ($failures !== array()) {
            return $failures[0];
        }
        $this->reported = $this->capture_reported();
        $this->step('verified');
        if (!$this->lease->maybe_renew() || !$this->lease->holds()) {
            return 'lease_lost';
        }
        // The settle barrier counts from here (CopyRemoval::SETTLE_SECONDS).
        $this->transitioned_at = $this->system->now();
        if (!TakeoverState::patch(array(self::PROGRESS => $this->progress(FinalizeRemoval::start(), true)), TakeoverState::CUT_OVER)) {
            return 'transition_refused';
        }
        // Immediately before the compare-and-swap: the lease must still be ours.
        if (!$this->lease->holds()) {
            return 'lease_lost';
        }
        if (!TakeoverState::transition(TakeoverState::FINALIZED, $force)) {
            return 'transition_refused';
        }
        $this->step('finalized');
        TakeoverState::refresh();
        $this->step('caches_flushed');
        return null;
    }

    /**
     * Pre-transition checks, in refusal order.
     *
     * @return list<string> Error codes.
     */
    private function verification_failures() {
        $failures = array();
        if ($this->official_active()) {
            $failures[] = 'official_active';
        }
        try {
            Journal::entries();
        } catch (\Throwable $e) {
            $failures[] = 'journal_unreadable';
        }
        if (!BackSync::secret_agrees()) {
            $failures[] = 'options_mismatch';
        }
        if ($this->store->table_exists(CopyStore::TABLE_LEGACY) && !$this->store->table_exists(CopyStore::TABLE_NEW)) {
            $failures[] = 'new_table_missing';
        }
        return $failures;
    }

    /**
     * @return bool True (and reported) while an official plugin install is active: it writes
     *              legacy names, ignores the lease and holds its own cron mutex
     *              (`upayments_cron_lock`), so nothing it owns may be removed under it.
     */
    private function official_active() {
        $official = $this->system->official_plugin();
        if ($official === null) {
            return false;
        }
        $this->report['official'] = $official;
        return true;
    }

    /**
     * Reported, never removed (read before the removal deletes the page's option).
     *
     * @return array{block_checkout_page: array{page_id:int, status:string}|null, official_tables: list<string>, shared_options: list<string>, callback_route: string}
     */
    private function capture_reported() {
        $page = null;
        $page_id = get_option(Keys::OFFICIAL_BLOCK_CHECKOUT_PAGE_OPTION, null);
        if (is_numeric($page_id) && (int) $page_id > 0) {
            $status = $this->store->post_status((int) $page_id);
            $page = $status === null ? null : array('page_id' => (int) $page_id, 'status' => (string) $status);
        }
        $tables = array();
        foreach (Keys::official_only()['tables'] as $table) {
            if ($this->store->named_table_exists($table)) {
                $tables[] = $table;
            }
        }
        $shared = array();
        foreach (FinalizeRemoval::SHARED_OPTIONS as $name) {
            if (get_option($name, null) !== null) {
                $shared[] = $name;
            }
        }
        return array('block_checkout_page' => $page, 'official_tables' => $tables, 'shared_options' => $shared, 'callback_route' => Keys::CALLBACK_ROUTE_LEGACY);
    }

    /**
     * A finalized store: resume a pending removal, or start a new sweep.
     *
     * @param array<string, mixed> $state State.
     * @return array{ok:bool, removed:array<string,int>, kept:list<array<string,mixed>>, reported:array<string,mixed>, forced:bool, error:string|null, steps:list<string>, report:array<string,mixed>}
     */
    private function sweep(array $state) {
        if ($this->official_active()) {
            return $this->result('official_active');
        }
        if (self::pending($state)) {
            return $this->resume();
        }
        $previous = isset($state[self::PROGRESS]['reported']) && is_array($state[self::PROGRESS]['reported']) ? $state[self::PROGRESS]['reported'] : array();
        $this->reported = $previous + $this->capture_reported();
        // A sweep settles from the original transition (a sweep right after it still waits).
        $this->transitioned_at = isset($state[self::PROGRESS]['transitioned_at']) ? (int) $state[self::PROGRESS]['transitioned_at'] : $this->system->now() - CopyRemoval::SETTLE_SECONDS;
        if (!TakeoverState::patch(array(self::PROGRESS => $this->progress(FinalizeRemoval::start(), true)), TakeoverState::FINALIZED)) {
            return $this->result('transition_refused');
        }
        $this->step('sweep');
        return $this->resume();
    }

    /**
     * Steps 5-6, resumed from the persisted progress.
     *
     * @return array{ok:bool, removed:array<string,int>, kept:list<array<string,mixed>>, reported:array<string,mixed>, forced:bool, error:string|null, steps:list<string>, report:array<string,mixed>}
     */
    private function resume() {
        if ($this->official_active()) {
            return $this->result('official_active');
        }
        try {
            TakeoverState::refresh();
            $progress = self::progress_of(TakeoverState::get());
            $this->removed = array_merge(FinalizeRemoval::no_removals(), isset($progress['removed']) && is_array($progress['removed']) ? array_map('intval', $progress['removed']) : array());
            $this->kept = FinalizeRemoval::merge_kept(array(), isset($progress['kept']) && is_array($progress['kept']) ? $progress['kept'] : array());
            $this->reported = isset($progress['reported']) && is_array($progress['reported']) ? $progress['reported'] : $this->reported;
            // Pass 1 is the cursor removal; passes 2.. are full rescans after the settle time.
            $this->pass = isset($progress['pass']) ? max(1, (int) $progress['pass']) : 1;
            $this->found = isset($progress['found']) ? (int) $progress['found'] : 0;
            $this->transitioned_at = isset($progress['transitioned_at']) ? (int) $progress['transitioned_at'] : $this->system->now();
            $this->settled = !empty($progress['settled']);
            $removal = isset($progress['removal']) && is_array($progress['removal']) ? $progress['removal'] : FinalizeRemoval::start();
            $error = $this->remove($removal);
            if ($error !== null) {
                return $this->result($error);
            }
        } catch (\Throwable $e) {
            $this->report['exception'] = get_class($e);
            return $this->result('error');
        }
        return $this->result(null);
    }

    /**
     * Every legacy-name phase in passes (the settle barrier, as CopyRemoval's for a rollback),
     * then the table retirement once a rescan came back clean.
     *
     * @param array<string, mixed> $removal Removal progress.
     * @return string|null Error code.
     */
    private function remove(array $removal) {
        if ($this->official_active()) {
            return 'official_active';
        }
        $locked = 0;
        while (true) {
            $phase = isset($removal['phase']) ? (string) $removal['phase'] : FinalizeRemoval::PHASE_CRON;
            if ($phase === FinalizeRemoval::PHASE_DONE) {
                break;
            }
            if ($phase === FinalizeRemoval::PHASE_TABLE && !$this->settled) {
                $next = $this->end_pass();
                if ($next === 'removal_unsettled') {
                    // The next run starts a fresh rescan (the settle time has long passed).
                    $this->pass = 2;
                    $this->found = 0;
                    $this->kept = array();
                    return $this->save_progress(FinalizeRemoval::start(), true) ? $next : 'transition_refused';
                }
                $removal = $next === true ? $removal : FinalizeRemoval::start();
                if (!$this->save_progress($removal, true)) {
                    return 'transition_refused';
                }
                continue;
            }
            $out = $phase === FinalizeRemoval::PHASE_TABLE ? $this->retire_table() : FinalizeRemoval::step($this->store, $this->system, $removal, true);
            $this->absorb($out);
            if (in_array($out['status'], array('busy', 'lease_lost'), true)) {
                return $out['status'];
            }
            $this->found += ($this->pass > 1 && $phase !== FinalizeRemoval::PHASE_TABLE) ? array_sum($out['removed']) : 0;
            // Units done before a lock or a failure stay done: persist them first.
            $removal = $out['progress'];
            if (!$this->save_progress($removal, true)) {
                return 'transition_refused';
            }
            if ($out['status'] === FinalizeRemoval::LOCKED) {
                if (++$locked >= self::LOCK_ATTEMPTS) {
                    return 'removal_busy';
                }
                $this->system->sleep(1);
                continue;
            }
            if ($out['status'] === FinalizeRemoval::FAILED) {
                $this->report['removal_failed'] = $phase;
                return 'removal_incomplete';
            }
            $locked = 0;
            if ($removal['phase'] !== $phase && ($this->pass === 1 || $phase === FinalizeRemoval::PHASE_TABLE)) {
                $this->step($phase === FinalizeRemoval::PHASE_TABLE ? (string) $out['table'] : $phase . '_removed');
            }
        }
        if (!$this->save_progress($removal, false)) {
            return 'transition_refused';
        }
        $this->step('legacy_removed');
        return null;
    }

    /**
     * After a removal pass: the cursor pass waits for the settle time; a rescan that found
     * nothing settles the removal (the table retirement follows); one that removed late legacy
     * names starts another rescan, at most CopyRemoval::MAX_RESCANS in all
     * (`removal_unsettled`, exit 4: rerun later). Each rescan recomputes what is kept.
     *
     * @return bool|string True once settled, false for another pass, or an error code.
     */
    private function end_pass() {
        // Reactivated meanwhile: it would delete and rewrite its own legacy names (and its
        // cron mutex) under the rescans, which could never settle.
        if ($this->official_active()) {
            return 'official_active';
        }
        if ($this->pass === 1) {
            $this->step('legacy_swept');
            $this->settle();
        } else {
            $this->step('rescanned');
            if ($this->found === 0) {
                $this->settled = true;
                return true;
            }
            $this->report['late_legacy_removed'] = (isset($this->report['late_legacy_removed']) ? (int) $this->report['late_legacy_removed'] : 0) + $this->found;
            if ($this->pass > CopyRemoval::MAX_RESCANS) {
                return 'removal_unsettled';
            }
        }
        ++$this->pass;
        $this->found = 0;
        $this->kept = array();
        return false;
    }

    /**
     * Wait, bounded, until CopyRemoval::SETTLE_SECONDS have passed since the transition: a
     * request or worker that read cut_over before it still dual-writes legacy names until it
     * ends. No lease is held, so renewals are not paused.
     *
     * @return void
     */
    private function settle() {
        $deadline = $this->transitioned_at + CopyRemoval::SETTLE_SECONDS;
        while ($this->system->now() < $deadline) {
            // One progress line per poll (WP-CLI prints every step).
            $this->step('settling');
            $this->system->sleep(min(CopyRemoval::SETTLE_POLL, $deadline - $this->system->now()));
        }
    }

    /**
     * @param array<string, mixed> $removal Removal progress.
     * @param bool                 $pending Still pending.
     * @return bool
     */
    private function save_progress(array $removal, $pending) {
        return TakeoverState::patch(array(self::PROGRESS => $this->progress($removal, $pending)), TakeoverState::FINALIZED);
    }

    /**
     * Step 6 under its own lease.
     *
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>, table:string}
     */
    private function retire_table() {
        $out = array('status' => FinalizeRemoval::DONE, 'progress' => array('phase' => FinalizeRemoval::PHASE_DONE, 'cursor' => 0),
            'removed' => FinalizeRemoval::no_removals(), 'kept' => array(), 'table' => 'table_absent');
        if (!$this->store->table_exists(CopyStore::TABLE_LEGACY)) {
            return $out;
        }
        $this->lease = QuiesceLease::acquire($this->system);
        if ($this->lease === null) {
            $out['status'] = 'busy';
            $out['progress']['phase'] = FinalizeRemoval::PHASE_TABLE;
            return $out;
        }
        try {
            // Another run may have retired it while this one waited for the lease.
            if (!$this->store->table_exists(CopyStore::TABLE_LEGACY)) {
                return $out;
            }
            $verdict = $this->drain_legacy();
            $rows = 0;
            if ($verdict === null) {
                list($verdict, $rows) = $this->table_verdict(TakeoverState::get());
            }
            if ($verdict === null && !$this->lease->holds()) {
                $verdict = 'lease_lost';
            }
            if ($verdict === 'lease_lost') {
                $out['status'] = 'lease_lost';
                $out['progress']['phase'] = FinalizeRemoval::PHASE_TABLE;
                return $out;
            }
            if ($verdict === null) {
                Journal::record('finalize', 'billing_attempts:drop', $this->store->table_count(CopyStore::TABLE_LEGACY), null, FinalizeRemoval::AUDIT);
                if (!$this->store->drop_legacy_table()) {
                    $out['status'] = FinalizeRemoval::FAILED;
                    $out['progress']['phase'] = FinalizeRemoval::PHASE_TABLE;
                    return $out;
                }
            }
            $outcome = $this->retire_outcome($verdict, $rows, true);
            return array_merge($out, $outcome);
        } finally {
            $this->lease->release();
            $this->lease = null;
        }
    }

    /**
     * @param string|null $verdict Null when the table is (or would be) dropped, else why it is kept.
     * @param int         $rows    Rows concerned by the reason (the kept group's count).
     * @param bool        $return  Return the outcome instead of absorbing it (dry run).
     * @return array{removed:array<string,int>, kept:list<array<string,mixed>>, table:string}
     */
    private function retire_outcome($verdict, $rows, $return) {
        $outcome = array('removed' => FinalizeRemoval::no_removals(), 'kept' => array(), 'table' => $verdict === null ? 'table_retired' : 'table_kept');
        if ($verdict === null) {
            $outcome['removed']['billing_table'] = 1;
        } else {
            $group = FinalizeRemoval::kept_group('billing_table', (string) array_keys(Keys::tables())[0], $verdict);
            $group['count'] = max(1, (int) $rows);
            $outcome['kept'][] = $group;
        }
        if (!$return) {
            $this->absorb($outcome);
        }
        return $outcome;
    }

    /**
     * Wait, bounded, for legacy claim holders a cut-over worker pinned before the switch
     * (CycleClaim releases a claim whose table is no longer in use).
     *
     * @return string|null `lease_lost` or `table_unreadable`, else null.
     */
    private function drain_legacy() {
        $deadline = $this->system->now() + Cutover::DRAIN_TIMEOUT;
        while (true) {
            $since = gmdate('Y-m-d H:i:s', $this->system->now() - Cutover::STALE_SECONDS);
            $open = $this->store->table_claims_open(CopyStore::TABLE_LEGACY, $since);
            if ($open === null) {
                return 'table_unreadable';
            }
            if ($open === 0 || $this->system->now() >= $deadline) {
                return null;
            }
            $this->report['legacy_claims_in_flight'] = $open;
            $this->system->sleep(Cutover::DRAIN_POLL);
            if (!$this->lease->maybe_renew()) {
                return 'lease_lost';
            }
        }
    }

    /**
     * Whether the legacy table may be dropped (read-only). While cut over only the new table
     * is written, so the legacy table is a stale subset of it: it is dropped only when no
     * legacy row is `claimed` or `dispatching`, none was updated after cut_over_at (the
     * cutover's final sync; a later one is a legacy-pinned straggler), and every legacy row's
     * cycle_key exists in the new table at a state at least as far along
     * (BillingTableCopier::covers(); an unknown state fails closed). The per-row checksum
     * comparison is reported for information only.
     *
     * @param array<string, mixed> $state State.
     * @return array{0:string|null, 1:int} [null when droppable, else why it is kept; rows concerned].
     */
    private function table_verdict(array $state) {
        $this->store->table_read_failed();
        $verdict = $this->table_rows_verdict($state);
        if ($verdict[0] === 'lease_lost') {
            return $verdict;
        }
        if ($this->store->table_read_failed()) {
            return array('table_unreadable', 0);
        }
        if ($verdict[0] === null) {
            $verdict = $this->report_checksums();
        }
        if ($this->store->table_read_failed()) {
            return array('table_unreadable', 0);
        }
        return $verdict;
    }

    /**
     * @param array<string, mixed> $state State.
     * @return array{0:string|null, 1:int} As table_verdict(), before the read-error check.
     */
    private function table_rows_verdict(array $state) {
        $claimed = $this->store->table_count_state(CopyStore::TABLE_LEGACY, 'claimed');
        $dispatching = $this->store->table_count_state(CopyStore::TABLE_LEGACY, 'dispatching');
        $newer = $this->store->table_rows_updated_after(CopyStore::TABLE_LEGACY, gmdate('Y-m-d H:i:s', (int) $state['cut_over_at']));
        if ($claimed === null || $dispatching === null || $newer === null) {
            return array('table_unreadable', 0);
        }
        if ($claimed + $dispatching > 0) {
            return array('legacy_claims_open', $claimed + $dispatching);
        }
        if ($newer > 0) {
            return array('legacy_newer_than_sync', $newer);
        }
        if (!$this->store->table_exists(CopyStore::TABLE_NEW)) {
            return array('new_table_missing', 0);
        }
        $missing = 0;
        $behind = 0;
        $seen = 0;
        $cursor = '';
        do {
            $keys = $this->store->table_keys_after(CopyStore::TABLE_LEGACY, $cursor, BillingTableCopier::BATCH);
            $seen += count($keys);
            $legacy = $this->store->table_states(CopyStore::TABLE_LEGACY, $keys);
            $new = $this->store->table_states(CopyStore::TABLE_NEW, $keys);
            foreach ($keys as $key) {
                if (!isset($new[$key])) {
                    ++$missing;
                } elseif (!isset($legacy[$key]) || !BillingTableCopier::covers($legacy[$key], $new[$key])) {
                    ++$behind;
                }
            }
            $cursor = $keys === array() ? $cursor : (string) end($keys);
            if ($this->lease !== null && !$this->lease->maybe_renew()) {
                return array('lease_lost', 0);
            }
        } while (count($keys) === BillingTableCopier::BATCH);
        // Fail closed: a key read that stopped short (a database error returns no keys) must
        // never let the drop run on rows it did not see.
        $count = $this->store->table_count(CopyStore::TABLE_LEGACY);
        if ($count === null || $count !== $seen) {
            return array('table_unreadable', 0);
        }
        if ($missing > 0) {
            return array('legacy_rows_missing_in_new', $missing);
        }
        return $behind > 0 ? array('legacy_rows_behind_in_new', $behind) : array(null, 0);
    }

    /**
     * Information only, for a table about to be dropped: how many rows differ between the two
     * tables by per-row checksum (expected after renewals while cut over). Renews the lease
     * per batch.
     *
     * @return array{0:string|null, 1:int} [null, 0], or ['lease_lost', 0].
     */
    private function report_checksums() {
        $cursor = '';
        $differing = 0;
        do {
            $step = BillingTableCopier::back_verify_batch($this->store, $cursor);
            $differing += $step['mismatches'] - (in_array(BillingTableCopier::ROW_COUNT, $step['codes'], true) ? 1 : 0);
            $cursor = $step['cursor'];
            if ($this->lease !== null && !$this->lease->maybe_renew()) {
                return array('lease_lost', 0);
            }
        } while (!$step['done']);
        $this->reported['billing_table_checksums'] = array('differing_rows' => $differing);
        return array(null, 0);
    }

    /**
     * @param array<string, mixed> $out A removal step outcome.
     * @return void
     */
    private function absorb(array $out) {
        foreach ($out['removed'] as $counter => $count) {
            $this->removed[$counter] = (isset($this->removed[$counter]) ? $this->removed[$counter] : 0) + (int) $count;
        }
        $this->kept = FinalizeRemoval::merge_kept($this->kept, $out['kept']);
    }

    /**
     * @param array<string, mixed> $removal Removal progress.
     * @param bool                 $pending Still pending.
     * @return array<string, mixed>
     */
    private function progress(array $removal, $pending) {
        return array('removal' => $removal, 'removed' => $this->removed, 'kept' => $this->kept, 'reported' => $this->reported, 'pending' => $pending,
            'transitioned_at' => $this->transitioned_at, 'pass' => $this->pass, 'found' => $this->found, 'settled' => $this->settled);
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return array<string, mixed> The recorded progress field, or an empty array.
     */
    public static function progress_of(array $state) {
        return isset($state[self::PROGRESS]) && is_array($state[self::PROGRESS]) ? $state[self::PROGRESS] : array();
    }

    /**
     * @param string $step Step name.
     * @return void
     */
    private function step($step) {
        $this->steps[] = $step;
        $this->system->reached($step);
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return bool Whether the store was finalized before FINALIZE_AFTER_DAYS (--force-after-review).
     */
    public static function forced(array $state) {
        return isset($state['finalize_forced']) && $state['finalize_forced'] === true;
    }

    /** @return int */
    private static function day() {
        return defined('DAY_IN_SECONDS') ? (int) constant('DAY_IN_SECONDS') : self::SECONDS_PER_DAY;
    }

    /**
     * @param string|null $error Error code, null when the run completed.
     * @return array{ok:bool, removed:array<string,int>, kept:list<array<string,mixed>>, reported:array<string,mixed>, forced:bool, error:string|null, steps:list<string>, report:array<string,mixed>}
     */
    private function result($error) {
        return array(
            'ok' => $error === null && $this->kept === array(),
            'removed' => $this->removed,
            'kept' => $this->kept,
            'reported' => $this->reported,
            'forced' => self::forced(TakeoverState::get()),
            'error' => $error,
            'steps' => $this->steps,
            'report' => $this->report,
        );
    }
}
