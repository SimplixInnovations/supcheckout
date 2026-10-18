<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\RollbackSystem;

/**
 * In-memory CutoverSystem: a WP-Cron array, the official plugin's active entry and a
 * clock that sleep() advances. Not final: FakeBudgetedSystem adds a budget to it.
 */
class FakeCutoverSystem implements RollbackSystem {
    /** @var list<string> Installed official plugins (any status). */
    public $installed = array('upayments/UPayments.php');
    /** @var list<string> */
    public $activated = array();
    /** @var bool activate_plugin() leaves the plugin inactive. */
    public $activate_fails = false;
    /** @var int */
    public $now = 1800000000;
    /** @var list<array{timestamp:int, hook:string, args:array<mixed>, schedule:string|false}> */
    public $events = array();
    /** @var string|null */
    public $official = 'upayments/UPayments.php';
    /** @var list<string> */
    public $deactivated = array();
    /** @var int */
    public $sleeps = 0;
    /** @var bool deactivate_plugin() leaves the plugin active. */
    public $deactivate_fails = false;
    /** @var callable|null function (array $event): void, called before an event is scheduled. */
    public $on_schedule = null;
    /** @var callable|null function (): void, called on each sleep. */
    public $on_sleep = null;
    /** @var callable|null function (): void, called on each clock read. */
    public $on_now = null;
    /** @var callable|null function (string $step): void, called when the cutover reaches a step. */
    public $on_step = null;
    /** @var callable|null function (string $basename): void, called after a successful deactivation. */
    public $on_deactivate = null;
    /** @var int Times purge_caches() was called. */
    public $purges = 0;
    /** @var list<string> Every reached() step, plus 'purge' for each purge_caches() call, in order. */
    public $log = array();
    /** @var bool purge_caches() throws. */
    public $purge_throws = false;

    public function cron_events() {
        return $this->events;
    }

    public function schedule_event($timestamp, $hook, array $args, $schedule) {
        $event = array('timestamp' => (int) $timestamp, 'hook' => (string) $hook, 'args' => $args, 'schedule' => $schedule);
        if (is_callable($this->on_schedule)) {
            call_user_func($this->on_schedule, $event);
        }
        $this->events[] = $event;
        return true;
    }

    public function official_plugin() {
        return $this->official;
    }

    public function deactivate_plugin($basename) {
        $this->deactivated[] = (string) $basename;
        if ($this->deactivate_fails) {
            return false;
        }
        $this->official = null;
        if (is_callable($this->on_deactivate)) {
            call_user_func($this->on_deactivate, (string) $basename);
        }
        return true;
    }

    public function now() {
        if (is_callable($this->on_now)) {
            call_user_func($this->on_now);
        }
        return $this->now;
    }

    public function sleep($seconds) {
        ++$this->sleeps;
        $this->now += (int) $seconds;
        if (is_callable($this->on_sleep)) {
            call_user_func($this->on_sleep);
        }
    }

    public function unschedule_event($timestamp, $hook, array $args) {
        $this->events = array_values(array_filter($this->events, static function ($event) use ($timestamp, $hook, $args) {
            return !($event['timestamp'] === (int) $timestamp && $event['hook'] === (string) $hook && $event['args'] === $args);
        }));
        return true;
    }

    public function installed_official_plugins() {
        return $this->installed;
    }

    public function activate_plugin($basename) {
        $this->activated[] = (string) $basename;
        if ($this->activate_fails) {
            return false;
        }
        $this->official = (string) $basename;
        return true;
    }

    public function purge_caches() {
        ++$this->purges;
        $this->log[] = 'purge';
        if ($this->purge_throws) {
            throw new \RuntimeException('purge boom');
        }
        return array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array());
    }

    public function reached($step) {
        $this->log[] = (string) $step;
        if (is_callable($this->on_step)) {
            call_user_func($this->on_step, (string) $step);
        }
    }
}
