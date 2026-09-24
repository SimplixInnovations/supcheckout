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
        self::assertStringNotContainsString('FLOAT', $source);
        self::assertStringNotContainsString('DOUBLE', $source);
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
        self::assertTrue($claim::has_dispatchable_snapshot(array(
            'expected_amount' => '10.000',
            'expected_currency' => 'KWD',
        )));
    }
}
