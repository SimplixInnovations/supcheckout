<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

final class GatewaySettingsTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
    }

    public function test_fields_preserve_exact_keys_order_and_runtime_defaults(): void {
        $fields = GatewaySettings::fields('upayments', 'UPayments', 'Provider description');

        self::assertSame(array(
            'enabled',
            'make_default_gateway',
            'title',
            'description',
            'api_key',
            'debug',
            'test_mode',
            'is_order_complete',
            'save_card_section_title',
            'use_new_design',
            'enable_save_card',
            'multimerchant_section_title',
            'enable_multimerchant',
            'iban_number',
            'cc_charge',
            'cc_charge_type',
            'knet_charge',
            'knet_charge_type',
            'multimerchant_accounts',
            'autodeduction_section_title',
            'enable_subscriptions',
        ), array_keys($fields));
        self::assertSame('yes', $fields['enabled']['default']);
        self::assertSame('no', $fields['make_default_gateway']['default']);
        self::assertSame('UPayments', $fields['title']['default']);
        self::assertSame('Provider description', $fields['description']['default']);
        self::assertSame('', $fields['api_key']['default']);
        self::assertSame('password', $fields['api_key']['type']);
        self::assertSame('yes', $fields['enable_save_card']['default']);
        self::assertSame('no', $fields['enable_multimerchant']['default']);
        self::assertSame('multimerchant_repeater', $fields['multimerchant_accounts']['type']);
    }

    public function test_runtime_eligibility_requires_exact_enabled_credentials_and_supported_currency(): void {
        self::assertTrue(GatewaySettings::is_runtime_eligible(
            array('enabled' => 'yes', 'api_key' => 'cert-key'),
            'USD'
        ));
        self::assertTrue(GatewaySettings::is_runtime_eligible(
            array('api_key' => 'cert-key'),
            'KWD'
        ));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array(), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array('enabled' => 'yes'), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array('enabled' => 'yes', 'api_key' => '   '), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array('enabled' => 'no', 'api_key' => 'cert-key'), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array('enabled' => true, 'api_key' => 'cert-key'), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible((object) array('enabled' => 'yes', 'api_key' => 'cert-key'), 'USD'));
        self::assertFalse(GatewaySettings::is_runtime_eligible(array('enabled' => 'yes', 'api_key' => 'cert-key'), 'JPY'));
    }

    public function test_dependency_normalization_forces_save_card_only_for_enabled_subscriptions(): void {
        $forced = GatewaySettings::normalize_dependencies(array(
            'enable_subscriptions' => 'yes',
            'enable_save_card'     => '',
        ));
        self::assertTrue($forced['forced_save_card']);
        self::assertSame('yes', $forced['settings']['enable_save_card']);

        $already_enabled = GatewaySettings::normalize_dependencies(array(
            'enable_subscriptions' => 'yes',
            'enable_save_card'     => 'yes',
        ));
        self::assertFalse($already_enabled['forced_save_card']);
        self::assertSame('yes', $already_enabled['settings']['enable_save_card']);

        $disabled = GatewaySettings::normalize_dependencies(array('enable_subscriptions' => ''));
        self::assertFalse($disabled['forced_save_card']);
        self::assertArrayNotHasKey('enable_save_card', $disabled['settings']);
    }

    public function test_prepare_post_data_rejects_missing_credentials_before_allocation_validation(): void {
        $result = GatewaySettings::prepare_post_data(array(
            'woocommerce_upayments_enable_multimerchant' => '1',
        ));

        self::assertTrue($result['api_key_missing']);
        self::assertFalse($result['multimerchant_missing']);
        self::assertSame(
            array('woocommerce_upayments_enable_multimerchant' => '1'),
            $result['post_data']
        );
    }

    public function test_prepare_post_data_uses_the_same_nonblank_string_api_key_boundary_as_runtime_eligibility(): void {
        $zero_string = GatewaySettings::prepare_post_data(array(
            'woocommerce_upayments_api_key' => '0',
        ));
        self::assertFalse($zero_string['api_key_missing']);

        foreach (array('', '   ', null, array('unexpected'), false, 0) as $invalid) {
            $result = GatewaySettings::prepare_post_data(array(
                'woocommerce_upayments_api_key' => $invalid,
            ));
            self::assertTrue($result['api_key_missing']);
        }
    }

    public function test_prepare_post_data_requires_every_enabled_allocation_field(): void {
        $complete = array(
            'woocommerce_upayments_api_key'              => 'secret',
            'woocommerce_upayments_enable_multimerchant' => '1',
            'woocommerce_upayments_iban_number'          => 'KW81CBKU0000000000001234560101',
            'woocommerce_upayments_cc_charge'            => '1.000',
            'woocommerce_upayments_cc_charge_type'       => 'fixed',
            'woocommerce_upayments_knet_charge'          => '2.000',
            'woocommerce_upayments_knet_charge_type'     => 'percentage',
        );
        $valid = GatewaySettings::prepare_post_data($complete);
        self::assertFalse($valid['api_key_missing']);
        self::assertFalse($valid['multimerchant_missing']);
        self::assertSame($complete, $valid['post_data']);

        foreach (array(
            'woocommerce_upayments_iban_number',
            'woocommerce_upayments_cc_charge',
            'woocommerce_upayments_cc_charge_type',
            'woocommerce_upayments_knet_charge',
            'woocommerce_upayments_knet_charge_type',
        ) as $required) {
            $mutation = $complete;
            $mutation[$required] = '';
            self::assertTrue(
                GatewaySettings::prepare_post_data($mutation)['multimerchant_missing'],
                $required
            );
        }
    }

    public function test_prepare_post_data_accepts_provider_permitted_zero_main_merchant_commissions(): void {
        $complete = array(
            'woocommerce_upayments_api_key'              => 'secret',
            'woocommerce_upayments_enable_multimerchant' => '1',
            'woocommerce_upayments_iban_number'          => 'KW81CBKU0000000000001234560101',
            'woocommerce_upayments_cc_charge'            => '0',
            'woocommerce_upayments_cc_charge_type'       => 'fixed',
            'woocommerce_upayments_knet_charge'          => '0.000',
            'woocommerce_upayments_knet_charge_type'     => 'percentage',
        );

        $result = GatewaySettings::prepare_post_data($complete);
        self::assertFalse($result['api_key_missing']);
        self::assertFalse($result['multimerchant_missing']);
        self::assertSame($complete, $result['post_data']);
    }

    public function test_prepare_post_data_clears_all_runtime_allocation_fields_when_checkbox_is_absent(): void {
        $result = GatewaySettings::prepare_post_data(array(
            'woocommerce_upayments_api_key'          => 'secret',
            'woocommerce_upayments_iban_number'      => 'KW01',
            'woocommerce_upayments_cc_charge'        => '1.000',
            'woocommerce_upayments_cc_charge_type'   => 'fixed',
            'woocommerce_upayments_knet_charge'      => '2.000',
            'woocommerce_upayments_knet_charge_type' => 'percentage',
        ));

        self::assertFalse($result['api_key_missing']);
        self::assertFalse($result['multimerchant_missing']);
        self::assertFalse($result['multimerchant_invalid'] ?? false);
        foreach (array('iban_number', 'cc_charge', 'cc_charge_type', 'knet_charge', 'knet_charge_type') as $field) {
            self::assertNull($result['post_data']['woocommerce_upayments_' . $field], $field);
        }
    }

    public function test_prepare_post_data_rejects_noncanonical_present_multimerchant_checkbox_tokens(): void {
        foreach (array('0', 'yes', 'true', 0, 1, false, true, array('1'), null) as $invalid) {
            $post_data = array(
                'woocommerce_upayments_api_key'              => 'secret',
                'woocommerce_upayments_enable_multimerchant' => $invalid,
                'woocommerce_upayments_iban_number'          => 'KW81CBKU0000000000001234560101',
                'woocommerce_upayments_cc_charge'            => '1.000',
                'woocommerce_upayments_cc_charge_type'       => 'fixed',
                'woocommerce_upayments_knet_charge'          => '2.000',
                'woocommerce_upayments_knet_charge_type'     => 'percentage',
            );

            $result = GatewaySettings::prepare_post_data($post_data);
            self::assertTrue($result['multimerchant_invalid'] ?? false, var_export($invalid, true));
            self::assertSame($post_data, $result['post_data'], var_export($invalid, true));
        }
    }

    public function test_json_presentation_field_keeps_only_five_sanitized_non_secret_values(): void {
        $raw = json_encode(array(array(
            'iban_number'     => ' <b>KW01</b> ',
            'knet_charge'     => '<script>1</script>2.000',
            'knet_charge_type'=> ' percentage ',
            'cc_charge'       => '<i>3.000</i>',
            'cc_charge_type'  => ' fixed ',
            'api_key'         => 'must-not-survive',
            'merchant_id'     => 'must-not-survive',
        )));
        $sanitized = json_decode(GatewaySettings::sanitize_multimerchant_accounts($raw), true);

        self::assertSame(array(array(
            'iban_number'      => 'KW01',
            'knet_charge'      => '12.000',
            'knet_charge_type' => 'percentage',
            'cc_charge'        => '3.000',
            'cc_charge_type'   => 'fixed',
        )), $sanitized);
        self::assertSame('[]', GatewaySettings::sanitize_multimerchant_accounts('{invalid'));
        self::assertSame('[]', GatewaySettings::sanitize_multimerchant_accounts('"scalar"'));
        self::assertSame('[]', GatewaySettings::sanitize_multimerchant_accounts(null));
        self::assertSame('[]', GatewaySettings::sanitize_multimerchant_accounts(array('unexpected')));
        self::assertSame('[]', GatewaySettings::sanitize_multimerchant_accounts(
            '[{"iban_number":"' . "\xB1\x31" . '"}]'
        ));
    }

    public function test_renderer_escapes_dynamic_values_and_emits_one_allocation_row(): void {
        $values = array(
            'multimerchant_accounts' => '"><script>alert(1)</script>',
            'iban_number'             => 'KW"><script>iban</script>',
            'knet_charge'             => '1"><script>knet</script>',
            'knet_charge_type'        => 'percentage',
            'cc_charge'               => '2"><script>cc</script>',
            'cc_charge_type'          => 'fixed',
        );
        $reader = static function ($key, $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        };
        $html = GatewaySettings::render_multimerchant(
            'multimerchant_accounts',
            array(
                'title'       => '<script>title</script>',
                'type'        => 'multi"><script>type</script>',
                'description' => '<strong>Allowed</strong><script>description</script>',
                'default'     => '[]',
            ),
            $reader,
            'upayments',
            'upayments'
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;title&lt;/script&gt;', $html);
        self::assertStringContainsString('<strong>Allowed</strong>description', $html);
        self::assertStringContainsString('name="woocommerce_upayments_iban_number"', $html);
        self::assertStringContainsString('name="woocommerce_upayments_multimerchant_accounts"', $html);
        self::assertStringContainsString('value="percentage"  selected="selected"', $html);
        self::assertStringContainsString('value="fixed"  selected="selected"', $html);
        self::assertSame(1, substr_count($html, '<tbody>'));
        self::assertSame(1, substr_count($html, '<tbody>') ? substr_count($html, '<tr>') - 1 : 0);
    }

    public function test_asset_loading_requires_exact_gateway_and_settings_scopes(): void {
        GatewaySettings::enqueue_admin_assets(
            'https://example.test/plugin/',
            'upayments',
            array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'upayments'),
            'woocommerce_page_wc-settings'
        );

        self::assertSame(array(array(
            'handle'       => 'upayments-multimerchant-style',
            'source'       => 'https://example.test/plugin/assets/css/admin-style.css',
            'dependencies' => array(),
            'version'      => '0.1.0',
            'media'        => 'all',
        )), $GLOBALS['supcheckout_test_gateway_settings']['styles']);
        self::assertSame(array('upayments-admin-logic'), array_column(
            $GLOBALS['supcheckout_test_gateway_settings']['scripts'],
            'handle'
        ));
        self::assertSame(array('jquery'), $GLOBALS['supcheckout_test_gateway_settings']['scripts'][0]['dependencies']);
        self::assertTrue($GLOBALS['supcheckout_test_gateway_settings']['scripts'][0]['in_footer']);
        self::assertCount(1, $GLOBALS['supcheckout_test_gateway_settings']['inline_styles']);
        self::assertSame(
            'woocommerce_admin_styles',
            $GLOBALS['supcheckout_test_gateway_settings']['inline_styles'][0]['handle']
        );
        $inlineCss = $GLOBALS['supcheckout_test_gateway_settings']['inline_styles'][0]['css'];
        self::assertStringContainsString('.upayments-disabled-setting', $inlineCss);
        foreach (array(
            'iban_number',
            'cc_charge',
            'cc_charge_type',
            'knet_charge',
            'knet_charge_type',
        ) as $legacyField) {
            self::assertStringContainsString('#woocommerce_upayments_' . $legacyField, $inlineCss);
        }
        self::assertStringNotContainsString('input[style*="display:none"]', $inlineCss);

        \supcheckout_test_reset_gateway_settings();
        GatewaySettings::enqueue_admin_assets(
            'https://example.test/plugin/',
            'upayments',
            array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'other'),
            'woocommerce_page_wc-settings'
        );
        self::assertSame(array(), $GLOBALS['supcheckout_test_gateway_settings']['styles']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_gateway_settings']['scripts']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_gateway_settings']['inline_styles']);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/assets/js/multimerchant-repeater.js');
    }
}