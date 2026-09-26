# Release Candidate Gate

An RC may be produced only when **scope-aware** mandatory blockers are closed.

## Mandatory for core one-time payment RC

- [x] current secure upstream WP/WC patches certified (live lookup CURRENT)
- [ ] all permanent CI green on final head
- [ ] accepted runtime/package provenance understood
- [ ] production auth contract resolved for provider API traffic (**HMAC — BLOCKER**)
- [ ] live one-time payment acceptance complete
- [ ] public-claims audit complete
- [x] no misleading recurring claim in current copy
- [x] secret scan clean
- [x] dependency/security audit clean
- [ ] independent pentest complete **if claiming enterprise production readiness**
- [ ] PCI/legal scope reviewed externally **if owner release policy requires**
- [ ] release scope explicitly locked

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

**Global blockers:** HMAC/auth production contract; live one-time acceptance; (enterprise) pentest + PCI/legal per policy.

**Feature-scoped:** saved-card BLOCKED; recurring EXCLUDED from production-ready claims.
