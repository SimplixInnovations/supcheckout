<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

/**
 * Small database-atomic rate gate for authenticated payment-status queries.
 *
 * The provider's dedicated endpoint documents 30 requests/minute. We use 30
 * one-minute slots per credential/mode scope. WordPress add_option() is backed
 * by a unique option_name, so concurrent workers cannot acquire the same slot.
 * Old slot options are removed when the minute bucket advances.
 *
 * Callback-triggered lookups (browser return, webhook) are reachable by any
 * visitor who knows an order's routing identifiers, so they may use only the
 * first CALLBACK_SLOTS_PER_MINUTE slots. The remainder stays reserved for
 * bounded reconciliation, which recovers callbacks refused here.
 */
final class StatusRateGate {
    private const LIMIT_PER_MINUTE = 30;
    private const CALLBACK_SLOTS_PER_MINUTE = 24;
    private const PREFIX = 'simplixpay_upay_status_v1_';

    /**
     * @param mixed $gateway Defensive active-gateway boundary value.
     * @param string $source 'reconcile' may use every slot; any other source is callback-triggered.
     * @return bool True when one provider-query slot was acquired.
     */
    public static function acquire($gateway, $source = 'reconcile') {
        if (!is_object($gateway)
            || !isset($gateway->apiKey)
            || !is_string($gateway->apiKey)
            || $gateway->apiKey === ''
            || !method_exists($gateway, 'getMode')
        ) {
            return false;
        }

        $mode = $gateway->getMode() ? 'test' : 'live';
        // This class is reachable only after the WordPress hook API is live.
        // Call the canonical WP salt function directly; an empty result still
        // fails closed and never creates a credential-derived option name.
        $salt = (string) wp_salt('auth');
        if ($salt === '') {
            return false;
        }

        $scope = substr(hash_hmac('sha256', $mode . '|' . $gateway->apiKey, $salt), 0, 16);
        $bucket = gmdate('YmdHi');
        $marker_name = self::PREFIX . $scope . '_bucket';
        $previous_bucket = get_option($marker_name, '');

        if (is_string($previous_bucket) && $previous_bucket !== '' && $previous_bucket !== $bucket) {
            self::delete_bucket($scope, $previous_bucket);
        }

        if ($previous_bucket !== $bucket) {
            // A stale/absent marker is observability state only. The slots below
            // remain the authoritative concurrency gate even if this update loses
            // a race against another worker.
            update_option($marker_name, $bucket, false);
        }

        $usable = $source === 'reconcile' ? self::LIMIT_PER_MINUTE : self::CALLBACK_SLOTS_PER_MINUTE;
        for ($slot = 0; $slot < $usable; $slot++) {
            $option_name = self::slot_name($scope, $bucket, $slot);
            if (add_option($option_name, time(), '', 'no')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exposed for deterministic harness verification only; no runtime caller
     * should derive financial meaning from this number.
     *
     * @return int
     */
    public static function limit_per_minute() {
        return self::LIMIT_PER_MINUTE;
    }

    private static function delete_bucket($scope, $bucket) {
        if (!is_string($scope) || !preg_match('/^[a-f0-9]{16}$/', $scope)) {
            return;
        }
        if (!is_string($bucket) || !preg_match('/^[0-9]{12}$/', $bucket)) {
            return;
        }
        for ($slot = 0; $slot < self::LIMIT_PER_MINUTE; $slot++) {
            delete_option(self::slot_name($scope, $bucket, $slot));
        }
    }

    private static function slot_name($scope, $bucket, $slot) {
        return self::PREFIX . $scope . '_' . $bucket . '_' . (int) $slot;
    }

    private function __construct() {
    }
}
