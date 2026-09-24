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

## Current successor — post-T3 ecosystem hardening

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

Current executable gate: **R5/T4 architecture decision**.

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

R5/T4 callback consolidation is **not pre-approved**.

Before implementation:

- E1/E2/R2 request-shape and callback evidence must be stable;
- compare thin compatibility adapters with lifecycle consolidation;
- solve direct-browser request-shape normalization;
- preserve payment authority/idempotency/cache semantics;
- record/approve the architecture decision;
- then use TDD and full recertification.

## Approach 3 closeout

Approach 3 closes only after all approved tranches pass exact-head gates, protected identities remain intact, remaining manual/external evidence is explicit, a fresh-clone owner technical re-acceptance is completed, and a new accepted source/package coordinate is recorded.

Public release authorization remains separate and false until explicitly granted.
