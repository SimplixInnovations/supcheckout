<?php

namespace Simplixi\SUPCheckout\Tests\Governance;

use PHPUnit\Framework\TestCase;

final class CurrentProjectStateRegressionTest extends TestCase {
    private const T3_MERGED_MAIN_SHA = 'a7a8bbfc3a1dc551127b7ead897c964e95c7cec9';
    private const T2_RUNTIME_BEARING_MAIN_SHA = '047cc86060efb97761d7a0cc4a3806f971ab6fe1';
    private const POST_T3_MILESTONE_MAIN_SHA = 'd69377d3e26831270a00151025d24cc64be9d36b';
    private const POST_T3_E3_RUNTIME_CHECKPOINT_SHA = '540b733c29656758f2392817649fc3d4a4db585d';
    private const POST_T3_E3_PACKAGE_SHA256 = '01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c';

    /**
     * Canonical living records that a fresh chat/agent may use to establish the
     * current Approach 3 coordinate.
     *
     * @return array<int, string>
     */
    private static function living_state_files(): array {
        return array(
            'AGENTS.md',
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/OWNER-HANDOFF.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/superpowers/plans/2026-09-10-approach-3-t03-legacy-direct-characterization.md',
            '.ai-architect/implementation-plan.md',
            '.ai-architect/architecture-contract.yaml',
        );
    }

    public function test_living_control_plane_records_the_t3_merged_main_coordinate(): void {
        foreach (self::living_state_files() as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(
                self::T3_MERGED_MAIN_SHA,
                $content,
                $path . ' must record the current merged T3 main coordinate'
            );
        }
    }

    public function test_start_here_records_post_t3_as_the_current_successor(): void {
        $content = self::read_repository_file('docs/project/START-HERE.md');

        self::assertStringContainsString('post-t3-ecosystem-hardening', $content);
        self::assertStringContainsString('T3', $content);
        self::assertStringNotContainsString('Continue Approach 3 from verified T2', $content);
        self::assertStringNotContainsString('Approach 3 post-T2 evidence review / direct legacy-method characterization', $content);
    }

    public function test_living_control_plane_records_post_t3_milestone_merge_and_no_deleted_branch_as_active(): void {
        $paths = array(
            'AGENTS.md',
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/OWNER-HANDOFF.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            '.ai-architect/implementation-plan.md',
            '.ai-architect/architecture-contract.yaml',
        );

        foreach ($paths as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(self::POST_T3_MILESTONE_MAIN_SHA, $content, $path);
            self::assertStringNotContainsString('active draft PR #108', strtolower($content), $path);
            self::assertStringNotContainsString('Active integration: draft PR #108', $content, $path);
        }

        foreach (array(
            'AGENTS.md',
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/OWNER-HANDOFF.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            '.ai-architect/implementation-plan.md',
        ) as $path) {
            $content = self::read_repository_file($path);
            self::assertStringNotContainsString('audit/post-t3-ecosystem-hardening', $content, $path);
        }
    }

    public function test_agent_instructions_do_not_send_new_sessions_back_to_pre_t3_work(): void {
        $content = self::read_repository_file('AGENTS.md');

        self::assertStringContainsString('post-t3-ecosystem-hardening', $content);
        self::assertStringNotContainsString(
            'The next substantive action is direct characterization of the legacy return/webhook/private verification surfaces before any further consolidation.',
            $content
        );
    }

    public function test_t3_plan_is_closed_as_verified_runtime_neutral_work(): void {
        $content = self::read_repository_file(
            'docs/superpowers/plans/2026-09-10-approach-3-t03-legacy-direct-characterization.md'
        );

        self::assertStringContainsString('**Status:** DONE / VERIFIED — RUNTIME-NEUTRAL', $content);
        self::assertStringNotContainsString('**Status:** IN PROGRESS', $content);
    }

    public function test_post_t3_plan_distinguishes_merged_t3_from_runtime_bearing_t2(): void {
        $content = self::read_repository_file(
            'docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md'
        );

        self::assertStringContainsString(self::T3_MERGED_MAIN_SHA, $content);
        self::assertStringContainsString(self::T2_RUNTIME_BEARING_MAIN_SHA, $content);
        self::assertStringNotContainsString(
            'Record T3 merge `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9` as current runtime-bearing main.',
            $content
        );
    }

    public function test_e3_runtime_package_evidence_is_exact_in_living_records(): void {
        $expected = array(
            'AGENTS.md' => 'Its deterministic package is 55 files / SHA-256 `' . self::POST_T3_E3_PACKAGE_SHA256 . '`.',
            'docs/project/START-HERE.md' => 'Its deterministic package is 55 files / SHA-256 `' . self::POST_T3_E3_PACKAGE_SHA256 . '`.',
            'docs/project/PROJECT-STATUS.md' => '- deterministic installable package — **55 files**, SHA-256 `' . self::POST_T3_E3_PACKAGE_SHA256 . '`.',
            'docs/project/OWNER-HANDOFF.md' => 'its deterministic 55-file candidate package SHA-256 is `' . self::POST_T3_E3_PACKAGE_SHA256 . '`.',
            'docs/project/NEW-CHAT-HANDOFF.md' => '- deterministic package — 55 files / SHA-256 `' . self::POST_T3_E3_PACKAGE_SHA256 . '`.',
        );

        foreach ($expected as $path => $package_evidence) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(self::POST_T3_E3_RUNTIME_CHECKPOINT_SHA, $content, $path);
            self::assertStringContainsString($package_evidence, $content, $path);
        }
    }

    public function test_current_post_t3_gate_cannot_regress_before_r5_decision(): void {
        $agents = self::read_repository_file('AGENTS.md');
        $start = self::read_repository_file('docs/project/START-HERE.md');
        $status = self::read_repository_file('docs/project/PROJECT-STATUS.md');
        $handoff = self::read_repository_file('docs/project/NEW-CHAT-HANDOFF.md');
        $implementation = self::read_repository_file('.ai-architect/implementation-plan.md');
        $contract = self::read_repository_file('.ai-architect/architecture-contract.yaml');

        self::assertStringContainsString('E3 repository-executable runtime evidence is **DONE / VERIFIED**', $agents);
        self::assertStringContainsString('R2 callback portability/cache safety is **DONE / VERIFIED**', $agents);
        self::assertStringContainsString('R3 subscription safety is **DONE / VERIFIED**', $agents);
        self::assertStringContainsString('R5 callback lifecycle consolidation is **DONE / VERIFIED**', $agents);
        self::assertStringContainsString('.github/workflows/ecosystem-certification.yml', $agents);
        self::assertStringContainsString('Current operational gate | **external certification & release readiness**', $start);
        self::assertStringContainsString('E3 repository-executable | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R2 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R3 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R4 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R5 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('Current executable gate: **external certification & release readiness**.', $status);
        self::assertStringContainsString('Current executable gate: **external certification & release readiness**.', $handoff);
        self::assertStringContainsString('E3 repository-executable exact-head checkpoint: `' . self::POST_T3_E3_RUNTIME_CHECKPOINT_SHA . '`', $implementation);
        self::assertStringContainsString('latest_e3_runtime_checkpoint: ' . self::POST_T3_E3_RUNTIME_CHECKPOINT_SHA, $contract);
        self::assertStringContainsString('e3_repository_status: done_verified', $contract);
        self::assertStringContainsString('r2_status: done_verified', $contract);
        self::assertStringContainsString('r3_status: done_verified', $contract);
        self::assertStringContainsString('r4_status: done_verified', $contract);
        self::assertStringContainsString('r5_status: done_verified', $contract);
        self::assertStringContainsString('r6_status: done_verified', $contract);
        self::assertStringContainsString('current_gate: external_certification_release_readiness', $contract);
        self::assertStringContainsString('approach3_status: owner_accepted', $contract);
        self::assertStringContainsString('owner_acceptance_token: OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $contract);
        self::assertStringContainsString('owner_accepted_approach3_source: 146d65a1c182630c1acc651cacafe30cff5f6b79', $contract);
        self::assertStringContainsString('publication_authorized: false', $contract);
        self::assertSame(1, substr_count($contract, 'owner_accepted_approach3_source:'), 'exactly one owner_accepted_approach3_source');
        self::assertSame(1, substr_count($contract, 'owner_accepted_approach3_package_sha256:'), 'exactly one package sha key');
        self::assertStringContainsString('current_repository_maintenance_main: f7a017124d04ced50ea4dae283fe198301dc6eba', $contract);
        self::assertStringNotContainsString('latest_merged_approach_3_main: 1c95bc94', $contract);
        $scope = self::read_repository_file('docs/project/RELEASE-SCOPE-DECISION.md');
        self::assertStringContainsString('BLOCKED / EXCLUDED FROM FIRST PUBLIC PRODUCTION CLAIM', $scope);
        $gate = self::read_repository_file('docs/project/RELEASE-CANDIDATE-GATE.md');
        self::assertStringContainsString('Mandatory for core one-time payment RC', $gate);
        self::assertStringContainsString('Feature-scoped blockers', $gate);
        self::assertStringContainsString('r5_decision: B', $contract);
        self::assertStringContainsString('r5_certified_head: 6fc225fc736da107de533ba8e19a12dc5c37227d', $contract);
        self::assertStringContainsString('r5_merged_main: 50170ea7f0d17b792e133a70beee48da7e2b6326', $contract);

        self::assertStringNotContainsString('current_gate: r2-callback-portability-cache-safety', $contract);
        self::assertStringNotContainsString('current_gate: r3-subscription-safety', $contract);
        self::assertStringNotContainsString('current_gate: r4-subscription-scalability-observability', $contract);
        self::assertStringNotContainsString('current_gate: r5-callback-lifecycle-consolidation', $contract);
        self::assertStringNotContainsString('current_gate: r6-final-qualification', $contract);
        self::assertStringNotContainsString('current_gate: r6_independent_review', $contract);
        self::assertStringNotContainsString('Current executable gate: **R2 callback portability / cache safety**.', $status);
        self::assertStringNotContainsString('Current executable gate: **E3 theme/cache/CDN/optimizer/analytics compatibility**.', $handoff);
        self::assertStringNotContainsString('Current executable gate: **R3 subscription safety**.', $status);
        self::assertStringNotContainsString('Current executable gate: **R4 subscription scalability/observability**.', $status);
        self::assertStringNotContainsString('Current executable gate: **R5/T4 architecture decision**.', $status);
        self::assertStringNotContainsString('Current executable gate: **R5/T4 architecture decision**.', $handoff);
        self::assertStringNotContainsString('Current executable gate: **R6 final qualification**.', $status);
        self::assertStringNotContainsString('Current executable gate: **R6 final qualification**.', $handoff);
        self::assertStringNotContainsString('Current executable gate: **R6 independent review**.', $status);
        self::assertStringNotContainsString('Current executable gate: **R6 independent review**.', $handoff);
        self::assertStringNotContainsString('current_gate: e3-theme-cache-analytics', $contract);

        // Approach 3 is the current owner-accepted baseline; reject stale Approach-2-as-current claims.
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $agents);
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $status);
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $handoff);
        self::assertStringNotContainsString('ACCEPTED only for the frozen Approach 2', $status);
        self::assertStringNotContainsString('A fresh owner acceptance is required at Approach 3 closeout.', $agents);
    }

    public function test_architecture_contract_records_t3_and_rejects_the_obsolete_t2_next_candidate(): void {
        $content = self::read_repository_file('.ai-architect/architecture-contract.yaml');

        self::assertStringContainsString("  t03:\n", $content);
        self::assertStringContainsString('    status: done_verified', $content);
        self::assertStringContainsString('    merged_main_sha: ' . self::T3_MERGED_MAIN_SHA, $content);
        self::assertStringContainsString('    name: post-t3-ecosystem-hardening', $content);
        self::assertStringNotContainsString('post-t02-evidence-review', $content);
    }

    private static function read_repository_file(string $path): string {
        $root = dirname(__DIR__, 3);
        $full_path = $root . '/' . $path;
        self::assertFileExists($full_path, 'Required living control-plane file is missing: ' . $path);

        $content = file_get_contents($full_path);
        self::assertIsString($content, 'Required living control-plane file is unreadable: ' . $path);

        return $content;
    }
}
