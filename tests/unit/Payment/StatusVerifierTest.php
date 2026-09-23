<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\ProviderResult;
use Simplixi\SUPCheckout\Payment\StatusVerifier;

final class StatusVerifierGateway {
    public $apiKey = 'test-api-key-secret';
    public $test_mode = true;
    public $scheme = 'https';
    public $userinfo = '';
    public $host = 'sandboxapi.upayments.com';
    public $port = '';
    public $path_prefix = '/api/v1/';
    public $url_suffix = '';

    public function getMode() {
        return $this->test_mode;
    }

    public function getAPIUrl($route = '') {
        return $this->scheme . '://' . $this->userinfo . $this->host . $this->port
            . $this->path_prefix . $route . $this->url_suffix;
    }

    public function getCurrencyCode($currency) {
        return strtoupper((string) $currency);
    }
}

final class StatusVerifierOrder {
    private $id;
    private $requested_order_id;

    public $currency = 'KWD';
    public $total = '10.000';

    public function __construct($id, $requested_order_id) {
        $this->id = (int) $id;
        $this->requested_order_id = (string) $requested_order_id;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_currency() {
        return $this->currency;
    }

    public function get_total() {
        return $this->total;
    }

    public function get_meta($key) {
        return $key === 'UPayments_order_id' ? $this->requested_order_id : '';
    }
}

final class StatusVerifierTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_wp_http();
    }

    public function test_invalid_boundaries_fail_before_rate_or_http_mutation(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');

        self::assertSame('invalid_request', StatusVerifier::verify(null, $order, 'track-abc')['reason']);
        self::assertSame('invalid_request', StatusVerifier::verify($gateway, null, 'track-abc')['reason']);
        self::assertSame('invalid_track_id', StatusVerifier::verify($gateway, $order, "bad\ntrack")['reason']);
        $gateway->apiKey = '';
        self::assertSame('credentials_missing', StatusVerifier::verify($gateway, $order, 'track-abc')['reason']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_http_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }

    public function test_disallowed_destination_is_rejected_before_bearer_or_rate_slot(): void {
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $invalid_destinations = array(
            'plaintext scheme' => array('scheme', 'http'),
            'user info'        => array('userinfo', 'user@'),
            'password info'    => array('userinfo', 'user:password@'),
            'explicit port'    => array('port', ':8443'),
            'foreign host'     => array('host', 'attacker.example'),
            'query string'     => array('url_suffix', '?redirect=attacker.example'),
            'fragment'         => array('url_suffix', '#fragment'),
            'wrong path'       => array('path_prefix', '/api/v2/'),
        );

        foreach ($invalid_destinations as $label => $case) {
            $gateway = new StatusVerifierGateway();
            $gateway->{$case[0]} = $case[1];
            $this->assert_destination_rejected_before_mutation($gateway, $order, $label);
        }
    }

    public function test_hardened_transport_binds_an_exact_captured_transaction(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $this->respond_with_transaction($this->transaction($order));

        $result = StatusVerifier::verify($gateway, $order, 'track-abc');

        self::assertTrue($result['authenticated']);
        self::assertTrue($result['bound']);
        self::assertSame(ProviderResult::CAPTURED, $result['classification']);
        self::assertSame('captured', $result['reason']);
        self::assertCount(1, $GLOBALS['supcheckout_test_http_calls']);
        $call = $GLOBALS['supcheckout_test_http_calls'][0];
        self::assertSame('https://sandboxapi.upayments.com/api/v1/get-payment-status/track-abc', $call['url']);
        self::assertSame(15, $call['args']['timeout']);
        self::assertSame(1048576, $call['args']['limit_response_size']);
        self::assertSame(0, $call['args']['redirection']);
        self::assertTrue($call['args']['sslverify']);
        self::assertSame('application/json', $call['args']['headers']['Accept']);
        self::assertSame('Bearer test-api-key-secret', $call['args']['headers']['Authorization']);
        self::assertStringNotContainsString('test-api-key-secret', implode('|', array_keys($GLOBALS['supcheckout_test_options'])));
    }

    public function test_live_status_host_uses_the_same_exact_authenticated_contract(): void {
        $gateway = new StatusVerifierGateway();
        $gateway->test_mode = false;
        $gateway->host = 'apiv2api.upayments.com';
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $this->respond_with_transaction($this->transaction($order));

        $result = StatusVerifier::verify($gateway, $order, 'track-abc');

        self::assertTrue($result['authenticated']);
        self::assertTrue($result['bound']);
        self::assertSame(ProviderResult::CAPTURED, $result['classification']);
        self::assertCount(1, $GLOBALS['supcheckout_test_http_calls']);
        self::assertSame(
            'https://apiv2api.upayments.com/api/v1/get-payment-status/track-abc',
            $GLOBALS['supcheckout_test_http_calls'][0]['url']
        );
        self::assertSame(
            'Bearer test-api-key-secret',
            $GLOBALS['supcheckout_test_http_calls'][0]['args']['headers']['Authorization']
        );
    }

    public function test_network_and_protocol_failures_remain_unauthenticated(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');

        $GLOBALS['supcheckout_test_http_response'] = new \SUPCheckout_Test_WP_Error();
        $this->assert_unauthenticated_failure(
            'network_error',
            StatusVerifier::verify($gateway, $order, 'track-network')
        );

        $this->reset_fixtures();
        $GLOBALS['supcheckout_test_http_response'] = array('response' => array('code' => 200), 'body' => '{}');
        $this->assert_unauthenticated_failure(
            'unexpected_http_200',
            StatusVerifier::verify($gateway, $order, 'track-http')
        );

        $this->reset_fixtures();
        $GLOBALS['supcheckout_test_http_response'] = array('response' => array('code' => 201), 'body' => '');
        $this->assert_unauthenticated_failure(
            'empty_response',
            StatusVerifier::verify($gateway, $order, 'track-empty')
        );

        $this->reset_fixtures();
        $GLOBALS['supcheckout_test_http_response'] = array(
            'response' => array('code' => 201),
            'body'     => str_repeat('x', 1048576),
        );
        $this->assert_unauthenticated_failure(
            'invalid_status_response',
            StatusVerifier::verify($gateway, $order, 'track-oversized')
        );

        $this->reset_fixtures();
        $GLOBALS['supcheckout_test_http_response'] = array('response' => array('code' => 201), 'body' => '{bad-json');
        $this->assert_unauthenticated_failure(
            'invalid_status_response',
            StatusVerifier::verify($gateway, $order, 'track-json')
        );

        $this->reset_fixtures();
        $GLOBALS['supcheckout_test_http_response'] = array(
            'response' => array('code' => 201),
            'body'     => json_encode(array('status' => false, 'data' => array())),
        );
        $this->assert_unauthenticated_failure(
            'invalid_status_response',
            StatusVerifier::verify($gateway, $order, 'track-status')
        );
    }

    public function test_binding_rejects_identity_currency_and_amount_mismatches(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $base = $this->transaction($order);
        $variants = array(
            'track_id'                    => array('different', 'binding_track_id'),
            'merchant_requested_order_id' => array('different', 'binding_merchant_requested_order_id'),
            'reference'                   => array('99', 'binding_reference'),
            'currency_type'               => array('USD', 'binding_currency'),
            'total_price'                 => array('9.000', 'binding_amount'),
        );

        foreach ($variants as $field => $case) {
            $transaction = $base;
            $transaction[$field] = $case[0];
            $result = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $transaction);
            self::assertTrue($result['authenticated'], $field);
            self::assertFalse($result['bound'], $field);
            self::assertSame($case[1], $result['reason'], $field);
        }
    }

    public function test_dispatched_capture_is_rejected_if_authoritative_order_amount_changes_before_binding(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $dispatched_transaction = $this->transaction($order);

        // The provider result reflects the originally dispatched 10.000 amount,
        // but Woo's authoritative economics changed before callback reconciliation.
        $order->total = '12.000';
        $result = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $dispatched_transaction);

        self::assertTrue($result['authenticated']);
        self::assertFalse($result['bound']);
        self::assertSame('binding_amount', $result['reason']);
        self::assertNotSame(ProviderResult::CAPTURED, $result['classification']);
    }

    public function test_capture_requires_payment_id_while_nonterminal_results_bind_fail_closed(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');

        $captured = $this->transaction($order);
        unset($captured['payment_id']);
        self::assertSame(
            'captured_payment_id_missing',
            StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $captured)['reason']
        );

        foreach (array('PENDING', null, 'Processing') as $provider_result) {
            $transaction = $this->transaction($order, $provider_result);
            unset($transaction['payment_id']);
            $result = StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $transaction);
            self::assertTrue($result['authenticated']);
            self::assertTrue($result['bound']);
            self::assertNotSame(ProviderResult::CAPTURED, $result['classification']);
        }
    }

    public function test_exact_decimal_binding_accepts_only_numeric_equality(): void {
        $gateway = new StatusVerifierGateway();
        $order = new StatusVerifierOrder(42, 'merchant-42');
        $order->total = '10.00';

        $trailing = $this->transaction($order);
        $trailing['total_price'] = '10.0000';
        self::assertTrue(StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $trailing)['bound']);

        $fraction = $this->transaction($order);
        $fraction['total_price'] = '10.004';
        self::assertSame(
            'binding_amount',
            StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $fraction)['reason']
        );

        $exponent = $this->transaction($order);
        $exponent['total_price'] = '1e1';
        self::assertSame(
            'amount_invalid',
            StatusVerifier::bind_transaction($gateway, $order, 'track-abc', $exponent)['reason']
        );
    }

    private function reset_fixtures(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_wp_http();
    }

    private function assert_unauthenticated_failure($reason, array $result): void {
        self::assertSame($reason, $result['reason']);
        self::assertFalse($result['authenticated']);
        self::assertFalse($result['bound']);
    }

    private function assert_destination_rejected_before_mutation(
        StatusVerifierGateway $gateway,
        StatusVerifierOrder $order,
        $label
    ): void {
        $result = StatusVerifier::verify($gateway, $order, 'track-abc');
        self::assertSame('status_url_invalid', $result['reason'], $label);
        self::assertFalse($result['authenticated'], $label);
        self::assertFalse($result['bound'], $label);
        self::assertSame(array(), $GLOBALS['supcheckout_test_http_calls'], $label);
        self::assertSame(array(), $GLOBALS['supcheckout_test_options'], $label);
    }

    private function transaction(StatusVerifierOrder $order, $result = 'CAPTURED'): array {
        return array(
            'result'                      => $result,
            'track_id'                    => 'track-abc',
            'merchant_requested_order_id' => $order->get_meta('UPayments_order_id'),
            'total_price'                 => $order->get_total(),
            'currency_type'               => $order->get_currency(),
            'reference'                   => (string) $order->get_id(),
            'payment_id'                  => 'payment-123',
        );
    }

    private function respond_with_transaction(array $transaction): void {
        $GLOBALS['supcheckout_test_http_response'] = array(
            'response' => array('code' => 201),
            'body'     => json_encode(array(
                'status' => true,
                'data'   => array('transaction' => $transaction),
            )),
        );
    }
}
