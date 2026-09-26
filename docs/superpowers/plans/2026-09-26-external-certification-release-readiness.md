# External Certification & Release Readiness — Plan

**Status:** in progress
**Branch:** `release/external-certification-readiness`
**Base:** `f7a017124d04ced50ea4dae283fe198301dc6eba`

## Ledger

| # | Task | Status | Evidence |
|---|---|---|---|
| 0 | SDD setup + worktree | DONE | this plan + design + worktree |
| 1 | Control-plane reconcile | TODO | |
| 2 | Upstream matrix refresh | TODO | |
| 3 | Upstream freshness policy | TODO | |
| 4 | UPayments contract snapshot | TODO | |
| 5 | Sandbox auth probe | TODO | |
| 6 | Provider contact draft | TODO | |
| 7 | Decision tree | TODO | |
| 8 | Recurring claims audit | TODO | |
| 9-11 | Live/recurring plans | TODO | |
| 12-16 | External matrices | TODO | |
| 17-18 | Pentest/PCI/privacy | TODO | |
| 19 | Supply-chain evidence | TODO | |
| 20-23 | Claims/scope/version/RC | TODO | |
| 24-25 | Tranche stop + external inventory | TODO | |
| 26-28 | Verify + draft PR + report | TODO | |

## Constraints

- No version bump / tag / release / publication
- No live payment without owner token
- No provider contact without owner token
- Accepted artifact hash must remain `0f9c4b60…` unless a separately accepted package change is required
- Recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED
