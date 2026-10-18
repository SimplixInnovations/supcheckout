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
     * value(), then, while the JSON is over MAX_BYTES:
     *  1. the largest lists are replaced by a one-item truncated marker, largest first;
     *  2. the map with the most entries loses its largest entries, replaced by a
     *     `_truncated` / `_omitted` marker, until the excess is covered;
     *  3. when nothing is left to trim, the fixed envelope `array('_truncated' => true)`.
     *
     * Each phase works from sizes measured once, so the number of full encodes stays small
     * however many entries the document has.
     *
     * @param array<array-key, mixed> $doc Document.
     * @return array<array-key, mixed>
     */
    public static function document(array $doc) {
        $bounded = self::value($doc);
        $bounded = is_array($bounded) ? $bounded : array();

        if (self::size($bounded) > self::MAX_BYTES) {
            $bounded = self::collapse_lists($bounded);
        }
        while (($excess = self::size($bounded) - self::MAX_BYTES) > 0) {
            $target = self::fullest_map($bounded, array());
            if ($target === null) {
                return array('_truncated' => true);
            }
            $bounded = self::trim_map_at($bounded, $target['path'], $excess);
        }
        return $bounded;
    }

    /**
     * Replace lists with a truncated marker, largest first, until the document fits or no
     * list is left. Sizes are measured once; descendants of a replaced list are skipped.
     *
     * @param array<array-key, mixed> $doc Document.
     * @return array<array-key, mixed>
     */
    private static function collapse_lists(array $doc) {
        $candidates = array();
        self::collect_lists($doc, array(), $candidates);
        usort(
            $candidates,
            static function ($a, $b) {
                return $b['size'] <=> $a['size'];
            }
        );

        $estimate = self::size($doc);
        $replaced = array();
        foreach ($candidates as $candidate) {
            if (self::is_under($candidate['path'], $replaced)) {
                continue;
            }
            $marker = array(array('truncated' => $candidate['count']));
            $doc = self::replace_at($doc, $candidate['path'], $marker);
            $replaced[] = $candidate['path'];
            $estimate += self::size($marker) - $candidate['size'];
            if ($estimate <= self::MAX_BYTES && self::size($doc) <= self::MAX_BYTES) {
                break;
            }
        }
        return $doc;
    }

    /**
     * @param mixed                                                         $node       Node.
     * @param array<int, int|string>                                        $path       Path to the node.
     * @param list<array{path:array<int, int|string>, size:int, count:int}> $candidates Collected lists.
     * @return void
     */
    private static function collect_lists($node, array $path, array &$candidates) {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            $child_path = array_merge($path, array($key));
            $collapsed = count($child) === 1 && isset($child[0]) && self::is_marker($child[0]);
            if (self::is_list($child) && !$collapsed) {
                $candidates[] = array('path' => $child_path, 'size' => self::size($child), 'count' => count($child));
            }
            self::collect_lists($child, $child_path, $candidates);
        }
    }

    /**
     * @param array<int, int|string>       $path     Path.
     * @param list<array<int, int|string>> $replaced Paths already replaced.
     * @return bool True when $path is inside one of them.
     */
    private static function is_under(array $path, array $replaced) {
        foreach ($replaced as $prefix) {
            if (array_slice($path, 0, count($prefix)) === $prefix) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed                  $node Node.
     * @param array<int, int|string> $path Path to the node.
     * @return array{path:array<int, int|string>, count:int}|null The map (maybe the root, empty
     *         path) with the most trimmable entries; null when no map has any.
     */
    private static function fullest_map($node, array $path) {
        if (!is_array($node)) {
            return null;
        }
        $count = self::trimmable_count($node);
        $best = $count > 0 ? array('path' => $path, 'count' => $count) : null;
        foreach ($node as $key => $child) {
            $found = self::fullest_map($child, array_merge($path, array($key)));
            if ($found !== null && ($best === null || $found['count'] > $best['count'])) {
                $best = $found;
            }
        }
        return $best;
    }

    /**
     * @param mixed $map Node.
     * @return int Entries of a map other than its own marker keys; 0 for lists and scalars.
     */
    private static function trimmable_count($map) {
        if (!is_array($map) || self::is_list($map)) {
            return 0;
        }
        return count($map) - (int) array_key_exists('_truncated', $map) - (int) array_key_exists('_omitted', $map);
    }

    /**
     * Trim the map at $path (the root for an empty path) by omitting its largest entries
     * until at least $excess bytes (plus the marker) are gone.
     *
     * @param array<array-key, mixed> $doc    Document.
     * @param array<int, int|string>  $path   Path to a map.
     * @param int                     $excess Bytes the document is over the limit.
     * @return array<array-key, mixed>
     */
    private static function trim_map_at(array $doc, array $path, $excess) {
        if ($path !== array()) {
            $key = array_shift($path);
            $child = isset($doc[$key]) && is_array($doc[$key]) ? $doc[$key] : array();
            $doc[$key] = self::trim_map_at($child, $path, $excess);
            return $doc;
        }

        $omitted = isset($doc['_omitted']) && is_int($doc['_omitted']) ? $doc['_omitted'] : 0;
        $sizes = array();
        foreach ($doc as $key => $item) {
            if ($key !== '_truncated' && $key !== '_omitted') {
                $sizes[$key] = self::entry_size($key, $item);
            }
        }
        arsort($sizes);
        $needed = $excess + self::MARKER_BYTES;
        foreach ($sizes as $key => $size) {
            unset($doc[$key]);
            $omitted++;
            $needed -= $size;
            if ($needed <= 0) {
                break;
            }
        }
        $doc['_truncated'] = true;
        $doc['_omitted'] = $omitted;
        return $doc;
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
     * @param mixed $item Candidate.
     * @return bool True for `array('truncated' => <int>)`.
     */
    private static function is_marker($item) {
        return is_array($item) && count($item) === 1 && isset($item['truncated']) && is_int($item['truncated']);
    }

    private function __construct() {}
}
