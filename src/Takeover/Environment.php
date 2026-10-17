<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Gateway\Gateway;
use Simplixi\SUPCheckout\Provider\EndpointResolver;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;

defined('ABSPATH') || exit;

/**
 * Everything Preflight reads, as one value object (Sub-project 0b Task 4).
 *
 * Read-only by construction: it holds readers only, and the database is reachable
 * solely through select_var() / select_col() / select_row(), which refuse anything but
 * a plain SELECT or SHOW (no FOR UPDATE, INTO, locks or stacked statements). There is
 * no write, schedule or hook capability to fake or to misuse. Tests build it from an
 * array of fakes; Environment::from_wordpress() binds the same fields to WordPress.
 *
 * Fields (all required):
 * - options: callable(string $name, mixed $default): mixed, get_option();
 * - db: object with prepare/get_var/get_col/get_row/esc_like and the prefix, options,
 *   posts and postmeta table names (global $wpdb), or null without a database;
 * - hpos: bool, WooCommerce HPOS is the authoritative order store;
 * - currency: string, the store currency;
 * - availability: callable(array $settings): ?array, the read-only credential probe
 *   (PaymentMethodAvailability::probe());
 * - pending_orders: callable(int $since): int, pending gateway orders created after $since;
 * - order_sample: callable(int $limit): list<object>, the newest gateway orders (WC_Order);
 * - post_meta: callable(int $id, string $key): array, get_post_meta($id, $key, false);
 * - http_get: callable(string $url): ?array{status:int, headers:array<string,string>},
 *   the loopback GET (never a body);
 * - callback_probe_url: string;
 * - plugins_dir: string, own_plugin_dir: string, active_plugins: list<string>;
 * - object_cache_transient: callable(string $name): bool, a transient held only in an
 *   external object cache;
 * - post_status: callable(int $id): ?string;
 * - now: int.
 */
final class Environment {
    private const FIELDS = array(
        'options', 'db', 'hpos', 'currency', 'availability', 'pending_orders', 'order_sample', 'post_meta', 'http_get',
        'callback_probe_url', 'plugins_dir', 'own_plugin_dir', 'active_plugins',
        'object_cache_transient', 'post_status', 'now',
    );
    private const CALLABLE_FIELDS = array(
        'options', 'availability', 'pending_orders', 'order_sample', 'post_meta', 'http_get', 'object_cache_transient', 'post_status',
    );
    private const DB_METHODS = array('prepare', 'get_var', 'get_col', 'get_row', 'esc_like');
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/D';
    private const READ_ONLY_START = '/^\s*(SELECT|SHOW)\s/i';
    /** Stacked statements, comments, locking reads, INTO, variable assignment and side-effecting functions. */
    private const READ_ONLY_REFUSED = '/;|\/\*|--|#|\bFOR\s+(UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bINTO\b|:=|\b(GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|SLEEP|BENCHMARK|LOAD_FILE)\s*\(/i';

    private const LOOPBACK_TIMEOUT = 10;
    private const LOOPBACK_MAX_BODY = 1024;

    /** @var array<string, mixed> */
    private $fields;

    /** @var array<string, string> */
    private $tables = array();

    /**
     * @param array<string, mixed> $fields Every field listed in the class docblock, nothing else.
     * @throws \InvalidArgumentException On a missing, unknown or ill-typed field.
     */
    public function __construct(array $fields) {
        $unknown = array_diff(array_keys($fields), self::FIELDS);
        $missing = array_diff(self::FIELDS, array_keys($fields));
        if ($unknown !== array() || $missing !== array()) {
            throw new \InvalidArgumentException(esc_html(
                'Preflight environment fields: unknown [' . implode(', ', $unknown) . '], missing [' . implode(', ', $missing) . ']'
            ));
        }
        foreach (self::CALLABLE_FIELDS as $name) {
            if (!is_callable($fields[$name])) {
                throw new \InvalidArgumentException(esc_html('Preflight environment field is not callable: ' . $name));
            }
        }
        $fields['hpos'] = (bool) $fields['hpos'];
        $fields['currency'] = (string) $fields['currency'];
        $fields['callback_probe_url'] = (string) $fields['callback_probe_url'];
        $fields['plugins_dir'] = (string) $fields['plugins_dir'];
        $fields['own_plugin_dir'] = (string) $fields['own_plugin_dir'];
        $fields['active_plugins'] = is_array($fields['active_plugins'])
            ? array_values(array_filter($fields['active_plugins'], 'is_string'))
            : array();
        $fields['now'] = (int) $fields['now'];
        $fields['db'] = self::usable_db($fields['db']);
        $this->fields = $fields;
        if ($fields['db'] !== null) {
            $this->tables = self::tables_of($fields['db']);
        }
    }

    /**
     * Bind every field to WordPress and WooCommerce. Nothing here writes: each binding
     * is a reader, and the provider and loopback calls are GETs whose results are not
     * stored anywhere.
     *
     * @return self
     */
    public static function from_wordpress() {
        global $wpdb;

        return new self(array(
            'options' => static function ($name, $default) {
                return function_exists('get_option') ? get_option($name, $default) : $default;
            },
            'db' => isset($wpdb) ? $wpdb : null,
            'hpos' => self::hpos_enabled(),
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'availability' => array(self::class, 'probe_availability'),
            'pending_orders' => static function ($since) {
                if (!function_exists('wc_get_orders')) {
                    return 0;
                }
                $result = wc_get_orders(array(
                    'type' => 'shop_order',
                    'status' => array('pending'),
                    'payment_method' => Keys::payment_method_query(),
                    'date_created' => '>' . (int) $since,
                    'limit' => 1,
                    'paginate' => true,
                    'return' => 'ids',
                ));
                return is_object($result) && isset($result->total) ? (int) $result->total : 0;
            },
            'order_sample' => static function ($limit) {
                if (!function_exists('wc_get_orders')) {
                    return array();
                }
                $orders = wc_get_orders(array(
                    'type' => 'shop_order',
                    'payment_method' => Keys::payment_method_query(),
                    'limit' => (int) $limit,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'return' => 'objects',
                ));
                return is_array($orders) ? $orders : array();
            },
            'post_meta' => static function ($id, $key) {
                if (!function_exists('get_post_meta')) {
                    return array();
                }
                $values = get_post_meta((int) $id, (string) $key, false);
                return is_array($values) ? $values : array();
            },
            'http_get' => array(self::class, 'loopback_get'),
            'callback_probe_url' => self::callback_probe_url_from_wordpress(),
            'plugins_dir' => defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '',
            'own_plugin_dir' => self::own_plugin_dir_from_wordpress(),
            'active_plugins' => self::active_plugins_from_wordpress(),
            'object_cache_transient' => static function ($name) {
                return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
                    && function_exists('wp_cache_get') && wp_cache_get((string) $name, 'transient') !== false;
            },
            'post_status' => static function ($id) {
                if (!function_exists('get_post_status')) {
                    return null;
                }
                $status = get_post_status((int) $id);
                return is_string($status) ? $status : null;
            },
            'now' => time(),
        ));
    }

    /**
     * @param string $name    Option name.
     * @param mixed  $default Default.
     * @return mixed
     */
    public function option($name, $default = null) {
        return call_user_func($this->fields['options'], (string) $name, $default);
    }

    /**
     * @param string      $query SELECT/SHOW template with %s, %d and %i placeholders.
     * @param list<mixed> $args  Placeholder values.
     * @return string|null
     */
    public function select_var($query, array $args) {
        $value = $this->select('get_var', $query, $args);
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param string      $query SELECT/SHOW template.
     * @param list<mixed> $args  Placeholder values.
     * @return list<string>
     */
    public function select_col($query, array $args) {
        $values = $this->select('get_col', $query, $args);
        if (!is_array($values)) {
            return array();
        }
        return array_values(array_map('strval', array_filter($values, 'is_scalar')));
    }

    /**
     * @param string      $query SELECT/SHOW template.
     * @param list<mixed> $args  Placeholder values.
     * @return array<string, mixed>|null
     */
    public function select_row($query, array $args) {
        $row = $this->select('get_row', $query, $args);
        if (is_object($row)) {
            $row = get_object_vars($row);
        }
        return is_array($row) ? $row : null;
    }

    /**
     * @param string $text Literal text for a LIKE pattern.
     * @return string
     */
    public function esc_like($text) {
        $db = $this->fields['db'];
        return $db === null ? addcslashes((string) $text, '_%\\') : (string) $db->esc_like((string) $text);
    }

    /**
     * @param string $name One of prefix, options, posts, postmeta, orders, orders_meta.
     * @return string Table name ('' without a database).
     */
    public function table($name) {
        return isset($this->tables[$name]) ? $this->tables[$name] : '';
    }

    /** @return bool */
    public function has_db() {
        return $this->fields['db'] !== null;
    }

    /** @return bool */
    public function hpos() {
        return $this->fields['hpos'];
    }

    /** @return string */
    public function currency() {
        return $this->fields['currency'];
    }

    /**
     * @param array<string, mixed> $settings The settings the switch would copy.
     * @return array<string, mixed>|null
     */
    public function availability(array $settings) {
        $result = call_user_func($this->fields['availability'], $settings);
        return is_array($result) ? $result : null;
    }

    /**
     * @param int $since Unix time.
     * @return int Pending gateway orders created after $since.
     */
    public function pending_orders($since) {
        return max(0, (int) call_user_func($this->fields['pending_orders'], (int) $since));
    }

    /**
     * @param int $limit Maximum orders.
     * @return list<mixed> The newest gateway orders, at most $limit.
     */
    public function order_sample($limit) {
        $orders = call_user_func($this->fields['order_sample'], (int) $limit);
        return is_array($orders) ? array_slice(array_values($orders), 0, max(0, (int) $limit)) : array();
    }

    /**
     * @param int    $id  Order / post ID.
     * @param string $key Meta key.
     * @return list<mixed> Every row, in storage order.
     */
    public function post_meta($id, $key) {
        $values = call_user_func($this->fields['post_meta'], (int) $id, (string) $key);
        return is_array($values) ? array_values($values) : array();
    }

    /**
     * @param string $url URL.
     * @return array{status:int, headers:array<string,string>}|null Null on a transport error.
     */
    public function http_get($url) {
        $response = call_user_func($this->fields['http_get'], (string) $url);
        if (!is_array($response) || !isset($response['status'])) {
            return null;
        }
        $headers = array();
        if (isset($response['headers']) && is_array($response['headers'])) {
            foreach ($response['headers'] as $name => $value) {
                if (is_string($name) && (is_scalar($value) || is_array($value))) {
                    $headers[strtolower($name)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                }
            }
        }
        return array('status' => (int) $response['status'], 'headers' => $headers);
    }

    /** @return string */
    public function callback_probe_url() {
        return $this->fields['callback_probe_url'];
    }

    /** @return string */
    public function plugins_dir() {
        return $this->fields['plugins_dir'];
    }

    /** @return string */
    public function own_plugin_dir() {
        return $this->fields['own_plugin_dir'];
    }

    /** @return list<string> */
    public function active_plugins() {
        return $this->fields['active_plugins'];
    }

    /**
     * @param string $name Transient name.
     * @return bool
     */
    public function object_cache_transient($name) {
        return (bool) call_user_func($this->fields['object_cache_transient'], (string) $name);
    }

    /**
     * @param int $id Post ID.
     * @return string|null
     */
    public function post_status($id) {
        $status = call_user_func($this->fields['post_status'], (int) $id);
        return is_string($status) ? $status : null;
    }

    /** @return int */
    public function now() {
        return $this->fields['now'];
    }

    /**
     * The read-only credential probe bound by from_wordpress().
     *
     * @param array<string, mixed> $settings Gateway settings.
     * @return array<string, mixed>|null
     */
    public static function probe_availability(array $settings) {
        $test_mode = isset($settings['test_mode']) && $settings['test_mode'] === 'yes';
        $api_key = isset($settings['api_key']) && is_string($settings['api_key']) ? $settings['api_key'] : '';
        $service = new PaymentMethodAvailability($test_mode, $api_key, static function () use ($test_mode, $api_key) {
            return self::provider_get($test_mode, $api_key);
        });
        return $service->probe();
    }

    /**
     * Loopback GET of the callback probe: no cookies, no redirects, a 1 KB body cap,
     * and the body discarded; only the status and the response header names/values
     * come back.
     *
     * @param string $url URL.
     * @return array{status:int, headers:array<string,string>}|null
     */
    public static function loopback_get($url) {
        if ($url === '' || !function_exists('wp_remote_get')) {
            return null;
        }
        $response = wp_remote_get($url, array(
            'timeout' => self::LOOPBACK_TIMEOUT,
            'redirection' => 0,
            'limit_response_size' => self::LOOPBACK_MAX_BODY,
            'cookies' => array(),
            // UPayments verifies the certificate when it calls back, so the probe does
            // too: an invalid certificate must fail here, not pass.
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            return null;
        }
        $headers = array();
        if (function_exists('wp_remote_retrieve_headers')) {
            $raw = wp_remote_retrieve_headers($response);
            if (is_object($raw) && method_exists($raw, 'getAll')) {
                $raw = $raw->getAll();
            }
            if (is_array($raw)) {
                $headers = $raw;
            }
        }
        return array('status' => (int) wp_remote_retrieve_response_code($response), 'headers' => $headers);
    }

    /**
     * The availability GET through the gateway's own transport
     * (Gateway::execute_upayments_request(): Bearer auth, 15 s, no redirects, 1 MB cap,
     * the gateway's user agent and endpoint), so Preflight adds no provider HTTP
     * implementation. The instance skips the gateway constructor, which loads settings
     * and registers hooks; only the credential and mode are set. The response goes to
     * PaymentMethodAvailability::probe() and is never logged or stored.
     *
     * @param bool   $test_mode Sandbox.
     * @param string $api_key   Credential.
     * @return mixed The transport outcome, or a failed outcome without WooCommerce.
     */
    private static function provider_get($test_mode, $api_key) {
        if (!class_exists('WC_Payment_Gateway', false)) {
            return array('transport_ok' => false, 'body' => null, 'http_status' => 0, 'curl_errno' => 1);
        }
        $gateway = new class() extends Gateway {
            /** Skip settings load and hook registration. */
            public function __construct() {}

            /** @return mixed */
            public function availability_request() {
                return $this->execute_upayments_request(EndpointResolver::CHECK_PAYMENT_BUTTON_STATUS, 'GET');
            }
        };
        $gateway->apiKey = $api_key;
        $gateway->testMode = $test_mode ? 'yes' : 'no';
        return $gateway->availability_request();
    }

    /**
     * @param string      $method get_var, get_col or get_row.
     * @param string      $query  Template.
     * @param list<mixed> $args   Placeholder values.
     * @return mixed
     * @throws \LogicException For anything but a plain SELECT/SHOW.
     */
    private function select($method, $query, array $args) {
        $query = (string) $query;
        if (preg_match(self::READ_ONLY_START, $query) !== 1 || preg_match(self::READ_ONLY_REFUSED, $query) === 1) {
            throw new \LogicException('Takeover preflight is read-only: refused a statement that is not a plain SELECT or SHOW.');
        }
        $db = $this->fields['db'];
        if ($db === null) {
            return null;
        }
        $prepared = $args === array() ? $query : $db->prepare($query, ...$args);
        if (!is_string($prepared) || $prepared === '') {
            return null;
        }
        if ($method === 'get_row') {
            return $db->get_row($prepared, defined('ARRAY_A') ? constant('ARRAY_A') : 'ARRAY_A');
        }
        return $method === 'get_col' ? $db->get_col($prepared) : $db->get_var($prepared);
    }

    /**
     * @param mixed $db Candidate database object.
     * @return object|null
     */
    private static function usable_db($db) {
        if (!is_object($db)) {
            return null;
        }
        foreach (self::DB_METHODS as $method) {
            if (!method_exists($db, $method)) {
                return null;
            }
        }
        foreach (array('prefix', 'options', 'posts', 'postmeta') as $property) {
            if (!isset($db->$property) || !is_string($db->$property) || preg_match(self::IDENTIFIER, $db->$property) !== 1) {
                return null;
            }
        }
        return $db;
    }

    /**
     * @param object $db A usable database.
     * @return array<string, string>
     */
    private static function tables_of($db) {
        $prefix = (string) $db->prefix;
        return array(
            'prefix' => $prefix,
            'options' => (string) $db->options,
            'posts' => (string) $db->posts,
            'postmeta' => (string) $db->postmeta,
            'orders' => $prefix . 'wc_orders',
            'orders_meta' => $prefix . 'wc_orders_meta',
        );
    }

    /**
     * Shared with the takeover copier, which reads postmeta for the same disagreement.
     *
     * @return bool True when WooCommerce HPOS is the authoritative order store.
     */
    public static function hpos_enabled() {
        // OrderUtil exists since WooCommerce 6.9; SUPCheckout requires 10.8.
        if (!class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            return false;
        }
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * The URL UPayments will call after cutover (WooCommerce's own WC-API URL for the
     * new route) plus the probe argument.
     *
     * @return string '' when neither WooCommerce nor home_url() is available.
     */
    private static function callback_probe_url_from_wordpress() {
        $base = '';
        if (function_exists('WC')) {
            $woocommerce = WC();
            if (is_object($woocommerce) && method_exists($woocommerce, 'api_request_url')) {
                $url = $woocommerce->api_request_url(Keys::CALLBACK_ROUTE);
                $base = is_string($url) ? $url : '';
            }
        }
        if ($base === '' && function_exists('home_url') && function_exists('add_query_arg')) {
            $base = (string) add_query_arg('wc-api', Keys::CALLBACK_ROUTE, home_url('/'));
        }
        if ($base === '' || !function_exists('add_query_arg')) {
            return '';
        }
        return (string) add_query_arg(Preflight::PROBE_ARG, '1', $base);
    }

    /**
     * SUPCheckout's own install directory, from its main file's location (as
     * WordPressCutoverSystem finds the official plugin), so it is right whatever the
     * directory is named and works from the paused boot. No runtime class is loaded:
     * Release/Identity.php registers the payment lifecycle when it loads, which a paused
     * SUPCheckout must never do while the official plugin owns `wc_upayments`.
     *
     * @return string
     */
    private static function own_plugin_dir_from_wordpress() {
        $root = dirname(__DIR__, 2);
        if (function_exists('plugin_basename')) {
            $dir = dirname(str_replace('\\', '/', (string) plugin_basename($root . '/supcheckout.php')));
            if ($dir !== '.' && $dir !== '') {
                return $dir;
            }
        }
        return basename($root);
    }

    /** @return list<string> */
    private static function active_plugins_from_wordpress() {
        if (!function_exists('get_option')) {
            return array();
        }
        $active = get_option('active_plugins', array());
        $active = is_array($active) ? $active : array();
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $sitewide = get_site_option('active_sitewide_plugins', array());
            if (is_array($sitewide)) {
                $active = array_merge($active, array_keys($sitewide));
            }
        }
        return array_values(array_filter($active, 'is_string'));
    }
}
