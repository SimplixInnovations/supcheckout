# SUPCheckout for UPayments — Release Engineering

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


**Current status:** owner technical acceptance COMPLETED for Approach 3 (`OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`) at `146d65a1c182630c1acc651cacafe30cff5f6b79`; package 62 files / SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`; explicit release administration (tag/GitHub Release/WordPress.org publication) remains NOT AUTHORIZED; automatic recurring `VERIFIED_SUCCESS` remains FAIL-CLOSED pending provider contracts
**Canonical GitHub repository:** `SimplixInnovations/supcheckout`
**Canonical plugin/package slug:** `supcheckout`
**Development version:** `0.1.0`
**Accepted Approach 3 baseline:** `146d65a1c182630c1acc651cacafe30cff5f6b79`
**Accepted package:** `supcheckout-0.1.0.zip` — **62 files**, SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`
**Historical Approach 2 (superseded):** `0c883d609906676966002eb022a82a9656eeacc5` / 51 files / `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`

## Certification anchors

### Final SUPCheckout identity baseline

PR #67 certified head `0059f365883fa4edd6a2d623c7b370d38d3f565c` squash-merged as `7547e59a2d5ef6d49b059851c6899a2d9987b16a`.

Fresh post-merge evidence:

- Quality #896 — **SUCCESS**;
- Compatibility #424 — **16/16 SUCCESS**;
- Release Artifact #373 — **SUCCESS**;
- Provider Sandbox #334 — **SUCCESS**;
- WordPress.org Submission Check #231 — **SUCCESS**;
- CodeQL/main-security #717 — **SUCCESS**.

The GitHub repository was then renamed to `SimplixInnovations/supcheckout`; public tag/Release/WordPress.org publication remain unperformed.

### Post-rename coordinate closure

PR #68 exact head `0e6ef6334282a83a428da7ee793daa98360c2bcc` passed Quality #900, Compatibility #428 (**16/16**), Release Artifact #377, Provider Sandbox #338, WordPress.org #235 and CodeQL #722, then squash-merged as `05fec942cc8fbeb58cfd0bd41f0ef5fdb86f966f`.

Fresh post-merge evidence:

- Quality #901 — **SUCCESS**;
- Compatibility #429 — **16/16 SUCCESS**;
- Release Artifact #378 — **SUCCESS**;
- Provider Sandbox #339 — **SUCCESS**;
- WordPress.org Submission Check #236 — **SUCCESS**;
- CodeQL/main-security #723 — **SUCCESS**.

At PR #68 closeout, before documentation-only PR #69 opened, remote topology was `main` only. No public tags/releases exist.


### Runtime-bearing release candidate

Latest runtime-bearing merge:

`047cc86060efb97761d7a0cc4a3806f971ab6fe1`

Certified Approach 3 T2 PR #104 head:

`4cff2dc6e6d11a4b3232a6d3d70d6280a59741c4`

Fresh merged-main evidence:

- **41/41 check-runs SUCCESS**;
- T1 dependency/provider-egress guardrail — **11/0**;
- T1 active callback characterization — **41/0**;
- T2 direct legacy-fallback characterization — **25/0**;
- Compatibility Certification — full matrix + **Compatibility Gate SUCCESS**;
- **Release Gate SUCCESS**;
- WordPress.org packaged Plugin Check, Provider Sandbox and CodeQL/security — **SUCCESS**;
- deterministic T2 candidate ZIP — **51 files**, SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`;
- canonical/Linux/Windows package evidence — **byte-identical**.

(HISTORICAL) This paragraph described the T2-era candidate state before Approach 3 owner acceptance.

The package contract excludes repository-only `README.md`, `CHANGELOG.md` and `SECURITY.md`; it retains WordPress `readme.txt` and `LICENSE`.

### Final control-plane closeout

PR #59 merged as:

`9591c431e1eb56fe40ca60147afdf9f3f909a212`

Fresh push-triggered evidence on that exact `main` SHA:

- Quality #773 — **SUCCESS**;
- Compatibility #301 — **all 16 cells SUCCESS**;
- Release Artifact #252 — **SUCCESS**;
- Provider Sandbox #216 — **SUCCESS**;
- WordPress.org Submission Check #110 — **SUCCESS**;
- CodeQL/main-security #588 — **SUCCESS**.

This evidence certifies the engineering artifact and migration contract. It does not create a public tag, GitHub Release or WordPress.org publication.

## Canonical package contract

The first-stable release identity is:

- human product: **SUPCheckout for UPayments**;
- package root: `supcheckout/`;
- physical main file: `UPayments.php`;
- plugin basename: `supcheckout/UPayments.php`;
- text domain: `supcheckout`;
- namespace: `Simplixi\SUPCheckout`;
- current development artifact: `supcheckout-0.1.0.zip`.

The retained `UPayments.php` filename is deliberate. Real-install qualification proved that directly renaming an already-active physical main file can strand WordPress's persisted plugin basename.

A future physical filename `supcheckout.php` is a separately gated migration target, not a first-stable requirement.

## Deterministic artifact contract

Build and verify:

```bash
bash scripts/build-release.sh dist
bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip
sha256sum dist/supcheckout-0.1.0.zip
cat dist/supcheckout-0.1.0.zip.sha256
```

The builder/verifier requires:

- distribution path set and bytes from exact Git `HEAD` tree/blobs;
- no dependence on mutable worktree/staged-index state;
- sorted archive paths;
- cross-platform deterministic ZIP container bytes using stored entries rather than environment-dependent DEFLATE output;
- fixed timestamps, creator-system metadata and file modes enforced by the verifier;
- canonical, Linux and Windows CI builds whose ZIP sidecars and per-file manifests must be byte-identical;
- ZIP SHA-256 sidecar;
- sorted per-file SHA-256 manifest;
- explicit release-path allowlist;
- exactly one `supcheckout/` ZIP root;
- exact source-byte verification;
- rejection of a rehashed/self-consistent ZIP whose bytes diverge from Git HEAD;
- reproducible byte-identical output from the same source commit.

Development/control surfaces such as `.github/`, `tests/`, `docs/`, `scripts/`, Composer development metadata and analysis configs are excluded according to `.distignore` and the release allowlist.

## Packaged runtime certification

Release Artifact CI builds one exact candidate artifact, verifies it, transfers it through pinned upload/download actions, verifies it again, then installs that exact ZIP into real WordPress/WooCommerce.

Permanent packaged smoke includes:

- activation and Classic gateway registration;
- release support metadata and Woo feature declarations;
- Blocks registration/availability;
- real Woo order CRUD with legacy authoritative storage;
- real Woo order CRUD with HPOS authoritative storage.

The WordPress plugin is installed under slug `supcheckout` while the protected WooCommerce gateway/payment identity remains `upayments`.

## Pre-release legacy-root migration certification

Changing package root from either real pre-stable root (`simplixpay-upayments/` or `sucheckout-upayments/`) to `supcheckout/` changes the WordPress plugin basename. This is therefore not represented as an invisible same-basename auto-update.

Permanent migration cells include:

- WordPress 7.1 / WooCommerce 11.1.0 / PHP 8.3;
- WordPress 6.9.7 / WooCommerce 10.8.1 / PHP 8.3.

Each cell:

1. builds the prior certified pre-rebrand package from historical source `54b1fbcc280b92372bd93baf929d6a746cfd3959`;
2. installs/activates it as `simplixpay-upayments/UPayments.php`;
3. seeds protected merchant settings, order/payment/token/subscription metadata and cron state;
4. deactivates the legacy package;
5. installs/activates canonical `supcheckout/UPayments.php`;
6. verifies settings/data/provider IDs/callback/cron continuity;
7. proves rollback to the legacy package remains non-destructive;
8. returns to canonical SUPCheckout;
9. removes the inactive legacy package;
10. re-verifies canonical runtime and retained data.

This is the certified path for internal/pre-release installations that used the old root.

## Protected compatibility contracts

Release rebranding must preserve, unless a future separately proven migration explicitly supersedes them:

- gateway/payment method ID `upayments`;
- settings option `woocommerce_upayments_settings`;
- callback route `wc_upayments`;
- Blocks / Store API identity `upayments`;
- historical `_upay_*` metadata;
- provider-order/token provenance identities;
- `upayments_token_identity_secret_v2` and related provenance/scope/generation state;
- cron hook `upay_process_subscriptions`;
- billing-attempt state/table;
- historical order payment-method values.

These are provider/data compatibility contracts, not first-party branding residue.

## WordPress.org submission gate

`.github/workflows/wordpress-org-submission-check.yml` is a permanent pre-submission gate. It:

- checks out the exact candidate head;
- runs the permanent submission harness;
- builds the deterministic canonical ZIP;
- verifies the ZIP before inspection;
- unpacks `supcheckout/`;
- runs the pinned official `WordPress/plugin-check-action` with slug `supcheckout` and `plugin_repo` checks;
- runs with `strict: true` and fails on any reported warning/error.

No blanket Plugin Check ignore list is allowed.

A green submission check is necessary engineering evidence but does not publish anything and does not guarantee WordPress.org manual-review approval.

## Version-promotion contract

The repository remains at development version `0.1.0` until the owner explicitly chooses the first public version.

If the version changes, use a dedicated PR and update all canonical version surfaces together, including at minimum:

- `src/Release/Identity.php`;
- plugin header in `UPayments.php`;
- `readme.txt` stable tag/changelog;
- root `CHANGELOG.md`;
- README version badge/text;
- release documentation tied to the version.

The exact version-promotion head must pass the complete release-sensitive stack. Merged `main` must then be reverified before tagging.

## Public release contract

Only after explicit owner approval:

1. fetch exact certified `main`;
2. build deterministic ZIP from that commit;
3. verify ZIP with `scripts/verify-release.sh`;
4. verify ZIP SHA-256 against sidecar;
5. create `vX.Y.Z` on that exact commit;
6. create GitHub Release with verified ZIP/checksum/manifest;
7. submit/publish that exact package to WordPress.org under slug `supcheckout`;
8. verify the public directory metadata/package/version;
9. perform post-publication install/upgrade smoke from the real public channel.

Do not publish an artifact from:

- a synthetic PR merge ref;
- a mutable local worktree;
- an unreviewed commit;
- a different SHA than the tag;
- a candidate with mandatory checks still pending/failed/skipped.

## Repository-coordinate boundary

The canonical GitHub repository is `SimplixInnovations/supcheckout`, matching the canonical plugin slug.

Living repository URLs use that coordinate. Historical evidence and pre-stable migration fixtures retain older package/repository tokens only where semantically required to preserve true past state.

## Historical evidence

Historical Task 5 established deterministic Git-HEAD-bound packaging. Historical Task 7 established same-basename continuity and the negative proof that a physical bootstrap rename is unsafe. Historical Task 8 closed the pre-rebrand enterprise release-candidate program.

These records remain historical truth. They are not rewritten to claim that old `simplixpay-upayments` identities were already SUCheckout.

## Release evidence boundary

CI artifacts are verification artifacts, not public releases.

The latest runtime-bearing certified baseline is `82d1fdaee91ee6bde6c26dfcc7ceb974d0d59847` (PR #97). Fresh merged-main evidence is **41/41 check-runs SUCCESS**, including H12 **1936/0 PHP + 150/0 Blocks**, **20/20** compatibility plus Compatibility Gate, Release Artifact **69/0** plus Release Gate, Provider Sandbox, WordPress.org readiness **31/0** plus official packaged Plugin Check and CodeQL. The canonical package is **51 files**, SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

The owner-accepted Approach 2 baseline is the **frozen regression reference coordinate for all Approach 3 work**:

- Accepted baseline SHA: `0c883d609906676966002eb022a82a9656eeacc5`
- Accepted package: `supcheckout-0.1.0.zip` (51 files, SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`).

Owner technical acceptance does not authorize publication. Public tag, GitHub Release and WordPress.org submission require a separate explicit owner authorization.

Repository-only documentation/presentation commits are excluded from the installable package and therefore should not change canonical ZIP bytes. Any distributable-file change may change the hash. For one exact distributable tree, Linux CI and Windows owner builds must reproduce the same ZIP SHA-256; a differing sidecar is a release blocker.

Live repository topology, open PR/issue state and exact-head checks must be verified at the time of a release decision; historical `main`-only snapshots are not substitutes for current evidence.

Remaining owner/admin actions are controlled by `OWNER-HANDOFF.md`:

- verify the owner's local canonical remote;
- perform independent local acceptance;
- apply approved launch branding/visual acceptance;
- explicitly choose version/publication;
- tag/release/submit only after exact-main verification.
