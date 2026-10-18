<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;
use Simplixi\SUPCheckout\Takeover\Cli;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Takeover\PausedBoot;

/**
 * 0b Task 9: while the official plugin is active, the paused branch registers only the
 * takeover CLI (under WP-CLI) and, on a preflight probe request alone, the probe answer.
 * No gateway, Blocks method, payment callback, cron hook or global symbol.
 *
 * It also declares the same WooCommerce feature compatibility as the full boot
 * (before_woocommerce_init); otherwise WooCommerce lists the paused plugin as
 * incompatible with HPOS and the Cart & Checkout blocks.
 */
final class PausedBootTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_migration_bootstrap();
        $_GET = array();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_migration_bootstrap();
        $_GET = array();
    }

    /** @return array<int, mixed> */
    private static function feature_declarations(): array {
        return array('action', 'before_woocommerce_init', array(WooCommerceDeclarations::class, 'declare_compatibility'), 10, 1);
    }

    public function test_a_web_request_registers_only_the_woocommerce_feature_declarations(): void {
        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(array(self::feature_declarations()), $GLOBALS['supcheckout_test_hook_calls']);
        self::assertSame(array(), \WP_CLI::$commands, 'no WP_CLI constant: no command');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_paused_declarations_match_the_full_boot_for_the_paused_main_file(): void {
        eval('namespace Automattic\WooCommerce\Utilities; final class FeaturesUtil { public static $declared = array(); public static function declare_compatibility($feature_id, $plugin_file, $positive_compatibility = true) { self::$declared[] = array($feature_id, $plugin_file, $positive_compatibility); return true; } }');
        \supcheckout_test_reset_migration_bootstrap();
        $main_file = ABSPATH . 'supcheckout.php';

        PausedBoot::boot($main_file);
        WooCommerceDeclarations::declare_compatibility();

        self::assertFalse(class_exists('Simplixi\SUPCheckout\Plugin', false), 'the full boot never ran');
        self::assertSame(
            array(array('cart_checkout_blocks', $main_file, true), array('custom_order_tables', $main_file, true)),
            \Automattic\WooCommerce\Utilities\FeaturesUtil::$declared
        );
    }

    public function test_a_probe_request_registers_only_the_probe_answer_on_the_new_route(): void {
        $_GET = array('wc-api' => 'supcheckout', 'supcheckout_probe' => '1');

        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(
            array(self::feature_declarations(), array('action', LegacyCallbackListener::HOOK, array(LegacyCallbackListener::class, 'handle_probe'), 5, 1)),
            $GLOBALS['supcheckout_test_hook_calls']
        );
    }

    public function test_the_probe_answer_ignores_every_non_probe_request_and_never_reaches_the_lifecycle(): void {
        $_GET = array('wc-api' => 'supcheckout', 'supcheckout_probe' => '0', 'order_id' => '7');

        LegacyCallbackListener::handle_probe();

        self::assertFalse(class_exists('Simplixi\SUPCheckout\Payment\PaymentLifecycle', false) && $GLOBALS['supcheckout_test_hook_calls'] !== array(), 'nothing registered');
        self::assertSame(array(), $GLOBALS['supcheckout_test_hook_calls']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_under_wp_cli_it_registers_the_takeover_command_only(): void {
        define('WP_CLI', true);
        \supcheckout_test_reset_migration_bootstrap();

        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(array(array('supcheckout takeover', Cli::class)), \WP_CLI::$commands);
        self::assertSame(array(self::feature_declarations()), $GLOBALS['supcheckout_test_hook_calls']);
    }
}
