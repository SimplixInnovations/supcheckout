<?php

namespace Simplixi\SUPCheckout\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;

defined('ABSPATH') || exit;

/**
 * The admin-only `supcheckout/v1` REST API (Sub-project 0c Task 7, spec §3.3).
 *
 * Every route needs `manage_woocommerce`; every write also needs the explicit `wp_rest`
 * nonce, takes JSON-schema args only (unknown keys are refused; consent flags are booleans
 * defaulting to false), is rate limited, and only starts one single-flight Operation that
 * Action Scheduler runs: no route marks, unmarks or re-verifies a payment, and none runs
 * long work inline (only stale-claims/release, at most 500 rows under the lease).
 *
 * - register() hooks `rest_api_init` → register_all() (full boot). The paused boot (the
 *   official plugin is active) hooks register_takeover() itself (0c Task 8), which leaves
 *   out the self-test and setup-check routes and SetupController.
 * - Both also add two filters scoped to this namespace: guard() at
 *   `rest_request_before_callbacks` (capability, nonce and unknown parameters before core
 *   reports a schema error) and no_store() at `rest_post_dispatch` (`Cache-Control:
 *   no-store, private` on every response, errors included, and `Retry-After` on a 429).
 *
 * `confirm` is a boolean defaulting to false that the controller requires to be strictly
 * true, so a missing or false confirm is 400 supcheckout_confirmation_required. Finalize's
 * `confirm` is the string enum ["FINALIZE"] with no default and not required: WordPress
 * validates a default against the schema, which would make `{"dry_run": true}` fail.
 */
final class Routes {
    public const NAMESPACE = 'supcheckout/v1';

    /** Route definitions: method, route, available while paused, bucket, controller, handler. */
    private const ROUTES = array(
        array('GET', '/onboarding/status', true, null, 'onboarding', 'status'),
        array('GET', '/onboarding/preflight', true, null, 'onboarding', 'get_preflight'),
        array('POST', '/onboarding/preflight', true, 'provider_probe', 'onboarding', 'start_preflight'),
        array('POST', '/onboarding/switch', true, 'switch', 'onboarding', 'start_switch'),
        array('POST', '/onboarding/rollback', true, 'rollback', 'onboarding', 'start_rollback'),
        array('POST', '/onboarding/abort', true, 'abort', 'onboarding', 'start_abort'),
        array('POST', '/onboarding/finalize', true, 'finalize', 'onboarding', 'start_finalize'),
        array('POST', '/onboarding/stale-claims/release', true, 'stale_claims', 'onboarding', 'release_stale_claims'),
        array('GET', '/onboarding/self-test', false, null, 'setup', 'get_self_test'),
        array('POST', '/onboarding/self-test', false, 'provider_probe', 'setup', 'start_self_test'),
        array('GET', '/setup/checks', false, null, 'setup', 'get_setup_checks'),
        array('POST', '/setup/checks', false, 'provider_probe', 'setup', 'start_setup_checks'),
    );

    /** Seconds each bucket stays closed after an allowed write. */
    private const WINDOWS = array(
        'provider_probe' => RateLimiter::PROBE_WINDOW,
        'switch' => RateLimiter::WRITE_WINDOW,
        'rollback' => RateLimiter::WRITE_WINDOW,
        'abort' => RateLimiter::WRITE_WINDOW,
        'finalize' => RateLimiter::WRITE_WINDOW,
        'stale_claims' => RateLimiter::WRITE_WINDOW,
    );

    /** @return void */
    public static function register() {
        add_action('rest_api_init', array(self::class, 'register_all'));
    }

    /** @return void The full boot's `rest_api_init` callback. */
    public static function register_all() {
        self::register_routes(false);
    }

    /** @return void The paused boot's `rest_api_init` callback (0c Task 8). */
    public static function register_takeover() {
        self::register_routes(true);
    }

    /**
     * @param bool          $paused     Only the takeover routes.
     * @param callable|null $onboarding function (): Onboarding; Onboarding::from_wordpress() by default.
     * @return void
     */
    public static function register_routes($paused, $onboarding = null) {
        add_filter('rest_request_before_callbacks', array(self::class, 'guard'), 10, 3);
        add_filter('rest_post_dispatch', array(self::class, 'no_store'), 10, 3);

        $controllers = array('onboarding' => new OnboardingController($onboarding));
        if (!$paused) {
            $controllers['setup'] = new SetupController($controllers['onboarding']);
        }
        $endpoints = array();
        foreach (self::ROUTES as $row) {
            if ($paused && !$row[2]) {
                continue;
            }
            $endpoints[$row[1]][] = array(
                'methods' => $row[0],
                'callback' => array($controllers[$row[4]], $row[5]),
                'permission_callback' => array(RequestGuard::class, 'can_manage'),
                'args' => self::args($row[1], $row[0]),
            );
        }
        foreach ($endpoints as $route => $list) {
            register_rest_route(self::NAMESPACE, $route, $list);
        }
    }

    /**
     * @param bool $paused Only the takeover routes.
     * @return list<array{method:string, route:string, write:bool, bucket:?string, args:array<string, array<string, mixed>>}>
     */
    public static function table($paused) {
        $table = array();
        foreach (self::ROUTES as $row) {
            if ($paused && !$row[2]) {
                continue;
            }
            $table[] = array('method' => $row[0], 'route' => $row[1], 'write' => $row[0] !== 'GET', 'bucket' => $row[3], 'args' => self::args($row[1], $row[0]));
        }
        return $table;
    }

    /**
     * @param string $bucket Bucket.
     * @return int Its window in seconds.
     */
    public static function window($bucket) {
        return isset(self::WINDOWS[$bucket]) ? self::WINDOWS[$bucket] : RateLimiter::PROBE_WINDOW;
    }

    /**
     * @param string $route  Route relative to the namespace.
     * @param string $method HTTP method.
     * @return array<string, array<string, mixed>> The route's JSON-schema args.
     */
    public static function args($route, $method = 'POST') {
        if ($method === 'GET') {
            return array();
        }
        $confirm = self::flag('Required: true to start this step.');
        $deactivate = self::flag('Consent to deactivate the official UPayments plugin on this site (never network-wide).');
        $reactivate = self::flag('Consent to reactivate the official plugin the takeover deactivated.');
        switch ($route) {
            case '/onboarding/switch':
                return array('confirm' => $confirm, 'deactivate_official' => $deactivate);
            case '/onboarding/rollback':
                return array('confirm' => $confirm, 'deactivate_official' => $deactivate, 'reactivate_official' => $reactivate);
            case '/onboarding/abort':
                return array('confirm' => $confirm, 'reactivate_official' => $reactivate);
            case '/onboarding/finalize':
                return array(
                    'dry_run' => self::flag('Only plan the removal; changes nothing.'),
                    'force_after_review' => self::flag('Consent to finalize before 30 days, after reviewing the dry run.'),
                    'confirm' => array(
                        'type' => 'string',
                        'enum' => array(RequestGuard::FINALIZE_WORD),
                        'description' => 'Required unless dry_run is true: the word FINALIZE (finalize is irreversible).',
                    ),
                );
            case '/onboarding/stale-claims/release':
                return array('confirm' => $confirm);
        }
        return array();
    }

    /**
     * `rest_request_before_callbacks`: steps 1-3 of RequestGuard for this namespace, before
     * core's schema error ($response) or the permission callback, so a caller without the
     * capability, or a write without the nonce, learns nothing about the arguments.
     *
     * Filter arguments are typed loosely: another plugin may pass anything.
     *
     * @param mixed $response Null, or core's WP_Error.
     * @param mixed $handler  The matched endpoint.
     * @param mixed $request  WP_REST_Request.
     * @return mixed $response, or the guard's WP_Error.
     */
    public static function guard($response, $handler, $request) {
        if (!$request instanceof \WP_REST_Request || !self::owns($request->get_route())) {
            return $response;
        }
        $allowed = RequestGuard::can_manage();
        if ($allowed !== true) {
            return $allowed;
        }
        if (self::is_write($request)) {
            $args = is_array($handler) && isset($handler['args']) && is_array($handler['args']) ? array_keys($handler['args']) : array();
            $checked = RequestGuard::check_write($request, array_map('strval', $args));
            if ($checked !== true) {
                return $checked;
            }
        }
        return $response;
    }

    /**
     * `rest_post_dispatch`: every response of this namespace, errors included, is
     * `no-store, private`; a 429 also carries `Retry-After`. Other namespaces are untouched.
     *
     * @param mixed $response WP_REST_Response (typed loosely: another plugin may pass anything).
     * @param mixed $server   WP_REST_Server.
     * @param mixed $request  WP_REST_Request.
     * @return mixed The response, with the headers on this namespace's.
     */
    public static function no_store($response, $server, $request) {
        unset($server);
        if (!$response instanceof \WP_REST_Response || !$request instanceof \WP_REST_Request || !self::owns($request->get_route())) {
            return $response;
        }
        $response->header('Cache-Control', Responses::NO_STORE);
        $data = $response->get_data();
        if ($response->get_status() === 429 && is_array($data) && isset($data['data']['retry_after']) && is_int($data['data']['retry_after'])) {
            $response->header('Retry-After', (string) $data['data']['retry_after']);
        }
        return $response;
    }

    /**
     * @param \WP_REST_Request $request Request.
     * @return bool Anything but a read is a write (fail closed).
     */
    public static function is_write(\WP_REST_Request $request) {
        return !in_array(strtoupper((string) $request->get_method()), array('GET', 'HEAD'), true);
    }

    /**
     * @param mixed $route Route.
     * @return bool The namespace index or one of its routes.
     */
    private static function owns($route) {
        $base = '/' . self::NAMESPACE;
        return is_string($route) && ($route === $base || strpos($route, $base . '/') === 0);
    }

    /**
     * @param string $description Description.
     * @return array{type:string, default:bool, description:string}
     */
    private static function flag($description) {
        return array('type' => 'boolean', 'default' => false, 'description' => $description);
    }

    private function __construct() {}
}
