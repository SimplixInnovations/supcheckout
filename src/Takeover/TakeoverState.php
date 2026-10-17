<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * Persisted takeover state machine (Sub-project 0b).
 *
 * idle → preflight_ok → copying → verified → cut_over → finalized, with
 * copying → preflight_ok (verification failed), verified → preflight_ok (abort, Task 10),
 * cut_over → rolled_back and rolled_back → preflight_ok. Nothing else is allowed.
 *
 * cut_over records `cut_over_at` and rolled_back records `rolled_back_at` (the start of
 * StaleGatewayChoice's mirror grace); both stay in the record as history.
 *
 * Storage: one non-autoloaded option holding a JSON object. The first record is
 * created with OrderLock::insert_if_absent() and every later change is an SQL
 * compare-and-swap against the exact stored string (OrderLock::replace_if_current()),
 * so of two admins racing a transition exactly one wins and the other gets false.
 * Neither add_option() nor update_option() is used: both read first and then write
 * unconditionally.
 *
 * is_cut_over() is called by Keys::write_key() on every write, so its answer is
 * cached for the request. A successful transition resets the cache in this process;
 * other long-running processes (a queue runner, WP-CLI) keep their answer until
 * flush_cache() or their next request.
 *
 * An unreadable stored record reads as status 'invalid': not cut over, no
 * transition, no patch, and the record is never overwritten.
 */
final class TakeoverState {
    public const OPTION = 'supcheckout_takeover_state_v1';

    public const IDLE = 'idle';
    public const PREFLIGHT_OK = 'preflight_ok';
    public const COPYING = 'copying';
    public const VERIFIED = 'verified';
    public const CUT_OVER = 'cut_over';
    public const FINALIZED = 'finalized';
    public const ROLLED_BACK = 'rolled_back';
    /** Reported by get() for an unreadable stored record; never stored. */
    public const INVALID = 'invalid';

    /** Finalize waits this many days after cut_over unless forced. */
    public const FINALIZE_AFTER_DAYS = 30;

    /** @var array<string, list<string>> */
    private const ALLOWED = array(
        self::IDLE => array(self::PREFLIGHT_OK),
        self::PREFLIGHT_OK => array(self::COPYING),
        self::COPYING => array(self::VERIFIED, self::PREFLIGHT_OK),
        self::VERIFIED => array(self::CUT_OVER, self::PREFLIGHT_OK),
        self::CUT_OVER => array(self::ROLLED_BACK, self::FINALIZED),
        self::FINALIZED => array(),
        self::ROLLED_BACK => array(self::PREFLIGHT_OK),
    );

    /** Fields only transition() may write. */
    private const LIFECYCLE_FIELDS = array('status', 'cut_over_at', 'rolled_back_at', 'updated_at', 'finalize_forced');

    private const SECONDS_PER_DAY = 86400;

    /** @var bool|null */
    private static $cut_over = null;

    /**
     * Current state. Progress fields written with patch() (for example `cursors` or
     * `mismatches`) are returned alongside the documented ones.
     *
     * @return array{status:string, cut_over_at:int, cursor:int, totals:array<mixed>}&array<string,mixed>
     */
    public static function get() {
        $state = self::decode(self::read_raw());
        return $state === null ? self::defaults(self::INVALID) : $state;
    }

    /**
     * @return bool True once the store has switched to the SUPCheckout identities
     *              (cut_over or finalized). One cached read per request.
     */
    public static function is_cut_over() {
        if (self::$cut_over === null) {
            if (!function_exists('get_option')) {
                return false;
            }
            $status = self::get()['status'];
            self::$cut_over = ($status === self::CUT_OVER || $status === self::FINALIZED);
        }
        return self::$cut_over;
    }

    /**
     * Move to $to if the documented map allows it from the current status.
     *
     * @param mixed $to    Target status.
     * @param bool  $force Only for cut_over → finalized before 30 days: the CLI sets it
     *                     with --force-after-review. It unlocks nothing else.
     * @return bool True only when this call persisted the transition.
     */
    public static function transition($to, $force = false) {
        if (!is_string($to) || !isset(self::ALLOWED[$to])) {
            return false;
        }

        // Transitions are rare; read past any cached copy so the swap compares
        // against the stored record.
        OrderLock::flush_option_cache(self::OPTION);
        $raw = self::read_raw();
        $current = self::decode($raw);
        if ($current === null || !in_array($to, self::ALLOWED[$current['status']], true)) {
            return false;
        }

        $now = time();
        $finalize_due = $current['cut_over_at'] > 0
            && $now - $current['cut_over_at'] >= self::FINALIZE_AFTER_DAYS * self::day_in_seconds();
        if ($to === self::FINALIZED && !$finalize_due && $force !== true) {
            return false;
        }

        $next = $current;
        $next['status'] = $to;
        $next['updated_at'] = $now;
        if ($to === self::CUT_OVER) {
            $next['cut_over_at'] = $now;
        }
        if ($to === self::ROLLED_BACK) {
            $next['rolled_back_at'] = $now;
        }
        if ($to === self::FINALIZED) {
            $next['finalize_forced'] = !$finalize_due;
        }

        if (!self::write($raw, $next)) {
            return false;
        }
        self::flush_cache();
        return true;
    }

    /**
     * Merge progress fields (cursor, cursors, totals, mismatches, max_copied_id …)
     * into the state with a compare-and-swap. Lifecycle fields are refused.
     *
     * @param array<array-key, mixed> $fields          Non-empty, string keys only, JSON-encodable.
     * @param string|null             $expected_status When given, the patch applies only while
     *                                                 the state still has this status.
     * @return bool True when the fields are stored (an unchanged record counts).
     */
    public static function patch(array $fields, $expected_status = null) {
        if ($fields === array()) {
            return false;
        }
        foreach (array_keys($fields) as $key) {
            if (!is_string($key) || in_array($key, self::LIFECYCLE_FIELDS, true)) {
                return false;
            }
        }

        OrderLock::flush_option_cache(self::OPTION);
        $raw = self::read_raw();
        $current = self::decode($raw);
        if ($current === null || ($expected_status !== null && $current['status'] !== $expected_status)) {
            return false;
        }

        $next = array_merge($current, $fields);
        if ($raw !== null && $next === $current) {
            return true;
        }
        return self::write($raw, $next);
    }

    /**
     * Forget the cached is_cut_over() answer (after a transition, and in tests).
     *
     * @return void
     */
    public static function flush_cache() {
        self::$cut_over = null;
    }

    /**
     * Forget every cached copy of the state in this process: the is_cut_over() answer and
     * the option's object-cache entry. Long-running workers (a queue runner working many
     * actions, WP-CLI) call it before a decision that depends on the takeover state (a
     * billing-cycle claim, a due-parent action), so a transition another process made
     * since this one started is seen.
     *
     * @return void
     */
    public static function refresh() {
        self::flush_cache();
        OrderLock::flush_option_cache(self::OPTION);
    }

    /**
     * @param string|null          $raw  Stored string, or null when there is no record yet.
     * @param array<string, mixed> $next New state.
     * @return bool
     */
    private static function write($raw, array $next) {
        $encoded = wp_json_encode($next);
        if (!is_string($encoded)) {
            return false;
        }
        if ($raw === null) {
            return OrderLock::insert_if_absent(self::OPTION, $encoded);
        }
        if ($encoded === $raw) {
            return true;
        }
        return OrderLock::replace_if_current(self::OPTION, $raw, $encoded);
    }

    /**
     * @return string|null|false The stored string, null when absent, false when unreadable.
     */
    private static function read_raw() {
        if (!function_exists('get_option')) {
            return null;
        }
        $raw = get_option(self::OPTION, null);
        if ($raw === null || $raw === false) {
            return null;
        }
        return is_string($raw) ? $raw : false;
    }

    /**
     * @param string|null|false $raw Stored value from read_raw().
     * @return (array{status:string, cut_over_at:int, cursor:int, totals:array<mixed>}&array<string,mixed>)|null
     *         Null for an unreadable record.
     */
    private static function decode($raw) {
        if ($raw === null) {
            return self::defaults(self::IDLE);
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !isset($decoded['status'])
            || !is_string($decoded['status'])
            || !isset(self::ALLOWED[$decoded['status']])
        ) {
            return null;
        }

        $state = array_merge(self::defaults($decoded['status']), $decoded);
        $state['cut_over_at'] = is_int($state['cut_over_at']) ? $state['cut_over_at'] : 0;
        $state['cursor'] = is_int($state['cursor']) ? $state['cursor'] : 0;
        $state['totals'] = is_array($state['totals']) ? $state['totals'] : array();
        return $state;
    }

    /**
     * @param string $status Status.
     * @return array{status:string, cut_over_at:int, cursor:int, totals:array<mixed>}
     */
    private static function defaults($status) {
        return array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array());
    }

    /**
     * @return int
     */
    private static function day_in_seconds() {
        return defined('DAY_IN_SECONDS') ? (int) constant('DAY_IN_SECONDS') : self::SECONDS_PER_DAY;
    }

    private function __construct() {}
}
