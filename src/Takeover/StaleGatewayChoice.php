<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * A gateway choice made before cutover, still in flight after it (Sub-project 0b Task 7).
 *
 * A WooCommerce session, or a checkout page left open in a tab, may still carry
 * `upayments` after the gateway was re-registered as `supcheckout`. Within GRACE_SECONDS
 * of the cutover, and only while our supcheckout gateway is available:
 *
 * - a session's chosen_payment_method is re-pointed on checkout (Gateway\Availability);
 * - a posted payment_method of exactly `upayments` is re-pointed to `supcheckout` on the
 *   classic checkout (woocommerce_checkout_posted_data, read by WC_Checkout before it
 *   updates the session and validates the gateway) and on the Store API checkout routes
 *   (rest_request_before_callbacks: the Store API has no hook before it reads and
 *   validates payment_method, so the request parameter is rewritten for those routes
 *   only).
 *
 * The mirror after a rollback (Task 10): within GRACE_SECONDS of `rolled_back_at`, while the
 * store is not cut over and SUPCheckout's own gateway is available at `upayments`, a posted
 * or session value of exactly `supcheckout` (a checkout page or session from the cut-over
 * window) is re-pointed to `upayments` on the same paths. It needs SUPCheckout's runtime, so
 * it covers a rollback that did not reactivate the official plugin; with the official plugin
 * active SUPCheckout is paused and WooCommerce rejects the unknown id as it always did.
 *
 * No other value is ever translated. Outside both windows nothing is registered.
 */
final class StaleGatewayChoice {
    /** Seconds after cutover a pre-cutover choice is still honoured (WooCommerce's 48 h session lifetime). */
    public const GRACE_SECONDS = 172800;

    /** Store API checkout and pay-for-order routes, any version. */
    private const STORE_API_CHECKOUT_ROUTE = '#^/wc/store(?:/v[0-9]+)?/checkout(?:/[0-9]+)?$#D';

    /** @return void */
    public static function register() {
        if (!TakeoverState::is_cut_over() && !self::in_rollback_grace()) {
            return;
        }
        add_filter('woocommerce_checkout_posted_data', array(self::class, 'filter_posted_data'));
        add_filter('rest_request_before_callbacks', array(self::class, 'filter_rest_request'), 10, 3);
    }

    /**
     * @param int|null $now Current time; time() when omitted.
     * @return bool True while a pre-cutover `upayments` choice may still be re-pointed.
     */
    public static function in_grace($now = null) {
        if (!TakeoverState::is_cut_over()) {
            return false;
        }
        $cut_over_at = (int) TakeoverState::get()['cut_over_at'];
        $now = $now === null ? time() : (int) $now;
        return $cut_over_at > 0 && $now < $cut_over_at + self::GRACE_SECONDS;
    }

    /**
     * @param int|null $now Current time; time() when omitted.
     * @return bool True while a `supcheckout` choice from before a rollback may still be
     *              re-pointed to `upayments` (never while cut over).
     */
    public static function in_rollback_grace($now = null) {
        if (TakeoverState::is_cut_over()) {
            return false;
        }
        $state = TakeoverState::get();
        $rolled_back_at = isset($state['rolled_back_at']) && is_int($state['rolled_back_at']) ? $state['rolled_back_at'] : 0;
        $now = $now === null ? time() : (int) $now;
        return $rolled_back_at > 0 && $now < $rolled_back_at + self::GRACE_SECONDS;
    }

    /**
     * Whether SUPCheckout's own gateway (never the official plugin's) is registered at `upayments`.
     *
     * @param mixed $gateways Gateway id => gateway.
     * @return bool
     */
    public static function ours_is_legacy($gateways) {
        return is_array($gateways)
            && isset($gateways[Keys::GATEWAY_LEGACY])
            && $gateways[Keys::GATEWAY_LEGACY] instanceof \Simplixi\SUPCheckout\Gateway\Gateway;
    }

    /**
     * @param mixed $data Classic checkout posted data.
     * @return mixed
     */
    public static function filter_posted_data($data) {
        if (is_array($data) && isset($data['payment_method'])) {
            $translated = self::translated($data['payment_method']);
            if ($translated !== null) {
                $data['payment_method'] = $translated;
            }
        }
        return $data;
    }

    /**
     * @param mixed $response Response so far (returned unchanged).
     * @param mixed $handler  Matched route handler.
     * @param mixed $request  The REST request.
     * @return mixed
     */
    public static function filter_rest_request($response, $handler, $request) {
        if (!$request instanceof \WP_REST_Request
            || !in_array($request->get_method(), array('POST', 'PUT', 'PATCH'), true)
            || preg_match(self::STORE_API_CHECKOUT_ROUTE, (string) $request->get_route()) !== 1
        ) {
            return $response;
        }
        $translated = self::translated($request->get_param('payment_method'));
        if ($translated !== null) {
            $request->set_param('payment_method', $translated);
        }
        return $response;
    }

    /**
     * @param mixed $value Posted payment method.
     * @return string|null The id to use instead, or null to keep the value.
     */
    private static function translated($value) {
        if ($value === Keys::GATEWAY_LEGACY
            && self::in_grace()
            && Keys::find_gateway_id(self::available_gateways()) === Keys::GATEWAY
        ) {
            return Keys::GATEWAY;
        }
        if ($value === Keys::GATEWAY && self::in_rollback_grace() && self::ours_is_legacy(self::available_gateways())) {
            return Keys::GATEWAY_LEGACY;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function available_gateways() {
        $wc = function_exists('WC') ? WC() : null;
        if (!is_object($wc) || !method_exists($wc, 'payment_gateways')) {
            return array();
        }
        $gateways = $wc->payment_gateways();
        if (!is_object($gateways) || !method_exists($gateways, 'get_available_payment_gateways')) {
            return array();
        }
        $available = $gateways->get_available_payment_gateways();
        return is_array($available) ? $available : array();
    }

    private function __construct() {}
}
