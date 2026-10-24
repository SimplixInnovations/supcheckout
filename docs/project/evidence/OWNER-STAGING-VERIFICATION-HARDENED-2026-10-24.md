# Owner staging verification of the hardened package, 2026-10-24

This records the owner's verification of the hardened package on the My Organic World staging copy. The verification **passed**. It was the current executable gate from 2026-10-23; it is now **DONE / OWNER-VERIFIED** (2026-10-24). The current gate is the revamp information architecture and branding from the owner (Sub-project 2+).

The owner verified every fact below on 2026-10-24. No credentials, card data, customer data, order numbers, provider payment IDs, addresses or URLs are recorded here.

## Package under verification
- Source: `main` @ `f3e207d` (hardening batch 3, #223).
- Package: **233 files**. ZIP SHA-256 `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8`.
- Installed-tree fingerprint on staging: `f236d64d7655254cb4b77c48a9d240020aa9f5184c51d6ba3432689722cb172d`.
- The owner confirmed both values.
- The package is **not owner accepted** as a release. This record verifies the hardened package on staging only. It does not accept it as a release candidate.

## Setup checks
- Every check passed except the new `currency_decimals` check. It warned that AED, SAR and QAR were set to 3 decimals but allow 2.
  - The owner set all three to 2 decimals. The base and the currency separators are `.` for decimals and `,` for thousands.
  - `currency_decimals` then passed.
- Other results:

| Check | Result |
|---|---|
| credential | pass (hosted mode, test mode) |
| `account_mode` | pass (`multimerchant` false) |
| currency | pass (KWD) |
| `callback_reachable` | pass |
| `cache_exclusion` | pass |
| `cache_layers` | pass |
| multilingual | pass (6 currencies, gateway strings checked) |
| `test_payment` | deferred (driven from the setup screen) |

## Checkout
- Verified in English and Arabic, each in KWD, AED and OMR.
- The Arabic gateway text and the RTL description display correctly.
- Prices use WooCommerce formatting.
- The Arabic decimal separator: the owner also corrected it in WPML String Translation (`woocommerce_price_decimal_sep`), which had been translated as `,`.

## Sandbox payments
- An Arabic KWD order opened the UPayments hosted page in Arabic.
- An English AED order opened the hosted page in English.
- Both orders ended Processing with Payment Status CAPTURED and the provider payment ID stored. The amounts matched, and the order notes were clean.
- Order numbers and payment IDs are deliberately not recorded.

## Admin
- The API key field is write-only: it is empty, with a "saved" note. Saving without a key kept the stored key, and checkout still worked.
- The Enable Subscriptions description states that automatic renewals are paused.
- Activating the official UPayments plugin on the finalized store was refused, with the "switch is final" notice. Detection worked for a non-standard folder name.

## Early-loading check
Staging has no `debug.log` (logging is off), so the early-loading behavior was not observable there. It is covered by the CI early-loading test (with canary) in all 20 Compatibility cells.

## Owner answers on settings
- Use New Design is off. The classic box stays until the revamp replaces it.
- Save Card is unavailable because the account is hosted, not white-label.
- Multimerchant is not used (single merchant).

## Still owner-side, not blocking
- Confirm whether staging email is blocked on purpose. The order notes show "failed to send".
- native Arabic review of the bundled translations.
- Send the UPayments support questions.
- FunnelKit one-click upsell is unsupported. This is a feature request, not a defect.

## Effect on the program
- **Owner staging verification of the hardened package — DONE / OWNER-VERIFIED (2026-10-24).**
- New current executable gate: **Revamp information architecture and branding from the owner (Sub-project 2+) — NEXT (owner input)**. Per the owner decision of 2026-10-20, the hardening program comes before any revamp information architecture or branding. The owner shares those next.
- The release gate (external certification & release readiness) stays open.
- Publication remains **NOT AUTHORIZED** (`publication_authorized: false`).
- Automatic recurring `VERIFIED_SUCCESS` remains FAIL-CLOSED.
