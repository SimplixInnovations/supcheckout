<?php

$q6_pass = 0;
$q6_fail = 0;
$q6_root = dirname(__DIR__, 2);

function q6_assert($condition, $label) {
    global $q6_pass, $q6_fail;
    if ($condition) {
        ++$q6_pass;
        echo "PASS: {$label}\n";
        return;
    }
    ++$q6_fail;
    echo "FAIL: {$label}\n";
}

function q6_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q6_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q6_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}

$phpstan = q6_read($q6_root, 'phpstan.neon.dist');
$phpcs = q6_read($q6_root, 'phpcs.xml.dist');
$settings = q6_read($q6_root, 'src/Admin/GatewaySettings.php');
$gateway = q6_read($q6_root, 'UPayments.php');
$tests = q6_read($q6_root, 'tests/unit/Admin/GatewaySettingsTest.php');
$fixture = q6_read($q6_root, 'tests/support/wordpress-gateway-settings.php');
$analysis_stubs = q6_read($q6_root, 'tests/phpstan/wordpress-option-stubs.php');
$workflow = q6_read($q6_root, '.github/workflows/quality-gates.yml');
$quality_record = q6_read($q6_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q6_read($q6_root, 'docs/project/PROJECT-STATUS.md');
$readme = q6_read($q6_root, 'README.md');
$handoff = q6_read($q6_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q6_read($q6_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q6_assert(q6_contains($phpstan, 'src/Admin/GatewaySettings.php'), 'PHPStan owns gateway settings');
q6_assert(q6_contains($phpcs, 'src/Admin/GatewaySettings.php'), 'PHPCS owns gateway settings');
q6_assert(!q6_contains($phpstan, 'baseline'), 'Q6 remains baseline-free');
q6_assert(!q6_contains($phpstan, 'ignoreErrors'), 'Q6 introduces no ignored analyzer errors');

foreach (array(
    "'enabled' => array(",
    "'api_key' => array(",
    "'enable_save_card' => array(",
    "'enable_multimerchant' => array(",
    "'multimerchant_accounts' => array(",
    "'enable_subscriptions' => array(",
) as $field_marker) {
    q6_assert(q6_contains($settings, $field_marker), "gateway schema marker remains: {$field_marker}");
}
q6_assert(substr_count($settings, "'type' =>") === 21, 'gateway settings retain exactly 21 ordered fields');
q6_assert(q6_contains($settings, "\$settings['enable_save_card'] = 'yes';"), 'subscriptions still force saved-card support');
q6_assert(
    q6_contains($settings, "!is_string(\$api_key) || trim(\$api_key) === ''"),
    'API credential uses the runtime-equivalent nonblank string boundary before save'
);
$api_key_field_start = strpos($settings, "'api_key' => array(");
$debug_field_start = strpos($settings, "'debug' => array(");
$api_key_field_source = ($api_key_field_start !== false && $debug_field_start !== false && $debug_field_start > $api_key_field_start)
    ? substr($settings, $api_key_field_start, $debug_field_start - $api_key_field_start)
    : '';
q6_assert(q6_contains($api_key_field_source, "'type' => 'password'"), 'API credential is rendered through a masked password control');
foreach (array('iban_number', 'cc_charge', 'cc_charge_type', 'knet_charge', 'knet_charge_type') as $field) {
    q6_assert(q6_contains($settings, "'woocommerce_upayments_{$field}'"), "runtime allocation identity remains protected: {$field}");
    q6_assert(q6_contains($settings, "'{$field}' =>"), "sanitizer retains bounded allocation field: {$field}");
}
q6_assert(!q6_contains($settings, "'merchant_id' => sanitize"), 'presentation sanitizer does not retain merchant credentials');
q6_assert(!q6_contains($settings, "'api_key' => sanitize"), 'presentation sanitizer does not retain API credentials');
q6_assert(q6_contains($settings, 'if (!is_string($value))'), 'malformed non-string presentation input fails closed');

foreach (array(
    'esc_html($data[\'title\'])',
    "esc_attr(sanitize_title(\$data['type']))",
    "wp_kses_post(\$data['description'])",
    "esc_attr(call_user_func(\$get_option, 'iban_number'))",
    "esc_attr(\$settings)",
) as $escape_marker) {
    q6_assert(q6_contains($settings, $escape_marker), "renderer retains context escape: {$escape_marker}");
}
q6_assert(substr_count($settings, '<tbody>') === 1, 'renderer remains one additional allocation row');
q6_assert(q6_contains($settings, "\$query['section'] === \$gateway_id"), 'SUPCheckout admin assets require the exact gateway section');
q6_assert(q6_contains($settings, "\$screen_id === 'woocommerce_page_wc-settings'"), 'admin logic remains Woo settings-screen scoped');
q6_assert(q6_contains($settings, "\$query['tab'] === 'checkout'"), 'admin logic remains checkout-tab scoped');
q6_assert(!q6_contains($settings, "'3.0.0'"), 'admin asset version is not frozen to the inherited release');
q6_assert(q6_contains($settings, '\\Simplixi\\SUPCheckout\\Release\\Identity::VERSION'), 'admin assets bind directly to canonical SUPCheckout release identity');
q6_assert(q6_contains($gateway, 'SUPCHECKOUT_VERSION'), 'gateway binds first-party asset versions to canonical SUPCheckout identity');
q6_assert(!q6_contains($settings, '$asset_version'), 'admin asset versioning does not widen the established public method boundary');

q6_assert(
    !q6_contains($gateway, 'allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.'),
    'merchant-facing gateway description does not overclaim wallet completion'
);
q6_assert(
    !q6_contains($gateway, 'Supports Block Checkout, Auto Deduction for Subscriptions.'),
    'merchant-facing gateway description does not overclaim live subscription auto-deduction'
);
q6_assert(
    q6_contains($gateway, 'Available payment methods depend on your UPayments account and provider configuration.'),
    'merchant-facing gateway description binds payment-method claims to provider/account configuration'
);
q6_assert(
    q6_contains($gateway, 'Subscription auto-deduction requires separately validated provider setup.'),
    'merchant-facing gateway description preserves the external-validation boundary for auto-deduction'
);

q6_assert(substr_count($tests, 'public function test_') >= 8, 'GatewaySettings has focused PHPUnit characterization');
foreach (array(
    'fields_preserve_exact_keys_order_and_runtime_defaults',
    'dependency_normalization_forces_save_card_only_for_enabled_subscriptions',
    'prepare_post_data_rejects_missing_credentials_before_allocation_validation',
    'prepare_post_data_uses_the_same_nonblank_string_api_key_boundary_as_runtime_eligibility',
    'prepare_post_data_requires_every_enabled_allocation_field',
    'prepare_post_data_accepts_provider_permitted_zero_main_merchant_commissions',
    'prepare_post_data_clears_all_runtime_allocation_fields_when_checkbox_is_absent',
    'prepare_post_data_rejects_noncanonical_present_multimerchant_checkbox_tokens',
    'json_presentation_field_keeps_only_five_sanitized_non_secret_values',
    'renderer_escapes_dynamic_values_and_emits_one_allocation_row',
    'asset_loading_requires_exact_gateway_and_settings_scopes',
) as $test_name) {
    q6_assert(q6_contains($tests, $test_name), "gateway-settings test exists: {$test_name}");
}
q6_assert(q6_contains($tests, "'api_key'         => 'must-not-survive'"), 'sanitizer test rejects API-key retention');
q6_assert(q6_contains($tests, "'merchant_id'     => 'must-not-survive'"), 'sanitizer test rejects merchant-ID retention');
q6_assert(q6_contains($tests, 'assertStringNotContainsString(\'<script>\''), 'renderer test rejects raw script output');

q6_assert(q6_contains($fixture, 'function supcheckout_test_reset_gateway_settings()'), 'unit fixture resets deterministic asset state');
q6_assert(q6_contains($fixture, 'function wp_enqueue_style('), 'unit fixture records enqueued styles');
q6_assert(q6_contains($fixture, 'function wp_enqueue_script('), 'unit fixture records enqueued scripts');
q6_assert(q6_contains($fixture, 'function wp_add_inline_style('), 'unit fixture records inline styles');
q6_assert(q6_contains($analysis_stubs, 'function sanitize_text_field('), 'analysis stubs declare bounded sanitization');
q6_assert(q6_contains($analysis_stubs, 'function wp_enqueue_script('), 'analysis stubs declare bounded admin assets');

q6_assert(q6_contains($workflow, 'quality-platform-gateway-settings-harness.php'), 'Q6 harness is mandatory in Quality Gates');
q6_assert(q6_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
q6_assert(q6_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'protected H12 aggregator still reads quality result');
q6_assert(q6_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'protected H12 aggregator still reads syntax result');

foreach (array(
    '85de7a009205e6bb810fad8ab8a0634ca91d1fa8',
    '07f944a3adbbdbf6953ea96512555cb6b16286fe',
    'Quality Gates run #201',
    '651e604659d1891e0f7d05b8e684edb4aa31c2b1',
    'Quality Gates run #202',
    'implementation branch deleted',
) as $closure_evidence) {
    q6_assert(q6_contains($quality_record, $closure_evidence), "Q6 closure evidence is pinned: {$closure_evidence}");
}
q6_assert(q6_contains($quality_record, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q6');
q6_assert(q6_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q6_assert(q6_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q6_assert(
    !q6_contains($playbook, 'Last verified implementation main SHA: 651e604659d1891e0f7d05b8e684edb4aa31c2b1')
        && !q6_contains($playbook, 'Canonical implementation tree: 07f944a3adbbdbf6953ea96512555cb6b16286fe'),
    'master playbook restart anchors do not mistake verified Q6 closure for current main'
);
q6_assert(!q6_contains($handoff, 'CURRENT / Q6'), 'handoff rejects the stale current-Q6 marker');
q6_assert(!q6_contains($playbook, 'CURRENT / Q6'), 'master playbook rejects the stale current-Q6 marker');
q6_assert(q6_contains($workflow, "reject_across_live_records 'CURRENT / Q6'"), 'Governance rejects stale current-Q6 markers');

echo "\nQ6 Gateway Settings Analysis: {$q6_pass} PASS / {$q6_fail} FAIL\n";
exit($q6_fail === 0 ? 0 : 1);
