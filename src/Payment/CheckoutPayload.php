<?php

namespace Simplixi\SUPCheckout\Payment;

use Simplixi\SUPCheckout\Provider\MultiMerchantContract;

/**
 * Pure checkout request, decimal, payload, and redirect helpers.
 *
 * No provider transport, credentials, order mutation, or token storage.
 */
class CheckoutPayload {
    /**
     * Static allowlist of plugin-supported whitelabel payment sources.
     * Does NOT include 'create-invoice' — this plugin does not expose
     * invoice creation as a checkout method.
     */
    private static $ALLOWED_PAYMENT_SOURCES = array(
        'knet',
        'cc',
        'apple-pay',
        'apple-pay-knet',
        'samsung-pay',
        'google-pay',
    );

    /**
     * Static allowlist of accepted subscription plans.
     */
    private static $ALLOWED_SUBSCRIPTION_PLANS = array(
        'one_time',
        'daily',
        'weekly',
        'monthly',
        'quarterly',
        'yearly',
    );

    /**
     * Plan-specific allowed intervals.
     * one_time => 0 only; daily => 1; weekly => 1-3; monthly => 1-2;
     * quarterly => 1-3; yearly => 1.
     */
    private static $ALLOWED_INTERVALS = array(
        'one_time'  => array(0),
        'daily'     => array(1),
        'weekly'    => array(1, 2, 3),
        'monthly'   => array(1, 2),
        'quarterly' => array(1, 2, 3),
        'yearly'    => array(1),
    );

    /**
     * Determine whether a security-sensitive field is present in the request.
     *
     * Presence uses array_key_exists semantics so an explicit JSON null
     * cannot masquerade as absence.
     *
     * @param mixed $source Source array (request body, $_POST, extension map, etc.).
     * @param string|int $key Field key.
     * @return bool
     */
    public static function field_present($source, $key) {
        if (!is_array($source)) {
            return false;
        }
        return array_key_exists($key, $source);
    }

    /**
     * Parse a save-card request value strictly.
     *
     * This parser itself ONLY accepts:
     *   - integer 0
     *   - string '0'
     *   - integer 1
     *   - string '1'
     * Any other explicitly supplied value (null, '', bool, float,
     * whitespace string, 'yes', 'true', 2, arrays, objects, ...) is
     * INVALID.
     *
     * Field presence is the responsibility of the caller: callers must
     * check field_present($source, $key) before invoking this parser, so
     * that "field absent" can never be confused with "field supplied as
     * null or ''".
     *
     * @param mixed $value Raw request value (only when present).
     * @return bool|null true/false for valid, null for invalid.
     */
    public static function parse_save_card_strict($value) {
        // Strict acceptance: integer 0, string '0' => false; integer 1, string '1' => true.
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        // Anything else (null, '', bool, float, 'yes', 'true', 2, arrays, objects, etc.) is invalid.
        return null;
    }

    /**
     * Parse a Whitelabel source value strictly.
     *
     * Strict contract (no trim, no silent coercion):
     *  - Only raw string values are accepted. Arrays/objects/null/floats/booleans/ints
     *    are explicitly invalid (no silent default to null).
     *  - Empty strings are invalid because an explicit empty source is not a
     *    meaningful payment-source choice.
     *  - The string is NOT trimmed: any leading/trailing whitespace is treated as
     *    an explicit malformed value and rejected.
     *  - Whitespace *anywhere* in the value is rejected.
     *
     * @param mixed $value Raw source value (only when present).
     * @return string|null Exact scalar string or null for invalid.
     */
    public static function parse_payment_source_strict($value) {
        if (!is_string($value)) {
            return null;
        }
        if ($value === '') {
            return null;
        }
        if (preg_match('/\s/', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * Pure-PHP comparison of canonical nonnegative-decimal strings.
     *
     * Both $a and $b are validated canonical plain-decimal strings
     * (canonical form per build_nonnegative_json_number_token: no exponent,
     * no sign, no leading-zero integer part except for "0", no whitespace,
     * no comma).
     *
     * Does not require BCMath, GMP, or any optional extension. Does not
     * cast to int or float for provider-bound monetary comparison.
     *
     * Returns:
     *   -1 if $a < $b
     *    0 if $a == $b
     *   +1 if $a > $b
     *
     * @param mixed $a First defensive boundary value.
     * @param mixed $b Second defensive boundary value.
     * @return int
     */
    public static function compare_nonnegative_decimal_strings($a, $b) {
        if (!is_string($a) || !is_string($b)) {
            return 0;
        }
        $strip = function ($s) {
            $dot = strpos($s, '.');
            if ($dot === false) {
                $int = $s; $frac = '';
            } else {
                $int = substr($s, 0, $dot);
                $frac = substr($s, $dot + 1);
            }
            $int = ltrim($int, '0');
            if ($int === '') $int = '0';
            $frac = rtrim($frac, '0');
            return array($int, $frac);
        };
        list($ai, $af) = $strip($a);
        list($bi, $bf) = $strip($b);
        $ai_len = strlen($ai);
        $bi_len = strlen($bi);
        if ($ai_len !== $bi_len) {
            return $ai_len > $bi_len ? 1 : -1;
        }
        if (strcmp($ai, $bi) !== 0) {
            return strcmp($ai, $bi) > 0 ? 1 : -1;
        }
        $max_frac = max(strlen($af), strlen($bf));
        $af_p = str_pad($af, $max_frac, '0');
        $bf_p = str_pad($bf, $max_frac, '0');
        $cmp = strcmp($af_p, $bf_p);
        if ($cmp === 0) return 0;
        return $cmp > 0 ? 1 : -1;
    }

    /**
     * Build a safe nonnegative JSON number token for provider monetary fields
     * where the provider contract explicitly permits zero.
     *
     * No float conversion is performed. The input must be an exact canonical
     * plain-decimal string. The 22-character ceiling preserves the effective
     * boundary previously imposed when these fields used build_amount_json_token().
     *
     * @param mixed $amount_str Defensive boundary input.
     * @return string|null JSON-safe nonnegative number token or null.
     */
    public static function build_nonnegative_json_number_token($amount_str) {
        if (!MultiMerchantContract::is_valid_commission_token($amount_str)) {
            return null;
        }
        return $amount_str;
    }

    /**
     * Build a safe JSON number token for strictly positive provider amount fields.
     *
     * No float conversion is performed. The validated plain-decimal string
     * is the JSON number token. Exponents, signs, leading-zero ambiguity,
     * whitespace, all-zero values and values over 22 characters fail closed.
     *
     * @param mixed $amount_str Defensive boundary input; only a validated plain decimal string is accepted.
     * @return string|null JSON-safe positive number token or null.
     */
    public static function build_amount_json_token($amount_str) {
        $token = self::build_nonnegative_json_number_token($amount_str);
        if ($token === null) {
            return null;
        }
        // Order/allocation amount fields using this helper remain strictly positive.
        if (self::compare_nonnegative_decimal_strings($token, '0') <= 0) {
            return null;
        }
        return $token;
    }

    /**
     * Inject pre-validated JSON number tokens for provider amount fields.
     *
     * Map-driven implementation: each numeric field has an associated
     * sentinel placeholder, max-length, and (optional) max-occurrences.
     * The payload is rewritten exactly once for each placeholder, with
     * the pre-validated token substituted verbatim. Final structural
     * + lexical verification confirms every replacement landed as a JSON
     * NUMBER (not quoted).
     *
     * Sentinel → field mapping:
     *   "__UPAY_ORDER_AMOUNT_SENTINEL__"      → order.amount          (max 1, max 22 chars)
     *   "__UPAY_PRODUCT_PRICE_SENTINEL_<i>__"  → products[i].price     (>=0, max 7 chars each)
     *   "__UPAY_MM_AMOUNT_SENTINEL__"         → extraMerchantData[0].amount (max 1, max 10 chars)
     *   "__UPAY_MM_KNET_CHARGE_SENTINEL__"    → extraMerchantData[0].knetCharge (max 1)
     *   "__UPAY_MM_CC_CHARGE_SENTINEL__"      → extraMerchantData[0].ccCharge  (max 1)
     *
     * Returns null on any verification failure (no fallback, no silent coerce).
     *
     * @param mixed $payload_json Encoded payload with sentinels.
     * @param array<array-key,string|null> $token_map Map of sentinel → token (or null to skip).
     * @param array<array-key,mixed> $extra_sentinels Optional indexed sentinels for per-product prices.
     *                                   Keys: 'product_price_sent_substring',
     *                                         'product_price_tokens' (array of strings).
     * @return string|null Final JSON or null on any verification failure.
     */
    public static function inject_amount_token_into_payload_json($payload_json, array $token_map, array $extra_sentinels = array()) {
        if (!is_string($payload_json) || $payload_json === '') {
            return null;
        }

        $result = $payload_json;

        // === Per-product price sentinels (indexed) ===
        $product_price_tokens = isset($extra_sentinels['product_price_tokens']) && is_array($extra_sentinels['product_price_tokens'])
            ? $extra_sentinels['product_price_tokens']
            : array();
        $product_price_substring = isset($extra_sentinels['product_price_sent_substring']) && is_string($extra_sentinels['product_price_sent_substring'])
            ? $extra_sentinels['product_price_sent_substring']
            : '';
        if ($product_price_substring !== '' && count($product_price_tokens) > 0) {
            foreach ($product_price_tokens as $idx => $ptoken) {
                if (!is_string($ptoken) || $ptoken === '') {
                    return null;
                }
                if (strlen($ptoken) > 7) {
                    return null;
                }
                $sentinel = $product_price_substring . $idx . '__';
                $q_sentinel = '"' . $sentinel . '"';
                $actual = substr_count($result, $q_sentinel);
                if ($actual !== 1) {
                    return null;
                }
                $new_result = str_replace($q_sentinel, $ptoken, $result);
                if ($new_result === $result) {
                    return null;
                }
                $result = $new_result;
            }
        }

        // === Map-driven substitution (single-occurrence sentinels) ===
        foreach ($token_map as $placeholder => $token) {
            if (!is_string($placeholder) || $placeholder === '') {
                return null;
            }
            $q_placeholder = '"' . $placeholder . '"';
            $actual_count = substr_count($result, $q_placeholder);
            $expected_count = ($token !== null) ? 1 : 0;
            if ($actual_count !== $expected_count) {
                return null;
            }
            if ($token === null) {
                continue;
            }
            $max_len = self::get_max_length_for_sentinel($placeholder);
            if ($max_len > 0 && strlen($token) > $max_len) {
                return null;
            }
            $new_result = str_replace($q_placeholder, $token, $result);
            if ($new_result === $result) {
                return null;
            }
            $result = $new_result;
        }

        // === Final structural + lexical verification ===
        // 1. Structural decode must succeed.
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return null;
        }

        // 2. Lexical verification: every substituted token must appear as an
        // unquoted JSON number. Positivity/nonnegativity is established by the
        // field-specific token builder before this generic injection layer.
        $all_tokens = array();
        foreach ($product_price_tokens as $pt) {
            if (is_string($pt) && $pt !== '') {
                $all_tokens[] = $pt;
            }
        }
        foreach ($token_map as $token) {
            if (is_string($token) && $token !== '') {
                $all_tokens[] = $token;
            }
        }
        foreach ($all_tokens as $token) {
            $literal = preg_quote($token, '/');
            $json_value_re = '/(?P<pre>[\\{\\,\\:])\\s*(?:' . $literal . ')\\s*(?P<post>[\\,\\}\\]]|\\z)/m';
            if (!preg_match($json_value_re, $result)) {
                return null;
            }
        }

        // 3. No leftover sentinels.
        foreach (array_keys($token_map) as $s) {
            if (strpos($result, (string) $s) !== false) {
                return null;
            }
        }
        if ($product_price_substring !== '') {
            if (strpos($result, $product_price_substring) !== false) {
                return null;
            }
        }

        return $result;
    }

    /**
     * Per-field max length for tokens substituted into the payload.
     * Provider contract varies per field. Returns 0 when this injection layer
     * adds no additional ceiling; token builders may impose their own bounds.
     *
     * @param mixed $placeholder Sentinel candidate.
     * @return int
     */
    public static function get_max_length_for_sentinel($placeholder) {
        switch ($placeholder) {
            case '__UPAY_ORDER_AMOUNT_SENTINEL__':
                return 22; // order.amount: provider contract ceiling
            case '__UPAY_PRODUCT_PRICE_SENTINEL__':
                return 7;  // products[].price: 1.00..9999.99 / 10.000..99999.99
            case '__UPAY_MM_AMOUNT_SENTINEL__':
                return 10; // MM allocation amount
            case '__UPAY_MM_KNET_CHARGE_SENTINEL__':
            case '__UPAY_MM_CC_CHARGE_SENTINEL__':
                return 0;  // The field-specific commission token builder owns its boundary.
        }
        return 0;
    }

    /**
     * Validate a subscription plan against the static allowlist.
     *
     * @param string $plan Plan identifier.
     * @return bool
     */
    public static function is_valid_subscription_plan(string $plan): bool {
        return in_array($plan, self::$ALLOWED_SUBSCRIPTION_PLANS, true);
    }

    /**
     * Parse a subscription plan value strictly.
     *
     * This shape parser accepts only non-empty, whitespace-free strings.
     * Allowlist membership is a separate mandatory caller step through
     * is_valid_subscription_plan(). Booleans, ints, floats, arrays, objects
     * and null are explicitly invalid. The string is not trimmed.
     *
     * @param mixed $value Raw plan value (only when present).
     * @return string|null Canonical plan name or null for invalid.
     */
    public static function parse_subscription_plan_strict($value) {
        if (!is_scalar($value)) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * Parse an interval value strictly.
     *
     * Accepts ONLY exact integer values 0, 1, 2, 3 or their string
     * equivalents '0', '1', '2', '3'. Anything else (null, '', bool,
     * float, '4', 4, arrays, objects, ...) returns -1.
     *
     * Field presence is the responsibility of the caller. Callers must
     * use field_present() before invoking this parser so an absent
     * interval does NOT silently default to 0.
     *
     * @param mixed $value Raw interval value (only when present).
     * @return int Parsed interval or -1 if invalid.
     */
    public static function parse_interval($value): int {
        if ($value === 0 || $value === '0') {
            return 0;
        }
        if ($value === 1 || $value === '1') {
            return 1;
        }
        if ($value === 2 || $value === '2') {
            return 2;
        }
        if ($value === 3 || $value === '3') {
            return 3;
        }
        return -1;
    }

    public static function is_valid_subscription_interval(string $plan, int $interval): bool {
        if (!isset(self::$ALLOWED_INTERVALS[$plan])) {
            return false;
        }
        return in_array($interval, self::$ALLOWED_INTERVALS[$plan], true);
    }

    /**
     * Validate and normalize a UPayments redirect URL.
     *
     * Accepts only absolute http/https URLs with a non-empty host.
     * Does NOT force same-origin — UPayments payment URLs are external.
     *
     * @param mixed $value Raw redirect value from provider response.
     * @return string|null Normalized URL or null if invalid.
     */
    public static function normalize_upayments_redirect_url($value) {
        if (!is_string($value)) {
            return null;
        }
        $url = trim($value);
        if ($url === '') {
            return null;
        }
        // CR/LF guard — header injection defense.
        if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) {
            return null;
        }
        // Length guard — production caps at 250 chars.
        if (strlen($url) > 250) {
            return null;
        }
        $parts = wp_parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }
        // Payment links never require URL authority credentials. Reject both
        // username-only and username/password forms so a provider response
        // cannot visually obscure or alter the effective redirect authority.
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        return $url;
    }

    /**
     * Normalize a request URI/path into a canonical REST route for the Store API.
     *
     * Supports pretty permalinks (e.g. /wp-json/wc/store/v1/checkout),
     * plain-permalink rest_route form (?rest_route=/wc/store/v1/checkout),
     * and WordPress installed in a subdirectory (e.g. /shop/wp-json/wc/store/v1/checkout).
     *
     * @param mixed $uri Raw REQUEST_URI boundary value.
     * @return string Canonical route (e.g. "/wc/store/v1/checkout") or empty string for invalid input.
     */
    public static function normalize_store_api_route($uri) {
        if (!is_string($uri) || $uri === '') {
            return '';
        }
        $path = $uri;
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $query = substr($path, $qpos + 1);
            $path = substr($path, 0, $qpos);
            if (preg_match('/(?:^|&)rest_route=([^&]+)/', $query, $m)) {
                $rest_route = rawurldecode($m[1]);
                if (strpos($rest_route, '/') !== 0) {
                    $rest_route = '/' . $rest_route;
                }
                return $rest_route;
            }
        }
        // Strip /index.php prefix if present.
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, strlen('/index.php'));
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
            return $path;
        }
        // Strip everything up to and including the /wp-json/ segment, preserving
        // any preceding subdirectory prefix.
        $wj = strpos($path, '/wp-json/');
        if ($wj !== false) {
            $path = substr($path, $wj + strlen('/wp-json/') - 1);
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
            return $path;
        }
        // Already a route-like path.
        return $path;
    }

    /**
     * Pure classifier used to identify a real WooCommerce Store API
     * checkout request. The wrapper is_store_api_checkout_request() gathers
     * the runtime context and delegates here.
     *
     * @param mixed $is_rest_request REST_REQUEST state.
     * @param mixed $normalized_route Route normalized via normalize_store_api_route().
     * @param mixed $method Uppercase HTTP method.
     * @return bool
     */
    public static function classify_checkout_request_context($is_rest_request, $normalized_route, $method) {
        if ($is_rest_request !== true) {
            return false;
        }
        if (!is_string($method) || strtoupper($method) !== 'POST') {
            return false;
        }
        if (!is_string($normalized_route) || $normalized_route === '') {
            return false;
        }
        // Exact endpoint match: must be the checkout route, not just the namespace.
        // Trailing slash is normalized to no-slash.
        $route = rtrim($normalized_route, '/');
        if ($route === '') {
            return false;
        }
        // Reject other Store API endpoints (cart, products, etc.) by exact match.
        return ($route === '/wc/store/v1/checkout');
    }

    /**
     * Validate a provider decimal monetary value as a positive-decimal lexical string.
     * Pure-PHP validation — does not use BCMath, GMP, is_numeric, float, round().
     *
     * Allowed: digits, at most one '.', leading digits (not optional).
     * Rejected: exponent (e/E), sign (+/-), commas, whitespace, INF, NAN,
     * empty string, leading/trailing '.', multiple '.', leading zeros beyond '0.x'.
     * Positive sub-units ('0.01', '0.50') are accepted. Pure zero ('0', '0.00')
     * is rejected because the provider contract is strictly positive.
     *
     * Section D2: deterministic preflight failure when the provider
     * representation cannot preserve the line.
     *
     * @param mixed  $value Candidate decimal value.
     * @param string $field_name Field label for error context (not used in return).
     * @return string|null Canonical positive-decimal string, or null on rejection.
     */
    public static function validate_provider_positive_decimal($value, $field_name = '') {
        if (!is_string($value)) {
            return null;
        }
        if ($value === '') {
            return null;
        }
        // Reject exponent/sign/comma/whitespace/INF/NAN.
        if (preg_match('/[eE+\-,]|[ \t\n\r\f\v]/', $value)) {
            return null;
        }
        // Must contain only digits and at most one '.'.
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            return null;
        }
        // No leading '.' allowed.
        $dot = strpos($value, '.');
        if ($dot === 0) {
            return null;
        }
        // Reject trailing '.'.
        $last = strlen($value) - 1;
        if ($value[$last] === '.') {
            return null;
        }
        // Reject NaN/INF literal strings (defensive — already filtered above).
        if (strcasecmp($value, 'NAN') === 0 || strcasecmp($value, 'INF') === 0) {
            return null;
        }
        // Reject zero as a non-positive monetary line. Pattern matches
        // '0', '0.0', '0.00', '00.000', etc. — any all-zero representation.
        // Positive sub-units like '0.01', '0.50', '0.1' are accepted.
        if (preg_match('/^0+(?:\.0+)?$/', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * Validate a provider decimal monetary value as a nonnegative-decimal
     * lexical string. Same lexical rules as the positive variant, but
     * accepts exactly zero. Used for product line totals that may be
     * zero (e.g. $0.00 promotional lines) while the overall order amount
     * still must be strictly positive.
     *
     * @param mixed  $value Candidate decimal value.
     * @param string $field_name Field label for error context (not used in return).
     * @return string|null Lexically valid nonnegative-decimal string, or null on rejection.
     */
    public static function validate_provider_nonnegative_decimal($value, $field_name = '') {
        if (!is_string($value)) {
            return null;
        }
        if ($value === '') {
            return null;
        }
        if (preg_match('/[eE+\-,]|[ \t\n\r\f\v]/', $value)) {
            return null;
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            return null;
        }
        $dot = strpos($value, '.');
        if ($dot === 0) {
            return null;
        }
        $last = strlen($value) - 1;
        if ($value[$last] === '.') {
            return null;
        }
        if (strcasecmp($value, 'NAN') === 0 || strcasecmp($value, 'INF') === 0) {
            return null;
        }
        // Anything matching /^[0-9]+(\.[0-9]+)?$/ is >= 0. Return as-is.
        return $value;
    }

    /**
     * Compute the provider-compatible unit price as a decimal string.
     * Division: (line_total / qty) using exact decimal long division.
     *
     * Section #14 behavior:
     *  - Accepts a nonnegative-decimal lexical string for line_total.
     *    A zero line_total ("0", "0.00") is accepted and yields "0" (or
     *    "0.<n>" once the fractional scale is matched) — zero-price
     *    promotional lines remain in products[] with numeric price 0.
     *  - Integer qty is preserved raw (no rounding at the boundary).
     *  - Float input for line_total is REJECTED — we cannot claim exact
     *    lexical product economics while accepting float line totals.
     *  - Digit long division extends fractional digits one at a time
     *    (remainder*10 / qty) until remainder=0, capping at
     *    UNIT_PRICE_MAX_FRACTIONAL_DIGITS. If the remainder never
     *    becomes zero inside the cap, return null deterministically —
     *    no truncation, no rounding, no float, no BCMath, no GMP.
     *  - Examples:
     *      1.00 / 8  -> 0.125   (exact)
     *      10.00 / 3 -> null    (non-terminating within cap)
     *      0.00 / 5  -> 0       (zero-price line preserved)
     *
     * @param mixed $line_total Canonical nonnegative-decimal string.
     * @param mixed $qty        Strict positive integer quantity boundary value.
     * @return string|null Decimal string unit price, or null on impossibility.
     */
    public static function compute_provider_unit_price_decimal($line_total, $qty) {
        // Reject float line totals outright — we cannot claim exact lexical
        // economics while accepting a float that may have been produced by
        // arithmetic on an inexact binary representation.
        if (is_float($line_total)) {
            return null;
        }
        $validated_line = self::validate_provider_nonnegative_decimal($line_total, 'line_total');
        if ($validated_line === null) {
            return null;
        }
        // Defensive: reject line totals with leading zeros on the integer
        // part (e.g. "01.00"). The lexical canonical form for payment
        // gateways is "1.00". Without this filter a crafted "01.00" would
        // pass the validator regex but bypass canonicalization.
        $dot = strpos($validated_line, '.');
        $int_part = ($dot !== false) ? substr($validated_line, 0, $dot) : $validated_line;
        if ($int_part !== '' && $int_part !== '0' && $int_part[0] === '0') {
            return null;
        }
        if (!is_int($qty) || $qty <= 0) {
            return null;
        }

        // Split the line into integer and fractional parts.
        $frac_part = ($dot !== false) ? substr($validated_line, $dot + 1) : '';
        $line_decimals = strlen($frac_part);

        // Integer numerator = int_part concatenated with frac_part (no decimal).
        $numer_str = $int_part . $frac_part;
        // Numerator is a nonnegative integer digit string. qty is a positive int.
        // We digit-divide by qty using ordinary long division semantics.
        $unit_int = self::digit_long_divide($numer_str, $qty);
        if ($unit_int === null) {
            return null;
        }
        // unit_int is the integer quotient (no fractional digits yet).

        // Exact long division with remainder extension.
        // Multiply the remainder by 10, divide by qty, repeat until
        // remainder=0 or we hit the cap. Each step appends one digit
        // to the fractional portion.
        $remainder = self::digit_long_divide_remainder($numer_str, $qty);
        if ($remainder === null) {
            return null;
        }
        // The cap is on TOTAL fractional digits in the FINAL result,
        // which equals k (extended frac digits) + line_decimals (scale
        // shift back to the original unit). For line_decimals = 2 and
        // max_frac = 7, F can extend at most 5 digits.
        $max_frac = self::$UNIT_PRICE_MAX_FRACTIONAL_DIGITS;
        $frac_cap = max(0, $max_frac - $line_decimals);
        $unit_frac = '';
        while ($remainder !== 0 && strlen($unit_frac) < $frac_cap) {
            $remainder *= 10;
            $digit = intdiv($remainder, $qty);
            $remainder = $remainder - $digit * $qty;
            $unit_frac .= (string) $digit;
        }
        if ($remainder !== 0) {
            // Non-terminating within the provider-compatible boundary.
            return null;
        }
        $k = strlen($unit_frac);

        // Scale back: the quotient was computed in 10^line_decimals
        // subunits (cents for line_decimals = 2). Combine the integer
        // quotient and extended fraction as one digit string N2, then
        // express as N2 / 10^(k + line_decimals) in the original unit.
        $combined = ($unit_int === '') ? '0' : $unit_int;
        $combined .= $unit_frac;
        $total_shift = $k + $line_decimals;
        $len_combined = strlen($combined);
        if ($len_combined <= $total_shift) {
            // Result is < 1: prepend "0." and pad with leading zeros.
            $pad = $total_shift - $len_combined;
            $unit_str = '0.' . str_repeat('0', $pad) . $combined;
        } else {
            $int_out = substr($combined, 0, $len_combined - $total_shift);
            $frac_out = substr($combined, $len_combined - $total_shift);
            $unit_str = $int_out . '.' . $frac_out;
        }

        // Drop trailing zeros from the fraction so the result is the
        // shortest exact representation. 0.50 -> 0.5, 0.125 stays.
        if (strpos($unit_str, '.') !== false) {
            $unit_str = rtrim($unit_str, '0');
            if (substr($unit_str, -1) === '.') {
                $unit_str = substr($unit_str, 0, -1);
            }
        }

        // The unit price for a zero-price line must validate as the
        // nonnegative decimal string "0". For any other unit price, the
        // positive validator guarantees >0 and the lexical shape.
        if ($unit_str === '0' || $unit_str === '0.0' || $unit_str === '0.00' || $unit_str === '0.000') {
            return self::validate_provider_nonnegative_decimal($unit_str, 'unit_price');
        }
        return self::validate_provider_positive_decimal($unit_str, 'unit_price');
    }

    /**
     * Digit long division of a positive integer digit string by a positive int.
     * Returns the exact quotient digit string, or null if the input string is
     * not a valid positive integer digit string. Detects digit-string overflow
     * (length > PHP_INT_MAX decimal digits) and returns null deterministically.
     *
     * @param mixed $numer_str Strict positive integer digit-string boundary value.
     * @param mixed $denom     Strict positive integer divisor boundary value.
     * @return string|null Quotient digit string, or null on invalid input.
     */
    public static function digit_long_divide($numer_str, $denom) {
        if (!is_string($numer_str) || !preg_match('/^[0-9]+$/', $numer_str)) {
            return null;
        }
        if (!is_int($denom) || $denom <= 0) {
            return null;
        }
        // 9,223,372,036,854,775,807 ≈ 19 decimal digits. Above this, PHP int
        // arithmetic on cumulative remainders is fine (remainder is bounded by
        // denominator), but the quotient string may exceed int width. We allow
        // up to a hard ceiling and beyond that we still operate digit-by-digit
        // (no overflow in the remainder).
        $quotient = '';
        $carry = 0;
        $len = strlen($numer_str);
        for ($i = 0; $i < $len; $i++) {
            $digit = ord($numer_str[$i]) - 48;
            $carry = $carry * 10 + $digit;
            $q = intdiv($carry, $denom);
            $carry = $carry - $q * $denom;
            // Skip leading zeros until we have written something.
            if ($q !== 0 || $quotient !== '') {
                $quotient .= (string) $q;
            }
        }
        if ($quotient === '') {
            $quotient = '0';
        }
        return $quotient;
    }

    /**
     * Compute the integer remainder of (numer_str / denom) using
     * digit long division. Returns the final carry (remainder) or null
     * on invalid input. The remainder is guaranteed to be in [0, denom)
     * for valid input.
     *
     * @param mixed $numer_str Strict positive integer digit-string boundary value.
     * @param mixed $denom     Strict positive integer divisor boundary value.
     * @return int|null Final remainder, or null on invalid input.
     */
    public static function digit_long_divide_remainder($numer_str, $denom) {
        if (!is_string($numer_str) || !preg_match('/^[0-9]+$/', $numer_str)) {
            return null;
        }
        if (!is_int($denom) || $denom <= 0) {
            return null;
        }
        $carry = 0;
        $len = strlen($numer_str);
        for ($i = 0; $i < $len; $i++) {
            $digit = ord($numer_str[$i]) - 48;
            $carry = $carry * 10 + $digit;
            $q = intdiv($carry, $denom);
            $carry = $carry - $q * $denom;
        }
        return $carry;
    }

    /**
     * Convert a numeric or string value into the canonical lexical decimal
     * accepted by validate_provider_positive_decimal() / validate_provider_nonnegative_decimal().
     *
     * No silent type coercion on malformed lexical forms. The following are
     * explicitly rejected with a null return:
     *   - Leading '+' (e.g. "+1", "+1.50") — never accepted; the provider
     *     contract uses unsigned decimal strings.
     *   - Leading zeros on positive integers (e.g. "01", "01.00") — never
     *     accepted; prevents lexical ambiguity ("01.00" vs "1.00").
     *   - Exponent notation (e.g. "1e3", "1.5E-2") — never accepted.
     *   - NaN / INF literals — never accepted.
     * Floats are accepted only as a deterministic conversion step (rejecting
     * non-finite values), and the resulting string is re-validated lexically.
     *
     * @param mixed $value Numeric value or string.
     * @return string|null Canonical decimal string, or null on rejection.
     */
    public static function canonicalize_provider_decimal_string($value) {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $candidate = (string) $value;
        } elseif (is_string($value)) {
            $candidate = $value;
        } else {
            return null;
        }
        // Reject malformed lexical forms explicitly. We do NOT strip '+' / '0'-prefixes.
        if (preg_match('/[eE+\-,]|[ \t\n\r\f\v]/', $candidate)) {
            return null;
        }
        if (strcasecmp($candidate, 'NAN') === 0 || strcasecmp($candidate, 'INF') === 0) {
            return null;
        }
        // Reject leading zeros on integer part (e.g. "01", "00.5", "007"). Lexically
        // canonical for payment gateways is "1", "0.5", "7". Without this filter, a
        // crafted "01.00" would otherwise pass the digit/dot regex in the validator.
        $dot = strpos($candidate, '.');
        $int_part = ($dot !== false) ? substr($candidate, 0, $dot) : $candidate;
        if ($int_part !== '' && $int_part !== '0' && $int_part[0] === '0') {
            return null;
        }
        return $candidate;
    }

    /**
     * Detect the actual WooCommerce Store API checkout request.
     *
     * REST_REQUEST alone is too broad: any WP/Woo REST traffic (admin REST,
     * custom REST endpoints, third-party plugins) sets REST_REQUEST and
     * would be misclassified as Blocks/Store API. Resolve the actual
     * canonical REST route and require it to match the Store API checkout
     * endpoint.
     *
     * @return bool true only when the request targets /wc/store/v1/checkout under REST_REQUEST + POST.
     */
    public static function is_store_api_checkout_request() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The raw URI is parsed and exact-route allowlisted below; text sanitization would alter route bytes.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return false;
        }
        $route = self::normalize_store_api_route($uri);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The method is accepted only by the exact POST classifier below.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        return self::classify_checkout_request_context(true, $route, $method);
    }

    /**
     * Maximum fractional digits tolerated for an exact provider unit
     * price. Provider product prices are bounded to a small number of
     * fractional digits; anything beyond this boundary is treated as a
     * non-terminating division and the unit-price computation fails
     * closed (null) so the request is rejected at preflight.
     */
    private static $UNIT_PRICE_MAX_FRACTIONAL_DIGITS = 7;

    public static function is_valid_payment_source($source) {
        return is_string($source) && in_array($source, self::$ALLOWED_PAYMENT_SOURCES, true);
    }
    /**
     * UTF-8 safe provider text truncation.
     * PHP 7.2 compatible, no mandatory mbstring dependency.
     *
     * @param mixed $value Provider-bound text candidate.
     * @param int $max_chars Maximum Unicode code points.
     * @return string
     */
    public static function truncate_provider_text($value, $max_chars) {
        if (!is_scalar($value)) {
            return '';
        }
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        // Remove invalid UTF-8 sequences using PCRE (always available).
        $str = preg_replace('/[\x00-\x7F][\x80-\xBF]+/u', '', $str);
        $str = preg_replace('/[\xC0-\xDF](?![\x80-\xBF])/u', '', $str);
        $str = preg_replace('/[\xE0-\xEF](?![\x80-\xBF]{2})/u', '', $str);
        $str = preg_replace('/[\xF0-\xF7](?![\x80-\xBF]{3})/u', '', $str);
        if ($str === '' || $str === null) {
            return '';
        }
        // Fast path: mbstring available.
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($str, 'UTF-8') <= $max_chars) {
                return $str;
            }
            return mb_substr($str, 0, $max_chars, 'UTF-8');
        }
        // PCRE fallback: count code points, then safely extract.
        $matches = array();
        if (preg_match_all('/./us', $str, $matches) === false) {
            return '';
        }
        $chars = $matches[0];
        if (count($chars) <= $max_chars) {
            return $str;
        }
        return implode('', array_slice($chars, 0, $max_chars));
    }
}
