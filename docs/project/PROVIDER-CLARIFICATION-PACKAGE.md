# UPayments Provider Clarification Package

**Status:** EXTERNAL REQUIRED until first-party answers exist  
**Last evidence update:** 2026-09-25  
**No secrets in this document.**

## Why this package exists

SUPCheckout cannot honestly promote several recurring/payment contracts without first-party clarification. Fail-closed engineering is correct; production recurring acceptance is not.

## Exact questions for UPayments

1. Are `X-Signature` and `X-Timestamp` mandatory for **Charge** today (in addition to `Authorization`)?
2. Are `X-Signature` and `X-Timestamp` mandatory for **Get Payment Status**?
3. What exact HMAC path string includes query parameters, if any? (canonical method + path + body)
4. For WooCommerce auto-deduction, may merchant software persist the **customer token** locally?
5. May it persist the **credit-card token** locally?
6. If yes, required encryption / retention / access-control / deletion conditions?
7. What exact auto-deduct response proves funds are irrevocably **CAPTURED** (not merely accepted/initialized)?
8. Which remote field uniquely identifies one recurring billing cycle and is safe for idempotency/reconciliation?

## Current first-party evidence (contradictory)

| Source | Claim |
|---|---|
| HMAC Authentication guide | Authenticated requests require `Authorization` + `X-Timestamp` + `X-Signature`; missing signature headers rejected |
| Charge endpoint page | Documents `Authorization: Bearer` |
| Get Payment Status page | Documents `Authorization: Bearer` |
| Subscription/token docs | Generated customer/card tokens are **not** stored in the local WooCommerce DB |

## Current SUPCheckout observation (public sandbox)

| Item | Observation |
|---|---|
| Public sandbox token historically used in CI | `jtest123` (see `provider-sandbox-certification.yml`) |
| Sandbox Charge initialization | Succeeds Bearer-only in existing bounded smoke |
| HMAC headers sent | No |
| Live merchant charge | Never performed |

## HMAC decision rule (unchanged)

If safe first-party/sandbox evidence later proves HMAC is mandatory for SUPCheckout egresses, implement a full cross-egress HMAC contract (secret storage/redaction, timestamp, canonical method/path/body, GET empty-body signing, Base64 HMAC-SHA256, clock window, test vectors, upgrade/rollback) and re-run the safety matrix.

Until then:

```text
HMAC: PROVIDER CLARIFICATION REQUIRED
```

Do **not** implement speculative signing.

## Token persistence audit (local facts)

| Field | Stored | Encrypted by plugin | Readers | Retention | Deleted |
|---|---|---|---|---|---|
| `_upay_credit_card_token` | order meta (protected identity) | No (H12 identity secret is separate) | authenticated admin/order context | until order/token lifecycle | not automatic |
| `_upay_customer_unique_token` | order meta (protected identity) | No | same | same | not automatic |
| `upayments_token_identity_secret_v2` | non-autoload option | HMAC-verified identity, not token ciphertext | plugin crypto only | persistent | manual/migration |

Renewals require the card/customer token to call auto-deduct. Deleting protected metadata without an approved migration would break renewals and violate the protected-identity contract.

```text
token persistence: PROVIDER CLARIFICATION REQUIRED
```

## Capture / cycle identity (still open)

```text
auto-deduct CAPTURED semantics: UNPROVEN
remote recurring-cycle identity: UNPROVEN
automatic recurring VERIFIED_SUCCESS: UNREACHABLE / FAIL-CLOSED
```

## Required recurring classification

```text
engineering safety: VERIFIED
provider contract: EXTERNAL REQUIRED
automatic recurring VERIFIED_SUCCESS: UNREACHABLE / FAIL-CLOSED
```
