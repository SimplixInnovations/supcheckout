<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

defined('ABSPATH') || exit;

/**
 * Rollback's new → legacy back-sync (Sub-project 0b Task 10), run only while `cut_over`
 * and under the Quiesce lease.
 *
 * While cut over the new name is read first, so it holds the latest value: wherever a new
 * name holds values that differ from its legacy name, the legacy name gets exactly the new
 * value list, in row order. A new name with no value leaves the legacy name alone (the
 * reader fell back to it, so it is still the value in use). Nothing else is ever written.
 *
 * Records: each legacy write is journaled first with an audit-only restore source
 * (`none:rollback_back_sync`): the stale legacy value is deliberately replaced, and no later
 * rollback replays the entry. Paths never carry an H12 scope: a prefixed user key is
 * journaled as its prefix plus a short hash of the key.
 *
 * payment_method: an order carrying exactly `supcheckout` (only orders created after cutover
 * can) gets `upayments`, journaled (target `order`, path `<id>/payment_method`, restore
 * source `none:rollback_payment_method`), so the official plugin and SUPCheckout's
 * pre-cutover identity recognise it. This is the only payment_method write the takeover
 * makes, and no payment state is ever touched.
 *
 * Orders are written under the per-order OrderLock (the payment lifecycle holds it), each in
 * one save(): a failure persists nothing.
 */
final class BackSync {
    public const SYNCED = 'synced';
    public const LOCKED = 'locked';
    public const MISSING = 'missing';
    public const FAILED = 'failed';

    public const AUDIT_BACK_SYNC = 'none:rollback_back_sync';
    public const AUDIT_PAYMENT_METHOD = 'none:rollback_payment_method';

    /**
     * Back-sync one order's meta and/or rewrite its payment_method, under its lock.
     *
     * @param int  $order_id Order ID.
     * @param bool $meta     Also copy new → legacy meta (false after the transition, when
     *                       runtime writes go to the legacy names only).
     * @return string One of SYNCED, LOCKED, MISSING, FAILED.
     * @throws \RuntimeException When a journal entry cannot be stored (nothing is written).
     */
    public static function order($order_id, $meta) {
        $order_id = (int) $order_id;
        $token = OrderLock::acquire($order_id);
        if ($token === null) {
            return self::LOCKED;
        }
        try {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!is_object($order) || !method_exists($order, 'get_payment_method')) {
                return self::MISSING;
            }
            $changed = $meta ? self::object_meta($order, Keys::order_meta(), 'order_meta', (string) $order_id) : false;
            if ($changed === null) {
                return self::FAILED;
            }
            $changed = self::repoint_payment_method($order) || $changed;
            return self::save($order, $changed);
        } finally {
            OrderLock::release($order_id, $token);
        }
    }

    /**
     * `supcheckout` → `upayments` on one order (in memory; the caller saves), journaled.
     * Any other payment_method is left alone.
     *
     * @param object $order WooCommerce order.
     * @return bool Whether it changed.
     * @throws \RuntimeException When the journal entry cannot be stored (nothing is changed).
     */
    public static function repoint_payment_method($order) {
        if ($order->get_payment_method() !== Keys::GATEWAY) {
            return false;
        }
        Journal::record('order', (int) $order->get_id() . '/payment_method', Keys::GATEWAY, Keys::GATEWAY_LEGACY, self::AUDIT_PAYMENT_METHOD);
        $order->set_payment_method(Keys::GATEWAY_LEGACY);
        return true;
    }

    /**
     * @param RollbackStore $store      Store.
     * @param int           $product_id Product ID.
     * @return string SYNCED, MISSING or FAILED.
     */
    public static function product(RollbackStore $store, $product_id) {
        $product = $store->load_product($product_id);
        if (!is_object($product)) {
            return self::MISSING;
        }
        $changed = self::object_meta($product, Keys::product_meta(), 'product_meta', (string) (int) $product_id);
        return $changed === null ? self::FAILED : self::save($product, $changed);
    }

    /**
     * Every new user key (Keys::user_meta() and the H12 provenance prefix) whose rows differ
     * from its legacy key's rows replaces them, all rows in order: the legacy record then
     * reads exactly as the post-cutover record did (an INVALID multi-row record stays
     * INVALID), so H12 provenance outcomes are preserved.
     *
     * @param RollbackStore $store   Store.
     * @param int           $user_id User ID.
     * @return string SYNCED or FAILED.
     */
    public static function user(RollbackStore $store, $user_id) {
        $user_id = (int) $user_id;
        foreach ($store->new_user_meta_keys($user_id) as $new_key) {
            $pair = Keys::user_meta_key_pair($new_key);
            if ($pair === null || $pair[0] !== $new_key) {
                continue;
            }
            $legacy = $pair[1];
            $new_values = $store->user_meta($user_id, $new_key);
            $legacy_values = $store->user_meta($user_id, $legacy);
            if ($new_values === array() || $new_values === $legacy_values) {
                continue;
            }
            Journal::record('user_meta', $user_id . '/' . self::journal_key($legacy), $legacy_values, $new_values, self::AUDIT_BACK_SYNC);
            $slashed = array();
            foreach ($new_values as $value) {
                $slashed[] = wp_slash($value);
            }
            if (!$store->replace_user_meta($user_id, $legacy, $slashed) || $store->user_meta($user_id, $legacy) !== $new_values) {
                return self::FAILED;
            }
        }
        return self::SYNCED;
    }

    /**
     * Keys::options(): a present new option that differs from its legacy option replaces it.
     * The token-identity secret is never chosen between: two different secrets mean H12
     * provenance was already failing closed (Keys::get_option_agreed()), so the rollback
     * fails closed too.
     *
     * @return array{ok:bool, synced:list<string>, mismatches:list<string>}
     */
    public static function options() {
        $missing = new \stdClass();
        $plan = array();
        $mismatches = array();
        foreach (Keys::options() as $legacy => $new_name) {
            $new_value = get_option($new_name, $missing);
            $legacy_value = get_option($legacy, $missing);
            if ($new_value === $missing || $new_value === $legacy_value) {
                continue;
            }
            if ($legacy === CustomerTokenIdentity::SECRET_OPTION && $legacy_value !== $missing) {
                $mismatches[] = $new_name;
                continue;
            }
            $plan[] = array($legacy, $legacy_value === $missing ? null : $legacy_value, $new_value, $legacy_value !== $missing);
        }
        if ($mismatches !== array()) {
            return array('ok' => false, 'synced' => array(), 'mismatches' => $mismatches);
        }
        $synced = array();
        foreach ($plan as $write) {
            list($legacy, $before, $value, $exists) = $write;
            Journal::record('option', $legacy, $before, $value, self::AUDIT_BACK_SYNC);
            if ($exists) {
                update_option($legacy, $value);
            } else {
                add_option($legacy, $value, '', 'no');
            }
            if (get_option($legacy, $missing) !== $value) {
                $mismatches[] = $legacy;
                continue;
            }
            $synced[] = $legacy;
        }
        return array('ok' => $mismatches === array(), 'synced' => $synced, 'mismatches' => $mismatches);
    }

    /**
     * @param object                $object CRUD object.
     * @param array<string, string> $map    Legacy => new.
     * @param string                $target Journal target.
     * @param string                $id     Object ID for the journal path.
     * @return bool|null Whether anything changed; null when a row cannot be read.
     */
    private static function object_meta($object, array $map, $target, $id) {
        $changed = false;
        foreach ($map as $legacy => $new_key) {
            $legacy = (string) $legacy;
            $new_values = OrderCopier::values($object, $new_key);
            $legacy_values = OrderCopier::values($object, $legacy);
            if ($new_values === null || $legacy_values === null) {
                return null;
            }
            if ($new_values === array() || $new_values === $legacy_values) {
                continue;
            }
            Journal::record($target, $id . '/' . $legacy, $legacy_values, $new_values, self::AUDIT_BACK_SYNC);
            if ($legacy_values !== array()) {
                $object->delete_meta_data($legacy);
            }
            foreach ($new_values as $value) {
                $object->add_meta_data($legacy, $value);
            }
            $changed = true;
        }
        return $changed;
    }

    /**
     * @param object $object  CRUD object.
     * @param bool   $changed Anything to persist.
     * @return string SYNCED or FAILED.
     */
    private static function save($object, $changed) {
        if (!$changed) {
            return self::SYNCED;
        }
        try {
            $object->save();
        } catch (\Throwable $e) {
            return self::FAILED;
        }
        return self::SYNCED;
    }

    /**
     * @param string $legacy Legacy user key.
     * @return string The key itself, or its legacy prefix plus a short hash (never the H12 scope).
     */
    public static function journal_key($legacy) {
        foreach (array_keys(Keys::user_meta_prefixes()) as $prefix) {
            if (strpos($legacy, $prefix) === 0) {
                return $prefix . '#' . substr(hash('sha256', $legacy), 0, 16);
            }
        }
        return $legacy;
    }

    private function __construct() {}
}
