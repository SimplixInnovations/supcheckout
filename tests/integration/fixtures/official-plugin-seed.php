<?php
/**
 * Seeds a store the way the official UPayments plugin leaves it, for the Sub-project 0b
 * Task 12 migration matrix (tests/integration/TakeoverMatrixTest.php). Never shipped.
 *
 * Shapes come from docs/superpowers/specs/2026-10-16-official-plugin-data-inventory.md
 * (§1-§3 and the migration hazards). The official plugin writes through WooCommerce CRUD,
 * so this seed does too: with HPOS authoritative and compatibility sync off, every value
 * lands in the order tables only (hazard 4, plan Review Focus 1).
 *
 * Per ref:
 * - every ref: the provider fields `UPayments_*` on orders in every status, a multi-valued
 *   `UPayments_WHS` (hazard 3), a pending order with an open payment link (the webhook
 *   target), the gateway settings under `woocommerce_upayments_settings` (2.x keys, plus
 *   the 3.0.0 / 3.1.1 additions where the ref has them) and the WooCommerce gateway order
 *   and default gateway that reference `upayments`;
 * - a 3.1.1-shaped subscription parent (token, card token, plan, last billed 40 days ago)
 *   with two renewals on the same billing date (hazard 6). The plan seeds it in every cell
 *   so every cell proves a pre-switch parent renews through SUPCheckout; on a 2.x or 3.0.0
 *   ref it models a store that ran 3.1.1 before going back to that release;
 * - optionally the WCML gateway restriction shapes (see supcheckout_matrix_seed_wcml()).
 *
 * The real 3.0.0 activation hook writes its own placeholder `woocommerce_your_gateway_id_settings`
 * (hazard 3); this seed never touches that option, so the cell shows what the real
 * activation left.
 */

defined('ABSPATH') || exit;

const SUPCHECKOUT_MATRIX_MANIFEST = 'supcheckout_matrix_manifest';
const SUPCHECKOUT_MATRIX_API_KEY = 'matrix-certification-key-not-a-secret';
const SUPCHECKOUT_MATRIX_CUSTOMER_TOKEN = '96512345678';
const SUPCHECKOUT_MATRIX_CARD_TOKEN = 'matrix-card-token-1';
/** Seeded orders carrying the legacy keys: more than two copy batches of 50. */
const SUPCHECKOUT_MATRIX_ORDER_TARGET = 120;

/**
 * Gateway settings as the given official ref defines them (inventory §1).
 *
 * @param string $version Official plugin version (2.0.5, 2.2.1, 3.0.0, 3.1.1).
 * @return array<string, string>
 */
function supcheckout_matrix_official_settings($version) {
    $settings = array(
        'enabled' => 'yes',
        'title' => 'UPayments',
        'description' => 'Pay with UPayments',
        'api_key' => SUPCHECKOUT_MATRIX_API_KEY,
        'debug' => 'no',
        'test_mode' => 'yes',
        'is_order_complete' => 'no',
    );
    if (version_compare($version, '3.0.0', '>=')) {
        $settings += array(
            'save_card_section_title' => '',
            'use_new_design' => 'yes',
            'enable_save_card' => 'yes',
            'multimerchant_section_title' => '',
            'enable_multimerchant' => 'no',
            'iban_number' => '',
            'cc_charge' => '',
            'cc_charge_type' => 'fixed',
            'knet_charge' => '',
            'knet_charge_type' => 'fixed',
            'multimerchant_accounts' => '[]',
        );
    }
    if (version_compare($version, '3.1.1', '>=')) {
        $settings += array(
            'make_default_gateway' => 'no',
            'autodeduction_section_title' => '',
        );
    }
    // Every cell renews a pre-switch subscription parent through SUPCheckout after the
    // cutover, which needs the subscription and save-card toggles in the copied settings.
    $settings['enable_save_card'] = 'yes';
    $settings['enable_subscriptions'] = 'yes';
    return $settings;
}

/**
 * The provider fields the official plugin writes on a paid order (inventory §2).
 *
 * @param int    $n       Fixture number.
 * @param string $version Official plugin version.
 * @return array<string, string>
 */
function supcheckout_matrix_paid_meta($n, $version) {
    $meta = array(
        'UPayments_order_id' => md5('matrix-order-' . $n),
        'UPayments_Result' => 'CAPTURED',
        'UPayments_PaymentID' => 'matrix-pay-' . $n,
        'UPayments_TrackID' => 'matrix-track-' . $n,
        'UPayments_payment_type' => 'knet',
        'UPayments_PostDate' => '1016',
        'UPayments_TranID' => 'matrix-tran-' . $n,
        'UPayments_Ref' => 'matrix-ref-' . $n,
        'UPayments_Auth' => 'matrix-auth-' . $n,
        'UPayments_webhook_triggered' => '1',
        'UPayments_reference' => 'matrix-reference-' . $n,
        'UPayments_Checkout_Selected' => 'knet',
    );
    if (version_compare($version, '3.1.1', '>=')) {
        $meta['_upay_customer_unique_token'] = SUPCHECKOUT_MATRIX_CUSTOMER_TOKEN;
    }
    return $meta;
}

/**
 * @param array<string, mixed> $args wc_create_order() arguments.
 * @param string               $status Order status.
 * @param array<string, mixed> $meta   Meta, a list value adds one row per item.
 * @param array<int, WC_Product> $products Line items.
 * @return WC_Order
 */
function supcheckout_matrix_order(array $args, $status, array $meta, array $products) {
    $order = wc_create_order($args);
    if (!$order instanceof WC_Order) {
        throw new RuntimeException('FAIL: matrix seed could not create an order');
    }
    foreach ($products as $product) {
        $order->add_product($product, 1);
    }
    $order->set_payment_method('upayments');
    $order->set_payment_method_title('UPayments');
    $order->set_currency('KWD');
    $order->set_billing_first_name('Matrix');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('matrix-customer@example.invalid');
    $order->set_billing_phone('96512345678');
    $order->set_billing_country('KW');
    $order->calculate_totals(false);
    foreach ($meta as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $row) {
                $order->add_meta_data($key, $row);
            }
        } else {
            $order->update_meta_data($key, $value);
        }
    }
    if (isset($meta['UPayments_PaymentID']) && is_string($meta['UPayments_PaymentID']) && in_array($status, array('processing', 'completed', 'refunded'), true)) {
        $order->set_transaction_id($meta['UPayments_PaymentID']);
        $order->set_date_paid(time() - DAY_IN_SECONDS);
    }
    $order->set_status($status);
    $order->save();
    $fresh = wc_get_order($order->get_id());
    return $fresh instanceof WC_Order ? $fresh : $order;
}

/**
 * The WCML gateway-restriction shapes. WPML/WCML are paid and licensed, so they are never
 * installed in CI (AGENTS.md); the real plugin is exercised by the owner's staging
 * rehearsal, whose store runs WPML/WCML. These are the two option shapes WCML keeps for a
 * gateway id:
 * - `wcml_payment_gateway_<gateway id>`: the "show this gateway by customer location"
 *   rule (WCML_Payment_Gateway::OPTION_KEY . id): array{mode, countries};
 * - `_wcml_settings['currency_options'][<currency>]['gateways_settings'][<gateway id>]` and
 *   `gateways_enabled`: the per-currency gateway settings.
 * Both name the gateway `upayments`. The takeover's contract for them (ThirdPartyTranslator,
 * Preflight third_party_references) is report-only until a real install evidences them:
 * they are reported by name and never written, journaled or restored.
 *
 * @return list<string> The option names seeded.
 */
function supcheckout_matrix_seed_wcml() {
    update_option('wcml_payment_gateway_upayments', array('mode' => 'include', 'countries' => array('KW', 'SA', 'BH')), false);
    update_option('_wcml_settings', array(
        'enable_multi_currency' => 2,
        'currency_options' => array(
            'KWD' => array('rate' => 1, 'gateways_enabled' => 1, 'gateways_settings' => array(
                'upayments' => array('currency' => 'KWD', 'value' => ''),
                'bacs' => array('currency' => 'KWD', 'value' => ''),
            )),
            'SAR' => array('rate' => 12.2, 'gateways_enabled' => 1, 'gateways_settings' => array(
                'upayments' => array('currency' => 'KWD', 'value' => ''),
            )),
        ),
    ), false);
    return array('_wcml_settings', 'wcml_payment_gateway_upayments');
}

/**
 * @param string $version Official plugin version.
 * @param bool   $wcml    Seed the WCML gateway-restriction shapes.
 * @return array<string, mixed> The manifest (order ids by role), also stored as an option.
 */
function supcheckout_matrix_seed_official($version, $wcml) {
    update_option('woocommerce_currency', 'KWD');
    update_option('woocommerce_upayments_settings', supcheckout_matrix_official_settings($version));
    update_option('woocommerce_gateway_order', array('bacs' => 0, 'upayments' => 1, 'cod' => 2));
    update_option('woocommerce_default_gateway', 'upayments');

    $user_id = wp_insert_user(array(
        'user_login' => 'matrix-customer',
        'user_pass' => wp_generate_password(24, true, true),
        'user_email' => 'matrix-customer@example.invalid',
        'role' => 'customer',
    ));
    if (is_wp_error($user_id)) {
        throw new RuntimeException('FAIL: matrix customer: ' . $user_id->get_error_message());
    }
    $user_id = (int) $user_id;

    $simple = new WC_Product_Simple();
    $simple->set_name('Matrix product');
    $simple->set_regular_price('5.000');
    $simple->set_price('5.000');
    $simple->save();

    // The subscription product type `custom_type` is a product_type term shared by official
    // 3.1.1 and SUPCheckout (Keys::unchanged_persisted()); while the official plugin is
    // active SUPCheckout's class is not loaded, so the term is set directly.
    $subscription = new WC_Product_Simple();
    $subscription->set_name('Matrix subscription');
    $subscription->set_regular_price('10.000');
    $subscription->set_price('10.000');
    $subscription_id = $subscription->save();
    wp_set_object_terms($subscription_id, 'custom_type', 'product_type');
    update_post_meta($subscription_id, '_custom_field_id', 'matrix-feature');
    wc_delete_product_transients($subscription_id);

    $manifest = array('version' => $version, 'user_id' => $user_id, 'orders' => array(), 'roles' => array(), 'wcml' => array());
    $n = 0;
    foreach (array('processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed') as $status) {
        ++$n;
        $meta = supcheckout_matrix_paid_meta($n, $version);
        if ($status === 'failed' || $status === 'cancelled') {
            $meta['UPayments_Result'] = $status === 'failed' ? 'NOT CAPTURED' : 'CANCELED';
        }
        $order = supcheckout_matrix_order(array('customer_id' => $user_id), $status, $meta, array($simple));
        $manifest['roles']['status_' . $status] = $order->get_id();
    }

    ++$n;
    $whs = supcheckout_matrix_paid_meta($n, $version) + array('UPayments_WHS' => array('failed', 'completed'));
    $manifest['roles']['whs_multi'] = supcheckout_matrix_order(array('customer_id' => $user_id), 'processing', $whs, array($simple))->get_id();

    // An unpaid order whose payment link is still open: only the Charge identity exists.
    $pending = supcheckout_matrix_order(array('customer_id' => $user_id), 'pending', array('UPayments_order_id' => md5('matrix-open-link')), array($simple, $simple, $simple));
    $manifest['roles']['pending_open_link'] = $pending->get_id();

    // 3.1.1 subscription parent, billed 40 days ago, monthly: due now.
    $parent_meta = array(
        'UPayments_order_id' => md5('matrix-parent'),
        'UPayments_Result' => 'CAPTURED',
        'UPayments_PaymentID' => 'matrix-pay-parent',
        'UPayments_TrackID' => 'matrix-track-parent',
        'UPayments_Ref' => 'matrix-ref-parent',
        'UPayments_AutoDeduction' => 'no',
        '_upay_customer_unique_token' => SUPCHECKOUT_MATRIX_CUSTOMER_TOKEN,
        '_upay_credit_card_token' => SUPCHECKOUT_MATRIX_CARD_TOKEN,
        '_upay_subscription_plan' => 'monthly',
        '_upay_subscription_interval' => '1',
        '_upay_subscription_status' => 'active',
        '_upay_last_billed_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS),
    );
    $parent = supcheckout_matrix_order(array('customer_id' => $user_id), 'processing', $parent_meta, array(wc_get_product($subscription_id)));
    $manifest['roles']['subscription_parent'] = $parent->get_id();

    // Two renewals on the same billing date (hazard 6: the official renewal lookup never matched).
    foreach (array('a', 'b') as $suffix) {
        $renewal_meta = array(
            'UPayments_order_id' => md5('matrix-renewal-' . $suffix),
            'UPayments_AutoDeduction' => 'yes',
            'UPayments_ParentOrderID' => (string) $parent->get_id(),
            'UPayments_InvoiceID' => 'matrix-invoice-' . $suffix,
            'UPayments_TransactionDate' => gmdate('Y-m-d', time() - 40 * DAY_IN_SECONDS),
            'UPayments_GatewayStatus' => 'CAPTURED',
            'UPayments_PaymentID' => 'matrix-pay-renewal-' . $suffix,
            '_upay_customer_unique_token' => SUPCHECKOUT_MATRIX_CUSTOMER_TOKEN,
        );
        $renewal = supcheckout_matrix_order(array('customer_id' => $user_id), 'processing', $renewal_meta, array(wc_get_product($subscription_id)));
        $manifest['roles']['renewal_' . $suffix] = $renewal->get_id();
    }

    // Filler history up to the target, so the copy runs more than two batches of 50.
    $seeded = count($manifest['roles']);
    for ($i = $seeded; $i < SUPCHECKOUT_MATRIX_ORDER_TARGET; $i++) {
        ++$n;
        supcheckout_matrix_order(array('customer_id' => $user_id), $i % 2 === 0 ? 'completed' : 'processing', supcheckout_matrix_paid_meta($n, $version), array($simple));
    }

    if ($wcml) {
        $manifest['wcml'] = supcheckout_matrix_seed_wcml();
    }

    $manifest['orders'] = wc_get_orders(array('limit' => -1, 'return' => 'ids', 'payment_method' => 'upayments', 'orderby' => 'ID', 'order' => 'ASC'));
    update_option(SUPCHECKOUT_MATRIX_MANIFEST, $manifest, false);
    return $manifest;
}

/**
 * The large-store cell: $count paid orders with the provider fields, created in bulk.
 *
 * @param int $count Orders.
 * @return array<string, mixed> Manifest.
 */
function supcheckout_matrix_seed_large($count) {
    update_option('woocommerce_currency', 'KWD');
    update_option('woocommerce_upayments_settings', supcheckout_matrix_official_settings('3.1.1'));
    wp_defer_term_counting(true);
    wp_suspend_cache_addition(true);
    for ($i = 1; $i <= $count; $i++) {
        $order = new WC_Order();
        $order->set_payment_method('upayments');
        $order->set_currency('KWD');
        $order->set_total('10.000');
        $order->set_status($i % 3 === 0 ? 'completed' : 'processing');
        foreach (supcheckout_matrix_paid_meta($i, '3.1.1') as $key => $value) {
            $order->update_meta_data($key, $value);
        }
        $order->save();
        if ($i % 1000 === 0) {
            wp_cache_flush();
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::log('CERT: seeded ' . $i . ' orders');
            }
        }
    }
    wp_defer_term_counting(false);
    wp_suspend_cache_addition(false);
    $manifest = array('version' => '3.1.1', 'orders_count' => $count, 'roles' => array(), 'wcml' => array());
    update_option(SUPCHECKOUT_MATRIX_MANIFEST, $manifest, false);
    return $manifest;
}
