# Release Candidate Gate

An RC may be produced only when **scope-aware** mandatory blockers are closed.

## Mandatory for core one-time payment RC

- [x] current secure upstream WP/WC patches certified (live lookup CURRENT)
- [x] all permanent CI green on certified repository head
- [x] accepted runtime/package provenance understood
- [ ] production auth contract resolved for provider API traffic — core Charge + track-ID Status (`PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED`); resolvable by a written UPayments answer **or** by the owner-run account probe in `PRODUCTION-AUTH-ACCOUNT-PROBE.md` (owner decision 2026-10-08)
- [ ] live one-time payment acceptance complete
- [x] public-claims audit complete
- [x] no misleading recurring claim in current copy
- [x] secret scan clean
- [x] dependency/security audit clean
- [ ] independent pentest complete **if claiming enterprise production readiness**
- [ ] PCI/legal scope reviewed externally **if owner release policy requires**
- [x] release scope explicitly locked

## Feature-scoped blockers (block only when included/advertised)

| Feature | Blockers |
|---|---|
| saved-card | token contract + live tokenization (currently **BLOCKED / EXCLUDED**) |
| recurring | capture/cycle/token + live recurring + owner recurring token |
| wallets | device/account certification |
| WPML/WCML | licensed environment |
| multicurrency | licensed environment + exact amount binding |
| commercial themes/builders | licensed copies |
| real Cloudflare | real edge staging |

## External-only

real merchant scale, professional pentest (if claimed), PCI/legal, provider contractual response.

## Current result

```text
RELEASE CANDIDATE: BLOCKED
```

**Global blockers:** `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED`; live one-time acceptance; independent pentest + PCI/legal only where the final release is claimed/approved as enterprise production-ready.

**Feature-scoped:** saved-card BLOCKED; recurring EXCLUDED from production-ready claims.


## Local final verification boundary

Repository/CI completion and local owner verification are separate evidence layers. A candidate that changes distributable runtime/UI bytes after the last owner-accepted package must complete the local final-verification runbook and receive a fresh explicit owner acceptance before it can replace the accepted baseline.

See `LOCAL-FINAL-VERIFICATION.md`.

Local verification does **not** authorize a live transaction and cannot resolve the provider production-auth blocker.
