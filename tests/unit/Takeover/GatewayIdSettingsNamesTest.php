<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

/**
 * 0b Task 7: every WC_Settings_API name derived from the gateway id follows the current
 * id: POST field names, the multi-merchant row inputs and the admin selectors. With the
 * legacy id (every store before cutover) the output is exactly what it was.
 */
final class GatewayIdSettingsNamesTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
    }

    private static function key(): string {
        return 'test-api-key-123';
    }

    public function test_post_data_is_read_under_the_current_gateway_id(): void {
        $post = array(
            'woocommerce_supcheckout_api_key' => self::key(),
            'woocommerce_supcheckout_enable_multimerchant' => '1',
            'woocommerce_supcheckout_iban_number' => 'KW81CBKU0000000000001234560101',
            'woocommerce_supcheckout_cc_charge' => '1.000',
            'woocommerce_supcheckout_cc_charge_type' => 'fixed',
            'woocommerce_supcheckout_knet_charge' => '0.500',
            'woocommerce_supcheckout_knet_charge_type' => 'percentage',
        );
        $prepared = GatewaySettings::prepare_post_data($post, 'supcheckout');
        self::assertFalse($prepared['api_key_missing'], 'a cut-over settings form posts woocommerce_supcheckout_*');
        self::assertFalse($prepared['multimerchant_missing']);
        self::assertSame($post, $prepared['post_data']);

        $cleared = GatewaySettings::prepare_post_data(array('woocommerce_supcheckout_api_key' => self::key()), 'supcheckout');
        self::assertArrayHasKey('woocommerce_supcheckout_iban_number', $cleared['post_data']);
        self::assertNull($cleared['post_data']['woocommerce_supcheckout_iban_number']);
        self::assertArrayNotHasKey('woocommerce_upayments_iban_number', $cleared['post_data']);

        self::assertTrue(GatewaySettings::prepare_post_data(array('woocommerce_upayments_api_key' => self::key()), 'supcheckout')['api_key_missing'],
            'a legacy-named field never counts for the new id');
        self::assertFalse(GatewaySettings::prepare_post_data(array('woocommerce_upayments_api_key' => self::key()))['api_key_missing'],
            'the legacy id stays the default');
    }

    public function test_the_multimerchant_row_and_admin_selectors_follow_the_gateway_id(): void {
        $reader = static function ($key, $default = null) {
            return $default;
        };
        $html = GatewaySettings::render_multimerchant('multimerchant_accounts', array('title' => 'T', 'type' => 'x', 'description' => '', 'default' => '[]'), $reader, 'supcheckout', 'upayments');
        foreach (array('iban_number', 'knet_charge', 'knet_charge_type', 'cc_charge', 'cc_charge_type', 'multimerchant_accounts') as $field) {
            self::assertStringContainsString('name="woocommerce_supcheckout_' . $field . '"', $html);
        }
        self::assertStringNotContainsString('woocommerce_upayments_', $html);

        GatewaySettings::enqueue_admin_assets('https://example.test/plugin/', 'supcheckout',
            array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'supcheckout'), 'woocommerce_page_wc-settings');
        $css = $GLOBALS['supcheckout_test_gateway_settings']['inline_styles'][0]['css'];
        self::assertStringContainsString('#woocommerce_supcheckout_iban_number', $css);
        self::assertStringNotContainsString('#woocommerce_upayments_', $css);
    }
}
