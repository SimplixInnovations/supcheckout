# Wallet / Device Certification Matrix

| Wallet | Prerequisites | Status | Closure path |
|---|---|---|---|
| Apple Pay | provider feature, eligible account/country, real device/browser, wallet configured, domain/cert if required | EXTERNAL REQUIRED | owner provides device+account+staging |
| Google Pay | provider feature, eligible account, real device/browser, wallet configured | EXTERNAL REQUIRED | owner provides device+account+staging |
| Samsung Pay | provider feature, eligible account, real device/browser | EXTERNAL REQUIRED | owner provides device+account+staging |
| KNET | provider account supports KNET; eligible country | EXTERNAL REQUIRED | owner provides test merchant + sandbox/live path |
| Credit Card | provider sandbox/live card flow | EXTERNAL REQUIRED (live) / SANDBOX OBSERVATION only | owner live-payment token or sandbox evidence |

## Per-wallet verification (when environment exists)

- availability / gateway visibility
- checkout selection
- provider redirect/whitelabel flow
- success / cancel / failure
- callback/status reconciliation
- responsive UI
- no false payment state

Do not infer wallet support from provider documentation alone.

**Public claims:** do not advertise Apple Pay / Google Pay / Samsung Pay as verified until executed.
