<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\OrderCopier;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 5: copy and verify one order (Review Focus 1 and 2).
 *
 * The order is a WooCommerce-shaped CRUD fake whose get_meta($key, false) returns
 * WC_Meta_Data-shaped objects; wc_get_order() (the unit bootstrap's store) returns the
 * stored instance, which is what the copier re-reads under the per-order lock.
 */
final class OrderCopierTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        self::state('copying');
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_public_order_status();
        TakeoverState::flush_cache();
    }

    public function test_multi_valued_public_status_is_copied_in_full(): void {
        $order = self::stored(new FakeCrudObject(11, 'upayments', array('UPayments_WHS' => array('failed', 'completed'))));

        $result = OrderCopier::copy($order);

        self::assertSame('copied', $result['status']);
        self::assertSame(1, $result['copied']);
        self::assertSame(array('failed', 'completed'), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array('failed', 'completed'), $order->persisted_values('UPayments_WHS'), 'legacy rows stay');
        self::assertTrue(OrderCopier::verify($order)['ok']);

        OrderCopier::copy($order);
        self::assertSame(array('failed', 'completed'), $order->persisted_values('_supcheckout_public_status'), 'no row added or dropped');
    }

    public function test_the_copied_value_is_the_original_scalar_never_the_meta_object(): void {
        $order = self::stored(new FakeCrudObject(12, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));

        OrderCopier::copy($order);

        self::assertSame(array('pay-1'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertFalse($order->object_value_written, 'a WC_Meta_Data object is never persisted');
        foreach ($order->reads as $read) {
            self::assertSame('edit', $read[1], 'raw values: no view-context filters');
        }
    }

    public function test_every_registered_order_key_is_copied_and_verified(): void {
        $meta = array();
        foreach (array_keys(Keys::order_meta()) as $i => $legacy) {
            $meta[$legacy] = array('v-' . $i);
        }
        $meta['_simplixpay_upayments_provider_result_v1'] = array(array('status' => 'CAPTURED', 'nested' => array('a' => 1)));
        $order = self::stored(new FakeCrudObject(13, 'upayments', $meta));

        $result = OrderCopier::copy($order);

        self::assertSame(count(Keys::order_meta()), $result['copied']);
        foreach (Keys::order_meta() as $legacy => $new) {
            self::assertSame($order->persisted_values($legacy), $order->persisted_values($new), $legacy);
        }
        self::assertSame(array('ok' => true, 'mismatches' => array()), OrderCopier::verify($order));
    }

    public function test_copy_is_idempotent_and_saves_once(): void {
        $order = self::stored(new FakeCrudObject(14, 'upayments', array('UPayments_PaymentID' => array('pay-1'), 'UPayments_WHS' => array('captured'))));

        $first = OrderCopier::copy($order);
        $snapshot = array($order->persisted_values('_supcheckout_payment_id'), $order->persisted_values('_supcheckout_public_status'));
        $second = OrderCopier::copy($order);

        self::assertSame(2, $first['copied']);
        self::assertSame(0, $second['copied']);
        self::assertSame(2, $second['skipped']);
        self::assertSame(1, $order->saves, 'one save() for the copy, none for the identical re-run');
        self::assertSame($snapshot, array($order->persisted_values('_supcheckout_payment_id'), $order->persisted_values('_supcheckout_public_status')));
    }

    public function test_an_order_without_legacy_data_is_not_saved(): void {
        $order = self::stored(new FakeCrudObject(15, 'upayments', array()));

        $result = OrderCopier::copy($order);

        self::assertSame('copied', $result['status']);
        self::assertSame(0, $result['copied']);
        self::assertSame(0, $order->saves);
        self::assertSame(array(), $order->writes);
    }

    public function test_outside_copying_a_foreign_differing_new_value_is_reported_and_never_overwritten(): void {
        self::state('cut_over');
        $order = self::stored(new FakeCrudObject(16, 'upayments', array(
            'UPayments_PaymentID' => array('pay-secret-legacy'),
            '_supcheckout_payment_id' => array('pay-secret-foreign'),
            'UPayments_TrackID' => array('track-1'),
        )));

        $result = OrderCopier::copy($order);

        self::assertSame(array('UPayments_PaymentID'), $result['mismatches']);
        self::assertSame(array('pay-secret-foreign'), $order->persisted_values('_supcheckout_payment_id'), 'the foreign value stays');
        self::assertSame(array('track-1'), $order->persisted_values('_supcheckout_track_id'), 'other keys are still copied');
        $verify = OrderCopier::verify($order);
        self::assertSame(array('ok' => false, 'mismatches' => array('UPayments_PaymentID')), $verify);
        self::assertStringNotContainsString('pay-secret', (string) json_encode(array($result, $verify)), 'key names only, never values');
    }

    public function test_the_copier_may_refresh_its_own_copy_when_the_legacy_value_changes(): void {
        $order = self::stored(new FakeCrudObject(17, 'upayments', array('UPayments_WHS' => array('pending'), 'UPayments_PaymentID' => array('pay-1'))));
        OrderCopier::copy($order);

        // The official plugin (still active in copying) writes the legacy names only.
        $order->external_write('UPayments_WHS', array('pending', 'captured'));
        $order->external_write('UPayments_PaymentID', array());
        $result = OrderCopier::copy($order);

        self::assertSame(2, $result['copied']);
        self::assertSame(array(), $result['mismatches']);
        self::assertSame(array('pending', 'captured'), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'), 'its own copy follows a removed legacy value');
        self::assertTrue(OrderCopier::verify($order)['ok']);
    }

    public function test_outside_copying_a_copy_edited_by_someone_else_is_no_longer_the_copiers_own(): void {
        $order = self::stored(new FakeCrudObject(18, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        OrderCopier::copy($order);
        self::state('verified');

        $order->external_write('_supcheckout_payment_id', array('pay-other'));
        $order->external_write('UPayments_PaymentID', array('pay-2'));
        $result = OrderCopier::copy($order);

        self::assertSame(array('UPayments_PaymentID'), $result['mismatches']);
        self::assertSame(array('pay-other'), $order->persisted_values('_supcheckout_payment_id'));
    }

    public function test_outside_copying_an_unreadable_marker_claims_nothing(): void {
        $order = self::stored(new FakeCrudObject(19, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        OrderCopier::copy($order);
        self::state('preflight_ok');
        $order->external_write(OrderCopier::MARKER_META, array('{not json'));
        $order->external_write('UPayments_PaymentID', array('pay-2'));

        $result = OrderCopier::copy($order);

        self::assertSame(array('UPayments_PaymentID'), $result['mismatches'], 'fail closed: no own copy is assumed');
        self::assertSame(array('pay-1'), $order->persisted_values('_supcheckout_payment_id'));
    }

    // --- While copying, legacy is authoritative: the new key is set to the legacy list. ---

    public function test_while_copying_an_extra_row_from_a_stale_unlocked_writer_self_heals(): void {
        $order = self::stored(new FakeCrudObject(40, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        OrderCopier::copy($order);
        // A stale object without the new key (Scheduler, SubscriptionActions: no order lock)
        // updates the legacy row and INSERTs a second new-key row.
        $order->external_write('UPayments_PaymentID', array('pay-2'));
        $order->external_write('_supcheckout_payment_id', array('pay-1', 'pay-2'));
        self::assertFalse(OrderCopier::verify($order)['ok']);

        $result = OrderCopier::copy($order);

        self::assertSame(array(), $result['mismatches']);
        self::assertSame(array('pay-2'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertTrue(OrderCopier::verify($order)['ok']);
    }

    public function test_while_copying_a_partially_saved_multi_row_list_self_heals(): void {
        $order = self::stored(new FakeCrudObject(41, 'upayments', array(
            'UPayments_WHS' => array('pending', 'failed', 'captured'),
            '_supcheckout_public_status' => array('pending', 'failed'),
        )));

        $result = OrderCopier::copy($order);

        self::assertSame(array(), $result['mismatches']);
        self::assertSame(array('pending', 'failed', 'captured'), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array('pending', 'failed', 'captured'), $order->persisted_values('UPayments_WHS'), 'legacy untouched');
    }

    public function test_while_copying_a_value_written_by_an_unlocked_writer_between_read_and_save_converges_on_rerun(): void {
        $order = self::stored(new FakeCrudObject(42, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        // The unlocked writer lands its legacy-only write after the copier read the order.
        $order->on_save = static function (FakeCrudObject $saved) {
            $saved->on_save = null;
            $saved->external_write('UPayments_PaymentID', array('pay-late'));
        };

        OrderCopier::copy($order);
        self::assertSame(array('pay-1'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertFalse(OrderCopier::verify($order)['ok'], 'the late write is not in the copy yet');
        OrderCopier::copy($order);

        self::assertSame(array('pay-late'), $order->persisted_values('_supcheckout_payment_id'));
        self::assertTrue(OrderCopier::verify($order)['ok']);
    }

    public function test_while_copying_the_marker_still_records_what_was_copied(): void {
        $order = self::stored(new FakeCrudObject(43, 'upayments', array('UPayments_PaymentID' => array('pay-1'), '_supcheckout_payment_id' => array('pay-x'))));

        OrderCopier::copy($order);

        $marker = json_decode((string) $order->persisted_values(OrderCopier::MARKER_META)[0], true);
        self::assertSame(array('UPayments_PaymentID'), array_keys($marker));
        self::assertSame(OrderCopier::MARKER_META, \Simplixi\SUPCheckout\Takeover\Keys::TAKEOVER_COPY_MARKER, 'the marker name comes from Keys');
    }

    public function test_an_unwrappable_row_still_fails_closed_while_copying(): void {
        $order = self::stored(new FakeCrudObject(44, 'upayments', array('UPayments_TrackID' => array('t-1'))));
        $order->inject_unwrappable_row('UPayments_PaymentID', 'pay-raw');

        $result = OrderCopier::copy($order);

        self::assertSame(array('UPayments_PaymentID'), $result['mismatches']);
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array('t-1'), $order->persisted_values('_supcheckout_track_id'));
    }

    public function test_hpos_disagreement_copies_the_crud_value_and_reports_postmeta_only_data(): void {
        $order = self::stored(new FakeCrudObject(20, 'upayments', array('UPayments_PaymentID' => array('pay-crud'))));
        $post_meta = static function ($id, $key) {
            $postmeta = array('UPayments_PaymentID' => array('pay-post'), 'UPayments_TrackID' => array('track-post-only'));
            return isset($postmeta[$key]) ? $postmeta[$key] : array();
        };

        $result = OrderCopier::copy($order, $post_meta);

        self::assertSame(array('pay-crud'), $order->persisted_values('_supcheckout_payment_id'), 'CRUD is the source of truth');
        self::assertSame(array(), $order->persisted_values('_supcheckout_track_id'), 'an empty CRUD value is never copied');
        self::assertSame(array('UPayments_TrackID'), $result['disagreements'], 'postmeta-only data is reported by key name');
        self::assertStringNotContainsString('track-post-only', (string) json_encode($result));
    }

    public function test_lock_contention_defers_the_copy_and_writes_nothing(): void {
        $order = self::stored(new FakeCrudObject(21, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        $runtime = OrderLock::acquire(21);
        self::assertIsString($runtime, 'a runtime worker holds the order lock');

        $result = OrderCopier::copy($order);

        self::assertSame('locked', $result['status']);
        self::assertSame(array(), $order->writes);
        self::assertSame(0, $order->saves);

        OrderLock::release(21, $runtime);
        self::assertSame('copied', OrderCopier::copy($order)['status']);
        self::assertSame(array(), self::lock_rows(), 'the copier releases every lock name');
    }

    public function test_the_copy_holds_the_order_lock_under_both_window_names(): void {
        $order = self::stored(new FakeCrudObject(22, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));
        $held = array();
        $order_id = 22;
        $GLOBALS['wpdb']->before_query = static function () use (&$held) {
            $held[] = self::lock_rows();
        };

        OrderCopier::copy($order);

        $GLOBALS['wpdb']->before_query = null;
        self::assertContains(array('simplixpay_upay_order_lock_v1_' . $order_id, 'supcheckout_order_lock_v1_' . $order_id), $held, 'both names are held while copying');
        self::assertSame(array(), self::lock_rows());
    }

    public function test_the_copy_rereads_the_order_under_the_lock(): void {
        $stale = new FakeCrudObject(23, 'upayments', array('UPayments_PaymentID' => array('pay-old')));
        // A runtime write in the window landed under both names after $stale was loaded.
        $fresh = self::stored(new FakeCrudObject(23, 'upayments', array('UPayments_PaymentID' => array('pay-new'), '_supcheckout_payment_id' => array('pay-new'))));

        $result = OrderCopier::copy($stale);

        self::assertSame(0, $result['copied']);
        self::assertSame(array('pay-new'), $fresh->persisted_values('_supcheckout_payment_id'), 'the newer value is never overwritten');
        self::assertSame(array(), $stale->writes, 'the stale object is never written');
    }

    public function test_a_missing_order_is_reported(): void {
        $result = OrderCopier::copy(new FakeCrudObject(24, 'upayments', array('UPayments_PaymentID' => array('pay-1'))));

        self::assertSame('missing', $result['status']);
        self::assertSame(array(), self::lock_rows());
    }

    public function test_payment_method_and_payment_state_are_never_touched(): void {
        $order = self::stored(new FakeCrudObject(25, 'upayments', array('UPayments_PaymentID' => array('pay-1'), 'UPayments_WHS' => array('captured')), 'processing'));
        $before = array();
        foreach (array_keys(Keys::order_meta()) as $legacy) {
            $before[$legacy] = $order->persisted_values($legacy);
        }

        OrderCopier::copy($order);

        self::assertSame('upayments', $order->get_payment_method());
        self::assertSame('processing', $order->get_status());
        self::assertSame(array(), $order->field_writes, 'no setter outside meta is called');
        $allowed = array_merge(array_values(Keys::order_meta()), array(OrderCopier::MARKER_META));
        self::assertSame(array(), array_values(array_diff($order->written_keys(), $allowed)), 'only new keys and the copy marker are written');
        foreach ($before as $legacy => $values) {
            self::assertSame($values, $order->persisted_values($legacy), "$legacy unchanged");
        }
    }

    public function test_a_failed_save_leaves_nothing_half_copied_and_releases_the_lock(): void {
        $order = self::stored(new FakeCrudObject(26, 'upayments', array('UPayments_PaymentID' => array('pay-1'), 'UPayments_WHS' => array('a', 'b'))));
        $order->fail_next_save = true;

        $result = OrderCopier::copy($order);

        self::assertSame('failed', $result['status']);
        self::assertSame(array(), $order->persisted_values('_supcheckout_payment_id'));
        self::assertSame(array(), $order->persisted_values('_supcheckout_public_status'));
        self::assertSame(array(), self::lock_rows());

        self::assertSame('copied', OrderCopier::copy($order)['status']);
        self::assertTrue(OrderCopier::verify($order)['ok']);
    }

    public function test_verify_compares_full_value_lists_in_order(): void {
        $order = new FakeCrudObject(27, 'upayments', array(
            'UPayments_WHS' => array('failed', 'completed'),
            '_supcheckout_public_status' => array('completed', 'failed'),
            'UPayments_TrackID' => array('t'),
        ));

        self::assertSame(array('ok' => false, 'mismatches' => array('UPayments_TrackID', 'UPayments_WHS')), OrderCopier::verify($order));
    }

    private static function stored(FakeCrudObject $order): FakeCrudObject {
        $GLOBALS['supcheckout_test_status_orders'][$order->get_id()] = $order;
        return $order;
    }

    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }

    /** @return list<string> */
    private static function lock_rows(): array {
        $rows = array();
        foreach (array_keys($GLOBALS['supcheckout_test_options']) as $name) {
            if (strpos($name, 'order_lock_v1_') !== false) {
                $rows[] = $name;
            }
        }
        sort($rows);
        return $rows;
    }
}
