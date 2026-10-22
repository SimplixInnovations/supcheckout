<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Cutover;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 7: the quiesced cutover on the real TakeoverState, Journal, Quiesce, OrderLock
 * and copiers, with an in-memory CopyStore and CutoverSystem.
 */
final class CutoverTest extends TestCase {
    /** @var FakeCopyStore */
    private $store;
    /** @var FakeCutoverSystem */
    private $system;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        Journal::flush_cache();
        self::state('verified');
        $this->store = new FakeCopyStore();
        $this->system = new FakeCutoverSystem();
        // The official plugin is deactivated before Cutover runs (CliCommands, 0c); a run
        // with it active is refused (official_active tests below).
        $this->system->official = null;
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
        Journal::flush_cache();
    }

    private static function secret(): string {
        return hash('sha256', 'test-api-key-123|identity');
    }

    private function seed_store(): FakeCrudObject {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = self::secret();
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        $order = new FakeCrudObject(7, 'upayments', array('UPayments_PaymentID' => array('pay-7'), 'UPayments_WHS' => array('failed', 'completed')));
        $GLOBALS['supcheckout_test_status_orders'][7] = $order;
        $this->store->user_meta[3] = array('_upay_customer_token_v2_b1_' . str_repeat('a', 32) => array('provenance-record'));
        $this->store->new_table_exists = true;
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('1', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'resolved', 'payment_id' => 'pay-r'));
        $this->system->events[] = array('timestamp' => 1800000600, 'hook' => 'simplixpay_upayments_reconcile_order', 'args' => array(7), 'schedule' => false);
        $this->system->events[] = array('timestamp' => 1800003600, 'hook' => 'upay_process_subscriptions', 'args' => array(), 'schedule' => 'hourly');
        return $order;
    }

    public function test_a_verified_store_cuts_over_under_quiesce_and_keeps_every_legacy_value(): void {
        $order = $this->seed_store();
        $quiesced_during = array();
        $this->system->on_step = static function ($step) use (&$quiesced_during) {
            $quiesced_during[$step] = Quiesce::is_active(1800000000);
        };

        $result = $this->cutover()->run(true);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(array('quiesced', 'drained', 'verified_objects', 'table_synced', 'settings_copied', 'cron_rescheduled', 'third_party_translated', 'rechecked', 'cut_over', 'caches_flushed'), $result['steps'], 'no post-switch deactivation: the plugin was inactive before the switch');
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertTrue(TakeoverState::is_cut_over());
        foreach (array('drained', 'table_synced', 'settings_copied', 'third_party_translated', 'rechecked') as $step) {
            self::assertTrue($quiesced_during[$step], "quiesced during {$step}");
        }
        self::assertFalse(Quiesce::is_active(1800000000), 'the lease is released');

        self::assertSame('upayments', $order->get_payment_method(), 'historical payment_method is never rewritten');
        self::assertSame(array('pay-7'), $order->persisted_values('UPayments_PaymentID'), 'legacy meta kept');
        self::assertSame(array('pay-7'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array('failed', 'completed'), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array('provenance-record'), $this->store->user_meta[3]['_supcheckout_customer_token_v2_b1_' . str_repeat('a', 32)]);
        self::assertSame($this->store->tables['legacy'], $this->store->tables['new']);
        self::assertSame(get_option('woocommerce_upayments_settings'), get_option(Keys::SETTINGS_OPTION));
        self::assertSame(self::secret(), get_option('supcheckout_token_identity_secret_v2'));
        self::assertSame(array('bacs' => 0, 'upayments' => 1, 'supcheckout' => 1), get_option('woocommerce_gateway_order'));

        $twins = array();
        foreach ($this->system->events as $event) {
            $twins[] = $event['hook'] . '@' . $event['timestamp'] . ':' . json_encode($event['args']) . ':' . json_encode($event['schedule']);
        }
        self::assertSame(array(
            'simplixpay_upayments_reconcile_order@1800000600:[7]:false',
            'upay_process_subscriptions@1800003600:[]:"hourly"',
            'supcheckout_reconcile_order@1800000600:[7]:false',
            'supcheckout_process_subscriptions@1800003600:[]:"hourly"',
        ), $twins, 'legacy events kept, twins scheduled under the new names');
        self::assertSame(array(), $this->system->deactivated);

        $targets = array_count_values(array_column(Journal::entries(), 'target'));
        self::assertSame(array('option' => 3, 'cron' => 2), $targets);
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode(Journal::entries()));
    }

    public function test_any_verify_mismatch_refuses_the_cutover_and_translates_nothing(): void {
        $this->seed_store();
        // A foreign value under a new H12 name: never overwritten, so it cannot verify.
        $this->store->user_meta[3]['_supcheckout_customer_token_v2_b1_' . str_repeat('a', 32)] = array('foreign');

        $result = $this->cutover()->run(true);

        self::assertFalse($result['ok']);
        self::assertSame('verify_mismatch', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status'], 'no transition');
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false), 'settings not copied');
        self::assertSame(array('bacs' => 0, 'upayments' => 1), get_option('woocommerce_gateway_order'), 'nothing translated');
        self::assertSame(array(), Journal::entries());
        self::assertCount(2, $this->system->events, 'no cron twin');
        self::assertSame(array(), $this->system->deactivated);
        self::assertFalse(Quiesce::is_active(1800000000), 'the lease is released on failure too');
        self::assertSame(1, $result['report']['mismatches']['users']);
    }

    public function test_a_table_that_does_not_resync_refuses_the_cutover(): void {
        $this->seed_store();
        $this->store->tables['new'][str_repeat('1', 64)] = array('cycle_key' => str_repeat('1', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'claimed', 'payment_id' => null);
        $this->store->fail_replace = true;

        $result = $this->cutover()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('table_mismatch', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
    }

    public function test_new_table_rows_without_a_legacy_table_refuse_the_cutover(): void {
        $this->seed_store();
        $this->store->legacy_table_exists = false;
        $this->store->tables['legacy'] = array();
        $this->store->tables['new'][str_repeat('2', 64)] = array('cycle_key' => str_repeat('2', 64), 'state' => 'claimed');

        $result = $this->cutover()->run(false);

        self::assertSame('table_mismatch', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    public function test_an_in_flight_claim_that_never_drains_refuses_the_cutover(): void {
        $this->seed_store();
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('3', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'dispatching', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', 1800000000)));

        $result = $this->cutover()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('claims_in_flight', $result['error']);
        self::assertGreaterThan(0, $this->system->sleeps, 'it waited before giving up');
        self::assertLessThanOrEqual(Cutover::DRAIN_TIMEOUT + Cutover::DRAIN_POLL, $this->system->now - 1800000000, 'the wait is bounded');
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(Quiesce::is_active($this->system->now));
    }

    public function test_a_claim_that_finishes_while_draining_lets_the_cutover_proceed(): void {
        $this->seed_store();
        $key = str_repeat('4', 64);
        $this->store->add_legacy_row(array('cycle_key' => $key, 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'dispatching', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', 1800000000)));
        $store = $this->store;
        $this->system->on_sleep = static function () use ($store, $key) {
            $store->tables['legacy'][$key]['state'] = 'resolved';
        };

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('resolved', $this->store->tables['new'][$key]['state'], 'the drained row is synced in its final state');
    }

    public function test_a_stale_dispatching_row_from_a_dead_worker_does_not_block(): void {
        $this->seed_store();
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('5', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'dispatching', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', 1800000000 - 3600)));
        self::assertTrue($this->cutover()->run(false)['ok']);
        self::assertSame('dispatching', $this->store->tables['new'][str_repeat('5', 64)]['state'], 'copied as is: never auto-expired, so it stays held');
    }

    public function test_a_stale_claimed_row_refuses_the_cutover_because_its_worker_may_still_dispatch(): void {
        $this->seed_store();
        // A worker claimed on the legacy table and stalled: after a cutover it could still
        // mark its legacy row dispatching while another worker reclaims the copied row.
        $this->store->add_legacy_row(array('cycle_key' => str_repeat('6', 64), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'claimed', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', 1800000000 - 3600)));

        $result = $this->cutover()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('stale_claims', $result['error']);
        self::assertSame(1, $result['report']['stale_claims']);
        self::assertSame(0, $this->system->sleeps, 'no wait: the quiesce pauses the reclaim that would clear it');
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
        self::assertFalse(Quiesce::is_active($this->system->now));
    }

    public function test_only_a_verified_store_cuts_over_and_a_busy_lease_refuses(): void {
        self::state('copying');
        self::assertSame('not_verified', $this->cutover()->run(false)['error']);
        self::assertFalse(Quiesce::is_active(1800000000), 'no lease was taken');

        self::state('verified');
        $this->seed_store();
        Quiesce::acquire(1800000000);
        self::assertSame('busy', $this->cutover()->run(false)['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    public function test_a_crash_mid_cutover_leaves_a_recoverable_state_and_a_rerun_completes_once(): void {
        $this->seed_store();
        $crashed = false;
        $this->system->on_schedule = static function () use (&$crashed) {
            if (!$crashed) {
                $crashed = true;
                throw new \RuntimeException('process killed');
            }
        };

        $first = $this->cutover()->run(false);
        self::assertFalse($first['ok']);
        self::assertSame('error', $first['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status'], 'still verified: reads stay legacy-only');
        self::assertFalse(Quiesce::is_active($this->system->now), 'released in finally');
        self::assertSame(get_option('woocommerce_upayments_settings'), get_option(Keys::SETTINGS_OPTION), 'the copy made before the crash stays (harmless before cutover)');

        $second = $this->cutover()->run(false);
        self::assertTrue($second['ok'], json_encode($second));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        $paths = array_column(Journal::entries(), 'path');
        self::assertSame(1, array_count_values($paths)[Keys::SETTINGS_OPTION], 'settings journaled once: the rerun skips the identical copy');
        $hooks = array_column($this->system->events, 'hook');
        self::assertSame(1, array_count_values($hooks)['supcheckout_reconcile_order'], 'one twin');
    }

    public function test_a_lost_lease_before_the_transition_refuses_the_cutover(): void {
        $this->seed_store();
        $this->system->on_step = static function ($step) {
            if ($step === 'third_party_translated') {
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = '0:released';
            }
        };
        $result = $this->cutover()->run(false);
        self::assertSame('lease_lost', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    public function test_a_slow_final_verify_keeps_the_lease_renewed_on_the_wall_clock(): void {
        $this->seed_store();
        for ($id = 100; $id < 112; $id++) {
            $GLOBALS['supcheckout_test_status_orders'][$id] = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $system = $this->system;
        // Each order load takes 100 s: far fewer than RENEW_EVERY objects outlast the TTL.
        $this->store->on_load_order = static function () use ($system) {
            $system->now += 100;
        };

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertGreaterThan(Quiesce::TTL, $this->system->now - 1800000000);
    }

    public function test_slow_product_and_table_batches_and_slow_switch_steps_keep_the_lease(): void {
        $this->seed_store();
        $system = $this->system;
        for ($id = 500; $id < 512; $id++) {
            $this->store->products[$id] = new FakeCrudObject($id, '', array('_upay_disable_subscription' => array('yes')));
        }
        for ($i = 0; $i < 1200; $i++) {
            $this->store->add_legacy_row(array('cycle_key' => sprintf('%064d', $i + 10), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'resolved', 'payment_id' => 'p'));
        }
        $this->store->on_load_product = static function () use ($system) {
            $system->now += 100;
        };
        $this->store->on_table_insert = static function () use ($system) {
            $system->now += 400;
        };
        $this->system->on_step = static function ($step) use ($system) {
            if (in_array($step, array('settings_copied', 'cron_rescheduled', 'third_party_translated'), true)) {
                $system->now += 500;
            }
        };

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_a_lease_lost_inside_the_final_verify_stops_at_once_and_reports_lease_lost(): void {
        $this->seed_store();
        for ($id = 100; $id < 160; $id++) {
            $GLOBALS['supcheckout_test_status_orders'][$id] = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $system = $this->system;
        $loaded = array();
        $this->store->on_load_order = static function ($order_id) use ($system, &$loaded) {
            $loaded[$order_id] = true;
            if ($order_id === 110) {
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = '0:released';
                $system->now += 61;
            }
        };

        $result = $this->cutover()->run(false);

        self::assertSame('lease_lost', $result['error']);
        self::assertArrayNotHasKey(112, $loaded, 'no further work once the lease is gone');
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
    }

    public function test_a_lease_lost_after_the_last_verify_refuses_the_transition(): void {
        $this->seed_store();
        $this->system->on_step = static function ($step) {
            if ($step === 'rechecked') {
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = '0:released';
            }
        };

        $result = $this->cutover()->run(false);

        self::assertSame('lease_lost', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    public function test_a_status_that_changes_before_the_lease_is_taken_stops_the_cutover(): void {
        $this->seed_store();
        $this->system->on_now = static function () {
            // Another operator's cutover completes between the status read and the lease.
            CutoverTest::state('cut_over', false);
        };
        $steps = array();
        $this->system->on_step = static function ($step) use (&$steps) {
            $steps[] = $step;
        };

        $result = $this->cutover()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('not_verified', $result['error']);
        self::assertSame(array(), $steps, 'nothing ran under the lease');
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false), 'no writes');
        self::assertSame(array(), Journal::entries());
        self::assertSame(array(), $this->store->tables['new'], 'the table was not synced');
        self::assertFalse(Quiesce::is_active($this->system->now), 'the lease is released');
    }

    public function test_an_active_official_plugin_refuses_the_switch_and_leaves_the_store_verified(): void {
        $this->seed_store();
        $this->system->official = 'upayments/UPayments.php';

        $result = $this->cutover()->run(true);

        self::assertFalse($result['ok']);
        self::assertSame('official_active', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertFalse(TakeoverState::is_cut_over());
        self::assertNotContains('cut_over', $result['steps']);
        self::assertSame(array(), $this->system->deactivated, 'Cutover never deactivates on a fresh switch');
        self::assertFalse(Quiesce::is_active($this->system->now), 'the lease is released');
    }

    public function test_an_official_plugin_reactivated_after_the_recheck_still_refuses_right_before_the_swap(): void {
        $this->seed_store();
        $system = $this->system;
        $this->system->on_step = static function ($step) use ($system) {
            if ($step === 'rechecked') {
                $system->official = 'upayments/UPayments.php';
            }
        };

        $result = $this->cutover()->run(true);

        self::assertSame('official_active', $result['error']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertNotContains('cut_over', $result['steps']);
    }

    public function test_a_store_already_cut_over_only_finishes_the_tail(): void {
        self::state('cut_over');
        $this->system->official = 'upayments/UPayments.php';
        $result = $this->cutover()->run(true);
        self::assertTrue($result['ok']);
        self::assertSame(array('already_cut_over', 'official_deactivated', 'caches_flushed'), $result['steps'], 'a cut-over store whose purge was never recorded gets it once');
        self::assertFalse(Quiesce::is_active(1800000000));
    }

    public function test_a_cut_over_store_whose_official_plugin_stays_active_is_a_warning_not_a_plain_success(): void {
        self::state('cut_over');
        $this->system->official = 'upayments/UPayments.php';
        $this->system->deactivate_fails = true;

        $result = $this->cutover()->run(true);

        self::assertTrue($result['ok'], 'the store stays cut over');
        self::assertSame(array('official_still_active'), $result['warnings']);
        self::assertSame(array('official_still_active'), $result['report']['warnings']);
        self::assertSame(array(), $this->cutover()->run(false)['warnings'], 'a clean run has no warnings');
    }

    public function test_the_final_verify_converges_a_stale_new_row_because_legacy_is_authoritative_before_cutover(): void {
        $order = $this->seed_store();
        // An unlocked writer left an extra new-name row during `verified`.
        $order->external_write('_supcheckout_payment_id', array('pay-7', 'pay-stale'));

        self::assertTrue($this->cutover()->run(false)['ok']);
        self::assertSame(array('pay-7'), $order->persisted_values('_supcheckout_payment_id'));
    }

    public function test_cutover_purges_caches_once_after_the_transition(): void {
        $this->seed_store();

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(1, $this->system->purges);
        self::assertSame(array('cut_over', 'purge', 'caches_flushed'), array_slice($this->system->log, -3));
        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $result['report']['cache_purge']);
    }

    public function test_a_throwing_purge_never_fails_a_completed_cutover(): void {
        $this->seed_store();
        $this->system->purge_throws = true;

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertNull($result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(array('ran' => array(), 'manual' => array(), 'failed' => array('system')), $result['report']['cache_purge']);
        self::assertSame('caches_flushed', end($result['steps']));
        self::assertStringNotContainsString('boom', (string) json_encode($result));
    }

    public function test_a_worker_killed_after_the_transition_purges_once_on_the_rerun_and_never_again(): void {
        $this->seed_store();
        $this->system->on_step = static function ($step) {
            if ($step === 'cut_over') {
                throw new \RuntimeException('killed between the transition and the purge');
            }
        };
        $crashed = $this->cutover()->run(false);
        self::assertSame('error', $crashed['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(0, $this->system->purges, 'killed before the purge');
        $this->system->on_step = null;

        $rerun = $this->cutover()->run(false);

        self::assertTrue($rerun['ok'], (string) json_encode($rerun));
        self::assertSame(1, $this->system->purges);
        self::assertSame(array('already_cut_over', 'caches_flushed'), $rerun['steps']);
        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $rerun['report']['cache_purge']);

        $again = $this->cutover()->run(false);

        self::assertSame(1, $this->system->purges, 'the recorded purge is not repeated');
        self::assertSame(array('already_cut_over'), $again['steps']);
        self::assertArrayNotHasKey('cache_purge', $again['report']);
    }

    public function test_a_purge_failure_on_the_rerun_never_fails_the_cutover_and_is_still_recorded(): void {
        self::state('cut_over');
        $this->system->purge_throws = true;

        $result = $this->cutover()->run(false);

        self::assertTrue($result['ok']);
        self::assertSame(array('system'), $result['report']['cache_purge']['failed']);
        $this->cutover()->run(false);
        self::assertSame(1, $this->system->purges);
    }

    public function test_a_refused_cutover_never_purges(): void {
        $this->seed_store();
        $this->system->official = 'upayments/UPayments.php';

        $result = $this->cutover()->run(false);

        self::assertSame('official_active', $result['error']);
        self::assertSame(0, $this->system->purges);
        self::assertArrayNotHasKey('cache_purge', $result['report']);
    }

    /** A5-02: a verified store of $count orders and nothing else (no user, product or table row). */
    private function seed_orders(int $count): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = self::secret();
        $this->store->new_table_exists = true;
        for ($id = 1; $id <= $count; $id++) {
            $GLOBALS['supcheckout_test_status_orders'][$id] = new FakeCrudObject($id, 'upayments', array('UPayments_PaymentID' => array('pay-' . $id)));
        }
    }

    /**
     * Flushes counted while the final verify ran (between `drained` and `verified_objects`).
     *
     * @return array{0:array<string, mixed>, 1:int}
     */
    private function run_counting_verify_flushes(): array {
        $at = array();
        $this->system->on_step = function ($step) use (&$at) {
            $at[$step] = $this->store->runtime_flushes;
        };
        $result = $this->cutover()->run(false);
        self::assertArrayHasKey('verified_objects', $at, (string) json_encode($result));
        return array($result, $at['verified_objects'] - $at['drained']);
    }

    public function test_a5_02_the_final_verify_flushes_the_runtime_cache_once_per_order_batch(): void {
        // 120 orders walk in three batches of at most CopyRunner::ORDER_BATCH (50, 50, 20).
        $this->seed_orders(120);

        list($result, $flushes) = $this->run_counting_verify_flushes();

        self::assertSame(3, $flushes, 'one runtime-cache flush after each order batch, so the walk never holds every order in memory');
        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], 'the same cutover as without the flush');
        self::assertSame(array('pay-120'), $GLOBALS['supcheckout_test_status_orders'][120]->persisted_values('_supcheckout_payment_id'), 'every order is still copied and verified');
    }

    public function test_a5_02_an_empty_closing_selection_is_not_flushed(): void {
        // Exactly two full batches: the closing empty selection holds nothing to drop.
        $this->seed_orders(100);

        list($result, $flushes) = $this->run_counting_verify_flushes();

        self::assertSame(2, $flushes);
        self::assertTrue($result['ok'], (string) json_encode($result));
    }

    private function cutover(): Cutover {
        return new Cutover($this->store, $this->system);
    }

    /** @param bool $flush Flush this process's cache (false: another process wrote it). */
    public static function state(string $status, bool $flush = true): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => $status === 'cut_over' ? 1 : 0, 'cursor' => 0, 'totals' => array()));
        if ($flush) {
            TakeoverState::flush_cache();
        }
    }
}
