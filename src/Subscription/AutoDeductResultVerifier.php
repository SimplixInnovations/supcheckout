<?php

namespace Simplixi\SUPCheckout\Subscription;

/**
 * Auto-deduct response verifier.
 *
 * Never performs provider transport. Classifies a parsed provider response
 * against the immutable expected cycle snapshot.
 *
 * Capture-authority note: Phase 8C records that a truthy top-level `status`
 * is historical compatibility, not authenticated capture. Until first-party
 * UPayments auto-deduct capture semantics are proven, a success-shaped
 * response that cannot bind to the expected cycle is unresolved/HELD.
 */
final class AutoDeductResultVerifier
{
    public const VERIFIED_SUCCESS      = 'verified_success';
    public const DEFINITIVE_FAILURE    = 'definitive_failure';
    public const UNRESOLVED            = 'unresolved';
    public const MALFORMED             = 'malformed';
    public const BINDING_MISMATCH      = 'binding_mismatch';

    /**
     * @param mixed  $response Decoded provider response body.
     * @param array  $expected {amount,currency,parent_id,cycle_key,reference_id}
     * @return array{outcome:string,reason:string,payment_id:?string,paid_amount:?string,paid_currency:?string}
     */
    public static function verify($response, array $expected)
    {
        if (!is_array($response)) {
            return self::result(self::MALFORMED, 'response_not_object');
        }

        // Definitive provider-negative top-level status (documented as falsy).
        if (array_key_exists('status', $response) && $response['status'] === false) {
            return self::result(self::DEFINITIVE_FAILURE, 'provider_status_false');
        }

        if (!isset($response['data']) || !is_array($response['data'])
            || !isset($response['data']['transaction']) || !is_array($response['data']['transaction'])
        ) {
            return self::result(self::MALFORMED, 'missing_transaction');
        }

        $transaction = $response['data']['transaction'];

        if (!array_key_exists('paymentId', $transaction) || !is_scalar($transaction['paymentId'])) {
            return self::result(self::MALFORMED, 'payment_id_invalid');
        }
        $payment_id = trim((string) $transaction['paymentId']);
        if ($payment_id === '') {
            return self::result(self::MALFORMED, 'payment_id_empty');
        }

        if (!array_key_exists('paid_amount', $transaction)) {
            return self::result(self::MALFORMED, 'paid_amount_missing');
        }
        $paid_amount = CycleEconomics::canonical_decimal($transaction['paid_amount']);
        if ($paid_amount === null) {
            return self::result(self::MALFORMED, 'paid_amount_invalid');
        }

        if (!array_key_exists('paid_currency', $transaction) || !is_string($transaction['paid_currency'])) {
            return self::result(self::MALFORMED, 'paid_currency_missing');
        }
        $paid_currency = CycleEconomics::canonical_currency($transaction['paid_currency']);
        if ($paid_currency === null) {
            return self::result(self::MALFORMED, 'paid_currency_invalid');
        }

        $expected_amount = isset($expected['amount']) ? CycleEconomics::canonical_decimal($expected['amount']) : null;
        $expected_currency = isset($expected['currency']) ? CycleEconomics::canonical_currency($expected['currency']) : null;
        if ($expected_amount === null || $expected_currency === null) {
            return self::result(self::MALFORMED, 'expected_snapshot_invalid');
        }

        if (!CycleEconomics::amounts_equal($paid_amount, $expected_amount)) {
            return self::result(self::BINDING_MISMATCH, 'amount', $payment_id, $paid_amount, $paid_currency);
        }
        if (!CycleEconomics::currencies_equal($paid_currency, $expected_currency)) {
            return self::result(self::BINDING_MISMATCH, 'currency', $payment_id, $paid_amount, $paid_currency);
        }

        // Expected local snapshot validation (not remote parent/cycle binding).
        if (isset($expected['parent_id']) && (int) $expected['parent_id'] <= 0) {
            return self::result(self::MALFORMED, 'expected_parent_invalid', $payment_id, $paid_amount, $paid_currency);
        }
        if (isset($expected['cycle_key']) && (!is_string($expected['cycle_key']) || $expected['cycle_key'] === '')) {
            return self::result(self::MALFORMED, 'expected_cycle_invalid', $payment_id, $paid_amount, $paid_currency);
        }

        // Optional merchant reference echo check. This is NOT proof of exact
        // remote cycle identity unless a cycle-unique merchant identifier is
        // documented as echoed by the auto-deduct endpoint.
        if (isset($transaction['reference']) && is_scalar($transaction['reference'])) {
            $reference = trim((string) $transaction['reference']);
            if ($reference !== ''
                && isset($expected['reference_id'])
                && is_string($expected['reference_id'])
                && $expected['reference_id'] !== ''
                && !hash_equals($expected['reference_id'], $reference)
            ) {
                return self::result(self::BINDING_MISMATCH, 'reference', $payment_id, $paid_amount, $paid_currency);
            }
        }

        // Capture authority remains unproven for auto-deduct from truthy status
        // alone (Phase 8C). Structural success + exact binding is still not an
        // authenticated CAPTURED proof. Classify as unresolved so the cycle is
        // HELD for reconciliation unless a future first-party capture contract
        // is approved.
        if (!array_key_exists('status', $response) || !$response['status']) {
            return self::result(self::UNRESOLVED, 'status_not_success', $payment_id, $paid_amount, $paid_currency);
        }

        return self::result(
            self::UNRESOLVED,
            'capture_authority_unproven_for_auto_deduct',
            $payment_id,
            $paid_amount,
            $paid_currency
        );
    }

    private static function result($outcome, $reason, $payment_id = null, $paid_amount = null, $paid_currency = null)
    {
        return array(
            'outcome'      => (string) $outcome,
            'reason'       => (string) $reason,
            'payment_id'   => $payment_id,
            'paid_amount'  => $paid_amount,
            'paid_currency' => $paid_currency,
        );
    }

    private function __construct()
    {
    }
}
