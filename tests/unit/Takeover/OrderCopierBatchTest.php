<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\OrderCopier;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 6: the batched, resumable copy runner (Review Focus 4) on the real
 * TakeoverState, OrderLock and OrderCopier, with an in-memory CopyStore.
 */
final class OrderCopierBatchTest extends TestCase {
    /** @var FakeCopyStore */
    private $store;
    /** @var int */
    private $now = 1800000000;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        self::state('copying');
        $this->store = new FakeCopyStore();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
    }

    // --- Orders. ---

    public function test_a_run_killed_after_order_37_of_120_resumes_without_skipping_or_double_processing(): void {
        $orders = array();
        for ($id = 1; $id <= 120; $id++) {
            $orders[$id] = self::order($id, array('UPayments_PaymentID' => array('pay-' . $id), 'UPayments_WHS' => array('failed', 'completed')));
        }
        $killed = false;
        $this->store->on_load_order = static function ($order_id) use (&$killed) {
            if ($order_id === 38 && !$killed) {
                $killed = true;
                throw new \RuntimeException('process killed');
            }
        };

        try {
            $this->runner()->run();
            self::fail('the batch must die at order 38');
        } catch (\RuntimeException $e) {
            self::assertSame('process killed', $e->getMessage());
        }
        self::assertSame(37, TakeoverState::get()['cursor'], 'the cursor is persisted after each order');

        $statuses = $this->run_to_end();

        self::assertSame('verified', end($statuses));
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertSame(120, TakeoverState::get()['cursor'], 'the cursor ends at the last ID');
        foreach ($orders as $id => $order) {
            self::assertSame(1, $order->saves, "order $id is copied exactly once");
            self::assertSame(array('pay-' . $id), $order->persisted_values('_supcheckout_payment_id'));
            self::assertSame(array('failed', 'completed'), $order->persisted_values('_supcheckout_public_status'));
            self::assertTrue(OrderCopier::verify($order)['ok']);
        }
        self::assertSame(120, TakeoverState::get()['totals']['orders']);
    }

    public function test_batches_take_at_most_fifty_orders_and_persist_the_cursor_after_each(): void {
        for ($id = 1; $id <= 60; $id++) {
            self::order($id, array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $cursors = array();
        $this->store->on_load_order = static function () use (&$cursors) {
            $cursors[] = TakeoverState::get()['cursor'];
        };

        self::assertSame('continue', $this->runner()->run());

        self::assertSame(50, TakeoverState::get()['cursor']);
        self::assertSame(range(0, 49), array_values(array_unique($cursors)), 'one cursor patch per order');
        foreach ($this->store->selections as $selection) {
            self::assertLessThanOrEqual(50, $selection[1]);
        }
        self::assertSame(array(array('orders', 50)), $this->store->selections);
    }

    public function test_orders_are_selected_by_gateway_or_by_legacy_meta(): void {
        $ours = self::order(1, array('UPayments_PaymentID' => array('pay-1')));
        $switched = self::order(2, array('UPayments_PaymentID' => array('pay-2')), 'bacs');
        $foreign = self::order(3, array(), 'bacs');

        $this->run_to_end();

        self::assertSame(array('pay-1'), $ours->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array('pay-2'), $switched->persisted_values('_supcheckout_payment_id'), 'a gateway-switched order keeps its UPayments data');
        self::assertSame(array(), $foreign->writes, 'another gateway order without legacy data is never touched');
        self::assertSame('bacs', $switched->get_payment_method(), 'payment_method is never rewritten');
    }

    public function test_a_status_change_stops_the_runner_and_its_cursor_patch_is_refused(): void {
        for ($id = 1; $id <= 10; $id++) {
            self::order($id, array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $this->store->on_load_order = static function ($order_id) {
            if ($order_id === 5) {
                // Another admin aborts the copy while order 5 is being processed.
                $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 4, 'totals' => array()));
            }
        };

        self::assertSame('stopped', $this->runner()->run());

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(4, TakeoverState::get()['cursor'], 'the patch after order 5 is refused');
        for ($id = 6; $id <= 10; $id++) {
            self::assertSame(0, $GLOBALS['supcheckout_test_status_orders'][$id]->saves, "order $id is not processed");
        }
        self::assertSame('idle', $this->runner()->run(), 'a runner outside copying does nothing');
    }

    public function test_a_locked_order_defers_the_batch_without_advancing_the_cursor(): void {
        for ($id = 1; $id <= 5; $id++) {
            self::order($id, array('UPayments_PaymentID' => array('pay-' . $id)));
        }
        $runtime = OrderLock::acquire(3);
        self::assertIsString($runtime);

        self::assertSame('retry', $this->runner()->run());
        self::assertSame(2, TakeoverState::get()['cursor']);
        self::assertSame(0, $GLOBALS['supcheckout_test_status_orders'][3]->saves);
        self::assertSame(0, $GLOBALS['supcheckout_test_status_orders'][4]->saves);

        OrderLock::release(3, $runtime);
        $statuses = $this->run_to_end();
        self::assertSame('verified', end($statuses));
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertSame(array('pay-3'), $GLOBALS['supcheckout_test_status_orders'][3]->persisted_values('_supcheckout_payment_id'));
    }

    public function test_mismatches_return_the_takeover_to_preflight_ok_with_a_summary_of_key_names(): void {
        $broken = self::order(1, array('UPayments_TrackID' => array('track-1')));
        $broken->inject_unwrappable_row('UPayments_PaymentID', 'pay-secret-1');
        self::order(2, array('UPayments_TrackID' => array('track-2'), '_supcheckout_track_id' => array('pay-secret-stale')));

        $statuses = $this->run_to_end();

        self::assertSame('mismatch', end($statuses));
        $state = TakeoverState::get();
        self::assertSame(TakeoverState::PREFLIGHT_OK, $state['status']);
        self::assertSame(array('count' => 1, 'keys' => array('UPayments_PaymentID')), $state['mismatches']['orders']);
        self::assertStringNotContainsString('pay-secret', (string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], 'the state never holds values');
    }

    public function test_an_hpos_postmeta_only_value_blocks_verification(): void {
        self::order(1, array('UPayments_PaymentID' => array('pay-1')));
        $this->store->post_meta = static function ($id, $key) {
            return $key === 'UPayments_TranID' ? array('tran-post-only') : array();
        };

        $statuses = $this->run_to_end();

        self::assertSame('mismatch', end($statuses));
        self::assertSame(array('count' => 1, 'keys' => array('UPayments_TranID')), TakeoverState::get()['mismatches']['disagreements']);
    }

    // --- Runner lock, start and idle. ---

    public function test_a_live_runner_lock_makes_a_second_runner_back_off_and_a_stale_one_is_recovered(): void {
        self::order(1, array('UPayments_PaymentID' => array('pay-1')));
        $GLOBALS['supcheckout_test_options'][CopyRunner::LOCK_OPTION] = ($this->now + 60) . ':' . str_repeat('a', 32);

        self::assertSame('busy', $this->runner()->run());
        self::assertSame(0, $GLOBALS['supcheckout_test_status_orders'][1]->saves);

        $GLOBALS['supcheckout_test_options'][CopyRunner::LOCK_OPTION] = ($this->now - 1) . ':' . str_repeat('a', 32);
        self::assertSame('continue', $this->runner()->run());
        self::assertSame(1, $GLOBALS['supcheckout_test_status_orders'][1]->saves);
        self::assertStringStartsWith('0:', (string) $GLOBALS['supcheckout_test_options'][CopyRunner::LOCK_OPTION], 'released after the batch');
    }

    public function test_a5_02_each_run_flushes_the_runtime_cache_once_after_releasing_its_lock(): void {
        for ($id = 1; $id <= 120; $id++) {
            self::order($id, array('UPayments_PaymentID' => array('pay-' . $id)));
        }

        self::assertSame('continue', $this->runner()->run());
        self::assertSame(1, $this->store->runtime_flushes, 'one flush per bounded step');
        self::assertSame('continue', $this->runner()->run());

        self::assertSame(2, $this->store->runtime_flushes);
        foreach ($this->store->lock_at_flush as $lock) {
            self::assertStringStartsWith('0:released', (string) $lock, 'the flush comes after the runner lock is released');
        }
        self::assertSame(array('pay-100'), $GLOBALS['supcheckout_test_status_orders'][100]->persisted_values('_supcheckout_payment_id'));
    }

    public function test_a5_02_an_idle_or_busy_runner_flushes_nothing(): void {
        self::order(1, array('UPayments_PaymentID' => array('pay-1')));
        self::state('verified');
        self::assertSame('idle', $this->runner()->run());

        self::state('copying');
        $GLOBALS['supcheckout_test_options'][CopyRunner::LOCK_OPTION] = ($this->now + 60) . ':' . str_repeat('a', 32);
        self::assertSame('busy', $this->runner()->run());

        self::assertSame(0, $this->store->runtime_flushes, 'no step ran, so there is nothing to drop');
    }

    public function test_begin_resets_progress_and_enters_copying_only_from_preflight_ok(): void {
        self::state('idle');
        self::assertFalse(CopyRunner::begin());

        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array(
            'status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 99, 'totals' => array('orders' => 99),
            'phase' => 'finish', 'mismatches' => array('orders' => array('count' => 3, 'keys' => array('UPayments_WHS'))),
        ));
        TakeoverState::flush_cache();

        self::assertTrue(CopyRunner::begin());
        $state = TakeoverState::get();
        self::assertSame(TakeoverState::COPYING, $state['status']);
        self::assertSame(0, $state['cursor']);
        self::assertSame('orders', $state['phase']);
        self::assertSame(array('count' => 0, 'keys' => array()), $state['mismatches']['orders']);
    }

    // --- Users. ---

    public function test_the_user_phase_copies_provenance_and_ledgers_and_an_interrupted_run_adds_no_second_row(): void {
        $scope = str_repeat('a', 32);
        for ($user = 1; $user <= 70; $user++) {
            $this->store->user_meta[$user]['_upay_customer_token_v2_b1_' . $scope] = array(self::record($scope, (string) (10000000 + $user)));
            $this->store->user_meta[$user]['simplixpay_upayments_migration_v1'] = array('ledger-' . $user);
        }
        $killed = false;
        $this->store->on_add_user_meta = static function ($user_id) use (&$killed) {
            if ($user_id === 37 && !$killed) {
                $killed = true;
                throw new \RuntimeException('process killed');
            }
        };

        try {
            $this->run_to_end();
            self::fail('the user batch must die at user 37');
        } catch (\RuntimeException $e) {
            self::assertSame('process killed', $e->getMessage());
        }
        self::assertSame(36, TakeoverState::get()['cursors']['users']);
        $statuses = $this->run_to_end();

        self::assertSame('verified', end($statuses));
        self::assertSame(140, $this->store->user_meta_adds, 'two keys per user, each added once');
        for ($user = 1; $user <= 70; $user++) {
            self::assertSame(array(self::record($scope, (string) (10000000 + $user))), $this->store->user_meta[$user]['_supcheckout_customer_token_v2_b1_' . $scope], "user $user: exactly one provenance row");
            self::assertSame(array('ledger-' . $user), $this->store->user_meta[$user]['supcheckout_migration_v1']);
        }
    }

    public function test_a_differing_new_user_value_is_reported_by_prefix_and_never_replaced(): void {
        $scope = str_repeat('b', 32);
        $this->store->user_meta[5]['_upay_customer_token_v2_b1_' . $scope] = array(self::record($scope, '12345678'));
        $this->store->user_meta[5]['_supcheckout_customer_token_v2_b1_' . $scope] = array(self::record($scope, '87654321'));

        $statuses = $this->run_to_end();

        self::assertSame('mismatch', end($statuses));
        self::assertSame(array('count' => 1, 'keys' => array('_upay_customer_token_v2_b')), TakeoverState::get()['mismatches']['users']);
        self::assertSame(0, $this->store->user_meta_adds);
        self::assertSame(array(self::record($scope, '87654321')), $this->store->user_meta[5]['_supcheckout_customer_token_v2_b1_' . $scope]);
        self::assertStringNotContainsString($scope, (string) $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION], 'no scope or token in the state');
    }

    public function test_slashed_user_values_round_trip_through_add_metadata_unslashing(): void {
        $value = array('path' => 'C:\\shop\\"quoted"', 'note' => "it's \\n literal");
        $this->store->user_meta[3]['_simplixpay_upayments_migration_result_v1'] = array($value);
        $this->store->user_meta[3]['simplixpay_upayments_migration_v1'] = array('a\\b');

        $this->run_to_end();

        self::assertSame(array($value), $this->store->user_meta[3]['_supcheckout_migration_result_v1'], 'byte-identical after add_metadata() unslashes');
        self::assertSame(array('a\\b'), $this->store->user_meta[3]['supcheckout_migration_v1']);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    public function test_a_multi_row_legacy_user_key_is_reported_as_an_anomaly_and_not_copied(): void {
        $this->store->user_meta[4]['simplixpay_upayments_migration_v1'] = array('one', 'two');

        $statuses = $this->run_to_end();

        self::assertSame('verified', end($statuses), 'reads fall back to the legacy rows: not blocking');
        self::assertArrayNotHasKey('supcheckout_migration_v1', $this->store->user_meta[4]);
        self::assertSame(array('count' => 1, 'keys' => array('simplixpay_upayments_migration_v1')), TakeoverState::get()['anomalies']['users_multi_row']);
    }

    // --- Products. ---

    public function test_the_product_phase_copies_the_subscription_flag_through_crud(): void {
        $product = new FakeCrudObject(500, '', array('_upay_disable_subscription' => array('yes')));
        $this->store->products[500] = $product;

        $this->run_to_end();
        $this->run_to_end();

        self::assertSame(array('yes'), $product->persisted_values('_supcheckout_disable_subscription'));
        self::assertSame(1, $product->saves);
        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
    }

    // --- Billing-attempts table. ---

    public function test_the_table_phase_copies_every_row_and_verifies_counts_and_checksums(): void {
        for ($i = 1; $i <= 1200; $i++) {
            $this->store->add_legacy_row(self::row($i, 'resolved'));
        }
        $killed = false;
        $this->store->on_table_insert = static function ($after) use (&$killed) {
            if ($after !== '' && !$killed) {
                $killed = true;
                throw new \RuntimeException('process killed');
            }
        };

        try {
            $this->run_to_end();
            self::fail('the second table batch must die');
        } catch (\RuntimeException $e) {
            self::assertSame('process killed', $e->getMessage());
        }
        $statuses = $this->run_to_end();

        self::assertSame('verified', end($statuses));
        self::assertSame(1, $this->store->table_creates, 'the new table is created once');
        $legacy = $this->store->tables['legacy'];
        $new = $this->store->tables['new'];
        ksort($legacy, SORT_STRING);
        ksort($new, SORT_STRING);
        self::assertSame($legacy, $new, 'every row, every column');
        $keys = array_keys($legacy);
        self::assertSame((string) end($keys), TakeoverState::get()['max_copied_key']);
        foreach ($this->store->selections as $selection) {
            if (strpos($selection[0], 'table_') === 0) {
                self::assertLessThanOrEqual(500, $selection[1]);
            }
        }
    }

    public function test_a_changed_row_fails_the_checksum_verification_and_a_rerun_refreshes_the_own_copy(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->store->add_legacy_row(self::row($i, 'claimed'));
        }
        // Copy the rows, then a live claim moves on in the legacy table before the final verify.
        $this->run_until_phase('table_verify');
        $this->store->tables['legacy'][self::key(2)]['state'] = 'dispatching';

        $statuses = $this->run_to_end();

        self::assertSame('mismatch', end($statuses));
        self::assertSame(array('count' => 1, 'keys' => array('checksum')), TakeoverState::get()['mismatches']['table']);

        self::assertTrue(CopyRunner::begin());
        $statuses = $this->run_to_end();
        self::assertSame('verified', end($statuses));
        self::assertSame('dispatching', $this->store->tables['new'][self::key(2)]['state']);
    }

    public function test_a_row_tampered_in_the_new_table_is_detected(): void {
        for ($i = 1; $i <= 2; $i++) {
            $this->store->add_legacy_row(self::row($i, 'resolved'));
        }
        $this->run_until_phase('table_verify');
        $this->store->tables['new'][self::key(1)]['payment_id'] = null;
        $this->store->tables['new'][self::key(9)] = self::row(9, 'resolved');

        $this->run_to_end();

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array('count' => 3, 'keys' => array('checksum', 'row_count')), TakeoverState::get()['mismatches']['table'], 'the changed row, the extra row and the count');
    }

    public function test_a_legacy_row_released_after_its_copy_leaves_no_orphan_after_a_rerun(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->store->add_legacy_row(self::row($i, 'claimed'));
        }
        $this->run_until_phase('table_verify');
        // CycleClaim::release_claimed() deletes a stale `claimed` row from the legacy table.
        unset($this->store->tables['legacy'][self::key(2)]);

        $statuses = $this->run_to_end();
        self::assertSame('mismatch', end($statuses), 'the orphan is detected');

        self::assertTrue(CopyRunner::begin());
        $statuses = $this->run_to_end();
        self::assertSame('verified', end($statuses));
        self::assertArrayNotHasKey(self::key(2), $this->store->tables['new'], 'the orphan copy is deleted');
        self::assertCount(2, $this->store->tables['new']);
    }

    public function test_orphans_in_the_new_table_are_removed_over_the_copied_range_and_the_open_tail(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->store->add_legacy_row(self::row($i, 'resolved'));
        }
        $this->store->new_table_exists = true;
        foreach (array(7, 8, 9) as $i) {
            $this->store->tables['new'][self::key($i)] = self::row($i, 'claimed');
        }

        $statuses = $this->run_to_end();

        self::assertSame('verified', end($statuses));
        $legacy = $this->store->tables['legacy'];
        $new = $this->store->tables['new'];
        ksort($legacy, SORT_STRING);
        ksort($new, SORT_STRING);
        self::assertSame($legacy, $new);
        self::assertNotSame(array(), $this->store->orphan_deletes);
        self::assertNull(end($this->store->orphan_deletes)[1], 'the last batch also clears the open tail');
    }

    public function test_max_copied_key_only_advances_on_a_successful_batch(): void {
        $this->store->add_legacy_row(self::row(1, 'resolved'));
        $this->store->new_table_exists = true;
        $this->store->tables['new'][self::key(1)] = self::row(1, 'claimed');
        $this->store->fail_replace = true;

        $this->run_to_end();

        self::assertSame('', TakeoverState::get()['max_copied_key'], 'a failed batch records nothing');
        self::assertSame(array('table_unreadable'), TakeoverState::get()['mismatches']['table']['keys']);
    }

    public function test_no_legacy_table_means_nothing_to_copy(): void {
        $this->store->legacy_table_exists = false;

        $this->run_to_end();

        self::assertSame(TakeoverState::VERIFIED, TakeoverState::get()['status']);
        self::assertSame(0, $this->store->table_creates);
    }

    public function test_a_legacy_column_missing_from_the_new_table_blocks_verification(): void {
        $this->store->add_legacy_row(self::row(1, 'resolved'));
        $this->store->new_columns = array('cycle_key', 'parent_order_id', 'owner_token', 'state');

        $this->run_to_end();

        self::assertSame(TakeoverState::PREFLIGHT_OK, TakeoverState::get()['status']);
        self::assertSame(array('schema_mismatch'), TakeoverState::get()['mismatches']['table']['keys']);
        self::assertSame(array(), $this->store->tables['new']);
    }

    /** @return list<string> Every status returned until the runner stops continuing. */
    private function run_to_end(): array {
        $statuses = array();
        for ($i = 0; $i < 200; $i++) {
            $status = $this->runner()->run();
            $statuses[] = $status;
            if ($status !== 'continue') {
                return $statuses;
            }
        }
        self::fail('the runner did not finish within 200 batches');
    }

    private function run_until_phase(string $phase): void {
        for ($i = 0; $i < 200 && (TakeoverState::get()['phase'] ?? '') !== $phase; $i++) {
            self::assertSame('continue', $this->runner()->run());
        }
        self::assertSame($phase, TakeoverState::get()['phase']);
    }

    private function runner(): CopyRunner {
        return new CopyRunner($this->store, function () {
            return $this->now;
        });
    }

    /** @param array<string, list<mixed>> $meta */
    private static function order(int $id, array $meta, string $method = 'upayments'): FakeCrudObject {
        $order = new FakeCrudObject($id, $method, $meta);
        $GLOBALS['supcheckout_test_status_orders'][$id] = $order;
        return $order;
    }

    /** @return array<string, mixed> */
    private static function record(string $scope, string $token): array {
        return array('version' => 1, 'kind' => 'canonical', 'token' => $token, 'scope' => $scope, 'secret_generation_id' => str_repeat('c', 32));
    }

    private static function key(int $i): string {
        return hash('sha256', 'cycle-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    }

    /** @return array<string, mixed> */
    private static function row(int $i, string $state): array {
        return array('cycle_key' => self::key($i), 'parent_order_id' => $i, 'owner_token' => str_repeat('f', 32), 'state' => $state, 'payment_id' => $state === 'resolved' ? 'pay-' . $i : null);
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }
}
