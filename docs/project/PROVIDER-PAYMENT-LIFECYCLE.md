# Provider Contract & Payment Lifecycle

> **Historical closed-gate record.** This file preserves the verified provider/payment-lifecycle milestone and its then-current program wording. Current SUCheckout program/repository state is owned by `PROJECT-STATUS.md`; the payment invariants remain permanent unless separately superseded by reviewed evidence.

**Status:** DONE / VERIFIED

**Repository:** `SimplixInnovations/simplixpay-upayments`

**Discovery base:** `8e5a93ceb4f133663fdf433cc1a10b8b36c13d97`

**Implementation PR:** #15

**Final reviewed head:** `d2b08ebe1e65ad4ea8f4e06b41423e7bd9904fc3`

**Verified squash merge:** `9569e39973a9e94926087738eae06c3846361943`

**Merge tree:** `40ec562674361624c2764263ba55cfba84594955`

**Verified parent:** `8e5a93ceb4f133663fdf433cc1a10b8b36c13d97`

**Discovery / verification date:** 2026-08-25

## Closure decision

Provider Contract & Payment Lifecycle is **DONE / VERIFIED** for the ordinary WooCommerce checkout lifecycle scope defined below.

The gate is closed because the provider/WooCommerce contract was researched against current official documentation, frozen before runtime implementation, implemented behind an isolated `Simplix\Pay\UPayments\Payment` strangler, independently challenged by automated review, fully regression-tested on the exact PR merge-ref, squash-merged, post-merge verified on `main`, and cleaned up.

This closure certifies the reviewed local lifecycle contract. It does **not** certify automatic refunds, arbitrary multi-merchant marketplace splitting, provider webhook HMAC verification that UPayments has not fully documented, subscription auto-deduction redesign, broad security closure, or broad WooCommerce/WordPress/PHP/WPML/performance compatibility.

The program gate immediately after this closure was **Security Threat-Model Closure**; that bounded gate, Architecture discovery/A1-A5 and Quality Platform Q1-Q19 are now **DONE / VERIFIED**. The current program gate is **Enterprise Compatibility Certification**.

## Scope

This gate covers the ordinary checkout payment lifecycle:

- existing Charge request/response behavior already characterized by H12;
- browser return and server-to-server notification handling;
- authenticated Get Payment Status reconciliation;
- deterministic provider-result classification;
- WooCommerce paid/failed/cancelled transitions;
- replay/idempotency and bounded reconciliation;
- status-query rate limiting;
- current refund and multi-merchant capability boundaries.

Historical token migration remains governed by the closed H12/Phase 9I contracts. Subscription auto-deduction retains its separately characterized scheduler/attempt-journal path and was not silently rewritten by this tranche.

## Evidence registry

Official UPayments documentation reviewed on 2026-08-25:

- Make Charge: `https://developers.upayments.com/reference/addcharge`
- Webhook: `https://developers.upayments.com/reference/webhook`
- Get Payment Status: `https://developers.upayments.com/reference/checkpaymentstatus`
- Possible Gateway Responses: `https://developers.upayments.com/reference/possible-gateway-responses`
- Refund: `https://developers.upayments.com/reference/refund`
- Multi-Merchant API: `https://developers.upayments.com/reference/multi-merchant-api`
- FAQ: `https://developers.upayments.com/reference/faqs`
- Test Environment: `https://developers.upayments.com/reference/test-environment-details`

Official/current WooCommerce documentation reviewed on 2026-08-25:

- Payment Gateway API: `https://developer.woocommerce.com/docs/features/payments/payment-gateway-api`
- Order statuses: `https://woocommerce.com/document/managing-orders/order-statuses/`
- current `WC_Order::payment_complete()` implementation/reference.

Live source base characterized:

- `UPayments.php` blob `0f9ee544afcd846e01ff0bee4072175b93e49c60`;
- historical callback route `wc_upayments`;
- inherited Bearer-authenticated `get-payment-status/{track_id}` behavior;
- existing Phase 0, Phase 9I, H12 PHP and H12 Blocks regression suites.

## Frozen provider/lifecycle contract

### Charge

- POST `/api/v1/charge` with Bearer authentication.
- `notificationUrl` is mandatory.
- Charge success requires HTTP `201`, boolean `status === true`, structurally valid response data and a valid external redirect URL under the existing H12 contract.
- Successful Charge initialization is **not** proof that funds were captured.
- An indeterminate Charge is never blindly retried by lifecycle reconciliation because no provider idempotency contract for Charge is assumed.

### Callback/webhook authority

UPayments callback/browser fields are routing and diagnostic input only. They never authorize a financial state transition.

The new lifecycle controller:

- preserves the historical `wc_upayments` route;
- runs at priority 5 before the inherited priority-10 handler;
- leaves the inherited `get_order_status` display poll untouched;
- merges GET/POST callback values explicitly and rejects conflicting values;
- excludes cookies and never uses `$_REQUEST`;
- locally preflights Woo order ID and `UPayments_order_id` before provider lookup.

### Authoritative payment truth

Financial truth is established only through this chain:

1. Bearer-authenticated Get Payment Status response;
2. exact HTTPS UPayments status-host/path allowlist;
3. HTTP `201` and strict top-level success/schema validation;
4. strict transaction binding to the WooCommerce order;
5. deterministic provider-result classification;
6. concurrency-locked WooCommerce state transition.

Browser return parameters, webhook fields, provider result text and callback `payment_id` never authorize paid state by themselves.

### Exact transaction binding

Every authenticated status response used to mutate an order must bind all of:

- `track_id` exactly to the current reconciliation cursor;
- `merchant_requested_order_id` exactly to local `UPayments_order_id`;
- `reference` exactly to the WooCommerce order ID;
- `currency_type` exactly to the order currency after the existing UPayments currency mapping;
- `total_price` exactly to the local order total using canonical decimal-string equality;
- for `CAPTURED`, a non-empty provider `payment_id`.

Amount comparison does **not** round through WooCommerce display precision. Canonical decimal values may differ only by insignificant trailing fractional zeros. For example, `10.00` equals `10.0000`; `10.004` does not equal `10.00`.

Charge amount snapshot (A1-07). WooCommerce re-rounds an order total to the current request's `wc_get_price_decimals()` every time the order loads. A 3-decimal KWD order charged at `12.345` therefore reads as `12.35` in a webhook or cron request on a store whose default currency has 2 decimals. To keep binding exact, a successful Charge records `_supcheckout_charge_amount_v1` = `{requested_order_id, amount, currency}` under the order lock, where `amount` is the exact token sent as `order.amount`. When the snapshot belongs to the attempt being bound and has the order's currency, `total_price` must equal the snapshot amount exactly. The snapshot is used only while the order is unedited:

- if the snapshot equals `get_total()` exactly, it is used, which is the same exact comparison as without a snapshot;
- otherwise the request has fewer decimals than the Charge, and two further conditions must both hold. First, the snapshot rounded with `wc_format_decimal($amount, wc_get_price_decimals())`, the rounding WooCommerce applied to this request's total, must equal `get_total()`. Second, the snapshot must equal the persisted order total **exactly**. That total is read independently of the request's decimals: `total_amount` from the HPOS orders table when `OrderUtil::custom_orders_table_usage_is_enabled()`, otherwise the CPT `_order_total` post meta, then canonicalized.

The rounding check alone is not enough. An edit inside the same rounding bucket (charged `12.341`, edited to `12.344`, both `12.34` at 2 decimals) passes it, and only the persisted total shows the edit. The rounding is only a comparison. The amount bound is the snapshot itself, so a provider amount equal to the re-rounded total but not to the charged amount never binds. Orders without a usable snapshot (those charged before this change, a late callback for an earlier attempt, or a malformed value) are compared exactly with `get_total()`. The key exists only under its SUPCheckout name and is not in `Takeover\Keys`, so the takeover never copies, removes or finalizes it.

Stored-total check on every bind (R-1). The persisted order total is not only a snapshot condition. Every bind, with or without a snapshot, also requires `total_price` to equal the persisted total exactly, read as above (one read per bind). This catches an edit made at a higher precision than the binding request, which `get_total()` re-rounds back to the charged amount. The outcomes are:

- an amount that fails the comparison above, or differs from the persisted total, fails with `binding_amount` (unless a filter changes the order total, below). It is final: no re-check is scheduled, the merchant gets one note for the track, and an order on a trusted cursor leaves the hourly sweep;
- a persisted total that cannot be read (missing, not a plain decimal, or a passing database error) fails closed with `binding_persisted_total_unreadable`. It is **retryable**, not a mismatch: the order keeps its bounded reconciliation schedule (at most `MAX_RECONCILE_ATTEMPTS`), and gets no mismatch note. The same reason is kept when the read fails on the rebind under the order lock: the primary path schedules its bounded re-check, and a capture-only (secondary) track stays in its bounded per-track window and is noted if that window runs out.

Order-total filters fail closed. WooCommerce's `woocommerce_order_get_total` filter changes what `get_total()` reports but not the stored total. On a store where a plugin uses it, the two totals differ, so a bind cannot confirm the amount and the order is never marked paid. When the provider amount equals either the stored total or the filtered total, the reason is `binding_total_filtered` instead of `binding_amount`. It fails closed in the same final way, including leaving the hourly sweep: the answer cannot change while the plugin stays active. Its note says that a plugin changes the order total WooCommerce reports, so SUPCheckout could not confirm the payment amount, and that the merchant should review the order against the UPayments dashboard. An amount that matches neither total stays `binding_amount`.

### Provider-result classification

Only exact provider values receive state-changing meaning:

| Provider result | Local class | Woo behavior |
|---|---|---|
| `CAPTURED` | `CAPTURED` | canonical Woo payment completion |
| `PENDING` | `PENDING` | remain unpaid; reconcile |
| `AUTHORIZED` | `PENDING` | remain unpaid; reconcile |
| `APPROVED` | `PENDING` | remain unpaid; reconcile |
| `NOT CAPTURED` | `FAILED` | fail only an unverified/unpaid order |
| `FAILED` | `FAILED` | fail only an unverified/unpaid order |
| `ERROR` | `FAILED` | fail only an unverified/unpaid order |
| `CANCELED` | `CANCELLED` | cancel only an unverified/unpaid order |
| `NULL` provider result | `INDETERMINATE` | remain unpaid; reconcile |
| `Processing` / unknown future value | `INDETERMINATE` | remain unpaid; reconcile |
| `REFUND` / `VOIDED` for unverified initial payment | `INDETERMINATE` | never infer initial capture |

Unknown future values fail closed. Provider documentation indicating pending/processing/NULL-style outcomes are non-terminal is preserved rather than guessed into failure.

**The transaction-level `status` field is not the result.** The table above classifies the transaction's `result`. The separate `data.transaction.status` field is not used for classification. The takeover rehearsal (2026-10-18, order #11590, `docs/project/evidence/TAKEOVER-REHEARSAL-2026-10-18.md`) observed this sandbox response for a KNET session that errored before authorization:
- `transaction.status: "error"`;
- `result: null`, `payment_date: null`, `tran_id: ""`;
- no `isTerminalStatus` field.

Under the table above it stays `INDETERMINATE`:
- **Provider documentation.** UPayments' "Possible Gateway Responses" reference says a NULL, Pending or Processing result must not be treated as a failure. Its CRON job can still update a KNET transaction to CAPTURED for up to about 13 minutes. The Get Payment Status reference documents only `status: "done"` and does not define `"error"`.
- **Reconciliation window.** Reconciliation retries at 60, 120, 240 and 480 seconds, about 15 minutes, which is past that window. It then flags the order for manual review.
- **Late captures.** A later authenticated `CAPTURED` still completes the order.

The order is never failed on `transaction.status` alone. That changes only if UPayments confirms that `status: "error"` (or a defined `isTerminalStatus: true`) is never followed by a capture. That question is open with the provider.

### Canonical WooCommerce payment completion

Authenticated/bound `CAPTURED` uses:

`$order->payment_complete($verified_payment_id)`

The lifecycle no longer treats direct paid-state `update_status()` as the canonical success path.

This preserves WooCommerce's standard transaction ID, paid lifecycle and `woocommerce_payment_complete` behavior. The existing merchant “Show paid orders as Completed?” setting is applied through WooCommerce's payment-complete status filter for the exact order rather than bypassing Woo payment completion.

The `_upay_verified_capture` flag is written only after paid state and transaction-ID postconditions are verified. Duplicate/replayed callbacks after verified capture make zero additional provider calls and do not re-fire payment completion.

A pre-existing paid order is never downgraded by a later terminal provider response. A refunded order is never resurrected.

## Reconciliation contract

### Two cursor trust levels

The implementation distinguishes:

- an **unverified routing cursor**, stored only after local callback/order preflight so an initial transient provider lookup can be retried;
- a **trusted cursor**, promoted only after an authenticated status response is fully rebound to the fresh Woo order.

Both cursor states are paired with the current provider Charge identity (`UPayments_order_id`). If the same Woo order begins a later UPayments Charge attempt, stale cursor/reconciliation state from the prior attempt cannot pin or contaminate the new attempt.

### Bounded retries

For authenticated unresolved states or retryable status-query failures with a safe current-attempt cursor:

- one deduplicated single-event reconciliation is scheduled;
- delays are `60, 120, 240, 480` seconds;
- maximum four scheduled attempts;
- every attempt repeats authenticated status verification and full order binding;
- no lifecycle reconciliation path creates or retries a Charge;
- terminal capture/failure/cancellation, refund state, verified capture, binding/cursor conflict or exhaustion stops scheduling;
- exhaustion leaves the order unpaid and creates one sanitized merchant note requiring manual review.

A binding mismatch clears the unverified cursor and does not schedule blind retries.

## Concurrency contract

A database-backed per-order lock prevents browser return, webhook and reconciliation workers from concurrently driving the same Woo lifecycle.

Stale-lock takeover uses compare-and-swap semantics against the exact stored lock value; it never blindly deletes a contested option. Release likewise conditionally deletes only the current owner's exact scalar lock record. The regression harness simulates a competing worker replacing a stale record and proves the newer owner's lock is not deleted.

## Status-query transport/rate contract

The verifier:

- accepts only HTTPS UPayments status endpoints under the exact allowed hosts/path;
- rejects query-bearing/foreign endpoints before sending credentials;
- disables redirects;
- keeps TLS verification enabled;
- uses a finite 15-second timeout;
- consumes rate-limit capacity only after URL validation.

UPayments documentation reviewed for this gate is contradictory:

- dedicated Get Payment Status page: **30 requests/minute**;
- FAQ: status/refund endpoints **800 requests/minute**.

The plugin deliberately uses the stricter **30 requests/minute** credential/mode-scoped atomic rate gate until UPayments resolves the contradiction.

## Webhook signature/HMAC unresolved boundary

UPayments states that server-to-server webhooks are signed / HMAC support is being rolled out, but the public documentation reviewed on 2026-08-25 did not provide a complete stable verification contract covering header name, canonical payload, algorithm/key derivation and rotation.

Therefore:

- no signature verifier was fabricated;
- callback payload fields remain non-authoritative;
- every financial transition still requires authenticated status lookup and strict binding;
- exact webhook HMAC verification remains **PROVIDER-DOC-UNRESOLVED** and becomes explicit input to Security Threat-Model Closure.

## Refund boundary

Automatic WooCommerce gateway refunds remain intentionally **UNSUPPORTED** and are not advertised.

UPayments documents asynchronous full/multiple-partial refunds, status polling, no refund webhook and no current idempotency-key support. Adding `process_refund()` without a durable local refund-intent/idempotency/reconciliation journal would create duplicate-refund risk. Refund automation remains a later dedicated certification tranche.

## Multi-merchant boundary

UPayments can accept a multi-entry `extraMerchantData` allocation array. Current SimplixPay behavior remains exactly one additional merchant allocation whose amount equals the order amount.

Frozen current support claim:

> **Single additional merchant allocation only. Arbitrary multi-split marketplace routing is not certified.**

Expansion requires a separate routing/allocation/refund characterization.

## Implementation shape

The verified runtime uses an incremental strangler instead of a broad `UPayments.php` rewrite:

- `src/Payment/ProviderResult.php`
- `src/Payment/StatusRateGate.php`
- `src/Payment/OrderLock.php`
- `src/Payment/StatusVerifier.php`
- `src/Payment/PaymentLifecycle.php`

`src/Release/Identity.php` conditionally loads/registers the lifecycle only when the WordPress hook API exists, preserving the Phase 0 isolated identity harness.

Protected H12 token/subscription identifiers remain untouched.

## Review findings closed before merge

Automated review raised four valid findings, all corrected before final merge:

1. **P1 — rate-gate test/runtime seam:** removed the incorrect global-string `function_exists('wp_salt')` pre-check while retaining fail-closed salt handling.
2. **P1 — first-query transient failure:** added explicitly unverified, attempt-scoped retry cursor semantics so a first callback 5xx/network/rate-limit failure can reconcile safely.
3. **P1 — stale lock race:** replaced blind stale-lock deletion with exact database compare-and-swap takeover/release semantics.
4. **P2 — amount rounding mismatch:** replaced Woo display-precision rounding with exact canonical decimal comparison and added a focused permanent harness.

All four PR review threads were resolved only after the fixes were present and tested.

## Exact final regression evidence

Exact final reviewed PR #15 head:

`d2b08ebe1e65ad4ea8f4e06b41423e7bd9904fc3`

GitHub Quality Gates run #70 passed the actual PR merge ref and reported:

- Governance: **SUCCESS**
- tracked PHP syntax: **SUCCESS**
- Phase 0 release identity: **35 PASS / 0 FAIL**
- Phase 9I preflight: **123 PASS / 0 FAIL**
- Phase 9I executor: **59 PASS / 0 FAIL**
- Phase 9I operations: **81 PASS / 0 FAIL**
- Provider Payment Lifecycle: **141 PASS / 0 FAIL**
- Provider Exact Amount Binding: **4 PASS / 0 FAIL**
- H12 PHP: **1927 PASS / 0 FAIL**
- Blocks syntax: **SUCCESS**
- H12 Blocks: **144 PASS / 0 FAIL**

## Merge and post-merge verification

PR #15 was squash-merged as:

- merge commit: `9569e39973a9e94926087738eae06c3846361943`;
- tree: `40ec562674361624c2764263ba55cfba84594955`;
- sole parent: `8e5a93ceb4f133663fdf433cc1a10b8b36c13d97`;
- GitHub signature: **VERIFIED**.

Post-merge verification established:

- `main` exactly equals the merge commit above;
- implementation branch `provider-lifecycle/discovery` is deleted;
- push-triggered Quality Gates run #71 on merged `main` completed **SUCCESS** with Governance and the complete H12 Regression Harness job green, including both provider lifecycle harnesses.

## Closed acceptance gate

The acceptance conditions are satisfied:

- deterministic provider-result classification — **YES**;
- strict status schema + order/currency/reference/amount binding — **YES**;
- exact decimal amount equality without display rounding — **YES**;
- CAPTURED uses Woo `payment_complete()` + verified provider payment ID — **YES**;
- standard Woo transaction ID persisted — **YES**;
- duplicate/replayed capture idempotency — **YES**;
- pending/authorized/approved/NULL/unknown remain unpaid — **YES**;
- terminal failure/cancel only affects unverified/unpaid orders — **YES**;
- refunded/verified-paid orders cannot be resurrected — **YES**;
- first-query transient failure can reconcile without treating callback data as payment proof — **YES**;
- reconciliation bounded/deduplicated/no Charge retry — **YES**;
- same-Woo-order later Charge attempt isolated from stale cursor state — **YES**;
- callback routing excludes cookies/`$_REQUEST` and rejects conflicts — **YES**;
- status transport host/TLS/redirect/timeout/rate boundaries — **YES**;
- concurrency stale-lock race characterized and fixed — **YES**;
- automatic refunds remain unsupported — **YES**;
- single-entry multi-merchant restriction documented — **YES**;
- Phase 0, Phase 9I, H12 PHP, H12 Blocks and Governance regressions green — **YES**;
- merge/post-merge `main`/branch cleanup verified — **YES**.

## Non-claims

This closure does not mean the plugin is broadly production-certified. The bounded Security Threat-Model Closure is now independently **DONE / VERIFIED**; architecture quality, full integration/static/browser platforms, WordPress/WooCommerce/PHP/HPOS/Blocks/WPML certification, feature certification, performance/UX/operations and release engineering remain later gates.

The current permitted program gate is **Enterprise Compatibility Certification**.
