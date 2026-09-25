---
feature: r5-callback-lifecycle-consolidation
status: done-verified-merged
updated: 2026-09-25
branch: r5/callback-lifecycle-consolidation (deleted after merge)
implementation: 1928df5..5baa649
evidence-integrity commits: e1a5730..af64193
certified-head: 6fc225fc736da107de533ba8e19a12dc5c37227d
merged-main: 50170ea7f0d17b792e133a70beee48da7e2b6326
package-files: 62
package-sha256: 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd
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

- [x] T1: ADR + architecture contract + this plan — acceptance: decision recorded before code (covers: S2)
- [x] T2: RED explicit-mode + semantic-delta characterization — acceptance: RED fails before GREEN (covers: S2)
- [x] T3: handle_callback_mode + handle_compat_callback seam — acceptance: one financial body (covers: S2)
- [x] T4: Thin return_from_upayments / web_hook_handler — acceptance: explicit mode, no superglobal spoof (covers: S2)
- [x] T5: Retire dead verifier + ratchets — acceptance: zero production callers; ratchets reviewed (covers: S2)
- [x] T6: Full qualification + draft PR — acceptance: exact-head CI green; stop for reviewer (covers: S2)

## Progress ledger

| Task | RED | GREEN | Evidence |
|---|---|---|---|
| T1 | n/a | ADR-003 + approved_r5_seam | decision before code |
| T2 | CallbackModeNormalizationTest 4 FAIL at 35f87d3 predecessor | 6/0 then extended | explicit mode + retirement pins |
| T3 | handle_compat_callback absent | handle_callback_mode single body | one financial path |
| T4 | adapters still full bodies | thin GET/REQUEST bags | no superglobal spoof |
| T5 | verifier still present | removed; UPayments 88194→63064 | call-site proof |
| T6 | — | exact-head 58/58 | draft PR #116 |

R5-2: REQUEST-only compatibility restoration; real Woo CallbackLifecycleRuntimeTest; 20-cell registration.
R5-3: terminal-state sensitivity hardening (exact outcome/status/meta; payment_complete count; post-capture protection). Historical RED for early T3 financial bodies was recorded in characterization harness; some intermediate unit RED is historical and not re-derived.

## R5-2 correction record

- historical T3 webhook characterization populated REQUEST (not POST); R5-2 restores that contract via request-bag normalization
- direct browser: GET bag; direct webhook: REQUEST callback-key bag only (no cookie forwarding)
- real Woo CallbackLifecycleRuntimeTest registered in 20-cell Compatibility
- terminal FAILED / CANCELED / PENDING / INDETERMINATE / refunded protection covered

## Request-bag provenance wording

Direct webhook compatibility reads the historical `$_REQUEST` source but extracts only the three canonical callback keys: `wc_order_id`, `track_id`, and `requested_order_id`.

No arbitrary request/cookie fields are forwarded into `PaymentLifecycle`. Callback values remain non-authoritative until `StatusVerifier` succeeds.

Do not claim that the provenance of a canonical callback-key value inside `$_REQUEST` is provably non-cookie, because PHP's `request_order` / `variables_order` configuration may include cookie-derived values.
