<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\PaymentLifecycle;

final class PaymentLifecycleRefundedOrderFixture {
    public function get_id() {
        return 42;
    }

    public function get_payment_method() {
        return 'upayments';
    }

    public function get_meta($key) {
        if ('UPayments_order_id' === $key) {
            return 'provider-order-refunded';
        }

        return '';
    }

    public function has_status($status) {
        return 'refunded' === $status;
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PaymentLifecycleRefundRegressionTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-payment-runtime.php';
        \supcheckout_test_reset_payment_runtime();
    }

    public function test_delayed_status_callback_cannot_recapture_fully_refunded_order(): void {
        $order = new PaymentLifecycleRefundedOrderFixture();
        $gateway = new \stdClass();

        $result = PaymentLifecycle::process_order_status(
            $gateway,
            $order,
            'track-refunded',
            'webhook'
        );

        self::assertSame('unchanged', $result['state']);
        self::assertSame('refunded', $result['reason']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_payment_runtime_request_calls']);
    }
}
