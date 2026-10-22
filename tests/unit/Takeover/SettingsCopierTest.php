<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\SettingsCopier;

/**
 * 0b Task 7: gateway settings into woocommerce_supcheckout_settings and the copied
 * options verbatim, each write journaled by fingerprint only.
 */
final class SettingsCopierTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    private static function api_key(): string {
        return 'test-api-key-123';
    }

    private static function secret(): string {
        return hash('sha256', 'test-api-key-123|secret');
    }

    public function test_settings_and_options_are_copied_verbatim_and_journaled_without_secrets(): void {
        $settings = array('enabled' => 'yes', 'api_key' => self::api_key(), 'enable_subscriptions' => 'yes');
        self::seed('woocommerce_upayments_settings', $settings);
        self::seed('upayments_token_identity_secret_v2', self::secret());
        self::seed('upay_billing_cycle_schema_version', '2');

        $result = SettingsCopier::copy();

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame($settings, get_option(Keys::SETTINGS_OPTION));
        self::assertSame(self::secret(), get_option('supcheckout_token_identity_secret_v2'), 'identical bytes keep H12 provenance valid');
        self::assertSame('2', get_option('supcheckout_billing_cycle_schema_version'));
        self::assertFalse(get_option('supcheckout_subscription_enrollment_cursor', false), 'an absent legacy option is not created');
        self::assertSame($settings, get_option('woocommerce_upayments_settings'), 'copy, never move');
        self::assertSame(self::secret(), get_option('upayments_token_identity_secret_v2'));

        $entries = Journal::entries();
        self::assertSame(array(Keys::SETTINGS_OPTION, 'supcheckout_token_identity_secret_v2', 'supcheckout_billing_cycle_schema_version'), array_column($entries, 'path'));
        self::assertSame('option:woocommerce_upayments_settings', $entries[0]['restore_source']);
        self::assertSame(Journal::fingerprint(null), $entries[0]['before_fingerprint'], 'the target did not exist');
        self::assertTrue(Journal::is_current_after($entries[1], get_option('supcheckout_token_identity_secret_v2')));
        $encoded = (string) json_encode($entries);
        self::assertStringNotContainsString(self::api_key(), $encoded);
        self::assertStringNotContainsString(self::secret(), $encoded);
        self::assertTrue(SettingsCopier::verify()['ok']);
    }

    public function test_the_first_non_empty_source_wins(): void {
        self::seed('woocommerce_upayments_settings', array());
        self::seed('woocommerce_your_gateway_id_settings', array('enabled' => 'yes', 'title' => '3.0.0'));
        self::assertTrue(SettingsCopier::copy()['ok']);
        self::assertSame(array('enabled' => 'yes', 'title' => '3.0.0'), get_option(Keys::SETTINGS_OPTION));
        self::assertSame('option:woocommerce_your_gateway_id_settings', Journal::entries()[0]['restore_source']);
    }

    public function test_no_settings_at_all_fails_closed(): void {
        $result = SettingsCopier::copy();
        self::assertFalse($result['ok']);
        self::assertSame(array('settings_missing'), $result['mismatches']);
        self::assertFalse(get_option(Keys::SETTINGS_OPTION, false));
    }

    public function test_a_rerun_is_idempotent_and_refreshes_only_its_own_stale_copy(): void {
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes', 'title' => 'A'));
        self::assertTrue(SettingsCopier::copy()['ok']);
        self::assertTrue(SettingsCopier::copy()['ok']);
        self::assertCount(1, Journal::entries(), 'nothing changed, nothing journaled');

        // The merchant edits the legacy settings after a crashed cutover copied them.
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes', 'title' => 'B'));
        self::assertFalse(SettingsCopier::verify()['ok']);
        self::assertTrue(SettingsCopier::copy()['ok'], 'our own stale copy is refreshed');
        self::assertSame(array('enabled' => 'yes', 'title' => 'B'), get_option(Keys::SETTINGS_OPTION));
        self::assertCount(2, Journal::entries());
    }

    public function test_a_foreign_target_or_option_is_reported_and_kept(): void {
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes'));
        self::seed(Keys::SETTINGS_OPTION, array('enabled' => 'no'));
        self::seed('upayments_token_identity_secret_v2', self::secret());
        self::seed('supcheckout_token_identity_secret_v2', hash('sha256', 'other'));
        self::seed('supcheckout_subscription_enrollment_cursor', '9');

        $result = SettingsCopier::copy();

        self::assertFalse($result['ok']);
        self::assertSame(array('settings_foreign', 'supcheckout_token_identity_secret_v2', 'supcheckout_subscription_enrollment_cursor'), $result['mismatches']);
        self::assertSame(array('enabled' => 'no'), get_option(Keys::SETTINGS_OPTION), 'never overwritten');
        self::assertSame(hash('sha256', 'other'), get_option('supcheckout_token_identity_secret_v2'));
        self::assertSame(array(), Journal::entries());
    }

    public function test_a5_04_foreign_targets_reports_what_copy_would_refuse_without_writing(): void {
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes'));
        self::seed(Keys::SETTINGS_OPTION, array('enabled' => 'no'));
        self::seed('upayments_token_identity_secret_v2', self::secret());
        self::seed('supcheckout_token_identity_secret_v2', hash('sha256', 'other'));
        $before = $GLOBALS['supcheckout_test_options'];

        $targets = SettingsCopier::foreign_targets();

        self::assertSame(array(Keys::SETTINGS_OPTION, 'supcheckout_token_identity_secret_v2'), $targets);
        self::assertSame($before, $GLOBALS['supcheckout_test_options'], 'read-only');
        self::assertSame(array(), Journal::entries());

        // Through an injected reader (Preflight's Environment), with the same verdict as copy().
        $values = array('woocommerce_upayments_settings' => array('enabled' => 'yes'));
        $reader = static function ($name, $default) use ($values) {
            return array_key_exists($name, $values) ? $values[$name] : $default;
        };
        self::assertSame(array(), SettingsCopier::foreign_targets($reader), 'absent targets are created, never foreign');
        self::assertSame(array(), SettingsCopier::foreign_targets(static function ($name, $default) {
            return $default;
        }), 'no settings source is credential_unverified\'s finding, not a foreign target');
    }

    public function test_a5_04_an_own_stale_copy_is_not_a_foreign_target(): void {
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes', 'title' => 'A'));
        self::assertTrue(SettingsCopier::copy()['ok']);
        self::seed('woocommerce_upayments_settings', array('enabled' => 'yes', 'title' => 'B'));

        self::assertSame(array(), SettingsCopier::foreign_targets());
        self::assertTrue(SettingsCopier::copy()['ok'], 'the verdict matches copy()');
    }

    /**
     * @param mixed $value
     */
    private static function seed(string $name, $value): void {
        $GLOBALS['supcheckout_test_options'][$name] = $value;
    }
}
