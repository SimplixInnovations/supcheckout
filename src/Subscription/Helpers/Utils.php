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
            if (self::productDisablesSubscription($product_id)) {
                return true;
            }

        }

        return false;
    }

    /**
     * The explicit product-level subscription opt-out (`_upay_disable_subscription` = "yes").
     *
     * A4-08: WCML puts the translated product ID in the cart. With WPML's "Don't translate"
     * custom-field preference the translation lacks the meta, so the opt-out is also read on
     * the original product (`wpml_original_element_id`). It only ever adds a restriction; with
     * no WPML the filter returns nothing and only the product's own meta counts.
     *
     * @param mixed $product_id Product ID from a cart or order line.
     * @return bool
     */
    public static function productDisablesSubscription($product_id)
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }
        if (Keys::read_post_meta($product_id, '_upay_disable_subscription') === 'yes') {
            return true;
        }
        if (!function_exists('apply_filters')) {
            return false;
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter.
        $original = apply_filters('wpml_original_element_id', null, $product_id, 'post_product');
        $original_id = is_numeric($original) ? (int) $original : 0;

        return $original_id > 0
            && $original_id !== $product_id
            && Keys::read_post_meta($original_id, '_upay_disable_subscription') === 'yes';
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
