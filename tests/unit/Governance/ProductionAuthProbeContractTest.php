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
