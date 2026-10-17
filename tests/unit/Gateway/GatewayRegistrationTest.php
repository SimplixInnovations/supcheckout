<?php
namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\GatewayRegistration;

final class GatewayRegistrationTest extends TestCase {
    public function test_woocommerce_receives_the_namespaced_gateway_class_once(): void {
        $methods = GatewayRegistration::filter_gateways(array('WC_Gateway_COD'));

        self::assertSame(array('WC_Gateway_COD', 'Simplixi\\SUPCheckout\\Gateway\\Gateway'), $methods);
        self::assertSame($methods, GatewayRegistration::filter_gateways($methods));
    }
}
