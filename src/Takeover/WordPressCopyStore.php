<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;

defined('ABSPATH') || exit;

/**
 * CopyStore bound to WordPress and WooCommerce (Sub-project 0b Task 6).
 *
 * Every statement is prepared: values with %s / %d and every table and column name with
 * %i, so no identifier is interpolated. Selections are bounded and ascending. Objects are
 * loaded through WooCommerce CRUD (wc_get_order() / wc_get_product()), which reads HPOS or
 * legacy storage, whichever is authoritative. The direct queries are deliberate: a copy
 * cursor must see the live rows, never a cached page.
 */
final class WordPressCopyStore implements CopyStore {
    /** Keys::tables() legacy name of the billing-attempts journal (CycleClaim). */
    private const LEGACY_TABLE = 'upayments_billing_attempts';
    private const KEY_COLUMN = 'cycle_key';
    private const COLUMN_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';
    /** Rows a checksum read without an upper bound returns at most. */
    private const OPEN_RANGE_LIMIT = 1001;

    public function order_ids_after($after_id, $limit) {
        global $wpdb;
        $legacy_keys = array_keys(Keys::order_meta());
        $gateways = Keys::gateway_ids();
        $key_list = self::placeholders(count($legacy_keys), '%s');
        $gateway_list = self::placeholders(count($gateways), '%s');
        if (Environment::hpos_enabled()) {
            // HPOS: payment_method is a column of the orders table.
            $sql = 'SELECT o.id FROM %i o WHERE o.id > %d AND o.type = %s AND (o.payment_method IN (' . $gateway_list . ')'
                . ' OR EXISTS (SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key IN (' . $key_list . ')))'
                . ' ORDER BY o.id ASC LIMIT %d';
            $args = array_merge(array($wpdb->prefix . 'wc_orders', (int) $after_id, 'shop_order'), $gateways, array($wpdb->prefix . 'wc_orders_meta'), $legacy_keys, array((int) $limit));
        } else {
            // Legacy storage: payment_method is the order post's _payment_method meta.
            $sql = "SELECT p.ID FROM %i p WHERE p.ID > %d AND p.post_type = %s AND EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID"
                . " AND ((m.meta_key = '_payment_method' AND m.meta_value IN (" . $gateway_list . ')) OR m.meta_key IN (' . $key_list . ')))'
                . ' ORDER BY p.ID ASC LIMIT %d';
            $args = array_merge(array($wpdb->posts, (int) $after_id, 'shop_order', $wpdb->postmeta), $gateways, $legacy_keys, array((int) $limit));
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and placeholders.
        return self::ids($wpdb->prepare($sql, $args));
    }

    public function load_order($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : false;
        return is_object($order) ? $order : null;
    }

    public function order_post_meta_reader() {
        if (!Environment::hpos_enabled()) {
            return null;
        }
        return static function ($object_id, $key) {
            $values = get_post_meta((int) $object_id, (string) $key, false);
            return is_array($values) ? $values : array();
        };
    }

    public function user_ids_after($after_id, $limit) {
        global $wpdb;
        list($clause, $clause_args) = self::user_key_clause();
        $sql = 'SELECT DISTINCT user_id FROM %i WHERE user_id > %d AND ' . $clause . ' ORDER BY user_id ASC LIMIT %d';
        $args = array_merge(array($wpdb->usermeta, (int) $after_id), $clause_args, array((int) $limit));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and placeholders.
        return self::ids($wpdb->prepare($sql, $args));
    }

    public function legacy_user_meta_keys($user_id) {
        global $wpdb;
        list($clause, $clause_args) = self::user_key_clause();
        $sql = 'SELECT DISTINCT meta_key FROM %i WHERE user_id = %d AND ' . $clause . ' ORDER BY meta_key ASC';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and placeholders.
        $prepared = $wpdb->prepare($sql, array_merge(array($wpdb->usermeta, (int) $user_id), $clause_args));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the copy must see live user meta keys.
        $keys = $wpdb->get_col($prepared);
        return is_array($keys) ? array_values(array_map('strval', $keys)) : array();
    }

    public function user_meta($user_id, $key) {
        $values = get_user_meta((int) $user_id, (string) $key, false);
        return is_array($values) ? array_values($values) : array();
    }

    public function add_unique_user_meta($user_id, $key, $slashed_value) {
        return add_user_meta((int) $user_id, (string) $key, $slashed_value, true) !== false;
    }

    public function product_ids_after($after_id, $limit) {
        global $wpdb;
        $legacy_keys = array_keys(Keys::product_meta());
        $sql = 'SELECT DISTINCT m.post_id FROM %i m INNER JOIN %i p ON p.ID = m.post_id'
            . ' WHERE m.post_id > %d AND m.meta_key IN (' . self::placeholders(count($legacy_keys), '%s') . ')'
            . ' AND p.post_type IN (%s, %s) ORDER BY m.post_id ASC LIMIT %d';
        $args = array_merge(array($wpdb->postmeta, $wpdb->posts, (int) $after_id), $legacy_keys, array('product', 'product_variation', (int) $limit));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and placeholders.
        return self::ids($wpdb->prepare($sql, $args));
    }

    public function load_product($product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product((int) $product_id) : false;
        return is_object($product) ? $product : null;
    }

    public function table_exists($which) {
        global $wpdb;
        $table = self::table($which);
        $prepared = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; schema-existence probe must not be cached.
        return $wpdb->get_var($prepared) === $table;
    }

    public function create_new_table() {
        global $wpdb;
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(CycleClaim::schema_sql(self::table(self::TABLE_NEW), $wpdb->get_charset_collate()));
        return $this->table_exists(self::TABLE_NEW);
    }

    public function table_columns($which) {
        global $wpdb;
        $prepared = $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION ASC',
            self::table($which)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; live schema read.
        $columns = $wpdb->get_col($prepared);
        if (!is_array($columns)) {
            return array();
        }
        $out = array();
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match(self::COLUMN_PATTERN, $column) !== 1) {
                return array();
            }
            $out[] = $column;
        }
        return $out;
    }

    public function table_keys_after($which, $after_key, $limit) {
        global $wpdb;
        $prepared = $wpdb->prepare(
            'SELECT %i FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d',
            self::KEY_COLUMN,
            self::table($which),
            self::KEY_COLUMN,
            (string) $after_key,
            self::KEY_COLUMN,
            (int) $limit
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the cursor must see live rows.
        $keys = $wpdb->get_col($prepared);
        return is_array($keys) ? array_values(array_map('strval', $keys)) : array();
    }

    public function table_insert_missing(array $columns, $after_key, $upto_key) {
        global $wpdb;
        $list = self::placeholders(count($columns), '%i');
        $sql = 'INSERT IGNORE INTO %i (' . $list . ') SELECT ' . $list . ' FROM %i WHERE %i > %s AND %i <= %s ORDER BY %i ASC';
        $args = array_merge(
            array(self::table(self::TABLE_NEW)),
            $columns,
            $columns,
            array(self::table(self::TABLE_LEGACY), self::KEY_COLUMN, (string) $after_key, self::KEY_COLUMN, (string) $upto_key, self::KEY_COLUMN)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and %i placeholders, one per column.
        $prepared = $wpdb->prepare($sql, $args);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; copy into the takeover-owned new table.
        return $wpdb->query($prepared) !== false;
    }

    public function table_replace(array $columns, array $keys) {
        global $wpdb;
        if ($keys === array()) {
            return true;
        }
        $list = self::placeholders(count($columns), '%i');
        $sql = 'REPLACE INTO %i (' . $list . ') SELECT ' . $list . ' FROM %i WHERE %i IN (' . self::placeholders(count($keys), '%s') . ')';
        $args = array_merge(
            array(self::table(self::TABLE_NEW)),
            $columns,
            $columns,
            array(self::table(self::TABLE_LEGACY), self::KEY_COLUMN),
            array_map('strval', $keys)
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and %i / %s placeholders.
        $prepared = $wpdb->prepare($sql, $args);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; refresh the takeover's own copies.
        return $wpdb->query($prepared) !== false;
    }

    public function table_checksums($which, array $columns, $after_key, $upto_key) {
        global $wpdb;
        $parts = array();
        $part_args = array();
        foreach ($columns as $column) {
            // HEX keeps the separator out of every value; ISNULL tells NULL from ''.
            $parts[] = 'HEX(%i), ISNULL(%i)';
            $part_args[] = $column;
            $part_args[] = $column;
        }
        $sql = "SELECT %i AS row_key, MD5(CONCAT_WS('|', " . implode(', ', $parts) . ')) AS row_sum FROM %i WHERE %i > %s';
        $args = array_merge(array(self::KEY_COLUMN), $part_args, array(self::table($which), self::KEY_COLUMN, (string) $after_key));
        if ($upto_key === null) {
            $sql .= ' ORDER BY %i ASC LIMIT %d';
            $args = array_merge($args, array(self::KEY_COLUMN, self::OPEN_RANGE_LIMIT));
        } else {
            $sql .= ' AND %i <= %s ORDER BY %i ASC';
            $args = array_merge($args, array(self::KEY_COLUMN, (string) $upto_key, self::KEY_COLUMN));
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literal SQL and placeholders.
        $prepared = $wpdb->prepare($sql, $args);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; verification must read live rows.
        $rows = $wpdb->get_results($prepared, defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A');
        $sums = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row) && isset($row['row_key'], $row['row_sum'])) {
                $sums[(string) $row['row_key']] = (string) $row['row_sum'];
            }
        }
        return $sums;
    }

    public function table_count($which) {
        global $wpdb;
        $prepared = $wpdb->prepare('SELECT COUNT(*) FROM %i', self::table($which));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; verification must read live rows.
        $count = $wpdb->get_var($prepared);
        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * @param string $which CopyStore::TABLE_LEGACY or CopyStore::TABLE_NEW.
     * @return string Prefixed table name.
     * @throws \InvalidArgumentException For any other role.
     */
    private static function table($which) {
        global $wpdb;
        $tables = Keys::tables();
        if ($which === self::TABLE_LEGACY) {
            return $wpdb->prefix . self::LEGACY_TABLE;
        }
        if ($which === self::TABLE_NEW && isset($tables[self::LEGACY_TABLE])) {
            return $wpdb->prefix . $tables[self::LEGACY_TABLE];
        }
        throw new \InvalidArgumentException('Unknown billing-attempts table role.');
    }

    /**
     * Legacy user meta names: Keys::user_meta() keys or anything under a legacy prefix.
     *
     * @return array{0:string, 1:list<string>} SQL clause and its arguments.
     */
    private static function user_key_clause() {
        global $wpdb;
        $exact = array_keys(Keys::user_meta());
        $likes = array();
        foreach (array_keys(Keys::user_meta_prefixes()) as $prefix) {
            $likes[] = $wpdb->esc_like($prefix) . '%';
        }
        $clause = '(meta_key IN (' . self::placeholders(count($exact), '%s') . ')' . str_repeat(' OR meta_key LIKE %s', count($likes)) . ')';
        return array($clause, array_merge($exact, $likes));
    }

    /**
     * @param mixed $prepared Prepared statement.
     * @return list<int>
     */
    private static function ids($prepared) {
        global $wpdb;
        if (!is_string($prepared) || $prepared === '') {
            return array();
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; the cursor must see live rows.
        $ids = $wpdb->get_col($prepared);
        $out = array();
        foreach (is_array($ids) ? $ids : array() as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * @param int    $count       Count.
     * @param string $placeholder '%s' or '%i'.
     * @return string Comma-separated placeholders.
     */
    private static function placeholders($count, $placeholder) {
        return implode(', ', array_fill(0, max(1, (int) $count), $placeholder));
    }
}
