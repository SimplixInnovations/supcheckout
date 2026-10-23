<?php

namespace Simplixi\SUPCheckout\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Bootstrap\Translations;

/**
 * A4-01 (verify-P U7): SUPCheckout ships its own Arabic translation and WordPress finds it.
 *
 * WordPress resolves a plugin domain's directory through WP_Textdomain_Registry::get(), which
 * passes the result through `lang_dir_for_domain` (WP 6.6+). Just-in-time loading of the .mo
 * file and load_script_textdomain() (when no path is given) both use that directory. The
 * bundled `languages/` directory is only a fallback: a language pack in WP_LANG_DIR keeps
 * precedence, and no load_plugin_textdomain() call is needed (strict Plugin Check flags it).
 *
 * Each test runs in its own process: Translations::register() is once-per-request.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TranslationsTest extends TestCase {
    private const ROOT = __DIR__ . '/../../..';

    protected function setUp(): void {
        \supcheckout_test_reset_migration_bootstrap();
    }

    private static function root(): string {
        return (string) realpath(self::ROOT);
    }

    private static function languages_dir(): string {
        return str_replace('\\', '/', self::root()) . '/languages/';
    }

    private static function registered_filter(): callable {
        Translations::register(self::root() . '/supcheckout.php');
        foreach ($GLOBALS['supcheckout_test_filter_calls'] as $call) {
            if ('lang_dir_for_domain' === $call[0]) {
                self::assertSame(3, $call[3], 'the filter takes the path, the domain and the locale');
                return $call[1];
            }
        }
        self::fail('Translations::register() adds no lang_dir_for_domain filter');
    }

    /**
     * Minimal GNU MO reader (little- and big-endian), test-only.
     *
     * @return array<string, string> msgid (with "\4" context prefix) => msgstr
     */
    private static function read_mo(string $file): array {
        self::assertFileExists($file);
        $bytes = (string) file_get_contents($file);
        $magic = unpack('V', substr($bytes, 0, 4))[1];
        $format = 0x950412de === $magic ? 'V' : 'N';
        $header = unpack($format . '7', substr($bytes, 0, 28));
        $count = $header[3];
        $originals = $header[4];
        $translations = $header[5];
        $entries = array();
        for ($i = 0; $i < $count; $i++) {
            $o = unpack($format . '2', substr($bytes, $originals + 8 * $i, 8));
            $t = unpack($format . '2', substr($bytes, $translations + 8 * $i, 8));
            $entries[substr($bytes, $o[2], $o[1])] = substr($bytes, $t[2], $t[1]);
        }
        return $entries;
    }

    private static function resolve(callable $filter, $path, string $domain, string $locale) {
        $resolved = $filter($path, $domain, $locale);
        return is_string($resolved) ? str_replace('\\', '/', $resolved) : $resolved;
    }

    public function test_arabic_checkout_string_resolves_from_the_bundled_file_when_no_language_pack_exists(): void {
        $filter = self::registered_filter();

        $dir = self::resolve($filter, false, 'supcheckout', 'ar');

        self::assertSame(self::languages_dir(), $dir, 'with no language pack, the ar locale resolves to the bundled languages/ directory');
        $entries = self::read_mo($dir . 'supcheckout-ar.mo');
        self::assertSame('ادفع بأمان عبر', $entries['Pay securely with'] ?? null, '"Pay securely with" resolves to Arabic');
        self::assertSame('حالة الدفع:', $entries['Payment Status:'] ?? null, 'the order-totals row resolves to Arabic');
        self::assertStringContainsString('nplurals=6;', $entries[''] ?? '', 'the Arabic plural forms header is set');
    }

    public function test_a_language_pack_in_wp_lang_dir_keeps_precedence(): void {
        $filter = self::registered_filter();
        $pack = 'C:/site/wp-content/languages/plugins/';

        self::assertSame($pack, $filter($pack, 'supcheckout', 'ar'));
    }

    public function test_other_domains_locales_without_a_bundled_file_and_unsafe_locales_are_left_alone(): void {
        $filter = self::registered_filter();

        self::assertFalse($filter(false, 'woocommerce', 'ar'), 'another domain is never redirected');
        self::assertFalse($filter(false, 'supcheckout', 'fr_FR'), 'a locale with no bundled file keeps WordPress\'s answer');
        self::assertFalse($filter(false, 'supcheckout', '../ar'), 'a locale that is not a locale name never builds a path');
        self::assertFalse($filter(false, 'supcheckout', ''), 'an empty locale never builds a path');
        self::assertNull($filter(null, 'supcheckout', array()), 'a non-string locale returns the value unchanged');
    }

    public function test_register_is_idempotent(): void {
        Translations::register(self::root() . '/supcheckout.php');
        Translations::register(self::root() . '/supcheckout.php');

        $filters = array_filter($GLOBALS['supcheckout_test_filter_calls'], static function ($call) {
            return 'lang_dir_for_domain' === $call[0];
        });
        self::assertCount(1, $filters);
    }

    public function test_admin_script_translations_ship_under_the_md5_names_load_script_textdomain_looks_for(): void {
        $build = self::root() . '/assets/admin/build/';
        $chunks = json_decode((string) file_get_contents($build . 'app.chunks.json'), true);
        self::assertIsArray($chunks);
        $scripts = array('assets/admin/build/app.js');
        foreach ($chunks as $chunk) {
            if (is_string($chunk) && str_contains($chunk, 'command-palette')) {
                $scripts[] = 'assets/admin/build/' . $chunk;
            }
        }
        self::assertCount(2, $scripts, 'the app and the command-palette chunk carry translatable strings');

        foreach ($scripts as $script) {
            $file = self::languages_dir() . 'supcheckout-ar-' . md5($script) . '.json';
            self::assertFileExists($file, $script . ' has bundled Arabic script translations');
            $json = json_decode((string) file_get_contents($file), true);
            self::assertSame('ar', $json['locale_data']['messages']['']['lang'] ?? null, $script . ' JSON is Arabic Jed data');
        }
    }

    public function test_every_arabic_translation_keeps_the_placeholders_of_its_source(): void {
        $entries = self::read_mo(self::languages_dir() . 'supcheckout-ar.mo');
        $pattern = '/%(?:\d+\$)?[sdf]/';
        self::assertGreaterThan(100, count($entries), 'the customer-facing strings are translated');
        foreach ($entries as $original => $translation) {
            if ('' === $original) {
                continue;
            }
            preg_match_all($pattern, $original, $want);
            sort($want[0]);
            preg_match_all($pattern, $translation, $have);
            sort($have[0]);
            self::assertSame($want[0], $have[0], 'placeholders are kept for: ' . $original);
        }
    }

    public function test_the_package_ships_the_template_and_the_header_points_at_it(): void {
        self::assertFileExists(self::languages_dir() . 'supcheckout.pot');
        self::assertFileExists(self::languages_dir() . 'supcheckout-ar.po');
        $header = (string) file_get_contents(self::root() . '/supcheckout.php', false, null, 0, 1024);
        self::assertMatchesRegularExpression('/^ \* Domain Path: \/languages$/m', $header);
    }
}
