<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0c Task 10 (Task 9 review): the single-flight record, the rate buckets and the takeover
 * state are read past a stale object cache, so a REST poll or a queue-runner tick sees what
 * another process stored, never the copy this process cached earlier.
 */
final class StaleObjectCacheReadTest extends TestCase {
    use StaleObjectCache;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    public function test_operation_current_reads_the_stored_record_past_a_stale_absent_entry(): void {
        $started = Operation::start(Operation::KIND_PREFLIGHT, array(), 7, 1000);
        self::assertIsArray($started);
        self::plant_stale_cache_entry(Operation::OPTION, null);
        self::assertNull(get_option(Operation::OPTION, null), 'the planted cache entry is stale');

        $current = Operation::current();

        self::assertIsArray($current);
        self::assertSame($started['id'], $current['id']);
        self::assertTrue(Operation::is_running($current));
    }

    public function test_rate_limiter_hit_reads_the_stored_window_past_a_stale_absent_entry(): void {
        self::assertSame(0, RateLimiter::hit('switch', 10, 1000));
        self::plant_stale_cache_entry('supcheckout_onboarding_rate_switch', null);
        self::assertNull(get_option('supcheckout_onboarding_rate_switch', null), 'the planted cache entry is stale');

        self::assertSame(6, RateLimiter::hit('switch', 10, 1004), 'the window stored by the first hit still holds');
    }

    public function test_takeover_state_refresh_reads_the_stored_state_past_a_stale_entry(): void {
        foreach (array(TakeoverState::PREFLIGHT_OK, TakeoverState::COPYING, TakeoverState::VERIFIED) as $to) {
            self::assertTrue(TakeoverState::transition($to));
        }
        $verified = (string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION];
        self::assertTrue(TakeoverState::transition(TakeoverState::CUT_OVER));
        self::plant_stale_cache_entry(TakeoverState::OPTION, $verified);
        TakeoverState::flush_cache();
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status'], 'the planted cache entry is stale');

        TakeoverState::refresh();

        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertTrue(TakeoverState::is_cut_over());
    }
}
