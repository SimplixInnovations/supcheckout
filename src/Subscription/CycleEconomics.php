<?php

namespace Simplixi\SUPCheckout\Subscription;

/**
 * Decimal-safe billing-cycle economics.
 *
 * Payment authority never uses binary floating-point equality.
 */
final class CycleEconomics
{
    /**
     * @param object $order Order-like.
     * @return array{amount:string,currency:string,plan:string,interval:int}|null
     */
    public static function snapshot_from_order($order)
    {
        if (!is_object($order)
            || !method_exists($order, 'get_total')
            || !method_exists($order, 'get_currency')
            || !method_exists($order, 'get_meta')
        ) {
            return null;
        }

        $amount = self::canonical_decimal($order->get_total());
        $currency = self::canonical_currency($order->get_currency());
        $plan = $order->get_meta('_upay_subscription_plan');
        $interval = (int) $order->get_meta('_upay_subscription_interval');

        if ($amount === null || $currency === null || !is_string($plan) || $plan === '' || $interval < 1) {
            return null;
        }

        return array(
            'amount'   => $amount,
            'currency' => $currency,
            'plan'     => $plan,
            'interval' => $interval,
        );
    }

    /**
     * @param mixed $value
     * @return string|null Canonical non-negative plain decimal or null.
     */
    public static function canonical_decimal($value)
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (is_float($value)) {
            // Reject binary floats as payment authority.
            return null;
        }
        if (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
            return null;
        }
        if (strlen($value) > 22) {
            return null;
        }
        // Normalize trailing fractional zeros for comparison, keep one fraction digit minimum split.
        if (strpos($value, '.') === false) {
            return $value;
        }
        $trimmed = rtrim($value, '0');
        return rtrim($trimmed, '.') === '' ? '0' : rtrim($trimmed, '.');
    }

    /**
     * @param mixed $value
     * @return string|null Uppercase 3-letter currency or null.
     */
    public static function canonical_currency($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    public static function amounts_equal($left, $right)
    {
        $a = self::canonical_decimal($left);
        $b = self::canonical_decimal($right);
        return $a !== null && $b !== null && $a === $b;
    }

    public static function currencies_equal($left, $right)
    {
        $a = self::canonical_currency($left);
        $b = self::canonical_currency($right);
        return $a !== null && $b !== null && $a === $b;
    }

    private function __construct()
    {
    }
}
