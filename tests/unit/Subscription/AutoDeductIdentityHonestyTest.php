<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\CycleEconomics;

/**
 * Identity-binding honesty: remote cycle binding is UNPROVEN for auto-deduct
 * until first-party echo contract exists. Local expected snapshot validation
 * must not be mislabeled as remote parent/cycle binding.
 */
final class AutoDeductIdentityHonestyTest extends TestCase
{
    private function expected(): array {
        return array(
            'amount' => '10.000',
            'currency' => 'KWD',
            'parent_id' => 42,
            'cycle_key' => 'cycle-key',
            'reference_id' => 'parent-ref',
        );
    }

    public function test_truthy_success_is_never_verified_without_capture_contract(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data' => array(
                    'transaction' => array(
                        'paymentId' => 'pay-1',
                        'orderId' => '99',
                        'paid_amount' => '10.000',
                        'paid_currency' => 'KWD',
                        'reference' => 'parent-ref',
                    ),
                ),
            ),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::UNRESOLVED, $result['outcome']);
        self::assertNotSame(AutoDeductResultVerifier::VERIFIED_SUCCESS, $result['outcome']);
    }

    public function test_parent_ref_echo_is_not_documented_as_exact_cycle_binding(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Subscription/AutoDeductResultVerifier.php');
        self::assertStringContainsString('not proof of exact', strtolower($source));
        self::assertStringContainsString('Expected local snapshot validation', $source);
        self::assertStringNotContainsString('Parent/cycle identity binding when provided', $source);
    }

    public function test_provider_order_id_is_not_fabricated_from_payment_id(): void
    {
        $scheduler = file_get_contents(dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php');
        self::assertStringContainsString('is not fabricated', $scheduler);
        self::assertStringNotContainsString("orderId'] + 1", $scheduler);
        self::assertStringNotContainsString('$provider_order_identity = $payment_id', $scheduler);
    }
}
