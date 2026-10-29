<?php

$q7_pass = 0;
$q7_fail = 0;
$q7_root = dirname(__DIR__, 2);

function q7_assert($condition, $label) {
    global $q7_pass, $q7_fail;
    if ($condition) { ++$q7_pass; echo "PASS: {$label}\n"; return; }
    ++$q7_fail; echo "FAIL: {$label}\n";
}

function q7_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q7_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q7_contains($source, $needle) { return strpos($source, $needle) !== false; }

$phpstan = q7_read($q7_root, 'phpstan.neon.dist');
$phpcs = q7_read($q7_root, 'phpcs.xml.dist');
$source = q7_read($q7_root, 'src/Security/PublicOrderStatus.php');
$tests = q7_read($q7_root, 'tests/unit/Security/PublicOrderStatusTest.php');
$fixture = q7_read($q7_root, 'tests/support/wordpress-public-order-status.php');
$workflow = q7_read($q7_root, '.github/workflows/quality-gates.yml');
$quality = q7_read($q7_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q7_read($q7_root, 'docs/project/PROJECT-STATUS.md');
$readme = q7_read($q7_root, 'README.md');
$handoff = q7_read($q7_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q7_read($q7_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q7_assert(q7_contains($phpstan, 'src/Security/PublicOrderStatus.php'), 'PHPStan owns public order status');
q7_assert(q7_contains($phpcs, 'src/Security/PublicOrderStatus.php'), 'PHPCS owns public order status');
q7_assert(!q7_contains($phpstan, 'baseline'), 'Q7 remains baseline-free');
q7_assert(!q7_contains($phpstan, 'ignoreErrors'), 'Q7 introduces no ignored analyzer errors');

foreach (array('wait', 'pending', 'failed', 'completed', 'cancelled') as $allowed) {
    q7_assert(q7_contains($source, "'{$allowed}'"), "public status remains explicit: {$allowed}");
}
q7_assert(q7_contains($source, "\$method !== 'GET'"), 'public poll remains GET-only');
q7_assert(q7_contains($source, "array_key_exists('wc_order_id', \$get)"), 'order ID remains explicit request input');
q7_assert(q7_contains($source, "Keys::is_our_gateway((string) \$order->get_payment_method())"), 'only UPayments orders are eligible');
q7_assert(q7_contains($source, '$current_user_id === $order_user_id'), 'logged-in ownership remains exact');
q7_assert(q7_contains($source, 'hash_equals($order_key, $provided_key)'), 'guest order-key comparison remains constant-time');
q7_assert(q7_contains($source, "strlen(\$value) > 18"), 'order ID length remains bounded');
q7_assert(q7_contains($source, "preg_match('/\\A[1-9][0-9]*\\z/', \$value)"), 'order ID uses absolute anchors for strict positive decimal input');
q7_assert(q7_contains($source, 'strlen($value) > 128'), 'order key length remains bounded');
q7_assert(q7_contains($source, "preg_match('/[\\x00-\\x20\\x7F]/', \$value)"), 'order key rejects controls and whitespace');
q7_assert(q7_contains($source, "Keys::read_order_meta(\$order, 'UPayments_WHS')"), 'public output reads only the protected narrow status');
q7_assert(q7_contains($source, "array('status' => \$status, 'message' => '')"), 'successful response remains two-field only');
q7_assert(q7_contains($source, "'Order status unavailable.'"), 'failure response remains generic');

q7_assert(substr_count($tests, 'public function test_') >= 8, 'PublicOrderStatus has focused PHPUnit characterization');
foreach (array(
    'order_id_parser_accepts_only_bounded_positive_decimal_strings',
    'status_normalization_preserves_only_the_narrow_public_allowlist',
    'authorization_requires_upayments_and_exact_owner_or_order_key',
    'order_key_authorization_rejects_empty_oversized_control_and_non_string_values',
    'handle_rejects_non_get_and_invalid_or_missing_order_identifiers',
    'handle_rejects_non_upayments_and_unauthorized_orders',
    'handle_allows_exact_logged_in_owner_and_returns_only_narrow_status_payload',
    'handle_allows_exact_guest_key_after_unslashing_and_unknown_status_fails_closed',
) as $name) {
    q7_assert(q7_contains($tests, $name), "public-status test exists: {$name}");
}
q7_assert(q7_contains($tests, "array('status', 'message')"), 'response tests reject expanded public payloads');
q7_assert(q7_contains($tests, "'CAPTURED'"), 'unknown provider-like state is tested fail closed');
q7_assert(q7_contains($fixture, 'class SUPCheckout_Test_Json_Response'), 'fixture captures terminating JSON responses');
q7_assert(q7_contains($fixture, 'function wc_get_order('), 'fixture provides deterministic order lookup');
q7_assert(q7_contains($fixture, 'function is_user_logged_in('), 'fixture provides deterministic login state');
q7_assert(q7_contains($fixture, 'function wp_send_json('), 'fixture captures WordPress JSON dispatch');
q7_assert(q7_contains($phpstan, '- vendor/php-stubs/wordpress-stubs/wordpress-stubs.php'), 'PHPStan scans the official WordPress stubs, which declare sanitize_text_field() and wp_send_json() (A6-06 retired the wordpress-option hand stub)');
q7_assert(q7_contains($source, '$sanitized_method === $raw_method'), 'malformed request methods cannot become GET through sanitation');
q7_assert(q7_contains($tests, "'G\\\\ET'"), 'authorized-order regression covers backslash method normalization bypass');
q7_assert(q7_contains($phpstan, '- vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php'), 'PHPStan scans the official WooCommerce stubs, which declare wc_get_order() (A6-06)');

q7_assert(q7_contains($workflow, 'quality-platform-public-order-status-harness.php'), 'Q7 harness is mandatory in Quality Gates');
q7_assert(q7_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '48de59414c952d6f90ce90c4f462dde67fcbdabc',
    '6ef43632a4868a1114b5468a38ad45138e41c393',
    'Quality Gates run #212',
    'e00a80147d4f6267d137e1bdfa0b2d1211e00f6a',
    'Quality Gates run #213',
    'implementation branch deleted',
) as $evidence) {
    q7_assert(q7_contains($quality, $evidence), "Q7 closure evidence is pinned: {$evidence}");
}
q7_assert(q7_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q7');
q7_assert(q7_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q7_assert(q7_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q7_assert(!q7_contains($playbook, 'Last verified implementation main SHA: e00a80147d4f6267d137e1bdfa0b2d1211e00f6a'), 'playbook does not mistake verified Q7 merge for current main');
q7_assert(!q7_contains($playbook, 'Canonical implementation tree: 6ef43632a4868a1114b5468a38ad45138e41c393'), 'playbook does not mistake verified Q7 tree for current tree');
q7_assert(!q7_contains($handoff, 'CURRENT / Q7'), 'handoff rejects stale current-Q7 marker');
q7_assert(!q7_contains($playbook, 'CURRENT / Q7'), 'playbook rejects stale current-Q7 marker');
q7_assert(q7_contains($workflow, "reject_across_live_records 'CURRENT / Q7'"), 'Governance rejects stale current-Q7 markers');

echo "\nQ7 Public Order Status Analysis: {$q7_pass} PASS / {$q7_fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($q7_fail === 0 ? 0 : 1);
