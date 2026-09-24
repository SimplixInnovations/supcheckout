<?php

$q15_pass = 0;
$q15_fail = 0;
$q15_root = dirname(__DIR__, 2);

function q15_assert($condition, $label) {
    global $q15_pass, $q15_fail;
    if ($condition) { ++$q15_pass; echo "PASS: {$label}\n"; return; }
    ++$q15_fail; echo "FAIL: {$label}\n";
}

function q15_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q15_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q15_contains($source, $needle) { return strpos($source, $needle) !== false; }

function q15_git_blob_sha($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q15_read($q15_root, 'phpstan.neon.dist');
$phpcs = q15_read($q15_root, 'phpcs.xml.dist');
$source = q15_read($q15_root, 'src/Subscription/Presentation.php');
$tests = q15_read($q15_root, 'tests/unit/Subscription/PresentationTest.php');
$fixture = q15_read($q15_root, 'tests/support/wordpress-subscription-presentation.php');
$runtime = q15_read($q15_root, 'tests/fixtures/subscription-presentation-runtime.php');
$stubs = q15_read($q15_root, 'tests/phpstan/subscription-presentation-stubs.php');
$bootstrap = q15_read($q15_root, 'tests/bootstrap.php');
$workflow = q15_read($q15_root, '.github/workflows/quality-gates.yml');
$quality = q15_read($q15_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q15_read($q15_root, 'docs/project/PROJECT-STATUS.md');
$readme = q15_read($q15_root, 'README.md');
$roadmap = q15_read($q15_root, 'docs/ENGINEERING-ROADMAP.md');
$agents = q15_read($q15_root, 'AGENTS.md');
$q14_harness = q15_read($q15_root, 'tests/harness/quality-platform-migration-admin-harness.php');

q15_assert(q15_contains($phpstan, 'src/Subscription/Presentation.php'), 'PHPStan owns subscription presentation');
q15_assert(q15_contains($phpstan, 'tests/phpstan/subscription-presentation-stubs.php'), 'PHPStan loads bounded presentation stubs');
q15_assert(q15_contains($phpcs, 'src/Subscription/Presentation.php'), 'PHPCS owns subscription presentation');
q15_assert(!q15_contains($phpstan, 'baseline'), 'Q15 remains baseline-free');
q15_assert(!q15_contains($phpstan, 'ignoreErrors'), 'Q15 introduces no ignored analyzer errors');

foreach (array(
    "\$types['custom_type'] = __('Subscription Product', 'supcheckout')",
    "if (\$product_type === 'custom_type')",
    "\$classname = 'WCProductCustomType'",
    "'target' => 'custom_product_data_panel'",
    "'class' => array('show_if_custom_type')",
    "'id' => '_custom_field_id'",
    "wp_verify_nonce(\$nonce, 'woocommerce_save_data')",
    "current_user_can('edit_post', \$post_id)",
    "update_post_meta(\$post_id, '_custom_field_id', \$custom_field_value)",
) as $contract) {
    q15_assert(q15_contains($source, $contract), "product/admin contract remains exact: {$contract}");
}

q15_assert(q15_contains($source, "isset(\$_POST['woocommerce_meta_nonce']) && is_string(\$_POST['woocommerce_meta_nonce'])"), 'malformed nonce shape fails closed');
q15_assert(q15_contains($source, "sanitize_text_field(wp_unslash(\$_POST['woocommerce_meta_nonce']))"), 'product nonce is unslashed and sanitized before verification');
q15_assert(q15_contains($source, "!is_string(\$_POST['post_ID']) && !is_int(\$_POST['post_ID'])"), 'malformed product ID shape fails closed');
q15_assert(q15_contains($source, '!$product instanceof \\WC_Product'), 'frontend presentation requires a WooCommerce product');
q15_assert(substr_count($source, "!isset(\$cart_item['product_id'])") === 1, 'cart presentation guards a missing product ID');
q15_assert(substr_count($source, "!isset(\$values['product_id'])") === 1, 'order-item copy guards a missing product ID');
q15_assert(q15_contains($source, "method_exists(\$item, 'add_meta_data')"), 'order-item copy guards the mutation method');
q15_assert(q15_contains($source, "method_exists(\$item, 'get_product')"), 'admin summary guards item product access');
q15_assert(q15_contains($source, '$product instanceof \\WC_Product'), 'admin summary guards missing products');
q15_assert(q15_contains($source, "\$gateway->render_subscription_summary(\$order);\n                return;"), 'admin summary renders at most once per order');
q15_assert(q15_contains($source, 'private static function date_time_or_null'), 'date parsing has one fail-closed boundary');
q15_assert(q15_contains($source, 'catch (\\Exception $exception)'), 'invalid dates cannot escape the presentation boundary');
q15_assert(q15_contains($source, "if (\$raw_status !== 'cancelled')"), 'cancelled admin summary does not expose a next-billing date');
q15_assert(q15_contains($source, "in_array(\$filter, array('active', 'paused', 'cancelled'), true)"), 'account filter uses the exact status allowlist');
q15_assert(q15_contains($source, "!isset(\$request['subscription_filter']) || !is_string(\$request['subscription_filter'])"), 'malformed account filter shape fails closed');
q15_assert(q15_contains($source, "\$raw_filter = wp_unslash(\$request['subscription_filter'])"), 'account status input is unslashed before validation');
q15_assert(q15_contains($source, 'if ($filter !== $raw_filter)'), 'account status rejects lossy sanitation before its allowlist');
q15_assert(q15_contains($source, "self::request_text(\$_GET, 'page_id')"), 'account page identity is read through the existing sanitized request boundary');
q15_assert(!q15_contains($source, "self::request_text(\$_GET, 'page_id', '12')"), 'account filter never invents historical page ID 12');
q15_assert(q15_contains($source, "if (\$page_id !== '')"), 'account page identity is rendered only when explicitly supplied');
q15_assert(q15_contains($source, "esc_html__('Auto Deduction', 'supcheckout')"), 'account type label is escaped');
q15_assert(q15_contains($source, "esc_attr(\$status)") && q15_contains($source, "esc_html(ucfirst(\$status))"), 'account status is escaped in attribute and HTML contexts');

foreach (array(
    'product_type_selector_mapping_and_admin_schema_remain_exact',
    'product_class_registration_remains_guarded_and_loads_exact_legacy_type',
    'product_meta_write_requires_exact_nonce_post_and_capability_boundaries',
    'malformed_product_meta_request_fails_closed_without_writing',
    'frontend_value_is_escaped_and_non_product_globals_are_ignored',
    'cart_and_order_item_meta_preserve_valid_shape_and_ignore_malformed_payloads',
    'admin_order_summary_ignores_missing_products_and_renders_once_per_order',
    'mixed_cart_validation_preserves_both_exact_rejection_contracts',
    'account_query_accepts_only_exact_known_subscription_statuses',
    'account_filter_preserves_only_supplied_page_identity_and_ignores_malformed_status',
    'account_columns_labels_and_status_output_remain_escaped',
    'owned_manual_account_details_preserve_actions_and_nonce_identities',
    'account_details_fail_closed_for_other_cancelled_auto_and_malformed_orders',
    'admin_summary_escapes_values_handles_invalid_dates_and_hides_cancelled_next_date',
    'class_is_final_with_only_the_frozen_public_static_boundary',
) as $name) {
    q15_assert(q15_contains($tests, $name), "subscription presentation test exists: {$name}");
}

q15_assert(q15_contains($tests, "array('upay_unsubscribe_44', '_wpnonce', false)"), 'unsubscribe nonce identity is asserted');
q15_assert(q15_contains($tests, "array('upay_resume_44', '_wpnonce', false)"), 'resume nonce identity is asserted');
q15_assert(q15_contains($tests, "'<b>paused</b>'"), 'formatted status cannot normalize into an allowed filter');
q15_assert(q15_contains($tests, "array('product_id' => array(12))"), 'malformed cart product shape is tested');
q15_assert(q15_contains($fixture, "'capability_calls'"), 'fixture records capability checks');
q15_assert(q15_contains($fixture, "'meta_writes'"), 'fixture records product-meta writes');
q15_assert(q15_contains($fixture, "'nonce_fields'"), 'fixture records manual-action nonces');
q15_assert(q15_contains($runtime, 'class WC_Upayments'), 'runtime fixture isolates the gateway summary delegate');
q15_assert(q15_contains($stubs, 'namespace UPayments\\Subscription\\Cron'), 'analysis stub models the scheduler boundary without running it');
q15_assert(q15_contains($bootstrap, "require __DIR__ . '/support/wordpress-subscription-presentation.php';"), 'PHPUnit bootstrap loads the presentation fixture');

foreach (array(
    'process_payment',
    'auto-deduct',
    'upay_process_subscriptions',
    'upayments_billing_attempts',
    'CURLOPT_',
    "update_meta_data('_upay_subscription_status'",
) as $forbidden) {
    q15_assert(!q15_contains($source, $forbidden), "presentation excludes unrelated runtime ownership: {$forbidden}");
}

q15_assert(q15_git_blob_sha($q15_root . '/includes/Subscription/Cron/Scheduler.php') === '1c120eefe36e874379149b1caadc134988756f4e', 'protected Scheduler blob remains exact');
q15_assert(q15_git_blob_sha($q15_root . '/includes/Subscription/Cron/CycleClaim.php') === 'a1ae04e3239e05c4894781480333fd5e747cefba', 'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening');

q15_assert(q15_contains($workflow, 'tests/harness/quality-platform-subscription-presentation-harness.php'), 'Q15 harness remains mandatory in Quality Gates');
q15_assert(q15_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
q15_assert(q15_contains($workflow, "reject_across_live_records 'CURRENT / Q14'"), 'Governance rejects stale current-Q14 markers');
q15_assert(q15_contains($agents, 'Quality Platform Q1-Q19 harnesses;'), 'AGENTS keeps the closed Quality Platform harness set mandatory');
q15_assert(q15_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q15');
q15_assert(q15_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q15_assert(q15_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q15_assert(q15_contains($roadmap, 'Q17 payment-runtime checkout-orchestration/lifecycle'), 'roadmap names the finite Q17 closeout');
q15_assert(q15_contains($quality, 'enterprise-critical risk'), 'quality record prohibits meaningless Q-sequence extension');

foreach (array(
    '01a06d45fcc0bc3d08da8d58f6be177b232bb1d4',
    'ea5b0b3880a99999577d51a9ed5f6a8c77a52cf0',
    'Quality Gates run #253',
    '144 tests / 899 assertions',
    'Q15 **107/0**',
    'a4bbb05021dbded73072c0ba108a18245b60ad88',
    'Quality Gates run #254',
    'implementation branch deleted',
) as $evidence) {
    q15_assert(q15_contains($quality, $evidence), "Q15 closure evidence is pinned: {$evidence}");
}

foreach (array(
    'b2d8630a5903af8f26a7f770a2a80547c871f7c6',
    '53107c93c8756985461a8d75e2009c91b89ee851',
    'Quality Gates run #247',
    '129 tests / 825 assertions',
    'Q14 Migration Admin Analysis: **109/0**',
    '22857f6304d4b4f19ec1cb6303a80d120173bcd1',
    'Quality Gates run #248',
    'implementation branch deleted after verified merge',
) as $evidence) {
    q15_assert(q15_contains($quality, $evidence), "Q14 closure evidence is pinned: {$evidence}");
    q15_assert(q15_contains($q14_harness, $evidence), "Q14 permanent harness pins closure evidence: {$evidence}");
}

echo "\nQ15 Subscription Presentation Analysis: {$q15_pass} PASS / {$q15_fail} FAIL\n";
exit($q15_fail === 0 ? 0 : 1);
