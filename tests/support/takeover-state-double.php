<?php
/**
 * Test double for Simplixi\SUPCheckout\Takeover\TakeoverState, loaded only inside
 * #[RunInSeparateProcess] tests BEFORE the real class could autoload.
 *
 * It implements the slice of the Task 3 API that Keys uses:
 *  - the status constants;
 *  - TakeoverState::get(): array{status:string, cut_over_at:int, cursor:int, totals:array};
 *  - TakeoverState::is_cut_over(): bool, true for cut_over and finalized (uncached here,
 *    so a test can move the status mid-test).
 * The status comes from $GLOBALS['supcheckout_test_takeover_status'] (default 'idle').
 * Loading it after the real class is a redeclaration fatal, never a silent fallback.
 */

namespace Simplixi\SUPCheckout\Takeover;

final class TakeoverState {
    public const IDLE = 'idle';
    public const PREFLIGHT_OK = 'preflight_ok';
    public const COPYING = 'copying';
    public const VERIFIED = 'verified';
    public const CUT_OVER = 'cut_over';
    public const FINALIZED = 'finalized';
    public const ROLLED_BACK = 'rolled_back';
    public const INVALID = 'invalid';

    /** @return array{status:string, cut_over_at:int, cursor:int, totals:array<string,int>} */
    public static function get() {
        $status = isset($GLOBALS['supcheckout_test_takeover_status']) ? (string) $GLOBALS['supcheckout_test_takeover_status'] : 'idle';
        return array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array());
    }

    /** @return bool */
    public static function is_cut_over() {
        return in_array(self::get()['status'], array(self::CUT_OVER, self::FINALIZED), true);
    }

    private function __construct() {}
}
