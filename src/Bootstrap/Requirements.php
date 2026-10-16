<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

/**
 * WooCommerce requirement check and its admin notice (was the global
 * woocommerceUpaymentsInit() / upaymentsMissingWcNotice() pair).
 */
final class Requirements {
    /** @return void */
    public static function register() {
        add_action('plugins_loaded', array(self::class, 'check_woocommerce'));
    }

    /**
     * If WooCommerce is not active, show the admin notice.
     *
     * @return void
     */
    public static function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array(self::class, 'missing_woocommerce_notice') );
            return;
        }
    }

    /**
     * If Woocommerce Plugin is not active/installed show admin notice to install/activate Woocommerce
     *
     * @return void
     */
    public static function missing_woocommerce_notice() {
        ?>
    <div class="error notice">
        <p><strong><?php esc_html_e('UPayments Gateway', 'supcheckout'); ?></strong> <?php esc_html_e('requires WooCommerce to be installed and active!', 'supcheckout'); ?></p>
    </div>
    <?php
    }

    private function __construct() {}
}
