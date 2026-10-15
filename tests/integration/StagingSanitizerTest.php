<?php
/**
 * Real WordPress/WooCommerce certification of the staging sanitizer
 * (tests/staging/lib/staging-sanitizer.php), in legacy and HPOS order storage.
 *
 * Runs as the last step of a compatibility cell because it scrubs the runtime.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/staging/lib/staging-sanitizer.php';

// The entry script's refusal applies to this real site's host.
$home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
supcheckout_cert_assert(
    !supcheckout_sanitizer_preflight('staging', true, $home_host, $home_host)['ok'],
    'sanitizer refuses when the site host is the production host'
);
supcheckout_cert_assert(
    supcheckout_sanitizer_preflight('staging', true, $home_host, 'production.example')['ok'],
    'sanitizer accepts an opted-in staging copy on another host'
);

// ---- Seed production-like data ----
$admin = get_users(array('role' => 'administrator', 'number' => 1));
supcheckout_cert_assert(!empty($admin), 'an administrator exists');
$admin_id = (int) $admin[0]->ID;
$admin_email = (string) $admin[0]->user_email;

$customer_id = wp_insert_user(array(
    'user_login' => 'staging_sanitizer_customer',
    'user_pass' => 'production-password',
    'user_email' => 'real.customer@gmail.com',
    'role' => 'customer',
));
supcheckout_cert_assert(is_int($customer_id), 'customer user created');
update_user_meta($customer_id, 'billing_phone', '+96599999999');
update_user_meta($customer_id, 'billing_email', 'real.customer@gmail.com');
update_user_meta($customer_id, 'billing_first_name', 'Fatima');
update_user_meta($customer_id, '_upay_customer_unique_token', 'cus_real_token');

$product = new WC_Product_Simple();
$product->set_name('Sanitizer Product');
$product->set_regular_price('12.500');
$product->set_price('12.500');
$product_id = $product->save();

$order = wc_create_order(array('customer_id' => $customer_id));
$order->set_payment_method('upayments');
$order->add_product(wc_get_product($product_id), 1);
$order->set_billing_first_name('Fatima');
$order->set_billing_last_name('Alsabah');
$order->set_billing_email('real.customer@gmail.com');
$order->set_billing_phone('+96599999999');
$order->set_billing_address_1('Block 3, Street 10');
$order->set_billing_city('Salmiya');
$order->set_billing_country('KW');
$order->set_customer_ip_address('203.0.113.7');
$order->set_customer_note('Leave at the door');
$order->update_meta_data('UPayments_order_id', 'sanitizer-provider-order');
$order->update_meta_data('_upay_credit_card_token', 'card_real_token');
$order->calculate_totals();
$order->set_status('processing');
$order_id = $order->save();
$total_before = wc_get_order($order_id)->get_total();
$currency_before = wc_get_order($order_id)->get_currency();

update_option('woocommerce_upayments_settings', array(
    'enabled' => 'yes',
    'api_key' => 'live-merchant-secret',
    'iban_number' => 'KW00BANK0000000000000000000000',
    'multimerchant_accounts' => '[{"merchant":"x"}]',
    'enable_multimerchant' => 'yes',
    'test_mode' => 'no',
    'title' => 'UPayments',
));
update_option('upayments_token_identity_secret_v2', 'copied-secret');
// Per-order UPayments jobs carry arguments; wp_clear_scheduled_hook() alone would miss them.
wp_schedule_single_event(time() + 3600, 'simplixpay_upayments_reconcile_order', array($order_id));
wp_schedule_single_event(time() + 7200, 'simplixpay_upayments_reconcile_secondary', array($order_id));
// Recurring jobs that UPayments plugins re-create on every page load (official 3.1.1 included).
wp_schedule_event(time() + 3600, 'hourly', 'upay_hourly_cron_job');
if (function_exists('as_schedule_single_action')) {
    as_schedule_single_action(time() + 3600, 'supcheckout_cert_sanitizer_pending');
}

$webhook = new WC_Webhook();
$webhook->set_name('Sanitizer webhook');
$webhook->set_user_id($admin_id);
$webhook->set_topic('order.created');
$webhook->set_delivery_url('https://example.invalid/hook');
$webhook->set_status('paused');
$webhook->save();

// Orders outside status 'any': a checkout draft and a trashed order.
$draft = wc_create_order();
$draft->set_billing_email('draft.customer@gmail.com');
$draft->set_billing_first_name('Draft');
$draft->set_customer_ip_address('203.0.113.8');
$draft->set_status('checkout-draft');
$draft_id = $draft->save();
$trashed = wc_create_order();
$trashed->set_billing_email('trashed.customer@gmail.com');
$trashed->set_billing_first_name('Trashed');
$trashed_id = $trashed->save();
$trashed->delete(false);

$note_id = wc_get_order($order_id)->add_order_note('Customer Fatima Alsabah called from +96599999999 about Block 3', 1);

$token = new WC_Payment_Token_CC();
$token->set_token('tok_real');
$token->set_gateway_id('upayments');
$token->set_card_type('visa');
$token->set_last4('4242');
$token->set_expiry_month('12');
$token->set_expiry_year('2030');
$token->set_user_id($customer_id);
$token->save();

$review_id = wp_insert_comment(array(
    'comment_post_ID' => $product_id,
    'comment_author' => 'Fatima',
    'comment_author_email' => 'real.customer@gmail.com',
    'comment_author_IP' => '203.0.113.7',
    'comment_content' => 'Great',
    'comment_type' => 'review',
    'comment_approved' => 1,
));

// ---- Dry run changes nothing ----
$dry = supcheckout_sanitizer_run(false);
supcheckout_cert_note('dry run: ' . wp_json_encode($dry));
foreach (array('gateway_secret_settings', 'token_identity_secret', 'upayments_cron_hooks', 'woocommerce_webhooks', 'token_meta_rows', 'data_table_rows', 'orders_anonymized', 'users_anonymized', 'comment_authors') as $step) {
    supcheckout_cert_assert((int) $dry[$step] > 0, "dry run reports pending work for $step");
}
wp_cache_flush();
supcheckout_cert_assert('real.customer@gmail.com' === wc_get_order($order_id)->get_billing_email(), 'dry run leaves order data unchanged');
supcheckout_cert_assert('live-merchant-secret' === get_option('woocommerce_upayments_settings')['api_key'], 'dry run leaves gateway settings unchanged');

// ---- Apply ----
supcheckout_sanitizer_run(true);
wp_cache_flush();

$after = wc_get_order($order_id);
supcheckout_cert_assert('customer-' . $order_id . '@example.invalid' === $after->get_billing_email(), 'order email anonymized');
supcheckout_cert_assert('Customer' === $after->get_billing_first_name() && '' === $after->get_billing_phone(), 'order name and phone anonymized');
supcheckout_cert_assert('' === (string) $after->get_customer_ip_address() && '' === (string) $after->get_customer_note(), 'order IP and customer note removed');
supcheckout_cert_assert('KW' === $after->get_billing_country(), 'order country kept for tax/shipping realism');
supcheckout_cert_assert($total_before === $after->get_total() && $currency_before === $after->get_currency(), 'order total and currency unchanged');
supcheckout_cert_assert('processing' === $after->get_status() && 'upayments' === $after->get_payment_method(), 'order status and payment method unchanged');
supcheckout_cert_assert('sanitizer-provider-order' === $after->get_meta('UPayments_order_id'), 'UPayments order identity kept');
supcheckout_cert_assert('' === $after->get_meta('_upay_credit_card_token'), 'order card token removed');

foreach (array($draft_id => 'checkout draft', $trashed_id => 'trashed order') as $hidden_id => $label) {
    $hidden = wc_get_order($hidden_id);
    supcheckout_cert_assert($hidden && 'customer-' . $hidden_id . '@example.invalid' === $hidden->get_billing_email(), "$label email anonymized");
    supcheckout_cert_assert($hidden && '' === (string) $hidden->get_customer_ip_address(), "$label IP removed");
}
$note = get_comment($note_id);
supcheckout_cert_assert(false === strpos($note->comment_content, 'Fatima') && false === strpos($note->comment_content, '99999999'), 'order note body no longer holds customer details');

$customer = get_userdata($customer_id);
supcheckout_cert_assert('customer-' . $customer_id === $customer->user_login && 'customer-' . $customer_id === $customer->user_nicename, 'customer login name replaced');
supcheckout_cert_assert(!wp_check_password('production-password', $customer->user_pass, $customer_id), 'customer production password no longer works');
supcheckout_cert_assert('user-' . $customer_id . '@example.invalid' === $customer->user_email, 'customer account email anonymized');
supcheckout_cert_assert('' === get_user_meta($customer_id, 'billing_phone', true), 'customer phone removed');
supcheckout_cert_assert('' === get_user_meta($customer_id, '_upay_customer_unique_token', true), 'customer provider token removed');
supcheckout_cert_assert($admin_email === get_userdata($admin_id)->user_email, 'administrator keeps their login email');

$settings = get_option('woocommerce_upayments_settings');
supcheckout_cert_assert('' === $settings['api_key'] && '' === $settings['iban_number'] && '' === $settings['multimerchant_accounts'], 'gateway secrets removed');
supcheckout_cert_assert('yes' === $settings['test_mode'] && 'no' === $settings['enable_multimerchant'], 'gateway forced to test mode without multi-merchant');
supcheckout_cert_assert('UPayments' === $settings['title'], 'non-secret gateway settings kept for the upgrade test');
supcheckout_cert_assert(false === get_option('upayments_token_identity_secret_v2', false), 'copied token identity secret removed');
supcheckout_cert_assert(false === wp_next_scheduled('simplixpay_upayments_reconcile_order', array($order_id)), 'per-order reconciliation job with arguments removed');
supcheckout_cert_assert(false === wp_next_scheduled('simplixpay_upayments_reconcile_secondary', array($order_id)), 'per-order secondary reconciliation job with arguments removed');
supcheckout_cert_assert(false !== wp_next_scheduled('upay_hourly_cron_job'), 'self-recreating official-plugin hourly job is left alone, not counted as remaining work');
if (function_exists('as_has_scheduled_action')) {
    supcheckout_cert_assert(!as_has_scheduled_action('supcheckout_cert_sanitizer_pending'), 'pending scheduled actions canceled');
}
supcheckout_cert_assert('disabled' === wc_get_webhook($webhook->get_id())->get_status(), 'WooCommerce webhooks disabled');
supcheckout_cert_assert(array() === WC_Payment_Tokens::get_customer_tokens($customer_id), 'stored payment tokens removed');
$review = get_comment($review_id);
supcheckout_cert_assert('reviewer-' . $review_id . '@example.invalid' === $review->comment_author_email && '' === $review->comment_author_IP, 'review author email and IP anonymized');
supcheckout_cert_assert('Great' !== $review->comment_content, 'review body replaced');

// ---- Idempotent: a second dry run finds nothing personal ----
$again = supcheckout_sanitizer_run(false);
supcheckout_cert_note('post-apply dry run: ' . wp_json_encode($again));
foreach ($again as $step => $count) {
    supcheckout_cert_assert(0 === (int) $count, "nothing left for $step after apply");
}

supcheckout_cert_note('staging sanitizer certified in ' . (supcheckout_sanitizer_hpos_authoritative() ? 'HPOS' : 'legacy') . ' storage');
