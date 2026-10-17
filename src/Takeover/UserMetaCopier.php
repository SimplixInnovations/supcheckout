<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Copy and verify one user's legacy meta (Sub-project 0b Task 6, users phase).
 *
 * Covers Keys::user_meta() (the Phase 9I migration ledgers) and every full key under a
 * Keys::user_meta_prefixes() legacy prefix (H12 ownership provenance,
 * `_upay_customer_token_v2_b{blog}_{scope}`).
 *
 * Never a second row: a second H12 provenance row makes the record INVALID, so a new key
 * is only ever added when it is empty, with a unique add, and read back. A new key that
 * already holds a different value is reported and kept, never replaced. A legacy key with
 * several rows is not copied at all and is reported as an anomaly: every reader falls back
 * to the legacy rows, so the outcome (an INVALID provenance record, the first ledger row)
 * is the same before and after cutover.
 *
 * add_metadata() unslashes the value it stores, so values go in through wp_slash().
 *
 * Reports name the exact key, or the bare legacy prefix for a prefixed key: an H12 scope
 * is derived from the merchant credential and never leaves this class.
 */
final class UserMetaCopier {
    /**
     * @param CopyStore $store   Store.
     * @param int       $user_id User ID.
     * @return array{copied:int, skipped:int, mismatches:list<string>, anomalies:list<string>}
     */
    public static function copy_user(CopyStore $store, $user_id) {
        $result = array('copied' => 0, 'skipped' => 0, 'mismatches' => array(), 'anomalies' => array());
        foreach (self::pairs($store, $user_id) as $pair) {
            list($new_key, $legacy) = $pair;
            $legacy_values = $store->user_meta($user_id, $legacy);
            if ($legacy_values === array()) {
                continue;
            }
            if (count($legacy_values) > 1) {
                $result['anomalies'][] = self::report_name($legacy);
                continue;
            }
            $new_values = $store->user_meta($user_id, $new_key);
            if ($new_values === $legacy_values) {
                ++$result['skipped'];
                continue;
            }
            if ($new_values !== array()) {
                $result['mismatches'][] = self::report_name($legacy);
                continue;
            }
            $store->add_unique_user_meta($user_id, $new_key, wp_slash($legacy_values[0]));
            if ($store->user_meta($user_id, $new_key) !== $legacy_values) {
                $result['mismatches'][] = self::report_name($legacy);
                continue;
            }
            ++$result['copied'];
        }
        $result['mismatches'] = array_values(array_unique($result['mismatches']));
        $result['anomalies'] = array_values(array_unique($result['anomalies']));
        return $result;
    }

    /**
     * @param CopyStore $store   Store.
     * @param int       $user_id User ID.
     * @return array{ok:bool, mismatches:list<string>, anomalies:list<string>}
     */
    public static function verify_user(CopyStore $store, $user_id) {
        $mismatches = array();
        $anomalies = array();
        foreach (self::pairs($store, $user_id) as $pair) {
            list($new_key, $legacy) = $pair;
            $legacy_values = $store->user_meta($user_id, $legacy);
            if (count($legacy_values) > 1) {
                $anomalies[] = self::report_name($legacy);
                continue;
            }
            if ($legacy_values !== $store->user_meta($user_id, $new_key)) {
                $mismatches[] = self::report_name($legacy);
            }
        }
        $mismatches = array_values(array_unique($mismatches));
        return array('ok' => $mismatches === array(), 'mismatches' => $mismatches, 'anomalies' => array_values(array_unique($anomalies)));
    }

    /**
     * @param CopyStore $store   Store.
     * @param int       $user_id User ID.
     * @return array<string, array{0:string,1:string}> Legacy key => [new, legacy].
     */
    private static function pairs(CopyStore $store, $user_id) {
        $pairs = array();
        foreach ($store->legacy_user_meta_keys($user_id) as $key) {
            $pair = Keys::user_meta_key_pair((string) $key);
            // Only legacy names start a copy; a bare prefix or a new name is no source.
            if ($pair !== null && $pair[1] === $key) {
                $pairs[$key] = $pair;
            }
        }
        return $pairs;
    }

    /**
     * @param string $legacy Legacy key.
     * @return string The key itself, or its legacy prefix.
     */
    private static function report_name($legacy) {
        foreach (array_keys(Keys::user_meta_prefixes()) as $prefix) {
            if (strpos($legacy, $prefix) === 0) {
                return $prefix;
            }
        }
        return $legacy;
    }

    private function __construct() {}
}
