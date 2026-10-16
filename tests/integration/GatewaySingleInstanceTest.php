<?php
/**
 * The gateway registers its hooks in its constructor. A second gateway object in one
 * request (the Blocks integration used to create one) registers every hook twice: the
 * admin payment box, the thank-you section and the order totals rows then render twice.
 * The Blocks integration must reuse WooCommerce's registered gateway instead.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * @return int Number of Simplixi\SUPCheckout\Gateway\Gateway callbacks attached to $hook for $method.
 */
function supcheckout_cert_gateway_callbacks($hook, $method) {
    global $wp_filter;
    if (!isset($wp_filter[$hook])) {
        return 0;
    }
    $count = 0;
    foreach ($wp_filter[$hook]->callbacks as $callbacks) {
        foreach ($callbacks as $callback) {
            $fn = $callback['function'];
            if (is_array($fn) && $fn[0] instanceof \Simplixi\SUPCheckout\Gateway\Gateway && $fn[1] === $method) {
                $count++;
            }
        }
    }
    return $count;
}

$registered = WC()->payment_gateways()->payment_gateways();
supcheckout_cert_assert(isset($registered['upayments']) && $registered['upayments'] instanceof \Simplixi\SUPCheckout\Gateway\Gateway, 'WooCommerce registers the SUPCheckout gateway');

supcheckout_cert_assert(
    class_exists('WCGatewayUPaymentsBlocks'),
    'Checkout Blocks integration class is loaded'
);

// Start the Blocks integration the way WooCommerce does on every request.
$blocks = new WCGatewayUPaymentsBlocks(WP_PLUGIN_DIR . '/' . getenv('SUPCHECKOUT_PLUGIN_SLUG') . '/supcheckout.php');
$blocks->initialize();

$property = new ReflectionProperty($blocks, 'gateway');
if (PHP_VERSION_ID < 80100) {
    $property->setAccessible(true);
}
supcheckout_cert_assert(
    $property->getValue($blocks) === $registered['upayments'],
    'Blocks integration reuses the registered gateway instead of creating a second one'
);

foreach (array(
    'woocommerce_admin_order_data_after_order_details' => 'admin_order_details',
    'woocommerce_get_order_item_totals' => 'add_order_item_totals',
    'woocommerce_gateway_icon' => 'custom_payment_gateway_icons',
) as $hook => $method) {
    supcheckout_cert_assert(
        1 === supcheckout_cert_gateway_callbacks($hook, $method),
        "gateway hook $hook is registered exactly once after Blocks initialization"
    );
}
