<?php
/**
 * Q19 Subscription Product Eligibility Consistency.
 *
 * Permanent behavior guard for explicit product-level subscription opt-out.
 */

define('ABSPATH', __DIR__ . '/');

final class Q19Cart {
    private $items;

    public function __construct($items) {
        $this->items = $items;
    }

    public function get_cart() {
        return $this->items;
    }
}

final class Q19Woo {
    public $cart;

    public function __construct($items) {
        $this->cart = new Q19Cart($items);
    }
}

$GLOBALS['q19_items'] = array();
$GLOBALS['q19_meta'] = array();

function WC() {
    return new Q19Woo($GLOBALS['q19_items']);
}

function get_post_meta($product_id, $key, $single = false) {
    return isset($GLOBALS['q19_meta'][$product_id][$key])
        ? $GLOBALS['q19_meta'][$product_id][$key]
        : '';
}

require_once dirname(__DIR__, 2) . '/src/Subscription/Helpers/Utils.php';

$pass = 0;
$fail = 0;

function q19_assert($condition, $description) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: " . $description . "\n";
    } else {
        $fail++;
        echo "FAIL: " . $description . "\n";
    }
}

function q19_set_cart($product_ids) {
    $GLOBALS['q19_items'] = array();
    foreach ($product_ids as $product_id) {
        $GLOBALS['q19_items'][] = array('product_id' => $product_id);
    }
}

use Simplixi\SUPCheckout\Subscription\Helpers\Utils;

q19_set_cart(array());
$GLOBALS['q19_meta'] = array();
q19_assert(Utils::cartHasRestrictedProducts() === false, 'empty cart is not restricted');

q19_set_cart(array(123));
$GLOBALS['q19_meta'] = array();
q19_assert(Utils::cartHasRestrictedProducts() === false, 'ordinary product ID 123 is eligible without explicit opt-out');

q19_set_cart(array(456));
$GLOBALS['q19_meta'] = array();
q19_assert(Utils::cartHasRestrictedProducts() === false, 'ordinary product ID 456 is eligible without explicit opt-out');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array();
q19_assert(Utils::cartHasRestrictedProducts() === false, 'ordinary product remains eligible without explicit opt-out');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => ''),
);
q19_assert(Utils::cartHasRestrictedProducts() === false, 'present but empty opt-out metadata remains eligible');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => 'no'),
);
q19_assert(Utils::cartHasRestrictedProducts() === false, 'canonical no value does not opt a product out');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => true),
);
q19_assert(Utils::cartHasRestrictedProducts() === false, 'boolean true does not masquerade as canonical opt-out');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => 'YES'),
);
q19_assert(Utils::cartHasRestrictedProducts() === false, 'noncanonical uppercase YES does not opt a product out');

q19_set_cart(array(789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => 'yes'),
);
q19_assert(Utils::cartHasRestrictedProducts() === true, 'explicit product-level opt-out remains restrictive');

q19_set_cart(array(123));
$GLOBALS['q19_meta'] = array(
    123 => array('_upay_disable_subscription' => 'yes'),
);
q19_assert(Utils::cartHasRestrictedProducts() === true, 'explicit opt-out also restricts product ID 123');

q19_set_cart(array(123, 789));
$GLOBALS['q19_meta'] = array(
    789 => array('_upay_disable_subscription' => 'yes'),
);
q19_assert(Utils::cartHasRestrictedProducts() === true, 'one explicitly opted-out product restricts the mixed cart');

$root = dirname(__DIR__, 2);
$utils_source = file_get_contents($root . '/src/Subscription/Helpers/Utils.php');
$fields_source = file_get_contents($root . '/src/Subscription/Checkout/Fields.php');
$checkout_source = file_get_contents($root . '/src/Payment/CheckoutOrchestrator.php');
$checkout_test_source = file_get_contents($root . '/tests/unit/Payment/CheckoutOrchestratorTest.php');
$phpstan_source = file_get_contents($root . '/phpstan.neon.dist');
$phpcs_source = file_get_contents($root . '/phpcs.xml.dist');
$workflow_source = file_get_contents($root . '/.github/workflows/quality-gates.yml');
$agents_source = file_get_contents($root . '/AGENTS.md');

q19_assert(
    is_string($utils_source)
    && strpos($utils_source, '[123, 456]') === false
    && strpos($utils_source, "_upay_disable_subscription") !== false,
    'eligibility helper has no arbitrary product IDs and retains explicit opt-out'
);
q19_assert(
    is_string($fields_source)
    && substr_count($fields_source, 'Utils::cartHasRestrictedProducts()') >= 2,
    'Classic checkout hides and rejects restricted subscription context'
);
q19_assert(
    is_string($fields_source)
    && substr_count($fields_source, "wp_unslash(\$post['upay_subscription_plan'])") >= 2
    && strpos($fields_source, "sanitize_text_field(wp_unslash(\$post['upay_subscription_plan']))") === false,
    'Classic subscription plan is allowlisted from exact unslashed bytes before order metadata can be written'
);
q19_assert(
    is_string($fields_source)
    && substr_count($fields_source, "wp_unslash(\$post['upay_subscription_interval'])") >= 2
    && strpos($fields_source, "sanitize_text_field(wp_unslash(\$post['upay_subscription_interval']))") === false,
    'Classic subscription interval is strict-parsed from exact unslashed bytes before order metadata can be written'
);
q19_assert(
    is_string($checkout_source)
    && strpos($checkout_source, "_upay_disable_subscription") !== false
    && strpos($checkout_source, "Subscription plan rejected: product-level opt-out.") !== false,
    'payment orchestrator enforces product opt-out server-side'
);
$preflight_position = is_string($checkout_source)
    ? strpos($checkout_source, "if (\$order_has_subscription_restricted_product) {")
    : false;
$availability_position = is_string($checkout_source)
    ? strpos($checkout_source, "\$payment_data = \$gateway->getPaymentIcons();")
    : false;
q19_assert(
    $preflight_position !== false
    && $availability_position !== false
    && $preflight_position < $availability_position,
    'product opt-out preflight remains ordered before availability lookup/provider transport'
);
q19_assert(
    is_string($checkout_test_source)
    && strpos($checkout_test_source, 'test_store_api_rejects_explicitly_opted_out_subscription_product_before_provider_request') !== false
    && strpos($checkout_test_source, 'test_classic_rejects_explicitly_opted_out_subscription_product_before_provider_request') !== false,
    'Classic and Store API opt-out regressions remain permanent'
);
q19_assert(
    is_string($phpstan_source)
    && strpos($phpstan_source, 'src/Subscription/Checkout/Fields.php') !== false
    && strpos($phpstan_source, 'src/Subscription/Helpers/Utils.php') !== false,
    'PHPStan directly owns Q19 subscription eligibility sources'
);
q19_assert(
    is_string($phpcs_source)
    && strpos($phpcs_source, '<file>src/Subscription/Checkout/Fields.php</file>') !== false
    && strpos($phpcs_source, '<file>src/Subscription/Helpers/Utils.php</file>') !== false,
    'PHPCS directly owns Q19 subscription eligibility sources'
);
q19_assert(
    is_string($workflow_source)
    && strpos($workflow_source, 'tests/harness/quality-platform-subscription-product-eligibility-harness.php') !== false,
    'Q19 harness is mandatory in Quality Gates'
);
q19_assert(
    is_string($agents_source)
    && strpos($agents_source, 'Quality Platform Q1-Q19 harnesses;') !== false,
    'AGENTS keeps the closed Quality Platform harness set mandatory'
);

$closed_quality_current_markers = array(
    'CURRENT / Q1**',
    'CURRENT / Q2',
    'CURRENT / Q3',
    'CURRENT / Q4',
    'CURRENT / Q5',
    'CURRENT / Q6',
    'CURRENT / Q7',
    'CURRENT / Q8',
    'CURRENT / Q9',
    'CURRENT / Q10',
    'CURRENT / Q11',
    'CURRENT / Q12',
    'CURRENT / Q13',
    'CURRENT / Q14',
    'CURRENT / Q15',
    'CURRENT / Q16',
    'CURRENT / Q17',
    'CURRENT / Q18',
    'CURRENT / Q19',
);
foreach ($closed_quality_current_markers as $marker) {
    q19_assert(
        is_string($workflow_source)
        && strpos($workflow_source, "reject_across_live_records '{$marker}'") !== false,
        "Governance rejects closed Quality Platform marker: {$marker}"
    );
}

echo "\nQ19 Subscription Product Eligibility: " . $pass . " PASS / " . $fail . " FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
