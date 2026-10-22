<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\Rollback;

defined('ABSPATH') || exit;

/**
 * The onboarding driver's operator hints and its pure cooldown predicates (Sub-project 0c),
 * kept apart from DriverStep's decision table. Nothing here reads or writes state.
 */
final class DriverHints {
    public const ERROR = 'restart the operation; every step resumes from the saved state';
    public const STATE = 'the takeover state changed meanwhile; check the status, then start again';
    public const BUSY = 'another takeover step holds the store; wait until it ends (at most 15 minutes), then start again';
    public const PREFLIGHT = 'fix the blocking preflight findings, then start again';
    public const OFFICIAL = 'deactivate the official UPayments plugin, or start again with deactivate_official';
    public const MISMATCH = 'review the mismatches; the store was not switched';
    /** A5-04: a kept SUPCheckout option blocks the switch (preflight) or was kept by a rollback. */
    public const SETTINGS_KEPT = Rollback::SETTINGS_DIVERGED_HINT;

    /**
     * @param array<string, mixed> $report Preflight report with a blocking finding.
     * @return string SETTINGS_KEPT when a kept option blocks, otherwise PREFLIGHT.
     */
    public static function preflight(array $report) {
        foreach (isset($report['blocking']) && is_array($report['blocking']) ? $report['blocking'] : array() as $finding) {
            if (is_array($finding) && isset($finding['code']) && $finding['code'] === 'settings_target_foreign') {
                return self::SETTINGS_KEPT;
            }
        }
        return self::PREFLIGHT;
    }

    /**
     * @param string $kind  Operation kind.
     * @param bool   $force The finalize was started with force_after_review.
     * @return string The WP-CLI command that finishes an operation out of long attempts.
     */
    public static function exhausted($kind, $force) {
        switch ($kind) {
            case Operation::KIND_ROLLBACK:
                return 'run "wp supcheckout takeover rollback" on the server';
            case Operation::KIND_ABORT:
                return 'run "wp supcheckout takeover abort" on the server';
            case Operation::KIND_FINALIZE:
                return 'run "wp supcheckout takeover finalize' . ($force ? ' --force-after-review' : '') . '" on the server';
            case Operation::KIND_FINALIZE_DRY_RUN:
                return 'run "wp supcheckout takeover finalize --dry-run" on the server';
        }
        return 'run "wp supcheckout takeover run --deactivate-official" on the server';
    }

    /**
     * A5-05: the copy stopped at an order its payment lock held through every busy retry.
     * A payment holds that lock for at most 45 seconds, so the record is most likely
     * unparseable (OrderLock then fails closed and never breaks it); the takeover never
     * deletes a payment lock itself.
     *
     * @param int $order_id The locked order.
     * @return string
     */
    public static function order_locked($order_id) {
        $order_id = (int) $order_id;
        return 'order ' . $order_id . ' stayed locked through every retry (a payment holds its lock for at most 45 seconds), so its lock record is probably damaged; check that no payment for order ' . $order_id . ' is in progress, delete the lock options whose names end in "order_lock_v1_' . $order_id . '" (wp option list --search="*order_lock_v1_' . $order_id . '"), then start again';
    }

    /**
     * @param string $kind Operation kind.
     * @return string
     */
    public static function stale_claims($kind) {
        return 'release stale claims (POST /onboarding/stale-claims/release), then start the ' . (string) $kind . ' again';
    }

    /**
     * @param array<string, mixed> $report Preflight report.
     * @return bool Every blocking finding is the provider cooldown (and there is one).
     */
    public static function only_cooldown(array $report) {
        $blocking = isset($report['blocking']) && is_array($report['blocking']) ? $report['blocking'] : array();
        foreach ($blocking as $finding) {
            if (!self::is_cooldown_finding($finding)) {
                return false;
            }
        }
        return $blocking !== array();
    }

    /**
     * @param array<string, mixed> $report Preflight report.
     * @return bool Some blocking finding is the provider cooldown.
     */
    public static function has_cooldown(array $report) {
        foreach (isset($report['blocking']) && is_array($report['blocking']) ? $report['blocking'] : array() as $finding) {
            if (self::is_cooldown_finding($finding)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $report SelfTest / SetupChecks report.
     * @return bool The credential check is the provider cooldown warning.
     */
    public static function check_cooldown(array $report) {
        foreach (isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : array() as $check) {
            if (is_array($check) && isset($check['id'], $check['result'], $check['code'])
                && $check['id'] === 'credential' && $check['result'] === Checks::WARN && $check['code'] === 'cooldown'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $finding Preflight finding.
     * @return bool
     */
    private static function is_cooldown_finding($finding) {
        return is_array($finding) && isset($finding['code'], $finding['data']['reason'])
            && $finding['code'] === 'credential_unverified' && $finding['data']['reason'] === 'cooldown';
    }

    private function __construct() {}
}
