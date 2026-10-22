# Release Scope Decision

| Area | Decision | Basis |
|---|---|---|
| CORE ONE-TIME PAYMENT | include in first public release (RC eligible) | full repo qualification + fail-closed payment authority |
| SAVED CARD | BLOCKED / EXCLUDED FROM FIRST PUBLIC PRODUCTION CLAIM | token contract unresolved; live tokenization NOT TESTED. Runtime evidence VERIFIED — BOUNDED only. |
| SUBSCRIPTIONS/AUTO-DEDUCT | EXCLUDE from first public production-ready scope; automatic renewal charges BLOCKED (owner decision 2026-10-22) | capture/cycle UNPROVEN; VERIFIED_SUCCESS fail-closed; no auto-deduct request is ever sent while it is |
| WALLETS | exclude until device/account evidence | NOT TESTED / EXTERNAL |
| MULTI-MERCHANT | include — BOUNDED (one additional merchant) | bounded allocation test |
| INTEGRATIONS (WPML/WCML/themes/CDN) | exclude from claims | EXTERNAL REQUIRED |

## Important

If excluding recurring from public claims requires runtime/UI/package behavior change:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=RELEASE_SCOPE
```

Do not make that change silently. Current fail-closed wording already avoids promising working automatic recurring.

## Owner decision 2026-10-22: block automatic renewal charges

```text
NEW_RUNTIME_TRANCHE_REQUIRED=RELEASE_SCOPE
OWNER_DECISION=BLOCK_AUTO_CHARGES (2026-10-22)
```

Hardening audit A1-01 showed that a due renewal sent a real auto-deduct request although `AutoDeductResultVerifier` can never return `VERIFIED_SUCCESS`. The cycle was then held with no renewal order and no merchant notice. The owner chose **"Block auto-charges"** and recorded it explicitly here, as this document requires. It is a runtime and UI change, delivered as its own fix tranche (`fix/a1-01-block-auto-deduct-dispatch`):

- `AutoDeductResultVerifier::can_verify_success()` is the single gate. It returns `false` while no approved UPayments auto-deduct capture contract exists.
- While it is `false`, SUPCheckout sends **no automatic renewal charge**. A due parent stops before the renewal-card lookup (`retrieve-customer-cards`) and before any billing-cycle claim, and returns `not_eligible`, which the worker never retries. The hourly feeder, initial scheduling and resume queue no due-parent work.
- Each subscription parent gets one order note, and store managers see one admin notice while "Enable Subscriptions" is on. The order box and My Account show "Automatic Renewal: Paused" instead of a next billing date. The setting description says so.
- The merchant collects renewals manually or by payment link.
- The billing-attempt engine (`CycleClaim`, verifier, held handling) stays in place behind the gate and is certified with the gate forced open in the takeover matrix. Opening the gate needs an approved capture contract and a new owner decision.
