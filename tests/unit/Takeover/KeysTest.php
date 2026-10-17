<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;

final class KeysTest extends TestCase {
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
        self::assertSame(array('options', 'transients', 'cron_hooks'), array_keys($official));
        foreach (array_merge($official['options'], $official['transients'], $official['cron_hooks'], Keys::settings_sources()) as $name) {
            self::assertNotContains($name, $copied, $name);
        }
        self::assertContains('upay_hourly_cron_job', $official['cron_hooks']);
        self::assertContains('upayments_cron_lock', $official['transients']);
    }

    public function test_gateway_and_route_identities(): void {
        self::assertSame('upayments', Keys::GATEWAY_LEGACY);
        self::assertSame('supcheckout', Keys::GATEWAY);
        self::assertSame('wc_upayments', Keys::CALLBACK_ROUTE_LEGACY);
        self::assertSame('supcheckout', Keys::CALLBACK_ROUTE);
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

    public function test_read_order_meta_prefers_the_new_name_and_falls_back_to_legacy(): void {
        $legacy_only = self::order(array('UPayments_PaymentID' => 'pay-1'));
        $both = self::order(array('UPayments_PaymentID' => 'pay-1', '_supcheckout_payment_id' => 'pay-2'));
        $neither = self::order(array());

        self::assertSame('pay-1', Keys::read_order_meta($legacy_only, 'UPayments_PaymentID'));
        self::assertSame('pay-2', Keys::read_order_meta($both, 'UPayments_PaymentID'));
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
