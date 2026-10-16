<?php
/**
 * Real-runtime activation/deactivation/uninstall/boot-boundary certification.
 */

require_once __DIR__ . '/bootstrap.php';

use UPayments\Token\CustomerTokenIdentity;

$phase = getenv('SUPCHECKOUT_CERT_PHASE');
$settings_key = 'woocommerce_upayments_settings';
$snapshot_key = '_supcheckout_feature_ops_snapshot';
$order_key = '_supcheckout_feature_ops_order_id';
$identity_snapshot_key = '_supcheckout_feature_ops_identity_snapshot';
$secret = 'ops-certification-secret-sentinel';

function supcheckout_cert_ops_verify_persistence(
    $settings_key,
    $snapshot_key,
    $order_key,
    $identity_snapshot_key,
    $secret
) {
    $snapshot = get_option($snapshot_key);
    $settings = get_option($settings_key);
    supcheckout_cert_assert(is_string($snapshot) && '' !== $snapshot, 'operations settings snapshot exists');
    supcheckout_cert_assert(hash_equals($snapshot, maybe_serialize($settings)), 'merchant gateway settings survive lifecycle operation byte-for-byte');

    $order_id = (int) get_option($order_key);
    $order = wc_get_order($order_id);
    supcheckout_cert_assert($order instanceof WC_Order, 'payment order survives lifecycle operation');
    supcheckout_cert_assert('upayments' === $order->get_payment_method(), 'payment-method identity survives lifecycle operation');
    supcheckout_cert_assert('ops-provider-order' === $order->get_meta('UPayments_order_id'), 'provider order identity survives lifecycle operation');
    supcheckout_cert_assert('ops-customer-token' === $order->get_meta('_upay_customer_unique_token'), 'historical token metadata survives lifecycle operation');

    $identity_snapshot = get_option($identity_snapshot_key);
    supcheckout_cert_assert(is_array($identity_snapshot), 'canonical identity lifecycle snapshot exists');
    supcheckout_cert_assert(
        isset(
            $identity_snapshot['user_id'],
            $identity_snapshot['meta_key'],
            $identity_snapshot['secret_record'],
            $identity_snapshot['provenance_record'],
            $identity_snapshot['token'],
            $identity_snapshot['scope'],
            $identity_snapshot['generation_id']
        ),
        'canonical identity lifecycle snapshot is structurally complete'
    );

    if (is_array($identity_snapshot)
        && isset($identity_snapshot['user_id'], $identity_snapshot['meta_key'])
    ) {
        $identity_user_id = (int) $identity_snapshot['user_id'];
        $identity_meta_key = (string) $identity_snapshot['meta_key'];

        $identity_user = get_userdata($identity_user_id);
        supcheckout_cert_assert($identity_user instanceof WP_User, 'canonical identity user survives lifecycle operation');

        $secret_record = get_option('upayments_token_identity_secret_v2', null);
        supcheckout_cert_assert(
            isset($identity_snapshot['secret_record'])
                && is_string($identity_snapshot['secret_record'])
                && hash_equals($identity_snapshot['secret_record'], maybe_serialize($secret_record)),
            'canonical token identity secret survives lifecycle operation byte-for-byte'
        );

        $provenance = get_user_meta($identity_user_id, $identity_meta_key, true);
        supcheckout_cert_assert(
            isset($identity_snapshot['provenance_record'])
                && is_string($identity_snapshot['provenance_record'])
                && hash_equals($identity_snapshot['provenance_record'], maybe_serialize($provenance)),
            'canonical user provenance survives lifecycle operation byte-for-byte'
        );

        supcheckout_cert_assert(
            is_array($provenance)
                && isset($provenance['token'], $provenance['scope'], $provenance['secret_generation_id'])
                && $provenance['token'] === $identity_snapshot['token']
                && $provenance['scope'] === $identity_snapshot['scope']
                && $provenance['secret_generation_id'] === $identity_snapshot['generation_id'],
            'canonical token, scope and generation remain bound after lifecycle operation'
        );
    }

    $serialized = maybe_serialize($settings);
    supcheckout_cert_assert(false !== strpos($serialized, $secret), 'credential sentinel remains stored rather than silently erased');
}

if ('seed' === $phase) {
    delete_option(CustomerTokenIdentity::SECRET_OPTION);

    $settings = array(
        'enabled' => 'yes',
        'api_key' => $secret,
        'test_mode' => 'yes',
        'enable_save_card' => 'yes',
        'enable_subscriptions' => 'no',
    );
    // The activation fixture intentionally leaves a malformed object-valued
    // gateway option in place. Older WooCommerce versions cannot run their
    // payment-gateway option-change observer against that malformed *old* value,
    // so transition this certification fixture through the raw persistence seam
    // used by PluginActivationTest rather than invoking unrelated Woo observers.
    // The lifecycle assertions below still exercise the real stored option.
    supcheckout_cert_store_option_raw($settings_key, $settings);
    update_option($snapshot_key, maybe_serialize($settings), false);

    $identity_user_id = wp_insert_user(array(
        'user_login' => 'supcheckout-cert-ops-' . wp_generate_password(12, false, false),
        'user_pass'  => wp_generate_password(24, true, true),
        'user_email' => 'ops-' . wp_generate_password(8, false, false) . '@example.invalid',
    ));
    supcheckout_cert_assert(
        !is_wp_error($identity_user_id) && (int) $identity_user_id > 0,
        'canonical lifecycle identity user is created'
    );
    $identity_user_id = (int) $identity_user_id;

    $identity = CustomerTokenIdentity::get_or_establish_token(
        $identity_user_id,
        $secret,
        true,
        function ($candidate) {
            return array(
                'transport_ok' => true,
                'http_status'  => 201,
                'curl_errno'   => 0,
                'body'         => wp_json_encode(array(
                    'status' => true,
                    'data'   => array('customerUniqueToken' => $candidate),
                )),
            );
        }
    );
    supcheckout_cert_assert(true === $identity['success'], 'canonical lifecycle token identity is established');
    supcheckout_cert_assert(true === $identity['established'], 'canonical lifecycle identity is newly persisted');

    $identity_meta_key = CustomerTokenIdentity::get_user_meta_key(
        (string) get_current_blog_id(),
        $identity['scope']
    );
    supcheckout_cert_assert(
        is_string($identity_meta_key) && '' !== $identity_meta_key,
        'canonical lifecycle provenance key is derived'
    );

    $secret_record = get_option(CustomerTokenIdentity::SECRET_OPTION, null);
    $provenance_record = get_user_meta($identity_user_id, $identity_meta_key, true);
    supcheckout_cert_assert(is_array($secret_record), 'canonical lifecycle secret record is persisted');
    supcheckout_cert_assert(is_array($provenance_record), 'canonical lifecycle provenance record is persisted');

    update_option(
        $identity_snapshot_key,
        array(
            'user_id' => $identity_user_id,
            'meta_key' => $identity_meta_key,
            'secret_record' => maybe_serialize($secret_record),
            'provenance_record' => maybe_serialize($provenance_record),
            'token' => $identity['token'],
            'scope' => $identity['scope'],
            'generation_id' => $identity['secret_generation_id'],
        ),
        false
    );

    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'operations certification order is created');
    $order->set_payment_method('upayments');
    $order->update_meta_data('UPayments_order_id', 'ops-provider-order');
    $order->update_meta_data('_upay_customer_unique_token', 'ops-customer-token');
    $order->update_meta_data('_upay_subscription_status', 'paused');
    $order->save();
    update_option($order_key, $order->get_id(), false);

    supcheckout_cert_assert(
        class_exists('Simplixi\\SUPCheckout\\Migration\\MigrationCliCommand', false),
        'migration CLI module boots in WP-CLI context'
    );
    supcheckout_cert_assert(
        !class_exists('Simplixi\\SUPCheckout\\Migration\\MigrationAdmin', false),
        'migration admin module does not boot in non-admin WP-CLI context'
    );

    ob_start();
    set_current_screen('dashboard');
    \Simplixi\SUPCheckout\Migration\MigrationBootstrap::boot();
    $boot_output = ob_get_clean();
    supcheckout_cert_assert(
        class_exists('Simplixi\\SUPCheckout\\Migration\\MigrationAdmin', false),
        'migration admin module boots only after an explicit admin context exists'
    );
    supcheckout_cert_assert(
        false === strpos((string) $boot_output, $secret),
        'migration bootstrap emits no merchant credential material'
    );

    supcheckout_cert_ops_verify_persistence(
        $settings_key,
        $snapshot_key,
        $order_key,
        $identity_snapshot_key,
        $secret
    );
    supcheckout_cert_note('operations seed and context-bound migration boot certification complete');
    return;
}

if (in_array($phase, array('deactivated', 'reactivated', 'uninstalled', 'final'), true)) {
    supcheckout_cert_ops_verify_persistence(
        $settings_key,
        $snapshot_key,
        $order_key,
        $identity_snapshot_key,
        $secret
    );

    if ('deactivated' === $phase || 'uninstalled' === $phase) {
        supcheckout_cert_assert(!class_exists('WC_Upayments'), 'SUPCheckout runtime class is absent while plugin is inactive');
    } else {
        supcheckout_cert_assert(class_exists('WC_Upayments'), 'SUPCheckout runtime class is present after reactivation');
    }

    if ('final' === $phase) {
        $order_id = (int) get_option($order_key);
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }

        $identity_snapshot = get_option($identity_snapshot_key);
        if (is_array($identity_snapshot) && isset($identity_snapshot['user_id'])) {
            wp_delete_user((int) $identity_snapshot['user_id']);
        }

        delete_option('upayments_token_identity_secret_v2');
        delete_option($identity_snapshot_key);
        delete_option($snapshot_key);
        delete_option($order_key);
        update_option($settings_key, array(
            'enabled' => 'yes',
            'enable_block_checkout' => 'no',
        ), false);
    }

    supcheckout_cert_note('operations lifecycle phase complete: ' . $phase);
    return;
}

throw new RuntimeException('Unknown SUPCHECKOUT_CERT_PHASE for operations certification.');
