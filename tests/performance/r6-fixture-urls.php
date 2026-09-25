<?php
/**
 * Emit shell exports for real fixture URLs (classic/blocks checkout, callback, status).
 * Usage: eval "$(wp eval-file tests/performance/r6-fixture-urls.php --path=...)"
 * Only export lines are written to stdout.
 */

require_once __DIR__ . '/../integration/bootstrap.php';

$base = getenv('SUPCHECKOUT_SITE_URL') ?: 'http://127.0.0.1:8080';
update_option('home', $base);
update_option('siteurl', $base);

// Seed eligible gateway so Classic/Blocks UI can render SUPCheckout.
// Raw option write avoids Woo settings-change observer on malformed history.
$settings = array(
    'enabled' => 'yes',
    'api_key' => 'certification-key',
    'currency' => 'KWD',
);
update_option('woocommerce_upayments_settings', $settings);
update_option('woocommerce_currency', 'KWD');
update_option('woocommerce_coming_soon', 'no');
wp_cache_flush();

// Product for cart/checkout UI rendering.
$products = get_posts(array(
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'meta_key'       => '_sku',
    'meta_value'     => 'r6-e2e-product',
));
if ($products) {
    $product_id = (int) $products[0]->ID;
} else {
    $product_id = wp_insert_post(array(
        'post_title'  => 'R6 E2E Product',
        'post_status' => 'publish',
        'post_type'   => 'product',
    ));
    wp_set_object_terms($product_id, 'simple', 'product_type');
    update_post_meta($product_id, '_regular_price', '10.00');
    update_post_meta($product_id, '_price', '10.00');
    update_post_meta($product_id, '_sku', 'r6-e2e-product');
    update_post_meta($product_id, '_virtual', 'yes');
}
$product_url = get_permalink($product_id);

ob_start();

// Classic checkout page with [woocommerce_checkout].
$classic_id = (int) get_option('woocommerce_checkout_page_id');
if ($classic_id <= 0) {
    $classic_id = (int) wp_insert_post(array(
        'post_title'   => 'R6 Classic Checkout',
        'post_content' => '[woocommerce_checkout]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    update_option('woocommerce_checkout_page_id', $classic_id);
} else {
    wp_update_post(array(
        'ID'           => $classic_id,
        'post_content' => '[woocommerce_checkout]',
    ));
}
$classic_url = get_permalink($classic_id);

// Blocks checkout page: dedicated page with Checkout block markup.
$blocks_id = (int) get_option('r6_blocks_checkout_page_id');
$blocks_content = '<!-- wp:woocommerce/checkout -->' . "\n"
    . '<div class="wp-block-woocommerce-checkout">' . "\n"
    . '<!-- wp:woocommerce/checkout-fields-block /-->' . "\n"
    . '<!-- wp:woocommerce/checkout-totals-block /-->' . "\n"
    . '</div>' . "\n"
    . '<!-- /wp:woocommerce/checkout -->';
if ($blocks_id <= 0) {
    $blocks_id = (int) wp_insert_post(array(
        'post_title'   => 'R6 Blocks Checkout',
        'post_content' => $blocks_content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    update_option('r6_blocks_checkout_page_id', $blocks_id);
} else {
    wp_update_post(array(
        'ID'           => $blocks_id,
        'post_content' => $blocks_content,
    ));
}
$blocks_url = get_permalink($blocks_id);

$callback_url = function_exists('WC') && WC() && method_exists(WC(), 'api_request_url')
    ? WC()->api_request_url('wc_upayments')
    : $base . '/wc-api/wc_upayments/';

$status_url = $base . '/index.php?rest_route=/supcheckout/v1/order-status';

ob_end_clean();

$exports = array(
    'R6_BASE_URL' => $base,
    'R6_CLASSIC_CHECKOUT_URL' => (string) $classic_url,
    'R6_BLOCKS_CHECKOUT_URL' => (string) $blocks_url,
    'R6_CALLBACK_URL' => (string) $callback_url,
    'R6_STATUS_URL' => (string) $status_url,
    'R6_PRODUCT_URL' => (string) $product_url,
    'R6_IS_RTL' => function_exists('is_rtl') && is_rtl() ? '1' : '0',
);

foreach ($exports as $k => $v) {
    echo 'export ' . $k . '=' . escapeshellarg($v) . "\n";
}
