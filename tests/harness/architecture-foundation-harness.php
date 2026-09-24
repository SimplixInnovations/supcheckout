<?php
/**
 * Architecture & Code-Quality Foundation characterization harness.
 *
 * Static-only by design: it must run without WordPress/WooCommerce bootstrap.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function arch_assert($condition, $message)
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }

    $fail++;
    echo "FAIL: {$message}\n";
}

function arch_read($root, $path)
{
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        return '';
    }
    $contents = file_get_contents($full);
    return is_string($contents) ? $contents : '';
}

function arch_contains($haystack, $needle)
{
    return is_string($haystack) && strpos($haystack, $needle) !== false;
}

function arch_php_string_literal_value($literal)
{
    if (!is_string($literal) || strlen($literal) < 2) {
        return null;
    }

    $quote = $literal[0];
    if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
        return null;
    }

    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(array('\\\\', "\\'"), array('\\', "'"), $body);
    }

    return stripcslashes($body);
}

/**
 * Normalize executable PHP tokens only. Comments/docblocks/whitespace/open tags
 * are discarded and quoted strings remain atomic values.
 */
function arch_executable_tokens($source)
{
    if (!is_string($source) || $source === '') {
        return array();
    }

    $normalized = array();
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $normalized[] = $token;
            continue;
        }

        $id = $token[0];
        $text = $token[1];
        if ($id === T_WHITESPACE
            || $id === T_COMMENT
            || $id === T_DOC_COMMENT
            || $id === T_OPEN_TAG
            || $id === T_CLOSE_TAG
        ) {
            continue;
        }

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $value = arch_php_string_literal_value($text);
            $normalized[] = $value === null ? 'string:INVALID' : 'string:' . $value;
            continue;
        }

        if ($id === T_VARIABLE) {
            $normalized[] = 'variable:' . $text;
            continue;
        }

        if ($id === T_STRING) {
            $normalized[] = 'name:' . $text;
            continue;
        }

        $normalized[] = $text;
    }

    return $normalized;
}

function arch_extract_braced_body(array $tokens, $openBraceIndex)
{
    $depth = 1;
    $body = array();
    $tokenCount = count($tokens);

    for ($i = $openBraceIndex + 1; $i < $tokenCount; $i++) {
        if ($tokens[$i] === '{') {
            $depth++;
        } elseif ($tokens[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return $body;
            }
        }
        $body[] = $tokens[$i];
    }

    return array();
}

function arch_class_body_tokens(array $tokens, $className)
{
    $tokenCount = count($tokens);
    $nameToken = 'name:' . $className;

    for ($i = 0; $i < $tokenCount - 1; $i++) {
        if ($tokens[$i] !== 'class' || $tokens[$i + 1] !== $nameToken) {
            continue;
        }

        for ($j = $i + 2; $j < $tokenCount; $j++) {
            if ($tokens[$j] === '{') {
                return arch_extract_braced_body($tokens, $j);
            }
        }
    }

    return array();
}

/**
 * Remove nested callable bodies from an owning function/method body while
 * retaining ordinary control-flow blocks.
 */
function arch_without_nested_callables(array $tokens)
{
    $result = array();
    $tokenCount = count($tokens);

    for ($i = 0; $i < $tokenCount; $i++) {
        if ($tokens[$i] === 'function') {
            $j = $i + 1;
            while ($j < $tokenCount && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }
            if ($j < $tokenCount && $tokens[$j] === '{') {
                $depth = 1;
                $j++;
                while ($j < $tokenCount && $depth > 0) {
                    if ($tokens[$j] === '{') {
                        $depth++;
                    } elseif ($tokens[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }
            } elseif ($j < $tokenCount) {
                $j++;
            }
            $i = $j - 1;
            continue;
        }

        if ($tokens[$i] === 'fn') {
            $j = $i + 1;
            $paren = 0;
            $bracket = 0;
            while ($j < $tokenCount) {
                if ($tokens[$j] === '(') {
                    $paren++;
                } elseif ($tokens[$j] === ')' && $paren > 0) {
                    $paren--;
                } elseif ($tokens[$j] === '[') {
                    $bracket++;
                } elseif ($tokens[$j] === ']' && $bracket > 0) {
                    $bracket--;
                } elseif (($tokens[$j] === ';' || $tokens[$j] === ',') && $paren === 0 && $bracket === 0) {
                    break;
                }
                $j++;
            }
            $i = $j;
            continue;
        }

        $result[] = $tokens[$i];
    }

    return $result;
}

/**
 * Resolve an actual direct public method of a class body.
 */
function arch_direct_public_method(array $classTokens, $methodName)
{
    $tokenCount = count($classTokens);
    $nameToken = 'name:' . $methodName;
    $depth = 0;

    for ($i = 0; $i < $tokenCount - 1; $i++) {
        if ($classTokens[$i] === '{') {
            $depth++;
            continue;
        }
        if ($classTokens[$i] === '}') {
            if ($depth > 0) {
                $depth--;
            }
            continue;
        }
        if ($depth !== 0 || $classTokens[$i] !== 'function' || $classTokens[$i + 1] !== $nameToken) {
            continue;
        }

        $isPublic = false;
        for ($p = $i - 1; $p >= 0; $p--) {
            if ($classTokens[$p] === ';' || $classTokens[$p] === '{' || $classTokens[$p] === '}') {
                break;
            }
            if ($classTokens[$p] === 'public') {
                $isPublic = true;
            }
            if ($classTokens[$p] === 'private' || $classTokens[$p] === 'protected') {
                $isPublic = false;
                break;
            }
        }
        if (!$isPublic) {
            return array('found' => false, 'body' => array());
        }

        for ($j = $i + 2; $j < $tokenCount; $j++) {
            if ($classTokens[$j] === ';') {
                return array('found' => true, 'body' => array());
            }
            if ($classTokens[$j] === '{') {
                return array(
                    'found' => true,
                    'body' => arch_without_nested_callables(arch_extract_braced_body($classTokens, $j)),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch_has_token_sequence(array $tokens, array $sequence)
{
    $sequenceCount = count($sequence);
    $tokenCount = count($tokens);
    if ($sequenceCount === 0 || $tokenCount < $sequenceCount) {
        return false;
    }

    $limit = $tokenCount - $sequenceCount;
    for ($i = 0; $i <= $limit; $i++) {
        $matched = true;
        for ($j = 0; $j < $sequenceCount; $j++) {
            if ($tokens[$i + $j] !== $sequence[$j]) {
                $matched = false;
                break;
            }
        }
        if ($matched) {
            return true;
        }
    }

    return false;
}

/**
 * Resolve a filter registration and callback as one direct top-level pair.
 * The pair cannot be hidden behind braced, alternative-syntax, or brace-less
 * conditional/control-flow statements.
 */
function arch_direct_top_level_filter_callback(array $tokens, array $filterSequence, $functionName)
{
    $tokenCount = count($tokens);
    $sequenceCount = count($filterSequence);
    $nameToken = 'name:' . $functionName;
    $depth = 0;

    for ($i = 0; $i < $tokenCount; $i++) {
        if ($tokens[$i] === '{') {
            $depth++;
            continue;
        }
        if ($tokens[$i] === '}') {
            if ($depth > 0) {
                $depth--;
            }
            continue;
        }
        if ($depth !== 0 || $i + $sequenceCount > $tokenCount) {
            continue;
        }
        if (array_slice($tokens, $i, $sequenceCount) !== $filterSequence) {
            continue;
        }

        $previous = $i > 0 ? $tokens[$i - 1] : null;
        if ($previous !== null && $previous !== ';' && $previous !== '}') {
            continue;
        }

        $functionIndex = $i + $sequenceCount;
        if (!isset($tokens[$functionIndex], $tokens[$functionIndex + 1])
            || $tokens[$functionIndex] !== 'function'
            || $tokens[$functionIndex + 1] !== $nameToken
        ) {
            continue;
        }

        for ($j = $functionIndex + 2; $j < $tokenCount; $j++) {
            if ($tokens[$j] === ';') {
                return array('found' => false, 'body' => array());
            }
            if ($tokens[$j] === '{') {
                return array(
                    'found' => true,
                    'body' => arch_without_nested_callables(arch_extract_braced_body($tokens, $j)),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

$gatewayIdSequence = array('variable:$this', '->', 'name:id', '=', 'string:upayments', ';');
$callbackWithTrailingComma = array(
    'name:add_action', '(', 'string:woocommerce_api_', '.', 'name:strtolower', '(', 'string:WC_UPayments', ')', ',',
    '[', 'variable:$this', ',', 'string:check_ipn_response', ',', ']', ')', ';',
);
$callbackWithoutTrailingComma = array(
    'name:add_action', '(', 'string:woocommerce_api_', '.', 'name:strtolower', '(', 'string:WC_UPayments', ')', ',',
    '[', 'variable:$this', ',', 'string:check_ipn_response', ']', ')', ';',
);
$settingsReadSequence = array(
    'variable:$settings', '=', 'name:get_option', '(', 'string:woocommerce_upayments_settings', ')', ';',
);
$availabilityDelegateSequence = array(
    'return', 'name:Availability', '::', 'name:filter', '(', 'variable:$available_gateways', ')', ';',
);
$runtimeEligibilitySequence = array(
    'name:GatewaySettings', '::', 'name:is_runtime_eligible', '(',
    'variable:$settings', ',', 'name:get_woocommerce_currency', '(', ')', ')',
);
$sessionGuardSequence = array(
    'variable:$wc', '&&', 'variable:$wc', '->', 'name:session',
);
$orderIdWriteSequence = array(
    'variable:$order', '->', 'name:add_meta_data', '(', 'string:UPayments_order_id', ',',
    'variable:$unique_order_id', ')', ';',
);
$availabilityFilterSequence = array(
    'name:add_filter', '(', 'string:woocommerce_available_payment_gateways', ',',
    'string:enableUpaymentsGateway', ')', ';',
);

$architecture = arch_read($root, 'docs/project/ARCHITECTURE-CODE-QUALITY.md');
$gateway = arch_read($root, 'UPayments.php');
$status = arch_read($root, 'docs/project/PROJECT-STATUS.md');
$naming = arch_read($root, 'docs/project/NAMING-IDENTITY-STANDARD.md');
$paymentLifecycle = arch_read($root, 'src/Payment/PaymentLifecycle.php');
$securityStatus = arch_read($root, 'src/Security/PublicOrderStatus.php');
$tokenIdentity = arch_read($root, 'includes/Token/CustomerTokenIdentity.php');
$scheduler = arch_read($root, 'includes/Subscription/Cron/Scheduler.php');
$subscriptionComposition = arch_read($root, 'src/Subscription/Composition.php');
$subscriptionPresentation = arch_read($root, 'src/Subscription/Presentation.php');
$checkoutPayload = arch_read($root, 'src/Payment/CheckoutPayload.php');
$checkoutOrchestrator = arch_read($root, 'src/Payment/CheckoutOrchestrator.php');
$gatewayAvailability = arch_read($root, 'src/Gateway/Availability.php');
$gatewayOrderPresentation = arch_read($root, 'src/Gateway/OrderPresentation.php');
$multiMerchantContract = arch_read($root, 'src/Provider/MultiMerchantContract.php');
$gatewayTokens = arch_executable_tokens($gateway);
$gatewayClassTokens = arch_class_body_tokens($gatewayTokens, 'WC_Upayments');
$checkoutOrchestratorTokens = arch_executable_tokens($checkoutOrchestrator);
$checkoutOrchestratorClassTokens = arch_class_body_tokens($checkoutOrchestratorTokens, 'CheckoutOrchestrator');
$checkoutProcess = arch_direct_public_method($checkoutOrchestratorClassTokens, 'process');
$gatewayAvailabilityTokens = arch_executable_tokens($gatewayAvailability);
$gatewayAvailabilityClassTokens = arch_class_body_tokens($gatewayAvailabilityTokens, 'Availability');
$gatewayAvailabilityFilter = arch_direct_public_method($gatewayAvailabilityClassTokens, 'filter');
$availabilityBinding = arch_direct_top_level_filter_callback(
    $gatewayTokens,
    $availabilityFilterSequence,
    'enableUpaymentsGateway'
);

arch_assert($architecture !== '', 'architecture control record exists');
arch_assert(arch_contains($architecture, '**Status:** DONE / VERIFIED (DISCOVERY + A1-A5)'), 'architecture record closes discovery and A1-A5');
arch_assert(arch_contains($architecture, 'Architecture & Code-Quality Foundation'), 'architecture gate is named explicitly');

$stageHeadings = array(
    'A1' => '### A1 — provider endpoint/mode resolution — first safe runtime seam',
    'A2' => '### A2 — payment-method availability client/cache',
    'A3' => '### A3 — gateway settings/admin/multi-merchant presentation',
    'A4' => '### A4 — subscription product/account presentation',
    'A5' => '### A5 — checkout payload/orchestration core',
);
$stagePositions = array();
foreach ($stageHeadings as $stage => $heading) {
    $position = strpos($architecture, $heading);
    $stagePositions[$stage] = $position;
    arch_assert($position !== false, "{$stage} extraction stage is present");
}
$ordered = true;
$previous = -1;
foreach ($stagePositions as $position) {
    if ($position === false || $position <= $previous) {
        $ordered = false;
        break;
    }
    $previous = $position;
}
arch_assert($ordered, 'A1-A5 extraction stages remain in frozen order');

arch_assert(arch_contains($architecture, 'no production runtime behavior changes in the discovery PR'), 'discovery tranche forbids runtime behavior changes');
arch_assert(arch_contains($architecture, 'This is not permission for a big-bang rewrite'), 'big-bang rewrite is prohibited');
arch_assert(arch_contains($architecture, 'exact accepted `UPayments.php` byte size for the current architecture milestone'), 'monolith ratchet update contract is explicit');
arch_assert(arch_contains($architecture, 'Composer only with an explicit distribution rule'), 'Composer introduction is gated by distribution contract');
arch_assert(arch_contains($architecture, 'PHPCS/WPCS and PHPStan incrementally'), 'static-analysis rollout is incremental');
arch_assert(
    arch_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'),
    'project status preserves verified Quality Platform progression through Q19'
);
arch_assert(arch_contains($naming, '**Canonical slug:** `supcheckout`'), 'canonical slug remains protected');

$gatewayPath = $root . '/UPayments.php';
$gatewaySize = is_file($gatewayPath) ? filesize($gatewayPath) : false;
// Approach 3 T2 intentionally reduces the gateway shell by consolidating the
// historical priority-10 check_ipn_response fallback onto PaymentLifecycle.
// The frozen Approach 2/T1 87995-byte coordinate remains historical evidence.
// Post-T3 E1 extracts the historical available-gateways policy to
// src/Gateway/Availability.php while retaining the global callback as a thin adapter.
// R1 then extracts gateway-specific order-total presentation without expanding
// the compatibility shell.
// R2 adds callback-cache emission and the explicit WC-API URL platform resolver
// while keeping the compatibility adapter bounded.
// R3 adds explicit Subscription safety requires (card authority, economics, verifier).
$acceptedGatewayBytes = 63064;
arch_assert(is_int($gatewaySize) && $gatewaySize === $acceptedGatewayBytes, 'UPayments.php matches current exact architecture ratchet');
arch_assert($gatewayClassTokens !== array(), 'legacy WC_Upayments gateway compatibility class remains executable');
arch_assert(arch_contains($gateway, "add_filter(\"woocommerce_payment_gateways\", \"addUpaymentsGatewayClass\")"), 'WooCommerce gateway registration remains characterized');

$publicMethods = array(
    'process_payment' => 'process_payment compatibility entry point remains public',
    'process_admin_options' => 'gateway settings save entry point remains public',
    'add_order_item_totals' => 'order-total compatibility entry point remains public',
    'payment_fields' => 'classic checkout payment_fields entry point remains public',
    'return_from_upayments' => 'browser return compatibility entry point remains public',
    'web_hook_handler' => 'legacy webhook compatibility entry point remains public',
    'check_ipn_response' => 'wc_upayments callback dispatcher remains public',
    'get_payment_staus' => 'historical public status-poll method remains as compatibility wrapper',
    'getAPIUrl' => 'public generic provider URL helper remains callable',
    'getAPIUrlForCreateToken' => 'public token endpoint helper remains callable',
    'getAPIUrlForCheckPaymentButtonStatus' => 'public payment-button endpoint helper remains callable',
    'getAPIUrlForRetreiveCards' => 'public saved-card endpoint helper remains callable',
    'getUpayPaymentMethods' => 'payment-method discovery responsibility remains public',
    'getSavedCards' => 'saved-card discovery responsibility remains public',
    'initializeSubscriptionModule' => 'subscription composition entry remains public',
    'generate_multimerchant_repeater_html' => 'multi-merchant admin responsibility remains public',
);
$resolvedPublicMethods = array();
foreach ($publicMethods as $methodName => $message) {
    $resolvedPublicMethods[$methodName] = arch_direct_public_method($gatewayClassTokens, $methodName);
    arch_assert($resolvedPublicMethods[$methodName]['found'], $message);
}

arch_assert(arch_contains($gateway, '\\Simplixi\\SUPCheckout\\Security\\PublicOrderStatus::handle();'), 'public status polling delegates to Security boundary');
arch_assert(arch_contains($subscriptionComposition, "add_action('woocommerce_process_product_meta', 'saveCustomFieldData')"), 'subscription product-meta hook retains legacy callback identity through A4 composition');
arch_assert(is_file($root . '/src/Release/Identity.php'), 'Release module exists');
arch_assert(is_dir($root . '/src/Migration'), 'Migration module exists');
arch_assert(is_dir($root . '/src/Payment'), 'Payment module exists');
arch_assert(is_dir($root . '/src/Security'), 'Security module exists');
arch_assert(is_file($root . '/src/Admin/GatewaySettings.php'), 'Admin GatewaySettings boundary exists');
arch_assert(is_file($root . '/src/Gateway/Availability.php'), 'E1 Gateway Availability boundary exists');
arch_assert(arch_contains($gatewayAvailability, 'namespace Simplixi\\SUPCheckout\\Gateway;'), 'E1 Gateway Availability uses SUPCheckout Gateway namespace');
arch_assert(is_file($root . '/src/Gateway/OrderPresentation.php'), 'R1 Gateway OrderPresentation boundary exists');
arch_assert(arch_contains($gatewayOrderPresentation, 'namespace Simplixi\\SUPCheckout\\Gateway;'), 'R1 Gateway OrderPresentation uses SUPCheckout Gateway namespace');
arch_assert(is_file($root . '/src/Provider/MultiMerchantContract.php'), 'R1 Provider MultiMerchantContract boundary exists');
arch_assert(arch_contains($multiMerchantContract, 'namespace Simplixi\\SUPCheckout\\Provider;'), 'R1 Provider MultiMerchantContract uses SUPCheckout Provider namespace');
arch_assert(is_file($root . '/src/Subscription/Composition.php'), 'Subscription Composition boundary exists');
arch_assert(is_file($root . '/src/Subscription/Presentation.php'), 'Subscription Presentation boundary exists');
arch_assert(is_file($root . '/src/Payment/CheckoutPayload.php'), 'A5 CheckoutPayload boundary exists');
arch_assert(is_file($root . '/src/Payment/CheckoutOrchestrator.php'), 'A5 CheckoutOrchestrator boundary exists');
arch_assert(arch_contains($subscriptionPresentation, 'namespace Simplixi\\SUPCheckout\\Subscription;'), 'Subscription presentation uses SUPCheckout namespace');
arch_assert(arch_contains($checkoutPayload, 'namespace Simplixi\\SUPCheckout\\Payment;'), 'A5 checkout payload uses SUPCheckout Payment namespace');
arch_assert(arch_contains($checkoutOrchestrator, 'namespace Simplixi\\SUPCheckout\\Payment;'), 'A5 checkout orchestrator uses SUPCheckout Payment namespace');
arch_assert(arch_contains($gateway, 'GatewaySettings::fields('), 'gateway settings schema delegates to Admin boundary');
arch_assert(arch_contains($gateway, 'GatewaySettings::render_multimerchant('), 'multi-merchant presentation delegates to Admin boundary');
arch_assert(is_file($root . '/src/Payment/OrderLock.php'), 'Payment OrderLock boundary exists');
arch_assert(is_file($root . '/src/Payment/ProviderResult.php'), 'Payment ProviderResult boundary exists');
arch_assert(is_file($root . '/src/Payment/StatusRateGate.php'), 'Payment StatusRateGate boundary exists');
arch_assert(is_file($root . '/src/Payment/StatusVerifier.php'), 'Payment StatusVerifier boundary exists');
arch_assert(arch_contains($paymentLifecycle, 'namespace Simplixi\\SUPCheckout\\Payment;'), 'Payment lifecycle uses SUPCheckout namespace');
arch_assert(arch_contains($securityStatus, 'namespace Simplixi\\SUPCheckout\\Security;'), 'Security boundary uses SUPCheckout namespace');
arch_assert(is_file($root . '/includes/Token/CustomerTokenIdentity.php'), 'protected H12 token identity module exists');
arch_assert(arch_contains($tokenIdentity, 'CustomerTokenIdentity'), 'H12 token identity implementation remains readable');
arch_assert(is_file($root . '/includes/Subscription/Cron/Scheduler.php'), 'protected subscription scheduler exists');
arch_assert(is_file($root . '/includes/Subscription/Cron/CycleClaim.php'), 'protected subscription cycle-claim module exists');
arch_assert(arch_contains($scheduler, 'class Scheduler'), 'subscription Scheduler class remains characterized');
arch_assert(is_file($root . '/includes/class-wc-gateway-upayments-blocks.php'), 'Checkout Blocks gateway integration exists');

$constructor = arch_direct_public_method($gatewayClassTokens, '__construct');
arch_assert($constructor['found'], 'WC_Upayments constructor remains public and executable');
arch_assert(
    arch_has_token_sequence($constructor['body'], $gatewayIdSequence),
    'gateway ID remains executable in WC_Upayments::__construct and bound to upayments'
);
arch_assert(
    arch_has_token_sequence($constructor['body'], $callbackWithTrailingComma)
        || arch_has_token_sequence($constructor['body'], $callbackWithoutTrailingComma),
    'wc_upayments callback hook remains executable in WC_Upayments::__construct and bound to check_ipn_response'
);
arch_assert($availabilityBinding['found'], 'enableUpaymentsGateway remains a direct top-level registered availability callback');
arch_assert(
    arch_has_token_sequence($availabilityBinding['body'], $availabilityDelegateSequence),
    'legacy global availability callback directly delegates to the E1 Gateway Availability boundary'
);
arch_assert($gatewayAvailabilityFilter['found'], 'E1 Gateway Availability filter entry point is public and executable');
arch_assert(
    arch_has_token_sequence($gatewayAvailabilityFilter['body'], $settingsReadSequence),
    'legacy WooCommerce settings option remains an executable availability-policy read behind the compatibility adapter'
);
arch_assert(
    arch_has_token_sequence($gatewayAvailabilityFilter['body'], $runtimeEligibilitySequence),
    'E1 Gateway Availability enforces deterministic runtime eligibility before presentation policy'
);
arch_assert(
    arch_has_token_sequence($gatewayAvailabilityFilter['body'], $sessionGuardSequence),
    'E1 Gateway Availability guards session-dependent policy before session access'
);
arch_assert(
    arch_contains($gatewayAvailability, "is_checkout()")
        && arch_contains($gatewayAvailability, "'enable_autodeduction'")
        && arch_contains($gatewayAvailability, "=== 'yes'"),
    'E1 Gateway Availability retains checkout-scoped auto-deduction COD policy'
);
arch_assert(
    arch_has_token_sequence(
        $resolvedPublicMethods['add_order_item_totals']['body'],
        array(
            'return', 'name:OrderPresentation', '::', 'name:add_order_item_totals', '(',
            'variable:$total_rows', ',', 'variable:$order', ',', 'variable:$this', '->', 'name:id', ')', ';',
        )
    ),
    'WC_Upayments::add_order_item_totals directly delegates to R1 OrderPresentation'
);
arch_assert(
    arch_contains($gatewayOrderPresentation, 'get_payment_method()')
        && arch_contains($gatewayOrderPresentation, 'UPayments_Result')
        && arch_contains($gatewayOrderPresentation, 'UPayments_PaymentID'),
    'R1 OrderPresentation keeps gateway identity guard and protected metadata ownership'
);
arch_assert(
    arch_has_token_sequence(
        $resolvedPublicMethods['process_payment']['body'],
        array(
            'return', '(', 'new', 'name:CheckoutOrchestrator', '(',
            'variable:$gateway', ',', 'variable:$request_body_reader', ',',
            'variable:$request_executor', ',', 'variable:$callback_url_resolver',
            ')', ')', '->', 'name:process', '(', 'variable:$order_id', ')', ';',
        )
    ),
    'WC_Upayments::process_payment directly delegates to the A5 checkout orchestrator'
);
arch_assert($checkoutProcess['found'], 'A5 CheckoutOrchestrator process entry point is public and executable');
arch_assert(
    arch_has_token_sequence($checkoutProcess['body'], $orderIdWriteSequence),
    'UPayments_order_id remains executable CheckoutOrchestrator::process persistence from the local provider-order identity'
);

$inertFixture = <<<'PHP'
<?php
class WrongGateway {
    public function getAPIUrl() { return 'dead'; }
    public function process_payment() { $order->add_meta_data("UPayments_order_id", $unique_order_id); }
}
class WC_Upayments {
    // public function getAPIUrl() {}
    public function __construct() {
        $dead_id = '$this->id = \'upayments\';';
        $nested = function () {
            $this->id = 'upayments';
            add_action("woocommerce_api_" . strtolower("WC_UPayments"), [$this, "check_ipn_response"]);
        };
    }
    protected function getAPIUrl() { return 'not-public'; }
    public function process_payment() {
        $dead_write = '$order->add_meta_data("UPayments_order_id", $unique_order_id);';
        $nested = function () use ($order, $unique_order_id) {
            $order->add_meta_data("UPayments_order_id", $unique_order_id);
        };
    }
}
if (false)
    add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
function enableUpaymentsGateway($available_gateways) {
    $dead_settings = '$settings = get_option("woocommerce_upayments_settings");';
    $nested = function () {
        $settings = get_option("woocommerce_upayments_settings");
    };
    return $available_gateways;
}
PHP;
$inertTokens = arch_executable_tokens($inertFixture);
$inertClass = arch_class_body_tokens($inertTokens, 'WC_Upayments');
$inertConstructor = arch_direct_public_method($inertClass, '__construct');
$inertGetApiUrl = arch_direct_public_method($inertClass, 'getAPIUrl');
$inertProcessPayment = arch_direct_public_method($inertClass, 'process_payment');
$inertAvailability = arch_direct_top_level_filter_callback($inertTokens, $availabilityFilterSequence, 'enableUpaymentsGateway');
arch_assert(!$inertGetApiUrl['found'], 'public-method matcher ignores comment/string, wrong class and protected same-class copy');
arch_assert(!arch_has_token_sequence($inertConstructor['body'], $gatewayIdSequence), 'role matcher ignores string/nested gateway ID');
arch_assert(
    !arch_has_token_sequence($inertConstructor['body'], $callbackWithTrailingComma)
        && !arch_has_token_sequence($inertConstructor['body'], $callbackWithoutTrailingComma),
    'role matcher ignores nested callback registration'
);
arch_assert(!arch_has_token_sequence($inertProcessPayment['body'], $orderIdWriteSequence), 'role matcher ignores string/nested order-id write and wrong-class executable copy');
arch_assert(!$inertAvailability['found'], 'top-level callback matcher rejects brace-less conditional registration');

$bracedConditionalFixture = <<<'PHP'
<?php
if (false) {
    add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
    function enableUpaymentsGateway($available_gateways) {
        $settings = get_option("woocommerce_upayments_settings");
        return $available_gateways;
    }
}
PHP;
$bracedConditional = arch_direct_top_level_filter_callback(
    arch_executable_tokens($bracedConditionalFixture),
    $availabilityFilterSequence,
    'enableUpaymentsGateway'
);
arch_assert(!$bracedConditional['found'], 'top-level callback matcher rejects braced conditional registration/declaration');

$validAvailabilityFixture = <<<'PHP'
<?php
add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
function enableUpaymentsGateway($available_gateways) {
    $settings = get_option("woocommerce_upayments_settings");
    return $available_gateways;
}
PHP;
$validAvailability = arch_direct_top_level_filter_callback(
    arch_executable_tokens($validAvailabilityFixture),
    $availabilityFilterSequence,
    'enableUpaymentsGateway'
);
arch_assert($validAvailability['found'], 'top-level callback matcher recognizes direct registered global callback');
arch_assert(arch_has_token_sequence($validAvailability['body'], $settingsReadSequence), 'top-level callback matcher exposes direct executable settings read');

$providerResolver = arch_read($root, 'src/Provider/EndpointResolver.php');
arch_assert($providerResolver !== '', 'A1 Provider endpoint resolver exists');
arch_assert(arch_contains($providerResolver, 'namespace Simplixi\\SUPCheckout\\Provider;'), 'A1 Provider resolver uses the SUPCheckout Provider namespace');

$availabilityService = arch_read($root, 'src/Provider/PaymentMethodAvailability.php');
arch_assert($availabilityService !== '', 'A2 payment-method availability service exists');
arch_assert(arch_contains($availabilityService, 'namespace Simplixi\\SUPCheckout\\Provider;'), 'A2 availability service uses the SUPCheckout Provider namespace');
arch_assert(is_file($root . '/tests/harness/architecture-payment-method-availability-harness.php'), 'A2 availability harness exists');

$gatewaySettings = arch_read($root, 'src/Admin/GatewaySettings.php');
arch_assert($gatewaySettings !== '', 'A3 gateway settings service exists');
arch_assert(arch_contains($gatewaySettings, 'namespace Simplixi\SUPCheckout\Admin;'), 'A3 settings service uses the SUPCheckout Admin namespace');
arch_assert(is_file($root . '/tests/harness/architecture-gateway-settings-harness.php'), 'A3 gateway settings harness exists');

printf("\nArchitecture Foundation: %d PASS / %d FAIL\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
