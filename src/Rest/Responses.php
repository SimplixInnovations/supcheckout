<?php

namespace Simplixi\SUPCheckout\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;

defined('ABSPATH') || exit;

/**
 * Response building for the supcheckout/v1 admin routes (Sub-project 0c Task 7).
 *
 * - Success envelope `{ok: true, data, warnings}`, the whole document through
 *   Bounded::document() (at most 32768 bytes of JSON, lists over 20 truncated), with
 *   `Cache-Control: no-store, private`. A WP_Error loses helper headers when the REST
 *   server turns it into a response, so Routes::no_store() sets the header again on every
 *   dispatched supcheckout/v1 response.
 * - An Outcome is mapped by its exit code (CliCommands' table): 0 → 200; 8 and 9 → 200 with
 *   a `conflicts` / `official_not_reactivated` warning; any other code → a WP_Error whose
 *   data is `{status, exit_code, reason, hint?, days_left?}`.
 * - Outcome::data() is never sent wholesale: its `result` and `report` are raw engine
 *   results. Only OUTCOME_FIELDS are projected.
 * - cap() bounds a document before Bounded::document() in one linear pass: at most
 *   MAP_LIMIT entries per map and LIST_LIMIT items per list, so a store with thousands of
 *   map entries each holding a list never makes response building super-linear.
 */
final class Responses {
    public const NO_STORE = 'no-store, private';
    /** Most entries cap() keeps per map. */
    public const MAP_LIMIT = 50;

    /** Exit code => HTTP status and error code. Anything unlisted is 500 supcheckout_error. */
    private const ERRORS = array(
        Outcome::BLOCKED => array(409, 'supcheckout_blocked'),
        Outcome::MISMATCH => array(409, 'supcheckout_mismatch'),
        Outcome::BUSY => array(409, 'supcheckout_busy'),
        Outcome::STALE_CLAIMS => array(409, 'supcheckout_stale_claims'),
        Outcome::STATE => array(409, 'supcheckout_state'),
        Outcome::OFFICIAL_STILL_ACTIVE => array(409, 'supcheckout_official_still_active'),
    );

    /** Exit codes that are a 200 with a warning: the step happened. */
    private const WARNINGS = array(
        Outcome::CONFLICTS => 'conflicts',
        Outcome::OFFICIAL_NOT_REACTIVATED => 'official_not_reactivated',
    );

    /** The Outcome::data() keys a response may carry: names, states, counts and times only. */
    private const OUTCOME_FIELDS = array('status', 'next', 'mode', 'resuming', 'retire_official', 'due_at', 'days_left', 'released', 'skipped', 'official_plugin', 'deactivated', 'resume_at');

    /**
     * @param array<string, mixed> $data     Payload.
     * @param int                  $status   HTTP status.
     * @param array<int, string>   $warnings Warning codes.
     * @return \WP_REST_Response
     */
    public static function ok(array $data, $status = 200, array $warnings = array()) {
        $document = Bounded::document(array('ok' => true, 'data' => $data, 'warnings' => array_values($warnings)));
        $response = new \WP_REST_Response($document, (int) $status);
        $response->header('Cache-Control', self::NO_STORE);
        return $response;
    }

    /**
     * @param string               $code    Error code.
     * @param string               $message Message.
     * @param int                  $status  HTTP status.
     * @param array<string, mixed> $data    Extra data (bounded).
     * @return \WP_Error
     */
    public static function error($code, $message, $status, array $data = array()) {
        return new \WP_Error((string) $code, (string) $message, array('status' => (int) $status) + Bounded::document($data));
    }

    /**
     * @param Outcome              $outcome Outcome of a synchronous Onboarding call.
     * @param array<string, mixed> $extra   Merged into the success data, or `hint` into the error data.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function from_outcome(Outcome $outcome, array $extra = array()) {
        $code = $outcome->code();
        $data = $outcome->data();
        if ($code === Outcome::OK || isset(self::WARNINGS[$code])) {
            $payload = array_merge(self::project($data, self::OUTCOME_FIELDS), array('message' => $outcome->message()), $extra);
            return self::ok($payload, 200, isset(self::WARNINGS[$code]) ? array(self::WARNINGS[$code]) : array());
        }
        list($status, $error) = isset(self::ERRORS[$code]) ? self::ERRORS[$code] : array(500, 'supcheckout_error');
        $error_data = array('exit_code' => $code, 'reason' => $outcome->reason());
        if (isset($extra['hint']) && is_string($extra['hint'])) {
            $error_data['hint'] = $extra['hint'];
        }
        if (isset($data['days_left']) && is_int($data['days_left'])) {
            $error_data['days_left'] = $data['days_left'];
        }
        return self::error($error, $outcome->message(), $status, $error_data);
    }

    /**
     * @param array<array-key, mixed> $source Source.
     * @param array<int, string>      $keys   Allowed keys, in output order.
     * @return array<string, mixed> Only the allowed keys present in $source.
     */
    public static function project(array $source, array $keys) {
        $out = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }
        return $out;
    }

    /**
     * Bound a document in one linear pass: lists keep LIST_LIMIT items plus a
     * `{truncated: n}` marker (Bounded's own marker, which it then extends), maps keep
     * MAP_LIMIT entries plus `_truncated` / `_omitted`. Dropped items are never visited.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    public static function cap($value) {
        if (!is_array($value)) {
            return $value;
        }
        $count = count($value);
        $is_list = $value === array() || array_keys($value) === range(0, $count - 1);
        $dropped = 0;
        if ($is_list && $count > 0 && self::is_marker($value[$count - 1])) {
            // An already bounded list: keep counting what it dropped.
            $dropped = (int) $value[$count - 1]['truncated'];
            array_pop($value);
            --$count;
        }
        $limit = $is_list ? Bounded::LIST_LIMIT : self::MAP_LIMIT;
        $out = array();
        foreach (array_slice($value, 0, $limit, true) as $key => $item) {
            $out[$key] = self::cap($item);
        }
        $dropped += max(0, $count - $limit);
        if ($is_list && $dropped > 0) {
            $out[] = array('truncated' => $dropped);
        } elseif (!$is_list && $count > $limit) {
            $out['_truncated'] = true;
            $out['_omitted'] = $count - $limit;
        }
        return $out;
    }

    /**
     * @param mixed $item Candidate.
     * @return bool True for Bounded's `array('truncated' => <int>)` list marker.
     */
    private static function is_marker($item) {
        return is_array($item) && count($item) === 1 && isset($item['truncated']) && is_int($item['truncated']);
    }

    private function __construct() {}
}
