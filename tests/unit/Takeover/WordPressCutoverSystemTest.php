<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\WordPressCutoverSystem;

/**
 * 0b Task 9 review: the official plugin is deactivated for this site only
 * (network_wide = false), and the post-check still sees a network activation, so a
 * network-activated official plugin is never reported as deactivated. Separate processes:
 * WP_PLUGIN_DIR and deactivate_plugins() are defined per test.
 */
final class WordPressCutoverSystemTest extends TestCase {
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_site_activation_is_deactivated_for_this_site_only(): void {
        self::boot(false);

        $system = new WordPressCutoverSystem();
        self::assertSame('upayments/UPayments.php', $system->official_plugin());
        self::assertTrue($system->deactivate_plugin('upayments/UPayments.php'));

        self::assertSame(array(array(array('upayments/UPayments.php'), false, false)), $GLOBALS['cutover_system_deactivations']);
        self::assertNull($system->official_plugin());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_network_activation_is_never_turned_off_and_never_reported_as_deactivated(): void {
        self::boot(true);

        $system = new WordPressCutoverSystem();
        self::assertSame('upayments/UPayments.php', $system->official_plugin());
        self::assertFalse($system->deactivate_plugin('upayments/UPayments.php'), 'the post-check still sees the network activation');

        self::assertSame(array(array(array('upayments/UPayments.php'), false, false)), $GLOBALS['cutover_system_deactivations'], 'network_wide is explicitly false');
        self::assertArrayHasKey('upayments/UPayments.php', $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'], 'other sites keep the plugin');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_every_active_official_install_is_deactivated_and_none_may_remain(): void {
        self::boot(false);
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('upayments/UPayments.php', 'upayments-old/UPayments.php');

        $system = new WordPressCutoverSystem();
        self::assertTrue($system->deactivate_plugin('upayments/UPayments.php'));

        self::assertSame(array(array(array('upayments/UPayments.php', 'upayments-old/UPayments.php'), false, false)), $GLOBALS['cutover_system_deactivations']);
        self::assertNull($system->official_plugin(), 'no official install is left active');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_second_install_that_stays_active_fails_the_post_check(): void {
        self::boot(false);
        $GLOBALS['supcheckout_test_multisite'] = true;
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array('upayments-old/UPayments.php' => 1);

        $system = new WordPressCutoverSystem();
        self::assertFalse($system->deactivate_plugin('upayments/UPayments.php'), 'upayments-old is still active');
        self::assertSame('upayments-old/UPayments.php', $system->official_plugin());
    }

    /**
     * 0b Task 10: rollback reactivates the official plugin for this site only, and only an
     * installed official plugin outside SUPCheckout's own directory counts.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_official_plugin_is_reactivated_for_this_site_only(): void {
        self::boot(false);
        $root = dirname(__DIR__, 3);
        \supcheckout_test_reset_plugin_registry(dirname($root));
        $own = basename($root);
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array();
        $GLOBALS['cutover_system_installed'] = array(
            'upayments/UPayments.php' => array(),
            $own . '/UPayments.php' => array(),
            $own . '/supcheckout.php' => array(),
            'hello.php' => array(),
        );
        $GLOBALS['cutover_system_installed']['upayments-old/UPayments.php'] = array();
        // Including the official main file in a process where SUPCheckout is booted can fatal
        // ("Cannot redeclare"): this one throws if anything ever includes it.
        file_put_contents(WP_PLUGIN_DIR . '/upayments/UPayments.php', "<?php\n/**\n * Plugin Name: UPayments\n */\nthrow new \\Error('the official main file was included');\n");
        $GLOBALS['cutover_system_activations'] = array();
        eval('function get_plugins($folder = "") { return $GLOBALS["cutover_system_installed"]; }
        function activate_plugin($plugin, $redirect = "", $network_wide = false, $silent = false) {
            $GLOBALS["cutover_system_activations"][] = $plugin;
            return null;
        }');

        $system = new WordPressCutoverSystem();
        self::assertSame(array('upayments-old/UPayments.php', 'upayments/UPayments.php'), $system->installed_official_plugins(), 'the own-directory shim is never the official plugin');
        self::assertFalse($system->activate_plugin('upayments-old/UPayments.php'), 'a main file without a plugin header is refused');
        self::assertFalse($system->activate_plugin('other/UPayments.php'), 'only an installed official plugin');
        self::assertSame(array(), $GLOBALS['supcheckout_test_options']['active_plugins']);

        self::assertTrue($system->activate_plugin('upayments/UPayments.php'));
        self::assertSame(array(), $GLOBALS['cutover_system_activations'], 'activate_plugin() (which includes the file) is never called');
        self::assertSame(array('upayments/UPayments.php'), $GLOBALS['supcheckout_test_options']['active_plugins'], 'this site only');
        self::assertArrayNotHasKey('active_sitewide_plugins', $GLOBALS['supcheckout_test_site_options']);
        self::assertSame('upayments/UPayments.php', $system->official_plugin());
        self::assertFalse($system->activate_plugin('upayments/UPayments.php'), 'never twice');
    }

    private static function boot(bool $network): void {
        $plugins = sys_get_temp_dir() . '/supcheckout-cutover-system-' . getmypid();
        @mkdir($plugins . '/upayments', 0777, true);
        @mkdir($plugins . '/upayments-old', 0777, true);
        file_put_contents($plugins . '/upayments/UPayments.php', "<?php\n");
        file_put_contents($plugins . '/upayments-old/UPayments.php', "<?php\n");
        define('WP_PLUGIN_DIR', $plugins);
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_plugin_registry(dirname(__DIR__, 3) . '/..');
        $GLOBALS['supcheckout_test_multisite'] = $network;
        $GLOBALS['cutover_system_deactivations'] = array();
        if ($network) {
            $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array('upayments/UPayments.php' => 1);
        } else {
            $GLOBALS['supcheckout_test_options']['active_plugins'] = array('upayments/UPayments.php');
        }
        // WordPress's deactivate_plugins() with network_wide false removes the site entry only.
        eval('function deactivate_plugins($plugins, $silent = false, $network_wide = null) {
            $GLOBALS["cutover_system_deactivations"][] = array((array) $plugins, $silent, $network_wide);
            $active = isset($GLOBALS["supcheckout_test_options"]["active_plugins"]) ? (array) $GLOBALS["supcheckout_test_options"]["active_plugins"] : array();
            $GLOBALS["supcheckout_test_options"]["active_plugins"] = array_values(array_diff($active, (array) $plugins));
            if ($network_wide !== false && isset($GLOBALS["supcheckout_test_site_options"]["active_sitewide_plugins"])) {
                foreach ((array) $plugins as $plugin) {
                    unset($GLOBALS["supcheckout_test_site_options"]["active_sitewide_plugins"][$plugin]);
                }
            }
        }');
    }
}
