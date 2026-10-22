<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Admin\GatewaySettings;
use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Preflight;

defined('ABSPATH') || exit;

/**
 * The check builders SelfTest and SetupChecks share (Sub-project 0c Task 5).
 *
 * Check: array{id: string, result: 'pass'|'warn'|'fail'|'skipped'|'deferred', code: ?string,
 * message: string (translatable), data: array}. Data holds counts, names, modes and
 * currencies, never a value, a key or a token. Every builder only reads: the provider
 * probe and the loopback GETs go through Preflight's seams and Environment, and WooCommerce
 * Multilingual settings are reported, never changed.
 *
 * Report: array{ok: bool, ran_at: int, checks: list<check>}, where ok is true when no check
 * failed (a warning, a skipped or a deferred check never fails a report).
 */
final class Checks {
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIPPED = 'skipped';
    public const DEFERRED = 'deferred';

    public const WCML_SETTINGS_OPTION = '_wcml_settings';
    public const WCML_LOCATION_OPTION_PREFIX = 'wcml_payment_gateway_';
    private const WCML_MULTI_CURRENCY = 2;
    /** WooCommerce's price decimals option and its default (wc_get_price_decimals()). */
    private const PRICE_DECIMALS_OPTION = 'woocommerce_price_num_decimals';
    private const PRICE_DECIMALS_DEFAULT = 2;

    /**
     * @param string               $id      Check ID.
     * @param string               $result  One of the result constants.
     * @param string|null          $code    Stable code, or null.
     * @param string               $message Translated message.
     * @param array<string, mixed> $data    Counts, names and flags; never values.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function check($id, $result, $code, $message, array $data = array()) {
        return array('id' => $id, 'result' => $result, 'code' => $code, 'message' => $message, 'data' => $data);
    }

    /**
     * @param list<array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}> $checks Checks.
     * @param int                                                                                              $now    Unix time.
     * @return array{ok:bool, ran_at:int, checks:list<array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}>}
     */
    public static function report(array $checks, $now) {
        $ok = true;
        foreach ($checks as $check) {
            if ($check['result'] === self::FAIL) {
                $ok = false;
            }
        }
        return array('ok' => $ok, 'ran_at' => (int) $now, 'checks' => $checks);
    }

    /**
     * @param Environment $env Environment.
     * @return array<string, mixed> The saved gateway settings; the key is only ever passed on to the probe.
     */
    public static function settings(Environment $env) {
        $settings = $env->option(Keys::SETTINGS_OPTION, array());
        return is_array($settings) ? $settings : array();
    }

    /**
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $findings Findings.
     * @param string                                                                              $code     Finding code.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function finding(array $findings, $code) {
        foreach ($findings as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }
        return null;
    }

    /**
     * The credential check from Preflight::credential_findings(). A cooldown is a warning,
     * never a failure: UPayments allows one availability request per minute and a verdict
     * was not reached.
     *
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $findings Credential findings.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function credential(array $findings) {
        $unverified = self::finding($findings, Preflight::CREDENTIAL_UNVERIFIED);
        if ($unverified === null) {
            $mode = self::finding($findings, Preflight::ACCOUNT_MODE);
            $data = $mode === null ? array() : $mode['data'];
            return self::check('credential', self::PASS, null, __('UPayments accepted the saved API key.', 'supcheckout'), array(
                'mode' => isset($data['mode']) ? $data['mode'] : 'unknown',
                'methods' => isset($data['methods']) ? $data['methods'] : array(),
                'test_mode' => isset($data['test_mode']) ? $data['test_mode'] === true : false,
            ));
        }
        if (isset($unverified['data']['reason']) && $unverified['data']['reason'] === 'cooldown') {
            return self::check('credential', self::WARN, 'cooldown', $unverified['message'], $unverified['data']);
        }
        return self::check('credential', self::FAIL, Preflight::CREDENTIAL_UNVERIFIED, $unverified['message'], $unverified['data']);
    }

    /**
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $findings Credential findings.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function account_mode(array $findings) {
        $mode = self::finding($findings, Preflight::ACCOUNT_MODE);
        $data = $mode === null ? array() : $mode['data'];
        if ($mode === null || !isset($data['mode']) || $data['mode'] === 'unknown') {
            return self::check('account_mode', self::SKIPPED, 'account_mode_unknown',
                __('The UPayments account mode is unknown until the API key is verified.', 'supcheckout'), $data);
        }
        return self::check('account_mode', self::PASS, null, $mode['message'], $data);
    }

    /**
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $findings Callback findings.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function callback_reachable(array $findings) {
        $unreachable = self::finding($findings, Preflight::CALLBACK_UNREACHABLE);
        if ($unreachable !== null) {
            return self::check('callback_reachable', self::FAIL, Preflight::CALLBACK_UNREACHABLE, $unreachable['message'], $unreachable['data']);
        }
        return self::check('callback_reachable', self::PASS, null, __('The payment callback address answered a test request.', 'supcheckout'));
    }

    /**
     * @param list<array{severity:string, code:string, message:string, data:array<string,mixed>}> $findings Callback findings.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function cache_exclusion(array $findings) {
        $missing = self::finding($findings, Preflight::CACHE_EXCLUSION_MISSING);
        if ($missing !== null) {
            return self::check('cache_exclusion', self::WARN, Preflight::CACHE_EXCLUSION_MISSING, $missing['message'], $missing['data']);
        }
        return self::check('cache_exclusion', self::PASS, null, __('No cache answered the payment callback test request.', 'supcheckout'));
    }

    /**
     * Whether the store currency is one UPayments supports.
     *
     * @param string      $id  Check ID.
     * @param Environment $env Environment.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function store_currency($id, Environment $env) {
        $supported = GatewaySettings::supported_currencies();
        if (in_array($env->currency(), $supported, true)) {
            return self::check($id, self::PASS, null, __('The store currency is supported by UPayments.', 'supcheckout'),
                array('currency' => $env->currency()));
        }
        return self::check($id, self::FAIL, Preflight::CURRENCY_UNSUPPORTED,
            __('The store currency is not supported by UPayments. Switch the store to a supported currency first.', 'supcheckout'),
            array('currency' => $env->currency(), 'supported' => $supported));
    }

    /**
     * Secondary WooCommerce Multilingual currencies outside UPayments' list, reported only
     * when multi-currency is on and the store currency itself passed.
     *
     * @param Environment $env Environment.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function currency(Environment $env) {
        $store = self::store_currency('currency', $env);
        if ($store['result'] !== self::PASS) {
            return $store;
        }
        $wcml = self::wcml($env);
        $unsupported = array();
        if ($wcml['multi_currency']) {
            $supported = GatewaySettings::supported_currencies();
            foreach ($wcml['currencies'] as $currency) {
                if ($currency['code'] !== $env->currency() && !in_array($currency['code'], $supported, true)) {
                    $unsupported[] = $currency['code'];
                }
            }
        }
        if ($unsupported === array()) {
            return $store;
        }
        return self::check('currency', self::WARN, 'wcml_currency_unsupported',
            __('A WooCommerce Multilingual secondary currency is not supported by UPayments. Orders in it cannot be paid with SUPCheckout; SUPCheckout does not change the multicurrency settings.', 'supcheckout'),
            array(
                'currency' => $env->currency(),
                'unsupported' => array_slice($unsupported, 0, Bounded::LIST_LIMIT),
                'truncated' => count($unsupported) > Bounded::LIST_LIMIT,
            ));
    }

    /**
     * Report-only: warns when the store currency, or a WooCommerce Multilingual currency
     * while multi-currency is on, shows more price decimals than its ISO 4217 minor units.
     * Checkout refuses such an amount before the Charge, so this catches the setting
     * first. Unsupported currencies and unreadable decimals are left to the currency and
     * multilingual checks. It never fails and never writes.
     *
     * @param Environment $env Environment.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function currency_decimals(Environment $env) {
        $configured = array();
        $store = self::decimals_value($env->option(self::PRICE_DECIMALS_OPTION, self::PRICE_DECIMALS_DEFAULT));
        if ($store !== null) {
            $configured[] = array($env->currency(), $store);
        }
        $raw = $env->option(self::WCML_SETTINGS_OPTION, null);
        if (is_array($raw) && isset($raw['enable_multi_currency'], $raw['currency_options'])
            && $raw['enable_multi_currency'] === self::WCML_MULTI_CURRENCY && is_array($raw['currency_options'])
        ) {
            foreach ($raw['currency_options'] as $code => $entry) {
                $decimals = is_array($entry) && isset($entry['num_decimals']) ? self::decimals_value($entry['num_decimals']) : null;
                if ($decimals !== null) {
                    $configured[] = array((string) $code, $decimals);
                }
            }
        }

        $mismatches = array();
        foreach ($configured as $pair) {
            $allowed = GatewaySettings::currency_minor_units($pair[0]);
            if ($allowed !== null && $pair[1] > $allowed && !isset($mismatches[$pair[0]])) {
                $mismatches[$pair[0]] = array('currency' => $pair[0], 'decimals' => $pair[1], 'allowed' => $allowed);
            }
        }
        if ($mismatches === array()) {
            return self::check('currency_decimals', self::PASS, null,
                __('Every supported currency shows no more decimals than it allows.', 'supcheckout'));
        }

        $list = array_values($mismatches);
        $first = $list[0];
        return self::check('currency_decimals', self::WARN, 'currency_decimals_exceed_minor_units',
            sprintf(
                /* translators: 1: ISO 4217 currency code, 2: configured price decimals, 3: decimals the currency allows. */
                __('%1$s is set to %2$d price decimals but allows only %3$d. SUPCheckout refuses a payment whose total uses the extra decimals, before UPayments is called. Set each listed currency to its allowed decimals in the WooCommerce or WooCommerce Multilingual currency settings; SUPCheckout does not change them.', 'supcheckout'),
                $first['currency'],
                $first['decimals'],
                $first['allowed']
            ),
            array(
                'currency' => $first['currency'],
                'decimals' => $first['decimals'],
                'allowed' => $first['allowed'],
                'mismatches' => array_slice($list, 0, Bounded::LIST_LIMIT),
                'truncated' => count($list) > Bounded::LIST_LIMIT,
            ));
    }

    /**
     * @param mixed $value A price-decimals setting.
     * @return int|null The decimals, or null when the value is not a plain non-negative integer.
     */
    private static function decimals_value($value) {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,2}$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Report-only look at WPML / WooCommerce Multilingual gateway settings: per-currency
     * `gateways_settings` and the location rule. Reads two options through Environment and
     * never writes.
     *
     * @param Environment $env Environment.
     * @return array{id:string, result:string, code:string|null, message:string, data:array<string,mixed>}
     */
    public static function multilingual(Environment $env) {
        $wcml = self::wcml($env);
        $data = array(
            'wpml_active' => defined('ICL_SITEPRESS_VERSION'),
            'wcml_active' => defined('WCML_VERSION'),
            'multi_currency' => $wcml['multi_currency'],
            'currencies' => array_slice($wcml['currencies'], 0, Bounded::LIST_LIMIT),
            'currencies_truncated' => count($wcml['currencies']) > Bounded::LIST_LIMIT,
            'location_rule' => $wcml['location_rule'],
        );
        if (!$wcml['shape_ok']) {
            return self::check('multilingual', self::WARN, 'unknown_shape',
                __('The WooCommerce Multilingual gateway settings do not have the shape SUPCheckout knows, so they were not checked. Review the payment gateway settings for each currency by hand.', 'supcheckout'), $data);
        }
        $legacy_only = $wcml['location_rule']['legacy'] && !$wcml['location_rule']['new'];
        foreach ($wcml['currencies'] as $currency) {
            $legacy_only = $legacy_only || ($currency['legacy_id'] && !$currency['new_id']);
        }
        if ($legacy_only) {
            return self::check('multilingual', self::WARN, 'wcml_gateway_settings_legacy_only',
                __('WooCommerce Multilingual gateway settings name only the previous UPayments gateway ID. Review the per-currency and location settings and add SUPCheckout; SUPCheckout does not change them.', 'supcheckout'), $data);
        }
        return self::check('multilingual', self::PASS, null, __('No multilingual gateway setting needs attention.', 'supcheckout'), $data);
    }

    /**
     * @param Environment $env Environment.
     * @return array{shape_ok:bool, multi_currency:bool, currencies:list<array{code:string, gateways_enabled:bool, new_id:bool, legacy_id:bool}>, location_rule:array{new:bool, legacy:bool}}
     */
    private static function wcml(Environment $env) {
        $shape_ok = true;
        $multi = false;
        $currencies = array();

        $raw = $env->option(self::WCML_SETTINGS_OPTION, null);
        if ($raw !== null && $raw !== false && $raw !== '' && $raw !== array()) {
            if (!is_array($raw)) {
                $shape_ok = false;
            } else {
                $multi = isset($raw['enable_multi_currency']) && $raw['enable_multi_currency'] === self::WCML_MULTI_CURRENCY;
                $options = isset($raw['currency_options']) ? $raw['currency_options'] : array();
                if (!is_array($options)) {
                    $shape_ok = false;
                    $options = array();
                }
                foreach ($options as $code => $entry) {
                    $row = self::wcml_currency((string) $code, $entry);
                    if ($row === null) {
                        $shape_ok = false;
                        continue;
                    }
                    $currencies[] = $row;
                }
            }
        }

        $rule = array('new' => false, 'legacy' => false);
        foreach (array('new' => Keys::GATEWAY, 'legacy' => Keys::GATEWAY_LEGACY) as $which => $gateway) {
            $value = $env->option(self::WCML_LOCATION_OPTION_PREFIX . $gateway, null);
            if ($value === null || $value === false) {
                continue;
            }
            $rule[$which] = true;
            if (!is_array($value)) {
                $shape_ok = false;
            }
        }

        return array('shape_ok' => $shape_ok, 'multi_currency' => $multi, 'currencies' => $currencies, 'location_rule' => $rule);
    }

    /**
     * @param string $code  Currency key.
     * @param mixed  $entry One currency_options entry.
     * @return array{code:string, gateways_enabled:bool, new_id:bool, legacy_id:bool}|null Null when the entry has an unknown shape.
     */
    private static function wcml_currency($code, $entry) {
        if (!is_array($entry)) {
            return null;
        }
        $settings = isset($entry['gateways_settings']) ? $entry['gateways_settings'] : array();
        if (!is_array($settings)) {
            return null;
        }
        return array(
            'code' => $code,
            'gateways_enabled' => !empty($entry['gateways_enabled']),
            'new_id' => array_key_exists(Keys::GATEWAY, $settings),
            'legacy_id' => array_key_exists(Keys::GATEWAY_LEGACY, $settings),
        );
    }

    private function __construct() {}
}
