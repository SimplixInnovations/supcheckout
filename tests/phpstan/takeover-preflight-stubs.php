<?php

// Development-only analysis stubs for src/Takeover/Environment.php: HPOS detection and
// the gateway parent class its constructor-skipping transport subclass resolves.

namespace Automattic\WooCommerce\Utilities {
    class OrderUtil {
        /** @return bool */
        public static function custom_orders_table_usage_is_enabled() {}
    }
}

namespace {
    class WC_Payment_Gateway {
        /** @var string */
        public $id = '';
    }
}
