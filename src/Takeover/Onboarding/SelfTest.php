<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Preflight;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

defined('ABSPATH') || exit;

/**
 * The post-switch self-test (spec section 5.4 step 6, Sub-project 0c Task 5).
 *
 * It reuses Preflight's probes and mutates nothing. The credential check makes exactly one
 * read-only PaymentMethodAvailability::probe() through Environment, in test and live mode
 * alike (owner decision, 2026-10-18): it never charges, never retries or sends a second
 * request, and respects the provider rate gate, so a cooldown is a warning (code
 * `cooldown`) and not a failure. A provider outage is reported as provider_unavailable,
 * like Preflight. The callback check is Preflight's loopback probe, at most two GETs.
 *
 * The gateway state and the takeover status come from the caller (Task 6 binds them to
 * WooCommerce's gateway registry and TakeoverState), so this class has no WooCommerce
 * dependency and a binding that throws fails the check closed without leaking its message.
 * Checks are listed in Checks's shape; the report carries no secret and no customer data.
 */
final class SelfTest {
    /** @var Environment */
    private $env;
    /** @var callable */
    private $gateway_state;
    /** @var callable */
    private $takeover_status;

    /**
     * @param Environment $env             Everything the checks read.
     * @param callable    $gateway_state   (): array{registered: bool, enabled: bool}
     * @param callable    $takeover_status (): string The TakeoverState status.
     */
    public function __construct(Environment $env, $gateway_state, $takeover_status) {
        $this->env = $env;
        $this->gateway_state = $gateway_state;
        $this->takeover_status = $takeover_status;
    }

    /**
     * @return array{ok:bool, ran_at:int, checks:list<array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}>}
     */
    public function run() {
        $callback = Preflight::callback_findings(Preflight::callback_probe($this->env));
        $credential = Preflight::credential_findings($this->env, Checks::settings($this->env));
        $gateway = $this->gateway();

        return Checks::report(array(
            $this->takeover_state(),
            Checks::callback_reachable($callback),
            Checks::cache_exclusion($callback),
            Checks::credential($credential),
            $this->flag('gateway_registered', $gateway['registered'], 'gateway_not_registered',
                __('The SUPCheckout gateway is registered with WooCommerce.', 'supcheckout'),
                __('The SUPCheckout gateway is not registered with WooCommerce, so customers cannot pick it at checkout.', 'supcheckout')),
            $this->flag('gateway_enabled', $gateway['enabled'], 'gateway_disabled',
                __('The SUPCheckout gateway is enabled.', 'supcheckout'),
                __('The SUPCheckout gateway is disabled in its settings, so customers cannot pick it at checkout.', 'supcheckout')),
            Checks::store_currency('currency_supported', $this->env),
            Checks::multilingual($this->env),
            Checks::check('event_journal', Checks::DEFERRED, 'event_journal_not_built',
                __('The payment event journal is not built yet, so it is not checked.', 'supcheckout')),
        ), $this->env->now());
    }

    /**
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    private function takeover_state() {
        $status = $this->call($this->takeover_status);
        $status = is_string($status) ? $status : 'unknown';
        if ($status === TakeoverState::CUT_OVER || $status === TakeoverState::FINALIZED) {
            return Checks::check('takeover_state', Checks::PASS, null, __('The switch to SUPCheckout is complete.', 'supcheckout'), array('status' => $status));
        }
        return Checks::check('takeover_state', Checks::FAIL, 'not_switched',
            __('The switch to SUPCheckout has not completed, so the checks below describe a store that is not using it yet.', 'supcheckout'), array('status' => $status));
    }

    /**
     * @param string $id        Check ID.
     * @param bool   $ok        Whether the condition holds.
     * @param string $code      Failure code.
     * @param string $pass_text Message when it holds.
     * @param string $fail_text Message when it does not.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    private function flag($id, $ok, $code, $pass_text, $fail_text) {
        return $ok
            ? Checks::check($id, Checks::PASS, null, $pass_text)
            : Checks::check($id, Checks::FAIL, $code, $fail_text);
    }

    /**
     * @return array{registered:bool, enabled:bool}
     */
    private function gateway() {
        $state = $this->call($this->gateway_state);
        return array(
            'registered' => is_array($state) && isset($state['registered']) && $state['registered'] === true,
            'enabled' => is_array($state) && isset($state['enabled']) && $state['enabled'] === true,
        );
    }

    /**
     * A binding that throws reads as no answer; its message is never reported.
     *
     * @param callable $binding Binding.
     * @return mixed
     */
    private function call($binding) {
        try {
            return call_user_func($binding);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
