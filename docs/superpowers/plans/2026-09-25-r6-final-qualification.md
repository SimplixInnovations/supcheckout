---
feature: r6-final-qualification
status: candidate-ready-for-independent-review
updated: 2026-09-25
branch: r6/final-qualification-closure
runtime-base: 776e0d3b98a54b78c502a792cc01fca7d757d6fe
evidence-series: r6/final-qualification-closure (this document + harnesses + workflows)
artifact-filename: supcheckout-0.1.0.zip
artifact-files: 62
artifact-sha256: 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd
---

# R6 Final Qualification

## [S1] Goal

Prove or truthfully classify the final Approach 3 candidate.

R6 is not an architecture/product feature phase. It closes remaining repository-addressable qualification evidence and classifies external/manual surfaces honestly.

## [S2] Scope

- fresh-clone independence proof
- full permanent quality suite
- H12 PHP + Blocks
- 20-cell Compatibility + Compatibility Gate
- Action Scheduler final matrix (WC 10.8 / 11.0 / 11.1)
- R3 CycleClaim DB/concurrency authority
- R4 queue/lifecycle concurrency
- R5 callback final qualification
- large-store feeder benchmark (bounded synthetic)
- migration/lifecycle final matrix
- payment failure-injection matrix
- security final audit
- provider-contract closeout classification
- external/manual matrix classification
- repository residual classification
- deterministic final artifact
- Approach 3 owner-acceptance candidate label

## [S3] Non-scope

- version bump / tag / GitHub Release / WordPress.org publication
- promoting unresolved provider contracts to verified
- live non-idempotent recurring charges
- replacing Approach 2 owner acceptance
- new payment/product features

## [S4] Hard invariants

1. Never weaken fail-closed paid/verified state to finish R6.
2. Automatic recurring VERIFIED_SUCCESS remains unreachable while auto-deduct capture is UNPROVEN.
3. Protected identities unchanged.
4. Provider HTTP egress remains the three known sites.
5. No publication without explicit owner authorization.

## Tasks

- [x] T1: fresh-clone independence + exact clone SHA
- [x] T2: full quality suite + H12 + permanent gates
- [x] T3: compatibility / Action Scheduler / R3 / R4 / R5 runtime matrix
- [x] T4: large-store + migration + failure-injection + security classification
- [x] T5: residual/external classification + final artifact + owner-acceptance candidate

## R6 qualification evidence

### Fresh clone

- clone path: disposable worktree/clone of `r6/final-qualification`
- clone SHA: `4689d1416d075a656ff9d6ac311a475cb8af4c6a`
- untracked/developer caches: absent
- `dist/`: absent
- committed private keys / `sk_live_`: none
- machine-specific absolute paths in tracked source: none required for package

### Quality

- `composer validate --strict`: PASS
- `composer audit --locked`: PASS (no advisories)
- unit: 313 tests / 2017 assertions PASS
- PHPStan: no errors
- PHPCS: clean
- JS harnesses: PASS
- analytics-return: 27 PASS / 0 FAIL
- http-server diagnostics: PASS
- `git diff --check`: PASS

### H12

- PHP: 1936 PASS / 0 FAIL (categories 377+841+46+662+10)
- Blocks: included in H12 Regression Harness green on exact head

### Compatibility / permanent gates

- 20/20 Compatibility + Compatibility Gate: green (PR #116 and post-merge main)
- R2 callback cache legacy+HPOS: green
- R3 CycleClaim DB/concurrency: green
- R4 scheduling runtime: green
- Ecosystem Gate: green
- Release Artifact + Cross-platform + Release Gate: green
- Provider Sandbox: green
- WordPress.org Plugin Check: green
- CodeQL: green
- Governance / Quality Platform / PHP syntax: green

### Action Scheduler matrix

Covered by Compatibility matrix bands WC 10.8.1 / 11.0.1 / 11.1.0 with legacy+HPOS and R4 scheduling runtime + ecosystem subscription lifecycle matrix (API presence, initialization, unique single-action, exact-args cancellation, pending/in-progress, no second bundled copy).

### R3 / R4 / R5 runtime

- security threat-model harness: 86 PASS / 0 FAIL
- provider payment lifecycle: 143 PASS / 0 FAIL
- provider amount binding: 4 PASS / 0 FAIL
- architecture foundation: 100 PASS / 0 FAIL
- HTTP transport: 33 PASS / 0 FAIL
- R3 subscription safety harness: 12 PASS / 0 FAIL
- R3 characterization/failure matrix: 89 PASS / 0 FAIL
- T3 legacy direct callback: 75 PASS / 0 FAIL
- T1 active callback: 41 PASS / 0 FAIL
- T2 legacy callback routing: 25 PASS / 0 FAIL
- Phase 9I preflight/executor/operations: 123 / 59 / 81 PASS / 0 FAIL
- Quality platform families (Q1-Q16 surfaces exercised): PASS / 0 FAIL
- Release artifact harness: 69 PASS / 0 FAIL
- CallbackLifecycleRuntimeTest (real Woo, Compatibility cell): green

### Large-store feeder invariant

- `HistoricalEnrollment::BATCH_SIZE = 50`
- unit `SchedulingCorrectionTest` proves scanned/cursor == 50 per batch across totals 0,1,49,50,51,75,100,125
- hard invariant `orders loaded/request <= 50`: **VERIFIED (structural + unit)**
- synthetic 1k/5k/10k wall-clock/DB profiling: **NOT TESTED** in this environment (no production-scale store; do not claim production throughput)

### Migration / lifecycle

- migration core/settings/bootstrap harnesses: PASS
- identity/namespace/frontend/residue harnesses: PASS
- fresh install / upgrade / activation retention: covered by Compatibility + Operations + migration harnesses

### Payment failure-injection

Covered by provider lifecycle harness, amount binding, authenticated status, provenance DB failure, HTTP transport fail-closed paths and real-Woo terminal-state cells (never false paid / never false verified / no order-key on unverified path).

### Security

- threat-model harness 86/0
- no hardcoded secrets in source/package
- fail-closed auth on public order status (constant-time key compare)
- no blind non-idempotent retries
- provider egress remains 3 sites (`execute_upayments_request`, `StatusVerifier::verify`, Scheduler renewal transport)

### Provider contracts (unchanged)

| Item | Classification |
|---|---|
| auto-deduct CAPTURED semantics | UNPROVEN |
| remote recurring-cycle identity | UNPROVEN |
| HMAC authentication | PROVIDER CLARIFICATION REQUIRED |
| token persistence | PROVIDER CLARIFICATION REQUIRED |
| live recurring payment | EXTERNAL REQUIRED |

Automatic recurring `VERIFIED_SUCCESS` remains unreachable.

### External / manual matrix

| Surface | Classification |
|---|---|
| live recurring payment | EXTERNAL REQUIRED |
| live cards/issuers | EXTERNAL REQUIRED |
| Apple Pay / Google Pay / Samsung Pay | NOT TESTED |
| WPML / WCML / multicurrency / Arabic RTL | EXTERNAL REQUIRED |
| commercial themes / checkout builders | EXTERNAL REQUIRED |
| real CDN/reverse proxy | EXTERNAL REQUIRED |
| production-scale merchant store | EXTERNAL REQUIRED |
| penetration test / PCI / legal | EXTERNAL REQUIRED |

### Unsupported (retained)

FunnelKit one-click upsells, CartFlows upsells/downsells, automatic gateway refunds, arbitrary marketplace multi-split, WooCommerce Subscriptions automatic-renewal gateway API, third-party subscription recurring integrations.

### Repository residuals

- TODO/FIXME/HACK hits: only historical plan prose describing scans — no production technical debt affecting release safety
- open PRs: none after control-plane merge
- R5 feature branch: deleted
- tags/releases: none created (publication prohibited)

### Final artifact

- source: `4689d1416d075a656ff9d6ac311a475cb8af4c6a` (pre-R6-docs) and final R6 evidence head (docs-only delta)
- filename: `supcheckout-0.1.0.zip`
- files: 62
- SHA-256: `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`
- dual local builds: byte-identical
- hosted Linux/Windows/canonical: identical (Release Artifact)

### Approach 3 acceptance candidate

**APPROACH 3 CANDIDATE FOR OWNER TECHNICAL ACCEPTANCE**

Frozen Approach 2 remains the accepted baseline until the owner explicitly replaces it. No publication.

## One-pass absolute-final closure (2026-09-25)

### Current-main CI failure closure

PHP 8.4 / WC 11.1.0 / legacy `checkout-request-context-http.sh` died as built-in-server segfault (curl 52) during Store API cart after prior matrices.

Root cause: PHP development server is single-process and unstable under successive WooCommerce/Action-Scheduler request lifecycles on PHP 8.4.

Fix: restart and re-ready the built-in server between logically separate configuration matrices; retain hard transport failure and server-death diagnostics.

### Added permanent owners

- `tests/integration/ActionSchedulerCompatibilityRuntimeTest.php` (registered in Compatibility after storage selection)
- `tests/performance/r6-large-store-benchmark.php` + `.github/workflows/r6-large-store-benchmark.yml` (100/1k/5k/10k × legacy/HPOS)
- `tests/security/r6-secret-scan.sh`, `r6-actions-pin-audit.sh`, `r6-dast-smoke.php`
- `tests/integration/lib/reverse-proxy-smoke.sh`
- `tests/e2e/r6-browser-ux.spec.ts`
- `docs/project/R6-COVERAGE-MATRIX.md`
- `docs/project/PROVIDER-CLARIFICATION-PACKAGE.md`

### External blockers (unchanged)

- auto-deduct CAPTURED semantics UNPROVEN
- remote cycle identity UNPROVEN
- HMAC PROVIDER CLARIFICATION REQUIRED
- token persistence PROVIDER CLARIFICATION REQUIRED
- live recurring/cards/wallets/CDN/pentest/PCI/legal EXTERNAL REQUIRED

### Final state (authoritative)

```text
R6 status: done_verified
R6 certified head: 4eccf884f95fe510a478c243ecb32d039f9468a9
R6 merged main: 3bd37c6a925ec724c7fbf8d8c0346fd7e21931cf
Approach 3: candidate_for_owner_technical_acceptance
current gate: owner_technical_acceptance
package: supcheckout-0.1.0.zip / 62 files / 0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd
```

Independent review token: `R6_REVIEWER_APPROVED_SHA=4eccf884f95fe510a478c243ecb32d039f9468a9`.

Do not claim Approach 3 owner acceptance until the owner explicitly says so.

Do not publish. Approach 2 remains owner accepted until replaced.

Provider HMAC / token persistence / auto-deduct capture / remote cycle identity remain **PROVIDER CLARIFICATION REQUIRED / UNPROVEN**.

Recurring engineering fail-closed safety: VERIFIED. Automatic recurring `VERIFIED_SUCCESS`: UNREACHABLE / FAIL-CLOSED.
