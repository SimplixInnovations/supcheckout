<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Thin capability wrapper around WooCommerce-bundled Action Scheduler.
 *
 * Action Scheduler is orchestration only. CycleClaim remains the
 * provider-mutation / charge-idempotency authority. Never pass secrets
 * through action arguments.
 */
final class ActionSchedulerBridge
{
    public const GROUP = 'supcheckout';
    public const ACTION_DUE_PARENT = 'supcheckout_process_due_parent';

    /**
     * True iff a usable Action Scheduler scheduling API is present.
     */
    public static function is_ready(): bool
    {
        return function_exists('as_schedule_single_action')
            && function_exists('as_has_scheduled_action')
            && function_exists('as_unschedule_action');
    }

    /**
     * Schedule (or keep) one due-parent action. Idempotent per parent.
     *
     * Args carry only the parent order id. Sensitive state is resolved
     * inside the worker at execution time.
     */
    public static function ensure_parent_action(int $parent_order_id, int $run_at_gmt): bool
    {
        if ($parent_order_id <= 0 || !self::is_ready()) {
            return false;
        }

        if (self::has_open_parent_action($parent_order_id)) {
            return true;
        }

        $scheduled = as_schedule_single_action(
            max($run_at_gmt, time() - 60),
            self::ACTION_DUE_PARENT,
            array('parent_order_id' => $parent_order_id),
            self::GROUP
        );

        return $scheduled > 0;
    }

    /**
     * True iff an open (pending/running) due-parent action already exists.
     */
    public static function has_open_parent_action(int $parent_order_id): bool
    {
        if ($parent_order_id <= 0 || !function_exists('as_has_scheduled_action')) {
            return false;
        }

        return (bool) as_has_scheduled_action(
            self::ACTION_DUE_PARENT,
            array('parent_order_id' => $parent_order_id),
            self::GROUP
        );
    }

    /**
     * Cancel queued due-parent actions for a parent (pause/cancel/refund).
     */
    public static function cancel_parent_actions(int $parent_order_id): int
    {
        if ($parent_order_id <= 0 || !function_exists('as_unschedule_action')) {
            return 0;
        }

        $count = as_unschedule_action(
            self::ACTION_DUE_PARENT,
            array('parent_order_id' => $parent_order_id),
            self::GROUP
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function __construct()
    {
    }
}
