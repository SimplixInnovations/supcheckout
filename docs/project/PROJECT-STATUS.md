# SUPCheckout for UPayments — Project Status

**Status document:** canonical living engineering state
**Last reconciled:** 2026-09-11
**Canonical repository:** `SimplixInnovations/supcheckout`
**Development version:** `0.1.0`
**Owner technical acceptance:** **ACCEPTED for Approach 3** (`OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`)
**Accepted Approach 3 source:** **`146d65a1c182630c1acc651cacafe30cff5f6b79`**
**Accepted Approach 3 package:** `supcheckout-0.1.0.zip` — 62 files / SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`
**Historical Approach 2 baseline (superseded):** `0c883d609906676966002eb022a82a9656eeacc5`
**Accepted package:** `supcheckout-0.1.0.zip` — 51 files / SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`

> Live GitHub source, exact-head checks and package evidence override this document if they differ. Historical milestone records stay historical; this file records current engineering truth.

## Executive status

| Area | Current state |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Provider scope | **UPayments only** |
| Technical slug / text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| First-stable bootstrap | `supcheckout/UPayments.php` — intentional compatibility exception |
| Approach 2 | **DONE / VERIFIED / OWNER ACCEPTED** |
| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |
| Final pre-clone runtime/QA closure | **DONE / VERIFIED — PR #75** |
| Approach 3 T1 | **DONE / VERIFIED** |
| Approach 3 T2 | **DONE / VERIFIED / runtime-bearing** |
| Approach 3 T3 | **DONE / VERIFIED / runtime-neutral** |
| Latest integrated post-T3 milestone main | `d69377d3e26831270a00151025d24cc64be9d36b` |
| Active program | `post-t3-ecosystem-hardening` — R2 **DONE / VERIFIED**; R3 **DONE / VERIFIED** |
| E2 repository-executable generic | **DONE / CERTIFIED** |
| E3 repository-executable runtime evidence | **DONE / VERIFIED** |
| Latest E3 runtime checkpoint | `540b733c29656758f2392817649fc3d4a4db585d` |
| R2 | **DONE / VERIFIED** — PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`; package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3` |
| R3 | **DONE / VERIFIED** — PR #112 certified head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`; candidate package SHA-256 `070279120064a6fe558dbeef3702a10b90330b8dc79fd9f21f028cd5fefba4da` (NOT owner accepted) |
| R4 | **DONE / VERIFIED** — PR #114 certified head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`; package 62 files SHA-256 `2543c2a0bfdba53e8968bd1a09b263a3a2b7bd02a11eabd41c102aa5d6dc7b8b` (NOT owner accepted) |
| R5 | **DONE / VERIFIED** — PR #116 certified head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` (NOT owner accepted) |
| Current executable gate | **publication decision — NOT AUTHORIZED** |
| Public tag / GitHub Release | **NOT CREATED / NOT AUTHORIZED** |
| WordPress.org publication | **NOT PERFORMED / NOT AUTHORIZED** |

No Q20 is justified. New work uses named bounded engineering tranches.

## Frozen regression authority

| Field | Value |
|---|---|
| Owner-accepted Approach 3 source | `146d65a1c182630c1acc651cacafe30cff5f6b79` |
| Accepted package | `supcheckout-0.1.0.zip` |
| Accepted package files | `62` |
| Accepted package SHA-256 | `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` |
| Historical Approach 2 package (superseded) | 51 files / `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655` |

Approach 3 is the current owner-accepted technical baseline. A fresh explicit owner acceptance event is required to replace it. Publication remains **NOT AUTHORIZED**. Unresolved provider contracts remain uncertified.

## Approach 3 merged coordinate

T3 was squash-merged through PR #107 to `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`. Its exact certified PR head was `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`.

T3 is runtime-neutral and permanently characterizes `WC_Upayments::return_from_upayments()`, `WC_Upayments::web_hook_handler()`, and private `WC_Upayments::verify_payment_status()`.

Direct callers of `return_from_upayments()` may not carry the WC-API GET `page` marker used by `PaymentLifecycle::handle_callback()` for browser-mode inference. T3 therefore does **not** authorize naive T4 delegation.

The completed post-T3 R0/R1/E1-E3 milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. T2 `047cc86060efb97761d7a0cc4a3806f971ab6fe1` remains the last separately tracked runtime-bearing Approach 3 tranche before that integrated milestone.

## Post-T3 hardening

Canonical plan: [`../superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`](../superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md).

Completed integration milestone: PR #108 / R0/R1/E1-E3, squash-merged to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. The temporary branch was deleted after merge.

E3 repository-executable exact-head checkpoint:

`540b733c29656758f2392817649fc3d4a4db585d`

At that exact SHA:

- Quality Gates / H12 — **SUCCESS**;
- Compatibility Certification — **20/20 runtime cells + Compatibility Gate SUCCESS**;
- Ecosystem Certification — **five free parent themes with real child-theme overrides, four free cache/optimizer coexistence lanes, current-runtime legacy+HPOS lanes + Ecosystem Gate SUCCESS**;
- Provider Sandbox Certification — **SUCCESS**;
- WordPress.org Submission Check — **SUCCESS**;
- Release Artifact — **SUCCESS**;
- delayed/combined/repeated Classic JS lifecycle harness — **26 PASS / 0 FAIL**;
- analytics-return characterization — **27 PASS / 0 FAIL**;
- deterministic installable package — **55 files**, SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

The GitHub default CodeQL JavaScript/TypeScript job for this historical checkpoint did **not** reach a terminal verdict. That nonterminal hosted-security result is not a plugin failure, but it is also not a success claim. Every descendant merge/release boundary still requires CodeQL/security green.

### E3 evidence boundary

Repository automation now proves the bounded behavior it actually exercised:

- Storefront 4.6.2, Twenty Twenty-Five 1.5, Astra 4.13.11, Blocksy 2.1.56 and Kadence 1.5.2;
- real child-theme WooCommerce template override precedence;
- LiteSpeed Cache 7.9.1, W3 Total Cache 2.10.6, Breeze 2.5.13 and SiteGround Speed Optimizer 7.8.2 coexistence;
- delayed-first-interaction, duplicate/combined evaluation and repeated fragment replacement behavior;
- browser return/replay financial idempotency and stable redirect identity.

This does **not** certify unavailable paid/licensed themes/plugins, Cloudflare custom rules, Rocket Loader, host-specific full-page caches, browser/device visual behavior or third-party analytics deduplication.

The analytics characterization establishes that replayed verified browser callbacks do not mutate financial state but can repeat the same order-received navigation. SUPCheckout itself emits none of the tested GA/Meta/GTM purchase APIs; downstream trackers remain responsible for their own deduplication.

## R0 anti-staleness evidence

R0 permanently introduced current-state regression coverage. For the E3→R2 transition, RED `715b0b8671573a1a4d5ef1d7d998cf00da64e611` produced 230 tests / 1,578 assertions / exactly two intended stale-state failures. The matching living-state reconciliation must keep that test GREEN.

## Remaining post-T3 program

The overall program is **not finished**.

Current executable gate: **publication decision — NOT AUTHORIZED**. Provider contracts remain unresolved; automatic recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED.

R5 callback lifecycle consolidation is **DONE / VERIFIED** (Option B / ADR-003; PR #116 certified head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`).

R4 subscription scalability/observability is **DONE / VERIFIED** (PR #114 certified head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`). External provider blockers remain open: auto-deduct capture **UNPROVEN**, remote cycle identity **UNPROVEN**, HMAC **PROVIDER CLARIFICATION REQUIRED**, token-storage **PROVIDER CLARIFICATION REQUIRED**. Automatic paid-renewal finalization remains **FAIL-CLOSED / HELD** until provider evidence exists.

R3 subscription safety is **DONE / VERIFIED** (PR #112 certified head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`). External provider blockers remain open: auto-deduct capture semantics **UNPROVEN**, remote exact cycle identity **UNPROVEN**, HMAC **PROVIDER CLARIFICATION REQUIRED**, token-storage contract **PROVIDER CLARIFICATION REQUIRED**. Automatic paid-renewal finalization remains **FAIL-CLOSED / HELD** until provider evidence exists.

1. **R2 — DONE / VERIFIED:** Woo API URL abstraction via injected platform resolver; `home_url` vs `site_url`; subdirectory, permalink/index and trusted proxy/public-origin behavior; explicit no-cache callback/public-status semantics.
2. **R3 — subscription safety:** exact auto-deduct amount/currency/parent/cycle/provider binding; no first-card fallback; parent discovery beyond `completed`; customer cancel/pause/resume policy; token-retention contract; held-cycle reconciliation.
3. **R4 — scalability/operations:** due-work scheduling/Action Scheduler, bounded batches, durable cycle-journal authority, observability, load/concurrency/failure injection and queue health.
4. **R5 / T4 — callback consolidation:** DONE / VERIFIED under Option B / ADR-003. Legacy public compatibility methods retained; financial lifecycle is PaymentLifecycle only.
5. **R6 — final release qualification:** immutable exact-head gates, manual/external qualification, fresh owner re-acceptance and explicit version/publication decision.

## Permanent payment/security invariants

1. Routing input is never financial truth.
2. Charge initialization is not capture.
3. Captured/paid state requires authenticated provider status bound to the correct attempt/order/economics.
4. Finalized WooCommerce order amount/currency is authoritative; provider `products[]` is descriptive.
5. Shipping, tax, fees, coupons and order-bump economics must be finalized before Charge.
6. Post-dispatch economic mutation cannot silently alter payment authority.
7. Non-idempotent Charge/refund/auto-deduct mutations are never blindly retried.
8. Customer/card/provider identity ambiguity fails closed.
9. Protected persisted/provider identities require an approved migration contract.
10. Runtime corrections use RED → minimal GREEN → exact-head recertification.

## Protected compatibility identities

Protected contracts include `upayments`, `woocommerce_upayments_settings`, Blocks identity `upayments`, callback `wc_upayments`, historical `_upay_*` metadata, `UPayments_order_id`, H12 token/provenance/scope/generation state, subscription/billing-attempt identities, historical order payment-method values, frozen Phase 9I identities, `getAPIUrlForRetreiveCards()`, and normalized `whitelabled`.

## Repository/release governance

Required protected-branch checks remain `Governance`, `H12 Regression Harness`, `Compatibility Gate`, and `Release Gate`.

Exact-head qualification also accounts for CodeQL/security, Provider Sandbox, WordPress.org Submission Check, deterministic cross-platform release evidence, locked dependency audit where applicable, review-thread resolution and post-merge verification.

Tags, GitHub Releases and WordPress.org publication remain prohibited until explicit owner authorization.

## Evidence boundaries

Repository certification does not replace production merchant payment completion, real wallet/account/device completion, WPML/WCML/multilingual/multicurrency/RTL qualification, broad browser/theme/accessibility testing, representative production-store load, penetration/PCI/legal attestation, or live non-idempotent subscription auto-deduction evidence.

Automatic WooCommerce refunds and arbitrary marketplace multi-split remain unsupported.

See [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md), [`NEW-CHAT-HANDOFF.md`](NEW-CHAT-HANDOFF.md), [`../COMPATIBILITY.md`](../COMPATIBILITY.md), and [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md).
