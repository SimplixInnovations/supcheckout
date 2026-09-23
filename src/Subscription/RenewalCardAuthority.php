<?php

namespace Simplixi\SUPCheckout\Subscription;

/**
 * Explicit renewal card authority.
 *
 * Automatic renewals may only use an explicitly persisted provider card token.
 * Array-order "first card" selection is never authorization.
 */
final class RenewalCardAuthority
{
    public const STATE_EXPLICIT        = 'explicit';
    public const STATE_MISSING         = 'missing';
    public const STATE_REVOKED         = 'revoked';
    public const STATE_RETRIEVAL_FAILED = 'retrieval_failed';
    public const STATE_MALFORMED       = 'malformed';

    /**
     * @param object $order Order-like with get_meta().
     * @param callable $saved_cards_loader fn(string $customer_token): mixed
     * @return array{state:string,token:?string}
     */
    public static function resolve_explicit_token($order, callable $saved_cards_loader)
    {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return array('state' => self::STATE_MALFORMED, 'token' => null);
        }

        $stored = $order->get_meta('_upay_credit_card_token');
        if (!is_string($stored) || $stored === '' || preg_match('/\s/', $stored)) {
            return array('state' => self::STATE_MISSING, 'token' => null);
        }

        $customer_token = $order->get_meta('_upay_customer_unique_token');
        if (!is_string($customer_token) || $customer_token === '') {
            // Explicit token without a bound customer token cannot be safely
            // attributed to this renewal attempt.
            return array('state' => self::STATE_MALFORMED, 'token' => null);
        }

        $saved = call_user_func($saved_cards_loader, $customer_token);
        if (!is_array($saved)
            || !isset($saved['result'])
            || $saved['result'] !== 'success'
            || !isset($saved['data'])
            || !is_array($saved['data'])
        ) {
            // Pre-dispatch retrieval failure: never substitute another card.
            return array('state' => self::STATE_RETRIEVAL_FAILED, 'token' => null);
        }

        foreach ($saved['data'] as $card) {
            if (!is_array($card) || !isset($card['token']) || !is_string($card['token'])) {
                continue;
            }
            if (hash_equals($card['token'], $stored)) {
                return array('state' => self::STATE_EXPLICIT, 'token' => $stored);
            }
        }

        if (count($saved['data']) === 0) {
            return array('state' => self::STATE_REVOKED, 'token' => null);
        }

        return array('state' => self::STATE_REVOKED, 'token' => null);
    }

    private function __construct()
    {
    }
}
