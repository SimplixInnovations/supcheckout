<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Environment;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Takeover\Preflight;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * Preflight (Sub-project 0b Task 4): one test per report code, plus the read-only
 * contract. Every environment is faked; no test touches the network.
 */
final class PreflightTest extends TestCase {
    /** @var list<string> */
    private $dirs = array();

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    protected function tearDown(): void {
        foreach ($this->dirs as $dir) {
            PreflightFixtures::remove_dir($dir);
        }
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    public function test_codes_and_severities_are_stable(): void {
        self::assertSame(array(
            'official_plugin_active' => 'fact',
            'official_network_active' => 'blocking',
            'credential_unverified' => 'blocking',
            'account_mode' => 'fact',
            'currency_unsupported' => 'blocking',
            'pending_with_open_link' => 'warning',
            'hpos_meta_disagreement' => 'warning',
            'whs_multi_valued' => 'fact',
            'duplicate_renewals' => 'warning',
            'official_debug_log_present' => 'warning',
            'third_party_references' => 'fact|warning',
            'callback_unreachable' => 'blocking',
            'cache_exclusion_missing' => 'warning',
            'settings_source' => 'fact',
            'official_only_names' => 'fact',
            'upay_subscriptions_table_present' => 'fact',
            'leftovers' => 'fact',
            // A5-04 and the A5-03 disclosure: added, every earlier code is unchanged.
            'settings_target_foreign' => 'blocking',
            'orders_to_copy' => 'fact',
        ), Preflight::codes());
    }

    public function test_a_healthy_store_reports_only_the_descriptive_facts(): void {
        $report = Preflight::run_with(PreflightFixtures::env());

        self::assertSame(array('blocking', 'warnings', 'facts'), array_keys($report));
        self::assertSame(array(), $report['blocking']);
        self::assertSame(array(), $report['warnings']);
        self::assertSame(array('settings_source', 'account_mode'), array_column($report['facts'], 'code'));
        foreach ($report['facts'] as $fact) {
            self::assertIsString($fact['message']);
            self::assertNotSame('', $fact['message']);
            self::assertIsArray($fact['data']);
        }
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode($report));
    }

    public function test_official_plugin_active_lists_active_official_installs_with_their_header_version(): void {
        $dir = $this->plugins(array('upayments' => '3.1.1', 'upayments-old' => '2.2.1', 'supcheckout' => '0.1.0'));
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'plugins_dir' => $dir,
            'active_plugins' => array('upayments/UPayments.php', 'supcheckout/supcheckout.php', 'woocommerce/woocommerce.php'),
        )));

        $fact = PreflightFixtures::find($report['facts'], 'official_plugin_active');
        self::assertNotNull($fact);
        self::assertSame(array(array('basename' => 'upayments/UPayments.php', 'version' => '3.1.1')), $fact['data']['plugins']);

        $inactive = Preflight::run_with(PreflightFixtures::env(array('plugins_dir' => $dir, 'active_plugins' => array())));
        self::assertNull(PreflightFixtures::find($inactive['facts'], 'official_plugin_active'));
    }

    public function test_a_network_activated_official_plugin_blocks_because_takeover_is_per_site(): void {
        $dir = $this->plugins(array('upayments' => '3.1.1', 'supcheckout' => '0.1.0'));
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'plugins_dir' => $dir,
            'active_plugins' => array('upayments/UPayments.php', 'supcheckout/supcheckout.php'),
            'network_active_plugins' => array('upayments/UPayments.php'),
        )));

        $blocking = PreflightFixtures::find($report['blocking'], 'official_network_active');
        self::assertNotNull($blocking);
        self::assertSame(array('upayments/UPayments.php'), $blocking['data']['plugins']);

        $site_only = Preflight::run_with(PreflightFixtures::env(array(
            'plugins_dir' => $dir,
            'active_plugins' => array('upayments/UPayments.php'),
            'network_active_plugins' => array('woocommerce/woocommerce.php'),
        )));
        self::assertNull(PreflightFixtures::find($site_only['blocking'], 'official_network_active'), 'a per-site activation does not block');
    }

    public function test_credential_unverified_blocks_when_the_key_is_missing_rejected_or_rate_gated(): void {
        $seen = array();
        $availability = static function ($result) use (&$seen) {
            return static function (array $settings) use ($result, &$seen) {
                $seen[] = $settings;
                return $result;
            };
        };
        $cases = array(
            'rejected' => array('availability' => $availability(array('result' => 'failure'))),
            'cooldown' => array('availability' => $availability(array('result' => 'cooldown'))),
            'missing' => array('availability' => $availability(null)),
        );
        foreach ($cases as $reason => $overrides) {
            $report = Preflight::run_with(PreflightFixtures::env($overrides));
            $blocking = PreflightFixtures::find($report['blocking'], 'credential_unverified');
            self::assertNotNull($blocking, $reason);
            self::assertSame($reason, $blocking['data']['reason']);
            self::assertStringNotContainsString('test-api-key-123', (string) json_encode($report), $reason);
        }
        self::assertSame('test-api-key-123', $seen[0]['api_key'], 'the current key from the settings source is probed');

        $no_key = Preflight::run_with(PreflightFixtures::env(array(
            'option_values' => array('woocommerce_upayments_settings' => array('enabled' => 'yes', 'api_key' => '')),
            'availability' => static function (array $settings) {
                throw new \LogicException('no provider call without a key');
            },
        )));
        self::assertSame('missing', PreflightFixtures::find($no_key['blocking'], 'credential_unverified')['data']['reason']);

        $verified = Preflight::run_with(PreflightFixtures::env());
        self::assertNull(PreflightFixtures::find($verified['blocking'], 'credential_unverified'));
    }

    /**
     * Owner staging rehearsal: the UPayments sandbox answered HTTP 502 through Cloudflare
     * and the report blamed the key. An outage (transport error, timeout, empty body or
     * HTTP 5xx) is provider_unavailable, still BLOCKING; a refusal stays rejected.
     */
    public function test_credential_unverified_tells_a_provider_outage_from_a_rejected_key(): void {
        $cases = array(
            // label => array(transport outcome, expected reason, expected http_status or null)
            'bad gateway' => array(array('transport_ok' => false, 'http_status' => 502, 'curl_errno' => 0, 'body' => '<html>502 Bad Gateway</html>'), 'provider_unavailable', 502),
            'service unavailable' => array(array('transport_ok' => false, 'http_status' => 503, 'curl_errno' => 0, 'body' => ''), 'provider_unavailable', 503),
            'curl error' => array(array('transport_ok' => false, 'http_status' => 0, 'curl_errno' => 1, 'body' => null), 'provider_unavailable', 0),
            'empty body' => array(array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => ''), 'provider_unavailable', 201),
            'unauthorized' => array(array('transport_ok' => false, 'http_status' => 401, 'curl_errno' => 0, 'body' => '{"status":false,"message":"Unauthenticated."}'), 'rejected', null),
            'status false' => array(array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{"status":false,"message":"Invalid key"}'), 'rejected', null),
        );
        foreach ($cases as $label => list($transport, $reason, $http_status)) {
            \supcheckout_test_reset_availability();
            $report = Preflight::run_with(PreflightFixtures::env(array('availability' => PreflightFixtures::probing($transport))));
            $blocking = PreflightFixtures::find($report['blocking'], 'credential_unverified');

            self::assertNotNull($blocking, $label . ': still BLOCKING, never a switch without a verified credential');
            self::assertSame($reason, $blocking['data']['reason'], $label);
            $encoded = (string) json_encode($report);
            self::assertStringNotContainsString('test-api-key-123', $encoded, $label);
            self::assertStringNotContainsString('Bad Gateway', $encoded, $label . ': never the body');
            self::assertStringNotContainsString('Unauthenticated', $encoded, $label . ': never the body');
            if ($reason === 'provider_unavailable') {
                self::assertSame($http_status, $blocking['data']['http_status'], $label . ': the HTTP status, as an int');
                self::assertStringContainsString('UPayments did not answer', $blocking['message'], $label);
                self::assertStringContainsString('your key and settings were not judged', $blocking['message'], $label);
                self::assertStringNotContainsString('Check the key', $blocking['message'], $label);
            } else {
                self::assertArrayNotHasKey('http_status', $blocking['data'], $label);
                self::assertStringContainsString('UPayments did not confirm the saved API key', $blocking['message'], $label);
            }
        }

        $gateway = PreflightFixtures::find(
            Preflight::run_with(PreflightFixtures::env(array('availability' => static function () {
                return array('result' => 'failure', 'http_status' => 502, 'provider_unavailable' => true);
            })))['blocking'],
            'credential_unverified'
        );
        self::assertStringContainsString('(HTTP 502)', $gateway['message']);

        \supcheckout_test_reset_availability();
        $GLOBALS['supcheckout_test_options']['upayments_payment_methods_rate_gate_test'] = time() + 30;
        $cooldown = Preflight::run_with(PreflightFixtures::env(array('availability' => PreflightFixtures::probing(null))));
        self::assertSame('cooldown', PreflightFixtures::find($cooldown['blocking'], 'credential_unverified')['data']['reason'], 'cooldown is unchanged');
    }

    public function test_account_mode_reports_white_label_or_hosted_and_enabled_methods(): void {
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'availability' => static function (array $settings) {
                return PreflightFixtures::canonical_success(true);
            },
        )));
        $fact = PreflightFixtures::find($report['facts'], 'account_mode');
        self::assertSame('white_label', $fact['data']['mode']);
        self::assertSame(array('knet', 'credit_card'), $fact['data']['methods']);
        self::assertTrue($fact['data']['test_mode']);
        self::assertFalse($fact['data']['multimerchant']);

        $hosted = PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['facts'], 'account_mode');
        self::assertSame('hosted', $hosted['data']['mode']);

        $unknown = PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env(array(
            'availability' => static function (array $settings) {
                return array('result' => 'failure');
            },
        )))['facts'], 'account_mode');
        self::assertSame('unknown', $unknown['data']['mode']);
        self::assertSame(array(), $unknown['data']['methods']);
    }

    public function test_currency_unsupported_blocks_a_store_currency_outside_the_gateway_list(): void {
        $report = Preflight::run_with(PreflightFixtures::env(array('currency' => 'GBP')));
        $blocking = PreflightFixtures::find($report['blocking'], 'currency_unsupported');
        self::assertNotNull($blocking);
        self::assertSame('GBP', $blocking['data']['currency']);
        self::assertContains('KWD', $blocking['data']['supported']);

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['blocking'], 'currency_unsupported'));
    }

    public function test_pending_with_open_link_counts_pending_gateway_orders_newer_than_48_hours(): void {
        $since = array();
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'pending_orders' => static function ($after) use (&$since) {
                $since[] = $after;
                return 3;
            },
        )));

        $warning = PreflightFixtures::find($report['warnings'], 'pending_with_open_link');
        self::assertNotNull($warning);
        self::assertSame(array('count' => 3), $warning['data']);
        self::assertSame(array(PreflightFixtures::NOW - 48 * 3600), $since);

        // The WordPress binding: one paginated count over pending gateway orders newer
        // than the window, filtered through Keys like every other order query (Task 2).
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Takeover/Environment.php');
        $binding = substr($source, (int) strpos($source, "'pending_orders' => static function"), 700);
        foreach (array("'status' => array('pending')", "'payment_method' => Keys::payment_method_query(),",
            "'date_created' => '>' . (int) \$since", "'limit' => 1", "'paginate' => true", "'return' => 'ids'") as $needle) {
            self::assertStringContainsString($needle, $binding);
        }

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['warnings'], 'pending_with_open_link'));
    }

    public function test_hpos_meta_disagreement_samples_at_most_200_orders_and_reports_counts_only(): void {
        $orders = array(
            self::order(1, array('UPayments_PaymentID' => array('pay-1'))),
            self::order(2, array('UPayments_PaymentID' => array('pay-2'), 'UPayments_WHS' => array('whs-x1', 'whs-x2'))),
            self::order(3, array()),
        );
        $postmeta = array(
            1 => array('UPayments_PaymentID' => array('pay-1')),
            2 => array('UPayments_WHS' => array('whs-x2', 'whs-x1')),
            3 => array('UPayments_TrackID' => array('track-3')),
        );
        $sample_limit = null;
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'hpos' => true,
            'order_sample' => static function ($limit) use ($orders, &$sample_limit) {
                $sample_limit = $limit;
                return $orders;
            },
            'post_meta' => static function ($id, $key) use ($postmeta) {
                return isset($postmeta[$id][$key]) ? $postmeta[$id][$key] : array();
            },
        )));

        self::assertSame(200, $sample_limit);
        $warning = PreflightFixtures::find($report['warnings'], 'hpos_meta_disagreement');
        self::assertNotNull($warning);
        self::assertSame(array(
            'sampled' => 3,
            'orders_disagreeing' => 2,
            'keys_disagreeing' => 2,
            'crud_only' => 1,
            'postmeta_only' => 1,
            'differing' => 0,
        ), $warning['data']);
        $json = (string) json_encode($report);
        foreach (array('pay-1', 'pay-2', 'track-3', 'whs-x1') as $value) {
            self::assertStringNotContainsString($value, $json, 'counts only, never values');
        }

        $calls = 0;
        $legacy = Preflight::run_with(PreflightFixtures::env(array(
            'hpos' => false,
            'post_meta' => static function ($id, $key) use (&$calls) {
                ++$calls;
                return array();
            },
        )));
        self::assertNull(PreflightFixtures::find($legacy['warnings'], 'hpos_meta_disagreement'));
        self::assertSame(0, $calls, 'CRUD reads postmeta itself when HPOS is off: nothing to compare');
    }

    public function test_whs_multi_valued_counts_orders_with_several_public_status_rows_in_the_authoritative_table(): void {
        $db = PreflightFixtures::db(array('AS multi' => '4'));
        $report = Preflight::run_with(PreflightFixtures::env(array('db' => $db)));
        $fact = PreflightFixtures::find($report['facts'], 'whs_multi_valued');
        self::assertNotNull($fact);
        self::assertSame(4, $fact['data']['orders']);
        $query = $db->executed_matching('AS multi')[0];
        self::assertStringContainsString('post_id', $query['query']);
        self::assertSame(array('wp_postmeta', 'UPayments_WHS'), $query['args']);

        $hpos_db = PreflightFixtures::db();
        $none = Preflight::run_with(PreflightFixtures::env(array('db' => $hpos_db, 'hpos' => true)));
        self::assertNull(PreflightFixtures::find($none['facts'], 'whs_multi_valued'));
        $hpos_query = $hpos_db->executed_matching('AS multi')[0];
        self::assertStringContainsString('order_id', $hpos_query['query']);
        self::assertSame(array('wp_wc_orders_meta', 'UPayments_WHS'), $hpos_query['args']);
    }

    public function test_duplicate_renewals_reports_renewals_sharing_parent_and_billing_date(): void {
        $db = PreflightFixtures::db(array('AS dup' => array('grp' => '2', 'extra' => '3')));
        $report = Preflight::run_with(PreflightFixtures::env(array('db' => $db)));
        $warning = PreflightFixtures::find($report['warnings'], 'duplicate_renewals');
        self::assertNotNull($warning);
        self::assertSame(array('groups' => 2, 'extra_orders' => 3), $warning['data']);
        $query = $db->executed_matching('AS dup')[0];
        self::assertSame(array('wp_postmeta', 'wp_posts', 'UPayments_ParentOrderID', 'shop_order', 'trash'), $query['args']);
        self::assertStringContainsString('DATE(p.post_date_gmt)', $query['query']);

        $hpos_db = PreflightFixtures::db();
        Preflight::run_with(PreflightFixtures::env(array('db' => $hpos_db, 'hpos' => true)));
        $hpos_query = $hpos_db->executed_matching('AS dup')[0];
        self::assertSame(array('wp_wc_orders_meta', 'wp_wc_orders', 'UPayments_ParentOrderID', 'shop_order', 'trash'), $hpos_query['args']);
        self::assertStringContainsString('DATE(o.date_created_gmt)', $hpos_query['query']);

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['warnings'], 'duplicate_renewals'));
    }

    public function test_official_debug_log_is_reported_by_path_only(): void {
        $dir = $this->plugins(array('upayments' => '3.1.1'));
        file_put_contents($dir . '/upayments/debug.log', "API key: live-secret-123\n");
        $report = Preflight::run_with(PreflightFixtures::env(array('plugins_dir' => $dir)));

        $warning = PreflightFixtures::find($report['warnings'], 'official_debug_log_present');
        self::assertNotNull($warning);
        self::assertSame(array('upayments/debug.log'), $warning['data']['paths']);
        self::assertStringNotContainsString('live-secret-123', (string) json_encode($report));
        self::assertStringNotContainsString($dir, (string) json_encode($report), 'no server path layout');

        $clean = $this->plugins(array('upayments' => '3.1.1'));
        self::assertNull(PreflightFixtures::find(
            Preflight::run_with(PreflightFixtures::env(array('plugins_dir' => $clean)))['warnings'],
            'official_debug_log_present'
        ));
    }

    public function test_third_party_references_lists_unknown_option_names_only(): void {
        $db = PreflightFixtures::db(array('option_value LIKE' => array(
            'woocommerce_upayments_settings',
            'woocommerce_your_gateway_id_settings',
            'upayments_token_identity_secret_v2',
            'upayments_payment_methods_rate_gate_live',
            'external_updates-upayments-V2.2.1',
            'upayments_block_checkout_page_id',
            'supcheckout_takeover_journal_v1',
            'active_plugins',
            'cron',
            'woocommerce_gateway_order',
            'wcml_payment_gateways',
        )));
        $report = Preflight::run_with(PreflightFixtures::env(array('db' => $db)));

        $warning = PreflightFixtures::find($report['warnings'], 'third_party_references');
        self::assertNotNull($warning);
        self::assertSame(array('wcml_payment_gateways'), $warning['data']['unknown']);
        self::assertSame(array('woocommerce_gateway_order'), $warning['data']['translator_handled']);
        self::assertFalse($warning['data']['truncated']);
        self::assertNull(PreflightFixtures::find($report['facts'], 'third_party_references'));

        $query = $db->executed_matching('option_value LIKE')[0];
        self::assertSame('get_col', $query['method']);
        self::assertSame(array('wp_options', '%upayments%', '%upayments%', '\\_transient\\_%', '\\_site\\_transient\\_%', 500), $query['args']);

        $core_only = Preflight::run_with(PreflightFixtures::env(array(
            'db' => PreflightFixtures::db(array('option_value LIKE' => array('woocommerce_default_gateway'))),
        )));
        $fact = PreflightFixtures::find($core_only['facts'], 'third_party_references');
        self::assertNotNull($fact);
        self::assertSame(array('woocommerce_default_gateway'), $fact['data']['translator_handled']);
        self::assertNull(PreflightFixtures::find($core_only['warnings'], 'third_party_references'));

        $many = array();
        for ($i = 0; $i < 500; $i++) {
            $many[] = sprintf('plugin_x_%03d', $i);
        }
        $truncated = Preflight::run_with(PreflightFixtures::env(array(
            'db' => PreflightFixtures::db(array('option_value LIKE' => $many)),
        )));
        $truncated_warning = PreflightFixtures::find($truncated['warnings'], 'third_party_references');
        self::assertTrue($truncated_warning['data']['truncated']);
        self::assertCount(100, $truncated_warning['data']['unknown']);
    }

    public function test_callback_unreachable_blocks_when_the_loopback_probe_is_not_200(): void {
        $urls = array();
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) use (&$urls) {
                $urls[] = $url;
                return array('status' => 404, 'headers' => array());
            },
        )));
        $blocking = PreflightFixtures::find($report['blocking'], 'callback_unreachable');
        self::assertNotNull($blocking);
        self::assertSame(404, $blocking['data']['status']);
        self::assertSame(array(PreflightFixtures::PROBE_URL), $urls, 'one probe; no second request after a failure');

        $transport_error = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) {
                return null;
            },
        )));
        self::assertSame(0, PreflightFixtures::find($transport_error['blocking'], 'callback_unreachable')['data']['status']);

        // Until Task 8 registers the listener, WooCommerce answers wc-api=supcheckout
        // itself with status_header(400) and "-1": blocking, as a real callback would be lost.
        $no_listener = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) {
                return array('status' => 400, 'headers' => array());
            },
        )));
        self::assertSame(400, PreflightFixtures::find($no_listener['blocking'], 'callback_unreachable')['data']['status']);

        // Task 8's listener: a no-op 200 with its acknowledgement header.
        $listener = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) {
                return array('status' => 200, 'headers' => array(Preflight::PROBE_ACK_HEADER => '1', 'cache-control' => 'no-store'));
            },
        )));
        self::assertNull(PreflightFixtures::find($listener['blocking'], 'callback_unreachable'));

        // Task 8 tightening: a 200 without the acknowledgement did not come from the
        // listener (a cached page, a maintenance or login page answering 200, a host
        // rewriting every request): the callback route is not proven reachable.
        foreach (array(array(), array(Preflight::PROBE_ACK_HEADER => '0'), array(Preflight::PROBE_ACK_HEADER => '')) as $headers) {
            $unproven = Preflight::run_with(PreflightFixtures::env(array(
                'http_get' => static function ($url) use ($headers) {
                    return array('status' => 200, 'headers' => $headers + array('cache-control' => 'no-store'));
                },
            )));
            $finding = PreflightFixtures::find($unproven['blocking'], 'callback_unreachable');
            self::assertNotNull($finding, 'a 200 without the listener acknowledgement blocks: ' . json_encode($headers));
            self::assertSame(200, $finding['data']['status']);
            self::assertFalse($finding['data']['acknowledged']);
        }
        self::assertFalse(PreflightFixtures::find($no_listener['blocking'], 'callback_unreachable')['data']['acknowledged']);
        self::assertSame(LegacyCallbackListener::PROBE_ARG, Preflight::PROBE_ARG, 'Preflight probes the argument the listener answers');
        self::assertSame(LegacyCallbackListener::PROBE_ACK_HEADER, Preflight::PROBE_ACK_HEADER, 'Preflight requires the header the listener sends');

        $unacked = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) {
                return array('status' => 302, 'headers' => array('location' => 'https://shop.example/login'));
            },
        )));
        self::assertNotNull(PreflightFixtures::find($unacked['blocking'], 'callback_unreachable'), 'a redirect is not reachable');
    }

    public function test_cache_exclusion_missing_warns_when_a_probe_response_was_served_from_cache(): void {
        $responses = array(
            array('status' => 200, 'headers' => array('x-litespeed-cache' => 'miss')),
            array('status' => 200, 'headers' => array('x-litespeed-cache' => 'hit', 'cf-cache-status' => 'HIT', 'age' => '12')),
        );
        $calls = 0;
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) use (&$responses, &$calls) {
                ++$calls;
                return array_shift($responses);
            },
        )));
        self::assertSame(2, $calls, 'a first miss can be cached: the probe is repeated once');
        $warning = PreflightFixtures::find($report['warnings'], 'cache_exclusion_missing');
        self::assertNotNull($warning);
        self::assertSame(array('age', 'cf-cache-status', 'x-litespeed-cache'), $warning['data']['headers']);

        $uncached = Preflight::run_with(PreflightFixtures::env(array(
            'http_get' => static function ($url) {
                return array('status' => 200, 'headers' => array('cf-cache-status' => 'DYNAMIC', 'x-litespeed-cache' => 'miss', 'x-cache' => 'MISS', 'age' => '0'));
            },
        )));
        self::assertNull(PreflightFixtures::find($uncached['warnings'], 'cache_exclusion_missing'));
    }

    public function test_settings_source_reports_which_settings_option_holds_the_settings(): void {
        // Built from the synthetic fixture so the source holds no new key-shaped literal (gitleaks).
        $placeholder_key = 'test-api-key-123' . '-placeholder';
        $seen = array();
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'option_values' => array(
                'woocommerce_upayments_settings' => array(),
                'woocommerce_your_gateway_id_settings' => array('api_key' => $placeholder_key, 'test_mode' => 'no'),
            ),
            'availability' => static function (array $settings) use (&$seen) {
                $seen[] = $settings;
                return PreflightFixtures::canonical_success();
            },
        )));
        $fact = PreflightFixtures::find($report['facts'], 'settings_source');
        self::assertSame(array(
            'source' => 'woocommerce_your_gateway_id_settings',
            'sources_present' => array('woocommerce_your_gateway_id_settings'),
            'target_present' => false,
        ), $fact['data']);
        self::assertSame($placeholder_key, $seen[0]['api_key'], 'hazard 3: the 3.0.0 placeholder option is the source');
        self::assertStringNotContainsString($placeholder_key, (string) json_encode($report));

        $both = PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env(array(
            'option_values' => array(
                'woocommerce_upayments_settings' => array('api_key' => 'a'),
                'woocommerce_your_gateway_id_settings' => array('api_key' => 'b'),
                Keys::SETTINGS_OPTION => array('api_key' => 'a'),
            ),
        )))['facts'], 'settings_source');
        self::assertSame('woocommerce_upayments_settings', $both['data']['source']);
        self::assertTrue($both['data']['target_present']);

        $none = Preflight::run_with(PreflightFixtures::env(array('option_values' => array())));
        self::assertNull(PreflightFixtures::find($none['facts'], 'settings_source')['data']['source']);
        self::assertSame('missing', PreflightFixtures::find($none['blocking'], 'credential_unverified')['data']['reason']);
    }

    /**
     * A5-04: a `settings_diverged` rollback keeps woocommerce_supcheckout_settings. The next
     * switch copied the whole store and deactivated the official plugin before Cutover's
     * SettingsCopier refused the kept, foreign target (`settings_mismatch`), every time.
     * Preflight now runs the copier's plan read-only and blocks before anything changes.
     */
    public function test_a5_04_a_kept_foreign_settings_target_blocks_preflight_with_a_hint(): void {
        // Cycle 1 copied A; the merchant edited the copy to B after cutover; the legacy source is C.
        $copied = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'A');
        Journal::record('option', Keys::SETTINGS_OPTION, null, $copied, 'option:woocommerce_upayments_settings');
        $kept = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'B');
        $source = array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'C');
        $options_before = $GLOBALS['supcheckout_test_options'];

        $report = Preflight::run_with(PreflightFixtures::env(array('option_values' => array(
            'woocommerce_upayments_settings' => $source,
            Keys::SETTINGS_OPTION => $kept,
        ))));

        $blocking = PreflightFixtures::find($report['blocking'], 'settings_target_foreign');
        self::assertNotNull($blocking, (string) json_encode($report));
        self::assertSame(array('targets' => array(Keys::SETTINGS_OPTION), 'journal_unreadable' => false), $blocking['data']);
        self::assertStringContainsString(Keys::SETTINGS_OPTION, $blocking['message']);
        self::assertStringContainsString('delete', $blocking['message'], 'the hint names the way out');
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode($report), 'names only, never values');
        self::assertSame($options_before, $GLOBALS['supcheckout_test_options'], 'read-only: nothing written');

        // Control: the kept target is still the takeover's own copy, which Cutover overwrites.
        $own = Preflight::run_with(PreflightFixtures::env(array('option_values' => array(
            'woocommerce_upayments_settings' => $source,
            Keys::SETTINGS_OPTION => $copied,
        ))));
        self::assertSame(array(), $own['blocking'], (string) json_encode($own['blocking']));
    }

    public function test_a5_04_a_foreign_copied_option_target_also_blocks_preflight(): void {
        $report = Preflight::run_with(PreflightFixtures::env(array('option_values' => array(
            'woocommerce_upayments_settings' => array('enabled' => 'yes', 'api_key' => 'test-api-key-123'),
            'upayments_token_identity_secret_v2' => hash('sha256', 'legacy'),
            'supcheckout_token_identity_secret_v2' => hash('sha256', 'other'),
        ))));

        $blocking = PreflightFixtures::find($report['blocking'], 'settings_target_foreign');
        self::assertNotNull($blocking);
        self::assertSame(array('supcheckout_token_identity_secret_v2'), $blocking['data']['targets']);
        self::assertStringNotContainsString(hash('sha256', 'other'), (string) json_encode($report));
    }

    public function test_a5_04_an_unreadable_journal_blocks_preflight_fail_closed(): void {
        $GLOBALS['supcheckout_test_options'][Journal::OPTION] = 'not a journal';
        Journal::flush_cache();

        $report = Preflight::run_with(PreflightFixtures::env(array('option_values' => array(
            'woocommerce_upayments_settings' => array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'C'),
            Keys::SETTINGS_OPTION => array('enabled' => 'yes', 'title' => 'B'),
        ))));

        $blocking = PreflightFixtures::find($report['blocking'], 'settings_target_foreign');
        self::assertNotNull($blocking);
        self::assertSame(array('targets' => array(), 'journal_unreadable' => true), $blocking['data']);
    }

    /**
     * Review round 1, I-2: after a full rollback every target is absent, so the copier's plan
     * never reads the journal; Cutover's Journal::record() would still fail on it.
     */
    public function test_a5_04_an_unreadable_journal_blocks_preflight_even_with_every_target_absent(): void {
        $GLOBALS['supcheckout_test_options'][Journal::OPTION] = 'not a journal';
        Journal::flush_cache();

        $report = Preflight::run_with(PreflightFixtures::env());

        $blocking = PreflightFixtures::find($report['blocking'], 'settings_target_foreign');
        self::assertNotNull($blocking, (string) json_encode($report['blocking']));
        self::assertSame(array('targets' => array(), 'journal_unreadable' => true), $blocking['data']);
    }

    public function test_a5_04_the_settings_target_check_is_skipped_once_the_store_is_cut_over(): void {
        foreach (array(TakeoverState::PREFLIGHT_OK, TakeoverState::COPYING, TakeoverState::VERIFIED, TakeoverState::CUT_OVER) as $to) {
            self::assertTrue(TakeoverState::transition($to), $to);
        }
        // After cutover the merchant edits the SUPCheckout settings: expected, not foreign.
        $report = Preflight::run_with(PreflightFixtures::env(array('option_values' => array(
            'woocommerce_upayments_settings' => array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'C'),
            Keys::SETTINGS_OPTION => array('enabled' => 'yes', 'api_key' => 'test-api-key-123', 'title' => 'Edited'),
        ))));

        self::assertNull(PreflightFixtures::find($report['blocking'], 'settings_target_foreign'));
    }

    /**
     * A5-03 (verify-T ruling: record, plus a disclosure): the copy, a rollback's removal and
     * Finalize each save every UPayments order once, and WooCommerce reports every save as an
     * order update (order.updated webhooks, analytics imports). Preflight says so up front.
     */
    public function test_a5_03_orders_to_copy_discloses_that_every_order_save_reports_an_order_update(): void {
        $db = PreflightFixtures::db(array('AS orders_to_copy' => '42'));
        $report = Preflight::run_with(PreflightFixtures::env(array('db' => $db)));

        $fact = PreflightFixtures::find($report['facts'], 'orders_to_copy');
        self::assertNotNull($fact, (string) json_encode($report['facts']));
        self::assertSame(array('count' => 42, 'updates_orders' => true), $fact['data']);
        self::assertStringContainsString('42', $fact['message']);
        self::assertStringContainsString('webhooks', $fact['message']);
        $query = $db->executed_matching('AS orders_to_copy')[0];
        self::assertStringContainsString('_payment_method', $query['query']);
        self::assertSame(array_merge(array('wp_posts', 'shop_order', 'wp_postmeta'), Keys::gateway_ids(), array_keys(Keys::order_meta())), $query['args']);

        $hpos_db = PreflightFixtures::db();
        $none = Preflight::run_with(PreflightFixtures::env(array('db' => $hpos_db, 'hpos' => true)));
        self::assertNull(PreflightFixtures::find($none['facts'], 'orders_to_copy'), 'no UPayments order, no fact');
        $hpos_query = $hpos_db->executed_matching('AS orders_to_copy')[0];
        self::assertStringContainsString('o.payment_method IN', $hpos_query['query']);
        self::assertSame(array_merge(array('wp_wc_orders', 'shop_order'), Keys::gateway_ids(), array('wp_wc_orders_meta'), array_keys(Keys::order_meta())), $hpos_query['args']);
    }

    public function test_official_only_names_reports_existing_official_options_transients_and_cron_hooks(): void {
        $db = PreflightFixtures::db(array('option_name IN (' => array(
            'your_gateway_version',
            'external_updates-upayments-V2.2.1',
            '_transient_upayments_cron_lock',
        )));
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'db' => $db,
            'option_values' => PreflightFixtures::minimal()['option_values'] + array('cron' => array(
                1800000100 => array('upay_hourly_cron_job' => array(), 'wp_version_check' => array()),
                1800000200 => array('puc_cron_check_updates-upayments-V2.2.1' => array()),
                'version' => 2,
            )),
        )));

        $fact = PreflightFixtures::find($report['facts'], 'official_only_names');
        self::assertNotNull($fact);
        self::assertSame(array(
            'options' => array('external_updates-upayments-V2.2.1', 'your_gateway_version'),
            'transients' => array('upayments_cron_lock'),
            'cron_hooks' => array('puc_cron_check_updates-upayments-V2.2.1', 'upay_hourly_cron_job'),
        ), $fact['data']);

        $query = $db->executed_matching('option_name IN (')[0];
        $official = Keys::official_only();
        $expected_args = array_merge(
            array('wp_options'),
            $official['options'],
            array('_transient_upayments_cron_lock', '_transient_timeout_upayments_cron_lock'),
            array('external\\_updates-upayments%')
        );
        self::assertSame($expected_args, $query['args']);

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['facts'], 'official_only_names'));
    }

    public function test_official_only_transients_held_in_an_external_object_cache_are_reported(): void {
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'object_cache_transient' => static function ($name) {
                return $name === 'upayments_cron_lock';
            },
        )));
        $fact = PreflightFixtures::find($report['facts'], 'official_only_names');
        self::assertSame(array('upayments_cron_lock'), $fact['data']['transients']);
    }

    public function test_upay_subscriptions_table_present_is_reported_and_never_touched(): void {
        $db = PreflightFixtures::db(array('SHOW TABLES LIKE' => 'wp_upay_subscriptions'));
        $report = Preflight::run_with(PreflightFixtures::env(array('db' => $db)));
        $fact = PreflightFixtures::find($report['facts'], 'upay_subscriptions_table_present');
        self::assertNotNull($fact);
        self::assertSame(array('table' => 'wp_upay_subscriptions'), $fact['data']);
        self::assertSame(array('wp\\_upay\\_subscriptions'), $db->executed_matching('SHOW TABLES LIKE')[0]['args']);

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['facts'], 'upay_subscriptions_table_present'));
    }

    public function test_leftovers_reports_block_checkout_page_phone_field_update_checker_and_cron_lock(): void {
        $db = PreflightFixtures::db(array('option_name IN (' => array(
            'external_updates-upayments-V2.2.1',
            '_transient_timeout_upayments_cron_lock',
        )));
        $report = Preflight::run_with(PreflightFixtures::env(array(
            'db' => $db,
            'option_values' => PreflightFixtures::minimal()['option_values'] + array(
                'upayments_block_checkout_page_id' => '42',
                'woocommerce_checkout_page_id' => 42,
                'woocommerce_checkout_phone_field' => 'required',
            ),
            'post_status' => static function ($id) {
                return $id === 42 ? 'publish' : null;
            },
        )));

        $fact = PreflightFixtures::find($report['facts'], 'leftovers');
        self::assertNotNull($fact);
        self::assertSame(array(
            'block_checkout_page' => array('page_id' => 42, 'status' => 'publish', 'is_checkout_page' => true),
            'phone_field_required' => true,
            'update_checker' => true,
            'cron_lock' => true,
        ), $fact['data']);

        self::assertNull(PreflightFixtures::find(Preflight::run_with(PreflightFixtures::env())['facts'], 'leftovers'));
    }

    public function test_environment_refuses_every_statement_that_is_not_a_plain_select_or_show(): void {
        $env = PreflightFixtures::env();
        $refused = array(
            'UPDATE %i SET option_value = %s',
            'DELETE FROM %i',
            'INSERT INTO %i VALUES (%s)',
            'REPLACE INTO %i VALUES (%s)',
            'SELECT option_name FROM %i FOR UPDATE',
            'SELECT option_name FROM %i LOCK IN SHARE MODE',
            'SELECT option_name INTO OUTFILE %s FROM %i',
            'SELECT GET_LOCK(%s, 0)',
            'SELECT 1; DROP TABLE %i',
            'CREATE TABLE %i (id int)',
            'SELECT option_name FROM %i FOR SHARE',
            'SELECT option_name FROM %i FOR /**/ UPDATE',
            'SELECT 1 /*! FOR UPDATE */ FROM %i',
            'SELECT 1 FROM %i -- x',
            'SELECT 1 FROM %i # x',
            'SELECT SLEEP(5)',
            'SELECT BENCHMARK(1000, MD5(1))',
            'SELECT LOAD_FILE(%s)',
            'SELECT @a := 1 FROM %i',
            'SELECT RELEASE_ALL_LOCKS()',
        );
        foreach ($refused as $query) {
            try {
                $env->select_var($query, array('wp_options', 'x'));
                self::fail('not refused: ' . $query);
            } catch (\LogicException $e) {
                self::assertStringContainsString('read-only', $e->getMessage());
            }
        }
        self::assertSame('0', $env->select_var('SELECT COUNT(*) FROM (SELECT 1) AS multi', array()));
    }

    public function test_environment_rejects_unknown_or_missing_fields(): void {
        $fields = PreflightFixtures::minimal();
        unset($fields['option_values']);
        $fields['options'] = static function ($name, $default) {
            return $default;
        };

        $missing = $fields;
        unset($missing['http_get']);
        try {
            new Environment($missing);
            self::fail('missing field accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('http_get', $e->getMessage());
        }

        try {
            new Environment($fields + array('update_option' => 'update_option'));
            self::fail('unknown field accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('update_option', $e->getMessage());
        }
    }

    public function test_preflight_and_environment_source_contain_no_write_or_hook_calls(): void {
        $forbidden = array(
            'update_option', 'add_option', 'delete_option', 'update_site_option', 'add_site_option', 'delete_site_option',
            'set_transient', 'delete_transient', 'get_transient', 'set_site_transient', 'delete_site_transient', 'get_site_transient',
            'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_user_meta', 'add_user_meta', 'delete_user_meta',
            'update_metadata', 'add_metadata', 'delete_metadata', 'wp_insert_post', 'wp_update_post', 'wp_delete_post',
            'update_meta_data', 'add_meta_data', 'delete_meta_data', 'save', 'save_meta_data', 'set_status',
            'wp_schedule_event', 'wp_schedule_single_event', 'wp_clear_scheduled_hook', 'wp_unschedule_event',
            'as_schedule_single_action', 'as_schedule_recurring_action', 'as_enqueue_async_action', 'as_unschedule_all_actions',
            'query', 'insert', 'replace', 'update', 'delete', 'dbDelta',
            'wp_remote_post', 'wp_remote_request', 'wp_safe_remote_post',
            'wp_cache_set', 'wp_cache_add', 'wp_cache_delete', 'wp_cache_flush',
            'add_action', 'add_filter', 'remove_action', 'remove_filter', 'do_action',
            'file_put_contents', 'fopen', 'unlink', 'rename', 'mkdir', 'touch', 'readfile', 'fpassthru',
            'deactivate_plugins', 'activate_plugin',
        );
        $root = dirname(__DIR__, 3);
        foreach (array('src/Takeover/Preflight.php', 'src/Takeover/Environment.php', 'src/Takeover/OfficialFootprint.php') as $file) {
            self::assertFileExists($root . '/' . $file);
            $tokens = token_get_all((string) file_get_contents($root . '/' . $file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                    continue;
                }
                $next = $i + 1;
                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }
                if ($next < $count && $tokens[$next] === '(') {
                    self::assertNotContains(strtolower($tokens[$i][1]), $forbidden, $file . ':' . $tokens[$i][2] . ' calls ' . $tokens[$i][1] . '()');
                }
            }
        }
    }

    public function test_run_through_the_wordpress_binding_writes_nothing(): void {
        // Same WooCommerce parent stand-in as DualReadTest::gateway_instance().
        if (!class_exists('WC_Payment_Gateway', false)) {
            eval('class WC_Payment_Gateway { public $id = ""; }');
        }
        \supcheckout_test_reset_availability();
        \supcheckout_test_reset_subscription_presentation();
        $GLOBALS['supcheckout_test_subscription_presentation']['wc'] = new class() {
            public function api_request_url($request) {
                return 'https://shop.example/wc-api/' . $request . '/';
            }
        };
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('api_key' => 'test-api-key-123', 'test_mode' => 'yes');
        $GLOBALS['wpdb'] = PreflightFixtures::db();
        \supcheckout_test_reset_wp_http();
        // The listener's acknowledgement (LegacyCallbackListener::answer_probe()).
        $GLOBALS['supcheckout_test_http_response'] = array(
            'response' => array('code' => 200),
            'headers' => array('X-SUPCheckout-Probe' => '1', 'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private'),
            'body' => '',
        );
        $options_before = $GLOBALS['supcheckout_test_options'];

        $report = Preflight::run();

        $urls = array_column($GLOBALS['supcheckout_test_http_calls'], 'url');
        self::assertSame(array(
            'https://sandboxapi.upayments.com/api/v1/check-payment-button-status',
            'https://shop.example/wc-api/supcheckout/?supcheckout_probe=1',
            'https://shop.example/wc-api/supcheckout/?supcheckout_probe=1',
        ), $urls, 'one read-only provider GET, then the loopback probe and its repeat');
        $provider = $GLOBALS['supcheckout_test_http_calls'][0]['args'];
        self::assertSame('GET', $provider['method'], 'the gateway transport, GET');
        self::assertSame('Bearer test-api-key-123', $provider['headers']['Authorization']);
        self::assertSame('SandboxUpaymentsWoocommercePlugin/2.2.1', $provider['user-agent']);
        self::assertSame(0, $provider['redirection']);
        self::assertSame(0, $GLOBALS['supcheckout_test_http_calls'][1]['args']['redirection']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_http_calls'][1]['args']['cookies']);
        self::assertTrue($GLOBALS['supcheckout_test_http_calls'][1]['args']['sslverify'], 'UPayments verifies TLS; so does the probe');
        // The one stubbed HTTP response (the probe acknowledgement: 200, empty body) also
        // answers the provider GET, and an empty body is an outage, not a refusal.
        $credential = PreflightFixtures::find($report['blocking'], 'credential_unverified');
        self::assertSame('provider_unavailable', $credential['data']['reason']);
        self::assertSame(200, $credential['data']['http_status']);
        self::assertNull(PreflightFixtures::find($report['blocking'], 'callback_unreachable'));

        self::assertSame(array(), $GLOBALS['supcheckout_test_option_calls'], 'no option write');
        self::assertSame($options_before, $GLOBALS['supcheckout_test_options']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_availability']['transient_ttls'], 'no transient write');
        self::assertSame(0, $GLOBALS['supcheckout_test_availability']['lock_acquires'], 'no advisory lock');
        foreach ($GLOBALS['wpdb']->executed as $statement) {
            self::assertMatchesRegularExpression('/^\s*(SELECT|SHOW)\s/i', $statement['query']);
        }
        foreach ($GLOBALS['supcheckout_test_http_calls'] as $call) {
            self::assertArrayNotHasKey('body', $call['args'], 'GET only');
        }
        self::assertStringNotContainsString('test-api-key-123', (string) json_encode($report));
    }

    /**
     * @param array<string, string> $plugins
     * @return string
     */
    private function plugins(array $plugins) {
        $dir = PreflightFixtures::plugins_dir($plugins);
        $this->dirs[] = $dir;
        return $dir;
    }

    /**
     * A WC_Order stand-in whose get_meta($key, false) returns WC_Meta_Data-shaped rows.
     *
     * @param array<string, list<string>> $meta
     * @return object
     */
    private static function order($id, array $meta) {
        return new class($id, $meta) {
            /** @var int */
            private $id;
            /** @var array<string, list<string>> */
            private $meta;

            public function __construct($id, array $meta) {
                $this->id = $id;
                $this->meta = $meta;
            }

            public function get_id() {
                return $this->id;
            }

            public function get_meta($key, $single = true) {
                $rows = isset($this->meta[$key]) ? $this->meta[$key] : array();
                if ($single) {
                    return $rows === array() ? '' : $rows[0];
                }
                return array_map(static function ($value) use ($key) {
                    return (object) array('id' => 0, 'key' => $key, 'value' => $value);
                }, $rows);
            }
        };
    }
}
