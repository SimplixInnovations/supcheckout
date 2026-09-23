<?php

namespace Simplixi\SUPCheckout\Provider;

/**
 * Pure UPayments multi-merchant lexical contract.
 *
 * This boundary owns only provider-facing value grammar shared by merchant
 * settings validation and checkout orchestration. It performs no transport,
 * persistence, WooCommerce lookup, sanitization, or value rewriting.
 */
final class MultiMerchantContract {
    private const MAX_COMMISSION_TOKEN_LENGTH = 22;

    /**
     * Validate the conservative UPayments IBAN boundary used by checkout.
     *
     * @param mixed $value Raw IBAN candidate.
     * @return bool
     */
    public static function is_valid_iban($value) {
        return is_string($value)
            && preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $value) === 1;
    }

    /**
     * Validate the canonical UPayments main-merchant commission grammar.
     *
     * Zero is provider-permitted. Signs, exponent notation, whitespace,
     * commas and leading-zero ambiguity fail closed. Length is deliberately
     * checked separately so checkout can preserve its existing two-stage
     * lexical-then-serialization failure semantics.
     *
     * @param mixed $value Raw commission candidate.
     * @return bool
     */
    public static function is_valid_commission_lexeme($value) {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $value) === 1;
    }

    /**
     * Validate a complete provider-bound commission JSON-number token.
     *
     * @param mixed $value Raw commission candidate.
     * @return bool
     */
    public static function is_valid_commission_token($value) {
        return self::is_valid_commission_lexeme($value)
            && strlen($value) <= self::MAX_COMMISSION_TOKEN_LENGTH;
    }

    /**
     * Validate the exact provider charge-type allowlist.
     *
     * @param mixed $value Raw charge-type candidate.
     * @return bool
     */
    public static function is_valid_charge_type($value) {
        return is_string($value)
            && in_array($value, array('fixed', 'percentage'), true);
    }

    private function __construct() {
    }
}
