<?php

namespace Simplixi\SUPCheckout\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * A4-01 (hardening U7): the bundled translation files stay in step with each other and with
 * the source, so a string change cannot silently leave the storefront untranslated.
 *
 * - languages/supcheckout-ar.po has exactly the msgids of languages/supcheckout.pot;
 * - supcheckout-ar.mo holds exactly the .po's translated entries used from PHP;
 * - each supcheckout-ar-<md5>.json holds exactly the .po's entries used from that script,
 *   with their .po translations;
 * - every single-quoted literal translated in the supcheckout domain in src/ and templates/
 *   is in the .pot.
 *
 * The regeneration commands are in docs/project/RELEASE-ENGINEERING.md.
 */
final class TranslationFilesConsistencyTest extends TestCase {
    private const ROOT = __DIR__ . '/../../..';
    private const CONTEXT_GLUE = "\4";

    private static function path(string $relative): string {
        return self::ROOT . '/' . $relative;
    }

    /**
     * Minimal PO reader for the files WP-CLI writes.
     *
     * @return array<string, array{msgstr: string, refs: list<string>}> key (context glue + msgid) => entry
     */
    private static function read_po(string $file): array {
        self::assertFileExists($file);
        $entries = array();
        $blocks = preg_split('/\n{2,}/', str_replace("\r\n", "\n", (string) file_get_contents($file)));
        foreach ((array) $blocks as $block) {
            $refs = array();
            $fields = array();
            $current = null;
            foreach (explode("\n", (string) $block) as $line) {
                if (0 === strpos($line, '#: ')) {
                    foreach (preg_split('/\s+/', trim(substr($line, 3))) as $ref) {
                        $refs[] = (string) preg_replace('/:\d+$/', '', $ref);
                    }
                    continue;
                }
                if (1 === preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $m)) {
                    $current = $m[1];
                    $fields[$current] = self::unescape($m[2]);
                } elseif (null !== $current && 1 === preg_match('/^"(.*)"$/', $line, $m)) {
                    $fields[$current] .= self::unescape($m[1]);
                }
            }
            if (!isset($fields['msgid']) || '' === $fields['msgid']) {
                continue; // The header entry.
            }
            $key = (isset($fields['msgctxt']) ? $fields['msgctxt'] . self::CONTEXT_GLUE : '') . $fields['msgid'];
            $entries[$key] = array('msgstr' => $fields['msgstr'] ?? '', 'refs' => array_values(array_unique($refs)));
        }
        return $entries;
    }

    private static function unescape(string $value): string {
        return (string) preg_replace_callback('/\\\\(.)/', static function ($m) {
            $map = array('n' => "\n", 't' => "\t", '"' => '"', '\\' => '\\');
            return $map[$m[1]] ?? $m[1];
        }, $value);
    }

    /** @return array<string, string> */
    private static function read_mo(string $file): array {
        self::assertFileExists($file);
        $bytes = (string) file_get_contents($file);
        $format = 0x950412de === unpack('V', substr($bytes, 0, 4))[1] ? 'V' : 'N';
        $header = unpack($format . '7', substr($bytes, 0, 28));
        $entries = array();
        for ($i = 0; $i < $header[3]; $i++) {
            $o = unpack($format . '2', substr($bytes, $header[4] + 8 * $i, 8));
            $t = unpack($format . '2', substr($bytes, $header[5] + 8 * $i, 8));
            $entries[substr($bytes, $o[2], $o[1])] = substr($bytes, $t[2], $t[1]);
        }
        unset($entries['']);
        return $entries;
    }

    /** @param list<string> $refs */
    private static function used_from_php(array $refs): bool {
        foreach ($refs as $ref) {
            if ('.js' !== substr($ref, -3)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Owner staging observation O4 (batch 4): the order and thank-you label reads
     * "UPayments payment ID", in Arabic and in the compiled catalogue.
     */
    public function test_the_payment_id_label_reads_upayments_payment_id_in_arabic(): void {
        $po = self::read_po(self::path('languages/supcheckout-ar.po'));
        $mo = self::read_mo(self::path('languages/supcheckout-ar.mo'));
        foreach (array('UPayments payment ID' => 'معرّف دفع يوبيمنتس', 'UPayments payment ID:' => 'معرّف دفع يوبيمنتس:') as $msgid => $arabic) {
            self::assertArrayHasKey($msgid, $po, $msgid);
            self::assertSame($arabic, $po[$msgid]['msgstr'], $msgid);
            self::assertSame($arabic, $mo[$msgid] ?? null, $msgid . ' (.mo)');
        }
        self::assertArrayNotHasKey('UPayment ID', $po);
        self::assertArrayNotHasKey('UPayment ID:', $po);
    }

    public function test_the_po_has_exactly_the_msgids_of_the_pot(): void {
        $pot = array_keys(self::read_po(self::path('languages/supcheckout.pot')));
        $po = array_keys(self::read_po(self::path('languages/supcheckout-ar.po')));
        sort($pot);
        sort($po);

        self::assertNotEmpty($pot);
        self::assertSame($pot, $po, 'regenerate the .po from the .pot (wp i18n update-po)');
    }

    public function test_the_mo_holds_exactly_the_translated_php_entries_of_the_po(): void {
        $expected = array();
        foreach (self::read_po(self::path('languages/supcheckout-ar.po')) as $key => $entry) {
            if ('' !== $entry['msgstr'] && self::used_from_php($entry['refs'])) {
                $expected[$key] = $entry['msgstr'];
            }
        }
        $actual = self::read_mo(self::path('languages/supcheckout-ar.mo'));
        ksort($expected);
        ksort($actual);

        self::assertGreaterThan(100, count($expected));
        self::assertSame($expected, $actual, 'rebuild the .mo from the .po (RELEASE-ENGINEERING.md)');
    }

    /**
     * `wp i18n make-json` writes every entry of a script; an untranslated one carries '' and
     * wp.i18n falls back to the English source for it.
     */
    public function test_each_script_json_holds_exactly_the_po_entries_of_that_script(): void {
        $expected = array();
        $translated = 0;
        foreach (self::read_po(self::path('languages/supcheckout-ar.po')) as $key => $entry) {
            foreach ($entry['refs'] as $ref) {
                if ('.js' === substr($ref, -3)) {
                    $expected[$ref][$key] = array($entry['msgstr']);
                    $translated += '' !== $entry['msgstr'] ? 1 : 0;
                }
            }
        }
        self::assertNotEmpty($expected);
        self::assertGreaterThan(15, $translated, 'the admin app strings are translated');

        $shipped = glob(self::path('languages/supcheckout-ar-*.json')) ?: array();
        self::assertCount(count($expected), $shipped, 'one JSON file per script with strings, and no stale one');
        foreach ($expected as $script => $messages) {
            $file = self::path('languages/supcheckout-ar-' . md5($script) . '.json');
            self::assertFileExists($file, $script);
            $json = json_decode((string) file_get_contents($file), true);
            $actual = $json['locale_data']['messages'] ?? array();
            unset($actual['']);
            ksort($messages);
            ksort($actual);
            self::assertSame($script, $json['source'] ?? null);
            self::assertSame($messages, $actual, 'rebuild the JSON from the .po for ' . $script);
        }
    }

    public function test_every_supcheckout_literal_in_src_and_templates_is_in_the_pot(): void {
        $pot = self::read_po(self::path('languages/supcheckout.pot'));
        $quoted = "'((?:[^'\\\\]|\\\\.)*)'";
        $plain = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*' . $quoted . '\s*,\s*\'supcheckout\'\s*\)/';
        $context = '/\b_x\(\s*' . $quoted . '\s*,\s*' . $quoted . '\s*,\s*\'supcheckout\'\s*\)/';
        $files = array();
        foreach (array('src', 'templates') as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ('php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $found = 0;
        $missing = array();
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all($plain, $source, $matches);
            foreach ($matches[1] as $literal) {
                $found++;
                $key = stripslashes($literal);
                if (!isset($pot[$key])) {
                    $missing[] = $key;
                }
            }
            preg_match_all($context, $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $found++;
                $key = stripslashes($match[2]) . self::CONTEXT_GLUE . stripslashes($match[1]);
                if (!isset($pot[$key])) {
                    $missing[] = $key;
                }
            }
        }

        self::assertGreaterThan(150, $found, 'the scan sees the plugin\'s translated literals');
        self::assertSame(array(), $missing, 'regenerate languages/supcheckout.pot (wp i18n make-pot, RELEASE-ENGINEERING.md)');
    }
}
