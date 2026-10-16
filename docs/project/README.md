# SUPCheckout for UPayments — Project Control Documents

This directory is the engineering control plane for **SUPCheckout for UPayments**.

Every new AI-agent, developer, reviewer, owner or release session starts with [`START-HERE.md`](START-HERE.md). Chat history is context only; it is never a substitute for live repository verification.

The governing rule is simple:

> **Living documents own current truth; historical documents preserve milestone truth.**

Do not rewrite historical evidence merely to make old milestones look as though they originally occurred under current branding.

## Required read order

For a new engineering or release session:

1. [`START-HERE.md`](START-HERE.md) — mandatory session bootstrap, exact program sequence and operational gate
2. [`../../AGENTS.md`](../../AGENTS.md) — repository-wide engineering, compatibility and merge rules
3. [`PROJECT-STATUS.md`](PROJECT-STATUS.md) — current verified state
4. [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md) — fresh-clone, local-acceptance and release sequence
5. [`NAMING-IDENTITY-STANDARD.md`](NAMING-IDENTITY-STANDARD.md) — canonical SUPCheckout identity and protected compatibility IDs
6. [`../COMPATIBILITY.md`](../COMPATIBILITY.md) — public compatibility/certification boundary
7. [`NEW-CHAT-HANDOFF.md`](NEW-CHAT-HANDOFF.md) — compact continuation context
8. [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md) — deterministic artifact and migration/release contract
9. [`ENTERPRISE-CERTIFICATION.md`](ENTERPRISE-CERTIFICATION.md) — retained certification evidence
10. [`MASTER-ENGINEERING-PLAYBOOK.md`](MASTER-ENGINEERING-PLAYBOOK.md) — broader permanent engineering discipline
11. [`ARCHITECTURE-CODE-QUALITY.md`](ARCHITECTURE-CODE-QUALITY.md) — architecture and code-quality controls
12. [`QUALITY-PLATFORM.md`](QUALITY-PLATFORM.md) — permanent closed Q1-Q19 record
13. [`BASELINE-H12.md`](BASELINE-H12.md) — historical token/saved-card/subscription evidence anchors when needed

## Living current-state documents

Reconcile these when verified project truth changes:

- `START-HERE.md` — program-level phase/gate/next-action continuity;
- `PROJECT-STATUS.md`
- `OWNER-HANDOFF.md`
- `NAMING-IDENTITY-STANDARD.md`
- `NEW-CHAT-HANDOFF.md`
- `../../README.md`
- `../COMPATIBILITY.md`
- `../../AGENTS.md`
- `RELEASE-ENGINEERING.md` when the artifact/migration/release contract changes

These files should be concise enough to operate from. Deep milestone detail belongs in retained certification/history records rather than being duplicated into every living document.

## Historical / closure evidence

These may intentionally contain former product names, old repository coordinates, old SHAs and then-current gate language:

- `REPOSITORY-READINESS.md`
- `REPOSITORY-AUDIT.md`
- Phase 0 / Phase 9I records
- Provider/Security closure evidence
- Quality Platform Q1-Q19 closure ledger
- historical Enterprise Task records
- `../history/**`
- approved `docs/superpowers/**` plans/specs that describe what was decided or executed at the time

Do not bulk-rebrand these records. Preserve milestone facts.

## Authority by question

| Question | Authority |
|---|---|
| How must a new session bootstrap, and what program gate controls the next step? | `START-HERE.md` |
| What substantive work is happening right now? | live open GitHub PR(s), then the `START-HERE.md` program ledger |
| Where are we now? | `PROJECT-STATUS.md` |
| What should the owner do next? | `OWNER-HANDOFF.md` |
| What is the canonical name/slug/namespace/bootstrap? | `NAMING-IDENTITY-STANDARD.md` |
| What can be publicly claimed as compatible? | `docs/COMPATIBILITY.md` |
| How is the ZIP built/verified/migrated/released? | `RELEASE-ENGINEERING.md` |
| What evidence closed the enterprise program? | `ENTERPRISE-CERTIFICATION.md` |
| What are permanent coding/merge rules? | `AGENTS.md` + `MASTER-ENGINEERING-PLAYBOOK.md` |
| What was true at an older milestone? | the relevant historical phase/quality record |

## Current identity summary

```text
Human product:       SUPCheckout for UPayments
Short product:       SUPCheckout
Technical slug:      supcheckout
Text domain:         supcheckout
Namespace:           Simplixi\SUPCheckout
Package root:        supcheckout/
Main file:           supcheckout.php
Canonical basename:  supcheckout/supcheckout.php
Canonical GitHub:    SimplixInnovations/supcheckout
Development version: 0.1.0
```

`for` is display/relationship wording only and must never be encoded into technical identifiers.

Existing provider/payment/persisted compatibility identities are not renamed merely for naming uniformity.

## Precedence

1. Freshly verified live GitHub/source/provider evidence.
2. `START-HERE.md` for session bootstrap, program sequencing and current operational gate.
3. `PROJECT-STATUS.md` for current engineering state.
4. `OWNER-HANDOFF.md` for owner/local/release actions.
5. `NAMING-IDENTITY-STANDARD.md` for identity.
6. `docs/COMPATIBILITY.md` for public claims.
7. `RELEASE-ENGINEERING.md` for artifact/migration/release mechanics.
8. `AGENTS.md` / `MASTER-ENGINEERING-PLAYBOOK.md` for permanent engineering discipline.
9. Historical records for milestone evidence only.

When documents appear inconsistent, first determine whether one is a historical snapshot. Never “fix” historical truth by rewriting it into current branding.
