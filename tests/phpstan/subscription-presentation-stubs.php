<?php

namespace UPayments\Subscription\Helpers {
    class Utils {
        public static function cartHasCustomType() { return false; }
        public static function cartHasNormalProduct() { return false; }
    }
}

namespace UPayments\Subscription\Cron {
    class Scheduler {
        /** @return \DateTime|null */
        public static function getNextBillingDate(?\DateTime $started_at, string $plan, int $interval): ?\DateTime { return null; }
    }
}

namespace {
    class WooCommerce {
        /** @var mixed */
        public $cart;
        /** @var mixed */
        public $session;
        /** @return mixed */
        public function payment_gateways() {}
    }

    class WC_Product {
        /** @return string */
        public function get_type() { return ''; }
        /** @return bool */
        public function is_type($type) { return false; }
        /** @return int */
        public function get_id() { return 0; }
    }

    class WC_Order_Item_Product {
        /** @return mixed */
        public function get_product() {}
        /** @return int */
        public function get_product_id() { return 0; }
        /** @return mixed */
        public function get_quantity() {}
        /** @return mixed */
        public function get_total() {}
        /** @return mixed */
        public function get_name() {}
    }

    class WC_Order {
        /** @return mixed */
        public function get_meta($key) {}
        /** @return int */
        public function get_user_id() { return 0; }
        /** @return int */
        public function get_id() { return 0; }
        /** @return array<int, mixed> */
        public function get_items($type = '') { return array(); }
        /** @return mixed */
        public function get_date_paid() {}
        /** @return mixed */
        public function get_date_completed() {}
        /** @return mixed */
        public function get_date_created() {}
        /** @return mixed */
        public function get_data() {}
        /** @return mixed */
        public function get_total() {}
        /** @return bool */
        public function needs_payment() { return false; }
        /** @return mixed */
        public function get_billing_phone() {}
        /** @return string */
        public function get_payment_method() { return ''; }
        /** @return string */
        public function get_currency() { return ''; }
        /** @return string */
        public function get_status() { return ''; }
        /** @return string */
        public function get_transaction_id() { return ''; }
        /** @return bool */
        public function is_paid() { return false; }
        /** @return bool */
        public function has_status($status) { return false; }
        /** @return void */
        public function update_meta_data($key, $value) {}
        /** @return void */
        public function delete_meta_data($key) {}
        /** @return void */
        public function add_meta_data($key, $value, $unique = false) {}
        /** @return void */
        public function save_meta_data() {}
        /** @return mixed */
        public function save() {}
        /** @return void */
        public function set_transaction_id($id) {}
        /** @return void */
        public function payment_complete($id = '') {}
        /** @return mixed */
        public function update_status($status, $note = '') {}
        /** @return mixed */
        public function add_order_note($note) {}
    }

    function absint($value) { return 0; }
    function wp_verify_nonce($nonce, $action) { return false; }
    function update_post_meta($post_id, $key, $value) { return false; }
    function get_post_meta($post_id, $key, $single = false) {}
    function get_post_type() { return ''; }
    function woocommerce_wp_text_input($args) {}
    /** @return mixed */
    function WC() {}
    /** @return WC_Product|false */
    function wc_get_product($product_id) { return false; }
    function wc_add_notice($message, $type) {}
    function wp_timezone() { return new DateTimeZone('UTC'); }
    function wc_get_account_endpoint_url($endpoint) { return ''; }
    function add_query_arg($key = null, $value = null, $url = null) { return ''; }
    function esc_url($value) { return ''; }
    function esc_js($value) { return ''; }
}
