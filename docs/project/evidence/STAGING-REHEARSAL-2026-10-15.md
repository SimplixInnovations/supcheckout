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
