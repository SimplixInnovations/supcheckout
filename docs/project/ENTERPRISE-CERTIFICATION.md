# SUPCheckout for UPayments — Historical Enterprise Foundation & Re-certification Context

**Historical status:** TASKS 1–8 DONE / VERIFIED under the former pre-rebrand product identity
**Current authoritative state:** see `PROJECT-STATUS.md`; this document preserves historical certification and re-certification evidence
**Canonical GitHub repository:** `SimplixInnovations/supcheckout`
**Canonical plugin/package slug:** `supcheckout`

This record preserves the reproducible enterprise certification foundation established before the approved SUCheckout rebrand. Historical package names, basenames, text domains, SHAs and run numbers below remain evidence of what was certified then; they are not current SUPCheckout identity declarations. Current state is owned by `PROJECT-STATUS.md`.

## SUCheckout identity-migration certification — DONE / VERIFIED

The historical Tasks 1–8 foundation was independently requalified after the full SUCheckout identity migration.

PR #58:

- final certified head: `5bf84dccb880733da45c1f922d43554af69a33dc`;
- Quality #763/H12 — **SUCCESS**;
- Compatibility #291 — **16/16 SUCCESS**;
- Release Artifact #242 — **SUCCESS**;
- Provider Sandbox #206 — **SUCCESS**;
- WordPress.org Submission Check #100 — **SUCCESS**;
- CodeQL — **SUCCESS**;
- official packaged Plugin Check — **0 blocking errors**.

Squash merge:

- canonical runtime-bearing main merge: `6aabc4fcb0606567a11637ea07fe081fed4c7f85`;
- post-merge Quality #764 — **SUCCESS**;
- Compatibility #292 — **16/16 SUCCESS**;
- Release Artifact #243 — **SUCCESS**;
- Provider Sandbox #207 — **SUCCESS**;
- WordPress.org Submission Check #101 — **SUCCESS**;
- CodeQL/main-security #579 — **SUCCESS**.

Review-specific permanent closures include the WordPress HTTP production transport contract (**27/27**), fail-closed provenance DB error contract (**3/3**) and retired-identity residue contract (**17/17**).

This closes the original identity-migration certification only. Repository rename, public version/tag/GitHub Release and WordPress.org publication remain separate owner actions.

### Final first-party naming reconciliation

PR #59 later reconciled living owner/control documentation as `9591c431e1eb56fe40ca60147afdf9f3f909a212` without changing runtime behavior. PR #61 then removed the remaining retired pre-rebrand first-party constants/certification-control prefixes while preserving protected UPayments compatibility identities.

Runtime-bearing certified `main` at this historical naming-reconciliation milestone: `efe937c67343242b7ccf3396a67b3cf2ce35ebac`.

Fresh post-merge evidence on that exact SHA:

- Quality #781 — **SUCCESS**;
- Compatibility #309 — **16/16 SUCCESS**;
- Release Artifact #258 — **SUCCESS**;
- Provider Sandbox #221 — **SUCCESS**;
- WordPress.org Submission Check #116 — **SUCCESS**;
- CodeQL/main-security #595 — **SUCCESS**;
- official packaged Plugin Check — **0 blocking errors**.

## Final SUPCheckout identity re-certification — DONE / VERIFIED

PR #67 certified head `0059f365883fa4edd6a2d623c7b370d38d3f565c` passed the complete release-sensitive stack and squash-merged as `7547e59a2d5ef6d49b059851c6899a2d9987b16a`.

Fresh exact-main evidence:

- Quality #896 — **SUCCESS**;
- Compatibility #424 — **16/16 SUCCESS**;
- Release Artifact #373 — **SUCCESS**;
- Provider Sandbox #334 — **SUCCESS**;
- WordPress.org Submission Check #231 — **SUCCESS**;
- CodeQL/main-security #717 — **SUCCESS**;
- official packaged Plugin Check — **0 blocking errors**.

The canonical repository was then renamed to `SimplixInnovations/supcheckout`. No public tag, GitHub Release or WordPress.org publication is created by that rename.

## Certified platform foundation

PR #47 established the permanent real WordPress/WooCommerce/MySQL certification matrix. PR #48 derived public support metadata and Woo feature declarations only from that executable evidence.

Historical matrix established at that certification milestone: **16/16** WordPress/WooCommerce/PHP × legacy/HPOS cells. The current expanded matrix is owned by `docs/COMPATIBILITY.md`:

| WordPress | WooCommerce | PHP | Legacy | HPOS |
|---|---|---:|---|---|
| 7.1 | 11.1.0 | 8.4 | **Verified** | **Verified** |
| 7.1 | 11.1.0 | 8.3 | **Verified** | **Verified** |
| 7.0.4 | 11.1.0 | 8.3 | **Verified** | **Verified** |
| 7.0.4 | 11.0.1 | 8.3 | **Verified** | **Verified** |
| 7.0.4 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 7.1 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 6.9.7 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 6.9.7 | 10.8.1 | 7.4 | **Verified** | **Verified** |

Every cell installs real WordPress/WooCommerce and verifies:

- activation with malformed pre-existing gateway settings without mutating the protected serialized option;
- Classic gateway ID `upayments` registration;
- standard Cart/Checkout Blocks registration and exact availability semantics;
- declared public support metadata;
- Woo feature registry compatibility for `cart_checkout_blocks` and `custom_order_tables`;
- real Woo order CRUD through the requested authoritative storage with protected historical plugin/UPayments metadata.

Public declarations authorized by this matrix:

- WordPress minimum 6.9 / tested 7.1;
- WooCommerce minimum 10.8 / tested 11.1;
- PHP minimum 7.4;
- Cart/Checkout Blocks compatible;
- HPOS `custom_order_tables` compatible.

PHP 7.4 is an EOL compatibility floor, not the recommended modern runtime.

## Bounded provider public-sandbox certification — VERIFIED

PR #49 added `tests/provider/sandbox-charge-smoke.php` and its permanent workflow. It uses only UPayments' documented public non-whitelabel test bearer token and performs exactly one bounded sandbox Charge initialization.

Verified boundary:

- endpoint derived through Simplix `EndpointResolver(true)`;
- HTTPS `sandboxapi.upayments.com/api/v1/charge`;
- TLS verification and redirects disabled in transport;
- request JSON created successfully;
- exact HTTP 201;
- valid response JSON with strict `status === true` and structured data;
- returned payment link accepted by the production redirect normalizer and remains HTTPS on the bounded UPayments sandbox host.

The workflow never follows the payment link, enters card data, captures/completes payment, polls status, refunds, saves/retrieves a card, auto-deducts or uses a merchant production credential. It certifies **transport and Charge-initialization schema only**.

## Feature and operations certification — DONE / VERIFIED

Task 6 / PR #51 final head `355a871636f2df00c0bd7357a810289be284b58c` passed the complete 16-cell matrix plus Quality/H12, packaged release smoke and CodeQL, then merged as `6c19dbcfab607f81c4ff28f7bd088a87575adbf3`.

Permanent real-runtime evidence covers:

### Saved cards / token identity

- guest establishment rejected before provider transport or identity initialization;
- authenticated canonical token identity established in real WordPress user storage through a bounded deterministic provider fixture;
- exact customer-token binding for saved-card retrieval;
- exact selected-card membership succeeds;
- foreign card fails;
- malformed provenance fails closed before retrieval.

### Subscriptions

- real Woo subscription products/orders;
- exact `_upay_disable_subscription=yes` opt-out rejects before provider transport;
- mixed subscription/normal order rejects before provider transport;
- guest subscription rejects before provider transport;
- strict plan/interval contract;
- eligible Classic subscription advances only to the bounded token-initialization seam before the fixture blocks external mutation.

The challenge run discovered a real production defect: `CustomerTokenIdentity::inspect_bootstrap_history()` could query an out-of-range page after already scanning the exact reported total; WooCommerce could then reset pagination metadata and cause a false `total_changed` / `legacy_migration_required` blocker. Fix `d7176d7d028978d41de5c3c0516d7347bb14faec` terminates once the exact total is scanned and remains protected by the real-runtime test.

### One additional merchant

- exactly one `extraMerchantData` allocation;
- allocation amount equals exact order amount;
- IBAN and charge-type contract preserved;
- malformed configuration rejects before Charge.

No arbitrary marketplace split-routing claim is made.

### Operations / data retention

- merchant settings survive activation/deactivation/reactivation/uninstall-hook execution;
- historical payment/token metadata survives;
- canonical token identity secret and user provenance survive byte-for-byte;
- token/scope/generation binding survives lifecycle transitions;
- migration CLI/admin modules boot only in intended contexts;
- boot/runtime evidence exposes no merchant credential material;
- uninstall remains non-destructive by default.

## Deterministic artifact certification — DONE / VERIFIED (historical package identity)

Task 5 / PR #50 established the then-canonical pre-rebrand artifact contract:

- historical `simplixpay-upayments-0.1.0.zip`;
- exact distribution set and file bytes from Git `HEAD` tree/blobs;
- deterministic paths/timestamps/modes/compression within the defined toolchain;
- ZIP SHA-256 sidecar and sorted per-file manifest;
- independent PHP `ZipArchive` inspection;
- explicit release-path allowlist;
- exact ZIP-path equality with the HEAD distribution set;
- byte-for-byte source binding;
- self-consistent tampered ZIP rejection;
- dirty worktree/staged-index isolation;
- packaged real WordPress/WooCommerce legacy+HPOS runtime smoke.

Task 5 merged as `54b1fbcc280b92372bd93baf929d6a746cfd3959` after exact-head green evidence and passed post-merge verification.

## Existing-install / release-identity certification — DONE / VERIFIED

Task 7 / PR #52 final head `dd550eb6af86262aabfd50479407903172327726` ran current and floor upgrade cells. It verified:

- same-basename active force-upgrade;
- exact merchant-settings retention;
- historical gateway/provider/customer-token/subscription metadata retention;
- `upay_process_subscriptions` schedule continuity;
- `wc_upayments` callback continuity;
- deactivate/reactivate;
- rollback to the prior candidate and return to current;
- duplicate-root package characterization.

A controlled hypothetical package that changed only `UPayments.php` to `simplixpay-upayments.php` failed the safe active-upgrade contract in both cells: `active_plugins` retained the old basename, the target basename was inactive, and the runtime did not load.

Historical Task 7 decision at that time:

- main file `UPayments.php`;
- historical basename `simplixpay-upayments/UPayments.php`;
- historical text domain `upayments`.

The approved SUCheckout migration later superseded the package/text identity while retaining the physical `UPayments.php` compatibility decision. Current canonical package basename is `supcheckout/UPayments.php` and current text domain is `supcheckout`; the legacy root remains only for migration/rollback evidence.

Sub-project 0a later superseded the physical-file decision (owner decision 2026-10-16, pre-release): the canonical package basename is `supcheckout/supcheckout.php`. The record above is retained as history.

Task 7 merged as `02b8d1c2851faabe020f23bbe84ebcca43a4827d`. Post-merge `main` passed Quality #545, Compatibility #73, Release Artifact #27 and CodeQL #349.

## External/manual evidence classification

The following are deliberately **not** converted into broad repository claims:

| Evidence | Classification |
|---|---|
| Production merchant payment completion | External/manual merchant-account evidence |
| Apple Pay / Google Pay / Samsung Pay completion | External account/device/provider evidence |
| WPML/WCML/String Translation | Dedicated commercial-plugin runtime evidence |
| Multicurrency | Dedicated WCML/store/provider economics evidence |
| Arabic/RTL | Real UI/runtime validation |
| Browser/device/theme interoperability | Real browser/device matrix |
| Accessibility | Keyboard/focus/screen-reader/contrast/error-state audit |
| Performance/stability | Representative store/load evidence with explicit thresholds |
| Penetration testing / PCI / legal compliance | External organizational evidence |
| Provider webhook signature | Deferred until a stable published signature contract exists |
| Live subscription auto-deduction | External/non-idempotent mutation evidence |

Automatic Woo refunds remain **unsupported** pending durable idempotency/reconciliation design. Arbitrary marketplace multi-split remains **unsupported**; only one additional merchant is certified.

## Task 8 — Enterprise Release Candidate Closeout — DONE / VERIFIED

PR #54 final reviewed head `5a24944617f7ee482c381e5e899f687b77d81d09` passed the complete Quality/H12, 16-cell Compatibility, deterministic Release Artifact/upgrade, bounded Provider Sandbox, locked dependency audit and CodeQL evidence set.

The reserved final whole-plugin Codex challenge identified one valid P2 in stale-current Governance coverage. Q4 and Q15-Q19 guards were restored, and the Q19 permanent harness now asserts the complete closed Q1-Q19 guard set.

PR #54 squash-merged as `2ddb1790fead37c6055256847dc7c827e165af4a`. Post-merge canonical `main` passed Quality #553, Compatibility #81, Release Artifact #35, Provider Sandbox #13 and CodeQL/main-security #358.

The historical enterprise engineering program is therefore **DONE / VERIFIED through Task 8**. The earlier pre-stable SUCheckout identity migration remains independently DONE / VERIFIED evidence; the final SUPCheckout migration is certified separately. Every future candidate must still satisfy the permanent exact-head certification gates appropriate to its scope before merge or release. External/manual classifications and unsupported features remain honest boundaries, not fabricated repository certifications.

## Post-program strict WordPress.org package certification

The later SUPCheckout release-engineering hardening is independently certified without rewriting the historical Task 1-8 record.

PR #71 exact head `a820b7feff765aa99e2321c6cc930baa57793fac` passed Quality #926, Compatibility #454 (**16/16**), Release Artifact #403, Provider Sandbox #364, strict WordPress.org #261 and CodeQL #748, then squash-merged as `bfadff34142a3a676258e8dc0774bd31287c0138`.

Fresh post-merge evidence on that exact `main`:

- Quality #927 — **SUCCESS**;
- Compatibility #455 — **16/16 SUCCESS**;
- Release Artifact #404 / Release Gate — **SUCCESS**;
- Provider Sandbox #365 — **SUCCESS**;
- WordPress.org Submission Check #262 — **SUCCESS** with official packaged Plugin Check configured `strict: true`;
- CodeQL/main-security #749 — **SUCCESS**;
- packaged Plugin Check result — **no reported findings**;
- deterministic `supcheckout-0.1.0.zip` — SHA-256 `0436256b16605b9b2db91aa8a7865ecec6cae7ef00fa515048d9643e50ad990a`, 74 files.

This is release-engineering evidence only. It does not constitute WordPress.org manual approval, a production merchant-payment certification, a public tag or a GitHub Release.

No public tag, GitHub Release, WordPress.org publication or repository rename is created or authorized by this engineering closeout alone.
