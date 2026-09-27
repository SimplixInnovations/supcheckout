<?php

namespace UPayments\Subscription\Helpers {
    class Utils {
        public static $custom = false;
        public static $normal = false;
        public static function cartHasCustomType() { return self::$custom; }
        public static function cartHasNormalProduct() { return self::$normal; }
    }
}

namespace UPayments\Subscription\Cron {
    class Scheduler {
        public static function getNextBillingDate($started_at, $plan, $interval) {
            return new \DateTime('2030-02-03 04:05:06', new \DateTimeZone('UTC'));
        }
    }
}

namespace {
use Simplixi\SUPCheckout\Subscription\Composition;
use Simplixi\SUPCheckout\Subscription\Presentation;
use UPayments\Subscription\Helpers\Utils;

$a4_hooks = array();
$a4_meta = array();
$a4_notices = array();
$a4_nonce_valid = false;
$a4_can_edit = false;
$a4_current_user = 7;
$a4_product = null;
$a4_wc = (object) array('cart' => (object) array());
$a4_field_args = null;

class WooCommerce {}
class WC_Product {
    public $type = 'simple';
    public $id = 12;
    public function get_type() { return $this->type; }
    public function is_type($type) { return $this->type === $type; }
    public function get_id() { return $this->id; }
}
class WC_Product_Simple extends WC_Product {}
class WC_Order {}
class A4Order extends WC_Order {
    public $meta = array();
    public $user_id = 7;
    public $id = 44;
    public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function get_user_id() { return $this->user_id; }
    public function get_id() { return $this->id; }
    public function get_date_created() { return new DateTime('2029-01-02 03:04:05', new DateTimeZone('UTC')); }
    public function get_date_paid() { return null; }
    public function get_date_completed() { return null; }
}

function __($text, $domain = null) { return $text; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($text, $domain = null) { return esc_html($text); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function esc_js($value) { return addslashes((string) $value); }
function esc_html_e($text, $domain = null) { echo esc_html($text); }
function wp_kses_post($value) { return (string) $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int) $value); }
function wp_verify_nonce($nonce, $action) { global $a4_nonce_valid; return $a4_nonce_valid; }
function current_user_can($capability, $post_id = null) { global $a4_can_edit; return $a4_can_edit; }
function update_post_meta($post_id, $key, $value) { global $a4_meta; $a4_meta[] = array($post_id, $key, $value); }
function get_post_meta($post_id, $key, $single = false) { global $a4_meta_values; return isset($a4_meta_values[$post_id][$key]) ? $a4_meta_values[$post_id][$key] : ''; }
function get_post_type() { return 'product'; }
function woocommerce_wp_text_input($args) { global $a4_field_args; $a4_field_args = $args; echo 'FIELD:' . esc_attr(json_encode($args)); }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { global $a4_hooks; $a4_hooks[] = array('action', $hook, $callback, $priority, $accepted_args); }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { global $a4_hooks; $a4_hooks[] = array('filter', $hook, $callback, $priority, $accepted_args); }
function WC() { global $a4_wc; return $a4_wc; }
function wc_get_product($product_id) { global $a4_product; return $a4_product; }
function wc_add_notice($message, $type) { global $a4_notices; $a4_notices[] = array($message, $type); }
function is_user_logged_in() { return true; }
function get_current_user_id() { global $a4_current_user; return $a4_current_user; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wc_get_account_endpoint_url($endpoint) { return 'https://example.test/account/' . $endpoint . '/'; }
function wp_nonce_field($action, $name, $referer = true) { echo 'NONCE:' . esc_html($action) . ':' . esc_html($name); }
function add_query_arg($key = null, $value = null) { return 'https://example.test/account/orders/'; }
function selected($selected, $current = true, $echo = true) { $out = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ($echo) { echo $out; } return $out; }

require_once dirname(__DIR__, 2) . '/src/Subscription/Presentation.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Composition.php';

$pass = 0;
$fail = 0;
function a4_assert($condition, $message) { global $pass, $fail; if ($condition) { $pass++; echo "PASS: {$message}\n"; } else { $fail++; echo "FAIL: {$message}\n"; } }
function a4_same($expected, $actual, $message) { a4_assert($expected === $actual, $message); }
function a4_output($callback) { ob_start(); call_user_func($callback); return ob_get_clean(); }
function a4_git_blob_sha($path) { $bytes = file_get_contents($path); return sha1('blob ' . strlen($bytes) . "\0" . $bytes); }

// Exact hook topology is owned by one composition boundary.
Composition::register_presentation_hooks();
$p = Presentation::class;
$expected_hooks = array(
    array('action', 'init', array($p, 'register_product_class'), 10, 1),
    array('filter', 'product_type_selector', 'addCustomProductType', 10, 1),
    array('filter', 'woocommerce_product_class', 'mapCustomProductClass', 10, 2),
    array('action', 'woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30, 1),
    array('action', 'admin_footer', 'customProductTypes', 10, 1),
    array('filter', 'woocommerce_product_data_tabs', 'addCustomDataTab', 10, 1),
    array('action', 'woocommerce_product_data_panels', 'addCustomDataPanel', 10, 1),
    array('action', 'woocommerce_process_product_meta', 'saveCustomFieldData', 10, 1),
    array('action', 'woocommerce_single_product_summary', 'displayCustomFieldOnFrontend', 10, 1),
    array('filter', 'woocommerce_get_item_data', 'displayCustomDataInCart', 10, 2),
    array('action', 'woocommerce_checkout_create_order_line_item', 'saveCustomDataToOrderItems', 10, 4),
    array('action', 'woocommerce_order_details_after_order_table', array($p, 'render_account_order_details'), 10, 1),
    array('action', 'woocommerce_before_account_orders', array($p, 'render_account_orders_filter'), 10, 1),
    array('filter', 'woocommerce_my_account_my_orders_query', array($p, 'filter_account_orders_query'), 10, 1),
    array('filter', 'woocommerce_my_account_my_orders_columns', array($p, 'filter_account_orders_columns'), 10, 1),
    array('action', 'woocommerce_my_account_my_orders_column_order_type', array($p, 'render_account_order_type'), 10, 1),
    array('action', 'woocommerce_my_account_my_orders_column_order_status', array($p, 'render_account_subscription_status'), 10, 1),
    array('action', 'woocommerce_admin_order_data_after_billing_address', array($p, 'render_admin_order_summary'), 10, 1),
);
a4_same($expected_hooks, $a4_hooks, 'complete product/account/admin presentation hook topology is frozen');

// Product type and admin schema remain exact.
Presentation::register_product_class();
a4_assert(class_exists('WCProductCustomType'), 'legacy global custom product class remains loadable');
a4_same('custom_type', (new WCProductCustomType())->get_type(), 'legacy custom product type identity is preserved');
a4_same(array('simple' => 'Simple', 'custom_type' => 'Subscription Product'), Presentation::add_custom_product_type(array('simple' => 'Simple')), 'product selector label and key are preserved');
a4_same('WCProductCustomType', Presentation::map_custom_product_class('WC_Product_Simple', 'custom_type'), 'custom product class mapping is preserved');
a4_same('OtherClass', Presentation::map_custom_product_class('OtherClass', 'simple'), 'unrelated product classes remain unchanged');
a4_same(array(
    'label' => 'Custom Settings', 'target' => 'custom_product_data_panel',
    'class' => array('show_if_custom_type'), 'priority' => 25,
), Presentation::add_custom_data_tab(array())['custom_settings'], 'complete custom product tab schema is preserved');
$a4_field_args = null;
a4_output(array(Presentation::class, 'add_custom_data_panel'));
a4_same(array(
    'id' => '_custom_field_id', 'label' => 'Custom Field', 'placeholder' => 'Enter value here',
    'desc_tip' => 'true', 'description' => 'This is a description of the field.',
), $a4_field_args, 'complete custom product field schema is preserved');
$product_js = a4_output(array(Presentation::class, 'custom_product_types'));
a4_assert(strpos($product_js, '.options_group.pricing') !== false && strpos($product_js, '.show_if_simple') !== false, 'custom product admin visibility selectors are preserved');

// Product-meta authorization stays fail-closed and retains the exact identity.
$_POST = array('woocommerce_meta_nonce' => 'n', 'post_ID' => '12', '_custom_field_id' => ' <b>value</b> ');
$a4_meta = array(); $a4_nonce_valid = false; $a4_can_edit = true;
Presentation::save_custom_field_data(12);
a4_same(array(), $a4_meta, 'invalid product nonce performs no write');
$a4_nonce_valid = true; $a4_can_edit = false;
Presentation::save_custom_field_data(12);
a4_same(array(), $a4_meta, 'missing edit_post authorization performs no write');
$a4_can_edit = true; $_POST['post_ID'] = '13';
Presentation::save_custom_field_data(12);
a4_same(array(), $a4_meta, 'posted product mismatch performs no write');
$_POST['post_ID'] = '12';
Presentation::save_custom_field_data(12);
a4_same(array(array(12, '_custom_field_id', 'value')), $a4_meta, 'authorized product meta preserves key and sanitized value');

// Product/cart/order-item presentation preserves the historical meta surface.
$a4_meta_values = array(12 => array('_custom_field_id' => '<b>Gold</b>'));
$product = new WC_Product(); $product->type = 'custom_type';
$html = a4_output(array(Presentation::class, 'display_custom_field_on_frontend'));
a4_assert(strpos($html, '&lt;b&gt;Gold&lt;/b&gt;') !== false && strpos($html, '<b>Gold</b>') === false, 'product custom value remains HTML-escaped');
$item_data = Presentation::display_custom_data_in_cart(array(), array('product_id' => 12));
a4_same(array(array('key' => 'Special Feature', 'value' => '<b>Gold</b>', 'display' => '')), $item_data, 'cart presentation retains exact label/value shape');
$line_item = new class { public $writes = array(); public function add_meta_data($key, $value) { $this->writes[] = array($key, $value); } };
Presentation::save_custom_data_to_order_items($line_item, 'cart-key', array('product_id' => 12), null);
a4_same(array(array('Special Feature', '<b>Gold</b>')), $line_item->writes, 'order-item copy retains exact label and product-meta bytes');

// Mixed-cart validation remains equivalent at both rejection boundaries.
$a4_product = new WC_Product();
Utils::$custom = true; Utils::$normal = false; $a4_notices = array();
a4_same(false, Presentation::restrict_mixed_cart_products(true, 12, 1, 'upayments'), 'normal product is rejected from a subscription cart');
a4_same(array(array('You can only add subscription products to the cart when a subscription item is present.', 'error')), $a4_notices, 'subscription-cart rejection message remains exact');
$a4_product->type = 'custom_type'; Utils::$custom = false; Utils::$normal = true; $a4_notices = array();
a4_same(false, Presentation::restrict_mixed_cart_products(true, 12, 1, 'upayments'), 'subscription product is rejected from a normal cart');
a4_same(array(array('Subscription products cannot be added together with normal products. Please complete your current purchase first.', 'error')), $a4_notices, 'normal-cart rejection message remains exact');
Utils::$normal = false;
a4_same(true, Presentation::restrict_mixed_cart_products(true, 12, 1, 'upayments'), 'compatible cart addition remains allowed');

// My Account filters/columns and outputs retain their established identities.
$_GET = array();
a4_same(array('limit' => 10), Presentation::filter_account_orders_query(array('limit' => 10)), 'empty account filter leaves query unchanged');
$_GET['subscription_filter'] = '<b>paused</b>';
a4_same(array(), Presentation::filter_account_orders_query(array()), 'malformed account filter cannot normalize into an allowed status');
$_GET['subscription_filter'] = 'paused';
a4_same(array('meta_query' => array(array('key' => '_upay_subscription_status', 'value' => 'paused'))), Presentation::filter_account_orders_query(array()), 'account filter retains exact subscription-status meta query');
$columns = Presentation::filter_account_orders_columns(array('order-number' => 'Order', 'order-status' => 'Woo Status', 'order-total' => 'Total'));
a4_same(array('order-number' => 'Order', 'order-status' => 'Woo Status', 'order_type' => 'Type', 'order_status' => 'Status', 'order-total' => 'Total'), $columns, 'account Type/Status columns retain exact insertion order');

$regular = new class extends WC_Order { public function get_meta($key) { return $key === 'UPayments_AutoDeduction' ? 'no' : ''; } };
$auto = new class extends WC_Order { public function get_meta($key) { return $key === 'UPayments_AutoDeduction' ? 'yes' : ''; } };
a4_same('Regular', a4_output(function () use ($regular) { Presentation::render_account_order_type($regular); }), 'manual subscription type label is preserved');
a4_same('Auto Deduction', a4_output(function () use ($auto) { Presentation::render_account_order_type($auto); }), 'auto-deduction type label is preserved');
$hostile = new class extends WC_Order { public function get_meta($key) { return $key === '_upay_subscription_status' ? 'active"><script>x</script>' : ''; } };
$status_html = a4_output(function () use ($hostile) { Presentation::render_account_subscription_status($hostile); });
a4_assert(strpos($status_html, '<script>') === false && strpos($status_html, '&lt;script&gt;') !== false, 'account subscription status remains escaped in class and text contexts');

$_GET = array('subscription_filter' => 'paused');
$filter_html = a4_output(array(Presentation::class, 'render_account_orders_filter'));
a4_assert(strpos($filter_html, 'name="subscription_filter"') !== false && strpos($filter_html, 'value="paused"  selected="selected"') !== false, 'account filter retains field identity and selected paused state');

$manual_order = new A4Order();
$manual_order->meta = array('_upay_subscription_plan' => 'monthly', '_upay_subscription_interval' => 2, '_upay_subscription_status' => 'paused', 'UPayments_AutoDeduction' => 'no');
$account_html = a4_output(function () use ($manual_order) { Presentation::render_account_order_details($manual_order); });
a4_assert(strpos($account_html, 'woocommerce-subscription-details') !== false, 'owned manual subscription renders account details');
a4_assert(strpos($account_html, 'name="upay_action" value="unsubscribe"') !== false && strpos($account_html, 'name="upay_action" value="resume"') !== false, 'manual paused subscription retains unsubscribe and resume POST actions');
a4_assert(strpos($account_html, 'NONCE:upay_unsubscribe_44:_wpnonce') !== false && strpos($account_html, 'NONCE:upay_resume_44:_wpnonce') !== false, 'manual actions retain exact action-specific nonce identities');
$other_order = clone $manual_order; $other_order->user_id = 8;
a4_same('', a4_output(function () use ($other_order) { Presentation::render_account_order_details($other_order); }), 'another customer subscription renders nothing');
$cancelled_order = clone $manual_order; $cancelled_order->meta['_upay_subscription_status'] = 'cancelled';
a4_same('', a4_output(function () use ($cancelled_order) { Presentation::render_account_order_details($cancelled_order); }), 'cancelled subscription account details remain suppressed');
$auto_order = clone $manual_order; $auto_order->meta['UPayments_AutoDeduction'] = 'yes';
$auto_html = a4_output(function () use ($auto_order) { Presentation::render_account_order_details($auto_order); });
a4_assert(strpos($auto_html, 'woocommerce-subscription-details') !== false && strpos($auto_html, 'name="upay_action"') === false, 'auto-deduction order keeps details but exposes no manual mutation forms');

$admin_html = a4_output(function () use ($manual_order) { Presentation::render_admin_summary($manual_order); });
a4_assert(strpos($admin_html, 'upay-subscription-summary') !== false && strpos($admin_html, 'Every 2 Month(s)') !== false, 'admin subscription summary retains plan/interval presentation');

// Gateway compatibility wrappers and high-risk boundaries remain in place.
$root = dirname(__DIR__, 2);
$gateway = file_get_contents($root . '/UPayments.php');
$composition = file_get_contents($root . '/src/Subscription/Composition.php');
$presentation = file_get_contents($root . '/src/Subscription/Presentation.php');
foreach (array('initializeSubscriptionModule', 'render_subscription_summary', 'restrictMixedCartProducts', 'renderSubscriptionBadgeInProductList') as $method) {
    a4_assert((bool) preg_match('/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $gateway), "legacy public {$method} seam remains callable");
}
foreach (array('addCustomProductType', 'mapCustomProductClass', 'customProductTypes', 'addCustomDataTab', 'addCustomDataPanel', 'saveCustomFieldData', 'displayCustomFieldOnFrontend', 'displayCustomDataInCart', 'saveCustomDataToOrderItems') as $function) {
    a4_assert((bool) preg_match('/function\s+' . preg_quote($function, '/') . '\s*\(/', $gateway), "legacy global {$function} compatibility seam remains callable");
}
$compact_gateway = preg_replace('/\s+/', '', $gateway);
$thin_wrappers = array(
    'functionaddCustomProductType($types){returnSubscriptionPresentation::add_custom_product_type($types);}',
    'functionmapCustomProductClass($classname,$product_type){returnSubscriptionPresentation::map_custom_product_class($classname,$product_type);}',
    'functioncustomProductTypes(){SubscriptionPresentation::custom_product_types();}',
    'functionaddCustomDataTab($tabs){returnSubscriptionPresentation::add_custom_data_tab($tabs);}',
    'functionaddCustomDataPanel(){SubscriptionPresentation::add_custom_data_panel();}',
    'functionsaveCustomFieldData($post_id){SubscriptionPresentation::save_custom_field_data($post_id);}',
    'functiondisplayCustomFieldOnFrontend(){SubscriptionPresentation::display_custom_field_on_frontend();}',
    'functiondisplayCustomDataInCart($item_data,$cart_item){returnSubscriptionPresentation::display_custom_data_in_cart($item_data,$cart_item);}',
    'functionsaveCustomDataToOrderItems($item,$cart_item_key,$values,$order){SubscriptionPresentation::save_custom_data_to_order_items($item,$cart_item_key,$values,$order);}',
    'publicfunctioninitializeSubscriptionModule(){SubscriptionComposition::initialize_legacy_modules();}',
    'publicfunctionrender_subscription_summary($order){SubscriptionPresentation::render_admin_summary($order);}',
    'publicfunctionrestrictMixedCartProducts($passed,$product_id,$quantity){returnSubscriptionPresentation::restrict_mixed_cart_products($passed,$product_id,$quantity,$this->domain);}',
    'publicfunctionrenderSubscriptionBadgeInProductList(){SubscriptionPresentation::render_subscription_badge();}',
);
foreach ($thin_wrappers as $wrapper) {
    a4_assert(strpos($compact_gateway, $wrapper) !== false, 'compatibility wrapper remains an exact one-call delegate: ' . substr($wrapper, 0, strpos($wrapper, '(')));
}
a4_assert(strpos($gateway, 'SubscriptionComposition::register_presentation_hooks();') !== false, 'main bootstrap activates the subscription composition boundary');
a4_assert(strpos($gateway, 'SubscriptionComposition::initialize_legacy_modules();') !== false, 'public subscription initializer delegates legacy checkout/storage composition');
a4_assert(strpos($gateway, 'SubscriptionPresentation::render_admin_summary($order);') !== false, 'admin summary wrapper delegates presentation');
a4_assert(strpos($gateway, "add_action('woocommerce_init', function ()") !== false && strpos($gateway, 'Scheduler::init();') !== false, 'protected scheduler bootstrap remains outside presentation boundary');
a4_assert(strpos($gateway, "update_meta_data('_upay_subscription_status', 'cancelled')") !== false, 'hardened customer mutation handler remains outside presentation boundary');
foreach (array('process_payment', 'auto-deduct', 'upay_process_subscriptions', 'upayments_billing_attempts', 'CURLOPT_', "update_meta_data('_upay_subscription_status'") as $forbidden) {
    a4_assert(strpos($presentation, $forbidden) === false, "presentation module excludes {$forbidden} ownership");
}
a4_assert(strpos($composition, 'register_presentation_hooks') !== false && strpos($composition, 'register_gateway_hooks') !== false, 'composition explicitly owns global and gateway hook registration');

// Exact protected scheduler/cycle-claim blobs remain unchanged except for the approved SUPCheckout identity migration.
a4_same('75be4ded83142f67a933be0e1c24cedc484f9a73', a4_git_blob_sha($root . '/includes/Subscription/Cron/Scheduler.php'), 'protected Scheduler blob matches approved SUPCheckout identity migration');
a4_same('c34d83e2d77cc65024fe663e4c378cecb2b17347', a4_git_blob_sha($root . '/includes/Subscription/Cron/CycleClaim.php'), 'protected CycleClaim blob remains exact');

echo "\nArchitecture Subscription Presentation: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
}
