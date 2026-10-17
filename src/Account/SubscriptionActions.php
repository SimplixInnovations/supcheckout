<?php

namespace Simplixi\SUPCheckout\Account;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * My Account pause / resume / unsubscribe POST handler for manual UPayments
 * subscription orders (was the anonymous 'init' closure in the main file).
 */
final class SubscriptionActions {
    /** @return void */
    public static function register() {
        add_action('init', array(self::class, 'handle'));
    }

    /** @return void */
    public static function handle() {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ($method !== 'POST') {
            return;
        }

        $action = isset($_POST['upay_action']) && is_string($_POST['upay_action'])
            ? sanitize_key(wp_unslash($_POST['upay_action']))
            : '';

        $order_id = isset($_POST['order_id'])
            ? absint(wp_unslash($_POST['order_id']))
            : 0;

        if (empty($action) || empty($order_id)) {
            return;
        }

        $allowed_actions = array('unsubscribe', 'pause', 'resume');
        if (!in_array($action, $allowed_actions, true)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Authorization: nonce is CSRF protection, never object authorization.
        if (!is_user_logged_in() || get_current_user_id() !== (int) $order->get_user_id()) {
            wc_add_notice(__('Unauthorized request.', 'supcheckout'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('orders'));
            exit;
        }

        // Object contract: this customer action belongs only to manual UPayments
        // subscription orders. Auto-deduction orders remain scheduler-controlled.
        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');
        $interval = (int) Keys::read_order_meta($order, '_upay_subscription_interval');
        $allowed_intervals = array(
            'daily' => array(1),
            'weekly' => array(1, 2, 3),
            'monthly' => array(1, 2),
            'quarterly' => array(1, 2, 3),
            'yearly' => array(1),
        );
        if (!Keys::is_our_gateway((string) $order->get_payment_method())
            || Keys::read_order_meta($order, 'UPayments_AutoDeduction') === 'yes'
            || !is_string($plan)
            || !isset($allowed_intervals[$plan])
            || !in_array($interval, $allowed_intervals[$plan], true)
        ) {
            wc_add_notice(__('Invalid subscription request.', 'supcheckout'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('orders'));
            exit;
        }

        $current_status = Keys::read_order_meta($order, '_upay_subscription_status') ?: 'active';
        $transition_allowed = ($action === 'unsubscribe' && in_array($current_status, array('active', 'paused'), true))
            || ($action === 'pause' && $current_status === 'active')
            || ($action === 'resume' && $current_status === 'paused');
        if (!$transition_allowed) {
            wc_add_notice(__('Invalid subscription state transition.', 'supcheckout'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
            exit;
        }

        // Nonce verification: required for every state-changing action.
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';

        if ($action === 'unsubscribe') {
            $nonce_action = 'upay_unsubscribe_' . $order_id;
        } else {
            $nonce_action = 'upay_' . $action . '_' . $order_id;
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, $nonce_action)) {
            wc_add_notice(__('Invalid request.', 'supcheckout'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('orders'));
            exit;
        }

        if ($action === 'unsubscribe') {
            Keys::update_order_meta($order, '_upay_subscription_status', 'cancelled');
            wc_add_notice(__('Your subscription has been cancelled.', 'supcheckout'), 'success');
        } elseif ($action === 'pause') {
            Keys::update_order_meta($order, '_upay_subscription_status', 'paused');
            wc_add_notice(__('Subscription paused.', 'supcheckout'), 'success');
        } elseif ($action === 'resume') {
            Keys::update_order_meta($order, '_upay_subscription_status', 'active');
            wc_add_notice(__('Subscription resumed.', 'supcheckout'), 'success');
        }

        $order->save();

        // R4: explicit customer-transition scheduling contract (best-effort).
        // Payment safety never depends on queue cancellation succeeding.
        if (class_exists('\Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler')) {
            if ($action === 'resume') {
                \Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler::schedule_resume($order);
            // @phpstan-ignore identical.alwaysTrue (the allowlist already narrowed $action; explicit test kept verbatim)
            } elseif ($action === 'pause' || $action === 'unsubscribe') {
                \Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler::cancel_after_state_change($order);
            }
        }

        wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
        exit;
    }

    private function __construct() {}
}
