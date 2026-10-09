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
