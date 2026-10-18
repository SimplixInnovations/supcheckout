<?php

namespace Simplixi\SUPCheckout\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;

defined('ABSPATH') || exit;

/**
 * The checks every supcheckout/v1 request passes (Sub-project 0c Task 7), in this order, so a
 * rejected request never spends a rate slot or creates an operation:
 *
 *  1. can_manage(): `manage_woocommerce` (401 logged out, 403 logged in);
 *  2. writes: the `wp_rest` nonce from the X-WP-Nonce header, verified explicitly, so an
 *     Application Password alone can never start a switch, rollback or finalize (owner
 *     decision 2026-10-18; automation uses WP-CLI) → 403 supcheckout_nonce_invalid;
 *  3. writes: unknown parameters (JSON, body and query keys that are neither the route's
 *     args nor WordPress' reserved keys) → 400 supcheckout_unknown_parameter, so a
 *     misspelled consent flag is never silently ignored;
 *  4. the args' JSON schema, by WordPress core → 400 rest_invalid_param;
 *  5. check_consent(): `confirm` strictly true (finalize: the typed word unless dry_run is
 *     true) → 400 supcheckout_confirmation_required;
 *  6. check_rate() → 429 supcheckout_rate_limited with `retry_after`.
 *
 * Routes::guard() runs 1-3 from `rest_request_before_callbacks`, before core reports a
 * schema error, and the permission callback and controllers run them again. Request input
 * comes only from WP_REST_Request.
 */
final class RequestGuard {
    public const CAPABILITY = 'manage_woocommerce';
    public const NONCE_ACTION = 'wp_rest';

    /** Keys WordPress itself reads from a REST request. */
    public const RESERVED = array('_wpnonce', '_locale', 'rest_route', '_method', '_envelope', '_fields', '_embed');

    /** Routes whose `confirm` must be strictly true. */
    private const CONFIRMED = array('/onboarding/switch', '/onboarding/rollback', '/onboarding/abort', '/onboarding/stale-claims/release');
    private const FINALIZE = '/onboarding/finalize';
    /** What the owner types to finalize. */
    public const FINALIZE_WORD = 'FINALIZE';

    /** @return true|\WP_Error */
    public static function can_manage() {
        if (current_user_can(self::CAPABILITY)) {
            return true;
        }
        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to manage the SUPCheckout switch.', array('status' => rest_authorization_required_code()));
    }

    /**
     * Steps 2 and 3 for a write.
     *
     * @param \WP_REST_Request   $request Request.
     * @param array<int, string> $allowed The route's arg names.
     * @return true|\WP_Error
     */
    public static function check_write(\WP_REST_Request $request, array $allowed) {
        $nonce = $request->get_header('x_wp_nonce');
        if (!is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return Responses::error('supcheckout_nonce_invalid', 'The request is missing a valid WordPress REST nonce (X-WP-Nonce); reload the admin page and try again.', 403);
        }
        $unknown = self::unknown_parameters($request, $allowed);
        if ($unknown !== array()) {
            return Responses::error('supcheckout_unknown_parameter', 'Unknown parameter(s); nothing was done.', 400, array('params' => $unknown));
        }
        return true;
    }

    /**
     * Step 5.
     *
     * @param \WP_REST_Request $request Request (after core sanitized the args).
     * @param string           $route   Route relative to the namespace, e.g. '/onboarding/switch'.
     * @return true|\WP_Error
     */
    public static function check_consent(\WP_REST_Request $request, $route) {
        if ($route === self::FINALIZE) {
            if ($request->get_param('dry_run') === true || $request->get_param('confirm') === self::FINALIZE_WORD) {
                return true;
            }
            return Responses::error('supcheckout_confirmation_required', 'Finalize is irreversible: send confirm "' . self::FINALIZE_WORD . '" (or dry_run true for the plan only).', 400);
        }
        if (in_array($route, self::CONFIRMED, true) && $request->get_param('confirm') !== true) {
            return Responses::error('supcheckout_confirmation_required', 'Send confirm true to start this step; nothing was done.', 400);
        }
        return true;
    }

    /**
     * Step 6.
     *
     * @param string $bucket RateLimiter bucket.
     * @param int    $window Seconds.
     * @param int    $now    Unix time (the caller's, so a release can match it).
     * @return true|\WP_Error
     */
    public static function check_rate($bucket, $window, $now = 0) {
        $now = $now > 0 ? (int) $now : time();
        $wait = RateLimiter::hit($bucket, (int) $window, $now);
        if ($wait > 0) {
            return Responses::error('supcheckout_rate_limited', 'Too many requests; retry in ' . $wait . ' second(s).', 429, array('retry_after' => $wait));
        }
        return true;
    }

    /**
     * @param \WP_REST_Request   $request Request.
     * @param array<int, string> $allowed Arg names.
     * @return list<mixed> The unknown keys, bounded: at most LIST_LIMIT names of at most
     *                     KEY_LIMIT characters, then a `{truncated: n}` marker.
     */
    private static function unknown_parameters(\WP_REST_Request $request, array $allowed) {
        $known = array_flip(array_merge($allowed, self::RESERVED));
        $unknown = array();
        foreach (array($request->get_json_params(), $request->get_body_params(), $request->get_query_params()) as $params) {
            if (!is_array($params)) {
                continue;
            }
            foreach (array_keys($params) as $key) {
                $key = (string) $key;
                if (!isset($known[$key])) {
                    $unknown[$key] = true;
                }
            }
        }
        $names = array();
        foreach (array_slice(array_keys($unknown), 0, Bounded::LIST_LIMIT) as $key) {
            $names[] = function_exists('mb_substr') ? mb_substr((string) $key, 0, Bounded::KEY_LIMIT, 'UTF-8') : substr((string) $key, 0, Bounded::KEY_LIMIT);
        }
        if (count($unknown) > Bounded::LIST_LIMIT) {
            $names[] = array('truncated' => count($unknown) - Bounded::LIST_LIMIT);
        }
        return $names;
    }

    private function __construct() {}
}
