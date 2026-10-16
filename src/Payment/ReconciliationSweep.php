<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

/**
 * Hourly safety net for payment reconciliation.
 *
 * Per-order checks run on WP-Cron, which only fires on site traffic and can lose or
 * refuse an event. A UPayments webhook is acknowledged once and never resent, so a
 * lost check can leave a paid order unpaid. Each run re-checks a small, recent batch
 * of pending UPayments orders that still carry a payment cursor but have no check
 * scheduled, through the normal authenticated reconcile_order() path (rate-gated).
 */
final class ReconciliationSweep {
    public const HOOK = 'supcheckout_reconciliation_sweep';
    private const GROUP = 'supcheckout';
    private const BATCH = 10;
    private const LOOKBACK_SECONDS = 172800;

    /**
     * @return void
     */
    public static function register() {
        add_action(self::HOOK, array(self::class, 'run'));
        // Ensuring the recurring action costs a query, so storefront requests skip it.
        add_action('admin_init', array(self::class, 'ensure_scheduled'));
    }

    /**
     * @return void
     */
    public static function ensure_scheduled() {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK, array(), self::GROUP)) {
            as_schedule_recurring_action(time() + 300, 3600, self::HOOK, array(), self::GROUP);
        }
    }

    /**
     * @return void
     */
    public static function run() {
        // Runs only from Action Scheduler, which ships with WooCommerce.
        $order_ids = wc_get_orders(array(
            'limit' => self::BATCH,
            'status' => array('pending'),
            'payment_method' => 'upayments',
            'date_created' => '>' . (time() - self::LOOKBACK_SECONDS),
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'ids',
            'meta_query' => PaymentLifecycle::sweep_cursor_meta_query(),
        ));
        if (!is_array($order_ids)) {
            return;
        }
        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id <= 0 || PaymentLifecycle::has_scheduled_reconciliation($order_id)) {
                continue;
            }
            PaymentLifecycle::reconcile_order($order_id);
        }
    }

    private function __construct() {}
}
