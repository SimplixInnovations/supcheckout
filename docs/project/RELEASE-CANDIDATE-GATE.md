# Release Candidate Gate

An RC may be produced only when mandatory blockers are closed.

## Mandatory

- [ ] current secure upstream WP/WC patches certified
- [ ] all permanent CI green
- [ ] accepted runtime/package provenance understood
- [ ] provider auth contract resolved for production egress
- [ ] live one-time payment acceptance complete
- [ ] public claim audit complete
- [ ] no misleading recurring claim
- [ ] secret scan clean
- [ ] dependency/security audit clean
- [ ] independent pentest complete for enterprise-production claim
- [ ] PCI/legal scope reviewed externally
- [ ] release scope explicitly locked

## Non-blocking (must stay excluded from claims)

- wallets without device/account
- WPML/WCML without license
- commercial themes without license
- real Cloudflare edge
- production-scale performance

## Current gate result

```text
RELEASE CANDIDATE: BLOCKED
```

Blocked by: provider auth/capture/cycle/token contracts, live one-time acceptance, independent pentest, PCI/legal, real edge, wallet/i18n licensed environments.
