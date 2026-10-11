<?php
/**
 * Sanitization of a staging copy of a production WooCommerce store.
 *
 * Removes customer personal data, stored payment/customer tokens and copied gateway
 * secrets, and stops jobs that act on customers. Keeps order IDs, totals, currencies,
 * statuses, payment methods and UPayments order metadata, so upgrade and payment tests
 * on the copy stay meaningful. Loaded by tests/staging/sanitize-staging.php and by the
 * CI integration test; it never runs on its own.
 */

defined('ABSPATH') || exit;

const SUPCHECKOUT_SANITIZER_ORDER_BATCH = 100;

/** Tables whose rows are personal data or customer-facing activity, never configuration. */
const SUPCHECKOUT_SANITIZER_DATA_TABLES = array(
    'woocommerce_sessions',
    'woocommerce_payment_tokens',
    'woocommerce_payment_tokenmeta',
    'wc_rate_limits',
    'wc_download_log',
    'bwfan_abandonedcarts',
    'bwf_contact',
    'bwf_contact_meta',
    'bwf_contact_fields',
    'bwf_wc_customers',
    'bwfan_automation_contact',
    'bwfan_automation_contact_trail',
    'bwfan_automation_complete_contact',
    'bwfan_automation_contact_claim',
    'bwfan_engagement_tracking',
    'bwfan_engagement_trackingmeta',
    'bwfan_conversions',
    'bwf_conversion_tracking',
    'bwfan_message_unsubscribe',
    'bwfan_contact_note',
    'bwf_optin_entries',
    'bwfan_wcs_subscriber',
    'wfocu_session',
    'asenha_email_delivery',
    'asenha_failed_logins',
    'transmail_failed_emails',
    'bitforms_form_entries',
    'bitforms_form_entrymeta',
    'bitforms_form_entry_log',
    'bitforms_form_entry_relatedinfo',
    'litespeed_avatar',
    'rank_math_404_logs',
);

/** Provider-side customer/card tokens kept on orders and users. */
const SUPCHECKOUT_SANITIZER_TOKEN_META = array(
    '_upay_customer_unique_token',
    '_upay_credit_card_token',
);

/** Gateway settings fields that hold secrets or bank identifiers. */
const SUPCHECKOUT_SANITIZER_SECRET_SETTINGS = array('api_key', 'iban_number', 'multimerchant_accounts');

const SUPCHECKOUT_SANITIZER_PERSONAL_USER_META = array(
    'first_name', 'last_name', 'nickname', 'description',
    'billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2',
    'billing_city', 'billing_postcode', 'billing_phone', 'billing_email',
    'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2',
    'shipping_city', 'shipping_postcode', 'shipping_phone',
    'session_tokens',
);

const SUPCHECKOUT_SANITIZER_LEGACY_ORDER_META = array(
    '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1', '_billing_address_2',
    '_billing_city', '_billing_postcode', '_billing_email', '_billing_phone',
    '_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1', '_shipping_address_2',
    '_shipping_city', '_shipping_postcode', '_shipping_phone',
    '_customer_ip_address', '_customer_user_agent', '_billing_address_index', '_shipping_address_index',
);

/**
 * Refuse unless this is an opted-in staging copy on a different host from production.
 *
 * @return array{ok: bool, reason: string}
 */
function supcheckout_sanitizer_preflight($env_type, $guard_defined, $home_host, $production_host) {
    $home_host = strtolower(trim((string) $home_host));
    $production_host = strtolower(trim((string) $production_host));
    if ('staging' !== $env_type) {
        return array('ok' => false, 'reason' => 'WP_ENVIRONMENT_TYPE must be staging (got ' . $env_type . ')');
    }
    if (true !== $guard_defined) {
        return array('ok' => false, 'reason' => 'SUPCHECKOUT_STAGING_GUARD must be defined as true in wp-config.php');
    }
    if ('' === $production_host) {
        return array('ok' => false, 'reason' => 'set SUPCHECKOUT_PRODUCTION_HOST to the production domain');
    }
    if ('' === $home_host || $home_host === $production_host
        || substr($production_host, -strlen('.' . $home_host)) === '.' . $home_host
    ) {
        return array('ok' => false, 'reason' => 'this site resolves to the production host ' . $home_host);
    }
    return array('ok' => true, 'reason' => 'staging copy at ' . $home_host);
}

function supcheckout_sanitizer_anon_email($kind, $id) {
    return $kind . '-' . (int) $id . '@example.invalid';
}

function supcheckout_sanitizer_is_anon_email($email) {
    $email = (string) $email;
    return '' === $email || '@example.invalid' === substr($email, -strlen('@example.invalid'));
}

function supcheckout_sanitizer_hpos_authoritative() {
    return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

function supcheckout_sanitizer_table_exists($table) {
    global $wpdb;
    return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
}

/**
 * @param bool $apply False reports what would change; true changes it.
 * @return array<string, int|string> Per-step counts.
 */
function supcheckout_sanitizer_run($apply) {
    // Changing a user's email must not notify that (real) address.
    add_filter('send_email_change_email', '__return_false', PHP_INT_MAX);
    add_filter('send_password_change_email', '__return_false', PHP_INT_MAX);

    $report = array();
    $report['gateway_secret_settings'] = supcheckout_sanitizer_gateway_settings($apply);
    $report['token_identity_secret'] = supcheckout_sanitizer_option_delete('upayments_token_identity_secret_v2', $apply);
    $report['orders_anonymized'] = supcheckout_sanitizer_orders($apply);
    $report['legacy_order_meta_rows'] = supcheckout_sanitizer_legacy_order_meta($apply);
    $report['users_anonymized'] = supcheckout_sanitizer_users($apply);
    $report['customer_lookup_rows'] = supcheckout_sanitizer_customer_lookup($apply);
    $report['comment_authors'] = supcheckout_sanitizer_comments($apply);
    $report['token_meta_rows'] = supcheckout_sanitizer_token_meta($apply);
    $report['data_table_rows'] = supcheckout_sanitizer_data_tables($apply);
    // Last: saving orders and users above queues background jobs of its own.
    $report['upayments_cron_hooks'] = supcheckout_sanitizer_cron($apply);
    $report['pending_scheduled_actions'] = supcheckout_sanitizer_scheduled_actions($apply);
    $report['woocommerce_webhooks'] = supcheckout_sanitizer_webhooks($apply);
    $report['woocommerce_rest_api_keys'] = supcheckout_sanitizer_truncate_rows('woocommerce_api_keys', $apply);
    return $report;
}

function supcheckout_sanitizer_gateway_settings($apply) {
    $settings = get_option('woocommerce_upayments_settings');
    if (!is_array($settings)) {
        return 0;
    }
    $changed = 0;
    foreach (SUPCHECKOUT_SANITIZER_SECRET_SETTINGS as $key) {
        if (isset($settings[$key]) && '' !== $settings[$key]) {
            $settings[$key] = '';
            $changed++;
        }
    }
    if (isset($settings['enable_multimerchant']) && 'no' !== $settings['enable_multimerchant']) {
        $settings['enable_multimerchant'] = 'no';
        $changed++;
    }
    if (!isset($settings['test_mode']) || 'yes' !== $settings['test_mode']) {
        $settings['test_mode'] = 'yes';
        $changed++;
    }
    if ($apply && $changed > 0) {
        update_option('woocommerce_upayments_settings', $settings);
    }
    return $changed;
}

function supcheckout_sanitizer_option_delete($name, $apply) {
    if (false === get_option($name, false)) {
        return 0;
    }
    if ($apply) {
        delete_option($name);
    }
    return 1;
}

function supcheckout_sanitizer_cron($apply) {
    $cron = _get_cron_array();
    $hooks = array();
    if (is_array($cron)) {
        foreach ($cron as $events) {
            foreach (array_keys((array) $events) as $hook) {
                if (0 === strpos($hook, 'upay') || 0 === strpos($hook, 'simplixpay_upayments_')) {
                    $hooks[$hook] = true;
                }
            }
        }
    }
    if ($apply) {
        foreach (array_keys($hooks) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
    return count($hooks);
}

function supcheckout_sanitizer_scheduled_actions($apply) {
    global $wpdb;
    $table = $wpdb->prefix . 'actionscheduler_actions';
    if (!supcheckout_sanitizer_table_exists($table)) {
        return 0;
    }
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status IN ('pending','in-progress')", $table));
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'canceled' WHERE status IN ('pending','in-progress')", $table));
    }
    return $count;
}

function supcheckout_sanitizer_webhooks($apply) {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_webhooks';
    if (!supcheckout_sanitizer_table_exists($table)) {
        return 0;
    }
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status <> 'disabled'", $table));
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare("UPDATE %i SET status = 'disabled'", $table));
    }
    return $count;
}

function supcheckout_sanitizer_truncate_rows($suffix, $apply) {
    global $wpdb;
    $table = $wpdb->prefix . $suffix;
    if (!supcheckout_sanitizer_table_exists($table)) {
        return 0;
    }
    $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
    }
    return $count;
}

function supcheckout_sanitizer_data_tables($apply) {
    $total = 0;
    foreach (SUPCHECKOUT_SANITIZER_DATA_TABLES as $suffix) {
        $total += supcheckout_sanitizer_truncate_rows($suffix, $apply);
    }
    return $total;
}

function supcheckout_sanitizer_token_meta($apply) {
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count(SUPCHECKOUT_SANITIZER_TOKEN_META), '%s'));
    $targets = array($wpdb->usermeta, $wpdb->postmeta);
    $orders_meta = $wpdb->prefix . 'wc_orders_meta';
    if (supcheckout_sanitizer_table_exists($orders_meta)) {
        $targets[] = $orders_meta;
    }
    $total = 0;
    foreach ($targets as $table) {
        $args = array_merge(array($table), SUPCHECKOUT_SANITIZER_TOKEN_META);
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders}/{$keys} is a generated %s list for IN (); every value is bound below.
        $total += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE meta_key IN ({$placeholders})", $args));
        if ($apply) {
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders}/{$keys} is a generated %s list for IN (); every value is bound below.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE meta_key IN ({$placeholders})", $args));
        }
    }
    if ($apply) {
        wp_cache_flush();
    }
    return $total;
}

/**
 * Anonymize every order through WooCommerce CRUD so the authoritative store (HPOS or
 * legacy) and its caches stay consistent.
 */
function supcheckout_sanitizer_orders($apply) {
    $total = 0;
    $page = 1;
    do {
        $ids = wc_get_orders(array(
            'type' => 'shop_order',
            'status' => 'any',
            'limit' => SUPCHECKOUT_SANITIZER_ORDER_BATCH,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ));
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order || !is_callable(array($order, 'set_billing_email'))) {
                continue;
            }
            if (supcheckout_sanitizer_is_anon_email($order->get_billing_email())
                && '' === (string) $order->get_customer_ip_address()
                && 'Customer' === $order->get_billing_first_name()
            ) {
                continue;
            }
            $total++;
            if (!$apply) {
                continue;
            }
            $order->set_billing_first_name('Customer');
            $order->set_billing_last_name((string) $id);
            $order->set_billing_company('');
            $order->set_billing_address_1('Anonymized');
            $order->set_billing_address_2('');
            $order->set_billing_city('Anonymized');
            $order->set_billing_postcode('');
            $order->set_billing_email(supcheckout_sanitizer_anon_email('customer', $id));
            $order->set_billing_phone('');
            $order->set_shipping_first_name('Customer');
            $order->set_shipping_last_name((string) $id);
            $order->set_shipping_company('');
            $order->set_shipping_address_1('Anonymized');
            $order->set_shipping_address_2('');
            $order->set_shipping_city('Anonymized');
            $order->set_shipping_postcode('');
            if (is_callable(array($order, 'set_shipping_phone'))) {
                $order->set_shipping_phone('');
            }
            $order->set_customer_ip_address('');
            $order->set_customer_user_agent('');
            $order->set_customer_note('');
            $order->save();
        }
        $page++;
    } while (count($ids) === SUPCHECKOUT_SANITIZER_ORDER_BATCH);
    return $total;
}

/**
 * With HPOS authoritative and sync off, pre-migration copies of address meta can remain
 * on the legacy placeholder posts. Blank them.
 */
function supcheckout_sanitizer_legacy_order_meta($apply) {
    global $wpdb;
    if (!supcheckout_sanitizer_hpos_authoritative()) {
        // Legacy store: postmeta is authoritative and the CRUD step anonymizes it.
        return 0;
    }
    $keys = implode(',', array_fill(0, count(SUPCHECKOUT_SANITIZER_LEGACY_ORDER_META), '%s'));
    $where = "pm.meta_key IN ({$keys}) AND pm.meta_value <> '' AND p.post_type IN ('shop_order','shop_order_placehold','shop_order_refund')";
    $args = array_merge(array($wpdb->postmeta, $wpdb->posts), SUPCHECKOUT_SANITIZER_LEGACY_ORDER_META);
    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders}/{$keys} is a generated %s list for IN (); every value is bound below.
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i pm JOIN %i p ON p.ID = pm.post_id WHERE {$where}", $args));
    if ($apply && $count > 0) {
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders}/{$keys} is a generated %s list for IN (); every value is bound below.
        $wpdb->query($wpdb->prepare("UPDATE %i pm JOIN %i p ON p.ID = pm.post_id SET pm.meta_value = '' WHERE {$where}", $args));
    }
    return $count;
}

/**
 * Anonymize every account that cannot manage the store; store managers keep their login.
 */
function supcheckout_sanitizer_users($apply) {
    $total = 0;
    foreach (get_users(array('fields' => array('ID', 'user_email'))) as $user) {
        $id = (int) $user->ID;
        if (user_can($id, 'manage_woocommerce') || user_can($id, 'manage_options')) {
            continue;
        }
        if (supcheckout_sanitizer_is_anon_email($user->user_email)
            && supcheckout_sanitizer_is_anon_email(get_user_meta($id, 'billing_email', true))
            && '' === (string) get_user_meta($id, 'billing_phone', true)
        ) {
            continue;
        }
        $total++;
        if (!$apply) {
            continue;
        }
        wp_update_user(array(
            'ID' => $id,
            'user_email' => supcheckout_sanitizer_anon_email('user', $id),
            'display_name' => 'User ' . $id,
            'user_url' => '',
        ));
        foreach (SUPCHECKOUT_SANITIZER_PERSONAL_USER_META as $key) {
            delete_user_meta($id, $key);
        }
        update_user_meta($id, 'billing_email', supcheckout_sanitizer_anon_email('user', $id));
    }
    return $total;
}

function supcheckout_sanitizer_customer_lookup($apply) {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_customer_lookup';
    if (!supcheckout_sanitizer_table_exists($table)) {
        return 0;
    }
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE email NOT LIKE '%%@example.invalid'", $table));
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET first_name = 'Customer', last_name = CAST(customer_id AS CHAR), "
            . "email = CONCAT('customer-lookup-', customer_id, '@example.invalid'), "
            . "username = CONCAT('customer-', customer_id), city = 'Anonymized', postcode = ''",
            $table
        ));
    }
    return $count;
}

function supcheckout_sanitizer_comments($apply) {
    global $wpdb;
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM %i WHERE comment_type NOT IN ('order_note','action_log','webhook_delivery') AND comment_author_email <> '' AND comment_author_email NOT LIKE '%%@example.invalid'",
        $wpdb->comments
    ));
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET comment_author = 'Reviewer', "
            . "comment_author_email = CONCAT('reviewer-', comment_ID, '@example.invalid'), "
            . "comment_author_IP = '', comment_agent = '', comment_author_url = '' WHERE comment_type NOT IN ('order_note','action_log','webhook_delivery')",
            $wpdb->comments
        ));
    }
    return $count;
}
