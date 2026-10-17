<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 7: while the takeover lease is live, no billing-cycle claim starts, and a claim
 * that raced the lease is released before any provider POST (it is still `claimed`).
 * Each test runs in its own process: CycleClaim needs current_time(), which the unit
 * bootstrap does not define.
 */
final class QuiesceClaimsTest extends TestCase {
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_claim_starts_while_the_lease_is_live(): void {
        $db = self::boot();
        Quiesce::acquire();

        self::assertFalse(CycleClaim::acquire(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00'));
        self::assertFalse(CycleClaim::acquire_with_snapshot(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00', '10.000', 'KWD'));
        $db->rows[self::KEY] = self::row('claimed');
        self::assertFalse(CycleClaim::reclaim_stale_claimed(self::KEY, 10, self::OWNER));
        self::assertSame(array(), $db->log, 'no claim SQL is issued at all');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_claim_that_raced_the_lease_is_released_again(): void {
        foreach (array('acquire', 'acquire_with_snapshot') as $method) {
            $db = self::boot();
            $db->on_insert = static function () {
                Quiesce::acquire();
            };
            $won = $method === 'acquire'
                ? CycleClaim::acquire(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00')
                : CycleClaim::acquire_with_snapshot(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00', '10.000', 'KWD');
            self::assertFalse($won, $method);
            self::assertSame(array('insert', 'delete'), $db->log, $method . ': inserted, then released');
            self::assertSame(array(), $db->rows, $method . ': the raced claim is gone, so a later claim can start');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_reclaim_that_raced_the_lease_is_released_again(): void {
        $db = self::boot();
        $db->rows[self::KEY] = self::row('claimed');
        $db->on_update = static function () {
            Quiesce::acquire();
        };
        self::assertFalse(CycleClaim::reclaim_stale_claimed(self::KEY, 10, self::OWNER));
        self::assertSame(array('update', 'delete'), $db->log);
        self::assertSame(array(), $db->rows);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_claims_work_unchanged_without_a_lease(): void {
        $db = self::boot();
        self::assertTrue(CycleClaim::acquire(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00'));
        self::assertSame(array('insert'), $db->log);
        self::assertSame('claimed', $db->rows[self::KEY]['state']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_claim_rereads_a_cutover_past_the_option_cache(): void {
        $db = self::boot();
        self::assertFalse(TakeoverState::is_cut_over());
        // A long-running worker's object cache still holds the old record.
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        $GLOBALS['supcheckout_test_cache_deletes'] = array();
        CycleClaim::acquire(self::KEY, 10, self::OWNER, '2026-10-17 00:00:00');
        self::assertContains(TakeoverState::OPTION, array_map(static function ($call) {
            return is_array($call) ? $call[0] : $call;
        }, $GLOBALS['supcheckout_test_cache_deletes']), 'the claim flushes the cached takeover state');
        self::assertSame('wp_supcheckout_billing_attempts', $db->tables[0]);
    }

    /** @return object The fake $wpdb. */
    private static function boot() {
        if (!function_exists('current_time')) {
            eval('function current_time($type, $gmt = 0) { return "2026-10-17 00:00:00"; }');
        }
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
        $GLOBALS['wpdb'] = new class() {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            /** @var array<string, array<string, mixed>> */
            public $rows = array();
            /** @var list<string> */
            public $log = array();
            /** @var list<string> */
            public $tables = array();
            /** @var callable|null */
            public $on_insert = null;
            /** @var callable|null */
            public $on_update = null;
            public function esc_like($text) {
                return addcslashes((string) $text, '_%\\');
            }
            public function prepare($query, ...$args) {
                if (strpos((string) $query, $this->options) !== false) {
                    // OrderLock's option primitives use the shared option-store fake.
                    return (new \SUPCheckout_Test_WPDB())->prepare($query, ...$args);
                }
                return array('query' => (string) $query, 'args' => $args);
            }
            public function query($prepared) {
                if (!is_array($prepared)) {
                    return (new \SUPCheckout_Test_WPDB())->query($prepared);
                }
                $query = $prepared['query'];
                $args = $prepared['args'];
                $this->tables[] = (string) $args[0];
                if (strpos($query, 'INSERT IGNORE') === 0) {
                    $this->log[] = 'insert';
                    if (is_callable($this->on_insert)) {
                        call_user_func($this->on_insert);
                    }
                    if (isset($this->rows[$args[1]])) {
                        return 0;
                    }
                    $this->rows[$args[1]] = array('cycle_key' => $args[1], 'parent_order_id' => $args[2], 'owner_token' => $args[3], 'state' => $args[4],
                        'expected_amount' => isset($args[8]) ? $args[8] : null, 'expected_currency' => isset($args[9]) ? $args[9] : null);
                    return 1;
                }
                if (strpos($query, 'UPDATE') === 0) {
                    $this->log[] = 'update';
                    if (is_callable($this->on_update)) {
                        call_user_func($this->on_update);
                    }
                    $key = $args[3];
                    if (!isset($this->rows[$key]) || $this->rows[$key]['state'] !== 'claimed') {
                        return 0;
                    }
                    $this->rows[$key]['owner_token'] = $args[1];
                    return 1;
                }
                if (strpos($query, 'DELETE FROM') === 0) {
                    $this->log[] = 'delete';
                    $key = $args[1];
                    if (!isset($this->rows[$key]) || $this->rows[$key]['owner_token'] !== $args[2] || $this->rows[$key]['state'] !== 'claimed') {
                        return 0;
                    }
                    unset($this->rows[$key]);
                    return 1;
                }
                return false;
            }
            public function get_row($prepared, $output = null) {
                $key = $prepared['args'][1];
                return isset($this->rows[$key]) ? $this->rows[$key] : null;
            }
        };
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        return $GLOBALS['wpdb'];
    }

    /** @return array<string, mixed> */
    private static function row(string $state): array {
        return array('cycle_key' => self::KEY, 'parent_order_id' => 10, 'owner_token' => 'cccccccccccccccccccccccccccccccc', 'state' => $state);
    }
}
