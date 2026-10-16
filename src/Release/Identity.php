<?php

namespace Simplixi\SUPCheckout\Release;

defined('ABSPATH') || exit;

/**
 * Canonical product/release identity for SUPCheckout for UPayments.
 *
 * The first-party PSR-4 namespace has migrated to the canonical SUPCheckout
 * root. NAMESPACE_ROOT is the permanent machine-readable namespace contract.
 *
 * Persisted payment/provider identities are explicit compatibility contracts,
 * not product branding. They must not be destroyed by a rebrand.
 */
final class Identity {
    public const PRODUCT_NAME = 'SUPCheckout for UPayments';
    public const SHORT_NAME = 'SUPCheckout';
    public const VERSION = '0.1.0';
    public const SLUG = 'supcheckout';
    public const REPOSITORY = 'SimplixInnovations/supcheckout';
    public const TEXT_DOMAIN = 'supcheckout';
    public const NAMESPACE_ROOT = 'Simplixi\\SUPCheckout';

    /** Physical main file inside the package root since Sub-project 0a. */
    public const MAIN_FILE = 'supcheckout.php';

    /** External self-updates stay disabled until release authority is explicitly enabled. */
    public const UPDATE_CHANNEL = 'disabled';

    /** Historical install/runtime identities retained for compatibility (LEGACY_MAIN_FILE is the pre-rename main file). */
    public const LEGACY_MAIN_FILE = 'UPayments.php';
    public const LEGACY_TEXT_DOMAIN = 'upayments';
    public const LEGACY_GATEWAY_ID = 'upayments';
    public const LEGACY_SETTINGS_OPTION = 'woocommerce_upayments_settings';
    public const LEGACY_CALLBACK_ROUTE = 'wc_upayments';
    public const LEGACY_SUBSCRIPTION_HOOK = 'upay_process_subscriptions';
    public const LEGACY_TOKEN_SECRET_OPTION = 'upayments_token_identity_secret_v2';
    public const LEGACY_BILLING_ATTEMPT_TABLE_SUFFIX = 'upayments_billing_attempts';

    /** Canonical packaging/i18n targets; bootstrap migration is qualified separately. */
    public const TARGET_MAIN_FILE = 'supcheckout.php';
    public const TARGET_TEXT_DOMAIN = 'supcheckout';

    private function __construct() {
    }
}

// Incremental payment-lifecycle bootstrap. The identity harness loads this file
// in deliberate isolation, outside a WordPress hook environment.
if (function_exists('add_action')) {
    require_once dirname(__DIR__) . '/Payment/PaymentLifecycle.php';
    \Simplixi\SUPCheckout\Payment\PaymentLifecycle::bootstrap();
}
