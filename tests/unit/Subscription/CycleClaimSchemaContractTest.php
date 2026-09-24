<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;

/**
 * T6 CycleClaim schema/migration contract pins.
 * Executable SQL behavior is covered by the lifecycle matrix + H12; this file
 * pins the migration contract and fail-closed dispatchable-snapshot rules.
 */
final class CycleClaimSchemaContractTest extends TestCase
{
    public function test_schema_v2_identity_and_snapshot_fields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/includes/Subscription/Cron/CycleClaim.php');
        self::assertIsString($source);
        self::assertStringContainsString("const SCHEMA_VERSION = '2';", $source);
        self::assertStringContainsString('upayments_billing_attempts', $source);
        self::assertStringContainsString('expected_amount', $source);
        self::assertStringContainsString('expected_currency', $source);
        self::assertStringContainsString('function acquire_with_snapshot', $source);
        self::assertStringContainsString('function has_dispatchable_snapshot', $source);
        self::assertStringContainsString('function schema_ready', $source);
        self::assertStringContainsString('function column_exists', $source);
        self::assertStringNotContainsString('FLOAT', $source);
        self::assertStringNotContainsString('DOUBLE', $source);
        self::assertStringNotContainsString('cycle_due_gmt = %s', $source);
    }

    public function test_dispatchable_snapshot_requires_complete_v2_economics(): void
    {
        require_once dirname(__DIR__, 3) . '/src/Subscription/CycleEconomics.php';
        require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/CycleClaim.php';

        $claim = \UPayments\Subscription\Cron\CycleClaim::class;
        self::assertFalse($claim::has_dispatchable_snapshot(array()));
        self::assertFalse($claim::has_dispatchable_snapshot(array(
            'expected_amount' => '',
            'expected_currency' => 'KWD',
        )));
        self::assertFalse($claim::has_dispatchable_snapshot(array(
            'expected_amount' => '10.000',
            'expected_currency' => '',
        )));
        // Historical v1 rows without snapshot must not authorize POST.
        self::assertFalse($claim::has_dispatchable_snapshot(array(
            'state' => 'claimed',
            'cycle_key' => 'abc',
        )));
        self::assertFalse($claim::has_dispatchable_snapshot(array(
            'expected_amount' => '10.000',
            'expected_currency' => 'KWD',
            'cycle_key' => 'abc',
            'parent_order_id' => 7,
            'state' => 'claimed',
            'owner_token' => '',
        )));
        self::assertTrue($claim::has_dispatchable_snapshot(array(
            'expected_amount' => '10.000',
            'expected_currency' => 'KWD',
            'cycle_key' => str_repeat('a', 64),
            'parent_order_id' => 7,
            'state' => 'claimed',
            'owner_token' => 'tok',
        )));
    }

    public function test_dispatchable_snapshot_rejects_malformed_economics(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/CycleClaim.php';

        $claim = \UPayments\Subscription\Cron\CycleClaim::class;
        $base = array(
            'cycle_key' => str_repeat('b', 64),
            'parent_order_id' => 9,
            'state' => 'claimed',
            'owner_token' => 'owner',
            'expected_currency' => 'KWD',
        );

        foreach (array('10.000.0', '1e3', '-1.00', '+1.00', '00.100', ' 10.00', '10.00 ', '10,00', '', '0x10') as $bad) {
            self::assertFalse(
                $claim::has_dispatchable_snapshot($base + array('expected_amount' => $bad)),
                "malformed amount must not authorize POST: {$bad}"
            );
        }

        foreach (array('kwd1', 'K', 'K W D', 'EURO', 'KWD1') as $bad_currency) {
            self::assertFalse(
                $claim::has_dispatchable_snapshot(array_merge($base, array(
                    'expected_amount' => '10.000',
                    'expected_currency' => $bad_currency,
                ))),
                "malformed currency must not authorize POST: {$bad_currency}"
            );
        }

        self::assertTrue($claim::has_dispatchable_snapshot(array_merge($base, array(
            'expected_amount' => '0',
            'expected_currency' => 'KWD',
        ))));
    }

    public function test_journal_validators_match_cycle_economics(): void
    {
        require_once dirname(__DIR__, 3) . '/src/Subscription/CycleEconomics.php';
        require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/CycleClaim.php';

        $claim = \UPayments\Subscription\Cron\CycleClaim::class;
        $econ = \Simplixi\SUPCheckout\Subscription\CycleEconomics::class;

        foreach (array('0', '10', '10.000', '10.0', 7, '0.5') as $ok) {
            self::assertSame(
                $econ::canonical_decimal($ok),
                $claim::canonical_snapshot_amount($ok),
                'amount validators stay consistent'
            );
        }
        foreach (array('1e3', -1, 1.5, '00.1', 'abc') as $bad) {
            self::assertSame(
                $econ::canonical_decimal($bad),
                $claim::canonical_snapshot_amount($bad),
                'amount rejectors stay consistent'
            );
        }
        foreach (array('kwd', 'KWD', ' usd ') as $currency) {
            self::assertSame(
                $econ::canonical_currency($currency),
                $claim::canonical_snapshot_currency($currency),
                'currency validators stay consistent'
            );
        }
    }
}
