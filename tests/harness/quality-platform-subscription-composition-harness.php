<?php

$q11_pass = 0;
$q11_fail = 0;
$q11_root = dirname(__DIR__, 2);

function q11_assert($condition, $label) {
    global $q11_pass, $q11_fail;
    if ($condition) { ++$q11_pass; echo "PASS: {$label}\n"; return; }
    ++$q11_fail; echo "FAIL: {$label}\n";
}

function q11_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q11_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q11_contains($source, $needle) { return strpos($source, $needle) !== false; }

function q11_git_blob_sha($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q11_read($q11_root, 'phpstan.neon.dist');
$phpcs = q11_read($q11_root, 'phpcs.xml.dist');
$source = q11_read($q11_root, 'src/Subscription/Composition.php');
$tests = q11_read($q11_root, 'tests/unit/Subscription/CompositionTest.php');
$fixture = q11_read($q11_root, 'tests/support/wordpress-migration-bootstrap.php');
$stubs = q11_read($q11_root, 'tests/phpstan/subscription-composition-stubs.php');
$workflow = q11_read($q11_root, '.github/workflows/quality-gates.yml');
$quality = q11_read($q11_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q11_read($q11_root, 'docs/project/PROJECT-STATUS.md');
$readme = q11_read($q11_root, 'README.md');
$handoff = q11_read($q11_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q11_read($q11_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');
$scheduler = q11_read($q11_root, 'includes/Subscription/Cron/Scheduler.php');

q11_assert(q11_contains($phpstan, 'src/Subscription/Composition.php'), 'PHPStan owns subscription composition');
q11_assert(q11_contains($phpcs, 'src/Subscription/Composition.php'), 'PHPCS owns subscription composition');
q11_assert(q11_contains($phpstan, 'tests/phpstan/subscription-composition-stubs.php'), 'analysis loads bounded composition stubs');
q11_assert(!q11_contains($phpstan, 'baseline'), 'Q11 remains baseline-free');
q11_assert(!q11_contains($phpstan, 'ignoreErrors'), 'Q11 introduces no ignored analyzer errors');

$presentation_hooks = array(
    "add_action('init', array(Presentation::class, 'register_product_class'))",
    "add_filter('product_type_selector', 'addCustomProductType')",
    "add_filter('woocommerce_product_class', 'mapCustomProductClass', 10, 2)",
    "add_action('woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30)",
    "add_action('admin_footer', 'customProductTypes')",
    "add_filter('woocommerce_product_data_tabs', 'addCustomDataTab')",
    "add_action('woocommerce_product_data_panels', 'addCustomDataPanel')",
    "add_action('woocommerce_process_product_meta', 'saveCustomFieldData')",
    "add_action('woocommerce_single_product_summary', 'displayCustomFieldOnFrontend', 10)",
    "add_filter('woocommerce_get_item_data', 'displayCustomDataInCart', 10, 2)",
    "add_action('woocommerce_checkout_create_order_line_item', 'saveCustomDataToOrderItems', 10, 4)",
    "add_action('woocommerce_order_details_after_order_table', array(Presentation::class, 'render_account_order_details'))",
    "add_action('woocommerce_before_account_orders', array(Presentation::class, 'render_account_orders_filter'))",
    "add_filter('woocommerce_my_account_my_orders_query', array(Presentation::class, 'filter_account_orders_query'))",
    "add_filter('woocommerce_my_account_my_orders_columns', array(Presentation::class, 'filter_account_orders_columns'))",
    "add_action('woocommerce_my_account_my_orders_column_order_type', array(Presentation::class, 'render_account_order_type'))",
    "add_action('woocommerce_my_account_my_orders_column_order_status', array(Presentation::class, 'render_account_subscription_status'))",
    "add_action('woocommerce_admin_order_data_after_billing_address', array(Presentation::class, 'render_admin_order_summary'), 10, 1)",
);
foreach ($presentation_hooks as $hook) {
    q11_assert(substr_count($source, $hook) === 1, "presentation hook remains exact: {$hook}");
}
q11_assert(substr_count($source, "add_filter('woocommerce_add_to_cart_validation', array(\$gateway, 'restrictMixedCartProducts'), 10, 3)") === 1, 'gateway cart-validation hook remains exact');
q11_assert(substr_count($source, "add_action('woocommerce_before_shop_loop_item_title', array(\$gateway, 'renderSubscriptionBadgeInProductList'), 9)") === 1, 'gateway product-badge hook remains exact');

q11_assert(substr_count($source, '$root = dirname(__DIR__, 2);') === 1, 'legacy dependency root remains exact');
foreach (array(
    'includes/Subscription/Checkout/Fields.php',
    'includes/Subscription/Manager.php',
    'includes/Subscription/Helpers/Utils.php',
) as $dependency) {
    q11_assert(substr_count($source, "require_once \$root . '/{$dependency}'") === 1, "legacy dependency remains exact: {$dependency}");
}
q11_assert(substr_count($source, 'Fields::init();') === 1, 'checkout fields initializer remains exact');
q11_assert(substr_count($source, 'Manager::init();') === 1, 'subscription manager initializer remains exact');

foreach (array(
    'Cron\\Scheduler',
    'Scheduler::',
    'CycleClaim::',
    'process_payment',
    'upay_process_subscriptions',
    'upayments_billing_attempts',
    'update_meta_data',
    'wp_remote_',
    'CURLOPT_',
) as $forbidden) {
    q11_assert(!q11_contains($source, $forbidden), "composition excludes protected ownership: {$forbidden}");
}
q11_assert(q11_contains($source, 'private function __construct()'), 'composition is explicitly non-instantiable');
q11_assert(preg_match("/'limit_response_size'\\s*=>\\s*1048576/", $scheduler) === 1, 'subscription provider response body is bounded to 1 MiB');
q11_assert(preg_match('/strlen\\(\\$response\\)\\s*>=\\s*1048576/', $scheduler) === 1, 'subscription treats a response at the cap as ambiguous and fail closed');
q11_assert(q11_git_blob_sha($q11_root . '/includes/Subscription/Cron/Scheduler.php') === '67b2597404fafee241ebfb9996e4da08ee3e11d9', 'protected Scheduler blob remains exact');
q11_assert(q11_git_blob_sha($q11_root . '/includes/Subscription/Cron/CycleClaim.php') === 'a1ae04e3239e05c4894781480333fd5e747cefba', 'protected CycleClaim reviewed blob remains exact after identifier-placeholder and warning-boundary hardening');

q11_assert(substr_count($tests, 'public function test_') >= 5, 'Subscription Composition has focused PHPUnit characterization');
foreach (array(
    'registers_exact_presentation_hook_topology',
    'registers_only_exact_gateway_instance_hooks',
    'legacy_modules_keep_exact_root_dependencies_and_initializers',
    'composition_excludes_scheduler_dispatch_mutation_and_transport_ownership',
    'composition_is_final_and_non_instantiable',
) as $name) {
    q11_assert(q11_contains($tests, $name), "subscription-composition test exists: {$name}");
}
q11_assert(q11_contains($tests, "array('filter', 'woocommerce_add_to_cart_validation'"), 'gateway filter registration is asserted exactly');
q11_assert(q11_contains($tests, "array('action', 'woocommerce_before_shop_loop_item_title'"), 'gateway action registration is asserted exactly');
q11_assert(q11_contains($fixture, 'function add_action('), 'unit fixture records actions');
q11_assert(q11_contains($fixture, 'function add_filter('), 'unit fixture records filters');
q11_assert(q11_contains($fixture, 'supcheckout_test_hook_calls'), 'unit fixture preserves ordered hook topology');
q11_assert(
    q11_contains($phpstan, 'includes/Subscription/Checkout/Fields.php')
    || q11_contains($stubs, 'namespace UPayments\\Subscription\\Checkout'),
    'analysis owns checkout fields boundary directly or through bounded stubs'
);
q11_assert(q11_contains($stubs, 'namespace UPayments\\Subscription'), 'analysis stubs declare subscription manager boundary');

q11_assert(q11_contains($workflow, 'quality-platform-subscription-composition-harness.php'), 'Q11 harness is mandatory in Quality Gates');
q11_assert(q11_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still always runs');
foreach (array(
    '2a03537723ec937e58337dfa3432500c2ce85728',
    'f27880f5f2a93f1dfd6428619e5bffa75e0bd4aa',
    'Quality Gates run #229',
    'e544a65130d4b009efea179038dd03275cd46897',
    'Quality Gates run #230',
    'implementation branch deleted',
) as $evidence) {
    q11_assert(q11_contains($quality, $evidence), "Q11 closure evidence is pinned: {$evidence}");
}
q11_assert(q11_contains($quality, 'Q16 is DONE / VERIFIED'), 'quality record advances beyond Q11');
q11_assert(q11_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q11_assert(q11_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q11_assert(q11_contains($playbook, 'Quality Platform Q11: DONE / VERIFIED; PR #37; merge e544a65130d4b009efea179038dd03275cd46897;'), 'playbook pins Q11 merge');
q11_assert(q11_contains($playbook, 'tree f27880f5f2a93f1dfd6428619e5bffa75e0bd4aa; Q11 84/0; post-merge Quality Gates #230 SUCCESS'), 'playbook pins Q11 tree');
q11_assert(!q11_contains($handoff, 'CURRENT / Q11'), 'handoff rejects stale current-Q11 marker');
q11_assert(!q11_contains($playbook, 'CURRENT / Q11'), 'playbook rejects stale current-Q11 marker');
q11_assert(q11_contains($workflow, "reject_across_live_records 'CURRENT / Q11'"), 'Governance rejects stale current-Q11 markers');

echo "\nQ11 Subscription Composition Analysis: {$q11_pass} PASS / {$q11_fail} FAIL\n";
exit($q11_fail === 0 ? 0 : 1);
