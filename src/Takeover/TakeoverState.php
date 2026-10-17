<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Takeover state machine (Sub-project 0b).
 *
 * Task 1 stub: no store has been cut over yet, so every identity read and write
 * stays on its legacy name. Task 3 replaces this with the persisted state machine;
 * is_cut_over() must then stay a cheap single read because Keys::write_key()
 * calls it on every write.
 */
final class TakeoverState {
    /**
     * @return bool True once the store has switched to the SUPCheckout identities.
     */
    public static function is_cut_over() {
        return false;
    }

    private function __construct() {}
}
