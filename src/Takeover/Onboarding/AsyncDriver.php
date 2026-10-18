<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge;
use Simplixi\SUPCheckout\Takeover\CachePurger;
use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Preflight;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Takeover\WordPressCopyStore;
use Simplixi\SUPCheckout\Takeover\WordPressCutoverSystem;
use Simplixi\SUPCheckout\Takeover\WordPressStaleClaimStore;

defined('ABSPATH') || exit;

/**
 * The Action Scheduler side of the onboarding driver (Sub-project 0c Task 6): one persisted,
 * single-flight Operation advanced by bounded ticks (DriverStep).
 *
 * - register() adds HOOK and WATCHDOG_HOOK in contexts that run Action Scheduler (cron,
 *   AJAX, WP-CLI) and otherwise only on `action_scheduler_before_process_queue` (ALTERNATE_WP_CRON
 *   and the admin "Run" action), without reading any state, so a frontend or admin page load pays
 *   nothing.
 * - enqueue() queues one unique action per operation in GROUP. If Action Scheduler cannot
 *   take it, the operation is finished `failed` / `scheduler_unavailable` (compare-and-swap)
 *   and false is returned, so no operation is left running with nothing to run it (the REST
 *   layer answers 503).
 *   When another operation's stale action holds the unique slot (Action Scheduler's
 *   `unique` compares only hook and group), it returns true and schedules this operation's
 *   own watchdog, so recovery never depends on the stale action alone.
 * - handle() runs one tick. For another operation's (stale) action it only re-queues the
 *   current running operation (enqueue is unique) and advances nothing. It
 *   replaces the dead-man watchdog on every tick (Action Scheduler's `unique` compares only
 *   hook and group, so a stale unique watchdog would keep a long phase's deadline into a
 *   short one); before a long phase it raises the time limit and stores the attempt before
 *   the work starts (a killed tick persists nothing afterwards); then it persists the tick
 *   and queues the continuation at shutdown, when the running action no longer counts as
 *   unique (as CopyRunner::handle_action() does).
 * - watchdog() re-queues a running operation that is due and has no pending or running
 *   action. It runs from the scheduled watchdog action, from status polls and start calls.
 * - runner_health() tells a status poll that Action Scheduler is not running (WP-Cron
 *   disabled and no admin page dispatching the async runner): the operation stays running
 *   with nothing half-done, and after RUNNER_STALL_SECONDS past due it reads `stalled`.
 *
 * A \Throwable outside the tick finishes the operation `failed` with error `error`, the
 * exception's class name only and a restart hint; the message is never stored.
 */
final class AsyncDriver {
    public const HOOK = 'supcheckout_onboarding_drive';
    public const WATCHDOG_HOOK = 'supcheckout_onboarding_watchdog';
    public const GROUP = 'supcheckout-onboarding';
    /** Action Scheduler fires it before it processes its queue, in any request context. */
    public const QUEUE_START_ACTION = 'action_scheduler_before_process_queue';
    /** Seconds before a short phase's watchdog fires. */
    public const WATCHDOG_DELAY = 300;
    /** Seconds before a long phase's watchdog fires: after a dead holder's lease expired. */
    public const LONG_WATCHDOG_DELAY = Quiesce::TTL + 60;
    /** Seconds past due after which the runner reads as stalled. */
    public const RUNNER_STALL_SECONDS = 60;

    private const HINT_SCHEDULER = 'Action Scheduler is not available, so nothing can run the operation; check that WooCommerce is active, then start again';

    /** @var bool Whether the queue-start callback already added the two hooks in this request. */
    private static $hooked = false;

    /** @var array<string, bool> Operations whose continuation this request already queued. */
    private static $continuations = array();

    /**
     * Add the two hooks now in a context known to run Action Scheduler; on any other request
     * add them lazily when Action Scheduler starts its queue (see register_hooks()).
     *
     * @return void
     */
    public static function register() {
        $cli = defined('WP_CLI') && constant('WP_CLI');
        $cron = function_exists('wp_doing_cron') && wp_doing_cron();
        $ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
        if (!$cli && !$cron && !$ajax) {
            add_action(self::QUEUE_START_ACTION, array(self::class, 'register_hooks'));
            return;
        }
        self::add_hooks();
    }

    /**
     * Add HOOK and WATCHDOG_HOOK (a pure add_action: no state is read). It is the callback of
     * Action Scheduler's `action_scheduler_before_process_queue`, which fires before the queue
     * runs in any context, so the two contexts register() cannot detect still find a callback:
     * ALTERNATE_WP_CRON (wp-cron runs inside a normal request after plugins load, so
     * DOING_CRON is defined too late) and Action Scheduler's admin "Run" action (an admin
     * page request, not AJAX). Without it Action Scheduler runs the action with no callback
     * and marks it failed. Idempotent.
     *
     * @return void
     */
    public static function register_hooks() {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        self::add_hooks();
    }

    /** @return void */
    private static function add_hooks() {
        add_action(self::HOOK, array(self::class, 'handle'), 10, 1);
        add_action(self::WATCHDOG_HOOK, array(self::class, 'watchdog'), 10, 2);
    }

    /**
     * Queue the operation's next tick, at most one action.
     *
     * @param string $op_id Operation ID.
     * @param int    $delay Seconds from now.
     * @return bool True when an action is queued (now or already); false after finishing
     *              the operation `scheduler_unavailable`.
     */
    public static function enqueue($op_id, $delay = 0) {
        $op_id = (string) $op_id;
        if (self::scheduler_ready()) {
            if (as_has_scheduled_action(self::HOOK, array($op_id), self::GROUP)) {
                return true;
            }
            if (as_schedule_single_action(time() + max(0, (int) $delay), self::HOOK, array($op_id), self::GROUP, true) > 0) {
                return true;
            }
            if (as_has_scheduled_action(self::HOOK, null, self::GROUP)) {
                // Another (stale) action holds the unique slot. It re-queues this operation
                // when it runs (drive()); this operation's own watchdog covers it meanwhile.
                $op = Operation::current();
                if (Operation::is_running($op) && $op['id'] === $op_id) {
                    self::replace_watchdog($op_id, DriverStep::upcoming($op)['phase'], time() + self::WATCHDOG_DELAY);
                }
                return true;
            }
        }
        Operation::finish($op_id, Operation::FAILED, 'scheduler_unavailable', self::HINT_SCHEDULER, array(), time());
        return false;
    }

    /**
     * The HOOK callback, bound to WordPress.
     *
     * @param string $op_id Operation ID.
     * @return void
     */
    public static function handle($op_id) {
        self::drive((string) $op_id, array(self::class, 'wordpress_step'), array(self::class, 'defer'));
    }

    /**
     * One tick of the running operation $op_id (handle() with its collaborators passed in).
     *
     * @param string   $op_id     Operation ID from the action.
     * @param callable $make_step function (BudgetedWordPressSystem $system): DriverStep.
     * @param callable $defer     function (string $op_id, int $delay): void, queues the continuation.
     * @return void
     */
    public static function drive($op_id, $make_step, $defer) {
        $op = Operation::current();
        if (!Operation::is_running($op)) {
            return;
        }
        if ($op['id'] !== (string) $op_id) {
            // A stale action of an earlier operation. It may have held the unique slot the
            // current operation's enqueue was refused for: hand the slot on (enqueue is
            // unique), without advancing the current operation in this tick.
            call_user_func($defer, $op['id'], 0);
            return;
        }
        $now = time();
        try {
            $upcoming = DriverStep::upcoming($op);
            self::replace_watchdog($op['id'], $upcoming['phase'], $now + ($upcoming['long'] ? self::LONG_WATCHDOG_DELAY : self::WATCHDOG_DELAY));
            if ($upcoming['long']) {
                if (function_exists('wc_set_time_limit')) {
                    wc_set_time_limit(0);
                }
                if (!self::store_attempt($op, $upcoming, $now)) {
                    return;
                }
            }
            $system = new BudgetedWordPressSystem(new WordPressCutoverSystem(), $now + DriverStep::TICK_BUDGET, 'time', $upcoming['long']);
            $result = call_user_func($make_step, $system)->tick($op);
        } catch (\Throwable $e) {
            Operation::finish($op['id'], Operation::FAILED, 'error', DriverStep::HINT_ERROR, array('exception' => get_class($e)), time());
            return;
        }
        self::persist($op, $result, $defer);
    }

    /**
     * Re-queue the running operation when it is due and nothing is pending or running.
     *
     * @param string|null $op_id Operation the watchdog was set for; another one is ignored.
     * @param string|null $phase Phase it was set in (informational).
     * @return bool True when this call queued an action.
     */
    public static function watchdog($op_id = null, $phase = null) {
        unset($phase);
        $op = Operation::current();
        if (!Operation::is_running($op) || ($op_id !== null && $op['id'] !== (string) $op_id)) {
            return false;
        }
        if ((int) $op['next_at'] > time() || !self::scheduler_ready()) {
            return false;
        }
        if (as_has_scheduled_action(self::HOOK, null, self::GROUP)) {
            return false;
        }
        return self::enqueue($op['id'], 0);
    }

    /**
     * @param array<string, mixed>|null $op  A record from Operation::current().
     * @param int                       $now Unix time.
     * @return array{stalled:bool, past_due_seconds:int}
     */
    public static function runner_health($op, $now) {
        if (!Operation::is_running($op)) {
            return array('stalled' => false, 'past_due_seconds' => 0);
        }
        $due = isset($op['next_at']) ? (int) $op['next_at'] : 0;
        if ($due <= 0) {
            // Never ticked: due since its start.
            $due = isset($op['started_at']) ? (int) $op['started_at'] : 0;
        }
        $past = max(0, (int) $now - $due);
        return array('stalled' => $past > self::RUNNER_STALL_SECONDS, 'past_due_seconds' => $past);
    }

    /**
     * The self-test's gateway binding: `enabled` from the SUPCheckout settings option,
     * `registered` from WooCommerce's live gateway registry (our class only).
     *
     * @return array{registered:bool, enabled:bool}
     */
    public static function gateway_state() {
        $settings = get_option(Keys::SETTINGS_OPTION, array());
        $enabled = is_array($settings) && isset($settings['enabled']) && $settings['enabled'] === 'yes';
        $registered = false;
        $woocommerce = function_exists('WC') ? WC() : null;
        if (is_object($woocommerce) && method_exists($woocommerce, 'payment_gateways')) {
            $registry = $woocommerce->payment_gateways();
            if (is_object($registry) && method_exists($registry, 'payment_gateways')) {
                $registered = Keys::find_gateway($registry->payment_gateways()) !== null;
            }
        }
        return array('registered' => $registered, 'enabled' => $enabled);
    }

    /** @return string The current TakeoverState status (fresh read). */
    public static function takeover_status() {
        TakeoverState::refresh();
        return (string) TakeoverState::get()['status'];
    }

    /** @return array<string, mixed> SelfTest::run() bound to WordPress. */
    public static function run_self_test() {
        return (new SelfTest(Environment::from_wordpress(), array(self::class, 'gateway_state'), array(self::class, 'takeover_status')))->run();
    }

    /** @return array<string, mixed> SetupChecks::run() bound to WordPress. */
    public static function run_setup_checks() {
        return (new SetupChecks(Environment::from_wordpress(), array(CachePurger::class, 'detected')))->run();
    }

    /**
     * @param BudgetedWordPressSystem $system The tick's system.
     * @return DriverStep Bound to WordPress through $system.
     */
    public static function wordpress_step(BudgetedWordPressSystem $system) {
        $onboarding = new Onboarding(array(Preflight::class, 'run'), new WordPressCopyStore(), $system, new WordPressStaleClaimStore());
        return new DriverStep($onboarding, $system, array(self::class, 'run_self_test'), array(self::class, 'run_setup_checks'), 'time');
    }

    /**
     * Queue the continuation at shutdown, once per operation and request.
     *
     * @param string $op_id Operation ID.
     * @param int    $delay Seconds.
     * @return void
     */
    public static function defer($op_id, $delay) {
        if (isset(self::$continuations[$op_id])) {
            return;
        }
        self::$continuations[$op_id] = true;
        register_shutdown_function(array(self::class, 'enqueue'), (string) $op_id, (int) $delay);
    }

    /**
     * @param string $op_id    Operation ID.
     * @param string $phase    Upcoming phase.
     * @param int    $deadline When the watchdog fires.
     * @return void
     */
    private static function replace_watchdog($op_id, $phase, $deadline) {
        if (!self::scheduler_ready()) {
            return;
        }
        as_unschedule_all_actions(self::WATCHDOG_HOOK, null, self::GROUP);
        as_schedule_single_action((int) $deadline, self::WATCHDOG_HOOK, array((string) $op_id, (string) $phase), self::GROUP, false);
    }

    /**
     * Store the long phase's attempt before the work starts.
     *
     * @param array<string, mixed>                          $op       The record.
     * @param array{phase:string, long:bool, attempt:?string} $upcoming DriverStep::upcoming().
     * @param int                                           $now      Unix time.
     * @return bool False when the record moved meanwhile: this tick does nothing (the watchdog resumes).
     */
    private static function store_attempt(array $op, array $upcoming, $now) {
        $attempts = $op['attempts'];
        $key = (string) $upcoming['attempt'];
        $attempts[$key] = (isset($attempts[$key]) ? (int) $attempts[$key] : 0) + 1;
        return Operation::update($op['id'], array('attempts' => $attempts), $now);
    }

    /**
     * Persist the tick, then finish the operation or queue its continuation.
     *
     * @param array<string, mixed> $op     The record the tick advanced.
     * @param array<string, mixed> $result DriverStep::tick().
     * @param callable             $defer  Queues the continuation.
     * @return void
     */
    private static function persist(array $op, array $result, $defer) {
        $now = time();
        $fields = DriverStep::progress_fields($op, $result);
        $terminal = $result['next_delay'] === null;
        if ($terminal) {
            unset($fields['error'], $fields['hint'], $fields['report']);
            if (isset($result['report']['self_test']) && is_array($result['report']['self_test'])) {
                $fields['self_test'] = $result['report']['self_test'];
            }
        } else {
            $fields['next_at'] = $now + (int) $result['next_delay'];
        }
        if (!self::write($op['id'], $fields, $now)) {
            return;
        }
        if ($terminal) {
            Operation::finish($op['id'], (string) $result['outcome'], $result['error'], $result['hint'], $result['report'], $now);
            return;
        }
        call_user_func($defer, $op['id'], (int) $result['next_delay']);
    }

    /**
     * Operation::update() with one retry after a lost compare-and-swap. A record that cannot
     * be updated while still ours and running is finished `failed`, never left orphaned.
     *
     * @param string               $op_id  Operation ID.
     * @param array<string, mixed> $fields Fields.
     * @param int                  $now    Unix time.
     * @return bool True when persisted.
     */
    private static function write($op_id, array $fields, $now) {
        for ($try = 0; $try < 2; $try++) {
            if (Operation::update($op_id, $fields, $now)) {
                return true;
            }
            $current = Operation::current();
            if (!Operation::is_running($current) || $current['id'] !== $op_id) {
                return false;
            }
        }
        Operation::finish($op_id, Operation::FAILED, 'error', DriverStep::HINT_ERROR, array('reason' => 'not_persisted'), $now);
        return false;
    }

    /** @return bool */
    private static function scheduler_ready() {
        return ActionSchedulerBridge::is_ready() && function_exists('as_unschedule_all_actions');
    }

    private function __construct() {}
}
