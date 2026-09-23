# Approach 3 T3 Legacy Direct Callback Characterization

**Status:** DONE / VERIFIED — RUNTIME-NEUTRAL

**Original base:** `348fd1e7493b97dee37e9839569ba2a7f7a3ab32`
**Certified PR head:** `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`
**Pull request:** #107
**Merged main:** `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`

## Purpose

Characterize the three remaining historical payment-authority compatibility surfaces before any further runtime consolidation:

- public `WC_Upayments::return_from_upayments()`;
- public `WC_Upayments::web_hook_handler()`;
- private `WC_Upayments::verify_payment_status()`.

T3 changed no production runtime behavior. Its output is executable evidence for later architecture decisions.

## Required evidence

### Legacy verifier

Pin representative outcomes for:

- invalid order;
- missing track ID;
- missing local `UPayments_order_id`;
- transport failure;
- unexpected HTTP status;
- malformed/top-level provider response;
- transaction binding failure;
- non-numeric / mismatched amount;
- authenticated non-captured result;
- fully bound `CAPTURED` result;
- unexpected exception fail-closed behavior.

Also prove the legacy verifier uses the general gateway transport route `get-payment-status/<track>` and does not itself call the modern `StatusVerifier`, `StatusRateGate`, or `OrderLock` path.

### Direct browser return

Pin at least:

- missing/invalid local preflight -> neutral verification-pending redirect;
- authenticated non-captured -> neutral redirect with no paid-state mutation;
- captured -> authenticated provider metadata written, paid-state transition, verified flags written after successful transition, save, cart clear, return URL redirect;
- failed Woo status transition -> no verified-success flags;
- verified-capture replay -> no provider request and no mutation;
- refunded order -> no provider request and no mutation.

### Direct webhook

Pin at least:

- missing/invalid local preflight -> terminal no mutation;
- binding/verification failure -> terminal no paid-state mutation;
- captured -> authenticated provider metadata, paid-state transition, verified flags and save;
- failed Woo status transition -> no verified-success flags;
- verified-capture replay -> no provider request and no mutation;
- refunded order -> no provider request and no mutation.

## Compatibility trap preserved for T4

A direct caller of `return_from_upayments()` does not necessarily arrive through the canonical WC-API router with a GET `page` marker. `PaymentLifecycle::handle_callback()` currently identifies browser mode by presence of that marker. Therefore T3 must not be interpreted as permission to replace a direct legacy browser-method call with a raw call to `PaymentLifecycle::handle_callback()` without request-shape normalization or an explicit compatibility decision.

## Non-scope retained

T3 did not modify:

- `UPayments.php`;
- any `src/` or `includes/` runtime file;
- provider/persisted identities;
- provider egress policy;
- H12 token/security logic;
- subscription financial logic;
- package/release authority.

## Completion gate — satisfied

T3 merged only after:

- the characterization harness executed the real legacy methods/private verifier against controlled Woo/provider doubles;
- characterization passed without first-party warnings/notices/deprecations;
- T1 and T2 permanent harnesses remained green;
- Quality/H12 and full compatibility gates remained green;
- deterministic release remained exactly the T2 runtime candidate: 51 files / SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`;
- no production runtime file changed;
- no T4 runtime implementation was introduced.

Exact T3 evidence:

- PR #107 certified head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`;
- squash-merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`;
- fresh merged-main Quality Gates, Compatibility Certification and CodeQL succeeded;
- package remained 51 files / SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`.

No T4 runtime implementation is authorized by T3 alone.

## Successor

The current successor program is **`post-t3-ecosystem-hardening`**.

Canonical plan: [`2026-09-10-post-t3-ecosystem-hardening.md`](2026-09-10-post-t3-ecosystem-hardening.md).

The plan sequences R0/R1/E1/E2/E3/R2/R3/R4 before the separately gated R5/T4 architecture decision and final R6 qualification.
