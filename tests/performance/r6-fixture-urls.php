<?php
/**
 * Emit shell exports for real fixture URLs (classic/blocks checkout, callback, status).
 * Usage: eval "$(wp eval-file tests/performance/r6-fixture-urls.php --path=...)"
 */

require_once __DIR__ . '/../integration/bootstrap.php';

$base = getenv('SUPCHECKOUT_SITE_URL') ?: 'http://127.0.0.1:8080';

// Classic checkout page with [woocommerce_checkout].
$classic_id = (int) get_option('woocommerce_checkout_page_id');
if ($classic_id <= 0) {
    $classic_id = wp_insert_post(array(
        'post_title'   => 'R6 Classic Checkout',
        'post_content' => '[woocommerce_checkout]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    update_option('woocommerce_checkout_page_id', (int) $classic_id);
} else {
    wp_update_post(array(
        'ID'           => $classic_id,
        'post_content' => '[woocommerce_checkout]',
    ));
}
$classic_url = get_permalink($classic_id);

// Blocks checkout page: create a dedicated page with the Checkout block.
$blocks_id = (int) get_option('r6_blocks_checkout_page_id');
if ($blocks_id <= 0) {
    $blocks_id = wp_insert_post(array(
        'post_title'   => 'R6 Blocks Checkout',
        'post_content' => '<!-- wp:woocommerce/checkout -->',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    update_option('r6_blocks_checkout_page_id', (int) $blocks_id);
}
$blocks_url = get_permalink($blocks_id);

$callback_url = function_exists('WC') && WC() && method_exists(WC(), 'api_request_url')
    ? WC()->api_request_url('wc_upayments')
    : $base . '/wc-api/wc_upayments/';

$status_url = $base . '/index.php?rest_route=/supcheckout/v1/order-status';

echo 'export R6_BASE_URL=' . escapeshellarg($base) . "\n";
echo 'export R6_CLASSIC_CHECKOUT_URL=' . escapeshellarg((string) $classic_url) . "\n";
echo 'export R6_BLOCKS_CHECKOUT_URL=' . escapeshellarg((string) $blocks_url) . "\n";
echo 'export R6_CALLBACK_URL=' . escapeshellarg((string) $callback_url) . "\n";
echo 'export R6_STATUS_URL=' . escapeshellarg((string) $status_url) . "\n";
echo 'export R6_IS_RTL=' . escapeshellarg(function_exists('is_rtl') ? (is_rtl() ? '1' : '0') : '0') . "\n";
