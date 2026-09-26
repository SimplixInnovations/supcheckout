# SimplixPay for UPayments — Master Engineering Playbook

> **SUPERSEDED AS CURRENT-STATE AUTHORITY — HISTORICAL PROGRAM PLAYBOOK.** This document preserves the original SimplixPay-era program, decisions and sequencing. Do not treat its product name, repository coordinate, “current posture,” current-gate language or pending-work lists as present state. Current authority order is `AGENTS.md` → `PROJECT-STATUS.md` → `OWNER-HANDOFF.md` → `NAMING-IDENTITY-STANDARD.md` / `RELEASE-ENGINEERING.md`.

**Repository:** `SimplixInnovations/simplixpay-upayments`
**Upstream:** `upaymentskwt/woocommerce`
**Document purpose:** authoritative project plan, engineering standard, status ledger, clean-chat handoff, and Agent execution guide
**Baseline date:** 2026-08-24
**Last independently verified implementation `main`:** `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9`
**Current project posture:** engineering hardening; **not yet a generally certified public production release**

---

## 0. Executive decision

This project should continue, but its goal is no longer merely “patch the upstream UPayments WooCommerce plugin.” The target is to build and operate an independently engineered, professionally maintained WooCommerce payment integration for UPayments under Simplix Innovations, with its own release identity, quality gates, security posture, compatibility evidence, documentation, user experience, diagnostics, and distribution strategy.

The preferred long-term product position is:

> **SimplixPay for UPayments** — an independently engineered and maintained WooCommerce integration for UPayments.

The project must remain transparent that UPayments is the payment provider and owns its trademarks; Simplix Innovations owns and supports its modifications and distribution. Upstream changes are inputs to review, not changes that are automatically trusted or merged.

The current codebase contains substantial security hardening, especially the H12 customer-token identity work. Provider Contract & Payment Lifecycle and the bounded Security Threat-Model Closure are now DONE / VERIFIED, but the project is still not appropriate to advertise as universally production-certified. Before a broad production release, it must still complete architecture/code-quality foundation, quality-platform expansion, compatibility/feature certification, operational readiness and release engineering.

The engineering principle for the remainder of the project is:

> **Evidence before claims. Characterization before refactoring. Fail closed in security/payment ambiguity. Incremental architecture replacement instead of a big-bang rewrite.**

---

# PART I — HOW TO USE THIS PLAYBOOK

## 1. What this document is

This document is the single project control plane. It serves five roles:

1. **Project roadmap** — what must ultimately be built, tested, reviewed, documented, and released.
2. **Status ledger** — what has already been completed and what remains open.
3. **Engineering policy** — the security, correctness, testing, compatibility, and review rules that future changes must obey.
4. **Clean-chat handoff** — enough context for a new ChatGPT conversation to continue without relying on hidden memory or the old chat history.
5. **Agent execution contract** — a repeatable method for assigning narrow implementation tasks to an external coding Agent and independently reviewing its work.

This document is intended to evolve. Any phase that materially changes architecture, release policy, compatibility, or production readiness must update this playbook or a linked canonical status document.

## 2. Clean-chat restart procedure

When starting a completely new conversation, attach or paste this Markdown file and use the following opening instruction:

> **Read the attached Master Engineering Playbook first. Treat it as historical/project context, not as proof of the current remote state. Before proposing or changing code, independently verify the GitHub repository, current `main`, open PRs, active branches, current source, relevant official provider documentation, and any phase-specific evidence. Continue from the first unfinished gate. Do not trust a prior Agent report without remote verification. Do direct repository work yourself where the connected GitHub tooling permits; only delegate work the integration cannot perform.**

The new session must then perform this freshness check:

### 2.1 Mandatory freshness check

Record:

- current date/time;
- repository visibility and default branch;
- current `origin/main` SHA;
- whether `main` moved from the SHA recorded in this document;
- open pull requests;
- active feature branches relevant to the project;
- last merged project PR;
- current plugin header name/version/author/text domain;
- current updater target;
- current CI/workflows and required checks;
- current branch protection/rulesets;
- current README/compatibility/roadmap status;
- current UPayments documentation for the phase being worked on;
- current WordPress/WooCommerce/WPML requirements relevant to the phase.

If any baseline in this document is stale, update the working state before continuing. Never rewrite historical facts; add a new verified state entry.

## 3. Status vocabulary

Use only these project-status labels:

- **NOT STARTED** — no implementation work has begun.
- **DISCOVERY** — research/audit is underway; no implementation contract frozen yet.
- **READY FOR IMPLEMENTATION** — scope and acceptance criteria are frozen.
- **IN PROGRESS** — implementation branch/PR exists.
- **READY FOR REVIEW** — Agent/implementation claims complete; reviewer verification pending.
- **CHANGES REQUIRED** — reviewer found defects; do not merge.
- **APPROVED FOR MERGE** — source/evidence independently verified and exact head approved.
- **MERGED — VERIFYING** — merge happened; `main`/topology/cleanup not yet independently verified.
- **DONE / VERIFIED** — merged state and branch cleanup independently verified.
- **BLOCKED** — cannot proceed until a named dependency/risk is resolved.
- **DEFERRED** — intentionally postponed and explicitly not part of the current release gate.

Never use “done,” “fixed,” “compatible,” “secure,” or “production-ready” as informal claims without an explicit gate and evidence.

---

# PART II — CURRENT AUTHORITATIVE BASELINE

## 4. Verified repository state as of 2026-08-24

This section is a dated historical baseline. Later living-status sections and `PROJECT-STATUS.md` supersede its then-current blockers without rewriting the historical facts recorded here.

### 4.1 Repository

- Canonical repository: `SimplixInnovations/simplixpay-upayments`
- Visibility: **public**
- Relationship: **standalone canonical Simplix repository** (`fork:false`)
- Default branch: `main`
- Last independently verified `main` at this historical baseline: `1caf38410354322c1d842c28a40b0909ba31026d`
- Canonical root tree: `34594c00d243b59345ec9fbb3a88d2e1ec8f3efc`
- Canonical root commit has **no parents** and intentionally starts the SimplixPay product history cleanly.
- Historical engineering/audit repository: `SimplixInnovations/upayments-woocommerce`
- Historical H12 merge remains preserved there at `93e9925247a8bfade626cb822136852fd96eaea2`, with PR/review provenance retained.
- The five H12 production blobs copied into the canonical root are byte-identical to the verified historical H12 merge.

### 4.2 H12 / Phase 9G token-identity hardening

**Status: DONE / VERIFIED.**

PR #16 in the historical engineering repository merged the H12 customer-token identity hardening and residual corrections. The final #33 head was independently verified before merge; the merge commit and five frozen production blobs were independently verified afterward.

Frozen production blob identities at the merge:

- `UPayments.php` → `64c789e81ae4d292ef9b1d7382812c319a44bc25`
- `includes/Token/CustomerTokenIdentity.php` → `85430d37e9baf540842f5655b86ccf0eca3e6aea`
- `includes/class-wc-gateway-upayments-blocks.php` → `813d192d69c069eb7ee11df93acc9dbdf03e270a`
- `includes/Subscription/Cron/Scheduler.php` → `5251866d4df2d1326e7c09f0c8ec1d146c0bb325`
- `includes/Subscription/Cron/CycleClaim.php` → `c34d83e2d77cc65024fe663e4c378cecb2b17347`

These SHAs are historical H12 verification anchors, not permanent prohibitions on future changes. When a later phase intentionally modifies one of these files, that phase must replace the old anchor with new reviewed evidence rather than pretending the file remained frozen.

### 4.3 Current repository governance/tooling facts at the clean-root baseline

At the clean-root baseline:

- `main` branch protection was reported disabled / no required status checks.
- No `.github/workflows/` directory was present.
- No `composer.json` was present.
- No `package.json` was present.
- The test tree consisted primarily of the custom `tests/harness` system used for H12.
- Issues and issue templates exist.
- README, `docs/COMPATIBILITY.md`, `docs/ENGINEERING-ROADMAP.md`, `SECURITY.md`, `SUPPORT.md`, `CONTRIBUTING.md`, `MAINTAINERS.md`, and `UPSTREAM.md` exist.
- Those documents still reflected the former fork-era identity before the Phase 0 governance bootstrap.

### 4.4 Release-identity blocker at the historical baseline

The plugin bootstrap at that baseline still declared the upstream identity approximately as:

- Plugin Name: `UPayments`
- Version: `3.1.1`
- Author: UPayments Company
- Text Domain: `upayments`

It also still constructed the bundled update checker against:

`https://github.com/upaymentskwt/woocommerce`

This was a P0 release blocker because a Simplix-maintained deployment must never be silently replaced by upstream code. Phase 0 subsequently closed this blocker; the historical record is retained here for provenance.

---

# PART III — FROZEN H12 TOKEN / PROVIDER CONTRACTS

## 5. Provider token contract

The H12 implementation and tests established the following contract. Future changes must not weaken it casually.

### 5.1 Customer Unique Token

- Create request field: `customerUniqueToken`.
- Provider shape: numeric, 8–18 digits.
- Prefer 8 digits for KFAST compatibility when generating canonical candidates.
- Generated values must be non-predictable.
- A phone/mobile number must not be used as the standalone canonical customer token.
- Customer phone/mobile and customer unique token are separate identity concepts.

### 5.2 Strict Create Customer Unique Token success

A successful canonical creation requires all of:

- HTTP status exactly `201`;
- parsed response `status === true`;
- returned customer unique token exactly equals the candidate that was submitted.

Do not infer success from messages, partial structures, 2xx ranges, or provider prose.

### 5.3 HTTP 422 behavior

HTTP `422` is fail-closed.

Do not:

- parse message text to infer duplicate-token success;
- silently reuse a candidate;
- automatically retry a guessed collision path;
- treat a provider string as identity proof.

### 5.4 Retrieve Cards

- request uses the customer unique token;
- strict token shape `^[0-9]{8,18}$` for provider retrieval compatibility;
- success requires HTTP `201`;
- response `status === true`;
- `data.customerCards` must be an array.

### 5.5 Saved-card Charge

Saved-card charge uses both:

- `tokens.creditCard`; and
- `tokens.customerUniqueToken`.

Using an already saved card must not set `isSaveCard=true` merely because the card is selected.

Selected-card usage must be gated by current valid provenance, exact current scope/generation, strict Retrieve Cards, and exact card membership.

## 6. Canonical customer-token identity model

### 6.1 Fresh users with no history

If the local safety census proves there is no historical identity evidence and the identity secret is genuinely absent, establish a canonical random 8-digit customer token and strict provenance.

### 6.2 Safely attributable historical identity

Historical identity that is strongly and safely attributable may become `legacy_compat` provenance with source `legacy_verified_capture`.

Never forge canonical provenance for a historical token merely to make the data fit the new schema.

### 6.3 Weak, malformed or ambiguous history

If historical evidence is weak, ambiguous, malformed, cross-user, incomplete, stale-generation, orphaned, or otherwise unsafe, migration is required. Do not retrieve cards, guess, or promote identity in the checkout hot path.

### 6.4 Guests

Guests never become persistent canonical customer identities merely because checkout provides a phone/email.

### 6.5 Phone changes

Changing customer mobile/phone does not rotate canonical customer-token identity.

### 6.6 Historical subscriptions

Trusted historical subscription/order snapshots may retain their historical identity context; do not rewrite them to the current customer's phone or blindly rotate identity.

## 7. Identity secret/provenance schema

Secret option:

`upayments_token_identity_secret_v2`

Secret record shape:

- version `1`;
- secret: 64 hex chars;
- generation ID: 32 hex chars;
- verifier: 64 hex chars.

Secret state is tri-state:

- ABSENT;
- VALID;
- INVALID.

Malformed/invalid is not the same as missing. Invalid secret state fails closed; it must never be silently replaced as if there were no prior security context.

Provenance schema v3 fields:

- `kind`: `canonical` or `legacy_compat`;
- `token`;
- `source`: `create_201` or `legacy_verified_capture`;
- `scope`: 32 hex chars;
- `secret_generation_id`: 32 hex chars;
- `established_at_gmt`: positive integer.

Allowed pairings only:

- `canonical` + `create_201`;
- `legacy_compat` + `legacy_verified_capture`.

## 8. H12 historical bootstrap behavior

The bootstrap history inspection is intentionally bounded and strict.

Key rules:

- pagination in bounded batches;
- safety cap at 200 historical items/orders for the H12 path;
- incomplete history beyond the cap is not “clean”; it is migration/indeterminate territory;
- unloadable orders cannot be silently ignored;
- non-scalar evidence is not coerced;
- force-refresh failures matter;
- orphan metadata/card-only evidence matters;
- unscoped historical data matters;
- current-scope orphans matter;
- generation mismatch matters;
- prior-scope same-generation histories matter;
- prior provenance is scanned strictly;
- provenance creation is immutable and persistence-verified;
- failures roll back rather than leaving partial identity state.

---

# PART IV — PHASE 9I MIGRATION BLOCKERS

## 9. Thirteen H12-to-9I blocker families — DONE / VERIFIED disposition

The following historical blocker families were the required Phase 9I safety scope:

1. Unscoped legacy tokens.
2. Current-scope orphan histories.
3. Cross-user token conflicts.
4. Malformed scoped histories.
5. Secret generation mismatches.
6. Card-token-only historical identity.
7. Prior-scope same-generation histories.
8. Non-scalar evidence.
9. Orphan metadata.
10. Incomplete history beyond the safety cap.
11. Unloadable orders.
12. Force-refresh failures.
13. Malformed-versus-missing secret distinction.

Phase 9I is now **DONE / VERIFIED**. These families are no longer unimplemented gaps: the verified classifier/executor contract assigns explicit fail-closed semantics and the bounded operational layer records/resumes sanitized per-user outcomes. A real merchant can still legitimately receive `BLOCKED` or `INDETERMINATE`; closure does not authorize guessing those users into valid identity.

See `docs/project/PHASE-9I-MIGRATION.md` for the closed implementation/evidence record.

---

# PART V — OPERATING MODEL AND EVIDENCE HIERARCHY

## 10. Operating model

The user has authorized direct repository work wherever the connected tooling allows it.

Therefore:

1. Independently inspect the remote state.
2. Independently inspect the actual source, diff and tests.
3. Design the exact implementation from evidence.
4. Perform repository mutations directly when tooling permits.
5. If a required mutation genuinely cannot be performed directly, give the external Agent or user only that inaccessible action.
6. Independently verify any external work.
7. Approve or merge only after exact verification.
8. After merge, independently verify the resulting `main`, topology/checks and branch cleanup.

Do not make the user act as a relay for tasks the integration can perform itself.

## 11. Evidence hierarchy

For project decisions, prefer evidence in this order:

1. exact remote Git objects/current source;
2. executable tests and reproducible runtime evidence;
3. official provider/platform documentation;
4. independently inspected diffs/static source evidence;
5. Agent implementation reports;
6. assumptions or historical chat text.

Agent reports are never proof by themselves.

## 12. Approval vocabulary

When verification fails, use:

**NOT APPROVED.**

**DO NOT MERGE.**

Implementation Agent reports should end:

**STOP. DO NOT MERGE.**

**Awaiting reviewer verification.**

Merge-only Agent reports should end:

**STOP.**

**Awaiting reviewer verification of merge.**

## 13. Base/head pinning

Before approval or merge, verify exact:

- base branch SHA;
- head branch SHA;
- PR diff;
- required file blobs;
- required executable evidence;
- CI/check results.

If base or head moved after approval, approval is stale until re-verified.

---

# PART VI — PRODUCTION READINESS LADDER

## 14. Readiness levels

### R0 — Engineering hardening

Current posture.

Characteristics:

- meaningful hardening exists;
- important known blockers remain;
- compatibility matrix incomplete;
- no broad stable-release claim;
- no WordPress.org release claim.

### R1 — Controlled development/staging candidate

Requirements include:

- upstream updater cannot silently replace the build;
- basic CI and repository governance active;
- known P0 security defects controlled;
- exact test/staging environment recorded;
- unsupported features explicitly disabled/documented.

### R2 — Controlled production pilot candidate

Requirements include:

- Phase 9I outcome known for the target store;
- no unsafe historical identity is silently used;
- exact enabled checkout/payment features tested;
- webhook/status reconciliation verified;
- credentials/logging hygiene verified;
- backup/rollback ready;
- release artifact pinned.

### R3 — Feature-certified production release

Requirements include:

- provider contract audit complete for enabled features;
- deterministic payment lifecycle/state handling;
- security threat-model gate passed;
- required automated quality gates;
- explicit compatibility matrix for supported WordPress/WooCommerce/PHP/modes;
- upgrade/rollback tests;
- reproducible release package.

### R4 — Broad ecosystem stable release

Requirements include:

- broader compatibility matrix;
- onboarding/diagnostics/support readiness;
- accessibility/frontend certification;
- performance regression gates;
- stable release/update process;
- support and security response process.

### R5 — WordPress.org / broad ecosystem release

Requirements:

- trademark-safe final plugin name/slug;
- WordPress.org-compliant main header and `readme.txt`;
- distribution license/dependencies/assets reviewed;
- external GitHub updater removed from WordPress.org build;
- Plugin Check and relevant review checks clean;
- current “Requires/Tested up to/Requires PHP” evidence;
- no prohibited telemetry/ads/update behavior;
- accessibility/admin UX/support readiness;
- release/support process capable of serving public users.

---

# PART VII — MASTER ROADMAP

## 17. Roadmap status summary

| Program | Current status | Release importance |
|---|---|---|
| H12 / Phase 9G token identity | **DONE / VERIFIED** | Foundational |
| Phase 0 — Release & repository safety | **DONE / VERIFIED** | Critical before distribution |
| Phase 9I — Historical migration | **DONE / VERIFIED** | Critical for upgrades/existing customers |
| Provider contract audit | **DONE / VERIFIED** | Critical |
| Payment lifecycle/state machine | **DONE / VERIFIED** | Critical |
| Security threat-model audit | **DONE / VERIFIED** | Critical |
| Architecture/code quality | **DONE / VERIFIED (A1-A5)** | High |
| Full automated quality platform | **DONE / VERIFIED (Q1-Q19)** | Critical before public stable |
| WooCommerce/WordPress/PHP certification | **CURRENT — ENTERPRISE COMPATIBILITY CERTIFICATION** | Critical |
| WPML/multilingual/multicurrency certification | **FIXES PARTIAL; CERTIFICATION PENDING** | High/product objective |
| Saved cards/subscriptions/wallets/multi-merchant | **PARTIAL; MATRIX PENDING** | Critical per enabled feature |
| Performance/stability engineering | **NOT STARTED AS FORMAL PROGRAM** | High |
| UI/UX/accessibility/browser/device program | **NOT STARTED AS FORMAL PROGRAM** | High |
| Onboarding/Site Health/diagnostics | **NOT STARTED** | High |
| Structured errors/logging/observability | **PARTIAL / REQUIRES REDESIGN** | High |
| Documentation/SEO/badges | **PARTIAL** | Medium/high |
| Release engineering | **NOT STARTED AS FORMAL PROGRAM** | Critical |
| WordPress.org publication | **DEFERRED UNTIL READY** | Strategic |
| Continuous maintenance | **ONGOING AFTER FIRST STABLE** | Critical long-term |

Provider Contract & Payment Lifecycle, the bounded Security Threat-Model Closure, Architecture discovery/A1-A5 and Quality Platform Q1-Q19 are **DONE / VERIFIED**. The current unified gate is **Enterprise Compatibility Certification**. Provider contract and lifecycle rows remain separated because they retain distinct closed contracts and regression evidence.

## 18. Phase ordering

Recommended execution order:

1. Phase 0 — Release & Repository Safety — **DONE / VERIFIED**.
2. Phase 9I — Historical Token-Identity Migration — **DONE / VERIFIED**.
3. Provider Contract & Payment Lifecycle — **DONE / VERIFIED**.
4. Security Threat-Model Closure — **DONE / VERIFIED**.
5. Architecture & Code Quality Foundation — **DONE / VERIFIED (A1-A5)**.
6. Full Test-Driven Quality Platform — **DONE / VERIFIED (Q1-Q19)**.
7. Enterprise Compatibility Certification — **CURRENT**.
8. WPML / i18n / multilingual / multicurrency / RTL.
9. Feature-specific certification — saved cards, subscriptions, wallets, multi-merchant, refunds.
10. Performance & Stability Engineering.
11. Frontend/UI/UX/Accessibility/Browser/Device Program.
12. Merchant Onboarding, Site Health & Diagnostics.
13. Error Handling / Observability / Logging / Support.
14. Documentation / README / SEO / Badges.
15. Release Engineering & Distribution.
16. WordPress.org Publication.
17. Continuous Maintenance.

This order is not an excuse to defer a critical security defect discovered later. P0 defects pre-empt normal sequencing.

---

# PHASE 0 — RELEASE & REPOSITORY SAFETY

**Status:** DONE / VERIFIED
**Priority:** P0
**Dependency:** H12 DONE / VERIFIED

Phase 0 closed through the independently verified repository-foundation and release-identity work. The following sections retain the original phase contract for audit/reference; they are not current implementation instructions. See `docs/project/PHASE-0-RELEASE-IDENTITY.md` and `PROJECT-STATUS.md` for closure evidence.

## 19. Goal

Take ownership of the Simplix distribution before deeper feature work. Prevent accidental upstream overwrite, establish an independent version/release identity, create repository governance, and introduce baseline required checks.

## 19.1 Product/release identity

The final product identity is governed by `docs/project/NAMING-IDENTITY-STANDARD.md`.

Canonical values include:

- formal product: **SimplixPay for UPayments**;
- short integration reference: **SimplixPay UPayments**;
- product family reserved for broader use: **SimplixPay**;
- canonical slug: `simplixpay-upayments`;
- repository: `SimplixInnovations/simplixpay-upayments`;
- target plugin folder: `simplixpay-upayments`;
- target main file: `simplixpay-upayments.php`;
- target text domain: `simplixpay-upayments`;
- new PHP namespace root: `Simplix\Pay\UPayments`;
- new procedural prefix: `simplixpay_upayments_`;
- new constants: `SIMPLIXPAY_UPAYMENTS_*`.

Do not globally rename legacy persisted identifiers.

## 19.2 Updater ownership

The historical bundled updater pointed to the provider/upstream repository. Phase 0 neutralized that authority and removed the bundled Plugin Update Checker. External self-updates remain disabled until a separately tested distribution/basename contract is established.

Permanent requirements remain:

- no path by which upstream releases silently replace the Simplix build;
- separate policy for GitHub/private distribution versus future WordPress.org distribution;
- update channel tied to Simplix release identity;
- upgrade/update behavior tested before enabling a channel;
- rollback/recovery understood;
- external updater excluded from WordPress.org build unless policy and review explicitly permit it.

## 19.3 Repository governance

Required and established for the current repository:

- root `AGENTS.md`;
- canonical project docs under `docs/project/`;
- CODEOWNERS;
- PR and issue templates;
- protected/default branch rules with required checks;
- no direct unreviewed payment-critical changes to `main`;
- review pinned to exact head SHA;
- merge verification and status-ledger updates.

Repository settings to preserve/verify where GitHub supports them:

- require PR before merge where practical;
- require passing status checks;
- prevent force pushes/deletion of `main`;
- restrict bypass/admin behavior;
- require conversation resolution where useful;
- configure merge methods intentionally;
- organization 2FA/security settings;
- enable secret scanning and push protection where available;
- enable Dependabot/security alerts for supported dependency ecosystems once manifests exist;
- enable private vulnerability reporting / GitHub Security Advisories if appropriate.

## 19.4 Baseline CI bootstrap

Initial CI exists and must not be mistaken for the final quality platform:

- PHP syntax lint across plugin-owned PHP;
- Phase 0 release-identity harness;
- Phase 9I preflight/executor/operations harnesses;
- Provider Payment Lifecycle and Provider Exact Amount Binding harnesses;
- existing H12 PHP harness;
- existing Blocks harness;
- whitespace/governance checks;
- later add install/activation smoke, Composer/PHPUnit/static analysis, matrix/runtime integration and release checks.

## 19.5 Credential hygiene

- scan current tree/history/release artifacts for secrets;
- verify any historically exposed production credential has been revoked/rotated before live release;
- never commit sandbox/live bearer tokens;
- ensure logs and fixtures use synthetic credentials only;
- document incident-response procedure for accidental key exposure.

## 19.6 Documentation sync

Maintain:

- README current status;
- roadmap/status docs;
- compatibility matrix;
- issue-tracking language;
- security/support contacts/process;
- release identity statement;
- historical archive/upstream relationship.

## 20. Phase 0 deliverables

- permanent repository governance layer;
- release-identity/updater ownership implementation/policy;
- branch/ruleset configuration record;
- first CI workflow(s);
- CODEOWNERS;
- updated README/roadmap/status;
- credential hygiene result;
- explicit deferral of physical basename/text-domain migration until separately characterized.

## 21. Phase 0 exit gate

Phase 0 is DONE because the verified implementation established:

- protected `main` and required checks;
- upstream cannot silently overwrite a Simplix build;
- Simplix release identity/version ownership is unambiguous;
- external updater is disabled pending a separately tested distribution contract;
- docs accurately describe the transitional package/text-domain identity;
- Phase 0 and H12 regression evidence remained green.

Physical basename/folder/text-domain migration remains a later separately tested release/i18n concern, not an open Phase 0 status.

---

# PHASE 9I — HISTORICAL TOKEN-IDENTITY MIGRATION

**Status:** DONE / VERIFIED
**Priority:** P0
**Dependency:** H12 DONE / VERIFIED; Phase 0 DONE / VERIFIED

Phase 9I closed through three independently reviewed implementation tranches: preflight PR #11, executor PR #12 and operations PR #13. The sections below retain the original design requirements as permanent safety contracts. See `docs/project/PHASE-9I-MIGRATION.md` for exact merge/evidence details.

## 22. Goal

Allow existing stores to transition historical saved-card/customer-token evidence into the new canonical identity model without guessing, cross-linking customers, weakening generation/scope guarantees, or forcing expensive checkout-time scans.

## 23. Phase 9I-A — Read-only migration decision engine

The verified deterministic inspector produces one top-level result:

- `CLEAN`
- `MIGRATABLE`
- `BLOCKED`
- `INDETERMINATE`

The result includes a machine-stable reason code and sanitized diagnostic details.

### 23.1 Rules

- core preflight is read-only;
- zero provider mutations/calls;
- zero identity/user/order metadata writes in core preflight;
- bounded work;
- deterministic precedence;
- no checkout-hot-path full historical scan;
- no unsafe token/card retrieval from ambiguous identity;
- malformed secret never silently regenerated;
- all 13 blocker families covered by explicit cases.

The operational dry-run wrapper intentionally writes only its separate redacted operations-result checkpoint so interrupted work can resume safely; this does not mutate H12 identity or contact the provider.

### 23.2 Cross-user conflict detection

A migration decision that could establish or preserve a customer token detects whether that token is attributable to another user/history. Cross-user collision is a security block, not a “first match wins” scenario.

### 23.3 Incomplete evidence

If evidence cannot be safely completed because of scan cap, load failure, cache refresh failure, non-scalar shape, or another evidence-integrity problem, return `INDETERMINATE` rather than `CLEAN`/`MIGRATABLE`.

## 24. Phase 9I-B — Migration executor

Only explicit fresh `MIGRATABLE` results may mutate state.

Verified requirements:

- idempotent;
- re-validates preconditions under the correct lock before mutation;
- writes truthful `legacy_compat` / `legacy_verified_capture` provenance for attributable legacy identity;
- never writes fake canonical/Create-201 provenance;
- persistence verified;
- safe recoverable failure semantics;
- zero provider calls;
- historical order metadata remains immutable;
- successful identity ledger is sanitized.

## 25. Phase 9I-C — Operational migration tooling

Verified bounded merchant/admin/CLI execution provides:

- identity/provider non-mutating dry run plus redacted operations-result checkpoint;
- explicit confirmed execute;
- strict list and batch sizing;
- explicit offset and durable resumability;
- idempotency;
- per-user decision/result ledger;
- safe retry;
- DB/caching-aware bounded work;
- no checkout-time mass scan;
- clear blocked/indeterminate result semantics;
- fail-closed checkpoint persistence behavior.

The final Phase 9I implementation head reran Phase 0, preflight, executor, operations, H12 PHP and H12 Blocks regression suites successfully before PR #13 merged.

---

# PROVIDER CONTRACT AUDIT

**Current program status:** DONE / VERIFIED. The closed contract/evidence is retained in `docs/project/PROVIDER-PAYMENT-LIFECYCLE.md`.

## 26. Goal

Freeze a local, versioned specification for provider behavior actually relied upon by the plugin, rather than scattering assumptions through the gateway class.

Audit at minimum:

- charge creation;
- redirect/return/cancel behavior;
- notification/webhook requirements;
- payment status lookup and rate limits;
- saved-card create/retrieve/charge;
- wallet-specific fields/eligibility;
- refunds;
- multi-merchant fields/sums/routing;
- subscriptions/auto deduction;
- provider error/timeout/malformed-response behavior.

## 27. Provider specification artifact

Create an internal provider-contract spec with:

- endpoint/method;
- required/optional fields;
- field shapes;
- strict success conditions;
- failure categories;
- retry/idempotency rules;
- rate limits;
- source documentation URL/date;
- plugin code mapping;
- test fixture mapping;
- unresolved provider documentation contradictions.

When provider documentation conflicts, do not choose the more permissive interpretation without evidence. Prefer the safer bounded behavior until clarified.

---

# PAYMENT LIFECYCLE / STATE MACHINE

**Current program status:** DONE / VERIFIED. The ordinary-checkout lifecycle is frozen by `docs/project/PROVIDER-PAYMENT-LIFECYCLE.md` and its required regression harnesses.

## 28. Goal

Move from scattered “set status in this callback” behavior to an explicit payment lifecycle and reconciliation policy.

Model events such as:

- checkout request accepted/rejected;
- provider charge created;
- customer redirect initiated;
- customer return/cancel;
- webhook received;
- provider status lookup;
- payment success/failure/pending/indeterminate;
- refund initiation/result;
- subscription automatic charge;
- replay/duplicate/out-of-order notification;
- persistence failure;
- reconciliation retry.

## 29. Principles

- browser return is not the sole source of payment truth;
- webhook/status lookup must be authenticated/validated as applicable;
- state transitions must be deterministic;
- duplicate/replayed notifications must be idempotent;
- ambiguous non-idempotent charge outcomes become `indeterminate`/reconciliation, not blind retry;
- order notes/logs use stable sanitized reason codes;
- success must never be inferred from user-controlled parameters alone.

---

# SECURITY THREAT-MODEL AUDIT

**Program status:** DONE / VERIFIED. This section retains the security phase scope as a historical engineering standard; the current program gate is **Enterprise Compatibility Certification**.

## 30. Scope

Threat-model:

- checkout inputs;
- Store API/Blocks extension data;
- callbacks/return/cancel;
- webhooks;
- REST/AJAX/admin tools;
- saved-card/customer-token identity;
- subscription scheduled actions;
- refund/multi-merchant operations;
- logs/diagnostics;
- update channel/supply chain;
- plugin capabilities/nonces;
- DB/cache failure behavior;
- secrets/configuration.

## 31. Threat categories

Include:

- authentication/authorization bypass;
- CSRF;
- replay;
- IDOR/order mismatch;
- forged webhook/callback;
- cross-user token/card linking;
- secret leakage;
- logging PII/secrets;
- unsafe deserialization/dynamic invocation;
- SSRF/open redirect where applicable;
- SQL injection;
- XSS/escaping;
- race/concurrency issues;
- duplicate charges;
- supply-chain/update compromise;
- insecure defaults;
- unsafe failure recovery.

---

# ARCHITECTURE & CODE QUALITY FOUNDATION

## 32. Strategy

Do not perform a big-bang rewrite. Use a strangler architecture:

1. characterize legacy behavior;
2. freeze critical provider/payment contracts;
3. extract one domain behind a clear boundary;
4. add tests;
5. route legacy entry points through the new service;
6. preserve compatibility IDs/hooks;
7. repeat.

## 33. Target architecture

A likely target:

```text
src/
  Plugin/
  Requirements/
  Gateway/
  Blocks/
  Api/
  Payment/
  Webhook/
  Refund/
  Token/
  Migration/
  Subscription/
  MultiMerchant/
  Admin/
  Diagnostics/
  Logging/
  Compatibility/
```

New code namespace:

`Simplix\Pay\UPayments`

Avoid an overbuilt DI container. Prefer explicit constructor dependencies and small composition roots.

## 34. Static/code-quality phase

A dedicated code-quality audit is mandatory and must include:

- naming consistency;
- typos/misspellings;
- visibility;
- type declarations where compatible;
- duplicate helpers;
- dead/unreachable code;
- stale comments;
- deprecated APIs;
- raw superglobals;
- magic strings/constants;
- dynamic properties/PHP deprecations;
- error suppression;
- raw SQL and prepared-query correctness;
- sanitization/validation/escaping;
- i18n/text-domain correctness;
- JS/CSS scoping;
- complexity and oversized classes;
- global side effects;
- public mutable state;
- autoloading/composer architecture.

Do not mechanically “clean” payment-critical logic without regression characterization.

---

# FULL TEST-DRIVEN QUALITY PLATFORM

**Program status:** DONE / VERIFIED (Q1-Q19). Q19 closed the bounded subscription product-eligibility/opt-out consistency contract across Classic and Store API through PR #45, exact-head Quality Gates #463 and squash merge `29ba16a1eabc00e25c3652ae838be9b9539b3a10`; post-merge Quality Gates #464 and CodeQL succeeded. The numbered sequence is closed at Q19; no Q20 is justified by current evidence. The current named program is Enterprise Compatibility Certification.

## 35. Testing philosophy

Coverage percentage is not the goal. Risk coverage is.

Use:

- unit tests;
- WordPress/WooCommerce integration tests;
- provider contract fixtures;
- webhook replay/idempotency tests;
- concurrency/race tests;
- migration tests;
- cache/DB/load-failure injection;
- property/boundary tests;
- fuzzing where valuable;
- mutation testing for critical decision logic;
- browser E2E;
- accessibility tests;
- performance regression tests;
- install/upgrade/rollback tests.

Every practical defect should get a regression test at the most appropriate layer.

## 36. Tooling target

Introduce progressively:

- Composer;
- PHPUnit;
- WordPress/WooCommerce test bootstrap;
- PHPStan;
- PHPCS + WordPress/WooCommerce coding standards;
- ESLint/Stylelint if frontend build/source warrants it;
- dependency auditing;
- CodeQL;
- Infection mutation testing for selected critical modules;
- GitHub Actions matrices;
- E2E browser tooling where justified.

Do not add tooling merely for badge count. Each tool must protect a named risk.

---

# WOOCOMMERCE / WORDPRESS / PHP CERTIFICATION

## 37. Matrix dimensions

Record exact supported/tested:

- WordPress versions;
- WooCommerce versions;
- PHP versions;
- Classic Checkout;
- Cart/Checkout Blocks;
- HPOS on/off where relevant;
- logged-in/guest;
- saved card/no saved card;
- subscriptions as applicable;
- supported browsers/devices for frontend behavior.

## 38. Compatibility declarations

Do not set “tested up to,” HPOS compatibility, Blocks compatibility or other declarations beyond verified evidence.

Certification must include install/activation, settings, checkout, callbacks/webhooks, My Account, admin, refunds, upgrades and relevant failure paths.

---

# WPML / I18N / MULTILINGUAL / MULTICURRENCY / RTL

## 39. Goals

- correct WordPress gettext use;
- canonical target text domain `simplixpay-upayments` for new/public identity, with tested migration from historical domain behavior;
- WPML/String Translation compatibility;
- WCML/multicurrency interaction;
- translated checkout/admin/customer messages;
- URL/redirect language handling;
- locale-safe provider/customer fields;
- RTL layout;
- no string concatenation patterns that defeat translation;
- no fatal on null/invalid text domain;
- JS translations where applicable.

Do not display a WPML certification badge unless actual certification/authorization supports it.

---

# FEATURE-SPECIFIC CERTIFICATION

## 40. Saved cards

Test:

- save consent;
- create token strict success/failures;
- retrieve cards;
- selected-card provenance;
- card removal/expiry/provider mismatch;
- current/legacy identities;
- cross-user protection;
- no accidental resave;
- guest behavior;
- phone change;
- migration states.

## 41. Subscriptions

Test provider eligibility and plugin behavior for:

- supported product/cart shapes;
- login requirements;
- initial payment;
- saved-card/token prerequisites;
- recurring dispatch;
- attempt journal/concurrency;
- duplicate scheduler execution;
- network/timeout/indeterminate outcome;
- status reconciliation;
- cancellation/expiry/update;
- retries/dunning policy;
- mixed cart restrictions;
- Classic versus Blocks behavior.

## 42. Wallets

For each supported wallet:

- eligibility;
- browser/device capability detection;
- merchant configuration;
- graceful absence;
- no console errors when API unavailable;
- real-device testing when required;
- provider response/state behavior.

## 43. Multi-merchant

Audit:

- amount allocation;
- charges/fees;
- type/IBAN/merchant identifiers;
- sum invariants;
- validation;
- refund implications;
- partial failure;
- provider error reporting.

---

# PERFORMANCE & STABILITY ENGINEERING

## 44. Performance principles

- no remote provider calls on ordinary storefront page views;
- conditional module/bootstrap loading;
- admin code only in admin context where practical;
- Blocks/subscription code loaded only where needed;
- assets only on relevant screens;
- avoid repeated settings/order/meta lookups;
- HPOS-aware CRUD rather than direct post assumptions;
- bounded migrations/background jobs;
- security-sensitive cache correctness over cache-hit vanity;
- benchmark before/after meaningful changes.

## 45. Performance evidence

Track:

- bootstrap wall time;
- DB query count/time;
- object-cache behavior where relevant;
- frontend JS/CSS size and requests;
- checkout initialization;
- API request latency separate from local code latency;
- cron/background batch size/time/memory;
- worst-case migration history behavior.

Set regression thresholds only after reliable baselines exist.

---

# FRONTEND / UI / UX / ACCESSIBILITY / BROWSER / DEVICE PROGRAM

## 46. Frontend scope

Audit:

- payment method selection;
- saved cards;
- save-card consent;
- loading/disabled/double-submit behavior;
- validation/error/retry;
- responsive layout;
- touch targets;
- dark/light appearance where relevant;
- RTL;
- keyboard navigation;
- screen-reader semantics;
- My Account payment methods;
- admin settings/onboarding;
- wallet capability failures.

## 47. CSS policy

New CSS must be scoped under SimplixPay UPayments component roots. Do not globally override WooCommerce/theme layout unless there is an explicit compatibility reason and regression evidence.

Avoid generic selectors such as unscoped `button`, `table`, `.woocommerce` or layout width overrides.

## 48. Browser/device evidence

Maintain a pragmatic support matrix based on current WooCommerce/browser support and wallet requirements. Test real wallet hardware/device paths when simulation cannot prove behavior.

---

# MERCHANT ONBOARDING, SITE HEALTH & DIAGNOSTICS

## 49. Onboarding/readiness

Provide a readiness experience that checks:

- WordPress/WooCommerce/PHP;
- HTTPS;
- cURL/JSON/OpenSSL;
- REST/permalinks;
- WP-Cron/Action Scheduler;
- server time/timezone;
- HPOS;
- Classic/Blocks;
- WPML/WCML/multicurrency;
- subscriptions;
- credential presence/format without exposing secrets;
- non-mutating connectivity where safe;
- webhook URL/SSL/local reachability concerns;
- token migration classifier;
- plugin conflicts/duplicate gateway ownership;
- unsupported combinations.

Readiness states might include:

- Ready;
- Warnings;
- Migration required;
- Unsupported;
- Critical.

## 50. Diagnostics

Integrate with Site Health where appropriate and provide a sanitized support export.

Never export:

- API secrets;
- bearer tokens;
- full provider sensitive payloads;
- customer/card tokens;
- H12 secret/provenance material;
- unnecessary PII.

---

# ERROR HANDLING / OBSERVABILITY / LOGGING / SUPPORT

## 51. Error taxonomy

At minimum distinguish:

- merchant configuration;
- customer validation;
- unsupported combination;
- provider rejection;
- provider authentication;
- rate limit;
- network/timeout;
- malformed provider response;
- payment indeterminate;
- webhook mismatch/replay;
- migration/security block;
- persistence/cache/DB failure;
- internal defect.

Each stable error should define:

- machine code;
- customer-safe message;
- merchant diagnostic;
- severity;
- retryability;
- reconciliation behavior;
- expected state transition.

## 52. Logging

Replace ad-hoc raw debug output with structured WooCommerce logging and redaction.

Never log raw secrets, full card data, bearer headers, customer/card tokens, token identity secret records, or unnecessary PII.

Support bundles must be safe-by-default.

---

# DOCUMENTATION / README / SEO / BADGES

## 53. Documentation principles

- distinguish provider capability from Simplix verification;
- distinguish implementation existence from production certification;
- keep README concise and current;
- keep deep engineering state in project docs;
- record unsupported combinations;
- document migrations/upgrades/rollback;
- avoid misleading “official” language;
- preserve upstream attribution/trademark boundaries.

## 54. Badges

Badges must be evidence-based.

Do not show a compatibility badge for:

- WPML;
- HPOS;
- Blocks;
- WordPress/WooCommerce version;
- PHP version;
- security/compliance;
- test coverage;

until evidence supports the exact badge claim.

---

# RELEASE ENGINEERING & DISTRIBUTION

## 55. Independent versioning

Use Simplix-owned semantic versioning. Do not pretend a first Simplix public release is upstream version 3.x simply because the inherited header is `3.1.1`.

Recommended hardening line:

- `0.1.0`, `0.2.0`, etc. during controlled development;
- `1.0.0` when the stable-release readiness gates pass.

Document upstream lineage separately.

## 56. Release artifacts

Canonical release artifact:

`simplixpay-upayments-X.Y.Z.zip`

with root:

`simplixpay-upayments/`

Releases should be reproducible and exclude development-only clutter. Record checksums and install-test the actual ZIP.

## 57. GitHub versus WordPress.org update path

If GitHub/private distribution uses a custom updater, it must point only to Simplix-controlled releases and be explicitly tested.

A future WordPress.org package should normally use WordPress.org as its update authority and must not ship a conflicting external updater.

---

# WORDPRESS.ORG PUBLICATION

## 58. Gate

WordPress.org work begins only after the plugin meets the relevant stable-release gates.

Requirements include:

- final trademark-safe name/slug;
- target slug `simplixpay-upayments` subject to final availability/review;
- proper plugin header;
- `readme.txt`;
- license/dependency review;
- Plugin Check;
- no conflicting external updater;
- accurate Requires/Tested metadata;
- screenshots/assets/FAQ/changelog/support;
- public security/support readiness;
- upgrade behavior from supported prior distributions documented/tested.

---

# CONTINUOUS MAINTENANCE

## 59. Ongoing program

After first stable release:

- monitor UPayments docs/API changes;
- review upstream changes selectively;
- track WordPress/WooCommerce/PHP/WPML changes;
- keep dependency/security automation current;
- run compatibility matrix on release candidates;
- maintain changelog/advisories;
- periodically exercise rollback/recovery;
- keep onboarding/diagnostics current;
- deprecate legacy compatibility identifiers only through explicit migrations.

---

# PART VIII — DETAILED ENGINEERING STANDARDS

## 60. WordPress/WooCommerce coding principles

- respect WordPress bootstrap/direct-access guards;
- sanitize untrusted input at the boundary;
- validate semantic constraints after sanitization;
- escape output at render time;
- use nonces for CSRF where applicable, but never treat a nonce as authorization;
- check capabilities for privileged operations;
- use WooCommerce CRUD for orders rather than direct post assumptions;
- use `$wpdb->prepare()` for variable SQL;
- avoid autoloading large/high-churn options unnecessarily;
- avoid global side effects at file include time where possible;
- use Action Scheduler/WP-Cron intentionally for bounded background work;
- keep external network operations time-bounded and failure-aware.

## 61. HTTP/provider client principles

Centralize:

- base URL/environment;
- auth header;
- timeouts;
- request JSON encoding;
- response parsing;
- HTTP status handling;
- provider status semantics;
- sanitized logging;
- stable internal error mapping;
- retry policy.

Do not let every gateway method implement its own HTTP/error logic.

## 62. Retry principles

Retry only when semantics are safe.

Safe retry examples may include read-only status retrieval after backoff.

Unsafe without provider idempotency/reconciliation evidence:

- charge creation after a timeout where the provider may have accepted the first request;
- auto-deduct after an indeterminate response;
- refund creation after an unknown provider outcome.

When outcome is indeterminate, reconcile rather than blindly duplicate.

## 63. Data migration principles

- read-only classifier first;
- explicit decision reasons;
- idempotent executor;
- resumable batches;
- no checkout hot-path mass migration;
- do not silently coerce malformed data;
- preserve historical evidence until safe deletion policy exists;
- sanitized audit logs;
- rollback/recovery plan.

## 64. Cache principles

Performance caches must never turn ambiguous security identity into trusted identity.

Where security correctness requires a fresh read, verify or invalidate caches deliberately. H12 force-refresh failure handling is evidence that cache failure is part of the threat/correctness model, not merely a performance detail.

## 65. DB principles

- minimize custom tables;
- if custom tables are needed, document schema/version/upgrade/rollback;
- preserve existing durable subscription-attempt journal semantics;
- no rename of historical tables merely for branding;
- add indexes based on measured queries;
- avoid unbounded scans in request paths.

## 66. Frontend JS principles

- avoid globals;
- use the frozen `simplixPayUpayments` global only when a global is truly necessary;
- guard optional browser/wallet APIs;
- avoid double-submit;
- expose deterministic validation errors;
- do not bundle platform libraries unnecessarily;
- keep checkout state compatible with WooCommerce lifecycle APIs;
- no sensitive values in browser logging.

## 67. Frontend CSS principles

- component-scoped;
- no broad theme overrides;
- responsive;
- RTL-aware;
- accessible focus states;
- no layout hacks that assume one theme;
- prefer design tokens/custom properties under `--simplixpay-upayments-*`.

---

# PART IX — REVIEW CHECKLISTS

## 100. Payment-critical PR checklist

- [ ] exact base/head verified;
- [ ] provider contract source identified;
- [ ] customer/browser input not trusted as payment truth;
- [ ] idempotency/replay considered;
- [ ] network timeout/indeterminate outcome covered;
- [ ] order/customer identity matching covered;
- [ ] saved-card provenance covered if applicable;
- [ ] subscription concurrency covered if applicable;
- [ ] provider mutations not blindly retried;
- [ ] persistence failures covered;
- [ ] sanitized logging;
- [ ] regression tests added;
- [ ] rollback/recovery described.

## 101. Security PR checklist

- [ ] capability checks;
- [ ] nonce/CSRF where applicable;
- [ ] validation/sanitization;
- [ ] output escaping;
- [ ] SQL preparation;
- [ ] secret handling;
- [ ] token/PII logging;
- [ ] replay/idempotency;
- [ ] cross-user/IDOR;
- [ ] failure behavior;
- [ ] supply-chain/update impact;
- [ ] security tests/negative cases.

## 102. Migration PR checklist

- [ ] read-only classifier separated from mutation;
- [ ] every decision reason explicit;
- [ ] all relevant blocker cases tested;
- [ ] incomplete evidence fails indeterminate/blocked;
- [ ] cross-user conflicts fail closed;
- [ ] idempotent;
- [ ] rollback verified;
- [ ] no checkout-time full scan;
- [ ] no provider mutation unless explicitly approved;
- [ ] audit result sanitized.

## 103. Frontend PR checklist

- [ ] Classic/Blocks scope explicit;
- [ ] no global Woo/theme layout overrides;
- [ ] script/style only where required;
- [ ] no duplicate bundled platform library;
- [ ] keyboard accessible;
- [ ] screen-reader/error semantics;
- [ ] responsive/mobile tested;
- [ ] no JS console errors if optional wallet API absent;
- [ ] translations/RTL considered;
- [ ] visual regression evidence.

## 104. Repository/governance PR checklist

- [ ] no payment runtime behavior changed unless explicitly in scope;
- [ ] `AGENTS.md` and project docs remain consistent;
- [ ] status ledger reflects truth;
- [ ] CI permissions are least-privilege;
- [ ] external Actions/dependencies are intentional;
- [ ] no secrets in workflows/docs;
- [ ] CODEOWNERS remains correct;
- [ ] issue/PR templates do not request sensitive data.

---

# PART X — MASTER STATUS LEDGER

## 107. Living project-state block

**This block should be updated after every verified merge.**

```text
LAST VERIFIED PROJECT STATE
Date: 2026-09-05
Repository: SimplixInnovations/simplixpay-upayments
Historical H12 merge: SimplixInnovations/upayments-woocommerce@93e9925247a8bfade626cb822136852fd96eaea2
Repository foundation/readiness: DONE / VERIFIED
Phase 0 release identity/updater ownership: DONE / VERIFIED
Phase 9I historical token-identity migration: DONE / VERIFIED
Provider Contract & Payment Lifecycle: DONE / VERIFIED
Security Threat-Model Closure: DONE / VERIFIED
Security Threat-Model implementation: PR #17; 81 PASS / 0 FAIL; post-merge Quality Gates #89 SUCCESS
Architecture A1: DONE / VERIFIED
Architecture A2: DONE / VERIFIED; PR #22; Payment-Method Availability 102/0; post-merge Quality Gates #156 SUCCESS
Architecture A3: DONE / VERIFIED; PR #23; Gateway Settings 90/0; post-merge Quality Gates #159 SUCCESS
Architecture A4: DONE / VERIFIED; PR #24; Subscription Presentation 75/0; post-merge Quality Gates #165 SUCCESS
Architecture A5: DONE / VERIFIED; PR #25; Checkout Orchestration 67/0; post-merge Quality Gates #174 SUCCESS
Quality Platform Q1: DONE / VERIFIED; PR #26; merge 9b3ead774a5a9bc2ac0f3b3ad754b2d99053f362; post-merge Quality Gates #178 SUCCESS
Quality Platform Q2: DONE / VERIFIED; PR #28; merge 356680b9fe8a2724e778d40386ca182247715249; post-merge Quality Gates #183 SUCCESS
Quality Platform Q3: DONE / VERIFIED; PR #29; merge 30e99a6a456b72709c87e442b8437301ba64e99b; Q3 69/0; post-merge Quality Gates #189 SUCCESS
Quality Platform Q4: DONE / VERIFIED; PR #30; merge 4b3db92b0ded0c598bad0ab677babab9e6102811; Q4 68/0; post-merge Quality Gates #195 SUCCESS
Quality Platform Q5: DONE / VERIFIED; PR #31; merge 984053aee6bb50e62e457a639f44307e461f5e38; Q5 83/0; post-merge Quality Gates #198 SUCCESS
Quality Platform Q6: DONE / VERIFIED; PR #32; merge 651e604659d1891e0f7d05b8e684edb4aa31c2b1; Q6 83/0; post-merge Quality Gates #202 SUCCESS
Quality Platform Q7: DONE / VERIFIED; PR #33; merge e00a80147d4f6267d137e1bdfa0b2d1211e00f6a; Q7 69/0; post-merge Quality Gates #213 SUCCESS
Quality Platform Q8: DONE / VERIFIED; PR #34; merge b59eb2d50b86a38d8ea130de63c38a672db86d32; Q8 46/0; post-merge Quality Gates #219 SUCCESS
Quality Platform Q9: DONE / VERIFIED; PR #35; merge f63591188e232505f8307cb71fdbe4c32d2dc4c7; Q9 62/0; post-merge Quality Gates #224 SUCCESS
Quality Platform Q10: DONE / VERIFIED; PR #36; merge 02a1ad24d262c3cb6d14653bf48aa31c3796ae4e; Q10 67/0; post-merge Quality Gates #227 SUCCESS
Quality Platform Q11: DONE / VERIFIED; PR #37; merge e544a65130d4b009efea179038dd03275cd46897; tree f27880f5f2a93f1dfd6428619e5bffa75e0bd4aa; Q11 84/0; post-merge Quality Gates #230 SUCCESS
Quality Platform Q12: DONE / VERIFIED; PR #38; merge 6dc53bdaf60f12774d7516294d7004974be3874f; tree b8a9f956e304fa9dba7658809207ddae14b1f4e1; Q12 63/0; post-merge Quality Gates #232 SUCCESS
Quality Platform Q13: DONE / VERIFIED; PR #39; merge a744417e1ec2f40b4f59706df84589d8b18638cb; tree be7c52143d2085550790b742d164ecbec413377f; Q13 77/0; post-merge Quality Gates #237 SUCCESS
Quality Platform Q14: DONE / VERIFIED; PR #40; merge 22857f6304d4b4f19ec1cb6303a80d120173bcd1; tree 53107c93c8756985461a8d75e2009c91b89ee851; Q14 109/0; post-merge Quality Gates #248 SUCCESS
Quality Platform Q15: DONE / VERIFIED; PR #41; merge a4bbb05021dbded73072c0ba108a18245b60ad88; tree ea5b0b3880a99999577d51a9ed5f6a8c77a52cf0; Q15 107/0; post-merge Quality Gates #254 SUCCESS
Quality Platform Q16: DONE / VERIFIED; PR #42; merge 06a9ebd732c7cc3f062d4bb361aaef4054a1dfa3; tree b9cc6eafb3c7f8df36b9c5db8b2e45bb330688d2; Q16 120/0; post-merge Quality Gates #316 SUCCESS; main security #84 SUCCESS
Quality Platform Q17: DONE / VERIFIED; PR #43; merge 570dbf3501b359b16767d070d18c25a67a0c24fe; Q17 97/0; post-merge Quality Gates #415 SUCCESS
Quality Platform Q18: DONE / VERIFIED; PR #44; merge fe572d2bed5a7250ea98e5b5935c19f1cc6b3246; Q18 17/0; post-merge Quality Gates #442 SUCCESS
Quality Platform Q19: DONE / VERIFIED; PR #45; final head 1717f0c25da7140a7799c7db3a7f016abecec7e9; merge 29ba16a1eabc00e25c3652ae838be9b9539b3a10; tree 8230778e3313e4d201de48b1a5cf170c42f7178d; Q19 22/0; PHPUnit 174/1063; post-merge Quality Gates #464 SUCCESS; post-merge CodeQL SUCCESS
Last verified implementation main SHA: 29ba16a1eabc00e25c3652ae838be9b9539b3a10
Canonical implementation tree: 8230778e3313e4d201de48b1a5cf170c42f7178d
Current program gate: Enterprise Compatibility Certification
Production readiness: R0 — engineering hardening
Public stable release: NO
WordPress.org release: NO
Known remaining P0/P1 program blockers:
- broad compatibility/feature certification
- release engineering/distribution
```

The security implementation anchor above is post-merge verified. `PROJECT-STATUS.md` and live GitHub remain authoritative; future verified gate merges must update this living block without rewriting dated historical baselines.

## 108. Completion ledger

### Completed / verified

- [x] Standalone canonical SimplixPay repository established with clean root; historical fork retained separately as audit provenance.
- [x] Upstream relationship documented.
- [x] Security/support/contribution/maintainer documentation exists.
- [x] Initial README and compatibility philosophy exist.
- [x] Initial WPML gettext text-domain fatal remediation implemented (validation/certification still pending).
- [x] Initial broad My Account CSS interference remediation implemented (cross-theme validation still pending).
- [x] H12 canonical customer-token identity hardening merged and independently verified in historical audit repository.
- [x] Strict token/provenance/generation/scope contracts established.
- [x] H12 targeted PHP and Blocks harness evidence established.
- [x] H12 production blob anchors copied byte-identically into canonical root.
- [x] Repository foundation/readiness completed and verified.
- [x] Phase 0 release/repository safety and updater ownership completed and verified.
- [x] Required Governance and H12 Regression Harness checks established for protected `main`.
- [x] Phase 9I deterministic read-only preflight completed and verified (PR #11).
- [x] Phase 9I locked fail-closed executor completed and verified (PR #12).
- [x] Phase 9I bounded admin/CLI operations with durable redacted per-user result checkpoints completed and verified (PR #13).
- [x] Phase 9I final implementation-head regression evidence: Phase 0 35/0, preflight 123/0, executor 59/0, operations 81/0, H12 PHP 1927/0, H12 Blocks 144/0.
- [x] Provider Contract & Payment Lifecycle completed and independently verified (PR #15).
- [x] Provider lifecycle evidence: Provider Payment Lifecycle 141/0, Provider Exact Amount 4/0, H12 PHP 1927/0, H12 Blocks 144/0, with Governance/syntax green on the exact PR merge-ref and post-merge `main`.
- [x] Provider lifecycle squash merge `9569e39973a9e94926087738eae06c3846361943`, tree `40ec562674361624c2764263ba55cfba84594955`, VERIFIED signature and implementation-branch cleanup.
- [x] Security Threat-Model Closure implementation completed and independently verified (PR #17).
- [x] Security evidence: Security Threat-Model **81/0**, with Phase 0 **35/0**, Phase 9I **123/0 + 59/0 + 81/0**, Provider **141/0 + 4/0**, H12 PHP **1927/0**, H12 Blocks **144/0**, Governance/syntax green.
- [x] Security squash merge `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9`, tree `e0027005f059fad03d8c08273b7aac6553c45f53`, VERIFIED signature, green post-merge Quality Gates #89 and implementation-branch cleanup.
- [x] Architecture discovery and A1-A5 completed and independently verified through PR #25.
- [x] Architecture A5 squash merge `3223a882867634a2ba7588d7afbd2b2e4b4c21e4`, tree `392b73425fa3219b6414a0984136b92c8ef77576`, VERIFIED signature, green post-merge Quality Gates #174 and implementation-branch cleanup.

### Remaining P0/P1 work

- [x] Full Automated Quality Platform Q1 foundation — **DONE / VERIFIED** through PR #26 and post-merge Quality Gates #178.
- [x] Full Automated Quality Platform Q2 CheckoutPayload expansion — **DONE / VERIFIED** through PR #28 and post-merge Quality Gates #183.
- [x] Full Automated Quality Platform Q3 payment-concurrency expansion — **DONE / VERIFIED** through PR #29 and post-merge Quality Gates #189.
- [x] Full Automated Quality Platform Q4 authenticated-status expansion — **DONE / VERIFIED** through PR #30 and post-merge Quality Gates #195.
- [x] Full Automated Quality Platform Q5 payment-method availability expansion — **DONE / VERIFIED** through PR #31 and post-merge Quality Gates #198.
- [x] Full Automated Quality Platform — **Q6 / DONE / VERIFIED**.
- [x] Full Automated Quality Platform — **Q7 / DONE / VERIFIED** through PR #33 and post-merge Quality Gates #213.
- [x] Full Automated Quality Platform — **Q8 / DONE / VERIFIED** through PR #34 and post-merge Quality Gates #219.
- [x] Full Automated Quality Platform — **Q9 / DONE / VERIFIED** through PR #35 and post-merge Quality Gates #224.
- [x] Full Automated Quality Platform — **Q10 / DONE / VERIFIED** through PR #36 and post-merge Quality Gates #227.
- [x] Full Automated Quality Platform — **Q11 / DONE / VERIFIED** through PR #37 and post-merge Quality Gates #230.
- [x] Full Automated Quality Platform — **Q12 / DONE / VERIFIED** through PR #38 and post-merge Quality Gates #232.
- [x] Full Automated Quality Platform — **Q13 / DONE / VERIFIED** through PR #39 and post-merge Quality Gates #237.
- [x] Full Automated Quality Platform — **Q14 / DONE / VERIFIED** through PR #40 and post-merge Quality Gates #248.
- [x] Full Automated Quality Platform — **Q15 / DONE / VERIFIED** through PR #41 and post-merge Quality Gates #254.
- [x] Full Automated Quality Platform — **Q16 / DONE / VERIFIED** through PR #42 and post-merge Quality Gates #316.
- [x] Full Automated Quality Platform — **Q17 / DONE / VERIFIED — PAYMENT-RUNTIME CLOSEOUT** through PR #43 and post-merge Quality Gates #415.
- [x] Full Automated Quality Platform — **Q18 / DONE / VERIFIED — BLOCKS AVAILABILITY ENFORCEMENT** through PR #44 and post-merge Quality Gates #442.
- [x] Full Automated Quality Platform — **Q19 / DONE / VERIFIED — SUBSCRIPTION PRODUCT ELIGIBILITY CONSISTENCY** through PR #45 and post-merge Quality Gates #464; the numbered sequence is closed at Q19.
- [ ] WordPress/WooCommerce/PHP compatibility certification — **CURRENT / ENTERPRISE COMPATIBILITY CERTIFICATION**.
- [ ] WPML/WCML certification.
- [ ] Feature-specific certification: saved cards/subscriptions/wallets/multi-merchant/refunds.
- [ ] Performance/stability program.
- [ ] UI/UX/accessibility/browser/device program.
- [ ] Onboarding/Site Health/diagnostics.
- [ ] Structured error taxonomy/logging/observability.
- [ ] Product branding/docs/SEO/badges finalization.
- [ ] Reproducible release engineering.
- [ ] Public stable release.
- [ ] WordPress.org submission.
- [ ] Continuous maintenance.

---

# PART XI — RELEASE CHECKLISTS

## 109. Controlled production pilot checklist

Do not begin pilot until all are true:

- [ ] exact release commit/tag frozen;
- [x] updater cannot switch to upstream;
- [ ] API credentials rotated/secure;
- [ ] backup/rollback ready;
- [ ] migration status for this store known;
- [ ] no `BLOCKED`/`INDETERMINATE` token identities are silently used;
- [ ] selected checkout mode explicitly tested;
- [ ] enabled payment methods individually tested;
- [ ] webhook receives provider events;
- [ ] status reconciliation verified;
- [ ] failed/cancelled/processing scenarios verified;
- [ ] logging redaction verified;
- [ ] current environment versions recorded;
- [ ] unsupported features disabled/documented;
- [ ] monitoring/support owner assigned.

## 110. Public stable release checklist

- [ ] all R3/R4 readiness gates;
- [ ] compatibility matrix current;
- [ ] no known P0 defects;
- [ ] CI required checks green;
- [ ] security review current;
- [ ] upgrade tests from supported historical versions;
- [ ] release ZIP reproducible;
- [ ] checksums recorded;
- [ ] changelog/release notes;
- [ ] install/activation test from ZIP;
- [ ] updater channel test;
- [ ] rollback test;
- [ ] documentation/support links valid;
- [ ] release tag immutable/protected policy.

---

# PART XII — NEW CHAT AND AGENT TEMPLATES

## 111. New Chat bootstrap template

Copy/paste:

```text
PROJECT: SimplixPay for UPayments / SimplixInnovations/simplixpay-upayments

Read root AGENTS.md first, then docs/project/PROJECT-STATUS.md, docs/project/NAMING-IDENTITY-STANDARD.md, docs/project/NEW-CHAT-HANDOFF.md, docs/project/PHASE-0-RELEASE-IDENTITY.md, docs/project/PHASE-9I-MIGRATION.md, docs/project/PROVIDER-PAYMENT-LIFECYCLE.md and the relevant sections of docs/project/MASTER-ENGINEERING-PLAYBOOK.md.

Rules for this session:
1. Treat recorded Git SHAs as historical until live GitHub verification confirms them.
2. Independently verify GitHub main, open PRs, branches, current source, CI, updater and relevant official docs.
3. Identify the first unfinished permitted gate in PROJECT-STATUS.md.
4. Preserve H12 payment/token contracts unless an approved later phase explicitly supersedes them.
5. Preserve protected historical upayments/upay runtime/persistence identities unless an approved migration explicitly changes them.
6. Do repository work directly where connected tooling permits. Delegate only genuinely inaccessible actions.
7. Do not approve a merge based on an Agent report; verify remote source/diff/tests independently.
8. Pin approvals/merges to exact base/head SHAs.
9. After merge, independently verify main/checks/critical files/branch cleanup before marking DONE.
10. Update PROJECT-STATUS.md when a verified milestone changes project truth.
```

## 112. Agent implementation template

```text
PHASE: <name>
REPOSITORY: SimplixInnovations/simplixpay-upayments
BRANCH: <branch>
REQUIRED BASE SHA: <full sha>
PR: <number/new>

OBJECTIVE
<one exact objective>

IN SCOPE
- ...

OUT OF SCOPE / DO NOT CHANGE
- ...

SECURITY/CORRECTNESS INVARIANTS
- ...

IMPLEMENTATION REQUIREMENTS
1. ...

TESTS / COMMANDS
- ...

STOP. DO NOT MERGE.
Awaiting reviewer verification.
```

## 113. Reviewer template

```text
REVIEW — <PR/phase>

Verify live:
- base SHA
- head SHA
- changed files/diff
- critical source
- executable evidence
- CI status
- protected invariants

If any required fact cannot be independently verified:
NOT APPROVED.
DO NOT MERGE.

If approved, identify the exact approved head SHA and allowed merge method.
```

## 114. Merge template

```text
FINAL MERGE — <PR>

Pre-merge expected:
main = <sha>
head = <sha>

Fail closed if either moved.
Use the approved merge method only.
Do not rebase/squash/force/admin-bypass unless explicitly approved.

After merge verify:
- PR merged state
- resulting main/merge SHA
- expected topology for chosen merge method
- critical file SHAs where applicable
- required checks
- delete feature branch only after verification

STOP.
Awaiting reviewer verification of merge.
```

---

# PART XIII — SOURCES AND STANDARDS REGISTRY

## 115. Project sources

- Canonical Simplix repository: https://github.com/SimplixInnovations/simplixpay-upayments
- Historical Simplix audit archive: https://github.com/SimplixInnovations/upayments-woocommerce
- Upstream repository: https://github.com/upaymentskwt/woocommerce
- Current Simplix README: repository `README.md`
- Current project status: repository `docs/project/PROJECT-STATUS.md`
- Naming/identity standard: repository `docs/project/NAMING-IDENTITY-STANDARD.md`
- Closed Phase 9I evidence: repository `docs/project/PHASE-9I-MIGRATION.md`
- Closed Provider Contract & Payment Lifecycle evidence: repository `docs/project/PROVIDER-PAYMENT-LIFECYCLE.md`
- Current compatibility matrix: repository `docs/COMPATIBILITY.md`
- Current security policy: repository `SECURITY.md`
- Current upstream relationship: repository `UPSTREAM.md`

## 116. UPayments official documentation

Maintain a dated snapshot/review of relevant pages. Examples include:

- WooCommerce: https://developers.upayments.com/reference/woocommerce
- Create Customer Unique Token: https://developers.upayments.com/reference/createcustomeruniquetoken
- Retrieve Cards: https://developers.upayments.com/reference/retrievecustomercards
- Add Card: https://developers.upayments.com/reference/addcard
- Make Charge: https://developers.upayments.com/reference/addcharge
- Webhook: https://developers.upayments.com/reference/webhook
- Payment status: provider current documented status endpoint/reference
- Subscriptions/auto deduction: provider current documentation
- Multi-vendor/multi-merchant: provider current documentation
- Refunds: provider current documentation

Provider documentation is time-sensitive. Re-check it when the implementation depends on it.

## 117. WordPress/WooCommerce sources

Use current official sources for:

- WordPress plugin developer handbook/guidelines;
- WordPress.org plugin naming/readme/Plugin Check requirements;
- WooCommerce extension compatibility;
- HPOS;
- Cart/Checkout Blocks payment integrations;
- WooCommerce CRUD/order APIs;
- Action Scheduler;
- WooCommerce logging;
- extension testing/release guidance.

## 118. WPML/WCML sources

Use current official WPML/WCML docs for:

- gettext/text domains;
- String Translation;
- WooCommerce Multilingual & Multicurrency;
- language URL behavior;
- currency integration;
- RTL/multilingual admin/frontend concerns.

---

# PART XIV — DESIGN PRINCIPLES

## 119. Core principles

1. **Payment correctness over convenience.**
2. **Fail closed when identity/security evidence is ambiguous.**
3. **Never infer provider success from prose.**
4. **Do not blindly retry non-idempotent financial mutations.**
5. **Historical compatibility identifiers are contracts, not naming dirt.**
6. **Characterize before refactoring.**
7. **Incremental architecture replacement beats a big-bang rewrite.**
8. **Public compatibility claims require reproducible evidence.**
9. **Security is designed; public source is not itself a security weakness.**
10. **Repository/release/update ownership is part of payment security.**
11. **Tests should protect decisions and failure modes, not vanity percentages.**
12. **Operational recovery is part of correctness.**
13. **Logs/diagnostics must help merchants without leaking sensitive data.**
14. **Performance optimization must be measured and must not weaken security correctness.**
15. **Documentation must describe reality, not aspiration.**

## 120. Closing project rule

The plugin should not be declared broadly production-ready, secure, compatible, certified, or WordPress.org-ready merely because a large amount of engineering work has been completed.

The correct final standard is:

> **Every release claim must be backed by the exact reviewed source, reproducible tests, current compatibility evidence, secure distribution controls, and an operational recovery path.**
