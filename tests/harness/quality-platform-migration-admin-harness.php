<?php

$q14_pass = 0;
$q14_fail = 0;
$q14_root = dirname(__DIR__, 2);

function q14_assert($condition, $label) {
    global $q14_pass, $q14_fail;
    if ($condition) { ++$q14_pass; echo "PASS: {$label}\n"; return; }
    ++$q14_fail; echo "FAIL: {$label}\n";
}

function q14_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q14_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q14_contains($source, $needle) { return strpos($source, $needle) !== false; }

function q14_git_blob_sha($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q14_read($q14_root, 'phpstan.neon.dist');
$phpcs = q14_read($q14_root, 'phpcs.xml.dist');
$source = q14_read($q14_root, 'src/Migration/MigrationAdmin.php');
$tests = q14_read($q14_root, 'tests/unit/Migration/MigrationAdminTest.php');
$fixture = q14_read($q14_root, 'tests/support/wordpress-migration-admin.php');
$stubs = q14_read($q14_root, 'tests/phpstan/migration-admin-stubs.php');
$bootstrap = q14_read($q14_root, 'tests/bootstrap.php');
$workflow = q14_read($q14_root, '.github/workflows/quality-gates.yml');
$quality = q14_read($q14_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q14_read($q14_root, 'docs/project/PROJECT-STATUS.md');
$readme = q14_read($q14_root, 'README.md');
$handoff = q14_read($q14_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q14_read($q14_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');
$q13_harness = q14_read($q14_root, 'tests/harness/quality-platform-migration-cli-harness.php');

q14_assert(q14_contains($phpstan, 'src/Migration/MigrationAdmin.php'), 'PHPStan owns migration admin adapter');
q14_assert(q14_contains($phpcs, 'src/Migration/MigrationAdmin.php'), 'PHPCS owns migration admin adapter');
q14_assert(q14_contains($phpstan, 'tests/phpstan/migration-admin-stubs.php'), 'PHPStan loads bounded admin stubs');
q14_assert(!q14_contains($phpstan, 'baseline'), 'Q14 remains baseline-free');
q14_assert(!q14_contains($phpstan, 'ignoreErrors'), 'Q14 introduces no ignored analyzer errors');

foreach (array(
    "const CAPABILITY = 'manage_woocommerce'",
    "const PAGE_SLUG = 'simplixpay-upayments-migration'",
    "const NONCE_ACTION = 'simplixpay_upayments_migration_run'",
    "const NONCE_FIELD = 'simplixpay_upayments_nonce'",
) as $contract) {
    q14_assert(q14_contains($source, $contract), "admin constant remains exact: {$contract}");
}
q14_assert(q14_contains($source, "add_submenu_page(\n            'woocommerce',\n            'SUPCheckout for UPayments Migration',\n            'SUPCheckout Migration',\n            self::CAPABILITY,\n            self::PAGE_SLUG,\n            array(__CLASS__, 'render')"), 'WooCommerce submenu contract remains exact');

$capability_position = strpos($source, 'current_user_can(self::CAPABILITY)');
$request_position = strpos($source, "\$_SERVER['REQUEST_METHOD']");
$nonce_position = strpos($source, 'check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD)');
q14_assert($capability_position !== false && $request_position !== false && $capability_position < $request_position, 'capability check precedes request processing');
q14_assert($nonce_position !== false && $request_position !== false && $request_position < $nonce_position, 'POST gate precedes nonce verification');
q14_assert(q14_contains($source, "\$request_method = \$_SERVER['REQUEST_METHOD']"), 'server request-method bytes remain exact');
q14_assert(q14_contains($source, "if (\$request_method === 'POST')"), 'request method uses an exact POST allowlist');
q14_assert(!q14_contains($source, "wp_unslash(\$_SERVER['REQUEST_METHOD'])"), 'request method cannot lose backslashes before comparison');
q14_assert(!q14_contains($source, "sanitize_key(wp_unslash(\$_SERVER['REQUEST_METHOD']))"), 'malformed request methods cannot normalize into POST');
q14_assert(q14_contains($source, "\$form['migration_action'] === 'execute'"), 'execute mode remains explicit');
q14_assert(q14_contains($source, "\$_POST['confirm_execute'] !== 'yes'"), 'execute confirmation requires exact yes');
q14_assert(q14_contains($source, "\$form['migration_action'] = wp_unslash(\$_POST['migration_action'])"), 'submitted action is unslashed without lossy normalization');
q14_assert(!q14_contains($source, "sanitize_key(wp_unslash(\$_POST['migration_action']))"), 'malformed action tokens cannot normalize into execute');
q14_assert(q14_contains($source, "\$form['resume'] = isset(\$_POST['resume']) && \$_POST['resume'] === 'yes' ? 'yes' : 'no'"), 'resume checkbox accepts exact yes only');
q14_assert(q14_contains($source, "if (\$resume && \$offset !== 0)"), 'resume and nonzero offset remain mutually exclusive');
q14_assert(q14_contains($source, "preg_match('/^(?:0|[1-9][0-9]*)\\z/', \$value) === 1"), 'integer text parser uses an absolute canonical-decimal end anchor');
q14_assert(!q14_contains($source, "preg_match('/^(?:0|[1-9][0-9]*)$/', \$value)"), 'permissive terminal-newline integer anchor is absent');
q14_assert(q14_contains($source, 'MigrationBatch::DEFAULT_LIMIT'), 'default batch limit remains centralized');
q14_assert(q14_contains($source, 'MigrationBatch::MAX_LIMIT'), 'maximum batch limit remains centralized');
q14_assert(q14_contains($source, 'MigrationSettings::resolve()'), 'credentials remain sourced only from existing settings');
q14_assert(q14_contains($source, 'MigrationSettings::redact($settings)'), 'admin output keeps settings redaction');
q14_assert(q14_contains($source, "\$error = \$settings['reason'];"), 'settings failure preserves the exact settings-contract reason');
q14_assert(q14_contains($source, "\$error = \$resume_info['reason'];"), 'resume failure preserves the exact batch-contract reason');
q14_assert(!q14_contains($source, "'settings_unavailable'"), 'analyzer-proven unreachable settings fallback is removed');
q14_assert(!q14_contains($source, "'resume_unavailable'"), 'analyzer-proven unreachable resume fallback is removed');
q14_assert(!q14_contains($source, "name=\"api_key\""), 'admin form exposes no API-key input');
q14_assert(q14_contains($source, "esc_html('Migration request rejected: ' . \$error)"), 'request failures are escaped');
q14_assert(q14_contains($source, 'esc_html($encoded)'), 'structured result output is escaped');
q14_assert(q14_contains($source, "esc_textarea(\$form['user_ids'])"), 'submitted user IDs are escaped for textarea context');
q14_assert(q14_contains($source, "esc_attr(\$form['offset'])"), 'submitted offset is escaped for attribute context');
q14_assert(q14_contains($source, "esc_attr(\$form['limit'])"), 'submitted limit is escaped for attribute context');
foreach (array('wp_remote_', 'curl_', 'CURLOPT_', 'process_payment', 'Scheduler', 'CycleClaim') as $forbidden) {
    q14_assert(!q14_contains($source, $forbidden), "admin adapter excludes unrelated ownership: {$forbidden}");
}

foreach (array(
    'register_uses_exact_woocommerce_submenu_contract',
    'render_denies_missing_capability_before_request_processing',
    'get_render_exposes_only_the_bounded_credential_free_form',
    'noncanonical_request_method_cannot_enter_post_path',
    'post_requires_exact_nonce_and_escapes_rejected_form_values',
    'invalid_nonce_terminates_before_form_or_settings_processing',
    'noncanonical_action_fails_closed_before_settings_resolution',
    'successful_preflight_renders_redacted_and_escaped_result_without_execution',
    'execute_requires_explicit_confirmation_before_settings_resolution',
    'form_parser_preserves_exact_defaults_bounds_and_resume_exclusion',
    'form_parser_rejects_noncanonical_and_out_of_range_integers',
    'boundary_is_final_with_only_register_and_render_public',
) as $name) {
    q14_assert(q14_contains($tests, $name), "migration admin test exists: {$name}");
}
q14_assert(q14_contains($tests, '"1\\n"'), 'admin parser tests reject terminal-newline integers');
q14_assert(q14_contains($tests, "'overflow offset'"), 'admin parser matrix rejects integer overflow');
q14_assert(q14_contains($tests, "'over-limit'"), 'admin parser matrix rejects values above the centralized maximum');
q14_assert(q14_contains($tests, "'embedded whitespace' => array('P OST')"), 'request-level regression covers a whitespace-malformed POST token');
q14_assert(q14_contains($tests, "'embedded backslash' => array('P\\\\OST')"), 'request-level regression covers an already-unslashed backslash method');
q14_assert(q14_contains($tests, "'migration_action' => 'exec ute'"), 'request-level regression covers a malformed execute token');
q14_assert(q14_contains($tests, "assertStringNotContainsString('name=\"api_key\"'"), 'admin form test rejects credential input');
q14_assert(q14_contains($tests, 'settings_must_not_be_read'), 'execute confirmation test proves settings are not read early');
q14_assert(q14_contains($tests, 'secret-api-key<script>'), 'successful render test uses a detectable secret sentinel');
q14_assert(q14_contains($tests, "'&quot;reason&quot;: &quot;batch_complete&quot;'"), 'successful render test requires escaped structured output');
q14_assert(q14_contains($fixture, "'capability_calls'"), 'admin fixture records capability checks');
q14_assert(q14_contains($fixture, "'nonce_checks'"), 'admin fixture records nonce checks');
q14_assert(q14_contains($fixture, "'submenu_calls'"), 'admin fixture records submenu registration');
q14_assert(q14_contains($stubs, '/** @return never */'), 'analysis stub models terminating wp_die boundary');
q14_assert(q14_contains($bootstrap, "require __DIR__ . '/support/wordpress-migration-admin.php';"), 'PHPUnit bootstrap loads admin fixture');

q14_assert(q14_git_blob_sha($q14_root . '/includes/Subscription/Cron/Scheduler.php') === '67b2597404fafee241ebfb9996e4da08ee3e11d9', 'protected Scheduler blob remains exact');
q14_assert(q14_git_blob_sha($q14_root . '/includes/Subscription/Cron/CycleClaim.php') === 'a1ae04e3239e05c4894781480333fd5e747cefba', 'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening');

q14_assert(q14_contains($workflow, 'tests/harness/quality-platform-migration-admin-harness.php'), 'Q14 harness remains mandatory in Quality Gates');
q14_assert(q14_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
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
    q14_assert(q14_contains($quality, $evidence), "Q14 closure evidence is pinned: {$evidence}");
}
q14_assert(q14_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q14');
q14_assert(q14_contains($quality, 'capability-before-request authorization'), 'quality record documents strict privileged control ordering');
q14_assert(q14_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q14_assert(q14_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q14_assert(q14_contains($playbook, 'Quality Platform Q13: DONE / VERIFIED; PR #39; merge a744417e1ec2f40b4f59706df84589d8b18638cb;'), 'playbook pins Q13 merge');
q14_assert(q14_contains($playbook, 'tree be7c52143d2085550790b742d164ecbec413377f; Q13 77/0; post-merge Quality Gates #237 SUCCESS'), 'playbook pins Q13 tree');
q14_assert(q14_contains($q13_harness, 'Quality Platform Q13: DONE / VERIFIED; PR #39; merge a744417e1ec2f40b4f59706df84589d8b18638cb;'), 'Q13 harness uses its immutable closure row');
q14_assert(!q14_contains($q13_harness, 'Last verified implementation main SHA: a744417e1ec2f40b4f59706df84589d8b18638cb'), 'Q13 harness does not mistake a historical merge for current main');
q14_assert(q14_contains($q13_harness, 'tree be7c52143d2085550790b742d164ecbec413377f; Q13 77/0; post-merge Quality Gates #237 SUCCESS'), 'Q13 harness pins its immutable closure tree');
q14_assert(!q14_contains($handoff, 'CURRENT / Q13'), 'handoff rejects stale current-Q13 marker');
q14_assert(!q14_contains($playbook, 'CURRENT / Q13'), 'playbook rejects stale current-Q13 marker');
q14_assert(q14_contains($workflow, "reject_across_live_records 'CURRENT / Q13'"), 'Governance rejects stale current-Q13 markers');
q14_assert(!q14_contains($handoff, 'CURRENT / Q14'), 'handoff rejects stale current-Q14 marker');
q14_assert(!q14_contains($playbook, 'CURRENT / Q14'), 'playbook rejects stale current-Q14 marker');
q14_assert(q14_contains($workflow, "reject_across_live_records 'CURRENT / Q14'"), 'Governance rejects stale current-Q14 markers');

echo "\nQ14 Migration Admin Analysis: {$q14_pass} PASS / {$q14_fail} FAIL\n";
exit($q14_fail === 0 ? 0 : 1);
