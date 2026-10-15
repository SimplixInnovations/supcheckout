<?php
/**
 * Real-runtime activation and Classic gateway certification.
 */

require_once __DIR__ . '/bootstrap.php';

$phase = getenv('SUPCHECKOUT_CERT_PHASE');
$snapshot_key = '_supcheckout_certification_settings_snapshot';
$activation_marker = '<!-- supcheckout-activation-certification -->';

if ('seed' === $phase) {
    $sentinel = (object) array(
        'enabled'               => 'yes',
        'api_key'               => 'certification-sentinel',
        'enable_block_checkout' => 'no',
    );

    supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $sentinel);

    $stored = get_option('woocommerce_upayments_settings');
    $serialized = maybe_serialize($stored);
    supcheckout_cert_assert(is_object($stored), 'malformed object-valued gateway settings are persisted before activation');
    supcheckout_cert_assert(isset($stored->api_key) && 'certification-sentinel' === $stored->api_key, 'pre-activation settings sentinel is exact');
    supcheckout_cert_assert(is_string($serialized) && '' !== $serialized, 'pre-activation protected settings serialize deterministically');
    supcheckout_cert_store_option_raw($snapshot_key, $serialized);

    $checkout_page_id = wc_get_page_id('checkout');
    supcheckout_cert_assert(is_int($checkout_page_id) && $checkout_page_id > 0, 'WooCommerce checkout page exists before SUPCheckout activation');

    $updated = wp_update_post(
        array(
            'ID'           => $checkout_page_id,
            'post_content' => $activation_marker,
        ),
        true
    );
    supcheckout_cert_assert(!is_wp_error($updated) && (int) $updated === $checkout_page_id, 'checkout page is seeded with the activation marker');

    $checkout_page = get_post($checkout_page_id);
    supcheckout_cert_assert(
        $checkout_page instanceof WP_Post && $activation_marker === $checkout_page->post_content,
        'activation marker is exact before SUPCheckout activation'
    );

    supcheckout_cert_note('activation seed complete');
    return;
}

if ('verify' === $phase) {
    $stored = get_option('woocommerce_upayments_settings');
    $snapshot = get_option($snapshot_key);

    supcheckout_cert_assert(is_object($stored), 'activation does not rewrite malformed persisted gateway settings');
    supcheckout_cert_assert(is_string($snapshot) && '' !== $snapshot, 'pre-activation protected settings snapshot is available');
    supcheckout_cert_assert(
        hash_equals($snapshot, maybe_serialize($stored)),
        'activation preserves the complete serialized protected settings option byte-for-byte'
    );
    supcheckout_cert_assert(class_exists('WooCommerce'), 'WooCommerce is active in the certification runtime');
    supcheckout_cert_assert(class_exists('WC_Upayments'), 'SUPCheckout Classic gateway class loads in the real WooCommerce runtime');

    $checkout_page_id = wc_get_page_id('checkout');
    $checkout_page = $checkout_page_id > 0 ? get_post($checkout_page_id) : null;
    // The merchant owns the checkout page. Page builders and checkout plugins render
    // checkout without the shortcode or block in post_content, so activation must
    // never rewrite the page.
    supcheckout_cert_assert(
        $checkout_page instanceof WP_Post && $activation_marker === $checkout_page->post_content,
        'SUPCheckout activation leaves the merchant checkout page content byte-for-byte unchanged'
    );

    $gateways = WC()->payment_gateways()->payment_gateways();
    supcheckout_cert_assert(is_array($gateways), 'WooCommerce returns a real payment gateway registry');
    supcheckout_cert_assert(isset($gateways['upayments']), 'Classic gateway ID upayments is registered');
    supcheckout_cert_assert($gateways['upayments'] instanceof WC_Upayments, 'registered Classic gateway is the SUPCheckout gateway instance');

    supcheckout_cert_note('activation and Classic gateway certification complete');
    return;
}

throw new RuntimeException('Unknown SUPCHECKOUT_CERT_PHASE.');
