<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * The single registry of legacy → SUPCheckout persisted identity names.
 *
 * Evidence: docs/superpowers/specs/2026-10-16-official-plugin-data-inventory.md and the
 * 2026-10-16 identity audit. tests/harness/takeover-identity-coverage-harness.php fails
 * when shipped code uses a legacy-looking name that is not classified here.
 *
 * Every legacy name appears exactly once across the copied maps. Settings sources,
 * ephemeral prefixes and official-only names are deliberately outside them: they are
 * never copied one-to-one.
 */
final class Keys {
    public const GATEWAY_LEGACY = 'upayments';
    public const GATEWAY = 'supcheckout';
    public const CALLBACK_ROUTE_LEGACY = 'wc_upayments';
    public const CALLBACK_ROUTE = 'supcheckout';

    /** @return array<string,string> Order meta, legacy → new. */
    public static function order_meta() {
        return array(
            // Official 2.x/3.x provider fields (UPayments_TranID/Auth/reference: official plugin only).
            'UPayments_order_id' => '_supcheckout_provider_order_id',
            'UPayments_Result' => '_supcheckout_result',
            'UPayments_PaymentID' => '_supcheckout_payment_id',
            'UPayments_TrackID' => '_supcheckout_track_id',
            'UPayments_payment_type' => '_supcheckout_payment_type',
            'UPayments_PostDate' => '_supcheckout_post_date',
            'UPayments_TranID' => '_supcheckout_tran_id',
            'UPayments_Ref' => '_supcheckout_ref',
            'UPayments_Auth' => '_supcheckout_auth',
            'UPayments_webhook_triggered' => '_supcheckout_webhook_triggered',
            'UPayments_WHS' => '_supcheckout_public_status',
            'UPayments_reference' => '_supcheckout_reference',
            'UPayments_Checkout_Selected' => '_supcheckout_checkout_selected',
            'UPayments_AutoDeduction' => '_supcheckout_auto_deduction',
            'UPayments_ParentOrderID' => '_supcheckout_parent_order_id',
            'UPayments_InvoiceID' => '_supcheckout_invoice_id',
            'UPayments_TransactionDate' => '_supcheckout_transaction_date',
            'UPayments_GatewayStatus' => '_supcheckout_gateway_status',
            // Tokens and subscriptions.
            '_upay_credit_card_token' => '_supcheckout_card_token',
            '_upay_customer_unique_token' => '_supcheckout_customer_token',
            '_upay_customer_token_kind_v1' => '_supcheckout_customer_token_kind_v1',
            '_upay_customer_token_scope_v1' => '_supcheckout_customer_token_scope_v1',
            '_upay_customer_token_generation_v1' => '_supcheckout_customer_token_generation_v1',
            '_upay_subscription_plan' => '_supcheckout_subscription_plan',
            '_upay_subscription_interval' => '_supcheckout_subscription_interval',
            '_upay_subscription_status' => '_supcheckout_subscription_status',
            '_upay_last_billed_at' => '_supcheckout_last_billed_at',
            '_upay_last_attempt_at' => '_supcheckout_last_attempt_at',
            '_upay_retry_count' => '_supcheckout_retry_count',
            '_upay_last_failed_reason' => '_supcheckout_last_failed_reason',
            '_upay_verified_capture' => '_supcheckout_verified_capture',
            // Lifecycle (PaymentLifecycle.php constants).
            '_simplixpay_upayments_status_track_v1' => '_supcheckout_status_track_v1',
            '_simplixpay_upayments_status_requested_v1' => '_supcheckout_status_requested_v1',
            '_simplixpay_upayments_unverified_track_v1' => '_supcheckout_unverified_track_v1',
            '_simplixpay_upayments_unverified_requested_v1' => '_supcheckout_unverified_requested_v1',
            '_simplixpay_upayments_prior_requested_v1' => '_supcheckout_prior_requested_v1',
            '_simplixpay_upayments_provider_result_v1' => '_supcheckout_provider_result_v1',
            '_simplixpay_upayments_reconcile_attempt_v1' => '_supcheckout_reconcile_attempt_v1',
            '_simplixpay_upayments_reconcile_reason_v1' => '_supcheckout_reconcile_reason_v1',
            '_simplixpay_upayments_reconcile_exhausted_v1' => '_supcheckout_reconcile_exhausted_v1',
            '_simplixpay_upayments_callback_lookups_v1' => '_supcheckout_callback_lookups_v1',
            '_simplixpay_upayments_capture_apply_failed_v1' => '_supcheckout_capture_apply_failed_v1',
            '_simplixpay_upayments_unapplied_captures_v1' => '_supcheckout_unapplied_captures_v1',
            '_simplixpay_upayments_secondary_pending_v1' => '_supcheckout_secondary_pending_v1',
            '_simplixpay_upayments_secondary_attempt_v1' => '_supcheckout_secondary_attempt_v1',
            '_simplixpay_upayments_secondary_noted_v1' => '_supcheckout_secondary_noted_v1',
        );
    }

    /** @return array<string,string> User meta, legacy → new (MigrationExecutor/MigrationBatch ledgers). */
    public static function user_meta() {
        return array(
            'simplixpay_upayments_migration_v1' => 'supcheckout_migration_v1',
            '_simplixpay_upayments_migration_result_v1' => '_supcheckout_migration_result_v1',
        );
    }

    /** @return array<string,string> H12 provenance: _upay_customer_token_v2_b{blog}_{scope}. */
    public static function user_meta_prefixes() {
        return array('_upay_customer_token_v2_b' => '_supcheckout_customer_token_v2_b');
    }

    /** @return array<string,string> Product meta, legacy → new. */
    public static function product_meta() {
        return array('_upay_disable_subscription' => '_supcheckout_disable_subscription');
    }

    /** @return array<string,string> Options, legacy → new. */
    public static function options() {
        return array(
            'upayments_token_identity_secret_v2' => 'supcheckout_token_identity_secret_v2',
            'upay_billing_cycle_schema_version' => 'supcheckout_billing_cycle_schema_version',
            'upay_subscription_enrollment_cursor' => 'supcheckout_subscription_enrollment_cursor',
        );
    }

    /** @return list<string> First non-empty wins; target is woocommerce_supcheckout_settings. */
    public static function settings_sources() {
        return array('woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings');
    }

    /** @return array<string,string> WP-Cron and Action Scheduler hooks, legacy → new. */
    public static function cron_hooks() {
        return array(
            'upay_process_subscriptions' => 'supcheckout_process_subscriptions',
            'simplixpay_upayments_reconcile_order' => 'supcheckout_reconcile_order',
            'simplixpay_upayments_reconcile_secondary' => 'supcheckout_reconcile_secondary',
        );
    }

    /** @return array<string,string> Table names without $wpdb->prefix. */
    public static function tables() {
        return array('upayments_billing_attempts' => 'supcheckout_billing_attempts');
    }

    /**
     * Recreated, never copied. During the switch window lock helpers take BOTH names
     * (legacy first) so a pre-cutover and a post-cutover worker still contend (Task 2).
     *
     * @return array<string,string>
     */
    public static function ephemeral_prefixes() {
        return array(
            'simplixpay_upay_order_lock_v1_' => 'supcheckout_order_lock_v1_',
            'simplixpay_upay_status_v1_' => 'supcheckout_status_gate_v1_',
            'upayments_payment_methods_rate_gate_' => 'supcheckout_pmgate_v1_',
            'upay_pm_v3_' => 'supcheckout_pmcache_v3_',
            'upay_pm_' => 'supcheckout_pmlock_',
            'upay_ctk_' => 'supcheckout_ctklock_',
        );
    }

    /**
     * Written only by the official plugin. Preflight reports them, Finalize clears them,
     * nothing copies them.
     *
     * @return array{options:list<string>,transients:list<string>,cron_hooks:list<string>}
     */
    public static function official_only() {
        return array(
            'options' => array('your_gateway_version', 'upayments_block_checkout_page_id',
                'woocommerce_upayments_enable_block_checkout', 'external_updates-upayments-V2.2.1', 'upayments_maat'),
            'transients' => array('upayments_cron_lock'),
            'cron_hooks' => array('upay_hourly_cron_job', 'puc_cron_check_updates-upayments-V2.2.1'),
        );
    }

    /** @return list<array<string,string>> */
    private static function copied_maps() {
        return array(self::order_meta(), self::user_meta(), self::user_meta_prefixes(), self::product_meta(),
            self::options(), self::cron_hooks(), self::tables());
    }

    /**
     * @param string $new_key SUPCheckout name.
     * @return string|null Legacy name.
     */
    public static function legacy_for($new_key) {
        foreach (self::copied_maps() as $map) {
            $legacy = array_search($new_key, $map, true);
            if ($legacy !== false) {
                return (string) $legacy;
            }
        }
        return null;
    }

    /**
     * @param string $legacy Legacy meta key.
     * @return array{0:string,1:string} [new, legacy].
     * @throws \InvalidArgumentException When the name is not in a copied map.
     */
    public static function meta_names($legacy) {
        foreach (self::copied_maps() as $map) {
            if (isset($map[$legacy])) {
                return array($map[$legacy], $legacy);
            }
        }
        throw new \InvalidArgumentException('Unclassified persisted name: ' . esc_html((string) $legacy));
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @return mixed New value, else the legacy value, else ''.
     */
    public static function read_order_meta($order, $legacy) {
        list($new_key) = self::meta_names($legacy);
        $value = $order->get_meta($new_key);
        return ($value !== '' && $value !== null) ? $value : $order->get_meta($legacy);
    }

    /**
     * @param string $legacy Legacy name.
     * @return string The name writes go to right now.
     */
    public static function write_key($legacy) {
        return TakeoverState::is_cut_over() ? self::meta_names($legacy)[0] : $legacy;
    }

    /**
     * @param string $legacy Legacy table name without prefix.
     * @return string Prefixed table name in use right now.
     */
    public static function table($legacy) {
        global $wpdb;
        return $wpdb->prefix . (TakeoverState::is_cut_over() ? self::tables()[$legacy] : $legacy);
    }

    /**
     * Historical orders keep payment_method 'upayments' forever; new ones get 'supcheckout'.
     *
     * @param string $id An order's payment_method or a gateway id.
     * @return bool True for either SUPCheckout identity.
     */
    public static function is_our_gateway($id) {
        return in_array($id, array(self::GATEWAY_LEGACY, self::GATEWAY), true);
    }

    private function __construct() {}
}
