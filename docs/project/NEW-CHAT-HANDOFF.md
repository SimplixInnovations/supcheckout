# SUPCheckout for UPayments — Continuation Handoff

**Mandatory first step:** read [`START-HERE.md`](START-HERE.md), then verify live GitHub/source/check state. Chat memory is not authority.

Use this with [`AGENTS.md`](../../AGENTS.md), [`PROJECT-STATUS.md`](PROJECT-STATUS.md), [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md), [`NAMING-IDENTITY-STANDARD.md`](NAMING-IDENTITY-STANDARD.md), [`../COMPATIBILITY.md`](../COMPATIBILITY.md), and [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md).

## Identity and immutable boundaries

- Product: **SUPCheckout for UPayments**
- Provider: **UPayments**
- Repository: `SimplixInnovations/supcheckout`
- Technical slug / text domain: `supcheckout`
- PHP namespace: `Simplixi\SUPCheckout`
- Package root/bootstrap: `supcheckout/UPayments.php`
- Development version: `0.1.0`

Protected compatibility identities include `upayments`, `woocommerce_upayments_settings`, `wc_upayments`, `_upay_*`, `UPayments_order_id`, H12 token/provenance identities, subscription/billing-attempt identities, historical payment-method values, frozen Phase 9I identities, `getAPIUrlForRetreiveCards()`, and `whitelabled`.

SUPCheckout remains UPayments-only. Do not introduce generic provider routing.

## Frozen owner-accepted regression baseline

- source SHA: `0c883d609906676966002eb022a82a9656eeacc5`;
- package: `supcheckout-0.1.0.zip`;
- files: 51;
- SHA-256: `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

Do not move this anchor without an explicit fresh owner acceptance event.

## Merged Approach 3 history

- T1 — **DONE / VERIFIED**.
- T2 — **DONE / VERIFIED / runtime-bearing**, merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`.
- T3 — **DONE / VERIFIED / runtime-neutral**, certified PR #107 head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`, merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`.
- Quality Platform Q1-Q19 — **DONE / VERIFIED; permanently closed at Q19**. Do not invent Q20.
- Enterprise Tasks 1-8 — **DONE / VERIFIED**.

T3 proved that a direct `return_from_upayments()` caller may not carry the GET `page` marker used by `PaymentLifecycle::handle_callback()` to infer browser mode. **R5/T4 consolidation therefore requires its own architecture decision.**

## Current program

Current program: **`post-t3-ecosystem-hardening`**
Plan: `docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`
R0/R1/E1-E3 integration milestone: **PR #108 — MERGED to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`; temporary branch deleted**
Publication: **NOT AUTHORIZED**

R0, R1 and E1 are **DONE / VERIFIED and integrated through PR #108**. Repository-executable generic E2 is **DONE / CERTIFIED**.

## Latest repository-executable E3 runtime checkpoint

`540b733c29656758f2392817649fc3d4a4db585d`

At that exact SHA:

- Quality Gates / H12 — **SUCCESS**;
- Compatibility Certification — **20/20 runtime cells + Compatibility Gate SUCCESS**;
- Ecosystem Certification — **SUCCESS** across five free themes with child-theme overrides, four free cache/optimizer coexistence lanes and current-runtime legacy+HPOS lanes;
- Provider Sandbox Certification — **SUCCESS**;
- WordPress.org Submission Check — **SUCCESS**;
- Release Artifact — **SUCCESS**;
- delayed/combined/repeated Classic JS lifecycle harness — **26 PASS / 0 FAIL**;
- analytics-return characterization — **27 PASS / 0 FAIL**;
- deterministic package — 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

E3 repository-executable runtime evidence is **DONE / VERIFIED** at this checkpoint.

The historical GitHub default CodeQL JavaScript/TypeScript job for `540b733c29656758f2392817649fc3d4a4db585d` did not reach a terminal verdict. Do not claim CodeQL success for that SHA. Descendant merge/release qualification still requires CodeQL/security green.

E3 does not certify paid/licensed themes/plugins, Cloudflare/Rocket Loader/server cache configurations, broad browser/device visuals, or third-party analytics deduplication.

## Current execution order

Current executable gate: **R3 subscription safety**.

R2 is **DONE / VERIFIED** (PR #110 certified head `5a4f83efa7bda0b5d6169811308800270c888d6c`, squash-merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`, package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3`).

1. **R3:** eliminate first-card fallback; exact auto-deduct economic/identity binding; dedicated response verifier; immutable cycle snapshot; parent discovery; pause/resume/cancel contract; held-cycle reconciliation without blind replay.
2. **R3:** bind auto-deduct result to exact amount/currency/parent/cycle/provider identity; remove first-card fallback; broaden valid parent discovery; define customer control, token retention and held-cycle reconciliation.
3. **R4:** replace historical hourly scanning with due-work orchestration, preferably Action Scheduler; keep the durable cycle journal authoritative; add observability/load/concurrency/failure-injection evidence.
4. **R5/T4:** separately approve architecture before callback consolidation.
5. **R6:** immutable exact-head qualification, remaining manual/external evidence, fresh owner re-acceptance and explicit version/publication decision.

## Immediate R3 TDD boundary

Required RED behavior:

- missing explicit renewal card token must produce ZERO auto-deduct POSTs even when saved cards exist;
- auto-deduct success must not complete a renewal without exact amount/currency/parent/cycle binding;
- truthy provider `status` alone must not authorize paid state without proven capture authority;
- HELD cycles must never auto-replay merely because time passed.

Minimal GREEN must correct subscription renewal safety only. No T4 consolidation belongs in R3.

## Permanent invariants

- Browser/provider routing data is not payment truth.
- Charge creation is not capture.
- Paid state requires authenticated provider verification bound to correct order/attempt/economics.
- Finalized Woo order amount/currency is authoritative; `products[]` is descriptive.
- No blind retry of non-idempotent Charge/refund/auto-deduct mutations.
- Ambiguous identity fails closed.
- Runtime fixes use RED → minimal GREEN → exact-head full recertification.

## Main-rule/release controls

Required protected-branch checks remain `Governance`, `H12 Regression Harness`, `Compatibility Gate` and `Release Gate`. Fresh live qualification also accounts for CodeQL/security, Provider Sandbox, WordPress.org Submission Check, deterministic release evidence, dependency audit and review-thread resolution.

## External/manual boundary

Do not claim repository automation proves production merchant payment completion, real wallet/device behavior, WPML/WCML/multilingual/multicurrency/RTL, broad browser/theme/accessibility, representative load, penetration/PCI/legal attestation, live non-idempotent subscription auto-deduction, or unexecuted paid/licensed/vendor infrastructure.

Live GitHub/source/check state always wins over this handoff.
