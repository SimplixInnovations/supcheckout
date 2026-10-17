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
        return isset($GLOBALS['supcheckout_test_gateway_availability_context']['wc'])
            ? $GLOBALS['supcheckout_test_gateway_availability_context']['wc']
            : null;
    }
}

namespace Simplixi\SUPCheckout\Tests\Gateway {
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use Simplixi\SUPCheckout\Gateway\Availability;
    use Simplixi\SUPCheckout\Takeover\TakeoverState;

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

        /**
         * 0b Task 7: a WooCommerce session started before cutover may hold
         * chosen_payment_method=upayments for up to 48 h; on checkout it is re-pointed to
         * the supcheckout gateway, so the merchant's default selection survives.
         */
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_a_stale_upayments_session_choice_is_repointed_after_cutover(): void {
            $session = self::session('upayments');
            $GLOBALS['supcheckout_test_gateway_availability_context']['is_checkout'] = true;
            $GLOBALS['supcheckout_test_gateway_availability_context']['wc'] = (object) array('session' => $session);
            \update_option('woocommerce_supcheckout_settings', array('enabled' => 'yes', 'api_key' => 'cert-key', 'make_default_gateway' => 'yes'));
            $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
            TakeoverState::flush_cache();

            $ours = self::our_gateway();
            $result = Availability::filter(array('cod' => (object) array('id' => 'cod'), 'supcheckout' => $ours));

            self::assertSame($ours, $result['supcheckout']);
            self::assertSame('supcheckout', $session->get('chosen_payment_method'), 'the stale choice selects our gateway');

            $session->set('chosen_payment_method', 'cod');
            Availability::filter(array('cod' => (object) array('id' => 'cod'), 'supcheckout' => $ours));
            self::assertSame('cod', $session->get('chosen_payment_method'), 'another gateway choice is untouched');
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_a_session_choice_is_untouched_before_cutover(): void {
            $session = self::session('upayments');
            $GLOBALS['supcheckout_test_gateway_availability_context']['is_checkout'] = true;
            $GLOBALS['supcheckout_test_gateway_availability_context']['wc'] = (object) array('session' => $session);
            \update_option('woocommerce_upayments_settings', array('enabled' => 'yes', 'api_key' => 'cert-key', 'make_default_gateway' => 'yes'));
            TakeoverState::flush_cache();

            Availability::filter(array('upayments' => (object) array('id' => 'upayments')));
            self::assertSame('upayments', $session->get('chosen_payment_method'));
        }

        /** @return object */
        private static function session($chosen) {
            return new class($chosen) {
                /** @var array<string, mixed> */
                private $data;
                public function __construct($chosen) { $this->data = array('chosen_payment_method' => $chosen); }
                public function get($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
                public function set($key, $value) { $this->data[$key] = $value; }
            };
        }

        /** @return \Simplixi\SUPCheckout\Gateway\Gateway */
        private static function our_gateway() {
            if (!class_exists('WC_Payment_Gateway', false)) {
                eval('class WC_Payment_Gateway { public $id = ""; }');
            }
            return (new \ReflectionClass(\Simplixi\SUPCheckout\Gateway\Gateway::class))->newInstanceWithoutConstructor();
        }
    }
}
