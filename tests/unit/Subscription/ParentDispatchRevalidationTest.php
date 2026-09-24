<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use UPayments\Subscription\Cron\Scheduler;

/**
 * TDD RED/GREEN for the final pre-dispatch authorization race gates.
 *
 * Uses a minimal WC_Order fixture because parent_still_eligible_for_dispatch()
 * is typed against WooCommerce's order object. Real Woo runtime coverage for
 * the same contract lives in SubscriptionRuntimeTest.php (all 20 compatibility
 * cells).
 */
final class ParentDispatchRevalidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        require_once dirname(__DIR__, 3) . '/src/Subscription/CycleEconomics.php';
        require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/Scheduler.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_test_wc_get_order_calls'] = array();
        \supcheckout_test_reset_subscription_presentation();
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = new class {
            /** @var object */
            public $payment_gateways;

            public function __construct()
            {
                $this->payment_gateways = new class {
                    public function payment_gateways()
                    {
                        return array();
                    }
                };
            }

            public function payment_gateways()
            {
                return $this->payment_gateways;
            }
        };
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function make_order(array $attrs): object
    {
        $order = new \WC_Order();
        foreach ($attrs as $key => $value) {
            $order->{"set_{$key}"}($value);
        }
        $GLOBALS['supcheckout_test_status_orders'][(int) $order->get_id()] = $order;
        return $order;
    }

    private function eligible_attrs(): array
    {
        return array(
            'id' => 501,
            'status' => 'processing',
            'payment_method' => 'upayments',
            'total' => '10.000',
            'currency' => 'KWD',
            'meta' => array(
                'UPayments_AutoDeduction' => 'no',
                '_upay_subscription_status' => 'active',
                '_upay_subscription_plan' => 'monthly',
                '_upay_subscription_interval' => '1',
                '_upay_customer_unique_token' => 'cust-token-1',
                '_upay_credit_card_token' => 'card-A',
            ),
            'items' => array(
                array('type' => 'custom_type'),
            ),
        );
    }

    private function invoke($order, string $amount, string $currency, string $customer, string $card): bool
    {
        $method = new \ReflectionMethod(Scheduler::class, 'parent_still_eligible_for_dispatch');
        return (bool) $method->invoke(null, $order, $amount, $currency, $customer, $card);
    }

    public function test_unchanged_eligible_parent_still_passes(): void
    {
        $order = $this->make_order($this->eligible_attrs());
        self::assertTrue(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'unchanged eligible control must remain dispatchable'
        );
    }

    public function test_refunded_parent_is_not_eligible(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['status'] = 'refunded';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'refunded parent must fail final revalidation'
        );
    }

    public function test_cancelled_parent_is_not_eligible(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['status'] = 'cancelled';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'cancelled parent must fail final revalidation'
        );
    }

    public function test_failed_parent_is_not_eligible(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['status'] = 'failed';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'failed parent must fail final revalidation'
        );
    }

    public function test_pending_parent_is_not_eligible(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['status'] = 'pending';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'pending parent must fail final revalidation unless Woo reports it paid'
        );
    }

    public function test_completed_paid_parent_remains_eligible(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['status'] = 'completed';
        $order = $this->make_order($attrs);
        self::assertTrue(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'completed parent remains eligible when Woo paid-status API reports it paid'
        );
    }

    public function test_card_change_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['meta']['_upay_credit_card_token'] = 'card-B';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'stored card A→B must fail final revalidation and never POST card-A'
        );
    }

    public function test_card_removal_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['meta']['_upay_credit_card_token'] = '';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'removed explicit card must fail final revalidation'
        );
    }

    public function test_customer_token_change_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['meta']['_upay_customer_unique_token'] = 'cust-token-2';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'customer token change must fail final revalidation'
        );
    }

    public function test_product_removal_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['items'] = array();
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'removing the custom_type line must fail final revalidation'
        );
    }

    public function test_non_custom_type_product_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['items'] = array(array('type' => 'simple'));
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'non-custom_type product must fail subscription-product revalidation'
        );
    }

    public function test_amount_mutation_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['total'] = '20.000';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'parent amount change must fail against immutable snapshot'
        );
    }

    public function test_currency_mutation_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['currency'] = 'USD';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'parent currency change must fail against immutable snapshot'
        );
    }

    public function test_payment_method_mutation_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['payment_method'] = 'cod';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'payment method mutation must fail final revalidation'
        );
    }

    public function test_paused_subscription_state_loses_authorization(): void
    {
        $attrs = $this->eligible_attrs();
        $attrs['meta']['_upay_subscription_status'] = 'paused';
        $order = $this->make_order($attrs);
        self::assertFalse(
            $this->invoke($order, '10.000', 'KWD', 'cust-token-1', 'card-A'),
            'paused subscription must fail final revalidation'
        );
    }
}
