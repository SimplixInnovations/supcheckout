<?php

namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A1-11: "Show paid orders as Completed" on a genuinely fresh install is "no", the
 * WooCommerce standard (paid physical orders go to Processing).
 *
 * - No gateway settings option at all (a fresh install): WooCommerce hydrates the settings
 *   from the declared field defaults, so the preference is "no".
 * - A stored settings option keeps its saved value. A stored option without the key keeps
 *   "yes": the official 3.1.1 plugin read it through get_option() with a "yes" field default,
 *   and the takeover copies the settings array verbatim, so a migrated store behaves as the
 *   official plugin did. The settings screen shows that same "yes", so a plain Save does not
 *   flip it.
 *
 * Separate processes: the WooCommerce settings API stand-in is a global class.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderCompletionDefaultTest extends TestCase {
    private const OPTION = 'woocommerce_upayments_settings';

    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
        \supcheckout_test_reset_wp_options();
        self::define_settings_api();
        if (!defined('SUPCHECKOUT_PLUGIN_URL')) {
            define('SUPCHECKOUT_PLUGIN_URL', 'https://example.test/wp-content/plugins/supcheckout/');
        }
    }

    public function test_a_fresh_install_with_no_settings_option_sends_paid_orders_to_processing(): void {
        unset($GLOBALS['supcheckout_test_options'][self::OPTION]);

        $gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();

        self::assertSame('no', $gateway->isOrderComplete);
        self::assertFalse($gateway->getIsOrderComplete());
        self::assertSame('no', $gateway->get_option('is_order_complete'), 'the settings screen shows the fresh default unchecked');
    }

    public function test_stored_settings_without_the_key_keep_the_official_yes_on_runtime_and_screen(): void {
        $GLOBALS['supcheckout_test_options'][self::OPTION] = array('enabled' => 'yes', 'api_key' => 'k');

        $gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();

        self::assertSame('yes', $gateway->isOrderComplete);
        self::assertTrue($gateway->getIsOrderComplete());
        self::assertSame('yes', $gateway->get_option('is_order_complete'), 'the settings screen shows the value the runtime applies');
        self::assertArrayNotHasKey('is_order_complete', $GLOBALS['supcheckout_test_options'][self::OPTION], 'nothing stored changes');
    }

    public function test_a_saved_value_is_kept(): void {
        foreach (array('yes' => true, 'no' => false) as $saved => $complete) {
            $GLOBALS['supcheckout_test_options'][self::OPTION] = array('enabled' => 'yes', 'is_order_complete' => $saved);

            $gateway = new \Simplixi\SUPCheckout\Gateway\Gateway();

            self::assertSame($saved, $gateway->isOrderComplete);
            self::assertSame($complete, $gateway->getIsOrderComplete());
            self::assertSame($saved, $gateway->get_option('is_order_complete'));
        }
    }

    /** WooCommerce 10.8 WC_Settings_API init_settings(), get_form_fields() and get_option(). */
    private static function define_settings_api(): void {
        if (class_exists('WC_Payment_Gateway', false)) {
            return;
        }
        eval(<<<'PHP'
class WC_Payment_Gateway {
    public $id = '';
    public $plugin_id = 'woocommerce_';
    public $settings = array();
    public $form_fields = array();
    public $title = '';
    public $description = '';
    public $method_title = '';
    public $method_description = '';
    public $icon = '';
    public $has_fields = false;
    public $supports = array('products');
    public function get_option_key() { return $this->plugin_id . $this->id . '_settings'; }
    public function get_form_fields() { return $this->form_fields; }
    public function init_settings() {
        $this->settings = get_option($this->get_option_key(), null);
        if (!is_array($this->settings)) {
            $form_fields = $this->get_form_fields();
            $defaults = array();
            foreach ($form_fields as $key => $field) {
                if (isset($field['default'])) {
                    $defaults[$key] = $field['default'];
                }
            }
            $this->settings = array_merge(array_fill_keys(array_keys($form_fields), ''), $defaults);
        }
    }
    public function get_option($key, $empty_value = null) {
        if (empty($this->settings)) {
            $this->init_settings();
        }
        if (!isset($this->settings[$key])) {
            $form_fields = $this->get_form_fields();
            $this->settings[$key] = isset($form_fields[$key]['default']) ? $form_fields[$key]['default'] : '';
        }
        if (!is_null($empty_value) && '' === $this->settings[$key]) {
            $this->settings[$key] = $empty_value;
        }
        return $this->settings[$key];
    }
}
PHP
        );
    }
}
