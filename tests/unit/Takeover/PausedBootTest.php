<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Bootstrap\Deactivation;
use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;
use Simplixi\SUPCheckout\Takeover\Cli;
use Simplixi\SUPCheckout\Rest\Routes;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\PausedBoot;

/**
 * 0b Task 9 and 0c Task 8: while the official plugin is active, the paused branch registers
 * only the WooCommerce feature declarations, the takeover CLI (under WP-CLI), the takeover
 * REST routes (`rest_api_init`, never the self-test or setup-check routes), the two
 * onboarding driver hooks (at once under cron, AJAX and WP-CLI, otherwise lazily on Action
 * Scheduler's queue start), the deactivation cleanup (A3-08, on the main file) and, on a
 * preflight probe request alone, the probe answer. No gateway, Blocks method, payment
 * callback, payment cron hook or global symbol.
 *
 * The feature declarations are the full boot's (before_woocommerce_init); otherwise
 * WooCommerce lists the paused plugin as incompatible with HPOS and the Cart & Checkout
 * blocks.
 */
final class PausedBootTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/onboarding-driver-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/wordpress-rest-stubs.php';
    }

    protected function setUp(): void {
        \supcheckout_test_reset_migration_bootstrap();
        $_GET = array();
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_migration_bootstrap();
        $_GET = array();
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);
    }

    /** @return array<int, mixed> */
    private static function feature_declarations(): array {
        return array('action', 'before_woocommerce_init', array(WooCommerceDeclarations::class, 'declare_compatibility'), 10, 1);
    }

    /** @return array<int, mixed> */
    private static function takeover_routes(): array {
        return array('action', 'rest_api_init', array(Routes::class, 'register_takeover'), 10, 1);
    }

    /** @return array<int, mixed> The lazy driver registration for a request no context covers. */
    private static function lazy_driver(): array {
        return array('action', 'action_scheduler_before_process_queue', array(AsyncDriver::class, 'register_hooks'), 10, 1);
    }

    /** @return list<array<int, mixed>> */
    private static function driver_hooks(): array {
        return array(
            array('action', AsyncDriver::HOOK, array(AsyncDriver::class, 'handle'), 10, 1),
            array('action', AsyncDriver::WATCHDOG_HOOK, array(AsyncDriver::class, 'watchdog'), 10, 2),
        );
    }

    public function test_a_web_request_registers_the_declarations_the_takeover_routes_and_the_lazy_driver_only(): void {
        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(
            array(self::feature_declarations(), self::takeover_routes(), self::lazy_driver()),
            $GLOBALS['supcheckout_test_hook_calls']
        );
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

    public function test_the_paused_boot_registers_the_deactivation_cleanup_on_its_main_file(): void {
        // A3-08: a store that deactivates SUPCheckout while the official plugin is active
        // still drops SUPCheckout's own schedules. The ::class constant loads no file.
        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(
            array(array('deactivation', ABSPATH . 'supcheckout.php', array(Deactivation::class, 'deactivate'))),
            $GLOBALS['supcheckout_test_file_hook_calls']
        );
    }

    public function test_a_probe_request_registers_only_the_probe_answer_on_the_new_route(): void {
        $_GET = array('wc-api' => 'supcheckout', 'supcheckout_probe' => '1');

        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(
            array(self::feature_declarations(), self::takeover_routes(), self::lazy_driver(), array('action', LegacyCallbackListener::HOOK, array(LegacyCallbackListener::class, 'handle_probe'), 5, 1)),
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
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/onboarding-driver-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/wordpress-rest-stubs.php';
        define('WP_CLI', true);
        \supcheckout_test_reset_migration_bootstrap();

        PausedBoot::boot(ABSPATH . 'supcheckout.php');

        self::assertSame(array(array('supcheckout takeover', Cli::class)), \WP_CLI::$commands);
        self::assertSame(
            array_merge(array(self::feature_declarations(), self::takeover_routes()), self::driver_hooks()),
            $GLOBALS['supcheckout_test_hook_calls']
        );
    }

    public function test_cron_and_ajax_requests_also_register_the_two_driver_hooks(): void {
        foreach (array('cron', 'ajax') as $context) {
            \supcheckout_test_reset_migration_bootstrap();
            $GLOBALS['supcheckout_test_request_context'] = array('cron' => $context === 'cron', 'ajax' => $context === 'ajax');

            PausedBoot::boot(ABSPATH . 'supcheckout.php');

            self::assertSame(
                array_merge(array(self::feature_declarations(), self::takeover_routes()), self::driver_hooks()),
                $GLOBALS['supcheckout_test_hook_calls'],
                $context
            );
        }
    }

    /**
     * Whether PausedBoot and AsyncDriver::register() each add the two driver hooks at once in
     * the current request context (true), or only the lazy queue-start registration (false).
     *
     * @return array{paused:bool, driver:bool}
     */
    private static function eager_driver_registration(): array {
        $eager = static function (): bool {
            $hooks = array_column($GLOBALS['supcheckout_test_action_calls'], 0);
            $at_once = in_array(AsyncDriver::HOOK, $hooks, true) && in_array(AsyncDriver::WATCHDOG_HOOK, $hooks, true);
            $lazy = in_array(AsyncDriver::QUEUE_START_ACTION, $hooks, true);
            self::assertNotSame($at_once, $lazy, 'exactly one of the eager and the lazy registration: ' . wp_json_encode($hooks));
            return $at_once;
        };
        \supcheckout_test_reset_migration_bootstrap();
        PausedBoot::boot(ABSPATH . 'supcheckout.php');
        $paused = $eager();
        \supcheckout_test_reset_migration_bootstrap();
        AsyncDriver::register();
        $driver = $eager();
        return array('paused' => $paused, 'driver' => $driver);
    }

    public function test_the_paused_driver_context_predicate_matches_async_driver_register_over_web_cron_and_ajax(): void {
        // PausedBoot repeats AsyncDriver::register()'s context predicate so a plain paused
        // request never loads AsyncDriver; the two must never drift apart.
        foreach (array('web' => false, 'cron' => true, 'ajax' => true) as $context => $expected) {
            $GLOBALS['supcheckout_test_request_context'] = array('cron' => $context === 'cron', 'ajax' => $context === 'ajax');

            self::assertSame(array('paused' => $expected, 'driver' => $expected), self::eager_driver_registration(), $context);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_paused_driver_context_predicate_matches_async_driver_register_under_wp_cli(): void {
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/onboarding-driver-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/wordpress-rest-stubs.php';
        define('WP_CLI', true);
        $GLOBALS['supcheckout_test_request_context'] = array('cron' => false, 'ajax' => false);

        self::assertSame(array('paused' => true, 'driver' => true), self::eager_driver_registration());
    }

    public function test_the_paused_routes_never_include_the_self_test_or_setup_check_routes(): void {
        \supcheckout_test_reset_rest();

        Routes::register_takeover();

        $endpoints = 0;
        foreach ($GLOBALS['supcheckout_test_rest_registry'] as $route => $list) {
            $endpoints += count($list);
            self::assertStringNotContainsString('self-test', $route);
            self::assertStringNotContainsString('/setup/', $route);
        }
        self::assertSame(count(Routes::table(true)), $endpoints);
        self::assertSame(8, $endpoints);
    }
}
