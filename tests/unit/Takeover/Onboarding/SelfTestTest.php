<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\Onboarding\SelfTest;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Tests\Takeover\PreflightFixtures;

/** 0c Task 5: the post-switch self-test (spec section 5.4 step 6). */
final class SelfTestTest extends TestCase {
    private const SECRET = 'sk-test-SECRET-0c';

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    /**
     * @param array<string, mixed> $settings Gateway settings.
     * @param array<string, mixed> $extra    Environment overrides.
     */
    private static function env(array $settings, array $extra = array()): Environment {
        return PreflightFixtures::env($extra + array('option_values' => array(
            Keys::SETTINGS_OPTION => $settings + array('enabled' => 'yes', 'api_key' => 'k', 'test_mode' => 'no'),
        )));
    }

    private static function run_self_test(Environment $env, bool $registered = true, bool $enabled = true, string $status = TakeoverState::CUT_OVER): array {
        return (new SelfTest($env, static function () use ($registered, $enabled) {
            return array('registered' => $registered, 'enabled' => $enabled);
        }, static function () use ($status) {
            return $status;
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

    private static function availability(&$calls, array $answer): callable {
        return static function () use (&$calls, $answer) {
            $calls++;
            return $answer;
        };
    }

    public function test_self_test_in_live_mode_makes_exactly_one_probe(): void {
        $calls = 0;
        $env = self::env(array('test_mode' => 'no'), array('availability' => self::availability($calls, PreflightFixtures::canonical_success())));

        $check = self::check(self::run_self_test($env), 'credential');

        self::assertSame(array('pass', 1, false, 'hosted'), array($check['result'], $calls, $check['data']['test_mode'], $check['data']['mode']));
    }

    public function test_self_test_in_test_mode_makes_exactly_one_probe(): void {
        $calls = 0;
        $env = self::env(array('test_mode' => 'yes'), array('availability' => self::availability($calls, PreflightFixtures::canonical_success())));

        $check = self::check(self::run_self_test($env), 'credential');

        self::assertSame(array('pass', 1, true, 'hosted'), array($check['result'], $calls, $check['data']['test_mode'], $check['data']['mode']));
        self::assertSame(array('knet', 'credit_card'), $check['data']['methods']);
    }

    public function test_a_failed_probe_is_never_repeated(): void {
        $calls = 0;
        $env = self::env(array(), array('availability' => self::availability($calls, array('result' => 'failure'))));

        $report = self::run_self_test($env);
        $check = self::check($report, 'credential');

        self::assertSame(array(1, 'fail', 'credential_unverified', 'rejected', false), array($calls, $check['result'], $check['code'], $check['data']['reason'], $report['ok']));
    }

    public function test_a_cooldown_warns_and_does_not_fail_the_self_test(): void {
        $calls = 0;
        $env = self::env(array(), array('availability' => self::availability($calls, array('result' => 'cooldown'))));

        $report = self::run_self_test($env);
        $check = self::check($report, 'credential');

        self::assertSame(array('warn', 'cooldown', 1, true), array($check['result'], $check['code'], $calls, $report['ok']));
    }

    public function test_self_test_reports_a_provider_outage_as_provider_unavailable(): void {
        $calls = 0;
        $env = self::env(array(), array('availability' => self::availability($calls, array('result' => 'failure', 'provider_unavailable' => true, 'http_status' => 502))));

        $report = self::run_self_test($env);
        $check = self::check($report, 'credential');

        self::assertSame(array('fail', 'credential_unverified', 'provider_unavailable', 502, false), array($check['result'], $check['code'], $check['data']['reason'], $check['data']['http_status'], $report['ok']));
    }

    public function test_a_missing_key_fails_without_any_probe(): void {
        $calls = 0;
        $env = self::env(array('api_key' => ''), array('availability' => self::availability($calls, PreflightFixtures::canonical_success())));

        $check = self::check(self::run_self_test($env), 'credential');

        self::assertSame(array('fail', 'missing', 0), array($check['result'], $check['data']['reason'], $calls));
    }

    public function test_self_test_defers_the_event_journal(): void {
        $check = self::check(self::run_self_test(self::env(array())), 'event_journal');

        self::assertSame(array('deferred', 'event_journal_not_built'), array($check['result'], $check['code']));
    }

    public function test_self_test_lists_its_checks_in_order(): void {
        $report = self::run_self_test(self::env(array()));

        self::assertSame(array('takeover_state', 'callback_reachable', 'cache_exclusion', 'credential', 'gateway_registered', 'gateway_enabled', 'currency_supported', 'multilingual', 'event_journal'), array_column($report['checks'], 'id'));
        self::assertTrue($report['ok']);
        self::assertSame(PreflightFixtures::NOW, $report['ran_at']);
        foreach ($report['checks'] as $check) {
            self::assertSame(array('id', 'result', 'code', 'message', 'data'), array_keys($check));
        }
    }

    public function test_takeover_state_passes_only_once_switched(): void {
        $results = array();
        foreach (array(TakeoverState::CUT_OVER, TakeoverState::FINALIZED, TakeoverState::VERIFIED, TakeoverState::IDLE, TakeoverState::ROLLED_BACK) as $status) {
            $check = self::check(self::run_self_test(self::env(array()), true, true, $status), 'takeover_state');
            $results[$status] = array($check['result'], $check['code']);
        }

        self::assertSame(array(
            'cut_over' => array('pass', null),
            'finalized' => array('pass', null),
            'verified' => array('fail', 'not_switched'),
            'idle' => array('fail', 'not_switched'),
            'rolled_back' => array('fail', 'not_switched'),
        ), $results);
    }

    public function test_a_failing_takeover_state_fails_the_report(): void {
        self::assertFalse(self::run_self_test(self::env(array()), true, true, TakeoverState::VERIFIED)['ok']);
    }

    public function test_callback_and_cache_findings_map_to_their_checks(): void {
        $unreachable = self::env(array(), array('http_get' => static function () {
            return array('status' => 503, 'headers' => array());
        }));
        $cached = self::env(array(), array('http_get' => static function () {
            return array('status' => 200, 'headers' => array('x-supcheckout-probe' => '1', 'x-litespeed-cache' => 'hit'));
        }));

        $down = self::run_self_test($unreachable);
        $warm = self::run_self_test($cached);

        $callback = self::check($down, 'callback_reachable');
        self::assertSame(array('fail', 'callback_unreachable', 503), array($callback['result'], $callback['code'], $callback['data']['status']));
        self::assertFalse($down['ok']);
        $cache = self::check($warm, 'cache_exclusion');
        self::assertSame(array('pass', 'warn', 'cache_exclusion_missing'), array(self::check($warm, 'callback_reachable')['result'], $cache['result'], $cache['code']));
        self::assertTrue($warm['ok']);
    }

    public function test_gateway_registration_enablement_and_currency_checks(): void {
        $off = self::run_self_test(self::env(array()), false, false);
        $on = self::run_self_test(self::env(array()));
        $bad_currency = self::check(self::run_self_test(self::env(array(), array('currency' => 'GBP'))), 'currency_supported');

        self::assertSame(array('fail', 'fail'), array(self::check($off, 'gateway_registered')['result'], self::check($off, 'gateway_enabled')['result']));
        self::assertSame(array('pass', 'pass', 'pass'), array(self::check($on, 'gateway_registered')['result'], self::check($on, 'gateway_enabled')['result'], self::check($on, 'currency_supported')['result']));
        self::assertSame(array('fail', 'currency_unsupported', 'GBP'), array($bad_currency['result'], $bad_currency['code'], $bad_currency['data']['currency']));
    }

    public function test_a_throwing_gateway_binding_fails_closed_without_leaking_its_message(): void {
        $report = (new SelfTest(self::env(array()), static function () {
            throw new \RuntimeException('woocommerce not loaded');
        }, static function () {
            return TakeoverState::CUT_OVER;
        }))->run();

        self::assertSame(array('fail', 'fail'), array(self::check($report, 'gateway_registered')['result'], self::check($report, 'gateway_enabled')['result']));
        self::assertStringNotContainsString('woocommerce not loaded', (string) wp_json_encode($report));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_post_switch_self_test_reports_wpml_gateway_strings_translated_only_under_the_previous_id(): void {
        // A4-04 / owner observation O2: after the switch WCML looks up supcheckout_gateway_*.
        define('WPML_ST_VERSION', '3.2.19');
        $db = PreflightFixtures::db(array(
            'SHOW TABLES LIKE' => static function (array $args) {
                return stripslashes((string) $args[0]);
            },
            't.string_id = s.id' => array('supcheckout_gateway_title|ar', 'upayments_gateway_description|ar', 'upayments_gateway_title|ar'),
        ));

        $report = self::run_self_test(self::env(array(), array('db' => $db)));
        $check = self::check($report, 'multilingual');

        self::assertSame(array('warn', 'wpml_gateway_strings_legacy_only'), array($check['result'], $check['code']));
        self::assertSame(array(array('string' => 'supcheckout_gateway_description', 'language' => 'ar')), $check['data']['gateway_strings']['untranslated']);
        self::assertTrue($report['ok'], 'report-only');
    }

    public function test_no_check_ever_contains_the_api_key(): void {
        $env = self::env(array('api_key' => self::SECRET), array('availability' => static function (array $settings) {
            return array('result' => 'failure', 'echo' => $settings['api_key']);
        }));

        self::assertFalse(strpos((string) wp_json_encode(self::run_self_test($env)), self::SECRET));
    }
}
