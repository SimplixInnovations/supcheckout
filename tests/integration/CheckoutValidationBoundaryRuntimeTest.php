<?php
/**
 * Real WooCommerce checkout finalization boundary certification.
 *
 * Shipping-rate availability is owned by Woo checkout validation. SUPCheckout
 * must not invent a parallel shipping engine; payment processing occurs only
 * after Woo accepts the checkout.
 */

require_once __DIR__ . '/bootstrap.php';

if (! WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
if (! WC()->customer) {
    WC()->customer = new WC_Customer(0, true);
}
if (! WC()->cart) {
    WC()->cart = new WC_Cart();
}

WC()->cart->empty_cart();
WC()->session->set('chosen_shipping_methods', array());

$product = new WC_Product_Simple();
$product->set_name('E2 No Shipping Rate Product');
$product->set_regular_price('8.000');
$product->set_price('8.000');
$product->set_virtual(false);
$product_id = $product->save();
supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'no-rate checkout product persists');

$cart_key = WC()->cart->add_to_cart($product_id, 1);
supcheckout_cert_assert(is_string($cart_key) && '' !== $cart_key, 'physical product enters real Woo cart');
WC()->customer->set_billing_country('KW');
WC()->customer->set_shipping_country('KW');
WC()->customer->set_billing_postcode('13001');
WC()->customer->set_shipping_postcode('13001');

$force_no_rates = static function ($rates, $package) {
    return array();
};
$skip_payment_validation = static function ($needs_payment, $cart) {
    return false;
};
add_filter('woocommerce_package_rates', $force_no_rates, PHP_INT_MAX, 2);
add_filter('woocommerce_cart_needs_payment', $skip_payment_validation, PHP_INT_MAX, 2);

$shipping = WC()->shipping();
$shipping_enabled_before = $shipping->enabled;
$ship_to_countries_before = get_option('woocommerce_ship_to_countries', '');
$default_zone = WC_Shipping_Zones::get_zone(0);
$shipping_instance_id = $default_zone->add_shipping_method('flat_rate');

try {
    supcheckout_cert_assert(is_int($shipping_instance_id) && $shipping_instance_id > 0, 'certification store configures one real Woo shipping method');
    update_option('woocommerce_ship_to_countries', 'all');
    $shipping->enabled = true;
    $shipping->reset_shipping();
    // Woo core caches this count independently. Mirror Woo's own tests and
    // invalidate the shipping transient after changing zone methods.
    WC_Cache_Helper::get_transient_version('shipping', true);
    delete_transient('wc_shipping_method_count');

    supcheckout_cert_assert(wc_get_shipping_method_count(true) > 0, 'Woo reports at least one configured shipping method');
    supcheckout_cert_assert(true === WC()->cart->needs_shipping(), 'physical cart requires shipping before payment');
    $raw_packages = WC()->cart->get_shipping_packages();
    supcheckout_cert_assert(! empty($raw_packages), 'physical cart produces at least one raw Woo shipping package');

    $packages = $shipping->calculate_shipping($raw_packages);
    supcheckout_cert_assert(! empty($packages), 'Woo shipping engine calculates the physical cart package');
    foreach ($packages as $package) {
        supcheckout_cert_assert(isset($package['rates']) && array() === $package['rates'], 'certification filter leaves the Woo shipping package with no available rate');
    }
    supcheckout_cert_assert(false === WC()->cart->needs_payment(), 'payment validation is isolated out of this shipping-boundary probe');

    $checkout = new class extends WC_Checkout {
        public function supcheckout_validate_checkout(&$data, &$errors) {
            return parent::validate_checkout($data, $errors);
        }
    };

    $data = array(
        'billing_first_name' => 'Shipping',
        'billing_last_name' => 'Boundary',
        'billing_country' => 'KW',
        'billing_address_1' => 'Certification Address',
        'billing_city' => 'Kuwait City',
        'billing_postcode' => '13001',
        'billing_phone' => '50000000',
        'billing_email' => 'shipping-boundary@example.invalid',
        'shipping_country' => 'KW',
        'ship_to_different_address' => false,
        'payment_method' => 'upayments',
    );
    $errors = new WP_Error();
    $checkout->supcheckout_validate_checkout($data, $errors);
    supcheckout_cert_assert('' !== $errors->get_error_message('shipping'), 'Woo checkout rejects a shippable cart when no chosen package rate exists');
    supcheckout_cert_note('no-rate shipping checkout is rejected by Woo before gateway payment processing');
} finally {
    if (is_int($shipping_instance_id) && $shipping_instance_id > 0) {
        $default_zone->delete_shipping_method($shipping_instance_id);
    }
    WC_Cache_Helper::get_transient_version('shipping', true);
    delete_transient('wc_shipping_method_count');
    update_option('woocommerce_ship_to_countries', $ship_to_countries_before);
    $shipping->enabled = $shipping_enabled_before;
    $shipping->reset_shipping();
    remove_filter('woocommerce_package_rates', $force_no_rates, PHP_INT_MAX);
    remove_filter('woocommerce_cart_needs_payment', $skip_payment_validation, PHP_INT_MAX);
    WC()->cart->empty_cart();
    WC()->session->set('chosen_shipping_methods', array());
    wp_delete_post($product_id, true);
}
