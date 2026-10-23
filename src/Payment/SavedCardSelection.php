<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

final class SavedCardSelection {
    private const PREFIX = 'sc1_';
    private const CONTEXT_DOMAIN = 'supcheckout_saved_card_selection_context_v1';
    private const HANDLE_DOMAIN = 'supcheckout_saved_card_selection_handle_v1';

    /**
     * Build an opaque browser selection handle for a provider card token.
     *
     * The handle is deterministic for the exact site/user/API-mode/token
     * context but does not contain or reveal the provider token.
     *
     * @param mixed $provider_token Provider saved-card token.
     * @param mixed $user_id Current WordPress user ID.
     * @param mixed $api_key Current UPayments API key.
     * @param mixed $is_test_mode Current provider mode.
     */
    public static function create($provider_token, $user_id, $api_key, $is_test_mode): ?string {
        $token = self::provider_token($provider_token);
        $context_key = self::context_key($user_id, $api_key, $is_test_mode);

        if ($token === null || $context_key === null) {
            return null;
        }

        $message = self::HANDLE_DOMAIN . '|' . $token;
        $digest = hash_hmac('sha256', $message, $context_key);

        if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            return null;
        }

        return self::PREFIX . $digest;
    }

    /**
     * Resolve an opaque browser selection against a freshly retrieved provider
     * card list. A handle is never authorization by itself.
     *
     * @param mixed $handle Browser-submitted saved-card selection handle.
     * @param array<mixed> $cards Fresh provider saved-card list.
     * @param mixed $user_id Current WordPress user ID.
     * @param mixed $api_key Current UPayments API key.
     * @param mixed $is_test_mode Current provider mode.
     */
    public static function resolve($handle, array $cards, $user_id, $api_key, $is_test_mode): ?string {
        if (!is_string($handle) || !self::is_handle($handle)) {
            return null;
        }

        $context_key = self::context_key($user_id, $api_key, $is_test_mode);
        if ($context_key === null) {
            return null;
        }

        $matched = null;
        foreach ($cards as $card) {
            if (!is_array($card) || !array_key_exists('token', $card)) {
                continue;
            }

            $token = self::provider_token($card['token']);
            if ($token === null) {
                continue;
            }

            $digest = hash_hmac('sha256', self::HANDLE_DOMAIN . '|' . $token, $context_key);
            $candidate = is_string($digest) ? self::PREFIX . $digest : '';

            if ($candidate !== '' && hash_equals($handle, $candidate)) {
                $matched = $token;
            }
        }

        return $matched;
    }

    /**
     * Resolve a selected-card checkout submission against one fresh provider
     * card list. New UI submits opaque handles; legacy already-rendered/custom
     * clients may still submit the provider token directly.
     *
     * A handle-shaped provider token remains backward compatible: if opaque
     * resolution does not match, exact membership in the same fresh list is
     * checked without a second provider Retrieve.
     *
     * @param mixed $submitted Browser-submitted selection/token value.
     * @param array<mixed> $cards Fresh provider saved-card list.
     * @param mixed $user_id Current WordPress user ID.
     * @param mixed $api_key Current UPayments API key.
     * @param mixed $is_test_mode Current provider mode.
     */
    public static function resolve_submission($submitted, array $cards, $user_id, $api_key, $is_test_mode): ?string {
        $submitted_token = self::provider_token($submitted);
        if ($submitted_token === null) {
            return null;
        }

        if (self::is_handle($submitted_token)) {
            $resolved = self::resolve($submitted_token, $cards, $user_id, $api_key, $is_test_mode);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $legacy_match = null;
        foreach ($cards as $card) {
            if (!is_array($card) || !array_key_exists('token', $card)) {
                continue;
            }

            $provider_token = self::provider_token($card['token']);
            if ($provider_token === null || strlen($provider_token) !== strlen($submitted_token)) {
                continue;
            }

            if (hash_equals($provider_token, $submitted_token)) {
                $legacy_match = $provider_token;
            }
        }

        return $legacy_match;
    }

    /**
     * @param mixed $value Candidate browser selection value.
     */
    public static function is_handle($value): bool {
        return is_string($value)
            && preg_match('/^sc1_[0-9a-f]{64}$/D', $value) === 1;
    }

    /**
     * @param mixed $provider_token Provider card token.
     */
    private static function provider_token($provider_token): ?string {
        if (!is_string($provider_token) || $provider_token === '') {
            return null;
        }

        if (preg_match('/\s/', $provider_token) === 1) {
            return null;
        }

        return $provider_token;
    }

    /**
     * Derive a request-independent context key without exposing merchant or
     * WordPress secrets in the handle.
     *
     * @param mixed $user_id Current WordPress user ID.
     * @param mixed $api_key Current UPayments API key.
     * @param mixed $is_test_mode Current provider mode.
     */
    private static function context_key($user_id, $api_key, $is_test_mode): ?string {
        if (!is_int($user_id) || $user_id <= 0) {
            return null;
        }
        if (!is_string($api_key) || $api_key === '') {
            return null;
        }
        if (!is_bool($is_test_mode)) {
            return null;
        }

        $salt = wp_salt('auth');
        if (!is_string($salt) || $salt === '') {
            return null;
        }

        $blog_id = get_current_blog_id();
        if (!is_int($blog_id) && !is_string($blog_id)) {
            return null;
        }
        $blog_id = (string) $blog_id;
        if ($blog_id === '' || preg_match('/^[0-9]+$/D', $blog_id) !== 1) {
            return null;
        }

        $mode = $is_test_mode ? 'test' : 'live';
        $context = self::CONTEXT_DOMAIN . '|' . $blog_id . '|' . $user_id . '|' . $mode . '|' . $api_key;
        $key = hash_hmac('sha256', $context, $salt, true);

        return is_string($key) && strlen($key) === 32 ? $key : null;
    }
}
