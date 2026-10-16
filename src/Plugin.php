<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

/**
 * Composition root. From Task 3 on, every WordPress hook SUPCheckout registers is
 * added from here (or from a class this method calls), never from the main file.
 */
final class Plugin {
    /** @var string|null */
    private static $main_file = null;

    /**
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function boot($main_file) {
        if (self::$main_file !== null) {
            return;
        }
        self::$main_file = (string) $main_file;
    }

    /** @return string */
    public static function main_file() {
        return (string) self::$main_file;
    }

    private function __construct() {}
}
