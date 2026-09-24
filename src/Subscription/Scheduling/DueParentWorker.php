<?php

namespace UPayments\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Narrow due-parent worker. Orchestration only — charge authority stays
 * with CycleClaim + the R3 Scheduler dispatch path.
 */
final class DueParentWorker
{
    public static function register(): void
    {
        add_action(ActionSchedulerBridge::ACTION_DUE_PARENT, array(__CLASS__, 'handle'), 10, 1);
    }

    /**
     * @param mixed $parent_order_id Action arg (int parent id only).
     */
    public static function handle($parent_order_id): void
    {
        $parent_id = (int) $parent_order_id;
        if ($parent_id <= 0) {
            return;
        }

        // Stale queued actions are not authority. R3 final revalidation and
        // CycleClaim still decide whether a provider POST may occur.
        $order = function_exists('wc_get_order') ? wc_get_order($parent_id) : false;
        if (!$order instanceof \WC_Order) {
            self::log('not_eligible', $parent_id, 'parent_unavailable');
            return;
        }

        if (!HistoricalEnrollment::parent_qualifies($order)) {
            self::log('not_eligible', $parent_id, 'parent_not_qualified');
            return;
        }

        if (!class_exists('UPayments\Subscription\Cron\Scheduler', false)) {
            require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php';
        }

        // Reuse the R3 claim/dispatch path. Do not duplicate payment authority.
        \UPayments\Subscription\Cron\Scheduler::process_parent_order($order);

        // Recompute next legitimate cycle after this attempt. Missed periods
        // do not back-fill as N charges.
        $next = HistoricalEnrollment::next_run_at($order);
        if ($next !== null) {
            ActionSchedulerBridge::ensure_parent_action($parent_id, $next);
        }
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
