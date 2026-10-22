<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Everything the takeover copy runner reads and writes outside the order CRUD objects
 * (Sub-project 0b Task 6). WordPressCopyStore binds it to WordPress; the unit tests bind
 * it to an in-memory store.
 *
 * Every selection is bounded (a limit) and ascending by ID or key, so a persisted cursor
 * resumes exactly where the last processed row ended.
 *
 * Billing-attempts tables are named by role, never by table name: TABLE_LEGACY is the
 * prefixed Keys::tables() legacy table, TABLE_NEW its new name.
 */
interface CopyStore {
    public const TABLE_LEGACY = 'legacy';
    public const TABLE_NEW = 'new';

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Orders with payment_method in Keys::gateway_ids() or carrying any
     *                   Keys::order_meta() legacy key, ascending.
     */
    public function order_ids_after($after_id, $limit);

    /**
     * @param int $order_id Order ID.
     * @return object|null The WooCommerce order, or null when it no longer exists.
     */
    public function load_order($order_id);

    /**
     * The postmeta reader OrderCopier uses to report HPOS/postmeta disagreement.
     *
     * @return callable|null callable(int $id, string $key): list<mixed>, get_post_meta($id, $key, false),
     *                       when HPOS is the authoritative order store; null otherwise.
     */
    public function order_post_meta_reader();

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Users holding a Keys::user_meta() key or a key under a
     *                   Keys::user_meta_prefixes() legacy prefix, ascending.
     */
    public function user_ids_after($after_id, $limit);

    /**
     * @param int $user_id User ID.
     * @return list<string> That user's legacy keys (exact names and full keys under a
     *                      legacy prefix), each once.
     */
    public function legacy_user_meta_keys($user_id);

    /**
     * @param int    $user_id User ID.
     * @param string $key     Meta key.
     * @return list<mixed> get_user_meta($user_id, $key, false): every value, in row order.
     */
    public function user_meta($user_id, $key);

    /**
     * add_user_meta($user_id, $key, $slashed_value, true): never a second row, and the
     * value is unslashed on the way in exactly as add_metadata() does, so callers pass
     * wp_slash()ed values.
     *
     * @param int    $user_id       User ID.
     * @param string $key           Meta key.
     * @param mixed  $slashed_value wp_slash()ed value.
     * @return bool True when a row was added.
     */
    public function add_unique_user_meta($user_id, $key, $slashed_value);

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Products and variations carrying a Keys::product_meta() legacy key, ascending.
     */
    public function product_ids_after($after_id, $limit);

    /**
     * @param int $product_id Product ID.
     * @return object|null The WooCommerce product, or null.
     */
    public function load_product($product_id);

    /**
     * Drop this request's in-memory object cache after a bounded batch of a takeover walk
     * (A5-02): wp_cache_flush_runtime() whenever the object cache supports `flush_runtime`,
     * including Redis and Memcached drop-ins, which keep an in-process copy of every key they
     * fetch. Never wp_cache_flush(): the persistent cache is left alone. Only memory is
     * cleared, so nothing a walk reads afterwards can differ: the lease, lock and state reads
     * already bypass the cache.
     *
     * @return bool True when the runtime cache was flushed.
     */
    public function flush_runtime_cache();

    /**
     * @phpstan-impure
     * @param string $which TABLE_LEGACY or TABLE_NEW.
     * @return bool
     */
    public function table_exists($which);

    /**
     * Create the new billing-attempts table from CycleClaim's own schema definition.
     *
     * @return bool True when the table exists afterwards.
     */
    public function create_new_table();

    /**
     * @param string $which TABLE_LEGACY or TABLE_NEW.
     * @return list<string> Column names in table order.
     */
    public function table_columns($which);

    /**
     * @param string $which     TABLE_LEGACY or TABLE_NEW.
     * @param string $after_key Exclusive lower bound ('' for the start).
     * @param int    $limit     Maximum count.
     * @return list<string> Primary keys, ascending.
     */
    public function table_keys_after($which, $after_key, $limit);

    /**
     * INSERT IGNORE the legacy rows with $after_key < key <= $upto_key into the new table.
     *
     * @param list<string> $columns   Columns to copy.
     * @param string       $after_key Exclusive lower bound.
     * @param string       $upto_key  Inclusive upper bound.
     * @return bool
     */
    public function table_insert_missing(array $columns, $after_key, $upto_key);

    /**
     * REPLACE the new table's rows for $keys with the legacy rows.
     *
     * @param list<string> $columns Columns to copy.
     * @param list<string> $keys    Primary keys.
     * @return bool
     */
    public function table_replace(array $columns, array $keys);

    /**
     * Delete the new table's rows with $after_key < key <= $upto_key that have no legacy
     * row (a legacy row CycleClaim::release_claimed() removed after it was copied). The
     * new table has no runtime writer before cutover, so every such row is an orphan copy.
     *
     * @param string      $after_key Exclusive lower bound.
     * @param string|null $upto_key  Inclusive upper bound; null for the open tail.
     * @return bool
     */
    public function table_delete_orphans($after_key, $upto_key);

    /**
     * @param string       $which     TABLE_LEGACY or TABLE_NEW.
     * @param list<string> $columns   Columns in the checksum, in this order.
     * @param string       $after_key Exclusive lower bound.
     * @param string|null  $upto_key  Inclusive upper bound; null for none.
     * @return array<string, string> Primary key => per-row checksum over every column.
     */
    public function table_checksums($which, array $columns, $after_key, $upto_key);

    /**
     * @param string $which TABLE_LEGACY or TABLE_NEW.
     * @return int|null Row count, or null when unreadable.
     */
    public function table_count($which);

    /**
     * In-flight claim holders (Cutover's drain): legacy rows in `claimed` or `dispatching`
     * updated at or after $since_gmt. 0 when the legacy table does not exist.
     *
     * @param string $since_gmt MySQL DATETIME in UTC.
     * @return int|null Count, or null when unreadable.
     */
    public function table_open_claims($since_gmt);

    /**
     * Stale claims (Cutover's drain): legacy rows still `claimed` and last updated before
     * $before_gmt. Their worker may have stalled rather than died: it could still mark its
     * legacy row dispatching after a cutover while another worker reclaims the copied
     * row, so they block the cutover. 0 when the legacy table does not exist.
     *
     * @param string $before_gmt MySQL DATETIME in UTC.
     * @return int|null Count, or null when unreadable.
     */
    public function table_stale_claims($before_gmt);
}
