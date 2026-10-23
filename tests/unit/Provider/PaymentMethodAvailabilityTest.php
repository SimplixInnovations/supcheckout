<?php

namespace Simplixi\SUPCheckout\Tests\Provider;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;

final class PaymentMethodAvailabilityTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_availability();
    }

    public function test_cache_gate_and_lock_identities_are_scoped_without_leaking_credentials(): void {
        $live = $this->service(false, 'live-secret', false);
        $test = $this->service(true, 'live-secret', false);
        $rotated = $this->service(false, 'rotated-secret', false);

        $live_transient = $this->invoke_private($live, 'transient_name');
        $test_transient = $this->invoke_private($test, 'transient_name');
        $rotated_transient = $this->invoke_private($rotated, 'transient_name');
        $expected_transient_hash = substr(
            hash_hmac('sha256', 'live|live-secret', 'supcheckout-test-auth-salt'),
            0,
            16
        );
        $expected_lock_hash = substr(
            hash('sha256', 'supcheckout_test_database|wp_7_|7|live'),
            0,
            16
        );

        self::assertSame('upayments_payment_methods_rate_gate_live', $this->invoke_private($live, 'rate_gate_option_name'));
        self::assertSame('upayments_payment_methods_rate_gate_test', $this->invoke_private($test, 'rate_gate_option_name'));
        self::assertSame('upay_pm_v3_' . $expected_transient_hash, $live_transient);
        self::assertNotSame($live_transient, $test_transient);
        self::assertNotSame($live_transient, $rotated_transient);
        self::assertStringNotContainsString('live-secret', $live_transient);
        self::assertSame('upay_pm_' . $expected_lock_hash, $this->invoke_private($live, 'lock_name'));
        self::assertNotSame($this->invoke_private($live, 'lock_name'), $this->invoke_private($test, 'lock_name'));
        self::assertSame($this->invoke_private($live, 'lock_name'), $this->invoke_private($rotated, 'lock_name'));
        self::assertLessThanOrEqual(64, strlen($this->invoke_private($live, 'lock_name')));
    }

    public function test_cache_classifier_accepts_only_exact_schema_three_shapes(): void {
        $canonical = $this->canonical();
        self::assertSame('success', PaymentMethodAvailability::classify_cached($canonical));
        self::assertSame(
            'failure',
            PaymentMethodAvailability::classify_cached(array('schema' => 3, 'state' => 'failure'))
        );

        $mutations = array(
            null,
            array_replace($canonical, array('schema' => 2)),
            array_replace($canonical, array('extra' => 1)),
            array_replace($canonical, array('isWhiteLabel' => '1')),
            array_replace($canonical, array('payButtons' => array_slice($canonical['payButtons'], 0, 5, true))),
            array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('future_pay' => 1)))),
            array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => '1')))),
            array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => true)))),
            array_replace($canonical, array('payButtons' => array_replace($canonical['payButtons'], array('knet' => 2)))),
            array('schema' => 3, 'state' => 'failure', 'extra' => 1),
        );
        foreach ($mutations as $index => $mutation) {
            self::assertFalse(PaymentMethodAvailability::classify_cached($mutation), (string) $index);
        }
    }

    public function test_fresh_success_persists_gate_before_transport_and_caches_canonical_result(): void {
        $transport_calls = 0;
        $lock_held_during_transport = null;
        $gate_during_transport = null;
        $provider_data = array(
            'isWhiteLabel' => '1',
            'payButtons'   => array('knet' => true, 'credit_card' => '0', 'future_pay' => 1),
            'providerTrace' => 'fresh-only',
        );
        $transport = function () use (
            &$transport_calls,
            &$lock_held_during_transport,
            &$gate_during_transport,
            $provider_data
        ) {
            ++$transport_calls;
            $lock_held_during_transport = !empty($GLOBALS['supcheckout_test_availability']['locks']);
            $gate_during_transport = get_option('upayments_payment_methods_rate_gate_live', null);
            return $this->envelope($provider_data);
        };
        $service = new PaymentMethodAvailability(false, 'fresh-key', $transport);

        $before = time();
        $result = $service->fetch();
        $after = time();

        self::assertSame(1, $transport_calls);
        self::assertFalse($lock_held_during_transport);
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_acquires']);
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        // One rate-gate write before transport; the last-known-good copy is written after it.
        $gate_writes = array_values(array_filter($GLOBALS['supcheckout_test_option_calls'], static function ($call) {
            return $call[1] === 'upayments_payment_methods_rate_gate_live';
        }));
        self::assertCount(1, $gate_writes);
        $gate = $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'];
        self::assertGreaterThanOrEqual($before + 65, $gate);
        self::assertLessThanOrEqual($after + 65, $gate);
        self::assertSame($gate, $gate_during_transport);
        self::assertSame('fresh-only', $result['providerTrace']);
        self::assertTrue($result['isWhiteLabel']);
        self::assertSame(1, $result['payButtons']['knet']);
        self::assertSame(0, $result['payButtons']['credit_card']);
        self::assertSame(0, $result['payButtons']['google_pay']);
        self::assertArrayNotHasKey('future_pay', $result['payButtons']);

        $transient = $this->invoke_private($service, 'transient_name');
        self::assertSame($this->canonical(true, 1, 0), $GLOBALS['supcheckout_test_availability']['transients'][$transient]);
        self::assertArrayNotHasKey('providerTrace', $GLOBALS['supcheckout_test_availability']['transients'][$transient]);
        // U5 (A3-02): this pin used to mean "availability is at most 65 s old anywhere".
        // It now pins only the authoritative fresh cache that place-order and Charge
        // read. Shopper renders read the separate ten-minute display copy instead
        // (test_display_copy_lives_ten_minutes_while_the_rate_gate_stays_at_sixty_five_seconds).
        $ttl = $GLOBALS['supcheckout_test_availability']['transient_ttls'][$transient];
        self::assertGreaterThanOrEqual(1, $ttl);
        self::assertLessThanOrEqual(65, $ttl, 'the authoritative fresh cache stays tied to the 65-second rate gate');
    }

    public function test_cache_hits_failure_sentinels_and_empty_credentials_bypass_mutation(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'cached-key', $this->counting_transport($transport_calls, false));
        $transient = $this->invoke_private($service, 'transient_name');
        $GLOBALS['supcheckout_test_availability']['transients'][$transient] = $this->canonical(false, 0);

        self::assertSame($this->canonical(false, 0), $service->fetch());
        self::assertSame(0, $transport_calls);
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_acquires']);

        \supcheckout_test_reset_availability();
        $failure = $this->service(false, 'failure-key', $this->counting_transport($transport_calls, false));
        $failure_transient = $this->invoke_private($failure, 'transient_name');
        $GLOBALS['supcheckout_test_availability']['transients'][$failure_transient] = array(
            'schema' => 3,
            'state'  => 'failure',
        );
        self::assertSame(array('result' => 'failure'), $failure->fetch());
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_acquires']);

        \supcheckout_test_reset_availability();
        $empty = $this->service(false, '', $this->counting_transport($transport_calls, false));
        self::assertNull($empty->fetch());
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_acquires']);
        self::assertSame(0, $transport_calls);
    }

    public function test_lock_contention_rechecks_cache_and_lock_errors_fail_closed(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'contended-key', $this->counting_transport($transport_calls, false));
        $GLOBALS['supcheckout_test_availability']['lock_result'] = '0';
        $GLOBALS['supcheckout_test_availability']['populate_on_lock'] = function () use ($service) {
            $name = $this->invoke_private($service, 'transient_name');
            $GLOBALS['supcheckout_test_availability']['transients'][$name] = $this->canonical(false, 0);
        };

        self::assertSame($this->canonical(false, 0), $service->fetch());
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_acquires']);
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        self::assertSame(0, $transport_calls);

        \supcheckout_test_reset_availability();
        $GLOBALS['supcheckout_test_availability']['lock_result'] = null;
        $error = $this->service(false, 'error-key', $this->counting_transport($transport_calls, false));
        self::assertSame(array('result' => 'failure'), $error->fetch());
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_acquires']);
        self::assertSame(0, $transport_calls);
    }

    public function test_cooldown_and_gate_persistence_failures_prevent_transport(): void {
        $transport_calls = 0;
        $cooldown = $this->service(false, 'cooldown-key', $this->counting_transport($transport_calls, false));
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() + 30;
        self::assertSame(array('result' => 'failure'), $cooldown->fetch());
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        self::assertSame(0, $transport_calls);

        \supcheckout_test_reset_availability();
        $GLOBALS['supcheckout_test_update_option_result'] = false;
        $write_failure = $this->service(false, 'write-key', $this->counting_transport($transport_calls, false));
        self::assertSame(array('result' => 'failure'), $write_failure->fetch());
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        self::assertSame(0, $transport_calls);

        \supcheckout_test_reset_availability();
        $GLOBALS['supcheckout_test_get_option_filter'] = function ($name, $value) {
            if (strpos($name, 'upayments_payment_methods_rate_gate_') === 0
                && !empty($GLOBALS['supcheckout_test_option_calls'])
            ) {
                return (int) $value - 1;
            }
            return $value;
        };
        $verify_failure = $this->service(false, 'verify-key', $this->counting_transport($transport_calls, false));
        self::assertSame(array('result' => 'failure'), $verify_failure->fetch());
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        self::assertSame(0, $transport_calls);
    }

    public function test_malformed_cache_is_refreshed_instead_of_trusted(): void {
        $transport_calls = 0;
        $data = array('isWhiteLabel' => false, 'payButtons' => array());
        $service = $this->service(
            false,
            'malformed-key',
            $this->counting_transport($transport_calls, $this->envelope($data))
        );
        $transient = $this->invoke_private($service, 'transient_name');
        $GLOBALS['supcheckout_test_availability']['transients'][$transient] = array(
            'schema' => 3,
            'result' => 'success',
        );

        $result = $service->fetch();

        self::assertSame('success', $result['result']);
        self::assertSame(1, $transport_calls);
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases']);
        self::assertSame('success', PaymentMethodAvailability::classify_cached(
            $GLOBALS['supcheckout_test_availability']['transients'][$transient]
        ));
    }

    public function test_every_transport_or_provider_failure_caches_the_exact_failure_sentinel(): void {
        $provider_data = array('isWhiteLabel' => true, 'payButtons' => array());
        $failures = array(
            'non-array transport' => false,
            'transport not ok'    => array('transport_ok' => false, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{}'),
            'HTTP status'         => $this->envelope($provider_data, true, 200),
            'curl error'          => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 7, 'body' => '{}'),
            'empty body'          => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => ''),
            'invalid JSON'        => array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{'),
            'non-strict status'   => $this->envelope($provider_data, 1),
            'missing data'        => array(
                'transport_ok' => true,
                'http_status'  => 201,
                'curl_errno'   => 0,
                'body'         => json_encode(array('status' => true)),
            ),
            'missing white label' => $this->envelope(array('payButtons' => array())),
            'malformed white label' => $this->envelope(array('isWhiteLabel' => 'true', 'payButtons' => array())),
            'malformed button' => $this->envelope(array('isWhiteLabel' => true, 'payButtons' => array('knet' => 2))),
        );

        foreach ($failures as $label => $failure) {
            \supcheckout_test_reset_availability();
            $transport_calls = 0;
            $service = $this->service(
                false,
                'failure-' . $label,
                $this->counting_transport($transport_calls, $failure)
            );
            self::assertSame(array('result' => 'failure'), $service->fetch(), $label);
            $transient = $this->invoke_private($service, 'transient_name');
            self::assertSame(
                array('schema' => 3, 'state' => 'failure'),
                $GLOBALS['supcheckout_test_availability']['transients'][$transient],
                $label
            );
            self::assertSame(1, $transport_calls, $label);
            self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_releases'], $label);
        }
    }

    public function test_failed_refresh_serves_the_last_known_good_result(): void {
        $transport_calls = 0;
        $good = $this->service(false, 'lkg-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => false, 'payButtons' => array('knet' => 1)))));
        self::assertSame('success', $good->fetch()['result']);

        // The fresh cache and cooldown expire, then the provider blips.
        $GLOBALS['supcheckout_test_availability']['transients'] = array();
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() - 1;
        $blip = $this->service(false, 'lkg-key', $this->counting_transport($transport_calls, false));

        self::assertSame($this->canonical(false, 1, 0), $blip->fetch());
        // The negative sentinel still throttles provider calls.
        $transient = $this->invoke_private($blip, 'transient_name');
        self::assertSame(array('schema' => 3, 'state' => 'failure'), $GLOBALS['supcheckout_test_availability']['transients'][$transient]);
        self::assertSame($this->canonical(false, 1, 0), $blip->fetch());
        self::assertSame(2, $transport_calls);
    }

    public function test_lock_contention_and_cooldown_serve_the_last_known_good_result(): void {
        $transport_calls = 0;
        $good = $this->service(false, 'busy-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => true, 'payButtons' => array('knet' => 1, 'credit_card' => 1)))));
        $good->fetch();
        $GLOBALS['supcheckout_test_availability']['transients'] = array();

        // Another worker holds the refresh lock: this checkout still shows the methods.
        $GLOBALS['supcheckout_test_availability']['lock_result'] = '0';
        self::assertSame($this->canonical(true, 1, 1), $good->fetch());

        // Inside the cooldown with no fresh cache: still the last known good.
        $GLOBALS['supcheckout_test_availability']['lock_result'] = '1';
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() + 30;
        self::assertSame($this->canonical(true, 1, 1), $good->fetch());
        self::assertSame(1, $transport_calls);
    }

    public function test_last_known_good_is_bounded_in_age_scoped_and_validated(): void {
        $transport_calls = 0;
        $good = $this->service(false, 'aged-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => false, 'payButtons' => array('knet' => 1)))));
        $good->fetch();
        $GLOBALS['supcheckout_test_availability']['transients'] = array();
        $GLOBALS['supcheckout_test_availability']['lock_result'] = '0';
        $name = $this->invoke_private($good, 'last_known_good_name');
        self::assertStringNotContainsString('aged-key', $name);
        self::assertMatchesRegularExpression('/^supcheckout_pm_lkg_v1_[a-f0-9]{16}$/', $name);

        // A different credential or mode never sees it.
        self::assertSame(array('result' => 'failure'), $this->service(false, 'other-key', $this->counting_transport($transport_calls, false))->fetch());
        self::assertSame(array('result' => 'failure'), $this->service(true, 'aged-key', $this->counting_transport($transport_calls, false))->fetch());

        // Older than six hours, from the future, or malformed: fail closed.
        $GLOBALS['supcheckout_test_options'][$name]['saved_at'] = time() - 21601;
        self::assertSame(array('result' => 'failure'), $good->fetch());
        $GLOBALS['supcheckout_test_options'][$name]['saved_at'] = time() + 120;
        self::assertSame(array('result' => 'failure'), $good->fetch());
        $GLOBALS['supcheckout_test_options'][$name] = array('saved_at' => time(), 'value' => array('schema' => 3, 'result' => 'success'));
        self::assertSame(array('result' => 'failure'), $good->fetch());
        self::assertSame(1, $transport_calls);
    }

    /**
     * A3-02: a shopper render (checkout, order-review refresh, Cart block) no longer
     * pays a blocking provider call every minute. Within ten minutes of the last
     * success the display read serves that copy and calls nothing.
     */
    public function test_display_read_serves_a_recent_success_without_a_provider_call_after_the_fresh_cache_expires(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'display-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => true, 'payButtons' => array('knet' => 1, 'credit_card' => 1)))));
        self::assertSame('success', $service->fetch()['result']);
        self::assertSame(1, $transport_calls);

        // Two minutes later: the 65-second fresh cache and the rate gate have both expired.
        $this->age_last_known_good($service, 120);
        $GLOBALS['supcheckout_test_availability']['transients'] = array();
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() - 1;

        $locks_before = $GLOBALS['supcheckout_test_availability']['lock_acquires'];

        self::assertSame($this->canonical(true, 1, 1), $service->fetch_for_display());
        self::assertSame(1, $transport_calls);
        self::assertSame($locks_before, $GLOBALS['supcheckout_test_availability']['lock_acquires'], 'the display copy takes no lock');
    }

    public function test_display_read_refreshes_once_its_copy_is_older_than_ten_minutes(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'stale-display-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => false, 'payButtons' => array('knet' => 1)))));
        $service->fetch();

        $this->age_last_known_good($service, 601);
        $GLOBALS['supcheckout_test_availability']['transients'] = array();
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() - 1;

        self::assertSame('success', $service->fetch_for_display()['result']);
        self::assertSame(2, $transport_calls);
        // The refresh went through the 65-second rate gate like any other.
        self::assertGreaterThanOrEqual(time() + 64, $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live']);
    }

    public function test_display_read_honours_a_fresh_cache_and_never_calls_without_a_credential(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'fresh-display-key', $this->counting_transport($transport_calls, false));
        $transient = $this->invoke_private($service, 'transient_name');
        $GLOBALS['supcheckout_test_availability']['transients'][$transient] = $this->canonical(false, 0);
        self::assertSame($this->canonical(false, 0), $service->fetch_for_display());

        $GLOBALS['supcheckout_test_availability']['transients'][$transient] = array('schema' => 3, 'state' => 'failure');
        self::assertSame(array('result' => 'failure'), $service->fetch_for_display());

        self::assertNull($this->service(false, '', $this->counting_transport($transport_calls, false))->fetch_for_display());
        self::assertSame(0, $transport_calls);
    }

    /**
     * The display copy must never authorize a payment: the place-order and Charge
     * paths keep calling fetch(), which ignores it while the rate gate is open.
     */
    public function test_authoritative_fetch_ignores_the_display_copy_while_the_rate_gate_is_open(): void {
        $transport_calls = 0;
        $service = $this->service(false, 'charge-key', $this->counting_transport($transport_calls, $this->envelope(array('isWhiteLabel' => true, 'payButtons' => array('credit_card' => 1)))));
        $service->fetch();

        $this->age_last_known_good($service, 120);
        $GLOBALS['supcheckout_test_availability']['transients'] = array();
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() - 1;

        self::assertSame('success', $service->fetch()['result']);
        self::assertSame(2, $transport_calls);
    }

    public function test_display_copy_lives_ten_minutes_while_the_rate_gate_stays_at_sixty_five_seconds(): void {
        $reflection = new \ReflectionClass(PaymentMethodAvailability::class);
        self::assertSame(65, $reflection->getConstant('RATE_GATE_COOLDOWN'));
        self::assertSame(600, $reflection->getConstant('DISPLAY_MAX_AGE'));
        self::assertLessThan($reflection->getConstant('LAST_KNOWN_GOOD_MAX_AGE'), $reflection->getConstant('DISPLAY_MAX_AGE'));
    }

    private function age_last_known_good(PaymentMethodAvailability $service, int $seconds): void {
        $name = $this->invoke_private($service, 'last_known_good_name');
        $GLOBALS['supcheckout_test_options'][$name]['saved_at'] = time() - $seconds;
    }

    private function service($test_mode, $api_key, $transport): PaymentMethodAvailability {
        if (!is_callable($transport)) {
            $response = $transport;
            $transport = function () use ($response) {
                return $response;
            };
        }
        return new PaymentMethodAvailability($test_mode, $api_key, $transport);
    }

    private function counting_transport(&$calls, $response): callable {
        return function () use (&$calls, $response) {
            ++$calls;
            return $response;
        };
    }

    private function envelope($data, $status = true, $http_status = 201): array {
        return array(
            'transport_ok' => true,
            'http_status'  => $http_status,
            'curl_errno'   => 0,
            'body'         => json_encode(array('status' => $status, 'data' => $data)),
        );
    }

    private function canonical($white_label = true, $knet = 1, $credit_card = 1): array {
        return array(
            'schema'       => 3,
            'result'       => 'success',
            'isWhiteLabel' => $white_label,
            'payButtons'   => array(
                'knet'           => $knet,
                'credit_card'    => $credit_card,
                'apple_pay_knet' => 0,
                'apple_pay'      => 0,
                'samsung_pay'    => 0,
                'google_pay'     => 0,
            ),
        );
    }

    private function invoke_private($object, $method) {
        $reflection = new ReflectionMethod($object, $method);
        // PHP < 8.1 requires explicit reflection accessibility; PHP 8.1+ does not.
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invoke($object);
    }
}
