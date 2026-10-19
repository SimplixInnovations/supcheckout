<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Payment\OrderLock;

defined('ABSPATH') || exit;

/**
 * The persisted single-flight onboarding operation record (Sub-project 0c).
 *
 * One non-autoloaded JSON option, created with OrderLock::insert_if_absent() and changed
 * only with OrderLock::replace_if_current() against the exact stored string (the
 * TakeoverState pattern), so of two requests racing start() or update() exactly one wins.
 * The option cache is flushed before every read. An unreadable stored record reads as
 * outcome 'invalid' and is never overwritten.
 *
 * Record: id (uuid4), kind, consent (three strict booleans), user_id, started_at,
 * updated_at, outcome, phase, steps (last 50 strings), attempts (string => int),
 * busy_retries, cooldown_retries, error, hint, next_at, report, self_test.
 *
 * The record holds no secret and no customer personal data: callers pass only step names,
 * counts, codes and the bounded engine reports (names, codes, counts and numeric order/user IDs). Every report and free-form field goes through
 * Bounded::document(), and every string in steps is cut to Bounded::STRING_LIMIT.
 */
final class Operation {
    public const OPTION = 'supcheckout_onboarding_operation_v1';

    public const KIND_PREFLIGHT = 'preflight';
    public const KIND_SWITCH = 'switch';
    public const KIND_ROLLBACK = 'rollback';
    public const KIND_ABORT = 'abort';
    public const KIND_FINALIZE = 'finalize';
    public const KIND_FINALIZE_DRY_RUN = 'finalize_dry_run';
    public const KIND_SELF_TEST = 'self_test';
    public const KIND_SETUP_CHECKS = 'setup_checks';

    public const RUNNING = 'running';
    public const DONE = 'done';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';
    /** Reported by current() for an unreadable stored record; never stored. */
    public const INVALID = 'invalid';

    /** Most steps a record keeps (the newest). */
    public const STEPS_LIMIT = 50;

    private const KINDS = array(
        self::KIND_PREFLIGHT,
        self::KIND_SWITCH,
        self::KIND_ROLLBACK,
        self::KIND_ABORT,
        self::KIND_FINALIZE,
        self::KIND_FINALIZE_DRY_RUN,
        self::KIND_SELF_TEST,
        self::KIND_SETUP_CHECKS,
    );

    private const FINISHED = array(self::DONE, self::BLOCKED, self::FAILED);

    private const CONSENT_KEYS = array('deactivate_official', 'reactivate_official', 'force_after_review');

    /** Fields update() may change, with the type each must have. */
    private const UPDATABLE = array(
        'phase' => 'string',
        'steps' => 'list',
        'attempts' => 'map',
        'busy_retries' => 'int',
        'cooldown_retries' => 'int',
        'error' => '?string',
        'hint' => '?string',
        'next_at' => 'int',
        'report' => 'array',
        'self_test' => '?array',
    );

    /**
     * The stored operation, or null when none was ever started.
     *
     * @return array<string, mixed>|null A record with outcome 'invalid' when unreadable.
     */
    public static function current() {
        $raw = self::read_raw();
        if ($raw === null) {
            return null;
        }
        return self::decode($raw) ?? self::defaults(self::INVALID);
    }

    /**
     * Start an operation unless one is running or the record is unreadable.
     *
     * @param string               $kind    One of the KIND_* constants.
     * @param array<string, mixed> $consent Only the three consent keys survive, each true only when strictly true.
     * @param int                  $user_id Acting user.
     * @param int                  $now     Current unix time.
     * @return array<string, mixed>|null The new record, null when refused or lost to a concurrent start.
     */
    public static function start($kind, array $consent, $user_id, $now) {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        $raw = self::read_raw();
        $existing = $raw === null ? null : self::decode($raw);
        if ($raw !== null && ($existing === null || self::is_running($existing))) {
            return null;
        }

        $record = self::defaults(self::RUNNING);
        $record['id'] = self::uuid4();
        $record['kind'] = $kind;
        $record['consent'] = self::consent($consent);
        $record['user_id'] = (int) $user_id;
        $record['started_at'] = (int) $now;
        $record['updated_at'] = (int) $now;
        $record['phase'] = 'started';

        return self::write($raw, $record) ? $record : null;
    }

    /**
     * Merge progress fields into the running operation with a compare-and-swap.
     *
     * Only the UPDATABLE fields, each with its declared type, are accepted; steps replaces the
     * stored list and keeps its last 50. Refuses another operation's ID, a finished operation
     * and an unreadable record.
     *
     * @param string               $id     Operation ID returned by start().
     * @param array<string, mixed> $fields Fields to change.
     * @param int                  $now    Current unix time.
     * @return bool True only when this call persisted the change.
     */
    public static function update($id, array $fields, $now) {
        $raw = self::read_raw();
        $current = is_string($raw) ? self::decode($raw) : null;
        if ($current === null || $current['id'] !== $id || !self::is_running($current)) {
            return false;
        }
        $clean = self::clean_fields($fields);
        if ($clean === null) {
            return false;
        }

        $next = array_merge($current, $clean);
        $next['updated_at'] = (int) $now;
        return self::write($raw, $next);
    }

    /**
     * End the running operation with a final outcome.
     *
     * @param string               $id      Operation ID.
     * @param string               $outcome DONE, BLOCKED or FAILED.
     * @param string|null          $error   Error code or short message.
     * @param string|null          $hint    What the owner should do next.
     * @param array<string, mixed> $report  Final bounded report.
     * @param int                  $now     Current unix time.
     * @return bool True only when this call persisted the outcome.
     */
    public static function finish($id, $outcome, $error, $hint, array $report, $now) {
        if (!in_array($outcome, self::FINISHED, true)) {
            return false;
        }
        $raw = self::read_raw();
        $current = is_string($raw) ? self::decode($raw) : null;
        if ($current === null || $current['id'] !== $id || !self::is_running($current)) {
            return false;
        }
        $clean = self::clean_fields(array('error' => $error, 'hint' => $hint, 'report' => $report));
        if ($clean === null) {
            return false;
        }

        $next = array_merge($current, $clean);
        $next['outcome'] = $outcome;
        $next['updated_at'] = (int) $now;
        return self::write($raw, $next);
    }

    /**
     * @param array<string, mixed>|null $op A record from current() or start().
     * @return bool
     */
    public static function is_running($op) {
        return is_array($op) && isset($op['outcome']) && $op['outcome'] === self::RUNNING;
    }

    /**
     * @return string A random RFC 4122 version 4 UUID.
     */
    private static function uuid4() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * @param array<string, mixed> $consent Caller consent.
     * @return array<string, bool>
     */
    private static function consent(array $consent) {
        $out = array();
        foreach (self::CONSENT_KEYS as $key) {
            $out[$key] = isset($consent[$key]) && $consent[$key] === true;
        }
        return $out;
    }

    /**
     * Type-check and bound caller fields.
     *
     * @param array<array-key, mixed> $fields Fields.
     * @return array<string, mixed>|null Storable fields; null when a key is not updatable or a type is wrong.
     */
    private static function clean_fields(array $fields) {
        $steps = null;
        if (array_key_exists('steps', $fields)) {
            $steps = $fields['steps'];
            unset($fields['steps']);
        }
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !isset(self::UPDATABLE[$key]) || !self::has_type($value, self::UPDATABLE[$key])) {
                return null;
            }
        }
        if ($steps !== null && !self::has_type($steps, 'list')) {
            return null;
        }

        $clean = Bounded::document($fields);
        if ($steps !== null) {
            $clean['steps'] = self::bounded_steps($steps);
        }
        return $clean;
    }

    /**
     * @param array<int, mixed> $steps Step names.
     * @return list<string> The last STEPS_LIMIT, each cut to the string limit.
     */
    private static function bounded_steps(array $steps) {
        $out = array();
        foreach (array_slice($steps, -self::STEPS_LIMIT) as $step) {
            $out[] = Bounded::value($step);
        }
        return $out;
    }

    /**
     * @param mixed  $value Value.
     * @param string $type  Declared type from UPDATABLE.
     * @return bool
     */
    private static function has_type($value, $type) {
        switch ($type) {
            case 'string':
                return is_string($value);
            case '?string':
                return $value === null || is_string($value);
            case 'int':
                return is_int($value);
            case 'array':
                return is_array($value);
            case '?array':
                return $value === null || is_array($value);
            case 'list':
                return is_array($value) && array_keys($value) === array_keys(array_values($value)) && self::all_strings($value);
            case 'map':
                return is_array($value) && self::all_int_values($value);
        }
        return false;
    }

    /**
     * @param array<mixed> $items Items.
     * @return bool
     */
    private static function all_strings(array $items) {
        foreach ($items as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<mixed> $items Items.
     * @return bool
     */
    private static function all_int_values(array $items) {
        foreach ($items as $key => $item) {
            if (!is_string($key) || !is_int($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string|null|false    $raw  Stored string, or null when there is no record yet.
     * @param array<string, mixed> $next New record.
     * @return bool
     */
    private static function write($raw, array $next) {
        $encoded = wp_json_encode($next);
        if (!is_string($encoded)) {
            return false;
        }
        if ($raw === null) {
            return OrderLock::insert_if_absent(self::OPTION, $encoded);
        }
        if (!is_string($raw)) {
            return false;
        }
        if ($encoded === $raw) {
            return true;
        }
        return OrderLock::replace_if_current(self::OPTION, $raw, $encoded);
    }

    /**
     * @return string|null|false The stored string, null when absent, false when unreadable.
     */
    private static function read_raw() {
        if (!function_exists('get_option')) {
            return null;
        }
        OrderLock::flush_option_cache(self::OPTION);
        $raw = get_option(self::OPTION, null);
        if ($raw === null || $raw === false) {
            return null;
        }
        return is_string($raw) ? $raw : false;
    }

    /**
     * @param string|false $raw Stored value from read_raw().
     * @return array<string, mixed>|null Null for an unreadable record.
     */
    private static function decode($raw) {
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !isset($decoded['id'], $decoded['kind'], $decoded['outcome'])
            || !is_string($decoded['id'])
            || !in_array($decoded['kind'], self::KINDS, true)
            || !in_array($decoded['outcome'], array_merge(array(self::RUNNING), self::FINISHED), true)
        ) {
            return null;
        }

        $record = array_merge(self::defaults($decoded['outcome']), $decoded);
        $record['consent'] = self::consent(is_array($record['consent']) ? $record['consent'] : array());
        foreach (array('user_id', 'started_at', 'updated_at', 'busy_retries', 'cooldown_retries', 'next_at') as $key) {
            $record[$key] = is_int($record[$key]) ? $record[$key] : 0;
        }
        foreach (array('steps', 'attempts', 'report') as $key) {
            $record[$key] = is_array($record[$key]) ? $record[$key] : array();
        }
        $record['self_test'] = is_array($record['self_test']) ? $record['self_test'] : null;
        foreach (array('error', 'hint') as $key) {
            $record[$key] = is_string($record[$key]) ? $record[$key] : null;
        }
        $record['phase'] = is_string($record['phase']) ? $record['phase'] : '';
        return $record;
    }

    /**
     * @param string $outcome Outcome.
     * @return array<string, mixed>
     */
    private static function defaults($outcome) {
        return array(
            'id' => '',
            'kind' => '',
            'consent' => array_fill_keys(self::CONSENT_KEYS, false),
            'user_id' => 0,
            'started_at' => 0,
            'updated_at' => 0,
            'outcome' => $outcome,
            'phase' => '',
            'steps' => array(),
            'attempts' => array(),
            'busy_retries' => 0,
            'cooldown_retries' => 0,
            'error' => null,
            'hint' => null,
            'next_at' => 0,
            'report' => array(),
            'self_test' => null,
        );
    }

    private function __construct() {}
}
