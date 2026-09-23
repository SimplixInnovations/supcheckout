<?php

namespace Simplixi\SUPCheckout\Gateway;

use Simplixi\SUPCheckout\Release\Identity;
use UPayments\Subscription\Helpers\Utils;

defined('ABSPATH') || exit;

/**
 * Assets required by a rendered Classic SUPCheckout payment-fields template.
 *
 * Canonical checkout requests are still pre-enqueued by the legacy gateway.
 * This boundary closes the embedded/custom-checkout gap by reacting only when
 * WooCommerce actually resolves one of SUPCheckout's payment-field templates.
 */
final class CheckoutAssets {
    /** @var bool */
    private static $bootstrapped = false;

    /** Register the render-scoped template observer exactly once. */
    public static function bootstrap() {
        if (self::$bootstrapped || ! function_exists('add_filter')) {
            return;
        }

        add_filter('wc_get_template', array(self::class, 'filter_template'), 5, 5);
        self::$bootstrapped = true;
    }

    /**
     * Provision only the assets required by an actually rendered SUPCheckout
     * Classic payment-fields template. The located path is never changed, so
     * theme/template overrides keep normal WooCommerce precedence.
     *
     * @param mixed  $located       Located template path.
     * @param mixed  $template_name Requested template name.
     * @param mixed  $args          Template arguments.
     * @param mixed  $template_path WooCommerce template path.
     * @param mixed  $default_path  Plugin fallback path.
     * @return mixed
     */
    public static function filter_template($located, $template_name, $args, $template_path, $default_path) {
        unset($template_path, $default_path);

        if ($template_name !== 'new-design-form.php' && $template_name !== 'old-design-form.php') {
            return $located;
        }

        if (! is_array($args)
            || ! isset($args['gateway'])
            || ! is_object($args['gateway'])
            || ! is_a($args['gateway'], 'WC_Upayments')
        ) {
            return $located;
        }

        /** @var \WC_Upayments $gateway */
        $gateway = $args['gateway'];
        self::enqueue_for_template($template_name, $gateway);
        return $located;
    }

    /**
     * Enqueue render-owned assets without widening frontend scope.
     *
     * @param string         $template_name Exact SUPCheckout template name.
     * @param \WC_Upayments $gateway       Exact rendered gateway instance.
     * @return void
     */
    private static function enqueue_for_template($template_name, $gateway) {
        $plugin_url = plugin_dir_url(dirname(__DIR__, 2) . '/UPayments.php');

        wp_enqueue_style(
            'supcheckout-customer',
            $plugin_url . 'assets/css/customer.css',
            array(),
            Identity::VERSION
        );

        if ($template_name === 'new-design-form.php') {
            wp_enqueue_style(
                'supcheckout-checkout-new-style',
                $plugin_url . 'assets/css/new-design.css',
                array(),
                Identity::VERSION
            );
            wp_enqueue_script(
                'supcheckout-checkout-new-script',
                $plugin_url . 'assets/js/new-upay.js',
                array('jquery'),
                Identity::VERSION,
                true
            );
        }

        // Preserve the canonical Classic subscription predicate while failing
        // closed if a legacy gateway instance does not expose the expected
        // public state. The handle check prevents duplicate localization when
        // the normal checkout page already pre-enqueued this first-party script.
        $gateway_state = get_object_vars($gateway);
        if (isset($gateway_state['autoDeduction'])
            && $gateway_state['autoDeduction'] === 'yes'
            && class_exists(Utils::class)
            && Utils::cartHasCustomType()
            && ! wp_script_is('supcheckout-subscription-checkout', 'enqueued')
        ) {
            wp_enqueue_script(
                'supcheckout-subscription-checkout',
                $plugin_url . 'assets/js/subscription-checkout.js',
                array('jquery'),
                Identity::VERSION,
                true
            );
            wp_localize_script(
                'supcheckout-subscription-checkout',
                'wcUser',
                array(
                    'isLoggedIn' => is_user_logged_in(),
                )
            );
        }
    }

    private function __construct() {}
}
