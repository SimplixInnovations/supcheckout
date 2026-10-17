<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;

final class KeysTest extends TestCase {
    /** @param list<string> $prefixes */
    private static function starts_with_any(string $name, array $prefixes): bool {
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,string>> */
    private static function copied_maps(): array {
        return array(Keys::order_meta(), Keys::user_meta(), Keys::user_meta_prefixes(), Keys::product_meta(),
            Keys::options(), Keys::cron_hooks(), Keys::tables());
    }

    public function test_every_copied_mapping_is_unique_prefixed_and_reversible(): void {
        $all_legacy = array();
        $all_new = array();
        foreach (self::copied_maps() as $map) {
            foreach ($map as $legacy => $new) {
                self::assertMatchesRegularExpression('/^_?supcheckout_/', $new, $new);
                self::assertSame($legacy, Keys::legacy_for($new));
                $all_legacy[] = $legacy;
                $all_new[] = $new;
            }
        }
        self::assertSame(count($all_legacy), count(array_unique($all_legacy)), 'a legacy name is in one map only');
        self::assertSame(count($all_new), count(array_unique($all_new)), 'no two legacy names share a target');
    }

    public function test_audited_names_are_in_the_right_map(): void {
        foreach (array('UPayments_order_id', 'UPayments_PaymentID', 'UPayments_WHS', 'UPayments_TranID',
            '_upay_verified_capture', '_simplixpay_upayments_provider_result_v1',
            '_simplixpay_upayments_reconcile_exhausted_v1', '_simplixpay_upayments_callback_lookups_v1',
            '_simplixpay_upayments_unapplied_captures_v1', '_simplixpay_upayments_secondary_noted_v1') as $legacy) {
            self::assertArrayHasKey($legacy, Keys::order_meta(), $legacy);
        }
        // Written with update_user_meta (MigrationExecutor.php, MigrationBatch.php): user meta, not options.
        self::assertArrayHasKey('simplixpay_upayments_migration_v1', Keys::user_meta());
        self::assertArrayNotHasKey('simplixpay_upayments_migration_v1', Keys::options());
        self::assertArrayHasKey('_simplixpay_upayments_migration_result_v1', Keys::user_meta());
        self::assertArrayHasKey('_upay_customer_token_v2_b', Keys::user_meta_prefixes());
        self::assertArrayHasKey('_upay_disable_subscription', Keys::product_meta());
        self::assertSame('supcheckout_billing_attempts', Keys::tables()['upayments_billing_attempts']);
        self::assertSame(array('woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings'), Keys::settings_sources());
        self::assertArrayNotHasKey('woocommerce_upayments_settings', Keys::options());
    }

    public function test_ephemeral_and_already_canonical_names_are_never_copied(): void {
        $copied = array_merge(...array_map('array_keys', self::copied_maps()));
        foreach (array_keys(Keys::ephemeral_prefixes()) as $prefix) {
            foreach ($copied as $legacy) {
                self::assertStringStartsNotWith($prefix, $legacy, "$legacy is ephemeral");
            }
        }
        foreach (array('_supcheckout_reconcile_schedule_failed_v1', 'supcheckout_reconciliation_sweep',
            'supcheckout_process_due_parent') as $canonical) {
            self::assertNull(Keys::legacy_for($canonical), $canonical);
            self::assertNotContains($canonical, $copied);
        }
    }

    public function test_no_new_prefix_shadows_another_family(): void {
        // A LIKE 'x%' cleanup on one prefix must never match another family (audit: supcheckout_pm_* overlap).
        $prefixes = array_merge(array_values(Keys::ephemeral_prefixes()), array('supcheckout_pm_lkg_v1_'));
        foreach ($prefixes as $a) {
            foreach ($prefixes as $b) {
                if ($a !== $b) {
                    self::assertStringStartsNotWith($a, $b, "$a shadows $b");
                }
            }
        }
    }

    public function test_official_only_and_settings_sources_are_never_copied(): void {
        $copied = array_merge(...array_map('array_keys', self::copied_maps()));
        $official = Keys::official_only();
        self::assertSame(
            array('options', 'option_prefixes', 'transients', 'cron_hooks', 'cron_hook_prefixes', 'tables'),
            array_keys($official)
        );
        foreach (array_merge(Keys::settings_sources(), ...array_values($official)) as $name) {
            self::assertNotContains($name, $copied, $name);
            foreach ($copied as $legacy) {
                self::assertStringStartsNotWith($name, $legacy, "$legacy shadows official-only $name");
            }
        }
        self::assertContains('upay_hourly_cron_job', $official['cron_hooks']);
        self::assertContains('upayments_cron_lock', $official['transients']);
        // Official 3.1.1 uninstall references both; SUPCheckout's dead Subscription\Manager listener writes the table.
        self::assertContains('upay_subscriptions', $official['tables']);
        self::assertContains('upay_subscription_settings', $official['options']);
    }

    public function test_update_checker_names_are_version_agnostic_prefixes(): void {
        $official = Keys::official_only();
        foreach (array('external_updates-upayments-V2.2.1', 'external_updates-upayments') as $option) {
            self::assertTrue(self::starts_with_any($option, $official['option_prefixes']), $option);
        }
        foreach (array('puc_cron_check_updates-upayments-V2.2.1', 'puc_cron_check_updates-upayments-V3.1.1') as $hook) {
            self::assertTrue(self::starts_with_any($hook, $official['cron_hook_prefixes']), $hook);
        }
        self::assertNotContains('external_updates-upayments-V2.2.1', $official['options']);
        self::assertNotContains('puc_cron_check_updates-upayments-V2.2.1', $official['cron_hooks']);
    }

    public function test_gateway_and_route_identities(): void {
        self::assertSame('upayments', Keys::GATEWAY_LEGACY);
        self::assertSame('supcheckout', Keys::GATEWAY);
        self::assertSame('wc_upayments', Keys::CALLBACK_ROUTE_LEGACY);
        self::assertSame('supcheckout', Keys::CALLBACK_ROUTE);
        self::assertSame('upayments', Keys::EXTENSION_NAMESPACE_LEGACY);
        self::assertSame('supcheckout', Keys::EXTENSION_NAMESPACE);
        // wc_get_orders(array('payment_method' => Keys::gateway_ids())): historical orders keep 'upayments'.
        self::assertSame(array('upayments', 'supcheckout'), Keys::gateway_ids());
        foreach (Keys::gateway_ids() as $id) {
            self::assertTrue(Keys::is_our_gateway($id), $id);
        }
        self::assertTrue(Keys::is_our_gateway('upayments'));
        self::assertTrue(Keys::is_our_gateway('supcheckout'));
        self::assertFalse(Keys::is_our_gateway('UPayments'));
        self::assertFalse(Keys::is_our_gateway('bacs'));
        self::assertFalse(Keys::is_our_gateway(''));
    }

    public function test_meta_names_returns_new_then_legacy_and_rejects_unclassified_names(): void {
        self::assertSame(array('_supcheckout_payment_id', 'UPayments_PaymentID'), Keys::meta_names('UPayments_PaymentID'));
        self::assertSame(array('supcheckout_process_subscriptions', 'upay_process_subscriptions'), Keys::meta_names('upay_process_subscriptions'));

        $this->expectException(\InvalidArgumentException::class);
        Keys::meta_names('_upay_brand_new_key');
    }

    public function test_h12_provenance_keys_resolve_through_the_prefix_map_in_both_directions(): void {
        $legacy = '_upay_customer_token_v2_b1_abc123';
        $new_key = '_supcheckout_customer_token_v2_b1_abc123';

        self::assertSame(array($new_key, $legacy), Keys::user_meta_key_pair($legacy));
        self::assertSame(array($new_key, $legacy), Keys::user_meta_key_pair($new_key));
        self::assertSame(array($new_key, $legacy), Keys::meta_names($legacy));
        self::assertSame($legacy, Keys::legacy_for($new_key));
        self::assertSame($legacy, Keys::write_key($legacy), 'before cutover H12 writes stay on the legacy key');

        // Exact user meta resolves the same way; unrelated and bare-prefix-only names do not.
        self::assertSame(
            array('supcheckout_migration_v1', 'simplixpay_upayments_migration_v1'),
            Keys::user_meta_key_pair('supcheckout_migration_v1')
        );
        self::assertNull(Keys::user_meta_key_pair('_upay_customer_token_v2_b'), 'a prefix alone is not a key');
        self::assertNull(Keys::user_meta_key_pair('_supcheckout_customer_token_v2_b'));
        self::assertNull(Keys::user_meta_key_pair('UPayments_PaymentID'), 'order meta is not user meta');
        self::assertNull(Keys::user_meta_key_pair('billing_phone'));
        self::assertNull(Keys::legacy_for('_supcheckout_unknown_v1'));
    }

    public function test_the_scope_is_sliced_by_the_matched_prefix_length(): void {
        // CustomerTokenIdentity slices the scope with strlen($meta_prefix); the two prefixes differ in length.
        $scope = str_repeat('a', 32);
        foreach (Keys::user_meta_prefixes() as $legacy_prefix => $new_prefix) {
            self::assertNotSame(strlen($legacy_prefix), strlen($new_prefix));
            foreach (array($legacy_prefix, $new_prefix) as $prefix) {
                $key = $prefix . '1_' . $scope;
                $pair = Keys::user_meta_key_pair($key);
                self::assertNotNull($pair, $key);
                self::assertSame($scope, substr($pair[0], strlen($new_prefix . '1_')));
                self::assertSame($scope, substr($pair[1], strlen($legacy_prefix . '1_')));
            }
        }
    }

    public function test_before_cutover_writes_and_tables_stay_on_legacy_names(): void {
        self::assertSame('UPayments_PaymentID', Keys::write_key('UPayments_PaymentID'));
        self::assertSame('upayments_token_identity_secret_v2', Keys::write_key('upayments_token_identity_secret_v2'));

        $saved = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
        try {
            self::assertSame('wp_upayments_billing_attempts', Keys::table('upayments_billing_attempts'));
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    public function test_table_rejects_an_unregistered_table(): void {
        $saved = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
        try {
            $this->expectException(\InvalidArgumentException::class);
            Keys::table('upay_subscriptions');
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    public function test_persisted_names_that_intentionally_keep_their_name_are_recorded(): void {
        $unchanged = Keys::unchanged_persisted();
        foreach (array('_custom_field_id', 'custom_type', 'Special Feature', 'chosen_payment_method', 'payment_method') as $name) {
            self::assertArrayHasKey($name, $unchanged, $name);
        }
        $copied = array_merge(...array_map('array_keys', self::copied_maps()));
        foreach ($unchanged as $name => $reason) {
            self::assertIsString($reason);
            self::assertNotSame('', trim($reason), $name);
            self::assertNotContains($name, $copied, $name);
            self::assertNull(Keys::legacy_for($name), $name);
        }
    }

    public function test_legacy_ephemeral_prefixes_are_listed_longest_match_first(): void {
        // A first-match prefix matcher must never classify a later, longer family under an earlier, shorter one.
        $legacy = array_keys(Keys::ephemeral_prefixes());
        foreach ($legacy as $i => $earlier) {
            foreach (array_slice($legacy, $i + 1) as $later) {
                self::assertStringStartsNotWith($earlier, $later, "$earlier is listed before the longer $later");
            }
        }
        self::assertArrayHasKey('upay_ctk_bootstrap_b', Keys::ephemeral_prefixes(), 'bootstrap lock is its own family');
    }

    public function test_customer_token_lock_names_fit_mysql_get_lock_with_the_widest_scope_and_user_id(): void {
        // get_lock_name(): PREFIX . 32-hex scope . '_' . user_id; user_id is BIGINT UNSIGNED (20 digits).
        $widest_suffix = strlen(str_repeat('f', 32) . '_' . '18446744073709551615');
        // The unit suite stubs CustomerTokenIdentity, so read the shipped constant from its source.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Token/CustomerTokenIdentity.php');
        self::assertSame(1, preg_match('/const LOCK_MAX_LENGTH = (\d+);/', $source, $match));
        self::assertMatchesRegularExpression("/const LOCK_PREFIX = 'upay_ctk_';/", $source);
        $limit = (int) $match[1];
        self::assertSame(64, $limit, 'MySQL GET_LOCK() name limit');
        foreach (array('upay_ctk_', Keys::ephemeral_prefixes()['upay_ctk_']) as $prefix) {
            self::assertLessThanOrEqual($limit - 2, strlen($prefix) + $widest_suffix, "$prefix leaves a margin");
        }
        // get_bootstrap_lock_name(): PREFIX . blog_id (BIGINT).
        $bootstrap = Keys::ephemeral_prefixes()['upay_ctk_bootstrap_b'];
        self::assertLessThanOrEqual($limit - 2, strlen($bootstrap) + 20);
    }

    public function test_before_cutover_read_order_meta_reads_exactly_the_legacy_name(): void {
        // Before cutover every runtime write lands on the legacy name, so it is
        // authoritative: a takeover copy (or a stale copy after a rollback) under the new
        // name never shadows it. New-name-first reads after cutover: DualReadTest.
        $legacy_only = self::order(array('UPayments_PaymentID' => 'pay-1'));
        $both = self::order(array('UPayments_PaymentID' => 'pay-1', '_supcheckout_payment_id' => 'pay-2'));
        $new_only = self::order(array('_supcheckout_payment_id' => 'pay-2'));
        $neither = self::order(array());

        self::assertSame('pay-1', Keys::read_order_meta($legacy_only, 'UPayments_PaymentID'));
        self::assertSame('pay-1', Keys::read_order_meta($both, 'UPayments_PaymentID'));
        self::assertSame('', Keys::read_order_meta($new_only, 'UPayments_PaymentID'));
        self::assertSame('', Keys::read_order_meta($neither, 'UPayments_PaymentID'));
    }

    /**
     * @param array<string,string> $meta
     */
    private static function order(array $meta): object {
        return new class($meta) {
            /** @var array<string,string> */
            private $meta;

            /** @param array<string,string> $meta */
            public function __construct(array $meta) {
                $this->meta = $meta;
            }

            public function get_meta(string $key): string {
                return $this->meta[$key] ?? '';
            }
        };
    }
}
