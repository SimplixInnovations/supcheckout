# Architecture & Code-Quality Foundation

> **Closed architecture milestone record with permanent ratchets.** Historical gate-entry/current-owner wording is preserved for provenance; later SUCheckout ratchets are appended where independently verified. `PROJECT-STATUS.md` owns current program state.

**Status:** DONE / VERIFIED (DISCOVERY + A1-A5)

**Final implementation PR:** #25

**Verified closure `main`:** `3223a882867634a2ba7588d7afbd2b2e4b4c21e4`

**Verified closure tree:** `392b73425fa3219b6414a0984136b92c8ef77576`

**Gate purpose:** replace inherited mixed-responsibility structure incrementally with explicit Simplix-owned boundaries while preserving every closed payment, security, H12, Phase 9I and compatibility contract.

This is not permission for a big-bang rewrite, cosmetic renaming, broad API redesign, or opportunistic cleanup of payment-critical code.

## Entry conditions

The gate starts only because the following are already independently DONE / VERIFIED:

- Repository Foundation & Readiness;
- Phase 0 release identity/updater ownership;
- Phase 9I historical token-identity migration;
- Provider Contract & Payment Lifecycle;
- Security Threat-Model Closure;
- H12 token-identity hardening baseline.

The permanent regression platform entering this gate is:

- Phase 0: **35 PASS / 0 FAIL**;
- Phase 9I preflight: **123 / 0**;
- Phase 9I executor: **59 / 0**;
- Phase 9I operations: **81 / 0**;
- Provider Payment Lifecycle: **141 / 0**;
- Provider Exact Amount Binding: **4 / 0**;
- Security Threat-Model: **81 / 0**;
- H12 PHP: **1927 / 0**;
- H12 Blocks: **144 / 0**.

Every architecture tranche must keep those suites mandatory.

## Discovery closure evidence

The discovery/characterization tranche is **DONE / VERIFIED**:

- PR #19 final reviewed head: `6e51b1c1c5649313acf86943e30793c38bc71f14`;
- exact PR merge tree: `3fcaed35546a6b1407d2a46797630e46301e65ef`;
- squash merge on `main`: `596ffb433813cdc06e81d67162617b3019af686b`, with a valid verified GitHub signature;
- exact-head Quality Gates run #147: **SUCCESS**;
- push-triggered post-merge Quality Gates run #148: **SUCCESS**;
- implementation branch `architecture/discovery`: **deleted after verified merge**.

Discovery froze the responsibility map, dependency direction, compatibility surfaces, A1-A5 order, monolith ratchet and permanent architecture harnesses.

## A1 closure evidence

The provider endpoint/mode tranche is **DONE / VERIFIED**:

- PR #21 final reviewed head: `baed693964556120dc7ad07dbc740d3acc1af20f`;
- exact head tree: `ddb2ac7cd8b2d4f454867e10bc361fee94dbcf4b`;
- squash merge on `main`: `d43d175a1443709d42efabfbe78519a5a84f4dc9`, with a valid verified GitHub signature;
- exact-head Quality Gates run #152: **SUCCESS**;
- push-triggered post-merge Quality Gates run #153: **SUCCESS**;
- Provider Endpoints: **49/0** on exact head and post-merge `main`;
- implementation branch `architecture/a1-provider-endpoints`: **deleted after verified merge**.

A1 moved deterministic mode/endpoint resolution to `src/Provider/EndpointResolver.php`, retained all four public compatibility wrappers and preserved the inherited live and sandbox URL bytes. The current official provider production-host difference remains explicitly out of scope for A2.

## A2 closure evidence

The payment-method availability tranche is **DONE / VERIFIED**:

- PR #22 final reviewed head: `bdb627520aa28e71b69a91f8ef71d04d257a3ad8`;
- exact head/merge tree: `1addbcc02e0d30f57a948cafd8111fb94e60c4da`;
- squash merge on `main`: `f85894271e8f991e77a8e6a2b306f4d191483bbd`, with a valid verified GitHub signature;
- exact-head Quality Gates run #155: **SUCCESS**;
- push-triggered post-merge Quality Gates run #156: **SUCCESS**;
- Payment-Method Availability: **102/0** on exact head and post-merge `main`;
- implementation branch `architecture/a2-payment-method-availability`: **deleted after verified merge**.

A2 moved cache identity, site/mode advisory locking, the durable 65-second gate and strict provider normalization to `src/Provider/PaymentMethodAvailability.php` while retaining authenticated transport and presentation in the gateway compatibility seam.

## A3 closure evidence

The gateway settings/admin/single-additional-merchant presentation tranche is **DONE / VERIFIED**:

- PR #23 final reviewed head: `85028cfb4431cc29820eaca4e254bf6c87daa378`;
- exact head/merge tree: `a7f66ee6cf8c9d5324a0ae77b8c61e69e87bdff7`;
- squash merge on `main`: `6291196b35a952ea974549d1aa6d6ae9bbcc64dc`, with a valid verified GitHub signature;
- exact-head Quality Gates run #158: **SUCCESS**;
- push-triggered post-merge Quality Gates run #159: **SUCCESS**;
- Gateway Settings: **90/0** on exact head and post-merge `main`;
- final independent review: clean on `85028cfb44`, with zero unresolved threads;
- implementation branch `architecture/a3-gateway-settings-admin-multimerchant`: **deleted after verified merge**.

A3 moved the complete characterized 21-field schema, settings validation, one-row allocation renderer and admin assets to `src/Admin/GatewaySettings.php`. The final review strengthened the permanent gate with an independent frozen full-schema fixture and exact complete asset-registration tuples. Runtime Charge behavior and exactly one `extraMerchantData` allocation remained in the gateway.

## A4 closure evidence

The subscription product/admin/My Account presentation tranche is **DONE / VERIFIED**:

- PR #24 final reviewed head: `2a2c6a4c67775b6614297d2c0150f3ca61220498`;
- exact head/merge tree: `f74899b93f493be872e0ce993e30079d0223dc7b`;
- squash merge on `main`: `d24b83356cc766f82c3ad9e529d3ec3f4194e887`, with a valid verified GitHub signature;
- exact-head Quality Gates run #164: **SUCCESS**;
- push-triggered post-merge Quality Gates run #165: **SUCCESS**;
- Subscription Presentation: **75/0** on exact head and post-merge `main`;
- final independent review: clean on `2a2c6a4c67`, with zero unresolved threads;
- implementation branch `architecture/a4-subscription-presentation`: **deleted after verified merge**.

A4 moved characterized subscription hook composition, product/admin fields and My Account presentation to `src/Subscription/Composition.php` and `src/Subscription/Presentation.php`. Customer mutation, scheduler, cycle-claim, billing-attempt, checkout and provider dispatch behavior remained outside the boundary.

## A5 closure evidence

The checkout payload/orchestration tranche is **DONE / VERIFIED**:

- PR #25 final reviewed head: `997e18d8eb6264a84c6a9a35158213d3d655e6b3`;
- exact head/merge tree: `392b73425fa3219b6414a0984136b92c8ef77576`;
- squash merge on `main`: `3223a882867634a2ba7588d7afbd2b2e4b4c21e4`, with a valid verified GitHub signature;
- exact-head Quality Gates run #173: **SUCCESS**;
- push-triggered post-merge Quality Gates run #174: **SUCCESS**;
- Checkout Orchestration: **67/0** on exact head and post-merge `main`;
- final independent review: clean on `997e18d8eb`, with zero unresolved threads;
- implementation branch `architecture/a5-checkout-orchestration`: **deleted after verified merge**.

A5 moved strict checkout request/decimal/payload construction and the checkout-to-Charge workflow to `src/Payment/CheckoutPayload.php` and `CheckoutOrchestrator.php`. The public gateway and protected request/transport seams remain compatibility adapters; scheduler, CycleClaim, billing-attempt storage and auto-deduct dispatch remained outside the boundary.

## Current structural baseline

### Primary monolith

At the verified discovery closure, `UPayments.php` was **257,832 bytes**. A1 reduced it to **257,298 bytes**, A2 to **238,714 bytes**, A3 to **223,942 bytes** and verified A4 to **205,702 bytes**. A5 reduced the accepted ratchet to **88,839 bytes**. Enterprise Compatibility Certification raised the exact accepted size by **23 bytes to 88,862 bytes** for one evidence-backed activation compatibility guard (`is_array($settings) && `) after a real WordPress 7.1 / WooCommerce 11.1.0 / PHP 8.3 activation reproduced `Cannot use object of type stdClass as array` at the activation callback. The matrix-proven support-metadata tranche then raised the exact accepted size by **76 bytes to 88,938 bytes** solely for the verified WordPress/WooCommerce/PHP support header lines. The separately RED→GREEN verified HPOS declaration then raised the exact accepted size by **164 bytes to 89,102 bytes** solely for the guarded `custom_order_tables` compatibility declaration inside the existing `before_woocommerce_init` callback. Release-claim hardening later raised the exact accepted size by **83 bytes to 89,185 bytes** solely to replace an overbroad merchant-facing gateway description with provider/account-bounded payment-method wording and an explicit separately-validated-provider-setup boundary for subscription auto-deduction; payment transport, provider payloads, scheduler behavior, feature toggles and persisted identities were unchanged. The SUCheckout WordPress.org transport tranche then reduced the accepted monolith ratchet to **87,846 bytes** by replacing the remaining gateway/status cURL implementation with the WordPress HTTP API while preserving endpoint, Bearer-authentication, timeout, redirect, TLS and response-classification behavior under a dedicated 27-assertion transport harness. The completed GitHub repository rename reconciliation then reduced the exact ratchet by **10 bytes to 87,836 bytes** solely by changing the plugin-header repository URI from the superseded `SimplixInnovations/sucheckout-upayments` coordinate to canonical `SimplixInnovations/sucheckout`; executable payment/provider behavior and persisted identities were unchanged. The final post-rename SUPCheckout coordinate closure later advances the current exact ratchet from **87,315 bytes to 87,316 bytes** solely because the living plugin-header URI changes from the pre-rename `SimplixInnovations/sucheckout` coordinate to canonical `SimplixInnovations/supcheckout`; no executable payment/provider behavior or persisted identity changes. The strict WordPress.org zero-warning hardening then advances the reviewed ratchet to **91,359 bytes** because protected legacy WooCommerce callback/class identities and external-provider nonce boundaries now carry precise inline PHPCS rationale, the admin asset router sanitizes its three query selectors explicitly, and `REQUEST_METHOD` is unslashed/sanitized. The change does not rename protected identities or broaden payment authority; the larger source footprint is deliberate review evidence required to make official Plugin Check warnings merge-blocking rather than globally ignored. The activation guard performs no option mutation and remains in `UPayments.php` because the failing activation callback itself is still a protected physical-main-file responsibility; extracting the callback solely to avoid a small ratchet increase would broaden the fix beyond the proven defect. The main plugin bootstrap, `WC_Upayments` gateway implementation and public/protected checkout compatibility seams remain preserved. Source characterization identifies at least these responsibility families inside the same file:

1. plugin bootstrap, constants, WooCommerce availability checks and gateway registration;
2. gateway constructor/hook registration and runtime composition;
3. settings schema, settings validation and admin presentation;
4. checkout/request-context parsing for classic and Store API flows;
5. exact decimal validation, provider amount arithmetic and JSON-number injection;
6. provider URL construction and authenticated transport;
7. payment-method discovery, cache, advisory lock and rate-gate behavior;
8. saved-card discovery and customer-token integration;
9. ordinary checkout `process_payment()` orchestration and provider Charge payload assembly;
10. legacy return/webhook/IPN routing around the extracted payment lifecycle;
11. thank-you/admin-order/account presentation;
12. multi-merchant routing/configuration/repeater presentation;
13. subscription module bootstrap and subscription summary/cart behavior;
14. custom subscription product type, product-meta persistence and cart/order-item presentation;
15. frontend/admin asset enqueueing and gateway icons;
16. activation/setup and global WooCommerce hooks.

This mixed ownership is the principal architecture debt. The gate must reduce it by delegation behind stable compatibility wrappers rather than deleting or globally renaming entry points.

File size alone is not authority to refactor; it is only a hotspot signal.

### External-readiness UI closure ratchet (appended)

The external-readiness enterprise/UI closure (PR #128) advances the exact `UPayments.php` ratchet from **63,064 bytes to 63,366 bytes** (+302). The growth is limited to two evidence-backed defects inside existing gateway callbacks and to delegation: the `target="_blank"` My Account link gains `rel="noopener noreferrer"` (reverse-tabnabbing hardening); the gateway icon label becomes translatable and escaped, with the decorative provider image given empty `alt` because the adjacent visible label already names the method; and the Classic checkout localization payloads are delegated in one line each to `CheckoutAssets::new_design_i18n()` and `CheckoutAssets::subscription_i18n()`. The translated payloads themselves live in `src/Gateway/CheckoutAssets.php`, which now owns them for both the canonical gateway enqueue and the embedded-template enqueue, so the two paths cannot drift. Extracting the icon filter or the account link solely to avoid the increase would broaden the change beyond the proven defects. Payment transport, provider payloads, callback routing, persisted identities and the boolean-only `isLoggedIn` browser context are unchanged.

### Monolith ratchet contract

The permanent architecture harness records the **exact accepted `UPayments.php` byte size for the current architecture milestone**, not merely the original entry ceiling. Any tranche that changes `UPayments.php` must update that accepted size to the exact reviewed post-change size, so every shrink becomes the next ratchet point instead of allowing later regrowth back to 257,832 bytes.

Normal architecture tranches may hold or reduce the ratchet. Increasing it is merge-blocking unless the PR documents a concrete defect/compatibility necessity, proves why the change cannot safely live behind an extracted boundary, and receives explicit independent review. Size ratcheting must also be supplemented by responsibility-specific assertions; removed bytes may not simply be replaced with a new unrelated responsibility.

### Existing extracted Simplix-owned seams

`src/` already provides useful strangler boundaries:

- `src/Release/` — public Simplix release identity and updater ownership foothold;
- `src/Migration/` — Phase 9I preflight/executor/batch/admin/CLI/settings/bootstrap;
- `src/Payment/` — `PaymentLifecycle`, `StatusVerifier`, `ProviderResult`, `StatusRateGate`, `OrderLock`;
- `src/Security/` — public order-status authorization boundary.

Legacy but already separated protected areas remain under `includes/`:

- `includes/Token/CustomerTokenIdentity.php` — **96,821 bytes**, H12-critical;
- `includes/Subscription/Cron/Scheduler.php` — **47,965 bytes**;
- `includes/Subscription/Cron/CycleClaim.php` — **15,954 bytes**;
- `includes/Subscription/Checkout/Fields.php`;
- `includes/Subscription/Manager.php`;
- `includes/class-wc-gateway-upayments-blocks.php` — Blocks gateway integration.

## Protected compatibility boundaries

Architecture work must preserve unless a separately characterized migration explicitly replaces them:

- payment method/gateway ID `upayments`;
- settings key `woocommerce_upayments_settings`;
- callback route `wc_upayments`;
- existing `_upay_*` metadata and historical `UPayments_*` evidence used by closed contracts;
- H12 secret/provenance/scope/generation identities;
- historical order payment-method values;
- subscription scheduler, cleanup, cycle-claim and billing-attempt identities;
- current `UPayments.php` physical main-file identity and `upayments` text domain until their dedicated distribution/i18n migrations;
- public/third-party-callable legacy gateway methods unless characterization proves a safe removal path.

New code uses the `Simplix\Pay\UPayments` namespace. Existing UPayments namespaces are not globally renamed in this gate.

## Dependency direction

The target is a small composition root plus explicit services, not an overbuilt container.

Rules:

1. plugin/bootstrap code may construct services and register WordPress/WooCommerce hooks;
2. domain/service classes must not reach back into the bootstrap to discover dependencies;
3. new pure provider/value helpers should not depend on WooCommerce globals;
4. WordPress/WooCommerce adapters may depend on stable domain services, not the reverse where avoidable;
5. Payment, Migration and Security closed modules must not acquire new cross-dependencies merely for convenience;
6. legacy gateway methods may remain as thin compatibility wrappers delegating into extracted services;
7. no new global mutable state is introduced;
8. no extraction may silently change persisted keys, hooks, routes, provider payload fields or order-state semantics.

## Candidate target modules

The long-term shape remains incremental and evidence-driven:

```text
src/
  Plugin/
  Requirements/
  Gateway/
  Provider/
  Payment/
  Webhook/
  Refund/
  Token/
  Migration/
  Subscription/
  MultiMerchant/
  Blocks/
  Admin/
  Diagnostics/
  Logging/
  Compatibility/
```

A directory is created only when a real responsibility is extracted; empty architecture theater is prohibited.

## Extraction priority

### A1 — provider endpoint/mode resolution — first safe runtime seam

Create a small pure `Provider` service for test/live API base and endpoint resolution. Existing public gateway helpers remain compatibility wrappers and must return byte-equivalent URLs. No network behavior, credentials, payment truth or payload semantics change.

**A1 implementation contract:**

- `src/Provider/EndpointResolver.php` owns only deterministic mode-to-base and route resolution;
- `getAPIUrl()`, `getAPIUrlForCreateToken()`, `getAPIUrlForCheckPaymentButtonStatus()` and the historically misspelled `getAPIUrlForRetreiveCards()` remain public compatibility wrappers;
- live mode remains byte-equivalent to the inherited `https://apiv2api.upayments.com/api/v1/` base and test mode remains byte-equivalent to `https://sandboxapi.upayments.com/api/v1/`;
- the current official UPayments V2 documentation names `https://uapi.upayments.com/api/v1/` for production. A1 deliberately does **not** adopt that provider-host change because it would be a runtime migration, not a structure-only extraction; any host migration requires separate provider-contract research, compatibility analysis, review and executable evidence;
- the resolver has no WordPress/WooCommerce/global-option dependency and performs no transport, authentication, payment-truth or payload work;
- `tests/harness/architecture-provider-endpoints-harness.php` freezes both modes, arbitrary route byte-equivalence, fixed endpoint routes, compatibility-wrapper behavior and dependency purity.

Why first:

- narrow and deterministic;
- duplicated provider host selection currently exists in several public gateway methods;
- pure logic is easy to characterize independently;
- wrappers preserve third-party compatibility;
- creates the first reusable `Provider` dependency without touching Charge/status state machines.

### A2 — payment-method availability client/cache

A1 is verified. A2 extracts the characterized availability client/cache coordination to `src/Provider/PaymentMethodAvailability.php` behind the existing public gateway entry point.

**A2 implementation contract:**

- `getUpayPaymentMethods()` remains public and preserves `null` for an empty API credential, existing success shapes, and the exact failure notice plus checkout redirect presentation;
- provider transport remains injected from the gateway's hardened `execute_upayments_request('check-payment-button-status', 'GET')` seam; the new service does not own Bearer authentication or generic HTTP;
- transient identity remains `upay_pm_v3_` plus the first 16 hexadecimal characters of `HMAC-SHA256(mode + "|" + API key, wp_salt('auth'))`;
- test/live result caches, advisory locks and durable cooldown options remain isolated exactly as characterized;
- advisory lock identity remains site + database prefix + blog ID + mode and contains no credential;
- the durable **65-second** gate is persisted and verified while the lock is held, before HTTP; the lock is released before outbound HTTP;
- lock contention/error, active cooldown, failed gate persistence and every malformed transport/provider shape fail closed without an unauthorized provider call;
- availability transport still requires `transport_ok === true`, HTTP **201**, cURL errno **0**, non-empty scalar JSON body, strict provider `status === true`, array `data`, and a normalizable `isWhiteLabel` value;
- the six known payment buttons retain strict boolean/0/1/string-0/string-1 normalization, missing known buttons default to zero, and malformed values fail closed;
- cached success remains exact canonical schema 3 with only `schema`, `result`, `isWhiteLabel` and the six known `payButtons`; cached failure remains exactly `{schema: 3, state: failure}`;
- fresh success continues to retain unknown top-level provider fields while replacing `payButtons` with only the normalized known set; cache hits return the canonical cache shape;
- `tests/harness/architecture-payment-method-availability-harness.php` freezes identity, locking, gate timing, cache schema, transport/provider mutation failures and gateway delegation, and is mandatory in Quality Gates.

### A3 — gateway settings/admin/multi-merchant presentation

A2 is verified. A3 separates the characterized gateway settings/admin/single-additional-merchant presentation boundary to `src/Admin/GatewaySettings.php` behind the existing public gateway entry points.

**A3 implementation contract:**

- `init_form_fields()`, `process_admin_options()`, `generate_multimerchant_repeater_html()`, `validate_multimerchant_repeater_field()` and `admin_enqueue_scripts()` remain public compatibility wrappers;
- protected option identity remains `woocommerce_upayments_settings`, and the exact 21 field keys/order/defaults remain unchanged;
- the five runtime allocation settings remain `iban_number`, `knet_charge`, `knet_charge_type`, `cc_charge` and `cc_charge_type`;
- subscription/save-card dependency normalization, missing API-key error, enabled-allocation completeness error and disabled-allocation null clearing remain byte-equivalent;
- stored allocation presentation values remain escaped in attribute context and the historical JSON-backed field sanitizer may retain only the five non-secret allocation fields;
- admin assets retain their current settings-page scopes, handles, paths, dependency/version/footer values and local inline disabled-row styling;
- the renderer presents one allocation row and does not expose merchant/API credentials or advertise arbitrary multi-split routing;
- runtime `process_payment()` remains in the gateway and retains exactly one additional `extraMerchantData` entry whose amount equals the order amount; provider payload, decimal validation, credential and payment-truth semantics do not move in A3;
- unused/historical routing helpers remain compatibility surfaces rather than authority to broaden the current feature claim;
- `tests/harness/architecture-gateway-settings-harness.php` freezes schema identity, validation, sanitation, escaping, admin-asset scope, delegation and the single-allocation boundary, and is mandatory in Quality Gates.

### A4 — subscription product/account presentation

Verified A3 permits A4. A4 moves only the characterized hook-heavy subscription product/admin/My Account presentation surface behind `src/Subscription/Composition.php` and `src/Subscription/Presentation.php`.

**A4 implementation contract:**

- `initializeSubscriptionModule()`, `render_subscription_summary()`, `restrictMixedCartProducts()` and `renderSubscriptionBadgeInProductList()` remain public gateway compatibility wrappers;
- named global product compatibility functions (`addCustomProductType`, `mapCustomProductClass`, `customProductTypes`, `addCustomDataTab`, `addCustomDataPanel`, `saveCustomFieldData`, `displayCustomFieldOnFrontend`, `displayCustomDataInCart`, `saveCustomDataToOrderItems`) remain callable wrappers;
- product identity remains `custom_type`, global class compatibility remains `WCProductCustomType`, and product metadata remains `_custom_field_id` with the existing nonce, posted-ID and `edit_post` authorization boundary;
- product/cart/order-item labels, account filter/columns/status output, subscription detail tables, manual pause/resume/unsubscribe POST forms and action-specific nonce identities remain characterized;
- the hardened customer subscription mutation handler remains outside the presentation module and retains exact ownership, UPayments-order, manual-subscription, transition and nonce preconditions;
- `includes/Subscription/Cron/Scheduler.php` and `CycleClaim.php` remain byte-identical; `upay_process_subscriptions`, `{$wpdb->prefix}upayments_billing_attempts`, `_upay_*`/`UPayments_*` metadata and no-blind-retry/dispatching semantics remain untouched;
- checkout Fields/Manager modules retain their existing public hooks/storage behavior and are initialized behind the public gateway composition seam;
- `process_payment()`, saved-card/token identity, Charge/auto-deduct provider transport, billing payloads and order/payment truth do not move in A4;
- `tests/harness/architecture-subscription-presentation-harness.php` freezes the full hook topology, product/admin schema, authorization, escaped presentation, compatibility delegation and protected scheduler blobs, and is mandatory in Quality Gates.

A5 remains prohibited until A4 is independently reviewed, exact-head green, merged, post-merge verified and cleaned up.

### A5 — checkout payload/orchestration core

Verified A4 permits A5. A5 moves the characterized strict request/decimal/payload helpers to `src/Payment/CheckoutPayload.php` and the exact checkout-to-Charge workflow to `src/Payment/CheckoutOrchestrator.php`.

**A5 implementation contract:**

- public `WC_Upayments::process_payment()` remains the WooCommerce compatibility entry point and directly composes the checkout orchestrator;
- protected `get_request_body_raw()` and `execute_upayments_request()` retain virtual dispatch through gateway-scoped closures, preserving H12 test and subclass seams without exposing new public transport methods;
- strict Classic/Blocks source parsing, subscription validation, exact decimal arithmetic, unquoted JSON-number sentinel injection, route classification, redirect validation and provider-text normalization live in the pure payload service;
- order/product validation, exact order-line economics, saved-card identity/provenance, single Charge dispatch, strict response handling and existing order metadata writes retain their original ordering in the orchestrator;
- no provider host, API key, route, payload field, payment truth, redirect rule, metadata identity, customer-token rule or no-blind-retry behavior is reinterpreted;
- scheduler, CycleClaim, billing-attempt storage and auto-deduct dispatch remain outside A5 and byte/behavior protected by the existing suites;
- `tests/harness/architecture-checkout-orchestration-harness.php` freezes the boundary and pure contracts, remains mandatory beside all prior architecture gates, and the full H12 PHP runtime continues to drive the public compatibility path.

The full automated quality platform became permitted only after the independently reviewed exact A5 head was green, merged, post-merge verified and cleaned up. That program is now DONE / VERIFIED through Q19; `QUALITY-PLATFORM.md` remains the permanent quality record and `PROJECT-STATUS.md` owns the current Enterprise Compatibility Certification state.

## Static/code-quality baseline sequence

Do not enable a tool by blindly failing the whole historical tree.

1. record current PHP compatibility (`Requires PHP: 7.2`) and WordPress/WooCommerce runtime assumptions;
2. introduce Composer only with an explicit distribution rule and no runtime dependency leakage;
3. add PHPCS/WPCS and PHPStan incrementally with a recorded baseline or initially scoped paths;
4. target newly extracted `src/` code first, then ratchet scope outward;
5. add dead-code/complexity findings only after runtime hook/reflection/callback reachability is characterized;
6. every suppression/baseline entry must identify why it exists and must not hide payment/security correctness findings.

## Discovery findings / debt register

Current evidence already shows:

- `UPayments.php` mixes bootstrap, gateway, provider, settings, UI, subscription and product responsibilities;
- several provider endpoint helpers duplicate test/live host selection;
- global hooks and anonymous closures make reachability harder to reason about than namespaced services;
- subscription responsibilities are split between `UPayments.php` and `includes/Subscription/`;
- H12 token identity remains a large protected legacy module and must not be opportunistically decomposed;
- payment truth is already better isolated in `src/Payment/` and should be treated as a model for strangler migration, not reopened;
- architecture modernization must distinguish genuinely dead code from public compatibility surfaces such as legacy gateway helper methods.

## Verified acceptance contract for the discovery tranche

This tranche is complete only when:

1. this responsibility/dependency map is reviewed against exact `main` source;
2. a permanent architecture-foundation harness protects the current gate, required module boundaries and compatibility identities;
3. the architecture harness is required in Quality Gates alongside every prior regression suite;
4. no production runtime behavior changes in the discovery PR;
5. exact PR head passes Governance, syntax and the complete regression platform;
6. independent review has no unresolved valid findings;
7. the discovery PR is squash-merged and post-merge verified before A1 begins.

## Non-claims

Architecture discovery does not certify code quality, eliminate technical debt, establish full static-analysis cleanliness, certify platform/features/performance, or authorize physical plugin/text-domain renames. It only freezes the decomposition contract and the safe order of work.

A1 likewise does not certify or change provider hosts, connectivity, credentials, request payloads, payment state, platform compatibility or production readiness. It extracts one deterministic endpoint-resolution seam behind the existing public API.
