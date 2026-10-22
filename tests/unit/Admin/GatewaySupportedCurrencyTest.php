<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

final class GatewaySupportedCurrencyTest extends TestCase {
    public function test_supported_currency_list_is_exact_and_shareable_with_blocks(): void {
        self::assertSame(
            array('KWD', 'SAR', 'USD', 'BHD', 'EUR', 'OMR', 'QAR', 'AED'),
            GatewaySettings::supported_currencies()
        );
    }

    public function test_every_supported_currency_has_its_iso_4217_minor_units(): void {
        $expected = array('KWD' => 3, 'SAR' => 2, 'USD' => 2, 'BHD' => 3, 'EUR' => 2, 'OMR' => 3, 'QAR' => 2, 'AED' => 2);

        foreach (GatewaySettings::supported_currencies() as $currency) {
            self::assertArrayHasKey($currency, $expected, 'A supported currency without a minor-unit entry in this test.');
            self::assertSame($expected[$currency], GatewaySettings::currency_minor_units($currency), $currency);
        }
        self::assertSame(array_keys($expected), GatewaySettings::supported_currencies());
    }

    public function test_unknown_or_malformed_currency_has_no_minor_units(): void {
        foreach (array('GBP', 'JOD', 'aed', '', null, 2, array('AED')) as $currency) {
            self::assertNull(GatewaySettings::currency_minor_units($currency));
        }
    }
}
