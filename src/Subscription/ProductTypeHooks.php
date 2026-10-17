<?php

namespace Simplixi\SUPCheckout\Subscription;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Subscription\Presentation as SubscriptionPresentation;

/**
 * Subscription product-data hooks (was the nine global product compatibility
 * functions addCustomProductType() ... saveCustomDataToOrderItems()). Each
 * method delegates to Presentation exactly as the global function did.
 *
 * Composition::register_presentation_hooks() calls register() at the position the
 * product-type hooks always had, so the presentation hook topology keeps its order.
 */
final class ProductTypeHooks {
    /** @return void */
    public static function register() {
        add_filter('product_type_selector', array(self::class, 'addCustomProductType'));
        add_filter('woocommerce_product_class', array(self::class, 'mapCustomProductClass'), 10, 2);
        add_action('woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30);
        add_action('admin_footer', array(self::class, 'customProductTypes'));
        add_filter('woocommerce_product_data_tabs', array(self::class, 'addCustomDataTab'));
        add_action('woocommerce_product_data_panels', array(self::class, 'addCustomDataPanel'));
        add_action('woocommerce_process_product_meta', array(self::class, 'saveCustomFieldData'));
        add_action('woocommerce_single_product_summary', array(self::class, 'displayCustomFieldOnFrontend'), 10);
        add_filter('woocommerce_get_item_data', array(self::class, 'displayCustomDataInCart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(self::class, 'saveCustomDataToOrderItems'), 10, 4);
    }

    public static function addCustomProductType( $types ){
        return SubscriptionPresentation::add_custom_product_type($types);
    }

    public static function mapCustomProductClass( $classname, $product_type ) {
        return SubscriptionPresentation::map_custom_product_class($classname, $product_type);
    }

    public static function customProductTypes() {
        SubscriptionPresentation::custom_product_types();
    }

    public static function addCustomDataTab( $tabs ) {
        return SubscriptionPresentation::add_custom_data_tab($tabs);
    }

    public static function addCustomDataPanel() {
        SubscriptionPresentation::add_custom_data_panel();
    }

    public static function saveCustomFieldData( $post_id ) {
        SubscriptionPresentation::save_custom_field_data($post_id);
    }

    public static function displayCustomFieldOnFrontend() {
        SubscriptionPresentation::display_custom_field_on_frontend();
    }

    public static function displayCustomDataInCart( $item_data, $cart_item ) {
        return SubscriptionPresentation::display_custom_data_in_cart($item_data, $cart_item);
    }

    public static function saveCustomDataToOrderItems( $item, $cart_item_key, $values, $order ) {
        SubscriptionPresentation::save_custom_data_to_order_items($item, $cart_item_key, $values, $order);
    }

    private function __construct() {}
}
