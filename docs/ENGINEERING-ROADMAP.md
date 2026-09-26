# SUPCheckout for UPayments — Engineering Roadmap

## Current state (authoritative)

```text
Approach 3: CURRENT OWNER-ACCEPTED TECHNICAL BASELINE (146d65a1c182630c1acc651cacafe30cff5f6b79)
Approach 2: HISTORICAL / SUPERSEDED
Active program: external-certification-release-readiness
Current repository maintenance base: f7a017124d04ced50ea4dae283fe198301dc6eba
Accepted package: supcheckout-0.1.0.zip / 62 files / 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd
Publication: NOT AUTHORIZED
```

Sections below labeled Historical are retained audit evidence and are not current state.


This is the public high-level sequence. `docs/project/PROJECT-STATUS.md` owns current verified state. Historical phase/quality records preserve detailed closeout evidence and are not rewritten into current branding.

## Completed engineering foundations

1. **Repository Foundation & Readiness — DONE / VERIFIED**
2. **Phase 0 — release identity and updater ownership — DONE / VERIFIED**
3. **Phase 9I — historical identity migration — DONE / VERIFIED**
4. **Provider Contract & Payment Lifecycle — DONE / VERIFIED**
5. **Security Threat-Model Closure — DONE / VERIFIED**
6. **Architecture & Code-Quality Foundation A1-A5 — DONE / VERIFIED**
7. **Automated Quality Platform Q1-Q19 — DONE / VERIFIED / CLOSED**

The numbered Quality Platform is permanently closed at Q19. No Q20 is justified merely because administrative, documentation, local-acceptance or release work remains.

## Enterprise completion program — DONE / VERIFIED

The enterprise completion plan is retained at `docs/superpowers/plans/2026-09-06-enterprise-completion.md`.

### Task 1 — quality-program closeout

**DONE / VERIFIED.** Closed the numbered quality sequence at Q19 while retaining the permanent regression stack.

Historical terminal quality gates retained as permanent evidence:

- Q16 migration-core preflight/batch/executor characterization and baseline-free analysis — **DONE / VERIFIED**;
- Q17 payment-runtime checkout-orchestration/lifecycle characterization and baseline-free analysis — **DONE / VERIFIED**;
- Q18 Blocks activation/availability enforcement and permanent regression coverage — **DONE / VERIFIED**;
- Q19 subscription product-eligibility consistency — **DONE / VERIFIED**.

### Task 2 — real platform compatibility

**DONE / VERIFIED.** Permanent 16-cell real WordPress/WooCommerce/PHP × legacy/HPOS matrix covering activation, Classic registration, Blocks registration/availability and Woo order CRUD.

### Task 3 — evidence-derived public declarations

**DONE / VERIFIED.** Public WordPress/WooCommerce/PHP support headers plus Woo `cart_checkout_blocks` / `custom_order_tables` declarations are derived from real runtime evidence.

### Task 4 — bounded provider sandbox

**DONE / VERIFIED.** Controlled public UPayments sandbox Charge initialization verifies the bounded endpoint/transport/schema/payment-link contract without production credentials or broad non-idempotent mutation.

### Task 5 — deterministic installable artifact

**DONE / VERIFIED.** Git-HEAD-bound deterministic ZIP, checksum, per-file manifest, source-byte verification, tamper rejection and packaged real-runtime smoke.

### Task 6 — feature and operational boundaries

**DONE / VERIFIED.** Saved-card/token provenance, subscription eligibility/pre-dispatch, one-additional-merchant allocation and non-destructive lifecycle/data retention.

### Task 7 — existing-install / release identity

**DONE / VERIFIED.** Upgrade/rollback/data/callback/cron continuity and duplicate-package characterization.

Task 7 also produced the important negative proof that changing the physical main filename alone does not preserve WordPress active-plugin identity. That is why first-stable SUPCheckout intentionally uses:

```text
supcheckout/UPayments.php
```

A future physical rename to `supcheckout.php` remains separately gated.

### Task 8 — Enterprise Release Candidate Closeout

**DONE / VERIFIED.** The pre-rebrand enterprise engineering foundation was closed with full exact-head quality, compatibility, artifact, provider and security evidence. Those records remain historical evidence.

## Final SUPCheckout identity migration — DONE / VERIFIED

The first-party identity is now:

- human product: **SUPCheckout for UPayments**;
- technical slug/text domain: `supcheckout`;
- PHP namespace: `Simplixi\SUPCheckout`;
- package root: `supcheckout/`;
- first-stable physical bootstrap: `UPayments.php`;
- canonical GitHub repository: `SimplixInnovations/supcheckout`.

The word `for` is human-facing relationship wording only and never appears in technical identifiers.

### Runtime-bearing certification

PR #58 certified head `5bf84dccb880733da45c1f922d43554af69a33dc` squash-merged as `6aabc4fcb0606567a11637ea07fe081fed4c7f85`.

Post-merge:

- Quality #764 — **SUCCESS**
- Compatibility #292 — **16/16 SUCCESS**
- Release Artifact #243 — **SUCCESS**
- Provider Sandbox #207 — **SUCCESS**
- WordPress.org #101 — **SUCCESS**
- CodeQL #579 — **SUCCESS**
- official packaged Plugin Check — **0 blocking errors**

### Final documentation/control-plane closeout

PR #59 squash-merged as `9591c431e1eb56fe40ca60147afdf9f3f909a212`.

Fresh main evidence:

- Quality #773 — **SUCCESS**
- Compatibility #301 — **all 16 cells SUCCESS**
- Release Artifact #252 — **SUCCESS**
- Provider Sandbox #216 — **SUCCESS**
- WordPress.org #110 — **SUCCESS**
- CodeQL #588 — **SUCCESS**

Later documentation-only maintenance may advance `main` without redefining the historical pre-stable SUCheckout baseline.

### Final SUPCheckout identity + repository closure

PR #67 certified head `0059f365883fa4edd6a2d623c7b370d38d3f565c` squash-merged as `7547e59a2d5ef6d49b059851c6899a2d9987b16a`.

Post-merge:

- Quality #896 — **SUCCESS**
- Compatibility #424 — **16/16 SUCCESS**
- Release Artifact #373 — **SUCCESS**
- Provider Sandbox #334 — **SUCCESS**
- WordPress.org #231 — **SUCCESS**
- CodeQL #717 — **SUCCESS**
- repository rename to `SimplixInnovations/supcheckout` — **COMPLETE**
- obsolete remote branches — **CLEANED; canonical remote topology was main-only before this reconciliation branch**;
- coordinate-closure PR #68 exact head `0e6ef6334282a83a428da7ee793daa98360c2bcc` — **FULL EXACT-HEAD STACK SUCCESS**;
- PR #68 squash merge `05fec942cc8fbeb58cfd0bd41f0ef5fdb86f966f` — **DONE**;
- post-merge Quality #901, Compatibility #429 (**16/16**), Release Artifact #378, Provider Sandbox #339, WordPress.org #236 and CodeQL #723 — **SUCCESS**;
- PR #68 closeout state before documentation-only PR #69 — **main only**, with open PRs/issues, tags and releases — **empty**


## Historical repository-admin closure

At the PR #68 coordinate-closure milestone, repository/admin state was reconciled with canonical About/topics, the four required Main Rule checks, protective rules, `main`-only topology and no open PRs/issues/tags/releases. That is historical milestone evidence, not a permanent assertion about whatever temporary review branch may exist later.

The latest runtime-bearing certified `main` is Approach 3 T2 merge `047cc86060efb97761d7a0cc4a3806f971ab6fe1` from PR #104 (certified head `4cff2dc6e6d11a4b3232a6d3d70d6280a59741c4`). The PR head completed **42/42** checks and fresh merged main completed **41/41**, including T1 dependency/provider-egress **11/0**, T1 active callback **41/0**, T2 direct fallback **25/0**, full compatibility + Compatibility Gate, Release Gate, Provider Sandbox, WordPress.org packaged Plugin Check and CodeQL/security. Current deterministic candidate package: **51 files**, SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`. The owner-accepted Approach 2 SHA/package remain a separate frozen regression reference until Approach 3 re-acceptance.

## Current owner/admin/local stage

Engineering does not need another invented numbered phase. Repository rename and obsolete persistent-branch cleanup are complete; temporary review branches remain normal during bounded work.

Owner technical acceptance has been **ACCEPTED** for the frozen Approach 2 baseline:

| Field | Value |
|---|---|
| Accepted baseline SHA | `0c883d609906676966002eb022a82a9656eeacc5` |
| Accepted package | `supcheckout-0.1.0.zip` |
| Accepted package SHA-256 | `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655` |
| Accepted package file count | `51` |

The procedure in `docs/project/OWNER-HANDOFF.md` is retained as a reusable regression/re-acceptance contract; it remains available for any future acceptance event whenever fresh evidence invalidates the current accepted baseline. The current remaining program is:

1. **Approach 3 T1 — `t01-architecture-guardrails-and-active-callback-characterization` — DONE / VERIFIED** on merged main `beb89ac0c4d8c0e9b7c8b2de1e13c237bbd37b15`; the accepted 51-file package remained byte-identical;
2. **Approach 3 T2 — `legacy-callback-routing-consolidation` — DONE / VERIFIED** on merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`; the priority-10 fallback now delegates to `PaymentLifecycle`, with 41/41 post-merge checks and deterministic 51-file candidate package SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`;
3. characterize the direct public legacy return/webhook methods and private verification path before approving any further consolidation;
4. re-certify all runtime-bearing Approach 3 changes against the accepted baseline and permanent payment/security/compatibility controls;
5. apply approved launch branding and visual/accessibility acceptance;
6. explicitly choose the first public version under a dedicated PR with the full release-sensitive gate stack re-run;
7. tag/GitHub Release/WordPress.org publication only after exact-main certification and explicit owner approval. Owner technical acceptance does not authorize publication.

## External/manual evidence track

These are not repository-CI claims:

- production merchant payment completion;
- wallet completion on real provider-enabled accounts/devices;
- WPML/WCML/multilingual/multicurrency/RTL certification;
- browser/device/theme/accessibility matrix;
- representative-store performance/load thresholds;
- penetration testing / PCI / legal-compliance attestations;
- live non-idempotent subscription auto-deduction;
- provider webhook signature verification until a stable published verification contract exists.

Automatic Woo refunds and arbitrary marketplace multi-split remain intentionally unsupported unless separately designed and certified.

## Continuous maintenance after release-candidate closeout

After the owner/release stage, normal maintenance includes:

- supported WordPress/WooCommerce/PHP version monitoring;
- UPayments API/documentation monitoring;
- dependency/security updates;
- permanent compatibility regression matrix;
- release/support lifecycle;
- public issue/security handling;
- future explicit migrations only when evidence justifies them.

## Completion rule

A roadmap item is complete only after exact implementation/review evidence, required checks, merge, post-merge verification and living-state reconciliation.

Branch existence, a bot report, a single green workflow or optimistic prose is never sufficient.
