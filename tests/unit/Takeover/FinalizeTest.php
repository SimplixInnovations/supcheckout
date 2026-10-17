<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CopyRemoval;
use Simplixi\SUPCheckout\Takeover\Cutover;
use Simplixi\SUPCheckout\Takeover\Finalize;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator;

/**
 * 0b Task 11: guarded, irreversible finalize (cut_over → finalized), on the real
 * TakeoverState, Journal, Quiesce, OrderLock and Cutover, with an in-memory store and system.
 *
 * Legacy names are removed only where the new name verifies equal (the full value list for
 * multi-valued keys); everything else is kept and reported. The legacy callback route,
 * other plugins' data and the official 3.0.0 "Checkout (Block)" page are never touched.
 */
final class FinalizeTest extends TestCase {
    private const T0 = 1800000000;
    private const DAY = 86400;
    private const SCOPE_KEY = '_upay_customer_token_v2_b1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW_SCOPE_KEY = '_supcheckout_customer_token_v2_b1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PAGE_ID = 55;

    /** @var FakeCopyStore */
    private $store;
    /** @var FakeCutoverSystem */
    private $system;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        CutoverTest::state('verified');
        $this->store = new FakeCopyStore();
        $this->system = new FakeCutoverSystem();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
    }

    private static function key(string $digit): string {
        return str_repeat($digit, 64);
    }

    /** A store cut over through the real Cutover (official plugin already deactivated by `run`). */
    private function seed_cut_over(): FakeCrudObject {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = hash('sha256', 'secret');
        $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 10;
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        $order = new FakeCrudObject(7, 'upayments', array('UPayments_PaymentID' => array('pay-7'), 'UPayments_WHS' => array('failed', 'completed')), 'completed');
        $GLOBALS['supcheckout_test_status_orders'][7] = $order;
        $this->store->user_meta[3] = array(self::SCOPE_KEY => array('provenance-record'), 'simplixpay_upayments_migration_v1' => array('done'));
        $this->store->products[40] = new FakeCrudObject(40, '', array('_upay_disable_subscription' => array('yes')));
        $this->store->new_table_exists = true;
        $this->store->add_legacy_row(array('cycle_key' => self::key('1'), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'resolved', 'payment_id' => 'pay-r'));
        $this->system->events[] = array('timestamp' => self::T0 + 600, 'hook' => 'simplixpay_upayments_reconcile_order', 'args' => array(7), 'schedule' => false);
        $this->system->events[] = array('timestamp' => self::T0 + 3600, 'hook' => 'upay_process_subscriptions', 'args' => array(), 'schedule' => 'hourly');
        $this->system->official = null;
        $cutover = (new Cutover($this->store, $this->system))->run(false);
        self::assertTrue($cutover['ok'], (string) json_encode($cutover));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        return $order;
    }

    /** The official plugin's leftovers and SUPCheckout's legacy-named ephemeral rows. */
    private function seed_leftovers(): void {
        $options = &$GLOBALS['supcheckout_test_options'];
        $options['your_gateway_version'] = '3.0.0';
        $options['upayments_block_checkout_page_id'] = self::PAGE_ID;
        $options['upay_subscription_settings'] = array('x' => 1);
        $options['external_updates-upayments'] = array('checked' => 1);
        $options['external_updates-upayments-woocommerce'] = array('checked' => 2);
        $options['_transient_upayments_cron_lock'] = '1';
        $options['_transient_timeout_upayments_cron_lock'] = self::T0 + 60;
        $options['simplixpay_upay_order_lock_v1_99'] = '1800000999:' . str_repeat('a', 32);
        $options['simplixpay_upay_status_v1_0123456789abcdef_bucket'] = '202610171200';
        $options['upayments_payment_methods_rate_gate_live'] = self::T0;
        $options['_transient_upay_pm_v3_0123456789abcdef'] = array('methods' => array());
        $options['_transient_timeout_upay_pm_v3_0123456789abcdef'] = self::T0 + 60;
        $options['_transient_timeout_upay_pm_v3_fedcba9876543210'] = self::T0 + 60;
        // New names and other plugins' data: never touched.
        $options['supcheckout_order_lock_v1_99'] = '1800000999:' . str_repeat('b', 32);
        $options['external_updates-other-plugin'] = array('checked' => 3);
        $options['some_other_plugin_option'] = 'kept';
        $this->store->post_statuses[self::PAGE_ID] = 'publish';
        $this->store->named_tables['upay_subscriptions'] = true;
        $this->system->events[] = array('timestamp' => self::T0 + 60, 'hook' => 'upay_hourly_cron_job', 'args' => array(), 'schedule' => 'hourly');
        $this->system->events[] = array('timestamp' => self::T0 + 120, 'hook' => 'puc_cron_check_updates-upayments', 'args' => array(), 'schedule' => 'daily');
        $this->system->events[] = array('timestamp' => self::T0 + 180, 'hook' => 'other_plugin_cron', 'args' => array(), 'schedule' => 'daily');
    }

    /** Move cut_over_at back by $days, as if the store had been cut over that long ago. */
    private static function age(int $days): void {
        $state = json_decode((string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], true);
        $state['cut_over_at'] = time() - $days * self::DAY;
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode($state);
        TakeoverState::flush_cache();
    }

    private function finalize(): Finalize {
        return new Finalize($this->store, $this->system);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>> kind:name:reason => kept group.
     */
    private static function kept(array $result): array {
        $kept = array();
        foreach ($result['kept'] as $group) {
            $kept[$group['kind'] . ':' . $group['name'] . ':' . $group['reason']] = $group;
        }
        return $kept;
    }

    /** @return list<string> */
    private function hooks(): array {
        return array_column($this->system->events, 'hook');
    }

    public function test_finalize_is_refused_before_30_days_and_changes_nothing(): void {
        $order = $this->seed_cut_over();
        self::age(29);

        $result = $this->finalize()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('too_early', $result['error']);
        self::assertSame(1, $result['report']['days_left']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'));
        self::assertContains('upay_process_subscriptions', $this->hooks());
        self::assertFalse(Quiesce::is_active(self::T0), 'no lease was taken');
    }

    public function test_after_30_days_verified_equal_legacy_names_are_removed_and_unequal_ones_kept_and_reported(): void {
        $order = $this->seed_cut_over();
        // The official plugin (active again for a while) wrote a newer legacy value on order 9.
        $other = new FakeCrudObject(9, 'upayments', array('UPayments_PaymentID' => array('pay-9b'), '_supcheckout_payment_id' => array('pay-9'), 'UPayments_Result' => array('CAPTURED')));
        $GLOBALS['supcheckout_test_status_orders'][9] = $other;
        self::age(31);
        $steps = array();
        $this->system->on_step = static function ($step) use (&$steps) {
            $steps[$step] = Quiesce::is_active(self::T0);
        };

        $result = $this->finalize()->run(false);

        self::assertNull($result['error'], (string) json_encode($result));
        self::assertFalse($result['ok'], 'kept values make the result not ok');
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
        self::assertFalse(TakeoverState::get()['finalize_forced']);
        self::assertTrue($steps['verified'], 'verification runs under the lease');
        self::assertTrue($steps['finalized'], 'the transition happens under the lease');
        self::assertFalse($steps['orders_removed'], 'the removal runs after the lease is released');
        self::assertLessThan(array_search('orders_removed', $result['steps'], true), array_search('finalized', $result['steps'], true), 'transition first, then removal');
        self::assertFalse(Quiesce::is_active(self::T0), 'every lease is released');

        // Verified equal: the legacy names are gone, the new names stay.
        self::assertSame(array(), $order->persisted_values('UPayments_PaymentID'));
        self::assertSame(array(), $order->persisted_values('UPayments_WHS'));
        self::assertSame(array('pay-7'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array('failed', 'completed'), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array(), $order->persisted_values(Keys::TAKEOVER_COPY_MARKER), 'takeover marker removed');
        self::assertSame('pay-7', Keys::read_order_meta($order, 'UPayments_PaymentID'), 'the runtime still reads the value');
        self::assertSame('upayments', $order->get_payment_method(), 'historical payment_method is never rewritten');

        // Unequal: kept and reported (key name and object ID, never a value).
        self::assertSame(array('pay-9b'), $other->persisted_values('UPayments_PaymentID'));
        $kept = self::kept($result);
        self::assertSame(1, $kept['order_meta:UPayments_PaymentID:unequal']['count']);
        self::assertSame(array(9), $kept['order_meta:UPayments_PaymentID:unequal']['ids']);
        self::assertSame(array('CAPTURED'), $other->persisted_values('UPayments_Result'), 'a legacy name with no new copy is kept');
        self::assertSame(1, $kept['order_meta:UPayments_Result:new_missing']['count']);

        // Users (H12 provenance, ledgers), products and options.
        self::assertSame(array(self::NEW_SCOPE_KEY => array('provenance-record'), 'supcheckout_migration_v1' => array('done')), $this->store->user_meta[3]);
        self::assertSame(array(), $this->store->products[40]->persisted_values('_upay_disable_subscription'));
        self::assertSame(array('yes'), $this->store->products[40]->persisted_values('_supcheckout_disable_subscription'));
        self::assertSame(array(), $this->store->products[40]->persisted_values(Keys::TAKEOVER_COPY_MARKER));
        self::assertFalse(get_option('upayments_token_identity_secret_v2', false));
        self::assertSame(hash('sha256', 'secret'), get_option('supcheckout_token_identity_secret_v2'));
        self::assertFalse(get_option('upay_subscription_enrollment_cursor', false));
        self::assertSame(10, get_option('supcheckout_subscription_enrollment_cursor'));
        self::assertSame(array('enabled' => 'yes', 'api_key' => 'test-api-key-123'), get_option('woocommerce_upayments_settings'), 'settings sources are not removed');

        // Cron: the legacy aliases are gone once their new twins exist.
        self::assertSame(array('supcheckout_reconcile_order', 'supcheckout_process_subscriptions'), $this->hooks());
        // Table: equal count and checksums, so the legacy table is dropped.
        self::assertFalse($this->store->table_exists('legacy'));
        self::assertTrue($this->store->table_exists('new'));
        self::assertSame(1, $result['removed']['billing_table']);

        self::assertSame(2, $result['removed']['legacy_cron']);
        self::assertSame(2, $result['removed']['order_meta'], 'UPayments_PaymentID and UPayments_WHS of order 7');
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode(array($result, Journal::entries())));
        self::assertStringNotContainsString('pay-9b', (string) json_encode(array($result, Journal::entries())), 'values are never reported or journaled');
        self::assertStringNotContainsString('aaaaaaaa', (string) json_encode(array($result, Journal::entries())), 'no H12 scope in the report or journal');
        self::assertFalse(Finalize::pending(TakeoverState::get()));
    }

    public function test_a_forced_finalize_before_30_days_completes_and_records_the_force(): void {
        $order = $this->seed_cut_over();

        $result = $this->finalize()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
        self::assertTrue(TakeoverState::get()['finalize_forced']);
        self::assertTrue($result['forced']);
        self::assertSame(array(), $order->persisted_values('UPayments_PaymentID'));
    }

    public function test_an_active_official_plugin_refuses_finalize(): void {
        $order = $this->seed_cut_over();
        self::age(31);
        $this->system->official = 'upayments/UPayments.php';

        $result = $this->finalize()->run(false);

        self::assertSame('official_active', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'));
        self::assertFalse(Quiesce::is_active(self::T0), 'the lease is released');
    }

    public function test_a_held_lease_makes_finalize_busy_and_a_lost_lease_stops_before_the_transition(): void {
        $order = $this->seed_cut_over();
        self::age(31);
        $holder = Quiesce::acquire(self::T0);
        self::assertNotNull($holder);

        self::assertSame('busy', $this->finalize()->run(false)['error']);

        Quiesce::release($holder);
        $this->system->on_step = static function ($step) {
            if ($step === 'verified') {
                // Another holder took the lease after ours expired.
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = (self::T0 + 900) . ':' . str_repeat('f', 32);
            }
        };
        $result = $this->finalize()->run(false);

        self::assertSame('lease_lost', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], 'no transition without the lease');
        self::assertFalse(Finalize::pending(TakeoverState::get()));
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'));
    }

    public function test_a_crash_at_any_step_is_resumed_by_running_finalize_again(): void {
        $steps = array('quiesced', 'verified', 'finalized', 'caches_flushed', 'cron_removed', 'orders_removed', 'users_removed',
            'products_removed', 'options_removed', 'backups_removed', 'official_removed', 'ephemeral_removed', 'legacy_swept', 'settling', 'rescanned', 'table_retired');
        foreach ($steps as $index => $crash_at) {
            $this->setUp();
            $order = $this->seed_cut_over();
            $this->seed_leftovers();
            self::age(31);
            $this->system->on_step = static function ($step) use ($crash_at) {
                if ($step === $crash_at) {
                    throw new \RuntimeException('killed');
                }
            };

            $crashed = $this->finalize()->run(false);

            self::assertSame('error', $crashed['error'], $crash_at);
            $expected = $index < 2 ? TakeoverState::CUT_OVER : TakeoverState::FINALIZED;
            self::assertSame($expected, TakeoverState::get()['status'], $crash_at);
            // Whatever was removed so far, the runtime still reads every value.
            self::assertSame('pay-7', Keys::read_order_meta($order, 'UPayments_PaymentID'), $crash_at);
            self::assertFalse(Quiesce::is_active(self::T0), $crash_at . ': the lease is released');

            $this->system->on_step = null;
            $result = $this->finalize()->run(false);

            self::assertTrue($result['ok'], $crash_at . ': ' . json_encode($result));
            self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status'], $crash_at);
            self::assertFalse(Finalize::pending(TakeoverState::get()), $crash_at);
            self::assertSame(array(), $order->persisted_values('UPayments_PaymentID'), $crash_at);
            self::assertSame(array('pay-7'), $order->persisted_values('_supcheckout_payment_id'), $crash_at);
            self::assertFalse($this->store->table_exists('legacy'), $crash_at);
            self::assertFalse(get_option('simplixpay_upay_order_lock_v1_99', false), $crash_at);
            self::assertNotContains('upay_process_subscriptions', $this->hooks(), $crash_at);
            self::assertContains('supcheckout_process_subscriptions', $this->hooks(), $crash_at);
        }
    }

    public function test_a_save_that_fails_mid_batch_stops_the_removal_and_a_rerun_resumes_it(): void {
        $order = $this->seed_cut_over();
        self::age(31);
        $order->fail_next_save = true;

        $failed = $this->finalize()->run(false);

        self::assertSame('removal_incomplete', $failed['error']);
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
        self::assertTrue(Finalize::pending(TakeoverState::get()));
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'), 'the failed save left the order as it was');

        $result = $this->finalize()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array(), $order->persisted_values('UPayments_PaymentID'));
    }

    public function test_the_removal_settles_before_its_final_rescan_and_holds_no_lease_meanwhile(): void {
        $this->seed_cut_over();
        $at = array();
        $leased = array();
        $system = $this->system;
        $this->system->on_step = static function ($step) use (&$at, &$leased, $system) {
            $at[$step] = $system->now;
            if ($step === 'settling') {
                $leased[] = Quiesce::is_active($system->now);
            }
        };

        $result = $this->finalize()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertGreaterThanOrEqual(CopyRemoval::SETTLE_SECONDS, $at['rescanned'] - $at['finalized'], 'the final rescan starts only after the settle time');
        self::assertSame(CopyRemoval::SETTLE_SECONDS / CopyRemoval::SETTLE_POLL, count(array_keys($result['steps'], 'settling', true)), 'one progress line per poll');
        self::assertSame(array(false), array_values(array_unique($leased)), 'the settle holds no lease (renewals are not paused)');
        $order = array_flip($result['steps']);
        self::assertLessThan($order['legacy_swept'], $order['ephemeral_removed']);
        self::assertLessThan($order['table_retired'], $order['rescanned'], 'the table is retired after a clean rescan');
    }

    public function test_legacy_names_a_cut_over_request_writes_after_the_sweep_are_removed_by_the_rescan(): void {
        $order = $this->seed_cut_over();
        $other = new FakeCrudObject(9, 'upayments', array('UPayments_PaymentID' => array('pay-9b'), '_supcheckout_payment_id' => array('pay-9')));
        $GLOBALS['supcheckout_test_status_orders'][9] = $other;
        $injected = false;
        $this->system->on_step = static function ($step) use ($order, &$injected) {
            if ($step === 'settling' && !$injected) {
                // A request that read the state as cut_over before the switch still writes both names.
                $injected = true;
                $order->external_write('UPayments_TrackID', array('t-1'));
                $order->external_write('_supcheckout_track_id', array('t-1'));
                $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 11;
                $GLOBALS['supcheckout_test_options']['supcheckout_subscription_enrollment_cursor'] = 11;
            }
        };

        $result = $this->finalize()->run(true);

        self::assertTrue($injected);
        self::assertSame(2, count(array_keys($result['steps'], 'rescanned', true)), 'a dirty rescan, then a clean one');
        self::assertSame(2, $result['report']['late_legacy_removed']);
        self::assertSame(array(), $order->persisted_values('UPayments_TrackID'), 'the straggler legacy twin is removed');
        self::assertSame(array('t-1'), $order->persisted_values('_supcheckout_track_id'));
        self::assertFalse(get_option('upay_subscription_enrollment_cursor', false));
        self::assertSame(11, get_option('supcheckout_subscription_enrollment_cursor'));
        self::assertSame(1, self::kept($result)['order_meta:UPayments_PaymentID:unequal']['count'], 'a rescan never double-counts a kept value');
        self::assertFalse(Finalize::pending(TakeoverState::get()));
    }

    public function test_a_writer_that_keeps_writing_legacy_names_leaves_the_removal_unsettled_until_a_rerun(): void {
        $order = $this->seed_cut_over();
        $writes = 0;
        $this->system->on_step = static function ($step) use ($order, &$writes) {
            if ($step === 'legacy_swept' || $step === 'rescanned') {
                ++$writes;
                $order->external_write('UPayments_TrackID', array('t-' . $writes));
                $order->external_write('_supcheckout_track_id', array('t-' . $writes));
            }
        };

        $unsettled = $this->finalize()->run(true);

        self::assertSame('removal_unsettled', $unsettled['error']);
        self::assertSame(CopyRemoval::MAX_RESCANS, count(array_keys($unsettled['steps'], 'rescanned', true)));
        self::assertTrue(Finalize::pending(TakeoverState::get()), 'not recorded complete');
        self::assertSame(0, $this->store->drops, 'the table waits for a clean rescan');

        $this->system->on_step = null;
        $result = $this->finalize()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array(), $order->persisted_values('UPayments_TrackID'));
        self::assertSame(array('t-' . $writes), $order->persisted_values('_supcheckout_track_id'));
        self::assertNotContains('settling', $result['steps'], 'the rerun does not wait again');
        self::assertFalse(Finalize::pending(TakeoverState::get()));
    }

    public function test_an_order_a_payment_request_holds_is_retried_then_left_for_a_rerun(): void {
        $order = $this->seed_cut_over();
        // After finalized the order lock is the new name only (Keys::ephemeral_names()).
        $GLOBALS['supcheckout_test_options']['supcheckout_order_lock_v1_7'] = (time() + 300) . ':' . str_repeat('c', 32);

        $busy = $this->finalize()->run(true);

        self::assertSame('removal_busy', $busy['error']);
        self::assertSame(2, $this->system->sleeps, 'three bounded attempts');
        self::assertTrue(Finalize::pending(TakeoverState::get()));
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'), 'never touched without its lock');

        unset($GLOBALS['supcheckout_test_options']['supcheckout_order_lock_v1_7']);
        $result = $this->finalize()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array(), $order->persisted_values('UPayments_PaymentID'));
    }

    public function test_multi_valued_keys_are_removed_only_when_the_full_value_list_is_equal(): void {
        $this->seed_cut_over();
        $short = new FakeCrudObject(11, 'upayments', array('UPayments_WHS' => array('failed', 'completed'), '_supcheckout_public_status' => array('failed')));
        $reordered = new FakeCrudObject(12, 'upayments', array('UPayments_WHS' => array('failed', 'completed'), '_supcheckout_public_status' => array('completed', 'failed')));
        $equal = new FakeCrudObject(13, 'upayments', array('UPayments_WHS' => array('failed', 'completed'), '_supcheckout_public_status' => array('failed', 'completed')));
        foreach (array($short, $reordered, $equal) as $order) {
            $GLOBALS['supcheckout_test_status_orders'][$order->get_id()] = $order;
        }

        $result = $this->finalize()->run(true);

        self::assertSame(array('failed', 'completed'), $short->persisted_values('UPayments_WHS'));
        self::assertSame(array('failed', 'completed'), $reordered->persisted_values('UPayments_WHS'));
        self::assertSame(array(), $equal->persisted_values('UPayments_WHS'));
        self::assertSame(array(11, 12), self::kept($result)['order_meta:UPayments_WHS:unequal']['ids']);
    }

    public function test_multi_row_user_anomalies_and_unequal_user_rows_are_kept(): void {
        $this->seed_cut_over();
        // Never copied by the copy runner (users_multi_row), so never verified equal.
        $this->store->user_meta[4] = array(self::SCOPE_KEY => array('row-1', 'row-2'), self::NEW_SCOPE_KEY => array('row-1', 'row-2'));
        $this->store->user_meta[6] = array('simplixpay_upayments_migration_v1' => array('legacy'), 'supcheckout_migration_v1' => array('other'));

        $result = $this->finalize()->run(true);

        self::assertSame(array('row-1', 'row-2'), $this->store->user_meta[4][self::SCOPE_KEY]);
        self::assertSame(array('legacy'), $this->store->user_meta[6]['simplixpay_upayments_migration_v1']);
        $kept = self::kept($result);
        self::assertSame(array(4), $kept['user_meta:_upay_customer_token_v2_b:multi_row_anomaly']['ids'], 'reported by prefix, never by scope');
        self::assertSame(array(6), $kept['user_meta:simplixpay_upayments_migration_v1:unequal']['ids']);
    }

    public function test_a_store_with_post_cutover_renewals_drops_its_stale_legacy_table(): void {
        $this->seed_cut_over();
        // While cut over only the new table is written: a held cycle resolved, and new cycles.
        $this->store->tables['legacy'][self::key('1')]['state'] = 'held';
        $this->store->tables['new'][self::key('1')]['state'] = 'resolved';
        $this->store->tables['new'][self::key('2')] = array('cycle_key' => self::key('2'), 'parent_order_id' => 7, 'owner_token' => 'p', 'state' => 'resolved', 'payment_id' => 'pay-r2');
        $this->store->tables['new'][self::key('3')] = array('cycle_key' => self::key('3'), 'parent_order_id' => 7, 'owner_token' => 'q', 'state' => 'claimed', 'payment_id' => null);

        $result = $this->finalize()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertFalse($this->store->table_exists('legacy'), 'a stale subset of the new table is dropped');
        self::assertSame(1, $result['removed']['billing_table']);
        self::assertSame(3, $result['reported']['billing_table_checksums']['differing_rows'], 'the checksum comparison is reported for information only');
        self::assertCount(3, $this->store->tables['new'], 'the table in use is untouched');
    }

    public function test_a_regressive_missing_or_unknown_legacy_row_keeps_the_table(): void {
        foreach (array('regressive' => 'legacy_rows_behind_in_new', 'missing' => 'legacy_rows_missing_in_new', 'unknown' => 'legacy_rows_behind_in_new') as $case => $reason) {
            $this->setUp();
            $this->seed_cut_over();
            $this->store->tables['new'][self::key('2')] = array('cycle_key' => self::key('2'), 'parent_order_id' => 7, 'owner_token' => 'p', 'state' => 'resolved', 'payment_id' => 'pay-r2');
            if ($case === 'regressive') {
                $this->store->tables['new'][self::key('1')]['state'] = 'held';
            } elseif ($case === 'missing') {
                unset($this->store->tables['new'][self::key('1')]);
            } else {
                $this->store->tables['legacy'][self::key('1')]['state'] = 'archived';
            }

            $result = $this->finalize()->run(true);

            self::assertNull($result['error'], $case . ': ' . json_encode($result));
            self::assertTrue($this->store->table_exists('legacy'), $case . ': kept');
            self::assertSame(0, $this->store->drops, $case);
            $kept = self::kept($result);
            self::assertArrayHasKey('billing_table:' . $this->legacy_table() . ':' . $reason, $kept, $case . ': ' . json_encode($result['kept']));
            self::assertSame(1, $kept['billing_table:' . $this->legacy_table() . ':' . $reason]['count'], $case . ': reported by count');
            self::assertStringNotContainsString(self::key('1'), (string) json_encode($result), $case . ': never a cycle key or value');
        }
    }

    public function test_a_legacy_table_with_claims_or_rows_newer_than_the_last_sync_is_kept(): void {
        foreach (array('legacy_claims_open', 'legacy_newer_than_sync') as $case) {
            $this->setUp();
            $this->seed_cut_over();
            $this->store->columns[] = 'updated_gmt';
            $this->store->new_columns[] = 'updated_gmt';
            $row = array('cycle_key' => self::key('5'), 'parent_order_id' => 7, 'owner_token' => 'w', 'payment_id' => null);
            if ($case === 'legacy_claims_open') {
                // A worker pinned to the legacy table before cutover, silent ever since.
                $row += array('state' => 'dispatching', 'updated_gmt' => gmdate('Y-m-d H:i:s', time() - 3 * self::DAY));
            } else {
                $row += array('state' => 'resolved', 'updated_gmt' => gmdate('Y-m-d H:i:s', time() + 60));
            }
            $this->store->add_legacy_row($row);
            $this->store->tables['new'][self::key('5')] = $row;

            $result = $this->finalize()->run(true);

            self::assertNull($result['error'], $case . ': ' . json_encode($result));
            self::assertTrue($this->store->table_exists('legacy'), $case);
            self::assertArrayHasKey('billing_table:' . $this->legacy_table() . ':' . $case, self::kept($result), $case . ': ' . json_encode($result['kept']));
        }
    }

    public function test_a_recent_legacy_claim_is_drained_before_the_table_is_retired(): void {
        $this->seed_cut_over();
        $this->store->columns[] = 'updated_gmt';
        $this->store->new_columns[] = 'updated_gmt';
        $cut_over_at = TakeoverState::get()['cut_over_at'];
        $row = array('cycle_key' => self::key('6'), 'parent_order_id' => 7, 'owner_token' => 'w', 'state' => 'claimed', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', self::T0));
        $this->store->add_legacy_row($row);
        $store = $this->store;
        $draining = false;
        $this->system->on_step = static function ($step) use (&$draining) {
            $draining = $draining || $step === 'rescanned';
        };
        $this->system->on_sleep = static function () use ($store, &$draining) {
            if ($draining) {
                // The pinned worker gives its claim up (CycleClaim::won() releases a claim whose table switched).
                unset($store->tables['legacy'][self::key('6')]);
            }
        };

        $result = $this->finalize()->run(true);

        self::assertSame(1, $result['report']['legacy_claims_in_flight'], 'the drain waited for the pinned claim');
        self::assertFalse($this->store->table_exists('legacy'), (string) json_encode($result));
        self::assertGreaterThan(0, $cut_over_at);
    }

    public function test_official_leftovers_and_legacy_ephemeral_rows_are_removed_by_storage_type(): void {
        $this->seed_cut_over();
        $this->seed_leftovers();
        $translated = get_option('woocommerce_gateway_order');

        $result = $this->finalize()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        foreach (array('your_gateway_version', 'upayments_block_checkout_page_id', 'upay_subscription_settings', 'external_updates-upayments',
            'external_updates-upayments-woocommerce', '_transient_upayments_cron_lock', '_transient_timeout_upayments_cron_lock',
            'simplixpay_upay_order_lock_v1_99', 'simplixpay_upay_status_v1_0123456789abcdef_bucket', 'upayments_payment_methods_rate_gate_live',
            '_transient_upay_pm_v3_0123456789abcdef', '_transient_timeout_upay_pm_v3_0123456789abcdef', '_transient_timeout_upay_pm_v3_fedcba9876543210') as $name) {
            self::assertArrayNotHasKey($name, $GLOBALS['supcheckout_test_options'], $name . ' removed');
        }
        self::assertSame('1800000999:' . str_repeat('b', 32), get_option('supcheckout_order_lock_v1_99'), 'new-name lock kept');
        self::assertSame(array('checked' => 3), get_option('external_updates-other-plugin'), 'another plugin\'s update checker kept');
        self::assertSame('kept', get_option('some_other_plugin_option'));
        self::assertSame($translated, get_option('woocommerce_gateway_order'), 'the translated third-party value is kept');
        self::assertNotContains('upay_hourly_cron_job', $this->hooks());
        self::assertNotContains('puc_cron_check_updates-upayments', $this->hooks());
        self::assertContains('other_plugin_cron', $this->hooks());
        self::assertSame(5, $result['removed']['official_options']);
        self::assertSame(1, $result['removed']['official_transients']);
        self::assertSame(2, $result['removed']['official_cron']);
        self::assertSame(3, $result['removed']['ephemeral_options']);
        self::assertSame(2, $result['removed']['ephemeral_transients']);
        // Reported, never removed.
        self::assertSame(array('page_id' => self::PAGE_ID, 'status' => 'publish'), $result['reported']['block_checkout_page']);
        self::assertSame(array('upay_subscriptions'), $result['reported']['official_tables']);
        self::assertSame(Keys::CALLBACK_ROUTE_LEGACY, $result['reported']['callback_route']);
    }

    public function test_the_takeover_backups_are_removed_but_the_legacy_settings_stay(): void {
        $this->seed_cut_over();
        $backups = array_values(array_filter(array_keys($GLOBALS['supcheckout_test_options']), static function ($name) {
            return strpos((string) $name, ThirdPartyTranslator::BACKUP_PREFIX) === 0;
        }));
        self::assertCount(1, $backups, 'the cutover backed up the original gateway order');
        $GLOBALS['supcheckout_test_options'][ThirdPartyTranslator::BACKUP_PREFIX . 'not-a-fingerprint'] = 'someone else';

        $result = $this->finalize()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertArrayNotHasKey($backups[0], $GLOBALS['supcheckout_test_options'], 'content-addressed backup removed');
        self::assertSame('someone else', get_option(ThirdPartyTranslator::BACKUP_PREFIX . 'not-a-fingerprint'), 'only the exact backup format');
        self::assertSame(1, $result['removed']['takeover_backups']);
        self::assertSame(array('enabled' => 'yes', 'api_key' => 'test-api-key-123'), get_option('woocommerce_upayments_settings'), 'the protected legacy settings option stays');
    }

    public function test_a_legacy_cron_event_without_its_new_twin_is_kept_and_reported(): void {
        $this->seed_cut_over();
        $this->system->events = array_values(array_filter($this->system->events, static function ($event) {
            return $event['hook'] !== 'supcheckout_reconcile_order';
        }));

        $result = $this->finalize()->run(true);

        self::assertContains('simplixpay_upayments_reconcile_order', $this->hooks(), 'kept: nothing scheduled is lost');
        self::assertSame(1, self::kept($result)['cron:simplixpay_upayments_reconcile_order:new_event_missing']['count']);
    }

    public function test_finalize_is_irreversible_and_a_rerun_only_sweeps_again(): void {
        $order = $this->seed_cut_over();
        self::assertTrue($this->finalize()->run(true)['ok']);

        self::assertSame('not_cut_over', (new Rollback($this->store, $this->system))->run(false)['error']);
        self::assertFalse(TakeoverState::transition(TakeoverState::ROLLED_BACK));
        self::assertFalse(TakeoverState::transition(TakeoverState::CUT_OVER));
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);

        // A straggler wrote a legacy twin after the sweep: a rerun removes it when equal.
        $order->external_write('UPayments_TrackID', array('t-1'));
        $order->external_write('_supcheckout_track_id', array('t-1'));
        $again = $this->finalize()->run(false);

        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertSame(array(), $order->persisted_values('UPayments_TrackID'));
        self::assertSame(TakeoverState::FINALIZED, TakeoverState::get()['status']);
    }

    public function test_finalize_requires_a_cut_over_store(): void {
        foreach (array('idle', 'preflight_ok', 'copying', 'verified', 'rolled_back') as $status) {
            CutoverTest::state($status);
            $result = $this->finalize()->run(true);
            self::assertSame('not_cut_over', $result['error'], $status);
            self::assertSame($status, TakeoverState::get()['status'], $status);
        }
    }

    public function test_a_disagreeing_token_secret_or_unreadable_journal_refuses_before_the_transition(): void {
        $this->seed_cut_over();
        $GLOBALS['supcheckout_test_options']['supcheckout_token_identity_secret_v2'] = hash('sha256', 'other');
        self::assertSame('options_mismatch', $this->finalize()->run(true)['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);

        $GLOBALS['supcheckout_test_options']['supcheckout_token_identity_secret_v2'] = hash('sha256', 'secret');
        $GLOBALS['supcheckout_test_options'][Journal::OPTION] = '[{}]';
        Journal::flush_cache();
        self::assertSame('journal_unreadable', $this->finalize()->run(true)['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_the_dry_run_writes_nothing_and_predicts_the_removal(): void {
        $order = $this->seed_cut_over();
        $this->seed_leftovers();
        $other = new FakeCrudObject(9, 'upayments', array('UPayments_PaymentID' => array('pay-9b'), '_supcheckout_payment_id' => array('pay-9')));
        $GLOBALS['supcheckout_test_status_orders'][9] = $other;
        self::age(10);
        $options = $GLOBALS['supcheckout_test_options'];
        $events = $this->system->events;
        $tables = $this->store->tables;
        $users = $this->store->user_meta;
        $GLOBALS['supcheckout_test_option_calls'] = array();
        $entries = count(Journal::entries());
        $objects = array($order, $other, $this->store->products[40]);
        $saves = array_map(static function ($object) {
            return $object->saves;
        }, $objects);
        foreach ($objects as $object) {
            $object->writes = array();
        }

        $plan = $this->finalize()->plan();

        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls'], 'no option write');
        self::assertSame($options, $GLOBALS['supcheckout_test_options'], 'options unchanged (no lease, lock, journal or state write)');
        self::assertSame($events, $this->system->events);
        self::assertSame($tables, $this->store->tables);
        self::assertSame($users, $this->store->user_meta);
        self::assertSame(0, $this->store->drops);
        foreach ($objects as $index => $object) {
            self::assertSame(array(), $object->writes, 'no meta mutation');
            self::assertSame($saves[$index], $object->saves, 'no save');
        }
        self::assertSame($entries, count(Journal::entries()));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);

        self::assertSame(array('too_early'), $plan['would_refuse']);
        self::assertSame(20, $plan['days_left']);
        self::assertSame('drop', $plan['table']);
        self::assertSame(array(9), self::kept($plan)['order_meta:UPayments_PaymentID:unequal']['ids']);
        self::assertSame(array('page_id' => self::PAGE_ID, 'status' => 'publish'), $plan['reported']['block_checkout_page']);

        $result = $this->finalize()->run(true);
        foreach ($plan['removed'] as $what => $count) {
            self::assertSame($count, $result['removed'][$what], $what . ': the dry run predicted the removal');
        }
        self::assertSame(array_keys($plan['removed']), array_keys($result['removed']));
    }

    public function test_finalize_never_touches_hooks_pages_or_other_plugins_by_construction(): void {
        $forbidden = array('remove_action', 'remove_filter', 'remove_all_actions', 'remove_all_filters', 'wp_delete_post', 'wp_trash_post',
            'deactivate_plugins', 'activate_plugin', 'delete_plugins', 'wp_clear_scheduled_hook', 'wp_unschedule_hook');
        $root = dirname(__DIR__, 3);
        foreach (array('src/Takeover/Finalize.php', 'src/Takeover/FinalizeRemoval.php') as $file) {
            self::assertFileExists($root . '/' . $file);
            $tokens = token_get_all((string) file_get_contents($root . '/' . $file));
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_STRING) {
                    self::assertNotContains(strtolower($token[1]), $forbidden, $file . ':' . $token[2]);
                }
            }
        }
    }

    private function legacy_table(): string {
        return (string) array_keys(Keys::tables())[0];
    }
}
