<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;

final class CheckoutEconomicsPrecisionGateway {
    public $domain = 'upayments';
    public $paymentData = array('whitelabled' => false);
    public $autoDeduction = 'no';
    public $saveCardEnabled = 'no';
    public $multiMerchant = 'no';
    public $apiKey = 'test-api-key';

    public function getPaymentIcons() {
        return $this->paymentData;
    }

    public function getCurrencyCode($currency) {
        return $currency;
    }

    public function log($message, $level = 'info') {
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CheckoutEconomicsPrecisionTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-payment-runtime.php';
        \supcheckout_test_reset_payment_runtime();
    }

    private function executeWithAmount(string $amount): array {
        $order = new \WC_Order(
            42,
            'KWD',
            $amount,
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 801), 1, '1.000', 'Precision boundary line'))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();

        $orchestrator = new CheckoutOrchestrator(
            new CheckoutEconomicsPrecisionGateway(),
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);
        return array($result, $requests);
    }

    /**
     * Runs one single-line order whose provider Charge is accepted, so the
     * result proves the checkout itself succeeded rather than stopping at the
     * transport stub.
     */
    private function executeAcceptedSingleLineOrder(string $currency, string $line_total, int $quantity): array {
        // The accepted-Charge path records the attempt under the real OrderLock
        // (option-store fake) and re-reads the order's paid state.
        \supcheckout_test_reset_wp_options();
        $order = new class(
            42,
            $currency,
            $line_total,
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 801), $quantity, $line_total, 'Unit price boundary line'))
        ) extends \WC_Order {
            public function is_paid() { return false; }
        };
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();

        $orchestrator = new CheckoutOrchestrator(
            new CheckoutEconomicsPrecisionGateway(),
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                if ($route !== 'charge') {
                    return array();
                }
                return array(
                    'transport_ok' => true,
                    'http_status' => 201,
                    'curl_errno' => 0,
                    'body' => json_encode(array('status' => true, 'data' => array('link' => 'https://sandbox.upayments.com/pay/unit-price'))),
                );
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);
        return array($result, $requests);
    }

    /**
     * Exact unit prices whose canonical token exceeds the provider's
     * 7-character products[].price cap.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function overlongExactUnitPrices(): array {
        return array(
            'AED 10499.99 x1' => array('AED', '10499.99', 1),
            'KWD 1234.567 x1' => array('KWD', '1234.567', 1),
            'KWD 10.000 x128 (unit 0.078125)' => array('KWD', '10.000', 128),
            'KWD 13.125 x8 (unit 1.640625)' => array('KWD', '13.125', 8),
        );
    }

    #[DataProvider('overlongExactUnitPrices')]
    public function test_overlong_exact_unit_price_omits_products_and_still_charges_the_order_total(string $currency, string $line_total, int $quantity): void {
        list($result, $requests) = $this->executeAcceptedSingleLineOrder($currency, $line_total, $quantity);

        self::assertSame('success', $result['result'], 'A descriptive unit price beyond the provider cap must not veto the authoritative order total.');
        self::assertCount(1, $requests, 'Exactly one Charge request.');
        self::assertSame('charge', $requests[0][0]);
        self::assertIsString($requests[0][2]);
        self::assertStringNotContainsString('"products"', $requests[0][2], 'products[] is omitted wholesale.');
        self::assertStringContainsString('"amount":' . $line_total, $requests[0][2], 'order.amount stays exactly the Woo order total.');
    }

    /**
     * Exact unit prices that fit the cap once trailing zeros are stripped.
     *
     * @return array<string, array{string, string, int, string}>
     */
    public static function inCapExactUnitPrices(): array {
        return array(
            'AED 10499.90 x1 (canonical 10499.9)' => array('AED', '10499.90', 1, '10499.9'),
            'KWD 139.200 x1 (canonical 139.2)' => array('KWD', '139.200', 1, '139.2'),
        );
    }

    #[DataProvider('inCapExactUnitPrices')]
    public function test_in_cap_exact_unit_price_keeps_products(string $currency, string $line_total, int $quantity, string $unit_price): void {
        list($result, $requests) = $this->executeAcceptedSingleLineOrder($currency, $line_total, $quantity);

        self::assertSame('success', $result['result']);
        self::assertCount(1, $requests);
        self::assertSame('charge', $requests[0][0]);
        self::assertIsString($requests[0][2]);
        self::assertStringContainsString('"products"', $requests[0][2]);
        self::assertStringContainsString('"price":' . $unit_price . ',', $requests[0][2]);
        self::assertStringContainsString('"amount":' . $line_total, $requests[0][2]);
    }

    public function test_maximum_supported_plain_decimal_amount_reaches_charge_without_float_reformatting(): void {
        $amount = '123456789012345.678901';
        self::assertSame(22, strlen($amount), 'Fixture must exercise the orchestrator maximum amount-token length.');

        list($result, $requests) = $this->executeWithAmount($amount);

        self::assertCount(1, $requests);
        self::assertSame('charge', $requests[0][0]);
        self::assertSame('POST', $requests[0][1]);
        self::assertIsString($requests[0][2]);
        self::assertStringContainsString('"amount":' . $amount, $requests[0][2]);
        self::assertStringNotContainsString('E+', $requests[0][2]);
        self::assertStringNotContainsString('e+', $requests[0][2]);
        self::assertStringNotContainsString('E-', $requests[0][2]);
        self::assertStringNotContainsString('e-', $requests[0][2]);
        self::assertSame('failure', $result['result'], 'Transport stub fails after proving the exact provider-bound request was emitted.');
    }

    public function test_amount_beyond_supported_plain_decimal_length_fails_before_provider_transport(): void {
        $amount = '1234567890123456.678901';
        self::assertSame(23, strlen($amount), 'Fixture must exceed the orchestrator amount-token length by exactly one byte.');

        list($result, $requests) = $this->executeWithAmount($amount);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests, 'Invalid overlong economics must be rejected before any provider call.');
    }
}
