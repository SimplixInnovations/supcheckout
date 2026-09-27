# UPayments Public Contract Research — 2026-09-27

**Purpose:** exhaust public, first-party evidence before asking UPayments for private/account-specific clarification.

**Decision rule:** public evidence may narrow or close a question only when first-party sources are mutually consistent and specific enough to support a production contract. A public implementation is evidence of provider behavior/intent, but an unreleased branch is not a normative production API contract.

**Runtime effect:** NONE. This document does not authorize a SUPCheckout runtime change.

## Evidence classes

- **PUBLICLY RESOLVED** — current first-party evidence is sufficiently consistent for the stated fact.
- **PUBLIC EVIDENCE — CONFLICTING** — first-party sources materially disagree; do not implement either interpretation as production truth.
- **STRONG PUBLIC INFERENCE** — evidence points to one interpretation, but the provider has not documented the exact contract.
- **PROVIDER CONFIRMATION REQUIRED** — no public evidence closes the production/account-specific question.

## First-party source inventory

### Current UPayments developer documentation

- HMAC Authentication: https://developers.upayments.com/reference/hmac-authentication
- FAQ: https://developers.upayments.com/reference/faqs
- Make Charge: https://developers.upayments.com/reference/addcharge
- Get Payment Status: https://developers.upayments.com/reference/checkpaymentstatus
- Test Mode: https://developers.upayments.com/reference/test-environment-details
- Postman Collection: https://developers.upayments.com/reference/upayments-postman-collection
- Webhook: https://developers.upayments.com/reference/webhook
- Possible Gateway Responses: https://developers.upayments.com/reference/possible-gateway-responses
- Create Customer Unique Token: https://developers.upayments.com/reference/createcustomeruniquetoken
- Add Card: https://developers.upayments.com/reference/addcard
- Retrieve Cards: https://developers.upayments.com/reference/retrievecustomercards
- KFAST Save Card: https://developers.upayments.com/reference/kfast-save-card
- WooCommerce Auto Deduction (Subscriptions): https://developers.upayments.com/reference/woocommerce-auto-deduction-subscriptions

### Current first-party SDK

- Official web SDK: https://www.npmjs.com/package/@upayments-kw/web-sdk
- Official React SDK: https://www.npmjs.com/package/@upayments-kw/react
- Official examples repository: https://github.com/upaymentskwt/web-sdk-examples

### Official WooCommerce repository

Repository: https://github.com/upaymentskwt/woocommerce

Observed on 2026-09-27:

- latest public GitHub Release: **3.1.1**, published 2026-07-20:
  https://github.com/upaymentskwt/woocommerce/releases/tag/3.1.1
- default `main`: `b9488a6e0bc7a4ba3bbed7549709b2b1fb822282`
- unreleased `develop`: `ec8a496fbded79c829dc6ca339d160b291727653`
- unreleased `hmac-signature`: `dc4ad344dce612fc9ed73b827cdd827da0fee26d`
- unreleased `upayments-V3.1.2`: `d8d8d314fd02ff3bf5ae0518f053c563910d9949`
- HMAC PR #5: https://github.com/upaymentskwt/woocommerce/pull/5
- Auto-deduction PR #2: https://github.com/upaymentskwt/woocommerce/pull/2
- customer-token change `49e0f89e67211f7390006de1c719a2bb75dce000`:
  https://github.com/upaymentskwt/woocommerce/commit/49e0f89e67211f7390006de1c719a2bb75dce000

The unreleased branches are evidence only. They are not treated as released production API authority.

---

## Authentication / HMAC findings

### Documented HMAC contract

The current HMAC guide states:

- Bearer authorization remains present.
- `X-Timestamp` and `X-Signature` are required.
- signature algorithm is Base64-encoded HMAC-SHA256.
- payload is timestamp + HTTP method + API path + exact raw request body.
- API path is the portion after `/api/v1/`.
- GET uses an empty request-body component.
- signatures are fresh per request and valid for a one-minute window.

### Contradictory first-party surfaces

The following current first-party sources do not present the same contract:

1. FAQ says requests use Bearer authentication and says HMAC is **currently rolling out**.
2. Charge documentation still presents Bearer authorization without HMAC headers.
3. Get Payment Status still presents Bearer authorization without HMAC headers.
4. Test Mode instructs Postman users to send the Bearer token, despite also publishing an HMAC test secret.
5. The official web SDK and React SDK, published in September 2026, advertise automated Bearer authentication and tell production users to provide a live Bearer API token.
6. The unreleased official WooCommerce `develop` branch adds an `X-Signature` setting/header and `Uplugin-Request: 1`, but does not calculate the documented timestamped HMAC and does not send `X-Timestamp`.
7. That same unreleased WooCommerce `develop` branch still performs its Get Payment Status verification using Bearer-only headers.
8. Its subscription scheduler still calls auto-deduct using Bearer-only headers.

**Classification: PUBLIC EVIDENCE — CONFLICTING.**

There is strong evidence that HMAC rollout is channel/account/endpoint-sensitive or in transition, but no safe public basis for choosing the exact production contract for SUPCheckout.

### Query-string canonicalization

Get Payment Status documents:

- `get-payment-status/{track_id}`
- `get-payment-status?session_id=...`
- `get-payment-status?invoice_id=...`

The HMAC guide says “API path” is the portion after `/api/v1/`, but does not explicitly state whether the query string belongs to the signed path.

For the path-parameter form, the documented formula strongly implies a payload shaped as:

`timestamp + GET + get-payment-status/{track_id} + ""`

For the two query-parameter forms, inclusion/exclusion and encoding/order of query parameters are not specified.

**Classification:**
- track-id path form: **STRONG PUBLIC INFERENCE**
- query canonicalization: **PROVIDER CONFIRMATION REQUIRED**

### HMAC clock skew and errors

The guide says signatures are valid for a “1-minute window.” It does not define whether that means exactly ±60 seconds, a one-sided age check, rounded server minutes, or another tolerance. It names missing, expired and invalid signature failure classes but does not publish stable machine-readable error codes for them.

**Classification: PROVIDER CONFIRMATION REQUIRED** for exact skew semantics and stable error codes.

---

## Webhook-security findings

The FAQ says server-to-server webhooks are signed. The webhook reference documents the payload/transaction fields and recommends using `track_id` for later status reconciliation.

No current public first-party page found in this research specifies:

- signature header name;
- timestamp header name;
- signing algorithm;
- canonical payload;
- signing secret identity;
- replay window;
- key rotation.

**Classification: PROVIDER CONFIRMATION REQUIRED.**

SUPCheckout must continue treating authenticated Get Payment Status as its financial authority unless a separately approved runtime tranche changes that contract.

---

## Tokenization / persistence findings

### Public API contract

Current token/card documentation establishes that:

- `customerUniqueToken` is the primary merchant-supplied identifier used to retrieve a customer's saved cards.
- It must be a numeric, non-predictable value.
- a standalone phone number should not be used.
- UPayments recommends deriving it from merchant-internal user identity plus phone context.
- Retrieve Cards requires the same customer token and returns reusable opaque card tokens.
- KFAST explicitly instructs merchants to pass the same customer token in future requests to retrieve saved card details.

These facts establish a requirement for stable merchant-side customer identity across requests.

### Direct contradiction

The WooCommerce Auto Deduction page says generated customer/card tokens are attached only to order context and are never stored in the local WooCommerce database or the UPayments database.

First-party implementation evidence conflicts with that sentence:

- released/current official WooCommerce code stores `_upay_credit_card_token` and customer-token-related order metadata for subscription operation;
- the September 2026 unreleased 3.1.2 branch explicitly writes:
  `customer_unique_token` into WordPress user meta and uses the resulting token for saved-card retrieval.

The 3.1.2 implementation is not released production authority, but it is sufficient to disprove treating the subscription-page sentence as an unambiguous universal architecture rule.

**Classification: PUBLIC EVIDENCE — CONFLICTING.**

What public evidence does **not** establish:

- whether third-party merchant plugins are contractually permitted to persist customer/card tokens;
- required encryption-at-rest controls;
- retention/deletion requirements;
- key rotation/access-control requirements;
- PCI scope consequences.

Those remain **PROVIDER / PCI CONFIRMATION REQUIRED**.

No protected SUPCheckout token field should be deleted or migrated from this evidence alone.

---

## Payment-state / capture findings

The current gateway-response guide gives a clear generic financial-state contract:

- `CAPTURED` means the transaction succeeded and funds are secured.
- `AUTHORIZED` may still require capture.
- Pending/Processing is not final and should not be treated as failure.
- webhook is recommended for terminal status; Get Payment Status is the reconciliation fallback.

**PUBLICLY RESOLVED:** a generic HTTP/API success envelope is not, by itself, equivalent to proven `CAPTURED` financial state. SUPCheckout is correct to require authoritative captured truth before marking payment successful.

### Auto-deduction-specific contradiction/gap

The released official WooCommerce subscription scheduler calls `auto-deduct` and, when the JSON envelope has `status === true`, reads the returned transaction object, creates a renewal order, writes `UPayments_Result = CAPTURED`, and calls WooCommerce payment completion.

That implementation does not prove the provider contract. The public documentation found in this research does not state that an auto-deduct envelope `status: true` necessarily means funds are captured, nor does it publish a distinct auto-deduct status verification contract.

**Classification: PROVIDER CONFIRMATION REQUIRED** for auto-deduct capture semantics.

### Status reconciliation

Get Payment Status documents `track_id` as unique for each transaction attempt. The official WooCommerce auto-deduct implementation receives/stores a transaction `trackId`.

It is therefore a **STRONG PUBLIC INFERENCE** that an auto-deduct transaction can be reconciled using its returned `trackId`, but no first-party public contract found explicitly guarantees that auto-deduct transactions are queryable through Get Payment Status.

Do not promote recurring `VERIFIED_SUCCESS` from this inference.

---

## Idempotency / cycle identity findings

The current FAQ explicitly says API idempotency keys are not supported at this time.

**PUBLICLY RESOLVED:** there is no documented generic UPayments API idempotency-key facility today.

However, public sources do not establish that any of these fields provide idempotent mutation semantics:

- `order.id`
- `requested_order_id`
- `reference.id`
- `paymentId`
- `trackId`

`track_id` is documented as a unique **transaction attempt** identifier, not a merchant renewal-cycle key.

The official WooCommerce auto-deduct implementation checks returned `paymentId` to avoid creating a second local renewal order for the same returned provider payment, but this happens after a response exists. It does not solve timeout-after-dispatch ambiguity.

**Classification: PROVIDER CONFIRMATION REQUIRED** for:
- merchant-supplied deduplication semantics;
- unique provider renewal-cycle identity;
- reconciliation after timeout before a provider identifier is known;
- safe retry procedure after ambiguous auto-deduct dispatch.

SUPCheckout's no-blind-retry / HELD behavior remains justified.

---

## Sandbox credential-family findings

Current Test Mode/HMAC pages publish a newer Bearer-key family and an HMAC test secret.

Current Postman, Add Card, Create Customer Unique Token and several endpoint examples still use the older `jtest123`-era family.

SUPCheckout's already-recorded non-destructive sandbox observations found:

- newer documented Bearer/HMAC variants at Charge returned HTTP 403;
- `jtest123` reached HTTP 422 schema validation.

Those observations prove behavioral difference, not which family is contractually canonical.

**Classification: PUBLIC EVIDENCE — CONFLICTING.**

Still unresolved publicly:

- which credential family new server-to-server integrations should use;
- whether the new HMAC secret pairs with both published Bearer variants;
- whether activation, merchant binding, whitelabel selection, IP allowlisting or a special plugin/request header is required;
- whether `jtest123` is current, legacy or endpoint-specific.

---

## Question-by-question disposition of the original 32-provider questionnaire

| # | Question area | Public disposition | Provider still needed? |
|---:|---|---|---|
| 1 | HMAC mandatory for production Charge | CONFLICTING | **YES** |
| 2 | HMAC mandatory for Get Payment Status | CONFLICTING; unreleased official plugin still Bearer-only for status verification | **YES** |
| 3 | universal vs merchant/endpoint rollout | FAQ proves rollout exists; scope absent | **YES** |
| 4 | enforcement date | no public date found | **YES** |
| 5 | Bearer-only production validity | current SDK/released surfaces support Bearer, but account scope unknown | **YES** |
| 6 | HMAC query parameters included in API path | undocumented | **YES** |
| 7 | exact status canonical strings | path-form strongly inferable; query forms not | **YES**, query forms |
| 8 | exact ±60s skew | “1-minute window” only | **YES** |
| 9 | stable HMAC error codes | not published | **YES** |
| 10 | webhook headers/algorithm | not published | **YES** |
| 11 | webhook secret same as API Secret | not published | **YES** |
| 12 | webhook replay/timestamp policy | not published | **YES** |
| 13 | may persist customer token locally | first-party conflict | **YES** |
| 14 | may persist card token locally | first-party conflict | **YES** |
| 15 | meaning of “never stored locally” | first-party conflict | **YES** |
| 16 | token storage controls | not published | **YES** |
| 17 | token-storage PCI scope/control | not published | **YES / PCI authority** |
| 18 | auto-deduct field proving captured | not published | **YES** |
| 19 | HTTP/API success vs capture | generic contract resolved: success envelope alone is insufficient; auto-deduct-specific mapping absent | **YES**, auto-deduct-specific |
| 20 | auto-deduct verifiable via status API | strong inference only | **YES** |
| 21 | identifier for auto-deduct verification | `track_id` is strongest public candidate | **YES** |
| 22 | auto-deduct can transition after response | generic statuses can transition; auto-deduct-specific behavior absent | **YES** |
| 23 | idempotency key support | FAQ: none documented | **NO** for generic API; **YES** only if auto-deduct has an exception |
| 24 | merchant values as idempotency equivalent | undocumented | **YES** |
| 25 | unique renewal-cycle identifier | transaction-attempt IDs are not documented as cycle keys | **YES** |
| 26 | timeout-after-dispatch reconciliation | no safe complete procedure published | **YES** |
| 27 | safe ambiguous retry | no procedure published | **YES** |
| 28 | newer sandbox keys operationally active | published but existing probe returned 403 | **YES** |
| 29 | one HMAC secret pairs with both test Bearer keys | page presentation suggests it but does not define binding | **YES** |
| 30 | `jtest123` current vs legacy | still published, but conflicts with new family | **YES** |
| 31 | hidden sandbox prerequisites | not published | **YES** |
| 32 | intended scope of both credential families | not published | **YES** |

---

## What public research closes now

The following decisions no longer need to be treated as wholly unknown:

1. **Captured truth:** `CAPTURED` is the documented funds-secured state; SUPCheckout must not equate generic success/acceptance with capture.
2. **Generic idempotency:** UPayments publicly states that API idempotency keys are not currently supported.
3. **Status identifiers:** `track_id` is a unique transaction-attempt identifier; status can also be queried by session/invoice identifiers.
4. **Stable customer identity:** saved-card retrieval requires stable `customerUniqueToken` reuse.
5. **HMAC algorithm mechanics:** if/where the documented dynamic-HMAC contract applies, the algorithm/body/timestamp/header mechanics are publicly specified.
6. **The remaining HMAC problem is applicability/canonicalization**, not the cryptographic primitive.
7. **The token-storage conflict is real first-party inconsistency**, not merely a SUPCheckout assumption.
8. **The sandbox-family conflict is real first-party inconsistency**, not merely a failed test.
9. **Official provider code must not be copied as authoritative financial logic:** the current official subscription scheduler promotes `status:true` to local `CAPTURED` without a separately documented capture proof.

## Binding release decision after this research

No runtime tranche is authorized from public research alone.

```text
HMAC production applicability: PROVIDER CLARIFICATION REQUIRED
webhook signature contract: PROVIDER CLARIFICATION REQUIRED
third-party token persistence/control contract: PROVIDER / PCI CLARIFICATION REQUIRED
auto-deduct captured truth: PROVIDER CLARIFICATION REQUIRED
auto-deduct safe ambiguous retry/cycle identity: PROVIDER CLARIFICATION REQUIRED
sandbox canonical credential family: PROVIDER CLARIFICATION REQUIRED

automatic recurring VERIFIED_SUCCESS: FAIL-CLOSED / UNREACHABLE
publication: NOT AUTHORIZED
```

If provider clarification cannot be obtained, the safe release posture is to keep unsupported/unverified surfaces excluded from production claims and keep the core release blocked wherever the unresolved contract affects core one-time payment authentication.

## Contact strategy if written email is unreliable

A verbal support response is useful for discovery but not sufficient as durable certification evidence. If owner authorization is granted, use any official support/meeting channel needed to obtain clarification, then request the final answers in writing (email/ticket/transcript) and retain them as evidence before changing runtime or production claims.
