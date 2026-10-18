<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

defined('ABSPATH') || exit;

/**
 * The result of one Onboarding step (Sub-project 0c), independent of any UI.
 *
 * `code()` is the `wp supcheckout takeover` exit code (the table in CliCommands' docblock),
 * `reason()` a stable machine-readable cause for a failure (null on success), `message()`
 * the text the CLI prints after `Error: ` / `Success: `, `data()` structured details, and
 * `lines()` the report lines the CLI prints before that message. Immutable.
 */
final class Outcome {
    public const OK = 0;
    public const ERROR = 1;
    public const BLOCKED = 2;
    public const MISMATCH = 3;
    public const BUSY = 4;
    public const STALE_CLAIMS = 5;
    public const STATE = 6;
    public const OFFICIAL_STILL_ACTIVE = 7;
    public const CONFLICTS = 8;
    public const OFFICIAL_NOT_REACTIVATED = 9;

    /** @var int */
    private $code;
    /** @var string|null */
    private $reason;
    /** @var string */
    private $message;
    /** @var array<string, mixed> */
    private $data;
    /** @var list<string> */
    private $lines;

    /**
     * @param int                  $code    Exit code.
     * @param string|null          $reason  Machine-readable cause; null on success.
     * @param string               $message Text after `Error: ` / `Success: `.
     * @param array<string, mixed> $data    Structured details.
     * @param array<int, string>   $lines   Report lines printed before the message.
     */
    public function __construct($code, $reason, $message, array $data = array(), array $lines = array()) {
        $this->code = (int) $code;
        $this->reason = $reason === null ? null : (string) $reason;
        $this->message = (string) $message;
        $this->data = $data;
        $this->lines = array_values(array_map('strval', $lines));
    }

    /**
     * @param string               $message Text after `Success: `.
     * @param array<string, mixed> $data    Structured details.
     * @param array<int, string>   $lines   Report lines.
     * @return self
     */
    public static function ok($message, array $data = array(), array $lines = array()) {
        return new self(self::OK, null, $message, $data, $lines);
    }

    /**
     * @param int                  $code    Exit code.
     * @param string               $reason  Machine-readable cause.
     * @param string               $message Text after `Error: `.
     * @param array<string, mixed> $data    Structured details.
     * @param array<int, string>   $lines   Report lines.
     * @return self
     */
    public static function fail($code, $reason, $message, array $data = array(), array $lines = array()) {
        return new self($code, $reason, $message, $data, $lines);
    }

    /** @return int */
    public function code() {
        return $this->code;
    }

    /** @return string|null */
    public function reason() {
        return $this->reason;
    }

    /** @return string */
    public function message() {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function data() {
        return $this->data;
    }

    /** @return list<string> */
    public function lines() {
        return $this->lines;
    }

    /** @return bool */
    public function is_ok() {
        return $this->code === self::OK;
    }
}
