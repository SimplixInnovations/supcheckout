<?php

namespace Simplixi\SUPCheckout\Security;

defined('ABSPATH') || exit;

/**
 * Read-only public status-poll boundary for the historical wc_upayments route.
 *
 * The legacy endpoint is intentionally retained for compatibility, but an order
 * ID alone is never authorization. A request may read the narrow status value
 * only when it is either:
 *
 * - made by the logged-in owner of the order; or
 * - accompanied by the exact WooCommerce order key.
 *
 * The endpoint never returns provider IDs, customer data, order totals, or raw
 * metadata. Unknown metadata values collapse to the non-terminal `wait` state.
 */
final class PublicOrderStatus {
    private const ALLOWED_STATUSES = array(
        'wait',
        'pending',
        'failed',
        'completed',
        'cancelled',
    );

    /**
     * Handle the historical GET status poll and terminate the request.
     *
     * @return void
     */
    public static function handle() {
        if (isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method is not slashed; intact bytes are compared with the sanitized copy before use.
            $raw_method = $_SERVER['REQUEST_METHOD'];
        } else {
            $raw_method = null;
        }
        $sanitized_method = $raw_method !== null ? sanitize_text_field($raw_method) : '';
        $method = $sanitized_method === $raw_method ? strtoupper($sanitized_method) : '';
        if ($method !== 'GET') {
            self::send_unavailable();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only endpoint with exact owner/order-key authorization.
        $get = $_GET;
        $order_id = array_key_exists('wc_order_id', $get)
            ? self::parse_order_id(self::unslash_string($get['wc_order_id']))
            : null;
        if ($order_id === null || !function_exists('wc_get_order')) {
            self::send_unavailable();
        }

        $order = wc_get_order($order_id);
        if (!self::is_upayments_order($order)) {
            self::send_unavailable();
        }

        $provided_key = array_key_exists('key', $get)
            ? self::normalize_order_key(self::unslash_string($get['key']))
            : null;
        $logged_in = function_exists('is_user_logged_in') && is_user_logged_in();
        $current_user_id = $logged_in && function_exists('get_current_user_id')
            ? (int) get_current_user_id()
            : 0;

        if (!self::authorize_order($order, $provided_key, $current_user_id, $logged_in)) {
            self::send_unavailable();
        }

        $raw_status = $order->get_meta('UPayments_WHS');
        $status = self::normalize_status($raw_status);
        self::send(array('status' => $status, 'message' => ''), 200);
    }

    /**
     * Pure authorization seam used by the security harness.
     *
     * @param mixed       $order           WooCommerce-like order object.
     * @param string|null $provided_key    Exact caller-supplied order key.
     * @param int         $current_user_id Current logged-in user ID.
     * @param bool        $logged_in       Whether the caller is logged in.
     * @return bool
     */
    public static function authorize_order($order, $provided_key, $current_user_id, $logged_in) {
        if (!self::is_upayments_order($order)
            || !method_exists($order, 'get_user_id')
            || !method_exists($order, 'get_order_key')
        ) {
            return false;
        }

        $order_user_id = (int) $order->get_user_id();
        $current_user_id = (int) $current_user_id;
        if ($logged_in === true
            && $current_user_id > 0
            && $order_user_id > 0
            && $current_user_id === $order_user_id
        ) {
            return true;
        }

        $provided_key = self::normalize_order_key($provided_key);
        $order_key = self::normalize_order_key($order->get_order_key());
        return $provided_key !== null
            && $order_key !== null
            && hash_equals($order_key, $provided_key);
    }

    /**
     * Strict positive decimal order ID parser.
     *
     * @param mixed $value Raw order ID boundary value.
     * @return int|null
     */
    public static function parse_order_id($value) {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > 18
            || !preg_match('/\A[1-9][0-9]*\z/', $value)
        ) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    /**
     * Collapse untrusted persisted status to the narrow display contract.
     *
     * @param mixed $value Persisted status boundary value.
     * @return string
     */
    public static function normalize_status($value) {
        if (!is_string($value) || !in_array($value, self::ALLOWED_STATUSES, true)) {
            return 'wait';
        }
        return $value;
    }

    /**
     * @param mixed $order WooCommerce-like order value.
     * @return bool
     */
    private static function is_upayments_order($order) {
        return is_object($order)
            && method_exists($order, 'get_payment_method')
            && method_exists($order, 'get_meta')
            && (string) $order->get_payment_method() === 'upayments';
    }

    /**
     * @param mixed $value Raw order-key boundary value.
     * @return string|null
     */
    private static function normalize_order_key($value) {
        if (!is_string($value) || $value === '' || strlen($value) > 128) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * @param mixed $value Raw request value.
     * @return string|null
     */
    private static function unslash_string($value) {
        if (!is_string($value)) {
            return null;
        }
        if (function_exists('wp_unslash')) {
            $value = wp_unslash($value);
        }
        return is_string($value) ? $value : null;
    }

    /**
     * @return void
     */
    private static function send_unavailable() {
        self::send(
            array(
                'status' => 'error',
                'message' => 'Order status unavailable.',
            ),
            404
        );
    }

    /**
     * @param array<string, string> $payload Narrow public response.
     * @param int                  $status_code HTTP status code.
     * @return void
     */
    private static function send(array $payload, $status_code) {
        $status_code = (int) $status_code;
        if (function_exists('wc_nocache_headers')) {
            wc_nocache_headers();
        } elseif (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (function_exists('wp_send_json')) {
            wp_send_json($payload, $status_code);
        }

        if (function_exists('status_header')) {
            status_header($status_code);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        exit;
    }

    private function __construct() {}
}
