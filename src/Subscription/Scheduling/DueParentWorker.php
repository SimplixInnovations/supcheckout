<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Narrow due-parent worker. Orchestration only — charge authority stays
 * with CycleClaim + the R3 Scheduler dispatch path.
 *
 * Action args identify one billing cycle. The queued due identity is not
 * payment authority; stale cycles never mutate CycleClaim just because they ran.
 */
final class DueParentWorker
{
    /** Pre-dispatch safe retry delay (seconds). Bounded; never a hot loop. */
    const PRE_DISPATCH_RETRY_DELAY = 3600;

    public static function register(): void
    {
        add_action(ActionSchedulerBridge::ACTION_DUE_PARENT, array(__CLASS__, 'handle'), 10, 2);
    }

    /**
     * @param mixed $parent_order_id  Action arg.
     * @param mixed $cycle_due_gmt    Action arg (orchestration identity only).
     */
    public static function handle($parent_order_id, $cycle_due_gmt = 0): void
    {
        $parent_id = (int) $parent_order_id;
        $queued_due = (int) $cycle_due_gmt;
        if ($parent_id <= 0) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($parent_id) : false;
        if (!$order instanceof \WC_Order) {
            self::log('not_eligible', $parent_id, 'parent_unavailable');
            return;
        }

        if (!HistoricalEnrollment::parent_qualifies($order)) {
            self::log('not_eligible', $parent_id, 'parent_not_qualified');
            ActionSchedulerBridge::cancel_parent_actions($parent_id);
            return;
        }

        // Stale-cycle detection: queued identity must match the current
        // legitimate next due cycle. Obsolete work is never a charge attempt.
        $current_due = HistoricalEnrollment::next_run_at($order);
        if ($current_due === null) {
            self::log('not_due', $parent_id, 'no_legitimate_next_cycle');
            return;
        }
        if ($queued_due > 0 && !self::same_cycle($queued_due, $current_due)) {
            self::log('stale_cycle', $parent_id, 'queued_cycle_mismatch');
            ActionSchedulerBridge::ensure_cycle_action($parent_id, $current_due);
            return;
        }

        if (!class_exists('UPayments\Subscription\Cron\Scheduler', false)) {
            require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php';
        }

        $outcome = \UPayments\Subscription\Cron\Scheduler::process_parent_order($order);

        // Outcome-aware post-processing. Next cycle only after resolved
        // AND fresh parent persistence shows last_billed advanced.
        if ($outcome === \UPayments\Subscription\Cron\Scheduler::OUTCOME_RESOLVED) {
            $fresh = wc_get_order($parent_id);
            if ($fresh instanceof \WC_Order) {
                $next = HistoricalEnrollment::next_run_at($fresh);
                if ($next !== null && $next !== $queued_due) {
                    ActionSchedulerBridge::ensure_cycle_action($parent_id, $next);
                }
            }
            return;
        }

        if ($outcome === \UPayments\Subscription\Cron\Scheduler::OUTCOME_PRE_DISPATCH_NO_ATTEMPT) {
            // Bounded pre-dispatch repair retry. No hot-loop; no payment authority.
            ActionSchedulerBridge::ensure_cycle_action(
                $parent_id,
                time() + self::PRE_DISPATCH_RETRY_DELAY
            );
            self::log('pre_dispatch_retry', $parent_id, 'bounded_backoff');
            return;
        }

        // held / dispatching / ambiguous / not_due / not_eligible:
        // NO automatic replacement charge action.
        self::log('no_next_charge', $parent_id, $outcome);
    }

    /**
     * Same billing cycle within a 120s clock-skew tolerance.
     */
    private static function same_cycle(int $left, int $right): bool
    {
        return abs($left - $right) <= 120;
    }

    private static function log(string $event, int $parent_id, string $reason): void
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
            )
        );
    }
}
