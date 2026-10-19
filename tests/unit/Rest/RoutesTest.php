<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use Simplixi\SUPCheckout\Rest\RequestGuard;
use Simplixi\SUPCheckout\Rest\Routes;

/**
 * 0c Task 7: the supcheckout/v1 route table, its registration and the namespace-scoped
 * response filters.
 */
final class RoutesTest extends RestTestCase {
    private const PAUSED = array(
        'GET /onboarding/status',
        'GET /onboarding/preflight',
        'POST /onboarding/preflight',
        'POST /onboarding/switch',
        'POST /onboarding/rollback',
        'POST /onboarding/abort',
        'POST /onboarding/finalize',
        'POST /onboarding/stale-claims/release',
    );

    /**
     * @param list<array<string, mixed>> $table
     * @return list<string>
     */
    private static function keys(array $table): array {
        return array_map(static function ($row) {
            return $row['method'] . ' ' . $row['route'];
        }, $table);
    }

    /** @return array<string, list<array<string, mixed>>> Registered endpoints by full route. */
    private static function registry(): array {
        return $GLOBALS['supcheckout_test_rest_registry'];
    }

    public function test_paused_registers_exactly_the_takeover_routes(): void {
        self::assertSame(self::PAUSED, self::keys(Routes::table(true)));
    }

    public function test_full_boot_adds_self_test_and_setup_checks(): void {
        self::assertSame(
            array_merge(self::PAUSED, array('GET /onboarding/self-test', 'POST /onboarding/self-test', 'GET /setup/checks', 'POST /setup/checks')),
            self::keys(Routes::table(false))
        );
    }

    public function test_writes_buckets_and_windows_follow_the_table(): void {
        $rows = array();
        foreach (Routes::table(false) as $row) {
            $rows[$row['method'] . ' ' . $row['route']] = array($row['write'], $row['bucket']);
        }
        self::assertSame(array(false, null), $rows['GET /onboarding/status']);
        self::assertSame(array(true, 'provider_probe'), $rows['POST /onboarding/preflight']);
        self::assertSame(array(true, 'switch'), $rows['POST /onboarding/switch']);
        self::assertSame(array(true, 'rollback'), $rows['POST /onboarding/rollback']);
        self::assertSame(array(true, 'abort'), $rows['POST /onboarding/abort']);
        self::assertSame(array(true, 'finalize'), $rows['POST /onboarding/finalize']);
        self::assertSame(array(true, 'stale_claims'), $rows['POST /onboarding/stale-claims/release']);
        self::assertSame(array(true, 'provider_probe'), $rows['POST /onboarding/self-test']);
        self::assertSame(array(true, 'provider_probe'), $rows['POST /setup/checks']);
        foreach (Routes::table(false) as $row) {
            self::assertSame($row['method'] === 'POST', $row['write'], $row['route']);
        }
    }

    public function test_consent_args_are_strict_booleans_defaulting_false_and_confirm_strings_have_no_default(): void {
        $args = array();
        foreach (Routes::table(false) as $row) {
            $args[$row['method'] . ' ' . $row['route']] = $row['args'];
        }
        self::assertSame(array('confirm', 'deactivate_official'), array_keys($args['POST /onboarding/switch']));
        self::assertSame(array('confirm', 'deactivate_official', 'reactivate_official'), array_keys($args['POST /onboarding/rollback']));
        self::assertSame(array('confirm', 'reactivate_official'), array_keys($args['POST /onboarding/abort']));
        self::assertSame(array('dry_run', 'force_after_review', 'confirm'), array_keys($args['POST /onboarding/finalize']));
        self::assertSame(array('confirm'), array_keys($args['POST /onboarding/stale-claims/release']));
        foreach ($args as $key => $route_args) {
            foreach ($route_args as $name => $arg) {
                if ($arg['type'] === 'boolean') {
                    self::assertFalse($arg['default'], $key . ' ' . $name);
                } else {
                    self::assertSame(array('type', 'enum', 'description'), array_keys($arg), $key . ' ' . $name . ': no default, not required');
                    self::assertSame(array('FINALIZE'), $arg['enum']);
                }
                self::assertArrayNotHasKey('required', $arg, 'consent is enforced by the controller');
            }
        }
        self::assertSame(array(), $args['GET /onboarding/status']);
        self::assertSame(array(), $args['POST /onboarding/preflight']);
    }

    public function test_register_hooks_rest_api_init_only(): void {
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_rest();

        Routes::register();

        self::assertSame(array(array('rest_api_init', array(Routes::class, 'register_all'), 10, 1)), $GLOBALS['supcheckout_test_action_calls']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_filter_calls'], 'filters are added at rest_api_init');
        self::assertSame(array(), $GLOBALS['supcheckout_test_rest_routes'], 'routes are registered at rest_api_init');
    }

    public function test_registration_adds_the_namespace_guard_and_no_store_filters(): void {
        $filters = array();
        foreach ($GLOBALS['supcheckout_test_filter_calls'] as $call) {
            $filters[] = array($call[0], $call[1], $call[2], $call[3]);
        }
        self::assertContains(array('rest_post_dispatch', array(Routes::class, 'no_store'), 10, 3), $filters);
        self::assertContains(array('rest_request_before_callbacks', array(Routes::class, 'guard'), 10, 3), $filters);
    }

    public function test_every_endpoint_is_registered_with_the_capability_check_and_its_table_args(): void {
        $table = Routes::table(false);
        $count = 0;
        foreach ($table as $row) {
            $endpoints = self::registry()['/supcheckout/v1' . $row['route']];
            foreach ($endpoints as $endpoint) {
                if ($endpoint['methods'] !== $row['method']) {
                    continue;
                }
                ++$count;
                self::assertSame(array(RequestGuard::class, 'can_manage'), $endpoint['permission_callback'], $row['route']);
                self::assertSame($row['args'], $endpoint['args'], $row['route']);
                self::assertIsCallable($endpoint['callback']);
            }
        }
        self::assertSame(count($table), $count, 'one endpoint per table row');
    }

    public function test_paused_registration_has_no_self_test_or_setup_routes(): void {
        \supcheckout_test_reset_rest();

        Routes::register_routes(true, function () {
            return $this->onboarding();
        });

        self::assertSame(
            array('/supcheckout/v1/onboarding/status', '/supcheckout/v1/onboarding/preflight', '/supcheckout/v1/onboarding/switch', '/supcheckout/v1/onboarding/rollback', '/supcheckout/v1/onboarding/abort', '/supcheckout/v1/onboarding/finalize', '/supcheckout/v1/onboarding/stale-claims/release'),
            array_keys(self::registry())
        );
    }

    public function test_every_route_requires_manage_woocommerce(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        foreach (self::registry() as $route => $endpoints) {
            foreach ($endpoints as $endpoint) {
                $GLOBALS['supcheckout_test_status_logged_in'] = true;
                $refused = call_user_func($endpoint['permission_callback'], new \WP_REST_Request($endpoint['methods'], $route));
                self::assertInstanceOf(\WP_Error::class, $refused, $route);
                self::assertSame(403, $refused->get_error_data()['status'], $route . ' logged in');

                $GLOBALS['supcheckout_test_status_logged_in'] = false;
                $refused = call_user_func($endpoint['permission_callback'], new \WP_REST_Request($endpoint['methods'], $route));
                self::assertSame(401, $refused->get_error_data()['status'], $route . ' logged out');
            }
        }
        self::assertSame(array('manage_woocommerce'), array_values(array_unique($GLOBALS['supcheckout_test_migration_admin']['capability_calls'])));
    }

    public function test_every_route_refuses_a_non_admin_over_http_before_anything_runs(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        foreach (Routes::table(false) as $row) {
            $response = self::serve($row['method'], $row['route'], array('confirm' => true));
            self::assertSame(403, $response->get_status(), $row['route']);
            self::assertSame('no-store, private', $response->get_headers()['Cache-Control'], $row['route']);
        }
        self::assertNull(\Simplixi\SUPCheckout\Takeover\Onboarding\Operation::current());
        self::assertSame(array(), $GLOBALS['supcheckout_as_calls']['scheduled']);
    }

    public function test_no_store_is_scoped_to_the_supcheckout_namespace(): void {
        $foreign = new \WP_REST_Response(array('id' => 1), 200, array('Cache-Control' => 'max-age=60'));

        $result = Routes::no_store($foreign, new \WP_REST_Server(), new \WP_REST_Request('GET', '/wp/v2/posts'));
        $lookalike = Routes::no_store(new \WP_REST_Response(array(), 200), new \WP_REST_Server(), new \WP_REST_Request('GET', '/supcheckout/v10/x'));

        self::assertSame(array('Cache-Control' => 'max-age=60'), $result->get_headers());
        self::assertSame(array(), $lookalike->get_headers());
    }

    public function test_no_store_sets_the_header_on_our_routes_and_retry_after_on_a_429(): void {
        $ours = Routes::no_store(new \WP_REST_Response(array('code' => 'supcheckout_rate_limited', 'data' => array('status' => 429, 'retry_after' => 7)), 429), new \WP_REST_Server(), new \WP_REST_Request('POST', '/supcheckout/v1/onboarding/switch'));

        self::assertSame(array('Cache-Control' => 'no-store, private', 'Retry-After' => '7'), $ours->get_headers());
    }

    public function test_guard_ignores_other_namespaces(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;

        self::assertNull(Routes::guard(null, array(), new \WP_REST_Request('POST', '/wp/v2/posts')));
    }
}
