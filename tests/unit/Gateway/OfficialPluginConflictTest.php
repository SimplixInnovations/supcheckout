<?php

namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\OfficialPluginConflict;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * A5-01: the paused notice depends on the takeover state. Before the switch the official
 * plugin really keeps processing payments and the gateway settings really stay in place.
 * On a cut-over or finalized store both claims are false: payments started after the switch
 * call back on the `supcheckout` route, which a paused SUPCheckout does not answer, and
 * settings edited after the switch live only under the SUPCheckout name.
 */
final class OfficialPluginConflictTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_migration_admin();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    /** @return array<string, array{0:string}> */
    public static function switched_states(): array {
        return array(
            'cut_over' => array(TakeoverState::CUT_OVER),
            'finalized' => array(TakeoverState::FINALIZED),
        );
    }

    /** @return array<string, array{0:string|null}> */
    public static function pre_switch_states(): array {
        return array(
            'no record' => array(null),
            'idle' => array(TakeoverState::IDLE),
            'copying' => array(TakeoverState::COPYING),
            'verified' => array(TakeoverState::VERIFIED),
            'rolled_back' => array(TakeoverState::ROLLED_BACK),
        );
    }

    #[DataProvider('pre_switch_states')]
    public function test_a_pre_switch_pause_keeps_the_current_copy(?string $status): void {
        self::state($status);

        $html = self::render();

        self::assertStringContainsString('notice notice-warning', $html);
        self::assertStringContainsString('SUPCheckout is paused.', $html);
        self::assertStringContainsString('UPayments keeps processing payments until you switch.', $html);
        self::assertStringContainsString('your gateway settings stay in place', $html);
    }

    #[DataProvider('switched_states')]
    public function test_a_switched_store_says_it_has_switched_and_that_payments_are_stranded(string $status): void {
        self::state($status);

        $html = self::render();

        self::assertStringContainsString('notice notice-error', $html);
        self::assertStringContainsString('SUPCheckout is paused.', $html);
        self::assertStringContainsString('This store has already switched to SUPCheckout', $html);
        self::assertStringContainsString('Payments started after the switch cannot complete', $html);
        self::assertStringContainsString('Deactivate the UPayments plugin now', $html);
        self::assertStringNotContainsString('keeps processing payments', $html);
        self::assertStringNotContainsString('settings stay in place', $html);
    }

    public function test_a_cut_over_store_points_to_rollback_and_a_finalized_one_does_not(): void {
        self::state(TakeoverState::CUT_OVER);
        self::assertStringContainsString('roll back the switch in SUPCheckout', self::render());

        TakeoverState::flush_cache();
        self::state(TakeoverState::FINALIZED);
        $finalized = self::render();
        self::assertStringNotContainsString('roll back', $finalized, 'a finalized switch cannot be rolled back');
        self::assertStringContainsString('The switch is final', $finalized);
    }

    public function test_users_who_cannot_activate_plugins_see_nothing(): void {
        self::state(TakeoverState::CUT_OVER);
        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array('activate_plugins');

        self::assertSame('', self::render());
    }

    private static function state(?string $status): void {
        if ($status === null) {
            unset($GLOBALS['supcheckout_test_options'][TakeoverState::OPTION]);
            return;
        }
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
    }

    private static function render(): string {
        ob_start();
        OfficialPluginConflict::render_notice();
        return (string) ob_get_clean();
    }
}
