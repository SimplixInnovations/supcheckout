<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;

/**
 * R5-2: historical request-bag normalization (REQUEST-only webhook).
 */
final class CallbackRequestBagNormalizationTest extends TestCase
{
    /** @var string */
    private $lifecycle;

    /** @var string */
    private $gateway;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->lifecycle = (string) file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
        $this->gateway = (string) file_get_contents($root . '/UPayments.php');
    }

    public function test_compat_entrypoint_accepts_explicit_request_bags(): void
    {
        self::assertStringContainsString('function handle_compat_callback', $this->lifecycle);
        self::assertStringContainsString('array $primary', $this->lifecycle);
    }

    public function test_webhook_adapter_uses_request_callback_keys(): void
    {
        $start = strpos($this->gateway, 'function web_hook_handler');
        self::assertNotFalse($start);
        $body = substr($this->gateway, $start, 1800);
        self::assertStringContainsString('$_REQUEST', $body);
        self::assertStringContainsString('wc_order_id', $body);
        self::assertStringContainsString("handle_compat_callback('webhook'", $body);
        self::assertStringNotContainsString("\$_GET['page'] =", $body);
    }

    public function test_browser_adapter_uses_get_bag(): void
    {
        $start = strpos($this->gateway, 'function return_from_upayments');
        self::assertNotFalse($start);
        $body = substr($this->gateway, $start, 1200);
        self::assertStringContainsString("\$_GET", $body);
        self::assertStringContainsString("handle_compat_callback('browser'", $body);
    }

    public function test_no_superglobal_writes(): void
    {
        self::assertStringNotContainsString("\$_GET['page'] =", $this->gateway);
        self::assertStringNotContainsString("\$_REQUEST =", $this->gateway);
        self::assertStringNotContainsString("\$_POST =", $this->gateway);
    }

    public function test_cookie_keys_are_not_forwarded_from_request(): void
    {
        $start = strpos($this->gateway, 'function web_hook_handler');
        $body = substr($this->gateway, $start, 1800);
        self::assertStringNotContainsString('$_COOKIE', $body);
        self::assertStringNotContainsString('$_REQUEST[', $body . 'x'); // only constructed bag keys
    }
}
