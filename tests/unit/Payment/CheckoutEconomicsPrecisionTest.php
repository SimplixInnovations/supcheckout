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
    public $ibanNumber = 'KW81CBKU0000000000001234560101';
    public $knetCharge = '1.000';
    public $ccCharge = '1.000';
    public $knetChargeType = 'fixed';
    public $ccChargeType = 'fixed';
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
    private function executeAcceptedSingleLineOrder(string $currency, string $line_total, int $quantity, ?CheckoutEconomicsPrecisionGateway $gateway = null, string $request_body = ''): array {
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
            $gateway !== null ? $gateway : new CheckoutEconomicsPrecisionGateway(),
            static function () use ($request_body) { return $request_body; },
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
        return array($result, $requests, $order);
    }

    /**
     * Amounts whose fraction, trailing zeros stripped, is longer than the
     * currency's ISO 4217 minor units. Card networks settle AED in 2 minor
     * units, so a non-zero third AED decimal is either rejected or rounded by
     * the provider; rounding then fails the strict amount binding and leaves a
     * paid order Pending. Failing closed before the Charge is strictly safer.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function amountsBeyondCurrencyMinorUnits(): array {
        return array(
            'AED 18.045 (2 allowed)' => array('AED', '18.045', 2),
            'KWD 1.5055 (3 allowed)' => array('KWD', '1.5055', 3),
            'USD 10.999 (2 allowed)' => array('USD', '10.999', 2),
        );
    }

    #[DataProvider('amountsBeyondCurrencyMinorUnits')]
    public function test_amount_beyond_currency_minor_units_fails_before_the_charge(string $currency, string $amount, int $allowed): void {
        list($result, $requests, $order) = $this->executeAcceptedSingleLineOrder($currency, $amount, 1);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests, 'No provider call may be made for an amount the currency cannot carry.');
        self::assertSame(
            array(array('Payment request could not be completed. Please try again.', 'error')),
            $GLOBALS['supcheckout_test_subscription_presentation']['notices'],
            'The shopper keeps the generic failure notice.'
        );
        self::assertCount(1, $order->notes, 'Exactly one merchant note.');
        self::assertStringContainsString($currency, $order->notes[0]);
        self::assertStringContainsString((string) $allowed, $order->notes[0]);
    }

    /**
     * Amounts within the currency's minor units, including a trailing zero
     * beyond them. The existing amount encoding is unchanged.
     *
     * @return array<string, array{string, string}>
     */
    public static function amountsWithinCurrencyMinorUnits(): array {
        return array(
            'AED 18.040 (trailing zero)' => array('AED', '18.040'),
            'AED 18.04' => array('AED', '18.04'),
            'KWD 1.505' => array('KWD', '1.505'),
            'USD 10' => array('USD', '10'),
        );
    }

    #[DataProvider('amountsWithinCurrencyMinorUnits')]
    public function test_amount_within_currency_minor_units_still_charges(string $currency, string $amount): void {
        list($result, $requests, $order) = $this->executeAcceptedSingleLineOrder($currency, $amount, 1);

        self::assertSame('success', $result['result']);
        self::assertCount(1, $requests, 'Exactly one Charge request.');
        self::assertSame('charge', $requests[0][0]);
        self::assertIsString($requests[0][2]);
        self::assertMatchesRegularExpression('/"amount":' . preg_quote($amount, '/') . '[,}]/', $requests[0][2], 'The passing amount encoding is unchanged.');
        self::assertSame(array(), $order->notes);
    }

    /**
     * A1-03 residual: an order whose meta reads only what save_meta_data()
     * persisted, and whose total can change between customer retries.
     */
    private function retryableOrder(string $currency, string $total) {
        \supcheckout_test_reset_wp_options();
        $order = new class(
            42,
            $currency,
            $total,
            array(new \WC_Order_Item_Product(new \WC_Product('simple', 801), 1, $total, 'Retry line'))
        ) extends \WC_Order {
            public $retry_total = null;
            public $pending_meta = array();
            public $saved_meta = array();
            public function is_paid() { return false; }
            public function get_total() { return $this->retry_total !== null ? $this->retry_total : parent::get_total(); }
            public function get_meta($key) { return array_key_exists($key, $this->saved_meta) ? $this->saved_meta[$key] : ''; }
            public function update_meta_data($key, $value) { $this->pending_meta[$key] = $value; }
            public function save_meta_data() { $this->saved_meta = array_merge($this->saved_meta, $this->pending_meta); $this->pending_meta = array(); }
        };
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        return $order;
    }

    private function retryCheckout(): array {
        $GLOBALS['supcheckout_test_subscription_presentation']['notices'] = array();
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
        return array($orchestrator->process(42), $requests);
    }

    public function test_a1_03_customer_retries_add_the_minor_unit_note_once_per_currency_and_decimals(): void {
        $order = $this->retryableOrder('AED', '18.045');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            list($result, $requests) = $this->retryCheckout();
            self::assertSame('failure', $result['result'], "Attempt $attempt still fails closed.");
            self::assertSame(array(), $requests, "Attempt $attempt makes no provider call.");
            self::assertSame(
                array(array('Payment request could not be completed. Please try again.', 'error')),
                $GLOBALS['supcheckout_test_subscription_presentation']['notices'],
                "Attempt $attempt shows the shopper the same failure notice."
            );
        }
        self::assertCount(1, $order->notes, 'Three refused retries leave one merchant note.');
        self::assertSame('AED:3', $order->saved_meta['_supcheckout_minor_unit_noted_v1'], 'The flag is persisted through HPOS CRUD.');

        // A different number of decimals (the merchant changed the store setting) is a new fact.
        $order->retry_total = '18.0455';
        $this->retryCheckout();
        $this->retryCheckout();
        self::assertCount(2, $order->notes, 'A new decimals count adds exactly one more note.');
        self::assertSame('AED:4', $order->saved_meta['_supcheckout_minor_unit_noted_v1']);
    }

    public function test_a1_03_an_unsupported_currency_is_noted_once_across_retries(): void {
        $order = $this->retryableOrder('GBP', '10.00');

        $this->retryCheckout();
        list($result, $requests) = $this->retryCheckout();

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests);
        self::assertSame(
            array(array('Payment request could not be completed. Please try again.', 'error')),
            $GLOBALS['supcheckout_test_subscription_presentation']['notices']
        );
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('GBP', $order->notes[0]);
    }

    public function test_unknown_currency_fails_closed_before_the_charge(): void {
        list($result, $requests) = $this->executeAcceptedSingleLineOrder('GBP', '10.00', 1);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests, 'A currency without a known minor-unit count must not reach the provider.');
    }

    public function test_store_api_checkout_applies_the_same_minor_unit_guard(): void {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
        $body = (string) json_encode(array('extensions' => array()));

        list($rejected, $rejected_requests, $order) = $this->executeAcceptedSingleLineOrder('AED', '18.045', 1, null, $body);
        self::assertSame('failure', $rejected['result']);
        self::assertSame(array(), $rejected_requests);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('AED', $order->notes[0]);

        list($accepted, $accepted_requests) = $this->executeAcceptedSingleLineOrder('AED', '18.040', 1, null, $body);
        self::assertSame('success', $accepted['result']);
        self::assertCount(1, $accepted_requests);
        self::assertMatchesRegularExpression('/"amount":18\.040[,}]/', (string) $accepted_requests[0][2]);
    }

    public function test_multi_merchant_amount_is_held_to_the_same_minor_unit_guard(): void {
        $gateway = new CheckoutEconomicsPrecisionGateway();
        $gateway->multiMerchant = 'yes';

        list($rejected, $rejected_requests, $order) = $this->executeAcceptedSingleLineOrder('AED', '18.045', 1, $gateway);
        self::assertSame('failure', $rejected['result']);
        self::assertSame(array(), $rejected_requests);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('AED', $order->notes[0]);

        list($accepted, $accepted_requests) = $this->executeAcceptedSingleLineOrder('AED', '18.040', 1, $gateway);
        self::assertSame('success', $accepted['result']);
        self::assertCount(1, $accepted_requests);
        $payload = json_decode((string) $accepted_requests[0][2], true);
        self::assertIsArray($payload);
        self::assertMatchesRegularExpression('/"amount":18\.040[,}]/', (string) $accepted_requests[0][2]);
        self::assertSame(18.04, $payload['extraMerchantData'][0]['amount']);
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
        // KWD carries 3 minor units, so the longest exact amount the minor-unit
        // guard lets through has a 3-digit fraction. It still fills the
        // 22-character amount-token ceiling.
        $amount = '123456789012345678.901';
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
