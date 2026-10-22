<?php
/**
 * Real WooCommerce certification for fresh-install gateway default hydration.
 *
 * A gateway constructed with no persisted settings must hydrate its cached
 * runtime properties from the same defaults declared by its settings schema.
 */

require_once __DIR__ . '/bootstrap.php';

$option_name = 'woocommerce_upayments_settings';
$missing = '__supcheckout_gateway_defaults_missing__';
$original = get_option($option_name, $missing);

// Exercise the true fresh-install path rather than an empty persisted array.
delete_option($option_name);
wp_cache_delete($option_name, 'options');

$fresh = new \Simplixi\SUPCheckout\Gateway\Gateway();
$fresh_fields = $fresh->get_form_fields();

supcheckout_cert_assert(
    isset($fresh_fields['title']['default']) && $fresh->title === $fresh_fields['title']['default'],
    'Fresh gateway cached title matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['description']['default']) && $fresh->description === $fresh_fields['description']['default'],
    'Fresh gateway cached description matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['debug']['default']) && $fresh->debug === $fresh_fields['debug']['default'],
    'Fresh gateway cached debug setting matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['test_mode']['default']) && $fresh->testMode === $fresh_fields['test_mode']['default'],
    'Fresh gateway cached provider mode matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['is_order_complete']['default']) && $fresh->isOrderComplete === $fresh_fields['is_order_complete']['default'],
    'Fresh gateway cached order-completion preference matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['enable_multimerchant']['default']) && $fresh->multiMerchant === $fresh_fields['enable_multimerchant']['default'],
    'Fresh gateway cached multi-merchant setting matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['enable_save_card']['default']) && $fresh->saveCardEnabled === $fresh_fields['enable_save_card']['default'],
    'Fresh gateway cached save-card setting matches declared WooCommerce default'
);
supcheckout_cert_assert(
    isset($fresh_fields['enable_subscriptions']['default']) && $fresh->autoDeduction === $fresh_fields['enable_subscriptions']['default'],
    'Fresh gateway cached subscription setting matches declared WooCommerce default'
);
supcheckout_cert_assert(
    $fresh->apiKey === '',
    'Fresh gateway API key remains empty and cannot become runtime-eligible accidentally'
);
supcheckout_cert_assert(
    $fresh->is_available() === false,
    'Fresh unconfigured gateway remains unavailable despite declared enabled default'
);

// The same constructor path must continue to hydrate explicit merchant values.
$configured_settings = array(
    'enabled'              => 'yes',
    'api_key'              => 'constructor-certification-key',
    'title'                => 'Configured constructor title',
    'description'          => 'Configured constructor description',
    'debug'                => 'yes',
    'test_mode'            => 'yes',
    'is_order_complete'    => 'no',
    'enable_multimerchant' => 'yes',
    'iban_number'          => 'KW81CBKU0000000000001234560101',
    'cc_charge'            => '1.25',
    'cc_charge_type'       => 'fixed',
    'knet_charge'          => '0.50',
    'knet_charge_type'     => 'fixed',
    'enable_save_card'     => 'no',
    'enable_subscriptions' => 'no',
);
supcheckout_cert_store_option_raw($option_name, $configured_settings);
$configured = new \Simplixi\SUPCheckout\Gateway\Gateway();

supcheckout_cert_assert($configured->title === 'Configured constructor title', 'Configured gateway cached title is preserved');
supcheckout_cert_assert($configured->description === 'Configured constructor description', 'Configured gateway cached description is preserved');
supcheckout_cert_assert($configured->debug === 'yes', 'Configured gateway cached debug setting is preserved');
supcheckout_cert_assert($configured->testMode === 'yes', 'Configured gateway cached provider mode is preserved');
supcheckout_cert_assert($configured->isOrderComplete === 'no', 'Configured gateway cached order-completion preference is preserved');
supcheckout_cert_assert($configured->multiMerchant === 'yes', 'Configured gateway cached multi-merchant setting is preserved');
supcheckout_cert_assert($configured->saveCardEnabled === 'no', 'Configured gateway cached save-card setting is preserved');
supcheckout_cert_assert($configured->autoDeduction === 'no', 'Configured gateway cached subscription setting is preserved');
supcheckout_cert_assert($configured->apiKey === 'constructor-certification-key', 'Configured gateway cached API credential is preserved');

// Hardening T6 (A6-02): an admin save with Subscriptions on and Save Card absent (the old
// design's admin script disables, and so never posts, the Save Card checkbox) runs with no
// WooCommerce session. Save Card is still forced on, and the merchant is told through the
// admin settings messages; nothing reaches wc_add_notice() and its null session.
if (!class_exists('WC_Admin_Settings', false)) {
    require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
}
supcheckout_cert_store_option_raw($option_name, array(
    'enabled'              => 'yes',
    'api_key'              => 'admin-save-certification-key',
    'use_new_design'       => 'no',
    'enable_save_card'     => 'no',
    'enable_subscriptions' => 'no',
));
$admin_save_gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();
$admin_save_gateway->set_post_data(array(
    'woocommerce_upayments_enabled'              => '1',
    'woocommerce_upayments_api_key'              => 'admin-save-certification-key',
    'woocommerce_upayments_enable_subscriptions' => '1',
));
$admin_save_session = WC()->session;
WC()->session = null;
$admin_save_thrown = 'none';
try {
    $admin_save_gateway->process_admin_options();
} catch (\Throwable $admin_save_error) {
    $admin_save_thrown = get_class($admin_save_error) . ': ' . $admin_save_error->getMessage();
}
WC()->session = $admin_save_session;
wp_cache_delete($option_name, 'options');
$admin_saved = get_option($option_name);
ob_start();
\WC_Admin_Settings::show_messages();
$admin_save_messages = (string) ob_get_clean();

supcheckout_cert_assert($admin_save_thrown === 'none', 'Admin save with Subscriptions on and Save Card absent completes with no WooCommerce session (no fatal): ' . $admin_save_thrown);
supcheckout_cert_assert(is_array($admin_saved) && isset($admin_saved['enable_save_card']) && $admin_saved['enable_save_card'] === 'yes', 'Admin save still forces Save Card on while Subscriptions are enabled');
supcheckout_cert_assert(strpos($admin_save_messages, 'Save Card must be enabled when Subscriptions are enabled, so it was turned on.') !== false, 'Admin save tells the merchant through the WooCommerce settings messages that Save Card was turned on');

if ($original === $missing) {
    delete_option($option_name);
} else {
    supcheckout_cert_store_option_raw($option_name, $original);
}
wp_cache_delete($option_name, 'options');

supcheckout_cert_note('Fresh gateway default-hydration certification complete');
