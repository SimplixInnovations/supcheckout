<?php
/**
 * Real WooCommerce legacy/HPOS order CRUD certification.
 */

require_once __DIR__ . '/bootstrap.php';

$mode = getenv('SUPCHECKOUT_HPOS_MODE');
supcheckout_cert_assert(in_array($mode, array('legacy', 'hpos'), true), 'HPOS certification mode is explicit');
supcheckout_cert_assert(
    class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil'),
    'WooCommerce OrderUtil is available'
);

$hpos_enabled = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
supcheckout_cert_assert(
    ('hpos' === $mode) === $hpos_enabled,
    'WooCommerce authoritative order store matches requested certification mode'
);

$product = new WC_Product_Simple();
$product->set_name('SUPCheckout Certification Product');
$product->set_regular_price('10.00');
$product->set_price('10.00');
$product_id = $product->save();
supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'certification product persists');

$order = wc_create_order();
supcheckout_cert_assert($order instanceof WC_Order, 'WooCommerce creates a real order');

$order->set_payment_method('upayments');
$order->add_product($product, 1);
$order->update_meta_data('UPayments_order_id', 'certification-order-identity');
$order->update_meta_data('_supcheckout_certification_marker', $mode);
$order->calculate_totals();
$order_id = $order->save();

supcheckout_cert_assert(is_int($order_id) && $order_id > 0, 'order persists in requested authoritative store');

$reloaded = wc_get_order($order_id);
supcheckout_cert_assert($reloaded instanceof WC_Order, 'order reloads through WooCommerce CRUD');
supcheckout_cert_assert('upayments' === $reloaded->get_payment_method(), 'SUPCheckout payment method identity survives order reload');
supcheckout_cert_assert(
    'certification-order-identity' === $reloaded->get_meta('UPayments_order_id'),
    'protected UPayments order metadata survives order reload'
);
supcheckout_cert_assert(
    $mode === $reloaded->get_meta('_supcheckout_certification_marker'),
    'certification metadata survives order reload'
);
supcheckout_cert_assert('10.00' === wc_format_decimal($reloaded->get_total(), 2), 'order economics survive order reload');

// R1: gateway-specific order presentation must be observational for orders paid
// through another gateway. WooCommerce applies woocommerce_get_order_item_totals
// globally, so SUPCheckout must not inject an empty UPayments status row into
// COD, bank-transfer, or third-party-gateway orders.
$gateway = new WC_Upayments();
$base_rows = array(
    'cart_subtotal' => array('label' => 'Subtotal:', 'value' => '10.00'),
    'payment_method' => array('label' => 'Payment method:', 'value' => 'Cash on delivery'),
    'order_total' => array('label' => 'Total:', 'value' => '10.00'),
);

$foreign_order = wc_create_order();
supcheckout_cert_assert($foreign_order instanceof WC_Order, 'WooCommerce creates foreign-gateway presentation fixture');
$foreign_order->set_payment_method('cod');
$foreign_order->add_product($product, 1);
$foreign_order->calculate_totals();
$foreign_order_id = $foreign_order->save();
supcheckout_cert_assert(is_int($foreign_order_id) && $foreign_order_id > 0, 'foreign-gateway presentation fixture persists');

$foreign_rows = $gateway->add_order_item_totals($base_rows, $foreign_order, 'excl');
supcheckout_cert_assert(
    $foreign_rows === $base_rows,
    'SUPCheckout leaves non-UPayments order-total rows byte-equivalent'
);

// Preserve the positive compatibility contract for genuine UPayments orders.
$reloaded->update_meta_data('UPayments_Result', 'CAPTURED');
$reloaded->update_meta_data('UPayments_PaymentID', 'certification-payment-id');
$reloaded->save();
$upay_rows = $gateway->add_order_item_totals($base_rows, $reloaded, 'excl');
supcheckout_cert_assert(
    isset($upay_rows['payment_status']) && 'CAPTURED' === $upay_rows['payment_status']['value'],
    'UPayments order-total presentation retains verified payment status'
);
supcheckout_cert_assert(
    isset($upay_rows['upayment_id']) && 'certification-payment-id' === $upay_rows['upayment_id']['value'],
    'UPayments order-total presentation retains provider payment ID'
);

global $wpdb;

if ('hpos' === $mode) {
    $orders_table = $wpdb->prefix . 'wc_orders';
    $meta_table   = $wpdb->prefix . 'wc_orders_meta';

    $order_row = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$orders_table} WHERE id = %d", $order_id)
    );
    $meta_row = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = %s",
            $order_id,
            'UPayments_order_id'
        )
    );

    supcheckout_cert_assert((int) $order_id === (int) $order_row, 'HPOS authoritative orders table contains the order');
    supcheckout_cert_assert('certification-order-identity' === $meta_row, 'HPOS authoritative meta table contains protected metadata');
} else {
    $post_type = $wpdb->get_var(
        $wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $order_id)
    );
    $meta_row = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
            $order_id,
            'UPayments_order_id'
        )
    );

    supcheckout_cert_assert('shop_order' === $post_type, 'legacy authoritative posts table contains the shop order');
    supcheckout_cert_assert('certification-order-identity' === $meta_row, 'legacy authoritative postmeta contains protected metadata');
}

$foreign_order->delete(true);
$reloaded->delete(true);
wp_delete_post($product_id, true);
supcheckout_cert_note('order CRUD certification complete: ' . $mode);
