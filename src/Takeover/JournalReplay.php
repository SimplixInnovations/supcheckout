<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Reverse replay of the takeover journal (Sub-project 0b Task 10).
 *
 * Entry kinds and what Rollback does with each:
 * - audit-only (target `billing_claim`, or a restore source starting with `none:`): never
 *   replayed and never a conflict. `release-stale-claims` journals released claims this way
 *   (re-creating a released `claimed` row would block or re-open a cycle), and Rollback's
 *   own writes are journaled this way too;
 * - a new-name option copy (Keys::SETTINGS_OPTION and the Keys::options() new names): not
 *   restored here; the copy removal deletes it after the transition when it is still a copy
 *   (removable_copy()), otherwise it is a later edit and kept;
 * - `cron` twins: moved back by the copy removal after the transition;
 * - any other `option` entry (WooCommerce's gateway order / default gateway): restored here
 *   from its restore source, but only while is_current_after() confirms the path still holds
 *   the value the takeover wrote. A later edit is a conflict {seq, target, path}: kept, and
 *   older entries for the same path are skipped. A path that already holds the entry's
 *   before-value counts as restored (a crash, or a cutover rerun that journaled twice);
 * - any other target is unknown to this version: reported as a conflict, never touched.
 */
final class JournalReplay {
    public const AUDIT_RESTORE = 'none:rollback_restore';
    private const OPTION_SOURCE = 'option:';

    /**
     * @param array<string, mixed> $entry Journal entry.
     * @return bool True for an entry Rollback never replays.
     */
    public static function is_audit(array $entry) {
        return $entry['target'] === 'billing_claim' || strpos((string) $entry['restore_source'], 'none:') === 0;
    }

    /**
     * @param string $path Option name.
     * @return bool True for a new-name option the takeover copies into.
     */
    public static function is_copy_path($path) {
        return $path === Keys::SETTINGS_OPTION || in_array($path, Keys::options(), true);
    }

    /**
     * Entries of the current takeover cycle: at or after the floor the last completed
     * rollback or abort recorded, so a later rollback never replays an earlier cycle.
     *
     * @param array<string, mixed> $state TakeoverState::get().
     * @return list<array{seq:int, recorded_at:int, target:string, path:string, before_fingerprint:string, after_fingerprint:string, restore_source:string}>
     * @throws \RuntimeException When the journal is malformed.
     */
    public static function cycle_entries(array $state) {
        $floor = isset($state[Rollback::FLOOR]) && is_int($state[Rollback::FLOOR]) ? $state[Rollback::FLOOR] : 0;
        $entries = array();
        foreach (Journal::entries() as $entry) {
            if ($entry['seq'] >= $floor) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Restore every restorable `option` entry, newest first.
     *
     * @param list<array<string, mixed>> $entries Cycle entries, oldest first.
     * @return array{restored:int, conflicts:list<array{seq:int, target:string, path:string}>, failed:list<string>}
     * @throws \RuntimeException When an audit entry cannot be stored (nothing more is written).
     */
    public static function restore_options(array $entries) {
        $result = array('restored' => 0, 'conflicts' => array(), 'failed' => array());
        $done = array();
        foreach (array_reverse($entries) as $entry) {
            if (self::is_audit($entry) || $entry['target'] === 'cron' || isset($done[$entry['path']])) {
                continue;
            }
            if ($entry['target'] !== 'option') {
                $result['conflicts'][] = self::conflict($entry);
                continue;
            }
            if (self::is_copy_path($entry['path'])) {
                continue;
            }
            $outcome = self::restore_option($entry);
            if ($outcome === 'conflict') {
                $result['conflicts'][] = self::conflict($entry);
                $done[$entry['path']] = true;
            } elseif ($outcome === 'failed') {
                $result['failed'][] = $entry['path'];
                $done[$entry['path']] = true;
            } elseif ($outcome === 'restored') {
                ++$result['restored'];
            }
        }
        return $result;
    }

    /**
     * Whether a new-name option copy may be removed: absent, equal to its source (the legacy
     * option, or the first non-empty settings source), or holding a value the takeover
     * journaled as written. Anything else is a later edit.
     *
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @param string                     $path    New-name option.
     * @return bool
     */
    public static function removable_copy(array $entries, $path) {
        $missing = new \stdClass();
        $current = get_option($path, $missing);
        if ($current === $missing || $current === self::copy_source($path, $missing)) {
            return true;
        }
        $fingerprint = Journal::fingerprint($current);
        foreach ($entries as $entry) {
            if ($entry['target'] === 'option' && $entry['path'] === $path && hash_equals($entry['after_fingerprint'], $fingerprint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $entries Cycle entries.
     * @param string                     $target  Target.
     * @param string                     $path    Path.
     * @return int The newest entry's seq for that path, or -1.
     */
    public static function latest_seq(array $entries, $target, $path) {
        $seq = -1;
        foreach ($entries as $entry) {
            if ($entry['target'] === $target && $entry['path'] === $path) {
                $seq = (int) $entry['seq'];
            }
        }
        return $seq;
    }

    /**
     * @param array<string, mixed> $entry Option entry.
     * @return string 'restored', 'already', 'conflict' or 'failed'.
     */
    private static function restore_option(array $entry) {
        $missing = new \stdClass();
        $current = get_option($entry['path'], $missing);
        $current_value = $current === $missing ? null : $current;
        if (hash_equals((string) $entry['before_fingerprint'], Journal::fingerprint($current_value))) {
            return 'already';
        }
        if (!Journal::is_current_after($entry, $current_value)) {
            return 'conflict';
        }
        $source = (string) $entry['restore_source'];
        if (strpos($source, self::OPTION_SOURCE) !== 0) {
            return 'conflict';
        }
        $original = get_option(substr($source, strlen(self::OPTION_SOURCE)), $missing);
        if ($original === $missing || !hash_equals((string) $entry['before_fingerprint'], Journal::fingerprint($original))) {
            return 'conflict';
        }
        Journal::record('option', $entry['path'], $current_value, $original, self::AUDIT_RESTORE);
        update_option($entry['path'], $original);
        return get_option($entry['path'], $missing) === $original ? 'restored' : 'failed';
    }

    /**
     * @param string    $path    New-name option.
     * @param \stdClass $missing Sentinel.
     * @return mixed The value the copy was taken from, or $missing.
     */
    private static function copy_source($path, $missing) {
        if ($path === Keys::SETTINGS_OPTION) {
            foreach (Keys::settings_sources() as $name) {
                $value = get_option($name, null);
                if (is_array($value) && $value !== array()) {
                    return $value;
                }
            }
            return $missing;
        }
        $legacy = array_search($path, Keys::options(), true);
        return $legacy === false ? $missing : get_option((string) $legacy, $missing);
    }

    /**
     * @param array<string, mixed> $entry Entry.
     * @return array{seq:int, target:string, path:string}
     */
    private static function conflict(array $entry) {
        return array('seq' => (int) $entry['seq'], 'target' => (string) $entry['target'], 'path' => (string) $entry['path']);
    }

    private function __construct() {}
}
