<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Onboarding\SetupChecks;
use Simplixi\SUPCheckout\Tests\Takeover\PreflightFixtures;

/** 0c Task 5: the new-merchant setup checks (spec section 5.5). */
final class SetupChecksTest extends TestCase {
    private const SECRET = 'sk-test-SECRET-0c';

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    /**
     * @param array<string, mixed> $options  Extra option values.
     * @param array<string, mixed> $extra    Environment overrides.
     * @param array<string, mixed> $settings Gateway settings.
     */
    private static function env(array $options = array(), array $extra = array(), array $settings = array()): Environment {
        return PreflightFixtures::env($extra + array('option_values' => $options + array(
            Keys::SETTINGS_OPTION => $settings + array('enabled' => 'yes', 'api_key' => 'k', 'test_mode' => 'yes'),
        )));
    }

    /** @param list<string> $detected */
    private static function run_checks(Environment $env, array $detected = array()): array {
        return (new SetupChecks($env, static function () use ($detected) {
            return $detected;
        }))->run();
    }

    private static function check(array $report, string $id): array {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        self::fail('no check ' . $id);
    }

    /** @return array<string, mixed> The seed shape of supcheckout_matrix_seed_wcml(). */
    private static function wcml_seed(): array {
        return array(
            '_wcml_settings' => array(
                'enable_multi_currency' => 2,
                'currency_options' => array(
                    'KWD' => array('rate' => 1, 'gateways_enabled' => 1, 'gateways_settings' => array(
                        Keys::GATEWAY_LEGACY => array('currency' => 'KWD', 'value' => ''),
                        'bacs' => array('currency' => 'KWD', 'value' => ''),
                    )),
                    'SAR' => array('rate' => 12.2, 'gateways_enabled' => 1, 'gateways_settings' => array(
                        Keys::GATEWAY_LEGACY => array('currency' => 'KWD', 'value' => ''),
                    )),
                ),
            ),
            'wcml_payment_gateway_' . Keys::GATEWAY_LEGACY => array('mode' => 'include', 'countries' => array('KW', 'SA')),
        );
    }

    public function test_setup_checks_list_their_checks_in_order(): void {
        $report = self::run_checks(self::env());

        self::assertSame(array('credential', 'account_mode', 'currency', 'currency_decimals', 'callback_reachable', 'cache_exclusion', 'cache_layers', 'multilingual', 'test_payment'), array_column($report['checks'], 'id'));
        self::assertTrue($report['ok']);
        self::assertSame(PreflightFixtures::NOW, $report['ran_at']);
        foreach ($report['checks'] as $check) {
            self::assertSame(array('id', 'result', 'code', 'message', 'data'), array_keys($check));
        }
    }

    public function test_the_test_payment_is_deferred(): void {
        $check = self::check(self::run_checks(self::env()), 'test_payment');

        self::assertSame(array('deferred', 'test_payment_ui_driven'), array($check['result'], $check['code']));
    }

    public function test_credential_and_account_mode_checks(): void {
        $report = self::run_checks(self::env());

        self::assertSame('pass', self::check($report, 'credential')['result']);
        $mode = self::check($report, 'account_mode');
        self::assertSame(array('pass', 'hosted', true), array($mode['result'], $mode['data']['mode'], $mode['data']['test_mode']));
    }

    public function test_a_provider_outage_is_reported_with_its_http_status(): void {
        $env = self::env(array(), array('availability' => static function () {
            return array('result' => 'failure', 'provider_unavailable' => true, 'http_status' => 503);
        }));

        $report = self::run_checks($env);
        $credential = self::check($report, 'credential');

        self::assertSame(array('fail', 'credential_unverified', 'provider_unavailable', 503, false), array($credential['result'], $credential['code'], $credential['data']['reason'], $credential['data']['http_status'], $report['ok']));
        self::assertSame('unknown', self::check($report, 'account_mode')['data']['mode']);
    }

    public function test_a_cooldown_warns_without_failing(): void {
        $env = self::env(array(), array('availability' => static function () {
            return array('result' => 'cooldown');
        }));

        $report = self::run_checks($env);
        $credential = self::check($report, 'credential');

        self::assertSame(array('warn', 'cooldown', true), array($credential['result'], $credential['code'], $report['ok']));
    }

    public function test_an_unsupported_store_currency_fails(): void {
        $report = self::run_checks(self::env(array(), array('currency' => 'GBP')));

        $check = self::check($report, 'currency');
        self::assertSame(array('fail', 'currency_unsupported', 'GBP', false), array($check['result'], $check['code'], $check['data']['currency'], $report['ok']));
    }

    public function test_wcml_secondary_currency_outside_the_supported_list_warns(): void {
        $options = array('_wcml_settings' => array(
            'enable_multi_currency' => 2,
            'currency_options' => array('KWD' => array('rate' => 1), 'SAR' => array('rate' => 12), 'XYZ' => array('rate' => 3)),
        ));

        $report = self::run_checks(self::env($options));
        $check = self::check($report, 'currency');

        self::assertSame(array('warn', 'wcml_currency_unsupported', array('XYZ'), true), array($check['result'], $check['code'], $check['data']['unsupported'], $report['ok']));
    }

    public function test_wcml_currencies_are_ignored_unless_multi_currency_is_enabled(): void {
        $options = array('_wcml_settings' => array('enable_multi_currency' => 1, 'currency_options' => array('XYZ' => array('rate' => 3))));

        self::assertSame('pass', self::check(self::run_checks(self::env($options)), 'currency')['result']);
    }

    public function test_store_price_decimals_within_the_currency_minor_units_pass(): void {
        foreach (array(array('KWD', '3'), array('KWD', 2), array('AED', '2'), array('USD', '0')) as $case) {
            $env = self::env(array('woocommerce_price_num_decimals' => $case[1]), array('currency' => $case[0]));

            $check = self::check(self::run_checks($env), 'currency_decimals');

            self::assertSame(array('pass', null), array($check['result'], $check['code']), $case[0] . ' ' . $case[1]);
        }
    }

    public function test_a_store_without_a_price_decimals_option_uses_the_woocommerce_default(): void {
        $check = self::check(self::run_checks(self::env(array(), array('currency' => 'AED'))), 'currency_decimals');

        self::assertSame('pass', $check['result']);
    }

    public function test_store_price_decimals_beyond_the_currency_minor_units_warn(): void {
        $env = self::env(array('woocommerce_price_num_decimals' => '3'), array('currency' => 'AED'));

        $report = self::run_checks($env);
        $check = self::check($report, 'currency_decimals');

        self::assertSame(array('warn', 'currency_decimals_exceed_minor_units'), array($check['result'], $check['code']));
        self::assertSame(array('currency' => 'AED', 'decimals' => 3, 'allowed' => 2), array_intersect_key($check['data'], array('currency' => 1, 'decimals' => 1, 'allowed' => 1)));
        self::assertStringContainsString('AED', $check['message']);
        self::assertTrue($report['ok'], 'report-only: a warning never fails the report');
    }

    public function test_a_wcml_currency_with_more_decimals_than_its_minor_units_warns(): void {
        $options = array(
            'woocommerce_price_num_decimals' => '3',
            '_wcml_settings' => array(
                'enable_multi_currency' => 2,
                'currency_options' => array(
                    'KWD' => array('rate' => 1, 'num_decimals' => '3'),
                    'SAR' => array('rate' => 12.2, 'num_decimals' => 2),
                    'AED' => array('rate' => 12, 'num_decimals' => '3'),
                ),
            ),
        );

        $report = self::run_checks(self::env($options));
        $check = self::check($report, 'currency_decimals');

        self::assertSame(array('warn', 'currency_decimals_exceed_minor_units'), array($check['result'], $check['code']));
        self::assertSame(array('AED', 3, 2), array($check['data']['currency'], $check['data']['decimals'], $check['data']['allowed']));
        self::assertSame(array(array('currency' => 'AED', 'decimals' => 3, 'allowed' => 2)), $check['data']['mismatches']);
        self::assertTrue($report['ok']);
    }

    public function test_every_mismatched_currency_is_listed(): void {
        $options = array(
            'woocommerce_price_num_decimals' => '3',
            '_wcml_settings' => array(
                'enable_multi_currency' => 2,
                'currency_options' => array(
                    'USD' => array('num_decimals' => '4'),
                    'KWD' => array('num_decimals' => '4'),
                ),
            ),
        );

        $check = self::check(self::run_checks(self::env($options, array('currency' => 'AED'))), 'currency_decimals');

        self::assertSame('warn', $check['result']);
        self::assertSame(array('AED', 3, 2), array($check['data']['currency'], $check['data']['decimals'], $check['data']['allowed']));
        self::assertSame(array(
            array('currency' => 'AED', 'decimals' => 3, 'allowed' => 2),
            array('currency' => 'USD', 'decimals' => 4, 'allowed' => 2),
            array('currency' => 'KWD', 'decimals' => 4, 'allowed' => 3),
        ), $check['data']['mismatches']);
    }

    public function test_wcml_decimals_are_ignored_unless_multi_currency_is_enabled(): void {
        $options = array('_wcml_settings' => array('enable_multi_currency' => 1, 'currency_options' => array('AED' => array('num_decimals' => '3'))));

        self::assertSame('pass', self::check(self::run_checks(self::env($options)), 'currency_decimals')['result']);
    }

    public function test_unsupported_or_unreadable_currencies_are_left_to_the_other_checks(): void {
        $options = array('_wcml_settings' => array(
            'enable_multi_currency' => 2,
            'currency_options' => array(
                'XYZ' => array('num_decimals' => '5'),
                'AED' => array('num_decimals' => 'three'),
                'SAR' => array('num_decimals' => array(3)),
                'USD' => 'garbage',
            ),
        ));

        self::assertSame('pass', self::check(self::run_checks(self::env($options)), 'currency_decimals')['result']);
        self::assertSame('pass', self::check(self::run_checks(self::env(array('woocommerce_price_num_decimals' => '5'), array('currency' => 'GBP'))), 'currency_decimals')['result']);
    }

    public function test_the_currency_decimals_check_never_writes(): void {
        self::run_checks(self::env(array('woocommerce_price_num_decimals' => '3'), array('currency' => 'AED')));

        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }

    public function test_wcml_per_currency_settings_naming_only_the_legacy_id_warn(): void {
        $report = self::run_checks(self::env(self::wcml_seed()));
        $check = self::check($report, 'multilingual');

        self::assertSame(array('warn', 'wcml_gateway_settings_legacy_only'), array($check['result'], $check['code']));
        self::assertSame(array('new' => false, 'legacy' => true), $check['data']['location_rule']);
        self::assertTrue($check['data']['multi_currency']);
        self::assertSame(array(
            array('code' => 'KWD', 'gateways_enabled' => true, 'new_id' => false, 'legacy_id' => true),
            array('code' => 'SAR', 'gateways_enabled' => true, 'new_id' => false, 'legacy_id' => true),
        ), $check['data']['currencies']);
        self::assertTrue($report['ok'], 'report-only: a warning never fails the report');
    }

    public function test_a_location_rule_naming_only_the_legacy_id_warns(): void {
        $check = self::check(self::run_checks(self::env(array('wcml_payment_gateway_' . Keys::GATEWAY_LEGACY => array('mode' => 'include', 'countries' => array('KW'))))), 'multilingual');

        self::assertSame(array('warn', 'wcml_gateway_settings_legacy_only', array('new' => false, 'legacy' => true)), array($check['result'], $check['code'], $check['data']['location_rule']));
    }

    public function test_settings_naming_the_new_id_as_well_pass(): void {
        $options = self::wcml_seed();
        foreach (array('KWD', 'SAR') as $code) {
            $options['_wcml_settings']['currency_options'][$code]['gateways_settings'][Keys::GATEWAY] = array('currency' => 'KWD', 'value' => '');
        }
        $options['wcml_payment_gateway_' . Keys::GATEWAY] = array('mode' => 'include', 'countries' => array('KW'));

        $check = self::check(self::run_checks(self::env($options)), 'multilingual');

        self::assertSame(array('pass', null, array('new' => true, 'legacy' => true)), array($check['result'], $check['code'], $check['data']['location_rule']));
    }

    public function test_an_option_that_is_not_the_known_shape_reports_unknown_shape(): void {
        $shapes = array(
            'a string' => 'garbage',
            'currency_options not an array' => array('enable_multi_currency' => 2, 'currency_options' => 'x'),
            'a currency that is not an array' => array('enable_multi_currency' => 2, 'currency_options' => array('KWD' => 'x')),
            'gateways_settings not an array' => array('enable_multi_currency' => 2, 'currency_options' => array('KWD' => array('gateways_settings' => 'x'))),
        );

        foreach ($shapes as $label => $value) {
            $check = self::check(self::run_checks(self::env(array('_wcml_settings' => $value))), 'multilingual');
            self::assertSame(array('warn', 'unknown_shape'), array($check['result'], $check['code']), $label);
        }
    }

    public function test_a_store_without_wcml_has_nothing_to_warn_about(): void {
        $check = self::check(self::run_checks(self::env()), 'multilingual');

        self::assertSame('pass', $check['result']);
        self::assertSame(array(false, false, false, array(), array('new' => false, 'legacy' => false)), array($check['data']['wpml_active'], $check['data']['wcml_active'], $check['data']['multi_currency'], $check['data']['currencies'], $check['data']['location_rule']));
    }

    public function test_the_multilingual_check_never_writes(): void {
        self::run_checks(self::env(self::wcml_seed()));

        self::assertSame(array(), $GLOBALS['supcheckout_test_options']);
    }

    public function test_cache_layers_report_plugins_and_a_cloudflare_proxy(): void {
        $proxy = self::env(array(), array('http_get' => static function () {
            return array('status' => 200, 'headers' => array('x-supcheckout-probe' => '1', 'cf-ray' => '8a1b2c3d4e5f-KWI'));
        }));

        $quiet = self::check(self::run_checks(self::env(), array('w3tc')), 'cache_layers');
        $proxied = self::check(self::run_checks($proxy, array('w3tc')), 'cache_layers');
        $plugin = self::check(self::run_checks(self::env(), array('litespeed', 'cloudflare')), 'cache_layers');

        self::assertSame(array('pass', array('w3tc'), false), array($quiet['result'], $quiet['data']['plugins'], $quiet['data']['cloudflare_proxied']));
        self::assertSame(array('pass', true), array($proxied['result'], $proxied['data']['cloudflare_proxied']), 'a proxy alone is not a manual purge');
        self::assertSame(array('warn', array('litespeed', 'cloudflare')), array($plugin['result'], $plugin['data']['plugins']), 'the Cloudflare plugin needs a manual purge');
    }

    public function test_a_cached_callback_warns_in_both_cache_checks(): void {
        $env = self::env(array(), array('http_get' => static function () {
            return array('status' => 200, 'headers' => array('x-supcheckout-probe' => '1', 'age' => '42'));
        }));

        $report = self::run_checks($env);
        $layers = self::check($report, 'cache_layers');

        self::assertSame(array('pass', 'warn', 'warn', 'cache_exclusion_missing'), array(self::check($report, 'callback_reachable')['result'], self::check($report, 'cache_exclusion')['result'], $layers['result'], $layers['code']));
    }

    public function test_an_unreachable_callback_fails(): void {
        $env = self::env(array(), array('http_get' => static function () {
            return null;
        }));

        $report = self::run_checks($env);
        $callback = self::check($report, 'callback_reachable');

        self::assertSame(array('fail', 'callback_unreachable', false), array($callback['result'], $callback['code'], $report['ok']));
    }

    public function test_no_check_ever_contains_the_api_key(): void {
        $env = self::env(array(), array('availability' => static function (array $settings) {
            return array('result' => 'failure', 'echo' => $settings['api_key']);
        }), array('api_key' => self::SECRET));

        self::assertFalse(strpos((string) wp_json_encode(self::run_checks($env)), self::SECRET));
    }
}
