<?php

namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\CheckoutAssets;
use Simplixi\SUPCheckout\Release\Identity;

/**
 * Batch 2 final review M-5: subscription-checkout.js was versioned by the plugin version
 * alone, so a browser or CDN that cached the pre-rename script kept serving it after its
 * localized contract changed (wcUser -> supCheckoutSubscription), and the plan selector
 * treated logged-in shoppers as logged out. Its version now follows its content.
 */
final class CheckoutAssetVersionTest extends TestCase {
    private const SUBSCRIPTION_SCRIPT = 'assets/js/subscription-checkout.js';

    public function test_the_version_is_the_plugin_version_plus_a_hash_of_the_file_content(): void {
        $root = dirname(__DIR__, 3);
        $expected = Identity::VERSION . '-' . substr((string) hash_file('sha256', $root . '/' . self::SUBSCRIPTION_SCRIPT), 0, 12);

        self::assertSame($expected, CheckoutAssets::asset_version(self::SUBSCRIPTION_SCRIPT));
    }

    public function test_different_content_gets_a_different_version(): void {
        self::assertNotSame(
            CheckoutAssets::asset_version(self::SUBSCRIPTION_SCRIPT),
            CheckoutAssets::asset_version('assets/js/new-upay.js')
        );
    }

    public function test_an_unreadable_or_escaping_path_falls_back_to_the_plugin_version(): void {
        self::assertSame(Identity::VERSION, CheckoutAssets::asset_version('assets/js/missing-file.js'));
        self::assertSame(Identity::VERSION, CheckoutAssets::asset_version('../outside.js'));
    }

    public function test_both_enqueue_sites_version_the_subscription_script_by_content(): void {
        $root = dirname(__DIR__, 3);
        foreach (array('src/Gateway/Gateway.php', 'src/Gateway/CheckoutAssets.php') as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertMatchesRegularExpression(
                "/'supcheckout-subscription-checkout',\\s*\\\$plugin_url \\. 'assets\\/js\\/subscription-checkout\\.js',\\s*array\\('jquery'\\),\\s*(?:\\\\?Simplixi\\\\SUPCheckout\\\\Gateway\\\\)?(?:CheckoutAssets|self)::asset_version\\('assets\\/js\\/subscription-checkout\\.js'\\),/",
                $source,
                $file
            );
        }
    }
}
