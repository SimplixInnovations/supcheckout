# R3 Provider Contract Audit (Part VI–VIII)

**Status:** evidence package for independent review
**Last independent-review evidence update:** 2026-09-24
**Browser Use / open-web search:** unavailable for the original audit; 2026-09-24 review recorded current first-party documentation classification below
**Sources used:** repository Phase 8C comments; existing certified request/response contracts in `Scheduler.php` / `StatusVerifier.php`; current UPayments HMAC Authentication documentation; current individual endpoint documentation (Charge / Get Payment Status); current UPayments subscription / token documentation

## Capture semantics (auto-deduct)

```text
What exact endpoint is auto-deduct?
  Repo route name: getAPIUrl('auto-deduct') — exact public path is provider-owned.
What exact success status proves funds captured?
  UNPROVEN. Phase 8C: truthy top-level `status` is historical compatibility only.
What exact field is authoritative?
  UNPROVEN for auto-deduct CAPTURED semantics.
Can a completed payment be queried afterward?
  Charge path uses StatusVerifier (get-payment-status). Auto-deduct query contract UNPROVEN.
```

**Decision:** keep all positive auto-deduct responses `UNRESOLVED`/HELD. Automatic paid-renewal finalization remains disabled pending provider-contract proof.

Documented product behavior:

```text
subscription recurring dispatch exists
automatic paid-renewal finalization disabled pending provider-contract proof
```

## Remote cycle identity

```text
Merchant-controlled fields on Charge: order.id, reference.id
Auto-deduct echo contract: UNPROVEN (not assumed identical to Charge)
Parent-level UPayments_Ref reused across cycles is NOT cycle-unique
```

**Decision:** exact remote cycle binding remains **UNPROVEN**. Parent ref echo is optional consistency check only, not cycle binding.

## HMAC contract (Part VII)

Independent review evidence dated **2026-09-24**:

Current UPayments HMAC Authentication documentation states authenticated requests use:

```text
Authorization
X-Timestamp
X-Signature
```

and describes missing signature headers as rejected.

However current individual endpoint documentation such as Charge / Get Payment Status still presents Bearer credentials.

| Endpoint | Documented auth | Sandbox | Repo behavior | Gap |
|---|---|---|---|---|
| charge | Bearer (endpoint pages) / Bearer+X-Timestamp+X-Signature (HMAC Authentication page) | not exercised | Bearer only | CONTRADICTORY FIRST-PARTY DOCUMENTATION |
| get-payment-status | Bearer (endpoint page) | StatusVerifier uses Bearer | Bearer only | same conflict if HMAC mandatory |
| retrieve cards | Bearer | existing getSavedCards | Bearer only | same |
| create customer token | Bearer | existing token flow | Bearer only | same |
| auto-deduct | Bearer | not exercised | Bearer only | same |
| availability | Bearer | existing | Bearer only | same |

**Classification:** `CONTRADICTORY FIRST-PARTY DOCUMENTATION` / `PROVIDER CLARIFICATION REQUIRED`

**Decision:** Do **not** add HMAC only to Scheduler. If HMAC is confirmed mandatory, it requires a separate cross-egress credential/security design covering all three accepted egress sites (gateway transport, StatusVerifier, Scheduler renewal dispatch). R3 remains Bearer-only and fail-closed on auth ambiguity.

## Token storage (Part VIII)

Current UPayments subscription documentation states generated customer/card tokens are not stored in the local WooCommerce database.

SUPCheckout retains protected historical identities:

```text
_upay_credit_card_token
_upay_customer_unique_token
```

These fields are **not** deleted or migrated on the R3 branch.

**Classification:** `PROVIDER / SECURITY CONTRACT CLARIFICATION REQUIRED` for final production acceptance.

**Decision:** `external provider/security contract unresolved`. Fields are protected historical identities and are not deleted. Final production certification for auto-deduct token storage is **withheld**.

Questions for UPayments:

```text
Are these tokens permitted to be persisted in order metadata?
Are they opaque reusable provider tokens?
What retention period is allowed?
Must they be encrypted?
Are they PCI-sensitive?
How should WooCommerce renewals reference the card if the token is not locally persisted?
```

## Sandbox evidence (2026-09-24)

Provider documents its Sandbox as non-production / non-real-funds.

```text
Sandbox Charge characterization: EXTERNAL REQUIRED (not exercised this session)
Auto-deduct capture semantics via sandbox tokenization: EXTERNAL REQUIRED
Credentials in repo/docs/artifacts: FORBIDDEN — env vars only if ever exercised
Live merchant credentials: FORBIDDEN
```

A sandbox Charge test would characterize Bearer vs HMAC authentication behavior only. It does **not** establish auto-deduct capture semantics unless the actual subscription/auto-deduct flow is exercised with provider-supported sandbox tokenization.

## Capture / cycle identity (unchanged)

```text
Provider capture semantics (auto-deduct): UNPROVEN
Remote cycle identity: UNPROVEN
Truthy status alone: UNRESOLVED → HELD (never VERIFIED_SUCCESS)
```
