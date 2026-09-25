# R6 Requirement Traceability Matrix

Living map: requirement → exact owner → result → classification.
Base for this pass: `776e0d3b98a54b78c502a792cc01fca7d757d6fe`.

| Requirement | Exact owner | Result | Classification |
|---|---|---|---|
| PHP 8.4 legacy HTTP request-context crash | `tests/integration/checkout-request-context-http.sh` (server restart per matrix) + Compatibility cell `WP 7.1 / WC 11.1.0 / PHP 8.4 / legacy` | targeted 3-pass hosted job + full matrix | PENDING hosted exact-head until R6 Final Evidence + Compatibility Gate green |
| Action Scheduler APIs exist | `tests/integration/ActionSchedulerCompatibilityRuntimeTest.php` | real AS datastore | VERIFIED |
| AS initialization / `ActionSchedulerBridge::is_ready()` | same | asserted after storage selection | VERIFIED |
| Unique single-action + no duplicate parent/cycle/retry | same | exact args pending count == 1 | VERIFIED |
| Different cycle coexistence | same | cycle1+cycle2 open | VERIFIED |
| Exact-args cancellation isolation | same | parent A cancel leaves parent B | VERIFIED |
| In-progress does not suppress other cycle | same | cycle3 schedules while cycle2 open | VERIFIED |
| No bundled duplicate AS library | same | recursive scan of plugin tree | VERIFIED |
| AS cross-version WC 10.8/11.0/11.1 × legacy/HPOS | Compatibility matrix + AS runtime test | one cell per band | PENDING hosted matrix until Compatibility Gate green |
| Orders loaded/request ≤ 50 | `HistoricalEnrollment::BATCH_SIZE` + `tests/performance/r6-large-store-benchmark.php` + `SchedulingCorrectionTest` | hard fail if exceeded | VERIFIED |
| All eligible parents reached | `r6-large-store-benchmark.php` | exact per-eligible ID action proof | PENDING hosted large-store until missing=0 duplicates=0 |
| Synthetic 10k legacy/HPOS | `.github/workflows/r6-large-store-benchmark.yml` | 100/1k/5k/10k × 2 | PENDING hosted large-store workflow until exact eligible proof green |
| Real merchant production throughput | — | not synthetic | EXTERNAL REQUIRED |
| Fresh install | `PluginActivationTest` + Compatibility | activation + defaults | VERIFIED |
| Upgrade SimplixPay/SUCheckout | `UpgradeCompatibilityTest` + Release packaged cells | settings/order/token preservation | VERIFIED |
| CycleClaim v1→v2 / repair | `CycleClaimRuntimeTest` + `cycleclaim-concurrency.sh` | real DB | VERIFIED |
| pre-R4 WP-Cron → AS | `SchedulingEnrollmentRuntimeTest` + migration harnesses | enrollment from historical parents | VERIFIED |
| Activation twice / deactivate / reactivate | `OperationsRuntimeTest` + Compatibility | no data loss | VERIFIED |
| Initial payment failure injection | `provider-payment-lifecycle-harness` (143/0), amount binding, HTTP transport 33/0, CallbackLifecycleRuntimeTest | never false paid/verified | VERIFIED |
| Recurring failure injection | `ecosystem-subscription-lifecycle-matrix-harness` 89/0, R3 safety 12/0, CycleClaim concurrency | ≤1 dispatch, HELD, no replay | VERIFIED |
| Classic/Blocks checkout browser | `tests/e2e/r6-browser-ux.spec.ts` (Playwright) + default theme | screenshots + axe | PENDING hosted Browser/RTL/Axe job |
| Mobile/desktop viewport | same | two viewports | PENDING hosted e2e |
| Console / broken assets / labels | same | assertions in spec | PENDING hosted e2e |
| Arabic/RTL default theme | `tests/e2e/r6-browser-ux.spec.ts` RTL project + locale ar | WordPress ar locale + dir=rtl | PENDING hosted e2e RTL job |
| Local reverse proxy | `tests/integration/lib/reverse-proxy-smoke.sh` | callback URL, no-cache, spoofed headers fail-closed | PENDING hosted Proxy/DAST job |
| Real Cloudflare/CDN | — | edge product | EXTERNAL REQUIRED |
| Automated DAST | `tests/security/r6-dast-smoke.php` | authenticated/unauth probes + pinned gitleaks | PENDING hosted Proxy/DAST + Secret job |
| Professional pentest | — | independent | EXTERNAL REQUIRED |
| Secrets current tree | `tests/security/r6-secret-scan.sh` + CI | no real secrets | PENDING hosted Secret/Supply-chain job |
| Secrets git history / ZIP | same | history + package scan | PENDING hosted gitleaks job |
| Actions pinned by SHA | `tests/security/r6-actions-pin-audit.sh` | all `uses:` pinned | VERIFIED (local + hosted pin audit) |
| CodeQL / dependency audit | permanent workflows | green | VERIFIED |
| HMAC mandatory? | `docs/project/PROVIDER-CLARIFICATION-PACKAGE.md` | contradictory first-party docs | PROVIDER CLARIFICATION REQUIRED |
| Token local persistence allowed? | same | provider says not stored locally | PROVIDER CLARIFICATION REQUIRED |
| Auto-deduct CAPTURED | same | unproven | UNPROVEN |
| Remote cycle identity | same | unproven | UNPROVEN |
| Live recurring / cards / wallets | — | needs real instruments | EXTERNAL REQUIRED |
| WPML/WCML/multicurrency | — | needs licenses | EXTERNAL REQUIRED |
| Commercial themes/builders | — | needs licenses | EXTERNAL REQUIRED |
| PCI / legal | — | professional | EXTERNAL REQUIRED |
| FunnelKit/CartFlows/refunds/multi-split/WCS auto-renew API | product scope | not implemented | NOT SUPPORTED |
| Recurring engineering safety | R3/R4 harnesses | fail-closed HELD | VERIFIED |
| Recurring production acceptance | — | blocked on provider | EXTERNAL REQUIRED |

## Initial payment failure-injection map

| Case | Owner | Outcome required |
|---|---|---|
| DNS/connect failure | HTTP transport harness | fail closed, never paid |
| timeout | HTTP transport harness | fail closed |
| 3xx | HTTP transport harness | no blind follow into paid |
| 400/401/403/429 | provider lifecycle harness | not paid, not verified |
| 500/502/503 | provider lifecycle harness | not paid |
| empty body / malformed JSON | provider lifecycle + StatusVerifier | not verified |
| oversized response | HTTP transport response-cap | fail closed |
| missing transaction / fields | provider lifecycle | not verified |
| track/reference/order/amount/currency mismatch | amount binding + lifecycle | fail closed |
| duplicate browser/webhook + reorder | CallbackLifecycleRuntimeTest + T3 | payment_complete ≤ 1 |
| persistence/lock/status failure | lifecycle + OrderLock harness | never false paid |

## Recurring failure-injection map

| Case | Owner | Outcome required |
|---|---|---|
| missing customer/card token | R3 safety harness | no dispatch |
| card membership/change | R3 characterization matrix | fail closed |
| parent status/product/economics change | R3 matrix | fail closed |
| claim loser / stale reclaim / old owner | CycleClaim concurrency | ≤1 dispatch |
| mark_dispatching / transport WP_Error / timeout after dispatch | lifecycle matrix | HELD / ambiguous preserved |
| HTTP 4xx/429/5xx / empty / malformed / truthy non-authoritative | AutoDeductResultVerifier + matrix | never VERIFIED_SUCCESS |
| HELD / duplicate worker / AS duplicate delivery | CycleClaim + AS test | no blind replay |
| retry / retry exhaustion / repair feeder | SchedulingCorrectionTest + R4 | bounded attempts |
| journal resolve failure / parent persistence anomaly | CycleClaim runtime | fail closed |
