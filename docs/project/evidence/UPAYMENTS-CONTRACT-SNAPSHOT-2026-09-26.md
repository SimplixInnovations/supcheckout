# UPayments Contract Snapshot — 2026-09-26

**Purpose:** first-party documentation evidence for release-readiness classification.  
**Rule:** do not copy entire provider pages. Short excerpts only.  
**Not production auth authority.** Sandbox behavior is `SANDBOX_AUTH_OBSERVATION` only.

## Sources (fetched for this program; record provider “updated” date if exposed)

| Source | Relevant claim | Classification | Conflict |
|---|---|---|---|
| HMAC Authentication | `Authorization` + `X-Timestamp` + `X-Signature` required; signature window ≈ one minute; path after `/api/v1/`; GET body empty | PROVIDER CLARIFICATION REQUIRED | Conflicts with Charge/Status Bearer docs |
| Make charge (Charge) | `Authorization: Bearer` documented | PROVIDER CLARIFICATION REQUIRED | Conflicts with HMAC guide |
| Get Payment Status | `Authorization: Bearer` documented | PROVIDER CLARIFICATION REQUIRED | Conflicts with HMAC guide |
| FAQ | “all requests use Bearer”; “HMAC signatures are currently rolling out” | PROVIDER CLARIFICATION REQUIRED | Suggests rollout, not universal mandate |
| Test Mode | public test-key family + HMAC secret | Evidence of multiple sandbox credential families | vs Postman/Add Card `jtest123` examples |
| Postman Collection / Add Card / Create Customer Unique Token | `jtest123`-era public sandbox examples | Evidence of multiple public examples | Do not call uniquely canonical |
| Webhook | reference does not establish complete verification algorithm/header contract | PROVIDER CLARIFICATION REQUIRED | FAQ says webhooks are signed |
| FAQ (webhook) | server-to-server webhooks are signed | PROVIDER CLARIFICATION REQUIRED | No stable header/algorithm contract |
| Auto Deduction (Subscriptions) | generated customer/card tokens are never stored locally | PROVIDER CLARIFICATION REQUIRED | vs SUPCheckout protected metadata |
| Subscription guide | same token statement | PROVIDER CLARIFICATION REQUIRED | may describe provider plugin architecture only |

## Authentication contradiction (exact)

```text
HMAC guide:   Authorization + X-Timestamp + X-Signature required
Charge page:  Authorization: Bearer documented
Status page:  Authorization: Bearer documented
FAQ:          all requests use Bearer; HMAC currently rolling out
```

**HMAC: PROVIDER CLARIFICATION REQUIRED**  
Do not declare mandatory or optional from docs alone.

## Sandbox credentials

Current Test Mode exposes one public test-key family plus HMAC secret.  
Postman/Add Card/Create Token pages still expose `jtest123`-era examples.  
Neither family is uniquely canonical unless UPayments confirms.

## Tokens

Provider subscription guide: generated customer/card tokens are **never stored locally**.  
SUPCheckout currently retains protected metadata:

```text
_upay_credit_card_token
_upay_customer_unique_token
```

**token persistence: PROVIDER CLARIFICATION REQUIRED**  
Do not delete/migrate protected fields without approved migration design.

## Webhook security

FAQ: signed. Webhook reference: no complete verification contract.  
Defense-in-depth clarification item only. Payment authority remains StatusVerifier.

## Capture / cycle (from earlier audits, still open)

```text
auto-deduct CAPTURED semantics: UNPROVEN
remote recurring-cycle identity: UNPROVEN
automatic recurring VERIFIED_SUCCESS: FAIL-CLOSED / UNREACHABLE
```
