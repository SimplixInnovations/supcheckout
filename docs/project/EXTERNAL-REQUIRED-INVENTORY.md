# External Required Inventory (with closure path)

Every `EXTERNAL REQUIRED` must have missing prerequisite, owner, runbook, closure evidence, blocked claim.

| Item | Missing prerequisite | Owner | Runbook | Closes when | Blocks |
|---|---|---|---|---|---|
| Live one-time payment | `OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES` + staging HTTPS | owner | `LIVE-PAYMENT-ACCEPTANCE-PLAN.md` | scenario matrix green | production payment claims |
| Saved-card live | account tokenization + owner token | owner | plan §10 | token flow evidence | saved-card production claims |
| Recurring live | 4 provider contracts + runtime tranche + `OWNER_RECURRING_LIVE_TEST_AUTHORIZATION=YES` | owner+provider | `RECURRING-LIVE-CERTIFICATION-PLAN.md` | renewal matrix green | recurring production claims |
| Provider contact | `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES` | owner | `UPAYMENTS-PROVIDER-CONTACT-DRAFT.md` | written answers | HMAC/token/capture classification |
| Independent pentest | third-party firm + staging | owner | `PENTEST-HANDOFF.md` | findings report | enterprise-security claims |
| PCI/QSA | acquirer/QSA | owner | `PCI-SCOPE-QUESTIONS.md` | written scope opinion | PCI claims |
| Legal/privacy | counsel | owner | `PRIVACY-SECURITY-QUESTIONS.md` | written opinion | privacy claims |
| Real Cloudflare edge | staging behind real edge | owner | `EDGE-CACHE-CERTIFICATION.md` | edge test green | Cloudflare claims |
| WPML/WCML | legal licenses + staging | owner | `I18N-MULTICURRENCY-CERTIFICATION.md` | i18n matrix green | WPML claims |
| Multicurrency plugin | legal license + staging | owner | same | currency matrix green | multicurrency claims |
| Commercial themes/builders | legal licenses | owner | theme qualification | theme matrix green | theme support claims |
| Wallets devices | device/account/country | owner | `WALLET-CERTIFICATION-MATRIX.md` | wallet matrix green | wallet claims |
| Production performance | representative merchant staging | owner | `PRODUCTION-PERFORMANCE-QUALIFICATION.md` | delta report | production throughput claims |
