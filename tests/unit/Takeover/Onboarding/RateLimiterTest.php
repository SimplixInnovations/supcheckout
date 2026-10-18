<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;

/** 0c Task 4: CAS rate buckets. */
final class RateLimiterTest extends TestCase {
    private const PREFIX = 'supcheckout_onboarding_rate_';

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    public function test_rate_limit_allows_then_waits_then_allows(): void {
        self::assertSame(array(0, 6, 0), array(RateLimiter::hit('switch', 10, 1000), RateLimiter::hit('switch', 10, 1004), RateLimiter::hit('switch', 10, 1010)));
    }

    public function test_a_denied_hit_does_not_extend_the_window(): void {
        RateLimiter::hit('switch', 10, 1000);
        RateLimiter::hit('switch', 10, 1004);
        RateLimiter::hit('switch', 10, 1009);

        self::assertSame('1010', $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch']);
    }

    public function test_rate_buckets_are_independent(): void {
        self::assertSame(array(0, 0, 0), array(RateLimiter::hit('switch', 10, 1000), RateLimiter::hit('abort', 10, 1000), RateLimiter::hit('provider_probe', 65, 1000)));
    }

    public function test_one_option_per_bucket_holding_the_closing_time(): void {
        RateLimiter::hit('provider_probe', RateLimiter::PROBE_WINDOW, 1000);

        self::assertSame(array(self::PREFIX . 'provider_probe' => '1065'), $GLOBALS['supcheckout_test_options']);
    }

    public function test_the_probe_window_equals_the_provider_rate_gate_cooldown(): void {
        $gate = new \ReflectionClassConstant(PaymentMethodAvailability::class, 'RATE_GATE_COOLDOWN');

        self::assertSame($gate->getValue(), RateLimiter::PROBE_WINDOW);
        self::assertSame(10, RateLimiter::WRITE_WINDOW);
    }

    public function test_an_unreadable_value_fails_closed_with_the_full_window(): void {
        $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch'] = 'not-a-time';

        self::assertSame(10, RateLimiter::hit('switch', 10, 1000));
        self::assertSame('not-a-time', $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch']);
    }

    public function test_an_invalid_bucket_name_fails_closed_and_stores_nothing(): void {
        self::assertSame(10, RateLimiter::hit('../x', 10, 1000));
        self::assertSame(10, RateLimiter::hit('', 10, 1000));
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }

    public function test_of_two_racing_first_hits_exactly_one_is_allowed(): void {
        $GLOBALS['wpdb']->before_query = static function ($statement): void {
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0) {
                $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch'] = '1008';
            }
        };

        self::assertSame(8, RateLimiter::hit('switch', 10, 1000));
        self::assertSame('1008', $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch']);
    }

    public function test_of_two_racing_renewals_exactly_one_is_allowed(): void {
        $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch'] = '1000';
        $GLOBALS['wpdb']->before_query = static function ($statement): void {
            if (strpos($statement['query'], 'UPDATE ') === 0) {
                $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch'] = '1015';
            }
        };

        self::assertSame(5, RateLimiter::hit('switch', 10, 1010));
        self::assertSame('1015', $GLOBALS['supcheckout_test_options'][self::PREFIX . 'switch']);
    }
}
