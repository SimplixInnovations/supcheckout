<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Narrow due-parent worker. Orchestration only — charge authority stays
 * with CycleClaim + the R3 Scheduler dispatch path.
 *
 * Callback args: parent_order_id, cycle_due_gmt, retry_attempt.
 * Stale-cycle comparison uses ONLY cycle_due_gmt (never run_at / retry).
 */
final class DueParentWorker
{
    public static function register(): void
    {
        add_action(ActionSchedulerBridge::ACTION_DUE_PARENT, array(__CLASS__, 'handle'), 10, 3);
    }

    /**
     * @param mixed $parent_order_id
     * @param mixed $cycle_due_gmt   Billing-cycle identity (never rewrite for retry).
     * @param mixed $retry_attempt   Queue retry ordinal (not payment identity).
     */
    public static function handle($parent_order_id, $cycle_due_gmt = 0, $retry_attempt = 0): void
    {
        $parent_id = (int) $parent_order_id;
        $queued_due = (int) $cycle_due_gmt;
        $attempt = max(0, (int) $retry_attempt);
        if ($parent_id <= 0) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($parent_id) : false;
        if (!$order instanceof \WC_Order) {
            self::log('not_eligible', $parent_id, 'parent_unavailable', $queued_due, $attempt);
            return;
        }

        if (!HistoricalEnrollment::parent_qualifies($order)) {
            self::log('not_eligible', $parent_id, 'parent_not_qualified', $queued_due, $attempt);
            ActionSchedulerBridge::cancel_cycle_actions($parent_id, $queued_due);
            return;
        }

        $current_due = HistoricalEnrollment::next_run_at($order);
        if ($current_due === null) {
            self::log('not_due', $parent_id, 'no_legitimate_next_cycle', $queued_due, $attempt);
            return;
        }

        // Stale-cycle detection compares billing-cycle identity only.
        if ($queued_due > 0 && !self::same_cycle($queued_due, $current_due)) {
            self::log('stale_cycle', $parent_id, 'queued_cycle_mismatch', $queued_due, $attempt);
            if (!ActionSchedulerBridge::has_any_open_cycle_attempt($parent_id, $current_due)) {
                ActionSchedulerBridge::ensure_cycle_action($parent_id, $current_due, null, 0);
            }
            return;
        }

        if (!class_exists('UPayments\Subscription\Cron\Scheduler', false)) {
            require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php';
        }

        $outcome = \UPayments\Subscription\Cron\Scheduler::process_parent_order($order);

        if ($outcome === \UPayments\Subscription\Cron\Scheduler::OUTCOME_RESOLVED) {
            $fresh = wc_get_order($parent_id);
            if ($fresh instanceof \WC_Order) {
                $next = HistoricalEnrollment::next_run_at($fresh);
                if ($next !== null && !self::same_cycle($next, $queued_due)) {
                    ActionSchedulerBridge::ensure_cycle_action($parent_id, $next, null, 0);
                }
            }
            return;
        }

        if ($outcome === \UPayments\Subscription\Cron\Scheduler::OUTCOME_PRE_DISPATCH_NO_ATTEMPT) {
            self::schedule_bounded_retry($parent_id, $queued_due, $attempt);
            return;
        }

        // held / dispatching / ambiguous / not_due / not_eligible:
        // NO automatic replacement charge / retry action.
        self::log('no_next_charge', $parent_id, $outcome, $queued_due, $attempt);
    }

    /**
     * Finite pre-dispatch retry. Same cycle_due_gmt; only run_at + ordinal move.
     */
    private static function schedule_bounded_retry(int $parent_id, int $cycle_due, int $attempt): void
    {
        $next_attempt = $attempt + 1;
        if ($next_attempt > ActionSchedulerBridge::MAX_RETRY_ATTEMPT) {
            self::log('retry_exhausted', $parent_id, 'max_attempts_reached', $cycle_due, $attempt);
            return;
        }

        $delay = ActionSchedulerBridge::retry_delay_for_attempt($next_attempt);
        if ($delay === null) {
            self::log('retry_exhausted', $parent_id, 'no_delay_for_attempt', $cycle_due, $attempt);
            return;
        }

        ActionSchedulerBridge::ensure_cycle_action(
            $parent_id,
            $cycle_due,
            time() + $delay,
            $next_attempt
        );
        self::log('pre_dispatch_retry', $parent_id, 'attempt_' . $next_attempt, $cycle_due, $next_attempt);
    }

    /**
     * Same billing cycle within a 120s clock-skew tolerance.
     * Never compares run_at or retry ordinal.
     */
    private static function same_cycle(int $left, int $right): bool
    {
        return abs($left - $right) <= 120;
    }

    private static function log(string $event, int $parent_id, string $reason, int $cycle_due = 0, int $attempt = 0): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->info(
            'R4 due-parent worker.',
            array(
                'source' => 'upayments-cron',
                'event'  => $event,
                'order_id' => $parent_id,
                'reason' => $reason,
                'cycle_due_gmt' => $cycle_due,
                'retry_attempt' => $attempt,
            )
        );
    }
}
