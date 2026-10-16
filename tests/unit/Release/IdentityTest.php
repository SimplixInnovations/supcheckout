<?php

namespace Simplixi\SUPCheckout\Tests\Release;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Simplixi\SUPCheckout\Release\Identity;

final class IdentityTest extends TestCase {
    public function test_canonical_public_brand_and_repository_are_exact(): void {
        self::assertSame('SUPCheckout for UPayments', Identity::PRODUCT_NAME);
        self::assertSame('SUPCheckout', Identity::SHORT_NAME);
        self::assertSame('supcheckout', Identity::SLUG);
        self::assertSame('SimplixInnovations/supcheckout', Identity::REPOSITORY);
        self::assertSame('supcheckout', Identity::TEXT_DOMAIN);
        self::assertSame('Simplixi\\SUPCheckout', Identity::NAMESPACE_ROOT);
    }

    public function test_main_file_is_the_renamed_bootstrap_and_the_pre_rename_file_stays_legacy(): void {
        self::assertSame('supcheckout.php', Identity::MAIN_FILE);
        self::assertSame(Identity::TARGET_MAIN_FILE, Identity::MAIN_FILE);
        self::assertNotSame(Identity::LEGACY_MAIN_FILE, Identity::MAIN_FILE);
        self::assertFileExists(dirname(__DIR__, 3) . '/' . Identity::MAIN_FILE);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/' . Identity::LEGACY_MAIN_FILE);
    }

    public function test_version_remains_an_independent_pre_one_release(): void {
        self::assertMatchesRegularExpression('/\A0\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', Identity::VERSION);
        self::assertSame('0.1.0', Identity::VERSION);
    }

    public function test_external_update_channel_is_explicitly_disabled(): void {
        self::assertSame('disabled', Identity::UPDATE_CHANNEL);
    }

    public function test_historical_install_identities_remain_exact(): void {
        self::assertSame('UPayments.php', Identity::LEGACY_MAIN_FILE);
        self::assertSame('upayments', Identity::LEGACY_TEXT_DOMAIN);
        self::assertSame('upayments', Identity::LEGACY_GATEWAY_ID);
        self::assertSame('woocommerce_upayments_settings', Identity::LEGACY_SETTINGS_OPTION);
        self::assertSame('wc_upayments', Identity::LEGACY_CALLBACK_ROUTE);
        self::assertSame('upay_process_subscriptions', Identity::LEGACY_SUBSCRIPTION_HOOK);
        self::assertSame('upayments_token_identity_secret_v2', Identity::LEGACY_TOKEN_SECRET_OPTION);
        self::assertSame('upayments_billing_attempts', Identity::LEGACY_BILLING_ATTEMPT_TABLE_SUFFIX);
    }

    public function test_future_targets_are_frozen_but_not_current_identity(): void {
        self::assertSame('supcheckout.php', Identity::TARGET_MAIN_FILE);
        self::assertSame('supcheckout', Identity::TARGET_TEXT_DOMAIN);
        self::assertNotSame(Identity::LEGACY_MAIN_FILE, Identity::TARGET_MAIN_FILE);
        self::assertNotSame(Identity::LEGACY_TEXT_DOMAIN, Identity::TARGET_TEXT_DOMAIN);
    }

    public function test_identity_is_a_non_instantiable_constant_boundary(): void {
        $reflection = new ReflectionClass(Identity::class);
        self::assertTrue($reflection->isFinal());
        self::assertNotNull($reflection->getConstructor());
        self::assertTrue($reflection->getConstructor()->isPrivate());
    }
}
