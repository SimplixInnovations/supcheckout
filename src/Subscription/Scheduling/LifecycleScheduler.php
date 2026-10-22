<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;
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
    /** Flag value: the plain paused note was written. */
    public const PAUSED_NOTE_PLAIN = '1';
    /** Flag value: the "check the UPayments dashboard first" note was written. */
    public const PAUSED_NOTE_UNCONFIRMED = 'unconfirmed';
    /** Per-parent option lock that keeps two passes from both writing the note. */
    public const NOTE_LOCK_PREFIX = 'supcheckout_renewal_note_lock_';
    /** Seconds after which a lock left by a crashed pass is replaced. */
    public const NOTE_LOCK_TTL = 300;

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
     * merchant collects the renewal manually (fix A1-01). A renewal child or a one-time order
     * gets nothing.
     *
     * When an earlier automatic charge may have debited the customer (a held or dispatching
     * billing cycle, or a legacy unresolved attempt), the note says to check the UPayments
     * dashboard before collecting, so the customer is not charged twice.
     *
     * Idempotent under concurrency: a per-parent option-row lock taken with the plugin's
     * atomic option CAS (acquire_note_lock(); add_option() is not atomic), the flag re-read
     * on a freshly loaded order under that lock, and the lock released in `finally`. The
     * flag is saved before the note: a later pass finds it and adds no second note, and a
     * note that fails after the flag is never retried into a duplicate. Never throws.
     *
     * @return bool True when this call added the note.
     */
    public static function note_auto_renewal_paused(\WC_Order $order): bool
    {
        if (!self::is_subscription_parent($order) || self::paused_note_flag($order) !== '') {
            return false;
        }
        $parent_id = (int) $order->get_id();
        if ($parent_id <= 0 || !self::acquire_note_lock($parent_id)) {
            return false;
        }
        try {
            $fresh = function_exists('wc_get_order') ? wc_get_order($parent_id) : false;
            if (!$fresh instanceof \WC_Order || self::paused_note_flag($fresh) !== '') {
                return false;
            }
            $unconfirmed = self::has_unconfirmed_charge($fresh);
            $fresh->update_meta_data(self::PAUSED_NOTE_META, $unconfirmed ? self::PAUSED_NOTE_UNCONFIRMED : self::PAUSED_NOTE_PLAIN);
            $fresh->save();
            $fresh->add_order_note($unconfirmed
                ? __('Automatic renewal charges are paused: SUPCheckout cannot yet confirm an automatic UPayments charge. An earlier automatic charge for this subscription could not be confirmed. Check the UPayments dashboard for that payment before you collect this renewal manually or send a payment link, so the customer is not charged twice.', 'supcheckout')
                : __('Automatic renewal charges are paused: SUPCheckout cannot yet confirm an automatic UPayments charge. Collect this renewal manually or send the customer a payment link.', 'supcheckout'));
            return true;
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    'Paused-renewal note could not be written.',
                    array('source' => 'upayments-cron', 'order_id' => $parent_id)
                );
            }
            return false;
        } finally {
            delete_option(self::NOTE_LOCK_PREFIX . $parent_id);
        }
    }

    /**
     * Read-only: may an earlier automatic charge have debited the customer for this parent?
     * True for a held or dispatching billing cycle, a legacy unresolved attempt, or when the
     * billing history cannot be read (fail closed: "check the dashboard first").
     */
    public static function has_unconfirmed_charge(\WC_Order $order): bool
    {
        if ((string) Keys::read_order_meta($order, '_upay_last_attempt_at') !== '') {
            return true;
        }
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return true;
        }
        try {
            if (!CycleClaim::table_exists()) {
                // No table and no error: no billing history (with the gate closed a new
                // install never creates it). A failing probe is unreadable: fail closed.
                return isset($wpdb->last_error) && is_string($wpdb->last_error) && $wpdb->last_error !== '';
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read-only count on the plugin-owned billing-attempt journal; a cached answer could hide a possible earlier debit.
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE parent_order_id = %d AND state IN (%s, %s)',
                    CycleClaim::table_name(),
                    (int) $order->get_id(),
                    CycleClaim::STATE_HELD,
                    CycleClaim::STATE_DISPATCHING
                )
            );
        } catch (\Throwable $e) {
            return true;
        }
        return $count === null || (int) $count > 0;
    }

    /** @return string The paused-note flag value ('' when the parent has no note yet). */
    public static function paused_note_flag(\WC_Order $order): string
    {
        $flag = $order->get_meta(self::PAUSED_NOTE_META);
        return is_scalar($flag) ? (string) $flag : '';
    }

    private static function is_subscription_parent(\WC_Order $order): bool
    {
        if (Keys::read_order_meta($order, 'UPayments_AutoDeduction') === 'yes') {
            return false;
        }
        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');
        return is_string($plan) && $plan !== '' && $plan !== 'one_time';
    }

    /**
     * Atomic per-parent lock on an option row, through the plugin's option CAS
     * (OrderLock::insert_if_absent() / replace_if_current(), as TakeoverState and
     * CopyRunner use it). The record is "<acquired_at>:<token>". A lock older than
     * NOTE_LOCK_TTL (a crashed pass) is stolen only by a compare-and-swap of the exact
     * stale value read, so two stealers of the same stale lock never both win.
     */
    private static function acquire_note_lock(int $parent_id): bool
    {
        $lock = self::NOTE_LOCK_PREFIX . $parent_id;
        $now = time();
        try {
            $record = $now . ':' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return false;
        }
        if (OrderLock::insert_if_absent($lock, $record)) {
            return true;
        }
        OrderLock::flush_option_cache($lock);
        $existing = get_option($lock, null);
        if (!is_string($existing)
            || preg_match('/^([0-9]{1,12})(?::[a-z0-9]+)?$/D', $existing, $matches) !== 1
            || (int) $matches[1] > $now - self::NOTE_LOCK_TTL
        ) {
            return false;
        }
        return OrderLock::replace_if_current($lock, $existing, $record);
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
