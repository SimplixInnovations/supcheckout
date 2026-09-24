<?php

namespace Simplixi\SUPCheckout\Subscription\Scheduling;

defined('ABSPATH') || exit;

/**
 * Bounded historical subscription enrollment (migration / repair / recovery).
 *
 * Steady-state new subscriptions schedule via LifecycleScheduler. This class
 * discovers existing parents on older installs. Each feeder tick loads at
 * most BATCH_SIZE order objects and advances a persistent offset coordinate.
 *
 * Consistency model: offset pagination over a stable ID ASC matching set
 * (paid + payment_method=upayments). Insertions with higher IDs are found
 * later. A deletion may shift the window and skip one row; after a complete
 * pass the cursor resets so repair re-discovers skips. Temporary revisit is
 * acceptable because ensure_cycle_action is duplicate-safe. Never POSTs.
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

        $paid_statuses = self::paid_statuses();
        $offset = $stats['cursor'];

        $orders = wc_get_orders(array(
            'status'         => $paid_statuses,
            'payment_method' => 'upayments',
            'limit'          => self::BATCH_SIZE,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'return'         => 'objects',
        ));

        if (!is_array($orders)) {
            $orders = array();
        }

        $count = count($orders);
        $complete = $count < self::BATCH_SIZE;

        $stats = self::enroll_slice($orders, $complete);
        $stats['cursor'] = $complete ? 0 : $offset + $count;
        $stats['complete'] = $complete;
        update_option(self::OPTION_CURSOR, $stats['cursor'], false);

        return $stats;
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

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $stats['scanned']++;

            if (!self::parent_qualifies($order)) {
                $stats['skipped']++;
                continue;
            }

            $run_at = self::next_run_at($order);
            if ($run_at === null) {
                $stats['skipped']++;
                continue;
            }

            $parent_id = (int) $order->get_id();
            // Repair feeder schedules attempt 0 only. A pending retry for the
            // same cycle must not be replaced by competing primary work.
            if (ActionSchedulerBridge::has_any_open_cycle_attempt($parent_id, $run_at)) {
                $stats['skipped']++;
                continue;
            }
            if (ActionSchedulerBridge::ensure_cycle_action($parent_id, $run_at, null, 0)) {
                $stats['scheduled']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    public static function reset_cursor(): void
    {
        delete_option(self::OPTION_CURSOR);
    }

    /**
     * @return array<int,string>
     */
    public static function paid_statuses(): array
    {
        return function_exists('wc_get_is_paid_statuses')
            ? wc_get_is_paid_statuses()
            : array('processing', 'completed');
    }

    /**
     * Local subscription-parent qualification. Never provider truth.
     * Includes Woo paid-status check so stale workers are skipped early.
     */
    public static function parent_qualifies(\WC_Order $order): bool
    {
        if ((int) $order->get_id() <= 0) {
            return false;
        }
        if (!$order->has_status(self::paid_statuses())) {
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
     * Next legitimate due run timestamp, or null when not schedulable.
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
