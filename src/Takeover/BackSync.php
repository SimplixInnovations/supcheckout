<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

defined('ABSPATH') || exit;

/**
 * Rollback's new → legacy back-sync (Sub-project 0b Task 10), run only while `cut_over`,
 * under the Quiesce lease and with the official plugin inactive.
 *
 * Absent-fill only (amended after review). While cut over SUPCheckout writes order, user
 * and product meta and Keys::options() under BOTH names (the window model), so a name that
 * exists under both names and differs was written under the legacy name by something else:
 * the official plugin (reactivated meanwhile) or a third party. That legacy value is the
 * newer one, and overwriting it could regress `_upay_last_billed_at` and similar keys into
 * a second charge. So:
 * - legacy absent, new present: the legacy name gets exactly the new value list (data
 *   written only under the new name, e.g. an order created after cutover);
 * - both present and different: the legacy value is kept and the name is reported as a
 *   `divergent` conflict (`<kind>:<path>`), for the owner to decide;
 * - new absent: nothing (the reader fell back to legacy, which is still the value in use).
 *
 * Gateway settings are not dual-written (WooCommerce saves the gateway's own option), so
 * settings() copies new → legacy only when the legacy settings are still exactly what the
 * cutover copied (the journal's after-fingerprint); otherwise `settings_diverged`.
 *
 * The token-identity secret is never chosen between: secret_agrees() is checked before any
 * write, and two different secrets fail the rollback closed.
 *
 * Records: each legacy write is journaled first with an audit-only restore source
 * (`none:rollback_back_sync`), fingerprints only. Paths never carry an H12 scope: a prefixed
 * user key is journaled and reported as its prefix plus a short hash of the key.
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
    /** Conflict target for a name present under both names with different values. */
    public const DIVERGENT = 'divergent';
    /** Conflict target for gateway settings changed under both names since cutover. */
    public const SETTINGS_DIVERGED = 'settings_diverged';

    /**
     * Back-sync one order's meta and/or rewrite its payment_method, under its lock.
     *
     * @param int  $order_id Order ID.
     * @param bool $meta     Also absent-fill legacy meta (false after the transition).
     * @return array{status:string, divergent:list<string>}
     * @throws \RuntimeException When a journal entry cannot be stored (nothing is written).
     */
    public static function order($order_id, $meta) {
        $order_id = (int) $order_id;
        $token = OrderLock::acquire($order_id);
        if ($token === null) {
            return self::outcome(self::LOCKED);
        }
        try {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!is_object($order) || !method_exists($order, 'get_payment_method')) {
                return self::outcome(self::MISSING);
            }
            $sync = $meta ? self::object_meta($order, Keys::order_meta(), 'order_meta', (string) $order_id) : array('changed' => false, 'divergent' => array());
            if ($sync === null) {
                return self::outcome(self::FAILED);
            }
            $changed = self::repoint_payment_method($order) || $sync['changed'];
            return self::outcome(self::save($order, $changed), $sync['divergent']);
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
     * @return array{status:string, divergent:list<string>}
     */
    public static function product(RollbackStore $store, $product_id) {
        $product = $store->load_product($product_id);
        if (!is_object($product)) {
            return self::outcome(self::MISSING);
        }
        $sync = self::object_meta($product, Keys::product_meta(), 'product_meta', (string) (int) $product_id);
        if ($sync === null) {
            return self::outcome(self::FAILED);
        }
        return self::outcome(self::save($product, $sync['changed']), $sync['divergent']);
    }

    /**
     * Every new user key (Keys::user_meta() and the H12 provenance prefix) whose legacy key
     * is absent fills it with all its rows, in order: the legacy record then reads exactly as
     * the post-cutover record did, so H12 provenance outcomes are preserved. A legacy key
     * that exists with other rows is kept and reported.
     *
     * @param RollbackStore $store   Store.
     * @param int           $user_id User ID.
     * @return array{status:string, divergent:list<string>}
     */
    public static function user(RollbackStore $store, $user_id) {
        $user_id = (int) $user_id;
        $divergent = array();
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
            $path = $user_id . '/' . self::journal_key($legacy);
            if ($legacy_values !== array()) {
                $divergent[] = 'user_meta:' . $path;
                continue;
            }
            Journal::record('user_meta', $path, $legacy_values, $new_values, self::AUDIT_BACK_SYNC);
            $slashed = array();
            foreach ($new_values as $value) {
                $slashed[] = wp_slash($value);
            }
            if (!$store->replace_user_meta($user_id, $legacy, $slashed) || $store->user_meta($user_id, $legacy) !== $new_values) {
                return self::outcome(self::FAILED, $divergent);
            }
        }
        return self::outcome(self::SYNCED, $divergent);
    }

    /**
     * @return bool False when both names of the token-identity secret exist and differ (H12
     *              provenance was already failing closed; the rollback must too).
     */
    public static function secret_agrees() {
        $missing = new \stdClass();
        $legacy = get_option(CustomerTokenIdentity::SECRET_OPTION, $missing);
        $new = get_option(Keys::options()[CustomerTokenIdentity::SECRET_OPTION], $missing);
        return $legacy === $missing || $new === $missing || $legacy === $new;
    }

    /**
     * Keys::options(): absent-fill; a legacy option present with another value is kept and
     * reported. Callers check secret_agrees() first.
     *
     * @return array{ok:bool, synced:list<string>, divergent:list<string>, failed:list<string>}
     */
    public static function options() {
        $missing = new \stdClass();
        $result = array('ok' => true, 'synced' => array(), 'divergent' => array(), 'failed' => array());
        foreach (Keys::options() as $legacy => $new_name) {
            $new_value = get_option($new_name, $missing);
            $legacy_value = get_option($legacy, $missing);
            if ($new_value === $missing || $new_value === $legacy_value) {
                continue;
            }
            if ($legacy_value !== $missing) {
                $result['divergent'][] = 'option:' . $legacy;
                continue;
            }
            Journal::record('option', $legacy, null, $new_value, self::AUDIT_BACK_SYNC);
            add_option($legacy, $new_value, '', 'no');
            if (get_option($legacy, $missing) !== $new_value) {
                $result['ok'] = false;
                $result['failed'][] = $legacy;
                continue;
            }
            $result['synced'][] = $legacy;
        }
        return $result;
    }

    /**
     * Gateway settings: merchant edits after cutover land in Keys::SETTINGS_OPTION only. They
     * reach the legacy settings source only while that source still holds exactly the value
     * the cutover copied (the newest copy entry's after-fingerprint); otherwise the legacy
     * settings changed too and the owner decides (`settings_diverged`, legacy and new kept).
     *
     * @param list<array<string, mixed>> $entries Cycle journal entries.
     * @return array{synced:bool, conflict:string|null, failed:bool} conflict: the legacy source name.
     */
    public static function settings(array $entries) {
        $result = array('synced' => false, 'conflict' => null, 'failed' => false);
        $missing = new \stdClass();
        $new = get_option(Keys::SETTINGS_OPTION, $missing);
        if ($new === $missing) {
            return $result;
        }
        $copy = null;
        foreach ($entries as $entry) {
            if ($entry['target'] === 'option' && $entry['path'] === Keys::SETTINGS_OPTION && strpos((string) $entry['restore_source'], 'option:') === 0) {
                $copy = $entry;
            }
        }
        $source = $copy === null ? Keys::settings_sources()[0] : substr((string) $copy['restore_source'], strlen('option:'));
        $legacy = get_option($source, $missing);
        if ($legacy === $new) {
            return $result;
        }
        $legacy_value = $legacy === $missing ? null : $legacy;
        if ($copy === null || !Journal::is_current_after($copy, $legacy_value)) {
            $result['conflict'] = $source;
            return $result;
        }
        Journal::record('option', $source, $legacy_value, $new, self::AUDIT_BACK_SYNC);
        update_option($source, $new);
        $result['synced'] = get_option($source, $missing) === $new;
        $result['failed'] = !$result['synced'];
        return $result;
    }

    /**
     * @param object                $object CRUD object.
     * @param array<string, string> $map    Legacy => new.
     * @param string                $target Journal target.
     * @param string                $id     Object ID for the journal path.
     * @return array{changed:bool, divergent:list<string>}|null Null when a row cannot be read.
     */
    private static function object_meta($object, array $map, $target, $id) {
        $changed = false;
        $divergent = array();
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
            if ($legacy_values !== array()) {
                $divergent[] = $target . ':' . $id . '/' . $legacy;
                continue;
            }
            Journal::record($target, $id . '/' . $legacy, $legacy_values, $new_values, self::AUDIT_BACK_SYNC);
            foreach ($new_values as $value) {
                $object->add_meta_data($legacy, $value);
            }
            $changed = true;
        }
        return array('changed' => $changed, 'divergent' => $divergent);
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
     * @param string       $status    Status.
     * @param list<string> $divergent Divergent names.
     * @return array{status:string, divergent:list<string>}
     */
    private static function outcome($status, array $divergent = array()) {
        return array('status' => $status, 'divergent' => $divergent);
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
