<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use PHPUnit\Framework\Attributes\DataProvider;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0c Task 10 security review (owner ruling 2026-10-19): `manage_woocommerce` stays on every
 * route, and a request that consents to deactivating or reactivating the official plugin,
 * or a real finalize, also needs `activate_plugins`. A Shop Manager (manage_woocommerce
 * without activate_plugins) gets 403 supcheckout_capability_plugins with no operation and
 * no rate slot spent; everything else stays open to them.
 */
final class PluginCapabilityTest extends RestTestCase {
    private static function as_shop_manager(): void {
        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array('activate_plugins');
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, string, string}>
     *         route, takeover state, body, rate bucket, operation kind
     */
    public static function gatedProvider(): array {
        return array(
            'switch deactivate_official' => array('/onboarding/switch', TakeoverState::IDLE, array('confirm' => true, 'deactivate_official' => true), 'switch', 'switch'),
            'rollback deactivate_official' => array('/onboarding/rollback', TakeoverState::CUT_OVER, array('confirm' => true, 'deactivate_official' => true), 'rollback', 'rollback'),
            'rollback reactivate_official' => array('/onboarding/rollback', TakeoverState::CUT_OVER, array('confirm' => true, 'reactivate_official' => true), 'rollback', 'rollback'),
            'abort reactivate_official' => array('/onboarding/abort', TakeoverState::VERIFIED, array('confirm' => true, 'reactivate_official' => true), 'abort', 'abort'),
            'finalize' => array('/onboarding/finalize', TakeoverState::CUT_OVER, array('confirm' => 'FINALIZE'), 'finalize', 'finalize'),
            'finalize force_after_review' => array('/onboarding/finalize', TakeoverState::CUT_OVER, array('confirm' => 'FINALIZE', 'force_after_review' => true), 'finalize', 'finalize'),
        );
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}> route, takeover state, body
     */
    public static function openProvider(): array {
        return array(
            'preflight' => array('/onboarding/preflight', TakeoverState::IDLE, array()),
            'setup checks' => array('/setup/checks', TakeoverState::IDLE, array()),
            'self-test' => array('/onboarding/self-test', TakeoverState::CUT_OVER, array()),
            'switch without the official-plugin flag' => array('/onboarding/switch', TakeoverState::IDLE, array('confirm' => true)),
            'rollback without the official-plugin flags' => array('/onboarding/rollback', TakeoverState::CUT_OVER, array('confirm' => true)),
            'abort without reactivate_official' => array('/onboarding/abort', TakeoverState::VERIFIED, array('confirm' => true)),
            'finalize dry run' => array('/onboarding/finalize', TakeoverState::CUT_OVER, array('dry_run' => true)),
        );
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('gatedProvider')]
    public function test_a_shop_manager_is_refused_403_without_an_operation_or_a_rate_slot(string $route, string $state, array $json, string $bucket, string $kind): void {
        unset($kind);
        self::state($state, time() - 40 * 86400);
        self::as_shop_manager();

        $response = self::serve('POST', $route, $json);

        self::assertSame(array(403, 'supcheckout_capability_plugins'), array($response->get_status(), self::error_code($response)), (string) json_encode($response->get_data()));
        self::assertStringContainsString('activate_plugins', (string) self::body($response)['message']);
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);
        self::assertNull(Operation::current(), 'no operation');
        self::assertArrayNotHasKey(RateLimiter::OPTION_PREFIX . $bucket, $GLOBALS['supcheckout_test_options'], 'no rate slot spent');
        self::assertSame(array(), self::scheduled(AsyncDriver::HOOK));
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('gatedProvider')]
    public function test_an_administrator_passes_every_gated_variant(string $route, string $state, array $json, string $bucket, string $kind): void {
        unset($bucket);
        self::state($state, time() - 40 * 86400);

        $response = self::serve('POST', $route, $json);

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertSame($kind, Operation::current()['kind']);
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('openProvider')]
    public function test_a_shop_manager_keeps_every_other_start(string $route, string $state, array $json): void {
        self::state($state, time() - 40 * 86400);
        self::as_shop_manager();

        $response = self::serve('POST', $route, $json);

        self::assertSame(202, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertTrue(Operation::is_running(Operation::current()));
    }

    public function test_a_shop_manager_still_reads_the_status(): void {
        self::as_shop_manager();

        self::assertSame(200, self::serve('GET', '/onboarding/status')->get_status());
    }

    public function test_the_nonce_is_checked_before_the_plugin_capability(): void {
        self::as_shop_manager();

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), false);

        self::assertSame(array(403, 'supcheckout_nonce_invalid'), array($response->get_status(), self::error_code($response)));
    }
}
