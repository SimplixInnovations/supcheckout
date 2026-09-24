---
feature: r3-subscription-safety-redesign
status: done_verified
updated: 2026-09-24
branch: r3/subscription-safety-redesign
commits: eebe46b..de0162b (squash e1ad338 on main)
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

- [x] T1: Characterize Scheduler/CycleClaim/pause — acceptance: permanent characterization tests pin current + target contracts (covers: S2)
- [x] T2: RenewalCardAuthority RED then GREEN — acceptance: missing explicit token → ZERO auto-deduct POST even with saved cards (covers: S2)
- [x] T3: CycleEconomics decimal-safe RED then GREEN — acceptance: amount/currency mismatch classes covered without float equality (covers: S2)
- [x] T4: AutoDeductResultVerifier RED then GREEN — acceptance: unproven capture/`orderId+1` never authorizes paid renewal (covers: S2)
- [x] T5: Scheduler wire-up — acceptance: card/verifier/economics/parent/pause gates used before POST (covers: S2)
- [x] T6: CycleClaim schema v2 snapshot — acceptance: install/upgrade/partial-fail/re-activate safe; no charge without schema (covers: S2)
- [x] T7: Failure injection + concurrency tests — acceptance: post-dispatch uncertainty stays HELD; at most one automatic POST (covers: S2)
- [x] T8: Full repository gates + package + PR — acceptance: exact-head CI green; draft PR (covers: S2)

## Progress ledger

| Task | Base | RED | Fix | Focused GREEN | Review | Final SHA |
|---|---|---|---|---|---|---|
| T1 characterize | eebe46b | historical RED evidence unavailable for T1 surface pins | 03e1af6..504bc27 + matrix harness | ecosystem-subscription-lifecycle-matrix 65/0 | independent review (Critical $paid_currency fixed 504bc27) | 3d09137+ |
| T2 card authority | eebe46b | harness FAIL cards[0]/RenewalCardAuthority (observed) | 03e1af6 | RenewalCardAuthorityTest | Critical fixed | 3d09137+ |
| T3 CycleEconomics | eebe46b | unit matrix RED on leading-zero/float (observed after draft) | 03e1af6 + decimal regex fix | CycleEconomicsTest | review OK | 3d09137+ |
| T4 verifier | eebe46b | harness FAIL capture_authority (observed) | 03e1af6 | AutoDeductResultVerifierTest + identity honesty | review: terminology corrected | 3d09137+ |
| T5 Scheduler wire-up | eebe46b | harness FAIL RenewalCardAuthority/AutoDeductResultVerifier/CycleEconomics (observed) | 03e1af6..current | ecosystem-subscription-safety 12/0 | review Critical paid_currency fixed | 3d09137+ |
| T6 CycleClaim v2 | eebe46b | historical RED evidence unavailable for initial migration SQL; later real-DB RED observed for CHAR(64) fixtures | dcbd89e + CycleClaimRuntimeTest | real MySQL: fresh install, v1 migration, partial schema repair, missing-table repair, immutable reclaim | independent review required | 3d09137+ |
| T7 failure/concurrency | eebe46b | partial matrix via lifecycle harness; multi-process concurrency RED observed for fixture paths (2d0d68d/3d09137) | daa1a0f + concurrency suite | real MySQL multi-process: 3-worker acquisition race, 2-worker reclaim race, old-owner dispatch rejection, dispatching/held terminal safety, sentinel count = 1 | independent review required | 3d09137+ |
| T7 FINAL-2 auth races | 3d09137 | ParentDispatchRevalidationTest 8 FAIL (refunded/cancelled/failed/pending/card-change/card-removed/product-removal/non-custom_type) | 711c640 | ParentDispatchRevalidationTest 15/0 + SubscriptionRuntimeTest revalidation races | independent review required | candidate head |
| T8 full qualification | — | n/a | current | exact-head CI green on candidate; draft PR #112 | pending independent review | candidate head |
