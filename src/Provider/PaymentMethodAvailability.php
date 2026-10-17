<?php

namespace Simplixi\SUPCheckout\Provider;

use Simplixi\SUPCheckout\Takeover\Keys;

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

    /** @return list<string> Advisory-lock names in use, acquired in order. */
    private function lock_names() {
        return Keys::ephemeral_names($this->lock_name());
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
        $stored = get_option($this->last_known_good_name(), null);
        if (is_array($stored)
            && isset($stored['saved_at'], $stored['value'])
            && is_int($stored['saved_at'])
            && $stored['saved_at'] <= time()
            && time() - $stored['saved_at'] <= self::LAST_KNOWN_GOOD_MAX_AGE
            && self::classify_cached($stored['value']) === 'success'
        ) {
            return $stored['value'];
        }
        return array('result' => 'failure');
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

        if (!is_array($transport)
            || !isset($transport['transport_ok']) || $transport['transport_ok'] !== true
            || !isset($transport['http_status']) || (int) $transport['http_status'] !== 201
            || !isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0
            || !isset($transport['body']) || !is_scalar($transport['body'])
            || (string) $transport['body'] === ''
        ) {
            $this->cache($failure, $new_not_before);
            return $this->failure_or_last_known_good();
        }

        $result = json_decode((string) $transport['body'], true);
        if (!is_array($result)
            || !array_key_exists('status', $result) || $result['status'] !== true
            || !isset($result['data']) || !is_array($result['data'])
        ) {
            $this->cache($failure, $new_not_before);
            return $this->failure_or_last_known_good();
        }

        $payment_methods = $result['data'];
        if (!array_key_exists('isWhiteLabel', $payment_methods)) {
            $this->cache($failure, $new_not_before);
            return $this->failure_or_last_known_good();
        }

        $white_label = $payment_methods['isWhiteLabel'];
        if ($white_label === true || $white_label === 1 || $white_label === '1') {
            $normalized_white_label = true;
        } elseif ($white_label === false || $white_label === 0 || $white_label === '0') {
            $normalized_white_label = false;
        } else {
            $this->cache($failure, $new_not_before);
            return $this->failure_or_last_known_good();
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
                $this->cache($failure, $new_not_before);
                return $this->failure_or_last_known_good();
            }
        }

        $canonical = array(
            'schema'       => self::CACHE_SCHEMA,
            'result'       => 'success',
            'isWhiteLabel' => $normalized_white_label,
            'payButtons'   => $normalized_buttons,
        );
        $payment_methods['result'] = 'success';
        $payment_methods['isWhiteLabel'] = $normalized_white_label;
        $payment_methods['payButtons'] = $normalized_buttons;
        $this->cache($canonical, $new_not_before);
        $this->remember_last_known_good($canonical);
        return $payment_methods;
    }
}
