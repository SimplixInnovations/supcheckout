<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\StaleGatewayChoice;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 7: a checkout page rendered before cutover still posts payment_method=upayments.
 * Within the grace window after cutover, and only while our supcheckout gateway is
 * available, exactly that value is re-pointed to supcheckout, on the classic checkout
 * (woocommerce_checkout_posted_data) and the Store API checkout routes. Nothing else is
 * ever translated, and an idle store registers nothing.
 */
final class StaleGatewayChoiceTest extends TestCase {
    /** @var mixed */
    private $wc_before;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_migration_bootstrap();
        TakeoverState::flush_cache();
        $this->wc_before = $GLOBALS['supcheckout_test_subscription_presentation']['wc'] ?? null;
        self::available(array('cod' => (object) array('id' => 'cod'), 'supcheckout' => self::our_gateway()));
        if (!class_exists('WP_REST_Request', false)) {
            eval('class WP_REST_Request implements ArrayAccess {
                private $route; private $method; private $params;
                public function __construct($method, $route, array $params) { $this->method = $method; $this->route = $route; $this->params = $params; }
                public function get_route() { return $this->route; }
                public function get_method() { return $this->method; }
                public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
                public function set_param($key, $value) { $this->params[$key] = $value; }
                #[\ReturnTypeWillChange] public function offsetExists($key) { return isset($this->params[$key]); }
                #[\ReturnTypeWillChange] public function offsetGet($key) { return $this->get_param($key); }
                #[\ReturnTypeWillChange] public function offsetSet($key, $value) { $this->set_param($key, $value); }
                #[\ReturnTypeWillChange] public function offsetUnset($key) { unset($this->params[$key]); }
            }');
        }
    }

    protected function tearDown(): void {
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = $this->wc_before;
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_migration_bootstrap();
        TakeoverState::flush_cache();
    }

    public function test_an_idle_store_registers_nothing_and_translates_nothing(): void {
        StaleGatewayChoice::register();
        self::assertSame(array(), $GLOBALS['supcheckout_test_hook_calls']);
        self::assertSame(array('payment_method' => 'upayments'), StaleGatewayChoice::filter_posted_data(array('payment_method' => 'upayments')));
        $request = new \WP_REST_Request('POST', '/wc/store/v1/checkout', array('payment_method' => 'upayments'));
        self::assertNull(StaleGatewayChoice::filter_rest_request(null, array(), $request));
        self::assertSame('upayments', $request->get_param('payment_method'));
    }

    public function test_a_cut_over_store_registers_both_checkout_paths(): void {
        self::state('cut_over', time());
        StaleGatewayChoice::register();
        $hooks = array_map(static function ($call) {
            return array($call[0], $call[1], $call[3], $call[4]);
        }, $GLOBALS['supcheckout_test_hook_calls']);
        self::assertSame(array(
            array('filter', 'woocommerce_checkout_posted_data', 10, 1),
            array('filter', 'rest_request_before_callbacks', 10, 3),
        ), $hooks);
    }

    public function test_a_stale_upayments_choice_is_repointed_within_the_grace_window(): void {
        self::state('cut_over', time() - StaleGatewayChoice::GRACE_SECONDS + 600);

        self::assertSame(array('payment_method' => 'supcheckout', 'terms' => 1), StaleGatewayChoice::filter_posted_data(array('payment_method' => 'upayments', 'terms' => 1)));
        foreach (array('/wc/store/v1/checkout', '/wc/store/checkout', '/wc/store/v1/checkout/42') as $route) {
            $request = new \WP_REST_Request('POST', $route, array('payment_method' => 'upayments'));
            self::assertSame('kept', StaleGatewayChoice::filter_rest_request('kept', array(), $request));
            self::assertSame('supcheckout', $request['payment_method'], $route);
        }
    }

    public function test_after_the_grace_window_nothing_is_translated(): void {
        self::state('cut_over', time() - StaleGatewayChoice::GRACE_SECONDS - 1);

        self::assertSame(array('payment_method' => 'upayments'), StaleGatewayChoice::filter_posted_data(array('payment_method' => 'upayments')));
        $request = new \WP_REST_Request('POST', '/wc/store/v1/checkout', array('payment_method' => 'upayments'));
        StaleGatewayChoice::filter_rest_request(null, array(), $request);
        self::assertSame('upayments', $request->get_param('payment_method'));
    }

    public function test_only_the_exact_legacy_value_on_a_checkout_route_is_translated(): void {
        self::state('cut_over', time());

        foreach (array('cod', 'UPayments', 'upayments ', '', 'supcheckout') as $value) {
            self::assertSame(array('payment_method' => $value), StaleGatewayChoice::filter_posted_data(array('payment_method' => $value)), var_export($value, true));
        }
        self::assertSame(array('payment_method' => array('upayments')), StaleGatewayChoice::filter_posted_data(array('payment_method' => array('upayments'))));
        self::assertSame(array('billing_email' => 'a@example.test'), StaleGatewayChoice::filter_posted_data(array('billing_email' => 'a@example.test')));
        self::assertSame('not-an-array', StaleGatewayChoice::filter_posted_data('not-an-array'));
        foreach (array(array('POST', '/wc/store/v1/cart'), array('POST', '/wc/v3/orders'), array('GET', '/wc/store/v1/checkout'), array('POST', '/wc/store/v1/checkout-extra')) as $case) {
            $request = new \WP_REST_Request($case[0], $case[1], array('payment_method' => 'upayments'));
            StaleGatewayChoice::filter_rest_request(null, array(), $request);
            self::assertSame('upayments', $request->get_param('payment_method'), implode(' ', $case));
        }
        self::assertSame('kept', StaleGatewayChoice::filter_rest_request('kept', array(), 'not-a-request'));
    }

    public function test_nothing_is_translated_when_our_supcheckout_gateway_is_not_available(): void {
        self::state('cut_over', time());

        foreach (array(array('cod' => (object) array('id' => 'cod')), array('supcheckout' => (object) array('id' => 'supcheckout')), null) as $available) {
            self::available($available);
            self::assertSame(array('payment_method' => 'upayments'), StaleGatewayChoice::filter_posted_data(array('payment_method' => 'upayments')));
            $request = new \WP_REST_Request('POST', '/wc/store/v1/checkout', array('payment_method' => 'upayments'));
            StaleGatewayChoice::filter_rest_request(null, array(), $request);
            self::assertSame('upayments', $request->get_param('payment_method'));
        }
    }

    private static function state(string $status, int $cut_over_at): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => $cut_over_at, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }

    /** @param array<string, object>|null $gateways Available gateways; null: WooCommerce has none loaded. */
    private static function available($gateways): void {
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = $gateways === null ? null : new class($gateways) {
            /** @var array<string, object> */
            private $gateways;
            public function __construct(array $gateways) { $this->gateways = $gateways; }
            public function payment_gateways() {
                $gateways = $this->gateways;
                return new class($gateways) {
                    /** @var array<string, object> */
                    private $gateways;
                    public function __construct(array $gateways) { $this->gateways = $gateways; }
                    public function get_available_payment_gateways() { return $this->gateways; }
                };
            }
        };
    }

    /** @return \Simplixi\SUPCheckout\Gateway\Gateway */
    private static function our_gateway() {
        if (!class_exists('WC_Payment_Gateway', false)) {
            eval('class WC_Payment_Gateway { public $id = ""; }');
        }
        return (new \ReflectionClass(\Simplixi\SUPCheckout\Gateway\Gateway::class))->newInstanceWithoutConstructor();
    }
}
