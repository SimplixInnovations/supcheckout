<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

final class MultiMerchantSettingsContractTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
    }

    public function test_enabled_admin_boundary_rejects_runtime_invalid_iban_lexemes(): void {
        foreach (array(
            'KW01',
            'kw81CBKU0000000000001234560101',
            ' KW81CBKU0000000000001234560101',
            'KW81CBKU0000000000001234560101 ',
            'KW81CBKU00000000000012345601_1',
            'KW811234567890123456789012345678901',
        ) as $invalid) {
            $post_data = self::valid_post_data();
            $post_data['woocommerce_upayments_iban_number'] = $invalid;

            $result = GatewaySettings::prepare_post_data($post_data);
            self::assertTrue($result['multimerchant_invalid'], $invalid);
            self::assertTrue($result['multimerchant_missing'], $invalid);
            self::assertSame($post_data, $result['post_data'], $invalid);
        }
    }

    public function test_enabled_admin_boundary_rejects_runtime_invalid_charge_types(): void {
        foreach (array('flat', 'Fixed', 'PERCENTAGE', 'fixed ', ' percentage') as $invalid) {
            foreach (array('cc', 'knet') as $rail) {
                $post_data = self::valid_post_data();
                $post_data['woocommerce_upayments_' . $rail . '_charge_type'] = $invalid;

                $result = GatewaySettings::prepare_post_data($post_data);
                self::assertTrue($result['multimerchant_invalid'], $rail . ':' . $invalid);
                self::assertTrue($result['multimerchant_missing'], $rail . ':' . $invalid);
                self::assertSame($post_data, $result['post_data'], $rail . ':' . $invalid);
            }
        }
    }

    public function test_enabled_admin_boundary_rejects_runtime_invalid_commission_lexemes_and_ceiling(): void {
        foreach (array(
            '1e2',
            '-1',
            '+1',
            '00',
            '01',
            '.5',
            '1.',
            ' 0.5',
            '0.5 ',
            '1,5',
            '12345678901234567890123',
        ) as $invalid) {
            foreach (array('cc', 'knet') as $rail) {
                $post_data = self::valid_post_data();
                $post_data['woocommerce_upayments_' . $rail . '_charge'] = $invalid;

                $result = GatewaySettings::prepare_post_data($post_data);
                self::assertTrue($result['multimerchant_invalid'], $rail . ':' . $invalid);
                self::assertTrue($result['multimerchant_missing'], $rail . ':' . $invalid);
                self::assertSame($post_data, $result['post_data'], $rail . ':' . $invalid);
            }
        }
    }

    public function test_enabled_admin_boundary_accepts_exact_runtime_edge_values_without_rewriting(): void {
        foreach (array('0', '0.000', '1', '0.5', '1234567890123456789012') as $valid_charge) {
            $post_data = self::valid_post_data();
            $post_data['woocommerce_upayments_cc_charge'] = $valid_charge;
            $post_data['woocommerce_upayments_knet_charge'] = $valid_charge;

            $result = GatewaySettings::prepare_post_data($post_data);
            self::assertFalse($result['api_key_missing'], $valid_charge);
            self::assertFalse($result['multimerchant_missing'], $valid_charge);
            self::assertFalse($result['multimerchant_invalid'], $valid_charge);
            self::assertSame($post_data, $result['post_data'], $valid_charge);
        }
    }

    private static function valid_post_data(): array {
        return array(
            'woocommerce_upayments_api_key'              => 'secret',
            'woocommerce_upayments_enable_multimerchant' => '1',
            'woocommerce_upayments_iban_number'          => 'KW81CBKU0000000000001234560101',
            'woocommerce_upayments_cc_charge'            => '0',
            'woocommerce_upayments_cc_charge_type'       => 'fixed',
            'woocommerce_upayments_knet_charge'          => '0.900',
            'woocommerce_upayments_knet_charge_type'     => 'percentage',
        );
    }
}
