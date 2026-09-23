# SUPCheckout for UPayments — Repository Agent Instructions

These instructions apply repository-wide. Nested instructions may tighten them but must never weaken payment, security, compatibility or release invariants.

## Read first

Before substantive work, read in this order:

1. `docs/project/START-HERE.md` — mandatory session bootstrap, program sequence and current operational gate
2. `docs/project/PROJECT-STATUS.md` — current verified engineering state
3. `docs/project/OWNER-HANDOFF.md` — fresh-clone/local/release sequence
4. `docs/project/NAMING-IDENTITY-STANDARD.md` — canonical identity and protected IDs
5. `docs/COMPATIBILITY.md` — public compatibility/evidence boundary
6. `docs/project/NEW-CHAT-HANDOFF.md` — compact continuation context
7. `docs/project/RELEASE-ENGINEERING.md` — deterministic package/migration contract
8. `docs/project/ENTERPRISE-CERTIFICATION.md` — retained certification evidence
9. relevant historical phase/quality records when touching their contracts
10. `docs/project/BASELINE-H12.md` when token/saved-card/subscription identity is relevant

### Session-bootstrap rule

Never begin substantive work from chat memory, an old handoff message or a copied SHA alone.

Every new chat, machine, clone, worktree or developer/AI-agent session must first follow `docs/project/START-HERE.md` and verify live GitHub/source/check state. If live evidence and a living document differ, reconcile current truth before implementation or release claims.

For every substantive active task, the open GitHub PR is the canonical task-level ledger. It must make the work reconstructable by recording base SHA, current exact head, scope/non-scope, current verification gate, blockers/failures, next action and merge-readiness evidence. `START-HERE.md` remains the program-level ledger; do not turn it into an append-only diary.

## Canonical identity

- Product: **SUPCheckout for UPayments**
- Short name: **SUPCheckout**
- Maintainer: **Simplix Innovations**
- Provider: **UPayments**
- Repository: `SimplixInnovations/supcheckout`
- Slug / text domain: `supcheckout`
- PHP namespace: `Simplixi\SUPCheckout`
- New first-party global prefix: `supcheckout_`
- Constants: `SUPCHECKOUT_*`
- Package root: `supcheckout/`
- First-stable bootstrap: `supcheckout/UPayments.php`
- Development version: `0.1.0`

The word **for** is relationship copy only. Never encode it into repository URLs, WordPress.org slug, package names, namespaces, CSS/JS roots, REST namespaces or release artifacts. Do not invent alternate product names, slugs, prefixes or namespaces.

## Provider boundary

SUPCheckout is permanently **UPayments-only**.

- Do not add unrelated payment-provider adapters here.
- Do not turn this repository into cross-provider routing/orchestration.
- Future provider integrations are independent products/repositories.
- Shared engineering practices may be reused; payment runtime ownership stays isolated unless separately designed and approved.

## Freshness rule

Live evidence beats recorded status. Before implementation, review or release:

- verify live `main`;
- inspect branches, open PRs/issues, tags/releases;
- inspect exact source/diff;
- inspect exact-head CI/check state;
- distinguish runtime-bearing baselines from later docs/presentation descendants;
- reconcile living status docs when project truth changes;
- use current provider/platform documentation where behavior depends on it.

Historical records may intentionally contain former product names, repository coordinates and old SHAs. They are evidence, not current branding guidance.

## Current engineering state

Repository Foundation, Phase 0, Phase 9I, Provider Payment Lifecycle, Security Threat Model, Architecture A1-A5, Quality Platform Q1-Q19, Enterprise Tasks 1-8, Approach 2 and the bounded pre-acceptance hardening sequence are **DONE / VERIFIED**. Quality Platform is permanently closed at Q19. **Never invent Q20.**

The frozen owner-accepted Approach 2 regression reference remains:

- source `0c883d609906676966002eb022a82a9656eeacc5`;
- deterministic 51-file package SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

Approach 3 architecture is **APPROVED / RECORDED**:

- T1 architecture guardrails/active-callback characterization — **DONE / VERIFIED**, merged main `beb89ac0c4d8c0e9b7c8b2de1e13c237bbd37b15`;
- T2 legacy callback fallback consolidation — **DONE / VERIFIED / runtime-bearing**, merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`, 51-file candidate SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`;
- T3 direct legacy callback/private-verifier characterization — **DONE / VERIFIED / runtime-neutral**, PR #107 certified head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`, merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`.

T3 proved that a direct `return_from_upayments()` caller may lack the WC-API GET `page` marker used by `PaymentLifecycle::handle_callback()` to infer browser mode. Therefore T3 does **not** authorize naïve T4 delegation or deletion of compatibility methods.

The current successor program is **post-t3-ecosystem-hardening**, governed by `docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`, with active draft PR #108 on `audit/post-t3-ecosystem-hardening`.

The latest repository-executable E3 runtime checkpoint inside PR #108 is `540b733c29656758f2392817649fc3d4a4db585d`. Quality/H12, all 20 Compatibility cells plus Compatibility Gate, Provider Sandbox, WordPress.org Submission Check, Release Artifact and the complete Ecosystem Certification matrix succeeded at that exact head. The E3 matrix covers five free parent themes with real child-theme template overrides, four legally runnable free cache/optimizer coexistence plugins, delayed/combined/repeated Classic script execution, and analytics-return replay characterization. The GitHub default CodeQL JavaScript/TypeScript job did not reach a terminal verdict at that historical checkpoint, so do not describe CodeQL as successful for that SHA. Its deterministic package is 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

Neither T2, T3 nor PR #108 silently redefines owner acceptance. A fresh owner acceptance is required at Approach 3 closeout.

Current post-T3 state: R0, R1 and E1 are **DONE / VERIFIED** on PR #108; repository-executable generic E2 is **DONE / CERTIFIED**; E3 repository-executable runtime evidence is **DONE / VERIFIED** at the exact checkpoint above. Named paid/licensed/vendor-infrastructure qualification remains external/unverified until actually exercised. The current executable gate is **R2 callback portability / cache safety**, followed by R3 subscription safety, R4 scalability/idempotency/observability, separately gated R5/T4 architecture decision, and R6 final exact-head qualification plus owner re-acceptance.

No public GitHub Release or WordPress.org publication is authorized.

## Protected compatibility identities

Never mechanically/global-replace provider/payment/persisted identities. Protected by default:

- gateway/payment method ID `upayments`;
- `woocommerce_upayments_settings`;
- Blocks / Store API identity `upayments`;
- callback route `wc_upayments`;
- `_upay_*` historical metadata;
- `UPayments_order_id` and related provider-order identities;
- `upayments_token_identity_secret_v2` and H12 provenance/scope/generation keys;
- `upay_process_subscriptions` and billing-attempt state;
- historical order payment-method identity;
- frozen Phase 9I migration identities;
- provider API field/path/schema terminology;
- public compatibility wrapper `getAPIUrlForRetreiveCards()`;
- normalized `whitelabled` compatibility shape.

Changing one requires an explicitly approved migration contract with old/new precedence, upgrade, rollback/failure semantics and regression evidence.

## First-stable bootstrap exception

`supcheckout/UPayments.php` is intentional. Real WordPress qualification proved that directly renaming an already-active physical main file can strand WordPress's stored plugin basename. A future `supcheckout.php` physical rename requires a separately approved migration.

## Permanent quality controls

Do not remove, skip, soften or blanket-ignore:

- `.github/workflows/quality-gates.yml`;
- `.github/workflows/compatibility-certification.yml`;
- `.github/workflows/ecosystem-certification.yml`;
- `.github/workflows/provider-sandbox-certification.yml`;
- `.github/workflows/release-artifact.yml`;
- `.github/workflows/wordpress-org-submission-check.yml`;
- CodeQL/security analysis;
- architecture harnesses;
- Quality Platform Q1-Q19 harnesses;
- security threat-model harness;
- Phase 0 / Phase 9I / Provider Lifecycle harnesses;
- H12 PHP and Blocks harnesses;
- SUPCheckout identity/namespace/frontend/residue/HTTP/provenance harnesses;
- real integration fixtures for activation, metadata, Blocks, HPOS, saved cards, subscriptions, multi-merchant, operations and upgrade compatibility;
- deterministic artifact builder/verifier/harness;
- official packaged Plugin Check.

The H12 job must fail when required upstream quality/syntax prerequisites fail or skip. Compatibility headers and public claims require real runtime evidence; static/unit/H12 success alone cannot broaden support claims.

## Provider automation boundary

Automated provider traffic may use only explicitly documented public sandbox/test credentials or separately authorized repository test secrets. Never use production merchant credentials in CI. Routine provider certification stays bounded: do not add payment completion, polling loops, refunds, saved-card mutation or subscription auto-deduction merely to make CI look broader.

## Payment/security rules

- Evidence before claims.
- Characterize before changing behavior.
- Routing/request input is never financial truth.
- Charge initialization is not capture.
- Paid state requires authenticated provider status bound to the correct order/transaction/economics.
- Finalized WooCommerce order amount/currency is payment authority; provider `products[]` is descriptive.
- Fail closed on ambiguous payment/security identity.
- Never blindly retry non-idempotent Charge/refund/auto-deduct operations.
- Preserve H12 token/provenance contracts unless an approved migration supersedes them.
- Never expose merchant API secrets/bearer tokens, card data, customer/card tokens, provenance secrets, unnecessary PII or production database exports.
- Uninstall remains non-destructive by default.

## Public compatibility boundaries

Do not imply certification beyond `docs/COMPATIBILITY.md`.

External/manual unless separately proven: production merchant payment completion; real wallet/account/device completion; WPML/WCML/multilingual/multicurrency/RTL; broad browser/device/theme/accessibility; representative performance/load; penetration testing, PCI or legal/compliance attestation; live subscription auto-deduction; provider webhook signature verification until a stable documented contract exists.

Unsupported: automatic WooCommerce refunds; arbitrary marketplace multi-split beyond one additional merchant.

## Repository presentation rules

The root README is a public product landing page, not an internal certification ledger. Keep public copy concise and factual; put deep run/SHA evidence in project-control docs; centralize legal/provenance language in `NOTICE.md` / `UPSTREAM.md`; do not add unsupported badges/topics or stale screenshots.

## Change discipline

- Use the authorized dedicated branch for the current tranche; do not implement on `main`.
- Use TDD for production behavior/bug fixes: meaningful RED first, prove the RED, minimal GREEN, affected + permanent regressions, then exact-head recertification.
- Keep changes bounded; no drive-by payment refactors.
- Do not grow `UPayments.php` with new responsibilities.
- Large compatibility-sensitive files are not refactored solely for aesthetics.
- Update living state docs when verified project truth changes.
- Preserve historical records instead of rewriting milestone facts.
- Do not create a new phase merely because documentation needs maintenance.
- R5/T4 callback consolidation requires a separate architecture decision; T3 characterization alone is not approval.

## Merge and release discipline

External AI/bot output is evidence input, not authority. Before merging runtime/release-sensitive work require the exact head to satisfy at minimum:

- Quality/H12 green;
- Compatibility Gate green with all 20 runtime cells successful;
- Release Artifact including packaged + migration cells green;
- bounded Provider Sandbox green when applicable;
- WordPress.org Submission Check green;
- CodeQL/security green;
- locked dependency audit where applicable;
- zero unresolved valid review threads;
- exact-head mergeability;
- squash-only merge;
- post-merge verification on `main`.

Documentation/presentation-only changes still require all workflows triggered by their paths. Do not fabricate runtime changes to force unrelated work.

If required verification fails:

`NOT APPROVED.`
`DO NOT MERGE.`
