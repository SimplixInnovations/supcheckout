<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * The legacy-name removal of Finalize (Sub-project 0b Task 11). It runs only after the
 * cut_over → finalized transition: runtime writes then reach the new names only, a runtime
 * delete removes both names (Keys::delete_names()), and reads take the new name first. A
 * legacy name is removed only where its new name holds exactly the same value list, so no
 * read result changes; everything else is kept and reported for the owner.
 *
 * One step() is one bounded unit of a phase; the caller persists `progress` after each, so
 * a killed process resumes where it stopped. Every unit is idempotent and re-verifies at
 * the moment it deletes. With $apply false the same walk only counts (Finalize::plan(), the
 * dry run): no lock, no save, no delete, no journal entry.
 *
 * Phases, in order:
 * - cron: every legacy-named Keys::cron_hooks() event whose new-name twin (same arguments)
 *   exists is unscheduled (one without a twin is kept: its callback still listens on both
 *   names), and every Keys::official_only() cron event (exact hooks and the update-checker
 *   prefixes);
 * - orders (under the per-order lock, one save() per order), users and products: every
 *   legacy meta key whose new key holds the identical value list (in row order), and every
 *   Keys::takeover_markers() meta. A user key with several legacy rows (the copy runner's
 *   users_multi_row anomaly, never copied) is always kept;
 * - options: Keys::options() legacy names whose new option holds the identical value;
 * - official: every Keys::official_only() option (exact and by prefix) and transient. Its
 *   tables are reported by Finalize, never touched;
 * - ephemeral: legacy rows by storage type (Keys::ephemeral_storage()): plain options by
 *   name, transients through delete_transient() plus orphaned timeout rows; GET_LOCK() names
 *   have no rows;
 * - table: handled by Finalize (it needs the Quiesce lease); step() reports it done.
 *
 * Reports carry key names, object IDs and counts, never a value (an H12 provenance key is
 * reported by its prefix). Journal entries are audit-only (`none:finalize`, fingerprints).
 */
final class FinalizeRemoval {
    public const PHASE_CRON = 'cron';
    public const PHASE_ORDERS = 'orders';
    public const PHASE_USERS = 'users';
    public const PHASE_PRODUCTS = 'products';
    public const PHASE_OPTIONS = 'options';
    public const PHASE_OFFICIAL = 'official';
    public const PHASE_EPHEMERAL = 'ephemeral';
    public const PHASE_TABLE = 'table';
    public const PHASE_DONE = 'done';

    public const CONTINUE_RUN = 'continue';
    public const DONE = 'done';
    public const LOCKED = 'locked';
    public const FAILED = 'failed';

    public const AUDIT = 'none:finalize';

    /** Option and transient rows per ephemeral step. */
    public const EPHEMERAL_BATCH = 500;
    /** Option names read per prefix query in the official phase. */
    private const OPTION_BATCH = 100;
    /** Object IDs a kept group lists at most. */
    private const IDS_SAMPLE = 10;
    private const TRANSIENT_ROW = '_transient_';
    private const TIMEOUT_ROW = '_transient_timeout_';

    private const ORDER = array(self::PHASE_CRON, self::PHASE_ORDERS, self::PHASE_USERS, self::PHASE_PRODUCTS, self::PHASE_OPTIONS,
        self::PHASE_OFFICIAL, self::PHASE_EPHEMERAL, self::PHASE_TABLE, self::PHASE_DONE);

    /** Removal counters, in report order. */
    private const COUNTERS = array('legacy_cron', 'official_cron', 'order_meta', 'markers', 'user_meta', 'product_meta', 'options',
        'official_options', 'official_transients', 'ephemeral_options', 'ephemeral_transients', 'billing_table');

    /** @return array{phase:string, cursor:int|string} */
    public static function start() {
        return array('phase' => self::PHASE_CRON, 'cursor' => 0);
    }

    /** @return array<string, int> Every removal counter at zero. */
    public static function no_removals() {
        return array_fill_keys(self::COUNTERS, 0);
    }

    /**
     * @param FinalizeStore        $store    Store.
     * @param CutoverSystem        $system   Cron (a RollbackSystem when $apply).
     * @param array<string, mixed> $progress {phase, cursor}.
     * @param bool                 $apply    False: count only (dry run).
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     * @throws \RuntimeException When an audit entry cannot be stored.
     */
    public static function step(FinalizeStore $store, CutoverSystem $system, array $progress, $apply) {
        $phase = isset($progress['phase']) && in_array($progress['phase'], self::ORDER, true) ? (string) $progress['phase'] : self::PHASE_CRON;
        $cursor = isset($progress['cursor']) ? $progress['cursor'] : 0;
        $apply = (bool) $apply;
        switch ($phase) {
            case self::PHASE_CRON:
                return self::cron($system, $apply);
            case self::PHASE_ORDERS:
                return self::orders($store, (int) $cursor, $apply);
            case self::PHASE_USERS:
                return self::users($store, (int) $cursor, $apply);
            case self::PHASE_PRODUCTS:
                return self::products($store, (int) $cursor, $apply);
            case self::PHASE_OPTIONS:
                return self::options($apply);
            case self::PHASE_OFFICIAL:
                return self::official($store, $apply);
            case self::PHASE_EPHEMERAL:
                return self::ephemeral($store, (string) $cursor, $apply);
            default:
                return self::result(self::DONE, self::PHASE_DONE, 0);
        }
    }

    /**
     * Add $more to $kept: the same kind, name and reason add up, with a bounded ID sample.
     *
     * @param list<array<string, mixed>> $kept Kept groups.
     * @param array<mixed>               $more Groups to add (possibly decoded from the stored progress).
     * @return list<array<string, mixed>>
     */
    public static function merge_kept(array $kept, array $more) {
        foreach ($more as $group) {
            if (!is_array($group) || !isset($group['kind'], $group['name'], $group['reason'])) {
                continue;
            }
            $found = false;
            foreach ($kept as $index => $known) {
                if ($known['kind'] === $group['kind'] && $known['name'] === $group['name'] && $known['reason'] === $group['reason']) {
                    $kept[$index]['count'] += (int) $group['count'];
                    $kept[$index]['ids'] = array_slice(array_values(array_unique(array_merge($known['ids'], $group['ids']))), 0, self::IDS_SAMPLE);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $kept[] = array('kind' => (string) $group['kind'], 'name' => (string) $group['name'], 'reason' => (string) $group['reason'],
                    'count' => (int) $group['count'], 'ids' => array_slice(array_map('intval', (array) $group['ids']), 0, self::IDS_SAMPLE));
            }
        }
        return $kept;
    }

    /**
     * @param string   $kind   Kind (order_meta, user_meta, product_meta, option, cron, billing_table).
     * @param string   $name   Key, option, hook or table name (never a value).
     * @param string   $reason Why it was kept.
     * @param int|null $id     Object ID, if any.
     * @return array<string, mixed>
     */
    public static function kept_group($kind, $name, $reason, $id = null) {
        return array('kind' => $kind, 'name' => $name, 'reason' => $reason, 'count' => 1, 'ids' => $id === null ? array() : array((int) $id));
    }

    /**
     * @param CutoverSystem $system Cron.
     * @param bool          $apply  Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function cron(CutoverSystem $system, $apply) {
        $result = self::result(self::CONTINUE_RUN, self::PHASE_ORDERS, 0);
        $hooks = Keys::cron_hooks();
        $events = $system->cron_events();
        $present = array();
        foreach ($events as $event) {
            $present[$event['hook'] . '#' . self::args_key($event['args'])] = true;
        }
        foreach ($events as $event) {
            $counter = null;
            if (isset($hooks[$event['hook']])) {
                if (!isset($present[$hooks[$event['hook']] . '#' . self::args_key($event['args'])])) {
                    $result['kept'] = self::merge_kept($result['kept'], array(self::kept_group('cron', $event['hook'], 'new_event_missing')));
                    continue;
                }
                $counter = 'legacy_cron';
            } elseif (self::is_official_cron($event['hook'])) {
                $counter = 'official_cron';
            }
            if ($counter === null) {
                continue;
            }
            if ($apply && !self::unschedule($system, $event)) {
                $failed = self::result(self::FAILED, self::PHASE_CRON, 0);
                $failed['kept'] = $result['kept'];
                return $failed;
            }
            ++$result['removed'][$counter];
        }
        return $result;
    }

    /**
     * @param CutoverSystem                                                                $system Cron.
     * @param array{timestamp:int, hook:string, args:array<mixed>, schedule:string|false} $event  Event.
     * @return bool
     */
    private static function unschedule(CutoverSystem $system, array $event) {
        if (!$system instanceof RollbackSystem) {
            return false;
        }
        $shape = array('schedule' => $event['schedule'], 'args' => $event['args']);
        Journal::record('cron', $event['hook'] . '@' . (int) $event['timestamp'] . '#' . self::args_key($event['args']), $shape, null, self::AUDIT);
        return $system->unschedule_event((int) $event['timestamp'], $event['hook'], $event['args']);
    }

    /**
     * @param string $hook Hook.
     * @return bool A Keys::official_only() cron hook (exact or by update-checker prefix).
     */
    private static function is_official_cron($hook) {
        $official = Keys::official_only();
        if (in_array($hook, $official['cron_hooks'], true)) {
            return true;
        }
        foreach ($official['cron_hook_prefixes'] as $prefix) {
            if (strpos($hook, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param FinalizeStore $store  Store.
     * @param int           $cursor Last order done.
     * @param bool          $apply  Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function orders(FinalizeStore $store, $cursor, $apply) {
        $ids = $store->finalize_order_ids_after($cursor, CopyRunner::ORDER_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_USERS, 0);
        }
        $result = self::result(self::CONTINUE_RUN, self::PHASE_ORDERS, $cursor);
        foreach ($ids as $order_id) {
            $outcome = self::order($store, $order_id, $apply);
            if ($outcome['status'] !== self::CONTINUE_RUN) {
                self::audit($apply, 'orders', $cursor, (int) $result['progress']['cursor']);
                $result['status'] = $outcome['status'];
                return $result;
            }
            $result = self::absorb($result, $outcome);
            $result['progress']['cursor'] = $order_id;
        }
        self::audit($apply, 'orders', $cursor, (int) $result['progress']['cursor']);
        return $result;
    }

    /**
     * @param FinalizeStore $store    Store.
     * @param int           $order_id Order ID.
     * @param bool          $apply    Remove.
     * @return array{status:string, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function order(FinalizeStore $store, $order_id, $apply) {
        $token = null;
        if ($apply) {
            $token = OrderLock::acquire($order_id);
            if ($token === null) {
                return self::outcome(self::LOCKED);
            }
        }
        try {
            $order = $store->load_order($order_id);
            if (!is_object($order)) {
                return self::outcome(self::CONTINUE_RUN);
            }
            return self::object_pass($order, Keys::order_meta(), 'order_meta', $order_id, $apply);
        } finally {
            if ($token !== null) {
                OrderLock::release($order_id, $token);
            }
        }
    }

    /**
     * @param FinalizeStore $store  Store.
     * @param int           $cursor Last product done.
     * @param bool          $apply  Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function products(FinalizeStore $store, $cursor, $apply) {
        $ids = $store->finalize_product_ids_after($cursor, CopyRunner::PRODUCT_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_OPTIONS, '');
        }
        $result = self::result(self::CONTINUE_RUN, self::PHASE_PRODUCTS, $cursor);
        foreach ($ids as $product_id) {
            $product = $store->load_product($product_id);
            if (is_object($product)) {
                $outcome = self::object_pass($product, Keys::product_meta(), 'product_meta', $product_id, $apply);
                if ($outcome['status'] !== self::CONTINUE_RUN) {
                    self::audit($apply, 'products', $cursor, (int) $result['progress']['cursor']);
                    $result['status'] = $outcome['status'];
                    return $result;
                }
                $result = self::absorb($result, $outcome);
            }
            $result['progress']['cursor'] = $product_id;
        }
        self::audit($apply, 'products', $cursor, (int) $result['progress']['cursor']);
        return $result;
    }

    /**
     * Verified-equal legacy keys and every takeover marker of one CRUD object, in one save().
     *
     * @param object               $object CRUD object.
     * @param array<string,string> $map    Legacy => new.
     * @param string               $kind   order_meta or product_meta.
     * @param int                  $id     Object ID.
     * @param bool                 $apply  Remove.
     * @return array{status:string, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function object_pass($object, array $map, $kind, $id, $apply) {
        $outcome = self::outcome(self::CONTINUE_RUN);
        $changed = false;
        foreach ($map as $legacy => $new_key) {
            $legacy_values = OrderCopier::values($object, $legacy);
            if ($legacy_values === array()) {
                continue;
            }
            $reason = self::difference($legacy_values, OrderCopier::values($object, $new_key));
            if ($reason !== null) {
                $outcome['kept'] = self::merge_kept($outcome['kept'], array(self::kept_group($kind, $legacy, $reason, $id)));
                continue;
            }
            if ($apply) {
                $object->delete_meta_data($legacy);
                $changed = true;
            }
            ++$outcome['removed'][$kind];
        }
        foreach (Keys::takeover_markers() as $marker) {
            if (OrderCopier::values($object, $marker) === array()) {
                continue;
            }
            if ($apply) {
                $object->delete_meta_data($marker);
                $changed = true;
            }
            ++$outcome['removed']['markers'];
        }
        if ($changed) {
            try {
                $object->save();
            } catch (\Throwable $e) {
                return self::outcome(self::FAILED);
            }
        }
        return $outcome;
    }

    /**
     * @param list<mixed>|null $legacy_values Legacy value list (null: unreadable).
     * @param list<mixed>|null $new_values    New value list (null: unreadable).
     * @return string|null Null when the lists are identical (verified equal), else the reason.
     */
    private static function difference($legacy_values, $new_values) {
        if ($legacy_values === null || $new_values === null) {
            return 'unreadable';
        }
        if ($new_values === $legacy_values) {
            return null;
        }
        return $new_values === array() ? 'new_missing' : 'unequal';
    }

    /**
     * @param FinalizeStore $store  Store.
     * @param int           $cursor Last user done.
     * @param bool          $apply  Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function users(FinalizeStore $store, $cursor, $apply) {
        $ids = $store->user_ids_after($cursor, CopyRunner::USER_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_PRODUCTS, 0);
        }
        $result = self::result(self::CONTINUE_RUN, self::PHASE_USERS, $cursor);
        foreach ($ids as $user_id) {
            $outcome = self::user($store, $user_id, $apply);
            if ($outcome['status'] !== self::CONTINUE_RUN) {
                self::audit($apply, 'users', $cursor, (int) $result['progress']['cursor']);
                $result['status'] = $outcome['status'];
                return $result;
            }
            $result = self::absorb($result, $outcome);
            $result['progress']['cursor'] = $user_id;
        }
        self::audit($apply, 'users', $cursor, (int) $result['progress']['cursor']);
        return $result;
    }

    /**
     * @param FinalizeStore $store   Store.
     * @param int           $user_id User ID.
     * @param bool          $apply   Remove.
     * @return array{status:string, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function user(FinalizeStore $store, $user_id, $apply) {
        $outcome = self::outcome(self::CONTINUE_RUN);
        foreach ($store->legacy_user_meta_keys($user_id) as $key) {
            $pair = Keys::user_meta_key_pair((string) $key);
            if ($pair === null || $pair[1] !== $key) {
                continue;
            }
            $legacy_values = $store->user_meta($user_id, $key);
            if ($legacy_values === array()) {
                continue;
            }
            $reason = count($legacy_values) > 1 ? 'multi_row_anomaly' : self::difference($legacy_values, $store->user_meta($user_id, $pair[0]));
            if ($reason !== null) {
                $outcome['kept'] = self::merge_kept($outcome['kept'], array(self::kept_group('user_meta', UserMetaCopier::report_name($key), $reason, $user_id)));
                continue;
            }
            if ($apply && !$store->delete_user_meta_key($user_id, $key)) {
                return self::outcome(self::FAILED);
            }
            ++$outcome['removed']['user_meta'];
        }
        return $outcome;
    }

    /**
     * @param bool $apply Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function options($apply) {
        $result = self::result(self::CONTINUE_RUN, self::PHASE_OFFICIAL, '');
        $missing = new \stdClass();
        foreach (Keys::options() as $legacy => $new_key) {
            $legacy_value = get_option($legacy, $missing);
            if ($legacy_value === $missing) {
                continue;
            }
            $new_value = get_option($new_key, $missing);
            $reason = $new_value === $missing ? 'new_missing' : ($new_value === $legacy_value ? null : 'unequal');
            if ($reason !== null) {
                $result['kept'] = self::merge_kept($result['kept'], array(self::kept_group('option', $legacy, $reason)));
                continue;
            }
            if ($apply && !self::delete_option_row($legacy, $legacy_value)) {
                return self::result(self::FAILED, self::PHASE_OPTIONS, '');
            }
            ++$result['removed']['options'];
        }
        return $result;
    }

    /**
     * @param FinalizeStore $store Store.
     * @param bool          $apply Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function official(FinalizeStore $store, $apply) {
        $result = self::result(self::CONTINUE_RUN, self::PHASE_EPHEMERAL, '0:');
        $official = Keys::official_only();
        $missing = new \stdClass();
        $names = array();
        foreach ($official['options'] as $name) {
            if (get_option($name, $missing) !== $missing) {
                $names[] = $name;
            }
        }
        foreach ($official['option_prefixes'] as $prefix) {
            $after = '';
            do {
                $batch = $store->option_names_with_prefix($prefix, $after, self::OPTION_BATCH);
                $names = array_merge($names, $batch);
                $after = $batch === array() ? $after : (string) end($batch);
            } while (count($batch) === self::OPTION_BATCH);
        }
        foreach (array_values(array_unique($names)) as $name) {
            if ($apply && !self::delete_option_row($name, get_option($name, null))) {
                return self::result(self::FAILED, self::PHASE_OFFICIAL, '');
            }
            ++$result['removed']['official_options'];
        }
        foreach ($official['transients'] as $transient) {
            if (!$store->transient_exists($transient)) {
                continue;
            }
            if ($apply) {
                Journal::record('transient', $transient, true, null, self::AUDIT);
                if (!$store->delete_transient($transient)) {
                    return self::result(self::FAILED, self::PHASE_OFFICIAL, '');
                }
            }
            ++$result['removed']['official_transients'];
        }
        return $result;
    }

    /**
     * Legacy ephemeral rows, one family at a time; the cursor is `<family index>:<last name>`.
     *
     * @param FinalizeStore $store  Store.
     * @param string        $cursor Cursor.
     * @param bool          $apply  Remove.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function ephemeral(FinalizeStore $store, $cursor, $apply) {
        $families = self::ephemeral_rows();
        $parts = explode(':', $cursor, 2);
        $index = is_numeric($parts[0]) ? (int) $parts[0] : 0;
        $after = isset($parts[1]) ? $parts[1] : '';
        if ($index >= count($families)) {
            return self::result(self::CONTINUE_RUN, self::PHASE_TABLE, '');
        }
        list($prefix, $mode) = $families[$index];
        $names = $store->option_names_with_prefix($prefix, $after, self::EPHEMERAL_BATCH);
        $result = self::result(self::CONTINUE_RUN, self::PHASE_EPHEMERAL, count($names) < self::EPHEMERAL_BATCH ? ($index + 1) . ':' : $index . ':' . (string) end($names));
        $missing = new \stdClass();
        $done = 0;
        foreach ($names as $name) {
            if ($mode === 'timeout' && get_option(self::TRANSIENT_ROW . substr($name, strlen(self::TIMEOUT_ROW)), $missing) !== $missing) {
                continue; // The transient's own timeout: delete_transient() removes it with its value.
            }
            if ($apply && !self::delete_ephemeral($store, $name, $mode)) {
                self::audit_ephemeral($apply, $prefix, $done);
                return self::result(self::FAILED, self::PHASE_EPHEMERAL, $cursor);
            }
            ++$done;
            ++$result['removed'][$mode === 'option' ? 'ephemeral_options' : 'ephemeral_transients'];
        }
        self::audit_ephemeral($apply, $prefix, $done);
        return $result;
    }

    /**
     * @param FinalizeStore $store Store.
     * @param string        $name  Option row name.
     * @param string        $mode  option, transient or timeout.
     * @return bool True when the row is gone.
     */
    private static function delete_ephemeral(FinalizeStore $store, $name, $mode) {
        if ($mode === 'transient') {
            return $store->delete_transient(substr($name, strlen(self::TRANSIENT_ROW)));
        }
        delete_option($name);
        return get_option($name, null) === null;
    }

    /**
     * Option-row prefixes of the legacy ephemeral families that have rows, with how each row
     * is removed. A transient family is walked twice: its value rows (delete_transient()),
     * then timeout rows whose value row is already gone.
     *
     * @return list<array{0:string, 1:string}>
     */
    private static function ephemeral_rows() {
        $rows = array();
        foreach (Keys::ephemeral_storage() as $prefix => $storage) {
            if ($storage === 'option') {
                $rows[] = array($prefix, 'option');
            } elseif ($storage === 'transient') {
                $rows[] = array(self::TRANSIENT_ROW . $prefix, 'transient');
                $rows[] = array(self::TIMEOUT_ROW . $prefix, 'timeout');
            }
        }
        return $rows;
    }

    /**
     * Journal (fingerprint only), delete, and confirm the option is gone.
     *
     * @param string $name  Option name.
     * @param mixed  $value Its current value.
     * @return bool
     */
    private static function delete_option_row($name, $value) {
        Journal::record('option', $name, $value, null, self::AUDIT);
        delete_option($name);
        return get_option($name, null) === null;
    }

    /**
     * @param array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>} $result  Running result.
     * @param array{status:string, removed:array<string,int>, kept:list<array<string,mixed>>}                                             $outcome One object's outcome.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function absorb(array $result, array $outcome) {
        foreach ($outcome['removed'] as $counter => $count) {
            $result['removed'][$counter] += $count;
        }
        $result['kept'] = self::merge_kept($result['kept'], $outcome['kept']);
        return $result;
    }

    /**
     * @param bool   $apply Whether anything was removed.
     * @param string $kind  Object kind.
     * @param int    $after Exclusive lower bound of the batch.
     * @param int    $upto  Last object done.
     * @return void
     */
    private static function audit($apply, $kind, $after, $upto) {
        if ($apply && $upto > $after) {
            Journal::record('finalize', $kind . ':' . (int) $after . '-' . (int) $upto, null, $upto, self::AUDIT);
        }
    }

    /**
     * @param bool   $apply  Whether anything was removed.
     * @param string $prefix Row prefix.
     * @param int    $count  Rows removed.
     * @return void
     */
    private static function audit_ephemeral($apply, $prefix, $count) {
        if ($apply && $count > 0) {
            Journal::record('finalize', 'ephemeral:' . $prefix . ':' . $count, null, $count, self::AUDIT);
        }
    }

    /**
     * @param array<mixed> $args Event arguments.
     * @return string WP-Cron's instance key.
     */
    private static function args_key(array $args) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron keys event instances by md5(serialize($args)); hashed only.
        return md5(serialize($args));
    }

    /**
     * @param string $status Status.
     * @return array{status:string, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function outcome($status) {
        return array('status' => $status, 'removed' => self::no_removals(), 'kept' => array());
    }

    /**
     * @param string     $status Status.
     * @param string     $phase  Next phase.
     * @param int|string $cursor Next cursor.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, removed:array<string,int>, kept:list<array<string,mixed>>}
     */
    private static function result($status, $phase, $cursor) {
        return array('status' => $status, 'progress' => array('phase' => $phase, 'cursor' => $cursor), 'removed' => self::no_removals(), 'kept' => array());
    }

    private function __construct() {}
}
