<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\ProviderErrorSummary;

final class ProviderErrorSummaryTest extends TestCase {
    protected function setUp(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/');
        }
        require_once dirname(__DIR__, 3) . '/src/Payment/ProviderErrorSummary.php';
    }

    public function test_provider_message_and_field_errors_are_summarized(): void {
        $body = json_encode(array(
            'status' => false,
            'message' => 'The given data was invalid.',
            'errors' => array('paymentGateway.src' => array('The payment gateway src field is required.')),
        ));

        self::assertSame(
            'The given data was invalid.; paymentGateway.src: The payment gateway src field is required.',
            ProviderErrorSummary::detail($body)
        );
    }

    public function test_secrets_urls_and_emails_never_survive(): void {
        $body = json_encode(array(
            'message' => 'Unauthorized Bearer abcDEF123456789xyz for https://api.upayments.com/api/v1/charge?x=1 contact ops@example.test token 0123456789abcdef0123456789abcdef',
        ));

        $detail = ProviderErrorSummary::detail($body);

        self::assertStringNotContainsString('abcDEF123456789xyz', $detail);
        self::assertStringNotContainsString('api.upayments.com', $detail);
        self::assertStringNotContainsString('ops@example.test', $detail);
        self::assertStringNotContainsString('0123456789abcdef0123456789abcdef', $detail);
        self::assertStringContainsString('[redacted]', $detail);
        self::assertStringContainsString('[url]', $detail);
        self::assertStringContainsString('[email]', $detail);
    }

    public function test_markup_control_characters_and_length_are_bounded(): void {
        $body = json_encode(array('message' => "<script>alert(1)</script>Bad\x00\x07 request " . str_repeat('x ', 400)));

        $detail = ProviderErrorSummary::detail($body);

        self::assertStringNotContainsString('<script>', $detail);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $detail);
        self::assertLessThanOrEqual(241, mb_strlen($detail, 'UTF-8'));
        self::assertStringEndsWith('…', $detail);
    }

    public function test_non_json_or_reasonless_bodies_yield_no_detail(): void {
        self::assertSame('', ProviderErrorSummary::detail('<html>502 Bad Gateway</html>'));
        self::assertSame('', ProviderErrorSummary::detail(''));
        self::assertSame('', ProviderErrorSummary::detail(json_encode(array('status' => false))));
    }

    public function test_summary_never_depends_on_the_optional_mbstring_extension(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Payment/ProviderErrorSummary.php');

        self::assertDoesNotMatchRegularExpression('/\bmb_[a-z_]+\s*\(/', $source);
    }

    public function test_arabic_provider_text_is_preserved(): void {
        $body = json_encode(array('message' => 'رقم الهاتف غير صحيح'));

        self::assertSame('رقم الهاتف غير صحيح', ProviderErrorSummary::detail($body));
    }
}
