<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Journal;

final class JournalTest extends TestCase {
    private const OPTION = 'supcheckout_takeover_journal_v1';

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    /** @return array<string, mixed> */
    private static function settings_with_key(): array {
        return array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'Pay');
    }

    public function test_entries_never_contain_the_api_key(): void {
        Journal::record('option', 'woocommerce_supcheckout_settings', self::settings_with_key(), 'option:woocommerce_your_gateway_id_settings');

        $encoded = (string) json_encode(Journal::entries());
        self::assertStringNotContainsString('test-api-key-123', $encoded);
        self::assertStringNotContainsString('test-api-key-123', implode('|', array_map('strval', $GLOBALS['supcheckout_test_options'])));
    }

    public function test_the_before_value_is_stored_only_as_its_sha256_fingerprint(): void {
        $value = self::settings_with_key();

        Journal::record('option', 'woocommerce_supcheckout_settings', $value, 'option:woocommerce_your_gateway_id_settings');

        $entries = Journal::entries();
        self::assertCount(1, $entries);
        self::assertSame(hash('sha256', serialize($value)), $entries[0]['before_fingerprint']);
        self::assertSame(Journal::fingerprint($value), $entries[0]['before_fingerprint']);
        self::assertSame(
            array('seq', 'recorded_at', 'target', 'path', 'before_fingerprint', 'restore_source'),
            array_keys($entries[0])
        );
        self::assertSame('option', $entries[0]['target']);
        self::assertSame('woocommerce_supcheckout_settings', $entries[0]['path']);
        self::assertSame('option:woocommerce_your_gateway_id_settings', $entries[0]['restore_source']);
    }

    public function test_fingerprints_distinguish_absent_empty_and_typed_values(): void {
        $fingerprints = array(
            Journal::fingerprint(null),
            Journal::fingerprint(''),
            Journal::fingerprint(false),
            Journal::fingerprint('0'),
            Journal::fingerprint(0),
            Journal::fingerprint(array()),
        );

        self::assertSame($fingerprints, array_unique($fingerprints));
        foreach ($fingerprints as $fingerprint) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        }
    }

    public function test_the_journal_is_append_only_and_ordered(): void {
        $before = time();
        Journal::record('option', 'woocommerce_gateway_order', array('bacs' => 0), 'option:supcheckout_takeover_backup_gateway_order');
        Journal::record('option', 'woocommerce_default_gateway', 'bacs', 'option:supcheckout_takeover_backup_default_gateway');
        Journal::record('order', '123/payment_method', 'supcheckout', 'value:upayments');

        $entries = Journal::entries();
        self::assertSame(array(0, 1, 2), array_column($entries, 'seq'));
        self::assertSame(array('woocommerce_gateway_order', 'woocommerce_default_gateway', '123/payment_method'), array_column($entries, 'path'));
        foreach ($entries as $entry) {
            self::assertGreaterThanOrEqual($before, $entry['recorded_at']);
            self::assertLessThanOrEqual(time(), $entry['recorded_at']);
        }
    }

    public function test_an_empty_journal_has_no_entries_and_no_option(): void {
        self::assertSame(array(), Journal::entries());
        self::assertArrayNotHasKey(self::OPTION, $GLOBALS['supcheckout_test_options']);
    }

    public function test_writes_are_non_autoloaded_atomic_inserts_and_compare_and_swap_updates(): void {
        $queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$queries) {
            $queries[] = $statement;
        };

        Journal::record('option', 'a', 'x', 'option:a_backup');
        Journal::record('option', 'b', 'y', 'option:b_backup');

        self::assertCount(2, $queries);
        self::assertStringStartsWith('INSERT IGNORE ', $queries[0]['query']);
        self::assertSame(self::OPTION, $queries[0]['args'][0]);
        self::assertSame('no', $queries[0]['args'][2], 'the journal is never autoloaded');
        self::assertStringStartsWith('UPDATE ', $queries[1]['query']);
        self::assertStringContainsString('AND option_value = %s', $queries[1]['query']);
        self::assertNotContains('add', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertNotContains('update', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertContains(array(self::OPTION, 'options'), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    public function test_a_large_journal_rolls_over_into_bounded_chunk_options(): void {
        $total = Journal::CHUNK_SIZE * 2 + 5;
        for ($i = 0; $i < $total; $i++) {
            Journal::record('order', $i . '/payment_method', 'supcheckout', 'value:legacy_gateway');
        }

        $first = json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION], true);
        $second = json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION . '_1'], true);
        $third = json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION . '_2'], true);
        self::assertCount(Journal::CHUNK_SIZE, $first);
        self::assertCount(Journal::CHUNK_SIZE, $second);
        self::assertCount(5, $third);
        self::assertArrayNotHasKey(self::OPTION . '_3', $GLOBALS['supcheckout_test_options']);

        $entries = Journal::entries();
        self::assertCount($total, $entries);
        self::assertSame(range(0, $total - 1), array_column($entries, 'seq'));
        self::assertSame('0/payment_method', $entries[0]['path']);
        self::assertSame(($total - 1) . '/payment_method', $entries[$total - 1]['path']);
    }

    public function test_appends_continue_in_the_last_chunk_across_requests(): void {
        for ($i = 0; $i < Journal::CHUNK_SIZE + 1; $i++) {
            Journal::record('order', $i . '/payment_method', 'supcheckout', 'value:legacy_gateway');
        }
        Journal::flush_cache();

        Journal::record('option', 'next_request', 'x', 'option:next_backup');

        $entries = Journal::entries();
        self::assertSame(Journal::CHUNK_SIZE + 1, $entries[Journal::CHUNK_SIZE + 1]['seq']);
        self::assertCount(2, json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION . '_1'], true));
    }

    public function test_a_stale_chunk_hint_recovers_after_the_journal_is_cleared(): void {
        for ($i = 0; $i < Journal::CHUNK_SIZE + 1; $i++) {
            Journal::record('order', $i . '/payment_method', 'supcheckout', 'value:legacy_gateway');
        }
        \supcheckout_test_reset_wp_options();

        Journal::record('option', 'fresh', 'x', 'option:fresh_backup');

        self::assertSame(array(0), array_column(Journal::entries(), 'seq'));
        self::assertArrayNotHasKey(self::OPTION . '_1', $GLOBALS['supcheckout_test_options']);
    }

    public function test_a_concurrent_append_is_retried_and_both_entries_survive(): void {
        Journal::record('option', 'first', 'x', 'option:first_backup');
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            // Another request appended between our read and our compare-and-swap.
            Journal::record('option', 'competitor', 'y', 'option:competitor_backup');
        };

        Journal::record('option', 'ours', 'z', 'option:ours_backup');

        $entries = Journal::entries();
        self::assertSame(array('first', 'competitor', 'ours'), array_column($entries, 'path'));
        self::assertSame(array(0, 1, 2), array_column($entries, 'seq'));
    }

    public function test_a_concurrent_first_append_is_retried_after_the_lost_insert(): void {
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            Journal::record('option', 'competitor', 'y', 'option:competitor_backup');
        };

        Journal::record('option', 'ours', 'z', 'option:ours_backup');

        self::assertSame(array('competitor', 'ours'), array_column(Journal::entries(), 'path'));
        self::assertSame(array(0, 1), array_column(Journal::entries(), 'seq'));
    }

    public function test_a_concurrent_chunk_rollover_is_retried_into_the_winners_chunk(): void {
        for ($i = 0; $i < Journal::CHUNK_SIZE; $i++) {
            Journal::record('order', $i . '/payment_method', 'supcheckout', 'value:legacy_gateway');
        }
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            Journal::record('option', 'competitor', 'y', 'option:competitor_backup');
        };

        Journal::record('option', 'ours', 'z', 'option:ours_backup');

        $entries = Journal::entries();
        self::assertCount(Journal::CHUNK_SIZE + 2, $entries);
        self::assertSame(array('competitor', 'ours'), array_column(array_slice($entries, Journal::CHUNK_SIZE), 'path'));
        self::assertSame(range(0, Journal::CHUNK_SIZE + 1), array_column($entries, 'seq'));
    }

    public function test_an_append_that_keeps_losing_fails_loudly_instead_of_dropping_the_entry(): void {
        Journal::record('option', 'first', 'x', 'option:first_backup');
        $GLOBALS['wpdb']->before_query = function () {
            $GLOBALS['supcheckout_test_options'][self::OPTION] = str_replace(']', ',{"seq":99}]', $GLOBALS['supcheckout_test_options'][self::OPTION]);
        };

        $this->expectException(\RuntimeException::class);
        Journal::record('option', 'ours', 'z', 'option:ours_backup');
    }

    public function test_an_unavailable_database_fails_loudly(): void {
        $GLOBALS['wpdb'] = null;

        $this->expectException(\RuntimeException::class);
        Journal::record('option', 'ours', 'z', 'option:ours_backup');
    }

    public function test_a_corrupt_chunk_fails_closed_for_reads_and_appends(): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = 'not-json';

        try {
            Journal::entries();
            self::fail('entries() must not silently skip a corrupt chunk');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('not-json', $e->getMessage());
        }

        try {
            Journal::record('option', 'ours', 'z', 'option:ours_backup');
            self::fail('record() must not overwrite a corrupt chunk');
        } catch (\RuntimeException $e) {
            self::assertSame('not-json', $GLOBALS['supcheckout_test_options'][self::OPTION]);
        }
    }

    public function test_identifiers_that_look_like_raw_values_are_rejected_before_any_write(): void {
        $invalid = array(
            array('', 'path', 'option:x'),
            array('option', '', 'option:x'),
            array('option', 'path', ''),
            array('option', 'api key with spaces', 'option:x'),
            array('option', 'path', '{"api_key":"test-api-key-123"}'),
            array('option', "path\n", 'option:x'),
            array('option', str_repeat('a', 192), 'option:x'),
            array(array('option'), 'path', 'option:x'),
            array('option', 7, 'option:x'),
        );

        foreach ($invalid as $arguments) {
            try {
                Journal::record($arguments[0], $arguments[1], 'value', $arguments[2]);
                self::fail('accepted ' . var_export($arguments, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('test-api-key-123', $e->getMessage());
            }
        }
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }
}
