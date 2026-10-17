<?php

namespace UPayments\Subscription\Helpers {
    final class Utils {
        public static $custom = false;
        public static $normal = false;

        public static function cartHasCustomType() { return self::$custom; }
        public static function cartHasNormalProduct() { return self::$normal; }
    }
}

namespace UPayments\Subscription\Cron {
    final class Scheduler {
        public static function getNextBillingDate($started_at, $plan, $interval) {
            if (!$started_at instanceof \DateTimeInterface || !is_string($plan) || $interval <= 0) {
                return null;
            }
            return new \DateTime('2030-02-03 04:05:06', new \DateTimeZone('UTC'));
        }
    }
}

namespace {
    class WooCommerce {}

    class WC_Product {
        public $type = 'simple';
        public $id = 12;

        public function get_type() { return $this->type; }
        public function is_type($type) { return $this->type === $type; }
        public function get_id() { return $this->id; }
    }

    class WC_Product_Simple extends WC_Product {}

    class WC_Order {
        public $completed = null;
        public $created = null;
        public $id = 44;
        public $items = array();
        public $meta = array();
        public $paid = null;
        public $user_id = 7;

        public function __construct() {
            $this->created = new DateTime('2029-01-02 03:04:05', new DateTimeZone('UTC'));
        }

        public function get_date_completed() { return $this->completed; }
        public function get_date_created() { return $this->created; }
        public function get_date_paid() { return $this->paid; }
        public function get_id() { return $this->id; }
        public function get_items($type = '') { return $this->items; }
        public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
        public function get_user_id() { return $this->user_id; }
    }

    final class SUPCheckout_Test_Presentation_Item {
        public $product;
        public $writes = array();

        public function __construct($product = null) { $this->product = $product; }
        public function add_meta_data($key, $value) { $this->writes[] = array($key, $value); }
        public function get_product() { return $this->product; }
    }
}

// Stands in for the real gateway class (each PresentationTest runs in its own
// process) and counts constructions: presentation must never build a second one.
namespace Simplixi\SUPCheckout\Gateway {
    class Gateway {
        public static $summary_orders = array();
        public static $constructed = 0;

        public function __construct() {
            self::$constructed++;
        }

        public function render_subscription_summary($order) {
            self::$summary_orders[] = $order;
        }
    }
}
