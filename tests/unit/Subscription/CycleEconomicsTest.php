<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\CycleEconomics;

final class CycleEconomicsTest extends TestCase
{
    public function test_decimal_canonicalization_matrix(): void
    {
        self::assertSame('10', CycleEconomics::canonical_decimal('10'));
        self::assertSame('10', CycleEconomics::canonical_decimal('10.0'));
        self::assertSame('10', CycleEconomics::canonical_decimal('10.000'));
        self::assertSame('0.001', CycleEconomics::canonical_decimal('0.001'));
        self::assertSame('0', CycleEconomics::canonical_decimal('0'));
        self::assertSame('0', CycleEconomics::canonical_decimal('0.000'));
        self::assertNull(CycleEconomics::canonical_decimal('01'));
        self::assertNull(CycleEconomics::canonical_decimal('-1.00'));
        self::assertNull(CycleEconomics::canonical_decimal('1e3'));
        self::assertNull(CycleEconomics::canonical_decimal(' 1.00'));
        self::assertNull(CycleEconomics::canonical_decimal(''));
        self::assertNull(CycleEconomics::canonical_decimal(10.0));
        self::assertSame('10', CycleEconomics::canonical_decimal(10));
        self::assertNull(CycleEconomics::canonical_decimal(array('10')));
        self::assertSame(str_repeat('9', 22), CycleEconomics::canonical_decimal(str_repeat('9', 22)));
        self::assertNull(CycleEconomics::canonical_decimal(str_repeat('9', 23)));
    }

    public function test_currency_canonicalization_matrix(): void
    {
        self::assertSame('KWD', CycleEconomics::canonical_currency('KWD'));
        self::assertSame('KWD', CycleEconomics::canonical_currency('kwd'));
        self::assertSame('USD', CycleEconomics::canonical_currency(' USD '));
        self::assertNull(CycleEconomics::canonical_currency('US'));
        self::assertNull(CycleEconomics::canonical_currency('USDD'));
        self::assertNull(CycleEconomics::canonical_currency('K W'));
        self::assertNull(CycleEconomics::canonical_currency(''));
    }

    public function test_amount_comparison_is_decimal_not_float(): void
    {
        self::assertTrue(CycleEconomics::amounts_equal('10.000', '10.0'));
        self::assertFalse(CycleEconomics::amounts_equal('10.000', '10.001'));
        self::assertFalse(CycleEconomics::amounts_equal(10.0, '10.000'));
        self::assertTrue(CycleEconomics::amounts_equal('0', '0.000'));
    }

    public function test_zero_amount_is_representable_but_not_positive_renewal_authority(): void
    {
        self::assertSame('0', CycleEconomics::canonical_decimal('0.000'));
        self::assertTrue(CycleEconomics::amounts_equal('0', '0'));
        // Zero-renewal product contract is undocumented; Scheduler treats zero as no-POST.
    }

    public function test_snapshot_from_order_requires_complete_economics(): void
    {
        $ok = new class {
            public function get_total() { return '10.000'; }
            public function get_currency() { return 'KWD'; }
            public function get_meta($k) {
                return $k === '_upay_subscription_plan' ? 'monthly' : '1';
            }
        };
        $snap = CycleEconomics::snapshot_from_order($ok);
        self::assertIsArray($snap);
        self::assertSame('10', $snap['amount']);
        self::assertSame('KWD', $snap['currency']);
        self::assertSame('monthly', $snap['plan']);
        self::assertSame(1, $snap['interval']);

        $bad = new class {
            public function get_total() { return 10.0; }
            public function get_currency() { return 'KWD'; }
            public function get_meta($k) { return 'monthly'; }
        };
        self::assertNull(CycleEconomics::snapshot_from_order($bad));
    }
}
