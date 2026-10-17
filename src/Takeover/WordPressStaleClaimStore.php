<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;

defined('ABSPATH') || exit;

/**
 * StaleClaimStore bound to the billing-attempts table in use right now
 * (CycleClaim::table_name(): the legacy table until cutover), the one Cutover's drain
 * checks and the scheduler claims in (Sub-project 0b Task 9).
 */
final class WordPressStaleClaimStore implements StaleClaimStore {
    public function stale_claimed($before_gmt, $limit) {
        global $wpdb;
        if (!CycleClaim::table_exists()) {
            return array();
        }
        $prepared = $wpdb->prepare(
            'SELECT cycle_key, owner_token, updated_gmt FROM %i WHERE state = %s AND updated_gmt < %s ORDER BY updated_gmt ASC, cycle_key ASC LIMIT %d',
            CycleClaim::table_name(),
            CycleClaim::STATE_CLAIMED,
            (string) $before_gmt,
            max(1, (int) $limit)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the stale-claim report must see live claim state on the plugin-owned table.
        $results = $wpdb->get_results($prepared, ARRAY_A);
        $rows = array();
        foreach (is_array($results) ? $results : array() as $row) {
            if (is_array($row) && isset($row['cycle_key'], $row['owner_token'], $row['updated_gmt'])) {
                $rows[] = array(
                    'cycle_key' => (string) $row['cycle_key'],
                    'owner_token' => (string) $row['owner_token'],
                    'updated_gmt' => (string) $row['updated_gmt'],
                );
            }
        }
        return $rows;
    }

    public function release(array $row, $before_gmt) {
        global $wpdb;
        $prepared = $wpdb->prepare(
            'DELETE FROM %i WHERE cycle_key = %s AND state = %s AND owner_token = %s AND updated_gmt = %s AND updated_gmt < %s',
            CycleClaim::table_name(),
            (string) $row['cycle_key'],
            CycleClaim::STATE_CLAIMED,
            (string) $row['owner_token'],
            (string) $row['updated_gmt'],
            (string) $before_gmt
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; a compare-and-swap DELETE on the plugin-owned table, so a live worker is never raced.
        return (int) $wpdb->query($prepared) === 1;
    }
}
