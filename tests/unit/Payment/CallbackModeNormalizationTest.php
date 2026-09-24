<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;

/**
 * R5 Option B — explicit-mode compatibility seam (no superglobal spoofing).
 */
final class CallbackModeNormalizationTest extends TestCase
{
    /** @var string */
    private $lifecycle;

    /** @var string */
    private $gateway;

    public static function setUpBeforeClass(): void
    {
        // Source-level contract is asserted without booting WordPress.
    }

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->lifecycle = (string) file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
        $this->gateway = (string) file_get_contents($root . '/UPayments.php');
    }

    public function test_lifecycle_exposes_compat_seam_and_private_mode_body(): void
    {
        self::assertStringContainsString('function handle_compat_callback', $this->lifecycle);
        self::assertStringContainsString('function handle_callback_mode', $this->lifecycle);
        self::assertStringContainsString("array_key_exists('page'", $this->lifecycle);
    }

    public function test_return_from_upayments_is_thin_browser_adapter(): void
    {
        $start = strpos($this->gateway, 'function return_from_upayments');
        self::assertNotFalse($start);
        $body = substr($this->gateway, $start, 2500);
        self::assertStringContainsString("handle_compat_callback('browser'", $body);
        self::assertStringNotContainsString('verify_payment_status(', $body);
        self::assertStringNotContainsString("\$_GET['page'] =", $body);
        self::assertStringNotContainsString('$_GET["page"] =', $body);
    }

    public function test_web_hook_handler_is_thin_webhook_adapter(): void
    {
        $start = strpos($this->gateway, 'function web_hook_handler');
        self::assertNotFalse($start);
        $body = substr($this->gateway, $start, 1500);
        self::assertStringContainsString("handle_compat_callback('webhook'", $body);
        self::assertStringNotContainsString('verify_payment_status(', $body);
        self::assertStringNotContainsString("\$_GET['page'] =", $body);
    }

    public function test_check_ipn_response_keeps_canonical_inference(): void
    {
        $start = strpos($this->gateway, 'function check_ipn_response');
        self::assertNotFalse($start);
        $body = substr($this->gateway, $start, 800);
        self::assertStringContainsString('handle_callback', $body);
        self::assertStringNotContainsString("handle_compat_callback('browser'", $body);
        self::assertStringNotContainsString("handle_compat_callback('webhook'", $body);
    }

    public function test_no_superglobal_spoofing_in_callback_surface(): void
    {
        self::assertStringNotContainsString("\$_GET['page'] =", $this->gateway);
        self::assertStringNotContainsString('$_GET["page"] =', $this->gateway);
        self::assertStringNotContainsString("\$_SERVER['REQUEST_METHOD'] =", $this->gateway);
    }

    public function test_dead_legacy_verifier_is_retired(): void
    {
        self::assertStringNotContainsString('function verify_payment_status', $this->gateway);
        self::assertStringNotContainsString('function get_payment_verification_fallback_url', $this->gateway);
    }
}
