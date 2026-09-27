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
- PCI-DSS Level 1 Compliance: https://developers.upayments.com/reference/pcidss-level-1-compliance
- Contact UPayments: https://developers.upayments.com/page/contact-upayments

### Current first-party SDK

- Official web SDK: https://www.npmjs.com/package/@upayments-kw/web-sdk
- Official React SDK: https://www.npmjs.com/package/@upayments-kw/react
- Official examples repository: https://github.com/upaymentskwt/web-sdk-examples
- Official OpenCart integration: https://github.com/upaymentskwt/opencart
- Official CS-Cart integration: https://github.com/upaymentskwt/cs-cart (including unreleased `hmac-signature` branch `c6ecc24839aea08ac84e84efad3bad1efbc78375`)
- Official Magento integration: https://github.com/upaymentskwt/magento

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
9. The official OpenCart `main` is older (`4a35acd777f7e8f1b5fc8e4b228e13f4bd22c3ea`, 2023-12-28) and Bearer-only. The newer `V4.0` branch (`02a859a5c06ec762cfd2ccc9a75f2de2f4a12b77`, 2026-09-23; commit message `Applied HMAC on charge, create-customer-unique-token and check-payment-button-status apis`) instead sends Bearer + `Uplugin-Request: 1` + a static `X-Signature` value and still has no `X-Timestamp` or local implementation of the documented timestamped HMAC.
10. The official CS-Cart repository `main`, pushed 2026-09-21, likewise uses Bearer-only requests. However, its unreleased `hmac-signature` branch (`c6ecc24839aea08ac84e84efad3bad1efbc78375`, 2026-09-21) adds the same configured/static `X-Signature` + `Uplugin-Request: 1` pattern as the WooCommerce HMAC work, again with no `X-Timestamp` and no per-request HMAC calculation.
11. The older Magento repository also uses Bearer-only API requests.

**Classification: PUBLIC EVIDENCE — CONFLICTING.**

There is strong evidence that HMAC rollout is channel/account/endpoint-sensitive or in transition, but no safe public basis for choosing the exact production contract for SUPCheckout.

The repeated WooCommerce + CS-Cart + OpenCart V4 implementation pattern supports a stronger distinction: UPayments appears to have an **official-plugin static signature / merchant-validation scheme** (`X-Signature` + `Uplugin-Request`) that is technically different from the documented **dynamic request HMAC scheme** (`X-Timestamp` + Base64 HMAC-SHA256). Public sources do not define the relationship between these two schemes. They must not be treated as interchangeable.

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

UPayments' current PCI page publicly states that UPayments itself is PCI-DSS Level 1 and that merchant scope is generally reduced to SAQ-A or SAQ-A-EP depending on checkout model. It does **not** specify how merchant persistence of UPayments customer/card tokens affects that scope.

Those token-persistence specifics remain **PROVIDER / PCI CONFIRMATION REQUIRED**.

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

SUPCheckout's recorded non-destructive sandbox evidence includes two layers:

- exploratory probes: newer documented Bearer/HMAC variants at Charge returned HTTP 403; an intentionally non-final `jtest123` probe reached HTTP 422 schema validation;
- permanent repository certification: PR #49 exact head `36b63fcdead47ebd6c8fcd3f1b2a993fc2852939` ran Provider Sandbox Certification successfully on 2026-09-06. Its bounded valid Charge initialization used Bearer `jtest123` only at `sandboxapi.upayments.com/api/v1/charge` and proved exact HTTP 201, strict `status=true`, structured response data, and a valid HTTPS UPayments sandbox payment link. No payment was completed. The same bounded certification harness and public token remain present on current `main`.

Current first-party Postman documentation still publishes `jtest123` for the non-whitelabel sandbox environment.

Therefore **RESOLVED_FIRST_PARTY for the tested sandbox Charge path:** `jtest123` is still a currently documented sandbox credential and has independently passed the bounded Bearer-only Charge initialization certification. This does **not** prove that it is the preferred long-term credential family, that other endpoints accept it, or that production accounts share the same HMAC policy.

**Overall credential-family classification: PUBLIC EVIDENCE — CONFLICTING.** The bounded `jtest123` Charge result is resolved; the intended migration/lifecycle relationship between the older and newer sandbox families is not.

Additional official-code evidence increases the ambiguity: the unreleased 3.1.2 WooCommerce branch changes its test-mode API host from `sandboxapi.upayments.com` to `dev-apiv2api.upayments.com`, while the current public Test Mode documentation still instructs integrations to use `sandboxapi.upayments.com`.

Still unresolved publicly:

- which host and credential family new server-to-server integrations should use;
- whether the new HMAC secret pairs with both published Bearer variants;
- whether activation, merchant binding, whitelabel selection, IP allowlisting or a special plugin/request header is required;
- whether `jtest123` is current, legacy or endpoint-specific.

---

## Question-by-question disposition of the original 32-provider questionnaire

Required classification vocabulary:

- `RESOLVED_FIRST_PARTY`
- `PARTIALLY_RESOLVED`
- `CONTRADICTED_BY_FIRST_PARTY_SOURCES`
- `UNRESOLVED_REQUIRES_PROVIDER`
- `REQUIRES_LIVE_TEST`
- `REQUIRES_LEGAL/PCI`
- `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE`

The classification is the **current primary disposition**. Notes preserve deferred/future obligations where a question is outside the first-release scope.

| # | Question area | Classification | Evidence / exact contract | Confidence | SUPCheckout / first-release action |
|---:|---|---|---|---|---|
| 1 | HMAC mandatory for production Charge | `CONTRADICTED_BY_FIRST_PARTY_SOURCES` | HMAC guide says every authenticated request uses dynamic HMAC; Charge/FAQ/SDK surfaces still show Bearer and FAQ says rollout is in progress | HIGH | **Core blocker.** Current Charge egress is Bearer-only; do not change runtime until production applicability is confirmed |
| 2 | HMAC mandatory for Get Payment Status | `CONTRADICTED_BY_FIRST_PARTY_SOURCES` | HMAC guide includes GET; current Status page shows Bearer; unreleased official WooCommerce status verification remains Bearer-only | HIGH | **Core blocker.** Current `StatusVerifier` is Bearer-only |
| 3 | universal vs merchant/account/endpoint/channel rollout | `PARTIALLY_RESOLVED` | FAQ proves rollout exists; public sources do not define rollout scope | HIGH that rollout exists / LOW on scope | Fold into the core production-auth clarification |
| 4 | HMAC enforcement date/policy | `UNRESOLVED_REQUIRES_PROVIDER` | no authoritative public enforcement date or account-policy rule found | HIGH | Ask only for the policy applicable now to a third-party production merchant; a historical rollout date is not independently release-critical |
| 5 | Bearer-only production validity | `CONTRADICTED_BY_FIRST_PARTY_SOURCES` | current SDK/Charge/Status/FAQ surfaces still support or describe Bearer while HMAC guide says signatures are required | HIGH | **Core blocker.** Resolve together with #1–#4 |
| 6 | HMAC query parameters included in signed API path | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | public docs do not define query canonicalization; SUPCheckout rejects status URLs containing a query and uses only `get-payment-status/{track_id}` | HIGH | No first-release contact/runtime work; revisit only if query-form status lookup is added |
| 7 | exact status canonical path/string for active SUPCheckout route | `RESOLVED_FIRST_PARTY` | current Status docs define `GET get-payment-status/{track_id}`; HMAC guide says sign the path immediately after `/api/v1/`; GET body is empty | HIGH | Existing route shape conforms; only HMAC applicability remains unresolved |
| 8 | exact ±60-second HMAC skew semantics | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | guide documents a one-minute validity window but not symmetric tolerance | HIGH | Outbound requests can use current UTC seconds; exact server tolerance need not be known to fail closed |
| 9 | stable HMAC failure/error codes | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | guide names missing/expired/invalid signature classes but no stable machine codes | HIGH | First release can treat authentication failure as failure without code-specific recovery |
| 10 | webhook signature headers/algorithm | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | FAQ says webhooks are signed; Webhook page does not publish verification mechanics | HIGH | Webhook is not financial truth; current flow reconciles via authenticated Status API |
| 11 | webhook secret same as outbound API Secret | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | not publicly specified | HIGH | Future defense-in-depth tranche only |
| 12 | webhook replay/timestamp policy | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | not publicly specified | HIGH | Future defense-in-depth tranche only |
| 13 | may persist customer token locally | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | first-party docs/code conflict | HIGH | Saved-card production claim is excluded; keep safer current design and defer contract decision |
| 14 | may persist card token locally | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | first-party docs/code conflict | HIGH | Saved-card production claim is excluded |
| 15 | meaning of “never stored locally” | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | provider subscription prose conflicts with provider implementation | HIGH | Defer until saved-card/subscription scope |
| 16 | token storage controls | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | encryption/retention/rotation/access rules not publicly specified | HIGH | Future saved-card work only |
| 17 | token-storage PCI scope/control | `REQUIRES_LEGAL/PCI` | UPayments states its own PCI Level 1 posture but does not define merchant opaque-token persistence scope | HIGH | **Deferred from first release because saved cards are excluded.** A qualified PCI/acquirer determination is still required before future production token persistence |
| 18 | auto-deduct field proving captured | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | no normative public auto-deduct capture field contract found | HIGH | Recurring is excluded and `VERIFIED_SUCCESS` remains unreachable |
| 19 | generic HTTP/API success vs financial capture | `RESOLVED_FIRST_PARTY` | gateway-response docs define `CAPTURED` as funds secured; a generic success envelope is not equivalent to captured truth | HIGH | SUPCheckout already conforms by requiring authenticated provider transaction truth |
| 20 | auto-deduct verifiable via Status API | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | strong inference from returned `trackId`, no explicit auto-deduct contract | MEDIUM | Defer recurring |
| 21 | identifier for auto-deduct verification | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | `track_id` is documented per transaction attempt, not specifically as recurring authority | MEDIUM | Defer recurring |
| 22 | auto-deduct state can transition after initial response | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | generic states can transition; auto-deduct-specific behavior is unpublished | MEDIUM | Defer recurring |
| 23 | generic API idempotency-key support | `RESOLVED_FIRST_PARTY` | FAQ explicitly says idempotency keys are not supported at this time | HIGH | Existing no-blind-retry invariant is compatible; no runtime relaxation |
| 24 | merchant fields as idempotency equivalent | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | no deduplication semantics documented for `order.id`, `reference.id`, or `requested_order_id` | HIGH | Defer recurring; never infer idempotency |
| 25 | unique renewal-cycle identifier | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | provider transaction-attempt IDs are not documented as merchant billing-cycle IDs | HIGH | Defer recurring |
| 26 | timeout-after-auto-deduct reconciliation | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | no complete public safe procedure found | HIGH | Defer recurring; current HELD/no-blind-retry posture remains |
| 27 | safe ambiguous auto-deduct retry | `NOT_NEEDED_FOR_FIRST_RELEASE_SCOPE` | no public procedure found | HIGH | Defer recurring |
| 28 | newer published sandbox keys operationally active | `REQUIRES_LIVE_TEST` | keys are currently published; earlier bounded probes observed HTTP 403 | MEDIUM | Not a core-release blocker while the current documented `jtest123` Charge path is certified; test only if needed for a future credential migration |
| 29 | one HMAC test secret pairs with both published Bearer variants | `PARTIALLY_RESOLVED` | HMAC/Test Mode pages present one secret alongside two alternative Bearer keys, but binding/activation prerequisites are not defined | MEDIUM | No first-release dependency until dynamic HMAC applicability is confirmed |
| 30 | `jtest123` operational/documented status | `RESOLVED_FIRST_PARTY` | current Postman environment still publishes it; PR #49 independently certified Bearer-only sandbox Charge on 2026-09-06 | HIGH | Keep evidence bounded to sandbox Charge; do not extrapolate to production or other endpoints |
| 31 | hidden sandbox prerequisites / sandbox-vs-dev host | `CONTRADICTED_BY_FIRST_PARTY_SOURCES` | public Test Mode says `sandboxapi.upayments.com`; unreleased WooCommerce 3.1.2 routes general test API traffic to `dev-apiv2api.upayments.com` while other helpers still use sandbox | HIGH | Not a core blocker for the already-certified public sandbox Charge path; resolve before migrating test infrastructure |
| 32 | intended scope/lifecycle of both public sandbox credential families | `UNRESOLVED_REQUIRES_PROVIDER` | public pages expose both families but do not define migration/deprecation scope | HIGH | Defer unless the newer family becomes required for the production-auth tranche or acceptance test |

### Immediate provider-contact reduction

For the **first public core one-time-payment release**, only #1–#5 remain provider-dependent release blockers. They collapse into a small set of production-authentication questions because #3–#5 are dimensions of the same HMAC/Bearer applicability decision.

Questions #6 and #8–#18, #20–#22, and #24–#27 remain documented future/security/feature questions but do not justify blocking the narrower first release. Questions #7, #19, #23 and #30 are resolved to the bounded extent stated above. Questions #28–#29 and #31–#32 are sandbox/migration follow-ups, not proof that the current production core contract is safe.

---

## Additional current-documentation inconsistencies reviewed

### Get Payment Status rate limit

The dedicated Get Payment Status page currently states **30 requests per minute**. The current FAQ separately states **800 requests per minute** for Refund and Status-Query endpoints.

SUPCheckout's existing `StatusRateGate` is deliberately capped at **30/minute per credential/mode scope**, matching the stricter dedicated endpoint documentation. Because 30 is safe under either published limit, this contradiction does **not** justify a runtime change. Keep the conservative 30/minute gate unless UPayments publishes a single authoritative replacement contract.

### WooCommerce Blocks support wording

The dedicated “Core Block Checkout Support” page describes native standard-product Block Checkout support, while the current FAQ still says the UPayments plugin is not natively supported by the new Checkout Block and recommends Classic Checkout.

This contradiction concerns the official provider plugin, not SUPCheckout's independently implemented/certified Blocks adapter. It does not change SUPCheckout's bounded Blocks evidence or release claim.

---

## What public research closes now

The following decisions no longer need to be treated as wholly unknown:

1. **Captured truth:** `CAPTURED` is the documented funds-secured state; SUPCheckout must not equate generic success/acceptance with capture.
2. **Generic idempotency:** UPayments publicly states that API idempotency keys are not currently supported.
3. **Status identifiers:** `track_id` is a unique transaction-attempt identifier; status can also be queried by session/invoice identifiers.
4. **Stable customer identity:** saved-card retrieval requires stable `customerUniqueToken` reuse.
5. **HMAC algorithm mechanics:** if/where the documented dynamic-HMAC contract applies, the algorithm/body/timestamp/header mechanics are publicly specified.
6. **The remaining first-release HMAC problem is production applicability**, not the cryptographic primitive or the active track-ID route shape. Query-form canonicalization is deferred because SUPCheckout does not use query-form status lookup.
7. **The token-storage conflict is real first-party inconsistency**, not merely a SUPCheckout assumption.
8. **`jtest123` is currently documented for the non-whitelabel sandbox and independently passed the bounded sandbox Charge certification on 2026-09-06.** The newer published family still conflicts with observed behavior/documentation; its migration scope/host/prerequisites remain unresolved.
9. **Official provider code must not be copied as authoritative financial logic:** the current official subscription scheduler promotes `status:true` to local `CAPTURED` without a separately documented capture proof.

## Binding release decision after this research

No runtime tranche is authorized from public research alone.

```text
FIRST-RELEASE CORE BLOCKER:
production Charge + track-id Status authentication/HMAC applicability: PROVIDER CLARIFICATION REQUIRED

DEFERRED / NOT FIRST-RELEASE BLOCKERS:
webhook signature mechanics: FUTURE DEFENSE-IN-DEPTH
third-party token persistence/control: FUTURE SAVED-CARD + PROVIDER / PCI GATE
auto-deduct captured truth/retry/cycle identity: FUTURE RECURRING GATE
new sandbox credential-family migration: FUTURE TEST-INFRASTRUCTURE GATE

automatic recurring VERIFIED_SUCCESS: FAIL-CLOSED / UNREACHABLE
publication: NOT AUTHORIZED
```

If provider clarification cannot be obtained, the safe release posture is to keep unsupported/unverified surfaces excluded from production claims and keep the core release blocked wherever the unresolved contract affects core one-time payment authentication.

## Contact strategy if written email is unreliable

UPayments' current first-party Contact page publishes multiple official escalation paths for UInterfaceV2/API questions: dedicated API technical-support email, phone/WhatsApp, and a Calendly technical-meeting route.

No channel may be used until `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES` is explicitly granted.

If authorization is granted and email is slow or unanswered:

1. use the official API-support email first so the three-question production-auth request has a durable timestamped record;
2. escalate through the published official WhatsApp/phone or Calendly technical meeting rather than relying on unofficial contacts;
3. use any verbal/meeting answer for discovery only;
4. request the final endpoint-by-endpoint authentication answer in writing (email/ticket/transcript) and retain it as provider-contract evidence before changing runtime or production claims.

A verbal answer alone is not sufficient to authorize a payment-runtime tranche.
