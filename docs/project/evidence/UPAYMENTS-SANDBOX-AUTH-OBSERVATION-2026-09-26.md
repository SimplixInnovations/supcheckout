# UPayments Sandbox Auth Observation — 2026-09-26 (FINAL-3)

**Classification:** `SANDBOX_AUTH_OBSERVATION` (never `PRODUCTION_AUTH_CONTRACT`)
**Headers:** `Accept: application/json` + `Content-Type: application/json` on every Charge probe
**Body:** byte-identical raw body used for signing and transmission
**Previous observation without Accept header is SUPERSEDED.**

## Credential families (accurate)

| Family | Source | Notes |
|---|---|---|
| Current Test Mode | developers.upayments.com Test Mode: Non-whitelabel/Whitelabel API keys + documented HMAC Secret | current family |
| jtest123 | Postman Collection / Add Card / Create Customer Token pages | still-published legacy/currently-still-published examples |
| cross-family (jtest123 Bearer + Test Mode HMAC secret) | not a provider-documented pair | if executed, classify as CROSS-FAMILY SANDBOX OBSERVATION |

## HMAC spec (first-party)

```text
payload = timestamp + HTTP_METHOD + API_PATH + REQUEST_BODY
API_PATH = path immediately after /api/v1/ (e.g. charge)
signature = Base64(HMAC-SHA256(payload, API Secret))
X-Timestamp: Unix UTC seconds
X-Signature: signature
Missing headers rejected; ~1 minute replay window
```

## Charge observations (supersede prior)

### Current Test Mode family (`f718…` Bearer + documented HMAC Secret)

| Case | HTTP | Result |
|---|---|---|
| Bearer-only | 403 | Not a valid API request |
| Bearer + valid HMAC | 403 | Not a valid API request |
| Bearer + invalid HMAC | 403 | Not a valid API request |
| HMAC headers absent | 403 | Not a valid API request |

Preserved exactly. Do not manipulate payloads until success.

### jtest123 family (Postman/Add Card examples)

| Case | HTTP | Result |
|---|---|---|
| Bearer-only | 422 | Parameter is missing please check product name, quantity, price, quantity at 0 |
| HMAC with this Bearer | not paired in docs | not treated as canonical Test Mode behavior |

Schema completion used documented Charge fields (`order.currency`, `order.amount`, `returnUrl`, `cancelUrl`, `notificationUrl`, `products[].name/quantity/price`) from validation errors; further payload iteration stopped.

## Get Payment Status

```text
session_id extracted: NO (no Location/session identity from corrected-header Charge)
Bearer Status: NOT TESTED
valid-HMAC Status: NOT TESTED
invalid-HMAC Status: NOT TESTED
QUERY_CANONICALIZATION = PROVIDER CLARIFICATION REQUIRED
```

## Binding

```text
HMAC = PROVIDER CLARIFICATION REQUIRED
```
