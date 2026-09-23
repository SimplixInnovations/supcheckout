<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

final class GatewaySettingsDependencyRegressionTest extends TestCase {
    public function test_subscriptions_force_save_card_when_woocommerce_checkbox_is_exact_no(): void {
        $result = GatewaySettings::normalize_dependencies(array(
            'enable_subscriptions' => 'yes',
            'enable_save_card'     => 'no',
        ));

        self::assertTrue($result['forced_save_card']);
        self::assertSame('yes', $result['settings']['enable_save_card']);
    }
}
