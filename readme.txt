=== SUPCheckout for UPayments ===
Tags: woocommerce, payments, payment gateway, upayments
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit/

WooCommerce payment gateway integration for UPayments.

== Description ==

**SUPCheckout for UPayments** connects WooCommerce checkout to the UPayments payment service.

SUPCheckout is developed and maintained by Simplix Innovations. UPayments is the external payment provider used by the integration; its names and trademarks remain the property of their respective owners.

= Core capabilities =

* WooCommerce Classic checkout registration.
* Cart / Checkout Blocks registration and availability.
* HPOS and legacy order-storage support in the documented compatibility matrix.
* Authenticated provider-status verification before financial order-state transitions.
* Saved-card/token provenance and eligibility safeguards.
* Subscription eligibility and pre-dispatch safeguards.
* One additional-merchant allocation boundary.
* Deterministic release packaging and official WordPress Plugin Check against the packaged artifact.

Payment-method and wallet availability depends on the merchant's UPayments account, provider configuration, plugin settings, checkout context and device/account eligibility.

Automatic WooCommerce refunds and arbitrary marketplace multi-split routing are not supported by the current feature boundary. Live subscription auto-deduction requires separately validated provider/account setup and is not broadly certified by repository CI.

= External service =

SUPCheckout communicates with UPayments APIs to initialize and verify payment operations and, where enabled and supported by the merchant account, related payment features.

Depending on the operation, information sent to UPayments may include order, payment, customer and provider-token data required to process or verify the transaction. A UPayments merchant account and API credentials are required for production use.

* UPayments developer documentation: https://developers.upayments.com/reference/overview
* UPayments terms of service: https://upayments.com/en/terms-of-service

Use UPayments sandbox/test mode for initial validation. Do not place production credentials in test environments, issue reports or public logs.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate SUPCheckout for UPayments.
3. Open WooCommerce payment settings and configure the UPayments gateway.
4. Validate checkout using UPayments sandbox/test mode.
5. Confirm the merchant account is enabled for each payment method or wallet you intend to offer before enabling production transactions.

== Frequently Asked Questions ==

= Does SUPCheckout process payments itself? =

No. SUPCheckout is the WooCommerce integration layer. Payment services are provided by UPayments.

= Who maintains SUPCheckout? =

Simplix Innovations develops and maintains SUPCheckout. UPayments remains responsible for its payment platform, merchant accounts, settlement, commercial services and provider policies.

= Does every UPayments payment method automatically become available? =

No. Availability depends on the merchant's UPayments account, provider configuration, plugin settings, checkout context and account/device eligibility.

= Does SUPCheckout support WooCommerce Blocks and HPOS? =

Yes within the documented certified matrix. Repository certification includes Cart / Checkout Blocks registration/availability plus HPOS and legacy order storage in exact WordPress/WooCommerce/PHP cells.

= Are automatic WooCommerce refunds supported? =

No. Automatic WooCommerce refunds are outside the current supported feature boundary.

= Does SUPCheckout support marketplace split payments? =

The current verified boundary supports one additional merchant allocation. Arbitrary marketplace multi-split routing is not supported.

= Does SUPCheckout support subscription auto-deduction? =

Subscription eligibility and pre-dispatch behavior are covered by repository tests. Live non-idempotent auto-deduction requires separately validated UPayments provider/account setup and is not claimed as broadly certified by repository CI.

= Is WPML, WCML, multicurrency or RTL certified? =

Not currently. Those environments require separate real-world validation before compatibility is claimed.

== Privacy ==

SUPCheckout itself does not add an independent analytics or advertising service. Payment-related data is sent to UPayments only as required for enabled payment operations. Merchants remain responsible for configuring their store, privacy notices and UPayments account in accordance with applicable requirements.

Do not expose merchant credentials, card data, customer tokens, private webhook payloads or unnecessary personal data in logs or support requests.

== Source code and build ==

The admin interface JavaScript and CSS in `assets/admin/build/` are compiled from the human-readable TypeScript and CSS source shipped in `assets/admin/src/`. The full source, including the build tooling, is public at https://github.com/SimplixInnovations/supcheckout.

To rebuild, use the Node.js version in `.nvmrc` and run `npm ci && npm run build` in a clone of the repository.

== Changelog ==

= 0.1.0 =
* Development-line SUPCheckout identity and release-engineering closeout.
* Canonical package/text-domain identity: `supcheckout`.
* Classic and Blocks registration plus HPOS/legacy order-storage certification matrix.
* Deterministic package verification, historical package-root migration/rollback qualification, hardened WordPress HTTP transport and permanent official WordPress Plugin Check gating.
* Historical UPayments payment/settings/token/subscription identifiers retained where required for merchant compatibility.
