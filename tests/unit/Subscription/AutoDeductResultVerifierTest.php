<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\CycleEconomics;

final class AutoDeductResultVerifierTest extends TestCase
{
    private function expected(array $overrides = array()): array {
        return array_merge(array(
            'amount'       => '10.000',
            'currency'     => 'KWD',
            'parent_id'    => 42,
            'cycle_key'    => 'cycle-key',
            'reference_id' => 'merchant-42',
        ), $overrides);
    }

    public function test_truthy_status_alone_is_not_verified_success(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data'   => array(
                    'transaction' => array(
                        'paymentId'    => 'pay-1',
                        'paid_amount'  => '10.000',
                        'paid_currency'=> 'KWD',
                        'reference'    => 'merchant-42',
                    ),
                ),
            ),
            $this->expected()
        );

        self::assertSame(AutoDeductResultVerifier::UNRESOLVED, $result['outcome']);
        self::assertSame('capture_authority_unproven_for_auto_deduct', $result['reason']);
        self::assertNotSame(AutoDeductResultVerifier::VERIFIED_SUCCESS, $result['outcome']);
    }

    public function test_amount_mismatch_is_binding_mismatch(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data'   => array(
                    'transaction' => array(
                        'paymentId'    => 'pay-1',
                        'paid_amount'  => '10.001',
                        'paid_currency'=> 'KWD',
                    ),
                ),
            ),
            $this->expected()
        );

        self::assertSame(AutoDeductResultVerifier::BINDING_MISMATCH, $result['outcome']);
        self::assertSame('amount', $result['reason']);
    }

    public function test_currency_case_difference_binds_but_wrong_currency_mismatches(): void
    {
        $ok = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data'   => array(
                    'transaction' => array(
                        'paymentId'    => 'pay-1',
                        'paid_amount'  => '10.000',
                        'paid_currency'=> 'kwd',
                    ),
                ),
            ),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::UNRESOLVED, $ok['outcome']);

        $bad = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data'   => array(
                    'transaction' => array(
                        'paymentId'    => 'pay-1',
                        'paid_amount'  => '10.000',
                        'paid_currency'=> 'USD',
                    ),
                ),
            ),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::BINDING_MISMATCH, $bad['outcome']);
        self::assertSame('currency', $bad['reason']);
    }

    public function test_provider_status_false_is_definitive_failure(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array('status' => false),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::DEFINITIVE_FAILURE, $result['outcome']);
    }

    public function test_malformed_transaction_is_malformed(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array('status' => true, 'data' => array()),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::MALFORMED, $result['outcome']);
    }

    public function test_reference_mismatch_is_binding_mismatch(): void
    {
        $result = AutoDeductResultVerifier::verify(
            array(
                'status' => true,
                'data'   => array(
                    'transaction' => array(
                        'paymentId'    => 'pay-1',
                        'paid_amount'  => '10.000',
                        'paid_currency'=> 'KWD',
                        'reference'    => 'other-ref',
                    ),
                ),
            ),
            $this->expected()
        );
        self::assertSame(AutoDeductResultVerifier::BINDING_MISMATCH, $result['outcome']);
        self::assertSame('reference', $result['reason']);
    }

    public function test_decimal_amounts_compare_without_float_authority(): void
    {
        self::assertTrue(CycleEconomics::amounts_equal('10.000', '10.0'));
        self::assertFalse(CycleEconomics::amounts_equal('10.000', '10.001'));
        self::assertFalse(CycleEconomics::amounts_equal(10.0, '10.000'));
        self::assertTrue(CycleEconomics::amounts_equal('0', '0.000'));
    }
}
