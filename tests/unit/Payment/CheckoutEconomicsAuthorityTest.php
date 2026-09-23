<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;

final class CheckoutEconomicsAuthorityGateway {
    public $domain = 'upayments';
    public $paymentData = array('whitelabled' => false);
    public $paymentIconCalls = 0;
    public $autoDeduction = 'no';
    public $saveCardEnabled = 'no';
    public $multiMerchant = 'no';
    public $ibanNumber = '';
    public $knetCharge = '1.000';
    public $ccCharge = '1.000';
    public $knetChargeType = 'fixed';
    public $ccChargeType = 'fixed';
    public $apiKey = 'test-api-key';
    public $logs = array();

    public function getPaymentIcons() {
        $this->paymentIconCalls++;
        return $this->paymentData;
    }

    public function getCurrencyCode($currency) {
        return $currency;
    }

    public function log($message, $level = 'info') {
        $this->logs[] = array($level, $message);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CheckoutEconomicsAuthorityTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-payment-runtime.php';
        \supcheckout_test_reset_payment_runtime();
    }

    private function captureCharge(\WC_Order $order): array {
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutEconomicsAuthorityGateway(),
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                return array();
            }
        );

        $orchestrator->process(42);
        return $requests;
    }

    private function assertChargeAmount(array $requests, string $amount, string $currency = 'KWD'): array {
        self::assertCount(1, $requests, 'Descriptive product serialization must not suppress a valid Charge request.');
        self::assertSame('charge', $requests[0][0]);
        self::assertSame('POST', $requests[0][1]);
        self::assertIsString($requests[0][2]);
        self::assertMatchesRegularExpression(
            '/"order":\{[^}]*"currency":"' . preg_quote($currency, '/') . '"[^}]*"amount":' . preg_quote($amount, '/') . '(?:[,}])/',
            $requests[0][2],
            'Finalized Woo order total must retain its exact provider-bound decimal lexeme.'
        );

        $payload = json_decode($requests[0][2], true);
        self::assertIsArray($payload);
        self::assertSame($currency, $payload['order']['currency'], 'Finalized Woo order currency remains payment authority.');
        return $payload;
    }

    public function test_non_divisible_descriptive_line_does_not_block_authoritative_order_charge(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 501), 3, '10.000', 'Three-for-ten extension bundle'))
        ));

        $payload = $this->assertChargeAmount($requests, '10.000');
        self::assertArrayNotHasKey(
            'products',
            $payload,
            'Unrepresentable descriptive line economics must be omitted rather than rounded or made payment-authoritative.'
        );
    }

    public function test_float_line_total_degrades_products_without_blocking_charge(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 502), 1, 10.0, 'Float-returning extension line'))
        ));

        $payload = $this->assertChargeAmount($requests, '10.000');
        self::assertArrayNotHasKey('products', $payload, 'Float descriptor economics are omitted rather than trusted or payment-authoritative.');
    }

    public function test_non_integer_quantity_degrades_products_without_blocking_charge(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 503), 1.5, '10.000', 'Measured quantity line'))
        ));

        $payload = $this->assertChargeAmount($requests, '10.000');
        self::assertArrayNotHasKey('products', $payload, 'Provider-incompatible descriptive quantity must not veto finalized Woo order economics.');
    }

    public function test_one_unrepresentable_line_omits_products_wholesale(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '20.000',
            array(
                new \WC_Order_Item_Product(new \WC_Product('simple', 504), 1, '10.000', 'Representable line'),
                new \WC_Order_Item_Product(new \WC_Product('simple', 505), 3, '10.000', 'Non-divisible line'),
            )
        ));

        $payload = $this->assertChargeAmount($requests, '20.000');
        self::assertArrayNotHasKey('products', $payload, 'A partial product ledger must never be sent after one line becomes unrepresentable.');
    }

    public function test_order_grand_total_is_not_rederived_from_product_lines(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '12.345',
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 506), 1, '10.000', 'Taxed or shipped order line'))
        ));

        $payload = $this->assertChargeAmount($requests, '12.345');
        self::assertArrayHasKey('products', $payload, 'Exactly representable descriptors may remain even when adjustments change the Woo grand total.');
    }

    public function test_zero_price_promotional_line_does_not_replace_positive_order_total(): void {
        $requests = $this->captureCharge(new \WC_Order(
            42,
            'KWD',
            '2.500',
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 507), 1, '0.000', 'Free promotion with positive shipping or tax'))
        ));

        $this->assertChargeAmount($requests, '2.500');
    }
}
