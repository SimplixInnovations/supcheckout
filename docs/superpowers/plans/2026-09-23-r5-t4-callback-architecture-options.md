# R5/T4 Callback Architecture Options (READ-ONLY)

**Status:** Proposal only — not approved for implementation  
**Date:** 2026-09-24 (refreshed post-R4)  
**Post-R4 main SHA:** `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`  
**Basis:** T3 characterization + R2 no-cache + R3/R4 stability + residual audit

## Scope reviewed (reconfirmed on post-R4 main)

| Surface | Status |
|---|---|
| `UPayments.php` `return_from_upayments()` | public compatibility entry (line ~698) |
| `UPayments.php` `web_hook_handler()` | public compatibility entry (line ~896) |
| `UPayments.php` `check_ipn_response()` | `woocommerce_api_wc_upayments` dispatcher (priority 10, line ~1035) |
| `src/Payment/PaymentLifecycle.php` `handle_callback` | active financial path (priority 5) |
| `src/Security/PublicOrderStatus.php` | read-only status surface |
| GET `page` browser marker | T3: direct callers may omit it |
| no-cache | R2: `wc_nocache_headers` on public callback/status surfaces |
| StatusVerifier | remains financial authority for capture proof |
| Protected identities | `wc_upayments`, public method names, hook priority 10 |

Harness evidence still green on post-R4 main:

- `architecture-legacy-direct-callback-characterization-harness` — 102/0  
- `architecture-active-callback-characterization-harness` — 41/0  
- `architecture-legacy-callback-routing-harness` — priority 10 pin intact  
- `ecosystem-callback-cache-harness` — no-cache intact  
- T3 conclusion still holds: **direct `return_from_upayments()` callers may lack GET `page`**

R2–R4 did not change callback routing, hook priorities, or public method signatures.

## Option A — Retain current architecture

**Behavior:** keep dual public adapters + `PaymentLifecycle` as today.  
**Files affected:** none.  
**Advantages:** zero compatibility risk; already fully characterized; rollback trivial.  
**Risks:** residual dual financial semantics remain; later consolidation cost unchanged.  
**Payment authority:** unchanged (legacy methods retain historical semantics).

## Option B — Thin normalization → PaymentLifecycle (RECOMMENDED)

**Behavior:** keep protected public methods; each body becomes a thin normalizer of historical request shape, then delegates to the single `PaymentLifecycle::handle_callback()`. No parallel controllers.

**Normalization seam:** `UPayments.php` only — the three public methods.

**Exact contract to preserve:**

```text
UPayments.php
WC_Upayments
return_from_upayments public
web_hook_handler public
check_ipn_response public + wc_upayments identity + priority 10
browser redirects
webhook HTTP 200 / termination
wc_nocache_headers first
StatusVerifier authority
direct-call compatibility without GET page
```

**Files that would change:** `UPayments.php` (method bodies only).  
**Tests required:**

```text
normal WC-API browser success / error
webhook
direct return_from_upayments without page marker
direct legacy webhook method
duplicate callback
invalid callback
no-cache
redirect termination
HTTP 200 webhook termination
T1 priority pins
R2 cache harness
```

**Advantages:** single financial path; removes dual semantics; small blast radius.  
**Risks:** medium — wrong browser/webhook inference on non-page direct calls (exactly T3 residual). Must normalize mode explicitly, never invent financial truth.  
**Rollback:** restore prior method bodies (git revert); no schema/identity migration.  
**ADR:** new ADR superseding “compatibility surfaces remain until consolidated”.  
**Architecture-contract:** allow thin delegation; still forbid Return/Webhook controllers, second lifecycle, generic provider framework.

## Option C — Broad callback restructuring

**Why not:** architecture-contract forbids parallel Return/Webhook controllers; high compatibility risk; no new evidence overturns that. **Not recommended.**

## DEFER

Leave R5 out of Approach 3. Cost: dual semantics remain. Benefit: zero risk now. Reconsider after owner accepts Approach 3 or when provider callback contract changes.

## Recommendation (post-R4)

**Option B still stands** after R4: the residual risk is unchanged and R3/R4 stability makes the seam safer than in 2026-09-23. Implement only after explicit `R5_DECISION=B`.

## Owner decision required

```text
R5_DECISION = A | B | DEFER
```

No T4 production code before this value is supplied.
