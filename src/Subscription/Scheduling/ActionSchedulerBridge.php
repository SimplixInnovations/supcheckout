<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Thin capability wrapper around WooCommerce-bundled Action Scheduler.
 *
 * Action Scheduler is orchestration only. CycleClaim remains the
 * provider-mutation / charge-idempotency authority.
 *
 * Action args (all non-secret):
 *   parent_order_id — local parent identity
 *   cycle_due_gmt   — immutable billing-cycle orchestration identity
 *   retry_attempt   — finite queue retry ordinal (not payment identity)
 *
 * Execution timestamp (run_at_gmt) is separate from cycle_due_gmt so a
 * delayed retry of the SAME billing cycle remains possible.
 */
final class ActionSchedulerBridge
{
    public const GROUP = 'supcheckout';
    public const ACTION_DUE_PARENT = 'supcheckout_process_due_parent';

    /** Maximum automatic pre-dispatch retry ordinal (attempts 0..3). */
    const MAX_RETRY_ATTEMPT = 3;

    /** Retry delay seconds by retry_attempt (attempt N schedules attempt N+1). */
    const RETRY_DELAYS = array(
        1 => 3600,   // +1 hour
        2 => 21600,  // +6 hours
        3 => 86400,  // +24 hours
    );

    /**
     * True iff Action Scheduler APIs exist AND its datastore is initialized.
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

        if (function_exists('did_action') && did_action('action_scheduler_init') > 0) {
            return true;
        }

        return false;
    }

    /**
     * Exact action args for one billing cycle + retry ordinal. Never secrets.
     *
     * @return array{parent_order_id:int,cycle_due_gmt:int,retry_attempt:int}
     */
    public static function cycle_args(int $parent_order_id, int $cycle_due_gmt, int $retry_attempt = 0): array
    {
        return array(
            'parent_order_id' => $parent_order_id,
            'cycle_due_gmt'   => $cycle_due_gmt,
            'retry_attempt'   => max(0, $retry_attempt),
        );
    }

    /**
     * Schedule one exact (parent, cycle, retry) action.
     *
     * @param int|null $run_at_gmt When AS should execute; defaults to cycle due.
     */
    public static function ensure_cycle_action(
        int $parent_order_id,
        int $cycle_due_gmt,
        ?int $run_at_gmt = null,
        int $retry_attempt = 0
    ): bool {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0 || !self::is_ready()) {
            return false;
        }

        $args = self::cycle_args($parent_order_id, $cycle_due_gmt, $retry_attempt);
        $run_at = $run_at_gmt === null ? $cycle_due_gmt : $run_at_gmt;

        if (self::has_open_action_with_args($args)) {
            return true;
        }

        $scheduled = as_schedule_single_action(
            max($run_at, time() - 60),
            self::ACTION_DUE_PARENT,
            $args,
            self::GROUP,
            true
        );

        if ($scheduled > 0) {
            return true;
        }

        return self::has_open_action_with_args($args);
    }

    /**
     * True iff this exact (parent, cycle, retry) action is pending/in-progress.
     */
    public static function has_open_action_with_args(array $args): bool
    {
        if (!function_exists('as_has_scheduled_action')) {
            return false;
        }
        return (bool) as_has_scheduled_action(
            self::ACTION_DUE_PARENT,
            $args,
            self::GROUP
        );
    }

    public static function has_open_cycle_action(int $parent_order_id, int $cycle_due_gmt, int $retry_attempt = 0): bool
    {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0) {
            return false;
        }
        return self::has_open_action_with_args(
            self::cycle_args($parent_order_id, $cycle_due_gmt, $retry_attempt)
        );
    }

    /**
     * True iff any finite attempt for this parent+cycle is open.
     * Bounded exact checks — never null-args / group-wide.
     */
    public static function has_any_open_cycle_attempt(int $parent_order_id, int $cycle_due_gmt): bool
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRY_ATTEMPT; $attempt++) {
            if (self::has_open_cycle_action($parent_order_id, $cycle_due_gmt, $attempt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cancel only SUPCheckout actions for this parent + billing cycle,
     * including finite retry attempts. Never uses null args / group-wide cancel.
     */
    public static function cancel_cycle_actions(int $parent_order_id, int $cycle_due_gmt): bool
    {
        if ($parent_order_id <= 0 || $cycle_due_gmt <= 0 || !function_exists('as_unschedule_all_actions')) {
            return false;
        }

        for ($attempt = 0; $attempt <= self::MAX_RETRY_ATTEMPT; $attempt++) {
            as_unschedule_all_actions(
                self::ACTION_DUE_PARENT,
                self::cycle_args($parent_order_id, $cycle_due_gmt, $attempt),
                self::GROUP
            );
        }

        return !self::has_any_open_cycle_attempt($parent_order_id, $cycle_due_gmt);
    }

    /**
     * Cancel known cycle actions for this parent across attempts 0..MAX.
     * Requires known cycle identities — never a group-wide cancel.
     *
     * @param int[] $cycle_due_list
     */
    public static function cancel_parent_cycle_actions(int $parent_order_id, array $cycle_due_list): bool
    {
        $ok = true;
        foreach ($cycle_due_list as $due) {
            $due = (int) $due;
            if ($due <= 0) {
                continue;
            }
            if (!self::cancel_cycle_actions($parent_order_id, $due)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Bounded pre-dispatch retry delay for the next attempt ordinal.
     */
    public static function retry_delay_for_attempt(int $retry_attempt): ?int
    {
        return self::RETRY_DELAYS[$retry_attempt] ?? null;
    }

    private function __construct()
    {
    }
}
