<?php

namespace Simplixi\SUPCheckout\Blocks;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Plugin;

/**
 * Registers the UPayments payment method with the WooCommerce Blocks payment
 * method registry once Blocks has loaded.
 */
final class PaymentMethodRegistration {
    /** @return void */
    public static function register() {
        add_action('woocommerce_blocks_loaded', array(self::class, 'on_blocks_loaded'));
    }

    /** @return void */
    public static function on_blocks_loaded() {
        add_action('woocommerce_blocks_payment_method_type_registration', array(self::class, 'register_payment_method'));
    }

    /**
     * @param object $payment_method_registry WooCommerce Blocks payment method registry.
     * @return void
     */
    public static function register_payment_method($payment_method_registry) {
        require_once dirname(Plugin::main_file()) . '/includes/class-wc-gateway-upayments-blocks.php';
        $payment_method_registry->register(
            new \WCGatewayUPaymentsBlocks( Plugin::main_file() )
        );
    }

    private function __construct() {}
}
