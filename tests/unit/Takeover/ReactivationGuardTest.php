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
                array('action', 'activate_plugin', array(ReactivationGuard::class, 'before_activation'), 0, 2),
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
        self::assertSame(array('switched' => array(self::OFFICIAL)), get_transient(ReactivationGuard::NOTICE_TRANSIENT), 'one admin error is queued');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('guarded_states')]
    public function test_a_switched_store_refuses_a_network_activation_of_the_official_plugin(string $status): void {
        self::boot($status);

        $kept = ReactivationGuard::filter_network_plugins(array(self::OWN => 1, self::OFFICIAL => 2), array(self::OWN => 1));

        self::assertSame(array(self::OWN => 1), $kept);
        self::assertSame(array('switched' => array(self::OFFICIAL)), get_transient(ReactivationGuard::NOTICE_TRANSIENT));
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

    /**
     * Review round 1 #2: on multisite a network activation is refused in every state (fail
     * closed: Preflight treats a network-activated official plugin as unsupported, and the
     * state read in a network-admin request is the main site's). Site activations keep the
     * per-site state rule.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('open_states')]
    public function test_on_multisite_a_network_activation_is_refused_in_every_state(string $status): void {
        self::boot($status);
        $GLOBALS['supcheckout_test_multisite'] = true;

        self::assertSame(array(self::OWN => 1), ReactivationGuard::filter_network_plugins(array(self::OWN => 1, self::OFFICIAL => 2), array(self::OWN => 1)));
        self::assertSame(array('network' => array(self::OFFICIAL)), get_transient(ReactivationGuard::NOTICE_TRANSIENT));
        $value = array(self::OFFICIAL, self::OWN);
        self::assertSame($value, ReactivationGuard::filter_site_plugins($value, array(self::OWN)), 'a site activation before the switch still passes');
    }

    /**
     * Review round 1 #1: WordPress runs the plugin's activation hooks before it writes the
     * option, so the option filter alone would still let the official plugin's activation
     * code run (creating or overwriting the checkout page, for example). A refused activation
     * runs neither `activate_{plugin}` nor the official plugin's own `activated_plugin`
     * callbacks; other plugins' callbacks stay.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('guarded_states')]
    public function test_a_refused_activation_never_runs_the_official_activation_hooks(string $status): void {
        self::boot($status);
        self::arm_official_hooks();

        ReactivationGuard::before_activation(self::OFFICIAL, false);
        do_action('activate_' . self::OFFICIAL, false);

        self::assertSame(array(), $GLOBALS['supcheckout_test_official_calls'], 'the official activation hook never runs');
        self::assertSame(array('foreign'), self::activated_plugin_callbacks(), "only the official plugin's activated_plugin callback is removed");
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('open_states')]
    public function test_an_allowed_activation_runs_the_official_activation_hooks(string $status): void {
        self::boot($status);
        self::arm_official_hooks();

        ReactivationGuard::before_activation(self::OFFICIAL, false);
        ReactivationGuard::before_activation('woocommerce/woocommerce.php', false);
        do_action('activate_' . self::OFFICIAL, false);

        self::assertSame(array('activate'), $GLOBALS['supcheckout_test_official_calls']);
        self::assertSame(array('official', 'foreign'), self::activated_plugin_callbacks());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_on_multisite_a_refused_network_activation_runs_no_official_hook_in_any_state(): void {
        self::boot(TakeoverState::IDLE);
        $GLOBALS['supcheckout_test_multisite'] = true;
        self::arm_official_hooks();

        ReactivationGuard::before_activation(self::OFFICIAL, true);
        do_action('activate_' . self::OFFICIAL, true);

        self::assertSame(array(), $GLOBALS['supcheckout_test_official_calls']);
        self::assertSame(array('foreign'), self::activated_plugin_callbacks());
    }

    /** Review round 1 #3: a finalized store is never told to roll back. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_notice_points_a_cut_over_store_to_rollback_and_tells_a_finalized_store_the_switch_is_final(): void {
        self::boot(TakeoverState::CUT_OVER);
        ReactivationGuard::filter_site_plugins(array(self::OFFICIAL), array());
        self::assertStringContainsString('roll back the switch in SUPCheckout', self::render());

        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => TakeoverState::FINALIZED, 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
        ReactivationGuard::filter_site_plugins(array(self::OFFICIAL), array());
        $finalized = self::render();
        self::assertStringContainsString('The switch is final', $finalized);
        self::assertStringNotContainsString('roll back', $finalized);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_refused_network_activation_explains_that_network_activation_is_unsupported(): void {
        self::boot(TakeoverState::IDLE);
        $GLOBALS['supcheckout_test_multisite'] = true;
        ReactivationGuard::filter_network_plugins(array(self::OFFICIAL => 2), array());

        $html = self::render();

        self::assertStringContainsString('SUPCheckout kept the UPayments plugin switched off for the network.', $html);
        self::assertStringContainsString('Activate UPayments on individual sites instead.', $html);
        self::assertStringNotContainsString('already switched', $html);
    }

    /**
     * Review round 2 #1: an official main file installed at the plugins root (`UPayments.php`,
     * dirname '.') must not turn "its own directory" into the whole plugins directory. Only
     * callbacks defined in that one file are removed; another plugin's survive.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_root_level_official_file_removes_only_its_own_activated_plugin_callbacks(): void {
        self::boot(TakeoverState::CUT_OVER);
        self::arm_official_hooks();
        file_put_contents(WP_PLUGIN_DIR . '/UPayments.php', "<?php\nfunction supcheckout_test_root_official_activated(\$plugin) { \$GLOBALS['supcheckout_test_official_calls'][] = 'root-activated'; }\n");
        file_put_contents(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php', "<?php\nfunction supcheckout_test_other_plugin_activated(\$plugin) {}\n");
        require_once WP_PLUGIN_DIR . '/UPayments.php';
        require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        $GLOBALS['wp_filter']['activated_plugin']->callbacks[10]['root_official'] = array('function' => 'supcheckout_test_root_official_activated', 'accepted_args' => 1);
        $GLOBALS['wp_filter']['activated_plugin']->callbacks[10]['other_plugin'] = array('function' => 'supcheckout_test_other_plugin_activated', 'accepted_args' => 1);

        ReactivationGuard::before_activation('UPayments.php', false);

        self::assertSame(
            array('official', 'foreign', 'other_plugin'),
            self::activated_plugin_callbacks(),
            "only the root-level official file's callback is removed; upayments/, woocommerce/ and this test file keep theirs"
        );
    }

    /**
     * The official main file defines the spies (WordPress includes it before the activation
     * hooks run); a foreign closure from this test file shares activated_plugin.
     */
    private static function arm_official_hooks(): void {
        require_once dirname(__DIR__, 2) . '/support/cache-purge-wp-stubs.php';
        if (!function_exists('remove_all_actions')) {
            eval('function remove_all_actions($hook_name, $priority = false) {
                $GLOBALS["supcheckout_test_hook_calls"] = array_values(array_filter($GLOBALS["supcheckout_test_hook_calls"], function ($call) use ($hook_name) { return $call[1] !== $hook_name; }));
                return true;
            }');
        }
        if (!function_exists('remove_action')) {
            eval('function remove_action($hook_name, $callback, $priority = 10) {
                $removed = false;
                foreach ($GLOBALS["wp_filter"][$hook_name]->callbacks[$priority] as $id => $entry) {
                    if ($entry["function"] === $callback) { unset($GLOBALS["wp_filter"][$hook_name]->callbacks[$priority][$id]); $removed = true; }
                }
                return $removed;
            }');
        }
        $GLOBALS['supcheckout_test_official_calls'] = array();
        file_put_contents(WP_PLUGIN_DIR . '/upayments/UPayments.php', "<?php\nfunction supcheckout_test_official_activate() { \$GLOBALS['supcheckout_test_official_calls'][] = 'activate'; }\nfunction supcheckout_test_official_activated(\$plugin) { \$GLOBALS['supcheckout_test_official_calls'][] = 'activated'; }\n");
        require_once WP_PLUGIN_DIR . '/upayments/UPayments.php';
        add_action('activate_' . self::OFFICIAL, 'supcheckout_test_official_activate');
        $GLOBALS['wp_filter']['activated_plugin'] = (object) array('callbacks' => array(10 => array(
            'official' => array('function' => 'supcheckout_test_official_activated', 'accepted_args' => 1),
            'foreign' => array('function' => static function () {}, 'accepted_args' => 1),
        )));
    }

    /** @return list<string> */
    private static function activated_plugin_callbacks(): array {
        return array_map('strval', array_keys($GLOBALS['wp_filter']['activated_plugin']->callbacks[10]));
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
