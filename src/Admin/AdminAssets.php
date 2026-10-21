<?php

namespace Simplixi\SUPCheckout\Admin;

defined('ABSPATH') || exit;

/**
 * Loads the SUPCheckout admin React app on SUPCheckout screens only.
 *
 * The committed build under assets/admin/build describes itself through two
 * JSON manifests: app.asset.json (script dependencies and version) and
 * app.chunks.json (lazy chunks). Both are validated strictly and read lazily,
 * inside enqueue_for(), never at construct or hook time. An invalid manifest
 * fails closed: nothing is registered or enqueued.
 *
 * WordPress loads script translations only for registered handles, so each
 * lazy chunk is registered under its own (never enqueued) handle and its
 * translations are inlined into wp.i18n before the app runs.
 */
final class AdminAssets {
    public const HANDLE = 'supcheckout-admin';
    public const ROOT_ID = 'supcheckout-admin-root';
    public const BODY_CLASS = 'supcheckout-admin-page';
    public const TEXT_DOMAIN = 'supcheckout';
    public const CHUNK_PATTERN = '#^chunks/[a-z0-9-]+\.[a-f0-9]{8}\.js\z#';

    private const BUILD_PATH = 'assets/admin/build/';
    private const CHUNK_HANDLE_PREFIX = 'supcheckout-admin-chunk-';
    private const MANIFEST_KEYS = array('dependencies', 'version');

    /** @var string */
    private $plugin_file;

    /** @var array<string, true> Hook suffixes of the SUPCheckout screens. */
    private $hook_suffixes = array();

    /** @var bool */
    private $hooked = false;

    /**
     * @param string $plugin_file Absolute path of the plugin main file. No side effects.
     */
    public function __construct(string $plugin_file) {
        $this->plugin_file = $plugin_file;
    }

    /**
     * Load the app on exactly these screens.
     *
     * Adds `admin_enqueue_scripts` and `admin_body_class` once, however often
     * it is called; every call adds its suffixes to the screen list.
     *
     * @param string ...$hook_suffixes Suffixes returned by add_menu_page()/add_submenu_page().
     */
    public function hook(string ...$hook_suffixes): void {
        foreach ($hook_suffixes as $hook_suffix) {
            if ('' !== $hook_suffix) {
                $this->hook_suffixes[$hook_suffix] = true;
            }
        }

        if ($this->hooked) {
            return;
        }
        add_action('admin_enqueue_scripts', array($this, 'enqueue_for'));
        add_filter('admin_body_class', array($this, 'body_class'));
        $this->hooked = true;
    }

    /**
     * Register and enqueue the app for a SUPCheckout screen.
     *
     * Untyped on purpose, like body_class(): `admin_enqueue_scripts` can be
     * fired with a non-string by another caller, and a TypeError here would
     * break the admin screen. Such a value enqueues nothing.
     *
     * @param mixed $hook_suffix The current admin screen's hook suffix.
     * @return bool False, with nothing enqueued, for a non-string, any other
     *         screen or an invalid manifest.
     */
    public function enqueue_for($hook_suffix): bool {
        if (! is_string($hook_suffix) || ! $this->is_supcheckout_screen($hook_suffix)) {
            return false;
        }

        $build = dirname($this->plugin_file) . '/' . self::BUILD_PATH;
        $manifest = self::manifest($build . 'app.asset.json');
        if (null === $manifest) {
            return false;
        }

        $version = $manifest['version'];
        wp_register_style(self::HANDLE, $this->build_url('app.css'), array(), $version);
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        wp_register_script(
            self::HANDLE,
            $this->build_url('app.js'),
            $manifest['dependencies'],
            $version,
            array('in_footer' => true)
        );
        wp_set_script_translations(self::HANDLE, self::TEXT_DOMAIN);

        foreach (self::chunks($build . 'app.chunks.json') as $chunk) {
            $this->inline_chunk_translations($chunk);
        }

        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);
        return true;
    }

    /**
     * Add the page-level scope class on SUPCheckout screens.
     *
     * Untyped on purpose: an earlier `admin_body_class` filter may return a
     * non-string, and a TypeError here would break every admin screen. Such a
     * value is returned unchanged.
     *
     * @param mixed $classes Space-separated admin body classes.
     * @return mixed
     */
    public function body_class($classes) {
        if (! is_string($classes)) {
            return $classes;
        }
        $hook_suffix = isset($GLOBALS['hook_suffix']) && is_string($GLOBALS['hook_suffix'])
            ? $GLOBALS['hook_suffix']
            : '';
        if (! $this->is_supcheckout_screen($hook_suffix)) {
            return $classes;
        }

        $present = preg_split('/\s+/', trim($classes));
        if (is_array($present) && in_array(self::BODY_CLASS, $present, true)) {
            return $classes;
        }
        return rtrim($classes) . ' ' . self::BODY_CLASS;
    }

    /**
     * Read and strictly validate app.asset.json.
     *
     * @param string $path Absolute manifest path.
     * @return array{dependencies: list<string>, version: string}|null Null unless the
     *         file holds exactly `dependencies` (a list of non-empty strings) and a
     *         non-empty string `version`.
     */
    public static function manifest(string $path): ?array {
        $data = self::decode($path);
        if (! is_array($data)) {
            return null;
        }

        $keys = array_keys($data);
        sort($keys);
        if (self::MANIFEST_KEYS !== $keys) {
            return null;
        }

        $version = $data['version'];
        $dependencies = $data['dependencies'];
        if (! is_string($version) || '' === $version || ! is_array($dependencies)) {
            return null;
        }
        if (array_values($dependencies) !== $dependencies) {
            return null;
        }
        foreach ($dependencies as $dependency) {
            if (! is_string($dependency) || '' === $dependency) {
                return null;
            }
        }

        return array(
            'dependencies' => $dependencies,
            'version' => $version,
        );
    }

    /**
     * Read and strictly validate app.chunks.json.
     *
     * @param string $path Absolute chunk-manifest path.
     * @return list<string> Chunk paths relative to the build directory; empty
     *         when the file is missing or invalid, or when any entry does not
     *         match CHUNK_PATTERN.
     */
    public static function chunks(string $path): array {
        $data = self::decode($path);
        if (! is_array($data) || array_values($data) !== $data) {
            return array();
        }

        foreach ($data as $chunk) {
            if (! is_string($chunk) || 1 !== preg_match(self::CHUNK_PATTERN, $chunk)) {
                return array();
            }
        }

        return $data;
    }

    /**
     * The app's mount point. SupRoot renders the only `.supcheckout-admin`
     * element inside it; the page-level scope is the body class.
     *
     * @return string
     */
    public function root_markup(): string {
        return '<div id="' . esc_attr(self::ROOT_ID) . '"></div>';
    }

    /**
     * @param string $hook_suffix Admin screen hook suffix.
     * @return bool
     */
    private function is_supcheckout_screen(string $hook_suffix): bool {
        return isset($this->hook_suffixes[$hook_suffix]);
    }

    /**
     * @param string $file Path relative to the build directory.
     * @return string
     */
    private function build_url(string $file): string {
        return plugins_url(self::BUILD_PATH . $file, $this->plugin_file);
    }

    /**
     * Register a chunk's handle (never enqueued) and inline its translations.
     *
     * @param string $chunk A CHUNK_PATTERN-valid path, e.g. `chunks/995.08985fb8.js`.
     * @return void
     */
    private function inline_chunk_translations(string $chunk): void {
        $name = substr($chunk, strlen('chunks/'), (int) strpos($chunk, '.') - strlen('chunks/'));
        $handle = self::CHUNK_HANDLE_PREFIX . $name;

        wp_register_script($handle, $this->build_url($chunk), array(), null, array('in_footer' => true));

        $json = load_script_textdomain($handle, self::TEXT_DOMAIN);
        if (! is_string($json) || '' === $json) {
            return;
        }

        $translations = json_decode($json, true);
        if (! is_array($translations)
            || ! isset($translations['locale_data'])
            || ! is_array($translations['locale_data'])
        ) {
            return;
        }
        $locale_data = $translations['locale_data'];
        $domain_data = $locale_data[self::TEXT_DOMAIN] ?? ($locale_data['messages'] ?? null);
        if (! is_array($domain_data) || ! isset($domain_data['']) || ! is_array($domain_data[''])) {
            return;
        }

        $encoded = wp_json_encode($translations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return;
        }

        wp_add_inline_script(
            self::HANDLE,
            '(function(d){var l=d.locale_data["supcheckout"]||d.locale_data.messages;l[""].domain="supcheckout";wp.i18n.setLocaleData(l,"supcheckout");})(' . $encoded . ');',
            'before'
        );
    }

    /**
     * @param string $path Absolute JSON file path.
     * @return mixed The decoded JSON (associative arrays), or null.
     */
    private static function decode(string $path) {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        return wp_json_file_decode($path, array('associative' => true));
    }
}
