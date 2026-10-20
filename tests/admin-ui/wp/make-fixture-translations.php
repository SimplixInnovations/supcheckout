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
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const SUPCHECKOUT_FIXTURE_DOMAIN = 'supcheckout';
const SUPCHECKOUT_FIXTURE_LOCALE = 'ar';
const SUPCHECKOUT_FIXTURE_CHUNK = '#^chunks/[a-z0-9-]+\.[a-f0-9]{8}\.js$#';

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
