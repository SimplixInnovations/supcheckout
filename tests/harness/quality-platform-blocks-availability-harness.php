<?php
/**
 * Q18 Blocks Availability Enforcement.
 *
 * Permanent behavior guard for the WooCommerce Blocks PHP adapter. This is
 * intentionally separate from the frozen H12 PHP ledger.
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    abstract class AbstractPaymentMethodType {
        protected $settings = array();
    }
}

namespace {
    $pass = 0;
    $fail = 0;

    function q18_assert($condition, $description) {
        global $pass, $fail;
        if ($condition) {
            $pass++;
            echo "PASS: " . $description . "\n";
        } else {
            $fail++;
            echo "FAIL: " . $description . "\n";
        }
    }

    $root = dirname(__DIR__, 2);
    $blocks_file = $root . '/includes/class-wc-gateway-upayments-blocks.php';
    $blocks_js = $root . '/assets/js/upayments-block.js';
    $phpstan_file = $root . '/phpstan.neon.dist';
    $phpcs_file = $root . '/phpcs.xml.dist';
    $workflow_file = $root . '/.github/workflows/quality-gates.yml';
    $agents_file = $root . '/AGENTS.md';

    q18_assert(is_file($blocks_file), 'Blocks adapter source exists');
    q18_assert(is_file($blocks_js), 'Blocks client source exists');

    $GLOBALS['q18_currency'] = 'USD';
    function get_woocommerce_currency() {
        return isset($GLOBALS['q18_currency']) && is_string($GLOBALS['q18_currency'])
            ? $GLOBALS['q18_currency']
            : '';
    }

    require_once $root . '/src/Admin/GatewaySettings.php';
    require_once $blocks_file;

    class Q18BlocksAvailabilityProbe extends \WCGatewayUPaymentsBlocks {
        public function set_settings_for_test($settings) {
            $this->settings = $settings;
        }

        public function set_gateway_for_test($gateway) {
            $this->gateway = $gateway;
        }
    }

    $probe = new Q18BlocksAvailabilityProbe('');
    $probe->set_gateway_for_test(new \stdClass());

    $probe->set_settings_for_test(array('enabled' => 'yes', 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === true, 'configured enabled gateway on supported currency exposes Blocks method');

    $probe->set_gateway_for_test(null);
    q18_assert($probe->is_active() === false, 'configured gateway without runtime object fails closed');

    $probe->set_gateway_for_test(new \stdClass());

    $probe->set_settings_for_test(array('enabled' => 'no', 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === false, 'enabled=no suppresses Blocks method');

    $probe->set_settings_for_test(array());
    q18_assert($probe->is_active() === false, 'fresh settings without API credential fail closed');

    $probe->set_settings_for_test(array('enabled' => 'yes'));
    q18_assert($probe->is_active() === false, 'enabled gateway without API credential fails closed');

    $probe->set_settings_for_test(array('enabled' => 'yes', 'api_key' => '   '));
    q18_assert($probe->is_active() === false, 'whitespace-only API credential fails closed');

    $probe->set_settings_for_test(array('enabled' => null, 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === false, 'explicit null enabled flag fails closed');

    $probe->set_settings_for_test((object) array('enabled' => 'yes', 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === false, 'object-valued gateway settings fail closed');

    $probe->set_settings_for_test(array('enabled' => true, 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === false, 'malformed boolean enabled flag fails closed');

    $probe->set_settings_for_test(array('enabled' => 'YES', 'api_key' => 'cert-key'));
    q18_assert($probe->is_active() === false, 'noncanonical enabled token fails closed');

    $probe->set_settings_for_test(array('enabled' => 'yes', 'api_key' => 'cert-key'));
    $GLOBALS['q18_currency'] = 'JPY';
    q18_assert($probe->is_active() === false, 'unsupported store currency suppresses Blocks method');
    $GLOBALS['q18_currency'] = 'USD';

    q18_assert($probe->get_name() === 'upayments', 'Blocks gateway identity remains upayments');

    $blocks_source = file_get_contents($blocks_file);
    $js_source = file_get_contents($blocks_js);
    q18_assert(
        is_string($blocks_source)
        && strpos($blocks_source, "get_option( 'woocommerce_upayments_settings', [] )") !== false,
        'canonical WooCommerce settings option identity remains exact'
    );
    q18_assert(
        is_string($js_source)
        && strpos($js_source, "name: 'upayments'") !== false,
        'Blocks client registration identity remains upayments'
    );
    q18_assert(
        is_string($blocks_source)
        && strpos($blocks_source, "'availability_valid'") !== false,
        'Blocks server data exposes explicit provider-availability truth'
    );
    q18_assert(
        is_string($js_source)
        && strpos($js_source, 'const canMakePayment = (currentOrder) =>') !== false
        && strpos($js_source, 'availability_valid === true') !== false
        && strpos($js_source, 'currentOrder.cartTotals') !== false
        && strpos($js_source, "typeof currentOrder.cartTotals.currency_code === 'string'") !== false
        && strpos($js_source, "liveCurrency !== ''") !== false
        && strpos($js_source, 'Array.isArray(supported_currencies)') !== false
        && strpos($js_source, 'supported_currencies.includes(liveCurrency)') !== false
        && strpos($js_source, 'canMakePayment: canMakePayment') !== false,
        'Blocks client canMakePayment fails closed on provider availability and current live economics'
    );
    q18_assert(
        is_string($js_source)
        && strpos($js_source, 'setTimeout(init, 100)') === false,
        'Blocks bootstrap does not poll indefinitely for declared script dependencies'
    );

    $phpstan_source = file_get_contents($phpstan_file);
    $phpcs_source = file_get_contents($phpcs_file);
    $workflow_source = file_get_contents($workflow_file);
    $agents_source = file_get_contents($agents_file);

    q18_assert(
        is_string($phpstan_source)
        && strpos($phpstan_source, 'includes/class-wc-gateway-upayments-blocks.php') !== false
        && strpos($phpstan_source, 'tests/phpstan/blocks-availability-stubs.php') !== false,
        'PHPStan owns Blocks adapter without a baseline'
    );
    q18_assert(
        is_string($phpcs_source)
        && strpos($phpcs_source, '<file>includes/class-wc-gateway-upayments-blocks.php</file>') !== false,
        'PHPCS owns Blocks adapter'
    );
    q18_assert(
        is_string($workflow_source)
        && strpos($workflow_source, 'tests/harness/quality-platform-blocks-availability-harness.php') !== false,
        'Q18 harness is mandatory in Quality Gates'
    );
    q18_assert(
        is_string($agents_source)
        && strpos($agents_source, 'Quality Platform Q1-Q19 harnesses;') !== false,
        'AGENTS keeps the closed Quality Platform harness set mandatory'
    );

    echo "\nQ18 Blocks Availability Enforcement: " . $pass . " PASS / " . $fail . " FAIL\n";
    exit($fail === 0 ? 0 : 1);
}
