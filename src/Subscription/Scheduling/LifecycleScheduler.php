<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Explicit customer lifecycle scheduling for the plugin-owned transition handler.
 *
 * Primary authority is the UPayments unsubscribe/pause/resume handler.
 * Meta hooks remain only a best-effort safety net and are not required for
 * correctness under HPOS.
 */
final class LifecycleScheduler
{
    public static function register(): void
    {
        add_action('woocommerce_payment_complete', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);

        // Best-effort net only. Primary pause/cancel/resume wiring lives in
        // the UPayments customer-state transition handler.
        add_action('updated_post_meta', array(__CLASS__, 'maybe_cancel_on_status_meta'), 10, 4);
        add_action('added_post_meta', array(__CLASS__, 'maybe_cancel_on_status_meta'), 10, 4);
    }

    /**
     * Schedule the first due action after an eligible paid subscription parent.
     *
     * @param mixed $order_id Order id or WC_Order.
     */
    public static function maybe_schedule_initial($order_id = 0): void
    {
        $id = $order_id instanceof \WC_Order ? (int) $order_id->get_id() : (int) $order_id;
        if ($id <= 0 || !function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        self::schedule_resume($order);
    }

    /**
     * Pause/cancel: best-effort exact-cycle cancellation. Stale actions still
     * fail closed in the worker. Never group-wide / null-args cancellation.
     */
    public static function cancel_after_state_change(\WC_Order $order): void
    {
        $parent_id = (int) $order->get_id();
        if ($parent_id <= 0) {
            return;
        }
        $due = HistoricalEnrollment::next_run_at($order);
        if ($due !== null) {
            ActionSchedulerBridge::cancel_cycle_actions($parent_id, $due);
        }
    }

    /**
     * Resume: schedule exactly one legitimate next cycle (no back-charge).
     */
    public static function schedule_resume(\WC_Order $order): bool
    {
        if (!HistoricalEnrollment::parent_qualifies($order)) {
            return false;
        }
        $due = HistoricalEnrollment::next_run_at($order);
        if ($due === null) {
            return false;
        }
        // Do not compete with a pending retry for the same cycle.
        if (ActionSchedulerBridge::has_any_open_cycle_attempt((int) $order->get_id(), $due)) {
            return true;
        }
        return ActionSchedulerBridge::ensure_cycle_action((int) $order->get_id(), $due, null, 0);
    }

    /**
     * Best-effort net for legacy/external meta writes (not primary authority).
     */
    public static function maybe_cancel_on_status_meta($meta_id, $object_id, $meta_key, $meta_value): void
    {
        if ((string) $meta_key !== '_upay_subscription_status') {
            return;
        }
        if (!in_array((string) $meta_value, array('paused', 'cancelled'), true)) {
            return;
        }
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order((int) $object_id);
        if ($order instanceof \WC_Order) {
            self::cancel_after_state_change($order);
        }
    }
}
