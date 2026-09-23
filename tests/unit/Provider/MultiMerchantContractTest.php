<?php

namespace Simplixi\SUPCheckout\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Provider\MultiMerchantContract;

final class MultiMerchantContractTest extends TestCase {
    public function test_iban_accepts_only_the_runtime_uppercase_structural_contract(): void {
        self::assertTrue(MultiMerchantContract::is_valid_iban('KW81CBKU0000000000001234560101'));
        self::assertTrue(MultiMerchantContract::is_valid_iban('AA00ABCDEFGHIJK'));

        foreach (array(
            'KW01',
            'kw81CBKU0000000000001234560101',
            ' KW81CBKU0000000000001234560101',
            'KW81CBKU0000000000001234560101 ',
            'KW81CBKU00000000000012345601_1',
            null,
            array('KW81CBKU0000000000001234560101'),
        ) as $invalid) {
            self::assertFalse(MultiMerchantContract::is_valid_iban($invalid));
        }
    }

    public function test_commission_lexeme_and_token_contracts_preserve_zero_and_ceiling(): void {
        foreach (array('0', '0.000', '1', '0.5', '1234567890123456789012') as $valid) {
            self::assertTrue(MultiMerchantContract::is_valid_commission_lexeme($valid), $valid);
            self::assertTrue(MultiMerchantContract::is_valid_commission_token($valid), $valid);
        }

        self::assertTrue(MultiMerchantContract::is_valid_commission_lexeme('12345678901234567890123'));
        self::assertFalse(MultiMerchantContract::is_valid_commission_token('12345678901234567890123'));

        foreach (array('1e2', '-1', '+1', '00', '01', '.5', '1.', ' 0.5', '0.5 ', '1,5', '', null, array('1')) as $invalid) {
            self::assertFalse(MultiMerchantContract::is_valid_commission_lexeme($invalid), var_export($invalid, true));
            self::assertFalse(MultiMerchantContract::is_valid_commission_token($invalid), var_export($invalid, true));
        }
    }

    public function test_charge_type_is_an_exact_two_value_allowlist(): void {
        self::assertTrue(MultiMerchantContract::is_valid_charge_type('fixed'));
        self::assertTrue(MultiMerchantContract::is_valid_charge_type('percentage'));

        foreach (array('flat', 'Fixed', 'PERCENTAGE', 'fixed ', ' percentage', '', null, array('fixed')) as $invalid) {
            self::assertFalse(MultiMerchantContract::is_valid_charge_type($invalid));
        }
    }
}
