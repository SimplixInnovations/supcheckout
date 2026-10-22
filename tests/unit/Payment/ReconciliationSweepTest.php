<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\PaymentLifecycle;
use Simplixi\SUPCheckout\Payment\ProviderResult;
use Simplixi\SUPCheckout\Payment\ReconciliationSweep;

/**
 * Scheduling of the hourly safety-net sweep (A3-06, A1-06(c)) and the failed-order
 * selection rule (A1-06(b)). The sweep's order selection, rotation, missing-callback
 * note and per-order isolation run in tests/harness/provider-payment-lifecycle-harness.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReconciliationSweepTest extends TestCase {
    private const MARKER = 'supcheckout_sweep_ensured_at';

    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/reconciliation-sweep-stubs.php';
        \supcheckout_sweep_test_reset();
        \supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_option_calls'] = array();
        $GLOBALS['supcheckout_test_action_calls'] = array();
    }

    public function test_ajax_and_heartbeat_requests_never_query_action_scheduler(): void {
        $GLOBALS['supcheckout_sweep_test']['ajax'] = true;

        ReconciliationSweep::ensure_scheduled();

        self::assertSame(0, $GLOBALS['supcheckout_sweep_test']['has_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_sweep_test']['schedule_calls']);
    }

    public function test_an_admin_request_ensures_the_action_once_then_throttles_for_twelve_hours(): void {
        ReconciliationSweep::ensure_scheduled();
        ReconciliationSweep::ensure_scheduled();

        self::assertSame(1, $GLOBALS['supcheckout_sweep_test']['has_calls'], 'the second admin request inside the window makes no Action Scheduler query');
        self::assertCount(1, $GLOBALS['supcheckout_sweep_test']['schedule_calls']);
        list($timestamp, $interval, $hook, $args, $group) = $GLOBALS['supcheckout_sweep_test']['schedule_calls'][0];
        self::assertSame(array(3600, ReconciliationSweep::HOOK, array(), 'supcheckout'), array($interval, $hook, $args, $group));
        self::assertGreaterThan(time(), $timestamp);
        self::assertEqualsWithDelta(time(), (int) $GLOBALS['supcheckout_test_options'][self::MARKER], 5);
        self::assertContains(array('update', self::MARKER, $GLOBALS['supcheckout_test_options'][self::MARKER], false), $GLOBALS['supcheckout_test_option_calls'], 'the marker is not autoloaded');

        $GLOBALS['supcheckout_test_options'][self::MARKER] = time() - 12 * 3600 - 1;
        ReconciliationSweep::ensure_scheduled();
        self::assertSame(2, $GLOBALS['supcheckout_sweep_test']['has_calls'], 'the action is re-ensured once the window has passed');
    }

    public function test_an_existing_action_is_not_scheduled_twice(): void {
        $GLOBALS['supcheckout_sweep_test']['has_result'] = true;

        ReconciliationSweep::ensure_scheduled();

        self::assertSame(1, $GLOBALS['supcheckout_sweep_test']['has_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_sweep_test']['schedule_calls']);
        self::assertArrayHasKey(self::MARKER, $GLOBALS['supcheckout_test_options']);
    }

    public function test_a_refused_schedule_is_retried_on_the_next_request(): void {
        $GLOBALS['supcheckout_sweep_test']['schedule_result'] = 0;

        ReconciliationSweep::ensure_scheduled();
        ReconciliationSweep::ensure_scheduled();

        self::assertArrayNotHasKey(self::MARKER, $GLOBALS['supcheckout_test_options']);
        self::assertSame(2, $GLOBALS['supcheckout_sweep_test']['has_calls']);
    }

    public function test_a_marker_from_the_future_does_not_suppress_the_check(): void {
        $GLOBALS['supcheckout_test_options'][self::MARKER] = time() + 86400;

        ReconciliationSweep::ensure_scheduled();

        self::assertSame(1, $GLOBALS['supcheckout_sweep_test']['has_calls']);
    }

    public function test_register_ensures_from_admin_init_and_from_woocommerce_init(): void {
        ReconciliationSweep::register();

        $hooks = array_map(static function ($call) {
            return array($call[0], $call[1]);
        }, $GLOBALS['supcheckout_test_action_calls']);
        self::assertContains(array(ReconciliationSweep::HOOK, array(ReconciliationSweep::class, 'run')), $hooks);
        self::assertContains(array('admin_init', array(ReconciliationSweep::class, 'ensure_scheduled')), $hooks);
        self::assertContains(array('woocommerce_init', array(ReconciliationSweep::class, 'ensure_scheduled_in_background')), $hooks);
    }

    public function test_woocommerce_init_ensures_only_in_background_requests_so_the_storefront_pays_nothing(): void {
        ReconciliationSweep::ensure_scheduled_in_background();
        self::assertSame(0, $GLOBALS['supcheckout_sweep_test']['has_calls'], 'a storefront request makes no query and reads no marker');
        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls']);

        $GLOBALS['supcheckout_sweep_test']['cron'] = true;
        ReconciliationSweep::ensure_scheduled_in_background();
        self::assertSame(1, $GLOBALS['supcheckout_sweep_test']['has_calls'], 'a WP-Cron request (auto-update, no admin visit) ensures the action');
    }

    public function test_failed_orders_are_selected_only_for_non_terminal_trusted_results(): void {
        $excluded = self::not_in_values(PaymentLifecycle::sweep_candidate_meta_query('failed'));
        self::assertNotNull($excluded, 'the failed-order query excludes terminal trusted results');

        $terminal = array(ProviderResult::CAPTURED, ProviderResult::FAILED, ProviderResult::CANCELLED);
        foreach (array('CAPTURED', 'NOT CAPTURED', 'FAILED', 'FAILURE', 'ERROR', 'CANCELED', 'CANCELLED',
            'PENDING', 'AUTHORIZED', 'APPROVED', 'REFUND', 'VOIDED', 'NULL', 'Processing', 'FUTURE_STATUS') as $result) {
            $is_terminal = in_array(ProviderResult::classify($result), $terminal, true);
            self::assertSame($is_terminal, in_array($result, $excluded, true), $result);
        }
    }

    public function test_pending_orders_keep_every_cursor_including_an_unapplied_capture(): void {
        self::assertNull(self::not_in_values(PaymentLifecycle::sweep_candidate_meta_query('pending')));
    }

    /**
     * @param array<mixed> $clause
     * @return list<string>|null
     */
    private static function not_in_values(array $clause) {
        if (isset($clause['compare']) && $clause['compare'] === 'NOT IN') {
            return array_values((array) $clause['value']);
        }
        foreach ($clause as $child) {
            if (is_array($child)) {
                $found = self::not_in_values($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
