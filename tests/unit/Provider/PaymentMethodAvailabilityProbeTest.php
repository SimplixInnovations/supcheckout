<?php

namespace Simplixi\SUPCheckout\Tests\Provider;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;

/**
 * PaymentMethodAvailability::probe(): the read-only variant the takeover Preflight
 * uses for `credential_unverified`. fetch() writes the rate gate, the fresh cache and
 * the last-known-good copy; probe() must write none of them and take no lock.
 */
final class PaymentMethodAvailabilityProbeTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_availability();
    }

    public function test_probe_returns_null_without_a_credential_and_never_calls_the_provider(): void {
        $calls = 0;
        $service = new PaymentMethodAvailability(false, '', $this->transport($calls, $this->envelope(array('isWhiteLabel' => 0))));

        self::assertNull($service->probe());
        self::assertSame(0, $calls);
        $this->assertNothingWritten();
    }

    public function test_probe_returns_a_fresh_cached_success_without_calling_the_provider(): void {
        $calls = 0;
        $service = new PaymentMethodAvailability(false, 'cached-key', $this->transport($calls, false));
        $this->seed_transient($service, $this->canonical(true), time() + 60);
        $snapshot = $this->snapshot();

        self::assertSame($this->canonical(true), $service->probe());
        self::assertSame(0, $calls);
        self::assertSame($snapshot, $this->snapshot());
        $this->assertNothingWritten();
    }

    public function test_probe_reports_a_fresh_failure_sentinel_as_failure_and_ignores_last_known_good(): void {
        $calls = 0;
        $service = new PaymentMethodAvailability(false, 'failed-key', $this->transport($calls, false));
        $this->seed_transient($service, array('schema' => 3, 'state' => 'failure'), time() + 60);
        $GLOBALS['supcheckout_test_options'][$this->invoke($service, 'last_known_good_name')] = array(
            'saved_at' => time(),
            'value' => $this->canonical(false),
        );
        $snapshot = $this->snapshot();

        self::assertSame(array('result' => 'failure'), $service->probe());
        self::assertSame(0, $calls);
        self::assertSame($snapshot, $this->snapshot());
        $this->assertNothingWritten();
    }

    public function test_probe_never_reaches_a_writing_or_deleting_call(): void {
        $source = file((string) (new \ReflectionClass(PaymentMethodAvailability::class))->getFileName());
        foreach (array('probe', 'peek_cached', 'rate_gate', 'normalize_provider_response') as $method) {
            $reflection = new ReflectionMethod(PaymentMethodAvailability::class, $method);
            $body = implode('', array_slice($source, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
            foreach (array('get_transient(', 'set_transient(', 'update_option(', 'add_option(', 'delete_option(', 'acquire_lock(', '->cache(', 'remember_last_known_good(', 'set_rate_gate(') as $call) {
                self::assertStringNotContainsString($call, $body, $method . '() calls ' . $call);
            }
        }
    }

    public function test_probe_ignores_an_expired_cache_row_without_deleting_it(): void {
        // WordPress get_transient() would delete these two rows; probe() must only read.
        $calls = 0;
        $service = new PaymentMethodAvailability(false, 'expired-key', $this->transport($calls, $this->envelope(array('isWhiteLabel' => 0))));
        $this->seed_transient($service, $this->canonical(true), time() - 1);
        $snapshot = $this->snapshot();

        $result = $service->probe();

        self::assertSame(1, $calls, 'an expired row is not a fresh result');
        self::assertFalse($result['isWhiteLabel']);
        self::assertSame($snapshot, $this->snapshot(), 'the expired rows are still there');
        $this->assertNothingWritten();
    }

    public function test_probe_never_calls_the_provider_inside_the_rate_gate_window(): void {
        $calls = 0;
        $service = new PaymentMethodAvailability(false, 'gated-key', $this->transport($calls, $this->envelope(array('isWhiteLabel' => 1))));
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = time() + 30;
        $snapshot = $this->snapshot();

        self::assertSame(array('result' => 'cooldown'), $service->probe());
        self::assertSame(0, $calls);
        self::assertSame($snapshot, $this->snapshot());
        $this->assertNothingWritten();
    }

    public function test_probe_calls_the_provider_once_and_normalizes_like_fetch_without_writing(): void {
        $calls = 0;
        $service = new PaymentMethodAvailability(true, 'fresh-key', $this->transport($calls, $this->envelope(array(
            'isWhiteLabel' => '1',
            'payButtons' => array('knet' => true, 'credit_card' => '0', 'future_pay' => 1),
        ))));
        $snapshot = $this->snapshot();

        $result = $service->probe();

        self::assertSame(1, $calls);
        self::assertSame('success', $result['result']);
        self::assertTrue($result['isWhiteLabel']);
        self::assertSame(1, $result['payButtons']['knet']);
        self::assertSame(0, $result['payButtons']['credit_card']);
        self::assertSame(0, $result['payButtons']['google_pay']);
        self::assertArrayNotHasKey('future_pay', $result['payButtons']);
        self::assertSame($snapshot, $this->snapshot(), 'no gate, cache or last-known-good row was written');
        $this->assertNothingWritten();
    }

    public function test_probe_reports_every_transport_or_provider_failure_without_writing(): void {
        $responses = array(
            'not an array' => null,
            'transport failed' => array('transport_ok' => false, 'http_status' => 0, 'curl_errno' => 1, 'body' => null),
            'unexpected status' => array('transport_ok' => true, 'http_status' => 401, 'curl_errno' => 0, 'body' => '{"status":false}'),
            'non-strict status' => $this->envelope(array('isWhiteLabel' => 0), 1),
            'missing white label' => $this->envelope(array('payButtons' => array())),
            'malformed button' => $this->envelope(array('isWhiteLabel' => 0, 'payButtons' => array('knet' => 'yes'))),
        );
        foreach ($responses as $label => $response) {
            \supcheckout_test_reset_availability();
            $calls = 0;
            $service = new PaymentMethodAvailability(false, 'key-' . md5($label), $this->transport($calls, $response));

            self::assertSame(array('result' => 'failure'), $service->probe(), $label);
            self::assertSame(1, $calls, $label);
            $this->assertNothingWritten($label);
        }
    }

    /** @return void */
    private function assertNothingWritten($label = '') {
        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls'], $label . ': option writes');
        self::assertSame(array(), $GLOBALS['supcheckout_test_availability']['transient_ttls'], $label . ': transient writes');
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_acquires'], $label . ': advisory lock');
    }

    /** @return array<string, mixed> */
    private function snapshot() {
        return array($GLOBALS['supcheckout_test_options'], $GLOBALS['supcheckout_test_availability']['transients']);
    }

    private function transport(&$calls, $response) {
        return static function () use (&$calls, $response) {
            ++$calls;
            return $response;
        };
    }

    /** @return array<string, mixed> */
    private function envelope(array $data, $status = true) {
        return array(
            'transport_ok' => true,
            'http_status' => 201,
            'curl_errno' => 0,
            'body' => (string) json_encode(array('status' => $status, 'data' => $data)),
        );
    }

    /** @return array<string, mixed> */
    private function canonical($white_label) {
        return array(
            'schema' => 3,
            'result' => 'success',
            'isWhiteLabel' => $white_label,
            'payButtons' => array(
                'knet' => 1,
                'credit_card' => 1,
                'apple_pay_knet' => 0,
                'apple_pay' => 0,
                'samsung_pay' => 0,
                'google_pay' => 0,
            ),
        );
    }

    /** Store a transient the way WordPress does without an object cache: two option rows. */
    private function seed_transient(PaymentMethodAvailability $service, array $value, $expires) {
        $name = $this->transient($service);
        $GLOBALS['supcheckout_test_options']['_transient_' . $name] = $value;
        $GLOBALS['supcheckout_test_options']['_transient_timeout_' . $name] = $expires;
    }

    private function transient(PaymentMethodAvailability $service) {
        return $this->invoke($service, 'transient_name');
    }

    private function invoke(PaymentMethodAvailability $service, $method) {
        $reflection = new ReflectionMethod($service, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invoke($service);
    }
}
