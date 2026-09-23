<?php
/**
 * Seed persisted gateway state for the real HTTP request-context certification.
 *
 * The compatibility sequence intentionally leaves malformed gateway settings
 * behind to prove activation/read safety. Request-context certification must
 * replace that fixture without firing WooCommerce's settings-change observer,
 * because the observer itself assumes its previous option value is an array.
 */

require_once __DIR__ . '/bootstrap.php';

$currency = getenv('SUPCHECKOUT_CERT_CURRENCY');
$enabled = getenv('SUPCHECKOUT_CERT_ENABLED');
$api_key = getenv('SUPCHECKOUT_CERT_API_KEY');

if (!is_string($currency) || $currency === '') {
    throw new RuntimeException('SUPCHECKOUT_CERT_CURRENCY is required.');
}
if (!is_string($enabled) || ($enabled !== 'yes' && $enabled !== 'no')) {
    throw new RuntimeException('SUPCHECKOUT_CERT_ENABLED must be yes or no.');
}
if (!is_string($api_key)) {
    throw new RuntimeException('SUPCHECKOUT_CERT_API_KEY must be present, including an explicit empty string.');
}

supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array(
        'enabled' => $enabled,
        'api_key' => $api_key,
    )
);

update_option('woocommerce_currency', $currency);
wp_cache_flush();

supcheckout_cert_note(
    sprintf(
        'HTTP context state seeded: currency=%s enabled=%s api_key=%s',
        $currency,
        $enabled,
        $api_key === '' ? 'empty' : 'present'
    )
);