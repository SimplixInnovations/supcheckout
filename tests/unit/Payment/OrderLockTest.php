<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\OrderLock;

final class OrderLockTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_logged'] = array();
        if (!function_exists('wc_get_logger')) {
            eval('function wc_get_logger() { return new class() {
                public function __call($level, $args) { $GLOBALS["supcheckout_test_logged"][] = $args[0]; }
            }; }');
        }
    }

    public function test_invalid_order_identity_fails_without_mutation(): void {
        self::assertNull(OrderLock::acquire(0));
        self::assertNull(OrderLock::acquire(-1));
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }

    public function test_atomic_acquire_blocks_a_second_live_owner(): void {
        $token = OrderLock::acquire(42);

        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        self::assertNull(OrderLock::acquire(42));
        self::assertArrayHasKey('simplixpay_upay_order_lock_v1_42', $GLOBALS['supcheckout_test_options']);
    }

    public function test_acquire_loses_to_a_live_owner_created_just_before_its_insert(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $competitor = (string) (time() + 45) . ':' . str_repeat('c', 32);
        $GLOBALS['wpdb']->before_query = function ($statement) use ($name, $competitor) {
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0) {
                $GLOBALS['supcheckout_test_options'][$name] = $competitor;
            }
        };

        self::assertNull(OrderLock::acquire(42));
        self::assertSame($competitor, $GLOBALS['supcheckout_test_options'][$name]);
        self::assertNotContains('add', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
    }

    public function test_release_requires_the_exact_owner_token(): void {
        $token = OrderLock::acquire(42);
        $name = 'simplixpay_upay_order_lock_v1_42';

        OrderLock::release(42, 'wrong-token');
        self::assertArrayHasKey($name, $GLOBALS['supcheckout_test_options']);

        OrderLock::release(42, $token);
        self::assertArrayNotHasKey($name, $GLOBALS['supcheckout_test_options']);
        self::assertContains(array($name, 'options'), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    public function test_expired_record_is_replaced_only_by_compare_and_swap(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = '1:' . str_repeat('a', 32);

        $token = OrderLock::acquire(42);

        self::assertIsString($token);
        self::assertStringEndsWith(':' . $token, $GLOBALS['supcheckout_test_options'][$name]);
        self::assertContains(array($name, 'options'), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    public function test_stale_takeover_cannot_replace_a_newer_competing_owner(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $newer = (string) (time() + 120) . ':' . str_repeat('b', 32);
        $GLOBALS['supcheckout_test_options'][$name] = '1:' . str_repeat('a', 32);
        $GLOBALS['wpdb']->before_query = function () use ($name, $newer) {
            $GLOBALS['supcheckout_test_options'][$name] = $newer;
        };

        self::assertNull(OrderLock::acquire(42));
        self::assertSame($newer, $GLOBALS['supcheckout_test_options'][$name]);
        self::assertSame(array(), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    /**
     * OL-1. Formerly "malformed existing record fails closed without deletion": that
     * pinned the defect, because no code path ever writes, honours or releases an
     * unparseable record, so it held the order's payment lifecycle forever.
     */
    public function test_an_unparseable_record_is_recovered_by_compare_and_swap(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = 'malformed';
        $updates = $this->record_conditional_updates();

        $token = OrderLock::acquire(42);

        self::assertIsString($token);
        self::assertStringEndsWith(':' . $token, $GLOBALS['supcheckout_test_options'][$name]);
        self::assertCount(1, $updates->queries);
        self::assertSame('malformed', $updates->queries[0]['args'][2], 'the swap is conditional on the exact unparseable value read');
        self::assertSame(array('Order lock: recovered an unparseable lock record.'), $GLOBALS['supcheckout_test_logged']);

        OrderLock::release(42, $token);
        self::assertArrayNotHasKey($name, $GLOBALS['supcheckout_test_options']);
    }

    public function test_every_unparseable_shape_is_recoverable(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $shapes = array(
            'empty'          => '',
            'zero expiry'    => '0:' . str_repeat('a', 32),
            'short token'    => (string) (time() + 45) . ':abc',
            'upper token'    => (string) (time() + 45) . ':' . str_repeat('A', 32),
            'no separator'   => str_repeat('a', 32),
            'released mark'  => '0:released',
            'serialized row' => array('expires' => time() + 45),
        );
        foreach ($shapes as $label => $value) {
            \supcheckout_test_reset_wp_options();
            $GLOBALS['supcheckout_test_logged'] = array();
            $GLOBALS['supcheckout_test_options'][$name] = $value;

            $token = OrderLock::acquire(42);

            self::assertIsString($token, $label);
            self::assertStringEndsWith(':' . $token, $GLOBALS['supcheckout_test_options'][$name], $label);
            self::assertSame(array('Order lock: recovered an unparseable lock record.'), $GLOBALS['supcheckout_test_logged'], $label);
        }
    }

    public function test_a_record_expiring_far_beyond_the_bound_is_recovered(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        // acquire() never writes an expiry beyond now + 45 s, so an expiry more than an
        // hour past that can only come from a skewed clock or a corrupt write.
        foreach (array('999999999999', (string) (time() + 45 + 3600 + 120)) as $expires) {
            \supcheckout_test_reset_wp_options();
            $GLOBALS['supcheckout_test_logged'] = array();
            $stale = $expires . ':' . str_repeat('d', 32);
            $GLOBALS['supcheckout_test_options'][$name] = $stale;
            $updates = $this->record_conditional_updates();

            $token = OrderLock::acquire(42);

            self::assertIsString($token, $expires);
            self::assertStringEndsWith(':' . $token, $GLOBALS['supcheckout_test_options'][$name], $expires);
            self::assertSame($stale, $updates->queries[0]['args'][2], $expires);
            self::assertSame(array('Order lock: recovered a lock record with an implausible expiry.'), $GLOBALS['supcheckout_test_logged'], $expires);
        }
    }

    public function test_a_live_record_is_never_stolen(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        foreach (array(time() + 45, time() + 1, time() + 120) as $expires) {
            \supcheckout_test_reset_wp_options();
            $GLOBALS['supcheckout_test_logged'] = array();
            $live = (string) $expires . ':' . str_repeat('e', 32);
            $GLOBALS['supcheckout_test_options'][$name] = $live;
            $updates = $this->record_conditional_updates();

            self::assertNull(OrderLock::acquire(42), (string) $expires);
            self::assertSame($live, $GLOBALS['supcheckout_test_options'][$name], (string) $expires);
            self::assertSame(array(), $updates->queries, 'no swap is even attempted');
            self::assertSame(array(), $GLOBALS['supcheckout_test_logged']);
        }
    }

    public function test_a_hold_just_inside_the_implausible_expiry_bound_is_not_stolen(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        // A skewed-clock holder up to an hour ahead is still treated as live.
        $held = (string) (time() + 45 + 3600) . ':' . str_repeat('f', 32);
        $GLOBALS['supcheckout_test_options'][$name] = $held;

        self::assertNull(OrderLock::acquire(42));
        self::assertSame($held, $GLOBALS['supcheckout_test_options'][$name]);
        self::assertSame(array(), $GLOBALS['supcheckout_test_logged']);
    }

    public function test_two_concurrent_recoverers_of_one_corrupt_record_have_exactly_one_winner(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = 'malformed';
        $second = array('token' => 'not-run');
        $interleaved = false;
        // Worker A has read the corrupt value; before its swap lands, worker B runs a
        // whole acquire() on the same value.
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$second, &$interleaved) {
            if ($interleaved || strpos($statement['query'], 'UPDATE ') !== 0) {
                return;
            }
            $interleaved = true;
            $second['token'] = OrderLock::acquire(42);
        };

        $first = OrderLock::acquire(42);

        self::assertTrue($interleaved);
        self::assertNull($first, 'the swap of the already-replaced value loses');
        self::assertIsString($second['token']);
        self::assertStringEndsWith(':' . $second['token'], $GLOBALS['supcheckout_test_options'][$name]);
        self::assertCount(1, $GLOBALS['supcheckout_test_logged'], 'only the winner logs a recovery');
    }

    public function test_an_ordinary_expired_takeover_is_logged_without_record_contents(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = '1:' . str_repeat('a', 32);

        self::assertIsString(OrderLock::acquire(42));
        self::assertSame(array('Order lock: recovered an expired lock record.'), $GLOBALS['supcheckout_test_logged']);
        self::assertStringNotContainsString(str_repeat('a', 32), implode('|', $GLOBALS['supcheckout_test_logged']));
    }

    public function test_a_record_released_between_insert_and_read_is_left_to_the_next_attempt(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = (string) (time() + 45) . ':' . str_repeat('c', 32);
        $GLOBALS['wpdb']->before_query = function ($statement) use ($name) {
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0) {
                $GLOBALS['supcheckout_test_get_option_filter'] = function () {
                    return null;
                };
            }
        };

        self::assertNull(OrderLock::acquire(42));
        self::assertSame(array(), $GLOBALS['supcheckout_test_logged']);
    }

    /**
     * Records every conditional UPDATE statement OrderLock sends.
     */
    private function record_conditional_updates(): object {
        $recorder = new \stdClass();
        $recorder->queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use ($recorder) {
            if (strpos($statement['query'], 'UPDATE ') === 0) {
                $recorder->queries[] = $statement;
            }
        };
        return $recorder;
    }

    public function test_conditional_sql_compares_the_stored_value_bytewise(): void {
        $name = 'simplixpay_upay_order_lock_v1_42';
        $GLOBALS['supcheckout_test_options'][$name] = '1:' . str_repeat('a', 32);
        $queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$queries) {
            $queries[] = $statement['query'];
        };

        $token = OrderLock::acquire(42);
        OrderLock::release(42, $token);

        $conditional = array_values(array_filter($queries, function ($query) {
            return strpos($query, 'UPDATE ') === 0 || strpos($query, 'DELETE ') === 0;
        }));
        self::assertCount(2, $conditional, 'stale recovery and release');
        foreach ($conditional as $query) {
            self::assertStringContainsString('WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $query);
        }
        self::assertArrayNotHasKey($name, $GLOBALS['supcheckout_test_options']);
    }

    public function test_a_case_or_padding_variant_of_the_expected_value_never_matches(): void {
        $GLOBALS['supcheckout_test_options']['supcheckout_cas_probe'] = '{"status":"copying"}';

        self::assertFalse(OrderLock::replace_if_current('supcheckout_cas_probe', '{"STATUS":"COPYING"}', 'lost-update'));
        self::assertFalse(OrderLock::replace_if_current('supcheckout_cas_probe', '{"status":"copying"} ', 'lost-update'));
        self::assertSame('{"status":"copying"}', $GLOBALS['supcheckout_test_options']['supcheckout_cas_probe']);

        self::assertTrue(OrderLock::replace_if_current('supcheckout_cas_probe', '{"status":"copying"}', '{"status":"verified"}'));
        self::assertSame('{"status":"verified"}', $GLOBALS['supcheckout_test_options']['supcheckout_cas_probe']);
    }

    public function test_the_public_compare_and_swap_fails_closed_on_invalid_input(): void {
        $GLOBALS['supcheckout_test_options']['supcheckout_cas_probe'] = 'a';

        self::assertFalse(OrderLock::replace_if_current('', 'a', 'b'));
        self::assertFalse(OrderLock::replace_if_current(array('supcheckout_cas_probe'), 'a', 'b'));
        self::assertFalse(OrderLock::replace_if_current('supcheckout_cas_probe', null, 'b'));
        self::assertFalse(OrderLock::replace_if_current('supcheckout_cas_probe', 'a', array('b')));
        self::assertSame('a', $GLOBALS['supcheckout_test_options']['supcheckout_cas_probe']);
    }
}
