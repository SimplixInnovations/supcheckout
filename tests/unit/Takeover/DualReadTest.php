<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\OrderPresentation;
use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Payment\PaymentLifecycle;
use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;
use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * 0b Task 2: every runtime read, query, hook check, write and lock goes through Keys.
 *
 * The default tests run against the real Task 1 TakeoverState stub (never cut over) and
 * pin that a store which never switches sees exactly today's names. The
 * #[RunInSeparateProcess] tests load tests/support/takeover-state-double.php first, which
 * implements the API this task relies on and Task 3 provides
 * (TakeoverState::get()['status'], TakeoverState::is_cut_over()), and drive the switch
 * window and the post-cutover state.
 *
 * H12 provenance under both prefixes runs in tests/harness/takeover-h12-dual-prefix-harness.php:
 * the unit suite replaces CustomerTokenIdentity with a stub.
 */
final class DualReadTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        $_GET = array();
    }

    // --- Before cutover: byte-identical names. ---

    public function test_before_cutover_every_name_is_todays_legacy_name(): void {
        self::assertSame('upayments', Keys::gateway_id());
        self::assertSame('upayments', Keys::extension_namespace());
        self::assertSame('wc_upayments', Keys::callback_route());
        self::assertSame('upayments', Keys::payment_method_query(), 'order queries keep the exact scalar filter');
        self::assertSame(array('woocommerce_upayments_settings'), Keys::settings_option_names());
        self::assertSame('UPayments_PaymentID', Keys::write_key('UPayments_PaymentID'));
        self::assertSame(array('UPayments_PaymentID'), Keys::delete_names('UPayments_PaymentID'));
        self::assertSame('idle', Keys::takeover_status(), 'the Task 1 stub has no get(): idle');
        self::assertSame(array('simplixpay_upay_order_lock_v1_7'), Keys::ephemeral_names('simplixpay_upay_order_lock_v1_7'));
        self::assertSame(array('upay_ctk_bootstrap_b1'), Keys::ephemeral_names('upay_ctk_bootstrap_b1'));

        $clause = array('key' => '_upay_subscription_status', 'value' => 'paused');
        self::assertSame($clause, Keys::meta_clause_any($clause), 'meta_query clauses are unchanged');
    }

    public function test_before_cutover_settings_and_options_read_exactly_the_legacy_option_once(): void {
        \update_option('woocommerce_upayments_settings', array('enabled' => 'yes'));
        \update_option('woocommerce_supcheckout_settings', array('enabled' => 'stale'));
        self::log_option_reads();

        self::assertSame(array('enabled' => 'yes'), Keys::get_settings(array()));
        self::assertSame(array('woocommerce_upayments_settings'), $GLOBALS['supcheckout_test_dual_reads']);

        \supcheckout_test_reset_wp_options();
        self::log_option_reads();
        self::assertNull(Keys::get_settings(null), 'an absent legacy option keeps the caller default; the target is never read');
        self::assertSame(array('woocommerce_upayments_settings'), $GLOBALS['supcheckout_test_dual_reads']);

        \update_option('supcheckout_subscription_enrollment_cursor', 99);
        self::assertSame(0, Keys::get_option('upay_subscription_enrollment_cursor', 0));
    }

    public function test_hook_checks_accept_either_name(): void {
        self::assertTrue(Keys::is_meta_key('_upay_subscription_status', '_upay_subscription_status'));
        self::assertTrue(Keys::is_meta_key('_supcheckout_subscription_status', '_upay_subscription_status'));
        self::assertFalse(Keys::is_meta_key('_upay_subscription_plan', '_upay_subscription_status'));
    }

    public function test_extension_namespace_prefers_the_current_namespace_then_the_legacy_one(): void {
        $posted = new \ReflectionMethod(\Simplixi\SUPCheckout\Payment\CheckoutOrchestrator::class, 'posted_extension_namespace');
        if (\PHP_VERSION_ID < 80100) {
            $posted->setAccessible(true);
        }
        $plan = array('upay_subscription_plan' => 'monthly', 'upay_subscription_interval' => '1');
        self::assertSame('upayments', $posted->invoke(null, array('upayments' => $plan)), 'an already-open checkout posts the legacy namespace');
        self::assertSame('supcheckout', $posted->invoke(null, array('supcheckout' => $plan)));
        self::assertSame('supcheckout', $posted->invoke(null, array('upayments' => $plan, 'supcheckout' => $plan)), 'the current namespace wins');
        self::assertNull($posted->invoke(null, array('other' => $plan)));
        self::assertNull($posted->invoke(null, 'malformed'));
    }

    // --- After cutover: new name first, legacy fallback. ---

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_order_presentation_reads_the_new_name_then_legacy(): void {
        self::state('cut_over');
        self::require_order_fixture();

        $legacy_only = self::order(1, 'upayments', array('UPayments_PaymentID' => 'pay-1', 'UPayments_Result' => 'CAPTURED'));
        $both = self::order(2, 'supcheckout', array('UPayments_PaymentID' => 'pay-1', '_supcheckout_payment_id' => 'pay-2', '_supcheckout_result' => 'CAPTURED'));
        $rows = array('payment_method' => array('label' => 'Method', 'value' => 'UPayments'));

        $legacy_rows = OrderPresentation::add_order_item_totals($rows, $legacy_only, 'supcheckout');
        self::assertSame('pay-1', $legacy_rows['upayment_id']['value'], 'a historical upayments order keeps its payment rows');
        self::assertSame('CAPTURED', $legacy_rows['payment_status']['value']);
        self::assertSame('pay-2', OrderPresentation::add_order_item_totals($rows, $both, 'supcheckout')['upayment_id']['value']);

        $foreign = self::order(3, 'bacs', array('UPayments_PaymentID' => 'pay-3'));
        self::assertSame($rows, OrderPresentation::add_order_item_totals($rows, $foreign, 'supcheckout'), 'another gateway\'s order stays untouched');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_writes_and_deletes_follow_the_new_names(): void {
        self::state('cut_over');

        self::assertSame('_supcheckout_payment_id', Keys::write_key('UPayments_PaymentID'));
        self::assertSame(array('_supcheckout_payment_id', 'UPayments_PaymentID'), Keys::delete_names('UPayments_PaymentID'), 'a removed marker cannot reappear through the legacy fallback');
        self::assertSame('supcheckout', Keys::gateway_id());
        self::assertSame('supcheckout', Keys::callback_route());
        self::assertSame(array('upayments', 'supcheckout'), Keys::payment_method_query(), 'historical upayments orders stay in every order query');
        self::assertSame(array('woocommerce_supcheckout_settings', 'woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings'), Keys::settings_option_names());

        $saved_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
        self::assertSame('wp_supcheckout_billing_attempts', Keys::table('upayments_billing_attempts'));
        $GLOBALS['wpdb'] = $saved_wpdb;

        \update_option('woocommerce_your_gateway_id_settings', array('enabled' => 'yes'));
        self::assertSame(array('enabled' => 'yes'), Keys::get_settings(array()), 'a 3.0.0 store with only the your_gateway_id option still resolves');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_queries_find_legacy_only_and_new_only_orders_but_not_stale_copies(): void {
        self::state('cut_over');
        self::require_order_fixture();

        $_GET = array('subscription_filter' => 'paused');
        $query = Presentation::filter_account_orders_query(array());
        $sweep = PaymentLifecycle::sweep_cursor_meta_query();

        $legacy_only = array('_upay_subscription_status' => 'paused', '_simplixpay_upayments_unverified_track_v1' => 't-1');
        $new_only = array('_supcheckout_subscription_status' => 'paused', '_supcheckout_status_track_v1' => 't-2');
        $stale_copy = array('_upay_subscription_status' => 'paused', '_supcheckout_subscription_status' => 'active');
        $neither = array('_upay_subscription_status' => 'active');

        self::assertTrue(self::meta_query_matches($query['meta_query'], $legacy_only));
        self::assertTrue(self::meta_query_matches($query['meta_query'], $new_only));
        self::assertFalse(self::meta_query_matches($query['meta_query'], $stale_copy), 'a legacy value the new name superseded never matches');
        self::assertFalse(self::meta_query_matches($query['meta_query'], $neither));
        self::assertTrue(self::meta_query_matches(array($sweep), $legacy_only));
        self::assertTrue(self::meta_query_matches(array($sweep), $new_only));
        self::assertFalse(self::meta_query_matches(array($sweep), $neither));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_a_historical_subscription_parent_still_qualifies(): void {
        self::state('cut_over');
        self::require_order_fixture();
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';

        $meta = array('UPayments_AutoDeduction' => 'no', '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly', '_upay_subscription_interval' => '1');
        $historical = self::order(10, 'upayments', $meta, 'processing');
        self::assertTrue(HistoricalEnrollment::parent_qualifies($historical), 'payment_method=upayments, legacy keys only');

        $cancelled_after_cutover = self::order(11, 'upayments', $meta + array('_supcheckout_subscription_status' => 'cancelled'), 'processing');
        self::assertFalse(HistoricalEnrollment::parent_qualifies($cancelled_after_cutover), 'the new name supersedes the stale legacy copy');

        $new_order = self::order(12, 'supcheckout', array('_supcheckout_auto_deduction' => 'no', '_supcheckout_subscription_status' => 'active',
            '_supcheckout_subscription_plan' => 'monthly', '_supcheckout_subscription_interval' => '1'), 'processing');
        self::assertTrue(HistoricalEnrollment::parent_qualifies($new_order));

        self::assertSame('gw-new', Keys::find_gateway(array('supcheckout' => 'gw-new', 'upayments' => 'gw-official')));
        self::assertSame('gw-legacy', Keys::find_gateway(array('bacs' => 'x', 'upayments' => 'gw-legacy')), 'the registered gateway is found under whichever id it has');
        self::assertNull(Keys::find_gateway(array('bacs' => 'x')));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_status_update_under_either_name_reaches_the_lifecycle_hook(): void {
        self::state('cut_over');
        self::require_order_fixture();
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        \Action_Scheduler::$initialized = true;

        foreach (array(40 => '_upay_subscription_status', 41 => '_supcheckout_subscription_status') as $id => $key) {
            $order = self::order($id, 'upayments', array('UPayments_AutoDeduction' => 'no', '_upay_subscription_status' => 'active',
                '_upay_subscription_plan' => 'monthly', '_upay_subscription_interval' => '1'), 'processing');
            $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
            $due = (int) HistoricalEnrollment::next_run_at($order);
            \Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge::ensure_cycle_action($id, $due);
            self::assertCount(1, $GLOBALS['supcheckout_as_calls']['scheduled'], $key);
            LifecycleScheduler::maybe_cancel_on_status_meta(1, $id, $key, 'paused');
            self::assertCount(0, $GLOBALS['supcheckout_as_calls']['scheduled'], $key . ' cancels the cycle');
        }
    }

    // --- Locks across the switch window. ---

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_in_copying_a_dual_acquirer_contends_with_a_legacy_only_holder(): void {
        self::state('idle');
        $worker_a = OrderLock::acquire(77);
        self::assertIsString($worker_a);
        self::assertSame(array('simplixpay_upay_order_lock_v1_77'), array_keys($GLOBALS['supcheckout_test_options']), 'idle: legacy name only');

        self::state('copying');
        self::assertNull(OrderLock::acquire(77), 'worker B takes legacy first and must fail');
        self::assertArrayNotHasKey('supcheckout_order_lock_v1_77', $GLOBALS['supcheckout_test_options'], 'no partial acquisition survives');

        OrderLock::release(77, $worker_a);
        $worker_b = OrderLock::acquire(77);
        self::assertIsString($worker_b);
        self::assertArrayHasKey('simplixpay_upay_order_lock_v1_77', $GLOBALS['supcheckout_test_options']);
        self::assertArrayHasKey('supcheckout_order_lock_v1_77', $GLOBALS['supcheckout_test_options']);

        self::state('cut_over');
        self::assertNull(OrderLock::acquire(77), 'a post-cutover worker contends on the same pair');
        OrderLock::release(77, $worker_b);
        self::assertSame(array(), $GLOBALS['supcheckout_test_options'], 'release frees both names');

        self::state('finalized');
        self::assertSame(array('supcheckout_order_lock_v1_77'), Keys::ephemeral_names('simplixpay_upay_order_lock_v1_77'));
        self::state('invalid');
        self::assertCount(2, Keys::ephemeral_names('simplixpay_upay_order_lock_v1_77'), 'an unknown status contends on both names');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_in_copying_a_post_cutover_holder_blocks_a_legacy_first_worker_on_the_new_name(): void {
        self::state('copying');
        $name_new = 'supcheckout_order_lock_v1_78';
        $GLOBALS['supcheckout_test_options'][$name_new] = (string) (time() + 45) . ':' . str_repeat('d', 32);

        self::assertNull(OrderLock::acquire(78));
        self::assertArrayNotHasKey('simplixpay_upay_order_lock_v1_78', $GLOBALS['supcheckout_test_options'], 'the legacy name taken first is rolled back');
    }

    // --- Last-known-good name. ---

    public function test_last_known_good_name_is_recomputed_from_the_fingerprint_inputs(): void {
        $service = new \Simplixi\SUPCheckout\Provider\PaymentMethodAvailability(true, 'cert-key', static function () {
            return array();
        });
        $lkg = self::call_private($service, 'last_known_good_name');
        $fingerprint = substr(hash_hmac('sha256', 'test|cert-key', \wp_salt('auth')), 0, 16);

        self::assertSame('supcheckout_pm_lkg_v1_' . $fingerprint, $lkg, 'identical to the value stored before Task 2');
        self::assertSame('upay_pm_v3_' . $fingerprint, self::call_private($service, 'transient_name'));
        self::assertSame('supcheckout_pmcache_v3_' . $fingerprint, Keys::ephemeral_prefixes()['upay_pm_v3_'] . $fingerprint, 'the renamed cache keeps the same fingerprint, so the LKG value is never orphaned');
    }

    // --- Helpers. ---

    private static function state(string $status): void {
        if (!class_exists(\Simplixi\SUPCheckout\Takeover\TakeoverState::class, false)) {
            require_once dirname(__DIR__, 2) . '/support/takeover-state-double.php';
        }
        $GLOBALS['supcheckout_test_takeover_status'] = $status;
    }

    private static function require_order_fixture(): void {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        if (!function_exists('wc_get_order')) {
            eval('function wc_get_order($id) { return $GLOBALS["supcheckout_test_status_orders"][(int) $id] ?? false; }');
        }
    }

    /** @param array<string,mixed> $meta */
    private static function order(int $id, string $method, array $meta, string $status = 'pending'): \WC_Order {
        $order = new \WC_Order();
        $order->set_id($id);
        $order->set_payment_method($method);
        $order->set_status($status);
        $order->set_meta($meta);
        $order->set_items(array(array('type' => 'custom_type')));
        return $order;
    }

    private static function log_option_reads(): void {
        $GLOBALS['supcheckout_test_dual_reads'] = array();
        // The takeover state itself (one cached read per request, Task 3) is not a data read.
        $GLOBALS['supcheckout_test_get_option_filter'] = static function ($name, $value) {
            if ($name !== 'supcheckout_takeover_state_v1') {
                $GLOBALS['supcheckout_test_dual_reads'][] = $name;
            }
            return $value;
        };
    }

    /**
     * Minimal WP_Meta_Query evaluator (relation AND/OR, '=' value, EXISTS, NOT EXISTS).
     *
     * @param array<mixed>        $query
     * @param array<string,mixed> $meta
     */
    private static function meta_query_matches(array $query, array $meta): bool {
        $relation = isset($query['relation']) ? $query['relation'] : 'AND';
        $results = array();
        foreach ($query as $index => $clause) {
            if ($index === 'relation') {
                continue;
            }
            if (isset($clause['key'])) {
                $compare = isset($clause['compare']) ? $clause['compare'] : '=';
                $exists = array_key_exists($clause['key'], $meta);
                if ($compare === 'EXISTS') {
                    $results[] = $exists;
                } elseif ($compare === 'NOT EXISTS') {
                    $results[] = !$exists;
                } else {
                    $results[] = $exists && (string) $meta[$clause['key']] === (string) $clause['value'];
                }
            } else {
                $results[] = self::meta_query_matches($clause, $meta);
            }
        }
        return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    /** @return mixed */
    private static function call_private(object $object, string $method) {
        $reflection = new \ReflectionMethod($object, $method);
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invoke($object);
    }
}
