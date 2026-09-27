<?php
/**
 * Existing-install package-root migration certification.
 *
 * The pre-release product identity moves from simplixpay-upayments/ to
 * supcheckout/ while preserving merchant/payment data contracts.
 * The physical bootstrap filename remains UPayments.php because prior
 * qualification proved an in-place filename rename unsafe for activation.
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$phase = getenv('SUPCHECKOUT_UPGRADE_PHASE');

$legacy_slug = getenv('SUPCHECKOUT_LEGACY_SLUG');
if (!is_string($legacy_slug) || !preg_match('/^[a-z0-9-]+$/', $legacy_slug)) {
    throw new RuntimeException('SUPCHECKOUT_LEGACY_SLUG is required.');
}
$legacy_text_domain = getenv('SUPCHECKOUT_LEGACY_TEXT_DOMAIN');
if (!is_string($legacy_text_domain) || $legacy_text_domain === '') {
    throw new RuntimeException('SUPCHECKOUT_LEGACY_TEXT_DOMAIN is required.');
}
$legacy_basename = $legacy_slug . '/UPayments.php';
$canonical_basename = 'supcheckout/UPayments.php';
$future_basename = 'supcheckout/supcheckout.php';

$settings_key = 'woocommerce_upayments_settings';
$settings_snapshot_key = '_supcheckout_upgrade_settings_snapshot';
$order_key = '_supcheckout_upgrade_order_id';
$product_key = '_supcheckout_upgrade_product_id';
$cron_key = '_supcheckout_upgrade_cron_timestamp';

function supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key) {
    $snapshot = get_option($settings_snapshot_key);
    $settings = get_option($settings_key);

    supcheckout_cert_assert(is_string($snapshot) && $snapshot !== '', 'upgrade settings snapshot exists');
    supcheckout_cert_assert(
        is_string($snapshot) && hash_equals($snapshot, maybe_serialize($settings)),
        'merchant settings survive package-root transition byte-for-byte'
    );

    $order_id = (int) get_option($order_key);
    $order = wc_get_order($order_id);
    supcheckout_cert_assert($order instanceof WC_Order, 'historical payment order survives package-root transition');
    supcheckout_cert_assert('upayments' === $order->get_payment_method(), 'historical gateway ID remains upayments');
    supcheckout_cert_assert('upgrade-provider-order' === $order->get_meta('UPayments_order_id'), 'historical provider order identity survives package-root transition');
    supcheckout_cert_assert('upgrade-customer-token' === $order->get_meta('_upay_customer_unique_token'), 'historical customer-token metadata survives package-root transition');
    supcheckout_cert_assert('monthly' === $order->get_meta('_upay_subscription_plan'), 'historical subscription plan metadata survives package-root transition');
    supcheckout_cert_assert(1 === (int) $order->get_meta('_upay_subscription_interval'), 'historical subscription interval metadata survives package-root transition');

    $expected_cron = (int) get_option($cron_key);
    $actual_cron = wp_next_scheduled('upay_process_subscriptions');
    supcheckout_cert_assert($expected_cron > 0, 'upgrade snapshot contains canonical subscription cron timestamp');
    supcheckout_cert_assert(
        is_int($actual_cron) && $actual_cron === $expected_cron,
        'canonical subscription cron schedule survives package-root transition unchanged'
    );
}

function supcheckout_upgrade_translation_hits($plugin_root, $domain) {
    if (!is_dir($plugin_root)) {
        return 0;
    }

    $hits = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_root, FilesystemIterator::SKIP_DOTS)
    );
    $domain_pattern = preg_quote($domain, '/');

    foreach ($iterator as $file_info) {
        if (!$file_info->isFile() || strtolower($file_info->getExtension()) !== 'php') {
            continue;
        }
        $source = file_get_contents($file_info->getPathname());
        if (!is_string($source)) {
            continue;
        }
        $hits += preg_match_all(
            '/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\([^)]*[\'\"]' . $domain_pattern . '[\'\"]/s',
            $source,
            $matches
        );
    }

    return $hits;
}

function supcheckout_upgrade_assert_runtime_contract($mode, $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $future_basename) {
    $legacy = 'legacy' === $mode;
    $active_basename = $legacy ? $legacy_basename : $canonical_basename;
    $inactive_basename = $legacy ? $canonical_basename : $legacy_basename;
    $root = WP_PLUGIN_DIR . '/' . ($legacy ? $legacy_slug : 'supcheckout');
    $expected_domain = $legacy ? $legacy_text_domain : 'supcheckout';
    $label = $legacy ? 'pre-stable existing-install ' . $legacy_slug : 'canonical SUPCheckout';

    supcheckout_cert_assert(file_exists($root . '/UPayments.php'), $label . ' retains qualified UPayments.php bootstrap');
    if (!$legacy) {
        supcheckout_cert_assert(!file_exists(WP_PLUGIN_DIR . '/' . $future_basename), 'unsafe physical bootstrap rename remains absent');
    }
    supcheckout_cert_assert(is_plugin_active($active_basename), $label . ' plugin basename is active');
    supcheckout_cert_assert(!is_plugin_active($inactive_basename), 'alternate package identity is inactive');
    supcheckout_cert_assert(class_exists('WC_Upayments'), $label . ' gateway runtime loads');

    $gateways = WC()->payment_gateways()->payment_gateways();
    supcheckout_cert_assert(
        isset($gateways['upayments']) && $gateways['upayments'] instanceof WC_Upayments,
        'WooCommerce gateway ID upayments remains registered'
    );
    supcheckout_cert_assert(false !== has_action('woocommerce_api_wc_upayments'), 'historical WooCommerce API callback hook remains registered');

    $headers = get_file_data($root . '/UPayments.php', array('text_domain' => 'Text Domain'));
    supcheckout_cert_assert(
        isset($headers['text_domain']) && $headers['text_domain'] === $expected_domain,
        $label . ' exposes expected text domain'
    );

    $translation_hits = supcheckout_upgrade_translation_hits($root, $expected_domain);
    supcheckout_cert_assert($translation_hits > 0, $label . ' runtime contains explicit translation calls for expected text domain');
    supcheckout_cert_note($label . ' translation call count=' . $translation_hits);
}

if ('seed-existing' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('legacy', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $future_basename);

    // Prime the negative cache to keep this fixture representative of an
    // already-running merchant installation.
    get_option($settings_key, false);

    $settings = array(
        'enabled' => 'yes',
        'api_key' => 'upgrade-certification-secret',
        'test_mode' => 'yes',
        'enable_save_card' => 'yes',
        'enable_subscriptions' => 'yes',
        'enable_multimerchant' => 'no',
    );
    supcheckout_cert_store_option_raw($settings_key, $settings);
    supcheckout_cert_store_option_raw($settings_snapshot_key, maybe_serialize($settings));

    $product = new WC_Product_Simple();
    $product->set_name('Upgrade Certification Product');
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $product_id = $product->save();
    supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'upgrade certification product persists');
    update_option($product_key, $product_id, false);

    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'upgrade certification order is created');
    $order->set_payment_method('upayments');
    $order->add_product($product, 1);
    $order->update_meta_data('UPayments_order_id', 'upgrade-provider-order');
    $order->update_meta_data('_upay_customer_unique_token', 'upgrade-customer-token');
    $order->update_meta_data('_upay_subscription_plan', 'monthly');
    $order->update_meta_data('_upay_subscription_interval', 1);
    $order->calculate_totals();
    $order->save();
    update_option($order_key, $order->get_id(), false);

    $cron = wp_next_scheduled('upay_process_subscriptions');
    supcheckout_cert_assert(is_int($cron) && $cron > 0, 'canonical subscription cron exists on legacy active install');
    update_option($cron_key, $cron, false);

    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('pre-stable installation seeded: ' . $legacy_slug);
    return;
}

if ('verify-canonical' === $phase || 'verify-canonical-final' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('canonical', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $future_basename);
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('canonical SUPCheckout package-root migration verified');
    return;
}

if ('verify-legacy-rollback' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('legacy', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $future_basename);
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('legacy rollback remains non-destructive');
    return;
}

if ('cleanup' === $phase) {
    $order_id = (int) get_option($order_key);
    $order = wc_get_order($order_id);
    if ($order instanceof WC_Order) {
        $order->delete(true);
    }
    $product_id = (int) get_option($product_key);
    if ($product_id > 0) {
        wp_delete_post($product_id, true);
    }
    foreach (array($settings_snapshot_key, $order_key, $product_key, $cron_key) as $key) {
        delete_option($key);
    }
    supcheckout_cert_note('upgrade certification fixtures cleaned');
    return;
}

throw new RuntimeException('Unknown SUPCHECKOUT_UPGRADE_PHASE.');
