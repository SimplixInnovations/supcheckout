<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * Copy and verify the legacy meta of one WooCommerce CRUD object (Sub-project 0b Task 5).
 *
 * Copy, never move: every Keys::order_meta() legacy key is read through WooCommerce CRUD
 * (HPOS and legacy storage alike) and its full value list is added, in row order, under
 * the new key. Legacy rows are never changed or removed, and neither is payment_method
 * or any payment state: the copier only writes new-name meta and its own marker.
 *
 * Orders are copied under the per-order OrderLock (in the takeover window, the pair of
 * names from Keys::ephemeral_names()) and re-read under it. The payment lifecycle and part
 * of checkout take the same lock, so those writers never interleave with a copy. Other
 * runtime writers (the subscription scheduler, customer subscription actions and more)
 * write without it, so a copy can still race them; the copy rules below converge on the
 * next run instead of relying on the lock. A contended lock defers the copy; it is never
 * waited for or forced.
 *
 * While the takeover is `copying`, the legacy names are authoritative: every read is
 * legacy-only until cutover and the window model mirrors every runtime write to both
 * names. The copier therefore sets each new key to exactly the legacy value list, in
 * order, whatever the new key holds: an extra row from a stale unlocked writer, a partly
 * saved multi-row list or a late write all converge on the next copy.
 *
 * Outside `copying` the copier is conservative: it changes a new key only when that key is
 * empty or still holds exactly a list the copier confirmed equal to the legacy list;
 * anything else is reported as a mismatch (by key name only) and kept. An unreadable
 * marker claims nothing.
 *
 * In every state the confirmed lists are recorded as SHA-256 fingerprints
 * (Journal::fingerprint()) in the object's marker meta MARKER_META
 * (Keys::TAKEOVER_COPY_MARKER), the audit record of what was copied.
 *
 * Idempotent: an identical value list is skipped, and an object with nothing to change is
 * not saved. Everything one object needs is written by a single save(), so a failure
 * mid-copy persists nothing.
 *
 * HPOS with compatibility sync off (Review Focus 1): CRUD is the source of truth. When a
 * legacy key is empty through CRUD but present in postmeta, nothing is copied for it and
 * the key is reported in `disagreements`, so the switch fails closed instead of losing
 * data the official plugin may have written with update_post_meta().
 */
final class OrderCopier {
    /** Per-object marker: JSON {legacy key: fingerprint of the value list it confirmed}. */
    public const MARKER_META = Keys::TAKEOVER_COPY_MARKER;

    public const COPIED = 'copied';
    public const LOCKED = 'locked';
    public const MISSING = 'missing';
    public const FAILED = 'failed';

    /** Methods a WooCommerce CRUD object must have to be copied. */
    private const CRUD_METHODS = array('get_id', 'get_meta', 'add_meta_data', 'update_meta_data', 'delete_meta_data', 'save');

    /**
     * Copy one order's legacy meta under the per-order lock.
     *
     * @param \WC_Order|object   $order     The order; it is re-read by ID under the lock.
     * @param callable|null|bool $post_meta callable(int $id, string $key): list<mixed>,
     *                                      get_post_meta($id, $key, false), for the HPOS
     *                                      disagreement check. Null: automatic (only when
     *                                      HPOS is authoritative). False: no check.
     * @return array{status:string, copied:int, skipped:int, mismatches:list<string>, disagreements:list<string>}
     */
    public static function copy($order, $post_meta = null) {
        $order_id = self::id_of($order);
        if ($order_id <= 0) {
            return self::result(self::MISSING);
        }
        $token = OrderLock::acquire($order_id);
        if ($token === null) {
            return self::result(self::LOCKED);
        }
        try {
            $fresh = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!self::is_crud($fresh) || self::id_of($fresh) !== $order_id) {
                return self::result(self::MISSING);
            }
            if ($post_meta === null) {
                $post_meta = self::default_post_meta_reader();
            }
            return self::copy_meta($fresh, Keys::order_meta(), is_callable($post_meta) ? $post_meta : null);
        } finally {
            OrderLock::release($order_id, $token);
        }
    }

    /**
     * Field-by-field comparison of every Keys::order_meta() key present under either name.
     *
     * @param \WC_Order|object $order Order.
     * @return array{ok:bool, mismatches:list<string>} Mismatching legacy key names, never values.
     */
    public static function verify($order) {
        return self::verify_meta($order, Keys::order_meta());
    }

    /**
     * The copy itself, for any CRUD object (orders under their lock, products as they are).
     *
     * @param object                $object    WooCommerce CRUD object.
     * @param array<string, string> $map       Legacy => new key.
     * @param callable|null         $post_meta Postmeta reader for the disagreement check.
     * @return array{status:string, copied:int, skipped:int, mismatches:list<string>, disagreements:list<string>}
     */
    public static function copy_meta($object, array $map, $post_meta = null) {
        if (!self::is_crud($object)) {
            return self::result(self::MISSING);
        }
        $object_id = self::id_of($object);
        $raw_marker = $object->get_meta(self::MARKER_META, true, 'edit');
        $marker = self::decode_marker($raw_marker);
        $legacy_authoritative = TakeoverState::get()['status'] === TakeoverState::COPYING;
        $next_marker = array();
        $result = self::result(self::COPIED);
        $changed = false;

        foreach ($map as $legacy => $new_key) {
            $legacy = (string) $legacy;
            $legacy_values = self::values($object, $legacy);
            $new_values = self::values($object, $new_key);
            if ($legacy_values === null || $new_values === null) {
                $result['mismatches'][] = $legacy;
                continue;
            }
            if ($legacy_values === array() && $post_meta !== null && self::post_meta_present($post_meta, $object_id, $legacy)) {
                $result['disagreements'][] = $legacy;
            }
            if ($legacy_values === $new_values) {
                if ($legacy_values !== array()) {
                    ++$result['skipped'];
                    $next_marker[$legacy] = Journal::fingerprint($new_values);
                }
                continue;
            }
            if (!$legacy_authoritative && !self::is_own($new_values, $marker, $legacy)) {
                $result['mismatches'][] = $legacy;
                continue;
            }
            if ($new_values !== array()) {
                $object->delete_meta_data($new_key);
            }
            foreach ($legacy_values as $value) {
                $object->add_meta_data($new_key, $value);
            }
            ++$result['copied'];
            $changed = true;
            if ($legacy_values !== array()) {
                $next_marker[$legacy] = Journal::fingerprint($legacy_values);
            }
        }

        $encoded = $next_marker === array() ? '' : (string) wp_json_encode($next_marker);
        if ($encoded !== (is_string($raw_marker) ? $raw_marker : '')) {
            if ($encoded === '') {
                $object->delete_meta_data(self::MARKER_META);
            } else {
                $object->update_meta_data(self::MARKER_META, $encoded);
            }
            $changed = true;
        }
        if (!$changed) {
            return $result;
        }
        try {
            $object->save();
        } catch (\Throwable $e) {
            $failed = self::result(self::FAILED);
            $failed['mismatches'] = $result['mismatches'];
            $failed['disagreements'] = $result['disagreements'];
            return $failed;
        }
        return $result;
    }

    /**
     * @param object                $object WooCommerce CRUD object.
     * @param array<string, string> $map    Legacy => new key.
     * @return array{ok:bool, mismatches:list<string>}
     */
    public static function verify_meta($object, array $map) {
        if (!self::is_crud($object)) {
            $all = array();
            foreach (array_keys($map) as $legacy) {
                $all[] = (string) $legacy;
            }
            return array('ok' => false, 'mismatches' => $all);
        }
        $mismatches = array();
        foreach ($map as $legacy => $new_key) {
            $legacy_values = self::values($object, (string) $legacy);
            $new_values = self::values($object, $new_key);
            if ($legacy_values === null || $new_values === null || $legacy_values !== $new_values) {
                $mismatches[] = (string) $legacy;
            }
        }
        return array('ok' => $mismatches === array(), 'mismatches' => $mismatches);
    }

    /**
     * Every value of one key, unwrapped from WooCommerce's WC_Meta_Data objects, in row
     * order. The 'edit' context reads the raw stored values (no view filters).
     *
     * @param object $object WooCommerce CRUD object.
     * @param string $key    Meta key.
     * @return list<mixed>|null Null when a row cannot be unwrapped (fail closed).
     */
    private static function values($object, $key) {
        $rows = $object->get_meta($key, false, 'edit');
        if (!is_array($rows)) {
            return null;
        }
        $values = array();
        foreach ($rows as $row) {
            if (!is_object($row) || !method_exists($row, 'get_data')) {
                return null;
            }
            $data = $row->get_data();
            if (!is_array($data) || !array_key_exists('value', $data)) {
                return null;
            }
            $values[] = $data['value'];
        }
        return $values;
    }

    /**
     * @param list<mixed>          $new_values Current new-key values.
     * @param array<string,string> $marker     Decoded marker.
     * @param string               $legacy     Legacy key.
     * @return bool True when the copier may replace the new key's values.
     */
    private static function is_own(array $new_values, array $marker, $legacy) {
        if ($new_values === array()) {
            return true;
        }
        return isset($marker[$legacy]) && hash_equals($marker[$legacy], Journal::fingerprint($new_values));
    }

    /**
     * @param mixed $raw Stored marker.
     * @return array<string, string> Legacy key => fingerprint; empty when unreadable.
     */
    private static function decode_marker($raw) {
        if (!is_string($raw) || $raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $marker = array();
        foreach ($decoded as $key => $fingerprint) {
            if (!is_string($key) || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                return array();
            }
            $marker[$key] = $fingerprint;
        }
        return $marker;
    }

    /**
     * @param callable $post_meta Reader.
     * @param int      $object_id Object ID.
     * @param string   $legacy    Legacy key.
     * @return bool
     */
    private static function post_meta_present($post_meta, $object_id, $legacy) {
        $values = call_user_func($post_meta, $object_id, $legacy);
        return is_array($values) && $values !== array();
    }

    /** @return callable|null get_post_meta() reader when HPOS is the authoritative store. */
    private static function default_post_meta_reader() {
        if (!function_exists('get_post_meta') || !Environment::hpos_enabled()) {
            return null;
        }
        return static function ($object_id, $key) {
            $values = get_post_meta((int) $object_id, (string) $key, false);
            return is_array($values) ? $values : array();
        };
    }

    /**
     * @param mixed $object Candidate.
     * @return bool
     */
    private static function is_crud($object) {
        if (!is_object($object)) {
            return false;
        }
        foreach (self::CRUD_METHODS as $method) {
            if (!method_exists($object, $method)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param mixed $object Candidate.
     * @return int
     */
    private static function id_of($object) {
        return is_object($object) && method_exists($object, 'get_id') ? (int) $object->get_id() : 0;
    }

    /**
     * @param string $status Status.
     * @return array{status:string, copied:int, skipped:int, mismatches:list<string>, disagreements:list<string>}
     */
    private static function result($status) {
        return array('status' => $status, 'copied' => 0, 'skipped' => 0, 'mismatches' => array(), 'disagreements' => array());
    }

    private function __construct() {}
}
