<?php

namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\TestCase;

final class BlocksReactiveContractTest extends TestCase {
    private $client_source;
    private $server_source;

    protected function setUp(): void {
        $root = dirname(__DIR__, 3);
        $this->client_source = file_get_contents($root . '/assets/js/upayments-block.js');
        $this->server_source = file_get_contents($root . '/includes/class-wc-gateway-upayments-blocks.php');

        self::assertIsString($this->client_source);
        self::assertIsString($this->server_source);
    }

    public function test_blocks_content_prefers_live_cart_items_from_payment_method_props(): void {
        self::assertStringContainsString(
            'const liveCartItems = props && props.cartData && Array.isArray(props.cartData.cartItems)',
            $this->client_source
        );
        self::assertStringContainsString(
            'Array.isArray(liveCartItems)',
            $this->client_source
        );
        self::assertStringContainsString(
            'liveCartItems.some(product => product && product.type === \'custom_type\')',
            $this->client_source
        );
    }

    public function test_blocks_payment_rows_do_not_repeat_a_server_snapshot_cart_total(): void {
        self::assertStringNotContainsString('cart_total', $this->client_source);
        self::assertStringNotContainsString('currency_display', $this->client_source);
        self::assertStringNotContainsString("'cart_total' =>", $this->server_source);
        self::assertStringNotContainsString("'currency_display' =>", $this->server_source);
    }
}
