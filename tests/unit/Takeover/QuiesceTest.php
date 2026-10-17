<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Quiesce;

/**
 * 0b Task 7: the global takeover lease that quiesces the subscription engine.
 */
final class QuiesceTest extends TestCase {
    private const NOW = 1800000000;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    public function test_one_holder_at_a_time_and_release_reopens(): void {
        self::assertFalse(Quiesce::is_active(self::NOW), 'no lease: nothing is paused');
        $record = Quiesce::acquire(self::NOW);
        self::assertIsString($record);
        self::assertTrue(Quiesce::is_active(self::NOW));
        self::assertTrue(Quiesce::holds($record, self::NOW));
        self::assertNull(Quiesce::acquire(self::NOW), 'a second operator is refused');

        self::assertTrue(Quiesce::release($record));
        self::assertFalse(Quiesce::is_active(self::NOW));
        self::assertFalse(Quiesce::holds($record, self::NOW));
        self::assertIsString(Quiesce::acquire(self::NOW), 'a released lease can be taken again');
    }

    public function test_an_expired_lease_pauses_nothing_and_can_be_taken_over(): void {
        $record = Quiesce::acquire(self::NOW);
        $later = self::NOW + Quiesce::TTL + 1;
        self::assertFalse(Quiesce::is_active($later), 'a holder that died pauses subscriptions for at most TTL');
        self::assertFalse(Quiesce::holds((string) $record, $later));
        self::assertNull(Quiesce::renew((string) $record, $later), 'an expired lease cannot be renewed');
        $next = Quiesce::acquire($later);
        self::assertIsString($next);
        self::assertNotSame($record, $next);
        self::assertFalse(Quiesce::release((string) $record) && Quiesce::holds((string) $next, $later), 'releasing the lost lease leaves the new one');
        self::assertTrue(Quiesce::holds((string) $next, $later));
    }

    public function test_renew_extends_only_the_held_lease(): void {
        $record = (string) Quiesce::acquire(self::NOW);
        $renewed = Quiesce::renew($record, self::NOW + 600);
        self::assertIsString($renewed);
        self::assertTrue(Quiesce::is_active(self::NOW + 600 + Quiesce::TTL - 1));
        self::assertNull(Quiesce::renew($record, self::NOW + 600), 'the superseded record no longer holds');
    }

    public function test_an_unreadable_lease_fails_closed(): void {
        foreach (array('garbage', '123:not-hex', array('x')) as $stored) {
            $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = $stored;
            self::assertTrue(Quiesce::is_active(self::NOW), 'claims stay paused for ' . json_encode($stored));
            self::assertNull(Quiesce::acquire(self::NOW), 'an unreadable lease is never taken over');
        }
    }
}
