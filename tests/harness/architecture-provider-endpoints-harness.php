<?php
/**
 * A1 provider endpoint/mode resolver characterization harness.
 */

use Simplixi\SUPCheckout\Provider\EndpointResolver;

// Exercise the pure service before the shared bootstrap defines any WP/Woo stubs.
require_once dirname(__DIR__, 2) . '/src/Provider/EndpointResolver.php';

$pass = 0;
$fail = 0;

function arch4_assert($condition, $message)
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

function arch4_purity_violations($source)
{
    $tokens = token_get_all($source);
    $superglobals = array(
        '$GLOBALS',
        '$_SERVER',
        '$_GET',
        '$_POST',
        '$_FILES',
        '$_COOKIE',
        '$_SESSION',
        '$_REQUEST',
        '$_ENV',
    );
    $callTokenIds = array(T_STRING);
    foreach (array('T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE') as $tokenName) {
        if (defined($tokenName)) {
            $callTokenIds[] = constant($tokenName);
        }
    }
    $allowedObjectMembers = array('base', 'resolve');
    $allowedSelfConstants = array(
        'LIVE_BASE',
        'SANDBOX_BASE',
        'CREATE_CUSTOMER_TOKEN',
        'CHECK_PAYMENT_BUTTON_STATUS',
        'RETRIEVE_CUSTOMER_CARDS',
    );
    $forbiddenDependencyTokens = array(
        T_NEW,
        T_EXTENDS,
        T_IMPLEMENTS,
        T_USE,
        T_INCLUDE,
        T_INCLUDE_ONCE,
        T_REQUIRE,
        T_REQUIRE_ONCE,
        T_CLONE,
        T_INSTANCEOF,
        T_STATIC,
    );

    $violations = array();
    $count = count($tokens);
    $inNamespaceDeclaration = false;
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            if ($inNamespaceDeclaration && ($token === ';' || $token === '{')) {
                $inNamespaceDeclaration = false;
            }
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $inNamespaceDeclaration = true;
            continue;
        }
        if ($inNamespaceDeclaration) {
            continue;
        }
        if ($token[0] === T_GLOBAL) {
            $violations[] = 'global-import';
            continue;
        }
        if ($token[0] === T_VARIABLE && in_array($token[1], $superglobals, true)) {
            $violations[] = 'superglobal:' . $token[1];
            continue;
        }
        if (in_array($token[0], $forbiddenDependencyTokens, true)) {
            $violations[] = 'dependency-token:' . token_name($token[0]);
            continue;
        }
        if ($token[0] === T_OBJECT_OPERATOR) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $previous--;
            }
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $next++;
            }
            $validInternalObjectAccess = $previous >= 0
                && is_array($tokens[$previous])
                && $tokens[$previous][0] === T_VARIABLE
                && $tokens[$previous][1] === '$this'
                && $next < $count
                && is_array($tokens[$next])
                && $tokens[$next][0] === T_STRING
                && in_array($tokens[$next][1], $allowedObjectMembers, true);
            if (!$validInternalObjectAccess) {
                $violations[] = 'object-dependency';
            }
            continue;
        }
        if ($token[0] === T_DOUBLE_COLON) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $previous--;
            }
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $next++;
            }
            $validInternalConstant = $previous >= 0
                && is_array($tokens[$previous])
                && $tokens[$previous][0] === T_STRING
                && strtolower($tokens[$previous][1]) === 'self'
                && $next < $count
                && is_array($tokens[$next])
                && $tokens[$next][0] === T_STRING
                && in_array($tokens[$next][1], $allowedSelfConstants, true);
            if (!$validInternalConstant) {
                $violations[] = 'static-dependency';
            }
            continue;
        }
        if (!in_array($token[0], $callTokenIds, true)) {
            continue;
        }

        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $next++;
        }

        $previous = $i - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $previous--;
        }
        $previousId = $previous >= 0 && is_array($tokens[$previous]) ? $tokens[$previous][0] : null;
        if ($next < $count && $tokens[$next] === '(') {
            if (in_array($previousId, array(T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW), true)) {
                continue;
            }
            $violations[] = 'global-call:' . ltrim($token[1], '\\');
            continue;
        }

        $nextId = $next < $count && is_array($tokens[$next]) ? $tokens[$next][0] : null;
        if (!in_array($previousId, array(T_CLASS, T_FUNCTION, T_CONST, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW), true)
            && $nextId !== T_DOUBLE_COLON) {
            $violations[] = 'external-symbol:' . $token[1];
        }
    }

    return array_values(array_unique($violations));
}

$liveBase = 'https://apiv2api.upayments.com/api/v1/';
$sandboxBase = 'https://sandboxapi.upayments.com/api/v1/';
$routes = array(
    '' => '',
    'charge' => 'charge',
    'status path' => 'get-payment-status/track%2Fid',
    'leading slash' => '/charge',
    'query text' => 'get-payment-status?session_id=session-1',
    'numeric route' => 123,
    'null route' => null,
);

foreach (array(false => $liveBase, true => $sandboxBase) as $mode => $base) {
    $resolver = new EndpointResolver((bool) $mode);
    foreach ($routes as $label => $route) {
        arch4_assert(
            $resolver->resolve($route) === $base . $route,
            ($mode ? 'sandbox' : 'live') . " resolver preserves {$label} concatenation"
        );
    }

    arch4_assert(
        $resolver->create_customer_token() === $base . 'create-customer-unique-token',
        ($mode ? 'sandbox' : 'live') . ' resolver preserves create-token endpoint'
    );
    arch4_assert(
        $resolver->check_payment_button_status() === $base . 'check-payment-button-status',
        ($mode ? 'sandbox' : 'live') . ' resolver preserves payment-button endpoint'
    );
    arch4_assert(
        $resolver->retrieve_customer_cards() === $base . 'retrieve-customer-cards',
        ($mode ? 'sandbox' : 'live') . ' resolver preserves retrieve-cards endpoint'
    );
}

require_once __DIR__ . '/_bootstrap.php';

$gateway = new WC_Upayments();
foreach (array('no' => $liveBase, 'yes' => $sandboxBase, '' => $liveBase) as $setting => $base) {
    $gateway->testMode = $setting;
    arch4_assert($gateway->getAPIUrl() === $base, "gateway {$setting} mode preserves empty-route URL");
    arch4_assert($gateway->getAPIUrl('charge') === $base . 'charge', "gateway {$setting} mode delegates generic route");
    arch4_assert(
        $gateway->getAPIUrlForCreateToken() === $base . 'create-customer-unique-token',
        "gateway {$setting} mode delegates create-token URL"
    );
    arch4_assert(
        $gateway->getAPIUrlForCheckPaymentButtonStatus() === $base . 'check-payment-button-status',
        "gateway {$setting} mode delegates payment-button URL"
    );
    arch4_assert(
        $gateway->getAPIUrlForRetreiveCards() === $base . 'retrieve-customer-cards',
        "gateway {$setting} mode delegates retrieve-cards URL"
    );
}

$resolverSource = file_get_contents(dirname(__DIR__, 2) . '/src/Provider/EndpointResolver.php');
$gatewaySource = file_get_contents(dirname(__DIR__, 2) . '/UPayments.php');
arch4_assert(is_string($resolverSource), 'provider endpoint resolver source is readable');
arch4_assert(is_string($gatewaySource), 'gateway source is readable');
arch4_assert(
    strpos($resolverSource, 'namespace Simplixi\\SUPCheckout\\Provider;') !== false,
    'resolver uses the SUPCheckout Provider namespace'
);
arch4_assert(arch4_purity_violations($resolverSource) === array(), 'resolver token stream has no global calls, superglobals or global imports');
arch4_assert(
    in_array('superglobal:$_SERVER', arch4_purity_violations('<?php $mode = $_SERVER["HTTP_HOST"];'), true),
    'purity guard rejects superglobal-dependent mode selection'
);
arch4_assert(
    in_array('global-import', arch4_purity_violations('<?php function endpoint_mode() { global $mode; }'), true),
    'purity guard rejects imported global state'
);
arch4_assert(
    in_array('global-call:apply_filters', arch4_purity_violations('<?php $mode = apply_filters("endpoint_mode", false);'), true),
    'purity guard rejects platform hook calls'
);
arch4_assert(
    in_array('static-dependency', arch4_purity_violations('<?php $mode = WC_Admin_Settings::get_option("test_mode");'), true),
    'purity guard rejects static platform dependencies'
);
arch4_assert(
    in_array('object-dependency', arch4_purity_violations('<?php $mode = $settings->get_option("test_mode");'), true),
    'purity guard rejects injected object dependencies'
);
arch4_assert(
    in_array('dependency-token:T_NEW', arch4_purity_violations('<?php $settings = new WC_Admin_Settings();'), true),
    'purity guard rejects platform object construction'
);
arch4_assert(
    in_array('external-symbol:WP_DEBUG', arch4_purity_violations('<?php $mode = WP_DEBUG;'), true),
    'purity guard rejects external constants'
);
arch4_assert(
    substr_count($gatewaySource, 'apiv2api.upayments.com/api/v1/') === 0
        && substr_count($gatewaySource, 'sandboxapi.upayments.com/api/v1/') === 0,
    'gateway monolith no longer owns provider API bases'
);
arch4_assert(
    strpos($gatewaySource, "require_once __DIR__ . '/src/Provider/EndpointResolver.php';") !== false,
    'gateway bootstrap loads provider endpoint resolver explicitly'
);
arch4_assert(
    strpos($gatewaySource, 'new EndpointResolver($this->getMode())') !== false,
    'legacy public wrappers delegate mode selection to resolver'
);

echo "\nArchitecture Provider Endpoints: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
