<?php

namespace Simplixi\SUPCheckout\Tests\Governance;

use PHPUnit\Framework\TestCase;

/**
 * The production-auth account probe runs against a real merchant account, so
 * its guards are part of the payment-safety contract, not a convenience.
 */
final class ProductionAuthProbeContractTest extends TestCase {
    private const PROBE = 'tests/provider/production-auth-account-probe.sh';

    public function test_probe_refuses_without_explicit_owner_authorization(): void {
        $probe = self::read_repository_file(self::PROBE);

        self::assertStringContainsString('OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION', $probe);
        self::assertStringContainsString('RESULT=REFUSED_NOT_AUTHORIZED', $probe);
    }

    public function test_probe_refuses_to_run_in_ci(): void {
        $probe = self::read_repository_file(self::PROBE);

        self::assertStringContainsString('GITHUB_ACTIONS', $probe);
        self::assertStringContainsString('RESULT=REFUSED_IN_CI', $probe);

        foreach (glob(dirname(__DIR__, 3) . '/.github/workflows/*.yml') as $workflow) {
            self::assertStringNotContainsString('production-auth-account-probe', (string) file_get_contents($workflow), basename($workflow));
        }
    }

    public function test_probe_targets_only_the_plugin_production_host_and_status_path(): void {
        $probe = self::read_repository_file(self::PROBE);
        $resolver = self::read_repository_file('src/Provider/EndpointResolver.php');

        self::assertStringContainsString('https://apiv2api.upayments.com/api/v1/', $resolver);
        self::assertStringContainsString('BASE="https://apiv2api.upayments.com/api/v1"', $probe);
        self::assertStringNotContainsString('UPAYMENTS_PRODUCTION_BASE_URL', $probe, 'production host must not be overridable');
        self::assertStringContainsString('get-payment-status/${TRACK_ID}', $probe, 'must use the StatusVerifier track-ID path form');
    }

    public function test_charge_initialization_needs_a_second_confirmation_and_never_follows_the_link(): void {
        $probe = self::read_repository_file(self::PROBE);

        self::assertStringContainsString('CONFIRM_PRODUCTION_CHARGE_INIT', $probe);
        self::assertStringNotContainsString('curl -L', $probe);
        self::assertStringNotContainsString('--location', $probe);
    }

    public function test_charge_reference_respects_the_plugin_35_character_limit(): void {
        $probe = self::read_repository_file(self::PROBE);
        $orchestrator = self::read_repository_file('src/Payment/CheckoutOrchestrator.php');

        self::assertStringContainsString('strlen($reference_id) > 35', $orchestrator);
        self::assertMatchesRegularExpression('/ID="scprobe-\$\(date -u \+%s\)-\$\{RANDOM\}"/', $probe);
        // Worst case: "scprobe-" (8) + 10-digit epoch + "-" + 5-digit RANDOM = 24.
        self::assertStringContainsString('if (( ${#ID} > 35 )); then', $probe, 'probe must refuse an over-long reference');
    }

    public function test_probe_never_prints_credentials_or_payment_identifiers(): void {
        $probe = self::read_repository_file(self::PROBE);

        self::assertDoesNotMatchRegularExpression('/echo[^\n]*\$\{?(API_KEY|TRACK_ID)\b/', $probe);
        self::assertStringContainsString('set +x', $probe);
        self::assertStringContainsString('classification=PRODUCTION_ACCOUNT_AUTH_OBSERVATION', $probe);
    }

    public function test_probe_keeps_secrets_and_identifiers_out_of_argv_and_disk(): void {
        $probe = self::read_repository_file(self::PROBE);

        self::assertStringContainsString('-H @"$WORK/auth.hdr"', $probe, 'Bearer key must reach curl from a file');
        self::assertDoesNotMatchRegularExpression('/curl[^\n]*Bearer/', $probe, 'Bearer key must never be a curl argument');
        self::assertDoesNotMatchRegularExpression('/curl[^\n]*\$\{?TRACK_ID/', $probe, 'track ID must never be a curl argument');
        self::assertStringContainsString('--config -', $probe, 'status URL must reach curl through stdin');
        self::assertStringContainsString("trap 'rm -rf \"\$WORK\"' EXIT", $probe);
        self::assertStringContainsString('umask 077', $probe);
        self::assertDoesNotMatchRegularExpression('/\bcat\b[^\n]*\$WORK/', $probe, 'raw bodies and the key file must never be printed');
        self::assertSame(1, preg_match_all('/^BASE=/m', $probe), 'BASE must be assigned exactly once');
        self::assertStringContainsString("--max-redirs 0 --proto '=https'", $probe);
        self::assertDoesNotMatchRegularExpression('/curl -[A-Za-z]*L/', $probe, 'redirects must never be followed');
    }

    public function test_status_acceptance_requires_a_transaction_bound_to_the_probed_track_id(): void {
        $probe = self::read_repository_file(self::PROBE);
        $verifier = self::read_repository_file('src/Payment/StatusVerifier.php');

        // StatusVerifier refuses a transaction whose track_id differs from the queried one;
        // the probe must not count a 201 for an unknown or foreign track ID as acceptance.
        self::assertStringContainsString("if ((string) \$transaction['track_id'] !== \$track_id) {", $verifier);
        self::assertStringContainsString('PROBE_TRACK_ID="$TRACK_ID"', $probe, 'track ID reaches php through the environment');
        self::assertStringContainsString('(string) $d["data"]["transaction"]["track_id"] === $expected_track', $probe);
        self::assertStringContainsString('$accepted = $http === 201 && $ok && $tx;', $probe);
    }

    public function test_verdict_mapping_matches_the_runbook_table(): void {
        $probe = self::read_repository_file(self::PROBE);
        $runbook = self::read_repository_file('docs/project/PRODUCTION-AUTH-ACCOUNT-PROBE.md');

        self::assertStringContainsString('} elseif ($http === 401 || $http === 403) {', $probe);
        self::assertStringContainsString('$verdict = "INCONCLUSIVE";', $probe);
        self::assertStringContainsString('curl_exit=', $probe, 'transport failures must report their curl exit code');
        foreach (array('BEARER_ONLY_ACCEPTED', 'REJECTED_AUTH', 'INCONCLUSIVE') as $verdict) {
            self::assertStringContainsString('verdict=' . $verdict, $runbook);
        }
    }

    public function test_charge_probe_mirrors_the_plugin_request_shape(): void {
        $probe = self::read_repository_file(self::PROBE);
        $orchestrator = self::read_repository_file('src/Payment/CheckoutOrchestrator.php');
        $gateway = self::read_repository_file('UPayments.php');

        foreach (array('returnUrl', 'cancelUrl', 'notificationUrl', 'products', 'order', 'reference', 'customer', 'plugin', 'is_whitelabled', 'language', 'isSaveCard', 'tokens', 'device', 'extraMerchantData') as $key) {
            self::assertStringContainsString("'" . $key . "'", $orchestrator, 'plugin payload key moved: ' . $key);
            self::assertStringContainsString('"' . $key . '":', $probe, 'probe body lacks plugin key: ' . $key);
        }
        self::assertStringContainsString('"tokens":{"creditCard":null,"customerUniqueToken":null}', $probe);
        self::assertStringNotContainsString('"reference":"', $probe, 'order.reference is not sent by the plugin');
        // The live-mode User-Agent the plugin sends with Charge.
        self::assertStringContainsString("\$userAgent = 'UpaymentsWoocommercePlugin/2.2.1';", $gateway);
        self::assertStringContainsString("-A 'UpaymentsWoocommercePlugin/2.2.1'", $probe);
    }

    public function test_rejected_response_shows_only_scrubbed_provider_validation_text(): void {
        // A 422 is a request-format failure, not an auth verdict; the operator needs
        // the provider's validation text, and nothing else from the body.
        $body = json_encode(array(
            'status' => false,
            'message' => 'The customer.email field is required. See https://pay.upayments.com/x?session=abc',
            'errors' => array(
                'customer.email' => array('The customer.email field is required.'),
                'returnUrl' => 'Invalid url https://example.com/supcheckout-return',
            ),
            'data' => array('link' => 'https://pay.upayments.com/secret-link', 'track_id' => 'TRACKSECRET123'),
        ));
        $out = self::run_summarize('charge', 422, (string) $body, 'TRACKSECRET123');

        self::assertStringContainsString('verdict=INCONCLUSIVE', $out);
        self::assertStringContainsString('provider_message=The customer.email field is required.', $out);
        self::assertStringContainsString('provider_error=customer.email: The customer.email field is required.', $out);
        self::assertStringContainsString('provider_error=returnUrl: Invalid url [url]', $out);
        self::assertStringNotContainsString('https://', $out, 'URLs (payment links) are never printed');
        self::assertStringNotContainsString('secret-link', $out);
        self::assertStringNotContainsString('TRACKSECRET123', $out, 'the probed track ID is never printed');

        $long = json_encode(array('status' => false, 'message' => str_repeat('A', 500)));
        $out = self::run_summarize('charge', 422, (string) $long, 'T1');
        self::assertDoesNotMatchRegularExpression('/A{201}/', $out, 'provider text is capped at 200 characters');

        $accepted = json_encode(array('status' => true, 'data' => array('link' => 'https://pay.upayments.com/l'), 'message' => 'ok'));
        $out = self::run_summarize('charge', 201, (string) $accepted, 'T1');
        self::assertStringContainsString('verdict=BEARER_ONLY_ACCEPTED', $out);
        self::assertStringNotContainsString('provider_message=', $out, 'accepted responses print no provider text');
    }

    /** Runs the probe's own embedded summarize() PHP against a fixture body. */
    private static function run_summarize(string $kind, int $http, string $body, string $track): string {
        $probe = self::read_repository_file(self::PROBE);
        $start = strpos($probe, "php -r '");
        $end = strpos($probe, "  ' \"\$kind\"");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $code = substr($probe, $start + strlen("php -r '"), $end - $start - strlen("php -r '"));

        $dir = sys_get_temp_dir() . '/probe-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/s.php', "<?php\n" . $code);
        file_put_contents($dir . '/b.json', $body);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/s.php') . ' ' . escapeshellarg($kind)
            . ' ' . $http . ' ' . escapeshellarg($dir . '/b.json');
        $env = array('PROBE_TRACK_ID' => $track, 'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot'));
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($proc);
        @unlink($dir . '/s.php');
        @unlink($dir . '/b.json');
        @rmdir($dir);
        return $out;
    }

    public function test_probe_route_resolves_the_gate_only_through_its_written_rule(): void {
        $runbook = self::read_repository_file('docs/project/PRODUCTION-AUTH-ACCOUNT-PROBE.md');
        $gate = self::read_repository_file('docs/project/RELEASE-CANDIDATE-GATE.md');
        $tree = self::read_repository_file('docs/project/UPAYMENTS-CONTRACT-DECISION-TREE.md');

        self::assertStringContainsString('RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY', $runbook);
        self::assertStringContainsString('NEW_RUNTIME_TRANCHE_REQUIRED=HMAC', $runbook);
        self::assertStringContainsString('A written UPayments answer, if one arrives, supersedes this rule.', $runbook);
        self::assertStringContainsString('PRODUCTION-AUTH-ACCOUNT-PROBE.md', $gate);
        self::assertStringContainsString('PRODUCTION-AUTH-ACCOUNT-PROBE.md', $tree);
        // Preparing the route does not resolve the gate.
        self::assertStringContainsString('- [ ] production auth contract resolved for provider API traffic', $gate);
        self::assertStringContainsString('RELEASE CANDIDATE: BLOCKED', $gate);
    }

    private static function read_repository_file(string $path): string {
        $full_path = dirname(__DIR__, 3) . '/' . $path;
        self::assertFileExists($full_path, 'Required file is missing: ' . $path);

        $content = file_get_contents($full_path);
        self::assertIsString($content, 'Required file is unreadable: ' . $path);

        return $content;
    }
}
