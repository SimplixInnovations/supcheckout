<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * Append-only takeover change journal (Sub-project 0b).
 *
 * Every write the takeover makes to a value it did not create is recorded here
 * before it happens, and Rollback replays the entries in reverse.
 *
 * No secrets, by construction: record() takes the before- and after-VALUES and stores
 * only their SHA-256 fingerprints, so a caller cannot put an API key, token, card
 * datum or PII into the journal by passing a value. The original stays restorable
 * from `restore_source`, which names where it is kept intact (the untouched legacy
 * option, or a backup the takeover made). Target, path and restore source are
 * identifiers: they are limited to 191 characters of [A-Za-z0-9_.:/#@[]-], so
 * serialized or JSON values, whitespace and quotes are rejected before any write.
 *
 * Later edits win: the after-fingerprint is the value the takeover wrote. Rollback
 * replays an entry only while is_current_after() confirms the current value is still
 * that one; a merchant's or another plugin's edit during the 30-day window is
 * reported as a conflict and kept.
 *
 * Bounded storage: entries are kept in chunk options of at most CHUNK_SIZE entries
 * (about 370 bytes each, so about 100 KB per option at most). Chunk 0 is the option
 * `supcheckout_takeover_journal_v1`; chunk n ≥ 1 is `supcheckout_takeover_journal_v1_<n>`.
 * Every chunk is non-autoloaded, so a journal with thousands of entries (for example
 * one per order whose payment_method Rollback rewrites) never loads on a normal
 * request and no single row grows without bound. Each append rewrites only the last
 * chunk. An entry's `seq` is its global position (chunk × CHUNK_SIZE + index); every
 * chunk but the last is full.
 *
 * Validation: every read decodes each entry strictly (exact field set, documented
 * types, identifier pattern, 64-hex fingerprints, seq equal to its position) and
 * checks the chunk layout (no empty, oversized, short-before-later or orphan chunk).
 * Anything else throws before an entry is returned or appended, so Rollback fails
 * before replaying a damaged journal.
 *
 * Race safety: a new chunk is created with OrderLock::insert_if_absent() and an
 * append to an existing chunk is a bytewise SQL compare-and-swap against the exact
 * stored string (OrderLock::replace_if_current()). A lost race re-reads and retries;
 * an append that still cannot land, an unavailable database or a corrupt chunk
 * throws instead of silently dropping the entry, so the caller must not make the change.
 */
final class Journal {
    public const OPTION = 'supcheckout_takeover_journal_v1';
    public const CHUNK_SIZE = 250;

    private const MAX_ATTEMPTS = 8;
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_.:\/#@\[\]-]{1,191}$/D';
    private const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/D';
    /** Every entry has exactly these fields, in this order. */
    private const FIELDS = array('seq', 'recorded_at', 'target', 'path', 'before_fingerprint', 'after_fingerprint', 'restore_source');

    /** @var int Last chunk index seen in this request; a hint only, re-validated on use. */
    private static $last_chunk = 0;

    /**
     * Record one change before it is made.
     *
     * @param mixed $target         What kind of store, e.g. 'option', 'order', 'user_meta'.
     * @param mixed $path           Where in it, e.g. 'woocommerce_gateway_order' or '123/payment_method'.
     * @param mixed $before_value   The value about to be replaced; only its fingerprint is stored.
     * @param mixed $after_value    The value the takeover is about to write; only its fingerprint is stored.
     * @param mixed $restore_source Where Rollback restores the original from, e.g. 'option:<name>'.
     * @return void
     * @throws \InvalidArgumentException When an identifier is not a plain identifier.
     * @throws \RuntimeException         When the entry cannot be stored.
     */
    public static function record($target, $path, $before_value, $after_value, $restore_source) {
        foreach (array('target' => $target, 'path' => $path, 'restore_source' => $restore_source) as $field => $identifier) {
            if (!self::is_identifier($identifier)) {
                throw new \InvalidArgumentException('Takeover journal ' . esc_html($field) . ' must be a plain identifier.');
            }
        }
        $entry = array(
            'seq' => 0,
            'recorded_at' => time(),
            'target' => $target,
            'path' => $path,
            'before_fingerprint' => self::fingerprint($before_value),
            'after_fingerprint' => self::fingerprint($after_value),
            'restore_source' => $restore_source,
        );

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if (self::try_append($entry)) {
                return;
            }
        }
        throw new \RuntimeException('Takeover journal entry could not be stored.');
    }

    /**
     * All entries, oldest first. Throws before returning anything when any chunk or
     * entry is malformed.
     *
     * @return list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, after_fingerprint:string, restore_source:string}>
     * @throws \RuntimeException When the journal is unreadable.
     */
    public static function entries() {
        $entries = array();
        $index = 0;
        $raw = self::read_raw(self::chunk_name(0));
        while ($raw !== null) {
            $chunk = self::decode($raw, $index);
            $next = self::read_raw(self::chunk_name($index + 1));
            if ($next !== null && count($chunk) !== self::CHUNK_SIZE) {
                self::fail_unreadable($index);
            }
            foreach ($chunk as $entry) {
                $entries[] = $entry;
            }
            $index++;
            $raw = $next;
        }
        // The probe stops at the first missing chunk; a chunk right after it is an orphan.
        if (self::read_raw(self::chunk_name($index + 1)) !== null) {
            self::fail_unreadable($index + 1);
        }
        return $entries;
    }

    /**
     * Whether $current_value is still the value the takeover wrote for $entry.
     *
     * @param array<string, mixed> $entry         An entry from entries().
     * @param mixed                $current_value The value stored at the entry's path now.
     * @return bool False for a later edit (or deletion) and for an entry without a valid after-fingerprint.
     */
    public static function is_current_after(array $entry, $current_value) {
        if (!isset($entry['after_fingerprint'])
            || !is_string($entry['after_fingerprint'])
            || preg_match(self::FINGERPRINT_PATTERN, $entry['after_fingerprint']) !== 1
        ) {
            return false;
        }
        return hash_equals($entry['after_fingerprint'], self::fingerprint($current_value));
    }

    /**
     * Whether the takeover itself wrote the value now stored at $target/$path: the most
     * recent entry for that path exists and is_current_after() holds for $current_value.
     * Copiers use it to refresh only their own earlier copy, never a foreign value.
     *
     * @param string $target        Entry target.
     * @param string $path          Entry path.
     * @param mixed  $current_value The value stored there now.
     * @return bool
     * @throws \RuntimeException When the journal is unreadable.
     */
    public static function wrote_current($target, $path, $current_value) {
        $latest = null;
        foreach (self::entries() as $entry) {
            if ($entry['target'] === $target && $entry['path'] === $path) {
                $latest = $entry;
            }
        }
        return $latest !== null && self::is_current_after($latest, $current_value);
    }

    /**
     * @param mixed $value Any value.
     * @return string SHA-256 of the PHP-serialized value (lowercase hex).
     */
    public static function fingerprint($value) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashed only, never stored or unserialized.
        return hash('sha256', serialize($value));
    }

    /**
     * Forget the per-request last-chunk hint (in tests).
     *
     * @return void
     */
    public static function flush_cache() {
        self::$last_chunk = 0;
    }

    /**
     * @param array<string, mixed> $entry Entry without its final seq.
     * @return bool True when the entry was stored; false after a lost race.
     * @throws \RuntimeException When the last chunk is unreadable.
     */
    private static function try_append(array $entry) {
        $index = self::last_chunk_index();
        $name = self::chunk_name($index);
        $raw = self::read_raw($name);

        if ($raw === null) {
            $entry['seq'] = $index * self::CHUNK_SIZE;
            return self::create_chunk($name, $entry);
        }

        $chunk = self::decode($raw, $index);
        if (count($chunk) >= self::CHUNK_SIZE) {
            $entry['seq'] = ($index + 1) * self::CHUNK_SIZE;
            if (self::create_chunk(self::chunk_name($index + 1), $entry)) {
                self::$last_chunk = $index + 1;
                return true;
            }
            return false;
        }

        $entry['seq'] = $index * self::CHUNK_SIZE + count($chunk);
        $chunk[] = $entry;
        if (OrderLock::replace_if_current($name, $raw, self::encode($chunk))) {
            return true;
        }
        OrderLock::flush_option_cache($name);
        return false;
    }

    /**
     * @param string               $name  Chunk option name.
     * @param array<string, mixed> $entry First entry.
     * @return bool
     */
    private static function create_chunk($name, array $entry) {
        if (OrderLock::insert_if_absent($name, self::encode(array($entry)))) {
            return true;
        }
        OrderLock::flush_option_cache($name);
        return false;
    }

    /**
     * Index of the last existing chunk (0 when the journal is empty).
     *
     * @return int
     */
    private static function last_chunk_index() {
        $index = self::$last_chunk;
        if ($index > 0 && self::read_raw(self::chunk_name($index)) === null) {
            $index = 0;
        }
        while (self::read_raw(self::chunk_name($index + 1)) !== null) {
            $index++;
        }
        self::$last_chunk = $index;
        return $index;
    }

    /**
     * @param int $index Chunk index.
     * @return string
     */
    private static function chunk_name($index) {
        return $index === 0 ? self::OPTION : self::OPTION . '_' . $index;
    }

    /**
     * @param string $name Option name.
     * @return string|null Stored string, or null when absent.
     * @throws \RuntimeException When the row holds something other than a string.
     */
    private static function read_raw($name) {
        if (!function_exists('get_option')) {
            return null;
        }
        $raw = get_option($name, null);
        if ($raw === null || $raw === false) {
            return null;
        }
        if (!is_string($raw)) {
            throw new \RuntimeException('Takeover journal chunk is unreadable.');
        }
        return $raw;
    }

    /**
     * Decode one chunk, validating every entry and its position.
     *
     * @param string $raw   Stored chunk.
     * @param int    $index Chunk index.
     * @return list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, after_fingerprint:string, restore_source:string}>
     * @throws \RuntimeException When the chunk is not a non-empty JSON list of valid entries.
     */
    private static function decode($raw, $index) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || $decoded === array()
            || count($decoded) > self::CHUNK_SIZE
            || array_keys($decoded) !== range(0, count($decoded) - 1)
        ) {
            self::fail_unreadable($index);
        }

        $entries = array();
        foreach ($decoded as $position => $entry) {
            $valid = self::validated_entry($entry, $index * self::CHUNK_SIZE + $position);
            if ($valid === null) {
                self::fail_unreadable($index);
            }
            $entries[] = $valid;
        }
        return $entries;
    }

    /**
     * @param mixed $entry        Decoded entry.
     * @param int   $expected_seq Its global position.
     * @return array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, after_fingerprint:string, restore_source:string}|null
     */
    private static function validated_entry($entry, $expected_seq) {
        if (!is_array($entry)
            || array_keys($entry) !== self::FIELDS
            || $entry['seq'] !== $expected_seq
            || !is_int($entry['recorded_at'])
            || $entry['recorded_at'] < 0
            || !self::is_identifier($entry['target'])
            || !self::is_identifier($entry['path'])
            || !self::is_identifier($entry['restore_source'])
            || !self::is_fingerprint($entry['before_fingerprint'])
            || !self::is_fingerprint($entry['after_fingerprint'])
        ) {
            return null;
        }
        return array(
            'seq' => $expected_seq,
            'recorded_at' => $entry['recorded_at'],
            'target' => $entry['target'],
            'path' => $entry['path'],
            'before_fingerprint' => $entry['before_fingerprint'],
            'after_fingerprint' => $entry['after_fingerprint'],
            'restore_source' => $entry['restore_source'],
        );
    }

    /**
     * @param mixed $value Candidate.
     * @phpstan-assert-if-true string $value
     * @return bool
     */
    private static function is_identifier($value) {
        return is_string($value) && preg_match(self::IDENTIFIER_PATTERN, $value) === 1;
    }

    /**
     * @param mixed $value Candidate.
     * @phpstan-assert-if-true string $value
     * @return bool
     */
    private static function is_fingerprint($value) {
        return is_string($value) && preg_match(self::FINGERPRINT_PATTERN, $value) === 1;
    }

    /**
     * @param int $index Chunk index.
     * @return never
     * @throws \RuntimeException Always; the message never contains stored content.
     */
    private static function fail_unreadable($index) {
        throw new \RuntimeException('Takeover journal chunk ' . (int) $index . ' is unreadable.');
    }

    /**
     * @param list<array<string, mixed>> $chunk Entries.
     * @return string
     * @throws \RuntimeException When the chunk cannot be encoded.
     */
    private static function encode(array $chunk) {
        $encoded = wp_json_encode($chunk);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Takeover journal chunk could not be encoded.');
        }
        return $encoded;
    }

    private function __construct() {}
}
