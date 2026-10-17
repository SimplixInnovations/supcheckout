<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * What the official UPayments plugin left on this store, read for Preflight
 * (Sub-project 0b Task 4; inventory §3, §7 and hazards 7 and 8).
 *
 * Read-only data collection through Environment; Preflight turns it into report
 * entries. Plugin files are located with glob() and is_file(), and only the main
 * file's header is read (get_file_data()). The official debug.log is checked for
 * presence only: it is never opened, read or copied.
 */
final class OfficialFootprint {
    private const MAIN_FILE = 'UPayments.php';
    private const DEBUG_LOG = 'debug.log';
    private const NAMES_LIMIT = 100;

    /** Official 3.0.0 activation page option and 3.1.1 cron lock (Keys::official_only()). */
    private const BLOCK_CHECKOUT_PAGE_OPTION = 'upayments_block_checkout_page_id';
    private const CRON_LOCK_TRANSIENT = 'upayments_cron_lock';
    /** Keys::official_only() table; reported, never touched. */
    private const SUBSCRIPTIONS_TABLE = 'upay_subscriptions';

    /**
     * Every other plugin directory whose main file is UPayments.php, active or not.
     *
     * @param Environment $env Environment.
     * @return list<array{slug:string, basename:string, path:string, version:string, active:bool}>
     */
    public static function plugins(Environment $env) {
        $root = rtrim(str_replace('\\', '/', $env->plugins_dir()), '/');
        if ($root === '' || !is_dir($root)) {
            return array();
        }
        $files = glob($root . '/*/' . self::MAIN_FILE);
        $found = array();
        foreach (is_array($files) ? $files : array() as $file) {
            $slug = basename(dirname($file));
            if ($slug === $env->own_plugin_dir() || !is_file($file)) {
                continue;
            }
            $basename = $slug . '/' . self::MAIN_FILE;
            $found[] = array(
                'slug' => $slug,
                'basename' => $basename,
                'path' => dirname($file),
                'version' => self::header_version($file),
                'active' => in_array($basename, $env->active_plugins(), true),
            );
        }
        usort($found, static function ($a, $b) {
            return strcmp($a['slug'], $b['slug']);
        });
        return $found;
    }

    /**
     * Hazard 8: debug.log files by presence only, as paths relative to the plugins
     * directory (no server layout).
     *
     * @param list<array{slug:string, basename:string, path:string, version:string, active:bool}> $plugins
     * @return list<string>
     */
    public static function debug_logs(array $plugins) {
        $paths = array();
        foreach ($plugins as $plugin) {
            if (is_file($plugin['path'] . '/' . self::DEBUG_LOG)) {
                $paths[] = $plugin['slug'] . '/' . self::DEBUG_LOG;
            }
        }
        return $paths;
    }

    /**
     * Which Keys::official_only() options, transients and cron hooks exist.
     *
     * @param Environment $env Environment.
     * @return array{options: list<string>, transients: list<string>, cron_hooks: list<string>}
     */
    public static function names(Environment $env) {
        $official = Keys::official_only();
        $exact = $official['options'];
        foreach ($official['transients'] as $transient) {
            $exact[] = '_transient_' . $transient;
            $exact[] = '_transient_timeout_' . $transient;
        }
        $likes = array();
        foreach ($official['option_prefixes'] as $prefix) {
            $likes[] = $env->esc_like($prefix) . '%';
        }
        $rows = $env->select_col(
            'SELECT option_name FROM %i WHERE option_name IN (' . implode(', ', array_fill(0, count($exact), '%s')) . ')'
                . str_repeat(' OR option_name LIKE %s', count($likes))
                . ' ORDER BY option_name LIMIT ' . self::NAMES_LIMIT,
            array_merge(array($env->table('options')), $exact, $likes)
        );

        $options = array();
        $transients = array();
        foreach ($rows as $name) {
            $transient = self::transient_of($name, $official['transients']);
            if ($transient !== null) {
                $transients[$transient] = true;
            } elseif (in_array($name, $official['options'], true) || self::has_prefix($name, $official['option_prefixes'])) {
                $options[$name] = true;
            }
        }
        foreach ($official['transients'] as $transient) {
            if ($env->object_cache_transient($transient)) {
                $transients[$transient] = true;
            }
        }

        return array(
            'options' => self::sorted_keys($options),
            'transients' => self::sorted_keys($transients),
            'cron_hooks' => self::sorted_keys(self::cron_hooks($env, $official)),
        );
    }

    /**
     * @param Environment $env Environment.
     * @return string|null The prefixed upay_subscriptions table name when it exists.
     */
    public static function subscriptions_table(Environment $env) {
        if (!$env->has_db()) {
            return null;
        }
        $table = $env->table('prefix') . self::SUBSCRIPTIONS_TABLE;
        $found = $env->select_var('SHOW TABLES LIKE %s', array($env->esc_like($table)));
        return $found === $table ? $table : null;
    }

    /**
     * Hazard 7: the 3.0.0 "Checkout (Block)" page, the forced phone field, the update
     * checker and the cron lock.
     *
     * @param Environment                                                                       $env   Environment.
     * @param array{options: list<string>, transients: list<string>, cron_hooks: list<string>} $names names().
     * @return array{block_checkout_page: array{page_id:int, status:string, is_checkout_page:bool}|null, phone_field_required: bool, update_checker: bool, cron_lock: bool}
     */
    public static function leftovers(Environment $env, array $names) {
        $official = Keys::official_only();
        $page = null;
        $page_id = $env->option(self::BLOCK_CHECKOUT_PAGE_OPTION, null);
        $page_id = is_numeric($page_id) ? (int) $page_id : 0;
        if ($page_id > 0) {
            $status = $env->post_status($page_id);
            if ($status !== null) {
                $checkout = $env->option('woocommerce_checkout_page_id', null);
                $page = array(
                    'page_id' => $page_id,
                    'status' => $status,
                    'is_checkout_page' => is_numeric($checkout) && (int) $checkout === $page_id,
                );
            }
        }
        $update_checker = false;
        foreach ($names['options'] as $name) {
            $update_checker = $update_checker || self::has_prefix($name, $official['option_prefixes']);
        }
        foreach ($names['cron_hooks'] as $hook) {
            $update_checker = $update_checker || self::has_prefix($hook, $official['cron_hook_prefixes']);
        }
        return array(
            'block_checkout_page' => $page,
            'phone_field_required' => $env->option('woocommerce_checkout_phone_field', null) === 'required',
            'update_checker' => $update_checker,
            'cron_lock' => in_array(self::CRON_LOCK_TRANSIENT, $names['transients'], true),
        );
    }

    /**
     * The plugin header Version through WordPress's get_file_data() (the first 8 KB of
     * the main file only).
     *
     * @param string $file Main plugin file.
     * @return string
     */
    private static function header_version($file) {
        if (!function_exists('get_file_data')) {
            return 'unknown';
        }
        $headers = get_file_data($file, array('version' => 'Version'));
        $version = is_array($headers) && isset($headers['version']) ? trim((string) $headers['version']) : '';
        return preg_match('/^[0-9A-Za-z.+_-]{1,32}$/D', $version) === 1 ? $version : 'unknown';
    }

    /**
     * @param Environment                                                $env      Environment.
     * @param array{cron_hooks: list<string>, cron_hook_prefixes: list<string>} $official Keys::official_only().
     * @return array<string, bool>
     */
    private static function cron_hooks(Environment $env, array $official) {
        $hooks = array();
        $cron = $env->option('cron', array());
        foreach (is_array($cron) ? $cron : array() as $events) {
            if (!is_array($events)) {
                continue;
            }
            foreach (array_keys($events) as $hook) {
                $hook = (string) $hook;
                if (in_array($hook, $official['cron_hooks'], true) || self::has_prefix($hook, $official['cron_hook_prefixes'])) {
                    $hooks[$hook] = true;
                }
            }
        }
        return $hooks;
    }

    /**
     * @param string       $name       Option name.
     * @param list<string> $transients Transient names.
     * @return string|null The transient an option row belongs to.
     */
    private static function transient_of($name, array $transients) {
        foreach ($transients as $transient) {
            if ($name === '_transient_' . $transient || $name === '_transient_timeout_' . $transient) {
                return $transient;
            }
        }
        return null;
    }

    /**
     * @param string       $name     Name.
     * @param list<string> $prefixes Prefixes.
     * @return bool
     */
    private static function has_prefix($name, array $prefixes) {
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, bool> $set Set.
     * @return list<string>
     */
    private static function sorted_keys(array $set) {
        $keys = array_map('strval', array_keys($set));
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function __construct() {}
}
