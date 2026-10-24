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

    /**
     * Batch 4 (M-5 rest): every front-end asset follows its content, not only the
     * subscription script. Each enqueue/register call in the three front-end sites must
     * pass CheckoutAssets::asset_version() of the very path it loads.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function frontEndEnqueueSites(): array {
        $assets = array('assets/css/customer.css', 'assets/css/new-design.css', 'assets/js/new-upay.js', 'assets/js/subscription-checkout.js');
        return array(
            'Classic checkout pre-enqueue' => array('src/Gateway/Gateway.php', $assets),
            'Rendered template enqueue' => array('src/Gateway/CheckoutAssets.php', $assets),
            'Blocks payment method' => array('src/Blocks/PaymentMethodType.php', array('assets/js/upayments-block.js')),
        );
    }

    /**
     * @param list<string> $expected_assets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('frontEndEnqueueSites')]
    public function test_every_front_end_enqueue_site_versions_its_asset_by_content(string $file, array $expected_assets): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        preg_match_all('/\bwp_(?:enqueue|register)_(?:script|style)\((.*?)\);/s', $source, $calls);

        $seen = array();
        foreach ($calls[1] as $call) {
            self::assertSame(1, preg_match("#'(assets/[A-Za-z0-9_\\-/]+\\.(?:js|css))'#", $call, $path), "$file: an enqueue call without a plugin asset path: $call");
            $seen[] = $path[1];
            self::assertStringContainsString("asset_version('" . $path[1] . "')", $call, "$file versions {$path[1]} by its content");
            self::assertDoesNotMatchRegularExpression('/\b(?:SUPCHECKOUT_VERSION|Identity::VERSION)\b/', $call, "$file: {$path[1]} is not versioned by the plugin version alone");
        }
        sort($seen);
        $expected = $expected_assets;
        sort($expected);
        self::assertSame($expected, $seen, "$file enqueues exactly these front-end assets");
    }

    public function test_each_front_end_asset_has_a_content_version(): void {
        foreach (array('assets/css/customer.css', 'assets/css/new-design.css', 'assets/js/new-upay.js', 'assets/js/upayments-block.js') as $asset) {
            self::assertMatchesRegularExpression('/^' . preg_quote(Identity::VERSION, '/') . '-[0-9a-f]{12}$/D', CheckoutAssets::asset_version($asset), $asset);
        }
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
