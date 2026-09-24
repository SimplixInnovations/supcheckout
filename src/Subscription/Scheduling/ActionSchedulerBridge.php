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
     * True iff Action Scheduler APIs exist AND its datastore is initialized.
     * Function existence alone is not readiness.
     */
    public static function is_ready(): bool
    {
        if (!function_exists('as_schedule_single_action')
            || !function_exists('as_has_scheduled_action')
            || !function_exists('as_unschedule_action')
        ) {
            return false;
        }

        if (class_exists('\\Action_Scheduler')
            && method_exists('\\Action_Scheduler', 'is_initialized')
            && \Action_Scheduler::is_initialized()
        ) {
            return true;
        }

        // Deliberate hook fallback for bundled versions where the datastore
        // becomes ready on action_scheduler_init.
        if (function_exists('did_action') && did_action('action_scheduler_init') > 0) {
            return true;
        }

        return false;
    }

    /**
     * Cycle-scoped action args. Orchestration identity only — never secrets.
     *
     * @return array{parent_order_id:int,cycle_due_gmt:int}
     */
    public static function cycle_args(int $parent_order_id, int $cycle_due_gmt): array
    {
        return array(
            'parent_order_id' => $parent_order_id,
            'cycle_due_gmt'   => $cycle_due_gmt,
        );
    }

    /**
     * Schedule one exact billing-cycle action. Unique + recheck defeats the
     * check-then-act race. Queue uniqueness is not payment authority.
     */
    public static function ensure_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
    {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0 || !self::is_ready()) {
            return false;
        }

        $args = self::cycle_args($parent_order_id, $cycle_due_gmt);

        // 1. Exact matching action already pending/in-progress → idempotent.
        if (self::has_open_cycle_action($parent_order_id, $cycle_due_gmt)) {
            return true;
        }

        // 2. unique=true so concurrent callers cannot both insert.
        $scheduled = as_schedule_single_action(
            max($cycle_due_gmt, time() - 60),
            self::ACTION_DUE_PARENT,
            $args,
            self::GROUP,
            true
        );

        if ($scheduled > 0) {
            return true;
        }

        // 3. No new ID: another caller may have won the unique insert.
        return self::has_open_cycle_action($parent_order_id, $cycle_due_gmt);
    }

    /**
     * @deprecated Use ensure_cycle_action(); kept name for older call sites.
     */
    public static function ensure_parent_action(int $parent_order_id, int $run_at_gmt): bool
    {
        return self::ensure_cycle_action($parent_order_id, $run_at_gmt);
    }

    /**
     * True iff this exact cycle action is pending or in-progress.
     * Distinct cycles for the same parent are independent identities.
     */
    public static function has_open_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
    {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0 || !function_exists('as_has_scheduled_action')) {
            return false;
        }

        return (bool) as_has_scheduled_action(
            self::ACTION_DUE_PARENT,
            self::cycle_args($parent_order_id, $cycle_due_gmt),
            self::GROUP
        );
    }

    public static function has_open_parent_action(int $parent_order_id): bool
    {
        // Any open cycle action for this parent (used only for diagnostics).
        if ($parent_order_id <= 0 || !function_exists('as_has_scheduled_action')) {
            return false;
        }

        return (bool) as_has_scheduled_action(
            self::ACTION_DUE_PARENT,
            null,
            self::GROUP
        );
    }

    /**
     * Cancel one exact cycle action. Returns true when no matching action remains.
     */
    public static function cancel_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
    {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0 || !function_exists('as_unschedule_action')) {
            return false;
        }

        as_unschedule_action(
            self::ACTION_DUE_PARENT,
            self::cycle_args($parent_order_id, $cycle_due_gmt),
            self::GROUP
        );

        return !self::has_open_cycle_action($parent_order_id, $cycle_due_gmt);
    }

    /**
     * Best-effort cancel of all SUPCheckout pending actions for one parent.
     * Touches only this plugin's hook + group. Payment safety never depends
     * on this succeeding — stale actions still fail closed in the worker.
     */
    public static function cancel_parent_actions(int $parent_order_id): bool
    {
        if ($parent_order_id <= 0 || !function_exists('as_unschedule_all_actions')) {
            return false;
        }

        as_unschedule_all_actions(
            self::ACTION_DUE_PARENT,
            null,
            self::GROUP
        );

        return true;
    }

    private function __construct()
    {
    }
}
