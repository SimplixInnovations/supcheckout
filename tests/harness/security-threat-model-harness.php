<?php

/**
 * Security Threat-Model Closure executable regression harness.
 *
 * This harness deliberately combines small pure-runtime checks with static
 * source contracts for security boundaries that otherwise require a full
 * WordPress/WooCommerce browser environment. It does not claim broad product
 * penetration-test certification; it protects the specific reviewed controls
 * named in docs/project/SECURITY-THREAT-MODEL.md.
 */

define('ABSPATH', __DIR__ . '/');

$pass = 0;
$fail = 0;

function sec_assert($condition, $description) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$description}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$description}\n";
}

function sec_source($relative) {
    $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    $content = @file_get_contents($path);
    sec_assert(is_string($content), "source readable: {$relative}");
    return is_string($content) ? $content : '';
}

require_once dirname(__DIR__, 2) . '/src/Security/PublicOrderStatus.php';

use Simplixi\SUPCheckout\Security\PublicOrderStatus;

final class SecurityHarnessOrder {
    private $payment_method;
    private $user_id;
    private $order_key;
    private $status;

    public function __construct($payment_method, $user_id, $order_key, $status = 'wait') {
        $this->payment_method = $payment_method;
        $this->user_id = $user_id;
        $this->order_key = $order_key;
        $this->status = $status;
    }

    public function get_payment_method() { return $this->payment_method; }
    public function get_user_id() { return $this->user_id; }
    public function get_order_key() { return $this->order_key; }
    public function get_meta($key) { return $key === 'UPayments_WHS' ? $this->status : ''; }
}

// SEC-01: public status-poll object authorization.
sec_assert(PublicOrderStatus::parse_order_id('1') === 1, 'status poll accepts positive decimal order id');
sec_assert(PublicOrderStatus::parse_order_id('987654') === 987654, 'status poll preserves positive order id');
sec_assert(PublicOrderStatus::parse_order_id('0') === null, 'status poll rejects zero order id');
sec_assert(PublicOrderStatus::parse_order_id('-1') === null, 'status poll rejects negative order id');
sec_assert(PublicOrderStatus::parse_order_id('01') === null, 'status poll rejects leading-zero order id');
sec_assert(PublicOrderStatus::parse_order_id('1e3') === null, 'status poll rejects exponent order id');
sec_assert(PublicOrderStatus::parse_order_id(' 1') === null, 'status poll rejects whitespace order id');
sec_assert(PublicOrderStatus::parse_order_id(array('1')) === null, 'status poll rejects non-scalar order id');

$owned = new SecurityHarnessOrder('upayments', 42, 'wc_order_secret');
$guest = new SecurityHarnessOrder('upayments', 0, 'wc_order_guest_secret');
$other_gateway = new SecurityHarnessOrder('cod', 42, 'wc_order_secret');

sec_assert(PublicOrderStatus::authorize_order($owned, null, 42, true) === true, 'logged-in exact owner may poll status');
sec_assert(PublicOrderStatus::authorize_order($owned, null, 43, true) === false, 'different logged-in user cannot poll status');
sec_assert(PublicOrderStatus::authorize_order($owned, null, 0, false) === false, 'anonymous caller cannot use order id alone');
sec_assert(PublicOrderStatus::authorize_order($owned, 'wc_order_secret', 0, false) === true, 'exact order key authorizes guest-compatible poll');
sec_assert(PublicOrderStatus::authorize_order($owned, 'wc_order_wrong', 0, false) === false, 'wrong order key cannot poll status');
sec_assert(PublicOrderStatus::authorize_order($guest, 'wc_order_guest_secret', 0, false) === true, 'guest order exact key authorizes status poll');
sec_assert(PublicOrderStatus::authorize_order($guest, '', 0, false) === false, 'empty guest order key rejected');
sec_assert(PublicOrderStatus::authorize_order($other_gateway, 'wc_order_secret', 42, true) === false, 'non-UPayments order cannot use status endpoint');

sec_assert(PublicOrderStatus::normalize_status('wait') === 'wait', 'status allowlist preserves wait');
sec_assert(PublicOrderStatus::normalize_status('pending') === 'pending', 'status allowlist preserves pending');
sec_assert(PublicOrderStatus::normalize_status('failed') === 'failed', 'status allowlist preserves failed');
sec_assert(PublicOrderStatus::normalize_status('completed') === 'completed', 'status allowlist preserves completed');
sec_assert(PublicOrderStatus::normalize_status('cancelled') === 'cancelled', 'status allowlist preserves cancelled');
sec_assert(PublicOrderStatus::normalize_status('<script>') === 'wait', 'unknown persisted status fails closed to wait');
sec_assert(PublicOrderStatus::normalize_status(array('completed')) === 'wait', 'non-scalar persisted status fails closed');

$gateway = sec_source('supcheckout.php');
$gateway_settings_path = dirname(__DIR__, 2) . '/src/Admin/GatewaySettings.php';
$gateway_settings = @file_get_contents($gateway_settings_path);
$gateway_settings = is_string($gateway_settings) ? $gateway_settings : '';
$subscription_presentation = sec_source('src/Subscription/Presentation.php');
$lifecycle = sec_source('src/Payment/PaymentLifecycle.php');
$status_verifier = sec_source('src/Payment/StatusVerifier.php');
$migration_admin = sec_source('src/Migration/MigrationAdmin.php');
$new_template = sec_source('templates/new-design-form.php');
$old_template = sec_source('templates/old-design-form.php');
$blocks_js = sec_source('assets/js/upayments-block.js');
$workflow = sec_source('.github/workflows/quality-gates.yml');
$security_doc = sec_source('docs/project/SECURITY-THREAT-MODEL.md');

sec_assert(
    strpos($gateway, "'isLoggedIn' => is_user_logged_in()") !== false,
    'subscription checkout browser context keeps only the required authentication boolean'
);
sec_assert(
    strpos($gateway, "'userId'     => get_current_user_id()") === false,
    'subscription checkout browser context does not expose the numeric WordPress user id'
);

// Public route must be intercepted before the inherited priority-10 dispatcher.
sec_assert(strpos($lifecycle, "require_once dirname(__DIR__) . '/Security/PublicOrderStatus.php';") !== false, 'payment lifecycle loads hardened public status boundary');
sec_assert(strpos($lifecycle, 'PublicOrderStatus::handle();') !== false, 'priority-5 lifecycle handles legacy status poll');
sec_assert(strpos($gateway, '\\Simplixi\\SUPCheckout\\Security\\PublicOrderStatus::handle();') !== false, 'legacy gateway status method delegates to hardened boundary');
sec_assert(strpos($gateway, 'get_post_meta($order_id, "UPayments_WHS"') === false, 'legacy poll no longer reads arbitrary post meta by numeric id');

// SEC-02: subscription mutations must be POST + owner + nonce + subscription preflight.
sec_assert(strpos($gateway, "\$method = isset(\$_SERVER['REQUEST_METHOD'])") !== false, 'subscription handler checks request method');
sec_assert(strpos($gateway, "\$method !== 'POST'") !== false, 'subscription mutation rejects non-POST requests');
sec_assert(strpos($gateway, "isset(\$_POST['upay_action'])") !== false, 'subscription mutation reads action from POST');
sec_assert(strpos($gateway, "isset(\$_POST['order_id'])") !== false, 'subscription mutation reads order id from POST');
sec_assert(strpos($subscription_presentation, "wp_nonce_field('upay_unsubscribe_' . \$order->get_id(), '_wpnonce', false)") !== false, 'unsubscribe form emits POST nonce field');
sec_assert(strpos($subscription_presentation, "wp_nonce_field('upay_' . \$action . '_' . \$order->get_id(), '_wpnonce', false)") !== false, 'pause/resume form emits POST nonce field');
sec_assert(strpos($gateway, "(string) \$order->get_payment_method() !== 'upayments'") !== false, 'subscription mutation requires UPayments order');
sec_assert(strpos($gateway, "\$order->get_meta('UPayments_AutoDeduction') === 'yes'") !== false, 'manual subscription mutation distinguishes auto-deduction orders');
sec_assert(strpos($gateway, "get_current_user_id() !== (int) \$order->get_user_id()") !== false, 'subscription mutation enforces exact owner');
sec_assert(strpos($gateway, 'wp_verify_nonce($nonce, $nonce_action)') !== false, 'subscription mutation verifies action-specific nonce');

// SEC-03: checkout must not pull fonts/icons from third-party CDNs.
sec_assert(strpos($gateway, 'fonts.googleapis.com') === false, 'checkout no longer requests Google Fonts');
sec_assert(strpos($gateway, 'cdnjs.cloudflare.com') === false, 'checkout no longer requests cdnjs Font Awesome');
sec_assert(strpos($new_template, 'fa fa-chevron-right') === false, 'new checkout template does not depend on Font Awesome chevron');
sec_assert(strpos($new_template, 'upay-chevron') !== false, 'new checkout template uses local text/CSS chevron');
sec_assert(strpos($blocks_js, 'fa fa-chevron-right') === false, 'Blocks checkout renderer does not depend on Font Awesome chevron');
sec_assert(substr_count($blocks_js, "className: 'upay-chevron'") >= 3, 'Blocks checkout renderer uses local chevrons for all payment rows');

// SEC-04: plain persisted/provider data is escaped as text, not trusted HTML.
sec_assert(strpos($gateway, 'wp_kses_post($payment_status)') === false, 'thank-you payment status is not permitted arbitrary post HTML');
sec_assert(strpos($gateway, 'wp_kses_post($upayment_id)') === false, 'thank-you provider payment id is not permitted arbitrary post HTML');
sec_assert(strpos($gateway_settings, "esc_attr(call_user_func(\$get_option, 'iban_number'))") !== false, 'multimerchant IBAN setting escaped in attribute context');
sec_assert(strpos($gateway_settings, "esc_attr(call_user_func(\$get_option, 'knet_charge'))") !== false, 'multimerchant KNET charge escaped in attribute context');
sec_assert(strpos($gateway_settings, "esc_attr(call_user_func(\$get_option, 'cc_charge'))") !== false, 'multimerchant card charge escaped in attribute context');
sec_assert(strpos($new_template, '$_REQUEST') === false, 'new checkout template excludes $_REQUEST');
sec_assert(strpos($old_template, '$_REQUEST') === false, 'old checkout template excludes $_REQUEST');

// SEC-05: plugin product-meta write mirrors WooCommerce save authorization.
sec_assert(
    strpos($subscription_presentation, "isset(\$_POST['woocommerce_meta_nonce']) && is_string(\$_POST['woocommerce_meta_nonce'])") !== false
        && strpos($subscription_presentation, "sanitize_text_field(wp_unslash(\$_POST['woocommerce_meta_nonce']))") !== false,
    'custom product meta save requires a sanitized scalar WooCommerce nonce'
);
sec_assert(strpos($subscription_presentation, "wp_verify_nonce(\$nonce, 'woocommerce_save_data')") !== false, 'custom product meta save verifies WooCommerce nonce action');
sec_assert(
    strpos($subscription_presentation, "!is_string(\$_POST['post_ID']) && !is_int(\$_POST['post_ID'])") !== false
        && strpos($subscription_presentation, "absint(\$_POST['post_ID']) !== \$post_id") !== false,
    'custom product meta save binds a scalar posted product id'
);
sec_assert(strpos($subscription_presentation, "current_user_can('edit_post', \$post_id)") !== false, 'custom product meta save requires edit_post capability');

// Existing verified trust boundaries must remain intact.
sec_assert(strpos($lifecycle, '$_REQUEST') === false, 'payment lifecycle continues to exclude $_REQUEST');
sec_assert(strpos($lifecycle, "add_action(self::CALLBACK_HOOK, array(__CLASS__, 'handle_callback'), 5)") !== false, 'hardened payment callback retains priority 5');
sec_assert(preg_match("/'redirection'\\s*=>\\s*0/", $status_verifier) === 1, 'provider status transport keeps redirects disabled');
sec_assert(preg_match("/'limit_response_size'\\s*=>\\s*1048576/", $status_verifier) === 1, 'provider status transport bounds response bodies to 1 MiB');
sec_assert(strpos($status_verifier, 'strlen($body) >= 1048576') !== false, 'provider status transport rejects a body that reaches the read cap');
sec_assert(strpos($status_verifier, "'sslverify'   => true") !== false, 'provider status transport keeps TLS verification');
sec_assert(strpos($status_verifier, "sandboxapi.upayments.com") !== false && strpos($status_verifier, "apiv2api.upayments.com") !== false, 'status verifier retains exact UPayments host allowlist');
sec_assert(strpos($status_verifier, "'Authorization' => 'Bearer ' . \$gateway->apiKey") !== false, 'provider status authority remains server-side Bearer authenticated');
sec_assert(strpos($migration_admin, "current_user_can(self::CAPABILITY)") !== false, 'migration admin retains capability authorization');
sec_assert(strpos($migration_admin, "check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD)") !== false, 'migration admin retains action-and-field CSRF nonce verification');

// Supply-chain workflow controls: third-party Actions stay full-SHA pinned.
$action_lines = preg_match_all('/^\s*- uses:\s+[^\s@]+@([^\s#]+)/m', $workflow, $matches);
sec_assert($action_lines !== false && $action_lines >= 2, 'quality workflow contains external Actions');
$all_pinned = true;
if (isset($matches[1])) {
    foreach ($matches[1] as $ref) {
        if (!preg_match('/^[0-9a-f]{40}$/', $ref)) {
            $all_pinned = false;
            break;
        }
    }
}
sec_assert($all_pinned, 'all quality workflow Actions are immutable full-SHA pins');

// Control-plane continuity is itself a security/recovery invariant.
sec_assert(strpos($security_doc, '**Status:** DONE / VERIFIED') !== false, 'security threat-model record tracks verified closed status');
sec_assert(strpos($security_doc, '08054a93c619f3c34fef747a6e530abce1e8986e') !== false, 'security record pins verified closure base');
sec_assert(strpos($security_doc, 'SEC-01') !== false && strpos($security_doc, 'SEC-05') !== false, 'security record names characterized findings');

// Guard against accidentally weakening the public endpoint back to order-id-only access.
$public_status_source = sec_source('src/Security/PublicOrderStatus.php');
sec_assert(strpos($public_status_source, 'hash_equals($order_key, $provided_key)') !== false, 'guest/public status authorization uses constant-time exact order-key comparison');
sec_assert(strpos($public_status_source, "(string) \$order->get_payment_method() === 'upayments'") !== false, 'status boundary requires UPayments payment method');
sec_assert(strpos($public_status_source, "method !== 'GET'") !== false, 'status boundary is read-only GET');
sec_assert(strpos($public_status_source, 'ALLOWED_STATUSES') !== false, 'status boundary allowlists returned state');

printf("\n--- Security Threat-Model Report ---\nPASS: %d\nFAIL: %d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
