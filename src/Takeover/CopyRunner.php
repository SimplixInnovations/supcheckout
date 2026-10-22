<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;

defined('ABSPATH') || exit;

/**
 * Batched, resumable copy runner (Sub-project 0b Task 6).
 *
 * One run() is one bounded step of the `copying` state: at most ORDER_BATCH orders, USER_BATCH
 * users, PRODUCT_BATCH products or BillingTableCopier::BATCH table rows. The phases run in
 * order — orders, users, products, table, table_verify, finish — and each has its own
 * cursor in the takeover state (`cursor` for orders, `cursors.users|products|table|
 * table_verify` for the others).
 *
 * The cursor is persisted after EACH order, user or product with
 * TakeoverState::patch(..., 'copying'). A crash therefore re-processes at most the one
 * object it was working on, which the copiers handle idempotently (identical values are
 * skipped, no second row is ever added); and a status change by anyone else makes the
 * patch fail, which stops the runner at once. A contended order lock ends the step without
 * advancing the cursor, so the order is retried by the next step; nothing is forced.
 *
 * At `finish` the state moves to `verified` only when every order, user, product and
 * table row verified, and back to `preflight_ok` otherwise. The mismatch summary stays in
 * the state as counts plus key names or codes, never values.
 *
 * Only one runner works at a time: a self-expiring lock option (LOCK_OPTION, compare-and-
 * swap through OrderLock's primitives) makes a second runner return `busy`.
 *
 * Scheduling: only while the takeover is copying, register() adds the Action Scheduler
 * action HOOK (group GROUP, no arguments) and the watchdog on WATCHDOG_HOOK. Each action
 * queues its own continuation once (schedule(), unique), including when another runner
 * holds the lock. An action killed outright queues nothing; on the next request,
 * watchdog() (run on `action_scheduler_init`, when Action Scheduler is ready, which it never
 * is during Plugin::boot() because SUPCheckout loads before WooCommerce) re-queues the chain
 * if no batch action is pending or running. The unique schedule never duplicates one.
 */
final class CopyRunner {
    public const ORDER_BATCH = 50;
    public const USER_BATCH = 50;
    public const PRODUCT_BATCH = 50;

    public const LOCK_OPTION = 'supcheckout_takeover_copy_runner_v1';
    public const LOCK_TTL = 300;

    /** Action Scheduler hook and group of the batch action. */
    public const HOOK = 'supcheckout_takeover_copy_batch';
    public const GROUP = 'supcheckout';
    /** Fired by Action Scheduler once its data store is ready, on every request it loads. */
    public const WATCHDOG_HOOK = 'action_scheduler_init';
    /** Seconds one batch action keeps running steps. */
    public const ACTION_TIME_BUDGET = 20;
    /** Seconds before an action retries a contended order lock. */
    public const RETRY_DELAY = 60;

    public const PHASE_ORDERS = 'orders';
    public const PHASE_USERS = 'users';
    public const PHASE_PRODUCTS = 'products';
    public const PHASE_TABLE = 'table';
    public const PHASE_TABLE_VERIFY = 'table_verify';
    public const PHASE_FINISH = 'finish';

    public const CONTINUE_RUN = 'continue';
    public const RETRY = 'retry';
    public const VERIFIED = 'verified';
    public const MISMATCH = 'mismatch';
    public const STOPPED = 'stopped';
    public const BUSY = 'busy';
    public const IDLE = 'idle';

    /** Mismatch groups that block `verified`. */
    private const BLOCKING = array('orders', 'disagreements', 'users', 'products', 'table');
    /** Report code for an order whose single save() failed. */
    private const SAVE_FAILED = 'save_failed';

    /** @var bool One continuation per request at most. */
    private static $continuation_queued = false;

    /** @var CopyStore */
    private $store;
    /** @var callable */
    private $clock;
    /** @var int|null The order whose lock made the last run() return RETRY (A5-05). */
    private $locked_order = null;

    /**
     * @param CopyStore|null $store Store; WordPress when omitted.
     * @param callable|null  $clock function (): int; time() when omitted.
     */
    public function __construct($store = null, $clock = null) {
        $this->store = $store instanceof CopyStore ? $store : new WordPressCopyStore();
        $this->clock = is_callable($clock) ? $clock : 'time';
    }

    /**
     * One step against WordPress.
     *
     * @return string One of the status constants.
     */
    public static function run_batch() {
        $runner = new self();
        return $runner->run();
    }

    /**
     * Register the batch action and the watchdog, only while the takeover is copying: an
     * idle store (and every other status) registers nothing. Called from Plugin::boot(),
     * which a paused SUPCheckout (official plugin active) never reaches.
     *
     * @return void
     */
    public static function register() {
        if (TakeoverState::get()['status'] === TakeoverState::COPYING) {
            add_action(self::HOOK, array(self::class, 'handle_action'));
            add_action(self::WATCHDOG_HOOK, array(self::class, 'watchdog'));
        }
    }

    /**
     * Queue the next batch action, at most one: a pending or running action counts, and the
     * schedule itself is unique. Nothing is queued outside `copying`.
     *
     * @param int $delay Seconds from now.
     * @return bool True when an action is queued (now or already).
     */
    public static function schedule($delay = 0) {
        if (!ActionSchedulerBridge::is_ready()) {
            return false;
        }
        if (TakeoverState::get()['status'] !== TakeoverState::COPYING) {
            return false;
        }
        if (as_has_scheduled_action(self::HOOK, array(), self::GROUP)) {
            return true;
        }
        return as_schedule_single_action(time() + max(0, (int) $delay), self::HOOK, array(), self::GROUP, true) > 0;
    }

    /**
     * Re-queue a dead chain (registered on WATCHDOG_HOOK while copying): with Action
     * Scheduler initialized, queue the batch action unless one is already pending or
     * running. Safe on every request: schedule() is unique and does nothing outside copying.
     *
     * @return bool True when an action is queued (now or already).
     */
    public static function watchdog() {
        return self::schedule();
    }

    /**
     * The Action Scheduler callback: run steps until the time budget is spent or the runner
     * stops continuing, then queue the continuation.
     *
     * The continuation is queued at shutdown, not here: while this action runs it is
     * `in-progress`, which as_has_scheduled_action() and the unique schedule both count, so
     * queueing it from inside the callback would be refused and the chain would end. At
     * shutdown the action is complete. A process killed outright queues nothing; the cursor
     * keeps the progress and the watchdog resumes it on a later request.
     *
     * @return void
     */
    public static function handle_action() {
        $deadline = time() + self::ACTION_TIME_BUDGET;
        do {
            $status = self::run_batch();
        } while ($status === self::CONTINUE_RUN && time() < $deadline);
        $delay = self::continuation_delay($status);
        if ($delay !== null && !self::$continuation_queued) {
            self::$continuation_queued = true;
            register_shutdown_function(array(self::class, 'schedule'), $delay);
        }
    }

    /**
     * @param string $status A run() status.
     * @return int|null Seconds until the next batch, or null when nothing follows: a
     *                  finished, stopped or idle runner ends the chain. A busy one retries,
     *                  because the lock holder may be a killed action whose lock only
     *                  expires after LOCK_TTL; the unique schedule never duplicates a
     *                  continuation the holder queues itself.
     */
    public static function continuation_delay($status) {
        if ($status === self::CONTINUE_RUN) {
            return 0;
        }
        return ($status === self::RETRY || $status === self::BUSY) ? self::RETRY_DELAY : null;
    }

    /**
     * preflight_ok → copying, with every progress field reset first.
     *
     * @return bool
     */
    public static function begin() {
        TakeoverState::refresh();
        if (Rollback::removal_pending(TakeoverState::get())) {
            // An interrupted abort is still removing the previous copies (Task 10): rerun it first.
            return false;
        }
        if (!TakeoverState::patch(self::fresh_progress() + array(Rollback::PROGRESS => null), TakeoverState::PREFLIGHT_OK)) {
            return false;
        }
        return TakeoverState::transition(TakeoverState::COPYING);
    }

    /**
     * One bounded step under the runner lock; after it, this request's object cache is
     * flushed (CopyStore::flush_runtime_cache()), so a loop of steps never accumulates them.
     *
     * @return string One of the status constants.
     */
    public function run() {
        $this->locked_order = null;
        if (TakeoverState::get()['status'] !== TakeoverState::COPYING) {
            return self::IDLE;
        }
        $lock = $this->acquire_lock();
        if ($lock === null) {
            return self::BUSY;
        }
        try {
            $progress = self::progress(TakeoverState::get());
            switch ($progress['phase']) {
                case self::PHASE_ORDERS:
                    return $this->orders($progress);
                case self::PHASE_USERS:
                    return $this->users($progress);
                case self::PHASE_PRODUCTS:
                    return $this->products($progress);
                case self::PHASE_TABLE:
                    return $this->table($progress);
                case self::PHASE_TABLE_VERIFY:
                    return $this->table_verify($progress);
                default:
                    return $this->finish($progress);
            }
        } finally {
            OrderLock::replace_if_current(self::LOCK_OPTION, $lock, '0:released');
            // A5-02: every loop over run() (the onboarding driver's time-boxed tick, the
            // Action Scheduler action, WP-CLI `run`) spans many steps in one request, and
            // an object that copies nothing is never saved, so never evicted.
            $this->store->flush_runtime_cache();
        }
    }

    /** @return int|null The order whose lock made the last run() return RETRY. */
    public function locked_order() {
        return $this->locked_order;
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function orders(array $progress) {
        $ids = $this->store->order_ids_after((int) $progress['cursor'], self::ORDER_BATCH);
        $post_meta = $this->store->order_post_meta_reader();
        foreach ($ids as $order_id) {
            $order = $this->store->load_order($order_id);
            $copy = $order === null ? null : OrderCopier::copy($order, is_callable($post_meta) ? $post_meta : false);
            if ($copy !== null && $copy['status'] === OrderCopier::LOCKED) {
                $this->locked_order = (int) $order_id;
                return self::RETRY;
            }
            if ($copy === null || $copy['status'] === OrderCopier::MISSING) {
                ++$progress['totals']['orders_missing'];
            } else {
                $mismatches = $copy['mismatches'];
                if ($copy['status'] === OrderCopier::FAILED) {
                    $mismatches[] = self::SAVE_FAILED;
                } elseif ($copy['status'] === OrderCopier::COPIED) {
                    $verify = OrderCopier::verify($this->store->load_order($order_id));
                    $mismatches = array_merge($mismatches, $verify['mismatches']);
                }
                ++$progress['totals']['orders'];
                $progress['totals']['orders_copied'] += $copy['copied'] > 0 ? 1 : 0;
                $progress['mismatches']['orders'] = self::add_group($progress['mismatches']['orders'], $mismatches);
                $progress['mismatches']['disagreements'] = self::add_group($progress['mismatches']['disagreements'], $copy['disagreements']);
            }
            $progress['cursor'] = (int) $order_id;
            if (!self::save($progress)) {
                return self::STOPPED;
            }
        }
        return $this->next_phase($progress, count($ids) < self::ORDER_BATCH, self::PHASE_USERS);
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function users(array $progress) {
        $ids = $this->store->user_ids_after((int) $progress['cursors']['users'], self::USER_BATCH);
        foreach ($ids as $user_id) {
            $copy = UserMetaCopier::copy_user($this->store, $user_id);
            $verify = UserMetaCopier::verify_user($this->store, $user_id);
            ++$progress['totals']['users'];
            $progress['totals']['users_copied'] += $copy['copied'] > 0 ? 1 : 0;
            $progress['mismatches']['users'] = self::add_group($progress['mismatches']['users'], array_merge($copy['mismatches'], $verify['mismatches']));
            $progress['anomalies']['users_multi_row'] = self::add_group($progress['anomalies']['users_multi_row'], $verify['anomalies']);
            $progress['cursors']['users'] = (int) $user_id;
            if (!self::save($progress)) {
                return self::STOPPED;
            }
        }
        return $this->next_phase($progress, count($ids) < self::USER_BATCH, self::PHASE_PRODUCTS);
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function products(array $progress) {
        $ids = $this->store->product_ids_after((int) $progress['cursors']['products'], self::PRODUCT_BATCH);
        foreach ($ids as $product_id) {
            $product = $this->store->load_product($product_id);
            $copy = $product === null ? null : OrderCopier::copy_meta($product, Keys::product_meta());
            if ($copy !== null && $copy['status'] !== OrderCopier::MISSING) {
                $mismatches = $copy['mismatches'];
                if ($copy['status'] === OrderCopier::FAILED) {
                    $mismatches[] = self::SAVE_FAILED;
                } else {
                    $verify = OrderCopier::verify_meta($this->store->load_product($product_id), Keys::product_meta());
                    $mismatches = array_merge($mismatches, $verify['mismatches']);
                }
                ++$progress['totals']['products'];
                $progress['totals']['products_copied'] += $copy['copied'] > 0 ? 1 : 0;
                $progress['mismatches']['products'] = self::add_group($progress['mismatches']['products'], $mismatches);
            }
            $progress['cursors']['products'] = (int) $product_id;
            if (!self::save($progress)) {
                return self::STOPPED;
            }
        }
        return $this->next_phase($progress, count($ids) < self::PRODUCT_BATCH, self::PHASE_TABLE);
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function table(array $progress) {
        $step = BillingTableCopier::copy_batch($this->store, (string) $progress['cursors']['table']);
        if ($step['error'] !== null) {
            $progress['mismatches']['table'] = self::add_group($progress['mismatches']['table'], array($step['error']));
            return $this->next_phase($progress, true, self::PHASE_FINISH);
        }
        // Only a successful batch advances the cursor and the copied-key record.
        $progress['cursors']['table'] = $step['cursor'];
        $progress['max_copied_key'] = $step['cursor'];
        $progress['totals']['table_rows'] += $step['rows'];
        return $this->next_phase($progress, $step['done'], self::PHASE_TABLE_VERIFY);
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function table_verify(array $progress) {
        $step = BillingTableCopier::verify_batch($this->store, (string) $progress['cursors']['table_verify']);
        $progress['cursors']['table_verify'] = $step['cursor'];
        $group = $progress['mismatches']['table'];
        $group['count'] += $step['mismatches'];
        $group['keys'] = self::merge_keys($group['keys'], $step['codes']);
        $progress['mismatches']['table'] = $group;
        return $this->next_phase($progress, $step['done'], self::PHASE_FINISH);
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return string
     */
    private function finish(array $progress) {
        $blocked = false;
        foreach (self::BLOCKING as $group) {
            $blocked = $blocked || $progress['mismatches'][$group]['count'] > 0;
        }
        if (!TakeoverState::transition($blocked ? TakeoverState::PREFLIGHT_OK : TakeoverState::VERIFIED)) {
            return self::STOPPED;
        }
        return $blocked ? self::MISMATCH : self::VERIFIED;
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @param bool                 $done     The current phase is complete.
     * @param string               $next     Next phase.
     * @return string
     */
    private function next_phase(array $progress, $done, $next) {
        if ($done) {
            $progress['phase'] = $next;
        }
        return self::save($progress) ? self::CONTINUE_RUN : self::STOPPED;
    }

    /**
     * @param array<string, mixed> $progress Progress.
     * @return bool False when the state is no longer `copying`.
     */
    private static function save(array $progress) {
        return TakeoverState::patch($progress, TakeoverState::COPYING);
    }

    /**
     * @param array{count:int, keys:list<string>} $group Group.
     * @param list<string>                       $keys  This object's mismatching keys.
     * @return array{count:int, keys:list<string>}
     */
    private static function add_group(array $group, array $keys) {
        if ($keys === array()) {
            return $group;
        }
        return array('count' => $group['count'] + 1, 'keys' => self::merge_keys($group['keys'], $keys));
    }

    /**
     * @param list<string> $keys  Known keys.
     * @param list<string> $added Added keys.
     * @return list<string> Sorted, unique.
     */
    private static function merge_keys(array $keys, array $added) {
        $merged = array_values(array_unique(array_merge($keys, $added)));
        sort($merged, SORT_STRING);
        return $merged;
    }

    /**
     * Progress fields with defaults for anything absent or ill-typed.
     *
     * @param array<string, mixed> $state TakeoverState::get().
     * @return array<string, mixed>
     */
    private static function progress(array $state) {
        $fresh = self::fresh_progress();
        $progress = array();
        foreach ($fresh as $field => $default) {
            $value = isset($state[$field]) ? $state[$field] : $default;
            $progress[$field] = is_array($default) && is_array($value) ? self::fill($default, $value) : $value;
            if (gettype($progress[$field]) !== gettype($default)) {
                $progress[$field] = $default;
            }
        }
        return $progress;
    }

    /**
     * @param array<string, mixed> $default Default shape.
     * @param array<mixed>         $value   Stored value.
     * @return array<string, mixed>
     */
    private static function fill(array $default, array $value) {
        $out = array();
        foreach ($default as $key => $item) {
            $stored = array_key_exists($key, $value) ? $value[$key] : $item;
            if ($item === array()) {
                // A list (mismatch key names): kept as stored, strings only.
                $out[$key] = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : array();
            } elseif (is_array($item)) {
                $out[$key] = is_array($stored) ? self::fill($item, $stored) : $item;
            } else {
                $out[$key] = gettype($stored) === gettype($item) ? $stored : $item;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function fresh_progress() {
        $group = array('count' => 0, 'keys' => array());
        return array(
            'phase' => self::PHASE_ORDERS,
            'cursor' => 0,
            'cursors' => array('users' => 0, 'products' => 0, 'table' => '', 'table_verify' => ''),
            'totals' => array(
                'orders' => 0, 'orders_copied' => 0, 'orders_missing' => 0, 'users' => 0, 'users_copied' => 0,
                'products' => 0, 'products_copied' => 0, 'table_rows' => 0,
            ),
            'mismatches' => array(
                'orders' => $group, 'disagreements' => $group, 'users' => $group, 'products' => $group, 'table' => $group,
            ),
            'anomalies' => array('users_multi_row' => $group),
            'max_copied_key' => '',
        );
    }

    /** @return string|null This runner's lock record, or null when another runner holds it. */
    private function acquire_lock() {
        $now = (int) call_user_func($this->clock);
        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return null;
        }
        $record = ($now + self::LOCK_TTL) . ':' . $token;
        if (OrderLock::insert_if_absent(self::LOCK_OPTION, $record)) {
            return $record;
        }
        OrderLock::flush_option_cache(self::LOCK_OPTION);
        $existing = get_option(self::LOCK_OPTION, null);
        if (!is_string($existing) || preg_match('/^([0-9]{1,12}):[a-z0-9]+$/D', $existing, $matches) !== 1 || (int) $matches[1] >= $now) {
            return null;
        }
        return OrderLock::replace_if_current(self::LOCK_OPTION, $existing, $record) ? $record : null;
    }
}
