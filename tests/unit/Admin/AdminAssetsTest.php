<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\AdminAssets;

require_once dirname(__DIR__, 2) . '/support/wordpress-admin-assets.php';

final class AdminAssetsTest extends TestCase {
    private const SCREEN = 'toplevel_page_supcheckout';

    /** @var list<string> */
    private $temporary = array();

    protected function setUp(): void {
        \supcheckout_test_reset_admin_assets();
    }

    protected function tearDown(): void {
        foreach (array_reverse($this->temporary) as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporary = array();
        \supcheckout_test_reset_admin_assets();
    }

    public function test_enqueue_for_another_screen_enqueues_nothing(): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN);

        self::assertFalse($assets->enqueue_for('index.php'));
        self::assertSame(array(), \supcheckout_test_enqueued());
        self::assertNull(\supcheckout_test_script(AdminAssets::HANDLE));
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['textdomain_calls']);
    }

    public function test_an_unhooked_instance_enqueues_nothing_even_for_a_supcheckout_screen(): void {
        self::assertFalse($this->assets()->enqueue_for(self::SCREEN));
        self::assertSame(array(), \supcheckout_test_enqueued());
    }

    public function test_a_registered_screen_enqueues_script_and_style_from_the_manifest(): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));

        // The committed app.asset.json, in its sorted order (Base UI imports
        // react, and react-dom since SupRoot portals the toast viewport).
        $script = \supcheckout_test_script(AdminAssets::HANDLE);
        self::assertNotNull($script);
        self::assertSame(
            array('react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element', 'wp-i18n'),
            $script['deps']
        );
        $manifest = AdminAssets::manifest(dirname(__DIR__, 3) . '/assets/admin/build/app.asset.json');
        self::assertNotNull($manifest);
        self::assertSame($manifest['dependencies'], $script['deps']);
        self::assertSame($manifest['version'], $script['ver']);
        self::assertSame(
            'https://shop.example.test/wp-content/plugins/supcheckout/assets/admin/build/app.js',
            $script['src']
        );
        self::assertSame(array('in_footer' => true), $script['args']);

        $style = \supcheckout_test_style(AdminAssets::HANDLE);
        self::assertNotNull($style);
        self::assertSame(array(), $style['deps']);
        self::assertSame($manifest['version'], $style['ver']);
        self::assertSame(
            'https://shop.example.test/wp-content/plugins/supcheckout/assets/admin/build/app.css',
            $style['src']
        );
        self::assertSame(array('rtl', 'replace'), \supcheckout_test_style_data(AdminAssets::HANDLE));

        self::assertSame(
            array('script:' . AdminAssets::HANDLE, 'style:' . AdminAssets::HANDLE),
            \supcheckout_test_enqueued()
        );
    }

    public function test_script_translations_use_the_supcheckout_domain(): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN);
        $assets->enqueue_for(self::SCREEN);

        self::assertSame(
            array(array(AdminAssets::HANDLE, 'supcheckout', '')),
            $GLOBALS['supcheckout_test_admin_assets']['script_translations']
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function broken_manifests(): array {
        return array(
            'missing file'           => array(null),
            'invalid json'           => array('{'),
            'dependencies not list'  => array('{"dependencies":"x","version":"1"}'),
            'empty version'          => array('{"dependencies":[],"version":""}'),
            'unexpected key'         => array('{"dependencies":[],"version":"1","x":1}'),
            'missing version'        => array('{"dependencies":[]}'),
            'non-string dependency'  => array('{"dependencies":["wp-i18n",3],"version":"1"}'),
            'keyed dependencies'     => array('{"dependencies":{"a":"wp-i18n"},"version":"1"}'),
            'non-string version'     => array('{"dependencies":[],"version":1}'),
            'top-level list'         => array('["wp-i18n"]'),
        );
    }

    #[DataProvider('broken_manifests')]
    public function test_a_missing_or_malformed_manifest_fails_closed(?string $json): void {
        self::assertNull(AdminAssets::manifest($this->fixture($json)));
    }

    public function test_a_valid_manifest_is_returned_exactly(): void {
        self::assertSame(
            array('dependencies' => array('wp-element', 'wp-i18n'), 'version' => 'abc123'),
            AdminAssets::manifest($this->fixture('{"version":"abc123","dependencies":["wp-element","wp-i18n"]}'))
        );
    }

    public function test_an_invalid_manifest_enqueues_nothing_on_a_registered_screen(): void {
        $plugin = $this->plugin('{"dependencies":"x","version":"1"}', '["chunks/panel.0123abcd.js"]');
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertFalse($assets->enqueue_for(self::SCREEN));
        self::assertSame(array(), \supcheckout_test_enqueued());
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['scripts']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['styles']);
    }

    public function test_chunk_translations_are_inlined_before_the_app(): void {
        $plugin = $this->plugin(
            '{"dependencies":["wp-i18n"],"version":"v1"}',
            '["chunks/panel.0123abcd.js"]'
        );
        $GLOBALS['supcheckout_test_admin_assets']['textdomain_json']['supcheckout-admin-chunk-panel'] = json_encode(array(
            'domain'      => 'messages',
            'locale_data' => array(
                'messages' => array(
                    ''       => array('domain' => 'messages', 'lang' => 'ar'),
                    'Search' => array('بحث </script>'),
                ),
            ),
        ));
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));

        $chunk = \supcheckout_test_script('supcheckout-admin-chunk-panel');
        self::assertNotNull($chunk);
        self::assertSame(
            'https://shop.example.test/wp-content/plugins/supcheckout/assets/admin/build/chunks/panel.0123abcd.js',
            $chunk['src']
        );
        self::assertSame(array(), $chunk['deps']);
        self::assertSame(
            array(array('supcheckout-admin-chunk-panel', 'supcheckout', '')),
            $GLOBALS['supcheckout_test_admin_assets']['textdomain_calls']
        );
        self::assertNotContains('script:supcheckout-admin-chunk-panel', \supcheckout_test_enqueued());

        $inline = \supcheckout_test_inline_scripts();
        self::assertCount(1, $inline);
        self::assertSame(AdminAssets::HANDLE, $inline[0]['handle']);
        self::assertSame('before', $inline[0]['position']);
        self::assertStringStartsWith(
            '(function(d){var l=d.locale_data["supcheckout"]||d.locale_data.messages;l[""].domain="supcheckout";wp.i18n.setLocaleData(l,"supcheckout");})(',
            $inline[0]['data']
        );
        self::assertStringEndsWith(');', $inline[0]['data']);
        self::assertStringContainsString('"Search"', $inline[0]['data']);
        // JSON_HEX_TAG: a translation can never close the inline script element.
        self::assertStringNotContainsString('</script>', $inline[0]['data']);
        self::assertStringContainsString('\u003C/script\u003E', $inline[0]['data']);
    }

    public function test_a_chunk_without_translations_adds_no_inline_script(): void {
        $plugin = $this->plugin('{"dependencies":[],"version":"v1"}', '["chunks/panel.0123abcd.js"]');
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));
        self::assertSame(array(), \supcheckout_test_inline_scripts());
    }

    public function test_chunk_translation_json_without_locale_data_is_ignored(): void {
        $plugin = $this->plugin('{"dependencies":[],"version":"v1"}', '["chunks/panel.0123abcd.js"]');
        $GLOBALS['supcheckout_test_admin_assets']['textdomain_json']['supcheckout-admin-chunk-panel'] = '{"domain":"messages"}';
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));
        self::assertSame(array(), \supcheckout_test_inline_scripts());
    }

    public function test_a_chunk_path_outside_chunks_drops_the_chunk_list(): void {
        self::assertSame(array(), AdminAssets::chunks($this->fixture('["../../wp-config.php"]')));
        self::assertSame(
            array(),
            AdminAssets::chunks($this->fixture('["chunks/panel.0123abcd.js","chunks/../../x.0123abcd.js"]'))
        );
        self::assertSame(array(), AdminAssets::chunks($this->fixture('["chunks/Panel.0123abcd.js"]')));
        self::assertSame(array(), AdminAssets::chunks($this->fixture('["chunks/panel.0123abcd.js",1]')));
        self::assertSame(array(), AdminAssets::chunks($this->fixture('{"a":"chunks/panel.0123abcd.js"}')));
        self::assertSame(array(), AdminAssets::chunks($this->fixture('{')));
        self::assertSame(array(), AdminAssets::chunks($this->fixture(null)));
    }

    public function test_a_chunk_path_with_a_trailing_newline_drops_the_chunk_list(): void {
        // `$` also matches before a final newline; the anchor must be `\z`.
        self::assertSame(array(), AdminAssets::chunks($this->fixture('["chunks/x.12345678.js\n"]')));
        self::assertSame(
            array(),
            AdminAssets::chunks($this->fixture('["chunks/panel.0123abcd.js","chunks/x.12345678.js\n"]'))
        );
        self::assertSame(0, preg_match(AdminAssets::CHUNK_PATTERN, "chunks/x.12345678.js\n"));
        self::assertSame(1, preg_match(AdminAssets::CHUNK_PATTERN, 'chunks/x.12345678.js'));
    }

    public function test_the_fixture_translation_script_uses_the_same_chunk_pattern(): void {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/admin-ui/wp/make-fixture-translations.php');
        self::assertSame(
            1,
            preg_match("/const SUPCHECKOUT_FIXTURE_CHUNK = '([^']+)';/", $script, $match),
            'make-fixture-translations.php declares SUPCHECKOUT_FIXTURE_CHUNK as a single-quoted literal'
        );
        self::assertSame(AdminAssets::CHUNK_PATTERN, $match[1]);
        self::assertSame(0, preg_match($match[1], "chunks/x.12345678.js\n"));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function non_string_hook_suffixes(): array {
        return array(
            'null from a misbehaving caller'  => array(null),
            'array from a misbehaving caller' => array(array(self::SCREEN)),
            'integer from a misbehaving caller' => array(0),
        );
    }

    /**
     * @param mixed $hook_suffix
     */
    #[DataProvider('non_string_hook_suffixes')]
    public function test_enqueue_for_a_non_string_hook_suffix_enqueues_nothing($hook_suffix): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN);

        self::assertFalse($assets->enqueue_for($hook_suffix));
        self::assertSame(array(), \supcheckout_test_enqueued());
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['scripts']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['styles']);
    }

    public function test_valid_chunks_are_returned_in_order(): void {
        self::assertSame(
            array('chunks/995.08985fb8.js', 'chunks/command-palette.0123abcd.js'),
            AdminAssets::chunks($this->fixture('["chunks/995.08985fb8.js","chunks/command-palette.0123abcd.js"]'))
        );
        self::assertSame(array(), AdminAssets::chunks($this->fixture('[]')));
    }

    public function test_the_committed_chunk_manifest_is_valid(): void {
        $chunks = AdminAssets::chunks(dirname(__DIR__, 3) . '/assets/admin/build/app.chunks.json');
        self::assertNotSame(array(), $chunks);
    }

    public function test_body_class_is_added_only_on_registered_screens(): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN, 'supcheckout_page_supcheckout-settings');

        $GLOBALS['hook_suffix'] = 'index.php';
        self::assertSame(' folded', $assets->body_class(' folded'));

        unset($GLOBALS['hook_suffix']);
        self::assertSame(' folded', $assets->body_class(' folded'));

        $GLOBALS['hook_suffix'] = 'supcheckout_page_supcheckout-settings';
        self::assertSame(' folded supcheckout-admin-page', $assets->body_class(' folded'));
        self::assertSame(' supcheckout-admin-page', $assets->body_class(''));
        self::assertSame(' supcheckout-admin-page', $assets->body_class(' supcheckout-admin-page'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function non_string_body_classes(): array {
        return array(
            'null from an earlier filter'   => array(null),
            'array from an earlier filter'  => array(array('folded')),
            'integer from an earlier filter' => array(0),
        );
    }

    /**
     * @param mixed $classes
     */
    #[DataProvider('non_string_body_classes')]
    public function test_body_class_returns_a_non_string_from_an_earlier_filter_unchanged($classes): void {
        $assets = $this->assets();
        $assets->hook(self::SCREEN);
        $GLOBALS['hook_suffix'] = self::SCREEN;

        self::assertSame($classes, $assets->body_class($classes));
    }

    public function test_an_invalid_chunk_manifest_still_enqueues_the_app_without_chunk_handles(): void {
        $plugin = $this->plugin('{"dependencies":["wp-i18n"],"version":"v1"}', '["../../wp-config.php"]');
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));
        self::assertSame(
            array('script:' . AdminAssets::HANDLE, 'style:' . AdminAssets::HANDLE),
            \supcheckout_test_enqueued()
        );
        self::assertSame(array(AdminAssets::HANDLE), array_keys($GLOBALS['supcheckout_test_admin_assets']['scripts']));
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['textdomain_calls']);
        self::assertSame(array(), \supcheckout_test_inline_scripts());
    }

    public function test_chunk_json_keyed_by_the_supcheckout_domain_is_inlined(): void {
        $plugin = $this->plugin('{"dependencies":[],"version":"v1"}', '["chunks/panel.0123abcd.js"]');
        $GLOBALS['supcheckout_test_admin_assets']['textdomain_json']['supcheckout-admin-chunk-panel'] = json_encode(array(
            'domain'      => 'supcheckout',
            'locale_data' => array(
                'supcheckout' => array(
                    ''       => array('domain' => 'supcheckout', 'lang' => 'ar'),
                    'Search' => array('بحث'),
                ),
            ),
        ));
        $assets = new AdminAssets($plugin);
        $assets->hook(self::SCREEN);

        self::assertTrue($assets->enqueue_for(self::SCREEN));
        $inline = \supcheckout_test_inline_scripts();
        self::assertCount(1, $inline);
        self::assertSame('before', $inline[0]['position']);
        self::assertStringContainsString('wp.i18n.setLocaleData(l,"supcheckout")', $inline[0]['data']);
        self::assertStringContainsString('"locale_data":{"supcheckout":{"":{"domain":"supcheckout"', $inline[0]['data']);
        self::assertStringContainsString('"Search"', $inline[0]['data']);
    }

    public function test_construct_and_hook_register_no_global_callbacks_beyond_the_two_filters(): void {
        $assets = $this->assets();
        self::assertSame(array(), $GLOBALS['supcheckout_test_hook_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['scripts']);

        $assets->hook(self::SCREEN, 'supcheckout_page_supcheckout-settings');
        $assets->hook('supcheckout_page_supcheckout-payments');

        $recorded = array_map(
            static function (array $call): array {
                return array($call[0], $call[1], $call[2]);
            },
            $GLOBALS['supcheckout_test_hook_calls']
        );
        self::assertSame(array(
            array('action', 'admin_enqueue_scripts', array($assets, 'enqueue_for')),
            array('filter', 'admin_body_class', array($assets, 'body_class')),
        ), $recorded);
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['scripts']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_admin_assets']['textdomain_calls']);

        $GLOBALS['hook_suffix'] = 'supcheckout_page_supcheckout-payments';
        self::assertSame(' supcheckout-admin-page', $assets->body_class(''));
    }

    public function test_root_markup_is_the_bare_mount_point(): void {
        self::assertSame('<div id="supcheckout-admin-root"></div>', $this->assets()->root_markup());
    }

    public function test_identity_constants(): void {
        self::assertSame('supcheckout-admin', AdminAssets::HANDLE);
        self::assertSame('supcheckout-admin-root', AdminAssets::ROOT_ID);
        self::assertSame('supcheckout-admin-page', AdminAssets::BODY_CLASS);
        self::assertSame('supcheckout', AdminAssets::TEXT_DOMAIN);
        self::assertSame('#^chunks/[a-z0-9-]+\.[a-f0-9]{8}\.js\z#', AdminAssets::CHUNK_PATTERN);
    }

    private function assets(): AdminAssets {
        return new AdminAssets(dirname(__DIR__, 3) . '/supcheckout.php');
    }

    /** Writes $json to a temporary file; null returns a path that does not exist. */
    private function fixture(?string $json): string {
        $path = sys_get_temp_dir() . '/supcheckout-admin-assets-' . bin2hex(random_bytes(6)) . '.json';
        if (null !== $json) {
            file_put_contents($path, $json);
            $this->temporary[] = $path;
        }
        return $path;
    }

    /** Builds a temporary plugin directory with the given build manifests. */
    private function plugin(string $manifest, string $chunks): string {
        $root = sys_get_temp_dir() . '/supcheckout-admin-plugin-' . bin2hex(random_bytes(6));
        $build = $root . '/assets/admin/build';
        foreach (array($root, $root . '/assets', $root . '/assets/admin', $build) as $directory) {
            mkdir($directory);
            $this->temporary[] = $directory;
        }
        file_put_contents($build . '/app.asset.json', $manifest);
        $this->temporary[] = $build . '/app.asset.json';
        file_put_contents($build . '/app.chunks.json', $chunks);
        $this->temporary[] = $build . '/app.chunks.json';
        return $root . '/supcheckout.php';
    }
}
