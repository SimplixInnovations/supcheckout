# SUPCheckout for UPayments — Provider-Specific Product and Final Pre-Stable Identity Design

**Status:** APPROVED / OWNER STRATEGY DECISION
**Approved:** 2026-09-07
**Maintainer:** Simplix Innovations
**Product:** SUPCheckout
**Formal integration name:** SUPCheckout for UPayments
**Strategic model:** provider-isolated plugin family (Option B)
**Provider scope:** UPayments only

## Decision

SUPCheckout is a permanently UPayments-specific WooCommerce payment plugin.

It is **not** the seed of a multi-provider runtime and must not accumulate PayTabs, Tap, MyFatoorah, Stripe, Tamara, Tabby, Apple Pay orchestration, or other unrelated provider adapters merely because those capabilities are adjacent to payments.

Future provider products are separate projects/repositories with their own release, compatibility, security, provider and persisted-data contracts.

The broader multi-provider/fraud/orchestration platform is a separate project and architecture track.

## Product-family strategy

The Simplix provider-plugin family follows these rules:

1. each provider plugin is independently installable, testable, releasable and removable;
2. no provider plugin requires another provider plugin at runtime;
3. provider-specific credentials, callback routes, tokens, schedules and persisted payment identities remain isolated;
4. a defect or provider API change in one plugin must not force a release of another;
5. common engineering practices may be standardized through templates, harnesses and internal development tooling, but shared runtime coupling is not introduced merely to reduce duplication;
6. code is extracted into a shared runtime library only after repeated, independently proven use across multiple products makes the abstraction safer than duplication;
7. provider plugins remain truthful wrappers/integrations, not payment processors or acquiring services;
8. future product names use a centrally maintained provider-code registry to avoid ambiguous abbreviations.

Current family anchor:

- **SUPCheckout** — UPayments-only WooCommerce payment integration.

Possible future names such as SPTCheckout or other provider-coded products are separate naming decisions and must be checked for ambiguity and legal/marketplace conflicts before use.

## Canonical technical identity

| Surface | Final target |
|---|---|
| Formal plugin name | **SUPCheckout for UPayments** |
| Short product name | **SUPCheckout** |
| External provider | **UPayments** |
| Current GitHub repository | `SimplixInnovations/sucheckout` until owner/admin rename |
| Target GitHub repository | `SimplixInnovations/supcheckout` |
| Canonical WordPress/plugin slug | `supcheckout` |
| Intended WordPress.org slug | `supcheckout` |
| Plugin folder | `supcheckout/` |
| First-stable physical main file | `UPayments.php` |
| First-stable basename | `supcheckout/UPayments.php` |
| Text domain | `supcheckout` |
| Composer package | `simplix-innovations/supcheckout` |
| PHP namespace root | `Simplixi\\SUPCheckout` |
| UPayments implementation namespace | `Simplixi\\SUPCheckout\\Provider\\UPayments` where provider-specific classes warrant a provider namespace |
| Global first-party function prefix | `supcheckout_` |
| First-party constants | `SUPCHECKOUT_*` |
| New first-party option/meta prefix | `supcheckout_*` / `_supcheckout_*` |
| CSS root | `.supcheckout` |
| CSS custom properties | `--supcheckout-*` |
| Script/style handles | `supcheckout-*` |
| JavaScript namespace | `supCheckout` |
| JavaScript config | `supCheckoutConfig` |
| HTML/data prefix | `supcheckout-*` / `data-supcheckout-*` |
| REST namespace | `supcheckout/v1` |
| Logger source | `supcheckout` |
| Action Scheduler group for new first-party jobs | `supcheckout` |
| Future WP-CLI root | `wp supcheckout` |
| Release ZIP | `supcheckout-X.Y.Z.zip` |
| Git tag form | `vX.Y.Z` |

The lowercase token `supcheckout` is the WordPress/package/text-domain/URL identifier. PHP namespaces are code identifiers and therefore use `Simplixi\\SUPCheckout`, not a lowercase bare namespace.

## Namespace architecture

The product namespace root is:

`Simplixi\\SUPCheckout`

Generic product-owned modules sit directly below the root, for example:

- `Simplixi\\SUPCheckout\\Release`
- `Simplixi\\SUPCheckout\\Payment`
- `Simplixi\\SUPCheckout\\Security`
- `Simplixi\\SUPCheckout\\Migration`
- `Simplixi\\SUPCheckout\\Subscription`
- `Simplixi\\SUPCheckout\\Admin`

UPayments-specific API/schema/endpoint classes belong under:

`Simplixi\\SUPCheckout\\Provider\\UPayments`

This separation does not make SUPCheckout multi-provider. It simply keeps first-party product code and external-provider contracts conceptually distinct.

## Protected UPayments compatibility identities

The rebrand must not destroy or reinterpret existing merchant/payment identity.

Protected unless a separately proven migration supersedes them:

- WooCommerce gateway/payment ID `upayments`;
- settings option `woocommerce_upayments_settings`;
- Blocks / Store API payment identity `upayments`;
- callback route `wc_upayments`;
- historical `_upay_*` order/user/product/subscription metadata;
- provider order identity such as `UPayments_order_id`;
- `upayments_token_identity_secret_v2` and related provenance/scope/generation state;
- subscription hook `upay_process_subscriptions`;
- historical cleanup hooks still required for compatibility;
- billing-attempt state/table identities rooted in `upayments_billing_attempts`;
- historical order payment-method value `upayments`;
- provider API request/response/path/schema terminology;
- public compatibility hooks whose external use is already protected by tests.

These are provider/data contracts, not stale first-party branding.

## Physical bootstrap decision

The first-stable package remains:

`supcheckout/UPayments.php`

That is intentional.

Prior real-WordPress qualification proved that directly renaming an active main plugin file can strand stored plugin-basename state. Because SUPCheckout is permanently UPayments-specific, retaining `UPayments.php` is also semantically acceptable.

A future `supcheckout.php` bootstrap is optional, not required for product quality, and may be attempted only through a separately characterized real-WordPress migration with activation, update, rollback and duplicate-entry evidence.

## Relationship to future provider plugins

Future provider plugins do not become modules inside SUPCheckout.

They should be created as independent repositories/products, preferably from a common **engineering template/standard** rather than by mechanically copying SUPCheckout's provider-specific runtime.

The reusable baseline may standardize:

- repository governance and CODEOWNERS;
- Composer/PHPCS/PHPStan/PHPUnit configuration;
- GitHub Actions security and immutable action pinning;
- deterministic release construction and verification;
- WordPress.org Plugin Check;
- WooCommerce Classic/Blocks/HPOS certification patterns;
- secrets hygiene;
- provider sandbox boundaries;
- release documentation structure;
- security reporting policy;
- architecture/testing conventions.

The baseline must **not** impose UPayments identifiers, payload assumptions, token models, callback semantics or subscription behavior on other providers.

## Fraud, orchestration and cross-provider features

SUPCheckout may contain only fraud/security behavior appropriate to the UPayments integration and local WooCommerce safety.

Cross-provider fraud scoring, provider routing, failover, unified analytics, global reconciliation, merchant-level risk policy, multi-provider vaulting or orchestration belong to the separate platform project.

This boundary prevents the provider plugin family from accidentally becoming a distributed, inconsistent payment platform.

## Repository-history and contributor policy

Existing contributor/author entries remain historical Git commit attribution.

Do not rewrite published/certified history merely to cosmetically reduce GitHub's Contributors count. Future commits must use an email linked to the canonical Simplix maintainer account.

Historical SHAs and evidence remain valid and are preserved.

## Pre-stable identity migration

The current transitional SUCheckout identity is pre-stable and may be superseded before the first public stable release.

The migration target is:

- product: SUPCheckout;
- repository: `SimplixInnovations/supcheckout` after owner rename;
- slug/text domain/package root: `supcheckout`;
- namespace root: `Simplixi\\SUPCheckout`;
- release artifact: `supcheckout-X.Y.Z.zip`.

Permanent migration certification must preserve both previous pre-stable roots where they are still used as upgrade evidence:

1. `simplixpay-upayments/UPayments.php`;
2. `sucheckout-upayments/UPayments.php`;
3. any certified `sucheckout/UPayments.php` transitional fixture created before this decision.

Historical fixtures remain historical; living current code converges on SUPCheckout.

## Branding boundary

Visual branding is a separate owner-supplied tranche.

This design does not invent logos, colors, screenshots, banners or product imagery.

## Completion requirements

The SUPCheckout identity migration is complete only when:

- all living first-party product identity is SUPCheckout;
- canonical slug/text domain/package root is `supcheckout`;
- first-party namespace root is `Simplixi\\SUPCheckout`;
- UPayments provider/persisted compatibility identities remain intact;
- no multi-provider runtime abstraction has been introduced without necessity;
- migration/rollback coverage from all relevant pre-stable roots is green;
- Quality/H12 is green;
- full compatibility certification is green;
- deterministic release build/verification is green;
- provider sandbox certification is green where applicable;
- WordPress.org packaged Plugin Check has zero blocking findings;
- CodeQL/security is green;
- exact-head independent review has zero unresolved valid findings;
- post-merge `main` is reverified;
- repository metadata and local remotes are reconciled after owner/admin rename to `SimplixInnovations/supcheckout`;
- no public tag/release/submission occurs until the final owner release decision.
