<?php

namespace Simplixi\SUPCheckout\Rest;

use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\Onboarding\Operation;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

defined('ABSPATH') || exit;

/**
 * The self-test (spec §5.4 step 6) and new-merchant setup-check routes of supcheckout/v1
 * (Sub-project 0c Task 7). Full boot only: while the official plugin is active (paused
 * boot) neither is registered. Both run as an operation through OnboardingController's
 * start pipeline and share the `provider_probe` rate bucket with preflight, so they never
 * make more availability probes than the provider's rate gate allows.
 */
final class SetupController {
    /** Takeover statuses the self-test runs in: a switched store. */
    private const SWITCHED = array(TakeoverState::CUT_OVER, TakeoverState::FINALIZED);

    /** @var OnboardingController */
    private $onboarding;

    /** @param OnboardingController $onboarding The start pipeline. */
    public function __construct(OnboardingController $onboarding) {
        $this->onboarding = $onboarding;
    }

    /**
     * GET /onboarding/self-test.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_self_test(\WP_REST_Request $request) {
        unset($request);
        return OnboardingController::report_response(Operation::KIND_SELF_TEST);
    }

    /**
     * POST /onboarding/self-test: 409 supcheckout_state unless the store is switched.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_self_test(\WP_REST_Request $request) {
        return $this->onboarding->start($request, '/onboarding/self-test', 'provider_probe', Operation::KIND_SELF_TEST, array(), static function () {
            $status = AsyncDriver::takeover_status();
            if (in_array($status, self::SWITCHED, true)) {
                return Outcome::ok('self-test allowed', array('status' => $status));
            }
            return Outcome::fail(Outcome::STATE, 'state', 'state "' . $status . '" does not allow the self-test (it needs a switched store)', array('status' => $status));
        });
    }

    /**
     * GET /setup/checks.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_setup_checks(\WP_REST_Request $request) {
        unset($request);
        return OnboardingController::report_response(Operation::KIND_SETUP_CHECKS);
    }

    /**
     * POST /setup/checks.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function start_setup_checks(\WP_REST_Request $request) {
        return $this->onboarding->start($request, '/setup/checks', 'provider_probe', Operation::KIND_SETUP_CHECKS, array());
    }
}
