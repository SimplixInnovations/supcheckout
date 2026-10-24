<?php
/**
 * Customer-facing text must reach translators.
 *
 * Arabic (and every other) storefront needs the order-totals rows translatable in
 * the supcheckout text domain, the checkout description read through
 * WC_Payment_Gateway::get_description() so WPML/WCML translations apply, and the
 * merchant's configured payment-method title left untouched on paid orders.
 */

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
function i18n_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$label}\n";
}

$GLOBALS['i18n_calls'] = array();
function __($text, $domain = 'default') {
    $GLOBALS['i18n_calls'][] = array($text, $domain);
    return $domain === 'supcheckout' ? '[ar] ' . $text : $text;
}

class WC_Order {
    public function get_payment_method() { return 'upayments'; }
    public function get_meta($key) {
        $meta = array('UPayments_Result' => 'CAPTURED', 'UPayments_PaymentID' => '100628810000039116');
        return isset($meta[$key]) ? $meta[$key] : '';
    }
}

require_once $root . '/src/Takeover/TakeoverState.php';
require_once $root . '/src/Takeover/Keys.php';
require $root . '/src/Gateway/OrderPresentation.php';

$rows = \Simplixi\SUPCheckout\Gateway\OrderPresentation::add_order_item_totals(
    array('cart_subtotal' => array('label' => 'Subtotal:', 'value' => '1'), 'payment_method' => array('label' => 'Payment method:', 'value' => 'KNET')),
    new WC_Order(),
    'upayments'
);
i18n_assert(isset($rows['payment_status']) && $rows['payment_status']['label'] === '[ar] Payment Status:', 'order-totals payment status label is translatable in the supcheckout domain');
i18n_assert(isset($rows['upayment_id']) && $rows['upayment_id']['label'] === '[ar] UPayments payment ID:', 'order-totals payment ID label reads "UPayments payment ID" (O4) and is translatable in the supcheckout domain');
$gateway_source = (string) file_get_contents($root . '/src/Gateway/Gateway.php');
i18n_assert(strpos($gateway_source, "esc_html_e('UPayments payment ID', 'supcheckout')") !== false && strpos($gateway_source, "'UPayment ID'") === false, 'admin order payment ID label reads "UPayments payment ID" (O4)');
i18n_assert(strpos($gateway_source, "esc_html_e('Payment Status', 'supcheckout')") !== false, 'admin order "Payment Status" label is unchanged (O4)');
i18n_assert(isset($rows['payment_status']) && $rows['payment_status']['value'] === 'CAPTURED', 'provider status value itself is unchanged');

$template = (string) file_get_contents($root . '/templates/old-design-form.php');
i18n_assert(strpos($template, '$gateway->get_description()') !== false, 'classic checkout description is read through get_description() so WPML/WCML translations apply');
i18n_assert(!preg_match('/\$gateway->description\b/', $template), 'classic checkout never prints the raw untranslated description property');

$lifecycle = (string) file_get_contents($root . '/src/Payment/PaymentLifecycle.php');
i18n_assert(strpos($lifecycle, "'_payment_method_title'") === false, 'a verified capture never overwrites the merchant-configured payment method title');

echo "\nI18n presentation: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
