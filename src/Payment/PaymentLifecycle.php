<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Security\PublicOrderStatus;

require_once dirname(__DIR__) . '/Security/PublicOrderStatus.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/StatusRateGate.php';
require_once __DIR__ . '/OrderLock.php';
require_once __DIR__ . '/StatusVerifier.php';

/**
 * Payment callback + reconciliation strangler for the historical wc_upayments route.
 */
final class PaymentLifecycle {
    private const CALLBACK_HOOK = 'woocommerce_api_wc_upayments';
    private const RECONCILE_HOOK = 'simplixpay_upayments_reconcile_order';
    private const TRUSTED_TRACK_META = '_simplixpay_upayments_status_track_v1';
    private const TRUSTED_REQUESTED_META = '_simplixpay_upayments_status_requested_v1';
    private const UNVERIFIED_TRACK_META = '_simplixpay_upayments_unverified_track_v1';
    private const UNVERIFIED_REQUESTED_META = '_simplixpay_upayments_unverified_requested_v1';
    private const PROVIDER_RESULT_META = '_simplixpay_upayments_provider_result_v1';
    private const RECONCILE_ATTEMPT_META = '_simplixpay_upayments_reconcile_attempt_v1';
    private const RECONCILE_REASON_META = '_simplixpay_upayments_reconcile_reason_v1';
    private const RECONCILE_EXHAUSTED_META = '_simplixpay_upayments_reconcile_exhausted_v1';
    private const MAX_RECONCILE_ATTEMPTS = 4;
    // Provider order identities of earlier Charge attempts on the same Woo order.
    // Written by CheckoutOrchestrator before it rotates UPayments_order_id.
    private const PRIOR_REQUESTED_META = '_simplixpay_upayments_prior_requested_v1';
    private const CALLBACK_LOOKUP_META = '_simplixpay_upayments_callback_lookups_v1';
    private const CALLBACK_LOOKUPS_PER_MINUTE = 3;
    private const CAPTURE_APPLY_FAILED_META = '_simplixpay_upayments_capture_apply_failed_v1';
    private const UNAPPLIED_CAPTURES_META = '_simplixpay_upayments_unapplied_captures_v1';
    // Historical public status-poll value read by PublicOrderStatus.
    private const PUBLIC_STATUS_META = 'UPayments_WHS';

    /** @var bool */
    private static $bootstrapped = false;

    public static function bootstrap() {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        add_action(self::CALLBACK_HOOK, array(__CLASS__, 'handle_callback'), 5);
        add_action(self::RECONCILE_HOOK, array(__CLASS__, 'reconcile_order'), 10, 1);
    }

    /**
     * WC-API entrypoint. The historical get_order_status poll is intercepted here before inherited priority 10.
     */
    public static function handle_callback() {
        // Provider callbacks cannot carry a WordPress nonce; authority comes only from authenticated status binding.
        $get = self::request_get();
        $post = self::request_post();

        if (array_key_exists('get_order_status', $get)) {
            PublicOrderStatus::handle();
            return;
        }

        // Normal WC-API route: infer mode from the historical GET page marker.
        $mode = array_key_exists('page', $get) ? 'browser' : 'webhook';
        self::handle_callback_mode($mode, $get, $post);
    }

    /**
     * Explicit compatibility-mode + request-bag entrypoint for legacy adapters.
     *
     * $primary is the historical source bag (browser: GET; webhook: REQUEST
     * callback keys). $secondary is optional. Never rewrites superglobals.
     * Only the three callback keys are honored from $primary/$secondary.
     */
    public static function handle_compat_callback($mode, array $primary, array $secondary = array()) {
        if ($mode !== 'browser' && $mode !== 'webhook') {
            self::log('callback_compat_mode_invalid', 'warning');
            self::finish_callback(false, false, null, null);
        }
        $primary = self::extract_callback_keys($primary);
        $secondary = self::extract_callback_keys($secondary);
        // Historical adapters use a single source bag; merge as primary+secondary
        // without inventing GET/POST conflict semantics that never applied.
        self::handle_callback_mode($mode, $primary, $secondary, true);
    }

    /**
     * Keep only canonical callback identity keys. Never forward cookies/PII bags.
     */
    private static function extract_callback_keys(array $bag) {
        $out = array();
        foreach (array('wc_order_id', 'track_id', 'requested_order_id') as $key) {
            if (array_key_exists($key, $bag)) {
                $out[$key] = $bag[$key];
            }
        }
        return $out;
    }

    /**
     * Single ambient GET read for callback routing hints. Never written.
     */
    private static function request_get() {
        return $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- Public callback values are untrusted routing hints only.
    }

    private static function request_post() {
        return $_POST; // phpcs:ignore WordPress.Security.NonceVerification -- Provider status verification supplies payment authority.
    }

    /**
     * Single canonical financial callback implementation.
     * Mode is explicit routing/termination policy only — never payment truth.
     */
    private static function handle_callback_mode($mode, array $get, array $post = array(), $compat_merge = false) {
        $is_browser = ($mode === 'browser');

        // Normal WC-API keeps GET/POST conflict detection. Compatibility
        // adapters pass one historical bag (+ optional secondary) and must not
        // invent conflicts that the original caller never had.
        if ($compat_merge) {
            $merged = array_merge($post, $get);
            $get = $merged;
            $post = array();
        }

        $order_field = self::merge_request_value($get, $post, 'wc_order_id');
        $track_field = self::merge_request_value($get, $post, 'track_id');
        $requested_field = self::merge_request_value($get, $post, 'requested_order_id');

        if (!$order_field['valid'] || !$track_field['valid'] || !$requested_field['valid']) {
            self::log('callback_request_conflict', 'warning');
            self::finish_callback($is_browser, false, null, null);
        }

        $order_id = self::parse_order_id($order_field['value']);
        $track_id = self::parse_track_id($track_field['value']);
        $requested_order_id = self::parse_generic_identifier($requested_field['value'], 255);
        if ($order_id === null || $track_id === null || $requested_order_id === null) {
            self::log('callback_request_invalid', 'warning');
            self::finish_callback($is_browser, false, null, null);
        }

        $order = wc_get_order($order_id);
        $gateway = self::gateway();
        $source = $is_browser ? 'browser' : 'webhook';
        if (!self::order_gateway_preflight($order, $gateway, $requested_order_id)) {
            // A late callback for an earlier Charge attempt on this order still
            // carries real money; route it to the capture-only secondary path.
            if ((self::basic_order_preflight($order, $gateway) && self::is_prior_attempt($order, $requested_order_id))
                || (self::is_gateway_switched_order($order, $gateway) && self::is_known_attempt($order, $requested_order_id))
            ) {
                $outcome = self::process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
                self::finish_callback($is_browser, self::outcome_captured($outcome), $gateway, $order);
            }
            self::log('callback_local_preflight_failed', 'warning');
            self::finish_callback($is_browser, false, $gateway, $order);
        }

        // The callback cursor is routing evidence, never payment truth. Persisting
        // it separately lets a bounded reconciliation survive a transient failure
        // of the first authenticated status lookup. A provider-bound cursor, once
        // established, cannot be replaced by unverified callback input.
        if (!self::remember_unverified_cursor($order, $track_id, $requested_order_id)) {
            // A different track for an attempt that already has a trusted cursor
            // (or an already-paid order) may still be a capture: a retry inside
            // the same hosted session, or a duplicate payment. Only CAPTURED is
            // ever applied on this path.
            if (self::trusted_cursor_present($order) || self::is_verified_capture($order)) {
                $outcome = self::process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
                self::finish_callback($is_browser, self::outcome_captured($outcome), $gateway, $order);
            }
            self::log('callback_cursor_conflict', 'warning');
            self::finish_callback($is_browser, false, $gateway, $order);
        }

        $outcome = self::process_order_status($gateway, $order, $track_id, $source);
        self::finish_callback($is_browser, self::outcome_captured($outcome), $gateway, $order);
    }

    /**
     * Bounded WP-Cron reconciliation entrypoint. Cron args contain only order ID;
     * the cursor is read from order meta and never grants payment authority itself.
     */
    public static function reconcile_order($order_id) {
        $order_id = self::parse_order_id($order_id);
        if ($order_id === null) {
            return;
        }

        $order = wc_get_order($order_id);
        $gateway = self::gateway();
        if (!self::basic_order_preflight($order, $gateway)) {
            return;
        }

        if (self::is_verified_capture($order) || self::is_refunded($order)) {
            self::clear_reconciliation($order);
            return;
        }

        $current_requested = self::parse_generic_identifier($order->get_meta('UPayments_order_id'), 255);
        if ($current_requested === null) {
            return;
        }

        $track_id = self::parse_track_id($order->get_meta(self::TRUSTED_TRACK_META));
        if ($track_id !== null) {
            $trusted_requested = self::parse_generic_identifier($order->get_meta(self::TRUSTED_REQUESTED_META), 255);
            if ($trusted_requested === null || !hash_equals($current_requested, $trusted_requested)) {
                self::reset_attempt_cursor_state($order);
                self::log('reconcile_stale_trusted_attempt', 'warning');
                return;
            }
        } else {
            $track_id = self::parse_track_id($order->get_meta(self::UNVERIFIED_TRACK_META));
            $unverified_requested = self::parse_generic_identifier($order->get_meta(self::UNVERIFIED_REQUESTED_META), 255);
            if ($track_id === null
                || $unverified_requested === null
                || !hash_equals($current_requested, $unverified_requested)
            ) {
                if ($track_id !== null || $unverified_requested !== null) {
                    self::reset_attempt_cursor_state($order);
                }
                self::log('reconcile_missing_cursor', 'warning');
                return;
            }
        }

        self::process_order_status($gateway, $order, $track_id, 'reconcile');
    }

    /**
     * Provider-query + lock + deterministic state application seam.
     */
    public static function process_order_status($gateway, $order, $track_id, $source = 'reconcile') {
        if (!self::basic_order_preflight($order, $gateway)) {
            return self::outcome('unchanged', 'local_preflight_failed');
        }
        if (self::is_verified_capture($order)) {
            return self::outcome('captured', 'already_verified');
        }
        if (self::is_refunded($order)) {
            return self::outcome('unchanged', 'refunded');
        }

        $track_id = self::parse_track_id($track_id);
        if ($track_id === null) {
            return self::outcome('unchanged', 'invalid_track_id');
        }

        if ($source !== 'reconcile' && !self::callback_lookup_allowed($order)) {
            $verification = self::verification_refused('callback_rate_limited');
        } else {
            $verification = StatusVerifier::verify($gateway, $order, $track_id, $source);
        }
        if (empty($verification['bound']) || !is_array($verification['transaction'])) {
            $reason = isset($verification['reason']) ? (string) $verification['reason'] : 'verification_failed';

            if (self::is_retryable_verification_reason($reason)
                && self::retry_cursor_matches($order, $track_id)
            ) {
                self::schedule_reconciliation($order, $reason, $track_id);
            } elseif (!empty($verification['authenticated'])
                && self::unverified_cursor_matches($order, $track_id)
                && !self::trusted_cursor_present($order)
            ) {
                // An authenticated provider response that cannot bind this cursor
                // is definitive negative evidence for using that unverified cursor.
                self::clear_reconciliation($order);
            }

            self::log('status_' . self::safe_code($reason), 'warning');
            return self::outcome('unchanged', $reason);
        }

        $order_id = (int) $order->get_id();
        $lock_token = OrderLock::acquire($order_id);
        if ($lock_token === null) {
            if (self::retry_cursor_matches($order, $track_id)) {
                self::schedule_reconciliation($order, 'order_lock_contention', $track_id);
            }
            return self::outcome('unchanged', 'order_lock_contention');
        }

        $capture_attempted = false;
        try {
            // Re-read under the mutation lock to prevent a TOCTOU change between
            // provider verification and local state mutation.
            $fresh_order = wc_get_order($order_id);
            if (!self::basic_order_preflight($fresh_order, $gateway)) {
                return self::outcome('unchanged', 'fresh_order_preflight_failed');
            }
            if (self::is_verified_capture($fresh_order)) {
                self::clear_reconciliation($fresh_order);
                return self::outcome('captured', 'already_verified');
            }
            if (self::is_refunded($fresh_order)) {
                self::clear_reconciliation($fresh_order);
                return self::outcome('unchanged', 'refunded');
            }

            $rebound = StatusVerifier::bind_transaction(
                $gateway,
                $fresh_order,
                $track_id,
                $verification['transaction']
            );
            if (empty($rebound['bound']) || !is_array($rebound['transaction'])) {
                return self::outcome('unchanged', 'binding_changed_under_lock');
            }

            $current_requested = self::parse_generic_identifier($fresh_order->get_meta('UPayments_order_id'), 255);
            if ($current_requested === null) {
                return self::outcome('unchanged', 'fresh_requested_order_invalid');
            }
            $existing_track = $fresh_order->get_meta(self::TRUSTED_TRACK_META);
            $existing_requested = $fresh_order->get_meta(self::TRUSTED_REQUESTED_META);
            if (is_string($existing_track) && $existing_track !== '') {
                if (!is_string($existing_requested) || $existing_requested === '') {
                    self::log('trusted_cursor_requested_missing', 'warning');
                    return self::outcome('unchanged', 'trusted_cursor_requested_missing');
                }
                if (!hash_equals($existing_requested, $current_requested)) {
                    // process_payment() creates a new provider order identity for every
                    // successful Charge attempt on the same Woo order. Old unpaid
                    // reconciliation state must not pin the new attempt to its track.
                    self::reset_attempt_cursor_state($fresh_order);
                    $existing_track = '';
                } elseif ($existing_track !== $track_id) {
                    self::log('trusted_cursor_conflict', 'warning');
                    return self::outcome('unchanged', 'trusted_cursor_conflict');
                }
            }

            $transaction = $rebound['transaction'];
            $classification = (string) $rebound['classification'];

            // Promotion to trusted occurs only after authenticated status response
            // + exact fresh-order rebound. Pair the track with provider order identity
            // so a later Charge attempt on the same Woo order can be distinguished.
            $fresh_order->update_meta_data(self::TRUSTED_TRACK_META, $track_id);
            $fresh_order->update_meta_data(self::TRUSTED_REQUESTED_META, $current_requested);
            $fresh_order->delete_meta_data(self::UNVERIFIED_TRACK_META);
            $fresh_order->delete_meta_data(self::UNVERIFIED_REQUESTED_META);
            $fresh_order->update_meta_data(
                self::PROVIDER_RESULT_META,
                $transaction['result'] === null ? 'NULL' : (string) $transaction['result']
            );

            if ($classification === ProviderResult::CAPTURED) {
                $capture_attempted = true;
                $captured = self::apply_captured($gateway, $fresh_order, $transaction);
                if ($captured) {
                    self::clear_reconciliation($fresh_order);
                    self::log('captured');
                    return self::outcome('captured', 'captured');
                }
                self::handle_capture_apply_failure($fresh_order, $track_id);
                return self::outcome('unchanged', 'payment_complete_failed');
            }

            if ($classification === ProviderResult::FAILED) {
                $state = self::apply_terminal($fresh_order, 'failed', (string) $transaction['result']);
                self::clear_reconciliation($fresh_order);
                return self::outcome($state ? 'failed' : 'unchanged', $state ? 'provider_failed' : 'terminal_transition_blocked');
            }

            if ($classification === ProviderResult::CANCELLED) {
                $state = self::apply_terminal($fresh_order, 'cancelled', (string) $transaction['result']);
                self::clear_reconciliation($fresh_order);
                return self::outcome($state ? 'cancelled' : 'unchanged', $state ? 'provider_cancelled' : 'terminal_transition_blocked');
            }

            // PENDING and INDETERMINATE remain unpaid.
            $fresh_order->save();
            self::schedule_reconciliation($fresh_order, strtolower($classification), $track_id);
            return self::outcome('pending', strtolower($classification));
        } catch (\Throwable $e) {
            self::log('lifecycle_exception', 'warning');
            if ($capture_attempted) {
                $current = wc_get_order($order_id);
                self::handle_capture_apply_failure(is_object($current) ? $current : $order, $track_id);
            }
            return self::outcome('unchanged', 'lifecycle_exception');
        } finally {
            OrderLock::release($order_id, $lock_token);
        }
    }

    /**
     * Conflict-safe GET/POST merge. Cookies are deliberately excluded.
     */
    public static function merge_request_value(array $get, array $post, $key) {
        $has_get = array_key_exists($key, $get);
        $has_post = array_key_exists($key, $post);
        if (!$has_get && !$has_post) {
            return array('valid' => true, 'present' => false, 'value' => null);
        }

        $get_value = null;
        $post_value = null;
        if ($has_get) {
            if (!is_string($get[$key])) {
                return array('valid' => false, 'present' => true, 'value' => null);
            }
            $get_value = function_exists('wp_unslash') ? wp_unslash($get[$key]) : $get[$key];
            if (!is_string($get_value)) {
                return array('valid' => false, 'present' => true, 'value' => null);
            }
        }
        if ($has_post) {
            if (!is_string($post[$key])) {
                return array('valid' => false, 'present' => true, 'value' => null);
            }
            $post_value = function_exists('wp_unslash') ? wp_unslash($post[$key]) : $post[$key];
            if (!is_string($post_value)) {
                return array('valid' => false, 'present' => true, 'value' => null);
            }
        }

        if ($has_get && $has_post && $get_value !== $post_value) {
            return array('valid' => false, 'present' => true, 'value' => null);
        }

        return array(
            'valid' => true,
            'present' => true,
            'value' => $has_get ? $get_value : $post_value,
        );
    }

    private static function order_gateway_preflight($order, $gateway, $requested_order_id) {
        if (!self::basic_order_preflight($order, $gateway)) {
            return false;
        }
        if (!is_string($requested_order_id) || $requested_order_id === '') {
            return false;
        }
        $local_requested = $order->get_meta('UPayments_order_id');
        return is_string($local_requested)
            && $local_requested !== ''
            && hash_equals($local_requested, $requested_order_id);
    }

    private static function basic_order_preflight($order, $gateway) {
        if (!is_object($order)
            || !method_exists($order, 'get_id')
            || !method_exists($order, 'get_payment_method')
            || !method_exists($order, 'get_meta')
            || !is_object($gateway)
        ) {
            return false;
        }
        if ((string) $order->get_payment_method() !== 'upayments') {
            return false;
        }
        $local_requested = $order->get_meta('UPayments_order_id');
        return is_string($local_requested) && $local_requested !== '';
    }

    private static function remember_unverified_cursor($order, $track_id, $requested_order_id) {
        if (!is_object($order)
            || !method_exists($order, 'get_meta')
            || !method_exists($order, 'update_meta_data')
            || !method_exists($order, 'delete_meta_data')
            || !method_exists($order, 'save')
        ) {
            return false;
        }
        $track_id = self::parse_track_id($track_id);
        $requested_order_id = self::parse_generic_identifier($requested_order_id, 255);
        $current_requested = self::parse_generic_identifier($order->get_meta('UPayments_order_id'), 255);
        if ($track_id === null || $requested_order_id === null || $current_requested === null
            || !hash_equals($current_requested, $requested_order_id)
        ) {
            return false;
        }

        $trusted = $order->get_meta(self::TRUSTED_TRACK_META);
        if (is_string($trusted) && $trusted !== '') {
            $trusted_requested = $order->get_meta(self::TRUSTED_REQUESTED_META);
            if (!is_string($trusted_requested) || $trusted_requested === '') {
                return false;
            }
            if (hash_equals($trusted_requested, $current_requested)) {
                return hash_equals($trusted, $track_id);
            }

            // A new successful Charge attempt rewrote UPayments_order_id. The old
            // trusted cursor was authoritative only for that prior unpaid attempt.
            self::reset_attempt_cursor_state($order);
        } else {
            $unverified_requested = $order->get_meta(self::UNVERIFIED_REQUESTED_META);
            if (is_string($unverified_requested) && $unverified_requested !== ''
                && !hash_equals($unverified_requested, $current_requested)
            ) {
                self::reset_attempt_cursor_state($order);
            }
        }

        // Unverified callback routing evidence is replaceable by a later locally
        // preflighted callback for the same current provider order identity. It can
        // never overwrite a trusted cursor for that same attempt.
        $order->update_meta_data(self::UNVERIFIED_TRACK_META, $track_id);
        $order->update_meta_data(self::UNVERIFIED_REQUESTED_META, $current_requested);
        $saved = $order->save();
        if ($saved === false || $saved === null || $saved === 0) {
            return false;
        }
        $readback_track = $order->get_meta(self::UNVERIFIED_TRACK_META);
        $readback_requested = $order->get_meta(self::UNVERIFIED_REQUESTED_META);
        return is_string($readback_track)
            && is_string($readback_requested)
            && hash_equals($readback_track, $track_id)
            && hash_equals($readback_requested, $current_requested);
    }

    /**
     * Capture-only path for a track that the primary cursor flow cannot own:
     * an earlier Charge attempt on this order, a second track inside an attempt
     * that already has a trusted cursor, or any track on an already-paid order.
     *
     * Only an authenticated, bound CAPTURED result has an effect. It completes a
     * still-unpaid UPayments order, or is recorded for manual refund when the
     * order is already paid or was switched to another gateway. Non-captured
     * results never change the order.
     */
    public static function process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source = 'webhook') {
        if (!self::basic_order_preflight($order, $gateway) && !self::is_gateway_switched_order($order, $gateway)) {
            return self::outcome('unchanged', 'local_preflight_failed');
        }
        if (self::is_refunded($order)) {
            return self::outcome('unchanged', 'refunded');
        }
        $track_id = self::parse_track_id($track_id);
        $requested_order_id = self::parse_generic_identifier($requested_order_id, 255);
        if ($track_id === null || $requested_order_id === null) {
            return self::outcome('unchanged', 'invalid_request');
        }
        if (!self::is_known_attempt($order, $requested_order_id)) {
            self::log('secondary_unknown_attempt', 'warning');
            return self::outcome('unchanged', 'unknown_attempt');
        }
        if ($source !== 'reconcile' && !self::callback_lookup_allowed($order)) {
            return self::outcome('unchanged', 'callback_rate_limited');
        }

        $verification = StatusVerifier::verify($gateway, $order, $track_id, $source, $requested_order_id);
        if (empty($verification['bound']) || !is_array($verification['transaction'])) {
            $reason = isset($verification['reason']) ? (string) $verification['reason'] : 'verification_failed';
            self::log('secondary_status_' . self::safe_code($reason), 'warning');
            return self::outcome('unchanged', $reason);
        }
        if ((string) $verification['classification'] !== ProviderResult::CAPTURED) {
            self::log('secondary_not_captured');
            return self::outcome('unchanged', 'secondary_not_captured');
        }

        $order_id = (int) $order->get_id();
        $lock_token = OrderLock::acquire($order_id);
        if ($lock_token === null) {
            return self::outcome('unchanged', 'order_lock_contention');
        }

        try {
            $fresh_order = wc_get_order($order_id);
            $gateway_switched = self::is_gateway_switched_order($fresh_order, $gateway);
            if ((!$gateway_switched && !self::basic_order_preflight($fresh_order, $gateway))
                || self::is_refunded($fresh_order)
                || !self::is_known_attempt($fresh_order, $requested_order_id)
            ) {
                return self::outcome('unchanged', 'fresh_order_preflight_failed');
            }
            $rebound = StatusVerifier::bind_transaction(
                $gateway,
                $fresh_order,
                $track_id,
                $verification['transaction'],
                $requested_order_id
            );
            if (empty($rebound['bound']) || (string) $rebound['classification'] !== ProviderResult::CAPTURED) {
                return self::outcome('unchanged', 'binding_changed_under_lock');
            }
            $transaction = $rebound['transaction'];
            $payment_id = (string) $transaction['payment_id'];
            $existing_payment_id = (string) $fresh_order->get_transaction_id();

            if ($gateway_switched) {
                self::record_unapplied_capture($fresh_order, $payment_id, 'gateway_changed');
                return self::outcome('unapplied_capture', 'gateway_changed');
            }

            if (self::is_verified_capture($fresh_order)) {
                if ($existing_payment_id !== '' && hash_equals($existing_payment_id, $payment_id)) {
                    return self::outcome('captured', 'already_verified');
                }
                self::record_unapplied_capture($fresh_order, $payment_id, 'duplicate_capture');
                return self::outcome('duplicate_capture', 'duplicate_capture');
            }

            if ($existing_payment_id !== '' && !hash_equals($existing_payment_id, $payment_id)) {
                self::record_unapplied_capture($fresh_order, $payment_id, 'duplicate_capture');
                return self::outcome('duplicate_capture', 'duplicate_capture');
            }

            if (!self::apply_captured($gateway, $fresh_order, $transaction)) {
                self::handle_capture_apply_failure($fresh_order, '');
                return self::outcome('unchanged', 'payment_complete_failed');
            }
            // The capturing track now owns the order's trusted cursor.
            $fresh_order->update_meta_data(self::TRUSTED_TRACK_META, $track_id);
            $fresh_order->update_meta_data(self::TRUSTED_REQUESTED_META, $requested_order_id);
            $fresh_order->update_meta_data(self::PROVIDER_RESULT_META, 'CAPTURED');
            self::clear_reconciliation($fresh_order);
            self::log('secondary_captured');
            return self::outcome('captured', 'secondary_captured');
        } catch (\Throwable $e) {
            self::log('secondary_lifecycle_exception', 'warning');
            return self::outcome('unchanged', 'lifecycle_exception');
        } finally {
            OrderLock::release($order_id, $lock_token);
        }
    }

    private static function outcome_captured($outcome) {
        return is_array($outcome) && isset($outcome['state']) && $outcome['state'] === 'captured';
    }

    private static function verification_refused($reason) {
        return array(
            'authenticated' => false,
            'bound' => false,
            'transaction' => null,
            'reason' => (string) $reason,
        );
    }

    private static function prior_attempts($order) {
        $prior = $order->get_meta(self::PRIOR_REQUESTED_META);
        return is_array($prior) ? array_values(array_filter($prior, 'is_string')) : array();
    }

    private static function is_prior_attempt($order, $requested_order_id) {
        if (!is_string($requested_order_id) || $requested_order_id === '') {
            return false;
        }
        foreach (self::prior_attempts($order) as $prior) {
            if ($prior !== '' && hash_equals($prior, $requested_order_id)) {
                return true;
            }
        }
        return false;
    }

    private static function is_known_attempt($order, $requested_order_id) {
        $current = $order->get_meta('UPayments_order_id');
        if (is_string($current) && $current !== '' && is_string($requested_order_id)
            && hash_equals($current, $requested_order_id)
        ) {
            return true;
        }
        return self::is_prior_attempt($order, $requested_order_id);
    }

    /**
     * Per-order throttle for callback-triggered provider lookups. Approximate
     * under concurrency by design: the global StatusRateGate stays authoritative.
     */
    private static function callback_lookup_allowed($order) {
        if (!is_object($order) || !method_exists($order, 'update_meta_data') || !method_exists($order, 'save')) {
            return false;
        }
        $bucket = gmdate('YmdHi');
        $count = 0;
        $raw = $order->get_meta(self::CALLBACK_LOOKUP_META);
        if (is_string($raw) && preg_match('/^([0-9]{12}):([0-9]{1,4})$/', $raw, $m) && $m[1] === $bucket) {
            $count = (int) $m[2];
        }
        if ($count >= self::CALLBACK_LOOKUPS_PER_MINUTE) {
            self::log('callback_lookup_throttled', 'warning');
            return false;
        }
        $order->update_meta_data(self::CALLBACK_LOOKUP_META, $bucket . ':' . ($count + 1));
        $order->save();
        return true;
    }

    /**
     * An authenticated CAPTURED could not be recorded by WooCommerce. Retry
     * through bounded reconciliation and tell the merchant once.
     */
    private static function handle_capture_apply_failure($order, $track_id) {
        if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data')) {
            return;
        }
        if ($track_id !== '') {
            self::schedule_reconciliation($order, 'capture_apply_failed', $track_id);
        }
        if ((string) $order->get_meta(self::CAPTURE_APPLY_FAILED_META) === '1') {
            return;
        }
        $order->update_meta_data(self::CAPTURE_APPLY_FAILED_META, 1);
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(
                __('UPayments confirmed a captured payment for this order, but WooCommerce could not record it. SUPCheckout for UPayments will retry; if the order stays unpaid, review it manually.', 'supcheckout')
            );
        }
        $order->save();
    }

    /**
     * The order is no longer a UPayments order, but it still carries the provider
     * identity of a Charge attempt that can be paid until its link expires.
     */
    private static function is_gateway_switched_order($order, $gateway) {
        if (!is_object($order)
            || !method_exists($order, 'get_id')
            || !method_exists($order, 'get_payment_method')
            || !method_exists($order, 'get_meta')
            || !is_object($gateway)
            || (string) $order->get_payment_method() === 'upayments'
        ) {
            return false;
        }
        $requested = $order->get_meta('UPayments_order_id');
        return is_string($requested) && $requested !== '';
    }

    /**
     * Record an authenticated capture that SUPCheckout must not apply, once per
     * payment ID, and tell the merchant how to resolve it.
     */
    private static function record_unapplied_capture($order, $payment_id, $reason) {
        $recorded = $order->get_meta(self::UNAPPLIED_CAPTURES_META);
        $recorded = is_array($recorded) ? array_values(array_filter($recorded, 'is_string')) : array();
        if (in_array($payment_id, $recorded, true)) {
            return;
        }
        $recorded[] = $payment_id;
        $order->update_meta_data(self::UNAPPLIED_CAPTURES_META, $recorded);
        if (method_exists($order, 'add_order_note')) {
            if ($reason === 'gateway_changed') {
                /* translators: %s: UPayments payment ID. */
                $message = __('UPayments captured a payment (payment ID %s) for this order after its payment method was changed. SUPCheckout did not mark the order paid. Check whether the customer also paid another way, and refund in the UPayments merchant dashboard if so.', 'supcheckout');
            } else {
                /* translators: %s: UPayments payment ID of the extra capture. */
                $message = __('UPayments captured an additional payment (payment ID %s) for this already-paid order. The customer may have been charged twice. Refund it in the UPayments merchant dashboard; SUPCheckout does not refund automatically.', 'supcheckout');
            }
            $order->add_order_note(sprintf($message, $payment_id));
        }
        $order->save();
        self::log('unapplied_capture_' . self::safe_code($reason), 'warning');
    }

    private static function apply_captured($gateway, $order, array $transaction) {
        if (!is_object($order)
            || !method_exists($order, 'get_id')
            || !method_exists($order, 'get_transaction_id')
            || !method_exists($order, 'is_paid')
            || !method_exists($order, 'get_meta')
            || !method_exists($order, 'update_meta_data')
            || !method_exists($order, 'delete_meta_data')
            || !method_exists($order, 'save')
        ) {
            return false;
        }

        $payment_id = isset($transaction['payment_id']) && is_scalar($transaction['payment_id'])
            ? (string) $transaction['payment_id']
            : '';
        if ($payment_id === '') {
            return false;
        }

        if (self::is_refunded($order) || self::is_verified_capture($order)) {
            return false;
        }

        $existing_transaction_id = (string) $order->get_transaction_id();
        if ($existing_transaction_id !== '' && !hash_equals($existing_transaction_id, $payment_id)) {
            self::log('transaction_id_conflict', 'warning');
            return false;
        }

        $legacy_capture_meta = array(
            'UPayments_Result' => 'CAPTURED',
            'UPayments_PaymentID' => $payment_id,
            'UPayments_TrackID' => (string) $transaction['track_id'],
            'UPayments_Ref' => (string) $transaction['reference'],
            '_payment_method_title' => 'UPayments',
        );
        if (isset($transaction['payment_type']) && is_scalar($transaction['payment_type'])) {
            $legacy_capture_meta['UPayments_payment_type'] = (string) $transaction['payment_type'];
        }

        $already_paid = (bool) $order->is_paid();
        $legacy_capture_snapshot = array();

        if ($already_paid) {
            if ($existing_transaction_id === '' && method_exists($order, 'set_transaction_id')) {
                $order->set_transaction_id($payment_id);
            }
        } else {
            if (!method_exists($order, 'payment_complete')) {
                return false;
            }

            // WooCommerce saves the order and fires payment-completion/status hooks
            // inside payment_complete(). Stage legacy provider metadata first so
            // those hooks observe the authenticated provider result. Do not stage
            // Simplix verified-capture truth until the Woo paid-state postcondition
            // succeeds. If completion fails or throws, restore the exact prior
            // legacy metadata so false CAPTURED evidence cannot survive durably.
            foreach ($legacy_capture_meta as $key => $value) {
                $exists = method_exists($order, 'meta_exists')
                    ? (bool) $order->meta_exists($key)
                    : (string) $order->get_meta($key) !== '';
                $legacy_capture_snapshot[$key] = array(
                    'exists' => $exists,
                    'value' => $exists ? $order->get_meta($key) : null,
                );
                $order->update_meta_data($key, $value);
            }

            $force_completed = method_exists($gateway, 'getIsOrderComplete') && $gateway->getIsOrderComplete();
            $filter = null;
            if ($force_completed) {
                $target_order_id = (int) $order->get_id();
                $filter = function ($status, $order_id) use ($target_order_id) {
                    return ((int) $order_id === $target_order_id) ? 'completed' : $status;
                };
                add_filter('woocommerce_payment_complete_order_status', $filter, PHP_INT_MAX, 3);
            }

            try {
                $order->payment_complete($payment_id);
            } catch (\Throwable $e) {
                foreach ($legacy_capture_snapshot as $key => $snapshot) {
                    if (!empty($snapshot['exists'])) {
                        $order->update_meta_data($key, $snapshot['value']);
                    } else {
                        $order->delete_meta_data($key);
                    }
                }
                $order->save();
                throw $e;
            } finally {
                if ($filter !== null) {
                    remove_filter('woocommerce_payment_complete_order_status', $filter, PHP_INT_MAX);
                }
            }
        }

        $transaction_after = (string) $order->get_transaction_id();
        $paid_after = (bool) $order->is_paid();
        if (!$paid_after || $transaction_after !== $payment_id) {
            if (!$already_paid) {
                foreach ($legacy_capture_snapshot as $key => $snapshot) {
                    if (!empty($snapshot['exists'])) {
                        $order->update_meta_data($key, $snapshot['value']);
                    } else {
                        $order->delete_meta_data($key);
                    }
                }
                $order->save();
            }
            self::log('payment_complete_postcondition_failed', 'warning');
            return false;
        }

        // Re-assert canonical legacy metadata after completion hooks, then persist
        // Simplix payment truth only after Woo confirms paid state + transaction ID.
        foreach ($legacy_capture_meta as $key => $value) {
            $order->update_meta_data($key, $value);
        }
        $order->update_meta_data('_upay_verified_capture', 1);
        $order->update_meta_data('UPayments_webhook_triggered', 1);
        $order->update_meta_data(self::PUBLIC_STATUS_META, 'completed');
        $order->delete_meta_data(self::CAPTURE_APPLY_FAILED_META);
        $order->save();
        return true;
    }

    private static function apply_terminal($order, $target_status, $provider_result) {
        if (self::is_verified_capture($order) || self::is_refunded($order)) {
            return false;
        }
        if (method_exists($order, 'is_paid') && $order->is_paid()) {
            self::log('terminal_ignored_for_paid_order', 'warning');
            return false;
        }
        if ($target_status !== 'failed' && $target_status !== 'cancelled') {
            return false;
        }

        $order->update_meta_data('UPayments_Result', (string) $provider_result);
        $order->update_meta_data(self::PUBLIC_STATUS_META, $target_status);
        if ((string) $order->get_status() !== $target_status) {
            $note = $target_status === 'failed'
                ? __('UPayments authenticated payment result is terminal failure.', 'supcheckout')
                : __('UPayments authenticated payment result is cancelled.', 'supcheckout');
            $order->update_status($target_status, $note);
        } else {
            $order->save();
        }

        return (string) $order->get_status() === $target_status;
    }

    private static function schedule_reconciliation($order, $reason, $track_id) {
        if (!is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'get_meta')) {
            return false;
        }
        if (self::is_verified_capture($order) || self::is_refunded($order)) {
            return false;
        }

        $track_id = self::parse_track_id($track_id);
        $order_id = (int) $order->get_id();
        if ($order_id <= 0 || $track_id === null || !self::retry_cursor_matches($order, $track_id)) {
            return false;
        }

        $args = array($order_id);
        if (wp_next_scheduled(self::RECONCILE_HOOK, $args) !== false) {
            return true;
        }

        $attempt = (int) $order->get_meta(self::RECONCILE_ATTEMPT_META);
        if ($attempt >= self::MAX_RECONCILE_ATTEMPTS) {
            self::mark_reconciliation_exhausted($order, $reason);
            return false;
        }

        $delays = array(60, 120, 240, 480);
        $delay = $delays[$attempt];
        $next_attempt = $attempt + 1;

        $order->update_meta_data(self::RECONCILE_ATTEMPT_META, $next_attempt);
        $order->update_meta_data(self::RECONCILE_REASON_META, self::safe_code($reason));
        $order->save();

        $scheduled = wp_schedule_single_event(time() + $delay, self::RECONCILE_HOOK, $args);
        if ($scheduled === false) {
            self::log('reconcile_schedule_failed', 'warning');
            return false;
        }
        return true;
    }

    private static function mark_reconciliation_exhausted($order, $reason) {
        if ((string) $order->get_meta(self::RECONCILE_EXHAUSTED_META) === '1') {
            return;
        }
        $order->update_meta_data(self::RECONCILE_EXHAUSTED_META, 1);
        $order->update_meta_data(self::RECONCILE_REASON_META, self::safe_code($reason));
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(
                __('SUPCheckout for UPayments reconciliation exhausted without authoritative terminal payment state. Manual review is required.', 'supcheckout')
            );
        }
        $order->save();
    }

    private static function clear_reconciliation($order) {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        self::unschedule_reconciliation($order);
        if (method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data(self::UNVERIFIED_TRACK_META);
            $order->delete_meta_data(self::UNVERIFIED_REQUESTED_META);
            $order->delete_meta_data(self::RECONCILE_ATTEMPT_META);
            $order->delete_meta_data(self::RECONCILE_REASON_META);
            $order->delete_meta_data(self::RECONCILE_EXHAUSTED_META);
            $order->save();
        }
    }

    private static function reset_attempt_cursor_state($order) {
        if (!is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'delete_meta_data')) {
            return;
        }
        self::unschedule_reconciliation($order);
        $order->delete_meta_data(self::TRUSTED_TRACK_META);
        $order->delete_meta_data(self::TRUSTED_REQUESTED_META);
        $order->delete_meta_data(self::UNVERIFIED_TRACK_META);
        $order->delete_meta_data(self::UNVERIFIED_REQUESTED_META);
        $order->delete_meta_data(self::PROVIDER_RESULT_META);
        $order->delete_meta_data(self::RECONCILE_ATTEMPT_META);
        $order->delete_meta_data(self::RECONCILE_REASON_META);
        $order->delete_meta_data(self::RECONCILE_EXHAUSTED_META);
        $order->save();
    }

    private static function unschedule_reconciliation($order) {
        $args = array((int) $order->get_id());
        for ($i = 0; $i < self::MAX_RECONCILE_ATTEMPTS + 2; $i++) {
            $timestamp = wp_next_scheduled(self::RECONCILE_HOOK, $args);
            if ($timestamp === false) {
                break;
            }
            wp_unschedule_event($timestamp, self::RECONCILE_HOOK, $args);
        }
    }

    private static function trusted_cursor_present($order) {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return false;
        }
        $trusted = $order->get_meta(self::TRUSTED_TRACK_META);
        return is_string($trusted) && self::parse_track_id($trusted) !== null;
    }

    private static function trusted_cursor_matches($order, $track_id) {
        $track_id = self::parse_track_id($track_id);
        if ($track_id === null || !is_object($order) || !method_exists($order, 'get_meta')) {
            return false;
        }
        $trusted = $order->get_meta(self::TRUSTED_TRACK_META);
        $trusted_requested = self::parse_generic_identifier($order->get_meta(self::TRUSTED_REQUESTED_META), 255);
        $current_requested = self::parse_generic_identifier($order->get_meta('UPayments_order_id'), 255);
        return is_string($trusted)
            && $trusted !== ''
            && $trusted_requested !== null
            && $current_requested !== null
            && hash_equals($trusted, $track_id)
            && hash_equals($trusted_requested, $current_requested);
    }

    private static function unverified_cursor_matches($order, $track_id) {
        $track_id = self::parse_track_id($track_id);
        if ($track_id === null || !is_object($order) || !method_exists($order, 'get_meta')) {
            return false;
        }
        $unverified = $order->get_meta(self::UNVERIFIED_TRACK_META);
        $unverified_requested = self::parse_generic_identifier($order->get_meta(self::UNVERIFIED_REQUESTED_META), 255);
        $current_requested = self::parse_generic_identifier($order->get_meta('UPayments_order_id'), 255);
        return is_string($unverified)
            && $unverified !== ''
            && $unverified_requested !== null
            && $current_requested !== null
            && hash_equals($unverified, $track_id)
            && hash_equals($unverified_requested, $current_requested);
    }

    private static function retry_cursor_matches($order, $track_id) {
        if (self::trusted_cursor_present($order)) {
            return self::trusted_cursor_matches($order, $track_id);
        }
        return self::unverified_cursor_matches($order, $track_id);
    }

    private static function is_retryable_verification_reason($reason) {
        if (!is_string($reason)) {
            return false;
        }
        if (in_array($reason, array(
            'network_error',
            'status_rate_limited',
            'callback_rate_limited',
            'empty_response',
            'invalid_status_response',
        ), true)) {
            return true;
        }
        if (preg_match('/^unexpected_http_([0-9]{3})$/', $reason, $matches)) {
            $status = (int) $matches[1];
            return $status === 404 || $status === 408 || $status === 425 || $status === 429 || $status >= 500;
        }
        return false;
    }

    private static function is_verified_capture($order) {
        return is_object($order)
            && method_exists($order, 'get_meta')
            && (string) $order->get_meta('_upay_verified_capture') === '1';
    }

    private static function is_refunded($order) {
        return is_object($order)
            && method_exists($order, 'has_status')
            && $order->has_status('refunded');
    }

    private static function parse_order_id($value) {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || strlen($value) > 18 || !preg_match('/^[1-9][0-9]*\\z/', $value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function parse_track_id($value) {
        return self::parse_generic_identifier($value, 255);
    }

    private static function parse_generic_identifier($value, $max_length) {
        if (!is_string($value) || $value === '' || strlen($value) > (int) $max_length) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7F]/', $value)) {
            return null;
        }
        return $value;
    }

    private static function gateway() {
        $woocommerce = WC();
        if (!is_object($woocommerce) || !method_exists($woocommerce, 'payment_gateways')) {
            return null;
        }
        $registry = $woocommerce->payment_gateways();
        if (!is_object($registry) || !method_exists($registry, 'payment_gateways')) {
            return null;
        }
        $gateways = $registry->payment_gateways();
        return is_array($gateways) && isset($gateways['upayments']) ? $gateways['upayments'] : null;
    }

    private static function finish_callback($is_browser, $captured, $gateway, $order) {
        self::send_nocache_headers();

        if ($is_browser) {
            $redirect = self::neutral_url();
            if ($captured && is_object($gateway) && is_object($order) && method_exists($gateway, 'get_return_url')) {
                $candidate = $gateway->get_return_url($order);
                if (is_string($candidate) && $candidate !== '') {
                    $redirect = $candidate;
                }
                $woocommerce = WC();
                if (is_object($woocommerce) && isset($woocommerce->cart) && is_object($woocommerce->cart)
                    && method_exists($woocommerce->cart, 'empty_cart')
                ) {
                    $woocommerce->cart->empty_cart();
                }
            }
            wp_safe_redirect($redirect);
            exit();
        }

        if (function_exists('status_header')) {
            status_header(200);
        }
        exit();
    }

    /**
     * Public callback responses must never be stored by shared caches.
     * Prefer WooCommerce's public no-cache wrapper when available.
     */
    private static function send_nocache_headers() {
        if (function_exists('wc_nocache_headers')) {
            wc_nocache_headers();
            return;
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }

    private static function neutral_url() {
        $base = function_exists('is_user_logged_in') && is_user_logged_in()
            ? wc_get_page_permalink('myaccount')
            : home_url('/');
        return add_query_arg('upayments_verification', 'pending', $base);
    }

    private static function log($code, $level = 'info') {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $logger = wc_get_logger();
        if (!is_object($logger)) {
            return;
        }
        $message = 'Payment lifecycle: ' . self::safe_code($code);
        $context = array('source' => 'supcheckout-lifecycle');
        if ($level === 'warning' && method_exists($logger, 'warning')) {
            $logger->warning($message, $context);
        } elseif (method_exists($logger, 'info')) {
            $logger->info($message, $context);
        }
    }

    private static function safe_code($value) {
        $value = is_scalar($value) ? strtolower((string) $value) : 'invalid';
        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value);
        return is_string($value) && $value !== '' ? substr($value, 0, 80) : 'invalid';
    }

    private static function outcome($state, $reason) {
        return array(
            'state' => (string) $state,
            'reason' => self::safe_code($reason),
        );
    }

    private function __construct() {}
}
