<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\BudgetedSystem;
use Simplixi\SUPCheckout\Takeover\RollbackSystem;

defined('ABSPATH') || exit;

/**
 * The asynchronous driver's time budget around a RollbackSystem (Sub-project 0c Task 6):
 * one Action Scheduler tick runs until `$deadline`, then Rollback and Finalize yield
 * (BudgetedSystem) and the driver schedules the continuation.
 *
 * Waits: every engine wait is a poll bounded by the real clock (Cutover's and Rollback's
 * claim drains, Finalize's legacy-table drain, the one-second lock retries). A skipped
 * sleep would turn such a poll into a busy loop on the database, so a wait is never just
 * skipped:
 * - a short tick sleeps through the inner system while the sleep fits the budget; a sleep
 *   that does not fit is skipped and spends the budget, so the caller's next budget check
 *   yields (Rollback and Finalize check after every saved unit and before every table-drain
 *   poll; the lock retries are bounded by their attempt counters);
 * - a long tick (`$real_waits`: Cutover, the pre-transition part of Rollback and Finalize,
 *   the Finalize plan) always sleeps for real. Its waits are bounded by the engines
 *   (Cutover::DRAIN_TIMEOUT), the driver raised its time limit, and its dead-man watchdog
 *   fires only after Quiesce::TTL; Cutover cannot yield, so skipping its drain polls would
 *   spin under the lease.
 *
 * reached() only collects the steps (WordPressCutoverSystem would print them under WP-CLI,
 * where `wp action-scheduler run` may run the driver); everything else delegates.
 */
final class BudgetedWordPressSystem implements BudgetedSystem {
    /** @var RollbackSystem */
    private $inner;
    /** @var int */
    private $deadline;
    /** @var callable */
    private $clock;
    /** @var bool */
    private $real_waits;
    /** @var bool A wait did not fit the budget. */
    private $exhausted = false;
    /** @var list<string> */
    private $steps = array();

    /**
     * @param RollbackSystem $inner      The WordPress-bound system.
     * @param int            $deadline   Unix time the tick's budget ends.
     * @param callable       $clock      function (): int, the budget's clock.
     * @param bool           $real_waits A long tick: sleep for real whatever the budget.
     */
    public function __construct(RollbackSystem $inner, $deadline, $clock, $real_waits = false) {
        $this->inner = $inner;
        $this->deadline = (int) $deadline;
        $this->clock = $clock;
        $this->real_waits = (bool) $real_waits;
    }

    public function budget_spent() {
        return $this->exhausted || $this->clock() >= $this->deadline;
    }

    /** @return bool Whether every wait sleeps for real (a long tick). */
    public function real_waits() {
        return $this->real_waits;
    }

    /** @return list<string> Every step reached so far. */
    public function steps() {
        return $this->steps;
    }

    public function sleep($seconds) {
        $seconds = max(0, (int) $seconds);
        if ($this->real_waits || $this->clock() + $seconds <= $this->deadline) {
            $this->inner->sleep($seconds);
            return;
        }
        $this->exhausted = true;
    }

    public function reached($step) {
        $this->steps[] = (string) $step;
    }

    public function cron_events() {
        return $this->inner->cron_events();
    }

    public function schedule_event($timestamp, $hook, array $args, $schedule) {
        return $this->inner->schedule_event($timestamp, $hook, $args, $schedule);
    }

    public function official_plugin() {
        return $this->inner->official_plugin();
    }

    public function deactivate_plugin($basename) {
        return $this->inner->deactivate_plugin($basename);
    }

    public function now() {
        return $this->inner->now();
    }

    public function purge_caches() {
        return $this->inner->purge_caches();
    }

    public function unschedule_event($timestamp, $hook, array $args) {
        return $this->inner->unschedule_event($timestamp, $hook, $args);
    }

    public function installed_official_plugins() {
        return $this->inner->installed_official_plugins();
    }

    public function activate_plugin($basename) {
        return $this->inner->activate_plugin($basename);
    }

    /** @return int */
    private function clock() {
        return (int) call_user_func($this->clock);
    }
}
