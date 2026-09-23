<?php

namespace Simplixi\SUPCheckout\Gateway {
    function is_admin() {
        return !empty($GLOBALS['supcheckout_test_gateway_availability_context']['is_admin']);
    }

    function wp_doing_ajax() {
        return !empty($GLOBALS['supcheckout_test_gateway_availability_context']['doing_ajax']);
    }

    function is_checkout() {
        return !empty($GLOBALS['supcheckout_test_gateway_availability_context']['is_checkout']);
    }

    function get_option($name, $default = false) {
        return \get_option($name, $default);
    }

    function get_woocommerce_currency() {
        return isset($GLOBALS['supcheckout_test_gateway_availability_context']['currency'])
            ? $GLOBALS['supcheckout_test_gateway_availability_context']['currency']
            : '';
    }

    function WC() {
        ++$GLOBALS['supcheckout_test_gateway_availability_context']['wc_calls'];
        return null;
    }
}

namespace Simplixi\SUPCheckout\Tests\Gateway {
    use PHPUnit\Framework\TestCase;
    use Simplixi\SUPCheckout\Gateway\Availability;

    final class AvailabilityTest extends TestCase {
        protected function setUp(): void {
            \supcheckout_test_reset_wp_options();
            $GLOBALS['supcheckout_test_gateway_availability_context'] = array(
                'is_admin'    => false,
                'doing_ajax'  => false,
                'is_checkout' => false,
                'currency'    => 'USD',
                'wc_calls'    => 0,
            );
        }

        public function test_admin_ajax_still_enforces_runtime_gateway_eligibility(): void {
            $GLOBALS['supcheckout_test_gateway_availability_context']['is_admin'] = true;
            $GLOBALS['supcheckout_test_gateway_availability_context']['doing_ajax'] = true;
            \update_option(
                'woocommerce_upayments_settings',
                array('enabled' => 'no', 'api_key' => 'cert-key')
            );

            $cod = (object) array('id' => 'cod');
            $upayments = (object) array('id' => 'upayments');
            $result = Availability::filter(array(
                'cod'       => $cod,
                'upayments' => $upayments,
            ));

            self::assertSame($cod, $result['cod']);
            self::assertArrayNotHasKey('upayments', $result);
            self::assertSame(0, $GLOBALS['supcheckout_test_gateway_availability_context']['wc_calls']);
        }

        public function test_non_ajax_wp_admin_keeps_gateway_inventory_inert(): void {
            $GLOBALS['supcheckout_test_gateway_availability_context']['is_admin'] = true;
            $GLOBALS['supcheckout_test_gateway_availability_context']['doing_ajax'] = false;
            \update_option(
                'woocommerce_upayments_settings',
                array('enabled' => 'no', 'api_key' => 'cert-key')
            );

            $gateways = array(
                'cod'       => (object) array('id' => 'cod'),
                'upayments' => (object) array('id' => 'upayments'),
            );

            self::assertSame($gateways, Availability::filter($gateways));
            self::assertSame(0, $GLOBALS['supcheckout_test_gateway_availability_context']['wc_calls']);
        }

        public function test_admin_ajax_with_valid_settings_preserves_unrelated_gateways_without_session_access(): void {
            $GLOBALS['supcheckout_test_gateway_availability_context']['is_admin'] = true;
            $GLOBALS['supcheckout_test_gateway_availability_context']['doing_ajax'] = true;
            \update_option(
                'woocommerce_upayments_settings',
                array('enabled' => 'yes', 'api_key' => 'cert-key')
            );

            $cod = (object) array('id' => 'cod');
            $upayments = (object) array('id' => 'upayments');
            $result = Availability::filter(array(
                'cod'       => $cod,
                'upayments' => $upayments,
            ));

            self::assertSame(array('cod', 'upayments'), array_keys($result));
            self::assertSame($cod, $result['cod']);
            self::assertSame($upayments, $result['upayments']);
            self::assertSame(0, $GLOBALS['supcheckout_test_gateway_availability_context']['wc_calls']);
        }
    }
}
