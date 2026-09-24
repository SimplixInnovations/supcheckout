# R3 Provider Contract Audit (Part VI–VIII)

**Status:** evidence package for independent review
**Browser Use / open-web search:** unavailable this session
**Sources used:** repository Phase 8C comments; existing certified request/response contracts in `Scheduler.php` / `StatusVerifier.php` / public Charge documentation references in implementation-plan.md

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

| Endpoint | Documented auth | Sandbox | Repo behavior | Gap |
|---|---|---|---|---|
| charge | Bearer (some pages) / Bearer+X-Timestamp+X-Signature (other pages) | not exercised this session | Bearer only | CONTRADICTORY FIRST-PARTY |
| get-payment-status | Bearer | StatusVerifier uses Bearer | Bearer only | same conflict if mandatory |
| retrieve cards | Bearer | existing getSavedCards | Bearer only | same |
| create customer token | Bearer | existing token flow | Bearer only | same |
| auto-deduct | Bearer | not exercised | Bearer only | same |
| availability | Bearer | existing | Bearer only | same |

**Decision:** `PROVIDER CLARIFICATION REQUIRED BEFORE PRODUCTION ACCEPTANCE`. Do not add HMAC inside R3 Scheduler only. If mandatory, a separate credential/config migration is required across all three accepted egress sites.

## Token storage (Part VIII)

Current protected persistence:

```text
_upay_credit_card_token
_upay_customer_unique_token
```

Provider guidance (reported): tokens should not be stored in local Woo DB.

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
