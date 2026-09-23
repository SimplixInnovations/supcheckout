<?php

/** Development-only WooCommerce order symbols for bounded PHPStan scope. */
class WC_Order {
    /** @return string */
    public function get_payment_method() { return ''; }

    /** @return mixed */
    public function get_meta($key, $single = true) {}
}
