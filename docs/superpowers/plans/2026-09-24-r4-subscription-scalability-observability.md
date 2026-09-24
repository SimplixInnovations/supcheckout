---
feature: r4-subscription-scalability-observability
status: in-progress
updated: 2026-09-24
branch: r4/subscription-scalability-observability
commits: a7e959f..
---

# R4 Subscription Scalability and Observability

## Report

## [S1] Problem

`Scheduler::process()` still enumerates every historical paid WooCommerce order every hourly tick (`wc_get_orders` paged loop with no enrollment bound). That is unbounded with store history and is the inherited scalability defect. Queue retries must never re-POST after `dispatching`/`held`/`resolved`. Stale queued work after pause/cancel/card change must be ZERO POST.

## [S2] Design

### Invariant

```
Action Scheduler = orchestration only
CycleClaim       = provider-mutation authority
```

Queue uniqueness is optimization. CycleClaim remains the charge guard.

### Data flow

```
upay_process_subscriptions (hourly, preserved)
  → HistoricalEnrollment::run_batch()   # bounded cursor scan
      → qualify parent (paid, upayments, custom_type, plan/interval, not child)
      → ActionSchedulerBridge::ensure_parent_action(parent_id, next_due)
  → (no full-history load)

Action Scheduler group=supcheckout
  action=supcheckout_process_due_parent  args={parent_order_id}
  → DueParentWorker::handle(parent_order_id)
      → load parent / R3 revalidation
      → compute cycle key + economics
      → CycleClaim acquire/reclaim
      → snapshot-first request build
      → final parent_still_eligible_for_dispatch
      → mark_dispatching → wp_remote_request
      → R3 post-dispatch hold/resolved
```

### Interfaces

**`UPayments\Subscription\Scheduling\ActionSchedulerBridge`**

```php
public static function is_ready(): bool
public static function ensure_parent_action(int $parent_order_id, int $run_at_gmt): bool
public static function has_open_parent_action(int $parent_order_id): bool
public static function cancel_parent_actions(int $parent_order_id): int
```

No secrets in action args. Group `supcheckout`. Prefer WooCommerce-bundled Action Scheduler. No second AS copy.

**`UPayments\Subscription\Scheduling\HistoricalEnrollment`**

```php
public static function run_batch(): array // stats
public static function reset_cursor(): void
```

- `BATCH_SIZE = 50` (tested bound)
- cursor option `upay_subscription_enrollment_cursor`
- resume-safe, duplicate-safe, skips paused/cancelled/children/deleted
- never issues provider POSTs

**`UPayments\Subscription\Scheduling\DueParentWorker`**

```php
public static function handle(int $parent_order_id): void
public static function register(): void
```

Delegates charge path to existing Scheduler R3 logic. Does not duplicate authority.

### Missed-cycle policy

One currently authorized cycle only. After successful reconciliation, recompute next due. Never emit N consecutive charges for downtime.

### Resume policy

Resume schedules the legitimate next cycle from `last_billed_at` / paid date. No back-charge of elapsed pause periods.

### Retry safety

- failure before `mark_dispatching`: safe retry allowed when CycleClaim still `claimed` or absent
- failure after `mark_dispatching`: observe journal; ZERO POST; surface reconciliation

### Observability

Redacted logs: order id, cycle short hash, state, http status, reason. Never API keys, card/customer tokens, emails, phones, full provider bodies.

## [S3] Out of Scope

- R5/T4 callback consolidation
- Live non-idempotent auto-deduct
- Second Action Scheduler vendor copy
- Version/tag/publication
- Changing R3 payment authority semantics

## Tasks

- [ ] T1: ActionSchedulerBridge capability + safe schedule — acceptance: missing/false AS is fail-closed; args contain only parent_order_id (covers: S2)
- [ ] T2: HistoricalEnrollment bounded cursor — acceptance: batch bound, resume, skip ineligible, no provider POST (covers: S2)
- [ ] T3: DueParentWorker + Scheduler feeder — acceptance: process() does not full-history scan; worker reuses R3 gates (covers: S2)
- [ ] T4: Pause/resume/cancel + stale action ZERO POST — acceptance: queue retry after dispatching/held/resolved never POSTs (covers: S2)
- [ ] T5: Concurrency + failure injection + benchmarks — acceptance: sentinel <= 1; feeder independent of total history (covers: S2)
- [ ] T6: Full permanent gates + draft PR — acceptance: exact-head CI green; draft PR; stop for reviewer (covers: S2)

## Progress ledger

| Task | RED | GREEN | Evidence |
|---|---|---|---|
| T1 | pending | pending | pending |
| T2 | pending | pending | pending |
| T3 | pending | pending | pending |
| T4 | pending | pending | pending |
| T5 | pending | pending | pending |
| T6 | pending | pending | pending |
