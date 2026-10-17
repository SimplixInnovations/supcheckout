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
    /** Store API checkout `extensions` namespace the Blocks client posts under. */
    public const EXTENSION_NAMESPACE_LEGACY = 'upayments';
    public const EXTENSION_NAMESPACE = 'supcheckout';
    /** The gateway settings option the switch copies into (Keys::settings_sources()). */
    public const SETTINGS_OPTION = 'woocommerce_supcheckout_settings';
    /**
     * Takeover-owned per-object audit marker (OrderCopier): JSON of the fingerprints of
     * the value lists the copier copied. Not a copied identity: Rollback's copy removal
     * and Finalize delete it (Keys::takeover_markers()).
     */
    public const TAKEOVER_COPY_MARKER = '_supcheckout_takeover_copy_v1';
    /**
     * Official 3.0.0 activation option holding the "Checkout (Block)" page ID (one of
     * Keys::official_only()). Finalize removes the option but only reports the page.
     */
    public const OFFICIAL_BLOCK_CHECKOUT_PAGE_OPTION = 'upayments_block_checkout_page_id';

    /**
     * Both gateway identities, for wc_get_orders(array('payment_method' => ...)) queries.
     * Historical orders keep 'upayments' forever; orders created after cutover carry 'supcheckout'.
     *
     * @return list<string>
     */
    public static function gateway_ids() {
        return array(self::GATEWAY_LEGACY, self::GATEWAY);
    }

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
     * Recreated, never copied. During the switch window stored lock helpers take BOTH names
     * (legacy first) so a pre-cutover and a post-cutover worker still contend (Task 2);
     * GET_LOCK() names stay legacy-only in every state (advisory_lock_names()).
     *
     * Storage differs per family, which Finalize must respect: order locks, status
     * gates and the payment-methods rate gate are plain options; the payment-methods
     * cache is a transient (`_transient_` / `_transient_timeout_` rows); the `upay_pm_`
     * and `upay_ctk_` families are MySQL GET_LOCK() names and never touch a table.
     *
     * Ordered longest match first: no legacy prefix is a prefix of a later one. The
     * GET_LOCK() families keep their legacy names in every state (advisory_lock_names()), so
     * their new prefixes are registry entries only; they are kept short anyway, because
     * GET_LOCK() names are limited to 64 characters (prefix + 32-hex scope + '_' + a 20-digit
     * user ID).
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
            'upay_ctk_bootstrap_b' => 'sup_ctkboot_b',
            'upay_ctk_' => 'sup_ctk_',
        );
    }

    /**
     * How each Keys::ephemeral_prefixes() family is stored, which decides how Finalize
     * removes its legacy rows: `option` rows are deleted by name, a `transient` through
     * delete_transient() (its `_transient_` and `_transient_timeout_` rows), and an
     * `advisory_lock` (MySQL GET_LOCK()) has no rows at all.
     *
     * @return array<string,string> Legacy prefix => option|transient|advisory_lock, in
     *                              Keys::ephemeral_prefixes() order.
     */
    public static function ephemeral_storage() {
        return array(
            'simplixpay_upay_order_lock_v1_' => 'option',
            'simplixpay_upay_status_v1_' => 'option',
            'upayments_payment_methods_rate_gate_' => 'option',
            'upay_pm_v3_' => 'transient',
            'upay_pm_' => 'advisory_lock',
            'upay_ctk_bootstrap_b' => 'advisory_lock',
            'upay_ctk_' => 'advisory_lock',
        );
    }

    /**
     * Written only by the official plugin (or, for the table, by SUPCheckout's dead
     * Subscription\Manager listener, removed in 0b Task 7: nothing ever fired its hook).
     * Nothing copies them. Preflight reports them;
     * Finalize clears the options, transients and cron hooks, but only reports the
     * tables and never drops them.
     *
     * The update-checker names are prefixes: their suffix carries the official
     * plugin's update-checker slug, which differs between releases.
     *
     * @return array{options:list<string>,option_prefixes:list<string>,transients:list<string>,cron_hooks:list<string>,cron_hook_prefixes:list<string>,tables:list<string>}
     */
    public static function official_only() {
        return array(
            'options' => array('your_gateway_version', self::OFFICIAL_BLOCK_CHECKOUT_PAGE_OPTION,
                'woocommerce_upayments_enable_block_checkout', 'upayments_maat', 'upay_subscription_settings'),
            'option_prefixes' => array('external_updates-upayments'),
            'transients' => array('upayments_cron_lock'),
            'cron_hooks' => array('upay_hourly_cron_job'),
            'cron_hook_prefixes' => array('puc_cron_check_updates-upayments'),
            'tables' => array('upay_subscriptions'),
        );
    }

    /**
     * Persisted names that deliberately keep their name through the switch.
     *
     * @return array<string,string> Name => reason.
     */
    public static function unchanged_persisted() {
        return array(
            '_custom_field_id' => 'product meta of the subscription product type (Subscription\Presentation); shared with official 3.1.1 products, read as-is',
            'custom_type' => 'product_type taxonomy term of the subscription product (WCProductCustomType); shared with official 3.1.1, renaming would retype products',
            'Special Feature' => 'order-item meta label key, stored translated; the text domain moved upayments -> supcheckout, so historical items keep whatever key string was stored',
            'chosen_payment_method' => 'WooCommerce core session key; its stored value may be upayments for up to 48 h after cutover and must still select our gateway (Keys::is_our_gateway())',
            'payment_method' => 'WooCommerce order field; historical orders keep upayments forever and are matched with Keys::gateway_ids() / Keys::is_our_gateway()',
        );
    }

    /**
     * Meta the takeover itself writes on orders and products, never copied from a legacy
     * name. Rollback (copy removal) and Finalize remove every one of them.
     *
     * @return list<string>
     */
    public static function takeover_markers() {
        return array(self::TAKEOVER_COPY_MARKER);
    }

    /** @var list<array<string,string>>|null Memoized copied maps (pure and constant per request). */
    private static $copied_maps = null;

    /** @return list<array<string,string>> */
    private static function copied_maps() {
        if (self::$copied_maps === null) {
            self::$copied_maps = array(self::order_meta(), self::user_meta(), self::user_meta_prefixes(), self::product_meta(),
                self::options(), self::cron_hooks(), self::tables());
        }
        return self::$copied_maps;
    }

    /**
     * Exact maps first, then full keys under a user-meta prefix.
     *
     * @param string $new_key SUPCheckout name (a prefix itself, or a full prefixed key).
     * @return string|null Legacy name.
     */
    public static function legacy_for($new_key) {
        $new_key = (string) $new_key;
        foreach (self::copied_maps() as $map) {
            $legacy = array_search($new_key, $map, true);
            if ($legacy !== false) {
                return (string) $legacy;
            }
        }
        foreach (self::user_meta_prefixes() as $legacy_prefix => $new_prefix) {
            if (strlen($new_key) > strlen($new_prefix) && strpos($new_key, $new_prefix) === 0) {
                return $legacy_prefix . substr($new_key, strlen($new_prefix));
            }
        }
        return null;
    }

    /**
     * Exact maps first, then full keys under a user-meta prefix
     * (`_upay_customer_token_v2_b1_{scope}` → `_supcheckout_customer_token_v2_b1_{scope}`).
     *
     * @param string $legacy Legacy name (a prefix itself, or a full prefixed key).
     * @return array{0:string,1:string} [new, legacy].
     * @throws \InvalidArgumentException When the name is not classified.
     */
    public static function meta_names($legacy) {
        $legacy = (string) $legacy;
        foreach (self::copied_maps() as $map) {
            if (isset($map[$legacy])) {
                return array($map[$legacy], $legacy);
            }
        }
        foreach (self::user_meta_prefixes() as $legacy_prefix => $new_prefix) {
            if (strlen($legacy) > strlen($legacy_prefix) && strpos($legacy, $legacy_prefix) === 0) {
                return array($new_prefix . substr($legacy, strlen($legacy_prefix)), $legacy);
            }
        }
        throw new \InvalidArgumentException('Unclassified persisted name: ' . esc_html($legacy));
    }

    /**
     * Resolve a user meta key given either its legacy or its new full name. A bare
     * prefix is not a key, and non-user-meta names return null.
     *
     * Consumers that slice a suffix (the H12 scope) must slice by the length of the
     * prefix that matched, because the legacy and new prefixes differ in length.
     *
     * @param string $key Legacy or new user meta key.
     * @return array{0:string,1:string}|null [new, legacy].
     */
    public static function user_meta_key_pair($key) {
        $key = (string) $key;
        foreach (self::user_meta() as $legacy => $new_key) {
            if ($key === $legacy || $key === $new_key) {
                return array($new_key, $legacy);
            }
        }
        foreach (self::user_meta_prefixes() as $legacy_prefix => $new_prefix) {
            if (strlen($key) > strlen($legacy_prefix) && strpos($key, $legacy_prefix) === 0) {
                return array($new_prefix . substr($key, strlen($legacy_prefix)), $key);
            }
            if (strlen($key) > strlen($new_prefix) && strpos($key, $new_prefix) === 0) {
                return array($key, $legacy_prefix . substr($key, strlen($new_prefix)));
            }
        }
        return null;
    }

    /**
     * @return string TakeoverState::get()['status'] (TakeoverState::INVALID for an unreadable record).
     */
    public static function takeover_status() {
        return (string) TakeoverState::get()['status'];
    }

    /**
     * The order in which a value is read when both names are consulted: the name writes
     * go to right now first, the other name as fallback. Before cutover [legacy, new],
     * after cutover [new, legacy].
     *
     * @param string $legacy Legacy name (exact, or a full key under a user-meta prefix).
     * @return array{0:string,1:string} [read first, fallback].
     */
    public static function read_names($legacy) {
        list($new_key, $legacy) = self::meta_names($legacy);
        return TakeoverState::is_cut_over() ? array($new_key, $legacy) : array($legacy, $new_key);
    }

    /**
     * Dual read through a caller-supplied reader, so each call site keeps its exact
     * storage call (get_meta($key), get_meta($key, true), get_user_meta(..., true), ...).
     *
     * Before cutover this is exactly one read of the legacy name: every runtime write
     * still lands there, so it is complete and authoritative, and a copy the takeover
     * made while `copying` (or a stale one after a rollback) can never shadow it or
     * resurrect a value runtime code deleted. After cutover the new name is read first
     * and the legacy name only when the new name is absent, which covers data written
     * before cutover and never copied.
     *
     * "Absent" comes from $exists when the storage API can tell a stored empty value
     * from a missing key (WC_Order::meta_exists(), metadata_exists()): then a stored
     * '' / 0 / false under the new name keeps its precedence. Without $exists the
     * single-value read cannot tell them apart, so '' / null / false / an empty array
     * count as absent. When both are absent the FIRST raw value is returned.
     *
     * @param callable      $read   function (string $key): mixed.
     * @param string        $legacy Legacy name.
     * @param bool          $always Read both names in every state (H12 provenance).
     * @param callable|null $exists function (string $key): bool, when the API can tell.
     * @return mixed
     */
    public static function read($read, $legacy, $always = false, $exists = null) {
        if (!$always && !TakeoverState::is_cut_over()) {
            self::meta_names($legacy);
            return $read((string) $legacy);
        }
        list($first, $fallback) = self::read_names($legacy);
        if (is_callable($exists)) {
            if ($exists($first)) {
                return $read($first);
            }
            return $exists($fallback) ? $read($fallback) : $read($first);
        }
        $value = $read($first);
        if (!self::is_absent($value)) {
            return $value;
        }
        $other = $read($fallback);
        return self::is_absent($other) ? $value : $other;
    }

    /**
     * @param mixed $value A raw storage read.
     * @return bool True for the values WordPress/WooCommerce return for a missing key.
     */
    public static function is_absent($value) {
        return $value === '' || $value === null || $value === false || $value === array();
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @return mixed $order->get_meta() under the read order.
     */
    public static function read_order_meta($order, $legacy) {
        $exists = method_exists($order, 'meta_exists')
            ? function ($key) use ($order) {
                return (bool) $order->meta_exists($key);
            }
            : null;
        return self::read(function ($key) use ($order) {
            return $order->get_meta($key);
        }, $legacy, false, $exists);
    }

    /**
     * @param int    $user_id User ID.
     * @param string $legacy  Legacy user meta key (exact or under a user-meta prefix).
     * @return mixed get_user_meta($user_id, $key, true) under the read order.
     */
    public static function read_user_meta($user_id, $legacy) {
        $exists = function_exists('metadata_exists')
            ? function ($key) use ($user_id) {
                return (bool) metadata_exists('user', $user_id, $key);
            }
            : null;
        return self::read(function ($key) use ($user_id) {
            return get_user_meta($user_id, $key, true);
        }, $legacy, false, $exists);
    }

    /**
     * @param int    $post_id Post / product ID.
     * @param string $legacy  Legacy post meta key.
     * @return mixed get_post_meta($post_id, $key, true) under the read order.
     */
    public static function read_post_meta($post_id, $legacy) {
        $exists = function_exists('metadata_exists')
            ? function ($key) use ($post_id) {
                return (bool) metadata_exists('post', $post_id, $key);
            }
            : null;
        return self::read(function ($key) use ($post_id) {
            return get_post_meta($post_id, $key, true);
        }, $legacy, false, $exists);
    }

    /**
     * Copied option read (Keys::options()). The first read keeps the caller's default;
     * a first value that is absent or equal to that default counts as missing.
     *
     * @param string $legacy  Legacy option name.
     * @param mixed  $default Default for the first read.
     * @param bool   $always  Read both names in every state (H12 provenance).
     * @return mixed
     * @throws \InvalidArgumentException When the option is not registered.
     */
    public static function get_option($legacy, $default = false, $always = false) {
        self::assert_option($legacy);
        if (!$always && !TakeoverState::is_cut_over()) {
            return get_option($legacy, $default);
        }
        // A private sentinel tells "missing" from any stored value, so a stored 0 / false /
        // '' under the preferred name keeps its precedence over a stale fallback.
        $missing = self::missing();
        foreach (self::read_names($legacy) as $name) {
            $value = get_option($name, $missing);
            if ($value !== $missing) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Both names of a security identity read in every state, which must agree.
     *
     * Returns $default when neither exists, the one present when only one exists, the
     * shared value when both hold the identical (===) value, and Keys::conflict() when
     * both hold different values (a partial takeover, rollback or restore): the caller
     * must fail closed and never pick one.
     *
     * @param string $legacy  Legacy option name.
     * @param mixed  $default Returned when neither name exists.
     * @return mixed
     */
    public static function get_option_agreed($legacy, $default = false) {
        self::assert_option($legacy);
        list($new_key, $legacy) = self::meta_names($legacy);
        $missing = self::missing();
        $legacy_value = get_option($legacy, $missing);
        $new_value = get_option($new_key, $missing);
        if ($legacy_value === $missing) {
            return $new_value === $missing ? $default : $new_value;
        }
        if ($new_value === $missing || $new_value === $legacy_value) {
            return $legacy_value;
        }
        return self::conflict();
    }

    /** @var \stdClass|null */
    private static $missing = null;

    /** @var \stdClass|null */
    private static $conflict = null;

    /** @return \stdClass Private "no stored value" sentinel. */
    private static function missing() {
        if (self::$missing === null) {
            self::$missing = new \stdClass();
        }
        return self::$missing;
    }

    /** @return \stdClass The value Keys::get_option_agreed() returns for disagreeing copies. */
    public static function conflict() {
        if (self::$conflict === null) {
            self::$conflict = new \stdClass();
        }
        return self::$conflict;
    }

    /**
     * The primary name of a value right now: the legacy name until cutover, the new name
     * after. For key builders and logs only; runtime writes and deletes go through
     * Keys::write_keys() / Keys::delete_names() so they follow the window model.
     *
     * @param string $legacy Legacy name.
     * @return string
     */
    public static function write_key($legacy) {
        return TakeoverState::is_cut_over() ? self::meta_names($legacy)[0] : $legacy;
    }

    /**
     * The window model: every name a runtime write or delete must reach right now.
     *
     * - idle, preflight_ok, rolled_back: the legacy name only (exactly today's write);
     * - copying, verified, cut_over, and an unreadable (invalid) or unknown status: BOTH
     *   names, legacy first, so a value written or a marker deleted after the takeover
     *   copied an order can never be shadowed or resurrected by that copy at cutover,
     *   and the legacy name stays current for a rollback;
     * - finalized: the new name only (a delete removes both: Keys::delete_names()).
     *
     * Reads stay legacy-only until is_cut_over() (Keys::read()).
     *
     * @param string $legacy Legacy name (exact, or a full key under a user-meta prefix).
     * @return list<string>
     */
    public static function write_keys($legacy) {
        list($new_key, $legacy) = self::meta_names($legacy);
        $status = self::takeover_status();
        if ($status === TakeoverState::IDLE || $status === TakeoverState::PREFLIGHT_OK
            || $status === TakeoverState::ROLLED_BACK
        ) {
            return array($legacy);
        }
        if ($status === TakeoverState::FINALIZED) {
            return array($new_key);
        }
        return array($legacy, $new_key);
    }

    /**
     * Names a delete must remove: the same window model as writes, except `finalized`,
     * which removes BOTH names (amended in Task 11). Reads after cutover fall back to the
     * legacy name when the new one is absent, and Finalize removes a legacy key only where
     * the new key verifies equal (an unequal one is kept for the owner, and the removal
     * pass reaches each object only after the transition). A new-name-only delete would
     * therefore let a read resurrect the legacy leftover.
     *
     * @param string $legacy Legacy name.
     * @return list<string>
     */
    public static function delete_names($legacy) {
        if (self::takeover_status() === TakeoverState::FINALIZED) {
            list($new_key, $legacy) = self::meta_names($legacy);
            return array($legacy, $new_key);
        }
        return self::write_keys($legacy);
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @param mixed     $value  Value.
     * @return void
     */
    public static function update_order_meta($order, $legacy, $value) {
        foreach (self::write_keys($legacy) as $name) {
            $order->update_meta_data($name, $value);
        }
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @param mixed     $value  Value.
     * @param bool      $unique Pass WooCommerce's unique flag.
     * @return void
     */
    public static function add_order_meta($order, $legacy, $value, $unique = false) {
        foreach (self::write_keys($legacy) as $name) {
            if ($unique) {
                $order->add_meta_data($name, $value, true);
            } else {
                $order->add_meta_data($name, $value);
            }
        }
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @return void
     */
    public static function delete_order_meta($order, $legacy) {
        foreach (self::delete_names($legacy) as $name) {
            $order->delete_meta_data($name);
        }
    }

    /**
     * update_option() on every name of a copied option (Keys::options()).
     *
     * @param string    $legacy   Legacy option name.
     * @param mixed     $value    Value.
     * @param bool|null $autoload Autoload flag, passed through.
     * @return bool True when every write reported success.
     */
    public static function update_copied_option($legacy, $value, $autoload = null) {
        self::assert_option($legacy);
        $written = true;
        foreach (self::write_keys($legacy) as $name) {
            $written = update_option($name, $value, $autoload) && $written;
        }
        return $written;
    }

    /**
     * add_option() on every name of a copied option, all or nothing.
     *
     * Every other window name is probed first: if one already holds a different value
     * nothing is added and the call fails closed (a conflicting copy is never
     * overwritten, and no partial result is left behind). Then the first name decides
     * creation exactly as one add_option() did, and each other name is added unless it
     * already holds the identical value. Should a later add still lose to a concurrent
     * writer with a different value, the value this call added is deleted again.
     *
     * @param string $legacy   Legacy option name.
     * @param mixed  $value    Value.
     * @param string $autoload Autoload flag, passed through.
     * @return bool True only when this call created the value and every name holds it.
     */
    public static function add_copied_option($legacy, $value, $autoload = 'no') {
        self::assert_option($legacy);
        $names = self::write_keys($legacy);
        $others = array_slice($names, 1);
        $missing = new \stdClass();
        foreach ($others as $name) {
            $existing = get_option($name, $missing);
            if ($existing !== $missing && $existing !== $value) {
                return false;
            }
        }
        if (!add_option($names[0], $value, '', $autoload)) {
            return false;
        }
        foreach ($others as $name) {
            if (!add_option($name, $value, '', $autoload) && get_option($name, $missing) !== $value) {
                delete_option($names[0]);
                return false;
            }
        }
        return true;
    }

    /**
     * delete_option() on every name of a copied option.
     *
     * @param string $legacy Legacy option name.
     * @return void
     */
    public static function delete_copied_option($legacy) {
        self::assert_option($legacy);
        foreach (self::delete_names($legacy) as $name) {
            delete_option($name);
        }
    }

    /**
     * @param string $legacy Legacy option name.
     * @return void
     * @throws \InvalidArgumentException When the option is not registered.
     */
    private static function assert_option($legacy) {
        if (!isset(self::options()[$legacy])) {
            throw new \InvalidArgumentException('Unregistered option: ' . esc_html((string) $legacy));
        }
    }

    /**
     * Whether a hooked meta key is this legacy name or its new name.
     *
     * @param mixed  $meta_key Key passed to the hook.
     * @param string $legacy   Legacy name.
     * @return bool
     */
    public static function is_meta_key($meta_key, $legacy) {
        return in_array((string) $meta_key, self::meta_names($legacy), true);
    }

    /**
     * A meta_query clause on a legacy key, in the read semantics of Keys::read().
     *
     * Before cutover the clause is returned unchanged (the legacy name is authoritative).
     * After cutover it matches the new name, or the legacy name on rows that have no new
     * name: array('relation' => 'OR', [new …], array('relation' => 'AND', [legacy …],
     * [new NOT EXISTS])). The NOT EXISTS guard keeps a stale legacy copy (a subscription
     * cancelled after cutover still holds `active` under the legacy name) from matching.
     *
     * @param array<string,mixed> $clause A meta_query clause whose 'key' is the legacy name.
     * @return array<string|int,mixed>
     */
    public static function meta_clause_any(array $clause) {
        list($new_key, $legacy) = self::meta_names((string) $clause['key']);
        if (!TakeoverState::is_cut_over()) {
            return $clause;
        }
        $new_clause = $clause;
        $new_clause['key'] = $new_key;
        $legacy_clause = $clause;
        $legacy_clause['key'] = $legacy;
        return array(
            'relation' => 'OR',
            $new_clause,
            array('relation' => 'AND', $legacy_clause, array('key' => $new_key, 'compare' => 'NOT EXISTS')),
        );
    }

    /**
     * The `payment_method` value for an order query: today's 'upayments' before cutover
     * (no order carries 'supcheckout' yet; Rollback rewrites post-cutover orders back),
     * both identities after it.
     *
     * @return string|list<string>
     */
    public static function payment_method_query() {
        return TakeoverState::is_cut_over() ? self::gateway_ids() : self::GATEWAY_LEGACY;
    }

    /**
     * Gateway settings read order. Before cutover exactly today's option
     * (woocommerce_upayments_settings); after cutover the target first, then every
     * settings source in precedence order.
     *
     * @return list<string>
     */
    public static function settings_option_names() {
        if (TakeoverState::is_cut_over()) {
            return array_merge(array(self::SETTINGS_OPTION), self::settings_sources());
        }
        return array(self::settings_sources()[0]);
    }

    /**
     * @param mixed $default Default for the first read (callers keep their own).
     * @return mixed The first non-empty settings array, else the first raw read.
     */
    public static function get_settings($default = false) {
        $names = self::settings_option_names();
        $first = get_option($names[0], $default);
        if (count($names) === 1 || (is_array($first) && $first !== array())) {
            return $first;
        }
        foreach (array_slice($names, 1) as $name) {
            $value = get_option($name, null);
            if (is_array($value) && $value !== array()) {
                return $value;
            }
        }
        return $first;
    }

    /**
     * The name a Keys::cron_hooks() event is scheduled under right now: the legacy name
     * until cutover, the new name after.
     *
     * @param string $legacy Legacy hook name.
     * @return string
     * @throws \InvalidArgumentException When the hook is not a registered cron hook.
     */
    public static function cron_hook($legacy) {
        $new_hook = self::new_cron_hook($legacy);
        return TakeoverState::is_cut_over() ? $new_hook : (string) $legacy;
    }

    /**
     * Every name a Keys::cron_hooks() callback listens on, and every name a "scheduled
     * already?" check or an unschedule must cover: the legacy name only before `copying`
     * (exactly today), both names (legacy first) from `copying` on. The cutover keeps the
     * legacy events until Finalize and schedules a twin under the new name, so both run
     * the same callback; a runtime unschedule inside the window removes both.
     *
     * @param string $legacy Legacy hook name.
     * @return list<string>
     * @throws \InvalidArgumentException When the hook is not a registered cron hook.
     */
    public static function cron_hook_names($legacy) {
        $new_hook = self::new_cron_hook($legacy);
        $status = self::takeover_status();
        if ($status === TakeoverState::IDLE || $status === TakeoverState::PREFLIGHT_OK) {
            return array((string) $legacy);
        }
        return array((string) $legacy, $new_hook);
    }

    /**
     * @param string $legacy Legacy hook name.
     * @return string
     * @throws \InvalidArgumentException When the hook is not a registered cron hook.
     */
    private static function new_cron_hook($legacy) {
        $hooks = self::cron_hooks();
        if (!isset($hooks[$legacy])) {
            throw new \InvalidArgumentException('Unregistered cron hook: ' . esc_html((string) $legacy));
        }
        return $hooks[$legacy];
    }

    /** @return string The gateway id in use right now ('upayments' until cutover). */
    public static function gateway_id() {
        return TakeoverState::is_cut_over() ? self::GATEWAY : self::GATEWAY_LEGACY;
    }

    /**
     * The WC-API route new Charges put in their callback URLs. The legacy route stays
     * registered forever (Task 8 LegacyCallbackListener) for payments started earlier.
     *
     * @return string
     */
    public static function callback_route() {
        return TakeoverState::is_cut_over() ? self::CALLBACK_ROUTE : self::CALLBACK_ROUTE_LEGACY;
    }

    /** @return string The Store API extensions namespace the client posts under right now. */
    public static function extension_namespace() {
        return TakeoverState::is_cut_over() ? self::EXTENSION_NAMESPACE : self::EXTENSION_NAMESPACE_LEGACY;
    }

    /**
     * The id our gateway is registered under in a WooCommerce gateways array.
     *
     * Before cutover exactly today's lookup: whatever is registered at 'upayments'.
     * After cutover the current id first, then the legacy id, and only an instance of
     * SUPCheckout's own gateway class: the official plugin's gateway object can sit at
     * 'upayments' and must never be mistaken for ours.
     *
     * @param mixed $gateways Gateway id => gateway.
     * @return string|null
     */
    public static function find_gateway_id($gateways) {
        if (!is_array($gateways)) {
            return null;
        }
        if (!TakeoverState::is_cut_over()) {
            return isset($gateways[self::GATEWAY_LEGACY]) ? self::GATEWAY_LEGACY : null;
        }
        foreach (array(self::GATEWAY, self::GATEWAY_LEGACY) as $id) {
            if (isset($gateways[$id]) && $gateways[$id] instanceof \Simplixi\SUPCheckout\Gateway\Gateway) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Our gateway in a WooCommerce gateways array (see Keys::find_gateway_id()).
     *
     * @param mixed $gateways Gateway id => gateway.
     * @return mixed|null The gateway, or null.
     */
    public static function find_gateway($gateways) {
        $id = self::find_gateway_id($gateways);
        return $id === null ? null : $gateways[$id];
    }

    /**
     * The names a lock, gate or cache entry uses, in acquisition order.
     *
     * Before `copying` (idle, preflight_ok) only the legacy name, exactly as today.
     * After `finalized` only the new name. In between (copying, verified, cut_over,
     * rolled_back, or an unknown status) legacy first, then new, so a worker that
     * started before cutover and one that started after still contend.
     *
     * @param string $legacy_name Full legacy name (or bare prefix) under a Keys::ephemeral_prefixes() key.
     * @return list<string>
     * @throws \InvalidArgumentException When the name is not under an ephemeral prefix.
     */
    public static function ephemeral_names($legacy_name) {
        $legacy_name = (string) $legacy_name;
        $new_name = null;
        foreach (self::ephemeral_prefixes() as $legacy_prefix => $new_prefix) {
            if (strpos($legacy_name, $legacy_prefix) === 0) {
                $new_name = $new_prefix . substr($legacy_name, strlen($legacy_prefix));
                break;
            }
        }
        if ($new_name === null) {
            throw new \InvalidArgumentException('Unregistered ephemeral name: ' . esc_html($legacy_name));
        }
        $status = self::takeover_status();
        if ($status === TakeoverState::IDLE || $status === TakeoverState::PREFLIGHT_OK) {
            return array($legacy_name);
        }
        if ($status === TakeoverState::FINALIZED) {
            return array($new_name);
        }
        // copying, verified, cut_over, rolled_back and an unreadable (invalid) record.
        return array($legacy_name, $new_name);
    }

    /**
     * MySQL GET_LOCK() names (the `upay_pm_` and `upay_ctk_` families): the legacy name in
     * EVERY state, finalized included (amended in the Task 11 review), never two at once.
     *
     * These names are never stored, so nothing needs them renamed, and switching them at any
     * transition would let a request that read the old state and one that read the new state
     * hold different names and exclude neither other: `upay_ctk_*` guards the H12
     * token-identity bootstrap and the Phase 9I migration, `upay_pm_` the payment-methods
     * refresh. Before MySQL 5.7.5 a connection holds at most one named lock (GET_LOCK()
     * silently releases any lock it already holds), and WordPress 6.9 still accepts MySQL
     * 5.5.5, so a second name is never taken either. The new prefixes in
     * Keys::ephemeral_prefixes() are kept for the registry but never used for GET_LOCK().
     *
     * @param string $legacy_name Full legacy GET_LOCK name.
     * @return list<string>
     * @throws \InvalidArgumentException When the name is not under an ephemeral prefix.
     */
    public static function advisory_lock_names($legacy_name) {
        self::ephemeral_names($legacy_name);
        return array((string) $legacy_name);
    }

    /**
     * The billing-attempts journal in use right now: the legacy table until cutover, the
     * new table after. Its claims are compare-and-swap SQL that cannot be mirrored into a
     * second table atomically, so this table is NOT dual-written in the window: the
     * plan's cutover re-syncs it (REPLACE + checksum) under a quiesce of the subscription
     * scheduler, and Rollback back-syncs it.
     *
     * @param string $legacy Legacy table name without prefix.
     * @return string Prefixed table name in use right now.
     * @throws \InvalidArgumentException When the table is not registered.
     */
    public static function table($legacy) {
        global $wpdb;
        $tables = self::tables();
        if (!isset($tables[$legacy])) {
            throw new \InvalidArgumentException('Unregistered table: ' . esc_html((string) $legacy));
        }
        return $wpdb->prefix . (TakeoverState::is_cut_over() ? $tables[$legacy] : $legacy);
    }

    /**
     * Historical orders keep payment_method 'upayments' forever; orders created after
     * cutover carry 'supcheckout'. Before cutover no SUPCheckout order or session can hold
     * 'supcheckout' (orders keep 'upayments' through the copy window), so only the legacy
     * id is ours: a request-controlled 'supcheckout' is rejected exactly as before.
     *
     * @param mixed $id An order's payment_method, a session value or a gateway id.
     * @return bool
     */
    public static function is_our_gateway($id) {
        if (!TakeoverState::is_cut_over()) {
            return $id === self::GATEWAY_LEGACY;
        }
        return in_array($id, array(self::GATEWAY_LEGACY, self::GATEWAY), true);
    }

    private function __construct() {}
}
