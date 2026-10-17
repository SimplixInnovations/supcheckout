# Sub-project 0a (clean core): staging rehearsal, 2026-10-17

This record covers the owner-run rehearsal of the Sub-project 0a package. The package renames the main file to `supcheckout.php` and keeps a header-less transitional `UPayments.php` shim. The run was on the My Organic World staging copy (`https://staging.myorganicworld.net`), which uses FunnelKit checkout and thank-you pages, WPML/WCML and LiteSpeed.

## Package
- Source: `main` @ `0cef28d` (after #172; contains every 0a code change, #165–#172).
- `supcheckout-0.1.0.zip`: 77 files, SHA-256 `d8dedbf94c7241e14e701e4a180d308359ab106c3c7651605a53fd1817404c05`. Built with `scripts/build-release.sh` and verified with `scripts/verify-release.sh`.

## Update path and installed tree
The pre-rename install was active as `supcheckout/UPayments.php`. The owner updated it by zip upload ("Replace current with uploaded") without deactivating it first.

The command below ran as the site user; WP-CLI refuses to run as root. It confirmed the shim moved the active entry:

```
wp option get siteurl                                    → https://staging.myorganicworld.net
wp plugin list --status=active --field=file | grep supcheckout → supcheckout/supcheckout.php
```

The installed tree matches the package:
- File count: 77.
- Normalized fingerprint: `b6f323abb3db717ca88edf4b1bd7f4dcc3c17513ef73871374f25ec0772ef1b2`. This is a locale-neutral sort with `sha256sum` output normalized, so Windows and Linux produce the same value.
- The same value was computed from the extracted verified ZIP.

## Sandbox payments (UPayments sandbox, KNET test card)
Every "Email … failed to send" note is expected, because the staging guard blocks all mail. The FunnelKit "Upsell Skipped – No Supported Gateway" note is expected, because one-click upsells need saved-card charging, which is out of scope here.

| Order | Scenario | Result |
|---|---|---|
| #11584 | KNET success | **Processing**. `UPayments_Result` CAPTURED, PaymentID `100629010000020994` is the transaction ID, and Paid shows 14,000 KWD. There is exactly one "Payment via UPayments" note, and stock was reduced once (1→0) for both lines. The browser landed on the FunnelKit thank-you page for #11584. |
| #11585 | KNET decline (expiry other than the capturing one) | **Failed**: NOT CAPTURED, with no payment ID and no stock reduction. The note reads "UPayments authenticated payment result is terminal failure". The browser returned to order-pay with "Your payment was not completed…". |
| #11586 | **Cancel** on the KNET sandbox page | **Failed**, identical to #11585. The provider reports a KNET cancel as NOT CAPTURED, and the plugin follows the authenticated status. |
| #11584 | Replay of the exact captured provider return URL (`curl`) | `HTTP/2 302` to the same thank-you page for #11584, with `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`. **No change** on the order: no new note, no second payment, no second stock reduction. |

### Webhook-first race (access log for #11584)
```
POST /wc-api/wc_upayments/?wc_order_id=11584                         200   14:35:36  (provider server notification)
GET  /wc-api/wc_upayments/?page=success&wc_order_id=11584?payment_id=…  302   14:35:38  (customer browser return)
```
The server notification arrived two seconds before the browser return, and the order was paid exactly once. That shows the lifecycle lock holding after the restructure. It also shows that the provider's `?`-glued return query is still parsed, which is the PR #141 fix.

## Result
Sub-project 0a passes the real-store rehearsal. On the rename and restructure:
- the in-place update keeps the plugin active;
- the installed tree is byte-identical to the package;
- success, decline, cancel and replay behave exactly as in the 2026-10-15 baseline (`STAGING-REHEARSAL-2026-10-15.md`).

No real secrets, card data or production data are recorded here. Customer details in the test orders are synthetic.
