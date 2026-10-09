# UPayments Contract Decision Tree

## Public research boundary — 2026-09-27

Deep first-party research is recorded in `docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md`.

It does **not** select an HMAC/token/recurring runtime outcome because current UPayments first-party surfaces materially conflict:

- dynamic HMAC guide vs Bearer-only/current-rollout surfaces and the official unreleased plugin's different signature model;
- subscription “never stored locally” wording vs token APIs and official plugin token persistence;
- generic `CAPTURED` financial semantics vs official auto-deduct code promoting a successful response envelope without a separately documented capture contract.

Therefore:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=NONE
PENDING=PROVIDER_CONTRACT_CLARIFICATION
```

No public-source inference may be promoted into production payment authority merely to close the release gate.

### First-release blocker boundary after exhaustive public research

For the narrower first public **core one-time-payment** scope, the provider-dependent release blocker is now limited to:

```text
PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED
```

Webhook-signature mechanics are defense-in-depth because webhook/redirect input is not financial truth; current financial authority remains authenticated `StatusVerifier`. Saved-card/token-storage and auto-deduct/cycle questions remain future feature gates because those capabilities are excluded from the first public production claim. Sandbox credential-family migration is also not a first-release blocker while the bounded public-sandbox Charge path remains independently certified.


## Account-scoped resolution path — owner decision 2026-10-08

The owner adopted a second resolution path that does not depend on a provider reply: an owner-run probe sends the exact Bearer-only Status and Charge-initialization requests SUPCheckout sends today to the release merchant's production account. The procedure, safety boundary and resolution rule are in `PRODUCTION-AUTH-ACCOUNT-PROBE.md`.

```text
both probes BEARER_ONLY_ACCEPTED + conditions in PRODUCTION-AUTH-ACCOUNT-PROBE.md
  -> PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY
any probe REJECTED_AUTH
  -> stays UNRESOLVED; NEW_RUNTIME_TRANCHE_REQUIRED=HMAC; provider answer needed for the scheme
```

A written provider answer, if one arrives, supersedes the probe result.

## HMAC outcome A — mandatory

If UPayments confirms mandatory dynamic HMAC for production Charge and/or Status:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=HMAC
```

Do NOT implement in this research branch. The separate runtime tranche must cover every provider egress to which the authoritative contract applies:

- `WC_Upayments::execute_upayments_request` — core Charge;
- `StatusVerifier::verify` — core track-ID Status;
- Scheduler renewal dispatch — only according to the authoritative endpoint contract; recurring remains excluded/fail-closed until its separate certification.

Required: API Secret config/storage, redaction, timestamp, exact body preservation, the active track-ID canonical path, GET empty-body rule, Base64 HMAC-SHA256, conservative auth-failure handling, deterministic test vectors, migration/rollback, release-sensitive recertification and fresh owner acceptance. Query-string canonicalization is not required by the current SUPCheckout Status route because that route rejects query strings. No fourth egress.

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

## Idempotency FAQ note

FAQ: API idempotency keys not currently supported. This alone does not close cycle identity.

## Capture/cycle outcome

Only if provider establishes authoritative captured state, safe status verification, and unambiguous billing-cycle identity may automatic recurring `VERIFIED_SUCCESS` become a candidate for a separate runtime tranche. Otherwise remain unreachable.

## Webhook security outcome

If provider confirms signed webhooks with a stable contract:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=WEBHOOK_SECURITY
```

Payment authority remains StatusVerifier unless a separately approved change says otherwise.
