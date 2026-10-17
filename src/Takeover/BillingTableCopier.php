<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Copy and verify the billing-attempts journal (Sub-project 0b Task 6, table phase).
 *
 * The new table is created from CycleClaim's own schema definition
 * (CycleClaim::schema_sql(), through CopyStore::create_new_table()); there is no second
 * copy of the DDL. Rows are copied in primary-key order, at most BATCH per step, with
 * INSERT IGNORE, so a re-run after an interruption never duplicates a row.
 *
 * Before cutover the new table has no runtime writer: Keys::table() routes every claim to
 * the legacy table until is_cut_over(), and Rollback removes the copies before a store can
 * re-enter copying. Every row in it is therefore the takeover's own copy, and a copied row
 * whose legacy row moved on (a live claim advancing from `claimed` to `dispatching`) is
 * refreshed from the legacy row (REPLACE of exactly those keys). Cutover (Task 7) still
 * re-syncs the whole table under a quiesce; this phase is the bulk copy and its proof.
 *
 * Verification walks the legacy keys again and compares a per-row checksum over every
 * legacy column, then compares the row counts. Mismatches are reported as counts plus
 * codes (`checksum`, `row_count`, `schema_mismatch`, `table_create_failed`), never as
 * row contents.
 */
final class BillingTableCopier {
    /** Rows per step. */
    public const BATCH = 500;
    /** Primary key of the billing-attempts table (CycleClaim::schema_sql()). */
    public const KEY_COLUMN = 'cycle_key';

    public const ERROR_SCHEMA = 'schema_mismatch';
    public const ERROR_CREATE = 'table_create_failed';
    public const ERROR_READ = 'table_unreadable';
    public const CHECKSUM = 'checksum';
    public const ROW_COUNT = 'row_count';

    /**
     * Copy the next batch of legacy rows after $cursor.
     *
     * @param CopyStore $store  Store.
     * @param string    $cursor Last key copied ('' at the start).
     * @return array{done:bool, cursor:string, rows:int, error:string|null}
     */
    public static function copy_batch(CopyStore $store, $cursor) {
        $cursor = (string) $cursor;
        if (!$store->table_exists(CopyStore::TABLE_LEGACY)) {
            return self::copied(true, $cursor, 0, null);
        }
        if (!$store->table_exists(CopyStore::TABLE_NEW) && !$store->create_new_table()) {
            return self::copied(true, $cursor, 0, self::ERROR_CREATE);
        }
        $columns = self::columns($store);
        if (!is_array($columns)) {
            return self::copied(true, $cursor, 0, $columns);
        }
        $keys = $store->table_keys_after(CopyStore::TABLE_LEGACY, $cursor, self::BATCH);
        if ($keys === array()) {
            return self::copied(true, $cursor, 0, null);
        }
        $last = (string) end($keys);
        if (!$store->table_insert_missing($columns, $cursor, $last)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        $stale = self::differing($store, $columns, $cursor, $last, false);
        if ($stale !== array() && !$store->table_replace($columns, $stale)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        return self::copied(count($keys) < self::BATCH, $last, count($keys), null);
    }

    /**
     * Verify the next batch of legacy rows after $cursor; the last batch also compares
     * the row counts and covers every new row beyond the last legacy key.
     *
     * @param CopyStore $store  Store.
     * @param string    $cursor Last key verified ('' at the start).
     * @return array{done:bool, cursor:string, mismatches:int, codes:list<string>}
     */
    public static function verify_batch(CopyStore $store, $cursor) {
        $cursor = (string) $cursor;
        if (!$store->table_exists(CopyStore::TABLE_LEGACY)) {
            return self::verified(true, $cursor, 0, array());
        }
        $columns = self::columns($store);
        if (!is_array($columns)) {
            return self::verified(true, $cursor, 1, array($columns));
        }
        $keys = $store->table_keys_after(CopyStore::TABLE_LEGACY, $cursor, self::BATCH);
        $done = count($keys) < self::BATCH;
        $upto = $done ? null : (string) end($keys);
        $differing = count(self::differing($store, $columns, $cursor, $upto, true));
        $codes = $differing > 0 ? array(self::CHECKSUM) : array();
        $next = $keys === array() ? $cursor : (string) end($keys);
        if (!$done) {
            return self::verified(false, $next, $differing, $codes);
        }
        $legacy_count = $store->table_count(CopyStore::TABLE_LEGACY);
        $new_count = $store->table_count(CopyStore::TABLE_NEW);
        if ($legacy_count === null || $new_count === null || $legacy_count !== $new_count) {
            ++$differing;
            $codes[] = self::ROW_COUNT;
        }
        return self::verified(true, $next, $differing, $codes);
    }

    /**
     * @param CopyStore $store Store.
     * @return list<string>|string The legacy columns, or an error code.
     */
    private static function columns(CopyStore $store) {
        $legacy = $store->table_columns(CopyStore::TABLE_LEGACY);
        $new = $store->table_columns(CopyStore::TABLE_NEW);
        if ($legacy === array() || $new === array()) {
            return self::ERROR_READ;
        }
        if (!in_array(self::KEY_COLUMN, $legacy, true) || array_diff($legacy, $new) !== array()) {
            return self::ERROR_SCHEMA;
        }
        return $legacy;
    }

    /**
     * @param CopyStore    $store      Store.
     * @param list<string> $columns    Columns.
     * @param string       $after      Exclusive lower bound.
     * @param string|null  $upto       Inclusive upper bound; null for none.
     * @param bool         $with_extra Also count new rows that have no legacy row.
     * @return list<string> Keys whose rows differ.
     */
    private static function differing(CopyStore $store, array $columns, $after, $upto, $with_extra) {
        $legacy = $store->table_checksums(CopyStore::TABLE_LEGACY, $columns, $after, $upto);
        $new = $store->table_checksums(CopyStore::TABLE_NEW, $columns, $after, $upto);
        $keys = array();
        foreach ($legacy as $key => $sum) {
            if (!isset($new[$key]) || !hash_equals($sum, $new[$key])) {
                $keys[] = (string) $key;
            }
        }
        if ($with_extra) {
            foreach (array_keys(array_diff_key($new, $legacy)) as $key) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /**
     * @param bool        $done   Done.
     * @param string      $cursor Cursor.
     * @param int         $rows   Rows in this step.
     * @param string|null $error  Error code.
     * @return array{done:bool, cursor:string, rows:int, error:string|null}
     */
    private static function copied($done, $cursor, $rows, $error) {
        return array('done' => $done, 'cursor' => $cursor, 'rows' => $rows, 'error' => $error);
    }

    /**
     * @param bool         $done       Done.
     * @param string       $cursor     Cursor.
     * @param int          $mismatches Mismatching rows.
     * @param list<string> $codes      Codes.
     * @return array{done:bool, cursor:string, mismatches:int, codes:list<string>}
     */
    private static function verified($done, $cursor, $mismatches, array $codes) {
        return array('done' => $done, 'cursor' => $cursor, 'mismatches' => $mismatches, 'codes' => $codes);
    }

    private function __construct() {}
}
