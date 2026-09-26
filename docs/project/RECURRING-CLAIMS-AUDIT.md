# Recurring Feature Exposure & Public Claims Audit

## Runtime facts (owner-accepted Approach 3)

```text
Can a merchant enable subscription/auto-deduct today?
YES — gateway settings expose enable_subscriptions / auto-deduct.

Can a customer see it as supported?
YES — subscription product type + checkout path exist.

Can a renewal dispatch occur?
YES — Scheduler / LifecycleScheduler enqueue work.

Can any renewal become VERIFIED_SUCCESS?
NO — FAIL-CLOSED / UNREACHABLE while provider capture authority is unproven.

What does the UI tell the merchant/user when provider authority is unresolved?
Renewals that cannot be verified remain HELD / non-success; automatic paid finalization stays disabled pending provider-contract proof.
```

## Classification

```text
RELEASE BLOCKER — CLAIM/RUNTIME MATCH
```

Observed: public/runtime exposure of subscription/auto-deduct does **not** claim production-ready automatic recurring payment. UI/docs language is fail-closed. This is **not** a claim/runtime mismatch blocker.

Residual risk: merchants may *expect* working recurring without reading fail-closed language.

## Minimum corrective proposal (no package change in this branch)

Keep current fail-closed wording. At RC metadata promotion, add an explicit one-line notice in readme.txt:

> Automatic recurring renewal is fail-closed until UPayments capture/cycle contracts are confirmed; one-time payments are the supported production path.

If that changes `readme.txt` bytes:

```text
RC_METADATA_CHANGE_REQUIRED
```

New package hash then requires fresh package acceptance.

## Recurring production status (binding)

```text
recurring engineering safety: VERIFIED
automatic recurring provider authorization: NOT VERIFIED / EXTERNAL REQUIRED
automatic recurring VERIFIED_SUCCESS: UNREACHABLE / FAIL-CLOSED
```
