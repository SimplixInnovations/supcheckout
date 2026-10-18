# Takeover staging rehearsal (Sub-project 0b), 2026-10-18

This is the owner-run rehearsal of the 0b smart takeover on the My Organic World staging copy (`https://staging.myorganicworld.net`). The copy is a sanitized clone of production. It runs WordPress 7.1.3, WooCommerce with HPOS (sync off), WPML/WCML in KWD, FunnelKit checkout and thank-you pages, and LiteSpeed. The staging guard was active throughout: all email blocked, outbound HTTP limited to the site, WordPress.org and the UPayments sandbox, webhooks stopped and WP-Cron disabled.

The rehearsal mirrors the production path. The official UPayments 3.1.1 was active, SUPCheckout was installed beside it and paused itself, and the takeover then ran through WP-CLI. The site user ran it with `sudo -u <owner> -- wp`, because WP-CLI refuses to run as root.

No credentials, card data, customer data or payment links appear in this file. Customer details in the test orders are synthetic. Provider references come from the UPayments public sandbox: key `jtest123` and the KNET test card. Expiry `09/30` captures; any other expiry is declined.

## Package
- Source: `main` @ `542af0e` (after #185).
- `supcheckout-0.1.0.zip`: 114 files, SHA-256 `1306d28205c57cbcc043896c71542b9a4c1d72b118018c72291e7177f6c02f75`. It was built with `scripts/build-release.sh` and verified with `scripts/verify-release.sh`.
- Uploaded with "Replace current with uploaded" while the official plugin stayed active. The installed tree measured 114 files with normalized fingerprint `116756c0c822ed9e464c34c50879be53f469bd3884063b1ecf4eaf5b56f25a52`, the same value computed from the extracted, verified ZIP.
- A database export was taken before any step.

## Starting state
| Plugin | State |
|---|---|
| `woocommerce-main/UPayments.php` (official 3.1.1) | active |
| `supcheckout/supcheckout.php` | active, **paused**: "SUPCheckout is paused. The UPayments plugin is also active…" |

`wp supcheckout takeover status` reported `idle` and `official_plugin: woocommerce-main/UPayments.php`.

## Provider outage and first preflight
The first preflight correctly blocked the switch with `credential_unverified` (reason `rejected`, `test_mode: true`). The saved settings were correct: test mode on and the public sandbox key. The cause was a UPayments sandbox outage. `sandboxapi.upayments.com` returned **HTTP 502 through Cloudflare** from the staging server and from a second network, over both IPv4 and IPv6. The official plugin's own checkout failed at the same time with "Payment request failed. Empty Response Received." The rehearsal waited for the provider.

Two findings from this window were fixed test-first and merged in **#185** before the rehearsal resumed:
1. While paused, WooCommerce listed SUPCheckout as "Incompatible with WooCommerce features". The paused boot now makes the same HPOS and Cart & Checkout blocks declarations as the full boot, so the warning is gone. This was confirmed on staging after the upload.
2. Preflight now reports a provider outage (curl error, timeout, empty body or HTTP 5xx) as reason `provider_unavailable` with the HTTP status. It no longer suggests the key or test mode is wrong. It still blocks.

After the sandbox recovered (it then answered `status: true`), preflight showed **no blocking finding**, only these warnings:
- `pending_with_open_link`;
- `hpos_meta_disagreement`: 197 of 200 sampled orders hold UPayments data only in the order tables, which is expected with HPOS sync off; no value differs;
- `official_debug_log_present`: path reported only, the file was never read;
- `third_party_references`: WPML/WCML options including `wcml_payment_gateways`, which are report-only; `woocommerce_gateway_order` is handled by the translator.

## Switch: `wp supcheckout takeover run --deactivate-official`
```
takeover copy: phase=orders … orders=752   (50-order batches)
takeover copy: verified
official plugin deactivated: woocommerce-main/UPayments.php
takeover cutover steps: quiesced, drained, verified_objects, table_synced, settings_copied,
  cron_rescheduled, third_party_translated, rechecked, cut_over, caches_flushed
Success: cut over
```
Status afterwards: `cut_over`. 752 orders were processed, 719 of them had UPayments data to copy, and **0 mismatches** were found in every group. The journal held 3 entries. SUPCheckout was active and the official plugin inactive. Users and products show 0 because the sanitized copy holds no saved cards.

## Orders while switched
| Order | Scenario | Result |
|---|---|---|
| #11591 | New KNET order after the switch | **Processing**, `payment_method=supcheckout`, provider `CAPTURED` (PaymentID `100629110000025842`). Exactly one "Payment via UPayments" note, stock reduced once, FunnelKit thank-you page shown. |
| #11590 | Payment page opened on the **official** plugin before the switch, completed after it | The provider's server notification reached the permanent legacy route `wc_upayments` (`POST … 200`), and the browser returned there with `page=error`. SUPCheckout bound the payment to the order through the official plugin's `UPayments_order_id` and track ID, then asked the provider for the authenticated status. The provider reported `status: "error"`, `result: null` and no payment date: the KNET session errored and nothing was charged. SUPCheckout **refused to decide**. The order stayed Pending with the bounded reconciliation scheduled, which ran when triggered by hand because WP-Cron is disabled on staging. No paid state was invented. |
| #11588 | Unpaid order created on the official plugin 5 hours earlier | Already **Cancelled** by WooCommerce's 60-minute stock hold, so its order-pay link correctly refused payment. This is WooCommerce behavior, unrelated to the switch. |
| #11584 | Historical paid order (0a rehearsal) | Unchanged: `upayments`, Processing, paid. |

## Rollback: `wp supcheckout takeover rollback --reactivate-official`
```
takeover rollback steps: quiesced, drained, orders_synced, users_synced, products_synced,
  options_synced, table_synced, rechecked, journal_replayed, rolled_back, caches_flushed,
  cron_restored, copies_removed, settling ×11, rescanned, removal_settled, preflight_ok,
  official_reactivated
Success: rolled back (restored 3)
```
- Status `preflight_ok`, with conflicts `[]`. Removal settled after the 120-second barrier and a clean rescan.
- The official plugin was active again and SUPCheckout paused.
- **#11591, paid through SUPCheckout, stayed paid.** It is back under `payment_method=upayments` with the legacy `UPayments_PaymentID` `100629110000025842` back-synced.
- **#11592**, a new KNET order on the official plugin after the rollback, was **CAPTURED**. The store worked normally on the official plugin.

## Switch again, then Finalize
- `run --deactivate-official` switched the store again. **#11593**, a new KNET order, came back **Processing** with `payment_method=supcheckout` and `CAPTURED`.
- `finalize --dry-run` wrote nothing. It reported `would refuse: too_early` (30 days) and listed the planned removals.
- `finalize --force-after-review`:
  ```
  takeover finalize steps: quiesced, verified, finalized, caches_flushed, cron_removed,
    orders_removed, users_removed, products_removed, options_removed, backups_removed,
    official_removed, ephemeral_removed, legacy_swept, settling ×11, rescanned, table_absent,
    legacy_removed
  removed: legacy_cron=1 official_cron=0 order_meta=6482 markers=721 user_meta=0 product_meta=0
    options=0 takeover_backups=1 official_options=1 official_transients=0 ephemeral_options=3
    ephemeral_transients=1 billing_table=0
  kept forever: the legacy callback route wc_upayments
  Success: finalized (forced after review)
  ```
- Status `finalized`, with 0 mismatches and 0 values kept.

After Finalize, the runtime still reads every payment reference from the SUPCheckout names:

| Order | Payment ID read through `Keys` | WooCommerce transaction ID |
|---|---|---|
| #11584 | `100629010000020994` | `100629010000020994` |
| #11591 | `100629110000025842` | `100629110000025842` |
| #11592 | `100629110000026108` | empty. Paid by official 3.1.1 during the rollback window; that plugin does not set WooCommerce's transaction ID. |

## Result
The 0b takeover **passes the real-store rehearsal**:
- preflight blocked correctly during a provider outage;
- the switch, the order flows, the in-flight payment from the official plugin, rollback with reactivation, a second switch and Finalize all behaved as designed on the production-shaped staging copy;
- paid state always came from the authenticated provider status;
- nothing was charged or marked paid without it;
- the legacy callback route kept working throughout.

### Follow-ups (not blocking)
1. **Provider `status: "error"` with `result: null`.** The provider marked #11590's transaction as an error with no result. SUPCheckout keeps such a payment Pending through its bounded reconciliation, which is the documented fail-closed handling of a null result. Closing it as failed sooner needs provider-contract evidence. Track it as a test-first follow-up.
2. **LiteSpeed:** the cache purge ran manually after each switch. An automatic purge is planned with the onboarding UI (0c).
3. **Real WCML per-currency gateway settings** (`wcml_payment_gateways`) are reported and left untouched by design. The owner confirms per-currency checkout visibility on staging during the 0c onboarding review.
