<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\PaymentLifecycle;

defined('ABSPATH') || exit;

/**
 * The two WC-API callback routes (Sub-project 0b Task 8). Permanent.
 *
 * UPayments calls back on the route a Charge put in its URLs: `wc_upayments` for every
 * payment started before cutover (and by the official plugin), `supcheckout`
 * (Keys::callback_route()) for Charges started after. Both routes must reach the same
 * verified lifecycle for as long as UPayments may hold such a URL, which has no end: a
 * webhook for a pre-switch payment can arrive months after Finalize.
 *
 * The legacy route keeps its two listeners exactly where they have always been
 * registered, unconditionally and in every takeover state (never removed, not even by
 * Finalize):
 * - PaymentLifecycle::handle_callback at priority 5 (PaymentLifecycle::bootstrap());
 * - the gateway's check_ipn_response at the default priority 10 (Gateway constructor).
 * This class mirrors that pair onto `woocommerce_api_supcheckout` in the same priority
 * order and from the same two places, so each listener is attached to both routes
 * exactly once per registration and a paused SUPCheckout (official plugin active, no
 * Plugin::boot()) registers neither route:
 * - register(), called from PaymentLifecycle::bootstrap(): handle_callback() at 5;
 * - register_gateway(), called from the Gateway constructor: check_ipn_response at 10.
 * The new route is registered before cutover too, so Preflight can prove it reachable
 * before any Charge uses it. A request that reaches it before cutover gets exactly the
 * legacy route's treatment: payment truth only from authenticated provider status
 * bound to the order, amount and currency, and every unverifiable input fails closed.
 *
 * Preflight probe (`supcheckout_probe=1`, Preflight's callback_unreachable check) is
 * answered on the new route only, before any callback processing: a 200 with
 * no-cache/no-store headers and the PROBE_ACK_HEADER acknowledgement, then exit. It
 * reads that one argument and nothing else, touches no order, writes nothing, calls
 * no provider and schedules nothing, so it adds no side effect to what an
 * unauthenticated request to the route could already cause. Callback URLs are built by
 * WooCommerce from the route alone (Keys::callback_route()) and never carry the
 * argument, so a probe can never stand in for a provider callback. The legacy route
 * does not answer the probe and stays byte-for-byte what it was.
 */
final class LegacyCallbackListener {
    /** The WC-API hook of Keys::CALLBACK_ROUTE (WooCommerce fires woocommerce_api_{route}). */
    public const HOOK = 'woocommerce_api_supcheckout';
    /** Query argument of the preflight probe. */
    public const PROBE_ARG = 'supcheckout_probe';
    /** Response header that acknowledges a probe (lower-case, as Preflight compares it). */
    public const PROBE_ACK_HEADER = 'x-supcheckout-probe';

    /** The only probe value; anything else is not a probe. */
    private const PROBE_VALUE = '1';
    /** Same directives as WordPress's own no-cache headers; never public. */
    private const NO_STORE = 'no-cache, must-revalidate, max-age=0, no-store, private';

    /** @var bool */
    private static $registered = false;

    /**
     * Attaches the lifecycle listener to the new route at priority 5, in every takeover
     * state. Idempotent, like PaymentLifecycle::bootstrap() that calls it.
     *
     * @return void
     */
    public static function register() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action(self::HOOK, array(__CLASS__, 'handle_callback'), 5);
    }

    /**
     * Attaches the gateway's historical priority-10 listener to the new route, beside the
     * constructor's own wc_upayments registration of the same method.
     *
     * @param object $gateway The gateway being constructed.
     * @return void
     */
    public static function register_gateway($gateway) {
        add_action(self::HOOK, array($gateway, 'check_ipn_response'));
    }

    /**
     * Priority-5 listener of the new route: the preflight probe, otherwise the same
     * callback authority as the legacy route.
     *
     * @return void
     */
    public static function handle_callback() {
        if (self::is_probe()) {
            self::answer_probe();
        }
        PaymentLifecycle::handle_callback();
    }

    /**
     * The probe answer alone, for the paused boot (PausedBoot, official plugin active):
     * answers a probe and exits; any other request returns without doing anything, and
     * the payment lifecycle is never reached.
     *
     * @return void
     */
    public static function handle_probe() {
        if (self::is_probe()) {
            self::answer_probe();
        }
    }

    /**
     * @return bool True only for exactly `supcheckout_probe=1` in the query string.
     */
    public static function is_probe() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A fixed-value probe flag; it grants nothing and selects a response that reads no other input.
        return isset($_GET[self::PROBE_ARG]) && $_GET[self::PROBE_ARG] === self::PROBE_VALUE;
    }

    /**
     * Headers of the probe response, name => value.
     *
     * @return array<string, string>
     */
    public static function probe_headers() {
        return array(
            'Cache-Control' => self::NO_STORE,
            'X-SUPCheckout-Probe' => self::PROBE_VALUE,
        );
    }

    /**
     * @return void Never returns.
     */
    private static function answer_probe() {
        if (function_exists('wc_nocache_headers')) {
            wc_nocache_headers();
        } elseif (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (!headers_sent()) {
            foreach (self::probe_headers() as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if (function_exists('status_header')) {
            status_header(200);
        }
        exit;
    }

    private function __construct() {}
}
