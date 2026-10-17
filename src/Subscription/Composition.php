<?php

namespace Simplixi\SUPCheckout\Subscription;

use Simplixi\SUPCheckout\Subscription\Checkout\Fields;

/**
 * Composition boundary for the inherited subscription presentation surfaces.
 *
 * Scheduler/dispatch, payment payloads and customer action mutations remain
 * outside this class. This boundary owns hook registration only.
 */
final class Composition {
    /** Prevent instantiation of the static composition boundary. */
    private function __construct() {
    }

    /**
     * Register hooks that are independent of a gateway instance.
     *
     * @return void
     */
    public static function register_presentation_hooks() {
        add_action('init', array(Presentation::class, 'register_product_class'));
        ProductTypeHooks::register();
        add_action('woocommerce_order_details_after_order_table', array(Presentation::class, 'render_account_order_details'));
        add_action('woocommerce_before_account_orders', array(Presentation::class, 'render_account_orders_filter'));
        add_filter('woocommerce_my_account_my_orders_query', array(Presentation::class, 'filter_account_orders_query'));
        add_filter('woocommerce_my_account_my_orders_columns', array(Presentation::class, 'filter_account_orders_columns'));
        add_action('woocommerce_my_account_my_orders_column_order_type', array(Presentation::class, 'render_account_order_type'));
        add_action('woocommerce_my_account_my_orders_column_order_status', array(Presentation::class, 'render_account_subscription_status'));
        add_action('woocommerce_admin_order_data_after_billing_address', array(Presentation::class, 'render_admin_order_summary'), 10, 1);
    }

    /**
     * Initialize existing checkout/storage modules behind the public gateway seam.
     *
     * @return void
     */
    public static function initialize_legacy_modules() {
        Fields::init();
    }

    /**
     * Register the two inherited gateway-instance presentation hooks.
     *
     * @param object $gateway \Simplixi\SUPCheckout\Gateway\Gateway instance.
     * @return void
     */
    public static function register_gateway_hooks($gateway) {
        add_filter('woocommerce_add_to_cart_validation', array($gateway, 'restrictMixedCartProducts'), 10, 3);
        add_action('woocommerce_before_shop_loop_item_title', array($gateway, 'renderSubscriptionBadgeInProductList'), 9);
    }
}
