<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Everything Cutover touches outside options, orders and the billing-attempts tables
 * (Sub-project 0b Task 7): WP-Cron, the official plugin's activation, the clock.
 * WordPressCutoverSystem binds it to WordPress; the unit tests bind it to memory.
 */
interface CutoverSystem {
    /**
     * Every scheduled WP-Cron event.
     *
     * @return list<array{timestamp:int, hook:string, args:array<mixed>, schedule:string|false}>
     */
    public function cron_events();

    /**
     * Schedule one event exactly: a single event when $schedule is false, otherwise a
     * recurring one with that recurrence.
     *
     * @param int          $timestamp First run.
     * @param string       $hook      Hook.
     * @param array<mixed> $args      Arguments.
     * @param string|false $schedule  Recurrence, or false.
     * @return bool True when WordPress accepted it.
     */
    public function schedule_event($timestamp, $hook, array $args, $schedule);

    /**
     * @return string|null Basename of the active official plugin (`<dir>/UPayments.php`), or null.
     */
    public function official_plugin();

    /**
     * Deactivate $basename and every other active official install, for this site only.
     *
     * @param string $basename Plugin basename.
     * @return bool True only when no official install is active afterwards.
     */
    public function deactivate_plugin($basename);

    /** @return int Current Unix time. */
    public function now();

    /**
     * @param int $seconds Seconds to wait.
     * @return void
     */
    public function sleep($seconds);

    /**
     * Progress report: the cutover reached $step (WP-CLI prints it; tests observe it).
     *
     * @param string $step Step name.
     * @return void
     */
    public function reached($step);
}
