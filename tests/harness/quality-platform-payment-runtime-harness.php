<?php

$pass = 0;
$fail = 0;
$root = dirname(__DIR__, 2);

function q17_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: " . $label . "\n";
        return;
    }
    ++$fail;
    echo "FAIL: " . $label . "\n";
}

function q17_read($root, $relative) {
    $value = file_get_contents($root . '/' . $relative);
    q17_assert(is_string($value), 'source readable: ' . $relative);
    return is_string($value) ? $value : '';
}

function q17_has($source, $needle) {
    return strpos($source, $needle) !== false;
}

function q17_blob($path) {
    $bytes = file_get_contents($path);
    return is_string($bytes) ? sha1('blob ' . strlen($bytes) . "\0" . $bytes) : '';
}

$phpstan = q17_read($root, 'phpstan.neon.dist');
$phpcs = q17_read($root, 'phpcs.xml.dist');
$checkout = q17_read($root, 'src/Payment/CheckoutOrchestrator.php');
$lifecycle = q17_read($root, 'src/Payment/PaymentLifecycle.php');
$multi_merchant_contract = q17_read($root, 'src/Provider/MultiMerchantContract.php');
$checkout_tests = q17_read($root, 'tests/unit/Payment/CheckoutOrchestratorTest.php');
$lifecycle_tests = q17_read($root, 'tests/unit/Payment/PaymentLifecycleTest.php');
$runtime_fixture = q17_read($root, 'tests/support/wordpress-payment-runtime.php');
$runtime_stubs = q17_read($root, 'tests/phpstan/payment-runtime-stubs.php');
$bootstrap = q17_read($root, 'tests/bootstrap.php');
$workflow = q17_read($root, '.github/workflows/quality-gates.yml');
$quality = q17_read($root, 'docs/project/QUALITY-PLATFORM.md');
$status = q17_read($root, 'docs/project/PROJECT-STATUS.md');
$readme = q17_read($root, 'README.md');
$agents = q17_read($root, 'AGENTS.md');
$playbook = q17_read($root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');
$audit = q17_read($root, 'docs/project/REPOSITORY-AUDIT.md');
$handoff = q17_read($root, 'docs/project/NEW-CHAT-HANDOFF.md');

foreach (array('src/Payment/CheckoutOrchestrator.php', 'src/Payment/PaymentLifecycle.php') as $path) {
    q17_assert(q17_has($phpstan, $path), 'PHPStan owns payment runtime: ' . $path);
    q17_assert(q17_has($phpcs, $path), 'PHPCS owns payment runtime: ' . $path);
}
q17_assert(q17_has($phpstan, 'tests/phpstan/payment-runtime-stubs.php'), 'PHPStan loads bounded payment-runtime stubs');
q17_assert(!q17_has($phpstan, 'baseline'), 'Q17 remains baseline-free');
q17_assert(!q17_has($phpstan, 'ignoreErrors'), 'Q17 has no ignored analyzer errors');

q17_assert(q17_has($checkout, "preg_match('/^[1-9][0-9]*\\\\z/', \$value)"), 'checkout order IDs use absolute end anchor');
q17_assert(q17_has($checkout, "preg_match('/^[A-Z]{3}\\\\z/', \$currency)"), 'provider currency uses absolute end anchor');
q17_assert(
    q17_has($checkout, 'MultiMerchantContract::is_valid_iban($iban)')
    && q17_has($multi_merchant_contract, 'public static function is_valid_iban($value)')
    && q17_has($multi_merchant_contract, '[A-Z0-9]{11,30}\\z/'),
    'provider IBAN delegates to strict absolute-end shared contract'
);
q17_assert(q17_has($checkout, 'CheckoutPayload::build_amount_json_token($amount_str)'), 'order amount retains exact JSON-number validator');
q17_assert(q17_has($checkout, '$unique_order_id = md5(wp_generate_uuid4());'), 'Charge attempt identity uses fresh WordPress UUID entropy');
q17_assert(!q17_has($checkout, '$order_id * time()'), 'Charge attempt identity is not second-bound');
q17_assert(q17_has($checkout, 'CustomerTokenIdentity::clear_stale_attempt_metadata($order)'), 'new Charge attempt clears stale attempt state');
q17_assert(
    q17_has($checkout, 'delete_meta_data("UPayments_order_id")')
    && q17_has($checkout, 'add_meta_data("UPayments_order_id", $unique_order_id)'),
    'successful Charge rotates provider order identity'
);
q17_assert(q17_has($checkout, 'Nonce ownership is upstream in Woo checkout'), 'Classic checkout nonce ownership is documented narrowly');
q17_assert(!q17_has($bootstrap, "support/wordpress-payment-runtime.php"), 'heavy payment-runtime fixture stays isolated from global PHPUnit bootstrap');

foreach (array(
    'test_process_rejects_noncanonical_order_ids_before_woo_lookup',
    'test_process_keeps_positive_integer_order_ids_compatible',
    'test_same_second_retries_use_distinct_provider_order_ids',
    'test_currency_with_terminal_newline_is_rejected_before_provider_request',
    'test_iban_with_terminal_newline_is_rejected_before_provider_request',
    'test_multimerchant_charge_newline_already_fails_closed_before_provider_request'
) as $name) {
    q17_assert(q17_has($checkout_tests, $name), 'checkout runtime test: ' . $name);
}
foreach (array(
    'test_reconcile_rejects_noncanonical_order_ids_before_woo_lookup',
    'test_reconcile_keeps_positive_integer_order_ids_compatible',
    'test_failed_payment_complete_postcondition_does_not_leave_durable_capture_metadata',
    'test_throwing_payment_complete_restores_prior_durable_capture_metadata',
    'test_request_merge_is_presence_aware_and_conflict_safe'
) as $name) {
    q17_assert(q17_has($lifecycle_tests, $name), 'lifecycle runtime test: ' . $name);
}
q17_assert(
    q17_has($checkout_tests, 'RunTestsInSeparateProcesses')
    && q17_has($lifecycle_tests, 'RunTestsInSeparateProcesses'),
    'runtime characterization remains process isolated'
);
q17_assert(
    q17_has($runtime_fixture, 'SUPCheckout_Test_Payment_Runtime_WC')
    && q17_has($checkout_tests, '$requests = array();'),
    'runtime fixture exposes deterministic Woo/provider observation'
);
q17_assert(q17_has($runtime_stubs, 'function wc_get_checkout_url'), 'payment-runtime stubs model checkout URL boundary');

q17_assert(q17_has($lifecycle, "preg_match('/^[1-9][0-9]*\\\\z/', \$value)"), 'lifecycle order IDs use absolute end anchor');
q17_assert(q17_has($lifecycle, 'merge_request_value('), 'callback preserves presence-aware GET/POST merge');
q17_assert(
    q17_has($lifecycle, 'OrderLock::acquire($order_id)')
    && q17_has($lifecycle, 'OrderLock::release($order_id, $lock_token)'),
    'payment truth remains lock bounded'
);
q17_assert(q17_has($lifecycle, 'StatusVerifier::bind_transaction('), 'locked path rebinds authenticated provider status');
q17_assert(q17_has($lifecycle, "private const TRUSTED_TRACK_META = '_simplixpay_upayments_status_track_v1';"), 'trusted track identity remains exact');
q17_assert(q17_has($lifecycle, "private const TRUSTED_REQUESTED_META = '_simplixpay_upayments_status_requested_v1';"), 'trusted requested identity remains exact');
q17_assert(q17_has($lifecycle, "private const UNVERIFIED_TRACK_META = '_simplixpay_upayments_unverified_track_v1';"), 'unverified track identity remains exact');
q17_assert(q17_has($lifecycle, "private const UNVERIFIED_REQUESTED_META = '_simplixpay_upayments_unverified_requested_v1';"), 'unverified requested identity remains exact');
q17_assert(q17_has($lifecycle, 'private const MAX_RECONCILE_ATTEMPTS = 4;'), 'reconciliation cap remains exact');
q17_assert(
    q17_has($lifecycle, 'reset_attempt_cursor_state($fresh_order)')
    && q17_has($lifecycle, 'reset_attempt_cursor_state($order)'),
    'stale/new Charge attempts reset cursor state'
);
q17_assert(q17_has($lifecycle, 'if (self::is_refunded($order) || self::is_verified_capture($order))'), 'captured path blocks refunded/verified resurrection');
q17_assert(q17_has($lifecycle, "self::log('transaction_id_conflict', 'warning')"), 'captured path fails closed on transaction conflict');
q17_assert(q17_has($lifecycle, '$order->payment_complete($payment_id)'), 'captured path keeps canonical Woo payment completion');
q17_assert(q17_has($lifecycle, "self::log('payment_complete_postcondition_failed', 'warning')"), 'capture requires payment-complete postcondition');
$payment_complete_position = strpos($lifecycle, '$order->payment_complete($payment_id)');
$legacy_capture_stage_position = strpos($lifecycle, "'UPayments_Result' => 'CAPTURED'");
$postcondition_position = strpos($lifecycle, "self::log('payment_complete_postcondition_failed', 'warning')");
$verified_capture_position = strpos($lifecycle, "\$order->update_meta_data('_upay_verified_capture', 1)");
q17_assert(
    $payment_complete_position !== false
    && $legacy_capture_stage_position !== false
    && $legacy_capture_stage_position < $payment_complete_position,
    'legacy provider capture metadata is staged before Woo payment-completion hooks'
);
q17_assert(
    $postcondition_position !== false
    && $verified_capture_position !== false
    && $verified_capture_position > $postcondition_position,
    'Simplix verified-capture truth remains gated by Woo paid-state postcondition'
);
q17_assert(
    q17_has($lifecycle, '$legacy_capture_snapshot')
    && q17_has($lifecycle, '$order->delete_meta_data($key)')
    && q17_has($lifecycle, '$order->update_meta_data($key, $snapshot[\'value\'])'),
    'failed or throwing payment completion restores staged legacy capture metadata'
);
q17_assert(
    q17_has($lifecycle, 'Provider callbacks cannot carry a WordPress nonce; authority comes only from authenticated status binding.'),
    'callback nonce exception documents provider-auth authority'
);

q17_assert(
    q17_blob($root . '/includes/Subscription/Cron/Scheduler.php') === '9730d28ed2ab63a2b182e138082e3d28f82e822c',
    'protected Scheduler blob remains exact'
);
q17_assert(
    q17_blob($root . '/includes/Subscription/Cron/CycleClaim.php') === '0ae25b176ce622f0500fb279a40ab60275a72705',
    'protected CycleClaim reviewed blob remains exact after WordPress.org SQL/warning hardening'
);

foreach (array(
    'provider-payment-lifecycle-harness.php',
    'provider-payment-amount-binding-harness.php',
    'architecture-checkout-orchestration-harness.php',
    'quality-platform-migration-core-harness.php'
) as $name) {
    q17_assert(q17_has($workflow, 'tests/harness/' . $name), 'historical runtime regression remains mandatory: ' . $name);
}
q17_assert(q17_has($workflow, 'tests/harness/quality-platform-payment-runtime-harness.php'), 'Q17 harness is mandatory');
q17_assert(q17_has($workflow, 'if: ${{ always() }}'), 'H12 aggregator always runs');
q17_assert(q17_has($agents, 'Quality Platform Q1-Q19 harnesses;'), 'AGENTS keeps the closed Quality Platform harness set mandatory');

foreach (array(
    '2c5d8e9213086c88147f5d1d26247d58f1cbc81b',
    '4dae7ad7db04fcd1466389d304e661ac0666983f',
    'Quality Gates run #414',
    '172 tests / 1053 assertions',
    'Q17 **97/0**',
    'CodeQL PR scan #194',
    '570dbf3501b359b16767d070d18c25a67a0c24fe',
    'Quality Gates run #415',
    'main security run #195',
    'implementation branch deleted'
) as $evidence) {
    q17_assert(q17_has($quality, $evidence), 'Q17 closure evidence pinned: ' . $evidence);
}

q17_assert(q17_has($quality, '## Closed Q17 contract') && q17_has($quality, '**Status:** DONE / VERIFIED (Q1-Q19)'), 'quality record closes Q17 and advances into closed Q1-Q19 state');
q17_assert(
    q17_has($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'),
    'project status records the closed Quality Platform Q1-Q19'
);
q17_assert(
    q17_has($status, '| Final pre-clone runtime/QA closure | **DONE / VERIFIED — PR #75** |'),
    'project status advances beyond historical Q17 into the certified pre-clone runtime closure'
);
q17_assert(
    q17_has($readme, 'docs/project/PROJECT-STATUS.md'),
    'README delegates current engineering state to project status'
);
q17_assert(q17_has($playbook, '**Q17 / DONE / VERIFIED — PAYMENT-RUNTIME CLOSEOUT**') && q17_has($playbook, '7. Enterprise Compatibility Certification — **CURRENT**.'), 'playbook closes Q17 and advances into named certification');
q17_assert(!q17_has($playbook, '**Q17 / PLANNED PAYMENT-RUNTIME CLOSEOUT**'), 'playbook removes stale planned-Q17 marker');
q17_assert(!q17_has($playbook, 'current program gate is **Full Automated Quality Platform — Q16**'), 'playbook removes lowercase stale Q16 current-gate marker');
q17_assert(q17_has($audit, 'new enterprise-critical evidence independently demonstrates another bounded risk'), 'repository audit preserves bounded post-Q17 extension policy');
q17_assert(q17_has($handoff, '- Quality Platform Q1-Q19 — **DONE / VERIFIED; permanently closed at Q19**'), 'handoff records the closed Quality Platform');
q17_assert(q17_has($handoff, '- Enterprise Tasks 1-8 — **DONE / VERIFIED**'), 'handoff records the historical enterprise task closure');

echo "\nQ17 Payment Runtime Analysis: " . $pass . " PASS / " . $fail . " FAIL\n";
exit($fail === 0 ? 0 : 1);
