<?php

namespace Simplixi\SUPCheckout\Gateway;

use Simplixi\SUPCheckout\Takeover\StaleGatewayChoice;

defined('ABSPATH') || exit;

final class GatewayRegistration {
    /** @return void */
    public static function register() {
        add_filter('woocommerce_payment_gateways', array(self::class, 'filter_gateways'));
        add_filter('woocommerce_available_payment_gateways', array(self::class, 'filter_available'));
        // Registers only once cut over (0b Task 7): an idle store's surface is unchanged.
        StaleGatewayChoice::register();
    }

    /**
     * @param mixed $methods Registered gateway classes.
     * @return array
     */
    public static function filter_gateways($methods) {
        $methods = is_array($methods) ? $methods : array();
        if (!in_array(Gateway::class, $methods, true)) {
            $methods[] = Gateway::class;
        }
        return $methods;
    }

    /**
     * @param mixed $gateways Available gateways.
     * @return mixed
     */
    public static function filter_available($gateways) {
        return Availability::filter($gateways);
    }

    private function __construct() {}
}
