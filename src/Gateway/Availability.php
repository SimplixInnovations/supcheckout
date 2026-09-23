<?php

namespace Simplixi\SUPCheckout\Gateway;

use Simplixi\SUPCheckout\Admin\GatewaySettings;

defined('ABSPATH') || exit;

require_once __DIR__ . '/CheckoutAssets.php';

/**
 * WooCommerce available-gateways compatibility adapter.
 *
 * This boundary intentionally preserves the historical global filter semantics
 * while keeping persisted configuration eligibility fail closed. It does not
 * own provider availability or payment-state authority.
 */
final class Availability {
    /**
     * Apply the historical available-gateways policy.
     *
     * @param array $available_gateways WooCommerce available gateways.
     * @return array
     */
    public static function filter($available_gateways) {
        if (is_admin() && ! wp_doing_ajax()) {
            return $available_gateways;
        }

        if (isset($available_gateways['upayments'])) {
            $upay = $available_gateways['upayments'];
            unset($available_gateways['upayments']);
            $available_gateways['upayments'] = $upay;

            $settings = get_option('woocommerce_upayments_settings');
            if (!GatewaySettings::is_runtime_eligible($settings, get_woocommerce_currency())) {
                unset($available_gateways['upayments']);
                return $available_gateways;
            }

            $is_checkout = is_checkout();
            if ($is_checkout
                && isset($available_gateways['cod'])
                && isset($settings['enable_autodeduction'])
                && $settings['enable_autodeduction'] === 'yes'
            ) {
                unset($available_gateways['cod']);
            }

            if ($is_checkout) {
                $wc = function_exists('WC') ? WC() : null;
                if ($wc
                    && $wc->session
                    && $wc->session->get('chosen_payment_method') === 'upayments'
                    && isset($settings['make_default_gateway'])
                    && $settings['make_default_gateway'] !== 'yes'
                ) {
                    $wc->session->set('chosen_payment_method', null);
                }
            }
        }

        return $available_gateways;
    }

    private function __construct() {}
}

// Availability.php is a guaranteed Gateway-module bootstrap dependency of the
// legacy adapter. Keep render-asset behavior in its dedicated boundary while
// avoiding any global frontend enqueue.
CheckoutAssets::bootstrap();
