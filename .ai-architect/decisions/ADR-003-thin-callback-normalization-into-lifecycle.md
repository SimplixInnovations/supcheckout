# ADR-003: Thin compatibility normalization into PaymentLifecycle (R5 Option B)

- **Status:** Accepted (R5_DECISION=B)
- **Date:** 2026-09-24
- **Repository:** `SimplixInnovations/supcheckout`
- **Post-R4 main:** `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`
- **Control-plane main at decision:** `1928df57884c009cc4d49a64b8027560ad175eaa`
- **Frozen owner-accepted Approach 2 baseline:** `0c883d609906676966002eb022a82a9656eeacc5`
- **Frozen owner-accepted package SHA-256:** `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`

## Context

T3 proved direct `return_from_upayments()` callers may omit the WC-API GET
`page` marker. Legacy public methods still contained an independent financial
implementation (`verify_payment_status()`, `update_status()` capture) that
diverged from `PaymentLifecycle` (`payment_complete()`, canonical terminal
states, StatusVerifier-only authority). R2–R4 stabilized the lifecycle.

## Decision

**Chosen: Option B** — thin compatibility normalization into `PaymentLifecycle`.

**Rejected:**

- **A** — retain duplicate financial implementations (residual dual semantics).
- **C** — broader controller restructuring (forbidden parallel controllers).
- **DEFER** — unnecessary after R2–R4 stabilization.

### Compatibility guarantees preserved

```text
UPayments.php physical bootstrap
WC_Upayments
return_from_upayments()
web_hook_handler()
check_ipn_response()
wc_upayments
woocommerce_api_wc_upayments priority 10 fallback
browser redirect behavior
webhook HTTP behavior
termination
no-cache
StatusVerifier financial authority
direct callers without GET page
```

### Intentionally NOT preserved

```text
duplicate legacy financial-state implementation
legacy update_status() capture algorithm
legacy independent verify_payment_status() authority
```

Canonical financial semantics after R5 belong to `PaymentLifecycle` only.

### Mode seam (no superglobal spoofing)

```text
handle_callback()          → existing page-marker inference → handle_callback_mode()
handle_compat_callback(m)  → explicit browser|webhook     → handle_callback_mode()
return_from_upayments()    → handle_compat_callback('browser')
web_hook_handler()         → handle_compat_callback('webhook')
check_ipn_response()       → handle_callback()  (unchanged inference)
```

`$_GET` / `$_POST` / `$_SERVER` are never rewritten to force mode.

## Consequences

- One financial implementation, one StatusVerifier path, one OrderLock path,
  one `apply_captured`, one terminal-result path, one `finish_callback`.
- Direct method identity supplies historical mode even when request globals
  contradict it.
- Dead private `verify_payment_status()` / `get_payment_verification_fallback_url()`
  are removed after call-site proof.
- Provider egress inventory unchanged (three sites).
