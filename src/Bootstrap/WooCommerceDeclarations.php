<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Plugin;

/**
 * Declares compatibility with WooCommerce's Cart & Checkout blocks (WooBlocks)
 * and High-Performance Order Storage for the SUPCheckout main file.
 *
 * The full boot registers it from Plugin::boot(). The paused boot (Takeover\PausedBoot,
 * official plugin active) registers the same declarations through register_paused(),
 * because WooCommerce otherwise lists the paused plugin as incompatible with both
 * features; Plugin::boot() never runs there, so it passes the main file itself.
 */
final class WooCommerceDeclarations {
    /** @var string|null Main file passed by the paused boot; null on the full boot. */
    private static $paused_main_file = null;

    /** @return void */
    public static function register() {
        add_action('before_woocommerce_init', array(self::class, 'declare_compatibility'));
    }

    /**
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function register_paused($main_file) {
        self::$paused_main_file = (string) $main_file;
        self::register();
    }

    /** @return void */
    public static function declare_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            $main_file = self::$paused_main_file !== null ? self::$paused_main_file : Plugin::main_file();
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                $main_file,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $main_file,
                true
            );
        }
    }

    private function __construct() {}
}
