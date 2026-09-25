# SUPCheckout for UPayments — Owner Handoff

**Purpose:** authoritative fresh-clone, local-acceptance and release-decision procedure
**Canonical GitHub repository:** `SimplixInnovations/supcheckout`
**Development version:** `0.1.0`
**Public publication:** **NOT AUTHORIZED**

This is a living operational procedure. Historical tranche detail belongs in the referenced plans/ADRs and Git history; current program coordinates are stated once below to avoid contradictory handoff prose.

## 1. Acceptance authority and current program coordinate

Owner technical acceptance remains **ACCEPTED only for the frozen Approach 2 baseline**:

- source SHA: `0c883d609906676966002eb022a82a9656eeacc5`;
- package: `supcheckout-0.1.0.zip`;
- files: 51;
- SHA-256: `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

Approach 3 advanced through T3 at `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`. The completed post-T3 R0/R1/E1-E3 integration milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`; T2 `047cc86060efb97761d7a0cc4a3806f971ab6fe1` remains the last separately tracked runtime-bearing tranche before that milestone.

Current successor program: `post-t3-ecosystem-hardening`. R2 is **DONE / VERIFIED** (PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`). R3 is **DONE / VERIFIED** (PR #112 head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`). R4 is **DONE / VERIFIED** (PR #114 head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`). R5 is **DONE / VERIFIED** (PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`). The current gate is R6 final qualification.

Latest repository-executable E3 runtime checkpoint is `540b733c29656758f2392817649fc3d4a4db585d`; its deterministic 55-file candidate package SHA-256 is `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

At that E3 checkpoint, Quality/H12, the 20-cell Compatibility matrix and gate, Provider Sandbox, WordPress.org Submission Check, Release Artifact, the full repository-owned Ecosystem Certification matrix, delayed/combined/repeated Classic JS characterization and analytics-return characterization all passed. The historical default CodeQL JavaScript/TypeScript job did not reach a terminal verdict; do not rewrite that as success.

Current executable gate: **owner technical acceptance**.

Neither PR #108 nor the E3 checkpoint redefines owner acceptance. Fresh owner acceptance is required at Approach 3 closeout.

## 2. Golden identity

Human-facing product: **SUPCheckout for UPayments**

Technical contracts:

- slug/text domain: `supcheckout`;
- namespace: `Simplixi\SUPCheckout`;
- package root: `supcheckout/`;
- first-stable bootstrap: `supcheckout/UPayments.php`;
- gateway/payment/Blocks ID: `upayments`;
- callback: `wc_upayments`.

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
- package contains `supcheckout/UPayments.php` and `readme.txt`;
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
- required checks `Governance`, `H12 Regression Harness`, `Compatibility Gate`, `Release Gate`;
- exact-head CodeQL/security, Provider Sandbox, WordPress.org Submission Check and deterministic artifact evidence.

A successful check from an ancestor SHA is never substituted for final exact-head evidence.

## 9. Remaining Approach 3 program

Current executable gate: **owner technical acceptance**.

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
