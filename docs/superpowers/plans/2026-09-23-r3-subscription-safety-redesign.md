---
feature: r3-subscription-safety-redesign
status: in-progress
updated: 2026-09-23
branch: r3/subscription-safety-redesign
commits: eebe46b..
---

# R3 Subscription Safety Redesign

## Report

## [S1] Problem

Automatic renewals can charge an arbitrary first saved card when `_upay_credit_card_token` is absent, complete renewals from a truthy top-level provider `status` without exact economic/identity binding, scan only `completed` parents, and leave HELD cycles without a classified reconciliation boundary. These are payment-safety defects.

## [S2] Design

### Data flow (current → target)

```
initial checkout
  → token/card authorization (_upay_credit_card_token + _upay_customer_unique_token)
  → parent subscription state (_upay_subscription_*)
  → due-date discovery (paid parents + valid plan/interval/state)
  → cycle claim (CycleClaim acquire)
  → dispatching (mark_dispatching)
  → provider POST /auto-deduct
  → provider response
  → AutoDeductResultVerifier (binding + capture authority)
  → renewal persistence (fresh-read verify)
  → parent advancement (fresh-read verify)
  → resolved | held
  → ReconciliationBoundary (HELD classification; no blind replay)
```

### Interfaces

**`Simplixi\SUPCheckout\Subscription\RenewalCardAuthority`**

```php
public static function resolve_explicit_token($order, callable $saved_cards_loader): array
// returns ['state' => 'explicit'|'missing'|'revoked'|'retrieval_failed'|'malformed', 'token' => ?string]
```

Rules: never `cards[0]`; explicit stored token only; optional membership check against retrieved cards when retrieval succeeds; transient retrieval failure is pre-dispatch safe (no POST).

**`Simplixi\SUPCheckout\Subscription\CycleEconomics`**

```php
public static function snapshot_from_order($order): ?array
// ['amount' => string canonical decimal, 'currency' => string ISO-like, 'plan' => string, 'interval' => int]
public static function amounts_equal(string $a, string $b): bool
public static function currencies_equal(string $a, string $b): bool
```

Decimal-safe: string compare after canonicalization; no float payment authority.

**`Simplixi\SUPCheckout\Subscription\AutoDeductResultVerifier`**

```php
public static function verify(array $response, array $expected): array
// ['outcome' => verified_success|definitive_failure|unresolved|malformed|binding_mismatch, 'reason' => string, 'payment_id' => ?string, 'paid_amount' => ?string, 'paid_currency' => ?string]
```

Does not POST. Capture authority: repository Phase 8C records that truthy top-level `status` is **historical compatibility, not authenticated capture**. Until first-party UPayments auto-deduct capture semantics are proven, `verified_success` requires explicit result field evidence matching certified charge-path CAPTURED semantics **or** fails closed to `unresolved`. This plan does **not** invent field names.

**Evidence for response fields**

| Field used | Evidence |
|---|---|
| `status` truthy | Phase 8C comment in `Scheduler::handle_post_dispatch` — historical compatibility only |
| `data.transaction.paymentId` | Existing certified repository contract (renewal persistence) |
| `data.transaction.paid_amount` / `paid_currency` | Existing certified repository contract (binding checks) |
| `data.transaction.orderId` | Existing contract; `orderId + 1` fabrication is **unsupported** — removed from identity authority |
| CAPTURED result token | **Unproven for auto-deduct** → treat success-without-binding as `unresolved`/HELD |

Browser Use / open-web provider docs were unavailable this session; external UPayments auto-deduct schema remains **EXTERNAL REQUIRED**.

### Parent discovery

Qualification: `payment_method=upayments`, not auto-deduction child (`UPayments_AutoDeduction !== 'yes'`), valid plan/interval, subscription state `active`, paid Woo status via `wc_get_is_paid_statuses()` (fallback `processing`,`completed`), custom_type product present.

### Cycle economic snapshot

Store `expected_amount` / `expected_currency` on CycleClaim rows (schema v2) at acquire time. Table identity `upayments_billing_attempts` preserved. Schema version option bumps `1`→`2` with additive columns; old rows remain valid; no charge if schema unavailable.

### Pause/resume/cancel

Existing handler (login + nonce + plan/interval allowlist + auto-deduction child rejection) is retained. Scheduler must skip non-`active` parents before claim.

## [S3] Out of Scope

- R5/T4 callback consolidation
- Live non-idempotent auto-deduct in CI
- Admin reconciliation UI (service API only)
- Action Scheduler (R4)
- Version/tag/publication
- Protected identity migration

## Tasks

- [ ] T1: Characterize Scheduler/CycleClaim/pause — acceptance: permanent characterization tests pin current + target contracts (covers: S2)
- [ ] T2: RenewalCardAuthority RED then GREEN — acceptance: missing explicit token → ZERO auto-deduct POST even with saved cards (covers: S2)
- [ ] T3: CycleEconomics decimal-safe RED then GREEN — acceptance: amount/currency mismatch classes covered without float equality (covers: S2)
- [ ] T4: AutoDeductResultVerifier RED then GREEN — acceptance: unproven capture/`orderId+1` never authorizes paid renewal (covers: S2)
- [ ] T5: Scheduler wire-up — acceptance: card/verifier/economics/parent/pause gates used before POST (covers: S2)
- [ ] T6: CycleClaim schema v2 snapshot — acceptance: install/upgrade/partial-fail/re-activate safe; no charge without schema (covers: S2)
- [ ] T7: Failure injection + concurrency tests — acceptance: post-dispatch uncertainty stays HELD; at most one automatic POST (covers: S2)
- [ ] T8: Full repository gates + package + PR — acceptance: exact-head CI green; draft PR (covers: S2)

## Progress ledger

| Task | Base | RED | Fix | Focused | Review | Notes |
|---|---|---|---|---|---|---|
| T2 | eebe46b | pending | | | | |
| T3 | eebe46b | pending | | | | |
| T4 | eebe46b | pending | | | | |
