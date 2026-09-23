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
}
