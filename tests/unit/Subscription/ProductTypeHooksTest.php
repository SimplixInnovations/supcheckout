<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Simplixi\SUPCheckout\Subscription\ProductTypeHooks;

final class ProductTypeHooksTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_subscription_composition();
    }

    public function test_every_registered_product_type_callback_is_a_public_static_method(): void {
        ProductTypeHooks::register();

        $own = 0;
        foreach ($GLOBALS['supcheckout_test_hook_calls'] as $call) {
            if (!is_array($call[2])) {
                // WooCommerce's own woocommerce_simple_add_to_cart template function.
                self::assertSame(array('action', 'woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30, 1), $call);
                continue;
            }
            self::assertSame(ProductTypeHooks::class, $call[2][0]);
            $method = new ReflectionMethod(ProductTypeHooks::class, $call[2][1]);
            self::assertTrue($method->isPublic() && $method->isStatic(), $call[2][1] . ' is public static');
            self::assertGreaterThanOrEqual($method->getNumberOfRequiredParameters(), $call[4], $call[2][1] . ' receives every argument it requires');
            $own++;
        }
        self::assertSame(9, $own);
    }

    public function test_product_type_hooks_exclude_scheduler_dispatch_mutation_and_transport_ownership(): void {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Subscription/ProductTypeHooks.php');

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

    public function test_product_type_hooks_is_final_and_non_instantiable(): void {
        $reflection = new ReflectionClass(ProductTypeHooks::class);

        self::assertTrue($reflection->isFinal());
        self::assertNotNull($reflection->getConstructor());
        self::assertTrue($reflection->getConstructor()->isPrivate());
    }
}
