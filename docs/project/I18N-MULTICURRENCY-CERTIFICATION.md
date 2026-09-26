# I18N / Multicurrency Certification

## WPML / WCML

**Status:** EXTERNAL REQUIRED — LICENSED ENVIRONMENT NEEDED

Cover when legally licensed packages + staging exist:

- English
- Arabic
- translated checkout
- translated account/order-received
- language switch before checkout
- callback/return landing
- no duplicated payment action
- metadata unaffected by locale

## Multicurrency

For each supported plugin/config:

- display currency
- order currency
- provider-supported settlement/request currency
- exact amount binding
- callback/status currency binding
- switcher before/after cart
- rounding

Never silently convert amounts inside SUPCheckout unless already part of the accepted contract.

## Arabic/RTL (already VERIFIED — BOUNDED)

Default Woo/theme WordPress `ar` locale RTL checkout was verified in R6 browser/RTL job. Commercial RTL themes remain `EXTERNAL REQUIRED`.
