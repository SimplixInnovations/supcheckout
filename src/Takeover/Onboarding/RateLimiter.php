<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * Per-site rate buckets for the onboarding write routes (Sub-project 0c).
 *
 * One non-autoloaded option per bucket, `supcheckout_onboarding_rate_<bucket>`, holding the
 * decimal timestamp until which the bucket is closed. The bucket is created with
 * OrderLock::insert_if_absent() and advanced with OrderLock::replace_if_current(), so of
 * two requests racing the same bucket exactly one is allowed. An unreadable value fails
 * closed with the full window.
 *
 * Buckets: provider_probe (shared by preflight, self-test and setup checks), switch,
 * rollback, abort, finalize, stale_claims.
 */
final class RateLimiter {
    public const OPTION_PREFIX = 'supcheckout_onboarding_rate_';

    public const WRITE_WINDOW = 10;
    /** Equal to PaymentMethodAvailability::RATE_GATE_COOLDOWN (proved by RateLimiterTest). */
    public const PROBE_WINDOW = 65;

    /**
     * @param mixed  $bucket Bucket name (lowercase letters and underscores); anything else fails closed.
     * @param int    $window Seconds the bucket stays closed after an allowed hit.
     * @param int    $now    Current unix time.
     * @return int 0 when the hit is allowed, else the seconds to wait.
     */
    public static function hit($bucket, $window, $now) {
        $window = max(1, (int) $window);
        if (!is_string($bucket) || preg_match('/^[a-z_]{1,64}$/', $bucket) !== 1) {
            return $window;
        }

        $name = self::OPTION_PREFIX . $bucket;
        $closes = (string) ($now + $window);
        $raw = self::read($name);

        if ($raw === null) {
            if (OrderLock::insert_if_absent($name, $closes)) {
                return 0;
            }
            return self::lost_race($name, $now, $window);
        }
        $until = self::until($raw);
        if ($until === null) {
            return $window;
        }
        if ($now < $until) {
            return $until - $now;
        }
        if (OrderLock::replace_if_current($name, $raw, $closes)) {
            return 0;
        }
        return self::lost_race($name, $now, $window);
    }

    /**
     * Reopen a bucket this request closed (0c Task 7: a start whose action could not be
     * scheduled gives its slot back, so an immediate retry is not blocked). A
     * compare-and-swap against the exact closing time hit() stored, so a slot another
     * request closed is never reopened.
     *
     * @param mixed $bucket Bucket name.
     * @param int   $until  The closing time the allowed hit() stored: its $now + $window.
     * @return bool True when this call reopened the bucket.
     */
    public static function release($bucket, $until) {
        if (!is_string($bucket) || preg_match('/^[a-z_]{1,64}$/', $bucket) !== 1) {
            return false;
        }
        $name = self::OPTION_PREFIX . $bucket;
        $raw = self::read($name);
        if (!is_string($raw) || $raw !== (string) (int) $until) {
            return false;
        }
        return OrderLock::replace_if_current($name, $raw, '0');
    }

    /**
     * Another request won the bucket between our read and our write.
     *
     * @param string $name   Option name.
     * @param int    $now    Current time.
     * @param int    $window Window.
     * @return int Seconds to wait.
     */
    private static function lost_race($name, $now, $window) {
        $until = self::until(self::read($name));
        return $until !== null && $until > $now ? $until - $now : $window;
    }

    /**
     * @param string|null|false $raw Stored value.
     * @return int|null Closing time, null when absent or unreadable.
     */
    private static function until($raw) {
        return is_string($raw) && preg_match('/^[0-9]{1,15}$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * @param string $name Option name.
     * @return string|null|false The stored string, null when absent, false when unreadable.
     */
    private static function read($name) {
        if (!function_exists('get_option')) {
            return false;
        }
        OrderLock::flush_option_cache($name);
        $raw = get_option($name, null);
        if ($raw === null || $raw === false) {
            return null;
        }
        return is_string($raw) ? $raw : false;
    }

    private function __construct() {}
}
