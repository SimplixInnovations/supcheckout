# SUPCheckout for UPayments — Compatibility & Certification Matrix

This document is the public compatibility source of truth. A capability is **Verified** only when exact reproducible evidence exists. Green CI is never interpreted beyond the boundary it actually exercises.

**Current posture:** pre-release; repository-owned runtime/payment hardening is advanced; publication is not authorized.

## Current runtime certification anchor

Latest repository-executable E3 runtime checkpoint in draft PR #108:

`540b733c29656758f2392817649fc3d4a4db585d`

Exact checkpoint evidence:

- Quality/H12 — **SUCCESS**;
- Compatibility Certification — full 20-cell runtime matrix + **Compatibility Gate SUCCESS**;
- Ecosystem Certification — **SUCCESS** across five free themes with real child-theme overrides, four free cache/optimizer coexistence plugins and current-runtime legacy+HPOS lanes;
- delayed/combined/repeated Classic script harness — **26 PASS / 0 FAIL**;
- analytics-return replay characterization — **27 PASS / 0 FAIL**;
- Release Gate — **SUCCESS**;
- Provider Sandbox — **SUCCESS**;
- WordPress.org packaged Plugin Check — **SUCCESS**;
- deterministic candidate package — **55 files**, SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`, byte-identical across canonical/Linux/Windows release evidence.

The GitHub default CodeQL JavaScript/TypeScript job did not reach a terminal verdict for this historical checkpoint. This matrix therefore does not claim CodeQL success for `540b733c29656758f2392817649fc3d4a4db585d`. Exact descendant merge/release qualification still requires CodeQL/security green.

Owner technical acceptance remains **ACCEPTED only for the frozen Approach 2 regression reference** `0c883d609906676966002eb022a82a9656eeacc5` and package SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`. The E3 checkpoint does not redefine owner acceptance. Publication remains unauthorized.

## Platform matrix

Every certified row uses a real WordPress/WooCommerce installation and exercises both legacy order storage and HPOS authoritative storage.

| WordPress | WooCommerce | PHP | Legacy storage | HPOS |
|---|---|---:|---|---|
| 7.1 | 11.1.0 | 8.5 | **Verified** | **Verified** |
| 7.1 | 11.1.0 | 8.4 | **Verified** | **Verified** |
| 7.1 | 11.1.0 | 8.3 | **Verified** | **Verified** |
| 7.1 | 11.1.0 | 8.2 | **Verified** | **Verified** |
| 7.0.4 | 11.1.0 | 8.3 | **Verified** | **Verified** |
| 7.0.4 | 11.0.1 | 8.3 | **Verified** | **Verified** |
| 7.0.4 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 7.1 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 6.9.7 | 10.8.1 | 8.3 | **Verified** | **Verified** |
| 6.9.7 | 10.8.1 | 7.4 | **Verified** | **Verified** |

WooCommerce 11.1 requires WordPress 7.0+, so WordPress 6.9 / WooCommerce 11.1 is intentionally excluded as upstream-invalid. PHP 7.4 is the supported compatibility floor, not a recommendation for new deployments.

Public metadata derived from this matrix:

- WordPress `Requires at least`: **6.9**;
- WordPress `Tested up to`: **7.1**;
- WooCommerce `WC requires at least`: **10.8**;
- WooCommerce `WC tested up to`: **11.1**;
- PHP minimum: **7.4**;
- `cart_checkout_blocks`: **declared compatible**;
- `custom_order_tables`: **declared compatible**.

## Identity compatibility

| Surface | Current contract |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Short name | **SUPCheckout** |
| Repository / package slug | `supcheckout` |
| Text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| First-stable physical bootstrap | `UPayments.php` retained |
| Canonical package basename | `supcheckout/UPayments.php` |
| Gateway/payment ID | `upayments` preserved |
| Settings option | `woocommerce_upayments_settings` preserved |
| Blocks / Store API ID | `upayments` preserved |
| Callback | `wc_upayments` preserved |
| Historical payment/meta/token/subscription identities | preserved |

The retained `UPayments.php` filename is an explicit compatibility decision. A future physical rename requires a separately tested migration.

Historical package-root movement remains certified for both pre-stable `simplixpay-upayments` and `sucheckout-upayments` roots moving to canonical `supcheckout`, including protected data continuity and rollback.

## Capability matrix

| Area | Status | Evidence boundary |
|---|---|---|
| Classic checkout registration/runtime | **Verified** | Real WooCommerce gateway registry, protected ID `upayments`. |
| Cart / Checkout Blocks registration & availability | **Verified** | Real Blocks registry plus enabled/disabled/default/malformed-settings behavior and reactive economics state. |
| HPOS | **Verified / declared compatible** | Real legacy + HPOS WooCommerce CRUD with protected payment metadata. |
| Provider Charge initialization | **Verified — bounded sandbox** | Public test Charge initialization; not production completion. |
| Payment-status financial truth | **Verified lifecycle contract** | Authenticated provider-status binding plus exact order/transaction/economic checks. |
| Saved-card/token identity | **Verified — bounded runtime** | Ownership/provenance/scope checks and fail-closed malformed/foreign identity behavior. |
| Subscription checkout eligibility/pre-dispatch | **Verified — bounded runtime** | Guest/mixed-order/plan/interval/token-preflight safeguards. R3 recurring mutation hardening remains open. |
| Multi-merchant | **Verified — one additional merchant only** | One additional allocation; arbitrary multi-split unsupported. |
| Activation/deactivation/reactivation | **Verified** | Protected settings/payment/token state preserved. |
| Uninstall | **Verified non-destructive** | Merchant/payment/token state retained by default. |
| Deterministic canonical ZIP | **Permanent exact-head gate** | HEAD-bound bytes, checksum/manifest, reproducibility and tamper rejection. |
| Historical package-root migration | **Permanent exact-head gate** | Both pre-stable roots → `supcheckout`, continuity + rollback. |
| Official WordPress Plugin Check | **Permanent packaged-artifact gate** | Runs against unpacked deterministic package with `strict: true`. |
| Browser/callback payment updates | **Non-authoritative alone** | Cannot establish paid state without trusted provider verification. |
| Free-theme WooCommerce runtime interoperability | **Verified — bounded** | Twenty Twenty-Five 1.5, Storefront 4.6.2, Astra 4.13.11, Blocksy 2.1.56 and Kadence 1.5.2, including real child-theme WooCommerce template override precedence, server-side checkout validation and finalized economics. |
| Free cache/optimizer coexistence | **Verified — bounded** | LiteSpeed Cache 7.9.1, W3 Total Cache 2.10.6, Breeze 2.5.13 and SiteGround Speed Optimizer 7.8.2 activated on the CI runtime. This proves coexistence contracts, not every vendor optimization mode or server cache. |
| Delayed/combined/repeated Classic JS lifecycle | **Verified — bounded** | First-interaction delay, duplicate/combined evaluation handler ownership and repeated Woo checkout fragment lifecycle are permanent harness contracts. |
| Analytics return/replay | **Verified — characterization** | Replayed verified browser callbacks do not mutate payment state and repeat the same order-received navigation; SUPCheckout emits no tested GA/Meta/GTM purchase API itself. Downstream tracker deduplication is not owned by SUPCheckout. |
| Automatic WooCommerce refunds | **Unsupported** | Withheld pending a durable idempotency/reconciliation design. |
| Arbitrary marketplace multi-split | **Unsupported** | Current boundary is one additional merchant only. |
| Live subscription auto-deduction | **External/manual** | Non-idempotent provider mutation is not executed merely for CI. |
| Wallet payment completion | **External/manual** | Requires eligible provider account/device evidence. |
| WPML / WCML / multicurrency | **External/manual** | Requires dedicated real-environment qualification. |
| RTL / Arabic | **External/manual** | Requires real admin/checkout/account/return UI validation. |
| Paid/proprietary theme modes | **External/manual** | Requires the legally licensed package and real browser/runtime qualification. |
| Cloudflare APO/custom rules/Rocket Loader/host page cache | **External/manual** | Server/CDN behavior is not inferred from local plugin activation; custom cache rules can override safe WooCommerce defaults. |
| Broad browser/device visual interoperability | **External/manual** | Repository automation does not prove visual rendering, focus or device-wallet behavior beyond bounded server-side theme/runtime checks. |
| Accessibility | **External/manual** | Repository markup regressions exist, but full keyboard/focus/screen-reader/contrast/error-state evidence is still external/manual. |
| Performance/load | **Store-specific evidence required** | Universal thresholds are not inferred from CI; R4 must add representative scheduler/load evidence. |
| Penetration test / PCI / legal compliance | **External organizational evidence** | Not produced by repository automation. |

## Current R2 portability/cache qualification boundary

R2 is not certified yet. It must prove:

- public WooCommerce WC-API URL construction rather than hand-built `site_url()` callbacks;
- public-home vs WordPress-site divergence and subdirectory/index/permalink layouts;
- trusted HTTPS/public-origin behavior without raw forwarded-header trust;
- explicit no-cache response semantics for public callback/status surfaces;
- preserved redirect/webhook termination and payment-authority behavior.

No R2 claim is promoted to Verified until a RED is observed, minimal GREEN lands and exact-head certification succeeds.

## Permanent regression controls

The evidence stack is layered:

- Quality Platform Q1-Q19 historical regressions — **closed / retained**;
- H12 PHP and Blocks regressions;
- 20-cell real compatibility matrix;
- Ecosystem Certification free-theme/cache/runtime matrix;
- delayed/combined/repeated Classic JS harness;
- analytics-return replay characterization;
- deterministic release artifact builder/verifier;
- packaged legacy/HPOS smoke;
- historical package-root migration/rollback;
- official packaged WordPress Plugin Check;
- bounded provider-sandbox certification;
- CodeQL/security analysis at merge/release boundaries.

No one layer substitutes for the others or for explicit external/manual qualification.

## Evidence definitions

- **Verified** — exact reproducible environment and reviewed evidence exists.
- **Permanent exact-head gate** — must pass on the exact candidate and again after merge before a release claim is authorized.
- **Verified — bounded** — only the stated boundary is proven.
- **Verified — characterization** — exact current behavior is regression-locked without claiming ownership of downstream third-party behavior.
- **External/manual** — requires an external account, commercial package, browser/device, production-like store or organizational evidence not safely generated by repository automation.
- **Unsupported** — intentionally not implemented/advertised.

## Public-claim rule

Do not broaden platform, provider, feature, multilingual, browser, accessibility, performance, security or compliance claims beyond this matrix. Neighboring green versions, static analysis, unit tests or provider/vendor marketing material are not SUPCheckout certification.
