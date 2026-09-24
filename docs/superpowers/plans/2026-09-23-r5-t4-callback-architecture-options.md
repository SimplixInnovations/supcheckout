# R5/T4 Callback Architecture Options (READ-ONLY)

**Status:** Proposal only — not approved for implementation
**Date:** 2026-09-23
**Basis:** T3 characterization + R2 no-cache + current `main` post-R2

## Scope reviewed

- `UPayments.php`: `return_from_upayments`, `web_hook_handler`, `check_ipn_response`
- `src/Payment/PaymentLifecycle.php` priority-5 `handle_callback`
- `src/Security/PublicOrderStatus.php`
- Hook identities `woocommerce_api_wc_upayments` (5 and 10)
- GET `page` browser marker (T3: direct callers may omit it)

## Option A — Keep current adapters after R2

**Files affected:** none
**Compatibility risk:** low
**Payment authority:** unchanged (dual paths remain)
**Termination/cache:** R2 no-cache already applied
**Tests:** existing T1/T2/T3 + ecosystem-callback-cache
**Rollback:** trivial
**Cost if wrong:** residual dual financial semantics stay until a later decision

## Option B — Thin adapters normalize request context then delegate to PaymentLifecycle (RECOMMENDED)

**Files affected:** `UPayments.php` only (thin body of the three public methods)
**Migration strategy:**
1. Normalize missing GET `page` for direct `return_from_upayments` callers to browser mode without inventing financial truth.
2. Delegate to `PaymentLifecycle::handle_callback()`.
3. Keep public method names and `wc_upayments` hook identities.
4. Preserve `wc_nocache_headers` first on every public response.

**Compatibility risk:** medium — request-shape normalization is the residual risk T3 identified.
**Payment authority:** single StatusVerifier path.
**Termination:** same redirects/status as characterized.
**Tests required:** extend T3 direct-caller matrix with no-`page` browser shape; keep T1 priority tests; keep cache harness.
**ADR changes:** new ADR superseding “compatibility surfaces remain until consolidated”.
**Architecture-contract changes:** allow thin delegation; still forbid parallel Return/Webhook controllers.
**Cost if wrong:** medium — wrong browser/webhook inference could mis-route a live callback.

## Option C — Broader callback-controller restructuring

**Files affected:** new controllers + lifecycle rewrite
**Compatibility risk:** high (contract currently forbids parallel controllers)
**Not recommended** under current architecture-contract.

## Recommendation

**Option B**, only after R3/R4 stabilize and with explicit owner/architect approval. Until then retain Option A.

## Owner decision required

```text
R5_DECISION = A | B | DEFER
```
