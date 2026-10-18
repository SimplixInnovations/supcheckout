<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

defined('ABSPATH') || exit;

/**
 * Journal-driven rollback (cut_over → rolled_back → preflight_ok) and abort (verified →
 * preflight_ok), Sub-project 0b Task 10. Never marks, unmarks or re-verifies a payment:
 * orders paid through SUPCheckout during the window stay paid and keep their payment IDs
 * (Review Focus 5).
 *
 * run(): rollback, under the Quiesce lease (claims and the scheduler defer):
 *  1. take the lease and re-read the state under it (must be cut_over); an active official
 *     plugin refuses (`official_active`: it writes legacy names and ignores the lease);
 *  2. read the journal: a malformed journal throws here, before anything is written; the
 *     token-identity secrets must agree before any write (`options_mismatch`);
 *  3. drain the NEW billing-attempts table (the one claims are pinned to while cut over):
 *     wait, bounded, until no row is `claimed` or `dispatching` with a recent update; a
 *     stale `claimed` row refuses at once (`stale_claims`), an older `dispatching` row belongs
 *     to a dead worker and is synced as it is (it stays held in legacy, never re-claimable);
 *  4. back-sync new → legacy (BackSync), absent-fill only: order meta plus the
 *     payment_method rewrite of post-cutover orders, user meta including H12 provenance,
 *     product meta, Keys::options(); a differing legacy value is newer (SUPCheckout
 *     dual-writes while cut over), so it is kept and reported `divergent`; gateway settings
 *     only while the legacy settings are unchanged since cutover, else `settings_diverged`;
 *  5. back-sync the billing-attempts table: REPLACE / orphan delete (claimed rows only) /
 *     count and checksum over the whole table (BillingTableCopier::back_sync_batch()), never
 *     bounded by `max_copied_key`, refusing to move a row backwards or to trust a new table
 *     that lost legacy rows;
 *  6. recheck: renew, drain once more, verify the table again;
 *  7. replay the journal in reverse (JournalReplay): WooCommerce's gateway order and default
 *     gateway; a later edit is kept and reported as a conflict; recheck the official plugin;
 *  8. check holds(), persist the conflicts and the removal start, check holds() again right
 *     before the compare-and-swap, transition cut_over → rolled_back, flush caches, release
 *     the lease;
 *  9. remove the new-name copies (CopyRemoval: cron back to legacy names, payment_method of
 *     orders an in-flight cut-over request still created, order/user/product meta and
 *     markers, option copies, new-table rows), persisting progress after every unit;
 *     then settle: wait, bounded with a progress line per poll, until
 *     CopyRemoval::SETTLE_SECONDS have passed since the transition (a late writer that read
 *     the old state may still dual-write), and run full rescans (not cursor-bound) until one
 *     finds nothing, at most CopyRemoval::MAX_RESCANS (`removal_unsettled`, exit 4: rerun);
 *     abort's removal settles the same way; with a BudgetedSystem (0c, never WP-CLI) it
 *     yields instead of sleeping, at the settle barrier and between saved units (`yielded`,
 *     report `resume_at`; see BudgetedSystem), and the rerun resumes;
 * 10. record the journal floor (the removal complete only after a clean rescan) and
 *     transition rolled_back → preflight_ok;
 * 11. if consented, reactivate the official install the takeover recorded as deactivated
 *     (site only, without loading it in this process; see reactivate_official()).
 *
 * abort(): verified → preflight_ok, under the same lease. Only entries of a cutover that
 * died after its steps 5-7 can exist (options, cron twins, third-party): the third-party
 * values are restored, the transition happens, and the copy removal removes the cron twins,
 * the option copies and every other new-name copy. There is NO billing-table back-sync:
 * while verified the legacy names and table are authoritative and the new ones are unread
 * copies. The copies are removed rather than left in place because the window model writes
 * only legacy names in preflight_ok, so they would go stale, and the user copier never
 * overwrites a differing new key (H12 provenance), so a stale user copy would block the next
 * run. Until the removal completes the state carries `removal_pending`, and
 * CopyRunner::begin() refuses to start a copy. If `run` deactivated the official plugin,
 * a consented abort reactivates it. Abort has no official-plugin guard: `run` without consent
 * legitimately leaves `verified` with it active, and abort only removes copies the official
 * plugin never reads and restores third-party values to their originals.
 *
 * Claim pinning across rollback: a worker that claimed a cycle before step 1 keeps writing
 * its pinned (new) table. The drain (3) waits for every such recent holder before the table
 * back-sync (5), and the recheck (6) drains again before the switch, so its final
 * state reaches the legacy table. A new claim cannot start meanwhile (the lease is live;
 * CycleClaim re-checks it after claiming). After the transition every claim lands in the
 * legacy table. The one residual case is a worker silent for over Cutover::STALE_SECONDS
 * with a `dispatching` row: its row is synced as `dispatching`, which stays held (never
 * auto-expired or re-claimed), so a late write of its outcome to the new table can never
 * cause a second charge; it needs the usual operator reconciliation.
 *
 * Crash recovery: before step 8 everything is idempotent and the state stays cut_over (or
 * verified); rerun after the lease expires (Quiesce::TTL). After step 8, rerun resumes the
 * removal from its persisted progress, then steps 10-11; the persisted conflicts are
 * reported again.
 *
 * Result: `ok` only with no error, no conflict and no warning. Conflicts are not an error:
 * the rollback completed and the newer values were kept for the owner to decide.
 */
final class Rollback {
    public const MODE_ROLLBACK = 'rollback';
    public const MODE_ABORT = 'abort';
    /** State field holding the progress of the current rollback or abort. */
    public const PROGRESS = TakeoverState::ROLLBACK_PROGRESS;
    /** State field: first journal seq of the current takeover cycle. */
    public const FLOOR = 'journal_floor';
    /** State field: the official install the takeover deactivated (basename), until reactivated. */
    public const OFFICIAL_RECORD = 'official_deactivated';
    /** State field: the purge after reactivating the official plugin has not finished yet. */
    public const REACTIVATION_PURGE_PENDING = 'reactivation_purge_pending';

    /** Attempts on an order whose lock a payment request holds. */
    private const LOCK_ATTEMPTS = 3;

    /** @var RollbackStore */
    private $store;
    /** @var RollbackSystem */
    private $system;
    /** @var QuiesceLease|null */
    private $lease = null;
    /** @var string */
    private $mode = self::MODE_ROLLBACK;
    /** @var list<string> */
    private $steps = array();
    /** @var array<string, mixed> */
    private $report = array();
    /** @var list<string> */
    private $warnings = array();
    /** @var list<array{seq:int, target:string, path:string}> */
    private $conflicts = array();
    /** @var int */
    private $restored = 0;
    /** @var int Removal pass: 1 is the cursor pass, 2.. are full rescans. */
    private $pass = 1;
    /** @var int Late copies the current rescan removed. */
    private $found = 0;
    /** @var int System time of the transition (start of the settle barrier). */
    private $transitioned_at = 0;

    /**
     * @param RollbackStore|null  $store  Store; WordPress when omitted.
     * @param RollbackSystem|null $system System; WordPress when omitted.
     */
    public function __construct($store = null, $system = null) {
        $this->store = $store instanceof RollbackStore ? $store : new WordPressCopyStore();
        $this->system = $system instanceof RollbackSystem ? $system : new WordPressCutoverSystem();
    }

    /**
     * @param bool $reactivate_official Reactivate the official plugin once rolled back (owner consent).
     * @return array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>, error:string|null, steps:list<string>, warnings:list<string>, report:array<string, mixed>}
     */
    public function run($reactivate_official) {
        $this->mode = self::MODE_ROLLBACK;
        TakeoverState::refresh();
        $state = TakeoverState::get();
        if ($state['status'] === TakeoverState::ROLLED_BACK
            || ($state['status'] === TakeoverState::PREFLIGHT_OK && self::mode_of($state) === self::MODE_ROLLBACK)
        ) {
            return $this->resume((bool) $reactivate_official);
        }
        if ($state['status'] !== TakeoverState::CUT_OVER) {
            return $this->result('not_cut_over');
        }
        return $this->quiesced(TakeoverState::CUT_OVER, (bool) $reactivate_official);
    }

    /**
     * @param bool $reactivate_official Reactivate the official plugin `run` deactivated (owner consent).
     * @return array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>, error:string|null, steps:list<string>, warnings:list<string>, report:array<string, mixed>}
     */
    public function abort($reactivate_official) {
        $this->mode = self::MODE_ABORT;
        TakeoverState::refresh();
        $state = TakeoverState::get();
        if ($state['status'] === TakeoverState::PREFLIGHT_OK && self::mode_of($state) === self::MODE_ABORT) {
            return $this->resume((bool) $reactivate_official);
        }
        if ($state['status'] !== TakeoverState::VERIFIED) {
            return $this->result('not_verified');
        }
        return $this->quiesced(TakeoverState::VERIFIED, (bool) $reactivate_official);
    }

    /**
     * Whether a rollback or abort still has copies to remove (CopyRunner::begin() refuses then).
     *
     * @param array<string, mixed> $state TakeoverState::get().
     * @return bool
     */
    public static function removal_pending(array $state) {
        // Only after a transition: a record left in cut_over or verified by a refused switch
        // (lease lost, transition refused) never blocks a run.
        return in_array($state['status'], array(TakeoverState::ROLLED_BACK, TakeoverState::PREFLIGHT_OK), true)
            && isset($state[self::PROGRESS]['removal_pending']) && $state[self::PROGRESS]['removal_pending'] === true;
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return array<string, mixed> The recorded progress field, or an empty array.
     */
    private static function progress_of(array $state) {
        return isset($state[self::PROGRESS]) && is_array($state[self::PROGRESS]) ? $state[self::PROGRESS] : array();
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return string|null The mode of the recorded rollback or abort, if any.
     */
    public static function mode_of(array $state) {
        return isset($state[self::PROGRESS]['mode']) && is_string($state[self::PROGRESS]['mode']) ? $state[self::PROGRESS]['mode'] : null;
    }

    /**
     * Steps 1-8 (the abort's subset), then the post-transition tail.
     *
     * @param string $expected   Status required under the lease.
     * @param bool   $reactivate Consent.
     * @return array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>, error:string|null, steps:list<string>, warnings:list<string>, report:array<string, mixed>}
     */
    private function quiesced($expected, $reactivate) {
        $this->lease = QuiesceLease::acquire($this->system);
        if ($this->lease === null) {
            return $this->result('busy');
        }
        try {
            // The status read above was unlocked: re-read it under the lease.
            TakeoverState::refresh();
            $state = TakeoverState::get();
            if ($state['status'] !== $expected) {
                return $this->result($this->mode === self::MODE_ROLLBACK ? 'not_cut_over' : 'not_verified');
            }
            if ($this->mode === self::MODE_ROLLBACK && $this->official_active()) {
                return $this->result('official_active');
            }
            $this->step('quiesced');
            try {
                $entries = JournalReplay::cycle_entries($state);
            } catch (\Throwable $e) {
                return $this->result('journal_unreadable');
            }
            $error = $this->mode === self::MODE_ROLLBACK ? $this->roll_back_data($entries) : $this->abort_data($entries);
            if ($error !== null) {
                return $this->result($error);
            }
        } catch (\Throwable $e) {
            $this->report['exception'] = get_class($e);
            return $this->result('error');
        } finally {
            $this->lease->release();
        }
        return $this->resume($reactivate);
    }

    /**
     * Rollback steps 3-8.
     *
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @return string|null Error code, or null once rolled back.
     */
    private function roll_back_data(array $entries) {
        // H12: never write a user or provenance value while the two secrets disagree.
        if (!BackSync::secret_agrees()) {
            $this->report['options_mismatches'] = array(Keys::options()[CustomerTokenIdentity::SECRET_OPTION]);
            return 'options_mismatch';
        }
        $drain = $this->drain(true);
        if ($drain !== null) {
            return $drain;
        }
        $this->step('drained');
        foreach (array('orders', 'users', 'products') as $kind) {
            $error = $this->back_sync($kind);
            if ($error !== null) {
                return $error;
            }
            $this->step($kind . '_synced');
        }
        $error = $this->back_sync_options($entries);
        if ($error !== null) {
            return $error;
        }
        $this->step('options_synced');
        if (!$this->lease->maybe_renew() || !$this->sync_table()) {
            return $this->lease->is_lost() ? 'lease_lost' : 'table_mismatch';
        }
        $this->step('table_synced');
        if (!$this->lease->renew()) {
            return 'lease_lost';
        }
        $drain = $this->drain(false);
        if ($drain !== null) {
            return $drain;
        }
        if (!$this->verify_table()) {
            return $this->lease->is_lost() ? 'lease_lost' : 'table_mismatch';
        }
        $this->step('rechecked');
        // Third-party values last, so a refused rollback leaves them pointing at the gateway
        // still registered (supcheckout) while the store stays cut over.
        $error = $this->replay($entries);
        if ($error !== null) {
            return $error;
        }
        if ($this->official_active()) {
            return 'official_active';
        }
        return $this->switch_state(TakeoverState::CUT_OVER, TakeoverState::ROLLED_BACK, 'rolled_back');
    }

    /**
     * Keys::options() and the gateway settings (absent-fill / unchanged-since-cutover only).
     *
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @return string|null Error code.
     */
    private function back_sync_options(array $entries) {
        if (!$this->lease->maybe_renew()) {
            return 'lease_lost';
        }
        $options = BackSync::options();
        $this->add_divergent($options['divergent']);
        if (!$options['ok']) {
            $this->report['options_mismatches'] = $options['failed'];
            return 'options_mismatch';
        }
        $settings = BackSync::settings($entries);
        if ($settings['failed']) {
            $this->report['options_mismatches'] = array(Keys::SETTINGS_OPTION);
            return 'options_mismatch';
        }
        if ($settings['conflict'] !== null) {
            $this->add_conflicts(array(array('seq' => -1, 'target' => BackSync::SETTINGS_DIVERGED, 'path' => $settings['conflict'])));
        }
        return null;
    }

    /** @return bool True (and reported) while an official plugin install is active. */
    private function official_active() {
        $official = $this->system->official_plugin();
        if ($official === null) {
            return false;
        }
        $this->report['official'] = $official;
        return true;
    }

    /**
     * @param list<string> $names `<kind>:<path>` names kept under their legacy value.
     * @return void
     */
    private function add_divergent(array $names) {
        $conflicts = array();
        foreach ($names as $name) {
            $conflicts[] = array('seq' => -1, 'target' => BackSync::DIVERGENT, 'path' => (string) $name);
        }
        $this->add_conflicts($conflicts);
    }

    /**
     * Abort: restore third-party values, then verified → preflight_ok.
     *
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @return string|null Error code, or null once aborted.
     */
    private function abort_data(array $entries) {
        $error = $this->replay($entries);
        if ($error !== null) {
            return $error;
        }
        return $this->switch_state(TakeoverState::VERIFIED, TakeoverState::PREFLIGHT_OK, 'aborted');
    }

    /**
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @return string|null
     */
    private function replay(array $entries) {
        if (!$this->lease->maybe_renew()) {
            return 'lease_lost';
        }
        $replay = JournalReplay::restore_options($entries);
        $this->add_conflicts($replay['conflicts']);
        $this->restored += $replay['restored'];
        if ($replay['failed'] !== array()) {
            $this->report['replay_failed'] = $replay['failed'];
            return 'replay_failed';
        }
        $this->step('journal_replayed');
        return null;
    }

    /**
     * Persist the removal start and conflicts, then the compare-and-swap transition.
     *
     * @param string $from Current status.
     * @param string $to   Target status.
     * @param string $step Step name for the transition.
     * @return string|null
     */
    private function switch_state($from, $to, $step) {
        // No removal record is written for a lease that is already lost.
        if (!$this->lease->holds()) {
            return 'lease_lost';
        }
        $progress = array(
            'mode' => $this->mode,
            'conflicts' => $this->conflicts,
            'restored' => $this->restored,
            'removal' => CopyRemoval::start(),
            'removal_pending' => true,
            // The settle barrier counts from here (CopyRemoval::SETTLE_SECONDS).
            'transitioned_at' => $this->system->now(),
            'pass' => 1,
            'found' => 0,
        );
        if (!TakeoverState::patch(array(self::PROGRESS => $progress), $from)) {
            return 'transition_refused';
        }
        // Immediately before the compare-and-swap: the lease must still be ours.
        if (!$this->lease->holds()) {
            return 'lease_lost';
        }
        if (!TakeoverState::transition($to)) {
            return 'transition_refused';
        }
        $this->step($step);
        TakeoverState::refresh();
        // After the compare-and-swap: a purge problem is reported, never an error.
        $this->purge_pending();
        $this->step('caches_flushed');
        return null;
    }

    /**
     * Steps 9-11 (also the whole run when resuming after the transition).
     *
     * @param bool $reactivate Consent.
     * @return array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>, error:string|null, steps:list<string>, warnings:list<string>, report:array<string, mixed>}
     */
    private function resume($reactivate) {
        try {
            TakeoverState::refresh();
            $state = TakeoverState::get();
            $progress = self::progress_of($state);
            $this->add_conflicts(isset($progress['conflicts']) && is_array($progress['conflicts']) ? $progress['conflicts'] : array());
            $this->restored = isset($progress['restored']) ? (int) $progress['restored'] : $this->restored;
            // A rolled_back store with no progress record (rolled back outside Rollback)
            // still has every copy: remove them before preflight_ok, from the start.
            // A worker killed between the transition and its purge never recorded it.
            if ($this->purge_pending()) {
                $this->step('caches_flushed');
            }
            $unrecorded = $state['status'] === TakeoverState::ROLLED_BACK && !isset($progress['removal_pending']);
            if (self::removal_pending($state) || $unrecorded) {
                $error = $this->remove($state, $unrecorded ? array() : $progress);
                if ($error !== null) {
                    return $this->result($error);
                }
            }
            if ($this->mode === self::MODE_ROLLBACK && TakeoverState::get()['status'] === TakeoverState::ROLLED_BACK) {
                if (!TakeoverState::transition(TakeoverState::PREFLIGHT_OK)) {
                    return $this->result('transition_refused');
                }
                $this->step('preflight_ok');
            }
            if ($reactivate) {
                $this->reactivate_official();
            }
            $this->purge_after_reactivation();
        } catch (\Throwable $e) {
            $this->report['exception'] = get_class($e);
            return $this->result('error');
        }
        return $this->result(null);
    }

    /**
     * Step 9 with progress persisted after every unit, then the floor.
     *
     * @param array<string, mixed> $state    State.
     * @param array<string, mixed> $progress Progress field.
     * @return string|null Error code.
     */
    private function remove(array $state, array $progress) {
        $status = $state['status'];
        $keep = array();
        foreach ($this->conflicts as $conflict) {
            if ($conflict['target'] === BackSync::SETTINGS_DIVERGED) {
                $keep[] = Keys::SETTINGS_OPTION;
            }
        }
        $removal = isset($progress['removal']) && is_array($progress['removal']) ? $progress['removal'] : CopyRemoval::start();
        // Pass 1 is the cursor removal; passes 2.. are full rescans after the settle time.
        $this->pass = isset($progress['pass']) ? max(1, (int) $progress['pass']) : 1;
        $this->found = isset($progress['found']) ? (int) $progress['found'] : 0;
        $this->transitioned_at = isset($progress['transitioned_at']) ? (int) $progress['transitioned_at'] : $this->system->now();
        if ($this->pass > 1 && empty($progress['yielded'])) {
            // A rerun after `removal_unsettled` (or a crash mid-rescan): start a fresh rescan
            // cycle. The settle reference time is kept, so the wait is not repeated; the
            // removal completes once a full rescan comes back clean. A rescan that yielded
            // (BudgetedSystem) instead continues from its saved cursor with its pass and
            // count: restarting it would never finish a rescan longer than one budget.
            $this->pass = 2;
            $this->found = 0;
            $removal = CopyRemoval::start();
        }
        $locked = 0;
        while (true) {
            $was = isset($removal['phase']) ? $removal['phase'] : CopyRemoval::PHASE_CRON;
            $out = CopyRemoval::step($this->store, $this->system, $removal, $keep);
            $this->add_conflicts($out['conflicts']);
            $this->restored += $out['restored'];
            if ($out['status'] === CopyRemoval::LOCKED) {
                if (++$locked >= self::LOCK_ATTEMPTS) {
                    return 'removal_busy';
                }
                $this->system->sleep(1);
                continue;
            }
            if ($out['status'] === CopyRemoval::FAILED) {
                $this->report['removal_failed'] = $was;
                return 'removal_incomplete';
            }
            $locked = 0;
            $this->found += $this->pass > 1 ? $out['removed'] : 0;
            $removal = $out['progress'];
            $spent = $this->budget_spent();
            if (!$this->save_progress($status, $removal, true, $spent)) {
                return 'transition_refused';
            }
            if ($was === CopyRemoval::PHASE_CRON && $this->pass === 1) {
                $this->step('cron_restored');
            }
            if ($spent) {
                // The unit's progress is saved: the rerun continues after it.
                return $this->yield_until($this->system->now());
            }
            if ($out['status'] !== CopyRemoval::DONE) {
                continue;
            }
            $next = $this->end_pass($status);
            if ($next === true) {
                break;
            }
            if (is_string($next)) {
                return $next;
            }
            $removal = CopyRemoval::start();
        }
        $this->step('removal_settled');
        return $this->save_progress($status, $removal, false) ? null : 'transition_refused';
    }

    /**
     * After a removal pass: the cursor pass waits for the settle time; a rescan that found
     * nothing completes the removal; one that removed late copies starts another rescan, at
     * most CopyRemoval::MAX_RESCANS in all. A budgeted run yields at the settle barrier with
     * the cursor pass still persisted, so its rerun settles (without a second wait once the
     * deadline has passed) before the first rescan.
     *
     * @param string $status Status the progress belongs to.
     * @return bool|string|null True when complete, an error code (`yielded` included), or null for another pass.
     */
    private function end_pass($status) {
        if ($this->pass === 1) {
            $this->step('copies_removed');
            if (!$this->settle()) {
                return 'yielded';
            }
        } else {
            $this->step('rescanned');
            if ($this->found === 0) {
                return true;
            }
            $this->report['late_copies_removed'] = $this->found;
            if ($this->pass > CopyRemoval::MAX_RESCANS) {
                return 'removal_unsettled';
            }
        }
        ++$this->pass;
        $this->found = 0;
        return $this->save_progress($status, CopyRemoval::start(), true) ? null : 'transition_refused';
    }

    /**
     * Wait, bounded, until CopyRemoval::SETTLE_SECONDS have passed since the transition, so a
     * request that read the old state before it has ended before the final rescans. A
     * budgeted run never sleeps: before the deadline it yields with `resume_at` = deadline.
     *
     * @return bool True once the deadline has passed, false when yielded.
     */
    private function settle() {
        $deadline = $this->transitioned_at + CopyRemoval::SETTLE_SECONDS;
        if ($this->system instanceof BudgetedSystem) {
            if ($this->system->now() < $deadline) {
                $this->yield_until($deadline);
                return false;
            }
            return true;
        }
        while ($this->system->now() < $deadline) {
            // One progress line per poll (WP-CLI prints every step).
            $this->step('settling');
            $this->system->sleep(min(CopyRemoval::SETTLE_POLL, $deadline - $this->system->now()));
        }
        return true;
    }

    /** @return bool A budgeted run whose budget is used up (never for WP-CLI). */
    private function budget_spent() {
        return $this->system instanceof BudgetedSystem && $this->system->budget_spent();
    }

    /**
     * Hand control back (BudgetedSystem): the progress is already persisted.
     *
     * @param int $resume_at Unix time from which a rerun can make progress.
     * @return string The `yielded` error code.
     */
    private function yield_until($resume_at) {
        $this->report['resume_at'] = (int) $resume_at;
        $this->step('yielded');
        return 'yielded';
    }

    /**
     * @param string               $status  Status the progress belongs to.
     * @param array<string, mixed> $removal Removal progress.
     * @param bool                 $pending Removal still pending.
     * @param bool                 $yielded The run yields after this save (a rescan resumes from its cursor).
     * @return bool
     */
    private function save_progress($status, array $removal, $pending, $yielded = false) {
        $progress = array(
            'mode' => $this->mode,
            'conflicts' => $this->conflicts,
            'restored' => $this->restored,
            'removal' => $removal,
            'removal_pending' => $pending,
            'transitioned_at' => $this->transitioned_at,
            'pass' => $this->pass,
            'found' => $this->found,
        );
        if ($yielded) {
            $progress['yielded'] = true;
        }
        $fields = array(self::PROGRESS => $progress);
        if (!$pending) {
            $fields[self::FLOOR] = count(Journal::entries());
        }
        return TakeoverState::patch($fields, $status);
    }

    /**
     * Step 11: only the install the takeover itself deactivated (recorded under
     * OFFICIAL_RECORD by `run` / `rollback --deactivate-official` / Cutover) is reactivated,
     * and only while it is still installed. No record, a missing install or a failed
     * activation is the `official_not_reactivated` warning: the owner activates it.
     *
     * @return void
     */
    private function reactivate_official() {
        if ($this->system->official_plugin() !== null) {
            $this->report['official'] = 'already_active';
            return;
        }
        $recorded = self::recorded_official(TakeoverState::get());
        if ($recorded === null || !in_array($recorded, $this->system->installed_official_plugins(), true)) {
            $this->report['official'] = $recorded === null ? 'not_deactivated_by_takeover' : 'not_installed';
            $this->warnings[] = 'official_not_reactivated';
            return;
        }
        if ($this->system->activate_plugin($recorded)) {
            // The purge below is recorded as pending in the same write, so a worker killed
            // before it finishes still purges on the rerun (resume() calls it again).
            TakeoverState::patch(array(self::OFFICIAL_RECORD => null, self::REACTIVATION_PURGE_PENDING => true));
            $this->step('official_reactivated');
            return;
        }
        $this->report['official'] = 'activation_failed';
        $this->warnings[] = 'official_not_reactivated';
    }

    /**
     * Purge the caches for this rollback or abort transition unless the state already
     * records it.
     *
     * @return bool True when a purge ran.
     */
    private function purge_pending() {
        $purge = CachePurger::purge_once($this->system, TakeoverState::get(), $this->mode === self::MODE_ABORT);
        if ($purge === null) {
            return false;
        }
        $this->report['cache_purge'] = $purge;
        return true;
    }

    /**
     * The reactivated official plugin may have re-served its own caches: purge once more
     * after a reactivation whose purge is still pending, then clear the flag.
     *
     * @return void
     */
    private function purge_after_reactivation() {
        TakeoverState::refresh();
        if (self::reactivation_purge_pending(TakeoverState::get())) {
            $this->report['cache_purge_after_reactivation'] = CachePurger::purge_through($this->system);
            TakeoverState::patch(array(self::REACTIVATION_PURGE_PENDING => false));
        }
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return bool True while the purge after a reactivation has not finished.
     */
    private static function reactivation_purge_pending(array $state) {
        return isset($state[self::REACTIVATION_PURGE_PENDING]) && $state[self::REACTIVATION_PURGE_PENDING] === true;
    }

    /**
     * @param array<string, mixed> $state TakeoverState::get().
     * @return string|null The official install the takeover deactivated, if recorded.
     */
    private static function recorded_official(array $state) {
        return isset($state[self::OFFICIAL_RECORD]) && is_string($state[self::OFFICIAL_RECORD]) ? $state[self::OFFICIAL_RECORD] : null;
    }

    /**
     * Record which official install the takeover deactivated, so a rollback or abort
     * reactivates exactly that one.
     *
     * @param string $basename Plugin basename.
     * @return void
     */
    public static function record_official_deactivated($basename) {
        TakeoverState::patch(array(self::OFFICIAL_RECORD => (string) $basename));
    }

    /**
     * Step 4 for one kind of object, over every candidate.
     *
     * @param string $kind orders, users or products.
     * @return string|null Error code.
     */
    private function back_sync($kind) {
        $mismatches = 0;
        $cursor = 0;
        $batch = $kind === 'orders' ? CopyRunner::ORDER_BATCH : ($kind === 'users' ? CopyRunner::USER_BATCH : CopyRunner::PRODUCT_BATCH);
        do {
            if ($kind === 'orders') {
                $ids = $this->store->rollback_order_ids_after($cursor, $batch);
            } elseif ($kind === 'users') {
                $ids = $this->store->rollback_user_ids_after($cursor, $batch);
            } else {
                $ids = $this->store->rollback_product_ids_after($cursor, $batch);
            }
            foreach ($ids as $id) {
                $cursor = $id;
                $outcome = $this->back_sync_one($kind, $id);
                $this->add_divergent($outcome['divergent']);
                $mismatches += ($outcome['status'] === BackSync::SYNCED || $outcome['status'] === BackSync::MISSING) ? 0 : 1;
                if (!$this->lease->maybe_renew()) {
                    return 'lease_lost';
                }
            }
        } while (count($ids) === $batch);
        if ($mismatches > 0) {
            $this->report['back_sync'][$kind] = $mismatches;
            return 'back_sync_mismatch';
        }
        return null;
    }

    /**
     * @param string $kind Kind.
     * @param int    $id   Object ID.
     * @return array{status:string, divergent:list<string>} A BackSync outcome.
     */
    private function back_sync_one($kind, $id) {
        if ($kind === 'users') {
            return BackSync::user($this->store, $id);
        }
        if ($kind === 'products') {
            return BackSync::product($this->store, $id);
        }
        $outcome = array('status' => BackSync::LOCKED, 'divergent' => array());
        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            $outcome = BackSync::order($id, true);
            if ($outcome['status'] !== BackSync::LOCKED) {
                return $outcome;
            }
            if ($attempt < self::LOCK_ATTEMPTS) {
                $this->system->sleep(1);
            }
        }
        return $outcome;
    }

    /**
     * Steps 3 and 7 on the new table (where claims are pinned while cut over).
     *
     * @param bool $wait Poll until Cutover::DRAIN_TIMEOUT (false: one check).
     * @return string|null Error code.
     */
    private function drain($wait) {
        $deadline = $this->system->now() + Cutover::DRAIN_TIMEOUT;
        while (true) {
            $since = gmdate('Y-m-d H:i:s', $this->system->now() - Cutover::STALE_SECONDS);
            $stale = $this->store->table_claims_stale(CopyStore::TABLE_NEW, $since);
            if ($stale !== 0) {
                $this->report['stale_claims'] = $stale;
                return 'stale_claims';
            }
            $open = $this->store->table_claims_open(CopyStore::TABLE_NEW, $since);
            if ($open === 0) {
                return null;
            }
            $this->report['claims_in_flight'] = $open;
            if (!$wait || $open === null || $this->system->now() >= $deadline) {
                return 'claims_in_flight';
            }
            $this->system->sleep(Cutover::DRAIN_POLL);
            if (!$this->lease->maybe_renew()) {
                return 'lease_lost';
            }
        }
    }

    /** @return bool Step 6: back-sync the whole table, then verify it. */
    private function sync_table() {
        $cursor = '';
        do {
            $step = BillingTableCopier::back_sync_batch($this->store, $cursor);
            if ($step['error'] !== null) {
                $this->report['table'] = $step['error'];
                return false;
            }
            $cursor = $step['cursor'];
            if (!$this->lease->maybe_renew()) {
                return false;
            }
        } while (!$step['done']);
        return $this->verify_table();
    }

    /** @return bool Every new row equals its legacy row and the counts agree. */
    private function verify_table() {
        $cursor = '';
        $mismatches = 0;
        do {
            $step = BillingTableCopier::back_verify_batch($this->store, $cursor);
            $mismatches += $step['mismatches'];
            $cursor = $step['cursor'];
            if (!$this->lease->maybe_renew()) {
                return false;
            }
        } while (!$step['done']);
        if ($mismatches > 0) {
            $this->report['table'] = 'mismatch';
            return false;
        }
        return true;
    }

    /**
     * @param array<mixed> $conflicts Conflicts to add (deduplicated by target and path).
     * @return void
     */
    private function add_conflicts(array $conflicts) {
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict) || !isset($conflict['target'], $conflict['path'])) {
                continue;
            }
            foreach ($this->conflicts as $known) {
                if ($known['target'] === $conflict['target'] && $known['path'] === $conflict['path']) {
                    continue 2;
                }
            }
            $this->conflicts[] = array('seq' => isset($conflict['seq']) ? (int) $conflict['seq'] : -1, 'target' => (string) $conflict['target'], 'path' => (string) $conflict['path']);
        }
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
     * @param string|null $error Error code, null when the run completed.
     * @return array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>, error:string|null, steps:list<string>, warnings:list<string>, report:array<string, mixed>}
     */
    private function result($error) {
        return array(
            'ok' => $error === null && $this->conflicts === array() && $this->warnings === array(),
            'restored' => $this->restored,
            'conflicts' => $this->conflicts,
            'error' => $error,
            'steps' => $this->steps,
            'warnings' => $this->warnings,
            'report' => $this->report,
        );
    }
}
