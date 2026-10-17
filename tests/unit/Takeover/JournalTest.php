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

    /** Record a simple change under $path. */
    private static function put(string $path, string $target = 'option'): void {
        Journal::record($target, $path, 'before:' . $path, 'after:' . $path, 'option:backup_' . preg_replace('/[^a-z0-9_]/', '_', $path));
    }

    /**
     * A well-formed stored entry.
     *
     * @return array<string, mixed>
     */
    private static function stored_entry(int $seq): array {
        return array(
            'seq' => $seq,
            'recorded_at' => 1700000000,
            'target' => 'option',
            'path' => 'path_' . $seq,
            'before_fingerprint' => hash('sha256', 'b' . $seq),
            'after_fingerprint' => hash('sha256', 'a' . $seq),
            'restore_source' => 'option:backup',
        );
    }

    /** @param list<array<string, mixed>> $entries */
    private static function store_chunk(int $index, array $entries): void {
        $name = $index === 0 ? self::OPTION : self::OPTION . '_' . $index;
        $GLOBALS['supcheckout_test_options'][$name] = (string) json_encode($entries);
    }

    /** @return list<array<string, mixed>> */
    private static function full_chunk(int $index): array {
        $entries = array();
        for ($i = 0; $i < Journal::CHUNK_SIZE; $i++) {
            $entries[] = self::stored_entry($index * Journal::CHUNK_SIZE + $i);
        }
        return $entries;
    }

    /**
     * The journal's own RuntimeException, or null when $operation returned normally.
     * PHPUnit's assertion failures are RuntimeExceptions too and are re-thrown.
     */
    private static function journal_failure(callable $operation): ?\RuntimeException {
        try {
            $operation();
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Exception) {
                throw $e;
            }
            return $e;
        }
        return null;
    }

    private static function assert_unreadable(string $message): void {
        $failure = self::journal_failure(function () {
            Journal::entries();
        });
        self::assertNotNull($failure, 'entries() returned a malformed journal: ' . $message);
        self::assertStringNotContainsString('test-api-key-123', $failure->getMessage());
    }

    public function test_entries_never_contain_the_api_key(): void {
        // Built from the synthetic fixture so the source holds no new key-shaped literal (gitleaks).
        $after = array('api_key' => 'test-api-key-123' . '-2') + self::settings_with_key();
        Journal::record('option', 'woocommerce_supcheckout_settings', self::settings_with_key(), $after, 'option:woocommerce_your_gateway_id_settings');

        $encoded = (string) json_encode(Journal::entries());
        self::assertStringNotContainsString('test-api-key-123', $encoded);
        self::assertStringNotContainsString('test-api-key-123', implode('|', array_map('strval', $GLOBALS['supcheckout_test_options'])));
    }

    public function test_before_and_after_values_are_stored_only_as_sha256_fingerprints(): void {
        $before = self::settings_with_key();
        $after = array('title' => 'Pay by card') + $before;

        Journal::record('option', 'woocommerce_supcheckout_settings', $before, $after, 'option:woocommerce_your_gateway_id_settings');

        $entries = Journal::entries();
        self::assertCount(1, $entries);
        self::assertSame(hash('sha256', serialize($before)), $entries[0]['before_fingerprint']);
        self::assertSame(hash('sha256', serialize($after)), $entries[0]['after_fingerprint']);
        self::assertSame(Journal::fingerprint($before), $entries[0]['before_fingerprint']);
        self::assertSame(Journal::fingerprint($after), $entries[0]['after_fingerprint']);
        self::assertNotSame($entries[0]['before_fingerprint'], $entries[0]['after_fingerprint']);
        self::assertSame(
            array('seq', 'recorded_at', 'target', 'path', 'before_fingerprint', 'after_fingerprint', 'restore_source'),
            array_keys($entries[0])
        );
        self::assertSame('option', $entries[0]['target']);
        self::assertSame('woocommerce_supcheckout_settings', $entries[0]['path']);
        self::assertSame('option:woocommerce_your_gateway_id_settings', $entries[0]['restore_source']);
    }

    public function test_is_current_after_tells_the_takeovers_value_from_a_later_edit(): void {
        $after = array('bacs' => 0, 'supcheckout' => 1);
        Journal::record('option', 'woocommerce_gateway_order', array('bacs' => 0, 'upayments_legacy' => 1), $after, 'option:supcheckout_takeover_backup_gateway_order');
        $entry = Journal::entries()[0];

        self::assertTrue(Journal::is_current_after($entry, $after), 'still the value the takeover wrote: safe to replay');
        self::assertFalse(Journal::is_current_after($entry, array('bacs' => 1, 'supcheckout' => 0)), 'the merchant reordered it later');
        self::assertFalse(Journal::is_current_after($entry, null), 'deleted later');
        self::assertFalse(Journal::is_current_after(array('path' => 'x'), $after), 'an entry without an after-fingerprint is never current');
        self::assertFalse(Journal::is_current_after(array('after_fingerprint' => 7), $after));
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
        Journal::record('option', 'woocommerce_gateway_order', array('bacs' => 0), array('bacs' => 1), 'option:supcheckout_takeover_backup_gateway_order');
        Journal::record('option', 'woocommerce_default_gateway', 'bacs', 'supcheckout', 'option:supcheckout_takeover_backup_default_gateway');
        Journal::record('order', '123/payment_method', 'supcheckout', 'legacy_gateway', 'value:legacy_gateway');

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

    public function test_writes_are_non_autoloaded_atomic_inserts_and_bytewise_compare_and_swap_updates(): void {
        $queries = array();
        $GLOBALS['wpdb']->before_query = function ($statement) use (&$queries) {
            $queries[] = $statement;
        };

        self::put('a');
        self::put('b');

        self::assertCount(2, $queries);
        self::assertStringStartsWith('INSERT IGNORE ', $queries[0]['query']);
        self::assertSame(self::OPTION, $queries[0]['args'][0]);
        self::assertSame('no', $queries[0]['args'][2], 'the journal is never autoloaded');
        self::assertStringStartsWith('UPDATE ', $queries[1]['query']);
        self::assertStringContainsString('AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $queries[1]['query']);
        self::assertNotContains('add', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertNotContains('update', array_column($GLOBALS['supcheckout_test_option_calls'], 0));
        self::assertContains(array(self::OPTION, 'options'), $GLOBALS['supcheckout_test_cache_deletes']);
    }

    public function test_a_large_journal_rolls_over_into_bounded_chunk_options(): void {
        $total = Journal::CHUNK_SIZE * 2 + 5;
        for ($i = 0; $i < $total; $i++) {
            self::put($i . '/payment_method', 'order');
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
            self::put($i . '/payment_method', 'order');
        }
        Journal::flush_cache();

        self::put('next_request');

        $entries = Journal::entries();
        self::assertSame(Journal::CHUNK_SIZE + 1, $entries[Journal::CHUNK_SIZE + 1]['seq']);
        self::assertCount(2, json_decode((string) $GLOBALS['supcheckout_test_options'][self::OPTION . '_1'], true));
    }

    public function test_a_stale_chunk_hint_recovers_after_the_journal_is_cleared(): void {
        for ($i = 0; $i < Journal::CHUNK_SIZE + 1; $i++) {
            self::put($i . '/payment_method', 'order');
        }
        \supcheckout_test_reset_wp_options();

        self::put('fresh');

        self::assertSame(array(0), array_column(Journal::entries(), 'seq'));
        self::assertArrayNotHasKey(self::OPTION . '_1', $GLOBALS['supcheckout_test_options']);
    }

    public function test_a_concurrent_append_is_retried_and_both_entries_survive(): void {
        self::put('first');
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            // Another request appended between our read and our compare-and-swap.
            self::put('competitor');
        };

        self::put('ours');

        $entries = Journal::entries();
        self::assertSame(array('first', 'competitor', 'ours'), array_column($entries, 'path'));
        self::assertSame(array(0, 1, 2), array_column($entries, 'seq'));
    }

    public function test_a_concurrent_first_append_is_retried_after_the_lost_insert(): void {
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            self::put('competitor');
        };

        self::put('ours');

        self::assertSame(array('competitor', 'ours'), array_column(Journal::entries(), 'path'));
        self::assertSame(array(0, 1), array_column(Journal::entries(), 'seq'));
    }

    public function test_a_concurrent_chunk_rollover_is_retried_into_the_winners_chunk(): void {
        for ($i = 0; $i < Journal::CHUNK_SIZE; $i++) {
            self::put($i . '/payment_method', 'order');
        }
        $GLOBALS['wpdb']->before_query = function ($statement, $wpdb) {
            $wpdb->before_query = null;
            self::put('competitor');
        };

        self::put('ours');

        $entries = Journal::entries();
        self::assertCount(Journal::CHUNK_SIZE + 2, $entries);
        self::assertSame(array('competitor', 'ours'), array_column(array_slice($entries, Journal::CHUNK_SIZE), 'path'));
        self::assertSame(range(0, Journal::CHUNK_SIZE + 1), array_column($entries, 'seq'));
    }

    public function test_an_append_that_keeps_losing_fails_loudly_instead_of_dropping_the_entry(): void {
        self::put('first');
        $competitors = 0;
        $hook = null;
        $hook = function ($statement, $wpdb) use (&$hook, &$competitors) {
            $wpdb->before_query = null;
            self::put('competitor_' . $competitors++);
            $wpdb->before_query = $hook;
        };
        $GLOBALS['wpdb']->before_query = $hook;

        $failure = self::journal_failure(function () {
            self::put('ours');
        });

        self::assertNotNull($failure, 'an append that never lands must throw');
        self::assertGreaterThan(1, $competitors, 'it retried before giving up');
        $GLOBALS['wpdb']->before_query = null;
        self::assertNotContains('ours', array_column(Journal::entries(), 'path'));
    }

    public function test_an_unavailable_database_fails_loudly(): void {
        $GLOBALS['wpdb'] = null;

        $this->expectException(\RuntimeException::class);
        self::put('ours');
    }

    public function test_a_corrupt_chunk_fails_closed_for_reads_and_appends(): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = 'not-json';

        self::assert_unreadable('not-json');
        self::assertNotNull(self::journal_failure(function () {
            self::put('ours');
        }), 'record() must not append to a corrupt chunk');
        self::assertSame('not-json', $GLOBALS['supcheckout_test_options'][self::OPTION]);
    }

    public function test_every_decoded_entry_is_validated_before_anything_is_returned(): void {
        $valid = self::stored_entry(0);
        $cases = array(
            'empty object' => array(new \stdClass()),
            'seq only' => array(array('seq' => 99)),
            'empty chunk' => array(),
            'missing after fingerprint' => array(array_diff_key($valid, array('after_fingerprint' => true))),
            'unknown field' => array($valid + array('before' => 'test-api-key-123')),
            'string seq' => array(array('seq' => '0') + $valid),
            'float recorded_at' => array(array('recorded_at' => 1.5) + $valid),
            'negative recorded_at' => array(array('recorded_at' => -1) + $valid),
            'array target' => array(array('target' => array('option')) + $valid),
            'path with spaces' => array(array('path' => 'api key') + $valid),
            'restore source as JSON' => array(array('restore_source' => '{"a":1}') + $valid),
            'short fingerprint' => array(array('before_fingerprint' => 'abc') + $valid),
            'uppercase fingerprint' => array(array('after_fingerprint' => strtoupper($valid['after_fingerprint'])) + $valid),
            'non-contiguous seq' => array($valid, self::stored_entry(2)),
            'seq not starting at the chunk base' => array(self::stored_entry(1)),
            'object instead of list' => array('x' => $valid),
        );

        foreach ($cases as $label => $chunk) {
            \supcheckout_test_reset_wp_options();
            Journal::flush_cache();
            $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode($chunk);

            self::assert_unreadable($label);
            self::assertNotNull(self::journal_failure(function () {
                self::put('ours');
            }), 'record() appended to a malformed chunk: ' . $label);
            self::assertSame((string) json_encode($chunk), $GLOBALS['supcheckout_test_options'][self::OPTION], $label);
        }
    }

    public function test_chunk_layout_is_validated_across_chunks(): void {
        self::store_chunk(0, self::full_chunk(0));
        self::store_chunk(1, array(self::stored_entry(Journal::CHUNK_SIZE)));
        self::assertCount(Journal::CHUNK_SIZE + 1, Journal::entries(), 'a well-formed two-chunk journal reads');

        $cases = array(
            'non-full chunk before a later chunk' => array(
                0 => array(self::stored_entry(0)),
                1 => array(self::stored_entry(Journal::CHUNK_SIZE)),
            ),
            'oversized chunk' => array(
                0 => array_merge(self::full_chunk(0), array(self::stored_entry(Journal::CHUNK_SIZE))),
            ),
            'second chunk numbered as the first' => array(
                0 => self::full_chunk(0),
                1 => array(self::stored_entry(0)),
            ),
            'orphan chunk after a gap' => array(
                1 => array(self::stored_entry(Journal::CHUNK_SIZE)),
            ),
        );

        foreach ($cases as $label => $chunks) {
            \supcheckout_test_reset_wp_options();
            Journal::flush_cache();
            foreach ($chunks as $index => $entries) {
                self::store_chunk($index, $entries);
            }
            self::assert_unreadable($label);
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
                Journal::record($arguments[0], $arguments[1], 'before', 'after', $arguments[2]);
                self::fail('accepted ' . var_export($arguments, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('test-api-key-123', $e->getMessage());
            }
        }
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }
}
