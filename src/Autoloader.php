<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

/**
 * PSR-4 autoloader for Simplixi\SUPCheckout\ only. Hand-written so the package has no
 * Composer runtime dependency; it never loads anything outside the namespace root.
 */
final class Autoloader {
    private const PREFIX = 'Simplixi\\SUPCheckout\\';

    /** @var string|null */
    private static $base_dir = null;

    /**
     * @param string $base_dir Absolute path of src/.
     * @return void
     */
    public static function register($base_dir) {
        if (self::$base_dir !== null) {
            return;
        }
        self::$base_dir = rtrim((string) $base_dir, '/\\') . '/';
        spl_autoload_register(array(self::class, 'load'));
    }

    /**
     * @param string $class Fully qualified class name.
     * @return void
     */
    public static function load($class) {
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        if (!preg_match('/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$/', $relative)) {
            return;
        }
        $file = self::$base_dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    private function __construct() {}
}
