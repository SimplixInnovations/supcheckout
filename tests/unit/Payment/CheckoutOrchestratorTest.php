<?php

namespace Simplixi\SUPCheckout\Payment;

function time() {
    return isset($GLOBALS['supcheckout_test_payment_runtime_time'])
        ? (int) $GLOBALS['supcheckout_test_payment_runtime_time']
        : \time();
}

function wp_generate_uuid4() {
    $next = isset($GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'])
        ? (int) $GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'] + 1
        : 1;
    $GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'] = $next;

    return sprintf('00000000-0000-4000-8000-%012x', $next);
}

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;
use Simplixi\SUPCheckout\Payment\StatusVerifier;

final class CheckoutOrchestratorGateway {
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
final class CheckoutOrchestratorTest extends TestCase {
    /**
     * Order double with real meta storage, so a "fresh" database read can differ
     * from the stale object a request loaded at its start. Declared at runtime because
     * the WC_Order test double loads in setUp().
     */
    private function stateful_order(array $meta, $paid = false): \WC_Order {
        $order = new class(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple')))) extends \WC_Order {
            public $meta = array();
            public $paid = false;
            public function get_meta($key) { return array_key_exists($key, $this->meta) ? $this->meta[$key] : ''; }
            public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
            public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
            public function delete_meta_data($key) { unset($this->meta[$key]); }
            public function save_meta_data() {}
            public function is_paid() { return $this->paid; }
            public function needs_payment() { return !$this->paid; }
        };
        $order->meta = $meta;
        $order->paid = $paid;
        return $order;
    }

    private function charge_success_executor(callable $during_charge): callable {
        return static function ($route, $method, $body) use ($during_charge) {
            if ($route !== 'charge') {
                return array();
            }
            $during_charge();
            return array(
                'transport_ok' => true,
                'http_status' => 201,
                'curl_errno' => 0,
                'body' => json_encode(array('status' => true, 'data' => array('link' => 'https://sandbox.upayments.com/pay/attempt-a'))),
            );
        };
    }

    public function test_concurrent_attempt_rotation_never_drops_an_attempt(): void {
        // The real OrderLock runs against the option-store database fake.
        \supcheckout_test_reset_wp_options();
        $stale = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'));
        $GLOBALS['supcheckout_test_status_orders'][42] = $stale;
        // While this request waits for UPayments, a second submit of the same order
        // finished its own Charge and rotated the stored attempt to B.
        $fresh = $this->stateful_order(array(
            'UPayments_order_id' => 'attempt-b',
            '_simplixpay_upayments_prior_requested_v1' => array('attempt-p0'),
        ));
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            $this->charge_success_executor(static function () use ($fresh) {
                $GLOBALS['supcheckout_test_status_orders'][42] = $fresh;
            }),
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('success', $result['result']);
        $current = $fresh->get_meta('UPayments_order_id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $current);
        // Both earlier attempts stay payable: neither P0 nor B is forgotten.
        self::assertSame(array('attempt-p0', 'attempt-b'), $fresh->get_meta('_simplixpay_upayments_prior_requested_v1'));
    }

    public function test_a_new_charge_attempt_unparks_the_order_for_the_hourly_sweep(): void {
        // U2 review M-2: an order parked out of the sweep (its trusted transaction did
        // not bind) is unparked by the next Charge, under the attempt lock.
        \supcheckout_test_reset_wp_options();
        $order = $this->stateful_order(array(
            'UPayments_order_id' => 'attempt-p0',
            '_supcheckout_sweep_parked_v1' => 'track-t',
        ));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            $this->charge_success_executor(static function () {}),
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('success', $result['result']);
        self::assertSame('', $order->get_meta('_supcheckout_sweep_parked_v1'));
        self::assertSame(array('attempt-p0'), $order->get_meta('_simplixpay_upayments_prior_requested_v1'));
    }

    public function test_a_busy_order_lock_fails_closed_without_touching_the_order(): void {
        \supcheckout_test_reset_wp_options();
        $order = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $held = \Simplixi\SUPCheckout\Payment\OrderLock::acquire(42);
        self::assertIsString($held);
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            $this->charge_success_executor(static function () {}),
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame('attempt-p0', $order->get_meta('UPayments_order_id'));
        self::assertSame('', $order->get_meta('_simplixpay_upayments_prior_requested_v1'));
    }

    public function test_an_order_paid_during_charge_is_never_rotated(): void {
        \supcheckout_test_reset_wp_options();
        $stale = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'));
        $GLOBALS['supcheckout_test_status_orders'][42] = $stale;
        $fresh = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'), true);
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            $this->charge_success_executor(static function () use ($fresh) {
                // A late capture for attempt P0 completed the order meanwhile.
                $GLOBALS['supcheckout_test_status_orders'][42] = $fresh;
            }),
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('attempt-p0', $fresh->get_meta('UPayments_order_id'));
        self::assertSame('', $fresh->get_meta('_simplixpay_upayments_prior_requested_v1'));
        self::assertSame('success', $result['result']);
        self::assertStringNotContainsString('sandbox.upayments.com', $result['redirect']);
        self::assertSame('', $fresh->get_meta(StatusVerifier::CHARGE_AMOUNT_META));
    }

    public function test_a1_07_a_successful_charge_records_the_exact_charged_amount_for_its_attempt(): void {
        \supcheckout_test_reset_wp_options();
        $order = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $sent = array();
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            static function ($route, $method, $body) use (&$sent) {
                if ($route !== 'charge') {
                    return array();
                }
                $sent[] = $body;
                return array(
                    'transport_ok' => true,
                    'http_status' => 201,
                    'curl_errno' => 0,
                    'body' => json_encode(array('status' => true, 'data' => array('link' => 'https://sandbox.upayments.com/pay/attempt-a'))),
                );
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('success', $result['result']);
        self::assertCount(1, $sent);
        self::assertStringContainsString('"amount":10.000', $sent[0]);
        self::assertSame(
            array(
                'requested_order_id' => $order->get_meta('UPayments_order_id'),
                'amount'             => '10.000',
                'currency'           => 'KWD',
            ),
            $order->get_meta(StatusVerifier::CHARGE_AMOUNT_META)
        );
    }

    /**
     * @param array<string,mixed> $meta Order meta.
     * @return array<string,mixed> The decoded Charge payload.
     */
    private function captured_charge_payload(array $meta): array {
        \supcheckout_test_reset_wp_options();
        $order = $this->stateful_order($meta);
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $sent = array();
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            static function ($route, $method, $body) use (&$sent) {
                if ($route === 'charge') {
                    $sent[] = $body;
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $orchestrator->process(42);

        self::assertCount(1, $sent);
        $payload = json_decode($sent[0], true);
        self::assertIsArray($payload);
        return $payload;
    }

    public function test_a4_02_an_arabic_order_requests_the_arabic_hosted_page(): void {
        $payload = $this->captured_charge_payload(array('wpml_language' => 'ar'));

        self::assertSame('ar', $payload['language']);
        // The static device fingerprint is left as it is.
        self::assertSame('en', $payload['device']['browserDetails']['language']);
    }

    public function test_a4_02_the_wpml_current_language_is_used_when_the_order_has_none(): void {
        if (!function_exists('apply_filters')) {
            eval('function apply_filters($hook, $value = null) {
                return $hook === "wpml_current_language" && isset($GLOBALS["supcheckout_test_wpml_language"])
                    ? $GLOBALS["supcheckout_test_wpml_language"]
                    : $value;
            }');
        }
        $GLOBALS['supcheckout_test_wpml_language'] = 'ar';

        self::assertSame('ar', $this->captured_charge_payload(array())['language']);

        $GLOBALS['supcheckout_test_wpml_language'] = 'en';
        self::assertSame('en', $this->captured_charge_payload(array())['language']);
        // The order's own language wins over the request's.
        self::assertSame('en', $this->captured_charge_payload(array('wpml_language' => 'en'))['language']);
    }

    public function test_a4_02_other_languages_keep_the_english_hosted_page(): void {
        self::assertSame('en', $this->captured_charge_payload(array())['language']);
        self::assertSame('en', $this->captured_charge_payload(array('wpml_language' => 'fr'))['language']);
    }

    public function test_a1_07_a_rejected_charge_records_no_amount_snapshot(): void {
        \supcheckout_test_reset_wp_options();
        $order = $this->stateful_order(array('UPayments_order_id' => 'attempt-p0'));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            static function () { return array(); },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame('', $order->get_meta(StatusVerifier::CHARGE_AMOUNT_META));
    }
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-payment-runtime.php';
        \supcheckout_test_reset_payment_runtime();
    }

    public function test_process_rejects_noncanonical_order_ids_before_woo_lookup(): void {
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            static function () { return array(); },
            supcheckout_test_callback_url_resolver()
        );

        foreach (array("42\n", '42 ', '+42', '42.0', '4e1', '042') as $invalid) {
            \supcheckout_test_reset_payment_runtime();

            $result = $orchestrator->process($invalid);

            self::assertSame('failure', $result['result'], var_export($invalid, true));
            self::assertSame(array(), $GLOBALS['supcheckout_test_wc_get_order_calls'], var_export($invalid, true));
        }
    }

    public function test_currency_with_terminal_newline_is_rejected_before_provider_request(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            "KWD\n",
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                return null;
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests);
    }

    public function test_iban_with_terminal_newline_is_rejected_before_provider_request(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $gateway->multiMerchant = 'yes';
        $gateway->ibanNumber = "KW81CBKU0000000000001234560101\n";
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                return null;
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests);
    }

    public function test_multimerchant_charge_newline_already_fails_closed_before_provider_request(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $gateway->multiMerchant = 'yes';
        $gateway->ibanNumber = 'KW81CBKU0000000000001234560101';
        $gateway->knetCharge = "1.000\n";
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$requests) {
                $requests[] = array($route, $method, $body);
                return null;
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $requests);
    }

    public function test_process_keeps_positive_integer_order_ids_compatible(): void {
        $orchestrator = new CheckoutOrchestrator(
            new CheckoutOrchestratorGateway(),
            static function () { return ''; },
            static function () { return array(); },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(42), $GLOBALS['supcheckout_test_wc_get_order_calls']);
    }

    public function test_classic_one_time_purchase_accepts_the_unfilled_interval_select(): void {
        // The interval select posts '' until subscription-checkout.js fills it in;
        // a delayed or blocked script must not block a one-time purchase.
        $_POST = array('upay_subscription_plan' => 'one_time', 'upay_subscription_interval' => '');
        $gateway = new CheckoutOrchestratorGateway();
        $GLOBALS['supcheckout_test_status_orders'][42] = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple'))));
        $charges = 0;
        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route) use (&$charges) {
                if ($route === 'charge') {
                    $charges++;
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $orchestrator->process(42);

        self::assertSame(1, $charges, 'a one-time purchase reaches the Charge request');
        self::assertNotContains(array('Please select a valid Billing Interval.', 'error'), $GLOBALS['supcheckout_test_subscription_presentation']['notices']);
    }

    public function test_classic_subscription_plan_still_rejects_an_empty_interval(): void {
        $_POST = array('upay_subscription_plan' => 'monthly', 'upay_subscription_interval' => '');
        $gateway = new CheckoutOrchestratorGateway();
        $GLOBALS['supcheckout_test_status_orders'][42] = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('custom_type', 7))));
        $charges = 0;
        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route) use (&$charges) {
                if ($route === 'charge') {
                    $charges++;
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        self::assertSame('failure', $orchestrator->process(42)['result']);
        self::assertSame(0, $charges);
    }

    public function test_classic_rejects_explicitly_opted_out_subscription_product_before_provider_request(): void {
        $_POST = array(
            'upay_subscription_plan' => 'monthly',
            'upay_subscription_interval' => '1',
        );

        $gateway = new CheckoutOrchestratorGateway();
        $gateway->paymentData = null;
        $product = new \WC_Product('custom_type', 789);
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product($product))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $GLOBALS['supcheckout_test_subscription_presentation']['meta'][789]['_upay_disable_subscription'] = 'yes';

        $request_body_calls = 0;
        $provider_requests = array();
        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () use (&$request_body_calls) {
                $request_body_calls++;
                return '';
            },
            static function ($route, $method, $body) use (&$provider_requests) {
                $provider_requests[] = array($route, $method, $body);
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(0, $request_body_calls);
        self::assertSame(0, $gateway->paymentIconCalls);
        self::assertSame(array(), $provider_requests);
        self::assertContains(
            array('warning', 'Subscription plan rejected: product-level opt-out.'),
            $gateway->logs
        );
    }

    public function test_store_api_rejects_explicitly_opted_out_subscription_product_before_provider_request(): void {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';

        $gateway = new CheckoutOrchestratorGateway();
        $gateway->paymentData = null;
        $product = new \WC_Product('custom_type', 789);
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product($product))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $GLOBALS['supcheckout_test_subscription_presentation']['meta'][789]['_upay_disable_subscription'] = 'yes';

        $request_body_calls = 0;
        $provider_requests = array();
        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () use (&$request_body_calls) {
                $request_body_calls++;
                return json_encode(array(
                    'extensions' => array(
                        'upayments' => array(
                            'upay_subscription_plan' => 'monthly',
                            'upay_subscription_interval' => '1',
                        ),
                    ),
                ));
            },
            static function ($route, $method, $body) use (&$provider_requests) {
                $provider_requests[] = array($route, $method, $body);
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(1, $request_body_calls);
        self::assertSame(0, $gateway->paymentIconCalls);
        self::assertSame(array(), $provider_requests);
        self::assertContains(
            array('warning', 'Subscription plan rejected: product-level opt-out.'),
            $gateway->logs
        );
    }

    public function test_same_second_retries_use_distinct_provider_order_ids(): void {
        $GLOBALS['supcheckout_test_payment_runtime_time'] = 1700000000;
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $provider_order_ids = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$provider_order_ids) {
                if ($route === 'charge' && $method === 'POST' && is_string($body)) {
                    $payload = json_decode($body, true);
                    if (is_array($payload) && isset($payload['order']['id'])) {
                        $provider_order_ids[] = $payload['order']['id'];
                    }
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $first = $orchestrator->process(42);
        $second = $orchestrator->process(42);

        self::assertSame('failure', $first['result']);
        self::assertSame('failure', $second['result']);
        self::assertCount(2, $provider_order_ids);
        self::assertIsString($provider_order_ids[0]);
        self::assertIsString($provider_order_ids[1]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $provider_order_ids[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $provider_order_ids[1]);
        self::assertNotSame($provider_order_ids[0], $provider_order_ids[1]);
    }

    /**
     * Hardening T5 (A6-01): the availability getter no longer queues a notice, so place
     * order is the one place an outage is reported, exactly once, before any Charge.
     */
    public function test_place_order_during_an_availability_outage_reports_the_failure_exactly_once(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $gateway->paymentData = null;
        $order = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple'))));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $provider_requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$provider_requests) {
                $provider_requests[] = $route;
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $provider_requests, 'no Charge is sent');
        self::assertSame(
            array(array('Payment methods could not be loaded. Please try again.', 'error')),
            $GLOBALS['supcheckout_test_subscription_presentation']['notices']
        );
    }

    public function test_rejected_charge_leaves_a_private_order_note_with_the_sanitized_provider_reason(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple'))));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) {
                if ($route === 'charge') {
                    // The real transport (supcheckout.php) sets transport_ok only for 2xx,
                    // while keeping the status and body of a 4xx/5xx response.
                    return array(
                        'transport_ok' => false,
                        'http_status' => 400,
                        'curl_errno' => 0,
                        'body' => json_encode(array(
                            'status' => false,
                            'message' => 'The given data was invalid.',
                            'errors' => array('paymentGateway.src' => array('The payment gateway src field is required.')),
                        )),
                    );
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('HTTP 400', $order->notes[0]);
        self::assertStringContainsString('paymentGateway.src: The payment gateway src field is required.', $order->notes[0]);
        $notices = $GLOBALS['supcheckout_test_subscription_presentation']['notices'];
        self::assertSame(array(array('Payment request could not be completed. Please try again.', 'error')), $notices);
    }

    public function test_declared_charge_failure_on_201_leaves_a_private_order_note(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple'))));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) {
                return $route === 'charge'
                    ? array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => json_encode(array('status' => false, 'message' => 'Merchant account is not active.')))
                    : array();
            },
            supcheckout_test_callback_url_resolver()
        );

        self::assertSame('failure', $orchestrator->process(42)['result']);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('HTTP 201', $order->notes[0]);
        self::assertStringContainsString('Merchant account is not active.', $order->notes[0]);
    }

    public function test_unreachable_provider_leaves_a_network_order_note(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(42, 'KWD', '10.000', array(new \WC_Order_Item_Product(new \WC_Product('simple'))));
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) {
                return $route === 'charge'
                    ? array('transport_ok' => false, 'http_status' => 0, 'curl_errno' => 28, 'body' => '')
                    : array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $orchestrator->process(42);

        self::assertCount(1, $order->notes);
        self::assertStringContainsString('could not be reached', $order->notes[0]);
    }

    public function test_callback_urls_use_woocommerce_api_request_url_authority(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $wc = $GLOBALS['supcheckout_test_subscription_presentation']['wc'];
        $wc->api_request_url_value = 'https://shop.example.test/store/wc-api/wc_upayments/';
        $charge_body = null;

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$charge_body) {
                if ($route === 'charge' && $method === 'POST' && is_string($body)) {
                    $charge_body = json_decode($body, true);
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(array('wc_upayments', null)), $wc->api_request_url_calls);
        self::assertIsArray($charge_body);
        self::assertSame(
            'https://shop.example.test/store/wc-api/wc_upayments/?page=success&wc_order_id=42',
            $charge_body['returnUrl']
        );
        self::assertSame(
            'https://shop.example.test/store/wc-api/wc_upayments/?page=error&wc_order_id=42',
            $charge_body['cancelUrl']
        );
        self::assertSame(
            'https://shop.example.test/store/wc-api/wc_upayments/?wc_order_id=42',
            $charge_body['notificationUrl']
        );
        self::assertStringStartsWith('https://shop.example.test/', $charge_body['returnUrl']);
        self::assertStringNotContainsString('https://example.test/', $charge_body['returnUrl']);
        self::assertStringNotContainsString('https://example.test/', $charge_body['cancelUrl']);
        self::assertStringNotContainsString('https://example.test/', $charge_body['notificationUrl']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_site_url_calls']);
    }

    public function test_callback_urls_append_query_args_on_plain_wc_api_base(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $wc = $GLOBALS['supcheckout_test_subscription_presentation']['wc'];
        $wc->api_request_url_value = 'https://shop.example.test/store/?wc-api=wc_upayments';
        $charge_body = null;

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$charge_body) {
                if ($route === 'charge' && $method === 'POST' && is_string($body)) {
                    $charge_body = json_decode($body, true);
                }
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertIsArray($charge_body);
        self::assertSame(
            'https://shop.example.test/store/?wc-api=wc_upayments&page=success&wc_order_id=42',
            $charge_body['returnUrl']
        );
        self::assertSame(
            'https://shop.example.test/store/?wc-api=wc_upayments&page=error&wc_order_id=42',
            $charge_body['cancelUrl']
        );
        self::assertSame(
            'https://shop.example.test/store/?wc-api=wc_upayments&wc_order_id=42',
            $charge_body['notificationUrl']
        );
    }

    public function test_invalid_wc_api_url_fails_closed_before_provider_request(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $wc = $GLOBALS['supcheckout_test_subscription_presentation']['wc'];
        $wc->api_request_url_value = '';
        $provider_requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$provider_requests) {
                $provider_requests[] = array($route, $method, $body);
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $provider_requests);
    }

    public function test_overlong_callback_urls_still_fail_closed_before_provider_request(): void {
        $gateway = new CheckoutOrchestratorGateway();
        $order = new \WC_Order(
            42,
            'KWD',
            '10.000',
            array(new \WC_Order_Item_Product(new \WC_Product('simple')))
        );
        $GLOBALS['supcheckout_test_status_orders'][42] = $order;
        $wc = $GLOBALS['supcheckout_test_subscription_presentation']['wc'];
        $wc->api_request_url_value = 'https://shop.example.test/' . str_repeat('a', 220) . '/wc-api/wc_upayments/';
        $provider_requests = array();

        $orchestrator = new CheckoutOrchestrator(
            $gateway,
            static function () { return ''; },
            static function ($route, $method, $body) use (&$provider_requests) {
                $provider_requests[] = array($route, $method, $body);
                return array();
            },
            supcheckout_test_callback_url_resolver()
        );

        $result = $orchestrator->process(42);

        self::assertSame('failure', $result['result']);
        self::assertSame(array(), $provider_requests);
    }
}
