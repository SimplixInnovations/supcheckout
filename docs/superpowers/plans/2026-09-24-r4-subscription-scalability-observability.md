---
feature: r4-subscription-scalability-observability
status: candidate-ready-for-independent-review
updated: 2026-09-24
branch: r4/subscription-scalability-observability
commits: a7e959f..1ea21e2
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

**`Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge`**

```php
public static function is_ready(): bool
public static function cycle_args(int $parent_order_id, int $cycle_due_gmt): array
public static function ensure_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
public static function has_open_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
public static function cancel_cycle_action(int $parent_order_id, int $cycle_due_gmt): bool
public static function cancel_parent_actions(int $parent_order_id): bool
```

No secrets in action args. Group `supcheckout`. Unique=true + recheck. `is_ready()` requires APIs **and** `Action_Scheduler::is_initialized()` (or `action_scheduler_init` fallback). Prefer WooCommerce-bundled Action Scheduler. No second AS copy.

**`Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment`**

```php
public static function run_batch(): array // stats
public static function enroll_slice(array $orders, bool $complete): array
public static function parent_qualifies(\WC_Order $order): bool
public static function next_run_at(\WC_Order $order): ?int
public static function reset_cursor(): void
```

- `BATCH_SIZE = 50` (tested bound)
- query: paid statuses + `payment_method=upayments` + limit + offset + orderby ID ASC
- persistent offset cursor in `upay_subscription_enrollment_cursor`
- consistency: offset pagination; deletion may skip one (repair on complete-pass reset); revisit is duplicate-safe
- migration/repair/recovery — steady-state uses `LifecycleScheduler`
- never issues provider POSTs

**`Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker`**

```php
public static function register(): void
public static function handle($parent_order_id, $cycle_due_gmt = 0): void
```

Args: `parent_order_id`, `cycle_due_gmt` (orchestration identity). Stale cycle → ZERO POST. Next cycle only on `OUTCOME_RESOLVED`. Pre-dispatch failures use bounded +1h retry.

**`Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler`**

Direct initial/resume scheduling from Woo lifecycle hooks. Pause/cancel best-effort queue cancel.

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

- [x] T1: ActionSchedulerBridge capability + safe schedule — acceptance: missing/false AS is fail-closed; cycle args + unique scheduling (covers: S2)
- [x] T2: HistoricalEnrollment bounded offset cursor — acceptance: batch bound, forward progress, no starvation (covers: S2)
- [x] T3: DueParentWorker + Scheduler feeder — acceptance: process() does not full-history scan; outcome-aware next cycle (covers: S2)
- [x] T4: Pause/resume/cancel + stale action ZERO POST — acceptance: queue retry after dispatching/held/resolved never POSTs (covers: S2)
- [x] T5: Concurrency + failure injection + benchmarks — acceptance: sentinel <= 1; feeder independent of total history (covers: S2)
- [x] T6: Full permanent gates + draft PR — acceptance: exact-head CI green; draft PR; stop for reviewer (covers: S2)

## Progress ledger

| Task | RED | GREEN | Evidence |
|---|---|---|---|
| T1 | functions-only readiness insufficient (review) | SchedulingCorrectionTest | is_initialized + unique=true + recheck |
| T2 | run_batch ignored offset cursor (starvation RED via run_batch) | offset cursor advances; matrix 0..125 | SchedulingTraversalRuntimeTest multi-batch |
| T3 | blind next-cycle after every outcome | OUTCOME_* + schedule only on resolved | DueParentWorker |
| T4 | stale/paused ZERO POST | LifecycleScheduler cancel + worker revalidation | integration |
| T5 | cycle A blocks B (parent-only args) | cycle_due_gmt identity | cycle A→B test |
| T6 | historical RED for first R4 draft retained | 291/0 units; lifecycle 82/0; H12 1936/0 | draft PR #114 |

## Benchmark before/after (local characterization)

| Metric | Before (inherited) | After (R4) |
|---|---|---|
| Historical orders inspected per hourly tick | all paid history (unbounded) | <= 50 (`BATCH_SIZE`) |
| Provider dispatch in feeder | possible during scan | never (enrollment only) |
| Due-work mechanism | inline in cron loop | Action Scheduler `supcheckout` group |
| Charge authority | CycleClaim | CycleClaim (unchanged) |
