<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\CutoverSystem;

/**
 * In-memory CutoverSystem: a WP-Cron array, the official plugin's active entry and a
 * clock that sleep() advances.
 */
final class FakeCutoverSystem implements CutoverSystem {
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
    /** @var callable|null function (array $event): void, called before an event is scheduled. */
    public $on_schedule = null;
    /** @var callable|null function (): void, called on each sleep. */
    public $on_sleep = null;
    /** @var callable|null function (string $step): void, called when the cutover reaches a step. */
    public $on_step = null;

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
        $this->official = null;
        return true;
    }

    public function now() {
        return $this->now;
    }

    public function sleep($seconds) {
        ++$this->sleeps;
        $this->now += (int) $seconds;
        if (is_callable($this->on_sleep)) {
            call_user_func($this->on_sleep);
        }
    }

    public function reached($step) {
        if (is_callable($this->on_step)) {
            call_user_func($this->on_step, (string) $step);
        }
    }
}
