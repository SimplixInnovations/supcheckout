# SUPCheckout for UPayments — Canonical Naming, Identity, Compatibility and Namespace Standard

**Status:** CANONICAL / CURRENT
**Maintainer:** Simplix Innovations
**Product family:** SUPCheckout
**Canonical slug:** `supcheckout`

This document is authoritative for all new naming and identity decisions.

## Product hierarchy

```text
Simplix Innovations
└── SUPCheckout
    └── SUPCheckout for UPayments
```

- Human-facing formal name: **SUPCheckout for UPayments**
- Short product reference: **SUPCheckout**
- Provider/integration: **UPayments**
- Maintainer/publisher: **Simplix Innovations**

The word **for** is relationship copy only. It MUST NOT appear in URLs, repository names, WordPress.org slug, text domain, package names, namespaces, prefixes, CSS/JS roots, REST namespaces, logger sources, scheduler groups or release ZIP identifiers.

Forbidden technical form: `supcheckout-for-upayments`.

## Canonical technical identity

| Surface | Canonical value |
|---|---|
| Formal plugin name | **SUPCheckout for UPayments** |
| Product family | **SUPCheckout** |
| Provider | **UPayments** |
| Technical slug | `supcheckout` |
| Canonical GitHub repository | `SimplixInnovations/supcheckout` |
| WordPress.org slug | `supcheckout` |
| Plugin folder | `supcheckout/` |
| Physical main file | `supcheckout.php` |
| Plugin basename | `supcheckout/supcheckout.php` |
| Pre-rename main file | `UPayments.php` — `Identity::LEGACY_MAIN_FILE`; shipped only as a header-less transitional shim until Sub-project 5 |
| Text domain | `supcheckout` |
| Composer package | `simplix-innovations/supcheckout` |
| PHP namespace root | `Simplixi\SUPCheckout` |
| Global PHP prefix | `supcheckout_` |
| Constants | `SUPCHECKOUT_*` |
| CSS component root | `.supcheckout` |
| CSS custom properties | `--supcheckout-*` |
| Admin design tokens (amendment, spec §4.1, owner decision 2026-10-16) | `--sup-*` CSS custom properties, defined only under `.supcheckout-admin` (the admin app root and its portal layer) |
| Admin component classes (amendment, spec §4.1, owner decision 2026-10-16) | `sup-<component>` / `sup-<component>--<modifier>`, every rule scoped under `.supcheckout-admin` or `body.supcheckout-admin-page` |
| JS namespace | `supCheckout` |
| Localized JS config | `supCheckoutConfig` |
| REST namespace | `supcheckout/v1` |
| Action Scheduler group for new first-party jobs | `supcheckout` |
| Logger source for new first-party logging | `supcheckout` |
| Release ZIP | `supcheckout-X.Y.Z.zip` |
| Git tag form | `vX.Y.Z` |

## Provider-specific product boundary

SUPCheckout is permanently scoped to UPayments. Other providers must be implemented as separate products/repositories rather than adapters inside SUPCheckout. Cross-provider orchestration, routing and fraud-platform behavior belong to a separate platform project.

## Public positioning

Preferred first reference:

> **SUPCheckout for UPayments is an independently engineered UPayments payment gateway integration for WooCommerce by Simplix Innovations.**

UPayments is the external payment provider/service. Never imply that Simplix Innovations is the acquiring bank/payment processor, that SUPCheckout is owned by UPayments, or that UPayments officially endorses/distributes SUPCheckout unless explicit authorization exists.

## Critical compatibility rule

> **First-party rebranding must never destroy, detach or silently reinterpret persisted payment identity.**

Every inherited identifier must be classified before change as one of:

- **FIRST-PARTY RENAME** — owned branding/implementation identity that should use SUPCheckout;
- **LEGACY COMPATIBILITY** — historical merchant/store identity that remains readable/usable;
- **PROVIDER CONTRACT** — UPayments-defined request/response/schema terminology that must remain provider-accurate;
- **REMOVE** — obsolete implementation residue proven unused and safe to delete.

Never perform a blind repository-wide replacement of provider/persisted compatibility tokens or the retired pre-rebrand package-root token.

## Protected compatibility identifiers

| Identifier | Protected value | Policy |
|---|---|---|
| WooCommerce gateway/payment ID | `upayments` | preserve until a tested dual-ID migration exists |
| Settings option | `woocommerce_upayments_settings` | preserve/read; never silently discard |
| Historical order payment method | `upayments` | preserve indefinitely |
| Blocks / Store API payment identity | `upayments` | preserve until upgrade-safe migration exists |
| Callback route | `wc_upayments` | continue recognizing for existing/in-flight callbacks |
| Existing order/user/product metadata | `_upay_*` | preserve/read compatibly |
| Provider order identity | e.g. `UPayments_order_id` | preserve provider/historical semantics |
| H12 token secret | `upayments_token_identity_secret_v2` | preserve exactly |
| H12 provenance/scope/generation keys | historical forms | preserve exactly |
| Subscription cron | `upay_process_subscriptions` | preserve/recognize unless a tested migration supersedes it |
| Historical cleanup cron | `upay_hourly_cron_job` | recognize/clean only under characterized behavior |
| Billing-attempt table | `{$wpdb->prefix}upayments_billing_attempts` | preserve unless transactional migration is proven |
| Existing public hooks | `upayments_*` | audit before replacement; alias where compatibility requires it |

Provider API request/response fields, endpoint paths, provider payment-method names and schema terminology retain UPayments' exact contract.

The approved migration for these identifiers is the Sub-project 0b takeover (`src/Takeover/`, `wp supcheckout takeover`, real-WordPress matrix `.github/workflows/takeover-migration-matrix.yml`). It copies, never moves, each identifier that `Keys` maps to its `supcheckout` name, keeps every legacy name until Finalize, never rewrites historical order `payment_method`, and serves `wc_upayments` permanently. Until a store runs its cutover, the values in the table above stay in use unchanged. `AGENTS.md` "Protected compatibility identities" is the authoritative contract, including the identifiers the takeover never migrates.

## New first-party identifiers

New plugin-owned options, hooks, nonces and cache keys use `supcheckout_*`.

New metadata uses `_supcheckout_*` only where new storage is genuinely required; do not create duplicate metadata just for naming uniformity.

New script/style handles use `supcheckout-*`.
New CSS uses `.supcheckout` component scoping and `--supcheckout-*` custom properties.
Amendment (spec §4.1, owner decision 2026-10-16): the admin app's design tokens use `--sup-*` and its component classes use `sup-*`, all scoped under `.supcheckout-admin` (or `body.supcheckout-admin-page`). The source is `assets/admin/src/tokens/*.tokens.json`, rendered to `tokens.generated.css`. Checkout and every other plugin stylesheet keep `.supcheckout` and `--supcheckout-*`, and checkout never uses `--sup-*` tokens or `.supcheckout-admin`.
A JS global is allowed only when necessary and must use `supCheckout`; localized configuration uses `supCheckoutConfig`.
New REST routes use `supcheckout/v1`.

## Physical bootstrap decision

**Current decision (Sub-project 0a, owner decision 2026-10-16, pre-release):** the main file is `supcheckout.php` and the canonical SUPCheckout package uses:

```text
supcheckout/supcheckout.php
```

A header-less transitional `supcheckout/UPayments.php` shim (Task 2b) moves pre-rename installs' active entry automatically. It is removed in Sub-project 5 after the owner confirms every pre-rename install has loaded it. If the package stopped shipping `UPayments.php`, WordPress would drop a stored `supcheckout/UPayments.php` entry and silently deactivate the gateway, so the shim keeps that file name loadable: on its first load `BasenameMigration` swaps the entry in `active_plugins` (and the `active_sitewide_plugins` key on a network) to `supcheckout/supcheckout.php` in place, then loads the real main file. It has no `Plugin Name` header, so WordPress never lists a second plugin, and it only touches entries inside SUPCheckout's own directory. The release-artifact controls assert the package ships `supcheckout/supcheckout.php` with a header and the header-less shim, and the Release Artifact in-place update cells exercise the move on real WordPress.

Pre-rename installs: update by zip upload ("Replace current with uploaded"), WP-CLI, or the Plugins-screen / Updates-screen one-click and bulk updates (`bulk_upgrade()`). These keep the stored `supcheckout/UPayments.php` entry, and the shim moves it on the next request. The classic single-plugin Update-now flow (`wp-admin/update.php?action=upgrade-plugin`, `Plugin_Upgrader::upgrade()`, used without JavaScript) deactivates the plugin before installing and then reactivates it by the old basename. Validation rejects the header-less shim, so the install ends **deactivated**: no SUPCheckout code runs, merchant data is unchanged, and one activation of `supcheckout/supcheckout.php` recovers it. Any future update channel must key updates to `supcheckout/supcheckout.php`.

**Superseded decision (history):** the first-stable physical bootstrap was intentionally `UPayments.php`. Prior real WordPress qualification proved that deleting or renaming an already-active `UPayments.php` can strand WordPress's stored plugin basename, so the package used `supcheckout/UPayments.php` and treated `supcheckout.php` as a future target that needed its own migration. The owner approved the rename with the transitional shim above, which removes the activation risk.

The official UPayments plugin's own `UPayments.php` main file is a different package. `OfficialPluginConflict` still detects it by that name.

## Text domain and translations

All SUPCheckout-owned translatable strings use the literal text domain `supcheckout`.

Dynamic translation domains and inherited `upayments` or third-party domains are not acceptable for SUPCheckout-owned copy. Provider names inside translated strings remain provider names; the **text domain** remains `supcheckout`.

No blanket Plugin Check ignore list is permitted.

## PHP architecture

Canonical namespace root:

```php
Simplixi\SUPCheckout
```

Existing globals, provider identifiers and persisted compatibility identifiers migrate only where evidence permits. Namespace cleanliness never takes priority over merchant/payment compatibility.

## Repository coordinate rule

The canonical GitHub repository is:

`SimplixInnovations/supcheckout`

Repository and plugin slug now intentionally converge on `supcheckout`; protected UPayments compatibility identities remain unchanged.

Only **living repository-coordinate references** use the canonical GitHub coordinate. Historical evidence and legacy package-root migration fixtures retain older coordinates/tokens where they record true past state.

## Release engineering

The project remains on the independent `0.x` development line until an explicit release/version decision.

Canonical artifact forms:

```text
folder: supcheckout/
ZIP:    supcheckout-X.Y.Z.zip
tag:    vX.Y.Z
```

WordPress.org Plugin Check must execute against the actual unpacked deterministic release package using slug `supcheckout` and `plugin_repo` checks, without blanket error suppression.

## Identity governance

Changing any of the following requires explicit owner approval plus regression evidence appropriate to the risk:

- formal product name;
- technical slug;
- repository identity;
- WordPress.org slug;
- plugin folder or bootstrap filename;
- text domain;
- PHP namespace root;
- Composer package;
- public/global prefixes;
- REST/JS/CSS identity;
- protected compatibility allowlist.

Unsafe destructive compatibility rename verdict:

`NOT APPROVED.`
`DO NOT MERGE.`

**Naming architecture:** FROZEN / CURRENT
**Main file:** `supcheckout.php` (renamed from `UPayments.php` in Sub-project 0a)
**Legacy compatibility identifiers:** PROTECTED
**Formal trademark/legal clearance:** separate business/legal gate
