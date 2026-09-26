# External Certification & Release Readiness — Design Spec

**Date:** 2026-09-26
**Binding spec:** master instruction for SUPCheckout external-certification and enterprise release readiness
**Base:** `f7a017124d04ced50ea4dae283fe198301dc6eba`
**Branch:** `release/external-certification-readiness`

## Architecture constraint

Owner-accepted Approach 3 runtime is frozen:

```text
OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3
accepted source: 146d65a1c182630c1acc651cacafe30cff5f6b79
artifact: supcheckout-0.1.0.zip / 62 files / 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd
```

This program is an **external-certification and release-readiness layer**, not R7.

If evidence forces production/runtime change: record blocker, open a separately bounded production tranche. Do not silently modify accepted runtime.

## Classification vocabulary

`VERIFIED` | `VERIFIED — BOUNDED` | `FAILED` | `NOT TESTED` | `EXTERNAL REQUIRED` | `PROVIDER CLARIFICATION REQUIRED` | `UNPROVEN` | `NOT SUPPORTED`

## External action tokens

Absent tokens mean prepare only:

- `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`
- `OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES`
- `OWNER_RECURRING_LIVE_TEST_AUTHORIZATION=YES`
- `OWNER_PUBLICATION_AUTHORIZATION=YES`

## Five highest-risk release failures

1. UPayments auth contract (HMAC) invalidates Bearer-only egress
2. Local token persistence violates provider subscription/security contract
3. Auto-deduct responses cannot prove captured truth / cycle idempotency
4. Release claims exceed certified evidence
5. Qualification uses stale WP/WC patches

Each requires owner, evidence, classification, release decision.

## Task map

| Task | Deliverable | Class |
|---|---|---|
| 0 | design + plan + worktree | done |
| 1 | control-plane reconcile | repo |
| 2 | current upstream matrix | repo |
| 3 | upstream freshness policy | repo |
| 4 | UPayments contract snapshot | docs |
| 5 | sandbox auth probe | tests/manual |
| 6 | provider contact draft | docs (unsent) |
| 7 | contract decision tree | docs |
| 8 | recurring claims audit | docs |
| 9-11 | live/recurring plans | docs only |
| 12-16 | wallet/i18n/theme/edge/perf matrices | docs + EXTERNAL |
| 17-18 | pentest/PCI/privacy packages | docs |
| 19 | SBOM/supply-chain | scripts + evidence |
| 20-23 | claims/scope/version/RC gate | docs |
| 24-25 | runtime-tranche stop + external inventory | docs |
| 26-28 | verify + draft PR + report | gates |
