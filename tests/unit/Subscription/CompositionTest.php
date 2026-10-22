<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Simplixi\SUPCheckout\Subscription\Composition;
use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\ProductTypeHooks;

final class CompositionTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_subscription_composition();
    }

    public function test_registers_exact_presentation_hook_topology(): void {
        Composition::register_presentation_hooks();

        $presentation = Presentation::class;
        $product_type = ProductTypeHooks::class;
        self::assertSame(array(
            array('action', 'init', array($presentation, 'register_product_class'), 10, 1),
            array('filter', 'product_type_selector', array($product_type, 'addCustomProductType'), 10, 1),
            array('filter', 'woocommerce_product_class', array($product_type, 'mapCustomProductClass'), 10, 2),
            array('action', 'woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30, 1),
            array('action', 'admin_footer', array($product_type, 'customProductTypes'), 10, 1),
            array('filter', 'woocommerce_product_data_tabs', array($product_type, 'addCustomDataTab'), 10, 1),
            array('action', 'woocommerce_product_data_panels', array($product_type, 'addCustomDataPanel'), 10, 1),
            array('action', 'woocommerce_process_product_meta', array($product_type, 'saveCustomFieldData'), 10, 1),
            array('action', 'woocommerce_single_product_summary', array($product_type, 'displayCustomFieldOnFrontend'), 10, 1),
            array('filter', 'woocommerce_get_item_data', array($product_type, 'displayCustomDataInCart'), 10, 2),
            array('action', 'woocommerce_checkout_create_order_line_item', array($product_type, 'saveCustomDataToOrderItems'), 10, 4),
            array('action', 'woocommerce_order_details_after_order_table', array($presentation, 'render_account_order_details'), 10, 1),
            array('action', 'woocommerce_before_account_orders', array($presentation, 'render_account_orders_filter'), 10, 1),
            array('filter', 'woocommerce_my_account_my_orders_query', array($presentation, 'filter_account_orders_query'), 10, 1),
            array('filter', 'woocommerce_my_account_my_orders_columns', array($presentation, 'filter_account_orders_columns'), 10, 1),
            array('action', 'woocommerce_my_account_my_orders_column_order_type', array($presentation, 'render_account_order_type'), 10, 1),
            array('action', 'woocommerce_my_account_my_orders_column_order_status', array($presentation, 'render_account_subscription_status'), 10, 1),
            array('action', 'woocommerce_admin_order_data_after_billing_address', array($presentation, 'render_admin_order_summary'), 10, 1),
            // Fix A1-01: the store-manager notice that automatic renewal charges are paused.
            array('action', 'admin_notices', array($presentation, 'render_auto_renewal_paused_notice'), 10, 1),
        ), $GLOBALS['supcheckout_test_hook_calls']);
    }

    public function test_registers_only_exact_gateway_instance_hooks(): void {
        $gateway = new \stdClass();

        Composition::register_gateway_hooks($gateway);

        self::assertSame(array(
            array('filter', 'woocommerce_add_to_cart_validation', array($gateway, 'restrictMixedCartProducts'), 10, 3),
            array('action', 'woocommerce_before_shop_loop_item_title', array($gateway, 'renderSubscriptionBadgeInProductList'), 9, 1),
        ), $GLOBALS['supcheckout_test_hook_calls']);
    }

    public function test_legacy_modules_keep_exact_root_dependencies_and_initializers(): void {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Subscription/Composition.php');

        self::assertIsString($source);
        // Sub-project 0a Task 6: the legacy modules moved from includes/ into src/ under
        // Simplixi\SUPCheckout and are autoloaded, so the exact dependencies are now the
        // namespaced classes instead of requires. 0b Task 7 removed the dead
        // Subscription\Manager listener (nothing ever fired upayments_payment_success).
        self::assertSame(1, substr_count($source, 'use Simplixi\\SUPCheckout\\Subscription\\Checkout\\Fields;'));
        self::assertSame(0, preg_match('/\b(require|include)(_once)?\b/', $source));
        self::assertStringNotContainsString('UPayments\\', $source);
        foreach (array(
            'src/Subscription/Checkout/Fields.php' => array('namespace Simplixi\\SUPCheckout\\Subscription\\Checkout;', 'class Fields'),
            'src/Subscription/Helpers/Utils.php' => array('namespace Simplixi\\SUPCheckout\\Subscription\\Helpers;', 'class Utils'),
        ) as $dependency => $declarations) {
            $dependency_source = file_get_contents(dirname(__DIR__, 3) . '/' . $dependency);
            self::assertIsString($dependency_source);
            foreach ($declarations as $declaration) {
                self::assertSame(1, substr_count($dependency_source, $declaration));
            }
        }
        self::assertSame(1, substr_count($source, 'Fields::init();'));
        self::assertSame(0, substr_count($source, 'Manager::init();'));
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/Subscription/Manager.php');
    }

    public function test_no_shipped_code_listens_on_or_fires_the_dead_payment_success_hook(): void {
        $root = dirname(__DIR__, 3);
        $files = array_merge(array($root . '/supcheckout.php', $root . '/UPayments.php'), self::php_files($root . '/src'), self::php_files($root . '/templates'));
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('upayments_payment_success', $source, $file);
        }
    }

    /** @return list<string> */
    private static function php_files(string $dir): array {
        $out = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function test_composition_excludes_scheduler_dispatch_mutation_and_transport_ownership(): void {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Subscription/Composition.php');

        self::assertIsString($source);
        foreach (array(
            'Cron\\Scheduler',
            'Scheduler::',
            'CycleClaim::',
            'process_payment',
            'upay_process_subscriptions',
            'upayments_billing_attempts',
            'update_meta_data',
            'wp_remote_',
            'CURLOPT_',
        ) as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_composition_is_final_and_non_instantiable(): void {
        $reflection = new ReflectionClass(Composition::class);

        self::assertTrue($reflection->isFinal());
        self::assertNotNull($reflection->getConstructor());
        self::assertTrue($reflection->getConstructor()->isPrivate());
    }
}
