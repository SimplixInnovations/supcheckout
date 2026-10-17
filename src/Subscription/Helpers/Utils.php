<?php

namespace Simplixi\SUPCheckout\Subscription\Helpers;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

class Utils
{
    public static function isSubscriptionOrder($order)
    {
        return in_array(
            Keys::read_order_meta($order, '_upay_subscription_plan'),
            ['daily', 'weekly', 'monthly', 'yearly'],
            true
        );
    }

    public static function cartHasRestrictedProducts()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = $item['product_id'];

            // Product-level restriction
            if (Keys::read_post_meta($product_id, '_upay_disable_subscription') === 'yes') {
                return true;
            }

        }

        return false;
    }

    public static function cartHasCustomType()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() === 'custom_type') {
                return true;
            }
        }

        return false;
    }

    public static function cartHasNormalProduct()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() !== 'custom_type') {
                return true;
            }
        }

        return false;
    }
}
