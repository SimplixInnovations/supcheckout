<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\Environment;

/**
 * Fake environments for PreflightTest. minimal() is a healthy store with nothing to
 * report beyond the always-present facts; each test overrides only what it checks.
 */
final class PreflightFixtures {
    public const NOW = 1800000000;
    public const PROBE_URL = 'https://shop.example/wc-api/supcheckout/?supcheckout_probe=1';

    /** @return array<string, mixed> */
    public static function canonical_success($white_label = false) {
        return array(
            'schema' => 3,
            'result' => 'success',
            'isWhiteLabel' => $white_label,
            'payButtons' => array(
                'knet' => 1,
                'credit_card' => 1,
                'apple_pay_knet' => 0,
                'apple_pay' => 0,
                'samsung_pay' => 0,
                'google_pay' => 0,
            ),
        );
    }

    /**
     * The fields of a healthy store.
     *
     * @return array<string, mixed>
     */
    public static function minimal() {
        return array(
            'option_values' => array(
                'woocommerce_upayments_settings' => array(
                    'enabled' => 'yes',
                    'api_key' => 'test-api-key-123',
                    'test_mode' => 'yes',
                    'enable_multimerchant' => 'no',
                ),
            ),
            'db' => self::db(),
            'hpos' => false,
            'currency' => 'KWD',
            'availability' => static function (array $settings) {
                return self::canonical_success();
            },
            'pending_orders' => static function ($since) {
                return 0;
            },
            'order_sample' => static function ($limit) {
                return array();
            },
            'post_meta' => static function ($id, $key) {
                return array();
            },
            'http_get' => static function ($url) {
                return array('status' => 200, 'headers' => array('x-supcheckout-probe' => '1', 'cache-control' => 'no-store'));
            },
            'callback_probe_url' => self::PROBE_URL,
            'plugins_dir' => '',
            'own_plugin_dir' => 'supcheckout',
            'active_plugins' => array(),
            'network_active_plugins' => array(),
            'object_cache_transient' => static function ($name) {
                return false;
            },
            'post_status' => static function ($id) {
                return null;
            },
            'now' => self::NOW,
        );
    }

    /**
     * A database with no matching rows for any Preflight query.
     *
     * @param array<string, mixed> $handlers Extra handlers, matched first.
     * @return PreflightFakeDb
     */
    public static function db(array $handlers = array()) {
        return new PreflightFakeDb($handlers + array(
            'AS multi' => '0',
            'AS dup' => array('grp' => '0', 'extra' => '0'),
            'option_value LIKE' => array(),
            'option_name IN (' => array(),
            'SHOW TABLES LIKE' => null,
        ));
    }

    /**
     * Build an Environment from minimal() with overrides. 'option_values' becomes the
     * read-only `options` reader.
     *
     * @param array<string, mixed> $overrides
     * @return Environment
     */
    public static function env(array $overrides = array()) {
        $fields = array_merge(self::minimal(), $overrides);
        $values = $fields['option_values'];
        unset($fields['option_values']);
        $fields['options'] = static function ($name, $default) use ($values) {
            return array_key_exists($name, $values) ? $values[$name] : $default;
        };
        return new Environment($fields);
    }

    /**
     * @param list<array{code:string, message:string, data:array<string,mixed>}> $entries
     * @return array{code:string, message:string, data:array<string,mixed>}|null
     */
    public static function find(array $entries, $code) {
        foreach ($entries as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * A temporary plugins directory; each entry is slug => main-file header Version.
     *
     * @param array<string, string> $plugins
     * @return string
     */
    public static function plugins_dir(array $plugins) {
        $root = sys_get_temp_dir() . '/sup-preflight-' . getmypid() . '-' . bin2hex(random_bytes(4));
        foreach ($plugins as $slug => $version) {
            @mkdir($root . '/' . $slug, 0777, true);
            file_put_contents(
                $root . '/' . $slug . '/UPayments.php',
                "<?php\n/**\n * Plugin Name: UPayments\n * Version: {$version}\n */\n"
            );
        }
        @mkdir($root, 0777, true);
        return $root;
    }

    /**
     * @param string $dir
     * @return void
     */
    public static function remove_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach (is_array($items) ? $items : array() as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::remove_dir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
