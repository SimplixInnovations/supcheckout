<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\StatusRateGate;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 2: the status rate gate, the payment-methods cache/rate gate and the
 * billing-attempts claim inside the takeover window, on the real TakeoverState.
 */
final class WindowGatesTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_availability();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    public function test_the_status_rate_gate_claims_a_slot_under_both_prefixes_in_the_window(): void {
        $GLOBALS['wpdb'] = new \SUPCheckout_Test_WPDB();
        self::assertTrue(StatusRateGate::acquire(self::gateway()));
        self::assertSame(array(1, 0), self::slot_counts(), 'idle: legacy slots only');

        self::state('copying');
        self::assertTrue(StatusRateGate::acquire(self::gateway()));
        self::assertSame(array(2, 1), self::slot_counts(), 'copying: one slot under each prefix');

        self::state('finalized');
        self::assertTrue(StatusRateGate::acquire(self::gateway()));
        self::assertSame(array(2, 2), self::slot_counts(), 'finalized: the new prefix only');
    }

    public function test_the_payment_methods_cache_and_rate_gate_use_both_names_in_the_window(): void {
        self::state('copying');
        $service = new PaymentMethodAvailability(false, 'window-key', static function () {
            return array(
                'transport_ok' => true,
                'http_status' => 201,
                'curl_errno' => 0,
                'body' => json_encode(array('status' => true, 'data' => array('isWhiteLabel' => true, 'payButtons' => array('knet' => 1)))),
            );
        });
        $result = $service->fetch();
        self::assertSame('success', $result['result']);

        $fingerprint = substr(hash_hmac('sha256', 'live|window-key', \wp_salt('auth')), 0, 16);
        self::assertArrayHasKey('upay_pm_v3_' . $fingerprint, $GLOBALS['supcheckout_test_availability']['transients']);
        self::assertArrayHasKey('supcheckout_pmcache_v3_' . $fingerprint, $GLOBALS['supcheckout_test_availability']['transients']);
        self::assertArrayHasKey('upayments_payment_methods_rate_gate_live', $GLOBALS['supcheckout_test_options']);
        self::assertArrayHasKey('supcheckout_pmgate_v1_live', $GLOBALS['supcheckout_test_options']);
        self::assertSame(1, $GLOBALS['supcheckout_test_availability']['lock_acquires'], 'one GET_LOCK name at a time');
        self::assertSame(array(), $GLOBALS['supcheckout_test_availability']['locks'], 'released');

        // A gate set only under the new name (a post-cutover worker) still blocks.
        unset($GLOBALS['supcheckout_test_availability']['transients']['upay_pm_v3_' . $fingerprint]);
        unset($GLOBALS['supcheckout_test_availability']['transients']['supcheckout_pmcache_v3_' . $fingerprint]);
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_live'] = 0;
        $GLOBALS['supcheckout_test_options']['supcheckout_pmgate_v1_live'] = time() + 60;
        $calls = 0;
        $blocked = new PaymentMethodAvailability(false, 'window-key', static function () use (&$calls) {
            ++$calls;
            return array();
        });
        $blocked->fetch();
        self::assertSame(0, $calls, 'the strictest cooldown across both names wins');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_cycle_claim_rereads_the_takeover_state_before_choosing_its_table(): void {
        if (!function_exists('current_time')) {
            eval('function current_time($type, $gmt = 0) { return "2026-10-17 00:00:00"; }');
        }
        $GLOBALS['supcheckout_test_claim_sql'] = array();
        $GLOBALS['wpdb'] = new class() {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) { return array('query' => $query, 'args' => $args); }
            public function query($prepared) { $GLOBALS['supcheckout_test_claim_sql'][] = $prepared['args'][0]; return 0; }
            public function get_row($prepared, $output = null) { return null; }
        };

        self::assertFalse(TakeoverState::is_cut_over(), 'a long-running worker cached "not cut over"');
        // Cutover lands in another request; this worker's cache is now stale.
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));

        \Simplixi\SUPCheckout\Subscription\Cron\CycleClaim::acquire(str_repeat('a', 64), 10, str_repeat('b', 32), '2026-10-17 00:00:00');
        \Simplixi\SUPCheckout\Subscription\Cron\CycleClaim::acquire_with_snapshot(str_repeat('c', 64), 10, str_repeat('d', 32), '2026-10-17 00:00:00', '10.000', 'KWD');
        self::assertSame(array('wp_supcheckout_billing_attempts', 'wp_supcheckout_billing_attempts'), $GLOBALS['supcheckout_test_claim_sql']);
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }

    /** @return array{0:int,1:int} [legacy slots, new slots] (bucket markers excluded). */
    private static function slot_counts(): array {
        $legacy = 0;
        $new = 0;
        foreach (array_keys($GLOBALS['supcheckout_test_options']) as $name) {
            if (substr($name, -7) === '_bucket') {
                continue;
            }
            if (strpos($name, 'simplixpay_upay_status_v1_') === 0) {
                ++$legacy;
            } elseif (strpos($name, 'supcheckout_status_gate_v1_') === 0) {
                ++$new;
            }
        }
        return array($legacy, $new);
    }

    /** @return object */
    private static function gateway() {
        return new class() {
            /** @var string */
            public $apiKey = 'window-credential';
            public function getMode() { return false; }
        };
    }
}
