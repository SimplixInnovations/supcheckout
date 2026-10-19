<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The test-only gallery mu-plugin (tests/admin-ui/wp/supcheckout-ui-gallery.php)
 * must be inert when copied without SUPCHECKOUT_UI_GALLERY === true: no global
 * function, no constant and no hook. Each case loads it in a fresh PHP process
 * with no WordPress function defined, so any hook call would be fatal.
 */
final class GalleryMuPluginInertTest extends TestCase {
    public function test_without_the_constant_the_mu_plugin_declares_nothing(): void {
        $result = $this->load('');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(array('functions' => array(), 'constants' => array()), $result['declared']);
    }

    public function test_a_non_true_constant_keeps_the_mu_plugin_inert(): void {
        $result = $this->load("define('SUPCHECKOUT_UI_GALLERY', 1);");

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(array('functions' => array(), 'constants' => array()), $result['declared']);
    }

    /**
     * @param string $prelude PHP run before the mu-plugin is included.
     * @return array{exit: int, output: string, declared: mixed}
     */
    private function load(string $prelude): array {
        $plugin = dirname(__DIR__, 2) . '/admin-ui/wp/supcheckout-ui-gallery.php';
        self::assertFileExists($plugin);
        $probe = sys_get_temp_dir() . '/supcheckout-gallery-probe-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($probe, "<?php\ndefine('ABSPATH', __DIR__ . '/');\n" . $prelude . "\n"
            . 'include ' . var_export($plugin, true) . ";\n"
            . '$functions = array_values(array_filter(get_defined_functions()[\'user\'], static function ($name) {'
            . " return 0 === strpos(\$name, 'supcheckout_ui_gallery'); }));\n"
            . '$constants = array_keys(array_filter(get_defined_constants(), static function ($value, $name) {'
            . " return 0 === strpos(\$name, 'SUPCHECKOUT_UI_GALLERY_'); }, ARRAY_FILTER_USE_BOTH));\n"
            . "echo json_encode(array('functions' => \$functions, 'constants' => \$constants));\n");

        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($probe) . ' 2>&1';
        $lines = array();
        $exit = 1;
        try {
            exec($command, $lines, $exit);
        } finally {
            unlink($probe);
        }
        $output = implode("\n", $lines);

        return array(
            'exit' => $exit,
            'output' => $output,
            'declared' => json_decode((string) end($lines), true),
        );
    }
}
