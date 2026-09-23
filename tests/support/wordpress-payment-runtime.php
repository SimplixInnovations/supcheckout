<?php

class WC_Product {
    private $type;
    private $id;

    public function __construct($type = 'simple', $id = 1) {
        $this->type = (string) $type;
        $this->id = (int) $id;
    }

    public function get_type() {
        return $this->type;
    }

    public function get_id() {
        return $this->id;
    }
}

class WC_Order_Item_Product {
    private $product;
    private $quantity;
    private $total;
    private $name;

    public function __construct($product, $quantity = 1, $total = '10.000', $name = 'Test product') {
        $this->product = $product;
        $this->quantity = $quantity;
        $this->total = $total;
        $this->name = $name;
    }

    public function get_product() { return $this->product; }
    public function get_product_id() { return $this->product ? $this->product->get_id() : 0; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_name() { return $this->name; }
}

class WC_Order {
    private $id;
    private $currency;
    private $total;
    private $items;
    private $billing_phone;

    public function __construct($id, $currency = 'KWD', $total = '10.000', $items = array(), $billing_phone = '') {
        $this->id = (int) $id;
        $this->currency = $currency;
        $this->total = $total;
        $this->items = $items;
        $this->billing_phone = $billing_phone;
    }

    public function get_data() {
        return array(
            'currency' => $this->currency,
            'billing' => array(
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'email' => 'test@example.test',
            ),
        );
    }

    public function get_total() { return $this->total; }
    public function needs_payment() { return (float) $this->total > 0; }
    public function get_items($type = '') { return $this->items; }
    public function get_billing_phone() { return $this->billing_phone; }
    public function get_meta($key) { return ''; }
    public function add_meta_data($key, $value, $unique = false) {}
    public function delete_meta_data($key) {}
    public function save_meta_data() {}
}

final class SUPCheckout_Test_Payment_Runtime_Session {
    public $sets = array();

    public function set($key, $value) {
        $this->sets[$key] = $value;
    }
}

final class SUPCheckout_Test_Payment_Runtime_Cart {
    public $empty_calls = 0;

    public function empty_cart() {
        $this->empty_calls++;
    }
}

final class SUPCheckout_Test_Payment_Runtime_WC {
    public $cart;
    public $session;

    public function __construct() {
        $this->cart = new SUPCheckout_Test_Payment_Runtime_Cart();
        $this->session = new SUPCheckout_Test_Payment_Runtime_Session();
    }

    public function payment_gateways() {
        return false;
    }
}

function supcheckout_test_reset_payment_runtime() {
    supcheckout_test_reset_public_order_status();
    supcheckout_test_reset_subscription_presentation();
    $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = new SUPCheckout_Test_Payment_Runtime_WC();
    $GLOBALS['supcheckout_test_payment_runtime_request_calls'] = array();
    $_POST = array();
}

function wc_get_checkout_url() {
    return 'https://example.test/checkout';
}

function site_url($path = '', $scheme = null) {
    return 'https://example.test' . (string) $path;
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        $next = isset($GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'])
            ? (int) $GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'] + 1
            : 1;
        $GLOBALS['supcheckout_test_payment_runtime_uuid_sequence'] = $next;

        return sprintf('00000000-0000-4000-8000-%012x', $next);
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

supcheckout_test_reset_payment_runtime();
