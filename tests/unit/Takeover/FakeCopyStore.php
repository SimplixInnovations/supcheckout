<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\FinalizeStore;
use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * In-memory CopyStore for the copy-runner tests.
 *
 * Orders live in the unit bootstrap's wc_get_order() store
 * ($GLOBALS['supcheckout_test_status_orders']) as FakeCrudObject instances, so the
 * copier's re-read under the lock sees exactly what the runner selected. User meta keeps
 * every row in order and unslashes added values as add_metadata() does. The two
 * billing-attempts tables are arrays of rows keyed by cycle_key. Options and transients
 * (Finalize) live in the unit bootstrap's option store, a transient as its
 * `_transient_<name>` and `_transient_timeout_<name>` rows, as without an object cache.
 */
final class FakeCopyStore implements FinalizeStore {
    /** @var bool replace_user_meta() fails. */
    public $fail_user_replace = false;

    /** @var array<int, array<string, list<mixed>>> */
    public $user_meta = array();
    /** @var array<int, FakeCrudObject> */
    public $products = array();
    /** @var array<string, array<string, array<string, mixed>>> */
    public $tables = array(self::TABLE_LEGACY => array(), self::TABLE_NEW => array());
    /** @var bool */
    public $legacy_table_exists = true;
    /** @var bool */
    public $new_table_exists = false;
    /** @var list<string> */
    public $columns = array('cycle_key', 'parent_order_id', 'owner_token', 'state', 'payment_id');
    /** @var list<string> */
    public $new_columns = array('cycle_key', 'parent_order_id', 'owner_token', 'state', 'payment_id');
    /** @var callable|null function (int $order_id): void, called before an order is loaded. */
    public $on_load_order = null;
    /** @var callable|null function (int $product_id): void, called before a product is loaded. */
    public $on_load_product = null;
    /** @var callable|null function (string $after, string $upto): void, called before an insert. */
    public $on_table_insert = null;
    /** @var callable|null function (int $user_id, string $key): void, called before a user meta add. */
    public $on_add_user_meta = null;
    /** @var callable|null Postmeta reader handed to the copier (HPOS authoritative). */
    public $post_meta = null;
    /** @var int */
    public $user_meta_adds = 0;
    /** @var list<array{0:string,1:mixed}> Calls to the selection methods: [method, limit]. */
    public $selections = array();
    /** @var int */
    public $table_creates = 0;
    /** @var bool */
    public $fail_replace = false;
    /** @var list<array{0:string,1:string|null}> Orphan deletions: [after, upto]. */
    public $orphan_deletes = array();

    public function order_ids_after($after_id, $limit) {
        $this->selections[] = array('orders', $limit);
        $ids = array();
        foreach ($GLOBALS['supcheckout_test_status_orders'] as $id => $order) {
            if ($id > $after_id && $this->is_candidate($order)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function load_order($order_id) {
        if (is_callable($this->on_load_order)) {
            call_user_func($this->on_load_order, $order_id);
        }
        return isset($GLOBALS['supcheckout_test_status_orders'][$order_id]) ? $GLOBALS['supcheckout_test_status_orders'][$order_id] : null;
    }

    public function order_post_meta_reader() {
        return $this->post_meta;
    }

    public function user_ids_after($after_id, $limit) {
        $this->selections[] = array('users', $limit);
        $ids = array();
        foreach ($this->user_meta as $user_id => $meta) {
            if ($user_id > $after_id && $this->legacy_user_meta_keys($user_id) !== array()) {
                $ids[] = (int) $user_id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function legacy_user_meta_keys($user_id) {
        $keys = array();
        foreach (array_keys(isset($this->user_meta[$user_id]) ? $this->user_meta[$user_id] : array()) as $key) {
            if (array_key_exists($key, Keys::user_meta())) {
                $keys[] = (string) $key;
                continue;
            }
            foreach (array_keys(Keys::user_meta_prefixes()) as $prefix) {
                if (strpos((string) $key, $prefix) === 0) {
                    $keys[] = (string) $key;
                }
            }
        }
        return $keys;
    }

    public function user_meta($user_id, $key) {
        return isset($this->user_meta[$user_id][$key]) ? $this->user_meta[$user_id][$key] : array();
    }

    public function add_unique_user_meta($user_id, $key, $slashed_value) {
        if (is_callable($this->on_add_user_meta)) {
            call_user_func($this->on_add_user_meta, $user_id, $key);
        }
        if ($this->user_meta($user_id, $key) !== array()) {
            return false;
        }
        ++$this->user_meta_adds;
        $this->user_meta[$user_id][$key][] = self::unslash($slashed_value);
        return true;
    }

    public function product_ids_after($after_id, $limit) {
        $this->selections[] = array('products', $limit);
        $ids = array();
        foreach ($this->products as $id => $product) {
            foreach (array_keys(Keys::product_meta()) as $legacy) {
                if ($id > $after_id && $product->persisted_values($legacy) !== array()) {
                    $ids[] = (int) $id;
                    break;
                }
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function load_product($product_id) {
        if (is_callable($this->on_load_product)) {
            call_user_func($this->on_load_product, $product_id);
        }
        return isset($this->products[$product_id]) ? $this->products[$product_id] : null;
    }

    public function table_exists($which) {
        return $which === self::TABLE_LEGACY ? $this->legacy_table_exists : $this->new_table_exists;
    }

    public function create_new_table() {
        ++$this->table_creates;
        $this->new_table_exists = true;
        return true;
    }

    public function table_columns($which) {
        if (!$this->table_exists($which)) {
            return array();
        }
        return $which === self::TABLE_LEGACY ? $this->columns : $this->new_columns;
    }

    public function table_keys_after($which, $after_key, $limit) {
        $this->selections[] = array('table_' . $which, $limit);
        if ($this->fail_legacy_keys && $which === self::TABLE_LEGACY) {
            return array();
        }
        $keys = array();
        foreach (array_keys($this->tables[$which]) as $key) {
            if (strcmp((string) $key, (string) $after_key) > 0) {
                $keys[] = (string) $key;
            }
        }
        sort($keys, SORT_STRING);
        return array_slice($keys, 0, $limit);
    }

    public function table_insert_missing(array $columns, $after_key, $upto_key) {
        if (is_callable($this->on_table_insert)) {
            call_user_func($this->on_table_insert, $after_key, $upto_key);
        }
        foreach ($this->tables[self::TABLE_LEGACY] as $key => $row) {
            if ($this->in_range($key, $after_key, $upto_key) && !isset($this->tables[self::TABLE_NEW][$key])) {
                $this->tables[self::TABLE_NEW][$key] = self::project($row, $columns);
            }
        }
        return true;
    }

    public function table_replace(array $columns, array $keys) {
        if ($this->fail_replace) {
            return false;
        }
        foreach ($keys as $key) {
            if (isset($this->tables[self::TABLE_LEGACY][$key])) {
                $this->tables[self::TABLE_NEW][$key] = self::project($this->tables[self::TABLE_LEGACY][$key], $columns);
            }
        }
        return true;
    }

    public function table_delete_orphans($after_key, $upto_key) {
        $this->orphan_deletes[] = array((string) $after_key, $upto_key === null ? null : (string) $upto_key);
        foreach (array_keys($this->tables[self::TABLE_NEW]) as $key) {
            if ($this->in_range($key, (string) $after_key, $upto_key) && !isset($this->tables[self::TABLE_LEGACY][$key])) {
                unset($this->tables[self::TABLE_NEW][$key]);
            }
        }
        return true;
    }

    public function table_checksums($which, array $columns, $after_key, $upto_key) {
        $sums = array();
        foreach ($this->tables[$which] as $key => $row) {
            if ($this->in_range($key, $after_key, $upto_key)) {
                $parts = array();
                foreach ($columns as $column) {
                    $value = array_key_exists($column, $row) ? $row[$column] : null;
                    $parts[] = $value === null ? 'N' : 'V' . bin2hex((string) $value);
                }
                $sums[(string) $key] = md5(implode('|', $parts));
            }
        }
        ksort($sums, SORT_STRING);
        return $sums;
    }

    public function table_count($which) {
        return count($this->tables[$which]);
    }

    public function table_open_claims($since_gmt) {
        if (!$this->legacy_table_exists) {
            return 0;
        }
        $open = 0;
        foreach ($this->tables[self::TABLE_LEGACY] as $row) {
            $state = isset($row['state']) ? $row['state'] : '';
            $updated = isset($row['updated_gmt']) ? (string) $row['updated_gmt'] : '';
            if (in_array($state, array('claimed', 'dispatching'), true) && strcmp($updated, (string) $since_gmt) >= 0) {
                ++$open;
            }
        }
        return $open;
    }

    public function table_stale_claims($before_gmt) {
        if (!$this->legacy_table_exists) {
            return 0;
        }
        $stale = 0;
        foreach ($this->tables[self::TABLE_LEGACY] as $row) {
            $updated = isset($row['updated_gmt']) ? (string) $row['updated_gmt'] : '';
            if (isset($row['state']) && $row['state'] === 'claimed' && strcmp($updated, (string) $before_gmt) < 0) {
                ++$stale;
            }
        }
        return $stale;
    }

    public function rollback_order_ids_after($after_id, $limit) {
        $this->selections[] = array('rollback_orders', $limit);
        $keys = array_merge(array_keys(Keys::order_meta()), array_values(Keys::order_meta()), Keys::takeover_markers());
        $ids = array();
        foreach ($GLOBALS['supcheckout_test_status_orders'] as $id => $order) {
            if ($id <= $after_id || !$order instanceof FakeCrudObject) {
                continue;
            }
            if (in_array($order->get_payment_method(), Keys::gateway_ids(), true) || self::holds_any($order, $keys)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function rollback_user_ids_after($after_id, $limit) {
        $ids = array();
        foreach (array_keys($this->user_meta) as $user_id) {
            if ($user_id > $after_id && $this->new_user_meta_keys($user_id) !== array()) {
                $ids[] = (int) $user_id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function new_user_meta_keys($user_id) {
        $keys = array();
        foreach (isset($this->user_meta[$user_id]) ? $this->user_meta[$user_id] : array() as $key => $values) {
            if ($values === array()) {
                continue;
            }
            if (in_array($key, Keys::user_meta(), true)) {
                $keys[] = (string) $key;
                continue;
            }
            foreach (Keys::user_meta_prefixes() as $prefix) {
                if (strpos((string) $key, $prefix) === 0) {
                    $keys[] = (string) $key;
                }
            }
        }
        return $keys;
    }

    public function replace_user_meta($user_id, $key, array $slashed_values) {
        if ($this->fail_user_replace) {
            return false;
        }
        $this->user_meta[$user_id][$key] = array_values(array_map(array(self::class, 'unslash'), $slashed_values));
        return true;
    }

    public function delete_user_meta_key($user_id, $key) {
        unset($this->user_meta[$user_id][$key]);
        return true;
    }

    public function rollback_product_ids_after($after_id, $limit) {
        $keys = array_merge(array_values(Keys::product_meta()), Keys::takeover_markers());
        $ids = array();
        foreach ($this->products as $id => $product) {
            if ($id > $after_id && self::holds_any($product, $keys)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function create_table($which) {
        if ($which === self::TABLE_NEW) {
            return $this->create_new_table();
        }
        $this->legacy_table_exists = true;
        return true;
    }

    public function table_replace_into($target, array $columns, array $keys) {
        if ($this->fail_replace) {
            return false;
        }
        $source = $target === self::TABLE_LEGACY ? self::TABLE_NEW : self::TABLE_LEGACY;
        foreach ($keys as $key) {
            if (isset($this->tables[$source][$key])) {
                $this->tables[$target][$key] = self::project($this->tables[$source][$key], $columns);
            }
        }
        return true;
    }

    public function table_delete_orphans_from($target, $after_key, $upto_key) {
        $source = $target === self::TABLE_LEGACY ? self::TABLE_NEW : self::TABLE_LEGACY;
        foreach ($this->tables[$target] as $key => $row) {
            if ($this->in_range($key, (string) $after_key, $upto_key) && !isset($this->tables[$source][$key])
                && isset($row['state']) && $row['state'] === 'claimed'
            ) {
                unset($this->tables[$target][$key]);
            }
        }
        return true;
    }

    public function table_states($which, array $keys) {
        $states = array();
        foreach ($keys as $key) {
            if (isset($this->tables[$which][$key])) {
                $states[(string) $key] = (string) $this->tables[$which][$key]['state'];
            }
        }
        return $states;
    }

    public function table_count_state($which, $state) {
        if (!$this->table_exists($which)) {
            return 0;
        }
        $count = 0;
        foreach ($this->tables[$which] as $row) {
            $count += isset($row['state']) && $row['state'] === $state ? 1 : 0;
        }
        return $count;
    }

    public function table_delete_keys($which, array $keys) {
        foreach ($keys as $key) {
            unset($this->tables[$which][$key]);
        }
        return true;
    }

    public function table_claims_open($which, $since_gmt) {
        if (!$this->table_exists($which)) {
            return 0;
        }
        $open = 0;
        foreach ($this->tables[$which] as $row) {
            $state = isset($row['state']) ? $row['state'] : '';
            $updated = isset($row['updated_gmt']) ? (string) $row['updated_gmt'] : '';
            if (in_array($state, array('claimed', 'dispatching'), true) && strcmp($updated, (string) $since_gmt) >= 0) {
                ++$open;
            }
        }
        return $open;
    }

    public function table_claims_stale($which, $before_gmt) {
        if (!$this->table_exists($which)) {
            return 0;
        }
        $stale = 0;
        foreach ($this->tables[$which] as $row) {
            $updated = isset($row['updated_gmt']) ? (string) $row['updated_gmt'] : '';
            if (isset($row['state']) && $row['state'] === 'claimed' && strcmp($updated, (string) $before_gmt) < 0) {
                ++$stale;
            }
        }
        return $stale;
    }

    /** @var array<string, bool> Other tables by unprefixed name (Finalize reports them). */
    public $named_tables = array();
    /** @var array<int, string> Post ID => status. */
    public $post_statuses = array();
    /** @var bool drop_legacy_table() leaves the table in place. */
    public $fail_drop = false;
    /** @var int */
    public $drops = 0;
    /** @var bool table_keys_after() on the legacy table returns nothing (a read error). */
    public $fail_legacy_keys = false;
    /** @var bool table_read_failed() reports a read error. */
    public $read_error = false;

    public function table_read_failed() {
        return $this->read_error;
    }

    public function finalize_order_ids_after($after_id, $limit) {
        $this->selections[] = array('finalize_orders', $limit);
        $keys = array_merge(array_keys(Keys::order_meta()), Keys::takeover_markers());
        $ids = array();
        foreach ($GLOBALS['supcheckout_test_status_orders'] as $id => $order) {
            if ($id > $after_id && $order instanceof FakeCrudObject && self::holds_any($order, $keys)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function finalize_product_ids_after($after_id, $limit) {
        $keys = array_merge(array_keys(Keys::product_meta()), Keys::takeover_markers());
        $ids = array();
        foreach ($this->products as $id => $product) {
            if ($id > $after_id && self::holds_any($product, $keys)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return array_slice($ids, 0, $limit);
    }

    public function option_names_with_prefix($prefix, $after_name, $limit) {
        $names = array();
        foreach (array_keys($GLOBALS['supcheckout_test_options']) as $name) {
            $name = (string) $name;
            if (strpos($name, (string) $prefix) === 0 && strcmp($name, (string) $after_name) > 0) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);
        return array_slice($names, 0, $limit);
    }

    public function transient_exists($name) {
        return array_key_exists('_transient_' . $name, $GLOBALS['supcheckout_test_options']);
    }

    public function delete_transient($name) {
        unset($GLOBALS['supcheckout_test_options']['_transient_' . $name], $GLOBALS['supcheckout_test_options']['_transient_timeout_' . $name]);
        return true;
    }

    public function named_table_exists($unprefixed) {
        return !empty($this->named_tables[$unprefixed]);
    }

    public function table_rows_updated_after($which, $since_gmt) {
        if (!$this->table_exists($which)) {
            return 0;
        }
        $count = 0;
        foreach ($this->tables[$which] as $row) {
            $updated = isset($row['updated_gmt']) ? (string) $row['updated_gmt'] : '';
            $count += strcmp($updated, (string) $since_gmt) > 0 ? 1 : 0;
        }
        return $count;
    }

    public function drop_legacy_table() {
        ++$this->drops;
        if (!$this->fail_drop) {
            $this->legacy_table_exists = false;
            $this->tables[self::TABLE_LEGACY] = array();
        }
        return !$this->legacy_table_exists;
    }

    public function post_status($post_id) {
        return isset($this->post_statuses[$post_id]) ? $this->post_statuses[$post_id] : null;
    }

    /** @param list<string> $keys */
    private static function holds_any(FakeCrudObject $object, array $keys): bool {
        foreach ($keys as $key) {
            if ($object->persisted_values($key) !== array()) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    public function add_legacy_row(array $row): void {
        $this->tables[self::TABLE_LEGACY][(string) $row['cycle_key']] = $row;
    }

    /** @return mixed */
    private static function unslash($value) {
        if (is_array($value)) {
            return array_map(array(self::class, 'unslash'), $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     * @return array<string, mixed>
     */
    private static function project(array $row, array $columns): array {
        $out = array();
        foreach ($columns as $column) {
            $out[$column] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        return $out;
    }

    /** @param int|string $key */
    private function in_range($key, string $after_key, ?string $upto_key): bool {
        return strcmp((string) $key, $after_key) > 0 && ($upto_key === null || strcmp((string) $key, $upto_key) <= 0);
    }

    /** @param mixed $order */
    private function is_candidate($order): bool {
        if (!$order instanceof FakeCrudObject) {
            return false;
        }
        if (in_array($order->get_payment_method(), Keys::gateway_ids(), true)) {
            return true;
        }
        foreach (array_keys(Keys::order_meta()) as $legacy) {
            if ($order->persisted_values($legacy) !== array()) {
                return true;
            }
        }
        return false;
    }
}
