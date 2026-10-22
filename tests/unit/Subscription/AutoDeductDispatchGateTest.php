<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier;
use Simplixi\SUPCheckout\Subscription\Cron\Scheduler;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * Fix A1-01 (owner decision 2026-10-22, "Block auto-charges"): while
 * AutoDeductResultVerifier cannot return VERIFIED_SUCCESS, SUPCheckout never sends an
 * automatic renewal charge. A due parent stops before the renewal-card lookup and before
 * any cycle claim, nothing is queued for it, and its order gets one note telling the
 * merchant to collect the renewal manually.
 *
 * The post-dispatch engine (CycleClaim, verifier, held handling) stays in place behind the
 * gate; the takeover matrix exercises it with the gate forced open
 * (tests/integration/lib/takeover-matrix-lib.php, supcheckout_matrix_renew_gate_open()).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AutoDeductDispatchGateTest extends TestCase {
    private const PARENT_ID = 70;
    private const UNCONFIRMED_NOTE = 'Automatic renewal charges are paused: SUPCheckout cannot yet confirm an automatic UPayments charge. '
        . 'An earlier automatic charge for this subscription could not be confirmed. Check the UPayments dashboard for that payment '
        . 'before you collect this renewal manually or send a payment link, so the customer is not charged twice.';

    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_wp_http();
        TakeoverState::flush_cache();
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        $GLOBALS['supcheckout_test_logged'] = array();
        \Action_Scheduler::$initialized = true;
        HistoricalEnrollment::reset_cursor();
        \update_option('woocommerce_upayments_settings', array('enabled' => 'yes', 'enable_subscriptions' => 'yes'));
        \update_option('upay_billing_cycle_schema_version', '2');
        $GLOBALS['wpdb'] = self::recording_wpdb();
        \supcheckout_test_reset_subscription_presentation();
        $GLOBALS['supcheckout_test_saved_card_lookups'] = array();
        $GLOBALS['supcheckout_test_unconfirmed_rows'] = '0';
        $GLOBALS['supcheckout_test_gateway_subscriptions'] = 'yes';
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = self::woocommerce_with_gateway();
        if (!function_exists('wc_get_logger')) {
            eval('function wc_get_logger() { return new class() {
                public function __call($level, $args) { $GLOBALS["supcheckout_test_logged"][] = $args[0]; }
            }; }');
        }
    }

    public function test_the_gate_is_closed_while_the_verifier_cannot_return_verified_success(): void {
        self::assertFalse(AutoDeductResultVerifier::can_verify_success());
    }

    public function test_a_due_parent_is_not_eligible_and_reaches_no_provider_or_claim(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        $outcome = Scheduler::process_parent_order($parent);

        self::assertSame(Scheduler::OUTCOME_NOT_ELIGIBLE, $outcome, 'not_eligible: the worker never retries it');
        self::assertSame(array(), $GLOBALS['supcheckout_test_saved_card_lookups'], 'the gate stops before the renewal-card lookup');
        self::assertSame(array(), $GLOBALS['supcheckout_test_http_calls'], 'no retrieve-customer-cards and no auto-deduct request');
        foreach ($GLOBALS['supcheckout_test_wpdb_queries'] as $statement) {
            // Only the read-only unconfirmed-charge lookup behind the note (review I1).
            self::assertStringStartsWith('get_var ', $statement, 'no cycle-claim write and no claim row');
            self::assertMatchesRegularExpression('/"query":"(SHOW TABLES|SELECT COUNT)/', $statement);
        }
    }

    // --- Review I1: a possible earlier debit changes the note. ---

    public function test_a_parent_with_a_held_or_dispatching_cycle_gets_the_check_the_dashboard_note(): void {
        $GLOBALS['supcheckout_test_unconfirmed_rows'] = '1';
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        Scheduler::process_parent_order($parent);

        self::assertSame(array(self::UNCONFIRMED_NOTE), $parent->notes);
        self::assertSame(LifecycleScheduler::PAUSED_NOTE_UNCONFIRMED, (string) $parent->get_meta(LifecycleScheduler::PAUSED_NOTE_META), 'the flag records which note was written, for the admin box');
        $lookup = array_values(array_filter($GLOBALS['supcheckout_test_wpdb_queries'], static function ($statement) {
            return strpos($statement, 'SELECT COUNT') !== false;
        }));
        self::assertCount(1, $lookup);
        self::assertStringContainsString('_billing_attempts",70,"held","dispatching"]', $lookup[0], 'held or dispatching rows of this parent only');
    }

    public function test_a_legacy_unresolved_attempt_also_gets_the_check_the_dashboard_note(): void {
        $parent = self::parent(self::PARENT_ID, array('_upay_last_attempt_at' => '2020-01-01 00:00:00') + self::legacy_meta());

        Scheduler::process_parent_order($parent);

        self::assertSame(array(self::UNCONFIRMED_NOTE), $parent->notes);
    }

    public function test_an_unreadable_billing_history_fails_closed_to_the_check_the_dashboard_note(): void {
        $GLOBALS['supcheckout_test_unconfirmed_rows'] = null;
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        Scheduler::process_parent_order($parent);

        self::assertSame(array(self::UNCONFIRMED_NOTE), $parent->notes);
    }

    // --- Review M1: idempotent under concurrency. ---

    public function test_a_concurrent_pass_holding_the_note_lock_adds_no_note(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());
        \add_option(LifecycleScheduler::NOTE_LOCK_PREFIX . self::PARENT_ID, (string) time());

        self::assertFalse(LifecycleScheduler::note_auto_renewal_paused($parent));

        self::assertSame(array(), $parent->notes);
        self::assertSame('', (string) $parent->get_meta(LifecycleScheduler::PAUSED_NOTE_META));
    }

    public function test_a_stale_note_lock_is_cleared_and_the_lock_is_released_after_the_note(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());
        $lock = LifecycleScheduler::NOTE_LOCK_PREFIX . self::PARENT_ID;
        \add_option($lock, (string) (time() - LifecycleScheduler::NOTE_LOCK_TTL - 1));

        self::assertTrue(LifecycleScheduler::note_auto_renewal_paused($parent));

        self::assertCount(1, $parent->notes);
        self::assertFalse(\get_option($lock), 'the lock is deleted once the note is written');
    }

    public function test_the_flag_is_re_read_from_a_fresh_order_under_the_lock(): void {
        $stale = self::parent(self::PARENT_ID, self::legacy_meta());
        $fresh = self::parent(self::PARENT_ID, array(LifecycleScheduler::PAUSED_NOTE_META => '1') + self::legacy_meta());

        self::assertFalse(LifecycleScheduler::note_auto_renewal_paused($stale), 'another process already noted the parent');

        self::assertSame(array(), $stale->notes);
        self::assertSame(array(), $fresh->notes);
        self::assertFalse(\get_option(LifecycleScheduler::NOTE_LOCK_PREFIX . self::PARENT_ID));
    }

    // --- Review M4: outcome first, note only when subscriptions are on, note isolated. ---

    public function test_no_note_when_the_gateway_has_subscriptions_disabled(): void {
        $GLOBALS['supcheckout_test_gateway_subscriptions'] = 'no';
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        self::assertSame(Scheduler::OUTCOME_NOT_ELIGIBLE, Scheduler::process_parent_order($parent));

        self::assertSame(array(), $parent->notes);
    }

    public function test_a_failing_note_never_turns_the_outcome_into_a_retry(): void {
        $parent = new class() extends \WC_Order {
            public function save(): int {
                throw new \RuntimeException('database gone');
            }
        };
        $parent->set_id(self::PARENT_ID);
        $parent->set_status('processing');
        $parent->set_payment_method('upayments');
        $parent->set_meta(self::legacy_meta());
        $parent->set_items(array(array('type' => 'custom_type')));
        $GLOBALS['supcheckout_test_status_orders'][self::PARENT_ID] = $parent;

        self::assertSame(Scheduler::OUTCOME_NOT_ELIGIBLE, Scheduler::process_parent_order($parent), 'not pre_dispatch_no_attempt: nothing is retried');
        self::assertFalse(\get_option(LifecycleScheduler::NOTE_LOCK_PREFIX . self::PARENT_ID), 'the lock is released on failure');
    }

    public function test_the_parent_gets_exactly_one_paused_note_across_passes(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        Scheduler::process_parent_order($parent);
        Scheduler::process_parent_order($parent);
        HistoricalEnrollment::enroll_slice(array($parent), true);
        LifecycleScheduler::schedule_resume($parent);

        self::assertSame(array(
            'Automatic renewal charges are paused: SUPCheckout cannot yet confirm an automatic UPayments charge. Collect this renewal manually or send the customer a payment link.',
        ), $parent->notes, 'one note per parent, however many passes reach it');
        self::assertSame('1', (string) $parent->get_meta(LifecycleScheduler::PAUSED_NOTE_META), 'the note is guarded by an order meta flag');
        self::assertSame(1, $parent->saves, 'the flag is saved through the order CRUD once');
        foreach ($parent->notes as $note) {
            self::assertDoesNotMatchRegularExpression('/verified_success|not_eligible|auto-deduct|held/i', $note, 'the note names no internal code');
        }
    }

    public function test_a_renewal_child_or_one_time_order_gets_no_note(): void {
        $child = self::parent(71, array('UPayments_AutoDeduction' => 'yes') + self::legacy_meta());
        $one_time = self::parent(72, array('_upay_subscription_plan' => 'one_time') + self::legacy_meta());

        Scheduler::process_parent_order($child);
        Scheduler::process_parent_order($one_time);

        self::assertSame(array(), $child->notes);
        self::assertSame(array(), $one_time->notes);
    }

    public function test_enrollment_and_lifecycle_scheduling_queue_nothing(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());

        $stats = HistoricalEnrollment::enroll_slice(array($parent), true);
        $resumed = LifecycleScheduler::schedule_resume($parent);
        LifecycleScheduler::maybe_schedule_initial(self::PARENT_ID);

        self::assertSame(0, $stats['scheduled']);
        self::assertSame(1, $stats['skipped']);
        self::assertFalse($resumed);
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled'], 'no due-parent action accumulates');
    }

    public function test_an_already_queued_action_is_neither_retried_nor_requeued(): void {
        $parent = self::parent(self::PARENT_ID, self::legacy_meta());
        $due = (int) HistoricalEnrollment::next_run_at($parent);

        DueParentWorker::handle(self::PARENT_ID, $due, 0);
        DueParentWorker::handle(self::PARENT_ID, $due - 86400 * 40, 0);

        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled'], 'no bounded retry and no stale-cycle replacement');
        self::assertSame(array(), $GLOBALS['supcheckout_test_http_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_saved_card_lookups']);
        self::assertCount(1, $parent->notes);
    }

    /**
     * Audit A5-01: a cut-over store whose official plugin renewed the parent while it was
     * reactivated (legacy `_upay_last_billed_at` = T1, new copy still T0). With the gate
     * closed SUPCheckout sends no auto-deduct, so the diverged copy cannot cause a second
     * charge for the same cycle.
     */
    public function test_a_cut_over_parent_with_diverged_last_billed_sends_no_auto_deduct(): void {
        self::state(TakeoverState::CUT_OVER);
        $t0 = '2026-01-01 00:00:00';
        $t1 = gmdate('Y-m-d H:i:s', time() - 3600);
        $meta = array(
            '_upay_last_billed_at' => $t1,
            '_supcheckout_last_billed_at' => $t0,
            '_supcheckout_auto_deduction' => 'no',
            '_supcheckout_subscription_status' => 'active',
            '_supcheckout_subscription_plan' => 'monthly',
            '_supcheckout_subscription_interval' => '1',
            '_supcheckout_customer_token' => 'cust-1',
            '_supcheckout_card_token' => 'card-A',
        ) + self::legacy_meta();
        $parent = self::parent(self::PARENT_ID, $meta);
        $due = (int) HistoricalEnrollment::next_run_at($parent);
        self::assertLessThan(time(), $due, 'the stale new-name copy makes the parent look due');

        $outcome = Scheduler::process_parent_order($parent);
        DueParentWorker::handle(self::PARENT_ID, $due, 0);

        self::assertSame(Scheduler::OUTCOME_NOT_ELIGIBLE, $outcome);
        self::assertSame(array(), self::auto_deduct_posts(), 'zero auto-deduct POSTs');
        self::assertSame(array(), $GLOBALS['supcheckout_test_saved_card_lookups'], 'no renewal-card lookup either');
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    /** @return array<string,string> */
    private static function legacy_meta(): array {
        return array(
            'UPayments_AutoDeduction' => 'no',
            '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => '1',
            '_upay_customer_unique_token' => 'cust-1',
            '_upay_credit_card_token' => 'card-A',
        );
    }

    /** @param array<string,mixed> $meta */
    private static function parent(int $id, array $meta): \WC_Order {
        $order = new \WC_Order();
        $order->set_id($id);
        $order->set_status('processing');
        $order->set_payment_method('upayments');
        $order->set_total('10.000');
        $order->set_currency('KWD');
        $order->set_meta($meta);
        $order->set_items(array(array('type' => 'custom_type')));
        $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
        return $order;
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }

    /** @return list<array{url:string}> */
    private static function auto_deduct_posts(): array {
        return array_values(array_filter($GLOBALS['supcheckout_test_http_calls'], static function ($call) {
            return strpos((string) $call['url'], 'auto-deduct') !== false;
        }));
    }

    /**
     * A $wpdb that reports the billing-attempts table and every column as present, so
     * CycleClaim::maybe_install() is ready, and records every statement it is handed.
     *
     * @return object
     */
    private static function recording_wpdb() {
        $GLOBALS['supcheckout_test_wpdb_queries'] = array();
        return new class() {
            /** @var string */
            public $prefix = 'wp_';
            /** @var string */
            public $options = 'wp_options';

            public function esc_like($text) {
                return addcslashes((string) $text, '_%\\');
            }

            /** @return array{query:string,args:list<mixed>} */
            public function prepare($query, ...$args) {
                return array('query' => (string) $query, 'args' => $args);
            }

            /** @return mixed */
            public function get_var($prepared) {
                $this->record('get_var', $prepared);
                if (is_array($prepared) && strpos($prepared['query'], 'SELECT COUNT') === 0) {
                    // Held or dispatching rows of the parent (null: the read failed).
                    return $GLOBALS['supcheckout_test_unconfirmed_rows'];
                }
                if (is_array($prepared) && $prepared['args'] !== array()) {
                    return stripslashes((string) end($prepared['args']));
                }
                return null;
            }

            /** @return mixed */
            public function __call($method, $arguments) {
                $this->record($method, $arguments);
                return false;
            }

            /** @param mixed $statement */
            private function record($method, $statement): void {
                $GLOBALS['supcheckout_test_wpdb_queries'][] = $method . ' ' . (string) json_encode($statement);
            }
        };
    }

    /**
     * WooCommerce with the legacy-identity gateway, subscriptions enabled. A saved-card
     * lookup is the first provider step of a renewal; it is recorded.
     *
     * @return object
     */
    private static function woocommerce_with_gateway() {
        $gateway = new class() {
            /** @var string */
            public $id = 'upayments';

            public function get_option($key, $default = '') {
                return $key === 'enable_subscriptions' ? $GLOBALS['supcheckout_test_gateway_subscriptions'] : $default;
            }

            /** @return array<string,mixed> */
            public function getSavedCards($customer_token) {
                $GLOBALS['supcheckout_test_saved_card_lookups'][] = (string) $customer_token;
                return array('result' => 'success', 'data' => array(array('token' => 'card-A')));
            }

            public function getCurrencyCode($currency) {
                return (string) $currency;
            }
        };
        $gateways = new class($gateway) {
            /** @var object */
            private $gateway;

            public function __construct($gateway) {
                $this->gateway = $gateway;
            }

            /** @return array<string,object> */
            public function payment_gateways() {
                return array('upayments' => $this->gateway);
            }
        };
        return new class($gateways) {
            /** @var object */
            public $payment_gateways;

            public function __construct($gateways) {
                $this->payment_gateways = $gateways;
            }

            public function payment_gateways() {
                return $this->payment_gateways;
            }
        };
    }
}
