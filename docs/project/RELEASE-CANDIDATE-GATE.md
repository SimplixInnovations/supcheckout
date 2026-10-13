# Release Candidate Gate

An RC may be produced only when **scope-aware** mandatory blockers are closed.

## Mandatory for core one-time payment RC

- [x] current secure upstream WP/WC patches certified (live lookup CURRENT)
- [x] all permanent CI green on certified repository head
- [x] accepted runtime/package provenance understood
- [x] production auth contract resolved for provider API traffic — core Charge + track-ID Status: `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` by the owner-run account probe in `PRODUCTION-AUTH-ACCOUNT-PROBE.md` on 2026-10-13 ([evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md)), scoped to that merchant account and to packages containing the PR #138 non-white-label Charge fix; re-run before each release
- [ ] owner technical acceptance of a package that contains PR #138 (Release Candidate 1, and every package before PR #138, sends a non-white-label Charge that this production account rejects (HTTP 422, `paymentGateway.src` missing), so it must not be released.)
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

**Global blockers:** owner technical acceptance of a package containing PR #138; live one-time acceptance; independent pentest + PCI/legal only where the final release is claimed/approved as enterprise production-ready.

**Feature-scoped:** saved-card BLOCKED; recurring EXCLUDED from production-ready claims.


## Local final verification boundary

Repository/CI completion and local owner verification are separate evidence layers. A candidate that changes distributable runtime/UI bytes after the last owner-accepted package must complete the local final-verification runbook and receive a fresh explicit owner acceptance before it can replace the accepted baseline.

See `LOCAL-FINAL-VERIFICATION.md`.

Local verification does **not** authorize a live transaction and cannot resolve the provider production-auth blocker.
