<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator;

/**
 * 0b Task 7: WooCommerce's own gateway references are translated (journaled, the
 * original kept in a takeover-owned backup); third-party references (WCML, FunnelKit, any
 * other plugin) are reported only, because no real install has evidenced their shapes yet.
 */
final class ThirdPartyTranslatorTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    public function test_the_gateway_order_gains_supcheckout_at_the_upayments_position_and_is_journaled(): void {
        $order = array('bacs' => 0, 'upayments' => 1, 'cod' => 2);
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = $order;

        $result = ThirdPartyTranslator::translate(self::no_references());

        self::assertTrue($result['ok']);
        self::assertSame(array('woocommerce_gateway_order'), $result['translated']);
        $after = $order + array('supcheckout' => 1);
        self::assertSame($after, get_option('woocommerce_gateway_order'), 'upayments kept: copy, never move');

        $entry = Journal::entries()[0];
        self::assertSame('woocommerce_gateway_order', $entry['path']);
        self::assertSame(Journal::fingerprint($order), $entry['before_fingerprint']);
        self::assertSame(Journal::fingerprint($after), $entry['after_fingerprint']);
        $backup = substr($entry['restore_source'], strlen('option:'));
        self::assertStringStartsWith('supcheckout_takeover_backup_v1_', $backup);
        self::assertSame($order, get_option($backup), 'the original is restorable from the backup');
    }

    public function test_the_default_gateway_string_is_translated_and_anything_else_is_left_alone(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_default_gateway'] = 'upayments';
        self::assertSame(array('woocommerce_default_gateway'), ThirdPartyTranslator::translate(self::no_references())['translated']);
        self::assertSame('supcheckout', get_option('woocommerce_default_gateway'));

        \supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_options']['woocommerce_default_gateway'] = 'bacs';
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('bacs' => 0);
        self::assertSame(array(), ThirdPartyTranslator::translate(self::no_references())['translated']);
        self::assertSame('bacs', get_option('woocommerce_default_gateway'));
        self::assertSame(array(), Journal::entries());
    }

    public function test_a_rerun_is_idempotent_and_an_existing_supcheckout_position_is_kept(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('upayments' => 3);
        ThirdPartyTranslator::translate(self::no_references());
        $again = ThirdPartyTranslator::translate(self::no_references());
        self::assertSame(array(), $again['translated']);
        self::assertCount(1, Journal::entries());

        \supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('upayments' => 3, 'supcheckout' => 0);
        $kept = ThirdPartyTranslator::translate(self::no_references());
        self::assertSame(array(), $kept['translated']);
        self::assertSame(array('upayments' => 3, 'supcheckout' => 0), get_option('woocommerce_gateway_order'));
    }

    public function test_a_wcml_gateway_restriction_is_reported_and_never_written_or_journaled(): void {
        $wcml = array('currency_options' => array('USD' => array('gateways_settings' => array('upayments' => array('countries' => array('KW', 'SA'))))));
        $GLOBALS['supcheckout_test_options']['_wcml_settings'] = $wcml;
        $GLOBALS['supcheckout_test_options']['wfacp_global_settings'] = array('gateways' => array('upayments'));

        $result = ThirdPartyTranslator::translate(static function () {
            return array('_wcml_settings', 'wfacp_global_settings', 'some_other_plugin');
        });

        self::assertTrue($result['ok'], 'report-only references never block the cutover');
        self::assertSame(array(
            array('option' => '_wcml_settings', 'integration' => 'wcml', 'action' => 'report_only'),
            array('option' => 'wfacp_global_settings', 'integration' => 'funnelkit', 'action' => 'report_only'),
            array('option' => 'some_other_plugin', 'integration' => 'unknown', 'action' => 'report_only'),
        ), $result['reported']);
        self::assertSame($wcml, get_option('_wcml_settings'), 'never written');
        self::assertSame(array(), Journal::entries(), 'nothing journaled for a path that is not written');
    }

    public function test_merchant_edit_after_cutover_fails_is_current_after_and_untouched_entries_stay_current(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_gateway_order'] = array('upayments' => 1, 'bacs' => 2);
        $GLOBALS['supcheckout_test_options']['woocommerce_default_gateway'] = 'upayments';
        ThirdPartyTranslator::translate(self::no_references());

        // The merchant reorders gateways during the 30 days.
        update_option('woocommerce_gateway_order', array('bacs' => 0, 'supcheckout' => 1, 'upayments' => 2));

        $by_path = array();
        foreach (Journal::entries() as $entry) {
            $by_path[$entry['path']] = $entry;
        }
        self::assertFalse(Journal::is_current_after($by_path['woocommerce_gateway_order'], get_option('woocommerce_gateway_order')), 'Rollback must keep the later edit');
        self::assertTrue(Journal::is_current_after($by_path['woocommerce_default_gateway'], get_option('woocommerce_default_gateway')), 'an untouched value is restorable');
    }

    /** @return callable */
    private static function no_references() {
        return static function () {
            return array();
        };
    }
}
