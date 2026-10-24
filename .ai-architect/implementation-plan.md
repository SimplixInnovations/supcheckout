# Approach 3 coding handoff

## Authority

Repository: `SimplixInnovations/supcheckout`
Frozen owner-accepted Approach 2 baseline: `0c883d609906676966002eb022a82a9656eeacc5`
Accepted package: 51 files / SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`
Latest merged Approach 3 main: `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`
Latest runtime-bearing merged main: `047cc86060efb97761d7a0cc4a3806f971ab6fe1`

Historical tranche design/evidence remains in the T1-T3 plans, ADRs and Git history. This file is the current implementation handoff and intentionally avoids duplicating their full narratives.

## Architecture decision

Approach 3 uses incremental strangler modernization around proven A1-A5 seams and the existing `PaymentLifecycle` callback strangler.

Permanent rules:

- SUPCheckout remains UPayments-only.
- Do not add a service locator/heavy DI container/generic provider framework.
- No new parallel Return/Webhook controller architecture beside `PaymentLifecycle`.
- No new provider HTTP egress site without architecture review.
- Protected persisted/provider identities require a separately approved migration.
- `UPayments.php` remains a compatibility adapter; do not grow it with new responsibilities.

## Closed tranches

### T1 — architecture guardrails and active callback characterization

**DONE / VERIFIED**, merged main `beb89ac0c4d8c0e9b7c8b2de1e13c237bbd37b15`.

Permanent evidence pins active callback topology, priority/termination semantics, verification/locking responsibility and the accepted provider-egress inventory.

### T2 — legacy callback fallback consolidation

**DONE / VERIFIED / runtime-bearing**, merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`.

Only the legacy `check_ipn_response()` fallback was delegated to `PaymentLifecycle::handle_callback()`; direct return/webhook/private verifier behavior remained untouched.

### T3 — legacy direct callback/private-verifier characterization

**DONE / VERIFIED / runtime-neutral**, certified PR #107 head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`, merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`.

T3 proved direct `return_from_upayments()` callers may lack the GET `page` marker used by the active lifecycle to infer browser mode. T3 therefore does **not** authorize naive direct T4 delegation.

## Current program — SUPCheckout revamp

Spec: `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md` (Sub-projects 0–5).

- Sub-project 0a clean core — **DONE / VERIFIED**, staging-rehearsed 2026-10-17.
- Sub-project 0b takeover engine — Tasks 1–12 **merged** (PRs #173–#177, #179–#184; plan `docs/superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md`). The Takeover Migration Matrix is green on `main` at `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b`.
- Sub-project 0c onboarding backend (services + REST) — Tasks 1–10 (plan `docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md`): the UI-agnostic `Onboarding` service, the Action Scheduler driver and the admin-only `supcheckout/v1` REST API. 0c ships no screens; the wizard UI moves to Sub-project 2 (owner decision 2026-10-18). Tasks 4–10 merged through PR #194 (`5dce3f1d1397d455baa4b4bfe64ef3f89184de6e`).
- Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22) (all seven setup checks passed, with the optional sandbox test payment deferred to the setup screen, on the My Organic World staging copy, WCML per-currency checkout visibility passed, and a sandbox AED card order completed as Processing with Payment Status CAPTURED; the automatic LiteSpeed purge on a REST switch or rollback is covered by the real-plugin CI cell, and confirming it on the live store's real takeover is on the owner manual test list). Evidence: [`docs/project/evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md`](../docs/project/evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md).
- Sub-project 1 design-token system and admin component library — **DONE / VERIFIED** (plan `docs/superpowers/plans/2026-10-19-subproject-1-design-system.md`; PRs #195–#197, #199, #200, #202–#209 and #211; last merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5`). Brand-ready, not branded: the `--sup-*` token system and the accessible React component library, with a committed deterministic build and the permanent Admin UI Certification (`.github/workflows/admin-ui-certification.yml`). Package 224 files, SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4` (not owner accepted). Evidence, the SP2 rules carried from SP1 and the owner manual test list: `docs/project/evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md`.
- Owner decision 2026-10-20 (binding): the owner shares the full revamp, branding and information architecture only once functionality, security and performance are verified. The program after Sub-project 1 is therefore a hardening program: a full audit of functionality, security and performance, then a fix loop. Sub-project 2 screens, the wizard IA, the menu structure and the visual checkout redesign wait for the owner's information architecture and branding.
- Hardening program (repository-executable fix loop) — **DONE / VERIFIED** (2026-10-23).
  - **Audit:** six audits (A1 to A6).
  - **Fixes:** #215 `376c482`; batch 1 #221 `e3884ca`; batch 2 #222 `3575721`; batch 3 #223 `f3e207d` (R1-1, R1-2).
  - **Batch 4** (`hardening/batch-4`, PR open): A1-03 note dedupe, note-lock token release, N-2, N-3, M-5 rest, O4 copy, A2-02 revised (https-only live links, with an `*.upayments.com` http to https upgrade), H12-META, AS-1 and the Subscription test-isolation fix. H12-META was a real WooCommerce meta-read defect: the historical order-meta read assumed index 0 and called a `WC_Meta_Data::get_value()` that WooCommerce lacks, so on real stores save-card, selected-card and subscription checkout failed closed at the persistence readback and Phase 9I could not run. A6-06 (PHPStan stubs) stays recorded as tooling backlog. The owner staging check of save-card, selected-card and subscription checkout is deferred until UPayments enables white-label.
  - **Package:** 233 files, SHA-256 `2a8e1aba7e056151a5995c9147eccfd2700efe866ac7b8c36ed87f2b17def6db` at the batch 4 head (not owner accepted). The owner-verified batch 3 package `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8` is historical.
  - **Parked:** none. A2-02 is fixed in batch 4, revised by the UPayments question 12 evidence.
  - **Evidence:** [`docs/project/evidence/HARDENING-PROGRAM-2026-10-23.md`](../docs/project/evidence/HARDENING-PROGRAM-2026-10-23.md).
- Owner staging verification of the hardened package — **DONE / OWNER-VERIFIED** (2026-10-24): the owner installed the 233-file package (`main` @ `f3e207d`, installed-tree fingerprint `f236d64d7655254cb4b77c48a9d240020aa9f5184c51d6ba3432689722cb172d`) on the My Organic World staging copy; setup checks, English and Arabic checkout in KWD, AED and OMR, two sandbox payments (CAPTURED), the write-only API key, the paused-renewals note and the final-switch refusal of the official plugin all passed; the new `currency_decimals` check warned once and passed after the owner set AED, SAR and QAR to 2 decimals. The package is not owner accepted as a release. [Evidence](../docs/project/evidence/OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md).
- Current executable gate: **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)**.
- Sub-project 2 admin screens begin only after the owner shares the information architecture and branding; then Sub-projects 3–5.

Release gate (open): **external certification & release readiness**. Public release authorization remains false.

## Successor — post-T3 ecosystem hardening (history)

Canonical plan:

`docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`

R0/R1/E1-E3 integration milestone: PR #108 — **MERGED** to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`; temporary branch deleted.

Execution state:

- R0 — **DONE / VERIFIED**
- R1 — **DONE / VERIFIED**
- E1 — **DONE / VERIFIED**
- E2 repository-executable generic — **DONE / CERTIFIED**
- E3 repository-executable runtime evidence — **DONE / VERIFIED**

E3 repository-executable exact-head checkpoint: `540b733c29656758f2392817649fc3d4a4db585d`.

At that exact E3 head, Quality/H12, all 20 Compatibility cells + Compatibility Gate, Provider Sandbox, WordPress.org Submission Check, Release Artifact and the complete repository-owned Ecosystem Certification matrix succeeded. Delayed/combined/repeated Classic JS characterization is 26 PASS / 0 FAIL; analytics-return characterization is 27 PASS / 0 FAIL. Deterministic candidate package: 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

GitHub default CodeQL JavaScript/TypeScript did not reach a terminal verdict for that historical SHA. Descendant merge/release qualification still requires CodeQL/security green.

The gate after that checkpoint was R6 final qualification, since **DONE / VERIFIED** (history).

## R2 implementation contract (closed)

R2 **DONE / VERIFIED**. Certified PR head `5a4f83efa7bda0b5d6169811308800270c888d6c`, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`, package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3`.

Required behavior delivered:

1. WC_Upayments owns a platform resolver calling `WC()->api_request_url('wc_upayments')`; CheckoutOrchestrator receives it as an explicit dependency and never discovers `WC()`.
2. Valid callback generation when `home_url != site_url`, WordPress is in a subdirectory, and plain/index/permalink layouts differ.
3. Trusted HTTPS/public-origin behavior through WordPress/WooCommerce configuration; no raw forwarded-header trust.
4. Explicit no-cache response semantics for public callback/status surfaces without altering payment authority, redirect behavior or webhook termination.
5. Permanent `ecosystem-callback-cache` regression plus real portability/HTTP fixtures.

## R3 / R4 after R2

R3: exact auto-deduct response/economic/identity binding, no first-card fallback, valid parent discovery beyond `completed`, customer control policy, provider-confirmed token retention, held-cycle reconciliation and immutable cycle economics.

R4: due-work orchestration with Action Scheduler preference, bounded batches, durable cycle journal as idempotency authority, queue/held-cycle observability, representative load/concurrency/failure-injection evidence.

## R5/T4 gate

R5/T4 callback consolidation is **DONE / VERIFIED** under Option B / ADR-003 (`handle_compat_callback` explicit-mode seam; legacy public methods retained; financial lifecycle is PaymentLifecycle only; legacy private verifier retired; provider egress unchanged). Certified PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`.

## Approach 3 closeout

Approach 3 closes only after all approved tranches pass exact-head gates, protected identities remain intact, remaining manual/external evidence is explicit, a fresh-clone owner technical re-acceptance is completed, and a new accepted source/package coordinate is recorded.

Public release authorization remains separate and false until explicitly granted.
