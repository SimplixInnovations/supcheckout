<?php
/**
 * Symbols that the official php-stubs/wordpress-stubs and
 * php-stubs/woocommerce-stubs packages do not declare (A6-06, hardening batch 6).
 *
 * Analysis only: PHPStan scans this file and never executes it. Each symbol
 * mirrors the upstream public signature; nothing here models plugin behaviour.
 */

// WordPress defines these in wp-includes/class-wpdb.php, outside the stubs.
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

// Plugin bootstrap constants: Simplixi\SUPCheckout\Plugin::define_constants()
// defines them at runtime from the main file path, so PHPStan cannot read a
// literal value.
define('SUPCHECKOUT_PLUGIN_URL', '');
define('SUPCHECKOUT_PLUGIN_PATH', '');

/**
 * WP-CLI public API (wp-cli/wp-cli, class WP_CLI), only the methods src/ calls.
 */
class WP_CLI {
    /**
     * @param string $name
     * @param callable|object|string $callable
     * @param array<string,mixed> $args
     * @return bool
     */
    public static function add_command($name, $callable, $args = array()) {
        return true;
    }

    /**
     * @param string $message
     * @return void
     */
    public static function line($message = '') {
    }

    /**
     * @param string $message
     * @return void
     */
    public static function warning($message) {
    }

    /**
     * @param string|\WP_Error|\Exception|\Throwable $message
     * @param bool|int $exit
     * @return void
     */
    public static function error($message, $exit = true) {
    }

    /**
     * @param string $question
     * @param array<string,mixed> $assoc_args
     * @return void
     */
    public static function confirm($question, $assoc_args = array()) {
    }

    /**
     * @param int $return_code
     * @return void
     */
    public static function halt($return_code) {
    }
}

// Cache-plugin public purge APIs called by Simplixi\SUPCheckout\Takeover\CachePurger,
// each only after its plugin was detected.

/**
 * WP Rocket.
 *
 * @param string $lang
 * @return void
 */
function rocket_clean_domain($lang = '') {
}

/**
 * W3 Total Cache.
 *
 * @param mixed $extras
 * @return void
 */
function w3tc_flush_all($extras = null) {
}

/**
 * WP Super Cache.
 *
 * @param int $blog_id
 * @return void
 */
function wp_cache_clear_cache($blog_id = 0) {
}

/**
 * SiteGround Optimizer.
 *
 * @return void
 */
function sg_cachepress_purge_everything() {
}
