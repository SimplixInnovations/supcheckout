<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Payment\ReconciliationSweep;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * Deactivation cleanup (A3-08, verify-P U8). Plugin::boot() and PausedBoot::boot(), never
 * the main file, register deactivate() on the main file by ::class, so this file loads only
 * on deactivation. Non-destructive: it stops SUPCheckout's own background schedules and
 * deletes one scheduling marker; no data, setting, order meta or token is touched, and
 * uninstall is unchanged.
 *
 * Removed:
 * - the hourly reconciliation sweep and its throttle marker (ReconciliationSweep::unschedule()),
 *   so the first admin, WP-Cron or WP-CLI request after a reactivation schedules it again;
 * - the new-name twin of the subscription tick; Subscription\Cron\Scheduler::init() schedules
 *   it again on the first request after a reactivation while subscriptions are enabled;
 * - stale onboarding driver, watchdog and copy-runner actions, only while no takeover work is
 *   in flight (no running or unreadable operation, takeover state not copying or unreadable).
 *   Otherwise they stay, so a quick reactivation resumes the operation (the watchdog and
 *   CopyRunner's watchdog re-queue it either way).
 *
 * Kept on purpose:
 * - the protected upay_process_subscriptions tick, which the official plugin also uses;
 * - per-order reconcile checks under either name: each is a single event that drains once,
 *   a quick reactivation still runs it, and a removed secondary check is never re-armed
 *   (its track stays queued with nothing to run it).
 *
 * Every step is isolated: a failure never stops the others and never blocks the deactivation.
 */
final class Deactivation {
    /** Legacy name of the subscription tick; only its new-name twin is ever removed. */
    private const SUBSCRIPTION_TICK = 'upay_process_subscriptions';

    /**
     * @return void
     */
    public static function deactivate() {
        self::attempt(array(ReconciliationSweep::class, 'unschedule'));
        if (self::takeover_in_flight()) {
            return;
        }
        self::attempt(array(self::class, 'unschedule_subscription_twin'));
        self::attempt(array(self::class, 'unschedule_stale_takeover_actions'));
    }

    /**
     * @return void
     */
    public static function unschedule_subscription_twin() {
        $twins = Keys::cron_hooks();
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook($twins[self::SUBSCRIPTION_TICK]);
        }
    }

    /**
     * @return void
     */
    public static function unschedule_stale_takeover_actions() {
        if (!ActionSchedulerBridge::is_ready() || !function_exists('as_unschedule_all_actions')) {
            return;
        }
        as_unschedule_all_actions(AsyncDriver::HOOK, null, AsyncDriver::GROUP);
        as_unschedule_all_actions(AsyncDriver::WATCHDOG_HOOK, null, AsyncDriver::GROUP);
        as_unschedule_all_actions(CopyRunner::HOOK, array(), CopyRunner::GROUP);
    }

    /**
     * Fails closed: an unreadable state or operation record counts as work in flight.
     *
     * @return bool
     */
    private static function takeover_in_flight() {
        try {
            $status = (string) TakeoverState::get()['status'];
            if ($status === TakeoverState::COPYING || $status === TakeoverState::INVALID) {
                return true;
            }
            $op = Operation::current();
            return $op !== null && (Operation::is_running($op) || $op['outcome'] === Operation::INVALID);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @param callable $step Cleanup step.
     * @return void
     */
    private static function attempt($step) {
        try {
            call_user_func($step);
        } catch (\Throwable $e) {
            // A deactivation hook that throws keeps the plugin active; the step is skipped.
            return;
        }
    }

    private function __construct() {}
}
