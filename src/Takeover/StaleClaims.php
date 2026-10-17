<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Stale billing-cycle claims (Sub-project 0b Task 9): the `status` report and
 * `release-stale-claims`.
 *
 * Cutover refuses to switch while a `claimed` row is older than STALE_SECONDS
 * (`stale_claims`): its worker may have stalled rather than died, and the quiesce pauses
 * the scheduler reclaim that would normally clear it. The owner clears it here.
 *
 * Why releasing a `claimed` row is money-safe: the scheduler sends the provider POST only
 * after CycleClaim::mark_dispatching() moved the row claimed → dispatching with its
 * owner_token (Scheduler::process_one_order()). A row still `claimed` therefore never had
 * a POST. The release is one compare-and-swap DELETE on (cycle_key, state `claimed`,
 * owner_token, updated_gmt): if the stalled worker wakes up and wins mark_dispatching()
 * first, the DELETE matches nothing and the row stays; if the DELETE wins, its
 * mark_dispatching() matches nothing and it sends no POST (it releases and skips). A
 * reclaim by another worker rewrites owner_token and updated_gmt, so it is never raced
 * either. `dispatching`, `held` and `resolved` rows are never selected.
 *
 * The release runs under the Quiesce lease, so no new claim or reclaim starts meanwhile
 * and it never overlaps a Cutover or Rollback. Each release is journaled before the
 * DELETE (fingerprints only) with restore source RESTORE_SOURCE: it is an audit record
 * and Rollback must never re-create the row (a released claim is claimed afresh by the
 * next scheduler tick). An entry whose row still exists means its CAS lost to the worker.
 */
final class StaleClaims {
    /** A claimed row updated longer ago is stale (Cutover::STALE_SECONDS, CycleClaim's reclaim threshold). */
    public const STALE_SECONDS = Cutover::STALE_SECONDS;
    /** Rows listed or released per call at most. */
    public const LIMIT = 500;
    /** Journal target of a release. */
    public const TARGET = 'billing_claim';
    /** Journal restore source: audit only, never restored. */
    public const RESTORE_SOURCE = 'none:stale_claim_release';

    /**
     * @param StaleClaimStore $store Table.
     * @param int             $now   Current Unix time.
     * @return list<array{cycle_key:string, age_seconds:int}> Oldest first. No owner token, no order data.
     */
    public static function report(StaleClaimStore $store, $now) {
        $report = array();
        foreach ($store->stale_claimed(self::before_gmt($now), self::LIMIT) as $row) {
            $updated = strtotime($row['updated_gmt'] . ' UTC');
            $report[] = array(
                'cycle_key' => $row['cycle_key'],
                'age_seconds' => $updated === false ? 0 : max(0, (int) $now - $updated),
            );
        }
        return $report;
    }

    /**
     * @param StaleClaimStore $store Table.
     * @param int             $now   Current Unix time.
     * @return array{ok:bool, error:string|null, released:list<string>, skipped:list<string>}
     */
    public static function release(StaleClaimStore $store, $now) {
        $now = (int) $now;
        $released = array();
        $skipped = array();
        $lease = Quiesce::acquire($now);
        if ($lease === null) {
            return array('ok' => false, 'error' => 'busy', 'released' => $released, 'skipped' => $skipped);
        }
        try {
            $before = self::before_gmt($now);
            foreach ($store->stale_claimed($before, self::LIMIT) as $row) {
                if (!Quiesce::holds($lease, $now)) {
                    return array('ok' => false, 'error' => 'lease_lost', 'released' => $released, 'skipped' => $skipped);
                }
                Journal::record(self::TARGET, 'billing_attempts/' . $row['cycle_key'], $row, null, self::RESTORE_SOURCE);
                if ($store->release($row, $before)) {
                    $released[] = $row['cycle_key'];
                } else {
                    $skipped[] = $row['cycle_key'];
                }
            }
        } finally {
            Quiesce::release($lease);
        }
        return array('ok' => true, 'error' => null, 'released' => $released, 'skipped' => $skipped);
    }

    /**
     * @param int $now Current Unix time.
     * @return string
     */
    private static function before_gmt($now) {
        return gmdate('Y-m-d H:i:s', (int) $now - self::STALE_SECONDS);
    }

    private function __construct() {}
}
