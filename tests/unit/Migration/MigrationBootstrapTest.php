<?php

namespace Simplixi\SUPCheckout\Tests\Migration;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Simplixi\SUPCheckout\Migration\MigrationAdmin;
use Simplixi\SUPCheckout\Migration\MigrationBootstrap;
use Simplixi\SUPCheckout\Migration\MigrationCliCommand;

final class MigrationBootstrapTest extends TestCase {
    public static function setUpBeforeClass(): void {
        class_exists(MigrationBootstrap::class);
    }

    protected function setUp(): void {
        \supcheckout_test_reset_migration_bootstrap();
    }

    public function test_public_boot_is_inert_outside_admin_and_cli_contexts(): void {
        MigrationBootstrap::boot();

        self::assertSame(array(), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(), \WP_CLI::$commands);
    }

    public function test_public_boot_registers_only_the_admin_menu_in_admin_context(): void {
        $GLOBALS['supcheckout_test_admin_context'] = true;

        MigrationBootstrap::boot();

        self::assertSame(array(
            array('admin_menu', array(MigrationAdmin::class, 'register'), 10, 1),
        ), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(), \WP_CLI::$commands);
    }

    public function test_cli_context_registers_only_the_canonical_command(): void {
        $this->bootForContext(true, false);

        self::assertSame(array(), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(
            array('simplixpay-upayments migration', MigrationCliCommand::class),
        ), \WP_CLI::$commands);
    }

    public function test_combined_context_registers_each_operational_surface_once(): void {
        $this->bootForContext(true, true);

        self::assertSame(array(
            array('admin_menu', array(MigrationAdmin::class, 'register'), 10, 1),
        ), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(
            array('simplixpay-upayments migration', MigrationCliCommand::class),
        ), \WP_CLI::$commands);
    }

    public function test_bootstrap_loads_only_bounded_operational_dependencies(): void {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Migration/MigrationBootstrap.php');

        self::assertIsString($source);
        foreach (array(
            'MigrationPreflight.php',
            'MigrationExecutor.php',
            'MigrationSettings.php',
            'MigrationBatch.php',
            'MigrationAdmin.php',
            'MigrationCliCommand.php',
        ) as $dependency) {
            self::assertSame(1, substr_count($source, "require_once __DIR__ . '/{$dependency}'"));
        }
        self::assertStringNotContainsString('supcheckout.php', $source);
        self::assertStringNotContainsString('wp_enqueue_scripts', $source);
        self::assertStringNotContainsString('woocommerce_checkout', $source);
        self::assertStringNotContainsString('wp_remote_', $source);
    }

    public function test_bootstrap_is_final_and_non_instantiable(): void {
        $reflection = new ReflectionClass(MigrationBootstrap::class);

        self::assertTrue($reflection->isFinal());
        self::assertNotNull($reflection->getConstructor());
        self::assertTrue($reflection->getConstructor()->isPrivate());
        self::assertTrue($reflection->getMethod('bootForContext')->isPrivate());
    }

    private function bootForContext($is_cli, $is_admin): void {
        $method = (new ReflectionClass(MigrationBootstrap::class))->getMethod('bootForContext');
        $method->invoke(null, $is_cli, $is_admin);
    }
}
