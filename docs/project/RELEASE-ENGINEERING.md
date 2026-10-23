# SUPCheckout for UPayments — Release Engineering

## Current state (authoritative)

```text
Release Candidate 1: CURRENT OWNER-ACCEPTED TECHNICAL BASELINE (8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243)
Approach 3: HISTORICAL / SUPERSEDED (146d65a1c182630c1acc651cacafe30cff5f6b79)
Approach 2: HISTORICAL / SUPERSEDED
Active program: external-certification-release-readiness
External-readiness integrated baseline: 705862bd2138126b53ea428551b326a0fd8961bc. Live `main` is dynamic — obtain it from GitHub/session bootstrap; it is not frozen into this document.
Accepted package: supcheckout-0.1.0.zip / 62 files / 8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156
Publication: NOT AUTHORIZED
```

Sections below labeled Historical are retained audit evidence and are not current state.


**Current status:** owner technical acceptance COMPLETED for Release Candidate 1 (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1`, 2026-10-07) at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243`; package 62 files / SHA-256 `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`; superseded Approach 3 acceptance (`OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`) at `146d65a1c182630c1acc651cacafe30cff5f6b79` / `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` is historical; explicit release administration (tag/GitHub Release/WordPress.org publication) remains NOT AUTHORIZED; automatic recurring `VERIFIED_SUCCESS` remains FAIL-CLOSED pending provider contracts
**Canonical GitHub repository:** `SimplixInnovations/supcheckout`
**Canonical plugin/package slug:** `supcheckout`
**Development version:** `0.1.0`
**Accepted Release Candidate 1 baseline:** `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243`
**Accepted package:** `supcheckout-0.1.0.zip` — **62 files**, SHA-256 `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`
**Historical Approach 3 (superseded):** `146d65a1c182630c1acc651cacafe30cff5f6b79` / 62 files / `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`
**Historical Approach 2 (superseded):** `0c883d609906676966002eb022a82a9656eeacc5` / 51 files / `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`
**Revamp development package (NOT owner accepted):** Sub-project 1 design system: **224 files**, SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4`, built with `scripts/build-release.sh` twice (byte-identical) and verified with `scripts/verify-release.sh` at the Sub-project 1 close. The last commit that changes distributable bytes is the #211 merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5` (the earlier 224-file builds of Task 8 and #209 are superseded). Sub-project 1 adds 90 files to the 134 of 0c: the 9 committed build files under `assets/admin/build/`, the 80 source files under `assets/admin/src/` and `src/Admin/AdminAssets.php`. See "Admin UI build contract (Sub-project 1)".
Historical Sub-project 0c package (superseded by Sub-project 1): **134 files** (114 before 0c), SHA-256 `914cca32cfc2ea4b60ba1fc32e61205d12e0d443e2ab55b08167436033600bba`, built with `scripts/build-release.sh` (twice, byte-identical) and verified with `scripts/verify-release.sh` at `8f7b2cf394e596a0bd937052df6ed5c683d33a56`, the PR #194 review fix and the last commit that changes distributable bytes (a switch always ends with its self-test; only a started operation keeps its rate slot). 0c adds 20 source files: `src/Rest/` (5), `src/Takeover/CachePurger.php`, `src/Takeover/BudgetedSystem.php` and 13 under `src/Takeover/Onboarding/`. The plan estimated 131; the extracted helpers `Checks.php`, `Lines.php` and `DriverHints.php` (final review: keeps `DriverStep.php` under the 800-line ceiling) make it 134. The earlier builds are superseded: 134 files `55ff2a95d984f5327c5a4f4a4deabbe9601cf5feb67541b8d853b00f06f24be1` (at `ab866d00f0bf5790ccd3ecd30583339c189f08f1`, before the PR #194 review fix) and 133 files `eb341df39a87a42eecc964c33f22c3f2e6843fc6a9e4adfb3246d13d1cd75d7c`. PR #194 merged as `5dce3f1d1397d455baa4b4bfe64ef3f89184de6e` after the Release Artifact job succeeded at its head `b48badf1f1c81284546ac4c3be62ca211201676f` (run 39205662707), and again on `main` (run 39209893592).

## Admin UI build contract (Sub-project 1)

The React admin app (`assets/admin/`) is built with npm, but the release builder never runs npm. `scripts/build-release.sh` and `scripts/verify-release.sh` are unchanged: they still package and verify Git HEAD blobs only, so a release never depends on the npm registry, the Node version or the operating system at release time.

- **Committed build.** The build output is committed under `assets/admin/build/` (`app.js`, `app.css`, `app-rtl.css`, `app.asset.json`, `app.chunks.json`, `chunks/*.js`, `THIRD-PARTY-NOTICES.txt`, `third-party-notices.json`). Each PR that changes the admin source rebuilds and commits the build in the same change; a later PR rebases and rebuilds, never hand-merges build files.
- **Drift gate.** `assets/admin/build` is generated, committed and drift-checked on Linux and Windows. `npm run check:drift` (`git diff --exit-code -- assets/admin` and an empty `git status --porcelain -- assets/admin`) runs after `npm run build` in the Admin UI Certification `static` lane, again after deleting the build and the Babel cache (byte-identical second build), and in the `build-windows` lane. A stale or hand-edited build cannot merge. LF line endings are enforced by `.gitattributes` and by the `build-windows` scan of `git ls-files --eol -- assets/admin/build`, which fails on any `crlf` or `mixed` working-tree file.
- **Toolchain pins.** Node 24.21.0 (`.nvmrc`) and npm 11.19.0 (`engines`, `packageManager`, `engine-strict=true`); `@wordpress/scripts` 36.0.0; every dependency pinned exactly (`save-exact=true`) and at least 14 days old (`min-release-age=14`); newer pins arrive only through Dependabot. CI installs with `npm ci --ignore-scripts`. React, `react-dom`, the JSX runtime and `@wordpress/*` are runtime externals from WordPress; only MIT/ISC/BSD/0BSD/CC0 code (today `@base-ui/react`, `@base-ui/utils`, `@floating-ui/utils`, `lucide-react` and `use-sync-external-store`) is bundled, listed in `THIRD-PARTY-NOTICES.txt` and in the runtime SBOM.
- **Source shipping.** The human-readable source `assets/admin/src/**` (TypeScript, CSS and token JSON) ships in the package (WordPress.org guideline 4), and `readme.txt` "Source code and build" names the repository and the build command. Shipping TypeScript source is acknowledged by the owner 2026-10-22. The build has no source maps and no `sourceMappingURL`; minification is not obfuscation, and translator functions and comments survive, so strings stay extractable.
- **Never shipped.** The toolchain and its configs (`package.json`, `package-lock.json`, `.nvmrc`, `.npmrc`, every build/lint/type/test config), `tools/`, `tests/` (the gallery, the visual baselines), `node_modules/` (including the `@fontsource` test fonts), and any `*.map`. The verifier rejects them, and a Release Artifact harness check asserts the package ships no web font.
- **Dependency audit.** `npm audit --omit=dev` must report nothing at any level (bundled tree). `npm run audit:full` blocks every advisory rated low or above in the full tree unless `tools/admin-ui/audit/audit-allowlist.json` lists it by GHSA id and package with an unexpired date, the advisory is still reported (a stale entry fails) and it does not reach the bundled tree. Allow-listed today, development-only: braces GHSA-vfj7-8cjw-p6xm (no patched release; expires 2027-01-17). The two markdown-it entries were removed when #208 adopted markdown-it 14.3.3; the sprintf-js GHSA-hp3w-g68c-fv3c entry was removed in hardening batch 2 (#222) after GitHub withdrew the advisory on 2026-10-22 and the gate reported it stale. `npm run audit:lock-age` (#209) enforces the 14-day floor on the lockfile itself. The allow-list is acknowledged by the owner 2026-10-22.

### Dependency hygiene

- **The 14-day cooldown.** `.npmrc` `min-release-age=14` and Dependabot `cooldown.default-days: 14` keep every new version at least 14 days old. The limit: npm applies the cooldown only when it resolves versions, and Dependabot security updates ignore it. Since #209 the lock-age check (`npm run audit:lock-age`, in the Admin UI Certification `static` lane) closes that gap: every `name@version` a change adds to `package-lock.json` must be at least 14 days old unless `tools/admin-ui/audit/lock-age-exceptions.json` lists that exact pair with a reason and an expiry at most 90 days away (the list is empty today). A security fix taken earlier is a recorded, owner-approved exception: #208 took markdown-it 14.3.3 on 2026-10-21, five days after its release, before the lock-age check existed.
- **Ignored majors.** Dependabot ignores React, `react-dom` and their types at major versions, because WordPress provides React 18.3.1 at runtime and the development copy must match it; React 19 is covered by `npm run test:unit:react19`. TypeScript minors and majors are ignored, because the `typescript-eslint` inside `@wordpress/scripts` supports TypeScript below 6.1.0 only; TypeScript moves with the `@wordpress/scripts` toolchain. `@types/node` 25 and later are ignored, because the types follow the pinned Node 24 runtime. The `@wordpress/element` 9 major (Dependabot #210) is to be held the same way until a deliberate React 19 decision.
- **Bundle-changing updates.** A Dependabot PR that changes a bundled package (`admin-ui-runtime` group) or the build toolchain changes `assets/admin/build`, so the drift gate fails until a maintainer rebuilds and commits the build on that branch. If it changes pixels, the visual baselines are regenerated and reviewed too (`CONTRIBUTING.md`).
- **Overrides** (`package.json` `overrides`; all development-only unless stated):

| Override | Reason | Exit condition |
|---|---|---|
| `serialize-javascript` → 7.1.2 | GHSA-5c6j-r48x-rmvq in the version `copy-webpack-plugin` (inside `@wordpress/scripts`) pulls in | Remove when `@wordpress/scripts` depends on a fixed release |
| `postcss-selector-parser` → 7.1.6 | GHSA-rj75-hqrm-r3gf (moderate, quadratic selector parsing) against the 6.x copies in the cssnano/stylelint stacks inside `@wordpress/scripts`; the committed build was proven byte-identical with the override (#200) | Remove when every dependent resolves 7.1.6 or later on its own |
| `markdownlint-cli` → `js-yaml` 5.4.1, `smol-toml` 1.9.0 | GHSA-r3ph-w7gj-g6xm (`js-yaml` ≤ 5.4.0, moderate) and GHSA-r4xh-jqrq-34v2 (`smol-toml` ≤ 1.8.0, moderate) in the versions `markdownlint-cli` (inside `@wordpress/scripts`) resolves (#200) | Remove when `markdownlint-cli` ships with fixed versions |
| `micromark-extension-math` → `katex` 0.18.2 | GHSA-238p-pmpm-9mq7 (low) against the transitive `katex` 0.16.x (#200) | Remove when the math extension resolves 0.18.2 or later |
| `sockjs` → `uuid` 11.1.1 | GHSA-w5hq-g745-h8pq (moderate) against the `uuid` 8.x used by `sockjs` (webpack-dev-server) (#200) | Remove when `sockjs` depends on a fixed `uuid` |
| `react-dom-19` → `react`: `$react` | Satisfies the React 19 alias's `react` peer with the root pin, so `npm ci` has no peer conflict; the React 19 test lane maps `react` and `react-dom` to the aliases itself | Remove when the runtime and the development copy move to React 19 and the alias lane is retired |

- **Audit allow-list rules** (`tools/admin-ui/audit/audit-allowlist.json`, enforced by `audit-policy.mjs`): each entry names a GHSA id, package, severity, reason and an expiry at most 90 days away; an entry the audit no longer reports fails (stale), and an entry whose advisory reaches the production tree or whose package is bundled is refused. `npm audit --omit=dev` stays unconditional.
- **Current entries** (all development-only, none bundled; the two markdown-it entries were removed by #208, and the sprintf-js GHSA-hp3w-g68c-fv3c entry by #222 after the advisory was withdrawn upstream):

| Advisory | Package | Severity | Expires | Planned action |
|---|---|---|---|---|
| GHSA-vfj7-8cjw-p6xm | braces | high | 2027-01-17 | No patched release exists; adopt a fix when one is published, otherwise review the entry before it expires |

### Reverting a Sub-project 1 change

1. `git revert` the change on a branch.
2. Run `npm ci --ignore-scripts && npm run build`, then commit the rebuilt `assets/admin/build/`: a reverted source with the old build fails the drift gate.
3. If pixels change, dispatch `admin-ui-certification.yml` with `update_visual_baselines: true`, review and commit the baselines, and rerun until the gate is green.

Time-dependent gates can fail a pure revert, or a re-certification of an old SHA, with no code change: an allow-list entry that has expired, the live advisory database reporting a new advisory (or no longer reporting an allow-listed one), and the Plugin Check job's `npm_config_before` cooldown resolving a different wp-env. Fix the gate input (the allow-list, the lockfile) in the revert PR; never loosen the gate.

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
- physical main file: `supcheckout.php`;
- plugin basename: `supcheckout/supcheckout.php`;
- text domain: `supcheckout`;
- namespace: `Simplixi\SUPCheckout`;
- current development artifact: `supcheckout-0.1.0.zip`.

The main file is `supcheckout.php` since Sub-project 0a (owner decision 2026-10-16, pre-release). It was `UPayments.php` before; real-install qualification had shown that renaming an already-active main file strands WordPress's persisted plugin basename. A header-less transitional `supcheckout/UPayments.php` shim (Task 2b) moves pre-rename installs' active entry automatically. It is removed in Sub-project 5 after the owner confirms every pre-rename install has loaded it. The verifier requires `supcheckout.php` with its plugin header, the header-less `UPayments.php` shim and `src/Bootstrap/BasenameMigration.php` in the package, and `Identity::MAIN_FILE = 'supcheckout.php'`. The Release Artifact `in-place-main-file-update` cells install the pre-rename build active as `supcheckout/UPayments.php` on WP 7.1.3 / WC 11.1.2 and WP 6.9.10 / WC 10.8.1. Each cell then runs `tests/integration/in-place-update-paths.sh` through these paths, resetting to the pre-rename build between them:

- a WP-CLI overwrite;
- an authenticated `wp-admin/plugins.php` GET as the first request after the overwrite;
- the upload installer (`Plugin_Upgrader::install(..., overwrite_package)`);
- the bulk / one-click upgrader (`wp plugin update`);
- a characterization of the classic single-plugin `Plugin_Upgrader::upgrade()`;
- a network-activated multisite variant.

Each migrating path asserts that `supcheckout/supcheckout.php` is active once, that no `supcheckout/UPayments.php` entry remains, and that merchant state and the subscription schedule are unchanged.

Pre-rename installs: update by zip upload ("Replace current with uploaded"), WP-CLI, or the Plugins-screen / Updates-screen one-click and bulk updates (`bulk_upgrade()`). These keep the stored `supcheckout/UPayments.php` entry, and the shim moves it on the next request. The classic single-plugin Update-now flow (`wp-admin/update.php?action=upgrade-plugin`, `Plugin_Upgrader::upgrade()`, used without JavaScript) deactivates the plugin before installing and then reactivates it by the old basename. Validation rejects the header-less shim, so the install ends **deactivated**: no SUPCheckout code runs, merchant data is unchanged, and one activation of `supcheckout/supcheckout.php` recovers it. Any future update channel must key updates to `supcheckout/supcheckout.php`.

## Bundled translations (hardening A4-01)

The package ships `languages/` (header `Domain Path: /languages`):

- `supcheckout.pot`;
- the Arabic `supcheckout-ar.po` and `supcheckout-ar.mo`;
- one `supcheckout-ar-<md5 of the script path>.json` per admin script with strings.

`Bootstrap\Translations` points WordPress at `languages/` through `lang_dir_for_domain` only when no language pack exists for the locale. The verifier accepts only flat `supcheckout*` files ending in `.pot`, `.po`, `.mo`, `.json` or `.l10n.php` there.

`tests/unit/Bootstrap/TranslationFilesConsistencyTest.php` fails when the files drift from each other or from the source. To regenerate them with WP-CLI 2.12.0 from the repository root (`$TMP` is any scratch directory outside the repository):

```bash
EXCLUDE=tests,vendor,docs,scripts,tools,node_modules,assets/admin/src,.cache,.github,languages
# 1. Template from the packaged PHP and the committed admin build.
wp i18n make-pot . languages/supcheckout.pot --slug=supcheckout --domain=supcheckout \
  --exclude="$EXCLUDE" --skip-audit \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/SimplixInnovations/supcheckout/issues"}'
# 2. Merge the template into the Arabic catalogue, then translate the new or changed entries.
wp i18n update-po languages/supcheckout.pot languages/supcheckout-ar.po
# 3. Admin script JSON. The shipped .po must keep its script strings, so never purge it.
#    Current WP-CLI purges by default and needs --no-purge; WP-CLI 2.12.0 never purges and rejects the flag.
wp i18n make-json languages/supcheckout-ar.po languages/ --no-purge
# 4. The .mo holds only the entries used from PHP: compile it from a copy merged with a PHP-only template.
wp i18n make-pot . "$TMP/supcheckout-php.pot" --slug=supcheckout --domain=supcheckout \
  --exclude="$EXCLUDE,assets/admin/build" --skip-audit
cp languages/supcheckout-ar.po "$TMP/supcheckout-ar.po"
wp i18n update-po "$TMP/supcheckout-php.pot" "$TMP/supcheckout-ar.po"
wp i18n make-mo "$TMP/supcheckout-ar.po" languages/supcheckout-ar.mo
```

A rebuild of the admin app changes the chunk file names, and so the JSON names. Rerun step 3 and delete the stale JSON files. The files are committed, so the release build stays byte-for-byte deterministic.

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
5. installs/activates canonical `supcheckout/supcheckout.php`;
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
- plugin header in `supcheckout.php`;
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

## Historical release evidence boundary — pre-Approach 3 acceptance

CI artifacts are verification artifacts, not public releases.

At that checkpoint, the latest runtime-bearing certified baseline was `82d1fdaee91ee6bde6c26dfcc7ceb974d0d59847` (PR #97). Fresh merged-main evidence is **41/41 check-runs SUCCESS**, including H12 **1936/0 PHP + 150/0 Blocks**, **20/20** compatibility plus Compatibility Gate, Release Artifact **69/0** plus Release Gate, Provider Sandbox, WordPress.org readiness **31/0** plus official packaged Plugin Check and CodeQL. At that checkpoint the canonical package was **51 files**, SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

The owner-accepted baseline at that time was Approach 2 (now HISTORICAL / SUPERSEDED). It was the frozen regression reference coordinate for Approach 3 work:

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
