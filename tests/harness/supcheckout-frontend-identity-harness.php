<?php
/**
 * SUPCheckout first-party frontend identity contract.
 *
 * Provider/Woo compatibility IDs such as "upayments" are intentionally not
 * banned globally. This harness owns only first-party handles, DOM roots,
 * callable JS namespace, and release-facing asset names.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function sufi_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

function sufi_read($root, $path) {
    $value = @file_get_contents($root . '/' . $path);
    return is_string($value) ? $value : '';
}

$gateway = sufi_read($root, 'UPayments.php');
$blocks = sufi_read($root, 'includes/class-wc-gateway-upayments-blocks.php');
$settings = sufi_read($root, 'src/Admin/GatewaySettings.php');
$new_template = sufi_read($root, 'templates/new-design-form.php');
$old_template = sufi_read($root, 'templates/old-design-form.php');
$new_js = sufi_read($root, 'assets/js/new-upay.js');
$subscription_js = sufi_read($root, 'assets/js/subscription-checkout.js');
$customer_css = sufi_read($root, 'assets/css/customer.css');
$blocks_js = sufi_read($root, 'assets/js/upayments-block.js');

foreach (array(
    'supcheckout-customer',
    'supcheckout-checkout-new-style',
    'supcheckout-checkout-new-script',
    'supcheckout-subscription-checkout',
) as $handle) {
    sufi_assert(strpos($gateway, "'" . $handle . "'") !== false, 'canonical first-party enqueue handle exists: ' . $handle);
}

foreach (array(
    'customer-new-style',
    'custom-checkout-new-style',
    'custom-checkout-script',
    'custom-checkout-old-style',
    'custom-checkout-old-script',
    'upayments-subscription-checkout',
    'supcheckout-checkout-legacy-script',
) as $retired) {
    sufi_assert(strpos($gateway, "'" . $retired . "'") === false, 'retired/generic first-party enqueue handle absent: ' . $retired);
}

sufi_assert(strpos($blocks, "'supcheckout-block-checkout'") !== false, 'Blocks script handle is SUPCheckout-owned');
sufi_assert(strpos($blocks, "return [ 'supcheckout-block-checkout' ];") !== false, 'Blocks returns canonical SUPCheckout script handle');

sufi_assert(strpos($gateway, "'supcheckout-checkout-legacy-style'") === false, 'empty legacy stylesheet handle is absent');
sufi_assert(strpos($gateway, 'assets/css/old-design.css') === false, 'empty legacy stylesheet is not enqueued');
sufi_assert(strpos($gateway, 'includes/admin-footer.php') === false, 'empty admin-footer include is not registered');
sufi_assert(strpos($gateway, 'your-gateway-core') === false, 'nonexistent script handle is not localized');
sufi_assert(strpos($gateway, 'upayments-debug') === false, 'default-gateway selection emits no unconditional diagnostic logging');
sufi_assert(strpos($blocks_js, 'console.log(') === false, 'Blocks runtime contains no console debugging');
sufi_assert(strpos($gateway, 'UPayemnts') === false, 'active provider icon alt text is spelled UPayments');
sufi_assert(strpos($gateway, 'assets/images/logo.png') === false, 'gateway does not retain duplicate provider-logo path');
sufi_assert(strpos($gateway, 'assets/images/upayment.png') !== false, 'gateway uses the single canonical provider-logo asset');

foreach (array(
    'assets/css/old-design.css',
    'includes/admin-footer.php',
    'assets/js/upayments-blocks-integration.js',
    'assets/js/upayments-thankyou.js',
    'assets/js/checkout/constants.js',
    'assets/js/checkout/data.js',
    'assets/images/disabled.gif',
    'assets/js/upay.js',
    'assets/images/logo.png',
    'assets/js/old-upay.js',
    'assets/images/loader.gif',
    'assets/images/check.png',
) as $dead_asset) {
    sufi_assert(!is_file($root . '/' . $dead_asset), 'proven dead runtime asset is absent: ' . $dead_asset);
}

foreach (array(
    'UPayments.php' => $gateway,
    'src/Admin/GatewaySettings.php' => $settings,
    'includes/class-wc-gateway-upayments-blocks.php' => $blocks,
) as $path => $source) {
    sufi_assert(strpos($source, "'3.0.0'") === false, 'first-party asset cache version is not frozen in ' . $path);
}
sufi_assert(strpos($gateway, 'SUPCHECKOUT_VERSION') !== false, 'classic checkout assets bind to canonical SUPCheckout version');
sufi_assert(strpos($settings, '\\Simplixi\\SUPCheckout\\Release\\Identity::VERSION') !== false, 'admin assets bind to canonical SUPCheckout release identity');
sufi_assert(strpos($blocks, '\\Simplixi\\SUPCheckout\\Release\\Identity::VERSION') !== false, 'Blocks asset binds to canonical SUPCheckout release identity');

sufi_assert(strpos($new_template, 'supcheckout') !== false, 'new checkout template exposes canonical SUPCheckout root');
sufi_assert(strpos($old_template, 'supcheckout') !== false, 'legacy checkout template exposes canonical SUPCheckout root');

sufi_assert(strpos($new_js, 'window.supCheckout') !== false, 'classic checkout JS exposes canonical SUPCheckout namespace');
foreach (array('function submitUpayButton', 'function submitSavedCard', 'function toggleSaveCard', 'function showToast') as $global) {
    sufi_assert(strpos($new_js, $global) === false, 'classic checkout JS does not expose legacy generic global: ' . $global);
}
sufi_assert(strpos($new_template, 'supCheckout.') !== false, 'new checkout template invokes canonical JS namespace');

foreach (array(
    'new checkout template' => $new_template,
    'legacy checkout template' => $old_template,
) as $label => $template_source) {
    sufi_assert(strpos($template_source, '$_GET[') === false, $label . ' does not trust query flags for payment notices');
    sufi_assert(strpos($template_source, '<script>') === false, $label . ' emits no inline payment-notice script');
    if ($label === 'new checkout template') {
        sufi_assert(strpos($template_source, '<style>') === false, 'new checkout template emits no inline style block');
    }
}


sufi_assert(!is_dir($root . '/assets/screenshots'), 'legacy repository screenshot source directory is absent');

sufi_assert(strpos($gateway, "'supcheckout-checkout-legacy-script'") === false, 'obsolete legacy checkout script handle is absent');
sufi_assert(!is_file($root . '/assets/js/old-upay.js'), 'obsolete legacy checkout script file is absent');
sufi_assert(!is_file($root . '/assets/images/loader.gif'), 'oversized animated status loader is absent');
sufi_assert(!is_file($root . '/assets/images/check.png'), 'oversized decorative success raster is absent');
sufi_assert(strpos($gateway, 'assets/images/loader.gif') === false, 'gateway contains no animated status-loader reference');
sufi_assert(strpos($gateway, 'assets/images/check.png') === false, 'gateway contains no decorative success-raster reference');
sufi_assert(strpos($gateway, 'upayment-status-holder-strong') === false, 'dead hidden thank-you status holder is absent');
sufi_assert(strpos($gateway, 'upayment-id-holder-strong') === false, 'dead hidden thank-you payment-ID holder is absent');
sufi_assert(strpos($gateway, '$order->is_paid()') !== false, 'thank-you success rendering follows WooCommerce paid-state semantics');
sufi_assert(strpos($new_js, 'ApplePaySession') === false, 'classic checkout performs no no-op Apple Pay capability polling');
sufi_assert(strpos($new_js, '#payment_method_upayments') === false, 'classic checkout never targets the UPayments payment option for forced client-side selection');
sufi_assert(strpos($new_js, 'ajaxComplete') === false, 'classic checkout avoids global ajaxComplete polling');
sufi_assert(strpos($new_js, 'updated_checkout') !== false, 'classic checkout reacts to the WooCommerce checkout update event');
sufi_assert(strpos($subscription_js, "\$intervalSelect.append(\$('<option></option>').val('one_time'))") === false, 'subscription interval never receives the invalid one_time token');
sufi_assert(strpos($subscription_js, "showToast(") === false, 'subscription checkout does not call an undefined global toast helper');
sufi_assert(strpos($subscription_js, ".val('0')") !== false, 'one-time subscription state normalizes interval to zero');
sufi_assert(strpos($customer_css, '.woocommerce .order-again') === false, 'SUPCheckout customer CSS does not style unrelated WooCommerce order-again controls');


echo "\nSUPCheckout Frontend Identity: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
