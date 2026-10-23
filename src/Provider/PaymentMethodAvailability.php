<?php

namespace Simplixi\SUPCheckout\Provider;

use Simplixi\SUPCheckout\Takeover\Keys;

defined('ABSPATH') || exit;

/**
 * UPayments payment-method availability client and cache coordinator.
 *
 * WordPress persistence and the MySQL advisory lock remain the durability
 * adapters for the inherited contract. Provider transport is injected so
 * this service does not own gateway authentication or HTTP implementation.
 */
final class PaymentMethodAvailability {
    private const CACHE_SCHEMA = 3;
    private const RATE_GATE_COOLDOWN = 65;
    // How long a successful result may stand in when a refresh is blocked or fails.
    private const LAST_KNOWN_GOOD_MAX_AGE = 21600;
    // How old a successful result may be and still be shown on a shopper render
    // without asking UPayments (fetch_for_display()). Payment paths never use it.
    private const DISPLAY_MAX_AGE = 600;

    private const KNOWN_BUTTONS = array(
        'knet',
        'credit_card',
        'apple_pay_knet',
        'apple_pay',
        'samsung_pay',
        'google_pay',
    );

    /** @var bool */
    private $test_mode;

    /** @var string */
    private $api_key;

    /** @var callable */
    private $transport;

    /**
     * @param mixed    $test_mode Truthy selects the sandbox cache/lock scope.
     * @param string   $api_key Current gateway API credential.
     * @param callable $transport Zero-argument hardened provider transport.
     */
    public function __construct($test_mode, $api_key, callable $transport) {
        $this->test_mode = (bool) $test_mode;
        $this->api_key = $api_key;
        $this->transport = $transport;
    }

    /** @return string The legacy rate-gate option name. */
    private function rate_gate_option_name() {
        return 'upayments_payment_methods_rate_gate_' . ($this->test_mode ? 'test' : 'live');
    }

    /**
     * Every rate-gate option name in use (Keys::ephemeral_names(): the legacy name
     * until the takeover starts copying, both during the switch window, the new name
     * after Finalize).
     *
     * @return list<string>
     */
    private function rate_gate_option_names() {
        return Keys::ephemeral_names($this->rate_gate_option_name());
    }

    /** @return string Credential/mode scope shared by the fresh cache and the last-known-good value. */
    private function fingerprint() {
        $mode = $this->test_mode ? 'test' : 'live';
        $fingerprint = hash_hmac('sha256', $mode . '|' . $this->api_key, wp_salt('auth'));
        return substr($fingerprint, 0, 16);
    }

    /** @return string The legacy fresh-cache transient name. */
    private function transient_name() {
        return 'upay_pm_v3_' . $this->fingerprint();
    }

    /** @return list<string> Fresh-cache transient names in use, read in order and written together. */
    private function transient_names() {
        return Keys::ephemeral_names($this->transient_name());
    }

    /** @return string The legacy advisory-lock name. */
    private function lock_name() {
        global $wpdb;

        $mode = $this->test_mode ? 'test' : 'live';
        $db_name = defined('DB_NAME') ? DB_NAME : '';
        $lock_input = $db_name . '|' . $wpdb->prefix . '|' . (string) get_current_blog_id() . '|' . $mode;
        return 'upay_pm_' . substr(hash('sha256', $lock_input), 0, 16);
    }

    /** @return list<string> Advisory-lock name in use (Keys::advisory_lock_names()). */
    private function lock_names() {
        return Keys::advisory_lock_names($this->lock_name());
    }

    /** @return int 1 when every name is held, else 0 (busy) or -1 (error); nothing stays held on failure. */
    private function acquire_lock() {
        $held = array();
        foreach ($this->lock_names() as $name) {
            $result = $this->acquire_one_lock($name);
            if ($result !== 1) {
                foreach (array_reverse($held) as $held_name) {
                    $this->release_one_lock($held_name);
                }
                return $result;
            }
            $held[] = $name;
        }
        return 1;
    }

    /** @return bool */
    private function release_lock() {
        foreach (array_reverse($this->lock_names()) as $name) {
            $this->release_one_lock($name);
        }
        return true;
    }

    /**
     * @param string $name Advisory-lock name.
     * @return int
     */
    private function acquire_one_lock($name) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock is an atomic concurrency primitive and must never be cached.
        $result = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)
        );
        if ($result === '1' || $result === 1) {
            return 1;
        }
        if ($result === '0' || $result === 0) {
            return 0;
        }
        return -1;
    }

    /**
     * @param string $name Advisory-lock name.
     * @return void
     */
    private function release_one_lock($name) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory-lock release must execute against the live connection and cannot be cached.
        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)
        );
    }

    /** @return array The strictest (latest) cooldown across every gate name. */
    private function rate_gate() {
        $not_before = 0;
        foreach ($this->rate_gate_option_names() as $index => $name) {
            $value = (int) get_option($name, 0);
            $not_before = $index === 0 ? $value : max($not_before, $value);
        }
        return array('not_before' => $not_before);
    }

    /**
     * @param int $not_before Unix timestamp boundary value.
     * @return bool True only when every gate name was written.
     */
    private function set_rate_gate($not_before) {
        $written = true;
        foreach ($this->rate_gate_option_names() as $name) {
            $written = update_option($name, (int) $not_before, false) && $written;
        }
        return $written;
    }

    /**
     * Recomputed from the fingerprint inputs rather than sliced out of a transient
     * name, so renaming the cache prefix can never orphan the stored value.
     *
     * @return string
     */
    private function last_known_good_name() {
        return 'supcheckout_pm_lkg_v1_' . $this->fingerprint();
    }

    /**
     * Keep the latest canonical success, scoped like the fresh cache.
     *
     * @param array $canonical Canonical schema-3 success value.
     * @return void
     */
    private function remember_last_known_good(array $canonical) {
        update_option($this->last_known_good_name(), array('saved_at' => time(), 'value' => $canonical), false);
    }

    /**
     * The fresh cache and cooldown expire every minute. While one worker refreshes,
     * every other checkout would otherwise see "unavailable", and one provider blip
     * would block the method for the whole cooldown. A recent canonical success
     * (at most six hours old, same credential and mode) stands in instead. With no
     * valid last-known-good result the method still fails closed.
     *
     * @return array
     */
    private function failure_or_last_known_good() {
        $stored = $this->last_known_good(self::LAST_KNOWN_GOOD_MAX_AGE);
        return $stored !== null ? $stored : array('result' => 'failure');
    }

    /**
     * The stored canonical success when it is at most $max_age seconds old, else null.
     *
     * @param int $max_age Maximum age in seconds.
     * @return array|null
     */
    private function last_known_good($max_age) {
        $stored = get_option($this->last_known_good_name(), null);
        if (is_array($stored)
            && isset($stored['saved_at'], $stored['value'])
            && is_int($stored['saved_at'])
            && $stored['saved_at'] <= time()
            && time() - $stored['saved_at'] <= $max_age
            && self::classify_cached($stored['value']) === 'success'
        ) {
            return $stored['value'];
        }
        return null;
    }
    /** @return array|null The first cached array under the names in use. */
    private function cached() {
        foreach ($this->transient_names() as $name) {
            $cached = get_transient($name);
            if (is_array($cached)) {
                return $cached;
            }
        }
        return null;
    }

    /**
     * cached() without its side effect, for probe(). WordPress's get_transient()
     * deletes an expired transient's rows, so it is not a pure read. This reads the
     * same value it would return: the object cache when one is in use, otherwise the
     * `_transient_` option, ignored once its `_transient_timeout_` has passed.
     *
     * @return array|null
     */
    private function peek_cached() {
        $object_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        foreach ($this->transient_names() as $name) {
            if ($object_cache) {
                $cached = function_exists('wp_cache_get') ? wp_cache_get($name, 'transient') : false;
            } else {
                $timeout = get_option('_transient_timeout_' . $name, null);
                $expired = $timeout !== null && $timeout !== false && (int) $timeout < time();
                $cached = $expired ? false : get_option('_transient_' . $name, false);
            }
            if (is_array($cached)) {
                return $cached;
            }
        }
        return null;
    }

    /**
     * Classify only the strict canonical schema-3 cache shapes.
     *
     * @param mixed $cached Cached value.
     * @return string|bool 'success', 'failure', or false when malformed.
     */
    public static function classify_cached($cached) {
        if (!is_array($cached)) {
            return false;
        }
        if (count($cached) === 2
            && array_key_exists('schema', $cached) && $cached['schema'] === self::CACHE_SCHEMA
            && array_key_exists('state', $cached) && $cached['state'] === 'failure'
        ) {
            return 'failure';
        }

        $cached_keys = array_keys($cached);
        sort($cached_keys);
        $expected_keys = array('schema', 'result', 'isWhiteLabel', 'payButtons');
        sort($expected_keys);
        if ($cached_keys !== $expected_keys
            || !isset($cached['schema']) || $cached['schema'] !== self::CACHE_SCHEMA
            || !isset($cached['result']) || $cached['result'] !== 'success'
            || !isset($cached['isWhiteLabel']) || !is_bool($cached['isWhiteLabel'])
            || !isset($cached['payButtons']) || !is_array($cached['payButtons'])
        ) {
            return false;
        }

        $button_keys = array_keys($cached['payButtons']);
        sort($button_keys);
        $expected_button_keys = self::KNOWN_BUTTONS;
        sort($expected_button_keys);
        if ($button_keys !== $expected_button_keys) {
            return false;
        }
        foreach (self::KNOWN_BUTTONS as $button) {
            if (!array_key_exists($button, $cached['payButtons'])
                || !is_int($cached['payButtons'][$button])
                || ($cached['payButtons'][$button] !== 0 && $cached['payButtons'][$button] !== 1)
            ) {
                return false;
            }
        }
        return 'success';
    }

    /** @return array */
    private static function failure_sentinel() {
        return array('schema' => self::CACHE_SCHEMA, 'state' => 'failure');
    }

    /**
     * @param array $result Provider availability cache value.
     * @param int $not_before Durable cooldown boundary.
     * @return bool
     */
    private function cache($result, $not_before) {
        $ttl = max(1, $not_before - time());
        $stored = true;
        foreach ($this->transient_names() as $name) {
            $stored = set_transient($name, $result, $ttl) && $stored;
        }
        return $stored;
    }

    /**
     * Resolve the current payment-method availability.
     *
     * @return array|null Success/provider data, failure result, or null when
     *                    the credential is empty.
     */
    public function fetch() {
        if (empty($this->api_key)) {
            return null;
        }

        $cached = $this->cached();
        if ($cached !== null) {
            $status = self::classify_cached($cached);
            if ($status === 'success') {
                return $cached;
            }
            if ($status === 'failure') {
                return $this->failure_or_last_known_good();
            }
        }

        if ($this->acquire_lock() !== 1) {
            $cached = $this->cached();
            if ($cached !== null) {
                $status = self::classify_cached($cached);
                if ($status === 'success') {
                    return $cached;
                }
                if ($status === 'failure') {
                    return $this->failure_or_last_known_good();
                }
            }
            return $this->failure_or_last_known_good();
        }

        $gate = $this->rate_gate();
        $now = time();

        $cached = $this->cached();
        if ($cached !== null) {
            $status = self::classify_cached($cached);
            if ($status === 'success') {
                $this->release_lock();
                return $cached;
            }
            if ($status === 'failure') {
                $this->release_lock();
                return $this->failure_or_last_known_good();
            }
        }

        if ($now < $gate['not_before']) {
            $this->release_lock();
            return $this->failure_or_last_known_good();
        }

        $new_not_before = $now + self::RATE_GATE_COOLDOWN;
        if (!$this->set_rate_gate($new_not_before)) {
            $this->release_lock();
            return $this->failure_or_last_known_good();
        }
        $verify_gate = $this->rate_gate();
        if ($verify_gate['not_before'] < $new_not_before) {
            $this->release_lock();
            return $this->failure_or_last_known_good();
        }

        // The inherited contract releases the advisory lock before HTTP.
        $this->release_lock();
        $transport = call_user_func($this->transport);
        $failure = self::failure_sentinel();

        $payment_methods = self::normalize_provider_response($transport);
        if ($payment_methods === null) {
            $this->cache($failure, $new_not_before);
            return $this->failure_or_last_known_good();
        }

        $canonical = array(
            'schema'       => self::CACHE_SCHEMA,
            'result'       => 'success',
            'isWhiteLabel' => $payment_methods['isWhiteLabel'],
            'payButtons'   => $payment_methods['payButtons'],
        );
        $this->cache($canonical, $new_not_before);
        $this->remember_last_known_good($canonical);
        return $payment_methods;
    }

    /**
     * Availability for a shopper render only: the checkout page, an order-review
     * refresh or the Blocks payment data (A3-02).
     *
     * The fresh cache lives 65 seconds because UPayments allows one availability
     * request a minute, so without this every render a minute after the last refresh
     * paid a blocking provider call. Here a success at most ten minutes old (the
     * last-known-good copy, same credential and mode) is shown without a call; only
     * an older one goes through fetch() and its rate gate.
     *
     * Never use it to decide a payment. The place-order check (validate_fields())
     * and the Charge path call fetch(), which ignores this copy while the rate gate
     * is open.
     *
     * @return array|null Same shapes as fetch().
     */
    public function fetch_for_display() {
        if (empty($this->api_key)) {
            return null;
        }

        $cached = $this->cached();
        if ($cached !== null) {
            $status = self::classify_cached($cached);
            if ($status === 'success') {
                return $cached;
            }
            if ($status === 'failure') {
                return $this->failure_or_last_known_good();
            }
        }

        $recent = $this->last_known_good(self::DISPLAY_MAX_AGE);
        return $recent !== null ? $recent : $this->fetch();
    }

    /**
     * Read-only availability check for the takeover Preflight (credential_unverified).
     *
     * Same credential scope, fresh-cache classification, cooldown and provider
     * normalization as fetch(), but it writes nothing: no rate-gate update, no fresh
     * cache, failure sentinel or last-known-good write, no advisory lock, and no
     * get_transient() (which deletes expired rows; peek_cached() reads instead). The
     * last-known-good copy is not consulted: it is a checkout-resilience stand-in, not
     * evidence that the current credential works now.
     *
     * - empty credential: null, no provider call;
     * - a fresh canonical success in the cache: that success, no provider call;
     * - a fresh failure sentinel: array('result' => 'failure'), no provider call;
     * - no fresh cache while the 65-second gate is closed: array('result' => 'cooldown').
     *   UPayments documents one availability request per minute and this call cannot
     *   record one, so it never calls inside the window;
     * - otherwise exactly one provider call, normalized like fetch(). It is not
     *   recorded in the gate, so a checkout refresh within the same minute may still
     *   call once more: the only cost of not writing. A failed call returns
     *   array('result' => 'failure', 'http_status' => int, 'provider_unavailable' => bool)
     *   so Preflight can tell an outage from a refusal; never the body or headers.
     *
     * @return array|null Normalized provider data (result 'success'), a failure or
     *                    cooldown result, or null when the credential is empty.
     */
    public function probe() {
        if (empty($this->api_key)) {
            return null;
        }

        $cached = $this->peek_cached();
        if ($cached !== null) {
            $status = self::classify_cached($cached);
            if ($status === 'success') {
                return $cached;
            }
            if ($status === 'failure') {
                return array('result' => 'failure');
            }
        }

        $gate = $this->rate_gate();
        if (time() < $gate['not_before']) {
            return array('result' => 'cooldown');
        }

        $transport = call_user_func($this->transport);
        $payment_methods = self::normalize_provider_response($transport);
        if ($payment_methods !== null) {
            return $payment_methods;
        }
        return array(
            'result' => 'failure',
            'http_status' => is_array($transport) && isset($transport['http_status']) ? (int) $transport['http_status'] : 0,
            'provider_unavailable' => self::is_provider_unavailable($transport),
        );
    }

    /**
     * Whether a failed provider call means UPayments did not answer, rather than that it
     * refused the credential: a transport error or timeout, no HTTP status, an empty (or
     * truncated) body, or HTTP 5xx (including Cloudflare's 520-527). HTTP 401/403 is a
     * refusal even with an empty body. Used by probe() only; fetch() is unchanged.
     *
     * @param mixed $transport Hardened transport outcome.
     * @return bool
     */
    private static function is_provider_unavailable($transport) {
        if (!is_array($transport)) {
            return true;
        }
        $status = isset($transport['http_status']) ? (int) $transport['http_status'] : 0;
        if ($status === 401 || $status === 403) {
            return false;
        }
        return (isset($transport['curl_errno']) && (int) $transport['curl_errno'] !== 0)
            || $status <= 0
            || $status >= 500
            || !isset($transport['body'])
            || !is_scalar($transport['body'])
            || (string) $transport['body'] === '';
    }

    /**
     * Strict provider-response normalization shared by fetch() and probe().
     *
     * @param mixed $transport Hardened transport outcome.
     * @return array|null Provider data with result 'success', a boolean isWhiteLabel and
     *                    exactly the known buttons as 0/1, or null for any failure.
     */
    private static function normalize_provider_response($transport) {
        if (!is_array($transport)
            || !isset($transport['transport_ok']) || $transport['transport_ok'] !== true
            || !isset($transport['http_status']) || (int) $transport['http_status'] !== 201
            || !isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0
            || !isset($transport['body']) || !is_scalar($transport['body'])
            || (string) $transport['body'] === ''
        ) {
            return null;
        }

        $result = json_decode((string) $transport['body'], true);
        if (!is_array($result)
            || !array_key_exists('status', $result) || $result['status'] !== true
            || !isset($result['data']) || !is_array($result['data'])
        ) {
            return null;
        }

        $payment_methods = $result['data'];
        if (!array_key_exists('isWhiteLabel', $payment_methods)) {
            return null;
        }

        $white_label = $payment_methods['isWhiteLabel'];
        if ($white_label === true || $white_label === 1 || $white_label === '1') {
            $normalized_white_label = true;
        } elseif ($white_label === false || $white_label === 0 || $white_label === '0') {
            $normalized_white_label = false;
        } else {
            return null;
        }

        $raw_buttons = isset($payment_methods['payButtons']) && is_array($payment_methods['payButtons'])
            ? $payment_methods['payButtons']
            : array();
        $normalized_buttons = array();
        foreach (self::KNOWN_BUTTONS as $button) {
            if (!array_key_exists($button, $raw_buttons)) {
                $normalized_buttons[$button] = 0;
                continue;
            }
            $value = $raw_buttons[$button];
            if ($value === true || $value === 1 || $value === '1') {
                $normalized_buttons[$button] = 1;
            } elseif ($value === false || $value === 0 || $value === '0') {
                $normalized_buttons[$button] = 0;
            } else {
                return null;
            }
        }

        $payment_methods['result'] = 'success';
        $payment_methods['isWhiteLabel'] = $normalized_white_label;
        $payment_methods['payButtons'] = $normalized_buttons;
        return $payment_methods;
    }
}
