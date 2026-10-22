<?php
/**
 * Real-runtime release support metadata certification.
 */

require_once __DIR__ . '/bootstrap.php';

$plugin_file = SUPCHECKOUT_PLUGIN_FILE;
$headers = get_file_data(
    $plugin_file,
    array(
        'requires_wp'    => 'Requires at least',
        'tested_wp'      => 'Tested up to',
        'requires_php'   => 'Requires PHP',
        'requires_wc'    => 'WC requires at least',
        'tested_wc'      => 'WC tested up to',
    )
);

supcheckout_cert_assert('6.9' === $headers['requires_wp'], 'WordPress minimum support series is matrix-proven 6.9');
supcheckout_cert_assert('7.1' === $headers['tested_wp'], 'WordPress tested series is matrix-proven 7.1');
supcheckout_cert_assert('7.4' === $headers['requires_php'], 'PHP runtime floor is matrix-proven 7.4');
supcheckout_cert_assert('10.8' === $headers['requires_wc'], 'WooCommerce minimum support series is matrix-proven 10.8');
supcheckout_cert_assert('11.1' === $headers['tested_wc'], 'WooCommerce tested series is matrix-proven 11.1');

supcheckout_cert_assert(did_action('woocommerce_init') > 0, 'WooCommerce initialized before feature compatibility inspection');

$plugin_name = plugin_basename($plugin_file);
$feature_compatibility = Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_features_for_plugin($plugin_name);

supcheckout_cert_assert(
    isset($feature_compatibility['compatible']) && is_array($feature_compatibility['compatible']),
    'WooCommerce returns the compatible-feature registry for SUPCheckout'
);
supcheckout_cert_assert(
    isset($feature_compatibility['incompatible']) && is_array($feature_compatibility['incompatible']),
    'WooCommerce returns the incompatible-feature registry for SUPCheckout'
);
supcheckout_cert_assert(
    in_array('cart_checkout_blocks', $feature_compatibility['compatible'], true),
    'Cart and Checkout Blocks compatibility remains declared in the real WooCommerce registry'
);
supcheckout_cert_assert(
    !in_array('cart_checkout_blocks', $feature_compatibility['incompatible'], true),
    'Cart and Checkout Blocks are not simultaneously declared incompatible'
);
supcheckout_cert_assert(
    in_array('custom_order_tables', $feature_compatibility['compatible'], true),
    'HPOS custom_order_tables compatibility is declared in the real WooCommerce registry'
);
supcheckout_cert_assert(
    !in_array('custom_order_tables', $feature_compatibility['incompatible'], true),
    'HPOS custom_order_tables is not simultaneously declared incompatible'
);

// A4-01 (U7): with no `ar` language pack in WP_LANG_DIR, WordPress's own just-in-time loader
// and load_script_textdomain() find the bundled Arabic files through `lang_dir_for_domain`.
$domain_path = get_file_data($plugin_file, array('domain_path' => 'Domain Path'));
supcheckout_cert_assert('/languages' === $domain_path['domain_path'], 'plugin header names the bundled languages directory');
supcheckout_cert_assert(!is_readable(WP_LANG_DIR . '/plugins/supcheckout-ar.mo'), 'fixture: no Arabic language pack is installed, so only the bundled file exists');
$supcheckout_ar_locale = static function () {
    return 'ar';
};
unload_textdomain('supcheckout', true);
add_filter('pre_determine_locale', $supcheckout_ar_locale);
$arabic = __('Pay securely with', 'supcheckout');
wp_register_script('supcheckout-admin-i18n-cert', plugins_url('assets/admin/build/app.js', $plugin_file), array(), null, array('in_footer' => true));
$admin_json = load_script_textdomain('supcheckout-admin-i18n-cert', 'supcheckout');
remove_filter('pre_determine_locale', $supcheckout_ar_locale);
unload_textdomain('supcheckout', true);
wp_deregister_script('supcheckout-admin-i18n-cert');
supcheckout_cert_assert('ادفع بأمان عبر' === $arabic, 'with the ar locale active, "Pay securely with" resolves to the bundled Arabic translation: ' . $arabic);
$admin_translations = is_string($admin_json) ? json_decode($admin_json, true) : null;
supcheckout_cert_assert(
    is_array($admin_translations) && 'ar' === ($admin_translations['locale_data']['messages']['']['lang'] ?? null),
    'the admin app script translations resolve to the bundled Arabic JSON'
);
supcheckout_cert_assert('Pay securely with' === __('Pay securely with', 'supcheckout'), 'the site locale is English again after the Arabic check');

supcheckout_cert_note('release support metadata and feature compatibility certification complete');
