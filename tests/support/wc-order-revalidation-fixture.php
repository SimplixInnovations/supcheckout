<?php
/**
 * Minimal WC_Order fixture for ParentDispatchRevalidationTest.
 *
 * Reuses the existing unit-bootstrap wc_get_order() store
 * ($GLOBALS['supcheckout_test_status_orders']). Real WooCommerce coverage for
 * the same contract is SubscriptionRuntimeTest.php (all 20 compatibility cells).
 */

if (!class_exists('WC_Order', false)) {
    class WC_Order_Fixture_Item
    {
        /** @var object|null */
        private $product;

        public function __construct($product)
        {
            $this->product = $product;
        }

        public function get_product()
        {
            return $this->product;
        }
    }

    class WC_Order_Fixture_Product
    {
        /** @var string */
        private $type;

        public function __construct(string $type)
        {
            $this->type = $type;
        }

        public function get_type(): string
        {
            return $this->type;
        }
    }

    class WC_Order
    {
        /** @var int */
        private $id = 0;
        /** @var string */
        private $status = 'pending';
        /** @var string */
        private $payment_method = '';
        /** @var string */
        private $total = '0';
        /** @var string */
        private $currency = 'KWD';
        /** @var array<string,mixed> */
        private $meta = array();
        /** @var array<int,array{type:string}> */
        private $items = array();

        public function set_id($id): void { $this->id = (int) $id; }
        public function set_status($status): void { $this->status = (string) $status; }
        public function set_payment_method($method): void { $this->payment_method = (string) $method; }
        public function set_total($total): void { $this->total = (string) $total; }
        public function set_currency($currency): void { $this->currency = (string) $currency; }
        public function set_meta($meta): void { $this->meta = is_array($meta) ? $meta : array(); }
        public function set_items($items): void { $this->items = is_array($items) ? $items : array(); }

        public function get_id() { return $this->id; }
        public function get_status() { return $this->status; }
        public function get_payment_method() { return $this->payment_method; }
        public function get_total() { return $this->total; }
        public function get_currency() { return $this->currency; }
        public function get_date_created() { return new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')); }
        public function get_date_paid() { return new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')); }
        public function get_date_completed() { return null; }

        public function get_meta($key, $single = true)
        {
            return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
        }

        public function has_status($status): bool
        {
            $statuses = is_array($status) ? $status : array($status);
            $normalized = array();
            foreach ($statuses as $item) {
                $normalized[] = (string) $item;
            }
            return in_array((string) $this->status, $normalized, true);
        }

        /** @return array<int,WC_Order_Fixture_Item> */
        public function get_items($type = '')
        {
            $out = array();
            foreach ($this->items as $spec) {
                $product_type = isset($spec['type']) ? (string) $spec['type'] : 'simple';
                $out[] = new WC_Order_Fixture_Item(new WC_Order_Fixture_Product($product_type));
            }
            return $out;
        }
    }
}

if (!function_exists('wc_get_is_paid_statuses')) {
    function wc_get_is_paid_statuses()
    {
        return array('processing', 'completed');
    }
}
