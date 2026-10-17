<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * verified → cut_over (Sub-project 0b Task 7). Switches a store to the `supcheckout`
 * identities; never rewrites a historical order's payment_method and never marks,
 * unmarks or re-verifies a payment.
 *
 * Step order, all under the Quiesce lease (the global takeover lock):
 *
 *  1. quiesce: take the lease (claims and the scheduler defer, see Quiesce);
 *  2. drain: wait, bounded (DRAIN_TIMEOUT), until no billing-attempts row is `claimed` or
 *     `dispatching` with an update in the last STALE_SECONDS. An older dispatching row
 *     belongs to a dead worker (never auto-expired, so it stays held) and is synced as it
 *     is; an older claimed row refuses the cutover at once (`stale_claims`, see drain());
 *  3. final verify: every order, user and product is re-copied and verified. Reads are
 *     still legacy-only and every runtime write lands under both names, so the legacy
 *     value is authoritative and orders and products are converged to it under their
 *     locks; users keep the never-overwrite rule (H12 provenance);
 *  4. billing-attempts table: insert missing, REPLACE differing, delete orphans, then
 *     per-row checksum and count verification over the whole table;
 *  5. settings and Keys::options() (SettingsCopier, all or nothing; options copied last
 *     of the data, after the table);
 *  6. cron: new-name twins of every legacy event (CronRescheduler);
 *  7. third-party: WooCommerce's gateway order / default gateway (ThirdPartyTranslator;
 *     WCML, FunnelKit and anything else are reported only);
 *  8. recheck right before the switch: the lease is still held and renewed, nothing is in
 *     flight, the table and the settings/options still verify;
 *  9. transition verified → cut_over (compare-and-swap);
 * 10. flush this process's caches (TakeoverState::refresh());
 * 11. if consented, deactivate the official plugin.
 * The lease is released in `finally`, on every path.
 *
 * Any mismatch, refusal or exception before step 9 ends the run with `ok: false` and the
 * state still `verified`: there is no transition, and steps 6-7 never ran when 3-5 failed.
 *
 * Crash recovery: a process killed anywhere before step 9 leaves `verified`. Everything it
 * wrote is harmless there and idempotent on a rerun: new-name meta, rows and options are
 * not read before cutover (the token-identity secret copy is byte-identical, so H12's
 * agreement check passes); cron twins run the same callbacks (both names listen from
 * `copying` on); the translated gateway order only adds a position for a gateway not yet
 * registered. The lease expires after Quiesce::TTL, so renewals pause at most that long.
 * Recovery is simply running Cutover again: each step skips what is already equal, so the
 * rerun converges (the journal may then hold a second entry for a path that a crash left
 * journaled but unwritten; Rollback treats an entry whose path already holds its
 * before-value as restored). A crash after step 9 leaves `cut_over` with all data in
 * place; running Cutover again only performs step 11.
 */
final class Cutover {
    /** Seconds the drain waits for in-flight claim holders at most. */
    public const DRAIN_TIMEOUT = 120;
    /** Seconds between drain polls. */
    public const DRAIN_POLL = 2;
    /** A claimed/dispatching row updated longer ago belongs to a dead worker. */
    public const STALE_SECONDS = 600;
    /** Attempts on an order whose lock a payment request holds. */
    private const LOCK_ATTEMPTS = 3;
    /** Objects verified between lease renewals. */
    private const RENEW_EVERY = 50;

    /** @var CopyStore */
    private $store;
    /** @var CutoverSystem */
    private $system;
    /** @var string|null */
    private $lease = null;
    /** @var list<string> */
    private $steps = array();
    /** @var array<string, mixed> */
    private $report = array();

    /**
     * @param CopyStore|null     $store  Store; WordPress when omitted.
     * @param CutoverSystem|null $system System; WordPress when omitted.
     */
    public function __construct($store = null, $system = null) {
        $this->store = $store instanceof CopyStore ? $store : new WordPressCopyStore();
        $this->system = $system instanceof CutoverSystem ? $system : new WordPressCutoverSystem();
    }

    /**
     * @param bool $deactivate_official Deactivate the official plugin once cut over (owner consent).
     * @return array{ok:bool, steps:list<string>, error:string|null, report:array<string, mixed>}
     */
    public function run($deactivate_official) {
        TakeoverState::refresh();
        $status = TakeoverState::get()['status'];
        if ($status === TakeoverState::CUT_OVER) {
            $this->step('already_cut_over');
            return $this->finish_tail((bool) $deactivate_official);
        }
        if ($status !== TakeoverState::VERIFIED) {
            return $this->result('not_verified');
        }
        $this->lease = Quiesce::acquire($this->system->now());
        if ($this->lease === null) {
            return $this->result('busy');
        }
        try {
            $this->step('quiesced');
            $error = $this->switch_identities();
            if ($error !== null) {
                return $this->result($error);
            }
        } catch (\Throwable $e) {
            $this->report['exception'] = get_class($e);
            return $this->result('error');
        } finally {
            Quiesce::release($this->lease);
            $this->lease = null;
        }
        return $this->finish_tail((bool) $deactivate_official);
    }

    /**
     * Steps 2-10. Null on success, else the error code (no transition happened).
     *
     * @return string|null
     */
    private function switch_identities() {
        $drain = $this->drain(true);
        if ($drain !== null) {
            return $drain;
        }
        $this->step('drained');
        if (!$this->verify_objects()) {
            return 'verify_mismatch';
        }
        $this->step('verified_objects');
        if (!$this->renew() || !$this->sync_table()) {
            return $this->lease === null ? 'lease_lost' : 'table_mismatch';
        }
        $this->step('table_synced');
        $settings = SettingsCopier::copy();
        if (!$settings['ok']) {
            $this->report['settings_mismatches'] = $settings['mismatches'];
            return 'settings_mismatch';
        }
        $this->step('settings_copied');
        $cron = CronRescheduler::reschedule($this->system);
        $this->report['cron'] = $cron;
        if (!$cron['ok']) {
            return 'cron_failed';
        }
        $this->step('cron_rescheduled');
        $third_party = ThirdPartyTranslator::translate();
        $this->report['third_party'] = $third_party;
        if (!$third_party['ok']) {
            return 'third_party_failed';
        }
        $this->step('third_party_translated');
        $recheck = $this->recheck();
        if ($recheck !== null) {
            return $recheck;
        }
        $this->step('rechecked');
        if (!TakeoverState::transition(TakeoverState::CUT_OVER)) {
            return 'transition_refused';
        }
        $this->step('cut_over');
        TakeoverState::refresh();
        $this->step('caches_flushed');
        return null;
    }

    /**
     * Step 8: nothing may have moved since the data steps.
     *
     * @return string|null Error code, or null.
     */
    private function recheck() {
        if (!$this->renew()) {
            return 'lease_lost';
        }
        $drain = $this->drain(false);
        if ($drain !== null) {
            return $drain;
        }
        if (!$this->verify_table()) {
            return 'table_mismatch';
        }
        $settings = SettingsCopier::verify();
        if (!$settings['ok'] || !ThirdPartyTranslator::is_translated()) {
            $this->report['settings_mismatches'] = $settings['mismatches'];
            return 'settings_mismatch';
        }
        return null;
    }

    /**
     * A stale `claimed` row blocks at once (fail closed): its worker may have stalled
     * rather than died, and CycleClaim pins a claim to the table it was taken in, so after
     * a switch that worker could still mark its legacy row dispatching while another
     * worker reclaims the copied row. Waiting is pointless (the quiesce pauses the reclaim
     * that would clear it); the next scheduler tick reclaims it, then Cutover is rerun.
     *
     * @param bool $wait Poll until DRAIN_TIMEOUT (false: one check).
     * @return string|null Error code, or null when no claim holder is in flight.
     */
    private function drain($wait) {
        $deadline = $this->system->now() + self::DRAIN_TIMEOUT;
        while (true) {
            $since = gmdate('Y-m-d H:i:s', $this->system->now() - self::STALE_SECONDS);
            $stale = $this->store->table_stale_claims($since);
            if ($stale !== 0) {
                $this->report['stale_claims'] = $stale;
                return 'stale_claims';
            }
            $open = $this->store->table_open_claims($since);
            if ($open === 0) {
                return null;
            }
            $this->report['claims_in_flight'] = $open;
            if (!$wait || $open === null || $this->system->now() >= $deadline) {
                return 'claims_in_flight';
            }
            $this->system->sleep(self::DRAIN_POLL);
        }
    }

    /**
     * Step 3 over every order, user and product the copy runner would select.
     *
     * @return bool
     */
    private function verify_objects() {
        $mismatches = array('orders' => 0, 'disagreements' => 0, 'locked' => 0, 'users' => 0, 'products' => 0);
        $post_meta = $this->store->order_post_meta_reader();
        $seen = 0;
        $cursor = 0;
        do {
            $ids = $this->store->order_ids_after($cursor, CopyRunner::ORDER_BATCH);
            foreach ($ids as $order_id) {
                $cursor = $order_id;
                $mismatches = $this->verify_order($order_id, $post_meta, $mismatches);
                if (++$seen % self::RENEW_EVERY === 0 && !$this->renew()) {
                    return false;
                }
            }
        } while (count($ids) === CopyRunner::ORDER_BATCH);
        $cursor = 0;
        do {
            $ids = $this->store->user_ids_after($cursor, CopyRunner::USER_BATCH);
            foreach ($ids as $user_id) {
                $cursor = $user_id;
                $copy = UserMetaCopier::copy_user($this->store, $user_id);
                $verify = UserMetaCopier::verify_user($this->store, $user_id);
                $mismatches['users'] += ($copy['mismatches'] !== array() || !$verify['ok']) ? 1 : 0;
            }
        } while (count($ids) === CopyRunner::USER_BATCH);
        $cursor = 0;
        do {
            $ids = $this->store->product_ids_after($cursor, CopyRunner::PRODUCT_BATCH);
            foreach ($ids as $product_id) {
                $cursor = $product_id;
                $product = $this->store->load_product($product_id);
                if ($product === null) {
                    continue;
                }
                $copy = OrderCopier::copy_meta($product, Keys::product_meta(), null, true);
                $verify = OrderCopier::verify_meta($this->store->load_product($product_id), Keys::product_meta());
                $mismatches['products'] += ($copy['status'] === OrderCopier::FAILED || $copy['mismatches'] !== array() || !$verify['ok']) ? 1 : 0;
            }
        } while (count($ids) === CopyRunner::PRODUCT_BATCH);
        $this->report['mismatches'] = $mismatches;
        return array_sum($mismatches) === 0;
    }

    /**
     * @param int                $order_id   Order ID.
     * @param callable|null      $post_meta  HPOS postmeta reader.
     * @param array<string, int> $mismatches Counters.
     * @return array<string, int>
     */
    private function verify_order($order_id, $post_meta, array $mismatches) {
        $order = $this->store->load_order($order_id);
        if ($order === null) {
            return $mismatches;
        }
        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            $copy = OrderCopier::copy($order, is_callable($post_meta) ? $post_meta : false, true);
            if ($copy['status'] !== OrderCopier::LOCKED) {
                break;
            }
            if ($attempt < self::LOCK_ATTEMPTS) {
                $this->system->sleep(1);
            }
        }
        if ($copy['status'] === OrderCopier::LOCKED) {
            ++$mismatches['locked'];
            return $mismatches;
        }
        if ($copy['status'] === OrderCopier::MISSING) {
            return $mismatches;
        }
        $verify = OrderCopier::verify($this->store->load_order($order_id));
        if ($copy['status'] === OrderCopier::FAILED || $copy['mismatches'] !== array() || !$verify['ok']) {
            ++$mismatches['orders'];
        }
        if ($copy['disagreements'] !== array()) {
            ++$mismatches['disagreements'];
        }
        return $mismatches;
    }

    /**
     * Step 4: copy (insert, REPLACE, orphan delete) over the whole table, then verify.
     *
     * @return bool
     */
    private function sync_table() {
        $cursor = '';
        do {
            $step = BillingTableCopier::copy_batch($this->store, $cursor);
            if ($step['error'] !== null) {
                $this->report['table'] = $step['error'];
                return false;
            }
            $cursor = $step['cursor'];
        } while (!$step['done']);
        return $this->verify_table();
    }

    /** @return bool Checksums and counts agree (and no orphan new table without a legacy one). */
    private function verify_table() {
        if (!$this->store->table_exists(CopyStore::TABLE_LEGACY)) {
            $new_rows = $this->store->table_exists(CopyStore::TABLE_NEW) ? $this->store->table_count(CopyStore::TABLE_NEW) : 0;
            if ($new_rows !== 0) {
                $this->report['table'] = 'orphan_new_rows';
                return false;
            }
            return true;
        }
        $cursor = '';
        $mismatches = 0;
        do {
            $step = BillingTableCopier::verify_batch($this->store, $cursor);
            $mismatches += $step['mismatches'];
            $cursor = $step['cursor'];
        } while (!$step['done']);
        if ($mismatches > 0) {
            $this->report['table'] = 'mismatch';
            return false;
        }
        return true;
    }

    /** @return bool True while the lease is still ours (now renewed). */
    private function renew() {
        $renewed = $this->lease === null ? null : Quiesce::renew($this->lease, $this->system->now());
        $this->lease = $renewed;
        return $renewed !== null;
    }

    /**
     * Step 11 (and the whole run for a store already cut over).
     *
     * @param bool $deactivate_official Consent.
     * @return array{ok:bool, steps:list<string>, error:string|null, report:array<string, mixed>}
     */
    private function finish_tail($deactivate_official) {
        if ($deactivate_official) {
            $official = $this->system->official_plugin();
            if ($official === null) {
                $this->report['official'] = 'not_active';
            } elseif ($this->system->deactivate_plugin($official)) {
                $this->step('official_deactivated');
            } else {
                // Cut over regardless; SUPCheckout stays paused until the owner deactivates it.
                $this->step('official_still_active');
            }
        }
        return $this->result(null);
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
     * @param string|null $error Error code, null on success.
     * @return array{ok:bool, steps:list<string>, error:string|null, report:array<string, mixed>}
     */
    private function result($error) {
        return array('ok' => $error === null, 'steps' => $this->steps, 'error' => $error, 'report' => $this->report);
    }
}
