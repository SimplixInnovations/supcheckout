<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Takeover\Preflight;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 8: the supcheckout callback route mirrors the legacy wc_upayments pair, and
 * the preflight probe is recognised by one exact argument only. The responses
 * themselves (exit boundary, headers, parity with the legacy route) are proven in child
 * processes by tests/harness/architecture-legacy-callback-routing-harness.php and
 * tests/harness/ecosystem-callback-cache-harness.php; real boots in every takeover
 * state by tests/harness/public-surface-snapshot-harness.php, and a paused boot by
 * tests/harness/official-plugin-coexistence-harness.php.
 */
final class LegacyCallbackListenerTest extends TestCase {
    /** @var array<string, mixed> */
    private $get = array();

    protected function setUp(): void {
        $this->get = $_GET;
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        TakeoverState::flush_cache();
        self::reset_registered();
    }

    protected function tearDown(): void {
        $_GET = $this->get;
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        TakeoverState::flush_cache();
        self::reset_registered();
    }

    public function test_the_new_route_is_the_wc_api_hook_of_keys_callback_route(): void {
        self::assertSame('woocommerce_api_' . Keys::CALLBACK_ROUTE, LegacyCallbackListener::HOOK);
        self::assertSame('supcheckout_probe', LegacyCallbackListener::PROBE_ARG);
        self::assertSame('x-supcheckout-probe', LegacyCallbackListener::PROBE_ACK_HEADER);
        self::assertSame(LegacyCallbackListener::PROBE_ARG, Preflight::PROBE_ARG);
        self::assertSame(LegacyCallbackListener::PROBE_ACK_HEADER, Preflight::PROBE_ACK_HEADER);
    }

    public function test_register_attaches_the_lifecycle_listener_once_at_priority_5_in_every_state(): void {
        foreach (array('idle', 'preflight_ok', 'copying', 'verified', 'cut_over', 'finalized', 'rolled_back', 'invalid') as $status) {
            \supcheckout_test_reset_subscription_composition();
            self::reset_registered();
            self::state($status);

            LegacyCallbackListener::register();
            LegacyCallbackListener::register();

            self::assertSame(
                array(array(LegacyCallbackListener::HOOK, array(LegacyCallbackListener::class, 'handle_callback'), 5, 1)),
                $GLOBALS['supcheckout_test_action_calls'],
                $status . ': exactly one priority-5 listener, never gated by the takeover state'
            );
        }
    }

    public function test_register_gateway_attaches_check_ipn_response_at_the_default_priority(): void {
        $gateway = new \stdClass();

        LegacyCallbackListener::register_gateway($gateway);

        self::assertSame(
            array(array(LegacyCallbackListener::HOOK, array($gateway, 'check_ipn_response'), 10, 1)),
            $GLOBALS['supcheckout_test_action_calls']
        );
    }

    public function test_only_the_exact_probe_argument_is_a_probe(): void {
        $_GET = array('supcheckout_probe' => '1');
        self::assertTrue(LegacyCallbackListener::is_probe());

        $not_probes = array(
            array(),
            array('supcheckout_probe' => '0'),
            array('supcheckout_probe' => ''),
            array('supcheckout_probe' => ' 1'),
            array('supcheckout_probe' => '1 '),
            array('supcheckout_probe' => 'true'),
            array('supcheckout_probe' => array('1')),
            array('supcheckout_probe' => 1),
            array('SUPCHECKOUT_PROBE' => '1'),
            array('wc_order_id' => '42', 'track_id' => 'track-42', 'requested_order_id' => 'merchant-42'),
        );
        foreach ($not_probes as $get) {
            $_GET = $get;
            self::assertFalse(LegacyCallbackListener::is_probe(), json_encode($get));
        }
    }

    public function test_the_probe_response_is_never_cacheable_and_carries_the_acknowledgement(): void {
        $headers = LegacyCallbackListener::probe_headers();

        self::assertSame('1', $headers['X-SUPCheckout-Probe']);
        self::assertSame(LegacyCallbackListener::PROBE_ACK_HEADER, strtolower('X-SUPCheckout-Probe'));
        $cache_control = $headers['Cache-Control'];
        foreach (array('no-store', 'no-cache', 'max-age=0', 'private') as $directive) {
            self::assertStringContainsString($directive, $cache_control);
        }
        self::assertStringNotContainsString('public', $cache_control);
        self::assertSame(array('Cache-Control', 'X-SUPCheckout-Probe'), array_keys($headers), 'nothing else is sent');
    }

    private static function state($status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = $status === 'invalid'
            ? '{not json'
            : json_encode(array('status' => $status, 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }

    private static function reset_registered(): void {
        if (!class_exists(LegacyCallbackListener::class)) {
            return;
        }
        $property = new \ReflectionProperty(LegacyCallbackListener::class, 'registered');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue(null, false);
    }
}
