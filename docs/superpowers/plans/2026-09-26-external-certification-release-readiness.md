# External Certification & Release Readiness — Plan

**Status:** FINAL-2 corrections applied — ready for independent review
**Branch:** `release/external-certification-readiness`
**Base:** `f7a017124d04ced50ea4dae283fe198301dc6eba`

## Ledger

| # | Task | Status | Evidence |
|---|---|---|---|
| 0 | SDD setup + worktree | DONE | design + plan + worktree |
| 1 | Control-plane reconcile | DONE | unique YAML keys; gate=external_certification_release_readiness; Approach 2 HISTORICAL/SUPERSEDED |
| 2 | Upstream matrix refresh | DONE | WP 6.9.9/7.0.6/7.1.2 + WC 11.1.2; Compatibility Gate PASS |
| 3 | Upstream freshness policy | DONE | `scripts/check-release-upstream-currentness.py` live lookup CURRENT |
| 4 | UPayments contract snapshot | DONE | evidence/UPAYMENTS-CONTRACT-SNAPSHOT + SANDBOX-AUTH-OBSERVATION |
| 5 | Sandbox auth probe | DONE | real Charge probes A–D executed; SANDBOX_AUTH_OBSERVATION |
| 6 | Provider contact draft | STOPPED — AUTHORIZATION REQUIRED | draft ready; OWNER_PROVIDER_CONTACT_AUTHORIZATION absent |
| 7 | Decision tree | DONE | UPAYMENTS-CONTRACT-DECISION-TREE.md |
| 8 | Recurring claims audit | DONE | RECURRING-CLAIMS-AUDIT.md |
| 9 | Live one-time payment | STOPPED — OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION REQUIRED | plan only |
| 10 | Saved-card live | STOPPED — AUTHORIZATION REQUIRED | plan only |
| 11 | Recurring live | STOPPED — multi-prerequisite + owner token | plan only |
| 12 | Wallets | DONE — EXTERNAL BLOCKED | WALLET-CERTIFICATION-MATRIX.md |
| 13 | WPML/WCML/multicurrency | DONE — EXTERNAL BLOCKED | I18N-MULTICURRENCY-CERTIFICATION.md |
| 14 | Commercial themes | DONE — EXTERNAL BLOCKED | public claims + E3 free themes bounded |
| 15 | Edge/CDN | DONE — EXTERNAL BLOCKED | EDGE-CACHE-CERTIFICATION.md |
| 16 | Production performance | DONE — EXTERNAL BLOCKED | PRODUCTION-PERFORMANCE-QUALIFICATION.md |
| 17 | Independent pentest | DONE — HANDOFF PREPARED / EXTERNAL EXECUTION REQUIRED | PENTEST-SCOPE + HANDOFF |
| 18 | PCI/privacy/legal | DONE — EXTERNAL REVIEW REQUIRED | compliance/* |
| 19 | Supply-chain SBOM | DONE | generate-sbom-evidence.py: runtime 62 comps, dev 40 comps, license_unknown=0 |
| 20 | Public claims audit | DONE | PUBLIC-CLAIMS-AUDIT.md |
| 21 | Release scope | DONE | RELEASE-SCOPE-DECISION.md (saved-card BLOCKED) |
| 22 | Version promotion plan | DONE | VERSION-PROMOTION-PLAN.md (not applied) |
| 23 | RC gate | DONE | RELEASE-CANDIDATE-GATE.md scope-aware |
| 24 | Runtime tranche stop | DONE | decision tree; NEW_RUNTIME_TRANCHE_REQUIRED=NONE pending provider |
| 25 | External inventory | DONE | EXTERNAL-REQUIRED-INVENTORY.md |
| 26 | Branch-wide verification | DONE | exact-head CI + local unit/currentness/SBOM |
| 27 | Superpowers reviews | DONE — scoped | governance + currentness tests |
| 28 | Draft PR | DONE | PR #125 DRAFT |

## Constraints

- No version bump / tag / release / publication
- Accepted artifact hash remains `0f9c4b60…`
- Recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED
