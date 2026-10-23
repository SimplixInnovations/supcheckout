<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Security\PublicOrderStatus;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

require_once dirname(__DIR__) . '/Security/PublicOrderStatus.php';
require_once dirname(__DIR__) . '/Takeover/LegacyCallbackListener.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/StatusRateGate.php';
require_once __DIR__ . '/OrderLock.php';
require_once __DIR__ . '/StatusVerifier.php';
require_once __DIR__ . '/ReconciliationSweep.php';

/**
 * Payment callback + reconciliation strangler for the historical wc_upayments route.
 *
 * The wc_upayments route is permanent (0b Task 8): bootstrap() registers it in every
 * takeover state, and Takeover\LegacyCallbackListener mirrors it onto the supcheckout
 * route that Charges use after cutover, both served by handle_callback().
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
    private const RECONCILE_SCHEDULE_FAILED_META = '_supcheckout_reconcile_schedule_failed_v1';
    private const RECONCILE_EXHAUSTED_META = '_simplixpay_upayments_reconcile_exhausted_v1';
    private const MAX_RECONCILE_ATTEMPTS = 4;
    // Provider order identities of earlier Charge attempts on the same Woo order.
    // Written by CheckoutOrchestrator before it rotates UPayments_order_id.
    private const PRIOR_REQUESTED_META = '_simplixpay_upayments_prior_requested_v1';
    private const CALLBACK_LOOKUP_META = '_simplixpay_upayments_callback_lookups_v1';
    private const CALLBACK_LOOKUPS_PER_MINUTE = 3;
    private const CAPTURE_APPLY_FAILED_META = '_simplixpay_upayments_capture_apply_failed_v1';
    private const UNAPPLIED_CAPTURES_META = '_simplixpay_upayments_unapplied_captures_v1';
    private const SECONDARY_RECONCILE_HOOK = 'simplixpay_upayments_reconcile_secondary';
    private const SECONDARY_PENDING_META = '_simplixpay_upayments_secondary_pending_v1';
    private const SECONDARY_ATTEMPT_META = '_simplixpay_upayments_secondary_attempt_v1';
    private const SECONDARY_NOTED_META = '_simplixpay_upayments_secondary_noted_v1';
    // Tracks that already got a window-exhaustion note (once per track).
    private const SECONDARY_EXHAUSTED_NOTED_META = '_supcheckout_secondary_exhausted_noted_v1';
    // Evictions can happen at callback rate with caller-chosen tracks: one note per order.
    private const SECONDARY_EVICTED_NOTED_META = '_supcheckout_secondary_evicted_noted_v1';
    private const MAX_SECONDARY_PENDING = 3;
    // Each queued track gets MAX_RECONCILE_ATTEMPTS runs; a cycle stops at twice that.
    private const MAX_SECONDARY_CYCLE_RUNS = 2 * self::MAX_RECONCILE_ATTEMPTS;
    private const MAX_SECONDARY_NOTED = 10;
    // Historical public status-poll value read by PublicOrderStatus.
    private const PUBLIC_STATUS_META = 'UPayments_WHS';
    // Hourly sweep (ReconciliationSweep): when an order was last swept, the trusted
    // track whose transaction cannot bind (noted once, never re-swept), and the
    // once-per-order missing-callback note. NO_CALLBACK_NOTED_META is also set as soon
    // as a callback stores or resets a cursor: such an order never gets that note.
    private const SWEEP_CHECKED_META = '_supcheckout_sweep_checked_at_v1';
    private const SWEEP_PARKED_META = '_supcheckout_sweep_parked_v1';
    private const NO_CALLBACK_NOTED_META = '_supcheckout_no_callback_noted_v1';
    // A reconcile event overdue by more than this is treated as lost by the sweep.
    private const SWEEP_LOST_EVENT_SECONDS = 3600;
    // Stored provider results that ProviderResult::classify() treats as terminal
    // (CAPTURED, FAILED, CANCELLED); ReconciliationSweepTest pins the two together.
    private const TERMINAL_PROVIDER_RESULTS = array(
        'CAPTURED', 'NOT CAPTURED', 'FAILED', 'FAILURE', 'ERROR', 'CANCELED', 'CANCELLED',
    );

    /** @var bool */
    private static $bootstrapped = false;

    public static function bootstrap() {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        add_action(self::CALLBACK_HOOK, array(__CLASS__, 'handle_callback'), 5);
        LegacyCallbackListener::register();
        // Takeover (0b Task 7): from `copying` on, both cron names run the callbacks; the
        // cutover keeps legacy events until Finalize and schedules new-name twins.
        foreach (Keys::cron_hook_names(self::RECONCILE_HOOK) as $hook) {
            add_action($hook, array(__CLASS__, 'reconcile_order'), 10, 1);
        }
        foreach (Keys::cron_hook_names(self::SECONDARY_RECONCILE_HOOK) as $hook) {
            add_action($hook, array(__CLASS__, 'reconcile_secondary'), 10, 1);
        }
        ReconciliationSweep::register();
    }

    /**
     * Orders still carrying a payment cursor (trusted or unverified).
     *
     * @return array wc_get_orders() meta_query.
     */
    public static function sweep_cursor_meta_query() {
        return array(
            'relation' => 'OR',
            Keys::meta_clause_any(array('key' => self::UNVERIFIED_TRACK_META, 'compare' => 'EXISTS')),
            Keys::meta_clause_any(array('key' => self::TRUSTED_TRACK_META, 'compare' => 'EXISTS')),
        );
    }

    /**
     * Hourly-sweep candidates of one status (ReconciliationSweep).
     *
     * A pending order qualifies with any cursor. A failed order qualifies only with an
     * unverified cursor or a trusted cursor whose stored result is not terminal: a
     * declined payment keeps its trusted cursor and must not be re-swept. Orders whose
     * trusted transaction cannot bind are parked. $checked_before null selects orders
     * never swept; otherwise orders last swept before that time.
     *
     * @param string   $status         'pending' or 'failed'.
     * @param int|null $checked_before Unix time, or null.
     * @return array wc_get_orders() meta_query.
     */
    public static function sweep_candidate_meta_query($status, $checked_before = null) {
        $cursor = self::sweep_cursor_meta_query();
        if ($status === 'failed') {
            $cursor = array(
                'relation' => 'OR',
                Keys::meta_clause_any(array('key' => self::UNVERIFIED_TRACK_META, 'compare' => 'EXISTS')),
                array(
                    'relation' => 'AND',
                    Keys::meta_clause_any(array('key' => self::TRUSTED_TRACK_META, 'compare' => 'EXISTS')),
                    Keys::meta_clause_any(array('key' => self::PROVIDER_RESULT_META, 'value' => self::TERMINAL_PROVIDER_RESULTS, 'compare' => 'NOT IN')),
                ),
            );
        }
        return array(
            'relation' => 'AND',
            $cursor,
            array('key' => self::SWEEP_PARKED_META, 'compare' => 'NOT EXISTS'),
            $checked_before === null
                ? array('key' => self::SWEEP_CHECKED_META, 'compare' => 'NOT EXISTS')
                : array('key' => self::SWEEP_CHECKED_META, 'value' => (int) $checked_before, 'compare' => '<', 'type' => 'NUMERIC'),
        );
    }

    /**
     * Charged orders with no cursor at all that have not had the missing-callback note
     * yet. A callback sets the flag when it first stores a cursor, and every cursor
     * reset sets it too, so an order whose cursor is gone after a callback is excluded.
     *
     * @return array wc_get_orders() meta_query.
     */
    public static function missing_callback_meta_query() {
        return array(
            'relation' => 'AND',
            Keys::meta_clause_any(array('key' => 'UPayments_order_id', 'compare' => 'EXISTS')),
            self::meta_absent_clause(self::UNVERIFIED_TRACK_META),
            self::meta_absent_clause(self::TRUSTED_TRACK_META),
            array('key' => self::NO_CALLBACK_NOTED_META, 'compare' => 'NOT EXISTS'),
        );
    }

    /**
     * NOT EXISTS under every current name of a mapped meta key (both after cutover).
     * Keys::meta_clause_any() is for positive clauses only.
     */
    private static function meta_absent_clause($legacy) {
        $absent = array('key' => $legacy, 'compare' => 'NOT EXISTS');
        if (!TakeoverState::is_cut_over()) {
            return $absent;
        }
        list($new_key) = Keys::meta_names($legacy);
        return array('relation' => 'AND', $absent, array('key' => $new_key, 'compare' => 'NOT EXISTS'));
    }

    /**
     * One hourly-sweep candidate. The sweep time is recorded first, so a candidate
     * that keeps failing still rotates out of the batch, and any failure is isolated
     * so one order cannot abort the run (A6-04).
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public static function sweep_order($order_id) {
        $order_id = self::parse_order_id($order_id);
        if ($order_id === null) {
            return;
        }
        try {
            $order = wc_get_order($order_id);
            if (!is_object($order) || !method_exists($order, 'update_meta_data') || !method_exists($order, 'save')) {
                return;
            }
            $order->update_meta_data(self::SWEEP_CHECKED_META, time());
            $order->save();
            // A scheduled check owns the order, unless it is overdue by more than an
            // hour: WP-Cron then lost it (no traffic, a failed run) and the sweep takes over.
            $scheduled = self::next_scheduled(self::RECONCILE_HOOK, array($order_id));
            if ($scheduled !== false && $scheduled[0] >= time() - self::SWEEP_LOST_EVENT_SECONDS) {
                return;
            }
            if (method_exists($order, 'get_status') && (string) $order->get_status() === 'failed'
                && !self::failed_order_sweepable($order)
            ) {
                return;
            }
            self::reconcile_order($order_id);
        } catch (\Throwable $e) {
            self::log('sweep_order_exception', 'warning');
        }
    }

    /**
     * A failed order is re-checked only for an unverified cursor or for a trusted
     * cursor with a non-terminal result (verify-P, A1-06(b)). A trusted cursor of an
     * earlier attempt then takes reconcile_order()'s stale-attempt path: the track is
     * queued once on the bounded capture-only check and the cursor is reset.
     */
    private static function failed_order_sweepable($order) {
        if (self::parse_track_id(Keys::read_order_meta($order, self::UNVERIFIED_TRACK_META)) !== null) {
            return true;
        }
        $result = Keys::read_order_meta($order, self::PROVIDER_RESULT_META);
        return self::trusted_cursor_present($order)
            && is_string($result) && $result !== ''
            && !in_array($result, self::TERMINAL_PROVIDER_RESULTS, true);
    }

    /**
     * A1-09: a charged pending order that never received a callback has no cursor, so
     * nothing can look it up. One neutral private note; no provider call. Most such
     * orders are abandoned payment sessions.
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public static function note_missing_callback($order_id) {
        $order_id = self::parse_order_id($order_id);
        if ($order_id === null) {
            return;
        }
        try {
            $order = wc_get_order($order_id);
            if (!is_object($order)
                || !method_exists($order, 'get_status')
                || !method_exists($order, 'get_meta')
                || !method_exists($order, 'update_meta_data')
                || !method_exists($order, 'add_order_note')
                || !method_exists($order, 'save')
                || (string) $order->get_meta(self::NO_CALLBACK_NOTED_META) !== ''
            ) {
                return;
            }
            // Every candidate is flagged, noted or not: a rejected one would otherwise
            // be selected again every run for 48 h.
            $order->update_meta_data(self::NO_CALLBACK_NOTED_META, 1);
            if (self::basic_order_preflight($order, self::gateway())
                && (string) $order->get_status() === 'pending'
                && !self::is_verified_capture($order)
                && !self::trusted_cursor_present($order)
                && self::parse_track_id(Keys::read_order_meta($order, self::UNVERIFIED_TRACK_META)) === null
            ) {
                $order->add_order_note(
                    __('SUPCheckout has not received a UPayments callback for this order. This is usual for an abandoned payment; if the customer reports paying, check the UPayments merchant dashboard before fulfilling the order.', 'supcheckout')
                );
            }
            $order->save();
        } catch (\Throwable $e) {
            self::log('missing_callback_note_exception', 'warning');
        }
    }

    /**
     * @param int $order_id WooCommerce order ID.
     * @return bool
     */
    public static function has_scheduled_reconciliation($order_id) {
        return self::next_scheduled(self::RECONCILE_HOOK, array((int) $order_id)) !== false;
    }

    /**
     * The first pending event of a cron hook under any of its current names.
     *
     * @param string     $legacy Legacy hook name (Keys::cron_hooks()).
     * @param array<int> $args   Event arguments.
     * @return array{0:int,1:string}|false [timestamp, hook name], or false.
     */
    private static function next_scheduled($legacy, array $args) {
        foreach (Keys::cron_hook_names($legacy) as $hook) {
            $timestamp = wp_next_scheduled($hook, $args);
            if ($timestamp !== false) {
                return array((int) $timestamp, $hook);
            }
        }
        return false;
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

        $order_id = self::parse_callback_order_id($order_field['value']);
        $track_id = self::parse_track_id($track_field['value']);
        $requested_order_id = self::parse_generic_identifier($requested_field['value'], 255);
        if ($order_id === null || $track_id === null || $requested_order_id === null) {
            self::log('callback_request_invalid', 'warning');
            self::finish_callback($is_browser, false, null, null);
        }

        $order = wc_get_order($order_id);
        $gateway = self::gateway();
        $source = $is_browser ? 'browser' : 'webhook';
        $outcome = self::route_callback($gateway, $order, $track_id, $requested_order_id, $source);
        self::finish_callback($is_browser, self::outcome_captured($outcome), $gateway, $order, $outcome);
    }

    /**
     * Routes one locally parsed callback to the path that owns it. Never
     * terminates the request and never throws; the caller decides the response.
     * A failure (for example a third-party order-save hook that throws) becomes
     * lifecycle_exception, so the customer still gets the neutral page and the
     * webhook a normal answer (A6-04). Mutation semantics are unchanged.
     */
    public static function route_callback($gateway, $order, $track_id, $requested_order_id, $source) {
        try {
            return self::route_callback_unguarded($gateway, $order, $track_id, $requested_order_id, $source);
        } catch (\Throwable $e) {
            self::log('callback_exception', 'warning');
            return self::outcome('unchanged', 'lifecycle_exception');
        }
    }

    private static function route_callback_unguarded($gateway, $order, $track_id, $requested_order_id, $source) {
        if (!self::order_gateway_preflight($order, $gateway, $requested_order_id)) {
            // A late callback for an earlier Charge attempt on this order still
            // carries real money; route it to the capture-only secondary path.
            if ((self::basic_order_preflight($order, $gateway) && self::is_prior_attempt($order, $requested_order_id))
                || (self::is_gateway_switched_order($order, $gateway) && self::is_known_attempt($order, $requested_order_id))
            ) {
                return self::process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
            }
            self::log('callback_local_preflight_failed', 'warning');
            return self::outcome('unchanged', 'local_preflight_failed');
        }

        // A paid order answers its own paid track locally. Any other track may be
        // a second real payment, so it is checked on the capture-only path and
        // never touches the paid order's cursors.
        if (self::is_verified_capture($order)) {
            foreach (array('UPayments_TrackID', self::TRUSTED_TRACK_META) as $paid_track_key) {
                $paid_track = Keys::read_order_meta($order, $paid_track_key);
                if (is_string($paid_track) && $paid_track !== '' && hash_equals($paid_track, (string) $track_id)) {
                    return self::outcome('captured', 'already_verified');
                }
            }
            return self::process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
        }

        // The callback cursor is routing evidence, never payment truth. Persisting
        // it separately lets a bounded reconciliation survive a transient failure
        // of the first authenticated status lookup. A provider-bound cursor, once
        // established, cannot be replaced by unverified callback input.
        if (!self::remember_unverified_cursor($order, $track_id, $requested_order_id)) {
            // A different track for an attempt that already has a trusted cursor
            // may still be a capture: a retry inside the same hosted session.
            // Only CAPTURED is ever applied on that path.
            if (self::trusted_cursor_present($order)) {
                return self::process_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
            }
            self::log('callback_cursor_conflict', 'warning');
            return self::outcome('unchanged', 'callback_cursor_conflict');
        }

        $outcome = self::process_order_status($gateway, $order, $track_id, $source);
        if (is_array($outcome) && isset($outcome['reason']) && $outcome['reason'] === 'trusted_cursor_conflict') {
            // Another track of this attempt became trusted between verification and
            // the lock (for example a retry inside the same hosted session). This
            // track may still be a capture; only CAPTURED has an effect there.
            $current = wc_get_order((int) $order->get_id());
            return self::process_secondary_capture($gateway, is_object($current) ? $current : $order, $track_id, $requested_order_id, $source);
        }
        return $outcome;
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

        $current_requested = self::parse_generic_identifier(Keys::read_order_meta($order, 'UPayments_order_id'), 255);
        if ($current_requested === null) {
            return;
        }

        $track_id = self::parse_track_id(Keys::read_order_meta($order, self::TRUSTED_TRACK_META));
        if ($track_id !== null) {
            $trusted_requested = self::parse_generic_identifier(Keys::read_order_meta($order, self::TRUSTED_REQUESTED_META), 255);
            if ($trusted_requested === null || !hash_equals($current_requested, $trusted_requested)) {
                if ($trusted_requested !== null) {
                    // The customer started a new attempt; the earlier one may still capture.
                    self::enqueue_secondary($order, $track_id, $trusted_requested);
                }
                self::reset_attempt_cursor_state($order);
                self::log('reconcile_stale_trusted_attempt', 'warning');
                return;
            }
        } else {
            $track_id = self::parse_track_id(Keys::read_order_meta($order, self::UNVERIFIED_TRACK_META));
            $unverified_requested = self::parse_generic_identifier(Keys::read_order_meta($order, self::UNVERIFIED_REQUESTED_META), 255);
            if ($track_id === null
                || $unverified_requested === null
                || !hash_equals($current_requested, $unverified_requested)
            ) {
                if ($track_id !== null && $unverified_requested !== null) {
                    // The customer started a new attempt; the earlier one may still capture.
                    self::enqueue_secondary($order, $track_id, $unverified_requested);
                }
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

            // Track, provider order identity and Woo reference bind before economics,
            // so an authenticated amount/currency mismatch on this order's own cursor
            // is this attempt's transaction. It stays unpaid; the merchant is told once.
            // A filtered order total (binding_total_filtered) is handled the same way:
            // the answer cannot change while the filtering plugin is active, so the
            // order leaves the sweep instead of repeating the lookup for 48 h.
            if (!empty($verification['authenticated'])
                && self::is_economics_mismatch($reason)
                && self::retry_cursor_matches($order, $track_id)
            ) {
                self::log('current_attempt_unbound_' . $reason, 'warning');
                self::note_unbound_current($order, $track_id, $reason);
                if (self::trusted_cursor_present($order)) {
                    // The trusted cursor stays (it cannot be replaced), but the hourly
                    // sweep would get the same answer every run for 48 h.
                    self::park_from_sweep($order, $track_id);
                }
            }

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
                // A callback did arrive, so the hourly sweep must not later call
                // the order "no callback received" (saved by clear_reconciliation).
                if (method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data(self::NO_CALLBACK_NOTED_META, 1);
                }
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
            // The provider has already confirmed this capture; another worker (usually
            // the webhook) holds the lock while it completes the order.
            $in_progress = isset($verification['classification'])
                && $verification['classification'] === ProviderResult::CAPTURED;
            return self::outcome($in_progress ? 'capture_in_progress' : 'unchanged', 'order_lock_contention');
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
                // Paid by another track between verification and the lock. A different
                // verified capture is a second charge: record it for manual refund.
                $duplicate_payment_id = self::verified_capture_payment_id($verification);
                if ($duplicate_payment_id !== null
                    && !hash_equals((string) $fresh_order->get_transaction_id(), $duplicate_payment_id)
                ) {
                    self::record_unapplied_capture($fresh_order, $duplicate_payment_id, 'duplicate_capture');
                }
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
                if (isset($rebound['reason']) && $rebound['reason'] === 'binding_persisted_total_unreadable') {
                    // The rebind's own stored-total read failed (R-1): retried within
                    // MAX_RECONCILE_ATTEMPTS like the first read, never treated as a change.
                    self::schedule_reconciliation($fresh_order, 'binding_persisted_total_unreadable', $track_id);
                    return self::outcome('unchanged', 'binding_persisted_total_unreadable');
                }
                return self::outcome('unchanged', 'binding_changed_under_lock');
            }

            $current_requested = self::parse_generic_identifier(Keys::read_order_meta($fresh_order, 'UPayments_order_id'), 255);
            if ($current_requested === null) {
                return self::outcome('unchanged', 'fresh_requested_order_invalid');
            }
            $existing_track = Keys::read_order_meta($fresh_order, self::TRUSTED_TRACK_META);
            $existing_requested = Keys::read_order_meta($fresh_order, self::TRUSTED_REQUESTED_META);
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
            Keys::update_order_meta($fresh_order, self::TRUSTED_TRACK_META, $track_id);
            Keys::update_order_meta($fresh_order, self::TRUSTED_REQUESTED_META, $current_requested);
            Keys::delete_order_meta($fresh_order, self::UNVERIFIED_TRACK_META);
            Keys::delete_order_meta($fresh_order, self::UNVERIFIED_REQUESTED_META);
            Keys::update_order_meta($fresh_order, self::PROVIDER_RESULT_META,
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
            try {
                $current = wc_get_order($order_id);
                $current = is_object($current) ? $current : $order;
                if ($capture_attempted) {
                    self::handle_capture_apply_failure($current, $track_id);
                } else {
                    // The callback was already answered and a reconcile run consumed
                    // its attempt; retry within MAX_RECONCILE_ATTEMPTS when the cursor matches.
                    self::schedule_reconciliation($current, 'lifecycle_exception', $track_id);
                }
            } catch (\Throwable $nested) {
                self::log($capture_attempted ? 'capture_failure_handling_exception' : 'exception_retry_scheduling_exception', 'warning');
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
        $local_requested = Keys::read_order_meta($order, 'UPayments_order_id');
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
        if (!Keys::is_our_gateway((string) $order->get_payment_method())) {
            return false;
        }
        $local_requested = Keys::read_order_meta($order, 'UPayments_order_id');
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
        $current_requested = self::parse_generic_identifier(Keys::read_order_meta($order, 'UPayments_order_id'), 255);
        if ($track_id === null || $requested_order_id === null || $current_requested === null
            || !hash_equals($current_requested, $requested_order_id)
        ) {
            return false;
        }

        $trusted = Keys::read_order_meta($order, self::TRUSTED_TRACK_META);
        if (is_string($trusted) && $trusted !== '') {
            $trusted_requested = Keys::read_order_meta($order, self::TRUSTED_REQUESTED_META);
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
            $unverified_requested = Keys::read_order_meta($order, self::UNVERIFIED_REQUESTED_META);
            if (is_string($unverified_requested) && $unverified_requested !== ''
                && !hash_equals($unverified_requested, $current_requested)
            ) {
                self::reset_attempt_cursor_state($order);
            }
        }

        // Unverified callback routing evidence is replaceable by a later locally
        // preflighted callback for the same current provider order identity. It can
        // never overwrite a trusted cursor for that same attempt.
        Keys::update_order_meta($order, self::UNVERIFIED_TRACK_META, $track_id);
        Keys::update_order_meta($order, self::UNVERIFIED_REQUESTED_META, $current_requested);
        // A callback has arrived for this order: the sweep's "no callback received"
        // note must never be added later, even after the cursor is reset or cleared.
        $order->update_meta_data(self::NO_CALLBACK_NOTED_META, 1);
        $saved = $order->save();
        if ($saved === false || $saved === null || $saved === 0) {
            return false;
        }
        $readback_track = Keys::read_order_meta($order, self::UNVERIFIED_TRACK_META);
        $readback_requested = Keys::read_order_meta($order, self::UNVERIFIED_REQUESTED_META);
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
        $outcome = self::attempt_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source);
        // Webhooks are always acknowledged with HTTP 200, so the provider will
        // not resend. A deferred or transiently failed lookup is retried here.
        if ($source !== 'reconcile' && self::is_secondary_retryable($outcome)) {
            self::enqueue_secondary($order, $track_id, $requested_order_id, self::secondary_kind($outcome));
        }
        return $outcome;
    }

    /**
     * Why a queued secondary track is retried: 'pending' when the provider bound it
     * as not yet completed, 'retry' when its state is unknown (transport, lock or
     * apply failure, or never looked up). Drives eviction order and the exhaustion note.
     */
    private static function secondary_kind($outcome) {
        return is_array($outcome) && isset($outcome['reason']) && $outcome['reason'] === 'secondary_pending'
            ? 'pending'
            : 'retry';
    }

    /**
     * Bounded WP-Cron retry for deferred secondary captures. Cron args contain
     * only the order ID; the queued cursors grant no payment authority.
     */
    public static function reconcile_secondary($order_id) {
        $order_id = self::parse_order_id($order_id);
        if ($order_id === null) {
            return;
        }
        $order = wc_get_order($order_id);
        $gateway = self::gateway();
        if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data')) {
            return;
        }
        $pending = self::secondary_pending($order);
        if (empty($pending)) {
            return;
        }

        // $attempt counts this cycle's runs (order level, hard cap); each entry
        // counts its own runs, so a track queued late still gets its full window.
        $attempt = (int) Keys::read_order_meta($order, self::SECONDARY_ATTEMPT_META) + 1;
        $remaining = array();
        $expired = array();
        foreach ($pending as $entry) {
            $current = wc_get_order($order_id);
            $outcome = self::attempt_secondary_capture($gateway, is_object($current) ? $current : $order, $entry['track'], $entry['requested'], 'reconcile');
            if (!self::is_secondary_retryable($outcome)) {
                continue;
            }
            $entry['runs'] = $entry['runs'] + 1;
            $entry['kind'] = self::secondary_kind($outcome);
            if ($entry['runs'] >= self::MAX_RECONCILE_ATTEMPTS) {
                $expired[] = $entry;
            } else {
                $remaining[] = $entry;
            }
        }

        // Lookups can take seconds; a webhook may have queued another track meanwhile.
        // Re-read under the order lock and keep anything not in this run's snapshot.
        $lock_token = OrderLock::acquire($order_id);
        try {
            $order = wc_get_order($order_id);
            if (!is_object($order)) {
                return;
            }
            foreach (self::secondary_pending($order) as $fresh_entry) {
                if (!self::secondary_entry_in($fresh_entry, $pending) && !self::secondary_entry_in($fresh_entry, $remaining)) {
                    $remaining[] = $fresh_entry;
                }
            }
            self::finish_secondary_run($order, $order_id, $remaining, $expired, $attempt);
        } finally {
            if ($lock_token !== null) {
                OrderLock::release($order_id, $lock_token);
            }
        }
    }

    private static function secondary_entry_in(array $entry, array $list) {
        foreach ($list as $candidate) {
            if (hash_equals($candidate['track'], $entry['track']) && hash_equals($candidate['requested'], $entry['requested'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Runs under the order lock on a fresh read. Ends the cycle at the order-level
     * hard cap, trims the queue to MAX_SECONDARY_PENDING, tells the merchant about
     * every track that left the queue unresolved, then saves and reschedules.
     */
    private static function finish_secondary_run($order, $order_id, array $remaining, array $expired, $attempt) {
        if ($attempt >= self::MAX_SECONDARY_CYCLE_RUNS) {
            $expired = array_merge($expired, $remaining);
            $remaining = array();
        }
        list($remaining, $evicted) = self::trim_secondary_queue($remaining);
        self::notify_expired_secondary($order, $expired);
        self::notify_evicted_secondary($order, $evicted);
        if (empty($remaining)) {
            Keys::delete_order_meta($order, self::SECONDARY_PENDING_META);
            Keys::delete_order_meta($order, self::SECONDARY_ATTEMPT_META);
            $order->save();
            return;
        }
        Keys::update_order_meta($order, self::SECONDARY_PENDING_META, $remaining);
        Keys::update_order_meta($order, self::SECONDARY_ATTEMPT_META, $attempt);
        $order->save();
        self::schedule_secondary($order, $order_id, $attempt);
    }

    /**
     * Hard queue cap. Evicts the oldest provider-pending entry first, then the
     * oldest entry of unknown state.
     *
     * @return array{0: array, 1: array} [kept entries, evicted entries]
     */
    private static function trim_secondary_queue(array $entries) {
        $entries = array_values($entries);
        $evicted = array();
        while (count($entries) > self::MAX_SECONDARY_PENDING) {
            $victim = 0;
            foreach ($entries as $index => $entry) {
                if ($entry['kind'] === 'pending') {
                    $victim = $index;
                    break;
                }
            }
            $evicted[] = $entries[$victim];
            // Tracks come from callbacks; log() reduces them to a safe code.
            self::log('secondary_evicted_' . $entries[$victim]['track'], 'warning');
            array_splice($entries, $victim, 1);
        }
        return array($entries, $evicted);
    }

    /**
     * Tracks evicted by the queue cap. Evictions can be driven at callback rate with
     * caller-chosen tracks, so each track is only logged (trim_secondary_queue) and
     * the order gets one generic note, ever. Caller holds the order lock on a fresh
     * read and saves. A paid order whose evicted tracks were all still pending at the
     * provider has nothing to act on.
     */
    private static function notify_evicted_secondary($order, array $entries) {
        if (empty($entries) || !is_object($order) || !method_exists($order, 'add_order_note')) {
            return;
        }
        $paid = self::is_verified_capture($order) || (method_exists($order, 'is_paid') && $order->is_paid());
        if ($paid && count(array_filter($entries, function ($entry) { return $entry['kind'] !== 'pending'; })) === 0) {
            return;
        }
        if ((string) $order->get_meta(self::SECONDARY_EVICTED_NOTED_META) !== '') {
            return;
        }
        $order->update_meta_data(self::SECONDARY_EVICTED_NOTED_META, 1);
        $order->add_order_note(
            __('SUPCheckout dropped some queued UPayments payment checks for this order; review the UPayments merchant dashboard.', 'supcheckout')
        );
    }

    /**
     * Tracks that leave the secondary queue unresolved because their window or the
     * cycle cap ran out (evictions: notify_evicted_secondary). Caller holds the order
     * lock on a fresh read and saves. Each track is noted at most once:
     * - state unknown: the merchant must check the dashboard for a capture;
     * - provider still reports it not completed: a calmer note on an unpaid order,
     *   and only a log entry on a paid one, where there is nothing to act on.
     */
    private static function notify_expired_secondary($order, array $entries) {
        if (empty($entries) || !is_object($order)) {
            return;
        }
        $paid = self::is_verified_capture($order) || (method_exists($order, 'is_paid') && $order->is_paid());
        $unverified = array();
        $not_completed = array();
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'pending' && $paid) {
                self::log('secondary_pending_exhausted');
                continue;
            }
            if (!self::claim_track_once($order, self::SECONDARY_EXHAUSTED_NOTED_META, $entry['track'])) {
                continue;
            }
            // Tracks come from untrusted callbacks; WooCommerce renders notes through
            // wp_kses_post, so they are escaped to stay inert text.
            if ($entry['kind'] === 'pending') {
                $not_completed[] = esc_html($entry['track']);
            } else {
                $unverified[] = esc_html($entry['track']);
            }
        }
        if (!empty($unverified)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: comma-separated UPayments track IDs. */
                    __('SUPCheckout for UPayments could not verify a callback for another payment attempt on this order (track %s). Check the UPayments merchant dashboard for a captured payment.', 'supcheckout'),
                    implode(', ', $unverified)
                )
            );
        }
        if (!empty($not_completed)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: comma-separated UPayments track IDs. */
                    __('UPayments still reports another payment attempt on this order (track %s) as not completed. SUPCheckout stopped checking it automatically; if the UPayments dashboard later shows it captured, review this order.', 'supcheckout'),
                    implode(', ', $not_completed)
                )
            );
        }
    }

    private static function attempt_secondary_capture($gateway, $order, $track_id, $requested_order_id, $source) {
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
            // Track, provider order identity and Woo reference are checked before
            // economics, so a currency/amount mismatch is this order's transaction.
            if (!empty($verification['authenticated']) && self::is_economics_mismatch($reason)) {
                self::note_unbound_secondary($order, $track_id, $reason);
            }
            return self::outcome('unchanged', $reason);
        }
        $classification = (string) $verification['classification'];
        if ($classification === ProviderResult::PENDING || $classification === ProviderResult::INDETERMINATE) {
            // The provider can still flip a NULL/pending result to CAPTURED (KNET
            // cron, ~13 min); keep it for the bounded secondary retry.
            self::log('secondary_pending');
            return self::outcome('unchanged', 'secondary_pending');
        }
        if ($classification !== ProviderResult::CAPTURED) {
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
                // An unreadable stored total (R-1) keeps its own, retryable reason, so the
                // track stays in its bounded window and is noted if that window runs out.
                $unreadable = isset($rebound['reason']) && $rebound['reason'] === 'binding_persisted_total_unreadable';
                return self::outcome('unchanged', $unreadable ? 'binding_persisted_total_unreadable' : 'binding_changed_under_lock');
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
            Keys::update_order_meta($fresh_order, self::TRUSTED_TRACK_META, $track_id);
            Keys::update_order_meta($fresh_order, self::TRUSTED_REQUESTED_META, $requested_order_id);
            Keys::update_order_meta($fresh_order, self::PROVIDER_RESULT_META, 'CAPTURED');
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

    private static function is_secondary_retryable($outcome) {
        $reason = is_array($outcome) && isset($outcome['reason']) ? (string) $outcome['reason'] : '';
        // A verified capture that WooCommerce could not record (a failing or throwing
        // payment_complete hook) is retried like a transient lookup failure.
        // A bound PENDING/NULL result is retried too; reconcile_secondary() caps each
        // queued track at MAX_RECONCILE_ATTEMPTS runs (and a cycle at
        // MAX_SECONDARY_CYCLE_RUNS), then notes the merchant once per track.
        return $reason === 'order_lock_contention'
            || $reason === 'secondary_pending'
            || $reason === 'payment_complete_failed'
            || $reason === 'lifecycle_exception'
            || self::is_retryable_verification_reason($reason);
    }

    private static function secondary_pending($order) {
        $raw = Keys::read_order_meta($order, self::SECONDARY_PENDING_META);
        $pending = array();
        if (!is_array($raw)) {
            return $pending;
        }
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['track'], $entry['requested'])
                && self::parse_track_id($entry['track']) !== null
                && self::parse_generic_identifier($entry['requested'], 255) !== null
            ) {
                // Entries written before per-entry state existed start a fresh window.
                $runs = isset($entry['runs']) && is_int($entry['runs']) ? $entry['runs'] : 0;
                $pending[] = array(
                    'track' => (string) $entry['track'],
                    'requested' => (string) $entry['requested'],
                    'runs' => max(0, min($runs, self::MAX_RECONCILE_ATTEMPTS)),
                    'kind' => isset($entry['kind']) && $entry['kind'] === 'pending' ? 'pending' : 'retry',
                );
            }
        }
        return $pending;
    }

    private static function enqueue_secondary($order, $track_id, $requested_order_id, $kind = 'retry') {
        $track_id = self::parse_track_id($track_id);
        $requested_order_id = self::parse_generic_identifier($requested_order_id, 255);
        if ($track_id === null || $requested_order_id === null
            || !is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'update_meta_data')
            || !self::is_known_attempt($order, $requested_order_id)
        ) {
            return;
        }
        // Read-modify-write on a fresh read under the order lock, so concurrent
        // callbacks never overwrite each other's queued tracks. A busy lock still
        // writes the fresh read: no worse than the unlocked path.
        $order_id = (int) $order->get_id();
        $lock_token = OrderLock::acquire($order_id);
        try {
            $fresh = wc_get_order($order_id);
            $order = is_object($fresh) ? $fresh : $order;
            $pending = self::secondary_pending($order);
            if (self::secondary_entry_in(array('track' => $track_id, 'requested' => $requested_order_id), $pending)) {
                return;
            }
            $pending[] = array(
                'track' => $track_id,
                'requested' => $requested_order_id,
                'runs' => 0,
                'kind' => $kind === 'pending' ? 'pending' : 'retry',
            );
            list($pending, $evicted) = self::trim_secondary_queue($pending);
            self::notify_evicted_secondary($order, $evicted);
            Keys::update_order_meta($order, self::SECONDARY_PENDING_META, $pending);
            $order->save();
            self::schedule_secondary($order, $order_id, (int) Keys::read_order_meta($order, self::SECONDARY_ATTEMPT_META));
        } finally {
            if ($lock_token !== null) {
                OrderLock::release($order_id, $lock_token);
            }
        }
    }

    private static function schedule_secondary($order, $order_id, $attempt) {
        $args = array((int) $order_id);
        if ((int) $order_id <= 0 || self::next_scheduled(self::SECONDARY_RECONCILE_HOOK, $args) !== false) {
            return;
        }
        $delays = array(60, 120, 240, 480);
        $delay = $delays[max(0, min((int) $attempt, count($delays) - 1))];
        if (wp_schedule_single_event(time() + $delay, Keys::cron_hook(self::SECONDARY_RECONCILE_HOOK), $args) === false) {
            self::log('secondary_schedule_failed', 'warning');
            // Nothing re-checks the queued track now; tell the merchant once, like the primary path.
            if (is_object($order) && method_exists($order, 'get_meta') && method_exists($order, 'update_meta_data')) {
                self::note_reconciliation_unschedulable($order);
            }
        }
    }

    /**
     * Claims $track_id in the per-track list $meta_key on $order. The caller writes
     * the note only when the claim succeeds, so each track is noted once.
     *
     * @return bool True when this call claimed the track and must write the note.
     */
    private static function claim_track_once($order, $meta_key, $track_id) {
        if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data') || !method_exists($order, 'add_order_note')) {
            return false;
        }
        // _supcheckout_* names are already canonical; legacy names go through Keys.
        $canonical = strpos($meta_key, '_supcheckout_') === 0;
        $noted = $canonical ? $order->get_meta($meta_key) : Keys::read_order_meta($order, $meta_key);
        $noted = is_array($noted) ? array_values(array_filter($noted, 'is_string')) : array();
        if (in_array((string) $track_id, $noted, true)) {
            return false;
        }
        $noted[] = (string) $track_id;
        $noted = array_slice($noted, -self::MAX_SECONDARY_NOTED);
        if ($canonical) {
            $order->update_meta_data($meta_key, $noted);
        } else {
            Keys::update_order_meta($order, $meta_key, $noted);
        }
        return true;
    }

    /**
     * Adds $message once per track. The claim is taken on a fresh read under the
     * order lock (a fresh read without it when another worker holds the lock), so a
     * webhook and a browser return holding stale order objects cannot both note it.
     * Callers must not hold the order lock.
     */
    private static function note_track_once($order, $meta_key, $track_id, $message) {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        $order_id = (int) $order->get_id();
        $lock_token = OrderLock::acquire($order_id);
        try {
            $fresh = wc_get_order($order_id);
            $target = is_object($fresh) ? $fresh : $order;
            if (self::claim_track_once($target, $meta_key, $track_id)) {
                $target->add_order_note($message);
                $target->save();
            }
        } finally {
            if ($lock_token !== null) {
                OrderLock::release($order_id, $lock_token);
            }
        }
    }

    /**
     * An authenticated transaction that bound on identity but not on amount or currency.
     * binding_total_filtered (StatusVerifier, review M-2) is an amount the order total
     * filter hides, not a changed order, so it gets its own wording.
     */
    private static function is_economics_mismatch($reason) {
        return $reason === 'binding_amount' || $reason === 'binding_currency' || $reason === 'binding_total_filtered';
    }

    private static function note_unbound_secondary($order, $track_id, $reason) {
        if ($reason === 'binding_total_filtered') {
            /* translators: %s: UPayments track ID. */
            $message = __('UPayments reported a transaction for another payment attempt on this order (track %s), but a plugin changes the order total WooCommerce reports, so SUPCheckout could not confirm the payment amount and did not apply it. Review the order against the UPayments merchant dashboard.', 'supcheckout');
        } else {
            /* translators: %s: UPayments track ID. */
            $message = __('UPayments reported a transaction for another payment attempt on this order (track %s) whose amount or currency no longer matches the order. SUPCheckout did not apply it. Review it in the UPayments merchant dashboard.', 'supcheckout');
        }
        self::note_track_once($order, self::SECONDARY_NOTED_META, $track_id, sprintf($message, esc_html((string) $track_id)));
    }

    /**
     * The current attempt's own transaction did not bind on amount or currency, so
     * the order stays unpaid. Private note, once per track (shared with the secondary
     * path); names only the track and which value did not match, or that a plugin
     * filters the order total (no amounts, secrets or customer data).
     */
    private static function note_unbound_current($order, $track_id, $reason) {
        if ($reason === 'binding_total_filtered') {
            /* translators: %s: UPayments track ID. */
            $message = __('UPayments reported a transaction for this order (track %s), but a plugin changes the order total WooCommerce reports, so SUPCheckout could not confirm the payment amount and did not mark the order paid. Review the order against the UPayments merchant dashboard before fulfilling it or refunding the payment.', 'supcheckout');
        } elseif ($reason === 'binding_currency') {
            /* translators: %s: UPayments track ID. */
            $message = __('UPayments reported a transaction for this order (track %s) whose currency does not match the order currency. SUPCheckout did not mark the order paid. Check it in the UPayments merchant dashboard; if it shows as captured, review this order before fulfilling it or refunding the payment.', 'supcheckout');
        } else {
            /* translators: %s: UPayments track ID. */
            $message = __('UPayments reported a transaction for this order (track %s) whose amount does not match the order total. SUPCheckout did not mark the order paid. Check it in the UPayments merchant dashboard; if it shows as captured, review this order before fulfilling it or refunding the payment.', 'supcheckout');
        }
        // Tracks come from callbacks; escaped so the note stays inert text.
        self::note_track_once($order, self::SECONDARY_NOTED_META, $track_id, sprintf($message, esc_html((string) $track_id)));
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
        $prior = Keys::read_order_meta($order, self::PRIOR_REQUESTED_META);
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
        $current = Keys::read_order_meta($order, 'UPayments_order_id');
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
        $raw = Keys::read_order_meta($order, self::CALLBACK_LOOKUP_META);
        if (is_string($raw) && preg_match('/^([0-9]{12}):([0-9]{1,4})$/', $raw, $m) && $m[1] === $bucket) {
            $count = (int) $m[2];
        }
        if ($count >= self::CALLBACK_LOOKUPS_PER_MINUTE) {
            self::log('callback_lookup_throttled', 'warning');
            return false;
        }
        Keys::update_order_meta($order, self::CALLBACK_LOOKUP_META, $bucket . ':' . ($count + 1));
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
        if ((string) Keys::read_order_meta($order, self::CAPTURE_APPLY_FAILED_META) === '1') {
            return;
        }
        Keys::update_order_meta($order, self::CAPTURE_APPLY_FAILED_META, 1);
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
            || Keys::is_our_gateway((string) $order->get_payment_method())
        ) {
            return false;
        }
        $requested = Keys::read_order_meta($order, 'UPayments_order_id');
        return is_string($requested) && $requested !== '';
    }

    /**
     * Record an authenticated capture that SUPCheckout must not apply, once per
     * payment ID, and tell the merchant how to resolve it.
     */
    /**
     * @param mixed $verification StatusVerifier::verify() result.
     * @return string|null Payment ID of an authenticated CAPTURED result.
     */
    private static function verified_capture_payment_id($verification) {
        if (!is_array($verification)
            || !isset($verification['classification'], $verification['transaction'])
            || $verification['classification'] !== ProviderResult::CAPTURED
            || !is_array($verification['transaction'])
        ) {
            return null;
        }
        $payment_id = isset($verification['transaction']['payment_id']) ? $verification['transaction']['payment_id'] : null;
        return is_scalar($payment_id) && (string) $payment_id !== '' ? self::parse_generic_identifier((string) $payment_id, 255) : null;
    }

    private static function record_unapplied_capture($order, $payment_id, $reason) {
        $recorded = Keys::read_order_meta($order, self::UNAPPLIED_CAPTURES_META);
        $recorded = is_array($recorded) ? array_values(array_filter($recorded, 'is_string')) : array();
        if (in_array($payment_id, $recorded, true)) {
            return;
        }
        $recorded[] = $payment_id;
        Keys::update_order_meta($order, self::UNAPPLIED_CAPTURES_META, $recorded);
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
        );
        // The payment method title is left alone: WooCommerce already stored the
        // merchant-configured (and WPML/WCML-translated) title at checkout.
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
            foreach ($legacy_capture_meta as $legacy_key => $value) {
                // Snapshot and restore exactly the names being written (Keys::write_keys():
                // the legacy name before the takeover window, both names inside it).
                foreach (Keys::write_keys($legacy_key) as $key) {
                    $exists = method_exists($order, 'meta_exists')
                        ? (bool) $order->meta_exists($key)
                        : (string) $order->get_meta($key) !== '';
                    $legacy_capture_snapshot[$key] = array(
                        'exists' => $exists,
                        'value' => $exists ? $order->get_meta($key) : null,
                    );
                    $order->update_meta_data($key, $value);
                }
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
            Keys::update_order_meta($order, $key, $value);
        }
        Keys::update_order_meta($order, '_upay_verified_capture', 1);
        Keys::update_order_meta($order, 'UPayments_webhook_triggered', 1);
        Keys::update_order_meta($order, self::PUBLIC_STATUS_META, 'completed');
        Keys::delete_order_meta($order, self::CAPTURE_APPLY_FAILED_META);
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

        Keys::update_order_meta($order, 'UPayments_Result', (string) $provider_result);
        Keys::update_order_meta($order, self::PUBLIC_STATUS_META, $target_status);
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
        if (self::next_scheduled(self::RECONCILE_HOOK, $args) !== false) {
            return true;
        }

        $attempt = (int) Keys::read_order_meta($order, self::RECONCILE_ATTEMPT_META);
        if ($attempt >= self::MAX_RECONCILE_ATTEMPTS) {
            self::mark_reconciliation_exhausted($order, $reason);
            return false;
        }

        $delays = array(60, 120, 240, 480);
        $delay = $delays[$attempt];
        $next_attempt = $attempt + 1;

        // Schedule first: an attempt is consumed only once WP-Cron has accepted it.
        $scheduled = wp_schedule_single_event(time() + $delay, Keys::cron_hook(self::RECONCILE_HOOK), $args);
        if ($scheduled === false) {
            self::log('reconcile_schedule_failed', 'warning');
            self::note_reconciliation_unschedulable($order);
            return false;
        }
        try {
            Keys::update_order_meta($order, self::RECONCILE_ATTEMPT_META, $next_attempt);
            Keys::update_order_meta($order, self::RECONCILE_REASON_META, self::safe_code($reason));
            $order->save();
        } catch (\Throwable $e) {
            // An attempt that cannot be counted must not stay scheduled, or every run
            // would schedule the next one without ever reaching MAX_RECONCILE_ATTEMPTS.
            // This removes every reconcile event of this order; none existed before this
            // call (checked above), so normally that is only the one just scheduled.
            self::unschedule_reconciliation($order);
            throw $e;
        }
        return true;
    }

    /**
     * WP-Cron refused the event (a pre_schedule_event filter or a failed cron option
     * write). Without it nothing re-checks the payment, so the merchant is told once.
     * The primary and the capture-only check share the flag (review M-4): the note
     * names neither path, so an order gets it once, whichever is refused first.
     */
    private static function note_reconciliation_unschedulable($order) {
        if ((string) $order->get_meta(self::RECONCILE_SCHEDULE_FAILED_META) === '1') {
            return;
        }
        $order->update_meta_data(self::RECONCILE_SCHEDULE_FAILED_META, 1);
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(
                __('SUPCheckout could not schedule the automatic UPayments payment check for this order. Verify the payment in the UPayments merchant dashboard before fulfilling it.', 'supcheckout')
            );
        }
        $order->save();
    }

    private static function mark_reconciliation_exhausted($order, $reason) {
        if ((string) Keys::read_order_meta($order, self::RECONCILE_EXHAUSTED_META) === '1') {
            return;
        }
        Keys::update_order_meta($order, self::RECONCILE_EXHAUSTED_META, 1);
        Keys::update_order_meta($order, self::RECONCILE_REASON_META, self::safe_code($reason));
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
            Keys::delete_order_meta($order, self::UNVERIFIED_TRACK_META);
            Keys::delete_order_meta($order, self::UNVERIFIED_REQUESTED_META);
            Keys::delete_order_meta($order, self::RECONCILE_ATTEMPT_META);
            Keys::delete_order_meta($order, self::RECONCILE_REASON_META);
            Keys::delete_order_meta($order, self::RECONCILE_EXHAUSTED_META);
            // Batch 2 final review R1-1: the shared "could not schedule" flag belongs to one
            // failure episode. A cleared reconciliation ends it, so a later and independent
            // refused schedule (a late second-attempt track) is reported again.
            $order->delete_meta_data(self::RECONCILE_SCHEDULE_FAILED_META);
            $order->save();
        }
    }

    private static function reset_attempt_cursor_state($order) {
        if (!is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'delete_meta_data')) {
            return;
        }
        self::unschedule_reconciliation($order);
        Keys::delete_order_meta($order, self::TRUSTED_TRACK_META);
        Keys::delete_order_meta($order, self::TRUSTED_REQUESTED_META);
        Keys::delete_order_meta($order, self::UNVERIFIED_TRACK_META);
        Keys::delete_order_meta($order, self::UNVERIFIED_REQUESTED_META);
        Keys::delete_order_meta($order, self::PROVIDER_RESULT_META);
        Keys::delete_order_meta($order, self::RECONCILE_ATTEMPT_META);
        Keys::delete_order_meta($order, self::RECONCILE_REASON_META);
        Keys::delete_order_meta($order, self::RECONCILE_EXHAUSTED_META);
        $order->delete_meta_data(self::SWEEP_PARKED_META);
        // Every caller resets a cursor that a callback created. Without the flag the
        // order would look like "no callback received" to the sweep's missing-callback
        // query, possibly in the same run that just queued that callback's track.
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(self::NO_CALLBACK_NOTED_META, 1);
        }
        $order->save();
    }

    /**
     * Takes an order out of the hourly sweep until its next payment attempt: the next
     * Charge (CheckoutOrchestrator::record_new_attempt()) or a cursor reset for a new
     * attempt (reset_attempt_cursor_state()) unparks it.
     */
    private static function park_from_sweep($order, $track_id) {
        if (!is_object($order) || !method_exists($order, 'update_meta_data') || !method_exists($order, 'save')) {
            return;
        }
        $order->update_meta_data(self::SWEEP_PARKED_META, (string) $track_id);
        $order->save();
    }

    private static function unschedule_reconciliation($order) {
        $args = array((int) $order->get_id());
        for ($i = 0; $i < 2 * (self::MAX_RECONCILE_ATTEMPTS + 2); $i++) {
            $next = self::next_scheduled(self::RECONCILE_HOOK, $args);
            if ($next === false) {
                break;
            }
            wp_unschedule_event($next[0], $next[1], $args);
        }
    }

    private static function trusted_cursor_present($order) {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return false;
        }
        $trusted = Keys::read_order_meta($order, self::TRUSTED_TRACK_META);
        return is_string($trusted) && self::parse_track_id($trusted) !== null;
    }

    private static function trusted_cursor_matches($order, $track_id) {
        $track_id = self::parse_track_id($track_id);
        if ($track_id === null || !is_object($order) || !method_exists($order, 'get_meta')) {
            return false;
        }
        $trusted = Keys::read_order_meta($order, self::TRUSTED_TRACK_META);
        $trusted_requested = self::parse_generic_identifier(Keys::read_order_meta($order, self::TRUSTED_REQUESTED_META), 255);
        $current_requested = self::parse_generic_identifier(Keys::read_order_meta($order, 'UPayments_order_id'), 255);
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
        $unverified = Keys::read_order_meta($order, self::UNVERIFIED_TRACK_META);
        $unverified_requested = self::parse_generic_identifier(Keys::read_order_meta($order, self::UNVERIFIED_REQUESTED_META), 255);
        $current_requested = self::parse_generic_identifier(Keys::read_order_meta($order, 'UPayments_order_id'), 255);
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
            // The stored order total could not be read (R-1): possibly a passing
            // database error, never proof of a mismatch.
            'binding_persisted_total_unreadable',
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
            && (string) Keys::read_order_meta($order, '_upay_verified_capture') === '1';
    }

    private static function is_refunded($order) {
        return is_object($order)
            && method_exists($order, 'has_status')
            && $order->has_status('refunded');
    }

    /**
     * Order ID from a provider callback. UPayments appends its return query with "?"
     * to a return URL that already has one, so wc_order_id arrives as
     * "<id>?payment_id=...". Keep the leading ID only in that exact shape; anything
     * else stays strict. The ID is a routing hint: preflight still binds it to the
     * order's provider identity, and paid state still needs verified provider status.
     *
     * @param mixed $value Raw wc_order_id boundary value.
     * @return int|null
     */
    public static function parse_callback_order_id($value) {
        if (is_string($value) && preg_match('/^([1-9][0-9]{0,17})\?/', $value, $matches)) {
            return self::parse_order_id($matches[1]);
        }
        return self::parse_order_id($value);
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
        return Keys::find_gateway($gateways);
    }

    /**
     * Where a returning browser goes, and which notice it sees. Paid orders reach
     * order-received. A failure or cancellation verified with the provider sends the
     * customer back to pay the same order, with a message. Everything else stays on
     * the neutral page and reveals nothing.
     *
     * @return array{url: string, notice: array{type: string, message: string}|null}
     */
    public static function browser_redirect($captured, $outcome, $gateway, $order) {
        $state = is_array($outcome) && isset($outcome['state']) ? (string) $outcome['state'] : '';
        // A capture the provider confirmed but another worker is still completing lands
        // on order-received too, so the customer is not sent away after paying.
        if (($captured || $state === 'capture_in_progress')
            && is_object($gateway) && is_object($order) && method_exists($gateway, 'get_return_url')
        ) {
            $candidate = $gateway->get_return_url($order);
            if (is_string($candidate) && $candidate !== '') {
                return array('url' => $candidate, 'notice' => null);
            }
        }
        // Like official 3.1.1, a cancellation returns to checkout, where the cart remains.
        if ($state === 'cancelled' && function_exists('wc_get_checkout_url')) {
            return array(
                'url' => wc_get_checkout_url(),
                'notice' => array(
                    'type' => 'notice',
                    'message' => __('Your payment was cancelled. You can try again when you are ready.', 'supcheckout'),
                ),
            );
        }
        if ($state === 'pending') {
            return array(
                'url' => self::neutral_url(),
                'notice' => array(
                    'type' => 'notice',
                    'message' => __('We are confirming your payment with UPayments. You will receive your order confirmation once it is verified.', 'supcheckout'),
                ),
            );
        }
        if (($state === 'failed' || $state === 'cancelled')
            && is_object($order)
            && method_exists($order, 'needs_payment') && $order->needs_payment()
            && method_exists($order, 'get_checkout_payment_url')
        ) {
            $pay_url = $order->get_checkout_payment_url();
            if (is_string($pay_url) && $pay_url !== '') {
                return array(
                    'url' => $pay_url,
                    'notice' => array(
                        'type' => 'error',
                        'message' => __('Your payment was not completed. Please try again or choose another payment method.', 'supcheckout'),
                    ),
                );
            }
        }
        return array('url' => self::neutral_url(), 'notice' => null);
    }

    private static function finish_callback($is_browser, $captured, $gateway, $order, $outcome = null) {
        self::send_nocache_headers();

        if ($is_browser) {
            $target = self::browser_redirect($captured, $outcome, $gateway, $order);
            $redirect = $target['url'];
            $woocommerce = function_exists('WC') ? WC() : null;
            // A notice needs the customer's WooCommerce session; without one, skip it.
            if (is_array($target['notice']) && function_exists('wc_add_notice')
                && is_object($woocommerce) && isset($woocommerce->session) && is_object($woocommerce->session)
            ) {
                wc_add_notice($target['notice']['message'], $target['notice']['type']);
            }
            $paid = $captured || (is_array($outcome) && isset($outcome['state']) && $outcome['state'] === 'capture_in_progress');
            if ($paid && $redirect !== self::neutral_url()) {
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
