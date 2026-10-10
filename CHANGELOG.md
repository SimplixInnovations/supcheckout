# Changelog

All notable **SUPCheckout for UPayments** product changes are documented here.

The project is still in pre-release engineering hardening. Entries below are engineering milestones and do not imply a merchant-facing stable release. The independent Simplix development line is `0.x`; `1.0.0` remains reserved for the first release that satisfies the stable-release gates.

## [Unreleased]

### Payment lifecycle hardening (post Release Candidate 1, not owner accepted)

- A capture on an earlier Charge attempt of the same order is no longer dropped. Checkout keeps a bounded history (5) of superseded provider order identities. An authenticated, bound CAPTURED result for one of them completes a still-unpaid order. If the order is already paid, or was switched to another gateway, SUPCheckout records the payment once with an order note for manual refund. Non-captured results for those attempts never change the order.
- A later CAPTURED track inside the same attempt now overrides an earlier bound non-captured track instead of being refused.
- A verified CAPTURED that WooCommerce fails to record is now retried through bounded reconciliation and announced once in an order note.
- Callback-triggered status lookups are capped at 3 per order per minute and may use at most 24 of the 30 global status slots. Six stay reserved for reconciliation, so one visitor replaying callbacks can no longer starve payment confirmation for the whole store.
- Authenticated results now reach the historical public status poll (`UPayments_WHS`: completed, failed, cancelled); it previously always answered `wait`.
- The package changes: 62 files, SHA-256 `6632cc8bf79d816de137e94480d61f0bdfa4478d841b8bcc6a1faab96fe88d28` at the candidate head. A fresh owner acceptance is required before it can replace Release Candidate 1.

### Final enterprise repository audit and presentation closeout

- Rebuilt the public repository landing page around product capabilities, compatibility, security, development and support instead of migration-era notices and internal-program narrative.
- Simplified WordPress.org, support, contribution, maintainer and provenance copy while retaining clear external-service, privacy, trademark and merchant-safety boundaries.
- Removed eight unreferenced repository-only legacy screenshots; runtime wallet/payment assets remain because checkout resolves them directly or dynamically.
- Reconciled living project-control documents and permanent quality contracts so current truth is owned by the designated authority documents rather than duplicated into the public README.
- No production payment runtime, provider contract, persisted identity, package basename, development version, public tag, GitHub Release or WordPress.org publication is changed by this audit.

### Repository-control hardening

- Upgraded release artifact upload/download actions to current Node-24 generations, added stable `Compatibility Gate` and `Release Gate` aggregate contexts for branch-protection use, and reconciled the owner ruleset handoff. No plugin runtime/provider/persisted identity changed.


### Final pre-clone repository closure

- PR #75 exact head `9474955e2d5438ccc9c0334b52dc0f72be557a86` removed proven dead/debug runtime residue, consolidated the provider logo, corrected living first-party QA/control naming, removed the unreferenced legacy order-details template, and preserved dynamically referenced wallet assets.
- Protected UPayments/provider/persisted identities, frozen lifecycle/migration keys, both historical package roots, first-stable `UPayments.php` bootstrap and development version `0.1.0` were not migrated.
- PR #75 passed Quality #957, Compatibility #485 (**16/16**), Release Artifact #433, Provider Sandbox #385, strict WordPress.org #288 and CodeQL, then squash-merged as GitHub-verified `1354b8e6f801a847a5fa9b5b657e77647384bdbc`.
- Fresh merged-main Quality #958, Compatibility #486 (**16/16**), Release Artifact #434, Provider Sandbox #386, strict WordPress.org #289 and CodeQL/main-security #780 all succeeded.
- Deterministic `supcheckout-0.1.0.zip`: SHA-256 `e85ef026cf43f2254625c2fd1c97e328dc37581dfea4f058c59c0434b11a360d`, 56 files.
- No public tag, GitHub Release, WordPress.org publication or version promotion is introduced by this closeout.


### Canonical repository-coordinate closure

- Post-rename coordinate PR #68 exact head `0e6ef6334282a83a428da7ee793daa98360c2bcc` passed the full Quality/H12, 16-cell Compatibility, deterministic Release Artifact/migration, Provider Sandbox, packaged WordPress.org and CodeQL stack.
- PR #68 squash-merged as `05fec942cc8fbeb58cfd0bd41f0ef5fdb86f966f`; fresh post-merge Quality #901, Compatibility #429 (**16/16**), Release Artifact #378, Provider Sandbox #339, WordPress.org #236 and CodeQL/main-security #723 all succeeded.
- All living repository URLs/release identity now use `SimplixInnovations/supcheckout`; historical evidence and protected UPayments persisted/provider identities remain intact.
- At PR #68 closeout, before documentation-only PR #69 opened, remote branch topology was `main` only with no open PRs/issues; public tags and GitHub Releases remain absent.

### Final SUPCheckout identity and repository transition

- Final provider-specific identity migration PR #67 certified exact head `0059f365883fa4edd6a2d623c7b370d38d3f565c` and squash-merged as `7547e59a2d5ef6d49b059851c6899a2d9987b16a`.
- Fresh merged-main Quality #896, Compatibility #424 (**16/16**), Release Artifact #373, Provider Sandbox #334, WordPress.org #231 and CodeQL/main-security #717 all succeeded.
- Canonical first-party repository identity is now `SimplixInnovations/supcheckout`; plugin slug/text domain/package root remain `supcheckout`, while protected UPayments payment and persisted compatibility identities remain unchanged.
- No public tag, GitHub Release or WordPress.org publication is introduced by this transition.


### Final SUCheckout first-party naming and documentation closeout

- PR #61 completed the remaining retired pre-rebrand first-party constants/certification-control prefix migration while preserving protected UPayments compatibility identities; current runtime-bearing certified `main` is `efe937c67343242b7ccf3396a67b3cf2ce35ebac`.
- Fresh post-merge Quality #781, Compatibility #309 (**16/16**), Release Artifact #258, Provider Sandbox #221, WordPress.org Submission Check #116 and CodeQL/main-security #595 all succeeded.
- Living README/status/compatibility/release/owner docs now distinguish historical identity milestones from the latest runtime-bearing baseline and provide one ordered docs merge → branch cleanup → repository rename → coordinate reconciliation → local acceptance → release-decision path.
- WordPress.org `readme.txt` is expanded to document provider independence, external-service/data boundaries, exact certified platform scope, unsupported features, privacy/support boundaries and safe installation expectations without broadening runtime claims.
- No protected persisted/provider identifier, public tag, GitHub Release or WordPress.org publication is introduced by this documentation closeout.


### SUCheckout identity migration — DONE / VERIFIED / PRE-RELEASE

- PR #58 final certified head `5bf84dccb880733da45c1f922d43554af69a33dc` passed Quality #763/H12, Compatibility #291 (**16/16**), Release Artifact #242, Provider Sandbox #206, WordPress.org Submission Check #100 and CodeQL.
- PR #58 squash-merged as `6aabc4fcb0606567a11637ea07fe081fed4c7f85`; post-merge `main` passed Quality #764, Compatibility #292 (**16/16**), Release Artifact #243, Provider Sandbox #207, WordPress.org Submission Check #101 and CodeQL/main-security #579.
- Official packaged Plugin Check closed with **0 blocking errors**; non-blocking warnings remain classified rather than blanket-suppressed.
- Direct gateway/status/subscription cURL transport was replaced with the WordPress HTTP API under a permanent **27/27** production transport regression.
- A whole-PR review found and fixed a fail-closed provenance DB ambiguity; the permanent regression closes at **3/3**.
- Repository residue contract closes at **17/17**, while protected provider/persisted compatibility identifiers and historical evidence remain intentionally preserved.

- Retires the pre-release SimplixPay first-party identity in favor of **SUCheckout for UPayments** and canonical technical slug `sucheckout-upayments`.
- Preserves evidence-backed UPayments provider and historical WooCommerce compatibility identifiers while first-party package, namespace, metadata, i18n, frontend and release surfaces migrate under regression gates.
- No public tag, GitHub Release, WordPress.org publication or repository rename is authorized by this migration work.


### Enterprise Release Candidate Closeout — DONE / VERIFIED

- PR #54 final reviewed head `5a24944617f7ee482c381e5e899f687b77d81d09` passed Quality #552/H12, Compatibility #80 (**16/16**), Release Artifact #34 including packaged legacy/HPOS plus current/floor upgrade cells, Provider Sandbox #12, locked dependency audit and CodeQL.
- The reserved final whole-plugin Codex review found one valid P2: stale-current-gate Governance guards omitted Q4 and Q15-Q19. The defect was independently reproduced and fixed on the final head, and Q19 now permanently asserts the complete Q1-Q19 guard set.
- PR #54 squash-merged exactly as `2ddb1790fead37c6055256847dc7c827e165af4a`.
- Canonical `main` then passed Quality #553, Compatibility #81 (**16/16**), Release Artifact #35, Provider Sandbox #13 and CodeQL/main-security #358 with no non-success checks.
- Task 8 establishes an enterprise-qualified release-candidate engineering state. It does not publish 1.0, create a public GitHub Release or publish to WordPress.org.
- Release-claim hardening narrows the merchant-facing WooCommerce gateway description to the certified boundary: wallet/payment-method availability depends on the merchant's UPayments account/provider configuration, and subscription auto-deduction requires separately validated provider setup.

### Enterprise Compatibility Certification — core runtime foundation — DONE / VERIFIED

- Added a permanent real WordPress/WooCommerce/MySQL runtime matrix covering 16 WordPress/WooCommerce/PHP × legacy/HPOS cells.
- Real RED head `23cc9edfa3a905730fbb3924318f09a06803e750` reproduced an activation fatal when `woocommerce_upayments_settings` was object-valued; minimal fix `e912819ad30c3be980c18fe104a1961f306a572a` added only the required `is_array()` guard.
- Strengthened activation certification to compare the complete serialized protected settings option byte-for-byte and prove the activation callback executed.
- Verified Classic gateway registration, standard Blocks registration/availability and real WooCommerce order CRUD under both legacy and HPOS authoritative storage.
- PR #47 final head `d46abc86f329a2b0ae24e79c18c371db2083a43a` passed Quality Gates #490, Compatibility Certification #18, CodeQL and all 16 runtime cells.
- Squash-merged as `5e4f33d24bcaed1032691c564b570e60c95a9483`; post-merge Quality Gates #491, Compatibility Certification #19, all 16 cells and CodeQL passed; branch auto-deleted.

### Enterprise Compatibility Certification — support metadata and HPOS declaration

- RED-A added real-runtime support-header assertions while leaving production metadata unchanged; Compatibility Certification #20 failed exactly because the prior WordPress minimum remained 5.6.
- GREEN-A derives public metadata only from the verified matrix: WordPress 6.9 minimum / 7.1 tested, WooCommerce 10.8 minimum / 11.1 tested, PHP 7.4 minimum. Exact candidate `b247965c2ff7e98c00b394b2672b5ef2ba14fba6` passed Quality Gates #495 and Compatibility Certification #23 across all 16 cells.
- RED-B then inspected WooCommerce's real feature registry and failed exactly because `custom_order_tables` was not declared compatible.
- GREEN-B adds only the guarded HPOS `custom_order_tables` declaration beside the already-existing `cart_checkout_blocks` declaration. The exact `UPayments.php` ratchet advances to 89,102 bytes for this declaration.
- Broader provider, feature, multilingual, browser/accessibility/performance and release certification remains pending.

### Architecture A3 — Gateway Settings/Admin Presentation — DONE / VERIFIED

- Extracted the complete characterized settings schema, validation, one-row allocation renderer and admin assets to `src/Admin/GatewaySettings.php` behind all legacy public gateway wrappers.
- Strengthened the permanent Gateway Settings gate with a frozen complete 21-field schema fixture and exact asset-registration tuples; final result **90 PASS / 0 FAIL**.
- Exact final PR #23 head `85028cfb4431cc29820eaca4e254bf6c87daa378` passed Quality Gates #158 and clean independent review.
- Squash-merged PR #23 as signed commit `6291196b35a952ea974549d1aa6d6ae9bbcc64dc`, tree `a7f66ee6cf8c9d5324a0ae77b8c61e69e87bdff7`; post-merge run #159 passed and the implementation branch was deleted.

### Architecture A4 — Subscription Presentation Boundary — DONE / VERIFIED

- Added `Simplix\Pay\UPayments\Subscription\Composition` and `Presentation` for the characterized product/admin/My Account hook and rendering surface while retaining all named global and public gateway compatibility wrappers.
- Kept the customer mutation handler, scheduler, cycle-claim journal, billing table, Charge/auto-deduct dispatch and protected metadata outside the presentation module.
- Added the mandatory Architecture Subscription Presentation regression gate, strengthened it to **75 PASS / 0 FAIL**, and reduced the exact `UPayments.php` ratchet to **205,702 bytes**.
- Exact final PR #24 head `2a2c6a4c67775b6614297d2c0150f3ca61220498` passed Quality Gates #164 and clean independent review.
- Squash-merged PR #24 as signed commit `d24b83356cc766f82c3ad9e529d3ec3f4194e887`, tree `f74899b93f493be872e0ce993e30079d0223dc7b`; post-merge run #165 passed and the implementation branch was deleted.

### Architecture A5 — Checkout Payload/Orchestration Core — DONE / VERIFIED

- Added pure `Simplix\Pay\UPayments\Payment\CheckoutPayload` and bounded `CheckoutOrchestrator` services behind the public legacy `process_payment()` compatibility entry point.
- Preserved protected request-body and provider-transport override seams via gateway-scoped closures, along with strict decimal/payload, H12 saved-card, single Charge dispatch, redirect and metadata behavior.
- Added the mandatory Architecture Checkout Orchestration gate and reduced the exact `UPayments.php` ratchet to **88,839 bytes**.
- Exact final PR #25 head `997e18d8eb6264a84c6a9a35158213d3d655e6b3` passed Quality Gates #173 and clean independent review.
- Squash-merged PR #25 as signed commit `3223a882867634a2ba7588d7afbd2b2e4b4c21e4`, tree `392b73425fa3219b6414a0984136b92c8ef77576`; post-merge run #174 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q1 Foundation

- Added a canonical development-only Composer manifest and committed lockfile with plugin execution disabled and no production package dependencies.
- Added PHPUnit pure-service tests, baseline-free PHPStan scope, risk-focused PHPCS/WPCS checks, locked dependency auditing and declared PHP-floor syntax CI for distributed PHP; development-only tests remain on the PHP 8.2 regression runtime.
- Made the protected H12 check an always-running aggregator that explicitly rejects failed or skipped Composer-quality and syntax prerequisites.
- Added the permanent Quality Platform Foundation regression gate while retaining every historical and architecture harness.
- Exact final PR #26 head `936e4630c83f7a92cbc4c77f061626e2b0c0c800` passed Quality Gates #177 and clean independent review.
- Squash-merged PR #26 as commit `9b3ead774a5a9bc2ac0f3b3ad754b2d99053f362`, tree `473543cd08515eedd764a4b1ef7b6581590d13a1`; post-merge run #178 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q2 Checkout Payload Analysis — DONE / VERIFIED

- Expanded PHPUnit boundary characterization and baseline-free PHPStan level 5 scope into `CheckoutPayload` without changing observable payment behavior or tool versions.
- Final exact-head evidence: PHPUnit **21 tests / 126 assertions**, Q1 **74/0**, Q2 **64/0**, PHPStan/PHPCS/audit clean and every historical/architecture/H12 regression green.
- Exact final PR #28 head `c2c30f90688747a523301cb776ed920ef39063f3` passed Quality Gates #182 and clean independent re-review.
- Squash-merged PR #28 as `356680b9fe8a2724e778d40386ca182247715249`, tree `3550fdbb0810af26808851e24e39a6130725e8db`; post-merge run #183 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q3 Payment Concurrency Analysis — DONE / VERIFIED

- Expanded baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `StatusRateGate` and `OrderLock`.
- Froze exact 30-per-minute credential/mode-scoped option slots plus exact-record compare-and-swap order-lock takeover/release without changing executable runtime behavior.
- Exact final PR #29 head `e08be468b5453524996c525860c12d5619081132` passed Quality Gates #188 and clean independent exact-head review; Q1 was **74/0**, Q2 **64/0**, Q3 **69/0** and PHPUnit **31 tests / 220 assertions**.
- Squash-merged PR #29 as `30e99a6a456b72709c87e442b8437301ba64e99b`, tree `703a56c03e95862b8b4807d9a1ea28e2e3e201dd`; post-merge run #189 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q4 Authenticated Status Analysis — DONE / VERIFIED

- Expanded baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `StatusVerifier`.
- Froze exact UPayments status destination validation before rate/Bearer use, hardened no-redirect/TLS HTTP handling and exact authenticated order/transaction binding.
- Exact final PR #30 head `8543bdfce1a4e216200791dc5637b646f49bcb59` passed Quality Gates #194 and clean independent exact-head review; Q4 was **68/0** and PHPUnit **39 tests / 327 assertions**.
- Squash-merged PR #30 as `4b3db92b0ded0c598bad0ab677babab9e6102811`, tree `ad5ae98d5e935bb48d1441f94e130f5d3adb3ca9`; post-merge run #195 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q5 Payment-Method Availability Analysis — DONE / VERIFIED

- Expanded baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `PaymentMethodAvailability`.
- Froze exact credential/mode/site cache and advisory-lock identities, the durable 65-second gate, strict schema-3 caches, provider failure sentinel and bounded payment-button normalization.
- Exact final PR #31 head `d4132b0caccaa6edc6d7421afcfd8e9694563224` passed Quality Gates #197 and clean independent exact-head review; Q5 was **83/0** and PHPUnit **47 tests / 444 assertions**.
- Squash-merged PR #31 as `984053aee6bb50e62e457a639f44307e461f5e38`, tree `dee657b03f8d44670b0ae2501a40dabf718d4bb2`; post-merge run #198 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q6 Gateway Settings Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `GatewaySettings`.
- Freezes the exact 21-field schema, subscription/save-card dependency, API/allocation validation, five-field non-secret sanitation, escaped single-allocation renderer and exact admin asset scopes.
- Adds development-only WordPress/WooCommerce admin fixtures and a permanent Q6 regression harness without changing payment truth, provider transport, scheduler state or protected compatibility identities.
- Exact final PR #32 head `85de7a009205e6bb810fad8ab8a0634ca91d1fa8` passed Quality Gates #201 and clean independent exact-head review; Q6 was **83/0** and PHPUnit **55 tests / 498 assertions**.
- Squash-merged PR #32 as `651e604659d1891e0f7d05b8e684edb4aa31c2b1`, tree `07f944a3adbbdbf6953ea96512555cb6b16286fe`; post-merge run #202 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q7 Public Order Status Analysis

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `PublicOrderStatus`.
- Freezes GET-only parsing, UPayments-order enforcement, exact owner/order-key authority, generic unavailable errors and the minimal allowlisted public response.
- Adds development-only WordPress/WooCommerce order/authentication/JSON fixtures and a permanent Q7 regression harness without changing payment truth, provider transport, callbacks, order mutation or protected compatibility identities.
- Exact final PR #33 head `48de59414c952d6f90ce90c4f462dde67fcbdabc` passed Quality Gates #212 and clean independent exact-head review; Q7 was **69/0** and PHPUnit **63 tests / 588 assertions**.
- Squash-merged PR #33 as `e00a80147d4f6267d137e1bdfa0b2d1211e00f6a`, tree `6ef43632a4868a1114b5468a38ad45138e41c393`; post-merge run #213 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q8 Release Identity Analysis

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `Release\Identity`.
- Freezes the public product/version/repository boundary, disabled update channel, historical installed identities and distinct future migration targets.
- Adds a permanent Q8 regression harness without activating the updater, renaming persisted identities or changing payment/bootstrap behavior.
- Exact final PR #34 head `458bf35b0cc60d78dc8f32d28605d1f60cbc501c` passed Quality Gates #218 and clean independent exact-head review; Q8 was **46/0** and PHPUnit **69 tests / 604 assertions**.
- Squash-merged PR #34 as `b59eb2d50b86a38d8ea130de63c38a672db86d32`, tree `109415fa6a4bc04bba60bb23275bc192dd232559`; post-merge run #219 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q9 Migration Settings Analysis

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `MigrationSettings`.
- Freezes the sole historical Woo gateway-option source, fail-closed credential/mode parsing, exact in-memory credential preservation and secret-free reporting redaction.
- Adds a permanent Q9 regression harness without adding credential input/storage or changing Phase 9I execution and payment behavior.
- Exact final PR #35 head `01ca31ec3bf55f60dbec5f8293c73ab5bfbdc9a5` passed Quality Gates #223 and clean independent exact-head review; Q9 was **62/0** and PHPUnit **76 tests / 663 assertions**.
- Squash-merged PR #35 as `f63591188e232505f8307cb71fdbe4c32d2dc4c7`, tree `96936981b8d3088a65c1d0917b7e5773952bc346`; post-merge run #224 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q10 Migration Bootstrap Analysis

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `MigrationBootstrap`.
- Freezes frontend inertness, exact admin/CLI contexts, bounded migration dependencies and canonical menu/command registrations.
- Adds a permanent Q10 regression harness without executing migration, adding operational contexts or changing payment behavior.
- Exact final PR #36 head `41b0d6d03af91b1e811562d609cf809345a221df` passed Quality Gates #226 and clean independent exact-head review; Q10 was **67/0** and PHPUnit **82 tests / 686 assertions**.
- Squash-merged PR #36 as `02a1ad24d262c3cb6d14653bf48aa31c3796ae4e`, tree `eae2fe0d0f0f54bef793ed6e58c9837bd01403ab`; post-merge run #227 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q19 Subscription Product Eligibility Consistency — DONE / VERIFIED

- Q18 is **DONE / VERIFIED** through PR #44, squash merge `fe572d2bed5a7250ea98e5b5935c19f1cc6b3246`, post-merge Quality Gates #442 and main CodeQL #238.
- Q19 removes arbitrary product-ID restriction semantics and enforces only exact `_upay_disable_subscription = yes` across Classic/Store API before provider transport.
- Q19 also preserves exact unslashed Classic subscription plan/interval tokens before strict parsing and rejects valid opted-out subscription plans before any cold-cache payment-availability/provider transport.
- Final PR #45 head `1717f0c25da7140a7799c7db3a7f016abecec7e9`, tree `8230778e3313e4d201de48b1a5cf170c42f7178d`, passed Quality Gates #463, PHPUnit **174/1063**, Q19 **22/0**, H12 PHP **1927/0**, H12 Blocks **144/0** and exact-head CodeQL with no new alerts.
- Squash merge `29ba16a1eabc00e25c3652ae838be9b9539b3a10` passed post-merge Quality Gates #464 and all post-merge CodeQL lanes; the implementation branch was deleted.
- Q19 closes the numbered Quality Platform. No Q20 is justified by current evidence; further work is owned by named certification, product-readiness and release-engineering programs.

### Full Automated Quality Platform — Q18 Blocks Availability Enforcement — DONE / VERIFIED

- Q17 is **DONE / VERIFIED** through PR #43, squash merge `570dbf3501b359b16767d070d18c25a67a0c24fe`, post-merge Quality Gates #415 and main security #195.
- Q18 enforces the canonical Woo gateway enabled state at the Blocks server activation boundary while preserving the declared fresh-install default `enabled=yes` for a missing key and failing closed for malformed storage or malformed explicit values.
- Q18 adds permanent Blocks availability regression coverage, baseline-free analyzer ownership and WooCommerce logger usage without claiming broad Blocks/platform certification.
- Q19 subsequently closed the evidence-backed subscription product-eligibility gate through PR #45. The numbered sequence is now closed at Q19; further work moves to named certification, product-readiness and release-engineering programs unless new enterprise-critical evidence independently demonstrates a distinct bounded defect.

### Full Automated Quality Platform — Q17 Payment Runtime Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into `CheckoutOrchestrator.php` and `PaymentLifecycle.php`.
- Adds process-isolated deterministic PHPUnit characterization for checkout/reconciliation order-ID boundaries, callback merge behavior and provider-bound payment-runtime inputs.
- Hardens canonical Woo order IDs, terminal-newline currency/IBAN boundaries and same-second Charge-attempt identity while preserving the provider-facing 32-lowercase-hex order-ID shape.
- Preserves authenticated status binding, lock/rebind TOCTOU protection, attempt-scoped cursor rotation, Woo `payment_complete()` semantics, no-resurrection rules and bounded reconciliation; legacy CAPTURED/payment metadata is now staged only after Woo paid-state + transaction-ID postconditions succeed.
- Q17 closed the bounded payment-runtime tranche through PR #43 and verified merge `570dbf3501b359b16767d070d18c25a67a0c24fe`; Q18 subsequently closed through PR #44 and verified merge `fe572d2bed5a7250ea98e5b5935c19f1cc6b3246`.

### Full Automated Quality Platform — Q16 Migration Core Analysis — DONE / VERIFIED

- Expanded baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into `MigrationPreflight`, `MigrationBatch` and `MigrationExecutor`.
- Added deterministic migration-core PHPUnit characterization while preserving every original Phase 9I preflight/executor/operations regression.
- Hardened full-string numeric/generation/digest/reason parsing, preserved bounded scans/checkpoints and kept SQL preparation explicit without broadening migration eligibility.
- Final reviewed PR #42 head `3cff2fcc64053d79be7427696c86039f1b52bbfd`, tree `b9cc6eafb3c7f8df36b9c5db8b2e45bb330688d2`, passed Quality Gates #315 with PHPUnit **160 tests / 987 assertions**, Q16 **120/0**, H12 PHP **1927/0**, H12 Blocks **144/0** and CodeQL #83; exact-head independent review was clean.
- Squash-merged as `06a9ebd732c7cc3f062d4bb361aaef4054a1dfa3`; post-merge Quality Gates #316 and main security run #84 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q15 Subscription Presentation Analysis — DONE / VERIFIED

- Expanded baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into the Architecture A4 subscription-presentation boundary.
- Added deterministic product/admin/cart/My Account characterization and malformed-input/output hardening without running scheduler, billing, provider or payment behavior.
- Exact final PR #41 head `01a06d45fcc0bc3d08da8d58f6be177b232bb1d4`, tree `ea5b0b3880a99999577d51a9ed5f6a8c77a52cf0`, passed Quality Gates #253 with PHPUnit **144 tests / 899 assertions** and Q15 **107/0**.
- Squash-merged as `a4bbb05021dbded73072c0ba108a18245b60ad88`; post-merge Quality Gates #254 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q14 Migration Admin Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into the existing privileged Phase 9I migration-admin adapter.
- Adds PHPUnit characterization for exact submenu registration, capability-before-request authorization, POST nonce verification, credential-free bounded form input, explicit execute confirmation, resume/offset exclusion and escaped/redacted output.
- Adds development-only WordPress admin fixtures/stubs and a permanent Q14 harness without executing migration/provider behavior or changing valid request behavior.
- Rejects terminal-newline offset/limit values with an absolute regex end anchor, replaces lossy control-token normalization with a byte-preserving request-method allowlist plus a raw-unslashed action allowlist, and removes only analyzer-proven unreachable missing-reason fallbacks.
- Exact final PR #40 head `b2d8630a5903af8f26a7f770a2a80547c871f7c6` passed Quality Gates #247 and clean independent exact-head review; Q14 was **109/0** and PHPUnit **129 tests / 825 assertions**.
- Squash-merged PR #40 as `22857f6304d4b4f19ec1cb6303a80d120173bcd1`, tree `53107c93c8756985461a8d75e2009c91b89ee851`; post-merge run #248 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q13 Migration CLI Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into the existing Phase 9I WP-CLI adapter.
- Adds PHPUnit characterization for strict requests, explicit execute confirmation, resume/offset exclusion, bounded integers, redacted JSON and exact CLI errors.
- Extends development-only WP-CLI fixtures/stubs and adds a permanent Q13 harness without executing migration/provider behavior or changing observable runtime behavior.
- Removes one PHPStan-proven unreachable missing-reason fallback; every `MigrationBatch::resumeOffset()` result already carries the exact reason returned to the CLI.
- Makes the permanent Q11/Q12 playbook checks closure-aware so later verified milestones cannot erase their immutable merge/tree evidence.
- Rejects terminal-newline offset/limit values with an absolute regex end anchor so canonical integer parsing is exact.
- Exact final PR #39 head `302dcdf9c1bbd3a1d259790e8f9f9c2d694b74d7` passed Quality Gates #236 and clean independent exact-head review; Q13 was **77/0** and PHPUnit **105 tests / 766 assertions**.
- Squash-merged PR #39 as `a744417e1ec2f40b4f59706df84589d8b18638cb`, tree `be7c52143d2085550790b742d164ecbec413377f`; post-merge run #237 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q12 Subscription Product Type Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan level 5/PHP 7.2 and risk-focused PHPCS ownership into the guarded global `WCProductCustomType` compatibility shim.
- Adds isolated-process PHPUnit characterization for absent base, available base and predeclared-child load states, plus the exact `WC_Product_Simple` parent and historical `custom_type` result.
- Adds a development-only analyzer base-class stub and permanent Q12 regression harness without changing production source behavior or entering hook, scheduler, cycle-claim, billing, dispatch, mutation or provider-transport ownership.
- Exact final PR #38 head `4396b83ef67a90d6d12d1d761e6c071e601c235c` passed Quality Gates #231 and clean independent exact-head review; Q12 was **63/0** and PHPUnit **90 tests / 714 assertions**.
- Squash-merged PR #38 as `6dc53bdaf60f12774d7516294d7004974be3874f`, tree `b8a9f956e304fa9dba7658809207ddae14b1f4e1`; post-merge run #232 passed and the implementation branch was deleted.

### Full Automated Quality Platform — Q11 Subscription Composition Analysis — DONE / VERIFIED

- Expands baseline-free PHPStan/PHPCS and deterministic PHPUnit characterization into `Subscription\Composition`.
- Freezes the exact ordered presentation/gateway hook topology and legacy checkout/storage module initialization.
- Adds a permanent Q11 regression harness while preserving exact Scheduler/CycleClaim blobs and excluding billing, dispatch, mutation and provider-transport ownership.
- Exact final PR #37 head `2a03537723ec937e58337dfa3432500c2ce85728` passed Quality Gates #229 and clean independent exact-head review; Q11 was **84/0** and PHPUnit **87 tests / 708 assertions**.
- Squash-merged PR #37 as `e544a65130d4b009efea179038dd03275cd46897`, tree `f27880f5f2a93f1dfd6428619e5bffa75e0bd4aa`; post-merge run #230 passed and the implementation branch was deleted.

### Architecture A2 — Payment-Method Availability Client/Cache — DONE / VERIFIED

- Extracted the characterized availability client/cache to `src/Provider/PaymentMethodAvailability.php` behind public `getUpayPaymentMethods()` while preserving cache identity, site/mode locking, the 65-second durable gate, strict provider normalization and fail-closed presentation.
- Added the mandatory Payment-Method Availability harness; the final exact-head and post-merge result was **102 PASS / 0 FAIL** alongside the complete historical and architecture stack.
- Exact final PR #22 head `bdb627520aa28e71b69a91f8ef71d04d257a3ad8` passed Quality Gates run #155 and clean independent review.
- Squash-merged PR #22 as signed commit `f85894271e8f991e77a8e6a2b306f4d191483bbd`, tree `1addbcc02e0d30f57a948cafd8111fb94e60c4da`; post-merge run #156 passed and the implementation branch was deleted.

### Architecture A1 — Provider Endpoint/Mode Resolution — DONE / VERIFIED

- Extracted deterministic live/test endpoint resolution to `src/Provider/EndpointResolver.php` while preserving all four public gateway compatibility wrappers and the inherited URL bytes.
- Added the mandatory Provider Endpoints harness; the final exact-head and post-merge result was **49 PASS / 0 FAIL** alongside the complete historical and architecture stack.
- Kept the official provider production-host difference out of this structure-only tranche as a separately researched future runtime migration.
- Exact final PR #21 head `baed693964556120dc7ad07dbc740d3acc1af20f` passed Quality Gates run #152 and clean independent review.
- Squash-merged PR #21 as signed commit `d43d175a1443709d42efabfbe78519a5a84f4dc9`, tree `ddb2ac7cd8b2d4f454867e10bc361fee94dbcf4b`; post-merge run #153 passed and the implementation branch was deleted.

### Security Threat-Model Closure — DONE / VERIFIED

- Closed the public historical order-status IDOR by requiring an UPayments order plus exact logged-in ownership or exact WooCommerce order key; numeric order ID alone is no longer authority and returned state is allowlisted.
- Replaced nonce-bearing subscription pause/resume/unsubscribe GET mutations with exact-owner-bound POST forms/actions, action-specific nonce verification, subscription object preflight and valid transition checks.
- Removed Google Fonts and cdnjs Font Awesome checkout dependencies and replaced classic plus Checkout Blocks chevrons with local presentation.
- Tightened plain provider/order metadata to text escaping, stored settings to attribute escaping, and removed `$_REQUEST` from checkout display markers.
- Added local WooCommerce nonce/post-ID/`edit_post` preconditions before plugin product-meta writes.
- Added permanent `tests/harness/security-threat-model-harness.php` to required Quality Gates; final characterization is **81 PASS / 0 FAIL**.
- Preserved existing provider host/TLS/redirect/Bearer, payment-truth, H12 identity, Phase 9I authorization, subscription no-blind-retry and immutable GitHub Actions-pin controls.
- Fixed one valid automated P2 review finding before merge by covering the Checkout Blocks Font Awesome seam explicitly in the permanent security harness.
- Exact final PR #17 head `fba12225899c3e01d6b23a6bba2f757a3b5f6a4a` passed full merge-ref Quality Gates run #88.
- Squash-merged PR #17 as `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9`, tree `e0027005f059fad03d8c08273b7aac6553c45f53`, with VERIFIED GitHub signature; implementation branch was deleted.
- Post-merge `main` Quality Gates run #89 passed the complete workflow again.
- Explicit non-claims remain: webhook HMAC/signature is provider-document unresolved, automatic refunds are unsupported pending durable idempotency/reconciliation, subscription auto-deduction is not broadly recurring-billing certified, and this is not broad penetration-test/PCI/platform/feature/performance/production certification.

### Historical program transition — Enterprise Compatibility Certification

**Enterprise Compatibility Certification** became the active gate at that milestone. Architecture A1-A5, Quality Platform Q1-Q19 and the PR #47 core runtime foundation are DONE / VERIFIED. That test-first declaration tranche published only matrix-proven WordPress/WooCommerce/PHP metadata and HPOS/Blocks feature declarations. Provider sandbox, feature-specific, multilingual, browser/accessibility/performance, operations and release certification remain open; completed gates do not establish broad feature, performance, PCI/compliance or production certification.

### Provider Contract & Payment Lifecycle — DONE / VERIFIED

- Researched and froze the ordinary-checkout UPayments/WooCommerce lifecycle contract against current official provider and WooCommerce documentation before implementation.
- Added isolated `Simplix\Pay\UPayments\Payment` lifecycle components rather than broadly refactoring the inherited gateway bootstrap.
- Preserved the historical `wc_upayments` route while moving ordinary browser/webhook financial truth to an earlier-priority controller.
- Made callback/browser payload fields non-authoritative; financial transitions require Bearer-authenticated Get Payment Status plus strict order binding.
- Added exact HTTPS UPayments status-host/path validation, redirect prohibition, TLS verification and finite timeout before Bearer credentials can be sent.
- Added a credential/mode-scoped atomic status-query gate at the stricter documented **30 requests/minute** ceiling while provider documentation remains contradictory.
- Added exact binding for `track_id`, `merchant_requested_order_id`, Woo order reference, currency and amount.
- Replaced display-precision monetary comparison with canonical exact decimal equality: trailing-zero equivalents are accepted, but additional fractional value can never round into equality.
- Classified exact provider results into CAPTURED / PENDING / FAILED / CANCELLED / INDETERMINATE; provider `NULL`, Processing-style and unknown future values remain unpaid and fail closed.
- Replaced direct paid-state `update_status()` semantics with WooCommerce `payment_complete($verified_payment_id)` and verified standard transaction-ID/paid-state postconditions before setting `_upay_verified_capture`.
- Prevented duplicate/replayed verified captures from repeating provider calls or payment-complete lifecycle hooks.
- Prevented terminal callback results from downgrading paid orders and prevented refunded orders from being resurrected.
- Added separate unverified and trusted reconciliation cursors; the former exists only for safe retry routing and is promoted only after authenticated rebinding.
- Scoped cursor/reconciliation state to the current `UPayments_order_id`, preventing a later Charge attempt on the same Woo order from inheriting stale attempt state.
- Added bounded deduplicated reconciliation at **60 / 120 / 240 / 480 seconds**, maximum four scheduled attempts, with no Charge retry.
- Added per-order database locking with exact compare-and-swap stale takeover/release semantics to prevent callback/browser/cron lifecycle races.
- Kept callback request parsing conflict-safe across GET/POST, excluded cookies, and removed `$_REQUEST` from the new lifecycle surface.
- Kept automatic WooCommerce refunds intentionally unsupported because UPayments documents asynchronous completion, no refund webhook and no idempotency keys; safe automation requires a later durable refund-intent/idempotency/reconciliation design.
- Froze current multi-merchant support as one additional merchant allocation only; arbitrary marketplace multi-split routing remains uncertified.
- Kept provider webhook HMAC verification explicitly unresolved instead of fabricating a verifier from incomplete public documentation.
- Added permanent Provider Payment Lifecycle and Provider Exact Amount Binding harnesses to required Quality Gates.
- Closed four valid automated review findings before merge: wp_salt/rate-gate seam, first-query transient reconciliation, stale-lock race and amount-rounding mismatch.
- Exact final PR #15 reviewed head `d2b08ebe1e65ad4ea8f4e06b41423e7bd9904fc3` passed Governance, tracked PHP syntax, Phase 0 **35/0**, Phase 9I preflight **123/0**, executor **59/0**, operations **81/0**, Provider Lifecycle **141/0**, Provider Exact Amount **4/0**, H12 PHP **1927/0**, Blocks syntax and H12 Blocks **144/0**.
- Squash-merged PR #15 as `9569e39973a9e94926087738eae06c3846361943`, tree `40ec562674361624c2764263ba55cfba84594955`, with VERIFIED GitHub signature and implementation-branch deletion.
- Post-merge `main` Quality Gates run #71 passed the complete workflow again.

### Phase 9I — historical token-identity migration — DONE / VERIFIED

- Added deterministic read-only migration preflight with exact `CLEAN`, `MIGRATABLE`, `BLOCKED` and `INDETERMINATE` classifications.
- Covered all 13 historical blocker families with explicit fail-closed behavior, including cross-user conflicts, malformed/mismatched scope or secret evidence, incomplete history, unloadable orders and force-refresh uncertainty.
- Kept preflight at zero provider calls and zero identity writes.
- Added a locked migration executor that acts only on fresh `MIGRATABLE` evidence and never fabricates `canonical` / `create_201` provenance.
- Limited migrated historical identity to `legacy_compat` / `legacy_verified_capture` with exact readback and final CLEAN verification.
- Preserved historical order metadata instead of rewriting payment/subscription evidence during migration.
- Added bounded admin and WP-CLI operations with dry-run, explicit execute confirmation, strict user-list/window bounds and no separate credential input surface.
- Added a separate redacted `_simplixpay_upayments_migration_result_v1` operations-result ledger for every processed user, including CLEAN, BLOCKED, INDETERMINATE, dry-run and exception outcomes.
- Added durable resume using credential/mode/list-scoped HMAC batch fingerprints without persisting the API key.
- Added fail-closed checkpoint semantics: failed result persistence stops the page and leaves the same user as the retry point; valid identity mutation is not rolled back and executor idempotency makes re-evaluation safe.
- Kept the migration operational surface isolated from provider transport, checkout, Store API, frontend and cron paths.
- Verified preflight PR #11 merge `8cca32819dd165e35efa0fcc5a48bdd551757d8c` with tree `c0af8a2ab1fbd2494f961ee9f924c00aaf519ab0`.
- Verified executor PR #12 merge `708253bd9d0daf217735fbb087b360e8b848136c` with tree `e222a18c9808229fdde79efb42268d8c3fbd33ae`.
- Verified operations PR #13 merge `db1c4ea4dab45bc1ffaf4529e0ccb940153cd999` with tree `5bec24ad26c66a504cd0dd609f4311f9e70add76` and VERIFIED GitHub signature.
- Phase 9I completion certifies the migration system/safety contract, not automatic migration of every merchant installation.

### Phase 0 — release identity and updater ownership — DONE / VERIFIED

- Established the active public plugin identity as **SimplixPay for UPayments** by **Simplix Innovations**.
- Established independent development version **0.1.0**.
- Added canonical release identity under `Simplix\Pay\UPayments\Release\Identity`.
- Removed inherited `upaymentskwt/woocommerce` update authority and bundled Plugin Update Checker.
- Disabled external self-updates pending a tested distribution/basename contract.
- Preserved transitional `UPayments.php`, `upayments` text domain and compatibility-sensitive payment identities.
- Changed uninstall behavior to retain merchant/payment data by default.
- Added permanent Phase 0 release-identity characterization: **35 PASS / 0 FAIL** with H12 PHP **1927/0** and Blocks **144/0**.

### Repository foundation — DONE / VERIFIED

- Established standalone canonical repository `SimplixInnovations/simplixpay-upayments` with preserved historical audit provenance.
- Established formal product identity **SimplixPay for UPayments** and canonical slug `simplixpay-upayments` while protecting persisted historical UPayments identities.
- Added permanent repository instructions, project-control documents, CODEOWNERS, issue/PR governance, security/support policies, MIT license and provenance notice.
- Added required GitHub Actions quality gates and protected-branch/security controls.

## Historical engineering record

The large pre-product H12 engineering changelog is preserved byte-for-byte at:

[`docs/history/H12-ENGINEERING-CHANGELOG.md`](docs/history/H12-ENGINEERING-CHANGELOG.md)

It documents engineering corrections made before the standalone SimplixPay product history was established and must not be interpreted as SimplixPay product releases.
