<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;
use Simplixi\SUPCheckout\Takeover\StaleClaims;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Takeover\WordPressStaleClaimStore;

/**
 * 0b Task 9: the stale-claim SQL against a fake $wpdb that evaluates the WHERE clauses the
 * store and CycleClaim send, and the money-safety proof: once a stale `claimed` row is
 * released, its stalled worker's CycleClaim::mark_dispatching() matches nothing, so that
 * worker never reaches the provider POST; and a worker that wins mark_dispatching() first
 * is never released. Separate processes: CycleClaim needs current_time().
 */
final class WordPressStaleClaimStoreTest extends TestCase {
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const NOW = 1800000000;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_lists_and_releases_only_stale_claimed_rows_of_the_table_in_use(): void {
        $db = self::boot();
        $db->rows[self::KEY] = self::row('claimed', self::OWNER, self::NOW - 3600);
        $db->rows[str_repeat('c', 64)] = self::row('dispatching', 'o-c', self::NOW - 7200, str_repeat('c', 64));
        $db->rows[str_repeat('d', 64)] = self::row('claimed', 'o-d', self::NOW - 30, str_repeat('d', 64));

        $store = new WordPressStaleClaimStore();
        $before = gmdate('Y-m-d H:i:s', self::NOW - StaleClaims::STALE_SECONDS);
        $rows = $store->stale_claimed($before, 10);

        self::assertSame(array(array('cycle_key' => self::KEY, 'owner_token' => self::OWNER, 'updated_gmt' => gmdate('Y-m-d H:i:s', self::NOW - 3600))), $rows);
        self::assertSame('wp_upayments_billing_attempts', $db->last_table, 'the legacy table before cutover');
        self::assertTrue($store->release($rows[0], $before));
        self::assertArrayNotHasKey(self::KEY, $db->rows);
        self::assertSame(array(str_repeat('c', 64), str_repeat('d', 64)), array_keys($db->rows));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_released_claim_can_never_be_dispatched_by_its_stalled_worker(): void {
        $db = self::boot();
        $db->rows[self::KEY] = self::row('claimed', self::OWNER, self::NOW - 3600);
        $store = new WordPressStaleClaimStore();
        $before = gmdate('Y-m-d H:i:s', self::NOW - StaleClaims::STALE_SECONDS);

        self::assertTrue($store->release($store->stale_claimed($before, 10)[0], $before));

        // The stalled worker wakes up: mark_dispatching() is the gate before the POST.
        self::assertFalse(CycleClaim::mark_dispatching(self::KEY, self::OWNER), 'no row: the worker never reaches the provider POST');
        self::assertSame(array(), $db->rows, 'and nothing is recreated');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_worker_that_dispatches_first_is_never_released(): void {
        $db = self::boot();
        $db->rows[self::KEY] = self::row('claimed', self::OWNER, self::NOW - 3600);
        $store = new WordPressStaleClaimStore();
        $before = gmdate('Y-m-d H:i:s', self::NOW - StaleClaims::STALE_SECONDS);
        $listed = $store->stale_claimed($before, 10)[0];

        self::assertTrue(CycleClaim::mark_dispatching(self::KEY, self::OWNER), 'the worker wins the race and may POST');
        self::assertFalse($store->release($listed, $before), 'the CAS matches nothing');
        self::assertSame('dispatching', $db->rows[self::KEY]['state']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_reclaimed_row_is_never_released(): void {
        $db = self::boot();
        $db->rows[self::KEY] = self::row('claimed', self::OWNER, self::NOW - 3600);
        $store = new WordPressStaleClaimStore();
        $before = gmdate('Y-m-d H:i:s', self::NOW - StaleClaims::STALE_SECONDS);
        $listed = $store->stale_claimed($before, 10)[0];

        $db->rows[self::KEY]['owner_token'] = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $db->rows[self::KEY]['updated_gmt'] = '2026-10-17 00:00:00';

        self::assertFalse($store->release($listed, $before));
        self::assertArrayHasKey(self::KEY, $db->rows);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_the_new_table_is_used_and_a_missing_table_lists_nothing(): void {
        $db = self::boot();
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        TakeoverState::refresh();
        $store = new WordPressStaleClaimStore();
        $store->stale_claimed('2026-10-17 00:00:00', 10);
        self::assertSame('wp_supcheckout_billing_attempts', $db->last_table);

        $db->table_present = false;
        self::assertSame(array(), $store->stale_claimed('2026-10-17 00:00:00', 10));
    }

    /** @return object The fake $wpdb. */
    private static function boot() {
        if (!function_exists('current_time')) {
            eval('function current_time($type, $gmt = 0) { return "2026-10-17 00:00:00"; }');
        }
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
        $GLOBALS['wpdb'] = new class() {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            /** @var array<string, array<string, mixed>> */
            public $rows = array();
            /** @var string */
            public $last_table = '';
            /** @var bool */
            public $table_present = true;
            public function esc_like($text) {
                return addcslashes((string) $text, '_%\\');
            }
            public function prepare($query, ...$args) {
                if (strpos((string) $query, $this->options) !== false) {
                    return (new \SUPCheckout_Test_WPDB())->prepare($query, ...$args);
                }
                return array('query' => preg_replace('/\s+/', ' ', trim((string) $query)), 'args' => $args);
            }
            public function get_var($prepared) {
                if (!is_array($prepared)) {
                    return (new \SUPCheckout_Test_WPDB())->get_var($prepared);
                }
                // SHOW TABLES LIKE: the escaped name comes back when present.
                return $this->table_present ? stripslashes((string) $prepared['args'][0]) : null;
            }
            public function get_results($prepared, $output = null) {
                $args = $prepared['args'];
                $this->last_table = (string) $args[0];
                $out = array();
                foreach ($this->rows as $row) {
                    if ($row['state'] === $args[1] && strcmp($row['updated_gmt'], $args[2]) < 0) {
                        $out[] = array('cycle_key' => $row['cycle_key'], 'owner_token' => $row['owner_token'], 'updated_gmt' => $row['updated_gmt']);
                    }
                }
                usort($out, static function ($a, $b) {
                    return strcmp($a['updated_gmt'] . $a['cycle_key'], $b['updated_gmt'] . $b['cycle_key']);
                });
                return array_slice($out, 0, (int) $args[3]);
            }
            public function query($prepared) {
                if (!is_array($prepared)) {
                    return (new \SUPCheckout_Test_WPDB())->query($prepared);
                }
                $query = $prepared['query'];
                $args = $prepared['args'];
                $this->last_table = (string) $args[0];
                if (strpos($query, 'DELETE FROM %i WHERE cycle_key = %s AND state = %s AND owner_token = %s AND updated_gmt = %s AND updated_gmt < %s') === 0) {
                    $key = $args[1];
                    if (!isset($this->rows[$key]) || $this->rows[$key]['state'] !== $args[2] || $this->rows[$key]['owner_token'] !== $args[3]
                        || $this->rows[$key]['updated_gmt'] !== $args[4] || strcmp($this->rows[$key]['updated_gmt'], $args[5]) >= 0) {
                        return 0;
                    }
                    unset($this->rows[$key]);
                    return 1;
                }
                if (strpos($query, 'UPDATE %i SET state = %s, dispatched_gmt = %s, updated_gmt = %s WHERE cycle_key = %s AND owner_token = %s AND state = %s') === 0) {
                    $key = $args[4];
                    if (!isset($this->rows[$key]) || $this->rows[$key]['owner_token'] !== $args[5] || $this->rows[$key]['state'] !== $args[6]) {
                        return 0;
                    }
                    $this->rows[$key]['state'] = $args[1];
                    $this->rows[$key]['updated_gmt'] = $args[3];
                    return 1;
                }
                return false;
            }
        };
        return $GLOBALS['wpdb'];
    }

    /** @return array<string, mixed> */
    private static function row(string $state, string $owner, int $updated, string $key = self::KEY): array {
        return array('cycle_key' => $key, 'parent_order_id' => 10, 'owner_token' => $owner, 'state' => $state, 'updated_gmt' => gmdate('Y-m-d H:i:s', $updated));
    }
}
