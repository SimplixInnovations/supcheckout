---
feature: r5-callback-lifecycle-consolidation
status: in-progress
updated: 2026-09-24
branch: r5/callback-lifecycle-consolidation
commits: 1928df5..
---

# R5 Callback Lifecycle Consolidation (Option B)

## Report

## [S1] Problem

Legacy `return_from_upayments()` / `web_hook_handler()` keep a parallel financial implementation that diverges from `PaymentLifecycle` (status mutation vs `payment_complete`, independent verifier). R5 consolidates callbacks onto one lifecycle without breaking public identities.

## [S2] Design

### Mode seam

```php
PaymentLifecycle::handle_callback()
  → infer browser iff array_key_exists('page', $_GET)
  → handle_callback_mode($mode)

PaymentLifecycle::handle_compat_callback($mode) // 'browser'|'webhook' only
  → handle_callback_mode($mode)

// private handle_callback_mode($mode) holds the single canonical body.
```

No superglobal rewriting.

### Adapters

| Method | Mode | Termination |
|---|---|---|
| `return_from_upayments()` | browser (explicit) | redirect + exit |
| `web_hook_handler()` | webhook (explicit) | HTTP 200 + exit |
| `check_ipn_response()` | inferred | exit (unchanged) |

### Semantic deltas accepted as canonical R5

| Outcome | Legacy | R5 canonical |
|---|---|---|
| CAPTURED | `update_status()` | `payment_complete()` |
| FAILED | often unchanged | terminal `failed` if unpaid |
| CANCELLED | often unchanged | terminal `cancelled` if unpaid |
| PENDING / INDETERMINATE | unpaid | unpaid + reconciliation |
| already verified / refunded | no resurrection | no resurrection |

### Retirement

After both adapters delegate, remove private `verify_payment_status()` and
`get_payment_verification_fallback_url()` if zero production callers remain.

## [S3] Out of Scope

- New financial authority / StatusVerifier rule changes
- New provider egress
- Return/Webhook controllers
- R6 qualification (after independent R5 approval)

## Tasks

- [ ] T1: ADR + architecture contract + this plan — acceptance: decision recorded before code (covers: S2)
- [ ] T2: RED explicit-mode + semantic-delta characterization — acceptance: RED fails before GREEN (covers: S2)
- [ ] T3: handle_callback_mode + handle_compat_callback seam — acceptance: one financial body (covers: S2)
- [ ] T4: Thin return_from_upayments / web_hook_handler — acceptance: explicit mode, no superglobal spoof (covers: S2)
- [ ] T5: Retire dead verifier + ratchets — acceptance: zero production callers; ratchets reviewed (covers: S2)
- [ ] T6: Full qualification + draft PR — acceptance: exact-head CI green; stop for reviewer (covers: S2)

## Progress ledger

| Task | RED | GREEN | Evidence |
|---|---|---|---|
| T1 | n/a | pending | ADR-003 |
| T2 | pending | pending | pending |
| T3 | pending | pending | pending |
| T4 | pending | pending | pending |
| T5 | pending | pending | pending |
| T6 | pending | pending | pending |
