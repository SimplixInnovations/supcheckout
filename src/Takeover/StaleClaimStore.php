<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * The billing-attempts table as the stale-claims report and release see it (Sub-project
 * 0b Task 9). WordPressStaleClaimStore binds it to the table in use; the unit tests bind
 * it to memory.
 */
interface StaleClaimStore {
    /**
     * Rows still `claimed` and last updated before $before_gmt, oldest first.
     *
     * @param string $before_gmt MySQL DATETIME in UTC.
     * @param int    $limit      Maximum count.
     * @return list<array{cycle_key:string, owner_token:string, updated_gmt:string}>
     */
    public function stale_claimed($before_gmt, $limit);

    /**
     * Delete one row only while it is still exactly what was listed: the same cycle_key,
     * state `claimed`, the same owner_token and the same updated_gmt, still before
     * $before_gmt (one compare-and-swap DELETE). A worker that reached mark_dispatching()
     * or a reclaim that took the row over changes one of those, so it is never released.
     *
     * @param array{cycle_key:string, owner_token:string, updated_gmt:string} $row        A row from stale_claimed().
     * @param string                                                          $before_gmt Same bound as the listing.
     * @return bool True when exactly this row was deleted.
     */
    public function release(array $row, $before_gmt);
}
