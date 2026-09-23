# SUPCheckout Post-T3 Ecosystem Hardening Plan

**Date:** 2026-09-10
**Repository:** `SimplixInnovations/supcheckout`
**Base:** `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`
**Base state:** Approach 3 T3 merged; public publication remains unauthorized

## Purpose

This plan extends the approved post-T3 remediation program after a fresh source/provider/WooCommerce ecosystem audit. The objective is not to claim compatibility from generic WooCommerce API use. The objective is to make payment authority independent of theme/checkout presentation assumptions, bind payment to finalized WooCommerce order economics, and establish permanent regression evidence for the classes of extensions that materially change checkout state.

No tranche below authorizes a public tag, GitHub Release, WordPress.org publication, automatic refund support, arbitrary multi-split routing, or live subscription auto-deduction by itself.

## Permanent safety invariants

1. Browser, AJAX, checkout, return and webhook data are routing/selection input only; they are never financial truth.
2. A successful Charge initialization is not proof of capture.
3. Captured payment state requires authenticated provider status evidence and exact binding to the locally authorized payment attempt.
4. The finalized WooCommerce order is the accounting source of truth for amount/currency. Optional provider product descriptors are not a parallel accounting ledger.
5. Shipping, tax, VAT, fee, coupon, discount, order-bump and product-composition changes must be finalized before provider Charge authorization; post-dispatch economic mutation must never be silently accepted.
6. No non-idempotent financial mutation may be blindly retried after an ambiguous result.
7. Customer/card identity ambiguity fails closed.
8. Protected persisted/provider/public compatibility identifiers remain unchanged unless a separately approved migration proves upgrade and rollback semantics.
9. Checkout compatibility must be tested by behavioral contract first and named third-party products second.
10. Every runtime-bearing correction is test-first and receives exact-head full-stack re-certification before merge.

## Findings driving this program

### Financial / recurring-payment blockers

- Auto-deduct success responses are not yet rebound to the exact requested amount, currency and provider/local identity before renewal completion.
- When the original card token is unavailable, the scheduler can select the first returned saved card without a documented consent/default-card contract.
- The scheduler discovers only `completed` parent orders, while a valid paid subscription can remain `processing`.
- Ambiguous auto-deduct cycles are safely held but lack a complete reconciliation/recovery workflow.
- Automatic subscriptions lack an equivalent customer self-service cancel/pause/resume path.
- Current provider documentation and local customer/card token persistence are in unresolved tension and require first-party clarification.

### Interactive checkout / AJAX blockers

- Local gateway eligibility is enforced primarily by `woocommerce_available_payment_gateways`; the filter returns early during `is_admin()`, so `admin-ajax.php` custom checkout requests can observe different availability from normal checkout.
- `WC_Upayments` does not own a deterministic `is_available()` boundary for API key + supported currency + enabled state.
- The Classic modern presentation hides `#place_order` and invokes `$('form.checkout').submit()` from custom buttons. This assumes stock form/button selectors and can bypass third-party logic attached specifically to the native Place Order click path.
- Classic interaction/subscription scripts are enqueued only when `is_checkout()` is true. Embedded WooCommerce checkout forms, including supported funnel/page-builder patterns, can render the gateway without loading `supCheckout`.
- Blocks payment-method data snapshots cart total and product-type state once, while shipping/address/coupon/tax/fee totals are reactive Store API state.
- Availability failure handling calls `wc_clear_notices()`, which can erase shipping/address/coupon/tax/custom-checkout validation notices owned by other extensions.
- Gateway-level order-total display hooks are not fully isolated to orders paid through UPayments.

### Economic composition blockers

- Provider `products[]` is optional, but the current checkout rejects the whole payment when a descriptive line cannot be converted to an exact provider unit price. Example: a legitimate quantity-3 discounted line total of `10.00` is non-terminating per-unit decimal and currently becomes a payment failure.
- This makes optional product descriptors a veto over coupons, dynamic pricing, add-ons, bundles, measurements and similar extensions even when the authoritative order grand total is valid.
- A third party that mutates persisted order economics after Charge initialization but before callback verification will correctly fail status binding, potentially leaving a provider-charged order unresolved. That safe failure mode needs an explicit immutable-attempt economics contract and merchant reconciliation path.

### Cache/CDN/analytics boundaries

- Dynamic commerce endpoints and WooCommerce session responses must never be page-cached. Cloudflare APO normally bypasses WooCommerce cookies/query parameters, but custom Cache Rules can override safe origin behavior.
- Rocket Loader / delay-JS optimizers can reorder an inline button's dependency on externally loaded `supCheckout` code.
- Replayed browser callbacks are financially idempotent but can redirect to order-received repeatedly, allowing browser-side purchase pixels that do not self-dedupe to fire again.
- Callback/public-status responses need an explicit no-cache contract rather than reliance on external cache heuristics.

## Program sequence

### R0 — control-plane reconciliation

Runtime-neutral.

- Record T3 merge `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9` as the latest merged Approach 3 main coordinate; the latest runtime-bearing merged main remains T2 `047cc86060efb97761d7a0cc4a3806f971ab6fe1` until another runtime-bearing tranche is merged.
- Reconcile `START-HERE.md`, `PROJECT-STATUS.md`, `NEW-CHAT-HANDOFF.md`, `.ai-architect/implementation-plan.md`, `.ai-architect/architecture-contract.yaml` and other living authorities that still say T3 is next.
- Add a governance ratchet that rejects contradictory Approach-3 current-state coordinates.
- Keep accepted Approach-2 baseline coordinates intact as regression reference; do not rewrite historical evidence.
- Remove stale temporary branches when a supported branch-delete operation is available.

### R1 — bounded local correctness

Runtime-bearing, test-first, no provider protocol change.

- Fix checkbox normalization to use exact WooCommerce `'yes'` / `'no'` semantics.
- Reject incomplete enabled multi-merchant settings without persisting the malformed configuration.
- Remove global notice clearing from payment-method availability failure.
- Scope order-total presentation to UPayments orders only.
- Correct gateway title initialization and loose boolean/string presentation logic if real-runtime characterization confirms the source-level defect.
- Stop globally loading gateway CSS when the current request cannot render any SUPCheckout surface.
- Add focused accessibility fixes for labels, decorative icons and asynchronous status announcements.

### E1 — interactive checkout compatibility

Runtime-bearing, test-first.

Behavioral contracts:

1. **Gateway eligibility parity** — normal Classic, Store API, `wc-ajax`, `admin-ajax.php`, REST/admin and sessionless contexts must agree on deterministic enabled/API-key/currency eligibility and must never fatal when Woo session is absent.
2. **Checkout rendering independence** — a Woo checkout form embedded outside the canonical checkout page must receive every asset required by the rendered SUPCheckout controls.
3. **Native submit lifecycle** — SUPCheckout payment-source selection must coexist with WooCommerce validation, CAPTCHA, consent, checkout-field validation, order bumps and custom checkout hooks; selection must not depend on bypassing the native checkout submission contract.
4. **AJAX fragment replacement** — repeated `updated_checkout`/fragment replacements may not duplicate handlers, lose selected source/card state incorrectly, leave a hidden native submit button stranded, or retain stale subscription controls.
5. **Blocks reactivity** — `canMakePayment` and visible total/context must consume live Store API state where the value can change after initial registration.
6. **Third-party notice isolation** — provider availability failures may not erase notices from shipping, tax, address, coupon, VAT, fraud, age/consent or funnel extensions.
7. **Session isolation** — availability filters may not write session state in REST/admin/non-checkout paths merely because the gateway registry is inspected.
8. **No duplicate registration** — Classic/Blocks/custom checkout rendering must not register duplicate gateway handlers or duplicate DOM IDs after fragment refreshes.

Named qualification fixtures after behavioral contracts pass:

- FunnelKit Checkout: global replacement, product-specific checkout, embedded shortcode/page-builder checkout and pre-checkout/order-bump path.
- CartFlows: checkout, pre-checkout offer and order bump. One-click upsell/downsell remains unsupported until an explicit gateway integration is designed.
- CheckoutWC.
- Fluid Checkout.
- Native Classic checkout and native Cart/Checkout Blocks.

### E2 — economic composition and product compatibility

Runtime-bearing, test-first.

#### Accounting contract

- Charge amount/currency come from the finalized persisted Woo order.
- Shipping, shipping tax, line tax, order tax, fees, fee tax, coupon discounts and order-level adjustments are represented only through the authoritative Woo order grand total for payment authority.
- `products[]` is optional descriptive data. Failure to represent one or more products exactly must degrade to a provider-valid payload without `products[]`; it must never change or round the order amount.
- A product descriptor must never fabricate unit economics to make a payload pass.
- Zero-grand-total Woo orders must remain outside gateway Charge processing.

#### Core product fixtures

- simple product;
- multiple simple products;
- variable product / variation;
- virtual product;
- downloadable product;
- grouped-product purchase (child purchasable lines, no fabricated parent economics);
- zero-price promotional line combined with positive order total;
- decimal quantity rejection where Woo/provider contract requires integer quantities;
- proprietary `custom_type` subscription product;
- mixed proprietary subscription/normal products remain rejected by current product boundary.

#### Extension-generated composition fixtures

- coupons, including fixed-cart/fixed-product/percentage and 100% line discount with nonzero shipping/tax where Woo still requires payment;
- dynamic pricing / quantity discounts where line total is not exactly divisible by quantity;
- product add-ons/custom option charges;
- Product Bundles parent/child line structures;
- Composite Products;
- Mix and Match;
- Measurement Price Calculator / nontrivial captured line economics;
- gift card/store credit partial redemption;
- deposits/partial-payment initial order only, with later payment plans unsupported until separately integrated;
- checkout/order fees and payment/shipping-method fees;
- negative/discount-style fee scenarios only where WooCommerce itself produces a valid payable order.

#### Shipping fixtures

- no shipping / all-virtual;
- core flat rate/free shipping/local pickup;
- multiple packages and split shipping;
- address-dependent live rates;
- table-rate shipping;
- rate recalculation after country/state/postcode changes;
- shipping method change immediately before Place Order;
- shipping taxes and mixed tax classes;
- unavailable/rate-error state must prevent checkout before Charge.

Named external qualification candidates include official UPS/FedEx/live-rate extensions and WooCommerce Table Rate Shipping, subject to license/runtime availability.

#### Tax/VAT fixtures

- prices inclusive and exclusive of tax;
- standard/reduced/zero tax classes;
- shipping tax;
- address-driven tax change during checkout;
- tax-exempt customer;
- valid VAT number exemption/reverse-charge style adjustment;
- invalid VAT result;
- automated tax providers such as WooCommerce Tax/Avalara/TaxJar subject to credentials/licenses.

### E3 — themes, caching, optimization and analytics

Primarily runtime certification; only bounded source changes where behavioral tests prove a defect.

#### Theme matrix

- Storefront or equivalent minimal Classic baseline;
- current default WordPress block theme;
- Astra checkout modes;
- WoodMart Checkout Builder;
- Flatsome;
- Porto;
- Blocksy;
- Kadence / Woo checkout enhancements;
- child-theme override of Woo checkout/payment templates.

Do not infer compatibility from visual rendering alone. Verify payment selection, AJAX refresh, keyboard/focus behavior, error recovery and final order economics.

#### Cache/CDN/optimizer matrix

- Cloudflare APO default WooCommerce behavior;
- Cloudflare custom Cache Rule misconfiguration detection/documentation;
- WP Rocket;
- LiteSpeed Cache;
- W3 Total Cache;
- SG Optimizer;
- Breeze;
- host-level full-page cache where available.

Dynamic exclusions to certify/document include cart, checkout, My Account, order-pay/order-received, `wc-ajax`, `wc-api=wc_upayments`, Store API checkout/cart requests, and WooCommerce session cookies. Callback/status endpoints must send explicit no-cache headers where feasible.

JavaScript optimization fixtures:

- defer;
- delay until interaction;
- minify/combine;
- Cloudflare Rocket Loader;
- repeated AJAX fragment replacement.

#### Pixels/analytics

- WooCommerce Google Analytics / GA4;
- Google Analytics Pro behavior;
- Meta/Conversions API where relevant;
- GTM/DataLayer plugins with and without built-in order/event deduplication.

SUPCheckout must not promise analytics deduplication it does not own. The payment return contract should nevertheless avoid creating unnecessary repeated confirmation navigations and should expose a stable order/payment identity that downstream trackers can dedupe.

### R2 — callback portability and cache safety

Payment-critical, test-first.

- Replace hand-built `site_url()` WC-API callbacks with WooCommerce's public API URL abstraction.
- Verify `home_url != site_url`, WordPress subdirectory, plain/index/permalink layouts and HTTPS/proxy configurations.
- Add explicit no-cache response behavior to public payment callback/status surfaces without weakening redirects or provider callback handling.
- Verify reverse-proxy forwarded HTTPS/public-origin behavior without trusting unvalidated client headers.

### R3 — subscription safety redesign

Payment-critical, independent reviewed tranche.

- Dedicated auto-deduct response parser/verifier.
- Exact amount/currency/parent/cycle/provider identity binding before renewal creation/completion.
- No first-card fallback. Missing/revoked authorized card becomes a held/re-authorization state.
- Parent discovery independent of `completed`-only status.
- Customer stop/cancel/pause/resume policy for automatic renewals.
- Explicit provider-confirmed token retention/storage contract.
- Complete held-cycle reconciliation with no blind replay of non-idempotent requests.
- Immutable billing-cycle economic snapshot.

### R4 — subscription scalability

- Replace full historical-order hourly scanning with due-work scheduling.
- Prefer WooCommerce Action Scheduler with grouped, traceable actions and bounded batches.
- Preserve the durable cycle journal as the non-idempotent mutation authority; Action Scheduler is dispatch orchestration, not an idempotency substitute.
- Add queue health/held-cycle observability and representative large-store benchmarks.

### R5 — Approach 3 T4 callback consolidation

Only after T3 characterization and E1/E2/R2 evidence are stable.

- Preserve public compatibility method/hook identities as adapters where required.
- Remove independent legacy financial semantics.
- Route all ordinary callback/status mutation through one canonical authenticated lifecycle.
- Preserve exact historical persisted identities unless a migration is separately approved.

### R6 — release qualification

- Full exact-head quality, H12, compatibility, deterministic release, Plugin Check and CodeQL gates.
- Representative concurrency/load/failure-injection testing.
- Browser/device/theme/custom-checkout matrix.
- Wallet/device/account manual evidence where provider sandbox cannot reproduce it.
- Multilingual/RTL/WPML/WCML/multicurrency qualification where claimed.
- External penetration/PCI/legal/compliance evidence remains external organizational evidence.
- Explicit owner version/tag/release/publication decision only after the evidence is complete.

## Explicit unsupported/unverified integrations until separately qualified

- FunnelKit one-click post-purchase upsells with UPayments.
- CartFlows one-click post-purchase upsells/downsells with UPayments.
- Automatic WooCommerce gateway refunds.
- Arbitrary marketplace multi-split routing.
- WooCommerce Subscriptions automatic-renewal gateway API compatibility: SUPCheckout's proprietary subscription product/auto-deduct flow is not the WooCommerce Subscriptions gateway contract.
- Any third-party subscription plugin's automatic recurring-payment integration unless explicitly implemented and certified.

## Test architecture

Each source correction should add the narrowest permanent test that would have failed before the fix. The ecosystem layer then adds reusable behavioral fixtures rather than hardcoding vendor internals.

Required permanent harness categories:

- `ecosystem-checkout-context` — Classic/Store API/wc-ajax/admin-ajax/sessionless eligibility and asset contract;
- `ecosystem-notice-isolation` — foreign notices survive UPayments availability failures;
- `ecosystem-order-economics` — final total across shipping/tax/fees/coupons/dynamic lines;
- `ecosystem-product-descriptors` — optional products degrade safely when exact unit representation is impossible;
- `ecosystem-callback-cache` — callback/status response cache headers and public URL generation;
- `ecosystem-post-dispatch-mutation` — changed persisted economics after Charge never produce false capture;
- `ecosystem-analytics-return` — replay behavior is characterized so confirmation/pixel duplication risk cannot change accidentally;
- named real-runtime matrix jobs when third-party packages/licenses can be legally installed in CI.

## Completion rule

No tranche is DONE merely because source looks correct. A runtime-bearing tranche requires:

1. observed failing regression test before the fix;
2. minimal implementation;
3. focused tests green;
4. complete existing regression stack green;
5. exact-head PR checks green;
6. review findings resolved;
7. squash merge with expected-head protection;
8. fresh merged-main verification;
9. living-state reconciliation;
10. temporary branch cleanup where supported.

External paid-plugin/theme/provider tests that cannot legally or technically run in repository CI remain explicit manual/external evidence, never silently promoted to certified compatibility.
