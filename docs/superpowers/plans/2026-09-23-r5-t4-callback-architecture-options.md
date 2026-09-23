# R5/T4 Architecture Options — READ-ONLY decision package

**Status:** PROPOSAL ONLY — production implementation NOT authorized  
**Date:** 2026-09-23  
**Base:** `main` after R2 `1c95bc9434784c705e98245f3f9d65f95f4de7ef` + control-plane `eebe46bd5317e619a42406b7b9d52f01116f98fd`  
**T3 evidence:** direct `return_from_upayments()` callers may lack WC-API GET `page` marker used by `PaymentLifecycle` browser inference.

## Surfaces

| Surface | File | Role |
|---|---|---|
| `return_from_upayments` | `UPayments.php` | Direct browser return compatibility |
| `web_hook_handler` | `UPayments.php` | Direct webhook compatibility |
| `check_ipn_response` | `UPayments.php` | Priority-10 fallback → `PaymentLifecycle::handle_callback()` |
| `PaymentLifecycle::handle_callback` | `src/Payment/PaymentLifecycle.php` | Priority-5 canonical authority |
| `PublicOrderStatus::handle` | `src/Security/PublicOrderStatus.php` | Narrow public status |

## Option A — Keep current architecture after R2/R3

**Pros:** Lowest compatibility risk; T3 constraint respected; no public method removal.  
**Cons:** Dual financial semantics remain (legacy verifier vs StatusVerifier); more test matrix.  
**Cost if wrong:** Low.  
**Recommendation rank:** 2

## Option B — Thin adapters normalize request context, delegate to PaymentLifecycle

**Pros:** One financial path; preserves public methods/hooks; can normalize missing `page` marker before delegation.  
**Cons:** Must characterize every direct-caller request shape; browser/webhook inference change risk; termination/cache semantics must stay identical.  
**Migration:** Adapter-only change in `UPayments.php`; no new controllers.  
**Tests required:** T1/T2/T3 harnesses + ecosystem-callback-cache + direct-caller shapes (GET with/without `page`, POST webhook, missing fields).  
**Cost if wrong:** Medium — redirect/termination drift could affect customers.  
**Recommendation rank:** **1 (recommended after R3/R4 land)**

## Option C — Broader callback-controller restructuring

**Pros:** Clean long-term shape.  
**Cons:** Architecture contract currently **forbids** parallel ReturnController/WebhookController; highest rollback risk.  
**Cost if wrong:** High.  
**Recommendation rank:** 3 — treat as hypothesized only.

## Recommendation

**Defer T4 until after R3+R4 merge.** Then implement **Option B** with a new ADR and explicit owner/architect approval. Do not treat R0–R4 as T4 authorization.

## Migration risk (Option B)

- Direct callers without `page` marker must stay browser-compatible (T3).
- `wc_nocache_headers` must remain first on all public response paths (R2).
- Payment authority must remain StatusVerifier-bound only.
- Protected method names `return_from_upayments` / `web_hook_handler` / `check_ipn_response` stay public.

## Owner decision required

- [ ] Approve Option B implementation after R4
- [ ] Explicitly defer R5/T4 and proceed to R6 against current callback architecture
- [ ] Reject and record alternative
