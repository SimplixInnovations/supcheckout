<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

/**
 * A6-02: the gateway settings save runs in wp-admin, where there is no WooCommerce
 * session. The forced Save Card change must be reported through the WooCommerce admin
 * settings messages, never through the storefront notice API (wc_add_notice()
 * dereferences the null session, a fatal "Call to a member function get() on null", and
 * a storefront notice is never printed in wp-admin anyway).
 *
 * Separate processes: the WC_Admin_Settings stand-in is a global class.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GatewaySettingsAdminSaveTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
        \supcheckout_test_reset_subscription_presentation();
        if (!class_exists('WC_Admin_Settings', false)) {
            eval('class WC_Admin_Settings {
                public static $messages = array();
                public static $errors = array();
                public static function add_message($text) { self::$messages[] = $text; }
                public static function add_error($text) { self::$errors[] = $text; }
            }');
        }
    }

    public function test_a_forced_save_card_is_reported_as_an_admin_settings_message_never_a_storefront_notice(): void {
        $settings = GatewaySettings::sanitize_saved_settings(array(
            'enable_subscriptions' => 'yes',
            'enable_save_card'     => 'no',
            'use_new_design'       => 'no',
        ));

        self::assertSame('yes', $settings['enable_save_card'], 'Save Card is still forced on');
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['notices'], 'no storefront notice');
        self::assertSame(
            array('Save Card must be enabled when Subscriptions are enabled, so it was turned on.'),
            \WC_Admin_Settings::$messages
        );
        self::assertSame(array(), \WC_Admin_Settings::$errors, 'an informational change, not a save error');
    }

    public function test_a_save_that_needs_no_correction_reports_nothing(): void {
        $input = array('enable_subscriptions' => 'yes', 'enable_save_card' => 'yes');

        self::assertSame($input, GatewaySettings::sanitize_saved_settings($input));
        self::assertSame(array(), \WC_Admin_Settings::$messages);
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['notices']);
    }

    public function test_the_gateway_registers_the_admin_safe_sanitizer_and_no_storefront_notice_on_save(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Gateway/Gateway.php');

        self::assertStringContainsString(
            "add_filter('woocommerce_settings_api_sanitized_fields_' . \$this->id, array(GatewaySettings::class, 'sanitize_saved_settings'));",
            $source
        );
        self::assertStringNotContainsString('Save Card must be enabled', $source);
    }
}
