<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

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
            }
        );

        $result = $orchestrator->process(42);
        return array($result, $requests);
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
