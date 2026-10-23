<?php

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    abstract class AbstractPaymentMethodType {
        /** @var array<string,mixed> */
        protected $settings = array();
    }
}

namespace {
    /** @return mixed */
    function wp_register_script($handle, $src, $deps = array(), $ver = false, $in_footer = false) { return true; }

    /** @return string */
    function plugins_url($path = '', $plugin = '') { return ''; }

    /** @return string */
    function get_locale() { return 'en_US'; }

    /** @return string */
    function get_woocommerce_currency() { return 'KWD'; }

    /** @return string */
    function get_woocommerce_currency_symbol($currency = '') { return 'KD'; }

    /** @return string */
    function plugin_dir_url($file) { return ''; }

    /** @return string */
    function wp_strip_all_tags($text, $remove_breaks = false) { return ''; }
}
