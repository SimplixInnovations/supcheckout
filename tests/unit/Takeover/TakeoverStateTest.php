<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

final class TakeoverStateTest extends TestCase {
    private const OPTION = 'supcheckout_takeover_state_v1';
    private const DAY = 86400;

    /** @var list<string> */
    private const STATUSES = array('idle', 'preflight_ok', 'copying', 'verified', 'cut_over', 'finalized', 'rolled_back');

    /** @var array<string, list<string>> */
    private const ALLOWED = array(
        'idle' => array('preflight_ok'),
        'preflight_ok' => array('copying'),
        'copying' => array('verified', 'preflight_ok'),
        'verified' => array('cut_over', 'preflight_ok'),
        'cut_over' => array('rolled_back', 'finalized'),
        'finalized' => array(),
        'rolled_back' => array('preflight_ok'),
    );

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    /** @param array<string, mixed> $state */
    private static function seed(array $state): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode($state);
        TakeoverState::flush_cache();
    }

    /** @return array<string, mixed> */
    private static function stored(): array {
        $decoded = json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION], true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function test_only_the_documented_transitions_are_allowed(): void {
        self::assertSame('idle', TakeoverState::get()['status']);
        self::assertFalse(TakeoverState::transition('cut_over'), 'cannot skip preflight and copy');
        self::assertTrue(TakeoverState::transition('preflight_ok'));
        self::assertTrue(TakeoverState::transition('copying'));
        self::assertTrue(TakeoverState::transition('verified'));
        self::assertTrue(TakeoverState::transition('cut_over'));
        self::assertTrue(TakeoverState::is_cut_over());
        self::assertFalse(TakeoverState::transition('finalized'), 'finalize waits 30 days');
        self::assertTrue(TakeoverState::transition('rolled_back'));
        self::assertFalse(TakeoverState::is_cut_over());
    }

    public function test_the_allowed_map_is_exactly_the_documented_one(): void {
        foreach (self::STATUSES as $from) {
            foreach (self::STATUSES as $to) {
                \supcheckout_test_reset_wp_options();
                self::seed(array('status' => $from, 'cut_over_at' => time() - 31 * self::DAY, 'cursor' => 0, 'totals' => array(), 'rollback' => array('removal_pending' => false)));

                $expected = in_array($to, self::ALLOWED[$from], true);
                self::assertSame($expected, TakeoverState::transition($to), "{$from} -> {$to}");
                self::assertSame($expected ? $to : $from, TakeoverState::get()['status'], "{$from} -> {$to} stored status");
            }
        }
    }

    public function test_a_fresh_store_reads_as_idle_with_the_documented_shape(): void {
        $state = TakeoverState::get();

        self::assertSame('idle', $state['status']);
        self::assertSame(0, $state['cut_over_at']);
        self::assertSame(0, $state['cursor']);
        self::assertSame(array(), $state['totals']);
        self::assertFalse(TakeoverState::is_cut_over());
        self::assertArrayNotHasKey(self::OPTION, $GLOBALS['supcheckout_test_options'], 'reading writes nothing');
    }

    public function test_first_record_is_a_non_autoloaded_atomic_insert(): void {
        $queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$queries) {
            $queries[] = $statement;
        };

        self::assertTrue(TakeoverState::transition('preflight_ok'));

        self::assertCount(1, $queries);
        self::assertStringStartsWith('INSERT IGNORE ', $queries[0]['query']);
        self::assertSame(self::OPTION, $queries[0]['args'][0]);
        self::assertSame('no', $queries[0]['args'][2], 'the state option is never autoloaded');
        self::assertNotContains('add', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertNotContains('update', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertSame('preflight_ok', self::stored()['status']);
    }

    public function test_later_transitions_are_compare_and_swap_updates_that_flush_the_option_cache(): void {
        TakeoverState::transition('preflight_ok');
        $before = $GLOBALS['supcheckout_test_options'][self::OPTION];
        $queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$queries) {
            $queries[] = $statement;
        };

        self::assertTrue(TakeoverState::transition('copying'));

        self::assertCount(1, $queries);
        self::assertStringStartsWith('UPDATE ', $queries[0]['query']);
        self::assertStringContainsString('AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $queries[0]['query'], 'bytewise, not collation-equal');
        self::assertSame(self::OPTION, $queries[0]['args'][1]);
        self::assertSame($before, $queries[0]['args'][2], 'the swap is conditional on the value that was read');
        self::assertContains(array(self::OPTION, 'options'), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    public function test_unknown_statuses_are_rejected_without_a_write(): void {
        foreach (array('bogus', '', 'IDLE', 'cut-over', 'invalid') as $status) {
            self::assertFalse(TakeoverState::transition($status), var_export($status, true));
        }
        self::assertFalse(TakeoverState::transition(array('preflight_ok')));
        self::assertFalse(TakeoverState::transition(null));
        self::assertArrayNotHasKey(self::OPTION, $GLOBALS['supcheckout_test_options']);
    }

    public function test_cut_over_records_its_time_and_rollback_keeps_it_as_history(): void {
        self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 120, 'totals' => array('orders' => 120)));
        $before = time();

        self::assertTrue(TakeoverState::transition('cut_over'));

        $state = TakeoverState::get();
        self::assertGreaterThanOrEqual($before, $state['cut_over_at']);
        self::assertLessThanOrEqual(time(), $state['cut_over_at']);
        self::assertSame(120, $state['cursor'], 'progress fields survive a transition');
        self::assertSame(array('orders' => 120), $state['totals']);

        self::assertTrue(TakeoverState::transition('rolled_back'));
        self::assertSame($state['cut_over_at'], TakeoverState::get()['cut_over_at']);
    }

    public function test_rollback_records_its_time_for_the_stale_choice_grace(): void {
        self::seed(array('status' => 'cut_over', 'cut_over_at' => time() - self::DAY, 'cursor' => 0, 'totals' => array()));
        $before = time();

        self::assertTrue(TakeoverState::transition('rolled_back'));
        $rolled_back_at = TakeoverState::get()['rolled_back_at'];
        self::assertGreaterThanOrEqual($before, $rolled_back_at);

        self::assertTrue(TakeoverState::patch(array('rollback' => array('removal_pending' => false))));
        self::assertTrue(TakeoverState::transition('preflight_ok'));
        self::assertSame($rolled_back_at, TakeoverState::get()['rolled_back_at'], 'kept as history');
        self::assertFalse(TakeoverState::patch(array('rolled_back_at' => 1)), 'a lifecycle field');
    }

    public function test_rolled_back_leaves_for_preflight_ok_only_once_the_copy_removal_is_recorded_complete(): void {
        foreach (array(null, array(), array('removal_pending' => true), array('removal_pending' => 'no'), 'garbage') as $progress) {
            $state = array('status' => 'rolled_back', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array());
            if ($progress !== null) {
                $state['rollback'] = $progress;
            }
            self::seed($state);
            self::assertFalse(TakeoverState::transition('preflight_ok'), (string) json_encode($progress));
            self::assertSame('rolled_back', TakeoverState::get()['status']);
        }
        self::assertTrue(TakeoverState::patch(array('rollback' => array('removal_pending' => false))));
        self::assertTrue(TakeoverState::transition('preflight_ok'));
    }

    public function test_abort_moves_a_verified_store_back_to_preflight_ok(): void {
        self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 9, 'totals' => array()));

        self::assertTrue(TakeoverState::transition('preflight_ok'));

        self::assertSame('preflight_ok', TakeoverState::get()['status']);
        self::assertSame(0, TakeoverState::get()['cut_over_at']);
        self::assertFalse(TakeoverState::is_cut_over());
    }

    public function test_finalize_waits_thirty_days_after_cut_over_unless_forced(): void {
        self::seed(array('status' => 'cut_over', 'cut_over_at' => time() - 29 * self::DAY, 'cursor' => 0, 'totals' => array()));
        self::assertFalse(TakeoverState::transition('finalized'));
        self::assertSame('cut_over', TakeoverState::get()['status']);

        self::assertTrue(TakeoverState::transition('finalized', true), '--force-after-review');
        self::assertSame('finalized', TakeoverState::get()['status']);
        self::assertTrue(self::stored()['finalize_forced'], 'a forced finalize is recorded');
        self::assertTrue(TakeoverState::is_cut_over(), 'a finalized store stays on the new identities');

        \supcheckout_test_reset_wp_options();
        self::seed(array('status' => 'cut_over', 'cut_over_at' => time() - 30 * self::DAY, 'cursor' => 0, 'totals' => array()));
        self::assertTrue(TakeoverState::transition('finalized'));
        self::assertFalse(self::stored()['finalize_forced']);
    }

    public function test_finalize_is_refused_when_the_cut_over_time_is_missing_or_in_the_future_even_when_forced(): void {
        // --force-after-review only bypasses the 30 days: Finalize's straggler guard compares
        // legacy rows with cut_over_at, so it must be a real past cutover time.
        foreach (array('missing' => 0, 'future' => time() + self::DAY) as $case => $cut_over_at) {
            \supcheckout_test_reset_wp_options();
            self::seed(array('status' => 'cut_over', 'cut_over_at' => $cut_over_at, 'cursor' => 0, 'totals' => array()));

            self::assertFalse(TakeoverState::transition('finalized'), $case);
            self::assertFalse(TakeoverState::transition('finalized', true), $case . ': force never unlocks it');
            self::assertSame('cut_over', TakeoverState::get()['status'], $case);
        }
    }

    public function test_the_force_flag_never_unlocks_any_other_transition(): void {
        self::assertFalse(TakeoverState::transition('cut_over', true));
        self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        self::assertFalse(TakeoverState::transition('finalized', true));
    }

    public function test_two_concurrent_transitions_exactly_one_wins(): void {
        TakeoverState::transition('preflight_ok');
        $competitor = null;
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) use (&$competitor) {
            $wpdb->before_query = null;
            // A second admin's request read the same record and commits first.
            TakeoverState::flush_cache();
            $competitor = TakeoverState::transition('copying');
        };

        $ours = TakeoverState::transition('copying');

        self::assertTrue($competitor);
        self::assertFalse($ours, 'the stale compare-and-swap loses');
        self::assertSame('copying', self::stored()['status']);
    }

    public function test_a_lost_race_never_overwrites_the_winner(): void {
        self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 50, 'totals' => array()));
        $winner = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 50, 'totals' => array(), 'mismatches' => array('order 7')));
        $GLOBALS['wpdb']->before_query = function () use ($winner) {
            $GLOBALS['supcheckout_test_options'][self::OPTION] = $winner;
        };

        self::assertFalse(TakeoverState::transition('verified'));
        self::assertSame($winner, $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_a_concurrent_first_record_loses_to_the_insert_that_landed_first(): void {
        $winner = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        $GLOBALS['wpdb']->before_query = function ($statement) use ($winner) {
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0) {
                $GLOBALS['supcheckout_test_options'][self::OPTION] = $winner;
            }
        };

        self::assertFalse(TakeoverState::transition('preflight_ok'));
        self::assertSame($winner, $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_is_cut_over_is_one_cached_read_per_request_until_flushed(): void {
        self::seed(array('status' => 'cut_over', 'cut_over_at' => time(), 'cursor' => 0, 'totals' => array()));
        $reads = 0;
        $GLOBALS['supcheckout_test_get_option_filter'] = function ($name, $value) use (&$reads) {
            if ($name === self::OPTION) {
                $reads++;
            }
            return $value;
        };

        for ($i = 0; $i < 25; $i++) {
            self::assertTrue(TakeoverState::is_cut_over());
        }
        self::assertSame(1, $reads, 'Keys::write_key() calls this on every write');

        $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode(array('status' => 'rolled_back'));
        self::assertTrue(TakeoverState::is_cut_over(), 'cached for the request');

        TakeoverState::flush_cache();
        self::assertFalse(TakeoverState::is_cut_over());
        self::assertSame(2, $reads);
    }

    public function test_a_successful_transition_resets_the_cached_answer(): void {
        self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        self::assertFalse(TakeoverState::is_cut_over());

        self::assertTrue(TakeoverState::transition('cut_over'));
        self::assertTrue(TakeoverState::is_cut_over());

        self::assertTrue(TakeoverState::transition('rolled_back'));
        self::assertFalse(TakeoverState::is_cut_over());
    }

    public function test_an_unreadable_stored_state_fails_closed(): void {
        foreach (array('garbage', '{"status":"warp"}', '["cut_over"]', '{"cursor":3}') as $raw) {
            \supcheckout_test_reset_wp_options();
            $GLOBALS['supcheckout_test_options'][self::OPTION] = $raw;
            TakeoverState::flush_cache();

            self::assertSame('invalid', TakeoverState::get()['status'], $raw);
            self::assertFalse(TakeoverState::is_cut_over(), $raw);
            foreach (self::STATUSES as $to) {
                self::assertFalse(TakeoverState::transition($to, true), "{$raw} -> {$to}");
            }
            self::assertFalse(TakeoverState::patch(array('cursor' => 1)), $raw);
            self::assertSame($raw, $GLOBALS['supcheckout_test_options'][self::OPTION], 'never overwritten');
        }
    }

    public function test_transitions_fail_closed_without_a_database_handle(): void {
        $GLOBALS['wpdb'] = null;

        self::assertFalse(TakeoverState::transition('preflight_ok'));
        self::assertSame('idle', TakeoverState::get()['status']);
    }

    public function test_patch_persists_progress_fields_with_a_compare_and_swap(): void {
        self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));

        self::assertTrue(TakeoverState::patch(array('cursor' => 37, 'cursors' => array('users' => 4))));
        self::assertTrue(TakeoverState::patch(array('cursor' => 37)), 'an unchanged record is a successful no-op');

        $state = TakeoverState::get();
        self::assertSame('copying', $state['status']);
        self::assertSame(37, $state['cursor']);
        self::assertSame(array('users' => 4), $state['cursors']);
    }

    public function test_patch_never_changes_the_lifecycle_fields(): void {
        self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));

        foreach (array('status', 'cut_over_at', 'updated_at', 'finalize_forced') as $reserved) {
            self::assertFalse(TakeoverState::patch(array($reserved => 'cut_over')), $reserved);
        }
        self::assertFalse(TakeoverState::patch(array()));
        self::assertFalse(TakeoverState::patch(array(7 => 'x')));
        self::assertSame('verified', TakeoverState::get()['status']);
        self::assertFalse(TakeoverState::is_cut_over());
    }

    public function test_patch_can_require_the_status_it_was_planned_under(): void {
        self::seed(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 10, 'totals' => array()));

        self::assertFalse(TakeoverState::patch(array('cursor' => 11), 'copying'), 'a runner stops once the state moved on');
        self::assertSame(10, TakeoverState::get()['cursor']);
        self::assertTrue(TakeoverState::patch(array('cursor' => 11), 'preflight_ok'));
        self::assertSame(11, TakeoverState::get()['cursor']);
    }

    public function test_patch_loses_a_race_instead_of_overwriting(): void {
        self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 10, 'totals' => array()));
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            TakeoverState::transition('preflight_ok');
        };

        self::assertFalse(TakeoverState::patch(array('cursor' => 11)));
        self::assertSame('preflight_ok', self::stored()['status']);
        self::assertSame(10, self::stored()['cursor']);
    }
}
