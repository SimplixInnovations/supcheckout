<?php
/**
 * Existing-install package-root migration certification.
 *
 * The pre-release product identity moves from simplixpay-upayments/ to
 * supcheckout/ while preserving merchant/payment data contracts.
 * Pre-stable packages ship the UPayments.php bootstrap; the canonical package
 * ships supcheckout/supcheckout.php since Sub-project 0a (owner decision
 * 2026-10-16, pre-release) plus a header-less transitional UPayments.php shim
 * that moves a pre-rename supcheckout/UPayments.php active entry in place.
 *
 * Phases: seed-existing / verify-canonical / verify-legacy-rollback /
 * verify-canonical-final / cleanup for the package-root migration, and
 * seed-in-place / record-in-place-cron / verify-in-place for the in-place
 * update of an active pre-rename build (SUPCHECKOUT_LEGACY_SLUG=supcheckout).
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
$canonical_basename = 'supcheckout/supcheckout.php';
$pre_rename_basename = 'supcheckout/UPayments.php';

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

function supcheckout_upgrade_assert_runtime_contract($mode, $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename) {
    $legacy = 'legacy' === $mode;
    $active_basename = $legacy ? $legacy_basename : $canonical_basename;
    $inactive_basename = $legacy ? $canonical_basename : $legacy_basename;
    $root = WP_PLUGIN_DIR . '/' . ($legacy ? $legacy_slug : 'supcheckout');
    $expected_domain = $legacy ? $legacy_text_domain : 'supcheckout';
    $label = $legacy ? 'pre-stable existing-install ' . $legacy_slug : 'canonical SUPCheckout';
    $main_file = basename($active_basename);

    supcheckout_cert_assert(file_exists($root . '/' . $main_file), $label . ' ships its ' . $main_file . ' bootstrap');
    if (!$legacy) {
        $shim_path = WP_PLUGIN_DIR . '/' . $pre_rename_basename;
        supcheckout_cert_assert(is_file($shim_path), 'canonical package ships the transitional UPayments.php shim');
        $shim_headers = get_file_data($shim_path, array('name' => 'Plugin Name'));
        supcheckout_cert_assert(isset($shim_headers['name']) && $shim_headers['name'] === '', 'transitional UPayments.php shim carries no plugin header');
        wp_cache_delete('plugins', 'plugins');
        $listed = array();
        foreach (array_keys(get_plugins()) as $plugin_basename) {
            if (strpos($plugin_basename, 'supcheckout/') === 0) {
                $listed[] = $plugin_basename;
            }
        }
        supcheckout_cert_assert($listed === array($canonical_basename), 'WordPress lists exactly one SUPCheckout plugin: ' . $canonical_basename);
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

    $headers = get_file_data($root . '/' . $main_file, array('text_domain' => 'Text Domain'));
    supcheckout_cert_assert(
        isset($headers['text_domain']) && $headers['text_domain'] === $expected_domain,
        $label . ' exposes expected text domain'
    );

    $translation_hits = supcheckout_upgrade_translation_hits($root, $expected_domain);
    supcheckout_cert_assert($translation_hits > 0, $label . ' runtime contains explicit translation calls for expected text domain');
    supcheckout_cert_note($label . ' translation call count=' . $translation_hits);
}

function supcheckout_upgrade_seed_merchant_state($settings_key, $settings_snapshot_key, $order_key, $product_key) {
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
}

function supcheckout_upgrade_record_cron($cron_key, $label) {
    $cron = wp_next_scheduled('upay_process_subscriptions');
    supcheckout_cert_assert(is_int($cron) && $cron > 0, 'canonical subscription cron exists on ' . $label);
    update_option($cron_key, $cron, false);
}

if ('seed-existing' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('legacy', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename);
    supcheckout_upgrade_seed_merchant_state($settings_key, $settings_snapshot_key, $order_key, $product_key);
    supcheckout_upgrade_record_cron($cron_key, 'legacy active install');
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('pre-stable installation seeded: ' . $legacy_slug);
    return;
}

if ('seed-in-place' === $phase) {
    // The pre-rename build is active as supcheckout/UPayments.php. Its hourly tick is
    // scheduled on the next request once subscriptions are enabled, so the cron
    // timestamp is recorded by record-in-place-cron after that request.
    supcheckout_cert_assert('supcheckout/UPayments.php' === $legacy_basename, 'in-place cell runs against the pre-rename supcheckout/UPayments.php basename');
    supcheckout_upgrade_assert_runtime_contract('legacy', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename);
    supcheckout_upgrade_seed_merchant_state($settings_key, $settings_snapshot_key, $order_key, $product_key);
    supcheckout_cert_note('pre-rename active installation seeded: ' . $legacy_basename);
    return;
}

if ('record-in-place-cron' === $phase) {
    supcheckout_cert_assert(is_plugin_active($legacy_basename), 'pre-rename build is still active as ' . $legacy_basename . ' before the update');
    supcheckout_upgrade_record_cron($cron_key, 'pre-rename active install');
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    return;
}

if ('verify-in-place' === $phase) {
    $active = get_option('active_plugins', array());
    supcheckout_cert_assert(is_array($active) && in_array($canonical_basename, $active, true), 'in-place update moved the active entry to ' . $canonical_basename);
    supcheckout_cert_assert(is_array($active) && !in_array($pre_rename_basename, $active, true), 'no ' . $pre_rename_basename . ' entry remains in active_plugins');
    supcheckout_cert_assert(is_array($active) && count(array_keys($active, $canonical_basename, true)) === 1, $canonical_basename . ' is listed once');

    // The Plugins screen runs this validation and deactivates entries whose file is invalid.
    $invalid = validate_active_plugins();
    supcheckout_cert_assert(is_array($invalid) && $invalid === array(), 'validate_active_plugins() reports no invalid SUPCheckout entry');
    supcheckout_cert_assert(is_plugin_active($canonical_basename), $canonical_basename . ' is active after plugin validation');

    supcheckout_upgrade_assert_runtime_contract('canonical', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename);
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('in-place update migrated ' . $pre_rename_basename . ' to ' . $canonical_basename . ' without deactivation');
    return;
}

if ('verify-canonical' === $phase || 'verify-canonical-final' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('canonical', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename);
    supcheckout_upgrade_verify_data($settings_key, $settings_snapshot_key, $order_key, $cron_key);
    supcheckout_cert_note('canonical SUPCheckout package-root migration verified');
    return;
}

if ('verify-legacy-rollback' === $phase) {
    supcheckout_upgrade_assert_runtime_contract('legacy', $legacy_slug, $legacy_text_domain, $legacy_basename, $canonical_basename, $pre_rename_basename);
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
