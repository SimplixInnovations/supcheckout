<?php

namespace Simplixi\SUPCheckout\Bootstrap;

defined('ABSPATH') || exit;

/**
 * Lets WordPress find the translations bundled in `languages/` (A4-01).
 *
 * WordPress resolves the directory of a plugin text domain in WP_Textdomain_Registry::get(),
 * which passes its answer through `lang_dir_for_domain` (WP 6.6+). Just-in-time loading of
 * `supcheckout-{locale}.mo` and load_script_textdomain() for a script registered without a
 * path both read that directory, so this one filter serves PHP and admin script strings.
 *
 * The bundled file is only a fallback: when WordPress already found a language pack in
 * WP_LANG_DIR (or another plugin set a custom path), its answer is kept. No
 * load_plugin_textdomain() call is made, which strict Plugin Check reports for plugins
 * hosted on WordPress.org.
 */
final class Translations {
    public const DOMAIN = 'supcheckout';
    public const DIRECTORY = 'languages';
    private const LOCALE_PATTERN = '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*(?:-[A-Za-z0-9]+)?\z/';

    /** @var string|null Absolute path of the bundled languages directory, with a trailing slash. */
    private static $directory = null;

    /**
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function register($main_file) {
        if (self::$directory !== null) {
            return;
        }
        self::$directory = dirname((string) $main_file) . '/' . self::DIRECTORY . '/';
        add_filter('lang_dir_for_domain', array(self::class, 'filter_lang_dir'), 10, 3);
    }

    /**
     * @param mixed $path   The directory WordPress found, or false when it found none.
     * @param mixed $domain Text domain.
     * @param mixed $locale Locale being loaded.
     * @return mixed The bundled directory when it is the only source for the locale, else $path.
     */
    public static function filter_lang_dir($path, $domain, $locale) {
        if (self::DOMAIN !== $domain || (is_string($path) && '' !== $path) || self::$directory === null) {
            return $path;
        }
        if (!is_string($locale) || 1 !== preg_match(self::LOCALE_PATTERN, $locale)) {
            return $path;
        }
        if (!is_readable(self::$directory . self::DOMAIN . '-' . $locale . '.mo')) {
            return $path;
        }
        return self::$directory;
    }

    private function __construct() {}
}
