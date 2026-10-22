<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Bearer-authenticated Get Payment Status client + strict order binding.
 */
final class StatusVerifier {
    /**
     * Order meta holding the exact amount of the latest successful Charge:
     * array{requested_order_id:string, amount:string, currency:string}.
     *
     * First-party only: it is born under its SUPCheckout name, has no legacy
     * counterpart and is not in Takeover\Keys, so the takeover never copies,
     * removes or finalizes it.
     */
    public const CHARGE_AMOUNT_META = '_supcheckout_charge_amount_v1';

    /**
     * @param mixed $gateway  Defensive active-gateway boundary value.
     * @param mixed $order    Defensive WooCommerce order boundary value.
     * @param mixed $track_id Provider status cursor.
     * @param string $source  Lookup origin; callback sources use the reduced rate-gate share.
     * @param string|null $expected_requested_order_id Provider order identity to bind; null means
     *                    the order's current UPayments_order_id. Callers must have proven the
     *                    identity belongs to this order.
     * @return array Authenticated status verification result.
     */
    public static function verify($gateway, $order, $track_id, $source = 'reconcile', $expected_requested_order_id = null) {
        $result = self::base_result('invalid_request');

        if (!is_object($gateway)
            || !is_object($order)
            || !method_exists($gateway, 'getAPIUrl')
            || !method_exists($gateway, 'getCurrencyCode')
            || !method_exists($order, 'get_id')
            || !method_exists($order, 'get_currency')
            || !method_exists($order, 'get_total')
            || !method_exists($order, 'get_meta')
        ) {
            return $result;
        }

        $track_id = self::normalize_track_id($track_id);
        if ($track_id === null) {
            return self::base_result('invalid_track_id');
        }

        if (!isset($gateway->apiKey) || !is_string($gateway->apiKey) || $gateway->apiKey === '') {
            return self::base_result('credentials_missing');
        }

        // Validate the exact provider destination before consuming a provider
        // rate-limit slot. This also prevents a compromised/extended gateway
        // object from turning the Bearer token into a cross-host credential leak.
        $url = $gateway->getAPIUrl('get-payment-status/' . rawurlencode($track_id));
        if (!self::is_allowed_status_url($url, $track_id)) {
            return self::base_result('status_url_invalid');
        }

        if (!StatusRateGate::acquire($gateway, $source)) {
            return self::base_result('status_rate_limited');
        }

        $response = wp_remote_get($url, array(
            'timeout'             => 15,
            'limit_response_size' => 1048576,
            'redirection'         => 0,
            'sslverify'   => true,
            'headers'     => array(
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $gateway->apiKey,
            ),
        ));

        if (is_wp_error($response)) {
            return self::base_result('network_error');
        }

        $http_status = (int) wp_remote_retrieve_response_code($response);
        if ($http_status !== 201) {
            return self::base_result('unexpected_http_' . $http_status);
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return self::base_result('empty_response');
        }
        // A body exactly at the WordPress read cap may be truncated. Treat it
        // as an invalid/ambiguous provider response so reconciliation remains
        // bounded and fail closed.
        if (strlen($body) >= 1048576) {
            return self::base_result('invalid_status_response');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)
            || !array_key_exists('status', $decoded)
            || $decoded['status'] !== true
            || !isset($decoded['data'])
            || !is_array($decoded['data'])
            || !isset($decoded['data']['transaction'])
            || !is_array($decoded['data']['transaction'])
        ) {
            return self::base_result('invalid_status_response');
        }

        $bound = self::bind_transaction($gateway, $order, $track_id, $decoded['data']['transaction'], $expected_requested_order_id);
        $bound['authenticated'] = true;
        return $bound;
    }

    /**
     * Pure binding/classification seam used by the executable harness.
     *
     * @param mixed $gateway     Defensive active-gateway boundary value.
     * @param mixed $order       Defensive WooCommerce order boundary value.
     * @param mixed $track_id    Provider status cursor.
     * @param mixed $transaction Provider transaction payload.
     * @param string|null $expected_requested_order_id See verify().
     * @return array Authenticated binding result.
     */
    public static function bind_transaction($gateway, $order, $track_id, $transaction, $expected_requested_order_id = null) {
        $result = self::base_result('binding_invalid');
        $result['authenticated'] = true;

        if (!is_object($gateway)
            || !is_object($order)
            || !is_array($transaction)
            || !method_exists($gateway, 'getCurrencyCode')
            || !method_exists($order, 'get_id')
            || !method_exists($order, 'get_currency')
            || !method_exists($order, 'get_total')
            || !method_exists($order, 'get_meta')
        ) {
            return $result;
        }

        $track_id = self::normalize_track_id($track_id);
        if ($track_id === null) {
            $result['reason'] = 'binding_track_id_invalid';
            return $result;
        }

        // UPayments explicitly documents NULL/processing-style result states as
        // non-terminal. Result must therefore be present, but null is a valid
        // fail-closed INDETERMINATE value. All identity/binding fields remain
        // mandatory and non-empty.
        if (!array_key_exists('result', $transaction)) {
            $result['reason'] = 'missing_field_result';
            return $result;
        }
        if ($transaction['result'] !== null && !is_string($transaction['result'])) {
            $result['reason'] = 'result_not_string_or_null';
            return $result;
        }

        $required = array(
            'track_id',
            'merchant_requested_order_id',
            'total_price',
            'currency_type',
            'reference',
        );
        foreach ($required as $field) {
            if (!array_key_exists($field, $transaction)
                || !is_scalar($transaction[$field])
                || (string) $transaction[$field] === ''
            ) {
                $result['reason'] = 'missing_field_' . $field;
                return $result;
            }
        }

        if ((string) $transaction['track_id'] !== $track_id) {
            $result['reason'] = 'binding_track_id';
            return $result;
        }

        $local_upay_order_id = $expected_requested_order_id === null
            ? Keys::read_order_meta($order, 'UPayments_order_id')
            : $expected_requested_order_id;
        if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
            $result['reason'] = 'missing_local_upay_order_id';
            return $result;
        }
        if ((string) $transaction['merchant_requested_order_id'] !== $local_upay_order_id) {
            $result['reason'] = 'binding_merchant_requested_order_id';
            return $result;
        }

        if ((string) $transaction['reference'] !== (string) $order->get_id()) {
            $result['reason'] = 'binding_reference';
            return $result;
        }

        $local_currency = $gateway->getCurrencyCode($order->get_currency());
        $expected_currency = is_scalar($local_currency) ? strtoupper(trim((string) $local_currency)) : '';
        $verified_currency = strtoupper(trim((string) $transaction['currency_type']));
        if ($expected_currency === '' || $verified_currency !== $expected_currency) {
            $result['reason'] = 'binding_currency';
            return $result;
        }

        $verified_amount = self::normalize_decimal($transaction['total_price']);
        $local_amount = self::normalize_decimal($order->get_total());
        if ($verified_amount === null || $local_amount === null) {
            $result['reason'] = 'amount_invalid';
            return $result;
        }

        // Exact numeric equality without display/store-decimal rounding.
        // Trailing-zero variants (10, 10.0, 10.000) are the same amount, while
        // any non-zero extra precision (10.004 vs 10.00) is a hard mismatch.
        // This comparison never casts the validated decimal to float.
        $verified_canonical = self::canonical_decimal($verified_amount);
        $local_canonical = self::canonical_decimal($local_amount);
        if ($local_canonical !== null) {
            $charged = self::charged_amount($order, $local_upay_order_id, $expected_currency, $local_canonical);
            if ($charged === false) {
                $result['reason'] = 'binding_amount';
                return $result;
            }
            if ($charged !== null) {
                $local_canonical = $charged;
            }
        }
        if ($verified_canonical === null
            || $local_canonical === null
            || !hash_equals($local_canonical, $verified_canonical)
        ) {
            $result['reason'] = 'binding_amount';
            return $result;
        }
        // R-1: get_total() is re-rounded to this request's price decimals, so an edit
        // made at higher precision (12.34 charged, edited to 12.344) still reads 12.34
        // here. Every bind therefore also needs the provider amount to equal the
        // persisted order total exactly. This is the only persisted read per bind
        // attempt. An unreadable total fails closed with its own reason: it may be a
        // passing database error rather than a mismatch, so the lifecycle keeps
        // its bounded re-check instead of treating it as final like binding_amount.
        $persisted = self::persisted_order_total($order->get_id());
        if ($persisted === null) {
            $result['reason'] = 'binding_persisted_total_unreadable';
            return $result;
        }
        if (!hash_equals($persisted, $verified_canonical)) {
            $result['reason'] = 'binding_amount';
            return $result;
        }

        $classification = ProviderResult::classify($transaction['result']);
        if ($classification === ProviderResult::CAPTURED) {
            if (!array_key_exists('payment_id', $transaction)
                || !is_scalar($transaction['payment_id'])
                || (string) $transaction['payment_id'] === ''
            ) {
                $result['reason'] = 'captured_payment_id_missing';
                return $result;
            }
        }

        $result['bound'] = true;
        $result['classification'] = $classification;
        $result['transaction'] = $transaction;
        $result['reason'] = strtolower($classification);
        return $result;
    }

    /**
     * The snapshot value for CHARGE_AMOUNT_META.
     *
     * @param string $requested_order_id Provider order identity of the Charge.
     * @param string $amount             The exact amount token sent as order.amount.
     * @param string $currency           The currency sent with it.
     * @return array{requested_order_id:string, amount:string, currency:string}
     */
    public static function charge_amount_snapshot($requested_order_id, $amount, $currency) {
        return array(
            'requested_order_id' => (string) $requested_order_id,
            'amount'             => (string) $amount,
            'currency'           => (string) $currency,
        );
    }

    /**
     * The exact amount charged for the attempt being bound (A1-07).
     *
     * WooCommerce re-rounds the order total to the current request's
     * wc_get_price_decimals() on every load, so a 3-decimal Charge reloaded in a
     * 2-decimal request (a webhook or cron with no client currency) no longer
     * equals what was charged. The snapshot recorded at Charge time is that exact
     * amount. It is used only while the order is unedited: rounded the way
     * WooCommerce rounds this request's total, it must equal get_total(). The
     * rounding is a comparison only; the bound amount is the snapshot itself.
     *
     * @param object $order              Order.
     * @param string $requested_order_id Provider order identity being bound.
     * @param string $currency           Expected provider currency.
     * @param string $local_canonical    Canonical get_total() of this request.
     * @return string|false|null The canonical charged amount; false when the order
     *                           changed since the Charge; null when no usable
     *                           snapshot exists (orders charged before it), so the
     *                           order total is compared exactly, as before.
     */
    private static function charged_amount($order, $requested_order_id, $currency, $local_canonical) {
        $snapshot = $order->get_meta(self::CHARGE_AMOUNT_META);
        if (!is_array($snapshot)
            || !isset($snapshot['requested_order_id'], $snapshot['amount'], $snapshot['currency'])
            || !is_string($snapshot['requested_order_id'])
            || !is_string($snapshot['currency'])
            || $snapshot['requested_order_id'] !== (string) $requested_order_id
            || strtoupper(trim($snapshot['currency'])) !== $currency
            || !function_exists('wc_format_decimal')
            || !function_exists('wc_get_price_decimals')
        ) {
            return null;
        }
        $charged = self::canonical_decimal($snapshot['amount']);
        if ($charged === null) {
            return null;
        }
        // Same precision as the Charge: exact equality, exactly as without a snapshot.
        if (hash_equals($local_canonical, $charged)) {
            return $charged;
        }
        // This request has fewer decimals than the Charge. Matching the request's
        // rounding is necessary but not enough: an edit inside the same rounding
        // bucket (12.341 -> 12.344, both 12.34 at 2 decimals) still matches. So the
        // snapshot must also equal the persisted order total exactly, read without
        // the request's decimals. bind_transaction() makes that check for every bind
        // (R-1): the provider amount must equal both the snapshot returned here and
        // the persisted total. Anything unreadable fails closed there.
        $as_loaded = self::canonical_decimal((string) wc_format_decimal($charged, wc_get_price_decimals()));
        if ($as_loaded === null || !hash_equals($local_canonical, $as_loaded)) {
            return false;
        }
        return $charged;
    }

    /**
     * The order total as stored, canonicalized, independent of the request's price
     * decimals: HPOS `total_amount` (decimal(26,8)) in the orders table, or the CPT
     * `_order_total` post meta, whichever store is authoritative.
     *
     * @param mixed $order_id Order ID.
     * @return string|null Canonical decimal, or null when it cannot be read.
     */
    private static function persisted_order_total($order_id) {
        $order_id = is_numeric($order_id) ? (int) $order_id : 0;
        if ($order_id <= 0) {
            return null;
        }
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            global $wpdb;
            if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
                return null;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The binding needs the stored row, not a cached or request-rounded copy.
            $stored = $wpdb->get_var($wpdb->prepare(
                'SELECT total_amount FROM %i WHERE id = %d LIMIT 1',
                $wpdb->prefix . 'wc_orders',
                $order_id
            ));
        } else {
            if (!function_exists('get_post_meta')) {
                return null;
            }
            $stored = get_post_meta($order_id, '_order_total', true);
        }
        if (!is_string($stored) && !is_int($stored)) {
            return null;
        }
        $stored = trim((string) $stored);
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $stored)) {
            return null;
        }
        // Strip insignificant zeros before the length-bounded canonicalizer, so the
        // HPOS form (12.34100000) compares like the CPT form (12.341).
        $stored = ltrim($stored, '0');
        if ($stored === '' || $stored[0] === '.') {
            $stored = '0' . $stored;
        }
        if (strpos($stored, '.') !== false) {
            $stored = rtrim(rtrim($stored, '0'), '.');
        }
        return self::canonical_decimal($stored);
    }

    private static function normalize_track_id($track_id) {
        if (!is_string($track_id) || $track_id === '' || strlen($track_id) > 255) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $track_id)) {
            return null;
        }
        return $track_id;
    }

    private static function normalize_decimal($value) {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        $value = (string) $value;
        if ($value === '' || strlen($value) > 22) {
            return null;
        }
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
            return null;
        }
        return $value;
    }

    private static function canonical_decimal($value) {
        $value = self::normalize_decimal($value);
        if ($value === null) {
            return null;
        }
        $dot = strpos($value, '.');
        if ($dot === false) {
            return $value;
        }
        $integer = substr($value, 0, $dot);
        $fraction = rtrim(substr($value, $dot + 1), '0');
        return $fraction === '' ? $integer : $integer . '.' . $fraction;
    }

    private static function is_allowed_status_url($url, $track_id) {
        if (!is_string($url) || $url === '' || strlen($url) > 500 || !is_string($track_id)) {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || strtolower((string) $parts['scheme']) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if ($host !== 'sandboxapi.upayments.com' && $host !== 'apiv2api.upayments.com') {
            return false;
        }
        $expected_path = '/api/v1/get-payment-status/' . rawurlencode($track_id);
        return (string) $parts['path'] === $expected_path;
    }

    private static function base_result($reason) {
        return array(
            'authenticated' => false,
            'bound' => false,
            'classification' => ProviderResult::INDETERMINATE,
            'transaction' => null,
            'reason' => (string) $reason,
        );
    }

    private function __construct() {}
}
