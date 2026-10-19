<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\BudgetedSystem;
use Simplixi\SUPCheckout\Takeover\CopyRemoval;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\CronRescheduler;
use Simplixi\SUPCheckout\Takeover\Cutover;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\SettingsCopier;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator;
use Simplixi\SUPCheckout\Takeover\WordPressCutoverSystem;

/**
 * 0b Task 10: journal-driven rollback (cut_over → rolled_back → preflight_ok) and abort
 * (verified → preflight_ok), on the real TakeoverState, Journal, Quiesce, OrderLock and
 * Cutover, with an in-memory store and system. Review Focus 5: orders paid through
 * SUPCheckout during the window stay paid and keep their payment IDs.
 */
final class RollbackTest extends TestCase {
    private const T0 = 1800000000;
    private const SCOPE_KEY = '_upay_customer_token_v2_b1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW_SCOPE_KEY = '_supcheckout_customer_token_v2_b1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const LATE_SCOPE_KEY = '_supcheckout_customer_token_v2_b1_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const LATE_LEGACY_SCOPE_KEY = '_upay_customer_token_v2_b1_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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

    /** A verified store as the copy runner leaves it, official plugin already deactivated by `run`. */
    private function seed_verified(): FakeCrudObject {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('enabled' => 'yes', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2'] = hash('sha256', 'secret');
        $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 10;
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0, 'upayments' => 1);
        $GLOBALS['supcheckout_test_options']['woocommerce_default_gateway'] = 'upayments';
        $order = new FakeCrudObject(7, 'upayments', array('UPayments_PaymentID' => array('pay-7'), 'UPayments_WHS' => array('failed', 'completed')), 'completed');
        $GLOBALS['supcheckout_test_status_orders'][7] = $order;
        $this->store->user_meta[3] = array(self::SCOPE_KEY => array('provenance-record'));
        $this->store->products[40] = new FakeCrudObject(40, '', array('_upay_disable_subscription' => array('yes')));
        $this->store->new_table_exists = true;
        $this->store->add_legacy_row(array('cycle_key' => self::key('1'), 'parent_order_id' => 7, 'owner_token' => 'o', 'state' => 'dispatching', 'payment_id' => 'pay-r'));
        $this->system->events[] = array('timestamp' => self::T0 + 600, 'hook' => 'simplixpay_upayments_reconcile_order', 'args' => array(7), 'schedule' => false);
        $this->system->events[] = array('timestamp' => self::T0 + 3600, 'hook' => 'upay_process_subscriptions', 'args' => array(), 'schedule' => 'hourly');
        $this->system->official = null;
        // `run --deactivate-official` records which install it deactivated.
        self::assertTrue(TakeoverState::patch(array('official_deactivated' => 'upayments/UPayments.php')));
        return $order;
    }

    /** Cut over through the real Cutover, then 30 days of SUPCheckout traffic. */
    private function seed_cut_over(): FakeCrudObject {
        $this->seed_verified();
        $cutover = (new Cutover($this->store, $this->system))->run(false);
        self::assertTrue($cutover['ok'], (string) json_encode($cutover));
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);

        // An order created through SUPCheckout after cutover: new names only (Review Focus 5).
        $paid = new FakeCrudObject(8, 'supcheckout', array('_supcheckout_payment_id' => array('pay-8'), '_supcheckout_result' => array('CAPTURED')), 'processing');
        $GLOBALS['supcheckout_test_status_orders'][8] = $paid;
        // A customer bootstrapped H12 provenance after cutover, and the cursor moved.
        $this->store->user_meta[5] = array(self::LATE_SCOPE_KEY => array('late-provenance'));
        $GLOBALS['supcheckout_test_options']['supcheckout_subscription_enrollment_cursor'] = 25;
        $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 25;
        // Billing attempts written after cutover land in the new table only.
        $this->store->tables['new'][self::key('1')]['state'] = 'resolved';
        $this->store->tables['new'][self::key('2')] = array('cycle_key' => self::key('2'), 'parent_order_id' => 8, 'owner_token' => 'p', 'state' => 'resolved', 'payment_id' => 'pay-8r');
        return $paid;
    }

    private function rollback(): Rollback {
        return new Rollback($this->store, $this->system);
    }

    public function test_review_focus_5_a_rollback_restores_identities_and_keeps_every_payment(): void {
        $paid = $this->seed_cut_over();
        $pre_switch = $GLOBALS['supcheckout_test_status_orders'][7];
        $quiesced = array();
        $this->system->on_step = static function ($step) use (&$quiesced) {
            $quiesced[$step] = Quiesce::is_active(self::T0);
        };

        $result = $this->rollback()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array(), $result['conflicts']);
        self::assertNull($result['error']);
        foreach (array('drained', 'orders_synced', 'options_synced', 'journal_replayed', 'table_synced', 'rechecked') as $step) {
            self::assertTrue($quiesced[$step], "quiesced during {$step}");
        }
        self::assertFalse($quiesced['copies_removed'], 'copies are removed after the quiesce is released');
        self::assertLessThan(array_search('copies_removed', $result['steps'], true), array_search('rolled_back', $result['steps'], true), 'transition first, then removal');
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], 'rolled_back → preflight_ok once the copies are gone');
        self::assertGreaterThan(0, TakeoverState::get()['rolled_back_at']);
        self::assertFalse(Quiesce::is_active(self::T0));

        // The post-cutover order: upayments again, still processing, payment ID under the legacy key.
        self::assertSame('upayments', $paid->get_payment_method());
        self::assertSame('processing', $paid->get_status());
        self::assertSame(array('pay-8'), $paid->persisted_values('UPayments_PaymentID'));
        self::assertSame(array('CAPTURED'), $paid->persisted_values('UPayments_Result'));
        self::assertSame('pay-8', Keys::read_order_meta($paid, 'UPayments_PaymentID'));
        self::assertSame(array(), $paid->persisted_values('_supcheckout_payment_id'), 'new-name copies removed');
        self::assertNotContains('status', $paid->field_writes, 'payment state is never touched');
        $payment_entries = array_values(array_filter(Journal::entries(), static function ($entry) {
            return $entry['target'] === 'order' && $entry['path'] === '8/payment_method';
        }));
        self::assertCount(1, $payment_entries, 'the payment_method rewrite is journaled once');

        // The pre-switch order was never rewritten and keeps every legacy value.
        self::assertSame('upayments', $pre_switch->get_payment_method());
        self::assertNotContains('payment_method', $pre_switch->field_writes);
        self::assertSame(array('failed', 'completed'), $pre_switch->persisted_values('UPayments_WHS'));
        self::assertSame(array(), $pre_switch->persisted_values('_supcheckout_public_status'));
        self::assertSame(array(), $pre_switch->persisted_values(Keys::TAKEOVER_COPY_MARKER), 'takeover marker removed');
        self::assertSame(array(), $this->store->products[40]->persisted_values('_supcheckout_disable_subscription'));
        self::assertSame(array(), $this->store->products[40]->persisted_values(Keys::TAKEOVER_COPY_MARKER));
        self::assertSame(array('yes'), $this->store->products[40]->persisted_values('_upay_disable_subscription'));

        // H12: the pre-switch record is intact, the post-cutover one is back-synced; new names gone.
        self::assertSame(array(self::SCOPE_KEY => array('provenance-record')), $this->store->user_meta[3]);
        self::assertSame(array(self::LATE_LEGACY_SCOPE_KEY => array('late-provenance')), $this->store->user_meta[5]);

        // Options: legacy back-synced and authoritative again; new names removed.
        self::assertSame(25, get_option('upay_subscription_enrollment_cursor'));
        self::assertSame(hash('sha256', 'secret'), get_option('upayments_token_identity_secret_v2'));
        foreach (array(Keys::SETTINGS_OPTION, 'supcheckout_token_identity_secret_v2', 'supcheckout_subscription_enrollment_cursor') as $copy) {
            self::assertFalse(get_option($copy, false), $copy . ' removed');
        }
        self::assertSame(array('enabled' => 'yes', 'api_key' => 'test-api-key-123'), get_option('woocommerce_upayments_settings'));
        self::assertSame(array('bacs' => 0, 'upayments' => 1), get_option('woocommerce_gateway_order'));
        self::assertSame('upayments', get_option('woocommerce_default_gateway'));

        // Table: the post-cutover insert and the post-cutover status change both reach legacy.
        self::assertSame('resolved', $this->store->tables['legacy'][self::key('1')]['state']);
        self::assertSame('pay-8r', $this->store->tables['legacy'][self::key('2')]['payment_id']);
        self::assertSame(array(), $this->store->tables['new'], 'new-table copies removed');

        // Cron: twins gone, legacy events kept.
        self::assertSame(array('simplixpay_upayments_reconcile_order', 'upay_process_subscriptions'), array_column($this->system->events, 'hook'));
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated, 'the official plugin is reactivated (consented)');
        self::assertContains('official_reactivated', $result['steps']);
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode(Journal::entries()));
        self::assertStringNotContainsString('aaaaaaaa', (string) json_encode(Journal::entries()), 'no H12 scope in the journal');
    }

    public function test_rollback_purges_at_the_transition_and_again_after_reactivation(): void {
        $this->seed_cut_over();
        $before = $this->system->purges;

        $result = $this->rollback()->run(true);

        self::assertSame(2, $this->system->purges - $before);
        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $result['report']['cache_purge']);
        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $result['report']['cache_purge_after_reactivation']);
        $log = $this->system->log;
        $transition = array_search('rolled_back', $log, true);
        self::assertSame(array('rolled_back', 'purge', 'caches_flushed'), array_slice($log, $transition, 3));
        self::assertSame(array('official_reactivated', 'purge'), array_slice($log, -2));
    }

    public function test_rollback_without_reactivation_purges_once(): void {
        $this->seed_cut_over();
        $before = $this->system->purges;

        $result = $this->rollback()->run(false);

        self::assertSame(1, $this->system->purges - $before);
        self::assertArrayNotHasKey('cache_purge_after_reactivation', $result['report']);
    }

    public function test_a_throwing_purge_never_fails_a_rollback_that_already_happened(): void {
        $this->seed_cut_over();
        $this->system->purge_throws = true;

        $result = $this->rollback()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertNull($result['error']);
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array('ran' => array(), 'manual' => array(), 'failed' => array('system')), $result['report']['cache_purge']);
        self::assertSame(array('ran' => array(), 'manual' => array(), 'failed' => array('system')), $result['report']['cache_purge_after_reactivation']);
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated);
    }

    public function test_a_rollback_killed_after_the_transition_purges_once_on_the_rerun_and_never_again(): void {
        $this->seed_cut_over();
        $before = $this->system->purges;
        $this->system->on_step = static function ($step) {
            if ($step === 'rolled_back') {
                throw new \RuntimeException('killed between the transition and the purge');
            }
        };
        $crashed = $this->rollback()->run(false);
        self::assertSame('error', $crashed['error']);
        self::assertSame(TakeoverState::ROLLED_BACK, TakeoverState::get()['status']);
        self::assertSame(0, $this->system->purges - $before);
        $this->system->on_step = null;

        $rerun = $this->rollback()->run(false);

        self::assertTrue($rerun['ok'], (string) json_encode($rerun));
        self::assertSame(1, $this->system->purges - $before);
        self::assertContains('caches_flushed', $rerun['steps']);
        self::assertArrayHasKey('cache_purge', $rerun['report']);

        $again = $this->rollback()->run(false);

        self::assertSame(1, $this->system->purges - $before, 'the recorded purge is not repeated');
        self::assertNotContains('caches_flushed', $again['steps']);
        self::assertArrayNotHasKey('cache_purge', $again['report']);
    }

    public function test_a_rollback_killed_after_the_reactivation_purges_that_once_on_the_rerun(): void {
        $this->seed_cut_over();
        $before = $this->system->purges;
        $this->system->on_step = static function ($step) {
            if ($step === 'official_reactivated') {
                throw new \RuntimeException('killed between the reactivation and its purge');
            }
        };
        $crashed = $this->rollback()->run(true);
        self::assertSame('error', $crashed['error']);
        self::assertSame(1, $this->system->purges - $before, 'only the transition purge ran');
        $this->system->on_step = null;

        $rerun = $this->rollback()->run(false);

        self::assertSame(2, $this->system->purges - $before);
        self::assertArrayHasKey('cache_purge_after_reactivation', $rerun['report']);
        self::assertArrayNotHasKey('cache_purge', $rerun['report'], 'the transition purge was already recorded');

        $this->rollback()->run(false);

        self::assertSame(2, $this->system->purges - $before, 'neither purge repeats');
    }

    public function test_an_abort_killed_after_the_transition_purges_once_on_the_rerun(): void {
        $this->seed_verified();
        $this->system->on_step = static function ($step) {
            if ($step === 'aborted') {
                throw new \RuntimeException('killed between the transition and the purge');
            }
        };
        self::assertSame('error', $this->rollback()->abort(false)['error']);
        self::assertSame(0, $this->system->purges);
        $this->system->on_step = null;

        $rerun = $this->rollback()->abort(false);

        self::assertSame(1, $this->system->purges);
        self::assertContains('caches_flushed', $rerun['steps']);
        $this->rollback()->abort(false);
        self::assertSame(1, $this->system->purges);
    }

    public function test_a_later_edit_wins_is_reported_as_a_conflict_and_every_untouched_value_is_restored(): void {
        $this->seed_cut_over();
        $merchant_order = array('supcheckout' => 0, 'bacs' => 1, 'upayments' => 2);
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = $merchant_order;
        $merchant_settings = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'Edited');
        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = $merchant_settings;

        $result = $this->rollback()->run(false);

        self::assertFalse($result['ok']);
        self::assertNull($result['error'], 'conflicts are not an error: the rollback completed');
        self::assertSame(array(array('seq' => $result['conflicts'][0]['seq'], 'target' => 'option', 'path' => 'woocommerce_gateway_order')), $result['conflicts']);
        self::assertIsInt($result['conflicts'][0]['seq']);
        self::assertSame($merchant_order, get_option('woocommerce_gateway_order'), 'the newer value is kept');
        self::assertSame('upayments', get_option('woocommerce_default_gateway'), 'untouched journaled values restored');
        // The legacy settings were unchanged since cutover: the merchant's later edit reaches them.
        self::assertSame($merchant_settings, get_option('woocommerce_upayments_settings'));
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array(), $this->system->activated, 'no consent, no reactivation');
    }

    public function test_settings_edited_under_both_names_since_cutover_are_a_settings_conflict(): void {
        $this->seed_cut_over();
        $merchant_settings = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'Edited');
        $GLOBALS['supcheckout_test_options'][Keys::SETTINGS_OPTION] = $merchant_settings;
        $legacy_edit = array('enabled' => 'no', 'api_key' => 'test-api-key-123');
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = $legacy_edit;

        $result = $this->rollback()->run(false);

        self::assertNull($result['error'], (string) json_encode($result));
        self::assertSame(array(array('seq' => -1, 'target' => 'settings_diverged', 'path' => 'woocommerce_upayments_settings')), $result['conflicts']);
        self::assertSame($legacy_edit, get_option('woocommerce_upayments_settings'), 'legacy kept');
        self::assertSame($merchant_settings, get_option(Keys::SETTINGS_OPTION), 'the merchant edit is kept for the owner to reconcile');
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode(array($result, Journal::entries())));
    }

    public function test_back_sync_never_regresses_a_legacy_value_written_outside_supcheckout(): void {
        $this->seed_cut_over();
        $order = $GLOBALS['supcheckout_test_status_orders'][7];
        // Dual-written by SUPCheckout while cut over, then the legacy name moved on alone.
        $order->external_write('_supcheckout_last_billed_at', array('2026-09-10'));
        $order->external_write('_upay_last_billed_at', array('2026-10-10'));
        $this->store->user_meta[3][self::NEW_SCOPE_KEY] = array('other-record');
        $this->store->products[40]->external_write('_supcheckout_disable_subscription', array('no'));
        $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 30;

        $result = $this->rollback()->run(false);

        self::assertNull($result['error'], (string) json_encode($result));
        self::assertFalse($result['ok']);
        $paths = array_column($result['conflicts'], 'path');
        sort($paths);
        self::assertSame(array(
            'option:upay_subscription_enrollment_cursor',
            'order_meta:7/_upay_last_billed_at',
            'product_meta:40/_upay_disable_subscription',
            'user_meta:3/_upay_customer_token_v2_b#' . substr(hash('sha256', self::SCOPE_KEY), 0, 16),
        ), $paths);
        self::assertSame(array('divergent'), array_values(array_unique(array_column($result['conflicts'], 'target'))));
        self::assertSame(array('2026-10-10'), $order->persisted_values('_upay_last_billed_at'), 'the newer legacy value is kept');
        self::assertSame(array('provenance-record'), $this->store->user_meta[3][self::SCOPE_KEY]);
        self::assertSame(array('yes'), $this->store->products[40]->persisted_values('_upay_disable_subscription'));
        self::assertSame(30, get_option('upay_subscription_enrollment_cursor'));
        // Absent legacy names are still filled: the Review Focus 5 order keeps its payment ID.
        self::assertSame(array('pay-8'), $GLOBALS['supcheckout_test_status_orders'][8]->persisted_values('UPayments_PaymentID'));
        self::assertSame(array(self::LATE_LEGACY_SCOPE_KEY => array('late-provenance')), $this->store->user_meta[5]);
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false), 'the stale new copy is removed');
    }

    public function test_an_active_official_plugin_refuses_the_rollback(): void {
        foreach (array('start', 'rechecked') as $when) {
            $this->setUp();
            $paid = $this->seed_cut_over();
            if ($when === 'start') {
                $this->system->official = 'upayments/UPayments.php';
            } else {
                $system = $this->system;
                $this->system->on_step = static function ($step) use ($system) {
                    if ($step === 'rechecked') {
                        $system->official = 'upayments/UPayments.php';
                    }
                };
            }

            $result = $this->rollback()->run(false);

            self::assertSame('official_active', $result['error'], $when);
            self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], $when);
            self::assertFalse(Rollback::removal_pending(TakeoverState::get()), $when);
            if ($when === 'start') {
                self::assertSame('supcheckout', $paid->get_payment_method(), 'nothing back-synced while the official plugin runs');
            }
        }
    }

    public function test_the_token_secret_agreement_is_checked_before_any_write(): void {
        $this->seed_cut_over();
        $GLOBALS['supcheckout_test_options']['supcheckout_token_identity_secret_v2'] = hash('sha256', 'other');

        $result = $this->rollback()->run(false);

        self::assertSame('options_mismatch', $result['error']);
        self::assertArrayNotHasKey(self::LATE_LEGACY_SCOPE_KEY, $this->store->user_meta[5], 'no provenance written');
        self::assertSame('supcheckout', $GLOBALS['supcheckout_test_status_orders'][8]->get_payment_method(), 'nothing written');
    }

    public function test_a_lease_lost_right_before_the_switch_leaves_no_progress_record(): void {
        $this->seed_cut_over();
        $this->system->on_step = static function ($step) {
            if ($step === 'rechecked') {
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = (self::T0 + 900) . ':' . str_repeat('f', 32);
            }
        };

        $result = $this->rollback()->run(false);

        self::assertSame('lease_lost', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertNull(Rollback::mode_of(TakeoverState::get()), 'no removal_pending record in a store that is still cut over');
    }

    public function test_a_legacy_only_option_write_after_the_transition_is_not_a_conflict(): void {
        $this->seed_cut_over();
        $this->system->on_step = static function ($step) {
            if ($step === 'cron_restored') {
                // The runtime writes legacy names only once rolled back.
                $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor'] = 31;
            }
        };

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(31, get_option('upay_subscription_enrollment_cursor'));
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false), 'the stale copy is removed, so the next copy starts clean');
    }

    public function test_the_billing_back_sync_fails_closed(): void {
        $cases = array(
            'legacy row without a new row' => function () {
                $this->store->tables['legacy'][self::key('5')] = array('cycle_key' => self::key('5'), 'parent_order_id' => 7, 'owner_token' => 'x', 'state' => 'resolved', 'payment_id' => 'pay-5');
            },
            'new row behind its legacy row' => function () {
                $this->store->tables['legacy'][self::key('1')]['state'] = 'resolved';
                $this->store->tables['new'][self::key('1')]['state'] = 'dispatching';
            },
            'empty new table' => function () {
                $this->store->tables['new'] = array();
            },
        );
        foreach ($cases as $case => $arrange) {
            $this->setUp();
            $this->seed_cut_over();
            $arrange();
            $legacy_before = $this->store->tables['legacy'];

            $result = $this->rollback()->run(false);

            self::assertSame('table_mismatch', $result['error'], $case . ': ' . json_encode($result));
            self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], $case);
            foreach ($legacy_before as $key => $row) {
                if ($row['state'] !== 'claimed') {
                    self::assertArrayHasKey($key, $this->store->tables['legacy'], $case . ': a non-claimed legacy row is never deleted');
                    $order = array('claimed', 'dispatching', 'held', 'resolved');
                    self::assertGreaterThanOrEqual(array_search($row['state'], $order, true), array_search($this->store->tables['legacy'][$key]['state'], $order, true), $case . ': never moved backwards');
                }
            }
        }
    }

    public function test_a_missing_new_table_refuses_the_back_sync_without_any_write(): void {
        $this->seed_cut_over();
        // The authoritative (new) table is gone while legacy holds only a stale claimed row.
        $this->store->new_table_exists = false;
        $this->store->tables['new'] = array();
        $this->store->tables['legacy'] = array(self::key('7') => array('cycle_key' => self::key('7'), 'parent_order_id' => 8, 'owner_token' => 'q', 'state' => 'claimed', 'payment_id' => null));
        $legacy_before = $this->store->tables['legacy'];

        $result = $this->rollback()->run(false);

        self::assertSame('table_mismatch', $result['error'], (string) json_encode($result));
        self::assertSame('new_table_missing', $result['report']['table']);
        self::assertSame($legacy_before, $this->store->tables['legacy'], 'the stale claim is never deleted or rewritten');
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_the_removal_settles_then_rescans_late_writes_before_recording_completion(): void {
        $this->seed_cut_over();
        $order = $GLOBALS['supcheckout_test_status_orders'][7];
        $store = $this->store;
        $transition = null;
        $system = $this->system;
        $pending_while_settling = array();
        $this->system->on_step = static function ($step) use (&$transition, $system, $order, $store) {
            if ($step === 'rolled_back') {
                $transition = $system->now;
            }
            if ($step === 'copies_removed') {
                // A request that read cut_over before the transition dual-writes after the cursor passed.
                $order->external_write('_supcheckout_payment_id', array('late'));
                $store->user_meta[3][self::NEW_SCOPE_KEY] = array('late-provenance');
                $GLOBALS['supcheckout_test_options']['supcheckout_subscription_enrollment_cursor'] = 26;
                $store->tables['new'][self::key('8')] = array('cycle_key' => self::key('8'), 'state' => 'resolved');
            }
        };
        $this->system->on_sleep = static function () use (&$pending_while_settling) {
            $pending_while_settling[] = Rollback::removal_pending(TakeoverState::get());
        };

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertNotNull($transition);
        self::assertGreaterThanOrEqual($transition + CopyRemoval::SETTLE_SECONDS, $this->system->now, 'completion waits for the settle time');
        self::assertNotContains(false, $pending_while_settling, 'completion is never recorded while settling');
        self::assertNotSame(array(), $pending_while_settling);
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'), 'the late order copy is removed by the rescan');
        self::assertArrayNotHasKey(self::NEW_SCOPE_KEY, $this->store->user_meta[3], 'the late H12 copy is removed');
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false));
        self::assertSame(array(), $this->store->tables['new']);
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertLessThan(array_search('removal_settled', $result['steps'], true), array_search('settling', $result['steps'], true));
    }

    public function test_late_writers_that_keep_recreating_copies_leave_the_removal_unsettled(): void {
        $this->seed_cut_over();
        $order = $GLOBALS['supcheckout_test_status_orders'][7];
        $this->system->on_step = static function ($step) use ($order) {
            if ($step === 'copies_removed' || $step === 'rescanned') {
                $order->external_write('_supcheckout_payment_id', array('late'));
            }
        };

        $result = $this->rollback()->run(false);

        self::assertSame('removal_unsettled', $result['error'], (string) json_encode($result));
        self::assertSame(TakeoverState::ROLLED_BACK, TakeoverState::get()['status'], 'never preflight_ok with copies still being recreated');
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
    }

    public function test_a_rerun_after_removal_unsettled_starts_a_fresh_rescan_and_completes(): void {
        foreach (array('rollback', 'abort') as $mode) {
            $this->setUp();
            if ($mode === 'rollback') {
                $this->seed_cut_over();
            } else {
                \Simplixi\SUPCheckout\Takeover\OrderCopier::copy($this->seed_verified(), false);
            }
            $order = $GLOBALS['supcheckout_test_status_orders'][7];
            $this->system->on_step = static function ($step) use ($order) {
                if ($step === 'copies_removed' || $step === 'rescanned') {
                    $order->external_write('_supcheckout_payment_id', array('late'));
                }
            };
            $first = $mode === 'rollback' ? $this->rollback()->run(false) : $this->rollback()->abort(false);
            self::assertSame('removal_unsettled', $first['error'], $mode);
            $settled_at = $this->system->now;

            // The late writer has stopped.
            $this->system->on_step = null;
            $again = $mode === 'rollback' ? $this->rollback()->run(false) : $this->rollback()->abort(false);

            self::assertTrue($again['ok'], $mode . ': ' . json_encode($again));
            self::assertNotContains('settling', $again['steps'], $mode . ': the settle reference time is kept');
            self::assertSame($settled_at, $this->system->now, $mode . ': no second settle wait');
            self::assertFalse(Rollback::removal_pending(TakeoverState::get()), $mode . ': completion recorded');
            self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], $mode);
            self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'), $mode);
        }
    }

    public function test_an_abort_also_settles_before_recording_completion(): void {
        $order = $this->seed_verified();
        \Simplixi\SUPCheckout\Takeover\OrderCopier::copy($order, false);
        $start = $this->system->now;
        $this->system->on_step = static function ($step) use ($order) {
            if ($step === 'copies_removed') {
                $order->external_write('_supcheckout_payment_id', array('late'));
            }
        };

        $result = $this->rollback()->abort(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertGreaterThanOrEqual($start + CopyRemoval::SETTLE_SECONDS, $this->system->now);
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
    }

    public function test_a_released_claim_still_claimed_in_legacy_is_deleted_by_the_back_sync(): void {
        $this->seed_cut_over();
        // Claimed in legacy before cutover (copied), released from the new table after it.
        $this->store->tables['legacy'][self::key('6')] = array('cycle_key' => self::key('6'), 'parent_order_id' => 7, 'owner_token' => 'r', 'state' => 'claimed', 'payment_id' => null);

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertArrayNotHasKey(self::key('6'), $this->store->tables['legacy']);
    }

    public function test_audit_only_billing_claim_entries_are_never_replayed(): void {
        $this->seed_cut_over();
        $released = array('cycle_key' => self::key('9'), 'parent_order_id' => 8, 'owner_token' => 'z', 'state' => 'claimed', 'payment_id' => null);
        Journal::record('billing_claim', 'billing_attempts/' . self::key('9'), $released, null, 'none:stale_claim_release');
        Journal::record('option', 'woocommerce_something_audited', 'a', 'b', 'none:other_audit');

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array(), $result['conflicts'], 'no conflict for audit-only entries');
        self::assertArrayNotHasKey(self::key('9'), $this->store->tables['legacy'], 'a released claim is never re-created');
        self::assertSame(array(self::key('1'), self::key('2')), array_keys($this->store->tables['legacy']));
        self::assertFalse(get_option('woocommerce_something_audited', false));
        self::assertSame(array('bacs' => 0, 'upayments' => 1), get_option('woocommerce_gateway_order'), 'the option entries next to it roll back');
    }

    public function test_a_malformed_journal_fails_before_anything_is_replayed(): void {
        $paid = $this->seed_cut_over();
        $GLOBALS['supcheckout_test_options'][Journal::OPTION] = '[{"seq":99}]';

        $result = $this->rollback()->run(true);

        self::assertFalse($result['ok']);
        self::assertSame('journal_unreadable', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame('supcheckout', $paid->get_payment_method(), 'nothing replayed or back-synced');
        self::assertSame('supcheckout', get_option('woocommerce_default_gateway'));
        self::assertArrayNotHasKey(self::key('2'), $this->store->tables['legacy']);
        self::assertSame(array(), $this->system->activated);
        self::assertFalse(Quiesce::is_active(self::T0), 'the lease is released');
    }

    public function test_a_lost_lease_stops_before_the_transition(): void {
        $this->seed_cut_over();
        $this->system->on_step = static function ($step) {
            if ($step === 'options_synced') {
                // Another holder took the lease after ours expired.
                $GLOBALS['supcheckout_test_options'][Quiesce::OPTION] = (self::T0 + 900) . ':' . str_repeat('f', 32);
            }
        };

        $result = $this->rollback()->run(false);

        self::assertFalse($result['ok']);
        self::assertSame('lease_lost', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], 'no transition without the lease');
        self::assertNotContains('rolled_back', $result['steps']);
    }

    public function test_a_second_holder_makes_rollback_busy(): void {
        $this->seed_cut_over();
        self::assertNotNull(Quiesce::acquire(self::T0));

        $result = $this->rollback()->run(false);

        self::assertSame('busy', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_claims_pinned_to_the_new_table_are_drained_before_the_table_back_sync(): void {
        $this->seed_cut_over();
        // A worker holds a claim in the new table (CycleClaim pins it there) when rollback starts.
        $this->store->tables['new'][self::key('3')] = array('cycle_key' => self::key('3'), 'parent_order_id' => 8, 'owner_token' => 'w', 'state' => 'dispatching', 'payment_id' => null, 'updated_gmt' => gmdate('Y-m-d H:i:s', self::T0));
        $store = $this->store;
        $this->system->on_sleep = static function () use ($store) {
            // Its provider call returns and it resolves the claim in its pinned (new) table.
            $store->tables['new'][self::key('3')]['state'] = 'resolved';
            $store->tables['new'][self::key('3')]['payment_id'] = 'pay-3';
        };
        $this->store->columns[] = 'updated_gmt';
        $this->store->new_columns[] = 'updated_gmt';

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertGreaterThan(0, $this->system->sleeps, 'the drain waited for the in-flight claim');
        self::assertSame('resolved', $this->store->tables['legacy'][self::key('3')]['state'], 'its final state is what the legacy table gets');
        self::assertSame('pay-3', $this->store->tables['legacy'][self::key('3')]['payment_id']);
    }

    public function test_a_stale_claim_in_the_new_table_refuses_the_rollback(): void {
        $this->seed_cut_over();
        $this->store->tables['new'][self::key('4')] = array('cycle_key' => self::key('4'), 'state' => 'claimed', 'updated_gmt' => gmdate('Y-m-d H:i:s', self::T0 - 3600));

        $result = $this->rollback()->run(false);

        self::assertSame('stale_claims', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
    }

    public function test_a_table_that_does_not_back_sync_keeps_the_store_cut_over(): void {
        $paid = $this->seed_cut_over();
        $this->store->fail_replace = true;

        $result = $this->rollback()->run(false);

        self::assertSame('table_mismatch', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame('upayments', $paid->get_payment_method(), 'a pre-transition rewrite is harmless while cut over');
        self::assertTrue(Keys::is_our_gateway('upayments'));
    }

    public function test_a_crash_before_the_transition_is_resumed_by_running_rollback_again(): void {
        foreach (array('drained', 'orders_synced', 'users_synced', 'products_synced', 'options_synced', 'journal_replayed', 'table_synced', 'rechecked') as $crash_at) {
            $this->setUp();
            $paid = $this->seed_cut_over();
            $this->system->on_step = static function ($step) use ($crash_at) {
                if ($step === $crash_at) {
                    throw new \RuntimeException('killed');
                }
            };
            $crashed = $this->rollback()->run(false);
            self::assertSame('error', $crashed['error'], $crash_at);
            self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status'], $crash_at);

            $this->system->on_step = null;
            $result = $this->rollback()->run(false);

            self::assertTrue($result['ok'], $crash_at . ': ' . json_encode($result));
            self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], $crash_at);
            self::assertSame('upayments', $paid->get_payment_method(), $crash_at);
            self::assertSame(array('pay-8'), $paid->persisted_values('UPayments_PaymentID'), $crash_at);
            self::assertSame(array('bacs' => 0, 'upayments' => 1), get_option('woocommerce_gateway_order'), $crash_at);
            self::assertSame('upayments', get_option('woocommerce_default_gateway'), $crash_at);
            self::assertSame('resolved', $this->store->tables['legacy'][self::key('1')]['state'], $crash_at);
            self::assertSame(array(), $this->store->tables['new'], $crash_at);
            self::assertCount(1, array_filter(Journal::entries(), static function ($entry) {
                return $entry['path'] === '8/payment_method';
            }), $crash_at . ': the rewrite is journaled once');
        }
    }

    public function test_a_crash_after_the_transition_resumes_the_removal_and_keeps_the_conflicts(): void {
        foreach (array('rolled_back', 'cron_restored', 'copies_removed') as $crash_at) {
            $this->setUp();
            $this->seed_cut_over();
            $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('supcheckout' => 0, 'upayments' => 1);
            $this->system->on_step = static function ($step) use ($crash_at) {
                if ($step === $crash_at) {
                    throw new \RuntimeException('killed');
                }
            };
            $crashed = $this->rollback()->run(true);
            self::assertSame('error', $crashed['error'], $crash_at);
            self::assertSame(TakeoverState::ROLLED_BACK, TakeoverState::get()['status'], $crash_at . ': rolled_back stays until the removal completes');
            self::assertFalse(TakeoverState::transition(TakeoverState::COPYING), 'no copy can start from rolled_back');

            $this->system->on_step = null;
            $result = $this->rollback()->run(true);

            self::assertFalse($result['ok'], $crash_at);
            self::assertNull($result['error'], $crash_at . ': ' . json_encode($result));
            self::assertSame(array('woocommerce_gateway_order'), array_column($result['conflicts'], 'path'), $crash_at . ': persisted conflicts are reported again');
            self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], $crash_at);
            self::assertSame(array(), $this->store->tables['new'], $crash_at);
            self::assertSame(array(), $GLOBALS['supcheckout_test_status_orders'][7]->persisted_values('_supcheckout_payment_id'), $crash_at);
            self::assertSame(array('upayments/UPayments.php'), $this->system->activated, $crash_at . ': reactivated once');
        }
    }

    public function test_a_rolled_back_store_without_a_progress_record_still_removes_its_copies_first(): void {
        $this->seed_cut_over();
        self::assertTrue(TakeoverState::transition(TakeoverState::ROLLED_BACK), 'rolled back outside Rollback (no progress record)');

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false), 'every option copy is removed');
        self::assertLessThan(array_search('preflight_ok', $result['steps'], true), array_search('copies_removed', $result['steps'], true));
        self::assertSame(array(), $this->store->tables['new']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_status_orders'][7]->persisted_values('_supcheckout_payment_id'));
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
    }

    public function test_an_order_created_by_an_in_flight_request_after_the_transition_is_rewritten_by_the_removal(): void {
        $this->seed_cut_over();
        $this->system->on_step = static function ($step) {
            if ($step === 'rolled_back') {
                $GLOBALS['supcheckout_test_status_orders'][9] = new FakeCrudObject(9, 'supcheckout', array('_supcheckout_payment_id' => array('pay-9')), 'pending');
            }
        };

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame('upayments', $GLOBALS['supcheckout_test_status_orders'][9]->get_payment_method());
    }

    public function test_a_removal_that_cannot_finish_leaves_the_store_rolled_back(): void {
        $this->seed_cut_over();
        $this->system->on_step = static function ($step) {
            if ($step === 'cron_restored') {
                $GLOBALS['supcheckout_test_status_orders'][7]->fail_next_save = true;
            }
        };

        $result = $this->rollback()->run(false);

        self::assertSame('removal_incomplete', $result['error']);
        self::assertSame(TakeoverState::ROLLED_BACK, TakeoverState::get()['status']);

        $this->system->on_step = null;
        $again = $this->rollback()->run(false);
        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
    }

    public function test_a_missing_legacy_cron_event_is_recreated_from_its_twin(): void {
        $this->seed_cut_over();
        // The legacy recurring event vanished during the window; only its twin is left.
        $this->system->events = array_values(array_filter($this->system->events, static function ($event) {
            return $event['hook'] !== 'upay_process_subscriptions';
        }));
        // A reconcile the runtime scheduled under the new name after cutover.
        $this->system->events[] = array('timestamp' => self::T0 + 900, 'hook' => 'supcheckout_reconcile_order', 'args' => array(8), 'schedule' => false);

        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        $events = array();
        foreach ($this->system->events as $event) {
            $events[] = $event['hook'] . '@' . $event['timestamp'] . ':' . json_encode($event['args']) . ':' . json_encode($event['schedule']);
        }
        sort($events);
        self::assertSame(array(
            'simplixpay_upayments_reconcile_order@' . (self::T0 + 600) . ':[7]:false',
            'simplixpay_upayments_reconcile_order@' . (self::T0 + 900) . ':[8]:false',
            'upay_process_subscriptions@' . (self::T0 + 3600) . ':[]:"hourly"',
        ), $events, 'every new-name event moved back to its legacy name, none lost');
    }

    public function test_a_disagreeing_token_identity_secret_fails_closed(): void {
        $this->seed_cut_over();
        $GLOBALS['supcheckout_test_options']['supcheckout_token_identity_secret_v2'] = hash('sha256', 'other');

        $result = $this->rollback()->run(false);

        self::assertSame('options_mismatch', $result['error']);
        self::assertSame(TakeoverState::CUT_OVER, TakeoverState::get()['status']);
        self::assertSame(hash('sha256', 'secret'), get_option('upayments_token_identity_secret_v2'), 'never overwritten');
    }

    public function test_only_the_install_the_takeover_deactivated_is_reactivated(): void {
        $this->seed_cut_over();
        $this->system->installed = array('upayments/UPayments.php', 'upayments-old/UPayments.php');

        $result = $this->rollback()->run(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated, 'the recorded one, although two are installed');
        self::assertArrayNotHasKey('official_deactivated', array_filter(TakeoverState::get(), static function ($value) {
            return $value !== null;
        }), 'the record is cleared once reactivated');
    }

    public function test_without_a_deactivation_record_or_an_install_nothing_is_reactivated(): void {
        foreach (array('no record', 'not installed') as $case) {
            $this->setUp();
            $this->seed_cut_over();
            if ($case === 'no record') {
                self::assertTrue(TakeoverState::patch(array('official_deactivated' => null)));
            } else {
                $this->system->installed = array('upayments-old/UPayments.php');
            }

            $result = $this->rollback()->run(true);

            self::assertFalse($result['ok'], $case);
            self::assertNull($result['error'], $case);
            self::assertSame(array('official_not_reactivated'), $result['warnings'], $case);
            self::assertSame(array(), $this->system->activated, $case);
            self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status'], $case . ': the rollback itself completed');
        }
    }

    public function test_rollback_requires_a_cut_over_store_and_abort_a_verified_one(): void {
        $this->seed_verified();
        self::assertSame('not_cut_over', $this->rollback()->run(false)['error']);
        CutoverTest::state('cut_over');
        self::assertSame('not_verified', $this->rollback()->abort(false)['error']);
        CutoverTest::state('copying');
        self::assertSame('not_cut_over', $this->rollback()->run(false)['error']);
        self::assertSame('not_verified', $this->rollback()->abort(false)['error']);
    }

    public function test_the_journal_floor_keeps_a_later_rollback_to_its_own_cycle(): void {
        $this->seed_cut_over();
        self::assertTrue($this->rollback()->run(false)['ok']);
        $floor = TakeoverState::get()['journal_floor'];
        self::assertSame(count(Journal::entries()), $floor);
    }

    public function test_abort_undoes_a_partial_cutover_and_removes_the_copies_without_a_table_back_sync(): void {
        $order = $this->seed_verified();
        // The copy runner's copies, and steps 5-7 of a cutover that died before its switch.
        \Simplixi\SUPCheckout\Takeover\OrderCopier::copy($order, false);
        \Simplixi\SUPCheckout\Takeover\UserMetaCopier::copy_user($this->store, 3);
        $this->store->tables['new'] = $this->store->tables['legacy'];
        self::assertTrue(SettingsCopier::copy()['ok']);
        self::assertTrue(CronRescheduler::reschedule($this->system)['ok']);
        self::assertTrue(ThirdPartyTranslator::translate(static function () {
            return array();
        })['ok']);
        // A legacy row changed after the copy; abort must not push the stale copy back.
        $this->store->tables['legacy'][self::key('1')]['state'] = 'resolved';
        $legacy_before = $this->store->tables['legacy'];

        $result = $this->rollback()->abort(true);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame($legacy_before, $this->store->tables['legacy'], 'no billing back-sync: legacy is authoritative while verified');
        self::assertSame(array(), $this->store->tables['new']);
        self::assertSame('upayments', get_option('woocommerce_default_gateway'));
        self::assertSame(array('bacs' => 0, 'upayments' => 1), get_option('woocommerce_gateway_order'));
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
        self::assertFalse(get_option('supcheckout_token_identity_secret_v2', false));
        self::assertSame(array('simplixpay_upayments_reconcile_order', 'upay_process_subscriptions'), array_column($this->system->events, 'hook'));
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array(), $order->persisted_values(Keys::TAKEOVER_COPY_MARKER));
        self::assertSame(array(self::SCOPE_KEY => array('provenance-record')), $this->store->user_meta[3]);
        self::assertSame(array('upayments/UPayments.php'), $this->system->activated, 'run deactivated it; abort reactivates it (consented)');
        self::assertFalse(Quiesce::is_active(self::T0));
    }

    public function test_an_interrupted_abort_blocks_a_new_copy_until_it_is_rerun(): void {
        $order = $this->seed_verified();
        \Simplixi\SUPCheckout\Takeover\OrderCopier::copy($order, false);
        $this->system->on_step = static function ($step) {
            if ($step === 'aborted') {
                throw new \RuntimeException('killed');
            }
        };

        self::assertSame('error', $this->rollback()->abort(false)['error']);
        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
        self::assertFalse(CopyRunner::begin(), 'no copy starts while copies from the abort are still being removed');

        $this->system->on_step = null;
        $result = $this->rollback()->abort(false);
        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertFalse(Rollback::removal_pending(TakeoverState::get()));
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
        self::assertTrue(CopyRunner::begin());
    }

    // --- 0c Task 3: a budgeted (asynchronous) run yields instead of sleeping. ---

    private function budgeted(int $now): FakeBudgetedSystem {
        $system = new FakeBudgetedSystem();
        $system->now = $now;
        $this->system = $system;
        return $system;
    }

    /** @return array<string, mixed> The persisted rollback progress. */
    private static function progress(): array {
        TakeoverState::refresh();
        return TakeoverState::get()[Rollback::PROGRESS];
    }

    public function test_a_budgeted_rollback_yields_at_the_settle_barrier_instead_of_sleeping(): void {
        $system = $this->budgeted(5000);
        $this->seed_cut_over();

        $result = $this->rollback()->run(false);

        self::assertSame('yielded', $result['error'], (string) json_encode($result));
        self::assertFalse($result['ok']);
        self::assertSame(5120, $result['report']['resume_at']);
        self::assertSame(0, $system->sleeps);
        self::assertSame('rolled_back', TakeoverState::get()['status']);
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
        self::assertSame('yielded', $result['steps'][count($result['steps']) - 1]);
        self::assertNotContains('settling', $result['steps']);
        self::assertContains('copies_removed', $result['steps']);
        $progress = self::progress();
        self::assertSame(5000, $progress['transitioned_at'], 'the settle reference time is persisted');
        self::assertSame(1, $progress['pass'], 'still the cursor pass: a rerun settles before any rescan');
        self::assertSame(CopyRemoval::PHASE_DONE, $progress['removal']['phase']);
        self::assertFalse(Quiesce::is_active(5000), 'no lease is held across the yield');
    }

    public function test_a_yielded_rollback_resumes_after_the_deadline_and_completes(): void {
        $system = $this->budgeted(5000);
        $this->seed_cut_over();
        self::assertSame('yielded', $this->rollback()->run(false)['error']);

        $system->now = 5060;
        $early = $this->rollback()->run(false);
        self::assertSame('yielded', $early['error'], 'before the deadline it yields again');
        self::assertSame(5120, $early['report']['resume_at'], 'the settle reference time is kept');
        self::assertSame('rolled_back', TakeoverState::get()['status']);

        $system->now = 5120;
        $result = $this->rollback()->run(false);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertNull($result['error']);
        self::assertSame(array('rescanned', 'removal_settled', 'preflight_ok'), array_slice($result['steps'], -3));
        self::assertNotContains('settling', $result['steps'], 'no second wait');
        self::assertSame(0, $system->sleeps);
        self::assertSame('preflight_ok', TakeoverState::get()['status']);
        self::assertFalse(Rollback::removal_pending(TakeoverState::get()));
        self::assertSame(array(), $this->store->tables['new']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_status_orders'][7]->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array(), $GLOBALS['supcheckout_test_status_orders'][8]->persisted_values('_supcheckout_payment_id'));
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false));
        self::assertArrayNotHasKey(self::LATE_SCOPE_KEY, $this->store->user_meta[5]);
    }

    public function test_a_budget_spent_mid_removal_yields_after_one_saved_unit(): void {
        $system = $this->budgeted(5000);
        $this->seed_cut_over();
        $system->spent = true;

        $result = $this->rollback()->run(false);

        self::assertSame('yielded', $result['error'], (string) json_encode($result));
        self::assertSame(5000, $result['report']['resume_at'], 'resume at once: only the budget ran out');
        self::assertSame(1, $system->budget_checks, 'asked once, after the first saved unit');
        self::assertSame(array('cron_restored', 'yielded'), array_slice($result['steps'], -2));
        self::assertSame(array('phase' => CopyRemoval::PHASE_ORDERS, 'cursor' => 0), self::progress()['removal'], 'the persisted cursor advanced by exactly one unit');
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
        self::assertSame('rolled_back', TakeoverState::get()['status']);

        $system->spent = false;
        $system->now = 5120;
        $again = $this->rollback()->run(false);
        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertNotContains('cron_restored', $again['steps'], 'the cron unit is not repeated');
        self::assertSame('preflight_ok', TakeoverState::get()['status']);
        self::assertSame(array(), $this->store->tables['new']);
    }

    public function test_a_budget_spent_by_the_save_itself_yields_before_another_unit(): void {
        $system = $this->budgeted(5000);
        $this->seed_cut_over();
        $system->spent = static function () {
            // Spent once the first unit's save has landed (a slow database write crossed the deadline).
            return self::progress()['removal']['phase'] !== CopyRemoval::PHASE_CRON;
        };

        $result = $this->rollback()->run(false);

        self::assertSame('yielded', $result['error'], (string) json_encode($result));
        self::assertSame(5000, $result['report']['resume_at']);
        self::assertSame(2, $system->budget_checks, 'asked before the save (false) and again after it (true)');
        self::assertSame(array('cron_restored', 'yielded'), array_slice($result['steps'], -2));
        $progress = self::progress();
        self::assertSame(array('phase' => CopyRemoval::PHASE_ORDERS, 'cursor' => 0), $progress['removal'], 'no second unit was started');
        self::assertTrue($progress['yielded'], 'the yield is recorded on the same cursor');
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));

        $system->spent = false;
        $system->now = 5120;
        $again = $this->rollback()->run(false);
        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertSame('preflight_ok', TakeoverState::get()['status']);
    }

    public function test_a_rescan_yielded_mid_pass_resumes_from_its_cursor_and_keeps_its_count(): void {
        $system = $this->budgeted(self::T0);
        $this->seed_cut_over();
        $order = $GLOBALS['supcheckout_test_status_orders'][7];
        $armed = false;
        $system->on_step = static function ($step) use (&$armed, $system, $order) {
            if ($step === 'copies_removed') {
                // A late writer after the cursor passed, and the settle time already over.
                $order->external_write('_supcheckout_payment_id', array('late'));
                $system->now += CopyRemoval::SETTLE_SECONDS;
                $armed = true;
            }
        };
        $system->spent = static function () use (&$armed) {
            // Out of budget once the rescan's first order batch has saved the late copy it removed.
            return $armed && self::progress()['found'] >= 1;
        };

        $first = $this->rollback()->run(false);

        self::assertSame('yielded', $first['error'], (string) json_encode($first));
        $progress = self::progress();
        self::assertSame(2, $progress['pass']);
        self::assertSame(1, $progress['found'], 'the late copy the rescan removed is counted');
        self::assertSame(CopyRemoval::PHASE_ORDERS, $progress['removal']['phase']);

        $system->on_step = null;
        $system->spent = false;
        $again = $this->rollback()->run(false);

        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertCount(2, array_keys($again['steps'], 'rescanned', true), 'the yielded rescan ends with its count, so one more clean rescan follows');
        self::assertSame(1, $again['report']['late_copies_removed']);
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame('preflight_ok', TakeoverState::get()['status']);
    }

    public function test_a_budgeted_abort_yields_at_the_settle_barrier_and_resumes(): void {
        $system = $this->budgeted(5000);
        $order = $this->seed_verified();
        \Simplixi\SUPCheckout\Takeover\OrderCopier::copy($order, false);

        $first = $this->rollback()->abort(false);
        self::assertSame('yielded', $first['error'], (string) json_encode($first));
        self::assertSame(5120, $first['report']['resume_at']);
        self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
        self::assertFalse(CopyRunner::begin(), 'no copy starts while the abort is yielded');

        $system->now = 5120;
        $again = $this->rollback()->abort(false);
        self::assertTrue($again['ok'], (string) json_encode($again));
        self::assertSame(0, $system->sleeps);
        self::assertFalse(Rollback::removal_pending(TakeoverState::get()));
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
    }

    public function test_the_wordpress_system_never_yields(): void {
        self::assertNotInstanceOf(BudgetedSystem::class, new WordPressCutoverSystem());
    }
}
