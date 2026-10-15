# Staging Rehearsal — 2026-10-15

**Runbook:** [`../STAGING-FROM-PRODUCTION.md`](../STAGING-FROM-PRODUCTION.md)
**Source store:** `myorganicworld.net` (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.5.10, HPOS on with sync off, KWD plus WCML multicurrency, WPML, FunnelKit checkout, Elessi child theme, OpenLiteSpeed 1.9.2)
**Staging copy:** `staging.myorganicworld.net` (ServerAvatar clone, app `DELETEMyOW`, separate database)
**Operator:** owner (Simplix Innovations)

No credential, customer data or payment link appears in this file. Order numbers and provider references below come from the sanitized copy.

## Steps 0–4: neutralize and sanitize — PASS

| Step | Result |
|---|---|
| 0 Backup | WPvivid full backup of production taken first |
| 1 Clone | New application and database; the staging domain resolves to the same server |
| 2 Before first request | `object-cache.php` deleted. `wp-config.php`: `WP_ENVIRONMENT_TYPE=staging`, `SUPCHECKOUT_STAGING_GUARD`, `DISABLE_WP_CRON`, a staging-only `WP_CACHE_KEY_SALT` (replacing a copied one) and staging `WP_HOME`/`WP_SITEURL`. Guard installed as a must-use plugin. No cron job referenced staging; the production cron job was left untouched. |
| 3 WP-CLI checks | home = staging URL; `staging guard no-object-cache`; live UPayments blocked (`WP_Error`); side-effect plugins off. **ZeptoMail was still active** under its folder name `transmail`; it was stopped with `SUPCHECKOUT_STAGING_DISABLE_PLUGINS='transmail'`, and the guard was fixed to recognize it by default (PR #139). |
| 4 Sanitizer | Files byte-identical to `main`. Dry run, then apply: 913 orders, 593 users, 270 lookup rows, 4,439 comments, 20,765 data rows, 3 gateway secret fields, 21 pending actions. The second dry run showed every personal-data and secret count at 0. The one remaining cron entry was the official plugin's self-recreating `upay_hourly_cron_job`, which the sanitizer now skips by design (PR #139). |

## HTTPS on staging

ServerAvatar's HTTP-01 certificate install failed. DNS was correct (A record matches, no AAAA or CAA), and files in the site root were served, but **every path under `/.well-known/` returned OpenLiteSpeed's 404** for this app, even with a dedicated `context /.well-known/acme-challenge/` added. The cause sits in the hosting stack and was not pursued further.

Workaround: certbot **DNS-01** (`certbot certonly --manual --preferred-challenges dns -d staging.myorganicworld.net --cert-name DELETEMyOW`). The certificate is valid until 2027-01-12 and does not renew automatically. HTTPS was then enabled by restoring ServerAvatar's own `DELETEMyOW.conf0`, which already points at that certificate, behind a guard: OpenLiteSpeed config problem counts before and after, with restart only if the count did not grow. Result: `HTTP 200`, `ssl_verify=0`.

## Step 5: baseline on official UPayments 3.1.1 (before the swap)

### Gateway settings

| Setting | Value |
|---|---|
| Active | on |
| Default gateway | off |
| Title | `UPayments` (WPML-translatable) |
| Description | `We accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.` |
| Api Key | empty (sanitized) |
| Debug | off |
| Test Mode | on (forced by the sanitizer) |
| Show paid orders as "Completed" | off |
| Use New Design | off (classic) |
| Enable Save Card | off (disabled) |
| Multimerchant | off |
| Subscriptions | off |
| WCML country availability | Kuwait, Oman, Qatar, Saudi Arabia, UAE, Bahrain only |

The store also runs a second gateway, **Direct Link Payment (Pay Later)**.

### Historical orders (official plugin metadata)

| Order | Status | `UPayments_Result` | Type | Language | Notes |
|---|---|---|---|---|---|
| #11565 | Processing | CAPTURED | knet | en | fresh paid order; matches "Completed" off |
| #11409 | Completed | CAPTURED | knet | ar | paid; later completed by staff |
| #11009 | Failed | NOT CAPTURED | knet | ar | provider decline |
| #10311 | Cancelled | (none) | — | en | abandoned: only `UPayments_order_id` |
| #11010 | Completed ("Paid on Aug 8, 2026") | **FAILED** | card | en | **Real payment; metadata overwritten.** See the analysis below. |
| #9337 | Completed | — | Direct Link Payment | en | non-UPayments control |

### #11010 analysis (live order notes, 2026-08-08)

| Time | Live order note |
|---|---|
| 16:16 | "Received error response from UPayments. PaymentID 101622…8352": Pending → Failed |
| 16:17 | Stock hold applied (customer retried checkout) |
| 16:20 | "Payment successful with UPayments. PaymentID 786195…": Failed → Processing |
| 16:59 | Staff bulk edit: Processing → Completed |

The customer paid once (16:20). The order's stored `UPayments_*` fields, however, describe a **third** transaction (card, `FAILED`, PaymentID `019fe186…`). It matches neither noted attempt and left no note. A later failed callback for another attempt **silently overwrote the paid order's payment data** in official UPayments 3.1.1. The real PaymentID now survives only in an order note, so refunds or reconciliation read from the order fields would use the wrong ID.

SUPCheckout's design prevents this. A verified capture short-circuits the primary path. Any other track on a paid order goes to the capture-only secondary path, where non-captured results never change the order and a second capture only adds one duplicate-capture note (PR #133). Rehearsal check: **after a successful payment, a failed callback for a different attempt must leave the order's status and payment fields unchanged.**

Metadata written by the official plugin on paid and failed orders: `UPayments_order_id`, `_PaymentID`, `_TrackID`, `_TranID`, `_Ref`, `_Auth`, `_PostDate`, `_Result`, `_payment_type`, `_webhook_triggered`.

### Expectations after the swap to SUPCheckout

- Every order above keeps its status, and its UPayments details stay visible on the order page.
- SUPCheckout never re-verifies or changes a historical order.
- #9337 shows no UPayments box or rows.
- Gateway settings (title, description, design, the "Completed" preference, WCML country restriction) carry over unchanged.
- Arabic labels are checked separately, because the plugin's text domain changes.

## Step 6: SUPCheckout against the UPayments sandbox

Sandbox key `jtest123`, KNET test card `8888880000000001`. Expiry `09/30` captures, and any other expiry is not captured. The staging guard blocks all email, so every "Email … failed to send" order note below is expected.

### Before PR #141 (package from `main` `a9a7da9`)

| Order | Scenario | Result |
|---|---|---|
| #11577 | KNET success | Order paid correctly, but the browser **landed on the home page**. UPayments glues its return query with `?`: `wc_order_id=11577?payment_id=…`. The order ID did not parse, so the plugin fell back to the neutral redirect. Fixed by PR #141. |
| #11578 | KNET success in SAR (WCML multicurrency) | Verified amount and currency bound to the finalized SAR order total. Paid once. |

### After PR #141: package `0b475e52a5c338f4e0c36d29de5dffc431599a986c9c530165a3d40028543808` (62 files, `main` `d8a9554`)

Installed-tree fingerprint on staging (database `Delete_MYOW_DB25`), sorted `sha256sum` of all 62 files: `e4b213fe1efc69b4514191b5b90133b4cb9718b22d5231eb042670e5e79e3f5c`. It matches the extracted verified ZIP.

| # | Order | Scenario | Landing page | Order result |
|---|---|---|---|---|
| 1 | #11580 | KNET success | FunnelKit thank-you page for #11580 | Processing. `CAPTURED`, PaymentID `100628810000039116` is the transaction ID. Stock reduced once (3→2). FunnelKit upsell skipped cleanly. |
| 2 | #11581 | KNET decline (expiry not `09/30`) | `/checkout/order-pay/11581/` with "Your payment was not completed…" | Failed, from the authenticated provider status. No payment ID, no paid date, stock not reduced. |
| 3 | #11582 | **Cancel** button on the KNET sandbox page | `/checkout/order-pay/11582/` with the same notice | Failed. The provider reports a KNET cancel as `NOT CAPTURED`, not `CANCELLED`. The plugin follows the authenticated provider status, never the URL it was returned to, so a KNET cancel is handled like a decline. The cancel → checkout path runs only when the provider answers `CANCELLED`. |
| 4 | #11580 | Replay of the exact captured browser return URL (`curl`) | `302` to the same thank-you page; `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` | **No change.** No new note, no second payment note, no second stock reduction, no email attempt. |

Access log for #11580: the provider's server notification (`POST /wc-api/wc_upayments/?wc_order_id=11580`, 17:06:16) arrived **two seconds before** the browser return (`GET …?page=success&wc_order_id=11580?payment_id=…`, 17:06:18). The order was paid exactly once, so the lifecycle lock held under a real webhook-first race.
