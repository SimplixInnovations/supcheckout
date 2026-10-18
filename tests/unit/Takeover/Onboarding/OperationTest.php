<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;

/** 0c Task 4: the persisted single-flight operation record. */
final class OperationTest extends TestCase {
    private const OPTION = 'supcheckout_onboarding_operation_v1';

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    public function test_current_is_null_before_any_start(): void {
        self::assertNull(Operation::current());
        self::assertFalse(Operation::is_running(null));
    }

    public function test_start_creates_one_running_operation(): void {
        $op = Operation::start('switch', array('deactivate_official' => true), 7, 1000);

        self::assertNotNull($op);
        self::assertSame(
            array('switch', 'running', 7, 1000, array('deactivate_official' => true, 'reactivate_official' => false, 'force_after_review' => false)),
            array($op['kind'], $op['outcome'], $op['user_id'], $op['started_at'], $op['consent'])
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $op['id']);
        self::assertSame($op, Operation::current());
        self::assertTrue(Operation::is_running(Operation::current()));
    }

    public function test_the_record_has_every_documented_field(): void {
        $op = Operation::start('preflight', array(), 1, 5);

        self::assertNotNull($op);
        $keys = array_keys($op);
        sort($keys);
        $expected = array('attempts', 'busy_retries', 'consent', 'cooldown_retries', 'error', 'hint', 'id', 'kind', 'next_at', 'outcome', 'phase', 'report', 'self_test', 'started_at', 'steps', 'updated_at', 'user_id');
        self::assertSame($expected, $keys);
    }

    public function test_the_option_is_not_autoloaded_and_has_no_legacy_twin(): void {
        Operation::start('switch', array(), 1, 5);

        self::assertArrayHasKey(self::OPTION, $GLOBALS['supcheckout_test_options']);
        self::assertSame(array(self::OPTION), array_keys($GLOBALS['supcheckout_test_options']));
    }

    public function test_a_second_start_while_running_returns_null(): void {
        $first = Operation::start('switch', array(), 1, 1000);
        $raw = $GLOBALS['supcheckout_test_options'][self::OPTION];

        self::assertNull(Operation::start('switch', array(), 2, 1001));
        self::assertNull(Operation::start('abort', array(), 2, 1001));
        self::assertSame($raw, $GLOBALS['supcheckout_test_options'][self::OPTION]);
        self::assertSame($first['id'], Operation::current()['id']);
    }

    public function test_start_with_an_unknown_kind_returns_null(): void {
        self::assertNull(Operation::start('format_disk', array(), 1, 1000));
        self::assertNull(Operation::current());
    }

    public function test_start_replaces_a_finished_operation(): void {
        $first = Operation::start('switch', array(), 1, 1000);
        self::assertTrue(Operation::finish($first['id'], 'done', null, null, array(), 1005));

        $second = Operation::start('rollback', array(), 1, 1010);

        self::assertNotNull($second);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(array('rollback', 'running'), array(Operation::current()['kind'], Operation::current()['outcome']));
    }

    public function test_update_merges_progress_fields_and_stamps_updated_at(): void {
        $op = Operation::start('switch', array(), 1, 1000);

        self::assertTrue(Operation::update($op['id'], array('phase' => 'copy', 'busy_retries' => 2, 'next_at' => 1030, 'attempts' => array('copy' => 3)), 1020));

        $now = Operation::current();
        self::assertSame(array('copy', 2, 1030, array('copy' => 3), 1020, 1000), array($now['phase'], $now['busy_retries'], $now['next_at'], $now['attempts'], $now['updated_at'], $now['started_at']));
    }

    public function test_steps_keep_only_the_last_fifty_and_cut_long_strings(): void {
        $op = Operation::start('switch', array(), 1, 1000);
        $steps = array();
        for ($i = 1; $i <= 80; $i++) {
            $steps[] = 'step_' . $i;
        }
        $steps[79] = str_repeat('x', 900);

        self::assertTrue(Operation::update($op['id'], array('steps' => $steps), 1001));

        $stored = Operation::current()['steps'];
        self::assertCount(50, $stored);
        self::assertSame('step_31', $stored[0]);
        self::assertSame(500, strlen($stored[49]));
    }

    public function test_update_refuses_another_id_and_a_finished_operation(): void {
        $op = Operation::start('switch', array(), 1, 1000);

        self::assertFalse(Operation::update('00000000-0000-4000-8000-00000000dead', array('phase' => 'x'), 1001));
        self::assertTrue(Operation::finish($op['id'], 'failed', 'boom', 'retry', array(), 1002));
        self::assertFalse(Operation::update($op['id'], array('phase' => 'x'), 1003));
        self::assertNotSame('x', Operation::current()['phase']);
    }

    public function test_update_refuses_fields_it_does_not_own_and_wrong_types(): void {
        $op = Operation::start('switch', array(), 1, 1000);
        $raw = $GLOBALS['supcheckout_test_options'][self::OPTION];

        self::assertFalse(Operation::update($op['id'], array('outcome' => 'done'), 1001));
        self::assertFalse(Operation::update($op['id'], array('id' => 'other'), 1001));
        self::assertFalse(Operation::update($op['id'], array('consent' => array('force_after_review' => true)), 1001));
        self::assertFalse(Operation::update($op['id'], array('busy_retries' => '2'), 1001));
        self::assertFalse(Operation::update($op['id'], array('steps' => array('ok', array('nested'))), 1001));
        self::assertSame($raw, $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_finish_records_the_outcome_and_a_bounded_report(): void {
        $op = Operation::start('switch', array(), 1, 1000);
        $report = array('rows' => range(1, 100), 'note' => str_repeat('n', 700));

        self::assertTrue(Operation::finish($op['id'], 'blocked', 'copy_unverified', 'Run the copy again.', $report, 1009));

        $now = Operation::current();
        self::assertSame(array('blocked', 'copy_unverified', 'Run the copy again.', 1009), array($now['outcome'], $now['error'], $now['hint'], $now['updated_at']));
        self::assertCount(21, $now['report']['rows']);
        self::assertSame(array('truncated' => 80), $now['report']['rows'][20]);
        self::assertSame(500, strlen($now['report']['note']));
        self::assertFalse(Operation::is_running($now));
    }

    public function test_finish_refuses_a_running_outcome_and_a_second_finish(): void {
        $op = Operation::start('switch', array(), 1, 1000);

        self::assertFalse(Operation::finish($op['id'], 'running', null, null, array(), 1001));
        self::assertFalse(Operation::finish($op['id'], 'invalid', null, null, array(), 1001));
        self::assertTrue(Operation::finish($op['id'], 'done', null, null, array(), 1002));
        self::assertFalse(Operation::finish($op['id'], 'failed', 'late', null, array(), 1003));
        self::assertSame('done', Operation::current()['outcome']);
    }

    public function test_consent_is_whitelisted_and_strictly_boolean(): void {
        $op = Operation::start('switch', array('deactivate_official' => 'true', 'reactivate_official' => 1, 'evil' => true), 1, 1000);

        self::assertSame(array('deactivate_official' => false, 'reactivate_official' => false, 'force_after_review' => false), $op['consent']);
    }

    public function test_an_unreadable_record_reads_invalid_and_is_never_overwritten(): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = '{';

        self::assertSame('invalid', Operation::current()['outcome']);
        self::assertFalse(Operation::is_running(Operation::current()));
        self::assertNull(Operation::start('switch', array(), 1, 1000));
        self::assertFalse(Operation::update('anything', array('phase' => 'x'), 1001));
        self::assertFalse(Operation::finish('anything', 'done', null, null, array(), 1001));
        self::assertSame('{', $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_a_valid_json_record_without_the_required_shape_reads_invalid(): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = '{"id":"x","kind":"nope","outcome":"running"}';

        self::assertSame('invalid', Operation::current()['outcome']);
        self::assertNull(Operation::start('switch', array(), 1, 1000));
    }

    public function test_a_lost_compare_and_swap_fails_the_update(): void {
        $op = Operation::start('switch', array(), 1, 1000);
        $GLOBALS['wpdb']->before_query = static function (): void {
            // Another process rewrites the record between our read and our write.
            $stored = json_decode($GLOBALS['supcheckout_test_options'][self::OPTION], true);
            $stored['phase'] = 'other_process';
            $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode($stored);
        };

        self::assertFalse(Operation::update($op['id'], array('phase' => 'mine'), 1001));
        self::assertSame('other_process', Operation::current()['phase']);
    }

    public function test_a_concurrent_first_start_loses_to_the_insert_that_landed_first(): void {
        $winner = (string) json_encode(array('id' => '00000000-0000-4000-8000-0000000000aa', 'kind' => 'abort', 'outcome' => 'running'));
        $GLOBALS['wpdb']->before_query = static function ($statement) use ($winner): void {
            if (strpos($statement['query'], 'INSERT IGNORE ') === 0) {
                $GLOBALS['supcheckout_test_options'][self::OPTION] = $winner;
            }
        };

        self::assertNull(Operation::start('switch', array(), 1, 1000));
        self::assertSame($winner, $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_a_concurrent_start_after_a_finished_record_loses_the_swap(): void {
        $first = Operation::start('switch', array(), 1, 1000);
        Operation::finish($first['id'], 'done', null, null, array(), 1001);
        $GLOBALS['wpdb']->before_query = static function ($statement): void {
            if (strpos($statement['query'], 'UPDATE ') === 0) {
                $stored = json_decode($GLOBALS['supcheckout_test_options'][self::OPTION], true);
                $stored['outcome'] = 'running';
                $stored['id'] = '00000000-0000-4000-8000-0000000000bb';
                $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode($stored);
            }
        };

        self::assertNull(Operation::start('rollback', array(), 1, 1002));
        self::assertSame('00000000-0000-4000-8000-0000000000bb', Operation::current()['id']);
    }
}
