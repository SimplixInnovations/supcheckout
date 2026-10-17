<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * Removal of the takeover's new-name copies after a rollback or abort (Sub-project 0b
 * Task 10). It runs only after the transition (rolled_back, or preflight_ok for an abort):
 * reads are legacy-only and runtime writes reach the legacy names only, so the copies are
 * unreachable and nothing recreates them.
 *
 * One step() is one bounded unit of a phase; the caller persists `progress` after each, so
 * a killed process resumes where it stopped. Every unit is idempotent.
 *
 * Phases, in order:
 * - cron: every WP-Cron event under a Keys::cron_hooks() new name moves back to its legacy
 *   name: a legacy event with the same arguments (at any time) is kept, a missing one is
 *   recreated from the new event (same time, arguments and recurrence), then the new event
 *   is unscheduled. That covers the cutover's journaled twins and every reconcile the
 *   runtime scheduled under the new name while cut over; after preflight_ok only the legacy
 *   names have callbacks, so nothing scheduled is lost;
 * - orders (ORDER_BATCH per step, under the per-order lock): a `supcheckout` payment_method
 *   that an in-flight cut-over request still wrote becomes `upayments` (journaled), and every
 *   new Keys::order_meta() key and Keys::takeover_markers() meta is deleted, in one save();
 * - users: every new user key, including H12 provenance under the new prefix;
 * - products: new Keys::product_meta() keys and takeover markers;
 * - options: every new-name option copy, except gateway settings kept for a
 *   `settings_diverged` conflict (the pre-transition back-sync filled or reported the rest);
 * - table: every row of the new billing-attempts table (the table itself stays).
 *
 * Records: per option and cron event, and one entry per object batch, all audit-only
 * (`none:rollback_removal`), so no rollback ever replays them.
 */
final class CopyRemoval {
    public const PHASE_CRON = 'cron';
    public const PHASE_ORDERS = 'orders';
    public const PHASE_USERS = 'users';
    public const PHASE_PRODUCTS = 'products';
    public const PHASE_OPTIONS = 'options';
    public const PHASE_TABLE = 'table';
    public const PHASE_DONE = 'done';

    public const CONTINUE_RUN = 'continue';
    public const DONE = 'done';
    public const LOCKED = 'locked';
    public const FAILED = 'failed';

    public const AUDIT = 'none:rollback_removal';

    private const ORDER = array(self::PHASE_CRON, self::PHASE_ORDERS, self::PHASE_USERS, self::PHASE_PRODUCTS, self::PHASE_OPTIONS, self::PHASE_TABLE, self::PHASE_DONE);

    /**
     * @return array{phase:string, cursor:int|string}
     */
    public static function start() {
        return array('phase' => self::PHASE_CRON, 'cursor' => 0);
    }

    /**
     * @param RollbackStore              $store    Store.
     * @param RollbackSystem             $system   Cron.
     * @param array<string, mixed>       $progress {phase, cursor}.
     * @param list<string>               $keep     Option copies kept (gateway settings with a `settings_diverged` conflict).
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     * @throws \RuntimeException When an audit entry cannot be stored.
     */
    public static function step(RollbackStore $store, RollbackSystem $system, array $progress, array $keep = array()) {
        $phase = isset($progress['phase']) && in_array($progress['phase'], self::ORDER, true) ? (string) $progress['phase'] : self::PHASE_CRON;
        $cursor = isset($progress['cursor']) ? $progress['cursor'] : 0;
        switch ($phase) {
            case self::PHASE_CRON:
                return self::cron($system);
            case self::PHASE_ORDERS:
                return self::orders($store, (int) $cursor);
            case self::PHASE_USERS:
                return self::users($store, (int) $cursor);
            case self::PHASE_PRODUCTS:
                return self::products($store, (int) $cursor);
            case self::PHASE_OPTIONS:
                return self::options($keep);
            case self::PHASE_TABLE:
                return self::table($store);
            default:
                return self::result(self::DONE, self::PHASE_DONE, 0);
        }
    }

    /**
     * @param RollbackSystem $system Cron.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function cron(RollbackSystem $system) {
        $legacy_for = array_flip(Keys::cron_hooks());
        $events = $system->cron_events();
        $present = array();
        foreach ($events as $event) {
            $present[$event['hook'] . '#' . self::args_key($event['args'])] = true;
        }
        $moved = 0;
        foreach ($events as $event) {
            if (!isset($legacy_for[$event['hook']])) {
                continue;
            }
            $legacy = $legacy_for[$event['hook']];
            $args_key = self::args_key($event['args']);
            $shape = array('schedule' => $event['schedule'], 'args' => $event['args']);
            if (!isset($present[$legacy . '#' . $args_key])) {
                Journal::record('cron', $legacy . '@' . (int) $event['timestamp'] . '#' . $args_key, null, $shape, self::AUDIT);
                if (!$system->schedule_event((int) $event['timestamp'], $legacy, $event['args'], $event['schedule'])) {
                    return self::result(self::FAILED, self::PHASE_CRON, 0);
                }
                $present[$legacy . '#' . $args_key] = true;
            }
            Journal::record('cron', $event['hook'] . '@' . (int) $event['timestamp'] . '#' . $args_key, $shape, null, self::AUDIT);
            if (!$system->unschedule_event((int) $event['timestamp'], $event['hook'], $event['args'])) {
                return self::result(self::FAILED, self::PHASE_CRON, 0);
            }
            ++$moved;
        }
        $result = self::result(self::CONTINUE_RUN, self::PHASE_ORDERS, 0);
        $result['restored'] = $moved;
        return $result;
    }

    /**
     * @param RollbackStore $store  Store.
     * @param int           $cursor Last order done.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function orders(RollbackStore $store, $cursor) {
        $ids = $store->rollback_order_ids_after($cursor, CopyRunner::ORDER_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_USERS, 0);
        }
        $done = $cursor;
        $keys = array_merge(array_values(Keys::order_meta()), Keys::takeover_markers());
        foreach ($ids as $order_id) {
            $status = self::order($order_id, $keys);
            if ($status !== BackSync::SYNCED && $status !== BackSync::MISSING) {
                self::audit('orders', $cursor, $done);
                return self::result($status === BackSync::LOCKED ? self::LOCKED : self::FAILED, self::PHASE_ORDERS, $done);
            }
            $done = $order_id;
        }
        self::audit('orders', $cursor, $done);
        return self::result(self::CONTINUE_RUN, self::PHASE_ORDERS, $done);
    }

    /**
     * @param int          $order_id Order ID.
     * @param list<string> $keys     New keys and markers.
     * @return string A BackSync status.
     */
    private static function order($order_id, array $keys) {
        $token = OrderLock::acquire($order_id);
        if ($token === null) {
            return BackSync::LOCKED;
        }
        try {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!is_object($order) || !method_exists($order, 'get_payment_method')) {
                return BackSync::MISSING;
            }
            $changed = BackSync::repoint_payment_method($order);
            $changed = self::delete_keys($order, $keys) || $changed;
            return self::save($order, $changed);
        } finally {
            OrderLock::release($order_id, $token);
        }
    }

    /**
     * @param RollbackStore $store  Store.
     * @param int           $cursor Last user done.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function users(RollbackStore $store, $cursor) {
        $ids = $store->rollback_user_ids_after($cursor, CopyRunner::USER_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_PRODUCTS, 0);
        }
        $done = $cursor;
        foreach ($ids as $user_id) {
            foreach ($store->new_user_meta_keys($user_id) as $key) {
                if (!$store->delete_user_meta_key($user_id, $key)) {
                    self::audit('users', $cursor, $done);
                    return self::result(self::FAILED, self::PHASE_USERS, $done);
                }
            }
            $done = $user_id;
        }
        self::audit('users', $cursor, $done);
        return self::result(self::CONTINUE_RUN, self::PHASE_USERS, $done);
    }

    /**
     * @param RollbackStore $store  Store.
     * @param int           $cursor Last product done.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function products(RollbackStore $store, $cursor) {
        $ids = $store->rollback_product_ids_after($cursor, CopyRunner::PRODUCT_BATCH);
        if ($ids === array()) {
            return self::result(self::CONTINUE_RUN, self::PHASE_OPTIONS, 0);
        }
        $done = $cursor;
        $keys = array_merge(array_values(Keys::product_meta()), Keys::takeover_markers());
        foreach ($ids as $product_id) {
            $product = $store->load_product($product_id);
            if (is_object($product) && self::save($product, self::delete_keys($product, $keys)) !== BackSync::SYNCED) {
                self::audit('products', $cursor, $done);
                return self::result(self::FAILED, self::PHASE_PRODUCTS, $done);
            }
            $done = $product_id;
        }
        self::audit('products', $cursor, $done);
        return self::result(self::CONTINUE_RUN, self::PHASE_PRODUCTS, $done);
    }

    /**
     * Every new-name option copy is deleted: after the transition reads are legacy-only, the
     * pre-transition back-sync already filled or reported each one, and a leftover copy would
     * block the next copy run. Only names in $keep (gateway settings the owner must reconcile,
     * `settings_diverged`) stay.
     *
     * @param list<string> $keep Option names kept.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function options(array $keep) {
        $result = self::result(self::CONTINUE_RUN, self::PHASE_TABLE, '');
        $missing = new \stdClass();
        foreach (array_merge(array(Keys::SETTINGS_OPTION), array_values(Keys::options())) as $name) {
            $current = get_option($name, $missing);
            if ($current === $missing || in_array($name, $keep, true)) {
                continue;
            }
            Journal::record('option', $name, $current, null, self::AUDIT);
            delete_option($name);
            if (get_option($name, $missing) !== $missing) {
                return self::result(self::FAILED, self::PHASE_OPTIONS, 0);
            }
            ++$result['restored'];
        }
        return $result;
    }

    /**
     * @param RollbackStore $store Store.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function table(RollbackStore $store) {
        if (!$store->table_exists(CopyStore::TABLE_NEW)) {
            return self::result(self::DONE, self::PHASE_DONE, 0);
        }
        $keys = $store->table_keys_after(CopyStore::TABLE_NEW, '', BillingTableCopier::BATCH);
        if ($keys === array()) {
            return self::result(self::DONE, self::PHASE_DONE, 0);
        }
        Journal::record('removal', 'billing_attempts:' . count($keys), null, count($keys), self::AUDIT);
        if (!$store->table_delete_keys(CopyStore::TABLE_NEW, $keys)) {
            return self::result(self::FAILED, self::PHASE_TABLE, '');
        }
        return self::result(self::CONTINUE_RUN, self::PHASE_TABLE, '');
    }

    /**
     * @param object       $object CRUD object.
     * @param list<string> $keys   Keys to delete.
     * @return bool Whether any was present.
     */
    private static function delete_keys($object, array $keys) {
        $changed = false;
        foreach ($keys as $key) {
            $values = OrderCopier::values($object, $key);
            if ($values === null || $values !== array()) {
                $object->delete_meta_data($key);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * @param object $object  CRUD object.
     * @param bool   $changed Anything to persist.
     * @return string BackSync::SYNCED or BackSync::FAILED.
     */
    private static function save($object, $changed) {
        if (!$changed) {
            return BackSync::SYNCED;
        }
        try {
            $object->save();
        } catch (\Throwable $e) {
            return BackSync::FAILED;
        }
        return BackSync::SYNCED;
    }

    /**
     * @param string $kind  Object kind.
     * @param int    $after Exclusive lower bound of the batch.
     * @param int    $upto  Last object done.
     * @return void
     */
    private static function audit($kind, $after, $upto) {
        if ($upto > $after) {
            Journal::record('removal', $kind . ':' . (int) $after . '-' . (int) $upto, null, $upto, self::AUDIT);
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
     * @param string     $status Status.
     * @param string     $phase  Next phase.
     * @param int|string $cursor Next cursor.
     * @return array{status:string, progress:array{phase:string, cursor:int|string}, conflicts:list<array{seq:int, target:string, path:string}>, restored:int}
     */
    private static function result($status, $phase, $cursor) {
        return array('status' => $status, 'progress' => array('phase' => $phase, 'cursor' => $cursor), 'conflicts' => array(), 'restored' => 0);
    }

    private function __construct() {}
}
