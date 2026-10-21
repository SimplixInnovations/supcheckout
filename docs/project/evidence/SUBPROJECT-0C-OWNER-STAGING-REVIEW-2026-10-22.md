# Sub-project 0c owner staging review, 2026-10-22

This records the owner's review of the Sub-project 0c onboarding backend on the My Organic World staging copy. The review **passed**. It was the Sub-project 0c exit gate, then (from 2026-10-21) a non-blocking owner action; it is now **DONE / OWNER-VERIFIED**.

The owner verified every fact below on 2026-10-22. No credentials, card data, customer data, order numbers, provider payment IDs or addresses are recorded here.

## Package under review
- Source: `main` @ `1882254` (the Sub-project 1 closeout, PR #212).
- Package: **224 files**. ZIP SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4`.
- Installed-tree fingerprint on staging: `f4ce1a3a942295123ec023bb1227197af8f97685a77c0714ada214907f7deec9`.

## Setup checks
- `GET supcheckout/v1/onboarding/status` reported the takeover as `finalized`. That is the result of the earlier takeover rehearsal ([evidence](TAKEOVER-REHEARSAL-2026-10-18.md)).
- `POST /setup/checks` started an operation. The Action Scheduler async runner then completed it on admin requests, even though WP-Cron is disabled on staging. A manual `wp action-scheduler run --hooks=supcheckout_onboarding_drive` found 0 pending actions. No stall warning appeared, because there was no stall.
- The `GET /setup/checks` report passed all seven checks it runs; the optional sandbox test payment is deferred to the setup screen:

| Check | Result |
|---|---|
| credential | pass |
| `account_mode` | pass: mode `hosted` (non-white-label); methods knet, credit_card, apple_pay_knet, apple_pay, samsung_pay and google_pay; `test_mode` true; `multimerchant` false |
| currency | pass (KWD) |
| `callback_reachable` | pass |
| `cache_exclusion` | pass |
| `cache_layers` | pass: no cache plugins, `cloudflare_proxied` false |
| multilingual | pass: WPML, WCML and multi-currency active; currencies KWD, AED, OMR, SAR, QAR and BHD, with `gateways_enabled` false for each and `currencies_truncated` false |
| `test_payment` | deferred (`test_payment_ui_driven`) |

## Follow-ups from the takeover rehearsal
- **Follow-up 3 (WCML per-currency visibility): PASSED.** On the checkout, UPayments is visible in AED, KWD and OMR, in both English and Arabic. That matches the `multilingual` check, which found no per-currency gateway restriction.
- **Follow-up 2 (automatic LiteSpeed purge on a REST switch or rollback): not repeatable on this staging copy.** The takeover there is finalized, and LiteSpeed Cache is not active (`cache_layers` reports no plugins).
  - Ruling: the purge is covered by the real-plugin CI cell, `tests/integration/CachePurgeRuntimeTest.php` with LiteSpeed Cache 7.9.1 in `.github/workflows/ecosystem-certification.yml`.
  - Confirming the purge on the live store's real takeover moves to the owner manual test list (external certification): item 7 in the [Sub-project 1 evidence](SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md#owner-manual-test-list).

## Sandbox order
A sandbox card order in AED completed as Processing, with Payment Status CAPTURED and the provider payment ID shown on the order.

## Owner acknowledgements, 2026-10-22
The owner accepted the recommended option for every pending item. Each is **acknowledged by the owner 2026-10-22**.
- Sub-project 0c:
  - the paused-surface amendment (the lazy `action_scheduler_before_process_queue` hook), `docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md` decision 6;
  - the gate-wording update.
- Sub-project 1: the eight items in the [Sub-project 1 evidence](SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md#owner-acknowledgements).

## What stays open
- The current executable gate is unchanged: **Hardening program: full functionality, security and performance audit and fix loop — NEXT**.
- External certification and release readiness stay open, and publication stays NOT AUTHORIZED. Two items are added to the owner manual test list: the live cutover purge, and the store configuration advice to use '.' as the decimal separator and AED at 2 decimals (because "1,500 KWD" reads as thousands).
