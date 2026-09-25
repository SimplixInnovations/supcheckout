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
- R2 — **DONE / VERIFIED**, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`.
- R3 — **DONE / VERIFIED**, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`.
- R4 — **DONE / VERIFIED**, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`.
- R5 — **DONE / VERIFIED / latest runtime-bearing**, Option B / ADR-003; certified PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`.
- Quality Platform Q1-Q19 — **DONE / VERIFIED; permanently closed at Q19**. Do not invent Q20.
- Enterprise Tasks 1-8 — **DONE / VERIFIED**.

T3 proved that a direct `return_from_upayments()` caller may not carry the GET `page` marker used by `PaymentLifecycle::handle_callback()` to infer browser mode. R5 Option B solved this with an explicit-mode seam (`handle_compat_callback`) rather than superglobal spoofing.

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

Current executable gate: **R6 final qualification**.

R5 is **DONE / VERIFIED** (PR #116 certified head `6fc225fc736da107de533ba8e19a12dc5c37227d`, squash-merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`, package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`).

R2 is **DONE / VERIFIED** (PR #110 certified head `5a4f83efa7bda0b5d6169811308800270c888d6c`, squash-merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`, package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3`).

R3 is **DONE / VERIFIED** (PR #112 certified head `de0162b4a1cca77c62f07290224b055902120c2a`, squash-merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`, candidate package SHA-256 `070279120064a6fe558dbeef3702a10b90330b8dc79fd9f21f028cd5fefba4da` — NOT owner accepted). External blockers remain: auto-deduct capture **UNPROVEN**, remote cycle identity **UNPROVEN**, HMAC **PROVIDER CLARIFICATION REQUIRED**, token storage **PROVIDER CLARIFICATION REQUIRED**. Automatic paid-renewal finalization stays **FAIL-CLOSED / HELD**.

1. **R3:** DONE / VERIFIED — first-card elimination, economic/identity binding, immutable cycle snapshot, parent discovery, pause/resume/cancel, held-cycle reconciliation without blind replay.
3. **R4:** DONE / VERIFIED — bounded HistoricalEnrollment (BATCH_SIZE=50), Action Scheduler group `supcheckout`, args `{parent_order_id, cycle_due_gmt, retry_attempt}`, CycleClaim remains provider-mutation authority, retries 0..3 (+1h/+6h/+24h), HELD/dispatching/ambiguous never auto-next-charge.
4. **R5:** DONE / VERIFIED — Option B / ADR-003 explicit-mode seam; legacy public compatibility methods retained; financial lifecycle is PaymentLifecycle only; legacy private verifier retired; provider egress unchanged.
5. **R6:** immutable exact-head qualification, remaining manual/external evidence, fresh owner re-acceptance and explicit version/publication decision.

## R5 architecture truth (recorded)

- legacy public compatibility methods: retained
- financial lifecycle: PaymentLifecycle only
- `return_from_upayments`: explicit browser compatibility adapter
- `web_hook_handler`: explicit webhook compatibility adapter
- `check_ipn_response`: normal canonical inference
- historical direct webhook source: `$_REQUEST` canonical callback keys (`wc_order_id`, `track_id`, `requested_order_id`)
- normal WC-API: GET/POST conflict-aware inference
- legacy private verifier: retired
- provider egress: unchanged (3 sites)

Provider blockers remain **UNPROVEN / PROVIDER CLARIFICATION REQUIRED** for auto-deduct capture, remote cycle identity, HMAC and token persistence. Do not promote them.

## Immediate R6 boundary

R5 is closed at certified head `6fc225fc736da107de533ba8e19a12dc5c37227d` / merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`. The current gate is **R6 final qualification**. Approach 2 acceptance unchanged. Publication unauthorized.

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
