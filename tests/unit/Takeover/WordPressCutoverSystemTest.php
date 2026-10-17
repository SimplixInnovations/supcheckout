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

    private static function boot(bool $network): void {
        $plugins = sys_get_temp_dir() . '/supcheckout-cutover-system-' . getmypid();
        @mkdir($plugins . '/upayments', 0777, true);
        file_put_contents($plugins . '/upayments/UPayments.php', "<?php\n");
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
