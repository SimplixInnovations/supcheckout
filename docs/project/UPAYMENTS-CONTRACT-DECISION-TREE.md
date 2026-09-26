# UPayments Contract Decision Tree

## HMAC outcome A — mandatory

If UPayments confirms mandatory HMAC for production Charge/Status:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=HMAC
```

Do NOT implement in this release-readiness branch. Future tranche must cover all three egresses:

- `WC_Upayments::execute_upayments_request`
- `StatusVerifier::verify`
- Scheduler renewal dispatch

Required: API Secret config/storage, redaction, timestamp, exact body preservation, canonical path (incl. query policy), GET empty-body rule, Base64 HMAC-SHA256, skew/errors, test vectors, migration, rollback, R0–R6 gates, fresh owner acceptance. No fourth egress.

## HMAC outcome B — optional/rollout

Document merchant-account conditions, effective date, capability detection. Do not add configuration unless provider evidence requires it.

## Token outcome A — local persistence forbidden

```text
NEW_RUNTIME_TRANCHE_REQUIRED=TOKEN_STORAGE
```

Do not delete protected fields. Design must cover remote token authority, migration, existing-subscription behavior, rollback, failure mode.

## Token outcome B — allowed with controls

If encryption required and current plaintext metadata insufficient:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=TOKEN_ENCRYPTION
```

Prepare design options only. Do not implement here.

## Capture/cycle outcome

Only if provider establishes authoritative captured state, safe status verification, and unambiguous billing-cycle identity may automatic recurring `VERIFIED_SUCCESS` become a candidate for a separate runtime tranche. Otherwise remain unreachable.

## Webhook security outcome

If provider confirms signed webhooks with a stable contract:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=WEBHOOK_SECURITY
```

Payment authority remains StatusVerifier unless a separately approved change says otherwise.
