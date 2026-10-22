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
    supcheckout_cert_assert(class_exists('Simplixi\SUPCheckout\Gateway\Gateway'), 'SUPCheckout Classic gateway class loads in the real WooCommerce runtime');

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
    supcheckout_cert_assert($gateways['upayments'] instanceof \Simplixi\SUPCheckout\Gateway\Gateway, 'registered Classic gateway is the SUPCheckout gateway instance');
    // Third parties call the public compatibility wrapper on the registered object.
    supcheckout_cert_assert(
        is_callable(array($gateways['upayments'], 'getAPIUrlForRetreiveCards'))
            && substr((string) $gateways['upayments']->getAPIUrlForRetreiveCards(), -strlen('retrieve-customer-cards')) === 'retrieve-customer-cards',
        'registered gateway keeps the public getAPIUrlForRetreiveCards() compatibility wrapper'
    );

    supcheckout_cert_deactivation_round_trip($snapshot);

    supcheckout_cert_note('activation and Classic gateway certification complete');
    return;
}

throw new RuntimeException('Unknown SUPCHECKOUT_CERT_PHASE.');

/**
 * A3-08: deactivating SUPCheckout in real WordPress stops its own schedules (the hourly
 * Action Scheduler sweep, its throttle marker and the new-name subscription tick twin),
 * keeps the protected upay_process_subscriptions tick at its exact next run and keeps the
 * protected settings byte-for-byte. Reactivating schedules the sweep again at once. The
 * plugin ends active, as the later certification steps expect.
 *
 * @param string $settings_snapshot Serialized protected settings from the seed phase.
 * @return void
 */
function supcheckout_cert_deactivation_round_trip($settings_snapshot) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $sweep = \Simplixi\SUPCheckout\Payment\ReconciliationSweep::HOOK;
    $basename = plugin_basename(SUPCHECKOUT_PLUGIN_FILE);
    $twin = 'supcheckout_process_subscriptions';

    \Simplixi\SUPCheckout\Payment\ReconciliationSweep::ensure_scheduled();
    supcheckout_cert_assert(as_has_scheduled_action($sweep, array(), 'supcheckout'), 'the hourly reconciliation sweep is scheduled while SUPCheckout is active');
    $legacy_sentinel = wp_next_scheduled('upay_process_subscriptions') === false;
    if ($legacy_sentinel) {
        wp_schedule_event(time() + 3600, 'hourly', 'upay_process_subscriptions');
    }
    $legacy_next = wp_next_scheduled('upay_process_subscriptions');
    if (wp_next_scheduled($twin) === false) {
        wp_schedule_event(time() + 3600, 'hourly', $twin);
    }

    deactivate_plugins($basename, false, false);

    supcheckout_cert_assert(!is_plugin_active($basename), 'SUPCheckout deactivates');
    supcheckout_cert_assert(!as_has_scheduled_action($sweep, array(), 'supcheckout'), 'deactivation unschedules the hourly reconciliation sweep');
    supcheckout_cert_assert(get_option('supcheckout_sweep_ensured_at', false) === false, 'deactivation deletes the sweep throttle marker');
    supcheckout_cert_assert(wp_next_scheduled($twin) === false, 'deactivation unschedules the new-name subscription tick twin');
    supcheckout_cert_assert(
        $legacy_next !== false && wp_next_scheduled('upay_process_subscriptions') === $legacy_next,
        'deactivation keeps the protected upay_process_subscriptions tick at its exact next run'
    );
    supcheckout_cert_assert(
        hash_equals((string) $settings_snapshot, maybe_serialize(get_option('woocommerce_upayments_settings'))),
        'deactivation keeps the protected settings option byte-for-byte'
    );

    $activated = activate_plugin($basename, '', false, true);
    supcheckout_cert_assert(!is_wp_error($activated) && is_plugin_active($basename), 'SUPCheckout reactivates');
    \Simplixi\SUPCheckout\Payment\ReconciliationSweep::ensure_scheduled();
    supcheckout_cert_assert(as_has_scheduled_action($sweep, array(), 'supcheckout'), 'the first request after a reactivation schedules the sweep again at once');

    if ($legacy_sentinel) {
        wp_clear_scheduled_hook('upay_process_subscriptions');
    }
    supcheckout_cert_note('deactivation cleanup round trip complete');
}
