<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * The global takeover lock, which also quiesces the subscription engine (Sub-project 0b
 * Task 7, Cutover; Task 10, Rollback).
 *
 * One self-expiring lease option, OPTION, holding "<expires>:<token>". The lease is taken
 * with OrderLock::insert_if_absent() (or, once expired, a compare-and-swap over the stale
 * record), renewed and released by compare-and-swap, so of two operators exactly one
 * holds it. While a lease is live:
 *
 * - Subscription\Cron\Scheduler::process() skips its hourly tick and
 *   Scheduler::process_parent_order() returns pre_dispatch_no_attempt before anything
 *   else, so the due-parent worker queues its bounded retry instead of claiming (this is
 *   how the `supcheckout` Action Scheduler group is paused: Action Scheduler has no
 *   group pause, so its due-parent actions defer themselves);
 * - CycleClaim::acquire(), acquire_with_snapshot() and reclaim_stale_claimed() refuse to
 *   claim, and re-check after a claim succeeds: a claim that raced the lease is released
 *   again (it is still `claimed`, so no provider POST was sent).
 *
 * The holder then drains in-flight claim holders (Cutover::drain()): it waits until no
 * billing-attempts row is `claimed` or `dispatching` with a recent update.
 *
 * Fail closed: a stored value that is neither absent, released nor a well-formed record
 * counts as a live lease (claims stay paused) and can never be taken over; an operator
 * must remove the option. An expired lease is not live, so a holder that died mid-cutover
 * pauses subscriptions for at most TTL seconds.
 *
 * Every read goes past the object cache (OrderLock::flush_option_cache()), because the
 * lease is written by another process.
 */
final class Quiesce {
    public const OPTION = 'supcheckout_takeover_quiesce_v1';
    /** Seconds a lease lives unless renewed. */
    public const TTL = 900;

    private const RELEASED = '0:released';
    private const RECORD_PATTERN = '/^([0-9]{1,12}):([a-f0-9]{32})$/D';

    /**
     * Take the lease.
     *
     * @param int|null $now Current time; time() when omitted.
     * @return string|null The lease record, or null when another holder has it (or the
     *                     stored value is unreadable).
     */
    public static function acquire($now = null) {
        $now = $now === null ? time() : (int) $now;
        $record = self::record($now);
        if ($record === null) {
            return null;
        }
        if (OrderLock::insert_if_absent(self::OPTION, $record)) {
            return $record;
        }
        $existing = self::read();
        if ($existing !== self::RELEASED && !self::is_expired_record($existing, $now)) {
            return null;
        }
        return OrderLock::replace_if_current(self::OPTION, (string) $existing, $record) ? $record : null;
    }

    /**
     * Extend a lease this caller holds.
     *
     * @param string   $record The current lease record.
     * @param int|null $now    Current time.
     * @return string|null The new record, or null when the lease was lost or expired.
     */
    public static function renew($record, $now = null) {
        $now = $now === null ? time() : (int) $now;
        if (!self::holds($record, $now)) {
            return null;
        }
        $next = self::record($now);
        if ($next === null) {
            return null;
        }
        return OrderLock::replace_if_current(self::OPTION, (string) $record, $next) ? $next : null;
    }

    /**
     * @param string   $record Lease record.
     * @param int|null $now    Current time.
     * @return bool True while $record is the stored, unexpired lease.
     */
    public static function holds($record, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $parsed = self::parse($record);
        return $parsed !== null && $parsed >= $now && self::read() === $record;
    }

    /**
     * Release a lease this caller holds. Releasing a lost lease changes nothing.
     *
     * @param string|null $record Lease record.
     * @return bool
     */
    public static function release($record) {
        if (!is_string($record) || self::parse($record) === null) {
            return false;
        }
        return OrderLock::replace_if_current(self::OPTION, $record, self::RELEASED);
    }

    /**
     * Whether claims and the scheduler must stay paused right now. One uncached option
     * read: absent or released → false; a live record → true; an expired record → false;
     * anything else → true (fail closed).
     *
     * @param int|null $now Current time.
     * @return bool
     * @phpstan-impure
     */
    public static function is_active($now = null) {
        $now = $now === null ? time() : (int) $now;
        $stored = self::read();
        if ($stored === null || $stored === self::RELEASED) {
            return false;
        }
        $expires = self::parse($stored);
        return $expires === null || $expires >= $now;
    }

    /**
     * @return string|null|false The stored string, null when absent, false when not a string.
     */
    private static function read() {
        if (!function_exists('get_option')) {
            return null;
        }
        OrderLock::flush_option_cache(self::OPTION);
        $stored = get_option(self::OPTION, null);
        if ($stored === null || $stored === false) {
            return null;
        }
        return is_string($stored) ? $stored : false;
    }

    /**
     * @param mixed $stored Stored value.
     * @param int   $now    Current time.
     * @return bool True only for a well-formed record whose time has passed.
     */
    private static function is_expired_record($stored, $now) {
        $expires = self::parse($stored);
        return $expires !== null && $expires < $now;
    }

    /**
     * @param mixed $record Candidate.
     * @return int|null Expiry, or null when not a well-formed record.
     */
    private static function parse($record) {
        if (!is_string($record) || preg_match(self::RECORD_PATTERN, $record, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    /**
     * @param int $now Current time.
     * @return string|null
     */
    private static function record($now) {
        try {
            return ($now + self::TTL) . ':' . bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function __construct() {}
}
