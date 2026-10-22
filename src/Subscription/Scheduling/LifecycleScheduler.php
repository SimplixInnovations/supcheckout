<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Explicit customer lifecycle scheduling for the plugin-owned transition handler.
 *
 * Primary authority is the UPayments unsubscribe/pause/resume handler.
 * Meta hooks remain only a best-effort safety net and are not required for
 * correctness under HPOS.
 */
final class LifecycleScheduler
{
    /** Order meta flag: the parent already carries the paused-renewal note (fix A1-01). */
    public const PAUSED_NOTE_META = '_supcheckout_auto_renewal_paused_note_v1';

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
        if (!AutoDeductResultVerifier::can_verify_success()) {
            // Automatic renewal charges are blocked: queue nothing, tell the merchant once.
            self::note_auto_renewal_paused($order);
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
     * One order note per subscription parent: automatic renewal charges are paused, so the
     * merchant collects the renewal manually (fix A1-01). Guarded by an order meta flag and
     * written through the order CRUD (HPOS-safe). A renewal child or a one-time order gets
     * nothing.
     *
     * @return bool True when this call added the note.
     */
    public static function note_auto_renewal_paused(\WC_Order $order): bool
    {
        if (Keys::read_order_meta($order, 'UPayments_AutoDeduction') === 'yes') {
            return false;
        }
        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');
        if (!is_string($plan) || $plan === '' || $plan === 'one_time') {
            return false;
        }
        if ((string) $order->get_meta(self::PAUSED_NOTE_META) === '1') {
            return false;
        }
        // The flag is saved before the note: a later pass (the hourly feeder, a queued
        // worker action, a resume) finds it and adds no second note.
        $order->update_meta_data(self::PAUSED_NOTE_META, '1');
        $order->save();
        $order->add_order_note(
            __('Automatic renewal charges are paused: SUPCheckout cannot yet confirm an automatic UPayments charge. Collect this renewal manually or send the customer a payment link.', 'supcheckout')
        );
        return true;
    }

    /**
     * Best-effort net for legacy/external meta writes (not primary authority).
     */
    public static function maybe_cancel_on_status_meta($meta_id, $object_id, $meta_key, $meta_value): void
    {
        if (!Keys::is_meta_key($meta_key, '_upay_subscription_status')) {
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
