<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Rest\Routes;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\FakeBudgetedSystem;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCopyStore;
use Simplixi\SUPCheckout\Tests\Takeover\FakeStaleClaimStore;

/**
 * 0c Task 7 fixtures: the supcheckout/v1 routes registered against the REST stubs, with the
 * real Onboarding, Operation, RateLimiter and AsyncDriver on the in-memory option store and
 * fake engines. An admin (manage_woocommerce) with a valid `wp_rest` nonce by default.
 */
abstract class RestTestCase extends TestCase {
    protected const NONCE = 'nonce-0c-task-7';

    /** @var FakeCopyStore */
    protected $store;
    /** @var FakeBudgetedSystem */
    protected $system;
    /** @var FakeStaleClaimStore */
    protected $claims;
    /** @var array<string, mixed> */
    protected $preflight = array('blocking' => array(), 'warnings' => array(), 'facts' => array());

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/support/wordpress-rest-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        require_once dirname(__DIR__, 2) . '/support/onboarding-driver-stubs.php';
    }

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_rest();
        Journal::flush_cache();
        TakeoverState::flush_cache();
        $GLOBALS['supcheckout_as_calls'] = array('scheduled' => array(), 'unscheduled' => array(), 'unscheduled_all' => array(), 'did_action' => array());
        $GLOBALS['supcheckout_test_unique_hook_group_only'] = true;
        \ActionScheduler::$initialized = true;
        $GLOBALS['supcheckout_test_subscription_presentation_active'] = false;
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = true;
        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array();
        $GLOBALS['supcheckout_test_migration_admin']['capability_calls'] = array();
        $GLOBALS['supcheckout_test_status_logged_in'] = true;
        $GLOBALS['supcheckout_test_status_user_id'] = 7;
        $GLOBALS['supcheckout_test_valid_nonces'] = array(self::NONCE => 'wp_rest');
        $GLOBALS['supcheckout_test_nonce_checks'] = array();
        $this->store = new FakeCopyStore();
        $this->system = new FakeBudgetedSystem();
        $this->system->now = time();
        $this->system->official = null;
        $this->claims = new FakeStaleClaimStore();
        Routes::register_routes(false, function () {
            return $this->onboarding();
        });
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        \supcheckout_test_reset_subscription_composition();
        \supcheckout_test_reset_rest();
        TakeoverState::flush_cache();
        Journal::flush_cache();
        unset($GLOBALS['supcheckout_test_valid_nonces'], $GLOBALS['supcheckout_test_nonce_checks'], $GLOBALS['supcheckout_test_as_schedule_throws']);
        $GLOBALS['supcheckout_test_unique_hook_group_only'] = false;
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = true;
        $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] = array();
        \ActionScheduler::$initialized = true;
    }

    protected function onboarding(): Onboarding {
        return new Onboarding(function () {
            return $this->preflight;
        }, $this->store, $this->system, $this->claims);
    }

    /**
     * @param array<array-key, mixed>|null $json  JSON body.
     * @param array<string, mixed>         $query Query parameters.
     */
    protected static function request(string $method, string $path, ?array $json = null, bool $nonce = true, array $query = array()): \WP_REST_Request {
        $request = new \WP_REST_Request($method, '/' . Routes::NAMESPACE . $path);
        if ($json !== null) {
            $request->set_json_params($json);
        }
        $request->set_query_params($query);
        if ($nonce) {
            $request->set_header('X-WP-Nonce', self::NONCE);
        }
        return $request;
    }

    /** As WP_REST_Server::serve_request() answers over HTTP (rest_post_dispatch applied). */
    protected static function serve(string $method, string $path, ?array $json = null, bool $nonce = true, array $query = array()): \WP_REST_Response {
        return \supcheckout_test_rest_serve(self::request($method, $path, $json, $nonce, $query));
    }

    /** @return array<string, mixed> */
    protected static function body(\WP_REST_Response $response): array {
        $data = $response->get_data();
        self::assertIsArray($data);
        return $data;
    }

    protected static function error_code(\WP_REST_Response $response): string {
        $body = self::body($response);
        return isset($body['code']) ? (string) $body['code'] : '';
    }

    /** @return list<array<string, mixed>> */
    protected static function scheduled(string $hook): array {
        return array_values(array_filter($GLOBALS['supcheckout_as_calls']['scheduled'], static function ($row) use ($hook) {
            return $row['hook'] === $hook;
        }));
    }

    protected static function state(string $status, int $cut_over_at = 0): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = (string) json_encode(array('status' => $status, 'cut_over_at' => $cut_over_at, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
    }
}
