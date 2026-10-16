<?php

use Simplixi\SUPCheckout\Admin\GatewaySettings;

$a3_assets = array('styles' => array(), 'scripts' => array(), 'inline' => array());

function __($text, $domain = null) {
    return $text;
}

function esc_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_attr($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post($value) {
    return (string) $value;
}

function esc_html_e($text, $domain = null) {
    echo esc_html($text);
}

function sanitize_title($value) {
    return strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $value));
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function wc_clean($value) {
    return sanitize_text_field($value);
}

function selected($selected, $current = true, $echo = true) {
    $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}

function wp_enqueue_style($handle, $src, $deps = array(), $version = false) {
    global $a3_assets;
    $a3_assets['styles'][] = array($handle, $src, $deps, $version);
}

function wp_enqueue_script($handle, $src, $deps = array(), $version = false, $footer = false) {
    global $a3_assets;
    $a3_assets['scripts'][] = array($handle, $src, $deps, $version, $footer);
}

function wp_add_inline_style($handle, $css) {
    global $a3_assets;
    $a3_assets['inline'][] = array($handle, $css);
}

require_once dirname(__DIR__, 2) . '/src/Release/Identity.php';
require_once dirname(__DIR__, 2) . '/src/Admin/GatewaySettings.php';

$pass = 0;
$fail = 0;

function a3_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

function a3_assert_same($expected, $actual, $message) {
    a3_assert($expected === $actual, $message);
}

function a3_reset_assets() {
    global $a3_assets;
    $a3_assets = array('styles' => array(), 'scripts' => array(), 'inline' => array());
}

function a3_asset_handles($type) {
    global $a3_assets;
    return array_map(function ($asset) {
        return $asset[0];
    }, $a3_assets[$type]);
}

// Exact gateway settings identity/schema.
$fields = GatewaySettings::fields('upayments', 'UPayments', 'Gateway description');
$expected_fields = require dirname(__DIR__) . '/fixtures/architecture-a3-gateway-settings-schema.php';
$expected_keys = array(
    'enabled',
    'make_default_gateway',
    'title',
    'description',
    'api_key',
    'debug',
    'test_mode',
    'is_order_complete',
    'save_card_section_title',
    'use_new_design',
    'enable_save_card',
    'multimerchant_section_title',
    'enable_multimerchant',
    'iban_number',
    'cc_charge',
    'cc_charge_type',
    'knet_charge',
    'knet_charge_type',
    'multimerchant_accounts',
    'autodeduction_section_title',
    'enable_subscriptions',
);
a3_assert_same($expected_keys, array_keys($fields), 'gateway field keys and order are preserved exactly');
a3_assert_same(21, count($fields), 'gateway schema contains exactly the inherited 21 fields');
a3_assert_same($expected_fields, $fields, 'complete nested gateway field schema matches frozen characterization');
a3_assert_same('yes', $fields['enabled']['default'], 'gateway remains enabled by default');
a3_assert_same('no', $fields['make_default_gateway']['default'], 'default-gateway opt-in remains disabled');
a3_assert_same('UPayments', $fields['title']['default'], 'checkout title default remains method title');
a3_assert_same('Gateway description', $fields['description']['default'], 'checkout description default remains method description');
a3_assert_same('', $fields['api_key']['default'], 'API key default remains empty');
a3_assert_same('password', $fields['api_key']['type'], 'API key uses a masked WooCommerce password control');
a3_assert_same('no', $fields['debug']['default'], 'debug logging remains opt-in');
a3_assert_same('no', $fields['test_mode']['default'], 'test mode remains opt-in');
a3_assert_same('yes', $fields['is_order_complete']['default'], 'paid-order completed setting remains enabled by default');
a3_assert_same('yes', $fields['use_new_design']['default'], 'new-design setting remains enabled by default');
a3_assert_same('yes', $fields['enable_save_card']['default'], 'save-card setting remains enabled by default');
a3_assert_same('no', $fields['enable_multimerchant']['default'], 'multi-merchant setting remains opt-in');
a3_assert_same('no', $fields['enable_subscriptions']['default'], 'subscriptions remain opt-in');
a3_assert_same('display:none;', $fields['iban_number']['css'], 'legacy IBAN scalar field remains hidden');
a3_assert_same('display:none;', $fields['cc_charge']['css'], 'legacy card-charge scalar field remains hidden');
a3_assert_same('display:none;', $fields['knet_charge']['css'], 'legacy KNET-charge scalar field remains hidden');
a3_assert_same('multimerchant_repeater', $fields['multimerchant_accounts']['type'], 'legacy custom field type is preserved');

// Save-card/subscription normalization remains byte-equivalent to inherited truthiness.
$normalized = GatewaySettings::normalize_dependencies(array('enable_subscriptions' => 'yes'));
a3_assert_same(true, $normalized['forced_save_card'], 'subscriptions force save-card when save-card is absent');
a3_assert_same('yes', $normalized['settings']['enable_save_card'], 'forced save-card uses Woo checkbox yes value');
$normalized = GatewaySettings::normalize_dependencies(array('enable_subscriptions' => '', 'enable_save_card' => ''));
a3_assert_same(false, $normalized['forced_save_card'], 'empty subscription value does not force save-card');
$normalized = GatewaySettings::normalize_dependencies(array('enable_subscriptions' => 'no', 'enable_save_card' => 'yes'));
a3_assert_same(false, $normalized['forced_save_card'], 'existing enabled save-card requires no correction');
a3_assert_same('no', $normalized['settings']['enable_subscriptions'], 'dependency normalization preserves subscription bytes');

// Posted settings validation preserves the historical key and clearing contract.
$missing_api = GatewaySettings::prepare_post_data(array('woocommerce_upayments_api_key' => ''));
a3_assert_same(true, $missing_api['api_key_missing'], 'empty protected API-key post field blocks settings processing');
a3_assert_same(false, $missing_api['multimerchant_missing'], 'API-key failure does not invent a multi-merchant error');
a3_assert_same(array('woocommerce_upayments_api_key' => ''), $missing_api['post_data'], 'API-key failure leaves post data unchanged');

$complete_multi = array(
    'woocommerce_upayments_api_key' => 'secret',
    'woocommerce_upayments_enable_multimerchant' => 1,
    'woocommerce_upayments_iban_number' => 'KW01',
    'woocommerce_upayments_cc_charge' => '1.000',
    'woocommerce_upayments_cc_charge_type' => 'fixed',
    'woocommerce_upayments_knet_charge' => '2.000',
    'woocommerce_upayments_knet_charge_type' => 'percentage',
);
$prepared = GatewaySettings::prepare_post_data($complete_multi);
a3_assert_same(false, $prepared['api_key_missing'], 'non-empty API key passes settings preflight');
a3_assert_same(false, $prepared['multimerchant_missing'], 'complete single allocation passes settings preflight');
a3_assert_same($complete_multi, $prepared['post_data'], 'complete single allocation is preserved byte-for-byte');

$incomplete_multi = $complete_multi;
$incomplete_multi['woocommerce_upayments_iban_number'] = '';
$prepared = GatewaySettings::prepare_post_data($incomplete_multi);
a3_assert_same(true, $prepared['multimerchant_missing'], 'enabled allocation requires all five legacy values');
a3_assert_same('', $prepared['post_data']['woocommerce_upayments_iban_number'], 'invalid enabled allocation is not silently rewritten');

$disabled_multi = $complete_multi;
unset($disabled_multi['woocommerce_upayments_enable_multimerchant']);
$prepared = GatewaySettings::prepare_post_data($disabled_multi);
foreach (array('iban_number', 'cc_charge', 'cc_charge_type', 'knet_charge', 'knet_charge_type') as $key) {
    a3_assert_same(null, $prepared['post_data']['woocommerce_upayments_' . $key], "disabled allocation clears {$key}");
}

// Historical JSON presentation field sanitizer remains bounded and redacted.
$raw_rules = json_encode(array(array(
    'iban_number' => ' <b>KW01</b> ',
    'knet_charge' => ' 1.000 ',
    'knet_charge_type' => ' fixed ',
    'cc_charge' => ' 2.000 ',
    'cc_charge_type' => ' percentage ',
    'merchant_id' => 'must-not-survive',
    'api_key' => 'must-not-survive',
)));
$sanitized = json_decode(GatewaySettings::sanitize_multimerchant_accounts($raw_rules), true);
a3_assert_same(1, count($sanitized), 'sanitizer preserves one submitted presentation record');
a3_assert_same(array('iban_number', 'knet_charge', 'knet_charge_type', 'cc_charge', 'cc_charge_type'), array_keys($sanitized[0]), 'sanitizer preserves only five non-secret allocation fields');
a3_assert_same('KW01', $sanitized[0]['iban_number'], 'IBAN presentation value is sanitized');
a3_assert_same('1.000', $sanitized[0]['knet_charge'], 'KNET charge presentation value is sanitized');
a3_assert_same('percentage', $sanitized[0]['cc_charge_type'], 'charge-type presentation value is cleaned');
a3_assert(!isset($sanitized[0]['merchant_id']) && !isset($sanitized[0]['api_key']), 'sanitizer cannot persist routing credentials');
a3_assert_same('[]', GatewaySettings::sanitize_multimerchant_accounts('{'), 'malformed JSON fails to empty presentation list');

// Renderer freezes the current one-row, five-field, escaped presentation.
$options = array(
    'multimerchant_accounts' => '[{"legacy":true}]',
    'iban_number' => 'KW01\"><script>x</script>',
    'knet_charge' => '1.000',
    'knet_charge_type' => 'fixed',
    'cc_charge' => '2.000',
    'cc_charge_type' => 'percentage',
);
$reader = function ($key, $default = false) use ($options) {
    return array_key_exists($key, $options) ? $options[$key] : $default;
};
$html = GatewaySettings::render_multimerchant(
    'multimerchant_accounts',
    $fields['multimerchant_accounts'],
    $reader,
    'upayments',
    'upayments'
);
a3_assert_same(1, substr_count($html, '<tbody>'), 'renderer emits one allocation table body');
a3_assert_same(1, substr_count($html, 'name="woocommerce_upayments_iban_number"'), 'renderer emits exactly one IBAN input');
a3_assert_same(1, substr_count($html, 'name="woocommerce_upayments_knet_charge"'), 'renderer emits exactly one KNET charge input');
a3_assert_same(1, substr_count($html, 'name="woocommerce_upayments_cc_charge"'), 'renderer emits exactly one card charge input');
a3_assert_same(1, substr_count($html, 'name="woocommerce_upayments_multimerchant_accounts"'), 'renderer preserves hidden custom-field identity');
a3_assert(strpos($html, '<script>x</script>') === false, 'renderer escapes stored IBAN in attribute context');
a3_assert(strpos($html, '&quot;&gt;&lt;script&gt;x&lt;/script&gt;') !== false, 'renderer retains escaped stored value bytes');
a3_assert(strpos($html, 'merchant_id') === false && strpos($html, 'api_key') === false, 'renderer exposes no routing credentials');
a3_assert(strpos($html, 'add_multimerchant_rule') === false, 'renderer does not advertise arbitrary multi-split rows');
a3_assert_same(2, substr_count($html, 'selected="selected"'), 'renderer preserves both selected charge types through exact option matching');

// Admin assets remain confined to the inherited WooCommerce settings surfaces.
a3_reset_assets();
GatewaySettings::enqueue_admin_assets(
    'https://example.test/plugin/',
    'upayments',
    array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'upayments'),
    'woocommerce_page_wc-settings'
);
a3_assert_same(array('upayments-multimerchant-style'), a3_asset_handles('styles'), 'allocation stylesheet loads on exact gateway settings page');
a3_assert_same(array('upayments-admin-logic'), a3_asset_handles('scripts'), 'only live SUPCheckout admin logic loads on the exact gateway settings page');
a3_assert_same('https://example.test/plugin/assets/js/admin-settings.js', $a3_assets['scripts'][0][1], 'admin settings script path is preserved');
a3_assert_same(1, count($a3_assets['inline']), 'disabled-row style remains registered once');
a3_assert_same(
    array(array('upayments-multimerchant-style', 'https://example.test/plugin/assets/css/admin-style.css', array(), '0.1.0')),
    $a3_assets['styles'],
    'complete allocation stylesheet registration is preserved'
);
a3_assert_same(
    array(
        array('upayments-admin-logic', 'https://example.test/plugin/assets/js/admin-settings.js', array('jquery'), '0.1.0', true),
    ),
    $a3_assets['scripts'],
    'complete gateway settings script registration is exact and contains no dead repeater runtime'
);
a3_assert_same(1, count($a3_assets['inline']), 'gateway settings register one scoped inline style payload');
a3_assert_same('woocommerce_admin_styles', $a3_assets['inline'][0][0], 'scoped inline style targets WooCommerce admin styles');
a3_assert(strpos($a3_assets['inline'][0][1], '.upayments-disabled-setting') !== false, 'save-card disabled-state styling is retained');
foreach (array('iban_number', 'cc_charge', 'cc_charge_type', 'knet_charge', 'knet_charge_type') as $legacy_field) {
    a3_assert(
        strpos($a3_assets['inline'][0][1], '#woocommerce_upayments_' . $legacy_field) !== false,
        "legacy {$legacy_field} row is hidden through an exact SUPCheckout field selector"
    );
}
a3_assert(
    strpos($a3_assets['inline'][0][1], 'input[style*="display:none"]') === false,
    'scoped inline style contains no generic hidden-input selector'
);

a3_reset_assets();
GatewaySettings::enqueue_admin_assets(
    'https://example.test/plugin/',
    'upayments',
    array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'cod'),
    'woocommerce_page_wc-settings',
    '0.1.0'
);
a3_assert_same(array(), a3_asset_handles('styles'), 'SUPCheckout admin styles do not load for another gateway');
a3_assert_same(array(), a3_asset_handles('scripts'), 'SUPCheckout admin scripts do not load for another gateway');
a3_assert_same(array(), $a3_assets['inline'], 'SUPCheckout inline admin styles do not load for another gateway');

a3_reset_assets();
GatewaySettings::enqueue_admin_assets('https://example.test/plugin/', 'upayments', array(), 'dashboard');
a3_assert_same(array(), a3_asset_handles('styles'), 'admin styles do not load outside settings');
a3_assert_same(array(), a3_asset_handles('scripts'), 'admin scripts do not load outside settings');

// Source/delegation boundary keeps runtime payment orchestration outside Admin settings.
$root = dirname(__DIR__, 2);
$module_source = file_get_contents($root . '/src/Admin/GatewaySettings.php');
$gateway_source = file_get_contents($root . '/supcheckout.php');
$checkout_orchestrator_source = file_get_contents($root . '/src/Payment/CheckoutOrchestrator.php');
$checkout_runtime_source = $gateway_source . "\n" . $checkout_orchestrator_source;
a3_assert(is_string($module_source), 'gateway settings module source is readable');
a3_assert(is_string($gateway_source), 'gateway source is readable');
a3_assert(strpos($module_source, 'namespace Simplixi\\SUPCheckout\\Admin;') !== false, 'settings module uses canonical SUPCheckout Admin namespace');
a3_assert(strpos($module_source, 'process_payment') === false, 'settings module does not own checkout orchestration');
a3_assert(strpos($module_source, 'execute_upayments_request') === false, 'settings module does not own provider transport');
a3_assert(strpos($module_source, 'UPayments_order_id') === false, 'settings module does not own payment/order truth');
a3_assert(strpos($gateway_source, "require_once __DIR__ . '/src/Admin/GatewaySettings.php';") !== false, 'plugin bootstrap explicitly loads settings module');
a3_assert(strpos($gateway_source, 'GatewaySettings::fields(') !== false, 'legacy form-field entry point delegates schema ownership');
a3_assert(strpos($gateway_source, 'GatewaySettings::prepare_post_data(') !== false, 'legacy settings-save entry point delegates validation');
a3_assert(strpos($gateway_source, 'GatewaySettings::render_multimerchant(') !== false, 'legacy renderer delegates presentation');
a3_assert(strpos($gateway_source, 'GatewaySettings::sanitize_multimerchant_accounts(') !== false, 'legacy custom-field validator delegates sanitation');
a3_assert(strpos($gateway_source, 'GatewaySettings::enqueue_admin_assets(') !== false, 'legacy admin enqueue entry point delegates asset scope');
a3_assert(strpos($gateway_source, "add_action('admin_head'") === false, 'gateway bootstrap has no global admin_head presentation hook');
a3_assert(strpos($gateway_source, 'input[style*="display:none"]') === false, 'gateway bootstrap has no cross-extension hidden-input selector');
a3_assert(!is_file($root . '/assets/js/multimerchant-repeater.js'), 'dead multimerchant repeater runtime is deleted');
$admin_css = file_get_contents($root . '/assets/css/admin-style.css');
a3_assert(is_string($admin_css) && strpos($admin_css, '.multimerchant-row-template') === false, 'stale repeater-template CSS is deleted');
$admin_js = file_get_contents($root . '/assets/js/admin-settings.js');
a3_assert(is_string($admin_js), 'live admin settings script is readable');
a3_assert(strpos($admin_js, "if (\n        !\$newDesignCheckbox.length") !== false, 'admin settings script fails closed when exact gateway DOM is absent');
a3_assert(strpos($admin_js, "typeof originalChecked !== 'undefined'") !== false, 'save-card state is restored only after a prior state was captured');
a3_assert(strpos($admin_js, "\$multiMerchantCheckbox.on('change', toggleMultiMerchantState)") !== false, 'multimerchant presentation responds to live setting changes');
a3_assert(strpos($gateway_source, 'wc_input_multimerchant_repeater') === false, 'gateway monolith no longer owns allocation HTML');
a3_assert(strpos($gateway_source, "'enabled' => array(") === false, 'gateway monolith no longer owns settings schema');
foreach (array('init_form_fields', 'process_admin_options', 'generate_multimerchant_repeater_html', 'validate_multimerchant_repeater_field', 'admin_enqueue_scripts') as $method) {
    a3_assert((bool) preg_match('/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $gateway_source), "legacy public {$method} seam remains callable");
}
a3_assert(strpos($checkout_runtime_source, "'extraMerchantData' => \$extraMerchantData") !== false, 'runtime Charge payload still owns extraMerchantData');
a3_assert(strpos($checkout_runtime_source, "'ibanNumber'     => \$iban") !== false, 'runtime allocation still uses legacy IBAN property');
a3_assert(strpos($checkout_runtime_source, "'amount'         => '__UPAY_MM_AMOUNT_SENTINEL__'") !== false, 'single allocation remains exact order-amount sentinel');
a3_assert(strpos($checkout_runtime_source, "array(\n                    array(\n                        'amount'         => '__UPAY_MM_AMOUNT_SENTINEL__'") !== false, 'runtime payload retains exactly one nested allocation entry');

echo "\nArchitecture Gateway Settings: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
