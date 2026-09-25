# SUPCheckout for UPayments — Start Here

> **Mandatory session bootstrap.** Every AI agent, developer, reviewer, release operator or owner working from a new chat, machine, clone, worktree or session must read this file first, then follow the authority chain below.
>
> **Never start substantive work from chat memory alone. Live repository evidence wins over this document whenever they differ.**

## 1. Golden project identity

| Surface | Canonical value |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Short product name | **SUPCheckout** |
| Maintainer | **Simplix Innovations** |
| Provider scope | **UPayments only** |
| Repository | `SimplixInnovations/supcheckout` |
| Default branch | `main` |
| Technical slug / text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| Package root | `supcheckout/` |
| First-stable bootstrap | `supcheckout/UPayments.php` |
| Development version | `0.1.0` |
| Public tag / GitHub Release | **Not created** |
| WordPress.org publication | **Not performed / not authorized** |

The word **for** is relationship copy only. It must not be encoded into repository, package, WordPress.org, namespace, REST, CSS/JS or release-artifact identifiers.

SUPCheckout is permanently **UPayments-specific**. Do not turn this repository into a generic payment router or add unrelated provider adapters.

## 2. Session bootstrap contract

Before analysis, implementation, review, release work or claims about project state:

1. fetch/prune the repository and resolve live `main`;
2. inspect remote/local branches, worktrees and stashes;
3. inspect open PRs and issues;
4. inspect tags and GitHub Releases;
5. inspect the active Main Rule / required checks;
6. inspect exact-head CI/check state relevant to the work;
7. compare live evidence with the living documents below;
8. reconcile living documents if verified project truth has changed;
9. only then begin substantive work.

For a local clone:

```bash
git fetch --prune --tags origin
git remote -v
git branch --show-current
git branch -r
git status --short
git rev-parse HEAD
git rev-parse origin/main
git worktree list
git stash list
```

A new session must never assume that a SHA, PR state, branch count, package hash or gate result copied from an older chat is still current.

## 3. Authority chain

Read in this order after this file:

1. [`../../AGENTS.md`](../../AGENTS.md) — repository-wide engineering, compatibility, security and merge rules;
2. [`PROJECT-STATUS.md`](PROJECT-STATUS.md) — canonical living engineering state;
3. [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md) — fresh-clone, local-acceptance and release-decision procedure;
4. [`NAMING-IDENTITY-STANDARD.md`](NAMING-IDENTITY-STANDARD.md) — canonical identity and protected compatibility IDs;
5. [`../COMPATIBILITY.md`](../COMPATIBILITY.md) — public compatibility/certification boundary;
6. [`NEW-CHAT-HANDOFF.md`](NEW-CHAT-HANDOFF.md) — compact continuation context;
7. [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md) — deterministic package, migration and release contract;
8. [`ENTERPRISE-CERTIFICATION.md`](ENTERPRISE-CERTIFICATION.md) — retained certification evidence;
9. relevant historical phase/quality/spec/plan records when touching their contracts.

Historical documents may contain former product names, old repository coordinates and old SHAs because those facts were true at the time. Do not bulk-rewrite historical evidence into current branding.

## 4. Frozen acceptance reference and current Approach 3 coordinate

The owner-accepted Approach 2 regression reference remains:

- source: `0c883d609906676966002eb022a82a9656eeacc5`;
- package: `supcheckout-0.1.0.zip`;
- files: 51;
- SHA-256: `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`.

That accepted baseline is frozen until an explicit fresh owner acceptance at Approach 3 closeout.

Approach 3 has advanced through three verified tranches:

- **T1 DONE / VERIFIED** — architecture guardrails and active callback characterization; merged main `beb89ac0c4d8c0e9b7c8b2de1e13c237bbd37b15`.
- **T2 DONE / VERIFIED / runtime-bearing** — bounded legacy callback fallback consolidation; merged main `047cc86060efb97761d7a0cc4a3806f971ab6fe1`; deterministic candidate package 51 files / SHA-256 `368aaa5cb1a75e6df41ff17bb2e2126431b49da5e6b8dfe508c718433009fc04`.
- **T3 DONE / VERIFIED / runtime-neutral** — direct legacy return/webhook/private-verifier characterization; PR #107 exact certified head `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`; squash-merged main `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`.

T3 preserved a critical compatibility constraint: a direct caller of `WC_Upayments::return_from_upayments()` may lack the WC-API GET `page` marker that current `PaymentLifecycle::handle_callback()` uses to infer browser mode. Therefore T3 does **not** authorize naïve T4 delegation.

## 5. Current program gate

The current substantive program is:

**`post-t3-ecosystem-hardening`**

Canonical plan:

`docs/superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`

The completed R0/R1/E1-E3 integration milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. No feature branch is retained for that milestone.

The latest repository-executable E3 runtime checkpoint inside that PR is:

`540b733c29656758f2392817649fc3d4a4db585d`

At that exact SHA, Quality/H12, the full 20-cell Compatibility matrix + Compatibility Gate, Provider Sandbox Certification, WordPress.org Submission Check, Release Artifact and the complete Ecosystem Certification matrix succeeded. E3 repository automation covers five free themes plus real child-theme WooCommerce template overrides, four free cache/optimizer coexistence plugins, delayed/combined/repeated Classic interaction behavior, and analytics-return replay characterization.

GitHub default CodeQL JavaScript/TypeScript analysis did not reach a terminal verdict for this historical checkpoint; that hosted-security gap must not be rewritten as a success claim. Its deterministic package is 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

R0, R1 and E1 are **DONE / VERIFIED and integrated through PR #108**. Repository-executable generic E2 is **DONE / CERTIFIED**. E3 repository-executable runtime evidence is **DONE / VERIFIED** at this checkpoint. Named paid/licensed themes, paid optimizers, CDN/server-specific cache modes, browser/device visual evidence and named analytics deduplication remain external/manual unless actually exercised.

That candidate does **not** redefine the accepted Approach 2 baseline.

## 6. Post-T3 execution sequence

The approved bounded sequence is:

```text
R0  control-plane reconciliation
 ↓
R1  bounded correctness / presentation / accessibility
 ↓
E1  interactive checkout compatibility
 ↓
E2  economics and product compatibility
 ↓
E3  theme / cache / analytics interaction evidence
 ↓
R2  callback portability and cache safety
 ↓
R3  subscription safety
 ↓
R4  scalability / idempotency / observability
 ↓
R5  callback lifecycle consolidation (Option B / ADR-003) — DONE / VERIFIED
 ↓
R6  exact-head qualification + fresh owner re-acceptance
 ↓
explicit version/publication decision
```

R0-R5 are bounded evidence-first work under the approved post-T3 plan. R5 used its own architecture decision (Option B / ADR-003) and is complete. **R6 is the current executable gate.**

## 7. Active-work ledger

| Field | Current value |
|---|---|
| Program phase | **Approach 3 post-T3 ecosystem hardening** |
| Approach 2 | **DONE / VERIFIED / owner accepted** |
| Frozen accepted baseline | `0c883d609906676966002eb022a82a9656eeacc5` |
| T1 | **DONE / VERIFIED** |
| T2 | **DONE / VERIFIED / runtime-bearing** |
| T3 | **DONE / VERIFIED / runtime-neutral** |
| Latest integrated post-T3 milestone main | `d69377d3e26831270a00151025d24cc64be9d36b` |
| Active task | **owner technical acceptance** |
| Latest repository-executable E3 runtime checkpoint | `540b733c29656758f2392817649fc3d4a4db585d` |
| Latest candidate package at that checkpoint | 55 files / SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c` |
| R0 / R1 / E1 | **DONE / VERIFIED and integrated through PR #108** |
| E2 repository-executable generic | **DONE / CERTIFIED** |
| E3 repository-executable | **DONE / VERIFIED** at `540b733c29656758f2392817649fc3d4a4db585d` |
| R2 | **DONE / VERIFIED** — certified PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, squash-merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`; candidate package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3` |
| R3 | **DONE / VERIFIED** — certified PR #112 head `de0162b4a1cca77c62f07290224b055902120c2a`, squash-merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`; candidate package SHA-256 `070279120064a6fe558dbeef3702a10b90330b8dc79fd9f21f028cd5fefba4da` (NOT owner accepted) |
| R4 | **DONE / VERIFIED** — certified PR #114 head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, squash-merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`; package 62 files SHA-256 `2543c2a0bfdba53e8968bd1a09b263a3a2b7bd02a11eabd41c102aa5d6dc7b8b` (NOT owner accepted) |
| R5 | **DONE / VERIFIED** — Option B / ADR-003; certified PR #116 head `6fc225fc736da107de533ba8e19a12dc5c37227d`, squash-merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` (NOT owner accepted) |
| Current operational gate | **owner technical acceptance** |
| Public release authorization | **NOT GRANTED** |
| Source of live truth | **GitHub + exact source/check/package evidence** |

### Operational tracking model

Use three layers of truth:

1. **Program state — this file.** Phase, gates, accepted baseline, next program action and release authorization.
2. **Active task state — a GitHub PR when one exists.** Base, exact head, scope/non-scope, failures, current gate, next action and merge evidence. Between bounded tranches, zero open PRs is the expected clean state.
3. **Durable history — merged PRs, commits, CI and retained evidence.** Do not duplicate a forever-growing event stream here.

Update this ledger whenever the active phase, acceptance state, release authorization, current gate, next substantive action or runtime-bearing evidence anchor changes materially.

## 8. Permanent compatibility and safety boundaries

Do not mechanically rename or refactor protected identities, including:

- gateway/payment ID `upayments`;
- `woocommerce_upayments_settings`;
- Blocks / Store API identity `upayments`;
- callback `wc_upayments`;
- historical `_upay_*` metadata;
- `UPayments_order_id` and related provider-order identities;
- token/provenance/scope/generation state;
- `upay_process_subscriptions` and billing-attempt state;
- historical order payment-method values;
- frozen Phase 9I migration identities;
- public compatibility wrapper `getAPIUrlForRetreiveCards()`;
- normalized `whitelabled` compatibility shape.

`supcheckout/UPayments.php` is an intentional first-stable compatibility exception. A physical bootstrap rename requires its own migration project.

Payment/security ambiguity fails closed. Routing input is not financial truth. Charge initialization is not capture. Paid state requires authenticated provider verification bound to the correct order/transaction/economics. Non-idempotent payment/refund/auto-deduct mutations are not blindly retried.

## 9. Deferred/external evidence boundary

Repository automation does not automatically prove:

- production merchant payment completion;
- real wallet/account/device completion;
- WPML/WCML, multilingual, multicurrency or RTL behavior;
- broad browser/device/theme/accessibility behavior;
- representative production-store load/performance;
- penetration testing, PCI or legal/compliance attestation;
- live non-idempotent subscription auto-deduction;
- provider webhook signatures until a stable documented contract exists.

Automatic WooCommerce refunds and arbitrary marketplace multi-split remain unsupported unless separately designed and approved.

## 10. Repository governance target

Outside temporary active work, desired repository state is:

- default branch `main`;
- no stale remote feature/audit branches;
- no unintended open PRs/issues;
- no public tags/releases before explicit approval;
- squash-only merge and linear history;
- deletion/non-fast-forward protection;
- required review-thread resolution;
- no bypass actors;
- strict required checks: `Governance`, `H12 Regression Harness`, `Compatibility Gate`, `Release Gate`.

Any continuation or release claim must verify this live.

## 11. Change-tracking protocol

For substantive work:

1. establish live baseline and scope;
2. use the authorized dedicated branch;
3. record intended outcome in PR/spec/plan;
4. characterize before behavior change;
5. use meaningful RED → prove failure → minimal GREEN for runtime fixes;
6. preserve compatibility-sensitive identities and authority rules;
7. obtain exact-head verification;
8. resolve valid review findings;
9. reconcile living state when truth changes;
10. merge only under repository policy;
11. reverify post-merge `main`;
12. clean stale task branches after safe closure.

Never create a new numbered historical phase merely because work continued in a new chat.

## 12. What a new agent/developer must be able to state

Before proceeding, a competent continuation must know from live evidence:

- exact current `main` SHA;
- active PR/branch and exact head if any;
- latest runtime-bearing certified coordinate;
- frozen owner-accepted baseline;
- current program phase and gate;
- whether release/publication is authorized;
- protected compatibility identities;
- exact tests/gates required for the intended work;
- unresolved manual/external evidence boundaries.

If any of these are unknown, the session is **not bootstrapped yet**.

## 13. Immediate next step

R3 is DONE / VERIFIED (PR #112 / main e1ad338). R4 is DONE / VERIFIED (PR #114 / main `10a33b4`). R5 is DONE / VERIFIED (PR #116 / main `50170ea`). R6 is DONE / VERIFIED. Current gate is **owner technical acceptance**. Do not publish without explicit owner authorization.
