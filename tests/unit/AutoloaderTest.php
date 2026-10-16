<?php

namespace Simplixi\SUPCheckout\Tests;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Autoloader;

final class AutoloaderTest extends TestCase {
    public function test_it_maps_the_namespace_root_to_src_and_ignores_foreign_classes(): void {
        require_once dirname(__DIR__, 2) . '/src/Autoloader.php';
        $dir = sys_get_temp_dir() . '/supcheckout-autoload-' . getmypid();
        @mkdir($dir . '/Probe', 0777, true);
        file_put_contents($dir . '/Probe/Sample.php', "<?php\nnamespace Simplixi\\SUPCheckout\\Probe;\nfinal class Sample { const OK = 1; }\n");
        Autoloader::register($dir);

        self::assertSame(1, \Simplixi\SUPCheckout\Probe\Sample::OK);
        self::assertFalse(class_exists('UPayments\\Subscription\\Manager', true));
        self::assertFalse(class_exists('Simplixi\\SUPCheckout\\Probe\\..\\Escape', true));
    }
}
