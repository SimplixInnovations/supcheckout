# UPayments Sandbox Auth Observation — 2026-09-26

**Classification:** `SANDBOX_AUTH_OBSERVATION` (NOT `PRODUCTION_AUTH_CONTRACT`)

**Endpoint:** `POST https://sandboxapi.upayments.com/api/v1/charge`  
**Fetched:** 2026-09-26T16:33Z UTC  
**Credentials:** publicly documented Test Mode / sandbox examples (ephemeral; not committed as source constants)

## HMAC spec (first-party HMAC Authentication page)

```text
payload = timestamp + HTTP_METHOD + API_PATH + REQUEST_BODY
API_PATH = path immediately after /api/v1/  (e.g. "charge")
signature = Base64(HMAC-SHA256(payload, API Secret))
X-Timestamp: Unix UTC seconds
X-Signature: generated signature
Missing X-Signature/X-Timestamp → request rejected
Signatures valid ~1 minute (replay window)
```

## Observations (synthetic Charge init; no payment completed; Location not followed)

| Case | HTTP | Location/session identity | Message |
|---|---|---|---|
| A Bearer-only (`jtest123`) | 302 | yes | hosted payment page |
| B Bearer + valid HMAC | 302 | yes | hosted payment page |
| C Bearer + invalid HMAC | 401 | no | rejected |
| D HMAC headers absent (HMAC-capable secret known) | 302 | yes | hosted payment page |

Alternate documented Test Mode Bearer key (`f718…` family) returned `403 Not a valid API request` for the same synthetic payload — credential families are not interchangeable without provider clarification.

## Decision rules (binding)

- Bearer-only success does **NOT** prove HMAC globally optional.
- Valid-HMAC success does **NOT** prove HMAC globally mandatory.
- Invalid-HMAC rejection proves only that a supplied invalid signature is rejected.
- Sandbox behavior does **NOT** prove live merchant behavior.

```text
HMAC: PROVIDER CLARIFICATION REQUIRED
```
