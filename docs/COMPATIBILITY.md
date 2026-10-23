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

Owner technical acceptance is **ACCEPTED for Release Candidate 1** (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1`) at `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` and package SHA-256 `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`. Historical Approach 3 (`OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3`, `146d65a1c182630c1acc651cacafe30cff5f6b79` / `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`) and Approach 2 are superseded. Publication remains unauthorized. Provider contracts remain uncertified.

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
| Physical main file | `supcheckout.php` (renamed from `UPayments.php` in Sub-project 0a) |
| Canonical package basename | `supcheckout/supcheckout.php` |
| Gateway/payment ID | `upayments` preserved |
| Settings option | `woocommerce_upayments_settings` preserved |
| Blocks / Store API ID | `upayments` preserved |
| Callback | `wc_upayments` preserved |
| Historical payment/meta/token/subscription identities | preserved |

The main file is `supcheckout.php` since Sub-project 0a (owner decision 2026-10-16, pre-release). A header-less transitional `supcheckout/UPayments.php` shim moves pre-rename installs' active entry to `supcheckout/supcheckout.php` on their next load, without reactivation or data changes. The Release Artifact `in-place-main-file-update` cells exercise the in-place update on real WordPress, single-site and network-activated, through WP-CLI, the Plugins screen, the upload installer and the bulk / one-click upgrader. Pre-rename installs: update by zip upload ("Replace current with uploaded"), WP-CLI, or the Plugins-screen / Updates-screen one-click and bulk updates (`bulk_upgrade()`). These keep the stored `supcheckout/UPayments.php` entry, and the shim moves it on the next request. The classic single-plugin Update-now flow (`wp-admin/update.php?action=upgrade-plugin`, `Plugin_Upgrader::upgrade()`, used without JavaScript) deactivates the plugin before installing and then reactivates it by the old basename. Validation rejects the header-less shim, so the install ends **deactivated**: no SUPCheckout code runs, merchant data is unchanged, and one activation of `supcheckout/supcheckout.php` recovers it. Any future update channel must key updates to `supcheckout/supcheckout.php`.

SUPCheckout no longer declares the following global functions (Sub-project 0a Task 4):
- `woocommerceUpaymentsInit()` and `upaymentsMissingWcNotice()`;
- the nine product-data wrappers `addCustomProductType()`, `mapCustomProductClass()`, `customProductTypes()`, `addCustomDataTab()`, `addCustomDataPanel()`, `saveCustomFieldData()`, `displayCustomFieldOnFrontend()`, `displayCustomDataInCart()` and `saveCustomDataToOrderItems()`.

Third-party `remove_action()` / `remove_filter()` calls that name these functions no longer match. The hooks, priorities and argument counts are unchanged, and the product-data callbacks are static methods of the same names on `Simplixi\SUPCheckout\Subscription\ProductTypeHooks`.

The gateway object no longer has the unused public methods `encrypt()`, `decrypt()` and `getApiKey()` (Sub-project 0a Task 7). The public compatibility wrapper `getAPIUrlForRetreiveCards()` is unchanged.

SUPCheckout no longer defines the constants `UP_PLUGIN_URL`, `UP_PLUGIN_PATH` and `UPAYMENTS_PLUGIN_FILE` (Sub-project 0b Task 2); the official UPayments plugin defines the same names. Its own are `SUPCHECKOUT_PLUGIN_URL`, `SUPCHECKOUT_PLUGIN_PATH` and `SUPCHECKOUT_PLUGIN_FILE`. Theme overrides of `templates/new-design-form.php` or `templates/old-design-form.php` that print `UP_PLUGIN_URL` must switch to `SUPCHECKOUT_PLUGIN_URL`. The gateway, settings, Blocks and callback identities in the table above are unchanged until the store runs the takeover's cutover.

### Takeover boundary (Sub-project 0b)

`wp supcheckout takeover` moves a store to the `supcheckout` identities. The Takeover Migration Matrix (`.github/workflows/takeover-migration-matrix.yml`, green on `main` at `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b`) proves this boundary on real WordPress:

- official UPayments plugin refs `V2.0.5`, `upayments-V2.2.1`, `3.0.0` and `3.1.1` (from `github.com/upaymentskwt/woocommerce`), each with HPOS (compatibility sync off) and with legacy storage: preflight, interrupted and resumed copy, cutover, legacy-route webhook, subscription claim, rollback, run again and forced finalize;
- an in-place update from the last pre-0b SUPCheckout package, including H12 saved-card provenance;
- 10,000 orders (3.1.1, legacy storage) with no batch over 50 orders.

The matrix sends no provider traffic. Other official plugin versions are not exercised. WCML and FunnelKit gateway settings are **report-only**: the matrix seeds WCML's option shapes and asserts that Preflight reports them and that they stay byte-for-byte unchanged. Real WPML/WCML is never installed in CI and stays **External/manual**. It is covered by the owner's staging rehearsal, which is in progress and not yet evidence.

### Onboarding API boundary (Sub-project 0c)

Sub-project 0c adds an admin-only REST API, `supcheckout/v1`, that runs the takeover and the new-merchant checks in the background. It has no screens; the wizard comes in Sub-project 2. WP-CLI (`wp supcheckout takeover`) keeps every command, flag, exit code and output line.

- **Routes.** 12 on the full boot: `GET /onboarding/status`, `GET`/`POST /onboarding/preflight`, `POST /onboarding/switch`, `/rollback`, `/abort`, `/finalize` and `/stale-claims/release`, `GET`/`POST /onboarding/self-test` and `GET`/`POST /setup/checks`. While the official UPayments plugin is active (SUPCheckout paused), only the first 8 are registered; the self-test and setup checks need the full runtime.
- **Who may call them.** Every route needs `manage_woocommerce`: a logged-out caller gets 401 and a logged-in user without it gets 403. Every write also needs the `wp_rest` nonce in `X-WP-Nonce`, verified explicitly, so an Application Password alone is refused with 403 `supcheckout_nonce_invalid`. Automation uses WP-CLI. Consenting to deactivate or reactivate the official plugin (`deactivate_official`, `reactivate_official`) and a real finalize (a dry run excepted) also need `activate_plugins`: a WooCommerce Shop Manager gets 403 `supcheckout_capability_plugins`, with no operation started and no rate slot spent, and keeps every other route.
- **Input.** Writes take JSON-schema arguments only. An unknown parameter, such as a misspelled consent flag, is refused with 400 `supcheckout_unknown_parameter`. `confirm` must be `true` (finalize: the word `FINALIZE`, unless `dry_run` is `true`). The consent flags (`deactivate_official`, `reactivate_official`, `force_after_review`) are booleans that default to `false`; the string `"false"` is `false`.
- **One operation at a time.** Writes are rate limited per site (10 s per write route; 65 s for the shared provider-probe bucket of preflight, self-test and setup checks) with 429 `supcheckout_rate_limited` and `Retry-After`. A start whose plan passes while another operation runs gets 409 `supcheckout_operation_in_progress`. A start that the takeover state does not allow gets the plan's 409 `supcheckout_state`: for example, an abort while a switch is running.
- **Responses.** At most 32768 bytes of JSON, lists over 20 entries truncated with a marker. They hold no API key, token, card data or customer personal data; engine results appear only inside the bounded operation report and hold names, codes, counts and numeric order/user IDs. Our responses send `Cache-Control: no-store, private`. For a logged-in user, WordPress core's no-cache headers replace that value; they still contain `no-store` and `private`. A logged-out `?_envelope` request gets no `Cache-Control` header, and its body is only the generic 401.
- **Background execution.** Preflight, switch, rollback, abort, finalize, self-test and setup checks run through Action Scheduler (bundled with WooCommerce). Only `stale-claims/release` runs inline, at most 500 rows. Action Scheduler needs WP-Cron, a system cron or admin page loads. Without them the operation waits with nothing half-done, and after 60 s past due `GET /onboarding/status` reports `runner.stalled: true` with a hint. The rollback's 120 s settle wait is a scheduled resume, not a sleeping request. If the Action Scheduler admin "Run" row action runs an onboarding action directly, that action is marked failed; the next status poll queues it again.
- **Self-test.** It makes one read-only payment-method availability request to UPayments, in test and live mode alike. A provider rate-gate cooldown is a warning, and a provider outage is `provider_unavailable`. It never creates a Charge or changes a payment.
- **Cache purge.** After a switch, rollback, abort or finalize changes the store, SUPCheckout purges the cache layers it can call through their documented public APIs, plus the object cache. A failing purge never fails the step. Evidence per layer:
  - **runtime-verified** in Ecosystem Certification (E3 cells, pinned versions): LiteSpeed Cache 7.9.1, W3 Total Cache 2.10.6, Breeze 2.5.13 and SiteGround Speed Optimizer 7.8.2. The Breeze and SiteGround APIs were source-verified at Breeze 2.6.2 and SiteGround Speed Optimizer 7.8.4;
  - **unit-verified only**: WP Rocket and WP Super Cache;
  - **manual**: the Cloudflare plugin has no public purge API, so the purge and the setup checks report it `manual` and the owner purges it by hand.
- **WCML.** Per-currency gateway settings in WooCommerce Multilingual are **report-only**: the self-test's `multilingual` check reports them, and nothing changes them. Real WPML/WCML stays **External/manual**.
- **Currency decimals.** Checkout refuses, before any UPayments call, an order total with more decimals than its currency's ISO 4217 minor units allow (3 for KWD, BHD and OMR; 2 for the other supported currencies), and never rounds it. The setup checks' `currency_decimals` check warns when the store or a WCML currency is set to more decimals than that.
- **Multisite.** A network-activated official plugin blocks the switch (`official_network_active`). Nothing is ever activated or deactivated network-wide. Deactivating SUPCheckout cancels its own schedules (the hourly reconciliation sweep, the new-name subscription tick and stale onboarding actions) for the current site only; a network deactivation does not visit the other sites, which keep theirs.

The real-WordPress REST and driver certification runs in every Compatibility cell, and the purge step runs in the Ecosystem Certification E3 cells. Evidence: `docs/project/evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md`.

### Admin UI boundary (Sub-project 1)

Sub-project 1 adds the design-token system and the React component library that the SUPCheckout admin screens will be built from (Sub-project 2). It adds no admin screen, menu or REST call. `AdminAssets` registers and enqueues the committed build only on the SUPCheckout screens registered with it; in Sub-project 1 only the test-only gallery registers any, so no shipped wp-admin page loads the build yet. The admin UI is brand-neutral until Sub-project 5. Evidence: `docs/project/evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md`.

- **Verified** (Admin UI Certification, `.github/workflows/admin-ui-certification.yml`, on a test-only component gallery in real wp-admin):
  - Chromium, as pinned by Playwright 1.63.0, on WP 6.9.10 / WC 10.8.1 and WP 7.1.3 / WC 11.1.2: axe WCAG 2.2 A/AA with zero violations and no disabled rule, keyboard operation, focus visibility and focus never obscured, 24 px targets, scoping to SUPCheckout screens, WordPress admin CSS not bleeding into the controls, the RTL stylesheet swap and translations, including the lazy chunk;
  - the React 18.3.1 runtime that WordPress 6.9 and 7.1 bundle (`wp-element`); React, `react-dom` and the JSX runtime are never bundled;
  - LTR and Arabic RTL, in light and dark (four modes);
  - the nine core admin colour schemes (`fresh`, `light`, `modern`, `blue`, `coffee`, `ectoplasm`, `midnight`, `ocean`, `sunrise`). The `--wp-admin-theme-color` of each is recorded at WP 7.1.3; WP 6.9.10 differs for seven schemes (`light`, `blue`, `coffee`, `ectoplasm`, `midnight`, `ocean`, `sunrise`) and those deltas are recorded and asserted;
  - exact visual regression (`threshold 0`, `maxDiffPixels 0`) of every gallery specimen in the four modes on WP 7.1.3 / WC 11.1.2, on a pinned `ubuntu-24.04` runner;
  - WCAG 2.2 AA contrast of every token pair in light and dark across the nine schemes, the committed-build drift gate on Linux and Windows, and the build contract (externals, no source maps, licenses, gzip budgets).
- **Unit-only:** React 19 (`npm run test:unit:react19`, Jest with React 19.3.0). No WordPress release in the matrix ships React 19 yet.
- **Browser floor:** only Chromium is certified. The command palette viewport uses the CSS `:has()` selector, so it needs at least Firefox 121 or Safari 15.4; older browsers are not supported for the admin app.
- **Emulated only:** `forced-colors: active` and `prefers-reduced-motion` are Playwright media emulation. Forced-colors emulation only toggles the media query; it does not apply a real Windows High Contrast theme.
- **Working tree, not the ZIP:** the browser and visual lanes run against the git working tree, not the packaged ZIP. The Release Artifact harness separately asserts that every committed build file under `assets/admin/build/` is in the canonical ZIP, and the drift gate proves the committed build equals a clean rebuild.
- **Limits of the gallery copy:** the gallery shows English copy in RTL. Real Arabic text rendering is baselined only through the `foundations-arabic` specimen (Noto Sans Arabic, a test-only font) and the Arabic fixture translations.
- **External / manual:**
  - Safari, Firefox and mobile devices (only Chromium is automated);
  - screen-reader passes with NVDA and VoiceOver over the dialog, the money confirmation, toasts (including whether a limited toast that becomes visible again is re-announced), tabs and the command palette;
  - real Windows High Contrast;
  - a real Arabic copy review by a native speaker.

Historical package-root movement remains certified for both pre-stable `simplixpay-upayments` and `sucheckout-upayments` roots moving to canonical `supcheckout`, including protected data continuity and rollback.

## Capability matrix

| Area | Status | Evidence boundary |
|---|---|---|
| Classic checkout registration/runtime | **Verified** | Real WooCommerce gateway registry, protected ID `upayments`. |
| Cart / Checkout Blocks registration & availability | **Verified** | Real Blocks registry plus enabled/disabled/default/malformed-settings behavior and reactive economics state. |
| HPOS | **Verified / declared compatible** | Real legacy + HPOS WooCommerce CRUD with protected payment metadata. |
| Provider Charge initialization | **Verified — bounded sandbox + one production account** | Public test Charge initialization; plus one production merchant account (non-white-label) accepting the plugin's Charge initialization on 2026-10-13. Not production completion. |
| Provider authentication | **Bearer API key only — known limitation** | SUPCheckout sends the merchant API key as a Bearer token and no HMAC / `X-Signature` / `X-Timestamp` headers. One production merchant account accepted this for payment status, button status and Charge initialization (2026-10-13). An account that enforces request signatures fails closed: checkout returns an error and no order is marked paid. |
| Payment-status financial truth | **Verified lifecycle contract** | Authenticated provider-status binding plus exact order/transaction/economic checks. |
| Saved-card/token identity | **Verified — bounded runtime** | Ownership/provenance/scope checks and fail-closed malformed/foreign identity behavior. |
| Subscription checkout eligibility/pre-dispatch | **Verified — bounded runtime** | Guest/mixed-order/plan/interval/token-preflight safeguards. R3 recurring mutation hardening remains open. |
| Multi-merchant | **Verified — one additional merchant only** | One additional allocation; arbitrary multi-split unsupported. |
| Activation/deactivation/reactivation | **Verified** | Protected settings/payment/token state preserved. |
| Uninstall | **Verified non-destructive** | Merchant/payment/token state retained by default. |
| Deterministic canonical ZIP | **Permanent exact-head gate** | HEAD-bound bytes, checksum/manifest, reproducibility and tamper rejection. |
| Historical package-root migration | **Permanent exact-head gate** | Both pre-stable roots → `supcheckout`, continuity + rollback. |
| Takeover from the official plugin / pre-0b SUPCheckout | **Verified — bounded** | Official `V2.0.5`, `upayments-V2.2.1`, `3.0.0`, `3.1.1` × HPOS/legacy, pre-0b lane and 10,000 orders on real WordPress, without provider traffic. WCML/FunnelKit settings are report-only; real WPML/WCML is external/manual. |
| Onboarding admin REST API (`supcheckout/v1`) | **Verified — bounded** | Admin-only routes with explicit `wp_rest` nonce on writes, and the Action Scheduler driver, on real WordPress in every Compatibility cell, without provider traffic. Cache purge runtime-verified for LiteSpeed Cache, W3 Total Cache, Breeze and SiteGround Speed Optimizer; WP Rocket and WP Super Cache unit-verified; Cloudflare manual. |
| Admin UI design system (Sub-project 1) | **Verified — bounded** | Chromium (Playwright 1.63.0) on WP 6.9.10 / WC 10.8.1 and WP 7.1.3 / WC 11.1.2, React 18.3.1 runtime, LTR + Arabic RTL, light + dark, nine core admin schemes, on a test-only gallery. React 19 unit-only. Safari, Firefox, mobile, screen readers, real Windows High Contrast and real Arabic copy are external/manual. See "Admin UI boundary (Sub-project 1)". |
| Official WordPress Plugin Check | **Permanent packaged-artifact gate** | Runs against unpacked deterministic package with `strict: true`. |
| Browser/callback payment updates | **Non-authoritative alone** | Cannot establish paid state without trusted provider verification. |
| Free-theme WooCommerce runtime interoperability | **Verified — bounded** | Twenty Twenty-Five 1.5, Storefront 4.6.2, Astra 4.13.11, Blocksy 2.1.56 and Kadence 1.5.2, including real child-theme WooCommerce template override precedence, server-side checkout validation and finalized economics. |
| Free cache/optimizer coexistence | **Verified — bounded** | LiteSpeed Cache 7.9.1, W3 Total Cache 2.10.6, Breeze 2.5.13 and SiteGround Speed Optimizer 7.8.2 activated on the CI runtime. This proves coexistence contracts, not every vendor optimization mode or server cache. |
| Delayed/combined/repeated Classic JS lifecycle | **Verified — bounded** | First-interaction delay, duplicate/combined evaluation handler ownership and repeated Woo checkout fragment lifecycle are permanent harness contracts. |
| Analytics return/replay | **Verified — characterization** | Replayed verified browser callbacks do not mutate payment state and repeat the same order-received navigation; SUPCheckout emits no tested GA/Meta/GTM purchase API itself. Downstream tracker deduplication is not owned by SUPCheckout. |
| Automatic WooCommerce refunds | **Unsupported** | Withheld pending a durable idempotency/reconciliation design. |
| Arbitrary marketplace multi-split | **Unsupported** | Current boundary is one additional merchant only. |
| Live subscription auto-deduction | **External/manual — automatic renewal charges blocked** | SUPCheckout sends no automatic renewal charge while its success cannot be verified (owner decision 2026-10-22); renewals are collected manually or by payment link. Non-idempotent provider mutation is not executed merely for CI. |
| Wallet payment completion | **External/manual** | Requires eligible provider account/device evidence. |
| WPML / WCML / multicurrency | **External/manual** | Requires dedicated real-environment qualification. |
| RTL / Arabic | **External/manual** | Requires real admin/checkout/account/return UI validation. |
| Paid/proprietary theme modes | **External/manual** | Requires the legally licensed package and real browser/runtime qualification. |
| Cloudflare APO/custom rules/Rocket Loader/host page cache | **External/manual** | Server/CDN behavior is not inferred from local plugin activation; custom cache rules can override safe WooCommerce defaults. |
| Broad browser/device visual interoperability | **External/manual** | Repository automation does not prove visual rendering, focus or device-wallet behavior beyond bounded server-side theme/runtime checks. |
| Accessibility | **External/manual** | Repository markup regressions exist, but full keyboard/focus/screen-reader/contrast/error-state evidence is still external/manual. |
| Performance/load | **Store-specific evidence required** | Universal thresholds are not inferred from CI; R4 must add representative scheduler/load evidence. |
| Penetration test / PCI / legal compliance | **External organizational evidence** | Not produced by repository automation. |

## R2 portability/cache qualification

R2 is **Verified — bounded** at certified PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c` / merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`.

Proven in repository automation:

- public WooCommerce WC-API URL construction via the gateway (`Simplixi\SUPCheckout\Gateway\Gateway`) platform resolver (not hand-built `site_url()` callbacks);
- public-home vs WordPress-site divergence and subdirectory/index/permalink layouts;
- trusted HTTPS/public-origin behavior without raw forwarded-header trust;
- explicit no-cache response semantics for public callback/status surfaces;
- preserved redirect/webhook termination and payment-authority behavior.

R2 does not certify arbitrary CDN custom cache rules or host-specific full-page cache overrides.

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
- real-WordPress takeover migration matrix;
- Admin UI Certification: token contrast, build contract and drift, accessibility on real WordPress and exact visual regression;
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
