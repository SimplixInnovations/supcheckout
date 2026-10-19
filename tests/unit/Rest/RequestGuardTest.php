<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use Simplixi\SUPCheckout\Rest\RequestGuard;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\RateLimiter;

/**
 * 0c Task 7: capability, nonce, unknown parameters, consent and rate, in that order. A
 * rejected request never spends a rate slot or creates an operation.
 */
final class RequestGuardTest extends RestTestCase {
    private const SWITCH_ARGS = array('confirm', 'deactivate_official');

    public function test_can_manage_requires_manage_woocommerce(): void {
        self::assertTrue(RequestGuard::can_manage());

        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        $refused = RequestGuard::can_manage();

        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('rest_forbidden', $refused->get_error_code());
        self::assertSame(403, $refused->get_error_data()['status']);
        self::assertSame('manage_woocommerce', end($GLOBALS['supcheckout_test_migration_admin']['capability_calls']));
    }

    public function test_write_without_a_valid_nonce_is_403_nonce_invalid(): void {
        $missing = self::request('POST', '/onboarding/switch', array('confirm' => true), false);
        $wrong = self::request('POST', '/onboarding/switch', array('confirm' => true), false);
        $wrong->set_header('X-WP-Nonce', 'forged');
        $other_action = self::request('POST', '/onboarding/switch', array('confirm' => true), false);
        $GLOBALS['supcheckout_test_valid_nonces']['other-action-nonce'] = 'some_other_action';
        $other_action->set_header('X-WP-Nonce', 'other-action-nonce');

        foreach (array($missing, $wrong, $other_action) as $request) {
            $refused = RequestGuard::check_write($request, self::SWITCH_ARGS);
            self::assertInstanceOf(\WP_Error::class, $refused);
            self::assertSame('supcheckout_nonce_invalid', $refused->get_error_code());
            self::assertSame(403, $refused->get_error_data()['status']);
        }
        self::assertTrue(RequestGuard::check_write(self::request('POST', '/onboarding/switch', array('confirm' => true)), self::SWITCH_ARGS));
        self::assertContains(array(self::NONCE, 'wp_rest'), $GLOBALS['supcheckout_test_nonce_checks']);
    }

    public function test_an_application_password_alone_cannot_start_a_switch(): void {
        // Authenticated with manage_woocommerce but no cookie nonce: refused, nothing started.
        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivate_official' => true), false);

        self::assertSame(array(403, 'supcheckout_nonce_invalid'), array($response->get_status(), self::error_code($response)));
        self::assertNull(Operation::current());
        self::assertSame(0, RateLimiter::hit('switch', RateLimiter::WRITE_WINDOW, time()), 'no rate slot was spent');
    }

    public function test_capability_is_checked_before_the_nonce(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true), false);

        self::assertSame(array(403, 'rest_forbidden'), array($response->get_status(), self::error_code($response)));
        self::assertSame(array(), $GLOBALS['supcheckout_test_nonce_checks'], 'the nonce is not even read');
    }

    public function test_capability_is_checked_before_core_schema_validation(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        $GLOBALS['supcheckout_test_status_logged_in'] = false;

        $response = self::serve('POST', '/onboarding/switch', array('confirm' => 'not-a-boolean'), false);

        self::assertSame(array(401, 'rest_forbidden'), array($response->get_status(), self::error_code($response)), 'a logged-out caller learns nothing about the arguments');
    }

    public function test_the_nonce_and_unknown_parameters_are_checked_before_core_schema_validation(): void {
        $no_nonce = self::serve('POST', '/onboarding/switch', array('confirm' => 'not-a-boolean'), false);
        $unknown = self::serve('POST', '/onboarding/switch', array('confirm' => 'not-a-boolean', 'deactivateOfficial' => true));
        $invalid = self::serve('POST', '/onboarding/switch', array('confirm' => 'not-a-boolean'));

        self::assertSame('supcheckout_nonce_invalid', self::error_code($no_nonce));
        self::assertSame('supcheckout_unknown_parameter', self::error_code($unknown));
        self::assertSame(array(400, 'rest_invalid_param'), array($invalid->get_status(), self::error_code($invalid)));
    }

    public function test_unknown_parameter_is_rejected_400(): void {
        // Review Focus 5: a misspelled consent flag is never silently ignored.
        $response = self::serve('POST', '/onboarding/switch', array('confirm' => true, 'deactivateOfficial' => true));

        self::assertSame(array(400, 'supcheckout_unknown_parameter'), array($response->get_status(), self::error_code($response)));
        self::assertSame(array('deactivateOfficial'), self::body($response)['data']['params']);
        self::assertNull(Operation::current());
        self::assertSame(0, RateLimiter::hit('switch', RateLimiter::WRITE_WINDOW, time()), 'no rate slot was spent');
    }

    public function test_unknown_parameters_are_collected_from_json_body_and_query(): void {
        $request = self::request('POST', '/onboarding/switch', array('confirm' => true, 'json_extra' => 1), true, array('query_extra' => '1', '_wpnonce' => 'x', '_locale' => 'user', 'rest_route' => '/x', '_method' => 'POST', '_envelope' => '1', '_fields' => 'a', '_embed' => '1'));
        $request->set_body_params(array('body_extra' => 'x', 'deactivate_official' => 'false'));

        $refused = RequestGuard::check_write($request, self::SWITCH_ARGS);

        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame(array('json_extra', 'body_extra', 'query_extra'), $refused->get_error_data()['params'], 'reserved WordPress keys are allowed');
        self::assertSame(400, $refused->get_error_data()['status']);
    }

    public function test_a_json_list_body_is_an_unknown_parameter(): void {
        $refused = RequestGuard::check_write(self::request('POST', '/onboarding/switch', array(true)), self::SWITCH_ARGS);

        self::assertSame(array('0'), $refused->get_error_data()['params']);
    }

    public function test_reported_unknown_parameter_names_are_bounded(): void {
        $json = array('confirm' => true);
        for ($i = 0; $i < 1000; $i++) {
            $json[str_repeat('k', 300) . $i] = true;
        }

        $refused = RequestGuard::check_write(self::request('POST', '/onboarding/switch', $json), self::SWITCH_ARGS);

        $params = $refused->get_error_data()['params'];
        self::assertCount(21, $params, '20 names and a truncation marker');
        self::assertSame(array('truncated' => 980), $params[20]);
        self::assertSame(100, strlen($params[0]));
    }

    public function test_switch_consent_is_strictly_true(): void {
        foreach (array(null, false, 'true', 1, '1') as $value) {
            $request = self::request('POST', '/onboarding/switch', $value === null ? array() : array('confirm' => $value));
            $refused = RequestGuard::check_consent($request, '/onboarding/switch');
            self::assertInstanceOf(\WP_Error::class, $refused, var_export($value, true));
            self::assertSame('supcheckout_confirmation_required', $refused->get_error_code());
            self::assertSame(400, $refused->get_error_data()['status']);
        }
        self::assertTrue(RequestGuard::check_consent(self::request('POST', '/onboarding/switch', array('confirm' => true)), '/onboarding/switch'));
    }

    public function test_every_confirming_route_requires_confirm_true(): void {
        foreach (array('/onboarding/switch', '/onboarding/rollback', '/onboarding/abort', '/onboarding/stale-claims/release') as $route) {
            self::assertInstanceOf(\WP_Error::class, RequestGuard::check_consent(self::request('POST', $route, array()), $route), $route);
            self::assertTrue(RequestGuard::check_consent(self::request('POST', $route, array('confirm' => true)), $route), $route);
        }
        foreach (array('/onboarding/preflight', '/onboarding/self-test', '/setup/checks') as $route) {
            self::assertTrue(RequestGuard::check_consent(self::request('POST', $route, array()), $route), $route);
        }
    }

    public function test_finalize_consent_is_the_typed_word_unless_dry_run(): void {
        $cases = array(
            array(array('dry_run' => true), true),
            array(array('dry_run' => true, 'confirm' => 'FINALIZE'), true),
            array(array('confirm' => 'FINALIZE'), true),
            array(array('confirm' => 'FINALIZE', 'force_after_review' => true), true),
            array(array(), false),
            array(array('force_after_review' => true), false),
            array(array('confirm' => 'finalize'), false),
            array(array('confirm' => true), false),
            array(array('dry_run' => 'true'), false),
            array(array('dry_run' => false), false),
        );
        foreach ($cases as $case) {
            $result = RequestGuard::check_consent(self::request('POST', '/onboarding/finalize', $case[0]), '/onboarding/finalize');
            self::assertSame($case[1], $result === true, (string) json_encode($case[0]));
        }
    }

    public function test_rate_returns_429_with_retry_after(): void {
        self::assertTrue(RequestGuard::check_rate('switch', RateLimiter::WRITE_WINDOW));

        $refused = RequestGuard::check_rate('switch', RateLimiter::WRITE_WINDOW);

        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('supcheckout_rate_limited', $refused->get_error_code());
        self::assertSame(429, $refused->get_error_data()['status']);
        self::assertGreaterThanOrEqual(9, $refused->get_error_data()['retry_after']);
        self::assertLessThanOrEqual(10, $refused->get_error_data()['retry_after']);
    }

    public function test_rejected_requests_never_spend_a_rate_slot(): void {
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = false;
        self::serve('POST', '/onboarding/switch', array('confirm' => true));
        $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] = true;
        self::serve('POST', '/onboarding/switch', array('confirm' => true), false);
        self::serve('POST', '/onboarding/switch', array('confirm' => true, 'x' => 1));
        self::serve('POST', '/onboarding/switch', array('confirm' => 'nope'));
        self::serve('POST', '/onboarding/switch', array('confirm' => false));

        self::assertNull(Operation::current());
        self::assertSame(0, RateLimiter::hit('switch', RateLimiter::WRITE_WINDOW, time()));
    }
}
