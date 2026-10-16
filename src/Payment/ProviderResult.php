<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

/**
 * Deterministic UPayments transaction-result classifier.
 *
 * Only exact provider-documented values receive terminal semantics. Unknown
 * values remain indeterminate so a future provider result can never be guessed
 * into paid/failed state.
 */
final class ProviderResult {
    public const CAPTURED = 'CAPTURED';
    public const PENDING = 'PENDING';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';
    public const INDETERMINATE = 'INDETERMINATE';

    /**
     * @param mixed $result Provider transaction result.
     * @return string One of this class's local classification constants.
     */
    public static function classify($result) {
        if (!is_string($result) || $result === '') {
            return self::INDETERMINATE;
        }

        switch ($result) {
            case 'CAPTURED':
                return self::CAPTURED;

            case 'PENDING':
            case 'AUTHORIZED':
            case 'APPROVED':
                return self::PENDING;

            case 'NOT CAPTURED':
            case 'FAILED':
            case 'FAILURE':
            case 'ERROR':
                return self::FAILED;

            // Both spellings, as handled by the official UPayments plugin.
            case 'CANCELED':
            case 'CANCELLED':
                return self::CANCELLED;

            case 'REFUND':
            case 'VOIDED':
            default:
                return self::INDETERMINATE;
        }
    }

    private function __construct() {
    }
}
