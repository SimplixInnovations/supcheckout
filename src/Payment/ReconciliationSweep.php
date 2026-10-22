<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Hourly safety net for payment reconciliation.
 *
 * Per-order checks run on WP-Cron, which only fires on site traffic and can lose or
 * refuse an event. A UPayments webhook is acknowledged once and never resent, so a
 * lost check can leave a paid order unpaid. Each run re-checks a small, recent batch
 * of UPayments orders that still carry a payment cursor but have no check scheduled,
 * through the normal authenticated reconcile_order() path (rate-gated):
 * - pending orders with any cursor, and failed orders (a retried payment keeps the
 *   order failed) with an unverified cursor or a non-terminal current-attempt one;
 * - orders never swept come first; a swept order waits RECHECK_AFTER_SECONDS, so
 *   orders stuck at a non-terminal result cannot hold the batch every hour.
 * It also leaves one neutral note on a charged pending order that never got a
 * callback (no provider call).
 */
final class ReconciliationSweep {
    public const HOOK = 'supcheckout_reconciliation_sweep';
    private const GROUP = 'supcheckout';
    private const BATCH = 10;
    private const LOOKBACK_SECONDS = 172800;
    private const RECHECK_AFTER_SECONDS = 21600;
    private const NO_CALLBACK_AFTER_SECONDS = 1800;
    // Non-autoloaded; read only on admin, WP-Cron and WP-CLI requests.
    private const ENSURED_OPTION = 'supcheckout_sweep_ensured_at';
    private const ENSURE_INTERVAL_SECONDS = 43200;

    /**
     * @return void
     */
    public static function register() {
        add_action(self::HOOK, array(self::class, 'run'));
        add_action('admin_init', array(self::class, 'ensure_scheduled'));
        // Covers stores updated through WP-CLI or an auto-update with no admin visit after.
        // Not woocommerce_init: WooCommerce fires it at init:0, before Action Scheduler
        // initialises at init:1, so its API would refuse to schedule.
        add_action('action_scheduler_init', array(self::class, 'ensure_scheduled_in_background'));
    }

    /**
     * Ensures the recurring action from WP-Cron and WP-CLI requests only, so storefront
     * requests never pay for it. With ALTERNATE_WP_CRON, cron runs inside a visitor
     * request that is not flagged as a cron request, so on those stores only the
     * admin_init path ensures the action.
     *
     * @return void
     */
    public static function ensure_scheduled_in_background() {
        $cron = function_exists('wp_doing_cron') && wp_doing_cron();
        if (!$cron && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }
        self::ensure_scheduled();
    }

    /**
     * Ensuring the recurring action costs an Action Scheduler query, so it is skipped on
     * AJAX (Heartbeat, front-end admin-ajax) and runs at most once per interval.
     *
     * @return void
     */
    public static function ensure_scheduled() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        // Before initialisation the Action Scheduler API returns false/0 and flags
        // _doing_it_wrong; no marker is written, so a later request tries again.
        if (class_exists('ActionScheduler') && !\ActionScheduler::is_initialized()) {
            return;
        }
        $now = time();
        $ensured_at = (int) get_option(self::ENSURED_OPTION, 0);
        if ($ensured_at <= $now && $ensured_at > $now - self::ENSURE_INTERVAL_SECONDS) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK, array(), self::GROUP)
            && (int) as_schedule_recurring_action($now + 300, 3600, self::HOOK, array(), self::GROUP) <= 0
        ) {
            return;
        }
        update_option(self::ENSURED_OPTION, $now, false);
    }

    /**
     * @return void
     */
    public static function run() {
        // Runs only from Action Scheduler, which ships with WooCommerce.
        foreach (self::candidates() as $order_id) {
            PaymentLifecycle::sweep_order($order_id);
        }
        $now = time();
        $quiet = self::query(
            array('pending'),
            ($now - self::LOOKBACK_SECONDS) . '...' . ($now - self::NO_CALLBACK_AFTER_SECONDS),
            PaymentLifecycle::missing_callback_meta_query(),
            self::BATCH
        );
        foreach ($quiet as $order_id) {
            PaymentLifecycle::note_missing_callback($order_id);
        }
    }

    /**
     * Never-swept orders first, then orders whose last sweep is older than the
     * recheck window; pending before failed; oldest first within each query.
     *
     * @return list<int>
     */
    private static function candidates() {
        $since = '>' . (time() - self::LOOKBACK_SECONDS);
        $ids = array();
        foreach (array(null, time() - self::RECHECK_AFTER_SECONDS) as $checked_before) {
            foreach (array('pending', 'failed') as $status) {
                $room = self::BATCH - count($ids);
                if ($room <= 0) {
                    return $ids;
                }
                $meta_query = PaymentLifecycle::sweep_candidate_meta_query($status, $checked_before);
                foreach (self::query(array($status), $since, $meta_query, $room) as $order_id) {
                    if (!in_array($order_id, $ids, true)) {
                        $ids[] = $order_id;
                    }
                }
            }
        }
        return $ids;
    }

    /**
     * @param list<string> $statuses
     * @param string       $date_created wc_get_orders() date_created expression.
     * @param array        $meta_query
     * @param int          $limit
     * @return list<int>
     */
    private static function query(array $statuses, $date_created, array $meta_query, $limit) {
        $order_ids = wc_get_orders(array(
            'limit' => (int) $limit,
            'status' => $statuses,
            'payment_method' => Keys::payment_method_query(),
            'date_created' => $date_created,
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'ids',
            'meta_query' => $meta_query,
        ));
        if (!is_array($order_ids)) {
            return array();
        }
        $ids = array();
        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id > 0) {
                $ids[] = $order_id;
            }
        }
        return $ids;
    }

    private function __construct() {}
}
