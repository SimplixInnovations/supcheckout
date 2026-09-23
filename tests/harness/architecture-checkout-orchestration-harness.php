<?php
/**
 * A5 checkout payload/orchestration architecture harness.
 *
 * Runs without WordPress/WooCommerce. The historical H12 harness exercises
 * the full WC_Upayments::process_payment runtime; this gate protects the new
 * ownership boundary and the pure lexical/payload contracts directly.
 */

use Simplixi\SUPCheckout\Payment\CheckoutPayload;

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

function a5_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

function a5_same($expected, $actual, $message) {
    a5_assert($expected === $actual, $message);
}

function a5_contains($source, $needle) {
    return is_string($source) && strpos($source, $needle) !== false;
}

$gatewayPath = $root . '/UPayments.php';
$payloadPath = $root . '/src/Payment/CheckoutPayload.php';
$orchestratorPath = $root . '/src/Payment/CheckoutOrchestrator.php';

a5_assert(is_file($gatewayPath), 'legacy gateway adapter exists');
a5_assert(is_file($payloadPath), 'CheckoutPayload service exists');
a5_assert(is_file($orchestratorPath), 'CheckoutOrchestrator service exists');

$gateway = file_get_contents($gatewayPath);
$payload = file_get_contents($payloadPath);
$orchestrator = file_get_contents($orchestratorPath);

a5_assert(a5_contains($payload, 'namespace Simplixi\\SUPCheckout\\Payment;'), 'payload service uses Payment namespace');
a5_assert(a5_contains($orchestrator, 'namespace Simplixi\\SUPCheckout\\Payment;'), 'orchestrator uses Payment namespace');
a5_assert(a5_contains($gateway, "require_once __DIR__ . '/src/Payment/CheckoutPayload.php';"), 'gateway loads payload service');
a5_assert(a5_contains($gateway, "require_once __DIR__ . '/src/Payment/CheckoutOrchestrator.php';"), 'gateway loads orchestrator service');
a5_assert(a5_contains($gateway, 'new CheckoutOrchestrator('), 'legacy process entry point composes orchestrator');
a5_assert(a5_contains($gateway, '))->process($order_id);'), 'legacy process entry point delegates the order ID');
a5_assert(substr_count($gateway, 'new CheckoutOrchestrator(') === 1, 'gateway has one checkout orchestrator composition point');
a5_assert(a5_contains($orchestrator, "execute_request('charge', 'POST', \$params)"), 'orchestrator owns Charge dispatch');
a5_assert(substr_count($orchestrator, "execute_request('charge', 'POST', \$params)") === 1, 'orchestrator dispatches Charge exactly once');
a5_assert(a5_contains($orchestrator, 'CustomerTokenIdentity::get_or_establish_token('), 'orchestrator owns saved-card token establishment');
a5_assert(a5_contains($orchestrator, 'CustomerTokenIdentity::validate_token_runtime_context('), 'orchestrator preserves token runtime validation');
a5_assert(a5_contains($orchestrator, 'add_meta_data("UPayments_order_id", $unique_order_id)'), 'orchestrator owns provider-order identity persistence');
a5_assert(a5_contains($orchestrator, 'CheckoutPayload::inject_amount_token_into_payload_json('), 'orchestrator delegates numeric JSON injection');
a5_assert(a5_contains($orchestrator, 'CheckoutPayload::normalize_upayments_redirect_url('), 'orchestrator delegates redirect validation');
a5_assert(!a5_contains($payload, 'execute_request('), 'pure payload service excludes provider transport');
a5_assert(!a5_contains($payload, 'execute_upayments_request'), 'pure payload service excludes gateway transport');
a5_assert(!a5_contains($payload, 'CustomerTokenIdentity'), 'pure payload service excludes token storage');
a5_assert(!a5_contains($payload, 'add_meta_data('), 'pure payload service excludes order mutation');
a5_assert(!a5_contains($payload, 'apiKey'), 'pure payload service excludes provider credentials');
a5_assert(!a5_contains($orchestrator, 'CURLOPT_'), 'orchestrator excludes direct cURL ownership');
a5_assert(!a5_contains($orchestrator, 'Scheduler::'), 'orchestrator excludes subscription scheduler ownership');
a5_assert(!a5_contains($orchestrator, 'CycleClaim::'), 'orchestrator excludes cycle-claim ownership');

require_once $root . '/src/Provider/MultiMerchantContract.php';
require_once $payloadPath;

a5_same(true, CheckoutPayload::field_present(array('x' => null), 'x'), 'field presence distinguishes explicit null');
a5_same(false, CheckoutPayload::field_present(array(), 'x'), 'field presence rejects absence');
a5_same(null, CheckoutPayload::parse_save_card_strict(null), 'save-card parser rejects null');
a5_same(false, CheckoutPayload::parse_save_card_strict('0'), 'save-card parser accepts string zero');
a5_same(true, CheckoutPayload::parse_save_card_strict(1), 'save-card parser accepts integer one');
a5_same(null, CheckoutPayload::parse_save_card_strict(true), 'save-card parser rejects booleans');
a5_same('apple-pay', CheckoutPayload::parse_payment_source_strict('apple-pay'), 'source parser preserves canonical source');
a5_same(null, CheckoutPayload::parse_payment_source_strict(' apple-pay'), 'source parser rejects leading whitespace');
a5_same(null, CheckoutPayload::parse_payment_source_strict(array('cc')), 'source parser rejects arrays');
a5_same(true, CheckoutPayload::is_valid_payment_source('cc'), 'source allowlist accepts credit card');
a5_same(false, CheckoutPayload::is_valid_payment_source('create-invoice'), 'source allowlist rejects unsupported invoice source');

a5_same(-1, CheckoutPayload::compare_nonnegative_decimal_strings('9.99', '10'), 'decimal comparison handles integer width');
a5_same(0, CheckoutPayload::compare_nonnegative_decimal_strings('1.0', '1.000'), 'decimal comparison ignores trailing fractional zeros');
a5_same(1, CheckoutPayload::compare_nonnegative_decimal_strings('1.01', '1.001'), 'decimal comparison preserves fractional ordering');
a5_same('0.125', CheckoutPayload::build_amount_json_token('0.125'), 'amount token accepts positive sub-unit');
a5_same(null, CheckoutPayload::build_amount_json_token('0.00'), 'amount token rejects zero');
a5_same(null, CheckoutPayload::build_amount_json_token('01.00'), 'amount token rejects leading zero ambiguity');
a5_same(null, CheckoutPayload::build_amount_json_token('1e3'), 'amount token rejects exponent notation');
a5_same('0.125', CheckoutPayload::compute_provider_unit_price_decimal('1.00', 8), 'unit-price division remains exact');
a5_same(null, CheckoutPayload::compute_provider_unit_price_decimal('10.00', 3), 'unit-price division fails closed when non-terminating');
a5_same('0', CheckoutPayload::compute_provider_unit_price_decimal('0.00', 5), 'zero-price product line remains representable');
a5_same(null, CheckoutPayload::compute_provider_unit_price_decimal(1.0, 2), 'unit-price input rejects binary float economics');

$sentinel = '__UPAY_ORDER_AMOUNT_SENTINEL__';
$encoded = '{"order":{"amount":"' . $sentinel . '"}}';
$injected = CheckoutPayload::inject_amount_token_into_payload_json($encoded, array($sentinel => '10.500'));
a5_same('{"order":{"amount":10.500}}', $injected, 'amount injection emits an unquoted JSON number');
a5_same(null, CheckoutPayload::inject_amount_token_into_payload_json('{"order":{}}', array($sentinel => '10.500')), 'amount injection requires its sentinel exactly once');
a5_same(null, CheckoutPayload::inject_amount_token_into_payload_json('{"a":"' . $sentinel . '","b":"' . $sentinel . '"}', array($sentinel => '1')), 'amount injection rejects duplicate sentinels');
a5_same(22, CheckoutPayload::get_max_length_for_sentinel($sentinel), 'order amount sentinel retains provider ceiling');

a5_same('monthly', CheckoutPayload::parse_subscription_plan_strict('monthly'), 'subscription plan parser accepts canonical string');
a5_same(null, CheckoutPayload::parse_subscription_plan_strict(' monthly'), 'subscription plan parser rejects whitespace');
a5_same(3, CheckoutPayload::parse_interval('3'), 'subscription interval parser accepts exact string integer');
a5_same(-1, CheckoutPayload::parse_interval(4), 'subscription interval parser rejects out-of-range integer');
a5_same(true, CheckoutPayload::is_valid_subscription_interval('quarterly', 3), 'subscription interval allowlist accepts quarterly three');
a5_same(false, CheckoutPayload::is_valid_subscription_interval('monthly', 3), 'subscription interval allowlist rejects monthly three');

a5_same('/wc/store/v1/checkout', CheckoutPayload::normalize_store_api_route('/shop/wp-json/wc/store/v1/checkout'), 'Store API route strips subdirectory wp-json prefix');
a5_same('/wc/store/v1/checkout', CheckoutPayload::normalize_store_api_route('/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout'), 'Store API route supports plain permalinks');
a5_same(true, CheckoutPayload::classify_checkout_request_context(true, '/wc/store/v1/checkout/', 'post'), 'request classifier accepts exact checkout POST');
a5_same(false, CheckoutPayload::classify_checkout_request_context(true, '/wc/store/v1/cart', 'POST'), 'request classifier rejects other Store API endpoints');
a5_same(false, CheckoutPayload::classify_checkout_request_context(false, '/wc/store/v1/checkout', 'POST'), 'request classifier requires REST context');

a5_same('https://pay.example/redirect', CheckoutPayload::normalize_upayments_redirect_url(' https://pay.example/redirect '), 'redirect validator trims valid HTTPS URL');
a5_same('http://sandboxapi.upayments.com/get-pay-by-apple', CheckoutPayload::normalize_upayments_redirect_url('http://sandboxapi.upayments.com/get-pay-by-apple'), 'redirect validator preserves documented UPayments HTTP sandbox links');
a5_same(null, CheckoutPayload::normalize_upayments_redirect_url('https://user@pay.example/redirect'), 'redirect validator rejects URL username authority');
a5_same(null, CheckoutPayload::normalize_upayments_redirect_url('https://user:pass@pay.example/redirect'), 'redirect validator rejects URL username/password authority');
a5_same('https://pay.example/redirect?email=user@example.com', CheckoutPayload::normalize_upayments_redirect_url('https://pay.example/redirect?email=user@example.com'), 'redirect validator does not over-reject at-sign outside URL authority');
a5_same(null, CheckoutPayload::normalize_upayments_redirect_url('javascript:alert(1)'), 'redirect validator rejects non-HTTP scheme');
a5_same(null, CheckoutPayload::normalize_upayments_redirect_url("https://pay.example/ok\r\nX-Test: bad"), 'redirect validator rejects CRLF injection');
a5_same('abc', CheckoutPayload::truncate_provider_text('abcdef', 3), 'provider text truncation obeys character ceiling');
a5_same('', CheckoutPayload::truncate_provider_text(array('x'), 3), 'provider text truncation rejects non-scalars');

echo "\nA5 Checkout Orchestration: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
