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
 * No secrets, by construction: record() takes the before-VALUE and stores only its
 * SHA-256 fingerprint, so a caller cannot put an API key, token, card datum or PII
 * into the journal by passing the value. The value itself stays restorable from
 * `restore_source`, which names where the original is kept intact (the untouched
 * legacy option, or a backup the takeover made). Target, path and restore source
 * are identifiers: they are limited to 191 characters of [A-Za-z0-9_.:/#@[]-], so
 * serialized or JSON values, whitespace and quotes are rejected before any write.
 *
 * Bounded storage: entries are kept in chunk options of at most CHUNK_SIZE entries
 * (about 300 bytes each, so well under 100 KB per option). Chunk 0 is the option
 * `supcheckout_takeover_journal_v1`; chunk n ≥ 1 is `supcheckout_takeover_journal_v1_<n>`.
 * Every chunk is non-autoloaded, so a journal with thousands of entries (for example
 * one per order whose payment_method Rollback rewrites) never loads on a normal
 * request and no single row grows without bound. Each append rewrites only the last
 * chunk. An entry's `seq` is its global position (chunk × CHUNK_SIZE + index).
 *
 * Race safety: a new chunk is created with OrderLock::insert_if_absent() and an
 * append to an existing chunk is an SQL compare-and-swap against the exact stored
 * string (OrderLock::replace_if_current()). A lost race re-reads and retries; an
 * append that still cannot land, an unavailable database or a corrupt chunk throws
 * instead of silently dropping the entry, so the caller must not make the change.
 */
final class Journal {
    public const OPTION = 'supcheckout_takeover_journal_v1';
    public const CHUNK_SIZE = 250;

    private const MAX_ATTEMPTS = 8;
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_.:\/#@\[\]-]{1,191}$/D';

    /** @var int Last chunk index seen in this request; a hint only, re-validated on use. */
    private static $last_chunk = 0;

    /**
     * Record one change before it is made.
     *
     * @param mixed $target         What kind of store, e.g. 'option', 'order', 'user_meta'.
     * @param mixed $path           Where in it, e.g. 'woocommerce_gateway_order' or '123/payment_method'.
     * @param mixed $before_value   The value about to be replaced; only its fingerprint is stored.
     * @param mixed $restore_source Where Rollback restores the original from, e.g. 'option:<name>'.
     * @return void
     * @throws \InvalidArgumentException When an identifier is not a plain identifier.
     * @throws \RuntimeException         When the entry cannot be stored.
     */
    public static function record($target, $path, $before_value, $restore_source) {
        foreach (array('target' => $target, 'path' => $path, 'restore_source' => $restore_source) as $field => $identifier) {
            if (!is_string($identifier) || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new \InvalidArgumentException('Takeover journal ' . esc_html($field) . ' must be a plain identifier.');
            }
        }
        $entry = array(
            'seq' => 0,
            'recorded_at' => time(),
            'target' => $target,
            'path' => $path,
            'before_fingerprint' => self::fingerprint($before_value),
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
     * All entries, oldest first.
     *
     * @return list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, restore_source:string}>
     * @throws \RuntimeException When a chunk is unreadable.
     */
    public static function entries() {
        $entries = array();
        for ($index = 0; ; $index++) {
            $raw = self::read_raw(self::chunk_name($index));
            if ($raw === null) {
                break;
            }
            $chunk = self::decode($raw, $index);
            foreach ($chunk as $entry) {
                $entries[] = $entry;
            }
        }
        return $entries;
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
        $encoded = self::encode($chunk);
        if (OrderLock::replace_if_current($name, $raw, $encoded)) {
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
     * @param string $raw   Stored chunk.
     * @param int    $index Chunk index, for the error message.
     * @return list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, restore_source:string}>
     * @throws \RuntimeException When the chunk is not a JSON list of entries.
     */
    private static function decode($raw, $index) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded !== array() && array_keys($decoded) !== range(0, count($decoded) - 1))) {
            throw new \RuntimeException('Takeover journal chunk ' . (int) $index . ' is unreadable.');
        }
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Takeover journal chunk ' . (int) $index . ' is unreadable.');
            }
        }
        /** @var list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, restore_source:string}> $decoded */
        return $decoded;
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
