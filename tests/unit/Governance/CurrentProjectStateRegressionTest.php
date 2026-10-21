<?php

namespace Simplixi\SUPCheckout\Tests\Governance;

use PHPUnit\Framework\TestCase;

final class CurrentProjectStateRegressionTest extends TestCase {
    private const T3_MERGED_MAIN_SHA = 'a7a8bbfc3a1dc551127b7ead897c964e95c7cec9';
    private const T2_RUNTIME_BEARING_MAIN_SHA = '047cc86060efb97761d7a0cc4a3806f971ab6fe1';
    private const POST_T3_MILESTONE_MAIN_SHA = 'd69377d3e26831270a00151025d24cc64be9d36b';
    private const POST_T3_E3_RUNTIME_CHECKPOINT_SHA = '540b733c29656758f2392817649fc3d4a4db585d';
    private const POST_T3_E3_PACKAGE_SHA256 = '01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c';
    private const RC1_ACCEPTANCE_TOKEN = 'RELEASE_CANDIDATE_1';
    private const RC1_SOURCE_SHA = '8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243';
    private const RC1_PACKAGE_SHA256 = '8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156';
    /**
     * The current executable gate (reconciled 2026-10-21): the hardening program. Owner decision
     * 2026-10-20: the full revamp, branding and information architecture follow only once
     * functionality, security and performance are verified, so a full audit and fix loop comes
     * next. Sub-project 1 is done and the 0c owner staging review is owner-verified.
     */
    private const CURRENT_GATE = '**Hardening program: full functionality, security and performance audit and fix loop — NEXT**';
    /** What follows the hardening program (owner decision 2026-10-20). */
    private const NEXT_AFTER_GATE = 'Next after the hardening program: Sub-project 2 admin screens, once the owner shares the information architecture and branding';
    /** Sub-project 1 status in every living record. */
    private const SUBPROJECT_1_DONE = 'Sub-project 1 design-token system and admin component library — **DONE / VERIFIED**';
    /** The 0c owner staging review passed on the My Organic World staging copy (2026-10-22). */
    private const SUBPROJECT_0C_REVIEW_DONE = 'Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22)';
    /** The superseded 0c review wording: the review is no longer pending. */
    private const SUPERSEDED_0C_REVIEW_PENDING = 'Sub-project 0c owner staging review — **pending owner action**';
    /** The 0c owner staging review evidence record. */
    private const SUBPROJECT_0C_REVIEW_EVIDENCE = 'docs/project/evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md';
    /** The binding owner decision that put the hardening program first. */
    private const OWNER_DECISION_HARDENING = 'Owner decision 2026-10-20';
    /** Sub-project 1 is done; it can never be the next gate again. */
    private const PREMATURE_SUBPROJECT_1_GATE = 'Sub-project 1 design-token system and admin component library — NEXT';
    /** The superseded 0c exit gate (reconciled 2026-10-19, superseded 2026-10-21). */
    private const SUPERSEDED_0C_EXIT_GATE = '**Sub-project 0c exit gate: owner staging review of the onboarding backend — NEXT**';
    /** The superseded 0c gate wording: 0c shipped no UI. */
    private const SUPERSEDED_0C_GATE = 'Sub-project 0c onboarding UI — NEXT';
    /** The release gate stays open until Sub-project 5; it is no longer the current executable gate. */
    private const RELEASE_GATE = 'Release gate (open): **external certification & release readiness**';
    /** The same current gate as a machine identifier (architecture contract). */
    private const CURRENT_GATE_ID = 'hardening_program_audit_fix_loop';
    /** The Sub-project 1 evidence record. */
    private const SUBPROJECT_1_EVIDENCE = 'docs/project/evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md';
    /** Last SP1 runtime-bearing merge (PR #211, the whole-SP1 UI and accessibility hardening). */
    private const SUBPROJECT_1_LAST_MERGE = '453e103bd5dc2c81f35e65520ac29dfa9182fbc5';
    /** Deterministic package at the Sub-project 1 close (built twice, byte-identical). */
    private const SUBPROJECT_1_PACKAGE_SHA256 = '2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4';
    /** The superseded Task 8 package; no living record may still present it as current. */
    private const SUPERSEDED_TASK_8_PACKAGE_SHA256 = '67beb72db5e829af5557a258db11c022312312589c11f156b6ce7665e0ed65ac';
    private const SUPERSEDED_GATE ='Current executable gate: **external certification & release readiness**.';

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

    public function test_start_here_records_external_readiness_as_the_current_successor(): void {
        $content = self::read_repository_file('docs/project/START-HERE.md');

        self::assertStringContainsString('docs/superpowers/plans/2026-09-26-external-certification-release-readiness.md', $content);
        self::assertStringContainsString('Program phase | **SUPCheckout revamp — Hardening program (full functionality, security and performance audit and fix loop)**', $content);
        self::assertStringNotContainsString('Program phase | **SUPCheckout revamp — Sub-project 0c exit gate', $content);
        self::assertStringNotContainsString('Program phase | **SUPCheckout revamp — Sub-project 1', $content);
        self::assertStringNotContainsString('Program phase | **SUPCheckout revamp — Sub-project 0b takeover**', $content);
        self::assertStringNotContainsString('Program phase | **SUPCheckout revamp — Sub-project 0c onboarding UI**', $content);
        self::assertStringNotContainsString('finish the rehearsal', $content);
        self::assertStringContainsString('release program external certification & release readiness stays open', $content);
        self::assertStringNotContainsString('Program phase | **external certification & release readiness** |', $content);
        self::assertStringContainsString('R6 | **DONE / VERIFIED**', $content);
        self::assertStringNotContainsString('Plan: `docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`', $content);
        self::assertStringNotContainsString('Program phase | **Approach 3 post-T3 ecosystem hardening**', $content);
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
        self::assertStringContainsString('Current operational gate | ' . self::CURRENT_GATE, $start);
        self::assertStringContainsString('Release gate | **external certification & release readiness — open**', $start);
        self::assertStringNotContainsString('Current operational gate | **external certification & release readiness** |', $start);
        self::assertStringContainsString('E3 repository-executable | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R2 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R3 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R4 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('R5 | **DONE / VERIFIED**', $start);
        self::assertStringContainsString('Current executable gate: ' . self::CURRENT_GATE, $status);
        self::assertStringContainsString('Current executable gate: ' . self::CURRENT_GATE, $handoff);
        self::assertStringContainsString('| Current executable gate | ' . self::CURRENT_GATE, $status);
        self::assertStringContainsString(self::RELEASE_GATE, $status);
        self::assertStringContainsString(self::RELEASE_GATE, $handoff);
        self::assertStringNotContainsString(self::SUPERSEDED_GATE, $status);
        self::assertStringNotContainsString(self::SUPERSEDED_GATE, $handoff);
        foreach (array('docs/project/START-HERE.md' => $start, 'docs/project/PROJECT-STATUS.md' => $status) as $living => $text) {
            self::assertStringContainsString('Publication remains **NOT AUTHORIZED**', $text, $living);
            self::assertStringContainsString('Automatic recurring `VERIFIED_SUCCESS` remains **FAIL-CLOSED', $text, $living);
        }
        self::assertStringContainsString('E3 repository-executable exact-head checkpoint: `' . self::POST_T3_E3_RUNTIME_CHECKPOINT_SHA . '`', $implementation);
        self::assertStringContainsString('latest_e3_runtime_checkpoint: ' . self::POST_T3_E3_RUNTIME_CHECKPOINT_SHA, $contract);
        self::assertStringContainsString('e3_repository_status: done_verified', $contract);
        self::assertStringContainsString('r2_status: done_verified', $contract);
        self::assertStringContainsString('r3_status: done_verified', $contract);
        self::assertStringContainsString('r4_status: done_verified', $contract);
        self::assertStringContainsString('r5_status: done_verified', $contract);
        self::assertStringContainsString('r6_status: done_verified', $contract);
        self::assertStringContainsString('release_gate: external_certification_release_readiness', $contract);
        self::assertStringContainsString('current_gate: ' . self::CURRENT_GATE_ID, $contract);
        self::assertStringNotContainsString('current_gate: external_certification_release_readiness', $contract);
        self::assertStringContainsString('approach3_status: owner_accepted', $contract);
        self::assertStringContainsString('owner_acceptance_token: OWNER_TECHNICAL_ACCEPTANCE=' . self::RC1_ACCEPTANCE_TOKEN, $contract);
        self::assertStringContainsString('historical_approach3_acceptance_token: OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $contract);
        self::assertStringContainsString('historical_approach3_source: 146d65a1c182630c1acc651cacafe30cff5f6b79', $contract);
        self::assertStringContainsString('publication_authorized: false', $contract);
        self::assertSame(1, substr_count($contract, 'owner_accepted_rc1_source:'), 'exactly one owner_accepted_rc1_source');
        self::assertSame(1, substr_count($contract, 'owner_accepted_rc1_package_sha256:'), 'exactly one package sha key');
        self::assertStringNotContainsString('owner_accepted_approach3_source:', $contract);
        self::assertStringContainsString('external_readiness_integrated_main: 705862bd2138126b53ea428551b326a0fd8961bc', $contract);
        self::assertStringNotContainsString('current_repository_maintenance_main:', $contract);
        self::assertStringNotContainsString('latest_merged_approach_3_main: 1c95bc94', $contract);
        $scope = self::read_repository_file('docs/project/RELEASE-SCOPE-DECISION.md');
        self::assertStringContainsString('BLOCKED / EXCLUDED FROM FIRST PUBLIC PRODUCTION CLAIM', $scope);
        $gate = self::read_repository_file('docs/project/RELEASE-CANDIDATE-GATE.md');
        self::assertStringContainsString('Mandatory for core one-time payment RC', $gate);
        self::assertStringContainsString('Feature-scoped blockers', $gate);
        self::assertStringContainsString('- [x] all permanent CI green on certified repository head', $gate);
        self::assertStringContainsString('- [x] accepted runtime/package provenance understood', $gate);
        self::assertStringContainsString('- [x] public-claims audit complete', $gate);
        self::assertStringContainsString('- [x] release scope explicitly locked', $gate);
        self::assertStringContainsString('- [x] production auth contract resolved for provider API traffic', $gate);
        self::assertStringContainsString('RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY', $gate);
        self::assertStringContainsString('- [ ] owner technical acceptance of a package that contains PR #138', $gate);
        self::assertStringContainsString('- [ ] live one-time payment acceptance complete', $gate);
        $status_hdr = self::read_repository_file('docs/project/PROJECT-STATUS.md');
        self::assertStringContainsString('Historical Approach 2 package', $status_hdr);
        self::assertStringContainsString('external-certification-release-readiness', $status_hdr);
        self::assertStringNotContainsString('Active program | `post-t3-ecosystem-hardening`', $status_hdr);
        $rel = self::read_repository_file('docs/project/RELEASE-ENGINEERING.md');
        self::assertStringContainsString('Current state (authoritative)', $rel);
        self::assertStringContainsString('Release Candidate 1: CURRENT OWNER-ACCEPTED TECHNICAL BASELINE', $rel);
        self::assertStringNotContainsString('Approach 3: CURRENT OWNER-ACCEPTED TECHNICAL BASELINE', $rel);
        $road = self::read_repository_file('docs/ENGINEERING-ROADMAP.md');
        self::assertStringContainsString('Current state (authoritative)', $road);
        $start = self::read_repository_file('docs/project/START-HERE.md');
        self::assertStringContainsString('external-certification-release-readiness', $start);
        self::assertStringContainsString('**Last reconciled:** 2026-10-21', $status_hdr);
        self::assertStringContainsString('Plan: `docs/superpowers/plans/2026-09-26-external-certification-release-readiness.md`', $handoff);
        self::assertStringContainsString('Next release dependency: **fresh owner acceptance of a package containing PR #138**', $handoff);
        // Owner decision 2026-10-08: the account probe is an accepted resolution path, so every
        // living handoff surface must name it instead of implying a provider answer is the only route.
        foreach (array('docs/project/PROJECT-STATUS.md', 'docs/project/OWNER-HANDOFF.md', 'docs/project/NEW-CHAT-HANDOFF.md') as $living) {
            $text = self::read_repository_file($living);
            self::assertStringContainsString('PRODUCTION-AUTH-ACCOUNT-PROBE.md', $text, $living);
            self::assertStringNotContainsString('**UPayments provider-contract clarification**', $text, $living);
        }
        self::assertStringNotContainsString('5. **R6:** immutable exact-head qualification, remaining manual/external evidence, fresh owner re-acceptance and explicit version/publication decision.', $handoff);
        self::assertStringNotContainsString('Immediate R6 boundary', self::read_repository_file('docs/project/NEW-CHAT-HANDOFF.md'));
        // FINAL-4: stale current-looking Approach-2/T1-T2 claims must be quarantined as historical.
        $road = self::read_repository_file('docs/ENGINEERING-ROADMAP.md');
        self::assertStringContainsString('Historical owner/admin/local stage', $road);
        self::assertStringContainsString('Historical only. This section does not describe current repository state.', $road);
        self::assertStringContainsString('At that historical checkpoint, the remaining program was', $road);
        self::assertStringNotContainsString('## Current owner/admin/local stage', $road);
        self::assertStringNotContainsString('The current remaining program is', $road);
        $rel = self::read_repository_file('docs/project/RELEASE-ENGINEERING.md');
        self::assertStringContainsString('Historical release evidence boundary', $rel);
        self::assertStringContainsString('At that checkpoint, the latest runtime-bearing certified baseline was', $rel);
        self::assertStringNotContainsString('## Release evidence boundary', $rel);
        self::assertStringNotContainsString('Current repository maintenance base', $road);
        self::assertStringNotContainsString('Current repository maintenance base', $rel);
        self::assertStringContainsString('External-readiness integrated baseline: 705862bd2138126b53ea428551b326a0fd8961bc', $road);
        self::assertStringContainsString('External-readiness integrated baseline: 705862bd2138126b53ea428551b326a0fd8961bc', $rel);
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

        // Approach 3 stays recorded as the superseded historical acceptance; reject stale Approach-2-as-current claims.
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $agents);
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $status);
        self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3', $handoff);
        self::assertStringNotContainsString('ACCEPTED only for the frozen Approach 2', $status);
        self::assertStringNotContainsString('A fresh owner acceptance is required at Approach 3 closeout.', $agents);
    }

    public function test_release_candidate_1_is_the_current_owner_acceptance_everywhere(): void {
        $living = array(
            'AGENTS.md',
            'docs/COMPATIBILITY.md',
            'docs/ENGINEERING-ROADMAP.md',
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/OWNER-HANDOFF.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/project/RELEASE-ENGINEERING.md',
        );
        foreach ($living as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString('OWNER_TECHNICAL_ACCEPTANCE=' . self::RC1_ACCEPTANCE_TOKEN, $content, $path);
            self::assertStringContainsString(self::RC1_SOURCE_SHA, $content, $path);
            self::assertStringContainsString(self::RC1_PACKAGE_SHA256, $content, $path);
        }

        // No living section may still present Approach 3 as the current acceptance anchor.
        $stale_current_claims = array(
            'Approach 3 is the current owner-accepted technical baseline',
            '| Approach 3 | **DONE / VERIFIED / OWNER ACCEPTED** |',
            '| Frozen accepted baseline | `146d65a1c182630c1acc651cacafe30cff5f6b79` (Approach 3) |',
            'Current executable gate: **external certification & release readiness**. Approach 3 owner acceptance recorded at',
            'the current distributable candidate postdates that accepted package and therefore requires fresh final acceptance',
        );
        foreach ($living as $path) {
            $content = self::read_repository_file($path);
            foreach ($stale_current_claims as $claim) {
                self::assertStringNotContainsString($claim, $content, $path);
            }
        }

        $contract = self::read_repository_file('.ai-architect/architecture-contract.yaml');
        self::assertStringContainsString('owner_accepted_rc1_source: ' . self::RC1_SOURCE_SHA, $contract);
        self::assertStringContainsString('owner_accepted_rc1_package_sha256: ' . self::RC1_PACKAGE_SHA256, $contract);
        self::assertStringContainsString('owner_accepted_rc1_package_files: 62', $contract);
        self::assertStringContainsString('historical_approach3_package_sha256: 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd', $contract);

        $status = self::read_repository_file('docs/project/PROJECT-STATUS.md');
        self::assertStringContainsString('| Release Candidate 1 | **CURRENT OWNER-ACCEPTED TECHNICAL BASELINE** |', $status);
        self::assertStringContainsString('| Approach 3 | **HISTORICAL / SUPERSEDED OWNER-ACCEPTED BASELINE** |', $status);
        self::assertStringNotContainsString('| Approach 3 | **CURRENT OWNER-ACCEPTED TECHNICAL BASELINE** |', $status);

        // Acceptance is technical only: it must not unlock publication or clear provider/live gates.
        $gate = self::read_repository_file('docs/project/RELEASE-CANDIDATE-GATE.md');
        self::assertStringContainsString('RELEASE CANDIDATE: BLOCKED', $gate);
        self::assertStringContainsString('- [x] production auth contract resolved for provider API traffic', $gate);
        self::assertStringContainsString('RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY', $gate);
        self::assertStringContainsString('- [ ] owner technical acceptance of a package that contains PR #138', $gate);
        self::assertStringContainsString('- [ ] live one-time payment acceptance complete', $gate);
        self::assertStringContainsString('publication_authorized: false', $contract);
    }

    public function test_architecture_contract_records_external_readiness_as_current_program(): void {
        $content = self::read_repository_file('.ai-architect/architecture-contract.yaml');

        self::assertStringContainsString("  t03:\n", $content);
        self::assertStringContainsString('    status: done_verified', $content);
        self::assertStringContainsString('    merged_main_sha: ' . self::T3_MERGED_MAIN_SHA, $content);
        self::assertStringContainsString('  external_readiness:', $content);
        self::assertStringContainsString('    name: external-certification-release-readiness', $content);
        self::assertStringContainsString('    plan: docs/superpowers/plans/2026-09-26-external-certification-release-readiness.md', $content);
        self::assertStringContainsString('    release_gate: external_certification_release_readiness', $content);
        self::assertStringContainsString('    next_external_gate: provider_contract_clarification', $content);
        self::assertStringNotContainsString('  next_runtime_candidate:', $content);
        self::assertStringNotContainsString('post-t02-evidence-review', $content);
    }

    public function test_control_plane_records_name_the_same_current_gate(): void {
        $contract = self::read_repository_file('.ai-architect/architecture-contract.yaml');
        self::assertStringContainsString("  revamp:\n", $contract);
        self::assertStringContainsString('    subproject_0a_status: done_verified', $contract);
        self::assertStringContainsString('    subproject_0b_status: tasks_1_12_merged', $contract);
        self::assertStringContainsString('    subproject_0c_plan: docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md', $contract);
        self::assertStringContainsString('    subproject_0c_status: onboarding_backend_tasks_1_10', $contract);
        self::assertStringContainsString('    current_gate: ' . self::CURRENT_GATE_ID, $contract);
        self::assertStringContainsString('    subproject_0c_owner_staging_review: done_owner_verified', $contract);
        self::assertStringContainsString('    subproject_1_plan: docs/superpowers/plans/2026-10-19-subproject-1-design-system.md', $contract);
        self::assertStringContainsString('    subproject_1_status: done_verified', $contract);
        self::assertStringContainsString('    subproject_1_last_merge: ' . self::SUBPROJECT_1_LAST_MERGE, $contract);
        self::assertStringContainsString('    subproject_1_evidence: ' . self::SUBPROJECT_1_EVIDENCE, $contract);
        self::assertStringContainsString('    owner_decision_2026_10_20: hardening_program_before_revamp_ia_and_branding', $contract);
        self::assertStringContainsString('    next: subproject_2_after_owner_ia_and_branding', $contract);
        self::assertStringNotContainsString('next: subproject_1_design_tokens', $contract);
        self::assertStringNotContainsString('current_gate: subproject_0c_exit_owner_staging_review', $contract);
        self::assertSame(1, substr_count($contract, 'current_gate:'), 'the architecture contract names exactly one current gate');

        $implementation = self::read_repository_file('.ai-architect/implementation-plan.md');
        self::assertStringContainsString('## Current program — SUPCheckout revamp', $implementation);
        self::assertStringContainsString(self::RELEASE_GATE, $implementation);
        self::assertStringNotContainsString('Current executable gate: **R6 final qualification**', $implementation);
        self::assertStringNotContainsString('## Current successor — post-T3 ecosystem hardening', $implementation);

        $records = array(
            'docs/project/START-HERE.md' => 'Current operational gate | ',
            'docs/project/PROJECT-STATUS.md' => 'Current executable gate: ',
            'docs/project/NEW-CHAT-HANDOFF.md' => 'Current executable gate: ',
            '.ai-architect/implementation-plan.md' => 'Current executable gate: ',
            'AGENTS.md' => 'Current executable gate: ',
        );
        foreach ($records as $path => $label) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString($label . self::CURRENT_GATE, $content, $path);
            self::assertStringNotContainsString(self::SUPERSEDED_GATE, $content, $path);
            self::assertStringNotContainsString(self::SUPERSEDED_0C_GATE, $content, $path);
            self::assertStringNotContainsString(self::PREMATURE_SUBPROJECT_1_GATE, $content, $path);
            self::assertStringNotContainsString($label . self::SUPERSEDED_0C_EXIT_GATE, $content, $path);
            self::assertStringNotContainsString('Current executable gate: **R6', $content, $path);
            self::assertStringNotContainsString('Current operational gate | **external certification & release readiness** |', $content, $path);
        }
    }

    public function test_the_hardening_program_follows_sub_project_1_in_every_living_record(): void {
        $records = array(
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/project/OWNER-HANDOFF.md',
            '.ai-architect/implementation-plan.md',
        );
        foreach ($records as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(self::CURRENT_GATE, $content, $path);
            self::assertStringContainsString(self::SUBPROJECT_1_DONE, $content, $path);
            self::assertStringContainsString(self::SUBPROJECT_0C_REVIEW_DONE, $content, $path);
            self::assertStringNotContainsString(self::SUPERSEDED_0C_REVIEW_PENDING, $content, $path);
            self::assertStringContainsString(basename(self::SUBPROJECT_0C_REVIEW_EVIDENCE), $content, $path);
            self::assertStringContainsString(self::OWNER_DECISION_HARDENING, $content, $path);
            self::assertStringContainsString(self::NEXT_AFTER_GATE, $content, $path);
            self::assertStringNotContainsString('Next after the 0c exit gate:', $content, $path);
            self::assertStringNotContainsString(self::PREMATURE_SUBPROJECT_1_GATE, $content, $path);
            self::assertStringNotContainsString(self::SUPERSEDED_0C_EXIT_GATE, $content, $path);
        }
        self::assertStringContainsString('Current executable gate: ' . self::CURRENT_GATE, self::read_repository_file('docs/project/OWNER-HANDOFF.md'));

        // AGENTS.md takes the same gate sentence and Sub-project 1 status.
        $agents = self::read_repository_file('AGENTS.md');
        self::assertStringContainsString(self::SUBPROJECT_1_DONE, $agents);
        self::assertStringContainsString(self::SUBPROJECT_0C_REVIEW_DONE, $agents);
        self::assertStringNotContainsString(self::SUPERSEDED_0C_REVIEW_PENDING, $agents);
        self::assertStringContainsString('Admin UI token, contrast, build-contract, accessibility and visual-regression suites', $agents);
        self::assertStringContainsString('.github/workflows/admin-ui-certification.yml', $agents);
    }

    public function test_sub_project_0c_owner_staging_review_is_recorded(): void {
        $evidence = self::read_repository_file(self::SUBPROJECT_0C_REVIEW_EVIDENCE);
        foreach (array(
            '2026-10-22',
            '224 files',
            'f4ce1a3a942295123ec023bb1227197af8f97685a77c0714ada214907f7deec9',
            '2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4',
            'test_payment_ui_driven',
            'Follow-up 3',
            'PASSED',
            'Follow-up 2',
            'CachePurgeRuntimeTest',
            'LiteSpeed Cache 7.9.1',
            'external certification',
            'CAPTURED',
        ) as $fact) {
            self::assertStringContainsString($fact, $evidence, '0c review evidence missing: ' . $fact);
        }

        // The owner acknowledgements are recorded and no pending-acknowledgement wording is left.
        foreach (array(
            'DESIGN.md',
            'docs/project/evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md',
            'docs/project/evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md',
            'docs/project/RELEASE-ENGINEERING.md',
            'docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md',
        ) as $path) {
            $text = self::read_repository_file($path);
            self::assertStringNotContainsStringIgnoringCase('pending owner acknowledgement', $text, $path);
            self::assertStringNotContainsStringIgnoringCase('Pending owner acknowledgements', $text, $path);
        }
        self::assertStringContainsString(
            'acknowledged by the owner 2026-10-22',
            self::read_repository_file('docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md')
        );

        // Owner manual test list: the live cutover purge and the store configuration advice.
        $sp1 = self::read_repository_file(self::SUBPROJECT_1_EVIDENCE);
        self::assertStringContainsString('Live cutover', $sp1);
        self::assertStringContainsString('LiteSpeed/Cloudflare purge', $sp1);
        self::assertStringContainsString('decimal separator', $sp1);
        self::assertStringNotContainsString('pending owner action', self::read_repository_file('docs/project/START-HERE.md'));
    }

    public function test_sub_project_1_package_and_evidence_are_recorded(): void {
        $evidence = self::read_repository_file(self::SUBPROJECT_1_EVIDENCE);
        self::assertStringContainsString('**224 files**', $evidence);
        self::assertStringContainsString(self::SUBPROJECT_1_PACKAGE_SHA256, $evidence);
        self::assertStringContainsString('byte-identical', $evidence);
        self::assertStringContainsString(self::SUBPROJECT_1_LAST_MERGE, $evidence);
        foreach (array('#195', '#196', '#197', '#199', '#200', '#202', '#203', '#204', '#205', '#206', '#207', '#208', '#209', '#211') as $pr) {
            self::assertStringContainsString($pr, $evidence, 'the SP1 evidence must list PR ' . $pr);
        }
        self::assertStringContainsString('## SP2 rules carried from SP1', $evidence);
        foreach (array(
            '<hr class="wp-header-end">',
            'AdminAssets::enqueue_for()',
            'beforeunload',
            'idempotency',
            'core/commands',
            'palette chunks',
            'actionKey',
            'StrictMode',
            'AbortSignal.timeout',
        ) as $rule) {
            self::assertStringContainsString($rule, $evidence, 'SP2 rule missing: ' . $rule);
        }
        foreach (array('NVDA', 'VoiceOver', 'Windows High Contrast', 'Safari', 'Firefox', 'Arabic copy') as $manual) {
            self::assertStringContainsString($manual, $evidence, 'owner manual test missing: ' . $manual);
        }
        self::assertStringContainsString('## Owner acknowledgements', $evidence);
        self::assertStringNotContainsString('Pending owner acknowledgements', $evidence);
        self::assertStringContainsString('acknowledged by the owner 2026-10-22', $evidence);
        self::assertStringContainsString('markdown-it 14.3.3', $evidence);
        self::assertStringContainsString('unicode-bidi: plaintext', $evidence);
        // The four whole-SP1 reviews and how each finding was resolved.
        self::assertStringContainsString('## Whole-SP1 independent reviews', $evidence);
        foreach (array('**Security**', '**Code**', '**Accessibility**', '**Release**', 'C1', 'I1', 'M6', 'N3', 'H1', 'L7') as $review) {
            self::assertStringContainsString($review, $evidence, 'review record missing: ' . $review);
        }
        // Tracker: markdown-it is done (#208); #210 is the first hardening item.
        self::assertStringContainsString('#210', $evidence);
        self::assertStringNotContainsString('waits for that date', $evidence);
        // SP2 rules: the nav-href rule is enforced in the component; two rules are new.
        self::assertStringContainsString('savedRevision', $evidence);
        self::assertStringContainsString('explicit `aria-live`', $evidence);
        self::assertStringNotContainsString('AppShell navigation `href` values must be relative', $evidence);
        foreach (array('Aquatic', 'Desert', 'F6', 'wp.a11y.speak', '400%', 'text-spacing', 'swipe') as $manual) {
            self::assertStringContainsString($manual, $evidence, 'owner manual test missing: ' . $manual);
        }
        foreach (array(
            'AGENTS.md',
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/project/RELEASE-ENGINEERING.md',
            '.ai-architect/architecture-contract.yaml',
            self::SUBPROJECT_1_EVIDENCE,
        ) as $record) {
            self::assertStringNotContainsString(self::SUPERSEDED_TASK_8_PACKAGE_SHA256, self::read_repository_file($record), $record . ' still carries the superseded Task 8 package');
        }
        self::assertStringContainsString(self::SUBPROJECT_1_PACKAGE_SHA256, self::read_repository_file('docs/project/NEW-CHAT-HANDOFF.md'));
        self::assertStringContainsString('subproject_1_package_sha256: ' . self::SUBPROJECT_1_PACKAGE_SHA256, self::read_repository_file('.ai-architect/architecture-contract.yaml'));
        // Admin UI Gate is a required status check in the Main Rule ruleset (owner approval 2026-10-21).
        foreach (array('docs/project/START-HERE.md', 'docs/project/PROJECT-STATUS.md', 'docs/project/NEW-CHAT-HANDOFF.md', 'docs/project/OWNER-HANDOFF.md') as $living) {
            self::assertStringContainsString('`Admin UI Gate`', self::read_repository_file($living), $living . ' must list Admin UI Gate as a required check');
        }

        self::assertStringContainsString(
            'Sub-project 1 design system: **224 files**, SHA-256 `' . self::SUBPROJECT_1_PACKAGE_SHA256 . '`',
            self::read_repository_file('docs/project/RELEASE-ENGINEERING.md')
        );
        foreach (array('docs/project/START-HERE.md', 'docs/project/PROJECT-STATUS.md') as $living) {
            $text = self::read_repository_file($living);
            self::assertStringContainsString(self::SUBPROJECT_1_PACKAGE_SHA256, $text, $living);
            self::assertStringContainsString(basename(self::SUBPROJECT_1_EVIDENCE), $text, $living);
        }

        // Post-merge verification on main at the last SP1 runtime merge.
        foreach (array('docs/project/START-HERE.md', 'docs/project/PROJECT-STATUS.md') as $living) {
            self::assertStringContainsString('39575252375', self::read_repository_file($living), $living);
        }

        // Release review additions: merge gate, build contract, dependency hygiene, revert recipe.
        self::assertStringContainsString(
            'Admin UI Gate green (static, Windows drift, both browser cells, visual)',
            self::read_repository_file('AGENTS.md')
        );
        $release = self::read_repository_file('docs/project/RELEASE-ENGINEERING.md');
        foreach (array(
            '## Admin UI build contract (Sub-project 1)',
            'git ls-files --eol',
            '### Dependency hygiene',
            'serialize-javascript',
            'postcss-selector-parser',
            'js-yaml',
            'smol-toml',
            'katex',
            'sockjs',
            'react-dom-19',
            '90 days',
            '2027-01-17',
            'audit:lock-age',
            '### Reverting a Sub-project 1 change',
            'git revert',
            'update_visual_baselines',
            'npm_config_before',
        ) as $rule) {
            self::assertStringContainsString($rule, $release, 'RELEASE-ENGINEERING.md missing: ' . $rule);
        }
        $contributing = self::read_repository_file('CONTRIBUTING.md');
        foreach (array('npm_config_script_shell', 'Git Bash', 'NODE_ENV', 'BROWSERSLIST', 'Never hand-merge') as $rule) {
            self::assertStringContainsString($rule, $contributing, 'CONTRIBUTING.md missing: ' . $rule);
        }

        $compatibility = self::read_repository_file('docs/COMPATIBILITY.md');
        self::assertStringContainsString('### Admin UI boundary (Sub-project 1)', $compatibility);
        self::assertStringContainsString('git working tree, not the packaged ZIP', $compatibility);
        foreach (array(':has()', 'Firefox 121', 'Safari 15.4') as $floor) {
            self::assertStringContainsString($floor, $compatibility, 'admin UI browser floor missing: ' . $floor);
        }
        self::assertStringNotContainsString('| GHSA-gfr2-rv44-r23c |', $release, 'markdown-it was adopted in #208');
        foreach (array(
            'Playwright 1.63.0',
            'WP 6.9.10 / WC 10.8.1',
            'WP 7.1.3 / WC 11.1.2',
            'React 18.3.1',
            'React 19',
            'NVDA',
            'VoiceOver',
            'Windows High Contrast',
            'foundations-arabic',
        ) as $boundary) {
            self::assertStringContainsString($boundary, $compatibility, 'admin UI boundary missing: ' . $boundary);
        }
    }

    public function test_the_onboarding_api_boundary_and_its_package_are_recorded(): void {
        $compatibility = self::read_repository_file('docs/COMPATIBILITY.md');
        self::assertStringContainsString('### Onboarding API boundary (Sub-project 0c)', $compatibility);
        self::assertStringContainsString('`supcheckout/v1`', $compatibility);
        self::assertStringContainsString('runner.stalled', $compatibility);
        self::assertStringContainsString('supcheckout_nonce_invalid', $compatibility);

        foreach (array('docs/project/START-HERE.md', 'docs/project/PROJECT-STATUS.md') as $living) {
            $text = self::read_repository_file($living);
            self::assertStringContainsString('onboarding backend (services + REST)', $text, $living);
            self::assertStringContainsString('wizard UI moves to Sub-project 2', $text, $living);
        }

        // The 0c package is history once Sub-project 1 changed the distributable bytes.
        $release = self::read_repository_file('docs/project/RELEASE-ENGINEERING.md');
        self::assertStringContainsString('Historical Sub-project 0c package (superseded by Sub-project 1): **134 files**', $release);
        self::assertStringNotContainsString('**Revamp development package (NOT owner accepted):** Sub-project 0c onboarding backend', $release);
        foreach (array('docs/project/START-HERE.md', 'docs/project/PROJECT-STATUS.md') as $living) {
            $text = self::read_repository_file($living);
            self::assertStringNotContainsString('package 134 files (`RELEASE-ENGINEERING.md`)', $text, $living);
            self::assertStringNotContainsString('; package 134 files;', $text, $living);
            self::assertStringNotContainsString('Deterministic package: 134 files', $text, $living);
        }
    }

    public function test_the_0c_certification_is_not_claimed_for_the_pr_head(): void {
        // Run 39177850394 certified 535b1b4, before the activate_plugins fix and the final
        // review wave: it is historical, and the exact-head CI gates are pending on the PR head.
        $evidence = self::read_repository_file('docs/project/evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md');
        self::assertStringContainsString('**pending on the PR head**', $evidence);
        self::assertStringContainsString('historical', $evidence);
        self::assertStringContainsString('**134 files**', $evidence);
        // PR #194 then merged (5dce3f1) after its exact-head CI went green at b48badf; the living
        // status records that, while the evidence file above stays a historical record.
        $status = self::read_repository_file('docs/project/PROJECT-STATUS.md');
        self::assertStringContainsString('5dce3f1d1397d455baa4b4bfe64ef3f89184de6e', $status);
        self::assertStringContainsString('b48badf1f1c81284546ac4c3be62ca211201676f', $status);
        self::assertStringContainsString('39205662675', $status);
        self::assertStringNotContainsString('are pending on the PR head', $status);
        self::assertStringNotContainsString('package 133 files', $status);
        self::assertStringNotContainsString('package 133 files', self::read_repository_file('docs/project/START-HERE.md'));
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
