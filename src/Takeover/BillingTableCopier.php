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
 * refreshed from the legacy row (REPLACE of exactly those keys), and a copied row whose
 * legacy row was deleted (CycleClaim::release_claimed()) is deleted from the new table over
 * the copied range, and over the open tail on the last batch. Cutover (Task 7) still
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
    /** Rollback: a REPLACE would move a legacy row backwards. */
    public const ERROR_REGRESSIVE = 'regressive_rows';
    /** Rollback: the new table lacks legacy rows it must hold. */
    public const ERROR_NEW_SHORT = 'new_table_short';
    /** Rollback: the authoritative new table does not exist next to a legacy table. */
    public const ERROR_NEW_MISSING = 'new_table_missing';
    /** CycleClaim's states, in the only order a row moves. */
    private const STATE_ORDER = array('claimed', 'dispatching', 'held', 'resolved');
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
            if (!$store->table_delete_orphans($cursor, null)) {
                return self::copied(true, $cursor, 0, self::ERROR_READ);
            }
            return self::copied(true, $cursor, 0, null);
        }
        $last = (string) end($keys);
        $done = count($keys) < self::BATCH;
        if (!$store->table_insert_missing($columns, $cursor, $last)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        $stale = self::differing($store, $columns, $cursor, $last, false);
        if ($stale !== array() && !$store->table_replace($columns, $stale)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        if (!$store->table_delete_orphans($cursor, $done ? null : $last)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        return self::copied($done, $last, count($keys), null);
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
     * Rollback (Task 10): copy the next batch of NEW rows after $cursor back into the legacy
     * table. While cut over the new table is the single-written one, so it is authoritative:
     * missing and differing legacy rows are REPLACEd, and legacy rows with no new row (a claim
     * CycleClaim::release_claimed() deleted after cutover) are deleted over the batch range,
     * and over the open tail on the last batch. Runs over the whole table, never bounded by
     * `max_copied_key`.
     *
     * @param RollbackStore $store  Store.
     * @param string        $cursor Last new-table key synced ('' at the start).
     * @return array{done:bool, cursor:string, rows:int, error:string|null}
     */
    public static function back_sync_batch(RollbackStore $store, $cursor) {
        $cursor = (string) $cursor;
        if (!$store->table_exists(CopyStore::TABLE_NEW)) {
            return self::copied(true, $cursor, 0, self::new_table_missing($store));
        }
        if ($cursor === '') {
            $short = self::new_table_short($store);
            if ($short !== null) {
                return self::copied(true, $cursor, 0, $short);
            }
        }
        if (!$store->table_exists(CopyStore::TABLE_LEGACY) && !$store->create_table(CopyStore::TABLE_LEGACY)) {
            return self::copied(true, $cursor, 0, self::ERROR_CREATE);
        }
        $columns = self::columns($store, CopyStore::TABLE_NEW, CopyStore::TABLE_LEGACY);
        if (!is_array($columns)) {
            return self::copied(true, $cursor, 0, $columns);
        }
        $keys = $store->table_keys_after(CopyStore::TABLE_NEW, $cursor, self::BATCH);
        $done = count($keys) < self::BATCH;
        $last = $keys === array() ? $cursor : (string) end($keys);
        if ($keys !== array()) {
            $stale = self::differing($store, $columns, $cursor, $last, false, CopyStore::TABLE_NEW, CopyStore::TABLE_LEGACY);
            if (self::regressive($store, $stale) !== array()) {
                // A REPLACE would move a legacy row backwards: keep it and refuse (fail closed).
                return self::copied(true, $cursor, 0, self::ERROR_REGRESSIVE);
            }
            if ($stale !== array() && !$store->table_replace_into(CopyStore::TABLE_LEGACY, $columns, $stale)) {
                return self::copied(true, $cursor, 0, self::ERROR_READ);
            }
        }
        if (!$store->table_delete_orphans_from(CopyStore::TABLE_LEGACY, $cursor, $done ? null : $last)) {
            return self::copied(true, $cursor, 0, self::ERROR_READ);
        }
        return self::copied($done, $last, count($keys), null);
    }

    /**
     * The cut-over invariant: every legacy row that is not `claimed` still exists in the new
     * table (only CycleClaim::release_claimed() deletes rows, and only `claimed` ones). A new
     * table that is missing, empty or smaller than that breaks it, and the back-sync refuses
     * before writing anything rather than deleting legacy rows.
     *
     * @param RollbackStore $store Store.
     * @return string|null Error code, or null when the new table can be authoritative.
     */
    private static function new_table_short(RollbackStore $store) {
        if (!$store->table_exists(CopyStore::TABLE_LEGACY)) {
            return null;
        }
        $legacy = $store->table_count(CopyStore::TABLE_LEGACY);
        $claimed = $store->table_count_state(CopyStore::TABLE_LEGACY, self::STATE_ORDER[0]);
        $new = $store->table_exists(CopyStore::TABLE_NEW) ? $store->table_count(CopyStore::TABLE_NEW) : 0;
        if ($legacy === null || $claimed === null || $new === null) {
            return self::ERROR_READ;
        }
        return $legacy - $claimed > 0 && ($new === 0 || $new < $legacy - $claimed) ? self::ERROR_NEW_SHORT : null;
    }

    /**
     * While cut over the new table is the authoritative one, and the copy phase creates it
     * whenever a legacy table exists. A legacy table without it means the authoritative rows
     * are lost (dropped or never restored): a cycle resolved only there would fall back to a
     * stale legacy `claimed` row and become claimable again, so nothing is synced or deleted.
     * With neither table there is nothing to back-sync.
     *
     * @param RollbackStore $store Store.
     * @return string|null ERROR_NEW_MISSING, or null when there is no legacy table either.
     */
    private static function new_table_missing(RollbackStore $store) {
        return $store->table_exists(CopyStore::TABLE_LEGACY) ? self::ERROR_NEW_MISSING : null;
    }

    /**
     * Keys whose new row is not at least as far along as its legacy row
     * (claimed < dispatching < held < resolved); an unknown state counts as behind.
     *
     * @param RollbackStore $store Store.
     * @param list<string>  $keys  Keys about to be replaced.
     * @return list<string>
     */
    private static function regressive(RollbackStore $store, array $keys) {
        if ($keys === array()) {
            return array();
        }
        $legacy = $store->table_states(CopyStore::TABLE_LEGACY, $keys);
        $new = $store->table_states(CopyStore::TABLE_NEW, $keys);
        $behind = array();
        foreach ($keys as $key) {
            if (!isset($legacy[$key])) {
                continue;
            }
            $from = array_search($legacy[$key], self::STATE_ORDER, true);
            $to = isset($new[$key]) ? array_search($new[$key], self::STATE_ORDER, true) : false;
            if ($from === false || $to === false || $to < $from) {
                $behind[] = $key;
            }
        }
        return $behind;
    }

    /**
     * Rollback (Task 10): verify the next batch of new rows against the legacy table; the
     * last batch also compares the row counts and covers every legacy row beyond the last
     * new key.
     *
     * @param RollbackStore $store  Store.
     * @param string        $cursor Last key verified ('' at the start).
     * @return array{done:bool, cursor:string, mismatches:int, codes:list<string>}
     */
    public static function back_verify_batch(RollbackStore $store, $cursor) {
        $cursor = (string) $cursor;
        if (!$store->table_exists(CopyStore::TABLE_NEW)) {
            $missing = self::new_table_missing($store);
            return self::verified(true, $cursor, $missing === null ? 0 : 1, $missing === null ? array() : array($missing));
        }
        $columns = self::columns($store, CopyStore::TABLE_NEW, CopyStore::TABLE_LEGACY);
        if (!is_array($columns)) {
            return self::verified(true, $cursor, 1, array($columns));
        }
        $keys = $store->table_keys_after(CopyStore::TABLE_NEW, $cursor, self::BATCH);
        $done = count($keys) < self::BATCH;
        $upto = $done ? null : (string) end($keys);
        $differing = count(self::differing($store, $columns, $cursor, $upto, true, CopyStore::TABLE_NEW, CopyStore::TABLE_LEGACY));
        $codes = $differing > 0 ? array(self::CHECKSUM) : array();
        $next = $keys === array() ? $cursor : (string) end($keys);
        if (!$done) {
            return self::verified(false, $next, $differing, $codes);
        }
        $new_count = $store->table_count(CopyStore::TABLE_NEW);
        $legacy_count = $store->table_count(CopyStore::TABLE_LEGACY);
        if ($legacy_count === null || $new_count === null || $legacy_count !== $new_count) {
            ++$differing;
            $codes[] = self::ROW_COUNT;
        }
        return self::verified(true, $next, $differing, $codes);
    }

    /**
     * @param CopyStore $store  Store.
     * @param string    $source Role copied from.
     * @param string    $target Role copied into.
     * @return list<string>|string The source columns, or an error code.
     */
    private static function columns(CopyStore $store, $source = CopyStore::TABLE_LEGACY, $target = CopyStore::TABLE_NEW) {
        $from = $store->table_columns($source);
        $into = $store->table_columns($target);
        if ($from === array() || $into === array()) {
            return self::ERROR_READ;
        }
        if (!in_array(self::KEY_COLUMN, $from, true) || array_diff($from, $into) !== array()) {
            return self::ERROR_SCHEMA;
        }
        return $from;
    }

    /**
     * @param CopyStore    $store      Store.
     * @param list<string> $columns    Columns.
     * @param string       $after      Exclusive lower bound.
     * @param string|null  $upto       Inclusive upper bound; null for none.
     * @param bool         $with_extra Also count target rows that have no source row.
     * @param string       $source     Authoritative role.
     * @param string       $target     Copy role.
     * @return list<string> Keys whose rows differ.
     */
    private static function differing(CopyStore $store, array $columns, $after, $upto, $with_extra, $source = CopyStore::TABLE_LEGACY, $target = CopyStore::TABLE_NEW) {
        $from = $store->table_checksums($source, $columns, $after, $upto);
        $into = $store->table_checksums($target, $columns, $after, $upto);
        $keys = array();
        foreach ($from as $key => $sum) {
            if (!isset($into[$key]) || !hash_equals($sum, $into[$key])) {
                $keys[] = (string) $key;
            }
        }
        if ($with_extra) {
            foreach (array_keys(array_diff_key($into, $from)) as $key) {
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
