<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Bounded historical subscription enrollment.
 *
 * Replaces the unbounded hourly historical-order scan. Each feeder tick
 * inspects at most BATCH_SIZE paid orders and schedules due-parent work.
 * Never performs a provider POST.
 */
final class HistoricalEnrollment
{
    public const OPTION_CURSOR = 'upay_subscription_enrollment_cursor';
    public const BATCH_SIZE   = 50;

    /**
     * One bounded enrollment batch. Resume-safe and duplicate-safe.
     *
     * @return array{scanned:int,scheduled:int,skipped:int,complete:bool,cursor:int}
     */
    public static function run_batch(): array
    {
        $stats = array(
            'scanned'   => 0,
            'scheduled' => 0,
            'skipped'   => 0,
            'complete'  => false,
            'cursor'    => (int) get_option(self::OPTION_CURSOR, 0),
        );

        if (!ActionSchedulerBridge::is_ready()) {
            return $stats;
        }

        $paid_statuses = function_exists('wc_get_is_paid_statuses')
            ? wc_get_is_paid_statuses()
            : array('processing', 'completed');

        $orders = wc_get_orders(array(
            'status' => $paid_statuses,
            'limit'  => self::BATCH_SIZE,
            'paged'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ));

        if (!is_array($orders)) {
            $orders = array();
        }

        return self::enroll_slice($orders, count($orders) < self::BATCH_SIZE);
    }

    /**
     * Enroll a concrete order slice. Used by run_batch() and focused tests.
     *
     * @param array<int,\WC_Order> $orders
     * @return array{scanned:int,scheduled:int,skipped:int,complete:bool,cursor:int}
     */
    public static function enroll_slice(array $orders, bool $complete): array
    {
        $stats = array(
            'scanned'   => 0,
            'scheduled' => 0,
            'skipped'   => 0,
            'complete'  => $complete,
            'cursor'    => (int) get_option(self::OPTION_CURSOR, 0),
        );

        if (!ActionSchedulerBridge::is_ready()) {
            return $stats;
        }

        $max_id = $stats['cursor'];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $stats['scanned']++;
            $parent_id = (int) $order->get_id();
            if ($parent_id > $max_id) {
                $max_id = $parent_id;
            }

            if (!self::parent_qualifies($order)) {
                $stats['skipped']++;
                continue;
            }

            $run_at = self::next_run_at($order);
            if ($run_at === null) {
                $stats['skipped']++;
                continue;
            }

            if (ActionSchedulerBridge::ensure_parent_action($parent_id, $run_at)) {
                $stats['scheduled']++;
            } else {
                $stats['skipped']++;
            }
        }

        $stats['cursor'] = $max_id;
        update_option(self::OPTION_CURSOR, $max_id, false);
        return $stats;
    }

    public static function reset_cursor(): void
    {
        delete_option(self::OPTION_CURSOR);
    }

    /**
     * Local subscription-parent qualification. Never provider truth.
     */
    public static function parent_qualifies(\WC_Order $order): bool
    {
        if ((int) $order->get_id() <= 0) {
            return false;
        }
        if ($order->get_meta('UPayments_AutoDeduction') === 'yes') {
            return false;
        }
        if ((string) $order->get_payment_method() !== 'upayments') {
            return false;
        }

        $status = $order->get_meta('_upay_subscription_status') ?: 'active';
        if ($status !== 'active') {
            return false;
        }

        $plan = $order->get_meta('_upay_subscription_plan');
        $interval = (int) $order->get_meta('_upay_subscription_interval');
        if ($plan === 'daily') {
            $interval = 1;
        }
        if ((!$plan || $interval < 1) || $plan === 'one_time') {
            return false;
        }

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product && method_exists($product, 'get_type') && $product->get_type() === 'custom_type') {
                return true;
            }
        }
        return false;
    }

    /**
     * Next due run timestamp, or null when not due/schedulable.
     */
    public static function next_run_at(\WC_Order $order): ?int
    {
        $plan = $order->get_meta('_upay_subscription_plan');
        $interval = (int) $order->get_meta('_upay_subscription_interval');
        if ($plan === 'daily') {
            $interval = 1;
        }
        if (!$plan || $interval < 1) {
            return null;
        }

        $start_date = $order->get_meta('_upay_last_billed_at')
            ?: $order->get_date_paid()
            ?: $order->get_date_completed()
            ?: $order->get_date_created();
        if (!$start_date) {
            return null;
        }
        if (is_string($start_date)) {
            $start_date = new \DateTime($start_date);
        } elseif ($start_date instanceof \DateTimeInterface) {
            $start_date = new \DateTime($start_date->format('Y-m-d H:i:s'), $start_date->getTimezone());
        }

        if (!class_exists('UPayments\Subscription\Cron\Scheduler', false)) {
            require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php';
        }
        $next = \UPayments\Subscription\Cron\Scheduler::getNextBillingDate($start_date, (string) $plan, $interval);
        if (!$next) {
            return null;
        }
        return $next->getTimestamp();
    }
}
