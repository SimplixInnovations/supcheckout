# SUPCheckout for UPayments — Owner Handoff

**Purpose:** authoritative fresh-clone, local-acceptance and release-decision procedure
**Canonical GitHub repository:** `SimplixInnovations/supcheckout`
**Development version:** `0.1.0`
**Public publication:** **NOT AUTHORIZED**

This is a living operational procedure. Historical tranche detail belongs in the referenced plans/ADRs and Git history; current program coordinates are stated once below to avoid contradictory handoff prose.

## 1. Acceptance authority and current program coordinate

Owner technical acceptance is **ACCEPTED for Release Candidate 1** (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1`, recorded 2026-10-07):

- source SHA: `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (PR #128 squash-merged main);
- package: `supcheckout-0.1.0.zip`;
- files: 62;
- SHA-256: `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`.

Historical Approach 3 (superseded by Release Candidate 1, `OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`): source `146d65a1c182630c1acc651cacafe30cff5f6b79`, 62 files / SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`.

Historical Approach 2 (superseded): source `0c883d609906676966002eb022a82a9656eeacc5`, 51 files / SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

Approach 3 advanced through T3 at `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`. The completed post-T3 R0/R1/E1-E3 integration milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`; T2 `047cc86060efb97761d7a0cc4a3806f971ab6fe1` remains the last separately tracked runtime-bearing tranche before that milestone.

Current successor program: `external-certification-release-readiness`. R2 is **DONE / VERIFIED** (PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`). R3 is **DONE / VERIFIED** (PR #112 head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`). R4 is **DONE / VERIFIED** (PR #114 head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`). R5 is **DONE / VERIFIED** (PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`). R6 is **DONE / VERIFIED** (PR #119 head `4eccf884f95fe510a478c243ecb32d039f9468a9`, merged main `3bd37c6a925ec724c7fbf8d8c0346fd7e21931cf`). The current executable gate is the revamp's hardening program (below); your Sub-project 0c staging review is done and recorded ([evidence](evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md)); the release gate, external certification & release readiness, stays open. Publication remains NOT AUTHORIZED. For the narrower first public core one-time-payment scope, the provider-dependent contract is `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` (2026-10-13, account-scoped, packages containing PR #138); saved-card/token and recurring contracts remain deferred feature gates.

Latest repository-executable E3 runtime checkpoint is `540b733c29656758f2392817649fc3d4a4db585d`; its deterministic 55-file candidate package SHA-256 is `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

At that E3 checkpoint, Quality/H12, the 20-cell Compatibility matrix and gate, Provider Sandbox, WordPress.org Submission Check, Release Artifact, the full repository-owned Ecosystem Certification matrix, delayed/combined/repeated Classic JS characterization and analytics-return characterization all passed. The historical default CodeQL JavaScript/TypeScript job did not reach a terminal verdict; do not rewrite that as success.

Release gate (open): **external certification & release readiness**. Release Candidate 1 owner acceptance recorded: `OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1` at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (package `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`), superseding Approach 3 (`146d65a1c182630c1acc651cacafe30cff5f6b79`). Publication remains NOT AUTHORIZED.

Current provider disposition after exhaustive first-party research:

- `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` — resolved 2026-10-13 by the owner-run account probe in [`PRODUCTION-AUTH-ACCOUNT-PROBE.md`](PRODUCTION-AUTH-ACCOUNT-PROBE.md) ([evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md)), scoped to the release merchant account and packages containing PR #138. Release Candidate 1, and every package before PR #138, sends a non-white-label Charge that this production account rejects (HTTP 422, `paymentGateway.src` missing), so it must not be released.
- saved-card/token persistence: FUTURE FEATURE GATE.
- auto-deduct capture/cycle identity: FUTURE RECURRING GATE.
- webhook signature mechanics: FUTURE DEFENSE-IN-DEPTH.
- sandbox credential-family migration: FUTURE TEST-INFRASTRUCTURE GATE.
- automatic recurring `VERIFIED_SUCCESS`: FAIL-CLOSED / UNREACHABLE.
- publication: NOT AUTHORIZED.

See `docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md` and `docs/project/UPAYMENTS-PROVIDER-CONTACT-DRAFT.md`.


Release Candidate 1 is the current owner-accepted technical baseline; Approach 3 is historical. Publication remains NOT AUTHORIZED. Unresolved provider contracts remain uncertified.

Active engineering program (reconciled 2026-10-23): the SUPCheckout revamp (`docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md`). Sub-project 0a clean core is **DONE / VERIFIED** and staging-rehearsed ([evidence](evidence/SUBPROJECT-0A-CLEAN-CORE-2026-10-17.md)). Sub-project 0b takeover engine Tasks 1–12 are merged (PRs #173–#184 except #178) and the Takeover Migration Matrix is green on `main` at `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b`. The 0b takeover staging rehearsal PASSED on 2026-10-18 ([evidence](evidence/TAKEOVER-REHEARSAL-2026-10-18.md)). Sub-project 0c, the onboarding backend (services + admin-only `supcheckout/v1` REST API, no UI; the wizard moves to Sub-project 2), is engineering-complete and merged through PR #194 ([evidence](evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md)). Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22): you ran it on the My Organic World staging copy; all seven setup checks passed (the optional sandbox test payment is deferred to the setup screen), WCML per-currency checkout visibility passed, and a sandbox AED card order completed as Processing with Payment Status CAPTURED. The automatic LiteSpeed purge is covered by the real-plugin CI cell; confirming it on the live store's real takeover is on your manual test list ([evidence](evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md)). Sub-project 1 design-token system and admin component library — **DONE / VERIFIED** (PRs #195–#197, #199, #200, #202–#209 and #211; package 224 files, not owner accepted; [evidence](evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md), including your manual test list: NVDA/VoiceOver, real Windows High Contrast, Safari, Firefox and mobile, and a real Arabic copy review). Owner decision 2026-10-20: functionality, security and performance are verified before the revamp's information architecture and branding.

Hardening program (repository-executable fix loop) — **DONE / VERIFIED** (2026-10-23). [Evidence](evidence/HARDENING-PROGRAM-2026-10-23.md).
- **Audit:** six audits, then a fix loop shipped as #215, batch 1 #221, batch 2 #222, batch 3 #223, batch 4 #226 (`hardening/batch-4`, merged `eed5bbd`) and batches 5 and 6 (`hardening/batch-5`, one PR, open).
- **Batch 4:** the last fixable residuals: A1-03 note dedupe, note-lock token release, N-2, N-3, M-5 rest, O4 copy, A2-02 revised (https-only live links, with an `*.upayments.com` http to https upgrade), H12-META, AS-1 and the Subscription test-isolation fix. H12-META was a real WooCommerce meta-read defect: the historical order-meta read assumed index 0 and called a `WC_Meta_Data::get_value()` that WooCommerce lacks, so on real stores save-card, selected-card and subscription checkout failed closed at the persistence readback and Phase 9I could not run; batch 4 fixes it. A6-06 (PHPStan stubs) stayed tooling backlog until batch 6.
- **Batches 5 and 6:** batch 5 fixes the safe "left on purpose" items (owner instruction 2026-10-25): A1-11 (paid orders go to Processing on a fresh install; a stored setting keeps its value), A4-05 rest (RTL-safe Classic and Blocks checkout UI with logical properties), A4-08 (the product subscription opt-out follows the WPML original) and U2 N-1 (the sweep unschedules a lost reconcile event, so the bounded checks resume); A6-08 is closed by design. Batch 6 fixes A6-06: PHPStan analyses all of `src/` at level 5 against the official WordPress 6.9 and WooCommerce 10.8 stubs, with no baseline (89 errors to 0, runtime-neutral). The remaining recorded items are closed by design or wait on provider answers (subscriptions stay gated by A1-01).
- **Your decision 2026-10-22 ("Block auto-charges"):** no automatic renewal charge is sent until its success can be verified.
- **Package:** 233 files, SHA-256 `1b7a5aca04ee09a79fae816cf2d18e34177c55f63a851bb95f0a113d9e91812f` at the batches 5 and 6 head (not owner accepted). The batch 4 package `2a8e1aba7e056151a5995c9147eccfd2700efe866ac7b8c36ed87f2b17def6db` and the owner-verified batch 3 package `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8` are historical.

Owner staging verification of the hardened package — **DONE / OWNER-VERIFIED** (2026-10-24). [Evidence](evidence/OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md). Verified package: 233 files, SHA-256 `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8` (batch 3, now historical; not owner accepted).

Current executable gate: **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)**. Share the information architecture and branding when ready. Still owner-side and not blocking:
- confirm whether staging email is blocked on purpose;
- do a native Arabic review of the bundled translations;
- check the LiteSpeed/Cloudflare purge at the live cutover;
- send the 14 UPayments support questions;
- once UPayments enables white-label on your account, check save-card, selected-card and subscription checkout on staging with the current hardening package (H12-META, fixed in batch 4).

Sub-project 2 admin screens begin only after the owner shares the information architecture and branding. Details: `PROJECT-STATUS.md` "Revamp program".

## 2. Golden identity

Human-facing product: **SUPCheckout for UPayments**

Technical contracts:

- slug/text domain: `supcheckout`;
- namespace: `Simplixi\SUPCheckout`;
- package root: `supcheckout/`;
- main file: `supcheckout/supcheckout.php` (a header-less transitional `supcheckout/UPayments.php` shim moves pre-rename installs automatically, except through the classic non-JavaScript single-plugin Update-now flow, which ends deactivated with data unchanged);
- gateway/payment/Blocks ID: `upayments` (`supcheckout` only after a store runs the takeover's cutover);
- callback: `wc_upayments` (served permanently; the takeover adds the `supcheckout` route).

Do not mechanically rename persisted/provider IDs, `_upay_*` metadata, `UPayments_order_id`, token/provenance state, subscription/billing-attempt state, historical payment-method values, frozen Phase 9I IDs, `getAPIUrlForRetreiveCards()` or `whitelabled`.

## 3. Fresh local bootstrap

Start from a new destination. Do not copy an older `.git`, `vendor/`, `dist/`, caches or worktree metadata.

```bash
git clone https://github.com/SimplixInnovations/supcheckout.git supcheckout
cd supcheckout
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

At a final acceptance/release boundary:

- `origin` must be the canonical repository;
- branch must be `main`;
- `HEAD == origin/main`;
- working tree must be clean;
- no unintended worktree/stash/feature branch may remain.

Between bounded engineering tranches, the expected clean state is `main` only, with no feature worktree, stash, feature branch or open PR. A future tranche may create a bounded branch/PR and must return to this clean state after merge.

## 4. Toolchain preflight

Verify:

```bash
git --version
bash --version
php -v
composer --version
node --version
npm --version
wp --info
python3 --version
```

On Windows, use Git for Windows Bash for the acceptance checkout when the worktree is a Windows Git worktree. Do not globally reconfigure Python merely for this project.

## 5. Independent local acceptance

Use a disposable detached worktree from the exact final `origin/main`:

```bash
SOURCE_REPO="$(git rev-parse --show-toplevel)"
ACCEPTANCE_DIR="${SOURCE_REPO}/../supcheckout-owner-acceptance"
test ! -e "$ACCEPTANCE_DIR"
git worktree add --detach "$ACCEPTANCE_DIR" origin/main
cd "$ACCEPTANCE_DIR"
git status --short
git rev-parse HEAD
git rev-parse origin/main
```

Required: clean status and identical SHAs.

Install and run the locked quality stack:

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer audit --locked
composer quality
```

Any unexplained failure, warning, first-party deprecation or notice blocks acceptance.

Run the high-value standalone contracts required by `AGENTS.md`, including identity/namespace/frontend/residue, WordPress.org runtime/submission, HTTP transport, provenance DB-failure, H12 PHP and H12 Blocks harnesses.

## 6. Deterministic artifact acceptance

```bash
rm -rf dist
bash scripts/build-release.sh dist
bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip
sha256sum dist/supcheckout-0.1.0.zip
cat dist/supcheckout-0.1.0.zip.sha256
```

Required:

- build and verifier succeed;
- checksum matches the sidecar;
- Linux/Windows/canonical artifacts for one exact Git tree are byte-identical;
- package has one `supcheckout/` root;
- package contains `supcheckout/supcheckout.php`, the header-less `supcheckout/UPayments.php` shim and `readme.txt`;
- development/tests/CI/docs/tooling/secrets/local artifacts are absent.

Install only into disposable/staging WordPress first.

## 7. Merchant-facing acceptance

### Identity/settings

Verify one plugin entry, correct display name, retained physical bootstrap, settings save/reload, masked credentials, enabled/disabled behavior and no secret leakage.

### Classic checkout

Verify gateway ID `upayments`, enabled payment methods, fail-closed unavailable methods, successful sandbox initialization, decline/cancel unpaid semantics, authenticated callback truth and correct order/transaction binding.

### Cart/Checkout Blocks

Verify registration, reactive availability, selection, no duplicate registration, no console errors and fail-closed unavailable state.

### Storage

Where safe, test both legacy order storage and HPOS through normal WooCommerce CRUD.

### Saved cards/tokens

Use only test identities. Verify ownership/provenance/scope, opaque browser handles, explicit selected-card authority and no cross-user leakage.

### Subscription boundary

Do not execute live non-idempotent renewal merely to obtain a green acceptance result. Verify pre-dispatch eligibility and the current R3/R4 evidence honestly. No first-card fallback is an acceptable final state.

### Economics

WooCommerce finalized amount/currency is authoritative. Exercise zero-total, coupons, fees, shipping, tax/VAT and supported product composition. `products[]` is descriptive only.

### UI/assets/accessibility smoke

Verify icons/assets, keyboard operability, label/live-region integrity, no obvious focus/contrast regression and no unrelated global asset pollution. Full accessibility certification remains separate evidence.

## 8. GitHub/repository verification

Before final acceptance or release decision verify live:

- default branch `main`;
- intended branch topology only;
- no unintended open PRs/issues;
- no public tags/releases before authorization;
- squash-only merge / linear history;
- review-thread resolution;
- deletion/non-fast-forward protection;
- no bypass actors;
- required checks `Governance`, `H12 Regression Harness`, `Compatibility Gate`, `Release Gate`, `Admin UI Gate`;
- exact-head CodeQL/security, Provider Sandbox, WordPress.org Submission Check and deterministic artifact evidence.

A successful check from an ancestor SHA is never substituted for final exact-head evidence.

## 9. Remaining Approach 3 program

Release gate (open): **external certification & release readiness**. Release Candidate 1 owner acceptance recorded: `OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1` at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` (package `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`), superseding Approach 3 (`146d65a1c182630c1acc651cacafe30cff5f6b79`). Publication remains NOT AUTHORIZED.

Then:

1. R3 subscription safety redesign — DONE / VERIFIED;
2. R4 due-work scalability/observability — DONE / VERIFIED;
3. R5 callback lifecycle consolidation — DONE / VERIFIED under Option B / ADR-003;
4. R6 immutable exact-head release qualification and fresh owner re-acceptance.

Paid/licensed themes/plugins, CDN/server modes, broad browser/device evidence, production merchant payment completion, wallets, multilingual/RTL, representative load, penetration/PCI/legal and live subscription mutation remain external/manual until actually proven.

## 10. Release decision

A release is permitted only after:

- all approved Approach 3 tranches are closed or explicitly excluded with owner approval;
- exact final head satisfies every required repository/security/release gate;
- manual/external gaps are explicitly classified;
- fresh-clone owner acceptance is completed;
- new accepted source/package coordinates are recorded;
- the owner explicitly authorizes version/tag/GitHub Release/WordPress.org publication.

Until then:

**DO NOT TAG. DO NOT CREATE A GITHUB RELEASE. DO NOT PUBLISH TO WORDPRESS.ORG.**

See `START-HERE.md`, `PROJECT-STATUS.md`, `NEW-CHAT-HANDOFF.md`, `RELEASE-ENGINEERING.md` and `docs/COMPATIBILITY.md` for the current evidence boundary.
