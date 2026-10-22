<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\ReactivationGuard;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * A5-01 (non-money part): once the store has switched (cut_over or finalized), activating
 * the official UPayments plugin again would pause SUPCheckout and strand every payment
 * started after the switch on the `supcheckout` callback route. The guard filters the
 * `active_plugins` option (and its network twin) so the Plugins screen, bulk activation,
 * WP-CLI and direct option writes are all covered, and it queues one admin error.
 *
 * It never blocks before the switch or in rolled_back: Rollback's and Abort's own
 * reactivation writes happen in preflight_ok, and an administrator may reactivate the
 * official plugin by hand after a rollback.
 *
 * Each test runs in its own process because WP_PLUGIN_DIR is a constant.
 */
final class ReactivationGuardTest extends TestCase {
    private const OWN = 'supcheckout/supcheckout.php';
    private const OFFICIAL = 'upayments/UPayments.php';

    /** @return array<string, array{0:string}> */
    public static function guarded_states(): array {
        return array(
            'cut_over' => array(TakeoverState::CUT_OVER),
            'finalized' => array(TakeoverState::FINALIZED),
        );
    }

    /** @return array<string, array{0:string}> */
    public static function open_states(): array {
        return array(
            'idle' => array(TakeoverState::IDLE),
            'preflight_ok' => array(TakeoverState::PREFLIGHT_OK),
            'copying' => array(TakeoverState::COPYING),
            'verified' => array(TakeoverState::VERIFIED),
            'rolled_back' => array(TakeoverState::ROLLED_BACK),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_register_adds_the_site_and_network_option_filters_and_the_notice(): void {
        self::boot(TakeoverState::CUT_OVER);

        self::assertSame(
            array(
                array('filter', 'pre_update_option_active_plugins', array(ReactivationGuard::class, 'filter_site_plugins'), 10, 2),
                array('filter', 'pre_update_site_option_active_sitewide_plugins', array(ReactivationGuard::class, 'filter_network_plugins'), 10, 2),
                array('action', 'admin_notices', array(ReactivationGuard::class, 'render_notice'), 10, 1),
                array('action', 'network_admin_notices', array(ReactivationGuard::class, 'render_notice'), 10, 1),
            ),
            $GLOBALS['supcheckout_test_hook_calls']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('guarded_states')]
    public function test_a_switched_store_refuses_a_site_activation_of_the_official_plugin(string $status): void {
        self::boot($status);

        $kept = ReactivationGuard::filter_site_plugins(array(self::OFFICIAL, self::OWN), array(self::OWN));

        self::assertSame(array(self::OWN), $kept, 'the official entry is stripped from the new value');
        self::assertSame(array(self::OFFICIAL), get_transient(ReactivationGuard::NOTICE_TRANSIENT), 'one admin error is queued');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('guarded_states')]
    public function test_a_switched_store_refuses_a_network_activation_of_the_official_plugin(string $status): void {
        self::boot($status);

        $kept = ReactivationGuard::filter_network_plugins(array(self::OWN => 1, self::OFFICIAL => 2), array(self::OWN => 1));

        self::assertSame(array(self::OWN => 1), $kept);
        self::assertSame(array(self::OFFICIAL), get_transient(ReactivationGuard::NOTICE_TRANSIENT));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('open_states')]
    public function test_before_the_switch_and_after_a_rollback_the_activation_passes_through(string $status): void {
        self::boot($status);

        $value = array(self::OFFICIAL, self::OWN);
        self::assertSame($value, ReactivationGuard::filter_site_plugins($value, array(self::OWN)));
        self::assertSame(array(self::OFFICIAL => 2), ReactivationGuard::filter_network_plugins(array(self::OFFICIAL => 2), array()));
        self::assertFalse(get_transient(ReactivationGuard::NOTICE_TRANSIENT), 'no notice is queued');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_only_a_newly_added_loadable_official_main_file_is_refused(): void {
        self::boot(TakeoverState::CUT_OVER);

        $others = array('supcheckout/UPayments.php', 'woocommerce/woocommerce.php', 'removed-upayments/UPayments.php', self::OWN);
        self::assertSame($others, ReactivationGuard::filter_site_plugins($others, array(self::OWN)), 'the own-directory shim, unrelated plugins and a missing install pass');
        $already = array(self::OFFICIAL, self::OWN);
        self::assertSame($already, ReactivationGuard::filter_site_plugins($already, $already), 'an entry that is already active is never removed by the guard');
        self::assertSame('not-an-array', ReactivationGuard::filter_site_plugins('not-an-array', array()));
        self::assertFalse(get_transient(ReactivationGuard::NOTICE_TRANSIENT));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_queued_notice_renders_once_for_plugin_administrators_only(): void {
        self::boot(TakeoverState::CUT_OVER);
        ReactivationGuard::filter_site_plugins(array(self::OFFICIAL, self::OWN), array(self::OWN));

        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array('activate_plugins');
        self::assertSame('', self::render(), 'a user who cannot manage plugins sees nothing');
        self::assertNotFalse(get_transient(ReactivationGuard::NOTICE_TRANSIENT), 'and the notice stays queued for an administrator');

        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array();
        $html = self::render();
        self::assertStringContainsString('notice notice-error', $html);
        self::assertStringContainsString('SUPCheckout kept the UPayments plugin switched off.', $html);
        self::assertStringContainsString('already switched to SUPCheckout', $html);
        self::assertStringContainsString('upayments/UPayments.php', $html);
        self::assertSame('', self::render(), 'the notice is shown once');
    }

    private static function render(): string {
        ob_start();
        ReactivationGuard::render_notice();
        return (string) ob_get_clean();
    }

    private static function boot(string $status): void {
        $plugins = sys_get_temp_dir() . '/supcheckout-reactivation-guard-' . getmypid();
        foreach (array('upayments', 'supcheckout', 'woocommerce') as $dir) {
            @mkdir($plugins . '/' . $dir, 0777, true);
        }
        file_put_contents($plugins . '/upayments/UPayments.php', "<?php\n");
        file_put_contents($plugins . '/supcheckout/UPayments.php', "<?php\n");
        file_put_contents($plugins . '/woocommerce/woocommerce.php', "<?php\n");
        define('WP_PLUGIN_DIR', $plugins);
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_availability();
        \supcheckout_test_reset_migration_bootstrap();
        \supcheckout_test_reset_migration_admin();
        \supcheckout_test_reset_plugin_registry($plugins);
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
        ReactivationGuard::register($plugins . '/' . self::OWN);
    }
}
