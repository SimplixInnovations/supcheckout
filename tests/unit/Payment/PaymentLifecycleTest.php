<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\PaymentLifecycle;

final class PaymentLifecycleCompletionFailureOrder {
    private $id = 42;
    private $status = 'pending';
    private $transaction_id = '';
    private $pending_meta = array();
    private $durable_meta = array();

    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return $this->status === $status; }
    public function is_paid() { return false; }
    public function get_transaction_id() { return $this->transaction_id; }
    public function set_transaction_id($id) { $this->transaction_id = (string) $id; }
    public function get_meta($key) {
        if (array_key_exists($key, $this->pending_meta)) {
            return $this->pending_meta[$key];
        }
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : '';
    }
    public function meta_exists($key) {
        return array_key_exists($key, $this->pending_meta) || array_key_exists($key, $this->durable_meta);
    }
    public function update_meta_data($key, $value) { $this->pending_meta[$key] = $value; }
    public function delete_meta_data($key) {
        unset($this->pending_meta[$key], $this->durable_meta[$key]);
    }
    public function payment_complete($transaction_id = '') {
        $this->transaction_id = (string) $transaction_id;
        $this->save();
    }
    public function save() {
        foreach ($this->pending_meta as $key => $value) {
            $this->durable_meta[$key] = $value;
        }
        $this->pending_meta = array();
        return $this->id;
    }
    public function durable_meta($key) {
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : null;
    }
}

final class PaymentLifecycleThrowingCompletionOrder {
    private $id = 42;
    private $status = 'pending';
    private $transaction_id = '';
    private $pending_meta = array();
    private $durable_meta = array(
        'UPayments_Result' => 'PENDING',
        'UPayments_PaymentID' => 'old-pay',
        'UPayments_TrackID' => 'old-track',
        'UPayments_payment_type' => 'OLD',
        'UPayments_Ref' => 'old-ref',
        '_payment_method_title' => 'Old title',
    );

    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return $this->status === $status; }
    public function is_paid() { return false; }
    public function get_transaction_id() { return $this->transaction_id; }
    public function set_transaction_id($id) { $this->transaction_id = (string) $id; }
    public function get_meta($key) {
        if (array_key_exists($key, $this->pending_meta)) {
            return $this->pending_meta[$key];
        }
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : '';
    }
    public function meta_exists($key) {
        return array_key_exists($key, $this->pending_meta) || array_key_exists($key, $this->durable_meta);
    }
    public function update_meta_data($key, $value) { $this->pending_meta[$key] = $value; }
    public function delete_meta_data($key) {
        unset($this->pending_meta[$key], $this->durable_meta[$key]);
    }
    public function payment_complete($transaction_id = '') {
        $this->transaction_id = (string) $transaction_id;
        $this->save();
        throw new \RuntimeException('simulated payment_complete failure');
    }
    public function save() {
        foreach ($this->pending_meta as $key => $value) {
            $this->durable_meta[$key] = $value;
        }
        $this->pending_meta = array();
        return $this->id;
    }
    public function durable_meta($key) {
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : null;
    }
}

final class PaymentLifecycleCompletionHookOrder {
    private $id = 42;
    private $status = 'pending';
    private $transaction_id = '';
    private $pending_meta = array();
    private $durable_meta = array();
    private $completion_hook_meta = array();

    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return $this->status === $status; }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function get_transaction_id() { return $this->transaction_id; }
    public function set_transaction_id($id) { $this->transaction_id = (string) $id; }
    public function get_meta($key) {
        if (array_key_exists($key, $this->pending_meta)) {
            return $this->pending_meta[$key];
        }
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : '';
    }
    public function meta_exists($key) {
        return array_key_exists($key, $this->pending_meta) || array_key_exists($key, $this->durable_meta);
    }
    public function update_meta_data($key, $value) { $this->pending_meta[$key] = $value; }
    public function delete_meta_data($key) {
        unset($this->pending_meta[$key], $this->durable_meta[$key]);
    }
    public function payment_complete($transaction_id = '') {
        $this->transaction_id = (string) $transaction_id;
        $this->status = 'processing';
        $this->save();
        foreach (array(
            'UPayments_Result',
            'UPayments_PaymentID',
            'UPayments_TrackID',
            'UPayments_payment_type',
            'UPayments_Ref',
            '_payment_method_title'
        ) as $key) {
            $this->completion_hook_meta[$key] = $this->durable_meta($key);
        }
    }
    public function save() {
        foreach ($this->pending_meta as $key => $value) {
            $this->durable_meta[$key] = $value;
        }
        $this->pending_meta = array();
        return $this->id;
    }
    public function durable_meta($key) {
        return array_key_exists($key, $this->durable_meta) ? $this->durable_meta[$key] : null;
    }
    public function completion_hook_meta($key) {
        return array_key_exists($key, $this->completion_hook_meta) ? $this->completion_hook_meta[$key] : null;
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PaymentLifecycleTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-payment-runtime.php';
        \supcheckout_test_reset_payment_runtime();
    }

    public function test_reconcile_rejects_noncanonical_order_ids_before_woo_lookup(): void {
        foreach (array("42\n", '42 ', '+42', '42.0', '4e1', '042', 42.0) as $invalid) {
            \supcheckout_test_reset_payment_runtime();

            PaymentLifecycle::reconcile_order($invalid);

            self::assertSame(array(), $GLOBALS['supcheckout_test_wc_get_order_calls'], var_export($invalid, true));
        }
    }

    public function test_reconcile_keeps_positive_integer_order_ids_compatible(): void {
        PaymentLifecycle::reconcile_order(42);

        self::assertSame(array(42), $GLOBALS['supcheckout_test_wc_get_order_calls']);
    }

    public function test_successful_payment_complete_hooks_observe_provider_capture_metadata(): void {
        $order = new PaymentLifecycleCompletionHookOrder();
        $transaction = array(
            'payment_id' => 'pay-123',
            'track_id' => 'track-abc',
            'payment_type' => 'KNET',
            'reference' => '42',
        );

        $method = new \ReflectionMethod(PaymentLifecycle::class, 'apply_captured');
        // PHP < 8.1 requires explicit reflection accessibility; PHP 8.1+ does not.
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $result = $method->invoke(null, new \stdClass(), $order, $transaction);

        self::assertTrue($result);
        self::assertSame('CAPTURED', $order->completion_hook_meta('UPayments_Result'));
        self::assertSame('pay-123', $order->completion_hook_meta('UPayments_PaymentID'));
        self::assertSame('track-abc', $order->completion_hook_meta('UPayments_TrackID'));
        self::assertSame('KNET', $order->completion_hook_meta('UPayments_payment_type'));
        self::assertSame('42', $order->completion_hook_meta('UPayments_Ref'));
        // The merchant's configured (and WPML/WCML-translated) title is never rewritten.
        self::assertNull($order->completion_hook_meta('_payment_method_title'));
        self::assertNull($order->durable_meta('_payment_method_title'));
        self::assertSame(1, $order->durable_meta('_upay_verified_capture'));
        self::assertSame(1, $order->durable_meta('UPayments_webhook_triggered'));
    }

    public function test_failed_payment_complete_postcondition_does_not_leave_durable_capture_metadata(): void {
        $order = new PaymentLifecycleCompletionFailureOrder();
        $transaction = array(
            'payment_id' => 'pay-123',
            'track_id' => 'track-abc',
            'payment_type' => 'KNET',
            'reference' => '42',
        );

        $method = new \ReflectionMethod(PaymentLifecycle::class, 'apply_captured');
        // PHP < 8.1 requires explicit reflection accessibility; PHP 8.1+ does not.
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $result = $method->invoke(null, new \stdClass(), $order, $transaction);

        self::assertFalse($result);
        self::assertNull($order->durable_meta('UPayments_Result'));
        self::assertNull($order->durable_meta('UPayments_PaymentID'));
        self::assertNull($order->durable_meta('UPayments_TrackID'));
        self::assertNull($order->durable_meta('UPayments_payment_type'));
        self::assertNull($order->durable_meta('UPayments_Ref'));
        self::assertNull($order->durable_meta('_payment_method_title'));
        self::assertNull($order->durable_meta('_upay_verified_capture'));
        self::assertNull($order->durable_meta('UPayments_webhook_triggered'));
    }

    public function test_throwing_payment_complete_restores_prior_durable_capture_metadata(): void {
        $order = new PaymentLifecycleThrowingCompletionOrder();
        $transaction = array(
            'payment_id' => 'pay-123',
            'track_id' => 'track-abc',
            'payment_type' => 'KNET',
            'reference' => '42',
        );

        $method = new \ReflectionMethod(PaymentLifecycle::class, 'apply_captured');
        // PHP < 8.1 requires explicit reflection accessibility; PHP 8.1+ does not.
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        try {
            $method->invoke(null, new \stdClass(), $order, $transaction);
            self::fail('Expected payment_complete exception was not rethrown.');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $cause = $e instanceof \ReflectionException ? $e : ($e->getPrevious() ?: $e);
            self::assertSame('simulated payment_complete failure', $cause->getMessage());
        }

        self::assertSame('PENDING', $order->durable_meta('UPayments_Result'));
        self::assertSame('old-pay', $order->durable_meta('UPayments_PaymentID'));
        self::assertSame('old-track', $order->durable_meta('UPayments_TrackID'));
        self::assertSame('OLD', $order->durable_meta('UPayments_payment_type'));
        self::assertSame('old-ref', $order->durable_meta('UPayments_Ref'));
        self::assertSame('Old title', $order->durable_meta('_payment_method_title'));
        self::assertNull($order->durable_meta('_upay_verified_capture'));
        self::assertNull($order->durable_meta('UPayments_webhook_triggered'));
    }

    public function test_request_merge_is_presence_aware_and_conflict_safe(): void {
        self::assertSame(
            array('valid' => true, 'present' => false, 'value' => null),
            PaymentLifecycle::merge_request_value(array(), array(), 'track_id')
        );
        self::assertSame(
            array('valid' => true, 'present' => true, 'value' => 'track-a'),
            PaymentLifecycle::merge_request_value(array('track_id' => 'track-a'), array(), 'track_id')
        );
        self::assertFalse(
            PaymentLifecycle::merge_request_value(
                array('track_id' => 'track-a'),
                array('track_id' => 'track-b'),
                'track_id'
            )['valid']
        );
    }
}
