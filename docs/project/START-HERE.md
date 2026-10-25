# SUPCheckout for UPayments — Start Here

> **Mandatory session bootstrap.** Every AI agent, developer, reviewer, release operator or owner working from a new chat, machine, clone, worktree or session must read this file first, then follow the authority chain below.
>
> **Never start substantive work from chat memory alone. Live repository evidence wins over this document whenever they differ.**

## 1. Golden project identity

| Surface | Canonical value |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Short product name | **SUPCheckout** |
| Maintainer | **Simplix Innovations** |
| Provider scope | **UPayments only** |
| Repository | `SimplixInnovations/supcheckout` |
| Default branch | `main` |
| Technical slug / text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| Package root | `supcheckout/` |
| Main file | `supcheckout/supcheckout.php` |
| Development version | `0.1.0` |
| Public tag / GitHub Release | **Not created** |
| WordPress.org publication | **Not performed / not authorized** |

The word **for** is relationship copy only. It must not be encoded into repository, package, WordPress.org, namespace, REST, CSS/JS or release-artifact identifiers.

SUPCheckout is permanently **UPayments-specific**. Do not turn this repository into a generic payment router or add unrelated provider adapters.

## 2. Session bootstrap contract

Before analysis, implementation, review, release work or claims about project state:

1. fetch/prune the repository and resolve live `main`;
2. inspect remote/local branches, worktrees and stashes;
3. inspect open PRs and issues;
4. inspect tags and GitHub Releases;
5. inspect the active Main Rule / required checks;
6. inspect exact-head CI/check state relevant to the work;
7. compare live evidence with the living documents below;
8. reconcile living documents if verified project truth has changed;
9. only then begin substantive work.

For a local clone:

```bash
git fetch --prune --tags origin
git remote -v
git branch --show-current
git branch -r
git status --short
git rev-parse HEAD
git rev-parse origin/main
git worktree list
git stash list
```

A new session must never assume that a SHA, PR state, branch count, package hash or gate result copied from an older chat is still current.

## 3. Authority chain

Read in this order after this file:

1. [`../../AGENTS.md`](../../AGENTS.md) — repository-wide engineering, compatibility, security and merge rules;
2. [`PROJECT-STATUS.md`](PROJECT-STATUS.md) — canonical living engineering state;
3. [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md) — fresh-clone, local-acceptance and release-decision procedure;
4. [`NAMING-IDENTITY-STANDARD.md`](NAMING-IDENTITY-STANDARD.md) — canonical identity and protected compatibility IDs;
5. [`../COMPATIBILITY.md`](../COMPATIBILITY.md) — public compatibility/certification boundary;
6. [`NEW-CHAT-HANDOFF.md`](NEW-CHAT-HANDOFF.md) — compact continuation context;
7. [`../../DESIGN.md`](../../DESIGN.md) — durable visual intent and runtime styling ownership;
8. [`../../UX-CONTRACT.md`](../../UX-CONTRACT.md) — canonical interaction, localization, accessibility and verification behavior;
9. [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md) — deterministic package, migration and release contract;
10. [`ENTERPRISE-CERTIFICATION.md`](ENTERPRISE-CERTIFICATION.md) — retained certification evidence;
11. relevant historical phase/quality/spec/plan records when touching their contracts.

Historical documents may contain former product names, old repository coordinates and old SHAs because those facts were true at the time. Do not bulk-rewrite historical evidence into current branding.

## 4. Frozen acceptance reference and current Approach 3 coordinate

The owner-accepted technical baseline is now **Release Candidate 1** (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1`, recorded 2026-10-07):

- source: `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (PR #128 squash-merged main; tree identical to certified head `3f004c3d3affc95ddbc564bd0594dd9dd0529695`, 77/77 checks SUCCESS);
- package: `supcheckout-0.1.0.zip`;
- files: 62;
- SHA-256: `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`.

Technical acceptance does not authorize publication, provider outreach or a live transaction, and does not close any release-candidate blocker.

Historical Approach 3 (superseded by Release Candidate 1, `OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`): source `146d65a1c182630c1acc651cacafe30cff5f6b79`, 62 files / SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`.

Any later candidate that changes distributable runtime or UI bytes **postdates this acceptance** and must receive fresh exact-head/package verification and a fresh explicit owner acceptance before it can replace the accepted baseline or be published.

**Post-acceptance runtime on `main`:** payment lifecycle hardening (2026-10-10 review) was squash-merged through PR #133 to `main` at `35ea66d4cb9c8395b264cd24b4844eaa268aa470` and changes runtime bytes: 62 files / SHA-256 `bbb40cb2bc6bec557195689ea9335bff514082ad3353dd2e40f2057501681730`. PR #138 (non-white-label Charge fix) then changes the package to `961aa4727d030d317ec892e8d3ee47e10b209bbabfc53a8fbba8ee6ece8f3133`. Neither is **owner accepted**. Release Candidate 1 stays the accepted baseline until a fresh explicit acceptance of an exact `main` SHA carrying this package, after `LOCAL-FINAL-VERIFICATION.md`.

Historical Approach 2 (superseded, retained for audit): source `0c883d609906676966002eb022a82a9656eeacc5`, 51 files / SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

This acceptance covers the verified repository engineering baseline only. It is **not** publication authorization and does **not** certify unresolved external/provider contracts. Automatic recurring `VERIFIED_SUCCESS` remains **FAIL-CLOSED / UNREACHABLE**.

Approach 3 advanced through verified tranches (historical):

- **T1 DONE / VERIFIED** — architecture guardrails and active callback characterization; merged main `beb89ac0c4d8c0e9b7c8b2de1e13c237bbd37b15`.
- **T2 DONE / VERIFIED / runtime-bearing** — bounded legacy callback fallback consolidation; merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`; deterministic candidate package 51 files / SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`.
- **T3 DONE / VERIFIED / runtime-neutral** — direct legacy return/webhook/private-verifier characterization; PR #107 exact certified head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`; squash-merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`.

T3 preserved a critical compatibility constraint: a direct caller of `WC_Upayments::return_from_upayments()` may lack the WC-API GET `page` marker that current `PaymentLifecycle::handle_callback()` uses to infer browser mode. Therefore T3 does **not** authorize naïve T4 delegation.

## 5. Current program gate

The active engineering program is the SUPCheckout revamp (see "Active engineering program" below). The release program, which stays open, is:

**`external-certification-release-readiness`**

Canonical plan:

`docs/superpowers/plans/2026-09-26-external-certification-release-readiness.md`

The completed R0/R1/E1-E3 integration milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. No feature branch is retained for that milestone.

The latest repository-executable E3 runtime checkpoint inside that PR is:

`540b733c29656758f2392817649fc3d4a4db585d`

At that exact SHA, Quality/H12, the full 20-cell Compatibility matrix + Compatibility Gate, Provider Sandbox Certification, WordPress.org Submission Check, Release Artifact and the complete Ecosystem Certification matrix succeeded. E3 repository automation covers five free themes plus real child-theme WooCommerce template overrides, four free cache/optimizer coexistence plugins, delayed/combined/repeated Classic interaction behavior, and analytics-return replay characterization.

GitHub default CodeQL JavaScript/TypeScript analysis did not reach a terminal verdict for this historical checkpoint; that hosted-security gap must not be rewritten as a success claim. Its deterministic package is 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

R0, R1 and E1 are **DONE / VERIFIED and integrated through PR #108**. Repository-executable generic E2 is **DONE / CERTIFIED**. E3 repository-executable runtime evidence is **DONE / VERIFIED** at this checkpoint. Named paid/licensed themes, paid optimizers, CDN/server-specific cache modes, browser/device visual evidence and named analytics deduplication remain external/manual unless actually exercised.

Release Candidate 1 is the current owner-accepted technical baseline (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1` at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (package `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`)), superseding Approach 3 (`146d65a`). Publication remains NOT AUTHORIZED.

### Current provider-contract disposition

Deep first-party research narrowed the **first public core one-time-payment** provider blocker to one contract decision. The owner-run account probe resolved it for the release merchant account on 2026-10-13 ([evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md)):

`PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY`

Scope: that account, at that time, for packages containing the PR #138 Charge fix. Release Candidate 1, and every package before PR #138, sends a non-white-label Charge that this production account rejects (HTTP 422, `paymentGateway.src` missing), so it must not be released.

The remaining provider-related areas are deliberately outside the first-release core claim: saved-card/token persistence is a **FUTURE FEATURE GATE**, auto-deduct capture/cycle identity is a **FUTURE RECURRING GATE**, webhook-signature mechanics are future defense-in-depth, and sandbox credential-family migration is a future test-infrastructure gate. None of those deferred areas may be silently promoted into supported production scope. Automatic recurring `VERIFIED_SUCCESS` remains fail-closed/unreachable. Publication remains **NOT AUTHORIZED**.

Authoritative public-evidence analysis: `docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md`.

### Active engineering program: SUPCheckout revamp (reconciled 2026-10-23)

Spec: `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md` (§9 delivery, Sub-projects 0–5). The release-readiness gate above stays open; spec §9 places the final owner technical acceptance and live acceptance in Sub-project 5.

- **Sub-project 0a clean core — DONE / VERIFIED, staging-rehearsed.** PRs #165–#172, last merge `0cef28dbcf35d3ec5e669f3c0b46f580069c4c31`; owner staging rehearsal passed 2026-10-17 ([evidence](evidence/SUBPROJECT-0A-CLEAN-CORE-2026-10-17.md), PR #178).
- **Sub-project 0b takeover engine — Tasks 1–12 merged** (PRs #173–#177, #179–#184; plan `docs/superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md`; per-PR merge SHAs in [`PROJECT-STATUS.md`](PROJECT-STATUS.md)). The real-WordPress Takeover Migration Matrix is green on `main` at `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b` and is a permanent quality control.
- The 0b takeover staging rehearsal PASSED on 2026-10-18 (`docs/project/evidence/TAKEOVER-REHEARSAL-2026-10-18.md`): preflight blocked correctly during a UPayments sandbox outage; then the switch, an in-flight payment from the official plugin, rollback with reactivation, a second switch and a forced Finalize all behaved as designed. Its two findings were fixed in #185.
- **Sub-project 0c onboarding backend (services + REST) — Tasks 1–10** (plan `docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md`; Tasks 1–2 merged through #190 and #191). 0c is the onboarding backend (services + REST) only: the UI-agnostic `Onboarding` service, an Action Scheduler driver and the admin-only `supcheckout/v1` REST API, with the cache purge at `caches_flushed`. The wizard UI moves to Sub-project 2 (owner decision 2026-10-18). Its API and runtime boundary is in `docs/COMPATIBILITY.md` "Onboarding API boundary (Sub-project 0c)"; the real-WordPress REST and driver certification (historical, at `535b1b4`) and the independent security review are in [`evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md`](evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md). Tasks 4–10 merged through PR #194 (`5dce3f1d1397d455baa4b4bfe64ef3f89184de6e`) after every exact-head workflow succeeded at `b48badf1f1c81284546ac4c3be62ca211201676f` (Compatibility Certification run 39205662675).
- Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22) (all seven setup checks passed, with the optional sandbox test payment deferred to the setup screen, on the My Organic World staging copy, WCML per-currency checkout visibility passed, and a sandbox AED card order completed as Processing with Payment Status CAPTURED; the automatic LiteSpeed purge on a REST switch or rollback is covered by the real-plugin CI cell, and confirming it on the live store's real takeover is on the owner manual test list). Evidence: `docs/project/evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md`.
- Sub-project 1 design-token system and admin component library — **DONE / VERIFIED** (plan `docs/superpowers/plans/2026-10-19-subproject-1-design-system.md`; PRs #195–#197, #199, #200, #202–#209 and #211; last runtime-bearing merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5`). The brand-neutral `--sup-*` token system and the accessible React component library, with a committed deterministic build and the permanent Admin UI Certification (`.github/workflows/admin-ui-certification.yml`). Package 224 files, SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4` (not owner accepted). Post-merge verification on `main`: at the Task 8 merge `ef9a101ec0ce2d106e1a0418523ed01a6d77ac9a` every workflow succeeded, including Admin UI Certification run 39575252375 (its gate covers static, Windows drift, both browser cells and visual), Compatibility Certification 39575252634 and Release Artifact 39575252360. At the final SP1 merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5` (#211, recorded 2026-10-21) the WordPress.org Submission Check 39660030118 and Supply Chain Evidence 39660030107 had succeeded and the rest (Admin UI Certification 39660030227, Quality Gates, Compatibility, Ecosystem, Release Artifact, CodeQL) were still queued; they are not claimed here. `Admin UI Gate` is a required status check in the `Main Rule` ruleset (owner approval 2026-10-21). Boundary: `docs/COMPATIBILITY.md` "Admin UI boundary (Sub-project 1)"; evidence, the SP2 rules carried from SP1 and the owner manual test list: [`evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md`](evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md).
- Owner decision 2026-10-20 (binding): the owner shares the full revamp, branding and information architecture only once functionality, security and performance are verified. The program after Sub-project 1 is therefore a hardening program: a full audit of functionality, security and performance, then a fix loop. Sub-project 2 screens, the wizard IA, the menu structure and the visual checkout redesign wait for the owner's information architecture and branding.
- Hardening program (repository-executable fix loop) — **DONE / VERIFIED** (2026-10-23).
  - **Audit:** six audits (A1 to A6) found 50 findings before dedupe.
  - **Fixes:** A1-02 #215 `376c482`; batch 1 #221 `e3884ca`; batch 2 #222 `3575721`; batch 3 #223 `f3e207d` (R1-1, R1-2); batch 4 #226 `eed5bbd`.
  - **Batch 4** (#226, branch `hardening/batch-4`, merged `eed5bbd`): the last fixable residuals: A1-03 note dedupe, note-lock token release, N-2, N-3, M-5 rest, O4 copy, A2-02 revised (https-only live links, with an `*.upayments.com` http to https upgrade), H12-META, AS-1 and the Subscription test-isolation fix. H12-META was a real WooCommerce meta-read defect: the historical order-meta read assumed index 0 and called a `WC_Meta_Data::get_value()` that WooCommerce lacks, so on real stores save-card, selected-card and subscription checkout failed closed at the persistence readback and Phase 9I could not run. A6-06 (PHPStan stubs) stayed tooling backlog until batch 6. The owner staging check of save-card, selected-card and subscription checkout is deferred until UPayments enables white-label.
  - **Batches 5 and 6** (branch `hardening/batch-5`, one PR, open): batch 5 fixes the safe "left on purpose" items (owner instruction 2026-10-25): A1-11 (paid orders go to Processing on a fresh install; a stored setting keeps its value), A4-05 rest (RTL-safe Classic and Blocks checkout UI with logical properties), A4-08 (the product subscription opt-out follows the WPML original) and U2 N-1 (the sweep unschedules a lost reconcile event, so the bounded checks resume); A6-08 is closed by design. Batch 6 fixes A6-06: PHPStan analyses all of `src/` at level 5 against the official WordPress 6.9 and WooCommerce 10.8 stubs, with no baseline (89 errors to 0, runtime-neutral). The remaining recorded items are closed by design or wait on provider answers (subscriptions stay gated by A1-01).
  - **Package:** 233 files, SHA-256 `1b7a5aca04ee09a79fae816cf2d18e34177c55f63a851bb95f0a113d9e91812f` at the batches 5 and 6 head (not owner accepted). The batch 4 package `2a8e1aba7e056151a5995c9147eccfd2700efe866ac7b8c36ed87f2b17def6db` and the owner-verified batch 3 package `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8` are historical.
  - **Parked:** none. A2-02 (https-only live redirect) is fixed in hardening batch 4 (`hardening/batch-4`), revised by the UPayments question 12 evidence: in live mode an https link passes, an http link on `upayments.com` or a subdomain is upgraded to https, and any other non-https link fails closed. Nothing is parked.
  - **Evidence:** the units, the recorded-not-fixed items with their reasons and the owner items are in [`evidence/HARDENING-PROGRAM-2026-10-23.md`](evidence/HARDENING-PROGRAM-2026-10-23.md).
- Owner staging verification of the hardened package — **DONE / OWNER-VERIFIED** (2026-10-24): the owner installed the 233-file package (`main` @ `f3e207d`, installed-tree fingerprint `f236d64d7655254cb4b77c48a9d240020aa9f5184c51d6ba3432689722cb172d`) on the My Organic World staging copy; setup checks, English and Arabic checkout in KWD, AED and OMR, two sandbox payments (CAPTURED), the write-only API key, the paused-renewals note and the final-switch refusal of the official plugin all passed; the new `currency_decimals` check warned once and passed after the owner set AED, SAR and QAR to 2 decimals. The package is not owner accepted as a release. [Evidence](evidence/OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md).
- Current executable gate: **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)**.
- Sub-project 2 admin screens begin only after the owner shares the information architecture and branding; then Sub-projects 3–5 per the spec.


## 6. Post-T3 execution sequence

The approved bounded sequence is:

```text
R0  control-plane reconciliation
 ↓
R1  bounded correctness / presentation / accessibility
 ↓
E1  interactive checkout compatibility
 ↓
E2  economics and product compatibility
 ↓
E3  theme / cache / analytics interaction evidence
 ↓
R2  callback portability and cache safety
 ↓
R3  subscription safety
 ↓
R4  scalability / idempotency / observability
 ↓
R5  callback lifecycle consolidation (Option B / ADR-003) — DONE / VERIFIED
 ↓
R6  exact-head qualification + fresh owner re-acceptance — DONE / VERIFIED
 ↓
external certification: core production-auth — `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` (2026-10-13); remaining: fresh acceptance of a package containing PR #138, live one-time acceptance
 ↓
explicit version/publication decision only after remaining release gates
```

R0-R5 are bounded evidence-first work under the approved post-T3 plan. R5 used its own architecture decision (Option B / ADR-003) and is complete. **R6 is DONE / VERIFIED.** Release gate (open): **external certification & release readiness**; the current executable gate is the owner staging verification of the hardened package (§5).

## 7. Active-work ledger

| Field | Current value |
|---|---|
| Program phase | **SUPCheckout revamp — Revamp information architecture and branding from the owner (Sub-project 2+)** (hardening program fix loop done; owner staging verification of the hardened package done 2026-10-24; spec `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md`); release program external certification & release readiness stays open |
| Approach 3 | **DONE / VERIFIED / OWNER ACCEPTED — HISTORICAL, superseded by Release Candidate 1** |
| Release Candidate 1 | **CURRENT OWNER-ACCEPTED TECHNICAL BASELINE** |
| Frozen accepted baseline | `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (Release Candidate 1; supersedes Approach 3 `146d65a1c182630c1acc651cacafe30cff5f6b79`) |
| T1 | **DONE / VERIFIED** |
| T2 | **DONE / VERIFIED / runtime-bearing** |
| T3 | **DONE / VERIFIED / runtime-neutral** |
| Latest integrated post-T3 milestone main | `d69377d3e26831270a00151025d24cc64be9d36b` |
| Active task | **Hardening batches 5 and 6** (`hardening/batch-5`, its PR is the task ledger): the safe "left on purpose" items (A1-11, A4-05 rest, A4-08, N-1) and PHPStan with the real stubs (A6-06). It does not change the current gate, which waits on the owner's information architecture and branding |
| Latest repository-executable E3 runtime checkpoint | `540b733c29656758f2392817649fc3d4a4db585d` |
| Latest candidate package at that checkpoint | 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c` |
| R0 / R1 / E1 | **DONE / VERIFIED and integrated through PR #108** |
| E2 repository-executable generic | **DONE / CERTIFIED** |
| E3 repository-executable | **DONE / VERIFIED** at `540b733c29656758f2392817649fc3d4a4db585d` |
| R2 | **DONE / VERIFIED** — certified PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, squash-merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`; candidate package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3` |
| R3 | **DONE / VERIFIED** — certified PR #112 head `de0162b4a1cca77c62f07290224b055902120c2a`, squash-merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`; candidate package SHA-256 `070279120064a6fe558dbeef3702a10b90330b8dc79fd9f21f028cd5fefba4da` (NOT owner accepted) |
| R4 | **DONE / VERIFIED** — certified PR #114 head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, squash-merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`; package 62 files SHA-256 `2543c2a0bfdba53e8968bd1a09b263a3a2b7bd02a11eabd41c102aa5d6dc7b8b` (NOT owner accepted) |
| R5 | **DONE / VERIFIED** — Option B / ADR-003; certified PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, squash-merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` (NOT owner accepted) |
| R6 | **DONE / VERIFIED** — exact-head qualification and owner technical re-acceptance complete |
| Current operational gate | **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)** (hardening program and owner staging verification done; owner decision 2026-10-20). Sub-project 2 admin screens begin only after the owner shares the information architecture and branding |
| Release gate | **external certification & release readiness — open** (owner acceptance and live acceptance in Sub-project 5) |
| Next release gate step | **Fresh owner acceptance of a package containing PR #138, then live one-time acceptance** (production auth `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY`, 2026-10-13) |
| Sub-project 0a clean core | **DONE / VERIFIED, staging-rehearsed** 2026-10-17 (last merge `0cef28dbcf35d3ec5e669f3c0b46f580069c4c31`) |
| Sub-project 0b takeover engine | **Tasks 1–12 merged** (PRs #173–#177, #179–#184); Takeover Migration Matrix green on `main` `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b` |
| Sub-project 0c onboarding backend | **Tasks 1–10 merged** (PR #194 `5dce3f1d1397d455baa4b4bfe64ef3f89184de6e`) — services + admin-only `supcheckout/v1` REST API, no UI; its 134-file package is historical, superseded by Sub-project 1; owner staging review **DONE / OWNER-VERIFIED** (2026-10-22) |
| Sub-project 1 design system | **DONE / VERIFIED** — PRs #195–#197, #199, #200, #202–#209 and #211, last merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5`; package 224 files (`RELEASE-ENGINEERING.md`); Admin UI Certification a permanent quality control |
| Hardening program | Hardening program (repository-executable fix loop) — **DONE / VERIFIED** — #215, #221, #222, #223 and batch 4 #226 (`hardening/batch-4`: residuals, A2-02, H12-META, AS-1) merged; batches 5 and 6 on `hardening/batch-5` (A1-11, A4-05 rest, A4-08, N-1, A6-06); package 233 files, SHA-256 `1b7a5aca04ee09a79fae816cf2d18e34177c55f63a851bb95f0a113d9e91812f` (not owner accepted); [evidence](evidence/HARDENING-PROGRAM-2026-10-23.md) |
| Owner staging verification | Owner staging verification of the hardened package — **DONE / OWNER-VERIFIED** (2026-10-24) — [evidence](evidence/OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md); verified package 233 files, SHA-256 `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8` (batch 3, now historical; not owner accepted) |
| Public release authorization | **NOT GRANTED** |
| Source of live truth | **GitHub + exact source/check/package evidence** |

### Operational tracking model

Use three layers of truth:

1. **Program state — this file.** Phase, gates, accepted baseline, next program action and release authorization.
2. **Active task state — a GitHub PR when one exists.** Base, exact head, scope/non-scope, failures, current gate, next action and merge evidence. Between bounded tranches, zero open PRs is the expected clean state.
3. **Durable history — merged PRs, commits, CI and retained evidence.** Do not duplicate a forever-growing event stream here.

Update this ledger whenever the active phase, acceptance state, release authorization, current gate, next substantive action or runtime-bearing evidence anchor changes materially.

## 8. Permanent compatibility and safety boundaries

Do not mechanically rename or refactor protected identities, including:

- gateway/payment ID `upayments`;
- `woocommerce_upayments_settings`;
- Blocks / Store API identity `upayments`;
- callback `wc_upayments`;
- historical `_upay_*` metadata;
- `UPayments_order_id` and related provider-order identities;
- token/provenance/scope/generation state;
- `upay_process_subscriptions` and billing-attempt state;
- historical order payment-method values;
- frozen Phase 9I migration identities;
- public compatibility wrapper `getAPIUrlForRetreiveCards()`;
- normalized `whitelabled` compatibility shape.

They stay protected until migrated by the approved takeover (Sub-project 0b, `src/Takeover/`, `wp supcheckout takeover`): it copies, never moves, each mapped identity through `Keys`, never rewrites historical order `payment_method`, and serves `wc_upayments` permanently. `AGENTS.md` "Protected compatibility identities" is the full contract.

`supcheckout/supcheckout.php` is the main file since Sub-project 0a (owner decision 2026-10-16, pre-release). A header-less transitional `supcheckout/UPayments.php` shim (Task 2b) moves pre-rename installs' active entry automatically on zip upload, WP-CLI and one-click/bulk updates. The exception is the classic non-JavaScript single-plugin Update-now flow (`update.php?action=upgrade-plugin`), which ends deactivated with data unchanged; see `docs/COMPATIBILITY.md`. It is removed in Sub-project 5 after the owner confirms every pre-rename install has loaded it.

Payment/security ambiguity fails closed. Routing input is not financial truth. Charge initialization is not capture. Paid state requires authenticated provider verification bound to the correct order/transaction/economics. Non-idempotent payment/refund/auto-deduct mutations are not blindly retried.

## 9. Deferred/external evidence boundary

Repository automation does not automatically prove:

- production merchant payment completion;
- real wallet/account/device completion;
- WPML/WCML, multilingual, multicurrency or RTL behavior;
- broad browser/device/theme/accessibility behavior;
- representative production-store load/performance;
- penetration testing, PCI or legal/compliance attestation;
- live non-idempotent subscription auto-deduction;
- provider webhook signatures until a stable documented contract exists.

Automatic WooCommerce refunds and arbitrary marketplace multi-split remain unsupported unless separately designed and approved.

## 10. Repository governance target

Outside temporary active work, desired repository state is:

- default branch `main`;
- no stale remote feature/audit branches;
- no unintended open PRs/issues;
- no public tags/releases before explicit approval;
- squash-only merge and linear history;
- deletion/non-fast-forward protection;
- required review-thread resolution;
- no bypass actors;
- strict required checks: `Governance`, `H12 Regression Harness`, `Compatibility Gate`, `Release Gate`, `Admin UI Gate`.

Any continuation or release claim must verify this live.

## 11. Change-tracking protocol

For substantive work:

1. establish live baseline and scope;
2. use the authorized dedicated branch;
3. record intended outcome in PR/spec/plan;
4. characterize before behavior change;
5. use meaningful RED → prove failure → minimal GREEN for runtime fixes;
6. preserve compatibility-sensitive identities and authority rules;
7. obtain exact-head verification;
8. resolve valid review findings;
9. reconcile living state when truth changes;
10. merge only under repository policy;
11. reverify post-merge `main`;
12. clean stale task branches after safe closure.

Never create a new numbered historical phase merely because work continued in a new chat.

## 12. What a new agent/developer must be able to state

Before proceeding, a competent continuation must know from live evidence:

- exact current `main` SHA;
- active PR/branch and exact head if any;
- latest runtime-bearing certified coordinate;
- frozen owner-accepted baseline;
- current program phase and gate;
- whether release/publication is authorized;
- protected compatibility identities;
- exact tests/gates required for the intended work;
- unresolved manual/external evidence boundaries.

If any of these are unknown, the session is **not bootstrapped yet**.

## 13. Immediate next step

**Revamp (active engineering):** Sub-project 1 design-token system and admin component library — **DONE / VERIFIED**. Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22) ([evidence](evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md)). Hardening program (repository-executable fix loop) — **DONE / VERIFIED** (owner decision 2026-10-20; [evidence](evidence/HARDENING-PROGRAM-2026-10-23.md)). Owner staging verification of the hardened package — **DONE / OWNER-VERIFIED** (2026-10-24) ([evidence](evidence/OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md)). Current executable gate: **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)**. Sub-project 2 admin screens begin only after the owner shares the information architecture and branding. See §5 "Active engineering program".

R3 is DONE / VERIFIED (PR #112 / main e1ad338). R4 is DONE / VERIFIED (PR #114 / main `10a33b4`). R5 is DONE / VERIFIED (PR #116 / main `50170ea`). R6 is **DONE / VERIFIED**. Owner technical acceptance recorded: `OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1` at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (package `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`), superseding Approach 3 (`146d65a1c182630c1acc651cacafe30cff5f6b79`). Publication remains **NOT AUTHORIZED**. Automatic recurring `VERIFIED_SUCCESS` remains **FAIL-CLOSED / UNREACHABLE**.

### Release owner action queue (reconciled 2026-10-13)

Repository-side work for the release gate is complete. The remaining work is owner/external only, in this order:

0. **Fresh technical acceptance of the current `main` package.** `main` carries runtime bytes newer than Release Candidate 1; with PR #138 the candidate package is `961aa4727d030d317ec892e8d3ee47e10b209bbabfc53a8fbba8ee6ece8f3133`. Run `LOCAL-FINAL-VERIFICATION.md` on the exact `main` SHA, then record `OWNER_TECHNICAL_ACCEPTANCE=<label>` with that SHA and package, or keep Release Candidate 1. Live evidence must be produced with the package that will be released.
1. **Production auth probe — DONE 2026-10-13.** Probes 1 (status), 0 (button status) and 2 (Charge) all reported `BEARER_ONLY_ACCEPTED` on the release merchant account in one run ([evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md)); gate `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY`. The same run proved release Candidate 1, and every package before PR #138, sends a non-white-label Charge that this production account rejects (HTTP 422, `paymentGateway.src` missing), so it must not be released. PR #138 fixes it, so step 0 must accept a package that contains PR #138. Re-run the probe before each release.
2. **Live one-time payment acceptance.** Needs `OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES` and step 1 resolved; follow `LIVE-PAYMENT-ACCEPTANCE-PLAN.md` on an isolated HTTPS staging site. A copy of a real store is acceptable only after sanitization: customer personal data anonymized or removed, stored payment/customer tokens and gateway credentials removed, and outbound email disabled (see `LIVE-PAYMENT-ACCEPTANCE-PLAN.md`). Real customers' checkout is never a test surface. An owner-run rehearsal of the same scenarios against the public UPayments sandbox on that staging site is recommended first; it costs nothing but proves nothing about production authentication.
3. **Pentest and PCI/legal review,** only if the release will be claimed as enterprise production-ready.
4. **Publication decision:** version, tag, GitHub Release and WordPress.org submission. Needs explicit publication authorization.

Resolved CI watch item: the intermittent nginx 502 in the request-context stability cells (10 of 416 jobs, 2026-09-26 to 2026-10-15) was the opcache tracing JIT that setup-php enables for every SAPI, crashing or wedging cold PHP-FPM workers. It was not the plugin, a PECL extension or stack depth. The CI stack now starts PHP-FPM with the JIT disabled and refuses a worker that reports it on. Evidence and A/B numbers are in [CI-HTTP-SERVER-INSTABILITY.md](CI-HTTP-SERVER-INSTABILITY.md). A new 502 is a new defect; read its core backtrace, do not treat it as a flake.

Contributor setup on any new machine: commit as `Simplix Innovations <admin@simplixi.com>`. Commits under an email that is not linked to the GitHub account are unattributed, and the `main` ruleset (`require_extra_approval_for_unattributed_changes`) then blocks the merge.
