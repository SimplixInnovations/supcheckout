<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

defined('ABSPATH') || exit;

/**
 * Bounding of everything the onboarding backend stores or returns (Sub-project 0c).
 *
 * Lists keep at most LIST_LIMIT items and end in a `array('truncated' => <dropped>)`
 * marker, strings and map keys are cut to STRING_LIMIT and KEY_LIMIT characters, and a whole
 * document never exceeds MAX_BYTES of JSON: document() always terminates, falling back from
 * list markers to map markers (`_truncated`, `_omitted`), to omitting the largest top-level
 * entries, and as a last resort to the fixed envelope `array('_truncated' => true)`. Applying value() to an already bounded structure is a no-op: an
 * existing trailing marker is kept (and its count extended) instead of being counted as an
 * ordinary item.
 */
final class Bounded {
    public const LIST_LIMIT = 20;
    public const STRING_LIMIT = 500;
    public const KEY_LIMIT = 100;

    /** Upper bound of the encoded `"_truncated":true,"_omitted":<n>` marker with its commas. */
    private const MARKER_BYTES = 48;
    public const MAX_BYTES = 32768;

    /**
     * @param mixed $value Any JSON-shaped value.
     * @return mixed The value with long lists and strings cut.
     */
    public static function value($value) {
        if (is_string($value)) {
            return self::cut($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!self::is_list($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $short = is_string($key) ? self::cut($key, self::KEY_LIMIT) : $key;
                if (!array_key_exists($short, $out)) {
                    $out[$short] = self::value($item);
                }
            }
            return $out;
        }

        $dropped = 0;
        $last = $value === array() ? null : $value[count($value) - 1];
        if (self::is_marker($last)) {
            $dropped = (int) $last['truncated'];
            array_pop($value);
        }
        if (count($value) > self::LIST_LIMIT) {
            $dropped += count($value) - self::LIST_LIMIT;
            $value = array_slice($value, 0, self::LIST_LIMIT);
        }
        $out = array();
        foreach ($value as $item) {
            $out[] = self::value($item);
        }
        if ($dropped > 0) {
            $out[] = array('truncated' => $dropped);
        }
        return $out;
    }

    /**
     * value(), then, while the JSON is over MAX_BYTES, the largest remaining list (or, when
     * only maps are left, the largest nested map) is replaced by a one-item truncated marker.
     *
     * @param array<array-key, mixed> $doc Document.
     * @return array<array-key, mixed>
     */
    public static function document(array $doc) {
        $bounded = self::value($doc);
        $bounded = is_array($bounded) ? $bounded : array();

        while (self::size($bounded) > self::MAX_BYTES) {
            $target = self::largest($bounded, array(), true);
            if ($target !== null) {
                $bounded = self::replace_at($bounded, $target['path'], array(array('truncated' => $target['count'])));
                continue;
            }
            $target = self::largest($bounded, array(), false);
            if ($target === null) {
                return self::omit_largest_entries($bounded);
            }
            $bounded = self::replace_at($bounded, $target['path'], array('_truncated' => true, '_omitted' => $target['count']));
        }
        return $bounded;
    }

    /**
     * Last-resort reduction of the top level: drop entries, largest first, until the
     * remainder plus the `_truncated` / `_omitted` marker fits; if nothing fits, the fixed
     * envelope.
     *
     * @param array<array-key, mixed> $doc Document with no replaceable nested array left.
     * @return array<array-key, mixed>
     */
    private static function omit_largest_entries(array $doc) {
        $sizes = array();
        foreach ($doc as $key => $item) {
            $sizes[$key] = self::entry_size($key, $item);
        }
        arsort($sizes);
        $estimate = array_sum($sizes);
        $omitted = 0;
        foreach (array_keys($sizes) as $key) {
            unset($doc[$key]);
            $omitted++;
            $estimate -= $sizes[$key];
            if ($estimate + self::MARKER_BYTES > self::MAX_BYTES) {
                continue;
            }
            $candidate = $doc;
            $candidate['_truncated'] = true;
            $candidate['_omitted'] = $omitted;
            if (self::size($candidate) <= self::MAX_BYTES) {
                return $candidate;
            }
        }
        return array('_truncated' => true);
    }

    /**
     * @param int|string $key  Key.
     * @param mixed      $item Value.
     * @return int Approximate encoded bytes of the entry; over the limit when it cannot be encoded.
     */
    private static function entry_size($key, $item) {
        $encoded_key = wp_json_encode($key);
        $encoded_item = wp_json_encode($item);
        if (!is_string($encoded_key) || !is_string($encoded_item)) {
            return self::MAX_BYTES + 1;
        }
        return strlen($encoded_key) + strlen($encoded_item) + 2;
    }

    /**
     * @param array<array-key, mixed> $doc Document.
     * @return int Encoded length; one past MAX_BYTES when the document cannot be encoded.
     */
    private static function size(array $doc) {
        $encoded = wp_json_encode($doc);
        return is_string($encoded) ? strlen($encoded) : self::MAX_BYTES + 1;
    }

    /**
     * @param mixed                  $node       Node.
     * @param array<int, int|string> $path       Path to the node.
     * @param bool                   $lists_only True: only lists are eligible; false: only maps.
     * @return array{path:array<int, int|string>, size:int, count:int}|null Largest eligible nested array.
     */
    private static function largest($node, array $path, $lists_only) {
        if (!is_array($node)) {
            return null;
        }
        $best = null;
        foreach ($node as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            $child_path = array_merge($path, array($key));
            $is_list = self::is_list($child);
            $is_collapsed = $is_list ? (count($child) === 1 && self::is_marker($child[0])) : self::is_map_marker($child);
            if ($is_list === $lists_only && !$is_collapsed) {
                $best = self::bigger($best, array('path' => $child_path, 'size' => self::size($child), 'count' => count($child)));
            }
            $best = self::bigger($best, self::largest($child, $child_path, $lists_only));
        }
        return $best;
    }

    /**
     * @param array{path:array<int, int|string>, size:int, count:int}|null $a First.
     * @param array{path:array<int, int|string>, size:int, count:int}|null $b Second.
     * @return array{path:array<int, int|string>, size:int, count:int}|null
     */
    private static function bigger($a, $b) {
        if ($a === null || ($b !== null && $b['size'] > $a['size'])) {
            return $b;
        }
        return $a;
    }

    /**
     * @param array<array-key, mixed> $doc         Document.
     * @param array<int, int|string>  $path        Non-empty path.
     * @param mixed                   $replacement New value.
     * @return array<array-key, mixed> A copy with the node at $path replaced.
     */
    private static function replace_at(array $doc, array $path, $replacement) {
        $key = array_shift($path);
        if ($path === array()) {
            $doc[$key] = $replacement;
            return $doc;
        }
        $child = isset($doc[$key]) && is_array($doc[$key]) ? $doc[$key] : array();
        $doc[$key] = self::replace_at($child, $path, $replacement);
        return $doc;
    }

    /**
     * @param string $text  Text.
     * @param int    $limit Characters to keep.
     * @return string At most $limit characters.
     */
    private static function cut($text, $limit = self::STRING_LIMIT) {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    /**
     * @param array<array-key, mixed> $items Array.
     * @return bool
     */
    private static function is_list(array $items) {
        return $items === array() || array_keys($items) === range(0, count($items) - 1);
    }

    /**
     * @param array<array-key, mixed> $item Map.
     * @return bool True for `array('_truncated' => true, '_omitted' => <int>)`.
     */
    private static function is_map_marker(array $item) {
        return count($item) === 2 && ($item['_truncated'] ?? null) === true && isset($item['_omitted']) && is_int($item['_omitted']);
    }

    /**
     * @param mixed $item Candidate.
     * @return bool True for `array('truncated' => <int>)`.
     */
    private static function is_marker($item) {
        return is_array($item) && count($item) === 1 && isset($item['truncated']) && is_int($item['truncated']);
    }

    private function __construct() {}
}
