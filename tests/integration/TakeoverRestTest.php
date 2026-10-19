<?php
/**
 * Sub-project 0c Task 9: the admin REST API (supcheckout/v1) and the Action Scheduler
 * onboarding driver on real WordPress/WooCommerce, on the store TakeoverCliTest.php has
 * taken through two switch cycles (official-plugin stand-in active, SUPCheckout paused,
 * state preflight_ok, the takeover HTTP mu-plugin answering both probes, so no provider
 * traffic).
 *
 * REST calls run in-process through rest_do_request() as an administrator with the
 * `wp_rest` nonce in X-WP-Nonce. rest_do_request() never applies `rest_post_dispatch`, so
 * the response headers are checked over real HTTP by phase rest_http instead. Every
 * Action Scheduler tick runs in a fresh process (`wp action-scheduler run --hooks=
 * supcheckout_onboarding_drive,supcheckout_onboarding_watchdog --batch-size=1 --batches=1
 * --force`), so it boots exactly as a real queue run would, paused or full; the loop runs
 * at most MAX_RUNS times and pauses PAUSE seconds after a run that found nothing due, until
 * the operation is not running. It filters by the two onboarding hooks, not by
 * `--group=supcheckout-onboarding`: while Action Scheduler's own migration to its tables is
 * incomplete (no WP-Cron in these cells), its HybridStore claims from the posts store first,
 * which throws "The group ... does not exist" for a group it never stored
 * (ActionScheduler_HybridStore::stake_claim()). Both hooks are only ever in that group.
 *
 *   SUPCHECKOUT_CERT_PHASE=rest_http     (tests/integration/takeover-rest-http.sh, with the
 *                                        nginx + PHP-FPM stack up) the wire headers: no-store
 *                                        and private on every response, Retry-After on a 429,
 *                                        `_envelope`, and an Application Password without the
 *                                        nonce refused. Run paused and again switched.
 *   SUPCHECKOUT_CERT_PHASE=rest_paused   the 8 paused routes and their access rules, a REST
 *                                        preflight, then a REST switch driven to the end
 *                                        (self-test included), payment state untouched;
 *   SUPCHECKOUT_CERT_PHASE=rest_switched in a fresh, fully booted process: the 12 routes, the
 *                                        self-test, the setup checks, a finalize dry run and
 *                                        the finalize refused before 30 days;
 *   SUPCHECKOUT_CERT_PHASE=rest_rollback a REST rollback with the official stand-in
 *                                        reactivated; the 120 s settle is a scheduled resume;
 *   SUPCHECKOUT_CERT_PHASE=rest_sweep    (after the CLI finalize) a REST finalize sweep, then
 *                                        a rollback refused.
 *
 * Every phase also proves no response body, operation record or stored report holds the
 * stored API key.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/takeover-cert.php';

use Simplixi\SUPCheckout\Rest\Responses;
use Simplixi\SUPCheckout\Rest\Routes;
use Simplixi\SUPCheckout\Takeover\CopyRemoval;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\DriverStep;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;
use Simplixi\SUPCheckout\Takeover\Rollback;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/** Most `action-scheduler run` processes one operation may take. */
const SUPCHECKOUT_TAKEOVER_REST_MAX_RUNS = 60;
/** Seconds to wait after a run that found nothing due. */
const SUPCHECKOUT_TAKEOVER_REST_PAUSE = 3;
/** Order statuses and payment methods before the REST switch (fixture option). */
const SUPCHECKOUT_TAKEOVER_REST_ORDERS = 'supcheckout_takeover_rest_cert_orders';
const SUPCHECKOUT_TAKEOVER_REST_CUSTOMER = 'supcheckout-rest-cert-customer';

const SUPCHECKOUT_TAKEOVER_REST_PAUSED_ROUTES = array(
    'GET /onboarding/preflight',
    'GET /onboarding/status',
    'POST /onboarding/abort',
    'POST /onboarding/finalize',
    'POST /onboarding/preflight',
    'POST /onboarding/rollback',
    'POST /onboarding/stale-claims/release',
    'POST /onboarding/switch',
);
const SUPCHECKOUT_TAKEOVER_REST_FULL_ONLY_ROUTES = array(
    'GET /onboarding/self-test',
    'GET /setup/checks',
    'POST /onboarding/self-test',
    'POST /setup/checks',
);

/** @var list<string> Every response body this process received (the API-key invariant). */
$GLOBALS['supcheckout_takeover_rest_bodies'] = array();

/** @return int An administrator's ID. */
function supcheckout_takeover_rest_admin() {
    static $admin = 0;
    if ($admin === 0) {
        $ids = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        $admin = isset($ids[0]) ? (int) $ids[0] : 0;
        supcheckout_cert_assert($admin > 0 && user_can($admin, 'manage_woocommerce'), 'an administrator with manage_woocommerce exists');
    }
    return $admin;
}

/**
 * One in-process REST request (rest_do_request()).
 *
 * @param string     $method  GET or POST.
 * @param string     $route   Route relative to supcheckout/v1.
 * @param array|null $params  JSON body.
 * @param int|null   $user_id Acting user: the administrator by default, 0 logged out.
 * @param bool       $nonce   Send a valid `wp_rest` nonce in X-WP-Nonce.
 * @return array{status:int, data:array<string, mixed>, json:string}
 */
function supcheckout_takeover_rest_call($method, $route, $params = null, $user_id = null, $nonce = true) {
    $user_id = $user_id === null ? supcheckout_takeover_rest_admin() : (int) $user_id;
    // Each real request starts with an empty runtime object cache; this long-lived process
    // would otherwise serve options (and "option does not exist") cached before the
    // Action Scheduler processes wrote them.
    wp_cache_flush();
    wp_set_current_user($user_id);
    $request = new WP_REST_Request($method, '/' . Routes::NAMESPACE . $route);
    if ($params !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($params));
    }
    if ($nonce && $user_id > 0) {
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    }
    $response = rest_do_request($request);
    $data = rest_get_server()->response_to_data($response, false);
    $json = (string) wp_json_encode($data);
    $GLOBALS['supcheckout_takeover_rest_bodies'][] = $json;
    return array('status' => (int) $response->get_status(), 'data' => is_array($data) ? $data : array(), 'json' => $json);
}

/**
 * @param array{status:int, data:array<string, mixed>, json:string} $response A response.
 * @param int         $status Expected HTTP status.
 * @param string|null $code   Expected error code.
 * @param string      $label  What was requested.
 * @return void
 */
function supcheckout_takeover_rest_expect(array $response, $status, $code, $label) {
    $code_ok = $code === null || (isset($response['data']['code']) && $response['data']['code'] === $code);
    supcheckout_cert_assert($response['status'] === $status && $code_ok, $label . ' → ' . $status . ($code === null ? '' : ' ' . $code) . ': ' . $response['status'] . ' ' . $response['json']);
}

/**
 * POST a start route and expect 202 with a running operation of $kind.
 *
 * @param string               $route  Route.
 * @param array<string, mixed> $params JSON body.
 * @param string               $kind   Operation kind.
 * @return string The operation ID.
 */
function supcheckout_takeover_rest_start($route, array $params, $kind) {
    $response = supcheckout_takeover_rest_call('POST', $route, $params);
    supcheckout_takeover_rest_expect($response, 202, null, 'POST ' . $route . ' ' . wp_json_encode($params));
    $op = isset($response['data']['data']['operation']) ? $response['data']['data']['operation'] : null;
    supcheckout_cert_assert(is_array($op) && $op['kind'] === $kind && $op['outcome'] === Operation::RUNNING && is_string($op['id']) && $op['id'] !== '', 'POST ' . $route . ' started a running ' . $kind . ' operation: ' . $response['json']);
    return $op['id'];
}

/**
 * Run Action Scheduler's onboarding group, one action per fresh `wp` process, until the
 * operation is not running.
 *
 * @param string        $op_id   Operation ID.
 * @param callable|null $observe function (array $op): void, called with every running snapshot.
 * @return array<string, mixed> The finished operation record.
 */
function supcheckout_takeover_rest_drive($op_id, $observe = null) {
    $started = time();
    $log = array();
    for ($run = 1; $run <= SUPCHECKOUT_TAKEOVER_REST_MAX_RUNS; $run++) {
        $op = Operation::current();
        supcheckout_cert_assert(is_array($op) && $op['id'] === $op_id, 'operation ' . $op_id . ' is the current one: ' . wp_json_encode($op));
        if ($op['outcome'] !== Operation::RUNNING) {
            supcheckout_cert_note(sprintf('%s operation %s ended %s after %d run(s) in %d s: %s', $op['kind'], $op_id, $op['outcome'], $run - 1, time() - $started, implode('; ', $log)));
            return $op;
        }
        if (is_callable($observe)) {
            call_user_func($observe, $op);
        }
        if ($run === 1) {
            $queued = supcheckout_takeover_rest_queued($op_id);
            supcheckout_cert_assert(in_array(AsyncDriver::GROUP, array_column($queued['actions'], 'group'), true), 'the ' . $op['kind'] . ' operation has its drive action queued in the onboarding group: ' . wp_json_encode($queued));
        }
        $result = supcheckout_takeover_cert_wp('action-scheduler run --hooks=' . AsyncDriver::HOOK . ',' . AsyncDriver::WATCHDOG_HOOK . ' --batch-size=1 --batches=1 --force');
        // "Found N scheduled task(s)" is printed only for a claimed batch; the closing
        // "Success: N ... completed" counts progress-bar ticks, which stay 0 without a TTY.
        $claimed = preg_match('/Found ([0-9]+) scheduled task/', $result->stdout, $match) === 1 ? (int) $match[1] : 0;
        $log[] = '#' . $run . ' ' . $op['phase'] . ' claimed ' . $claimed;
        supcheckout_cert_assert($result->return_code === 0 && strpos($result->stdout, 'Success:') !== false, 'Action Scheduler run #' . $run . ' for the ' . $op['kind'] . ' operation succeeds: ' . $result->stdout . $result->stderr);
        if ($claimed === 0) {
            sleep(SUPCHECKOUT_TAKEOVER_REST_PAUSE);
        }
    }
    throw new RuntimeException('FAIL: operation ' . $op_id . ' still running after ' . SUPCHECKOUT_TAKEOVER_REST_MAX_RUNS . ' Action Scheduler runs (' . (time() - $started) . ' s): ' . implode('; ', $log) . ' ' . wp_json_encode(Operation::current()));
}

/**
 * The operation's drive actions as Action Scheduler stored them, read from its tables.
 *
 * @param string $op_id Operation ID.
 * @return array{store:string, actions:list<array<string, mixed>>, group_row:mixed}
 */
function supcheckout_takeover_rest_queued($op_id) {
    global $wpdb;
    $actions_table = $wpdb->prefix . 'actionscheduler_actions';
    $groups_table = $wpdb->prefix . 'actionscheduler_groups';
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT a.action_id, a.status, a.group_id, a.scheduled_date_gmt, g.slug AS `group` FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.hook = %s AND a.args = %s ORDER BY a.action_id',
        $actions_table,
        $groups_table,
        AsyncDriver::HOOK,
        (string) wp_json_encode(array($op_id))
    ), ARRAY_A);
    return array(
        'store' => class_exists('ActionScheduler') ? get_class(ActionScheduler::store()) : 'none',
        'actions' => is_array($rows) ? $rows : array(),
        'group_row' => $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE slug = %s', $groups_table, AsyncDriver::GROUP), ARRAY_A),
        'db_error' => $wpdb->last_error,
    );
}

/**
 * Drive an operation and expect it `done` without an error.
 *
 * @param string        $op_id   Operation ID.
 * @param callable|null $observe Snapshot observer.
 * @return array<string, mixed>
 */
function supcheckout_takeover_rest_drive_done($op_id, $observe = null) {
    $op = supcheckout_takeover_rest_drive($op_id, $observe);
    supcheckout_cert_assert($op['outcome'] === Operation::DONE && $op['error'] === null, 'the ' . $op['kind'] . ' operation is driven to done: ' . wp_json_encode($op));
    return $op;
}

/**
 * @param string $name Option name.
 * @return string|null The stored value, read past every cache.
 */
function supcheckout_takeover_rest_option_raw($name) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));
    return $value === null ? null : (string) $value;
}

/**
 * Make sure a rate bucket is open before a request that must not be rate limited. A write
 * bucket (10 s) is waited out; the 65 s provider_probe bucket is reopened by the fixture,
 * because this cell does not wait a minute per probe (RateLimiterTest owns the window).
 *
 * @param string $bucket Bucket.
 * @return void
 */
function supcheckout_takeover_rest_open_bucket($bucket) {
    $raw = supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . $bucket);
    $wait = $raw === null ? 0 : (int) $raw - time();
    if ($wait <= 0) {
        return;
    }
    if ($wait <= RateLimiter::WRITE_WINDOW) {
        supcheckout_cert_note('waiting ' . ($wait + 1) . ' s for the ' . $bucket . ' rate bucket');
        sleep($wait + 1);
        return;
    }
    delete_option(RateLimiter::OPTION_PREFIX . $bucket);
    supcheckout_cert_note('fixture: reopened the ' . $bucket . ' rate bucket (' . $wait . ' s left of its window)');
}

/** @return array<int, array{0:string, 1:string}> Every order: status and payment method. */
function supcheckout_takeover_rest_orders() {
    wp_cache_flush();
    $orders = array();
    foreach (wc_get_orders(array('limit' => -1, 'type' => 'shop_order', 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC')) as $order_id) {
        $order = wc_get_order((int) $order_id);
        if ($order instanceof WC_Order) {
            $orders[(int) $order_id] = array($order->get_status(), $order->get_payment_method());
        }
    }
    return $orders;
}

/**
 * @param string $label When.
 * @return void
 */
function supcheckout_takeover_rest_assert_orders_unchanged($label) {
    $before = get_option(SUPCHECKOUT_TAKEOVER_REST_ORDERS, null);
    supcheckout_cert_assert(is_array($before) && $before !== array(), 'the order snapshot exists');
    $now = supcheckout_takeover_rest_orders();
    supcheckout_cert_assert($now === $before, $label . ': every order keeps its status and payment method (' . count($before) . ' orders): ' . wp_json_encode(array_diff_assoc(array_map('wp_json_encode', $now), array_map('wp_json_encode', $before))));
}

/** @return string The stored API key (the certification key TakeoverCliTest seeded). */
function supcheckout_takeover_rest_api_key() {
    $settings = maybe_unserialize((string) supcheckout_takeover_rest_option_raw('woocommerce_upayments_settings'));
    $key = is_array($settings) && isset($settings['api_key']) ? (string) $settings['api_key'] : '';
    supcheckout_cert_assert(strpos($key, 'takeover-cli-certification-key-') === 0, 'the stored API key is the certification key');
    return $key;
}

/**
 * No response body of this process, no operation record and no stored check report holds
 * the stored API key.
 *
 * @param list<string> $extra More bodies (the real HTTP responses).
 * @return void
 */
function supcheckout_takeover_rest_assert_no_secret(array $extra = array()) {
    $key = supcheckout_takeover_rest_api_key();
    $bodies = array_merge($GLOBALS['supcheckout_takeover_rest_bodies'], $extra);
    supcheckout_cert_assert($bodies !== array(), 'responses were collected');
    foreach ($bodies as $index => $body) {
        supcheckout_cert_assert(strpos($body, $key) === false, 'response body #' . $index . ' never contains the API key');
    }
    $stored = array(Operation::OPTION);
    foreach (array(Operation::KIND_PREFLIGHT, Operation::KIND_SELF_TEST, Operation::KIND_SETUP_CHECKS) as $kind) {
        $stored[] = DriverStep::last_report_option($kind);
    }
    foreach ($stored as $name) {
        $raw = supcheckout_takeover_rest_option_raw($name);
        supcheckout_cert_assert($raw === null || strpos($raw, $key) === false, $name . ' never contains the API key');
    }
    supcheckout_cert_note(count($bodies) . ' response bodies and ' . count($stored) . ' stored records hold no API key');
}

/** @return list<string> "METHOD /route" of every registered supcheckout/v1 endpoint, sorted. */
function supcheckout_takeover_rest_routes() {
    $prefix = '/' . Routes::NAMESPACE;
    $keys = array();
    foreach (rest_get_server()->get_routes() as $route => $endpoints) {
        if (strpos((string) $route, $prefix . '/') !== 0) {
            continue;
        }
        foreach ($endpoints as $endpoint) {
            foreach (array_keys((array) $endpoint['methods']) as $method) {
                $keys[] = $method . ' ' . substr((string) $route, strlen($prefix));
            }
        }
    }
    sort($keys);
    return $keys;
}

/**
 * @param string $label When.
 * @return array<string, mixed> GET /onboarding/status `data`, checked 200 and at most 32768 bytes.
 */
function supcheckout_takeover_rest_status($label) {
    $status = supcheckout_takeover_rest_call('GET', '/onboarding/status');
    supcheckout_takeover_rest_expect($status, 200, null, $label . ': GET /onboarding/status');
    supcheckout_cert_assert(strlen($status['json']) <= 32768 && isset($status['data']['data']['takeover']['status']), $label . ': the status document is bounded (' . strlen($status['json']) . ' bytes)');
    return $status['data']['data'];
}

/**
 * @param mixed $report A Checks report.
 * @return array<string, array<string, mixed>> Its checks by id.
 */
function supcheckout_takeover_rest_checks($report) {
    $checks = array();
    foreach (is_array($report) && isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : array() as $check) {
        if (is_array($check) && isset($check['id'])) {
            $checks[(string) $check['id']] = $check;
        }
    }
    return $checks;
}

/**
 * @param array<string, array<string, mixed>> $checks Checks by id.
 * @param string                              $id     Check.
 * @return string|null Its result.
 */
function supcheckout_takeover_rest_result(array $checks, $id) {
    return isset($checks[$id]['result']) ? (string) $checks[$id]['result'] : null;
}

/** @return bool The official stand-in is active (read past the object cache). */
function supcheckout_takeover_rest_official_active() {
    wp_cache_flush();
    return is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL);
}

/** @return string The takeover status (fresh read). */
function supcheckout_takeover_rest_state() {
    TakeoverState::refresh();
    return (string) TakeoverState::get()['status'];
}

/**
 * One real HTTP request to this cell's WordPress.
 *
 * @param string               $method  Method.
 * @param string               $url     URL.
 * @param array<string,string> $headers Request headers.
 * @param array|null           $params  JSON body.
 * @return array{status:int, cache_control:mixed, retry_after:mixed, data:array<string, mixed>, json:string}
 */
function supcheckout_takeover_rest_http($method, $url, array $headers, $params = null) {
    if ($params !== null) {
        $headers['Content-Type'] = 'application/json';
    }
    $response = wp_remote_request($url, array(
        'method' => $method,
        'headers' => $headers,
        'body' => $params === null ? null : (string) wp_json_encode($params),
        'timeout' => 20,
        'redirection' => 0,
    ));
    supcheckout_cert_assert(!is_wp_error($response), $method . ' ' . $url . ' is answered: ' . (is_wp_error($response) ? $response->get_error_message() : ''));
    $json = (string) wp_remote_retrieve_body($response);
    $data = json_decode($json, true);
    return array(
        'status' => (int) wp_remote_retrieve_response_code($response),
        'cache_control' => wp_remote_retrieve_header($response, 'cache-control'),
        'retry_after' => wp_remote_retrieve_header($response, 'retry-after'),
        'data' => is_array($data) ? $data : array(),
        'json' => $json,
    );
}

/**
 * @param mixed $value A Cache-Control header as received (an array when sent more than once).
 * @return bool Sent once, with both the no-store and the private directive.
 */
function supcheckout_takeover_rest_uncacheable($value) {
    if (!is_string($value)) {
        return false;
    }
    $directives = array_map('trim', explode(',', strtolower($value)));
    return in_array('no-store', $directives, true) && in_array('private', $directives, true);
}

$phase = (string) getenv('SUPCHECKOUT_CERT_PHASE');

if ($phase === 'rest_http') {
    $base = rtrim((string) getenv('SUPCHECKOUT_REST_HTTP_BASE'), '/');
    supcheckout_cert_assert(preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $base) === 1, 'the loopback base URL is set: ' . $base);
    supcheckout_cert_note('real HTTP against ' . $base . ', ' . (defined('SUPCHECKOUT_VERSION') ? 'full boot' : 'paused boot') . ', takeover ' . supcheckout_takeover_rest_state());
    $url = static function ($route, array $query = array()) use ($base) {
        return $base . '/index.php?' . http_build_query(array_merge(array('rest_route' => '/' . Routes::NAMESPACE . $route), $query));
    };
    $http_bodies = array();
    $call = static function ($method, $route, array $query, array $headers, $params = null) use ($url, &$http_bodies) {
        $response = supcheckout_takeover_rest_http($method, $url($route, $query), $headers, $params);
        $http_bodies[] = $response['json'];
        return $response;
    };

    // A real logged-in session for the administrator: its logged_in cookie and the matching
    // `wp_rest` nonce, exactly what wp-admin hands the admin screens.
    $admin = supcheckout_takeover_rest_admin();
    $expiration = time() + HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance($admin)->create($expiration);
    $logged_in = wp_generate_auth_cookie($admin, $expiration, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $logged_in;
    wp_set_current_user($admin);
    $session = array('Cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode($logged_in), 'X-WP-Nonce' => wp_create_nonce('wp_rest'));
    $core_nocache = wp_get_nocache_headers()['Cache-Control'];

    // Logged out: core sends no no-cache headers, so the header on the wire is ours.
    $anonymous = $call('GET', '/onboarding/status', array(), array());
    supcheckout_cert_assert($anonymous['status'] === 401 && ($anonymous['data']['code'] ?? '') === 'rest_forbidden', 'logged out, GET status over HTTP → 401: ' . $anonymous['status'] . ' ' . $anonymous['json']);
    supcheckout_cert_assert($anonymous['cache_control'] === Responses::NO_STORE, 'the 401 carries exactly one Cache-Control: no-store, private (Routes::no_store at rest_post_dispatch): ' . wp_json_encode($anonymous['cache_control']));

    // Logged in: serve_request() sends core's no-cache headers after the response's own (WP
    // 6.3.2+), so core's value, which also holds no-store and private, replaces ours.
    $status = $call('GET', '/onboarding/status', array(), $session);
    supcheckout_cert_assert($status['status'] === 200 && ($status['data']['ok'] ?? null) === true, 'the administrator\'s cookie and nonce: GET status over HTTP → 200: ' . $status['status'] . ' ' . $status['json']);
    supcheckout_cert_assert($status['cache_control'] === $core_nocache && supcheckout_takeover_rest_uncacheable($status['cache_control']), 'the 200 carries one Cache-Control with no-store and private (core\'s logged-in value "' . $core_nocache . '" replaces ours on the wire): ' . wp_json_encode($status['cache_control']));
    supcheckout_cert_assert(strlen($status['json']) <= 32768, 'the status body is at most 32768 bytes over HTTP');

    // `_envelope`: core moves the response's headers into the body and answers 200.
    $enveloped = $call('GET', '/onboarding/status', array('_envelope' => '1'), $session);
    supcheckout_cert_assert($enveloped['status'] === 200 && ($enveloped['data']['status'] ?? null) === 200 && ($enveloped['data']['headers']['Cache-Control'] ?? null) === Responses::NO_STORE, 'an enveloped status carries our Cache-Control in body.headers: ' . $enveloped['json']);
    supcheckout_cert_assert($enveloped['cache_control'] === $core_nocache, 'logged in, the enveloped response is still no-store, private on the wire (core\'s no-cache headers): ' . wp_json_encode($enveloped['cache_control']));
    $enveloped_anonymous = $call('GET', '/onboarding/status', array('_envelope' => '1'), array());
    supcheckout_cert_assert($enveloped_anonymous['status'] === 200 && ($enveloped_anonymous['data']['status'] ?? null) === 401 && ($enveloped_anonymous['data']['headers']['Cache-Control'] ?? null) === Responses::NO_STORE && ($enveloped_anonymous['data']['body']['code'] ?? '') === 'rest_forbidden', 'logged out, an enveloped status is the 401 error with our Cache-Control in body.headers: ' . $enveloped_anonymous['json']);
    supcheckout_cert_assert($enveloped_anonymous['cache_control'] === '', 'documented: a logged-out enveloped error carries no Cache-Control on the wire (core sends the envelope without the response headers and no no-cache headers for a visitor); it holds only the generic refusal: ' . wp_json_encode($enveloped_anonymous['cache_control']));

    // An Application Password authenticates the administrator but carries no nonce: it can
    // never start a switch (owner decision; automation uses WP-CLI).
    $operation_before = supcheckout_takeover_rest_option_raw(Operation::OPTION);
    $bucket_before = supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . 'switch');
    $user = get_userdata($admin);
    $created = WP_Application_Passwords::create_new_application_password($admin, array('name' => 'supcheckout-rest-certification'));
    supcheckout_cert_assert(is_array($created) && $user instanceof WP_User, 'an Application Password is created for the administrator');
    list($app_password, $app_item) = $created;
    $basic = array('Authorization' => 'Basic ' . base64_encode($user->user_login . ':' . $app_password));
    $app_status = $call('GET', '/onboarding/status', array(), $basic);
    supcheckout_cert_assert($app_status['status'] === 200, 'the Application Password authenticates the administrator for a read: ' . $app_status['status'] . ' ' . $app_status['json']);
    $app_switch = $call('POST', '/onboarding/switch', array(), $basic, array('confirm' => true, 'deactivate_official' => true));
    supcheckout_cert_assert($app_switch['status'] === 403 && ($app_switch['data']['code'] ?? '') === 'supcheckout_nonce_invalid', 'an Application Password without the nonce, POST switch over HTTP → 403 supcheckout_nonce_invalid: ' . $app_switch['status'] . ' ' . $app_switch['json']);
    supcheckout_cert_assert(supcheckout_takeover_rest_uncacheable($app_switch['cache_control']), 'the 403 is no-store, private on the wire: ' . wp_json_encode($app_switch['cache_control']));
    supcheckout_cert_assert(supcheckout_takeover_rest_option_raw(Operation::OPTION) === $operation_before && supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . 'switch') === $bucket_before, 'the refused switch created no operation and spent no rate slot');
    WP_Application_Passwords::delete_application_password($admin, $app_item['uuid']);

    // A refused start gives its rate slot back (PR #194 review): an abort, which no store in
    // this state allows, is refused by its plan, and an immediate retry is refused the same
    // way, never rate limited.
    supcheckout_takeover_rest_open_bucket('abort');
    $abort = $call('POST', '/onboarding/abort', array(), $session, array('confirm' => true));
    supcheckout_cert_assert($abort['status'] === 409 && ($abort['data']['code'] ?? '') === 'supcheckout_state', 'POST abort over HTTP → 409 supcheckout_state (state ' . supcheckout_takeover_rest_state() . '): ' . $abort['status'] . ' ' . $abort['json']);
    supcheckout_cert_assert(supcheckout_takeover_rest_uncacheable($abort['cache_control']), 'the 409 is no-store, private on the wire: ' . wp_json_encode($abort['cache_control']));
    $abort_again = $call('POST', '/onboarding/abort', array(), $session, array('confirm' => true));
    supcheckout_cert_assert($abort_again['status'] === 409 && ($abort_again['data']['code'] ?? '') === 'supcheckout_state', 'an immediate second abort over HTTP is refused by its plan again (409 supcheckout_state), not rate limited: ' . $abort_again['status'] . ' ' . $abort_again['json']);
    supcheckout_cert_assert(supcheckout_takeover_rest_uncacheable($abort_again['cache_control']), 'the second 409 is no-store, private on the wire: ' . wp_json_encode($abort_again['cache_control']));

    // Retry-After on a 429: a start that STARTS an operation keeps its slot, so a second
    // preflight inside the 65 s provider_probe window is rate limited. The preflight never
    // runs here (DISABLE_WP_CRON, and REST never dispatches Action Scheduler's runner): its
    // operation record, action and bucket are put back afterwards, so the next phases find
    // this step's store unchanged.
    $operation_raw = supcheckout_takeover_rest_option_raw(Operation::OPTION);
    supcheckout_cert_assert(!Operation::is_running(Operation::current()), 'no operation is running before the HTTP preflight start: ' . wp_json_encode(Operation::current()));
    supcheckout_takeover_rest_open_bucket('provider_probe');
    $probe_raw = supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . 'provider_probe');
    $started = $call('POST', '/onboarding/preflight', array(), $session, array());
    $started_id = isset($started['data']['data']['operation']['id']) ? (string) $started['data']['data']['operation']['id'] : '';
    supcheckout_cert_assert($started['status'] === 202 && $started_id !== '' && ($started['data']['data']['operation']['kind'] ?? '') === Operation::KIND_PREFLIGHT, 'POST preflight over HTTP → 202 with a running preflight operation: ' . $started['status'] . ' ' . $started['json']);
    supcheckout_cert_assert(supcheckout_takeover_rest_uncacheable($started['cache_control']), 'the 202 is no-store, private on the wire: ' . wp_json_encode($started['cache_control']));
    $again = $call('POST', '/onboarding/preflight', array(), $session, array());
    $retry_after = isset($again['data']['data']['retry_after']) ? $again['data']['data']['retry_after'] : null;
    supcheckout_cert_assert($again['status'] === 429 && ($again['data']['code'] ?? '') === 'supcheckout_rate_limited' && is_int($retry_after) && $retry_after >= 1 && $retry_after <= RateLimiter::PROBE_WINDOW, 'a second preflight within 65 s over HTTP → 429 supcheckout_rate_limited with retry_after 1-65: ' . $again['status'] . ' ' . $again['json']);
    supcheckout_cert_assert($again['retry_after'] === (string) $retry_after, 'the 429 carries Retry-After: ' . $retry_after . ' on the wire: ' . wp_json_encode($again['retry_after']));
    supcheckout_cert_assert(supcheckout_takeover_rest_uncacheable($again['cache_control']), 'the 429 is no-store, private on the wire: ' . wp_json_encode($again['cache_control']));
    supcheckout_cert_assert(Operation::current()['id'] === $started_id, 'the rate-limited start started nothing: the HTTP preflight is still the current operation');

    // Put the store back: no action, the operation record and the bucket as they were.
    as_unschedule_all_actions(AsyncDriver::HOOK, array($started_id), AsyncDriver::GROUP);
    foreach (array(Operation::OPTION => $operation_raw, RateLimiter::OPTION_PREFIX . 'provider_probe' => $probe_raw) as $name => $raw) {
        delete_option($name);
        if ($raw !== null) {
            add_option($name, $raw, '', false);
        }
        wp_cache_delete($name, 'options');
    }
    supcheckout_cert_assert(supcheckout_takeover_rest_option_raw(Operation::OPTION) === $operation_raw && !as_has_scheduled_action(AsyncDriver::HOOK, array($started_id), AsyncDriver::GROUP), 'the HTTP preflight left no operation record change and no pending action behind');

    unset($_COOKIE[LOGGED_IN_COOKIE]);
    WP_Session_Tokens::get_instance($admin)->destroy($token);
    supcheckout_takeover_rest_assert_no_secret($http_bodies);
    supcheckout_cert_note('rest_http complete');
    return;
}

if ($phase === 'rest_paused') {
    supcheckout_cert_assert(supcheckout_takeover_rest_official_active(), 'the official plugin stand-in is active');
    supcheckout_cert_assert(!defined('SUPCHECKOUT_VERSION'), 'SUPCheckout is paused: Plugin::boot() did not run');
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::PREFLIGHT_OK, 'the takeover state is preflight_ok after the CLI cycles');
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Certification fixture: clear the availability rate gate earlier steps may have set (as TakeoverCliTest does).
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%payment\\_methods\\_rate\\_gate\\_%' OR option_name LIKE 'supcheckout\\_pmgate\\_v1\\_%'");

    // Routes and access.
    $routes = supcheckout_takeover_rest_routes();
    supcheckout_cert_assert($routes === SUPCHECKOUT_TAKEOVER_REST_PAUSED_ROUTES, 'paused, the supcheckout/v1 routes are exactly the 8 takeover routes: ' . wp_json_encode($routes));
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('GET', '/onboarding/status', null, 0), 401, 'rest_forbidden', 'logged out, GET status');
    $customer = get_user_by('login', SUPCHECKOUT_TAKEOVER_REST_CUSTOMER);
    $customer_id = $customer instanceof WP_User ? (int) $customer->ID : (int) wp_insert_user(array('user_login' => SUPCHECKOUT_TAKEOVER_REST_CUSTOMER, 'user_pass' => wp_generate_password(24), 'user_email' => SUPCHECKOUT_TAKEOVER_REST_CUSTOMER . '@example.test', 'role' => 'customer'));
    supcheckout_cert_assert($customer_id > 0 && !user_can($customer_id, 'manage_woocommerce'), 'a customer without manage_woocommerce exists');
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('GET', '/onboarding/status', null, $customer_id), 403, 'rest_forbidden', 'a customer, GET status');
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), $customer_id), 403, 'rest_forbidden', 'a customer with the nonce, POST switch');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($customer_id);

    $switch_bucket = supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . 'switch');
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), null, false), 403, 'supcheckout_nonce_invalid', 'an administrator without the nonce, POST switch');
    $unknown = supcheckout_takeover_rest_call('POST', '/onboarding/switch', array('confirm' => true, 'deactivateOfficial' => true));
    supcheckout_takeover_rest_expect($unknown, 400, 'supcheckout_unknown_parameter', 'POST switch {"confirm":true,"deactivateOfficial":true}');
    supcheckout_cert_assert(($unknown['data']['data']['params'] ?? null) === array('deactivateOfficial'), 'the misspelled consent flag is named: ' . $unknown['json']);
    // A Shop Manager has manage_woocommerce but not activate_plugins (0c security review).
    $manager_id = (int) wp_insert_user(array('user_login' => 'supcheckout-rest-shop-manager', 'user_pass' => wp_generate_password(24), 'user_email' => 'supcheckout-rest-shop-manager@example.test', 'role' => 'shop_manager'));
    supcheckout_cert_assert($manager_id > 0 && user_can($manager_id, 'manage_woocommerce') && !user_can($manager_id, 'activate_plugins'), 'a shop manager with manage_woocommerce and without activate_plugins exists');
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), $manager_id), 403, 'supcheckout_capability_plugins', 'a shop manager with the nonce, POST switch with deactivate_official');
    wp_delete_user($manager_id);
    supcheckout_cert_assert(Operation::current() === null && supcheckout_takeover_rest_option_raw(RateLimiter::OPTION_PREFIX . 'switch') === $switch_bucket, 'the refused requests created no operation and spent no rate slot');
    supcheckout_cert_assert(supcheckout_takeover_rest_official_active(), 'nothing was deactivated');

    $status = supcheckout_takeover_rest_status('paused');
    supcheckout_cert_assert($status['takeover']['status'] === TakeoverState::PREFLIGHT_OK && $status['operation'] === null && $status['runner']['stalled'] === false, 'status reports preflight_ok and no operation: ' . wp_json_encode($status));

    // Preflight.
    supcheckout_takeover_rest_open_bucket('provider_probe');
    $preflight_id = supcheckout_takeover_rest_start('/onboarding/preflight', array(), Operation::KIND_PREFLIGHT);
    supcheckout_takeover_rest_drive_done($preflight_id);
    $preflight = supcheckout_takeover_rest_call('GET', '/onboarding/preflight');
    supcheckout_takeover_rest_expect($preflight, 200, null, 'GET /onboarding/preflight');
    supcheckout_cert_assert(isset($preflight['data']['data']['report']['blocking']) && $preflight['data']['data']['report']['blocking'] === array(), 'the stored preflight report has nothing blocking (the takeover HTTP mu-plugin answers both probes): ' . $preflight['json']);

    // Switch.
    $orders = supcheckout_takeover_rest_orders();
    update_option(SUPCHECKOUT_TAKEOVER_REST_ORDERS, $orders, false);
    supcheckout_cert_note('snapshot of ' . count($orders) . ' orders taken before the switch');
    supcheckout_takeover_rest_open_bucket('switch');
    $switch_id = supcheckout_takeover_rest_start('/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), Operation::KIND_SWITCH);
    supcheckout_cert_assert(Operation::current()['consent']['deactivate_official'] === true, 'the operation carries the deactivation consent');
    // A second admin while the switch runs. Each start runs its cheap plan before the
    // single-flight check, so an abort, which this store's state never allows, is refused
    // by its plan (409 supcheckout_state); a rollback, whose plan allows resuming the
    // completed CLI rollback, reaches the single-flight check (409 in progress).
    supcheckout_takeover_rest_open_bucket('abort');
    $abort = supcheckout_takeover_rest_call('POST', '/onboarding/abort', array('confirm' => true));
    supcheckout_takeover_rest_expect($abort, 409, 'supcheckout_state', 'POST abort while the switch runs');
    supcheckout_takeover_rest_open_bucket('rollback');
    $in_progress = supcheckout_takeover_rest_call('POST', '/onboarding/rollback', array('confirm' => true));
    supcheckout_takeover_rest_expect($in_progress, 409, 'supcheckout_operation_in_progress', 'POST rollback while the switch runs');
    supcheckout_cert_assert(($in_progress['data']['data']['operation_id'] ?? null) === $switch_id, 'the 409 names the running switch: ' . $in_progress['json']);

    $op = supcheckout_takeover_rest_drive_done($switch_id);
    foreach (array('preflight_passed', 'copy_verified', 'official_deactivated', 'cut_over', 'caches_flushed', 'self_test_ran') as $step) {
        supcheckout_cert_assert(in_array($step, $op['steps'], true), 'the switch reached ' . $step . ': ' . wp_json_encode($op['steps']));
    }
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::CUT_OVER, 'TakeoverState is cut_over');
    supcheckout_cert_assert(!supcheckout_takeover_rest_official_active(), 'the official plugin stand-in is inactive');

    // Self-test results.
    $checks = supcheckout_takeover_rest_checks($op['self_test']);
    supcheckout_cert_note('switch self-test: ' . wp_json_encode(array_map(static function ($check) {
        return $check['result'] . ($check['code'] === null ? '' : ' ' . $check['code']);
    }, $checks)));
    supcheckout_cert_assert(supcheckout_takeover_rest_result($checks, 'credential') === 'pass', 'self-test credential: pass (one read-only probe, answered by the mu-plugin)');
    supcheckout_cert_assert(supcheckout_takeover_rest_result($checks, 'event_journal') === 'deferred', 'self-test event_journal: deferred');
    supcheckout_cert_assert(supcheckout_takeover_rest_result($checks, 'gateway_registered') === 'pass', 'self-test gateway_registered: pass (checked in a fresh, fully booted process)');
    supcheckout_cert_assert($op['self_test']['ok'] === true && ($op['report']['warnings'] ?? null) === array(), 'the self-test passed, so the switch has no warning: ' . wp_json_encode($op['report']['warnings'] ?? null));
    $status = supcheckout_takeover_rest_status('switched (paused process)');
    supcheckout_cert_assert($status['operation']['id'] === $switch_id && $status['operation']['outcome'] === Operation::DONE && $status['takeover']['status'] === TakeoverState::CUT_OVER, 'status reports the finished switch: ' . wp_json_encode($status['operation']));

    // Invariants.
    supcheckout_takeover_rest_assert_orders_unchanged('after the REST switch');
    supcheckout_takeover_rest_assert_no_secret();
    supcheckout_cert_note('rest_paused complete');
    return;
}

if ($phase === 'rest_switched') {
    supcheckout_cert_assert(defined('SUPCHECKOUT_VERSION'), 'a fresh process after the REST switch runs the full boot');
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::CUT_OVER && !supcheckout_takeover_rest_official_active(), 'the store is cut over and the stand-in inactive');
    $routes = supcheckout_takeover_rest_routes();
    $expected = array_merge(SUPCHECKOUT_TAKEOVER_REST_PAUSED_ROUTES, SUPCHECKOUT_TAKEOVER_REST_FULL_ONLY_ROUTES);
    sort($expected);
    supcheckout_cert_assert($routes === $expected, 'the full boot adds the self-test and setup-check routes (12 in all): ' . wp_json_encode($routes));

    supcheckout_takeover_rest_open_bucket('provider_probe');
    $self_test_id = supcheckout_takeover_rest_start('/onboarding/self-test', array(), Operation::KIND_SELF_TEST);
    $self_test = supcheckout_takeover_rest_drive_done($self_test_id);
    $checks = supcheckout_takeover_rest_checks($self_test['report']);
    supcheckout_cert_assert(supcheckout_takeover_rest_result($checks, 'credential') === 'pass' && supcheckout_takeover_rest_result($checks, 'takeover_state') === 'pass', 'the REST self-test passes its credential and takeover-state checks: ' . wp_json_encode($self_test['report']));
    $stored = supcheckout_takeover_rest_call('GET', '/onboarding/self-test');
    supcheckout_takeover_rest_expect($stored, 200, null, 'GET /onboarding/self-test');
    supcheckout_cert_assert(isset($stored['data']['data']['report']['checks']) && supcheckout_takeover_rest_checks($stored['data']['data']['report']) === $checks, 'GET /onboarding/self-test returns the stored report');

    // The self-test, the setup checks and preflight share the provider_probe bucket.
    $limited = supcheckout_takeover_rest_call('POST', '/setup/checks', array());
    supcheckout_takeover_rest_expect($limited, 429, 'supcheckout_rate_limited', 'POST setup/checks right after the self-test (shared provider_probe bucket)');
    $wait = $limited['data']['data']['retry_after'] ?? null;
    supcheckout_cert_assert(is_int($wait) && $wait >= 1 && $wait <= RateLimiter::PROBE_WINDOW && Operation::current()['id'] === $self_test_id, 'retry_after is within the 65 s probe window and nothing was started: ' . $limited['json']);
    supcheckout_takeover_rest_open_bucket('provider_probe');
    $checks_id = supcheckout_takeover_rest_start('/setup/checks', array(), Operation::KIND_SETUP_CHECKS);
    $setup = supcheckout_takeover_rest_drive_done($checks_id);
    $checks = supcheckout_takeover_rest_checks($setup['report']);
    supcheckout_cert_note('setup checks: ' . wp_json_encode(array_map(static function ($check) {
        return $check['result'] . ($check['code'] === null ? '' : ' ' . $check['code']);
    }, $checks)));
    supcheckout_cert_assert(supcheckout_takeover_rest_result($checks, 'test_payment') === 'deferred', 'setup checks: test_payment deferred');
    supcheckout_cert_assert(isset($checks['multilingual']), 'setup checks: multilingual present');
    $stored = supcheckout_takeover_rest_call('GET', '/setup/checks');
    supcheckout_cert_assert($stored['status'] === 200 && supcheckout_takeover_rest_checks($stored['data']['data']['report'] ?? null) === $checks, 'GET /setup/checks returns the stored report');

    // Finalize.
    supcheckout_takeover_rest_open_bucket('finalize');
    $dry_id = supcheckout_takeover_rest_start('/onboarding/finalize', array('dry_run' => true), Operation::KIND_FINALIZE_DRY_RUN);
    $dry = supcheckout_takeover_rest_drive_done($dry_id);
    supcheckout_cert_assert(isset($dry['report']['plan']['would_refuse']) && $dry['report']['plan']['would_refuse'] === array('too_early'), 'the finalize dry run reports would_refuse ["too_early"]: ' . wp_json_encode($dry['report']));
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::CUT_OVER, 'the dry run changed nothing');
    supcheckout_takeover_rest_open_bucket('finalize');
    $early = supcheckout_takeover_rest_call('POST', '/onboarding/finalize', array('confirm' => 'FINALIZE'));
    supcheckout_takeover_rest_expect($early, 409, 'supcheckout_state', 'POST finalize {"confirm":"FINALIZE"} before 30 days');
    supcheckout_cert_assert(($early['data']['data']['reason'] ?? null) === 'too_early' && ($early['data']['data']['days_left'] ?? null) === 30, 'the refusal says too_early with days_left 30: ' . $early['json']);
    supcheckout_cert_assert(Operation::current()['id'] === $dry_id, 'the refused finalize started nothing');

    supcheckout_takeover_rest_assert_orders_unchanged('after the self-test, the setup checks and the dry run');
    supcheckout_takeover_rest_assert_no_secret();
    supcheckout_cert_note('rest_switched complete');
    return;
}

if ($phase === 'rest_rollback') {
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::CUT_OVER && !supcheckout_takeover_rest_official_active(), 'the store is cut over and the stand-in inactive before the rollback');
    supcheckout_takeover_rest_open_bucket('rollback');
    $rollback_id = supcheckout_takeover_rest_start('/onboarding/rollback', array('confirm' => true, 'reactivate_official' => true), Operation::KIND_ROLLBACK);
    // A double click: the second start inside the 10 s window is rate limited.
    $double = supcheckout_takeover_rest_call('POST', '/onboarding/rollback', array('confirm' => true, 'reactivate_official' => true));
    supcheckout_takeover_rest_expect($double, 429, 'supcheckout_rate_limited', 'a second POST rollback within 10 s');
    supcheckout_cert_assert(Operation::current()['id'] === $rollback_id, 'exactly one rollback operation exists');

    // The settle barrier is a scheduled resume: a tick yields with next_at at the deadline.
    $yields = array();
    $observe = static function (array $op) use (&$yields) {
        $message = isset($op['report']['message']) ? (string) $op['report']['message'] : '';
        if ($op['phase'] === 'rewind' && strpos($message, 'yielded') !== false) {
            $yields[] = (int) $op['next_at'] - (int) $op['updated_at'];
        }
    };
    $op = supcheckout_takeover_rest_drive_done($rollback_id, $observe);
    $longest = $yields === array() ? 0 : max($yields);
    supcheckout_cert_assert($longest >= CopyRemoval::SETTLE_SECONDS - 5 && $longest <= CopyRemoval::SETTLE_SECONDS, 'the rollback yielded at the settle barrier and was resumed by an action scheduled ' . $longest . ' s later (yields seen: ' . wp_json_encode(array_values(array_unique($yields))) . ')');
    foreach (array('rolled_back', 'copies_removed', 'preflight_ok', 'official_reactivated') as $step) {
        supcheckout_cert_assert(in_array($step, $op['steps'], true), 'the rollback reached ' . $step . ': ' . wp_json_encode($op['steps']));
    }
    supcheckout_cert_note('rollback warnings: ' . wp_json_encode($op['report']['warnings'] ?? null));
    supcheckout_cert_assert(supcheckout_takeover_rest_official_active(), 'the official plugin stand-in is active again');
    TakeoverState::refresh();
    $state = TakeoverState::get();
    supcheckout_cert_assert($state['status'] === TakeoverState::PREFLIGHT_OK && !Rollback::removal_pending($state), 'the state is preflight_ok with no removal pending, as "Cut over again and finalize" requires');
    supcheckout_takeover_rest_assert_orders_unchanged('after the REST rollback');
    supcheckout_takeover_rest_assert_no_secret();
    delete_option(SUPCHECKOUT_TAKEOVER_REST_ORDERS);
    supcheckout_cert_note('rest_rollback complete');
    return;
}

if ($phase === 'rest_sweep') {
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::FINALIZED && !supcheckout_takeover_rest_official_active(), 'the store is finalized and the stand-in inactive');
    supcheckout_takeover_rest_open_bucket('finalize');
    $sweep_id = supcheckout_takeover_rest_start('/onboarding/finalize', array('confirm' => 'FINALIZE'), Operation::KIND_FINALIZE);
    $op = supcheckout_takeover_rest_drive_done($sweep_id);
    foreach (array('sweep', 'legacy_removed') as $step) {
        supcheckout_cert_assert(in_array($step, $op['steps'], true), 'the finalize sweep reached ' . $step . ': ' . wp_json_encode($op['steps']));
    }
    $expected_kept = (array) get_option('supcheckout_takeover_cert_finalize_kept', array());
    $warnings = isset($op['report']['warnings']) && is_array($op['report']['warnings']) ? $op['report']['warnings'] : array();
    supcheckout_cert_assert(in_array('kept', $warnings, true) === ($expected_kept !== array()), 'the sweep keeps legacy values exactly when the CLI finalize did (' . count($expected_kept) . ' group(s)): ' . wp_json_encode($warnings));
    supcheckout_cert_assert(supcheckout_takeover_rest_state() === TakeoverState::FINALIZED, 'the store stays finalized');
    supcheckout_takeover_rest_open_bucket('rollback');
    supcheckout_takeover_rest_expect(supcheckout_takeover_rest_call('POST', '/onboarding/rollback', array('confirm' => true)), 409, 'supcheckout_state', 'POST rollback on a finalized store');
    supcheckout_takeover_rest_assert_no_secret();
    supcheckout_cert_note('rest_sweep complete');
    return;
}

throw new RuntimeException('Set SUPCHECKOUT_CERT_PHASE to rest_http, rest_paused, rest_switched, rest_rollback or rest_sweep.');
