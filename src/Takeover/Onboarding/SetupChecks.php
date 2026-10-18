<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Preflight;

defined('ABSPATH') || exit;

/**
 * New-merchant setup checks (spec section 5.5, Sub-project 0c Task 5).
 *
 * Read-only, like Preflight, whose seams it reuses: one availability probe for the
 * credential and account mode, one callback probe (at most two loopback GETs) for the
 * callback and both cache checks. Settings come from Keys::SETTINGS_OPTION and the API key
 * is never part of a check. The multilingual check only reports WooCommerce Multilingual
 * gateway settings and writes nothing. The optional sandbox test payment is designed in
 * Sub-project 2 through the core checkout lifecycle, so `test_payment` is deferred.
 */
final class SetupChecks {
    /** Response header Cloudflare adds to every request it proxies. */
    private const CLOUDFLARE_HEADER = 'cf-ray';

    /** @var Environment */
    private $env;
    /** @var callable */
    private $detected_caches;

    /**
     * @param Environment $env             Everything the checks read.
     * @param callable    $detected_caches (): list<string> CachePurger::detected().
     */
    public function __construct(Environment $env, $detected_caches) {
        $this->env = $env;
        $this->detected_caches = $detected_caches;
    }

    /**
     * @return array{ok:bool, ran_at:int, checks:list<array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}>}
     */
    public function run() {
        $credential = Preflight::credential_findings($this->env, Checks::settings($this->env));
        $probe = Preflight::callback_probe($this->env);
        $callback = Preflight::callback_findings($probe);

        return Checks::report(array(
            Checks::credential($credential),
            Checks::account_mode($credential),
            Checks::currency($this->env),
            Checks::callback_reachable($callback),
            Checks::cache_exclusion($callback),
            $this->cache_layers($callback, $probe),
            Checks::multilingual($this->env),
            Checks::check('test_payment', Checks::DEFERRED, 'test_payment_ui_driven',
                __('The optional sandbox test payment is started from the setup screen, so it is not run here.', 'supcheckout')),
        ), $this->env->now());
    }

    /**
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $callback Callback findings.
     * @param list<array{status:int, headers:array<string,string>}|null>                          $probe    Callback probe responses.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    private function cache_layers(array $callback, array $probe) {
        $plugins = $this->detected();
        $proxied = false;
        foreach ($probe as $response) {
            if ($response !== null && isset($response['headers'][self::CLOUDFLARE_HEADER])) {
                $proxied = true;
            }
        }
        $data = array('plugins' => $plugins, 'cloudflare_proxied' => $proxied);

        foreach ($callback as $finding) {
            if ($finding['code'] === Preflight::CACHE_EXCLUSION_MISSING) {
                return Checks::check('cache_layers', Checks::WARN, Preflight::CACHE_EXCLUSION_MISSING, $finding['message'], $data);
            }
        }
        if (in_array('cloudflare', $plugins, true)) {
            return Checks::check('cache_layers', Checks::WARN, 'manual_purge_required',
                __('The Cloudflare plugin is active and has no purge API SUPCheckout can call. Purge the Cloudflare cache by hand after changing the payment settings.', 'supcheckout'), $data);
        }
        return Checks::check('cache_layers', Checks::PASS, null, __('No cache layer needs a manual step.', 'supcheckout'), $data);
    }

    /**
     * @return list<string> Detected cache layer names, bounded.
     */
    private function detected() {
        $names = array();
        try {
            $detected = call_user_func($this->detected_caches);
        } catch (\Throwable $e) {
            $detected = array();
        }
        foreach (is_array($detected) ? $detected : array() as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        return array_slice($names, 0, Bounded::LIST_LIMIT);
    }
}
