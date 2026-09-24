<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Steady-state scheduling for new/resumed subscriptions.
 *
 * HistoricalEnrollment is migration/repair only. New eligible parents must
 * get their next-cycle action directly from a safe Woo lifecycle hook so
 * future billing does not depend on re-scanning historical stores.
 */
final class LifecycleScheduler
{
    public static function register(): void
    {
        add_action('woocommerce_payment_complete', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'maybe_schedule_initial'), 20, 1);

        // Pause/cancel: best-effort queue cleanup. Stale actions still fail closed.
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
        if (!HistoricalEnrollment::parent_qualifies($order)) {
            return;
        }
        $due = HistoricalEnrollment::next_run_at($order);
        if ($due === null) {
            return;
        }
        ActionSchedulerBridge::ensure_cycle_action($id, $due);
    }

    /**
     * On pause/cancel meta writes, best-effort cancel SUPCheckout pending work.
     */
    public static function maybe_cancel_on_status_meta($meta_id, $object_id, $meta_key, $meta_value): void
    {
        if ((string) $meta_key !== '_upay_subscription_status') {
            return;
        }
        if (!in_array((string) $meta_value, array('paused', 'cancelled'), true)) {
            return;
        }
        ActionSchedulerBridge::cancel_parent_actions((int) $object_id);
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
        return ActionSchedulerBridge::ensure_cycle_action((int) $order->get_id(), $due);
    }
}
