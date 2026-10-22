<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Database-atomic, self-expiring per-order lifecycle lock.
 *
 * Acquisition uses INSERT IGNORE against the unique option_name index, so exactly
 * one worker can create the record. WordPress add_option() is not a lock: it checks
 * existence first and then writes with ON DUPLICATE KEY UPDATE, so two racing
 * workers can both report success. Stale recovery and release use SQL
 * compare-and-swap/delete against the exact stored scalar record so one worker can
 * never delete or replace another worker's lock. Recovery covers only records that
 * are provably not held (stale_reason()): expired, unparseable, or expiring further
 * ahead than any acquire() can write. Each recovery is logged without the record.
 *
 * The value condition is bytewise (CAST(option_value AS BINARY) = CAST(%s AS BINARY)):
 * option_value usually carries a case-insensitive, PAD SPACE collation, under which a
 * plain `option_value = %s` also matches a stale value differing only in case or
 * trailing spaces. The option_name equality stays a plain, index-friendly comparison.
 */
final class OrderLock {
    private const PREFIX = 'simplixpay_upay_order_lock_v1_';
    private const TTL = 45;

    /**
     * How far beyond now + TTL a lease's expiry may lie before it counts as impossible
     * rather than live: the writer's clock would have to run more than an hour ahead
     * of this node's. 80 times the TTL: clocks more than TTL apart already break the
     * lease itself (the node that runs ahead sees a live lease as expired), so this
     * bound tolerates far more skew than the lease does.
     */
    private const MAX_CLOCK_AHEAD = 3600;

    /**
     * @param int $order_id Positive WooCommerce order identity.
     * @return string|null Opaque owner token on success.
     */
    public static function acquire($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }

        $token = self::token();
        $record = self::encode_record($token, time() + self::TTL);

        // Every name of this lock (Keys::ephemeral_names(): only the legacy name until
        // the takeover starts copying, legacy then new during the switch window, only
        // the new name after Finalize), all or nothing, so a worker that started before
        // cutover and one that started after still contend.
        $held = array();
        foreach (Keys::ephemeral_names(self::PREFIX . $order_id) as $name) {
            if (!self::acquire_name($name, $record, $order_id)) {
                foreach (array_reverse($held) as $held_name) {
                    self::delete_if_current($held_name, $record);
                }
                return null;
            }
            $held[] = $name;
        }

        return $token;
    }

    /**
     * @param string $name   One lock option name.
     * @param string $record This worker's encoded record.
     * @return bool True when this worker now owns the name.
     */
    private static function acquire_name($name, $record, $order_id) {
        if (self::insert_if_absent($name, $record)) {
            return true;
        }

        $existing = get_option($name, null);
        if ($existing === null) {
            // Released between our insert and this read: the next attempt can insert.
            return false;
        }
        $stale = self::stale_reason(self::decode_record($existing));
        if ($stale === null) {
            return false;
        }

        // Compare-and-swap is essential here. A read + delete_option() + add_option()
        // sequence can delete a newer owner's lock after another worker wins recovery.
        // The swap is conditional on the exact value read, so of two recoverers only one
        // wins, and a holder that replaced the value in between is never displaced. A
        // non-string value reached get_option() unserialized; the row holds its
        // serialized form.
        $expected = is_string($existing) ? $existing : serialize($existing);
        if (!self::replace_if_current($name, $expected, $record)) {
            return false;
        }
        self::log_recovery($stale, $order_id);
        return true;
    }

    /**
     * Why an existing record may be recovered, or null while it may still be held.
     *
     * Only acquire() writes these records, always as "<now + TTL>:<token>", and the
     * format has never changed. So:
     * - an unparseable record is one that no worker holds, honours or can release;
     * - an expiry more than TTL + MAX_CLOCK_AHEAD in the future cannot come from any
     *   acquire(), even one on a node whose clock runs an hour ahead (it is a corrupt
     *   write, or a clock that was wrong and has since been corrected);
     * - a past expiry is the ordinary lease timeout.
     * Anything else is a live lease and is never taken.
     *
     * @param array{expires:int, token:string}|null $parsed Decoded record.
     * @return string|null Reason code.
     */
    private static function stale_reason($parsed) {
        if ($parsed === null) {
            return 'unparseable';
        }
        $now = time();
        if ($parsed['expires'] < $now) {
            return 'expired';
        }
        if ($parsed['expires'] > $now + self::TTL + self::MAX_CLOCK_AHEAD) {
            return 'implausible_expiry';
        }
        return null;
    }

    /**
     * Record a recovered lock for the already-validated order ID. The record value
     * is never logged.
     *
     * @param string $reason Reason code from stale_reason().
     * @return void
     */
    private static function log_recovery($reason, $order_id) {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $logger = wc_get_logger();
        if (!is_object($logger)) {
            return;
        }
        $messages = array(
            'expired'            => 'Order lock: recovered an expired lock record.',
            'unparseable'        => 'Order lock: recovered an unparseable lock record.',
            'implausible_expiry' => 'Order lock: recovered a lock record with an implausible expiry.',
        );
        $level = $reason === 'expired' ? 'info' : 'warning';
        $logger->{$level}(
            $messages[$reason],
            array('source' => 'supcheckout-order-lock', 'reason' => $reason, 'order_id' => (int) $order_id)
        );
    }

    /**
     * Release only the exact record owned by this token.
     *
     * @param int   $order_id Positive WooCommerce order identity.
     * @param mixed $token    Token returned by acquire().
     * @return void
     */
    public static function release($order_id, $token) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !is_string($token) || $token === '') {
            return;
        }

        foreach (array_reverse(Keys::ephemeral_names(self::PREFIX . $order_id)) as $name) {
            $existing = get_option($name, null);
            $parsed = self::decode_record($existing);
            if ($parsed === null || !hash_equals($parsed['token'], $token)) {
                continue;
            }

            self::delete_if_current($name, $existing);
        }
    }

    private static function encode_record($token, $expires) {
        return (string) ((int) $expires) . ':' . (string) $token;
    }

    private static function decode_record($record) {
        if (!is_string($record)
            || !preg_match('/^([1-9][0-9]{0,11}):([a-f0-9]{32}|[a-f0-9]{64})$/', $record, $matches)
        ) {
            return null;
        }
        $expires = (int) $matches[1];
        if ($expires <= 0) {
            return null;
        }
        return array('expires' => $expires, 'token' => $matches[2]);
    }

    /**
     * Create a non-autoloaded option only if no row with that name exists.
     *
     * The unique option_name index makes the insert atomic across workers. Fails
     * closed (false) when the database handle is unavailable.
     *
     * @param mixed $name  Option name; anything but a non-empty string fails closed.
     * @param mixed $value Option value; anything but a string fails closed.
     * @return bool True only for the single worker whose insert created the row.
     */
    public static function insert_if_absent($name, $value) {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->options)
            || !is_string($wpdb->options)
            || $wpdb->options === ''
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !is_string($name)
            || $name === ''
            || !is_string($value)
        ) {
            return false;
        }

        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $name,
            $value,
            'no'
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock creation uses freshly prepared SQL and must bypass caches.
        $inserted = $wpdb->query($sql);
        if ((int) $inserted !== 1) {
            return false;
        }
        self::flush_option_cache($name);
        return true;
    }

    /**
     * Compare-and-swap an option row: replace its value only if it still holds $expected.
     *
     * Bypasses update_option() (which reads, then writes unconditionally) and flushes
     * the option cache after a successful swap. Fails closed (false) when the database
     * handle is unavailable. An unchanged value (replacement === expected) reports 0
     * affected rows on MySQL and therefore false; callers skip no-op swaps.
     *
     * @param mixed $name        Option name; anything but a non-empty string fails closed.
     * @param mixed $expected    Exact stored value (compared bytewise) the swap is conditional on.
     * @param mixed $replacement New value; anything but a string fails closed.
     * @return bool True only for the single worker whose swap changed the row.
     */
    public static function replace_if_current($name, $expected, $replacement) {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->options)
            || !is_string($wpdb->options)
            || $wpdb->options === ''
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !is_string($name)
            || $name === ''
            || !is_string($expected)
            || !is_string($replacement)
        ) {
            return false;
        }

        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
            $replacement,
            $name,
            $expected
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set lock mutation uses freshly prepared SQL and must bypass caches.
        $changed = $wpdb->query($sql);
        if ((int) $changed !== 1) {
            return false;
        }
        self::flush_option_cache($name);
        return true;
    }

    private static function delete_if_current($name, $expected) {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->options)
            || !is_string($wpdb->options)
            || $wpdb->options === ''
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !is_string($expected)
        ) {
            return false;
        }

        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
            $name,
            $expected
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set lock mutation uses freshly prepared SQL and must bypass caches.
        $changed = $wpdb->query($sql);
        if ((int) $changed !== 1) {
            return false;
        }
        self::flush_option_cache($name);
        return true;
    }

    /**
     * Drop an option's object-cache entry and its "absent" marker after a raw SQL write.
     *
     * @param string $name Option name.
     * @return void
     */
    public static function flush_option_cache($name) {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
        }
        // A raw insert bypasses add_option(), so drop any cached "option absent"
        // marker or later get_option() calls in this request would still miss it.
        if (function_exists('wp_cache_get') && function_exists('wp_cache_set')) {
            $notoptions = wp_cache_get('notoptions', 'options');
            if (is_array($notoptions) && isset($notoptions[$name])) {
                unset($notoptions[$name]);
                wp_cache_set('notoptions', $notoptions, 'options');
            }
        }
    }

    private static function token() {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return hash('sha256', uniqid('supcheckout-upay-lock-', true) . '|' . microtime(true));
        }
    }

    private function __construct() {}
}
