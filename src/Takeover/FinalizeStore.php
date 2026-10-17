<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * What Finalize (Sub-project 0b Task 11) reads and writes beyond the rollback store:
 * selections of objects still holding a legacy name or a takeover marker, options and
 * transients by prefix, and the legacy billing-attempts table's retirement.
 * WordPressCopyStore binds it to WordPress; the unit tests bind it to memory.
 *
 * Every selection is bounded and ascending, like CopyStore's, so a persisted cursor
 * resumes exactly where the last processed row ended, and a dry run (which deletes
 * nothing) walks the same rows.
 */
interface FinalizeStore extends RollbackStore {
    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Orders holding any Keys::order_meta() legacy key or a
     *                   Keys::takeover_markers() meta, ascending.
     */
    public function finalize_order_ids_after($after_id, $limit);

    /**
     * @param int $after_id Exclusive lower bound.
     * @param int $limit    Maximum count.
     * @return list<int> Products and variations holding a Keys::product_meta() legacy key or
     *                   a Keys::takeover_markers() meta, ascending.
     */
    public function finalize_product_ids_after($after_id, $limit);

    /**
     * @param string $prefix     Literal option-name prefix (LIKE wildcards are escaped).
     * @param string $after_name Exclusive lower bound ('' for the start).
     * @param int    $limit      Maximum count.
     * @return list<string> Option names starting with $prefix, ascending.
     */
    public function option_names_with_prefix($prefix, $after_name, $limit);

    /**
     * @param string $name Transient name.
     * @return bool True while the transient holds a value.
     */
    public function transient_exists($name);

    /**
     * delete_transient(): its value and timeout rows (or the object-cache entry).
     *
     * @param string $name Transient name.
     * @return bool True when no value is left afterwards.
     */
    public function delete_transient($name);

    /**
     * @param string $unprefixed Table name without $wpdb->prefix.
     * @return bool Whether that table exists (reported, never touched).
     */
    public function named_table_exists($unprefixed);

    /**
     * @param string $which     CopyStore::TABLE_LEGACY or CopyStore::TABLE_NEW.
     * @param string $since_gmt MySQL DATETIME in UTC.
     * @return int|null Rows whose updated_gmt is after $since_gmt (0 when the table does not
     *                  exist), or null when unreadable.
     */
    public function table_rows_updated_after($which, $since_gmt);

    /**
     * Drop the legacy billing-attempts table (only that role, never the table in use).
     *
     * @return bool True when it no longer exists afterwards.
     */
    public function drop_legacy_table();

    /**
     * @param int $post_id Post ID.
     * @return string|null Its post status, or null when it does not exist.
     */
    public function post_status($post_id);
}
