<?php
/**
 * Real-runtime certification for child-theme WooCommerce template precedence.
 *
 * The active child theme supplies its own new-design-form.php. SUPCheckout must
 * preserve WooCommerce's located template while still self-provisioning the
 * assets owned by the rendered modern payment-fields contract.
 */

require_once __DIR__ . '/bootstrap.php';

supcheckout_cert_assert(class_exists('WC_Upayments'), 'SUPCheckout gateway class is available for child-theme certification');

$expected_child_slug = getenv('SUPCHECKOUT_CHILD_THEME_SLUG');
$expected_parent_slug = getenv('SUPCHECKOUT_PARENT_THEME_SLUG');
$expected_marker = getenv('SUPCHECKOUT_CHILD_THEME_MARKER');

supcheckout_cert_assert(is_string($expected_child_slug) && $expected_child_slug !== '', 'Child-theme certification receives an expected child stylesheet slug');
supcheckout_cert_assert(is_string($expected_parent_slug) && $expected_parent_slug !== '', 'Child-theme certification receives an expected parent template slug');
supcheckout_cert_assert(is_string($expected_marker) && $expected_marker !== '', 'Child-theme certification receives an expected render marker');
supcheckout_cert_assert(get_stylesheet() === $expected_child_slug, 'Certification child theme is the active stylesheet');
supcheckout_cert_assert(get_template() === $expected_parent_slug, 'Certification child theme retains the expected parent template');

$child_template = trailingslashit(get_stylesheet_directory()) . 'woocommerce/new-design-form.php';
supcheckout_cert_assert(is_file($child_template), 'Certification child theme contains a WooCommerce new-design-form override');

$style_handles = array(
    'supcheckout-customer',
    'supcheckout-checkout-new-style',
);
$script_handles = array(
    'supcheckout-checkout-new-script',
    'supcheckout-subscription-checkout',
);

foreach ($style_handles as $handle) {
    wp_dequeue_style($handle);
    wp_deregister_style($handle);
}
foreach ($script_handles as $handle) {
    wp_dequeue_script($handle);
    wp_deregister_script($handle);
}

$GLOBALS['supcheckout_child_theme_located_template'] = null;
$template_observer = static function ($template, $template_name) {
    if ($template_name === 'new-design-form.php') {
        $GLOBALS['supcheckout_child_theme_located_template'] = $template;
    }
    return $template;
};
add_filter('wc_get_template', $template_observer, 999, 2);

$gateway = new WC_Upayments();
$gateway->settings['use_new_design'] = 'yes';
$gateway->autoDeduction = 'no';

ob_start();
$gateway->payment_fields();
$output = ob_get_clean();

remove_filter('wc_get_template', $template_observer, 999);

$located_template = $GLOBALS['supcheckout_child_theme_located_template'];
unset($GLOBALS['supcheckout_child_theme_located_template']);

supcheckout_cert_assert(
    is_string($located_template) && realpath($located_template) === realpath($child_template),
    'SUPCheckout preserves WooCommerce child-theme template precedence instead of forcing its plugin fallback'
);
supcheckout_cert_assert(
    strpos((string) $output, $expected_marker) !== false,
    'Gateway payment_fields renders the child-theme WooCommerce override'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-customer', 'enqueued'),
    'Child-theme modern payment-fields render still provisions shared customer CSS'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-checkout-new-style', 'enqueued'),
    'Child-theme modern payment-fields render still provisions modern checkout CSS'
);
supcheckout_cert_assert(
    wp_script_is('supcheckout-checkout-new-script', 'enqueued'),
    'Child-theme modern payment-fields render still provisions modern checkout JS'
);
supcheckout_cert_assert(
    ! wp_script_is('supcheckout-subscription-checkout', 'enqueued'),
    'Non-subscription child-theme render does not acquire subscription JS'
);

$scripts = wp_scripts();
$registered = isset($scripts->registered['supcheckout-checkout-new-script'])
    ? $scripts->registered['supcheckout-checkout-new-script']
    : null;
supcheckout_cert_assert(
    is_object($registered) && is_array($registered->deps) && in_array('jquery', $registered->deps, true),
    'Child-theme checkout script retains explicit jQuery dependency ordering'
);

fwrite(STDOUT, "CERT: child-theme checkout override certification complete\n");
