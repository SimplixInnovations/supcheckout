<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Plugin;

/**
 * Declares compatibility with WooCommerce's Cart & Checkout blocks (WooBlocks)
 * and High-Performance Order Storage for the SUPCheckout main file.
 */
final class WooCommerceDeclarations {
    /** @return void */
    public static function register() {
        add_action('before_woocommerce_init', array(self::class, 'declare_compatibility'));
    }

    /** @return void */
    public static function declare_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                Plugin::main_file(),
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                Plugin::main_file(),
                true
            );
        }
    }

    private function __construct() {}
}
