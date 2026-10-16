<?php

namespace Simplixi\SUPCheckout\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Bootstrap\BasenameMigration;

final class BasenameMigrationTest extends TestCase {
    /** @var string */
    private $plugins;

    protected function setUp(): void {
        $this->plugins = sys_get_temp_dir() . '/supcheckout-basename-' . getmypid() . '/plugins';
        foreach (array('supcheckout', 'supcheckout-staging', 'no-main') as $dir) {
            @mkdir($this->plugins . '/' . $dir, 0777, true);
            file_put_contents($this->plugins . '/' . $dir . '/UPayments.php', "<?php\n");
        }
        file_put_contents($this->plugins . '/supcheckout/supcheckout.php', "<?php\n");
        file_put_contents($this->plugins . '/supcheckout-staging/supcheckout.php', "<?php\n");
        supcheckout_test_reset_wp_options();
        supcheckout_test_reset_plugin_registry($this->plugins);
    }

    public function test_single_site_entry_moves_in_place_without_a_duplicate(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('akismet/akismet.php', 'supcheckout/UPayments.php', 'woocommerce/woocommerce.php');

        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(
            array('akismet/akismet.php', 'supcheckout/supcheckout.php', 'woocommerce/woocommerce.php'),
            get_option('active_plugins')
        );
        self::assertSame(1, $this->option_writes('active_plugins'));
    }

    public function test_both_present_keeps_the_main_entry_once(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('supcheckout/supcheckout.php', 'akismet/akismet.php', 'supcheckout/UPayments.php');
        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(array('supcheckout/supcheckout.php', 'akismet/akismet.php'), get_option('active_plugins'));

        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('supcheckout/UPayments.php', 'akismet/akismet.php', 'supcheckout/supcheckout.php');
        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(array('supcheckout/supcheckout.php', 'akismet/akismet.php'), get_option('active_plugins'));
    }

    public function test_network_activation_key_moves_with_its_timestamp(): void {
        $GLOBALS['supcheckout_test_multisite'] = true;
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('akismet/akismet.php');
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array(
            'woocommerce/woocommerce.php' => 1700000000,
            'supcheckout/UPayments.php' => 1700000123,
            'upayments/UPayments.php' => 1700000456,
        );

        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(
            array(
                'woocommerce/woocommerce.php' => 1700000000,
                'supcheckout/supcheckout.php' => 1700000123,
                'upayments/UPayments.php' => 1700000456,
            ),
            get_site_option('active_sitewide_plugins')
        );
        self::assertSame(0, $this->option_writes('active_plugins'));
        self::assertCount(1, $GLOBALS['supcheckout_test_site_option_calls']);
    }

    public function test_network_both_present_keeps_the_main_key_and_its_timestamp(): void {
        $GLOBALS['supcheckout_test_multisite'] = true;
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array(
            'supcheckout/UPayments.php' => 1700000123,
            'supcheckout/supcheckout.php' => 1700000999,
        );

        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(array('supcheckout/supcheckout.php' => 1700000999), get_site_option('active_sitewide_plugins'));
    }

    public function test_only_entries_in_our_own_directory_move(): void {
        $active = array('upayments/UPayments.php', 'other/UPayments.php', 'akismet/akismet.php');
        $GLOBALS['supcheckout_test_options']['active_plugins'] = $active;
        $GLOBALS['supcheckout_test_multisite'] = true;
        $sitewide = array('upayments/UPayments.php' => 1, 'other/UPayments.php' => 2);
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = $sitewide;

        self::assertFalse(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame($active, get_option('active_plugins'));
        self::assertSame($sitewide, get_site_option('active_sitewide_plugins'));
        self::assertSame(0, $this->option_writes('active_plugins'));
        self::assertSame(array(), $GLOBALS['supcheckout_test_site_option_calls']);
    }

    public function test_the_directory_comes_from_plugin_basename_not_a_hard_coded_slug(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('supcheckout/UPayments.php', 'supcheckout-staging/UPayments.php');

        self::assertTrue(BasenameMigration::run($this->shim('supcheckout-staging')));
        self::assertSame(array('supcheckout/UPayments.php', 'supcheckout-staging/supcheckout.php'), get_option('active_plugins'));
    }

    public function test_a_second_run_returns_false_and_writes_nothing(): void {
        $GLOBALS['supcheckout_test_multisite'] = true;
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('supcheckout/UPayments.php');
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array('supcheckout/UPayments.php' => 5);

        self::assertTrue(BasenameMigration::run($this->shim('supcheckout')));
        $writes = count($GLOBALS['supcheckout_test_option_calls']);
        $site_writes = count($GLOBALS['supcheckout_test_site_option_calls']);

        self::assertFalse(BasenameMigration::run($this->shim('supcheckout')));
        self::assertCount($writes, $GLOBALS['supcheckout_test_option_calls']);
        self::assertCount($site_writes, $GLOBALS['supcheckout_test_site_option_calls']);
        self::assertSame(array('supcheckout/supcheckout.php'), get_option('active_plugins'));
        self::assertSame(array('supcheckout/supcheckout.php' => 5), get_site_option('active_sitewide_plugins'));
    }

    public function test_single_site_install_never_reads_or_writes_network_options(): void {
        $GLOBALS['supcheckout_test_site_options']['active_sitewide_plugins'] = array('supcheckout/UPayments.php' => 5);
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('supcheckout/supcheckout.php');

        self::assertFalse(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame(array(), $GLOBALS['supcheckout_test_site_option_calls']);
        self::assertSame(0, $this->option_writes('active_plugins'));
    }

    public function test_it_never_points_the_entry_at_a_missing_main_file(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('no-main/UPayments.php');

        self::assertFalse(BasenameMigration::run($this->shim('no-main')));
        self::assertSame(array('no-main/UPayments.php'), get_option('active_plugins'));
        self::assertSame(0, $this->option_writes('active_plugins'));
    }

    public function test_a_shim_outside_a_plugin_directory_does_nothing(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = array('UPayments.php');

        self::assertFalse(BasenameMigration::run($this->plugins . '/UPayments.php'));
        self::assertSame(array('UPayments.php'), get_option('active_plugins'));
    }

    public function test_a_non_array_active_plugins_value_is_left_alone(): void {
        $GLOBALS['supcheckout_test_options']['active_plugins'] = 'corrupt';

        self::assertFalse(BasenameMigration::run($this->shim('supcheckout')));
        self::assertSame('corrupt', get_option('active_plugins'));
    }

    private function shim(string $dir): string {
        return $this->plugins . '/' . $dir . '/UPayments.php';
    }

    private function option_writes(string $name): int {
        $writes = 0;
        foreach ($GLOBALS['supcheckout_test_option_calls'] as $call) {
            if ($call[0] === 'update' && $call[1] === $name) {
                $writes++;
            }
        }
        return $writes;
    }
}
