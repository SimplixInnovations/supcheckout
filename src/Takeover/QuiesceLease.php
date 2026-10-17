<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * One holder's Quiesce lease, renewed on the wall clock (Sub-project 0b Task 10).
 *
 * The same rules as Cutover's own lease handling: renew every Cutover::RENEW_INTERVAL
 * seconds from inside every batch loop (well inside Quiesce::TTL), and check holds() right
 * before a compare-and-swap transition. A lost lease stays lost.
 */
final class QuiesceLease {
    /** @var CutoverSystem */
    private $system;
    /** @var string|null */
    private $record;
    /** @var int */
    private $renewed_at;

    /**
     * @param CutoverSystem $system Clock.
     * @param string        $record Lease record.
     * @param int           $now    When it was taken.
     */
    private function __construct(CutoverSystem $system, $record, $now) {
        $this->system = $system;
        $this->record = $record;
        $this->renewed_at = $now;
    }

    /**
     * @param CutoverSystem $system Clock.
     * @return self|null Null when another holder has the lease.
     */
    public static function acquire(CutoverSystem $system) {
        $now = $system->now();
        $record = Quiesce::acquire($now);
        return $record === null ? null : new self($system, $record, $now);
    }

    /** @return bool True while the lease is still ours (now renewed). */
    public function renew() {
        $now = $this->system->now();
        $this->record = $this->record === null ? null : Quiesce::renew($this->record, $now);
        $this->renewed_at = $now;
        return $this->record !== null;
    }

    /** @return bool False once the lease is lost; renews every Cutover::RENEW_INTERVAL seconds. */
    public function maybe_renew() {
        if ($this->record === null) {
            return false;
        }
        if ($this->system->now() - $this->renewed_at < Cutover::RENEW_INTERVAL) {
            return true;
        }
        return $this->renew();
    }

    /** @return bool True while the stored lease is still ours and unexpired (no write). */
    public function holds() {
        if ($this->record === null || !Quiesce::holds($this->record, $this->system->now())) {
            $this->record = null;
            return false;
        }
        return true;
    }

    /** @return bool */
    public function is_lost() {
        return $this->record === null;
    }

    /** @return void */
    public function release() {
        Quiesce::release($this->record);
        $this->record = null;
    }
}
