<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * What Rollback (Sub-project 0b Task 10) reads and writes beyond the copy runner's store:
 * selections that also find objects holding only new names, user meta replacement and
 * removal, and the billing-attempts tables in either direction. WordPressCopyStore binds it
 * to WordPress; the unit tests bind it to memory.
 *
 * Every selection is bounded and ascending, like CopyStore's.
 */
interface RollbackStore extends CopyStore {
    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Orders with payment_method in Keys::gateway_ids(), or holding any
     *                   Keys::order_meta() legacy or new key or a Keys::takeover_markers()
     *                   meta, ascending.
     */
    public function rollback_order_ids_after($after_id, $limit);

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Users holding a new Keys::user_meta() key or a key under a new
     *                   Keys::user_meta_prefixes() prefix, ascending.
     */
    public function rollback_user_ids_after($after_id, $limit);

    /**
     * @param int $user_id User ID.
     * @return list<string> That user's new keys (exact names and full keys under a new
     *                      prefix), each once.
     */
    public function new_user_meta_keys($user_id);

    /**
     * Replace every row of $key with $slashed_values, in order (delete_user_meta(), then one
     * add_user_meta() per value; add_metadata() unslashes, so callers pass wp_slash()ed values).
     *
     * @param int         $user_id        User ID.
     * @param string      $key            Meta key.
     * @param list<mixed> $slashed_values wp_slash()ed values.
     * @return bool True when every row was written.
     */
    public function replace_user_meta($user_id, $key, array $slashed_values);

    /**
     * @param int    $user_id User ID.
     * @param string $key     Meta key.
     * @return bool True when no row of $key is left.
     */
    public function delete_user_meta_key($user_id, $key);

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Products and variations holding a new Keys::product_meta() key or a
     *                   Keys::takeover_markers() meta, ascending.
     */
    public function rollback_product_ids_after($after_id, $limit);

    /**
     * Create a billing-attempts table from CycleClaim's own schema definition.
     *
     * @param string $which TABLE_LEGACY or TABLE_NEW.
     * @return bool True when the table exists afterwards.
     */
    public function create_table($which);

    /**
     * REPLACE $target's rows for $keys with the other table's rows.
     *
     * @param string       $target  TABLE_LEGACY or TABLE_NEW.
     * @param list<string> $columns Columns to copy.
     * @param list<string> $keys    Primary keys.
     * @return bool
     */
    public function table_replace_into($target, array $columns, array $keys);

    /**
     * Delete $target's rows with $after_key < key <= $upto_key that have no row in the
     * other table.
     *
     * @param string      $target    TABLE_LEGACY or TABLE_NEW.
     * @param string      $after_key Exclusive lower bound.
     * @param string|null $upto_key  Inclusive upper bound; null for the open tail.
     * @return bool
     */
    public function table_delete_orphans_from($target, $after_key, $upto_key);

    /**
     * @param string       $which TABLE_LEGACY or TABLE_NEW.
     * @param list<string> $keys  Primary keys.
     * @return bool
     */
    public function table_delete_keys($which, array $keys);

    /**
     * In-flight claim holders in $which: rows `claimed` or `dispatching` updated at or after
     * $since_gmt. 0 when the table does not exist.
     *
     * @param string $which     TABLE_LEGACY or TABLE_NEW.
     * @param string $since_gmt MySQL DATETIME in UTC.
     * @return int|null Count, or null when unreadable.
     */
    public function table_claims_open($which, $since_gmt);

    /**
     * Stale claims in $which: rows still `claimed` and last updated before $before_gmt.
     * 0 when the table does not exist.
     *
     * @param string $which      TABLE_LEGACY or TABLE_NEW.
     * @param string $before_gmt MySQL DATETIME in UTC.
     * @return int|null Count, or null when unreadable.
     */
    public function table_claims_stale($which, $before_gmt);
}
