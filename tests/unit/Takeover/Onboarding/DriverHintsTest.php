<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Onboarding\Checks;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverHints;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;

/**
 * 0c final review: the driver's operator hints and its pure cooldown predicates, extracted
 * from DriverStep without a behavior change.
 */
final class DriverHintsTest extends TestCase {
    public function test_the_restart_hint_is_still_published_by_the_driver_step(): void {
        self::assertSame('restart the operation; every step resumes from the saved state', DriverHints::ERROR);
        self::assertSame(DriverHints::ERROR, DriverStep::HINT_ERROR);
    }

    public function test_the_exhausted_hint_names_the_wp_cli_command_of_each_kind(): void {
        self::assertSame('run "wp supcheckout takeover rollback" on the server', DriverHints::exhausted(Operation::KIND_ROLLBACK, false));
        self::assertSame('run "wp supcheckout takeover abort" on the server', DriverHints::exhausted(Operation::KIND_ABORT, false));
        self::assertSame('run "wp supcheckout takeover finalize" on the server', DriverHints::exhausted(Operation::KIND_FINALIZE, false));
        self::assertSame('run "wp supcheckout takeover finalize --force-after-review" on the server', DriverHints::exhausted(Operation::KIND_FINALIZE, true));
        self::assertSame('run "wp supcheckout takeover finalize --dry-run" on the server', DriverHints::exhausted(Operation::KIND_FINALIZE_DRY_RUN, false));
        self::assertSame('run "wp supcheckout takeover run --deactivate-official" on the server', DriverHints::exhausted(Operation::KIND_SWITCH, false));
    }

    public function test_the_stale_claims_hint_names_the_kind_to_start_again(): void {
        self::assertSame('release stale claims (POST /onboarding/stale-claims/release), then start the rollback again', DriverHints::stale_claims(Operation::KIND_ROLLBACK));
    }

    public function test_the_preflight_cooldown_predicates(): void {
        $cooldown = array('code' => 'credential_unverified', 'data' => array('reason' => 'cooldown'));
        $other = array('code' => 'settings_missing', 'data' => array());

        self::assertTrue(DriverHints::only_cooldown(array('blocking' => array($cooldown))));
        self::assertFalse(DriverHints::only_cooldown(array('blocking' => array($cooldown, $other))));
        self::assertFalse(DriverHints::only_cooldown(array('blocking' => array())), 'no finding is not a cooldown');
        self::assertTrue(DriverHints::has_cooldown(array('blocking' => array($other, $cooldown))));
        self::assertFalse(DriverHints::has_cooldown(array('blocking' => array($other))));
        self::assertFalse(DriverHints::has_cooldown(array()));
    }

    public function test_the_check_report_cooldown_predicate(): void {
        $cooldown = Checks::check('credential', Checks::WARN, 'cooldown', 'wait');

        self::assertTrue(DriverHints::check_cooldown(array('checks' => array($cooldown))));
        self::assertFalse(DriverHints::check_cooldown(array('checks' => array(Checks::check('credential', Checks::WARN, 'unverified', 'x')))));
        self::assertFalse(DriverHints::check_cooldown(array('checks' => 'not a list')));
    }
}
