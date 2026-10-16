# Official UPayments Plugin: Persisted-Data Inventory

- **Date:** 2026-10-16
- **Purpose:** the evidence base for Sub-project 0b, the smart switch and identity migration. See the revamp spec, §5.
- **Method:** source reading of `upaymentskwt/woocommerce` at four refs:
  - branch `V2.0.5` (2.x exists only as branches; there are no tags);
  - branch `upayments-V2.2.1`;
  - tag `3.0.0`;
  - tag `3.1.1`.
- **Not verified:**
  - runtime behavior on real stores;
  - the contents of third-party settings;
  - PUC filter-suffix resolution;
  - the unreleased `upayments-V3.1.2` / `hmac-signature` branches, beyond a grep;
  - intermediate branches V2.0.6–V2.2.0, V2.0.8 and V2.1.5.

Citations are `ref UPayments.php:line` unless another file is named. "Sx" means SUPCheckout.

## 1. Gateway identity and settings

| Item | 2.0.5 / 2.2.1 | 3.0.0 / 3.1.1 | Sx now | 0b target |
|---|---|---|---|---|
| Gateway ID | `upayments` (2.0.5:41) | `upayments` (3.1.1:68) | `upayments` | `supcheckout` |
| Gateway class | `WC_UPayments` (2.0.5:1276-1280) | `WC_Upayments`, registered as `"WC_UPayments"` (3.0.0:40, 1694-1698) | `WC_Upayments` → namespaced in 0a | n/a |
| Blocks / Store API namespace | none | `upayments` (3.1.1 blocks.php:13; 3.1.1:848) | `upayments` | `supcheckout` |
| Settings option | `woocommerce_upayments_settings` (2.0.5:1293) | same (3.1.1:2004) | same | `woocommerce_supcheckout_settings` |

**Settings keys:**
- **2.x:** `enabled, title, description, api_key, debug, test_mode, is_order_complete`.
- **3.0.0 adds:** `save_card_section_title, use_new_design, enable_save_card, multimerchant_section_title, enable_multimerchant, iban_number, cc_charge, cc_charge_type, knet_charge, knet_charge_type, multimerchant_accounts`.
  - `multimerchant_accounts` is a JSON string.
  - Read but never defined: `charge`, `default_merchant_id`, `default_api_key`, `save_card_enabled`.
- **3.1.1 adds:** `make_default_gateway, autodeduction_section_title, enable_subscriptions`.
- **Unreleased:** `hmac_signature_key`.

## 2. Order meta

All versions write through WooCommerce CRUD, but **read through `get_post_meta`**, which is not HPOS-safe. No version declares `custom_order_tables`.

| Key | Versions | Notes | 0b target |
|---|---|---|---|
| `UPayments_order_id` | all | `md5(order_id*time())`; on 3.1.1 renewals it is provider `orderId+1` | `_supcheckout_provider_order_id` |
| `UPayments_Result, _PaymentID, _TrackID, _payment_type, _PostDate, _TranID, _Ref, _Auth` | all | raw callback query values | `_supcheckout_*` |
| `UPayments_webhook_triggered` | all | `1` | `_supcheckout_webhook_triggered` |
| `UPayments_WHS` | all | added without a delete, so it **may be multi-valued** | `_supcheckout_public_status` |
| `UPayments_reference`, `UPayments_Checkout_Selected` | all | | `_supcheckout_*` |
| `_payment_method_title` | 2.2.1+ | WooCommerce core field | not renamed |
| `_upay_credit_card_token`, `_upay_customer_unique_token` | 3.1.1 (+ Sx H12 `_upay_customer_token_{scope,kind,generation}_v1`) | | `_supcheckout_*` |
| `_upay_subscription_plan / _interval / _status` | 3.1.1 | status is active / paused / cancelled | `_supcheckout_subscription_*` |
| `UPayments_AutoDeduction` | 3.1.1 | `no` on parents, `yes` on renewals | `_supcheckout_auto_deduction` |
| `_upay_last_billed_at, _upay_last_attempt_at, _upay_retry_count, _upay_last_failed_reason` | 3.1.1 | | `_supcheckout_*` |
| Renewal `UPayments_ParentOrderID, _InvoiceID, _TransactionDate, _GatewayStatus` | 3.1.1 | renewals have `payment_method=upayments` | `_supcheckout_*` |
| Sx lifecycle `_simplixpay_upayments_*_v1` | Sx only | mixed prefixes | `_supcheckout_*_v1` |

## 3. Customer tokens, options and transients

**Customer token (saved cards).** No official version stores user meta. The token is derived from the billing phone on every request, and the cards live only at UPayments.

| Version | Customer token formula |
|---|---|
| 2.0.5 | raw phone |
| 2.2.1 / 3.0.0 | phone with non-alphanumerics stripped, leading `0` → `1` |
| 3.1.1 | phone + `user_id`, then leading `0` → `1` |

Retrieval in 3.0.0 and 2.2.1 uses the phone only.

**Sx now:** user meta `_upay_customer_token_v2_b{blog}_{scope}`, option `upayments_token_identity_secret_v2`, lock prefix `upay_ctk_`.

**Official options and transients:**
- `upayments_maat` is deleted only.
- **3.0.0:**
  - placeholder bugs `your_gateway_version` and `woocommerce_your_gateway_id_settings`;
  - `upayments_block_checkout_page_id`: activation **creates and publishes** a "Checkout (Block)" page;
  - `woocommerce_upayments_enable_block_checkout`;
  - writes core `woocommerce_checkout_page_id`.
- **3.1.1:**
  - transient `upayments_cron_lock` (60 s);
  - forces `woocommerce_checkout_phone_field=required` on every `init`;
  - activation may overwrite the checkout page content;
  - uninstall references a never-created `upay_subscription_settings` option and `{prefix}upay_subscriptions` table.
- **3.x update checker:** option `external_updates-upayments-V2.2.1` and cron `puc_cron_check_updates-upayments-V2.2.1`.

**Sx-owned names that also move:**

| Current | Target |
|---|---|
| `simplixpay_upay_order_lock_v1_*` | `supcheckout_order_lock_v1_*` |
| `simplixpay_upay_status_v1_*` | `supcheckout_status_v1_*` |
| `upayments_payment_methods_rate_gate_*` | `supcheckout_pm_rate_gate_*` |
| transients `upay_pm_v3_*` / `upay_pm_*` | `supcheckout_pm_*` |
| `upay_billing_cycle_schema_version` | `supcheckout_billing_cycle_schema_version` |
| `upay_subscription_enrollment_cursor` | `supcheckout_subscription_enrollment_cursor` |
| table `{prefix}upayments_billing_attempts` | `{prefix}supcheckout_billing_attempts` |
| migration ledgers `simplixpay_upayments_migration_v1` / `_simplixpay_upayments_migration_result_v1` | `supcheckout_migration_*` |

## 4. Cron and Action Scheduler

| Hook | Source | 0b target |
|---|---|---|
| `upay_hourly_cron_job` (3.1.1, fires `upay_process_subscriptions`) | official | remove (Sx already clears it) |
| `upay_process_subscriptions` (hourly, no args) | official 3.1.1 and Sx | `supcheckout_process_subscriptions` |
| `puc_cron_check_updates-upayments-V2.2.1` | official 3.x | clear |
| `supcheckout_process_due_parent` (Action Scheduler, group `supcheckout`) | Sx | unchanged |
| `simplixpay_upayments_reconcile_order` / `_secondary` | Sx | `supcheckout_reconcile_order` / `_secondary` |

2.x and 3.0.0 schedule no payment cron, and no official version uses Action Scheduler.

## 5. URL shapes held by UPayments (all versions)

- **URLs sent with every charge:**
  - return: `site_url()."/?wc-api=wc_upayments&page=success&wc_order_id=N"`
  - cancel: `...&page=error&wc_order_id=N`
  - notification: `...&wc_order_id=N`, with **no `page` marker**
- **Status poll:** `?wc-api=wc_upayments&get_order_status=1&wc_order_id=N`.
- **Callback query args:** `result, payment_id, track_id, payment_type, post_date, tran_id, ref, auth, requested_order_id`.
- **3.1.1 My Account actions:** `upay_action=unsubscribe|pause|resume&order_id&_wpnonce`, nonce action `upay_{action}_{id}`.

## 6. Products, cart and order items (3.1.1)

- **Product type `custom_type`:** a `product_type` taxonomy term.
- **Product meta:** `_custom_field_id`. `_upay_disable_subscription` is read but never written.
- **Order item meta:** the label key is the **translated** string `__('Special Feature','upayments')`, so it is locale-dependent.

## 7. Tables, REST, rewrites, roles

None in any version. The only table reference is the phantom `upay_subscriptions`.

## 8. Third-party settings

There is no WCML, WPML, FunnelKit, CartFlows or Polylang code in any version. Entries holding `upayments` in those plugins, in `woocommerce_gateway_order` or in `woocommerce_default_gateway` come from WooCommerce conventions, so the 0b preflight must **scan for them** rather than assume a list.

## Migration hazards (0b must design for each)

1. **Permanent legacy callbacks.** Pre-switch payments call `wc-api=wc_upayments` with `wc_order_id`, and the webhook form carries no `page` marker. Renewal parents' references live at UPayments. The inbound listener is permanent.
2. **Saved cards cannot be copied.** There are four token formulas, keyed by phone, and the token changes when a customer edits their phone. Treat these as legacy-compat identities that are re-verified live, never as canonical H12 identities.
3. **Multi-valued and placeholder data.**
   - `UPayments_WHS` duplicates: define how they collapse before verification.
   - Upgraders from 3.0.0 may have defaults only in `woocommerce_your_gateway_id_settings`.
   - `default_merchant_id`, `default_api_key` and `charge` may be absent everywhere.
4. **HPOS split.** Legacy reads used `get_post_meta`, so on HPOS stores with sync off the official plugin's own reads were empty. Read the source via CRUD, and report any postmeta/HPOS disagreement.
5. **Version gaps.**
   - 2.x orders have no `_upay_*` keys.
   - Only 3.1.1 carries subscription and token meta.
   - 3.1.1 renewal `UPayments_order_id` = provider `orderId+1`, not a value we generated.
6. **Pre-existing duplicate renewals.** The official renewal flow looks up `_upay_payment_id`, which nothing writes, so duplicates may already exist. The preflight detects and reports them.
7. **Leftover state to report or clean up at Finalize:**
   - the 3.0.0 "Checkout (Block)" page and the `woocommerce_checkout_page_id` override;
   - the forced `woocommerce_checkout_phone_field`;
   - the PUC option and cron;
   - two hourly cron events;
   - the `upayments_cron_lock` transient.
8. **Secrets in the official plugin's `debug.log`.** With debug on, every version logs the API key to its plugin directory. The preflight flags the file and the switch never copies it.
9. **Translated item-meta key.** `Special Feature` is matched per locale.
10. **Historical payment method.** Orders keep `payment_method=upayments`, so every lookup by gateway includes the legacy ID.
11. **Sx's own mixed prefixes** (`upay_`, `upayments_`, `simplixpay_`, `supcheckout_`) are part of the rename set.
