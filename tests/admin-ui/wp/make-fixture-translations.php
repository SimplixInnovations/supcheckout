<?php
/**
 * Writes test-only Arabic script translations for the shipped admin bundle.
 *
 * Usage: php tests/admin-ui/wp/make-fixture-translations.php <wordpress-path>
 *
 * WordPress resolves a plugin script's translations at
 * wp-content/languages/plugins/<domain>-<locale>-<md5>.json, where <md5> is
 * the md5 of the script path relative to the plugin root. This writes one
 * Jed 1.x file for assets/admin/build/app.js and one per lazy chunk listed in
 * app.chunks.json, from tests/admin-ui/wp/fixtures/ar-strings.json. Never
 * shipped; no network language pack is needed.
 *
 * It also writes wp-content/languages/plugins/supcheckout-ar.mo with the same
 * strings, so the fixture is a complete language pack. WP_Textdomain_Registry
 * keys a pack on its .mo (or .l10n.php) file, and load_script_textdomain()
 * reads the JSON from the directory the registry returns. Without the .mo the
 * registry finds no pack, the A4-01 `lang_dir_for_domain` fallback points
 * WordPress at the bundled languages/ folder, and the bundled JSON replaces the
 * fixture JSON. With it, the pack wins, as an installed pack does on a real
 * store. The bundled fallback itself is covered by tests/unit/Bootstrap/
 * TranslationsTest.php and the Compatibility ReleaseMetadataTest and
 * I18nEarlyLoadingTest runs, which install no pack.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const SUPCHECKOUT_FIXTURE_DOMAIN = 'supcheckout';
const SUPCHECKOUT_FIXTURE_LOCALE = 'ar';
const SUPCHECKOUT_FIXTURE_CHUNK = '#^chunks/[a-z0-9-]+\.[a-f0-9]{8}\.js\z#';

/**
 * @param string $message Failure reason.
 * @return void Never returns.
 */
function supcheckout_fixture_fail($message) {
    fwrite(STDERR, 'make-fixture-translations: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @param string $path JSON file.
 * @return mixed
 */
function supcheckout_fixture_read_json($path) {
    $raw = is_file($path) ? file_get_contents($path) : false;
    if (false === $raw) {
        supcheckout_fixture_fail("cannot read $path");
    }
    $data = json_decode((string) $raw, true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        supcheckout_fixture_fail("invalid JSON in $path: " . json_last_error_msg());
    }
    return $data;
}

/**
 * @param array<string, string> $strings Source string => Arabic translation.
 * @return array<string, mixed> Jed 1.x document in the shape WP-CLI `i18n make-json` writes.
 */
function supcheckout_fixture_jed(array $strings) {
    $messages = array(
        '' => array(
            'domain' => 'messages',
            'lang' => SUPCHECKOUT_FIXTURE_LOCALE,
            'plural-forms' => 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));',
        ),
    );
    foreach ($strings as $source => $translation) {
        $messages[(string) $source] = array((string) $translation);
    }
    return array(
        'translation-revision-date' => '2026-10-19 00:00:00+0000',
        'generator' => 'SUPCheckout test fixture',
        'domain' => 'messages',
        'locale_data' => array('messages' => $messages),
    );
}

if ($argc !== 2 || !is_dir($argv[1])) {
    supcheckout_fixture_fail('usage: php tests/admin-ui/wp/make-fixture-translations.php <wordpress-path>');
}

$plugin = dirname(__DIR__, 3);
$fixture = supcheckout_fixture_read_json(__DIR__ . '/fixtures/ar-strings.json');
if (!is_array($fixture)
    || !isset($fixture['app'], $fixture['chunks'])
    || !is_array($fixture['app'])
    || !is_array($fixture['chunks'])
) {
    supcheckout_fixture_fail('ar-strings.json needs "app" and "chunks" objects');
}

$chunks = supcheckout_fixture_read_json($plugin . '/assets/admin/build/app.chunks.json');
if (!is_array($chunks) || array() === $chunks) {
    supcheckout_fixture_fail('app.chunks.json lists no chunk');
}

$files = array('assets/admin/build/app.js' => $fixture['app']);
foreach ($chunks as $chunk) {
    if (!is_string($chunk) || 1 !== preg_match(SUPCHECKOUT_FIXTURE_CHUNK, $chunk)) {
        supcheckout_fixture_fail('unexpected chunk entry: ' . var_export($chunk, true));
    }
    $files['assets/admin/build/' . $chunk] = $fixture['chunks'];
}

$languages = rtrim($argv[1], '/\\') . '/wp-content/languages/plugins';
if (!is_dir($languages) && !mkdir($languages, 0755, true) && !is_dir($languages)) {
    supcheckout_fixture_fail("cannot create $languages");
}

foreach ($files as $relative => $strings) {
    if (!is_file($plugin . '/' . $relative)) {
        supcheckout_fixture_fail("built file missing: $relative");
    }
    $target = sprintf(
        '%s/%s-%s-%s.json',
        $languages,
        SUPCHECKOUT_FIXTURE_DOMAIN,
        SUPCHECKOUT_FIXTURE_LOCALE,
        md5($relative)
    );
    $json = json_encode(
        supcheckout_fixture_jed($strings),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if (false === $json || false === file_put_contents($target, $json . "\n")) {
        supcheckout_fixture_fail("cannot write $target");
    }
    echo 'wrote ' . basename($target) . ' for ' . $relative . PHP_EOL;
}

// The pack's .mo, written with WordPress's own MO writer from the target install.
$pomo = rtrim($argv[1], '/\\') . '/wp-includes/pomo/mo.php';
if (!is_file($pomo)) {
    supcheckout_fixture_fail("WordPress MO writer missing: $pomo");
}
require_once $pomo;
$mo = new MO();
$mo->set_header('Language', SUPCHECKOUT_FIXTURE_LOCALE);
$mo->set_header('Content-Type', 'text/plain; charset=UTF-8');
$mo->set_header('Plural-Forms', 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));');
foreach (array_merge($fixture['app'], $fixture['chunks']) as $source => $translation) {
    $mo->add_entry(new Translation_Entry(array(
        'singular' => (string) $source,
        'translations' => array((string) $translation),
    )));
}
$mo_target = sprintf('%s/%s-%s.mo', $languages, SUPCHECKOUT_FIXTURE_DOMAIN, SUPCHECKOUT_FIXTURE_LOCALE);
if (!$mo->export_to_file($mo_target)) {
    supcheckout_fixture_fail("cannot write $mo_target");
}
echo 'wrote ' . basename($mo_target) . ' (the language pack the registry keys on)' . PHP_EOL;
