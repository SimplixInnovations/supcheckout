# Recurring Feature Exposure & Public Claims Audit

## Runtime facts (owner-accepted Approach 3)

```text
Can a merchant enable subscription/auto-deduct today?
YES — gateway settings expose enable_subscriptions / auto-deduct.

Can a customer see it as supported?
YES — subscription product type + checkout path exist.

Can a renewal dispatch occur?
NO — automatic renewal charges are blocked (owner decision 2026-10-22, fix A1-01).
AutoDeductResultVerifier::can_verify_success() returns false, so Scheduler::process_parent_order()
returns not_eligible before the renewal-card lookup and before any CycleClaim row, and
HistoricalEnrollment / LifecycleScheduler / DueParentWorker queue no due-parent work.
(Before 2026-10-22: YES — one auto-deduct request per cycle, then HELD.)

Can any renewal become VERIFIED_SUCCESS?
NO — FAIL-CLOSED / UNREACHABLE while provider capture authority is unproven.

What does the UI tell the merchant/user when provider authority is unresolved?
Automatic renewal is shown as paused: one order note per subscription parent, an admin notice for
store managers while "Enable Subscriptions" is on, "Automatic Renewal: Paused" in the order box and
My Account instead of a next billing date, and the same statement in the setting description.
Renewals are collected manually or by payment link.
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
automatic renewal dispatch: BLOCKED (owner decision 2026-10-22)
```
