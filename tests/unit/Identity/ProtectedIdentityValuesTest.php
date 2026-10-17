<?php
namespace Simplixi\SUPCheckout\Tests\Identity;

use PHPUnit\Framework\TestCase;

final class ProtectedIdentityValuesTest extends TestCase {
    public function test_namespaced_classes_keep_every_persisted_identity(): void {
        self::assertSame('upay_process_subscriptions', \Simplixi\SUPCheckout\Subscription\Cron\Scheduler::CRON_HOOK);
        self::assertSame('upayments_token_identity_secret_v2', \Simplixi\SUPCheckout\Token\CustomerTokenIdentity::SECRET_OPTION);
        self::assertFalse(class_exists('UPayments\\Token\\CustomerTokenIdentity', false));
        self::assertFalse(class_exists('UPayments\\Subscription\\Cron\\Scheduler', false));
    }

    /**
     * The unit bootstrap declares a CustomerTokenIdentity test double
     * (tests/support/wordpress-migration-core.php), so the constant read above can
     * come from that double. Pin the shipped declarations in the real files too, so
     * the persisted values cannot drift unnoticed behind the double.
     */
    public function test_shipped_sources_declare_the_persisted_identities(): void {
        $root = dirname(__DIR__, 3);
        $token = (string) file_get_contents($root . '/src/Token/CustomerTokenIdentity.php');
        $scheduler = (string) file_get_contents($root . '/src/Subscription/Cron/Scheduler.php');
        $blocks = (string) file_get_contents($root . '/src/Blocks/PaymentMethodType.php');

        self::assertStringContainsString('namespace Simplixi\\SUPCheckout\\Token;', $token);
        self::assertStringContainsString("const SECRET_OPTION = 'upayments_token_identity_secret_v2';", $token);
        self::assertStringContainsString('namespace Simplixi\\SUPCheckout\\Subscription\\Cron;', $scheduler);
        self::assertStringContainsString("const CRON_HOOK = 'upay_process_subscriptions';", $scheduler);
        self::assertStringContainsString('namespace Simplixi\\SUPCheckout\\Blocks;', $blocks);
        self::assertStringContainsString('class PaymentMethodType extends AbstractPaymentMethodType', $blocks);
        self::assertStringContainsString("protected \$name = 'upayments';", $blocks);
        // One directory deeper than includes/, so the Blocks plugin_url still resolves to the plugin root.
        self::assertStringContainsString("'plugin_url'                => plugin_dir_url( dirname( __FILE__, 2 ) ),", $blocks);
        self::assertDirectoryDoesNotExist($root . '/includes');
    }
}
