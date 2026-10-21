# SUPCheckout for UPayments — Project Status

**Status document:** canonical living engineering state
**Last reconciled:** 2026-10-21
**Canonical repository:** `SimplixInnovations/supcheckout`
**Development version:** `0.1.0`
**Owner technical acceptance:** **ACCEPTED for Release Candidate 1** (`OWNER_TECHNICAL_ACCEPTANCE=RELEASE_CANDIDATE_1`, recorded 2026-10-07)
**Accepted Release Candidate 1 source:** **`8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243`** (PR #128 squash-merged main)
**Accepted Release Candidate 1 package:** `supcheckout-0.1.0.zip` — 62 files / SHA-256 `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156`
**Historical Approach 3 acceptance (superseded):** `OWNER_TECHNICAL_ACCEPTANCE=APPROACH_3` at `146d65a1c182630c1acc651cacafe30cff5f6b79` / 62 files / `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`
**Historical Approach 2 baseline (superseded):** `0c883d609906676966002eb022a82a9656eeacc5`
**Historical Approach 2 package:** `supcheckout-0.1.0.zip` — 51 files / SHA-256 `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655`

> Live GitHub source, exact-head checks and package evidence override this document if they differ. Historical milestone records stay historical; this file records current engineering truth.

## Executive status

| Area | Current state |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Provider scope | **UPayments only** |
| Technical slug / text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| Main file | `supcheckout/supcheckout.php` — renamed from `UPayments.php` in Sub-project 0a (owner decision 2026-10-16, pre-release) |
| Approach 2 | **HISTORICAL / SUPERSEDED OWNER-ACCEPTED BASELINE** |
| Approach 3 | **HISTORICAL / SUPERSEDED OWNER-ACCEPTED BASELINE** |
| Release Candidate 1 | **CURRENT OWNER-ACCEPTED TECHNICAL BASELINE** |
| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |
| Final pre-clone runtime/QA closure | **DONE / VERIFIED — PR #75** |
| Approach 3 T1 | **DONE / VERIFIED** |
| Approach 3 T2 | **DONE / VERIFIED / runtime-bearing** |
| Approach 3 T3 | **DONE / VERIFIED / runtime-neutral** |
| Latest integrated post-T3 milestone main | `d69377d3e26831270a00151025d24cc64be9d36b` |
| Active program | **SUPCheckout revamp** — spec `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md`; see "Revamp program" below. The release program `external-certification-release-readiness` stays open until Sub-project 5 |
| E2 repository-executable generic | **DONE / CERTIFIED** |
| E3 repository-executable runtime evidence | **DONE / VERIFIED** |
| Latest E3 runtime checkpoint | `540b733c29656758f2392817649fc3d4a4db585d` |
| R2 | **DONE / VERIFIED** — PR #110 head `5a4f83efa7bda0b5d6169811308800270c888d6c`, merged main `1c95bc9434784c705e98245f3f9d65f95f4de7ef`; package SHA-256 `126195841942e923e3ee07cf659a42fd58bce32057dd9cc3e8a15d180d4229c3` |
| R3 | **DONE / VERIFIED** — PR #112 certified head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`; candidate package SHA-256 `070279120064a6fe558dbeef3702a10b90330b8dc79fd9f21f028cd5fefba4da` (NOT owner accepted) |
| R4 | **DONE / VERIFIED** — PR #114 certified head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`; package 62 files SHA-256 `2543c2a0bfdba53e8968bd1a09b263a3a2b7bd02a11eabd41c102aa5d6dc7b8b` (NOT owner accepted) |
| R5 | **DONE / VERIFIED** — PR #116 certified head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` (NOT owner accepted) |
| Current executable gate | **Hardening program: full functionality, security and performance audit and fix loop — NEXT** (owner decision 2026-10-20). Next after the hardening program: Sub-project 2 admin screens, once the owner shares the information architecture and branding |
| Release gate | **external certification & release readiness — open**; spec §9 places its owner acceptance and live acceptance in Sub-project 5 |
| Post-acceptance runtime on `main` | PR #133 payment lifecycle hardening (2026-10-10 review), merged main `35ea66d4cb9c8395b264cd24b4844eaa268aa470`; package 62 files SHA-256 `bbb40cb2bc6bec557195689ea9335bff514082ad3353dd2e40f2057501681730`; then PR #138 non-white-label Charge fix, package `961aa4727d030d317ec892e8d3ee47e10b209bbabfc53a8fbba8ee6ece8f3133`; **NOT owner accepted** |
| Owner step before live evidence | **Fresh technical acceptance of the current `main` package** (`LOCAL-FINAL-VERIFICATION.md`). Live evidence must be produced with the package that will be released, not Release Candidate 1. |
| Next release dependency | **Fresh owner acceptance of a package containing PR #138**, then live one-time acceptance. Production auth: `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` (owner-run account probe, 2026-10-13, [evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md), runbook [`PRODUCTION-AUTH-ACCOUNT-PROBE.md`](PRODUCTION-AUTH-ACCOUNT-PROBE.md)) |
| Sub-project 0a clean core | **DONE / VERIFIED, staging-rehearsed** (2026-10-17) |
| Sub-project 0b takeover engine | **Tasks 1–12 merged**; Takeover Migration Matrix green on `main` `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b` |
| Sub-project 0c onboarding backend | **Tasks 1–10 merged** (PR #194 `5dce3f1d1397d455baa4b4bfe64ef3f89184de6e`) — services + admin-only `supcheckout/v1` REST API, no UI (wizard in Sub-project 2); its 134-file package is historical, superseded by Sub-project 1; owner staging review **DONE / OWNER-VERIFIED** (2026-10-22) |
| Sub-project 1 design system | **DONE / VERIFIED** — PRs #195–#197, #199, #200, #202–#209 and #211; last merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5`; package 224 files, SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4` (NOT owner accepted) |
| Public tag / GitHub Release | **NOT CREATED / NOT AUTHORIZED** |
| WordPress.org publication | **NOT PERFORMED / NOT AUTHORIZED** |

No Q20 is justified. New work uses named bounded engineering tranches.

## Frozen regression authority

| Field | Value |
|---|---|
| Owner-accepted Release Candidate 1 source | `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243` |
| Accepted package | `supcheckout-0.1.0.zip` |
| Accepted package files | `62` |
| Accepted package SHA-256 | `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156` |
| Historical Approach 3 (superseded) | `146d65a1c182630c1acc651cacafe30cff5f6b79` / 62 files / `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd` |
| Historical Approach 2 package (superseded) | 51 files / `58eba75019416f39a09211c87e7ccbcbb635834fb20bc890e9efbd5fec859655` |

Release Candidate 1 is the current owner-accepted technical baseline; it supersedes Approach 3. A fresh explicit owner acceptance event is required to replace it. Technical acceptance does not authorize publication, provider outreach or a live transaction. Publication remains **NOT AUTHORIZED**. Unresolved provider contracts remain uncertified.

## Approach 3 merged coordinate

T3 was squash-merged through PR #107 to `a7a8bbfc3a1dc551127b7ead897c964e95c7cec9`. Its exact certified PR head was `f7c7d596dc4a2c8464d1acdf13dfe51028a5f9e0`.

T3 is runtime-neutral and permanently characterizes `WC_Upayments::return_from_upayments()`, `WC_Upayments::web_hook_handler()`, and private `WC_Upayments::verify_payment_status()`.

Direct callers of `return_from_upayments()` may not carry the WC-API GET `page` marker used by `PaymentLifecycle::handle_callback()` for browser-mode inference. T3 therefore does **not** authorize naive T4 delegation.

The completed post-T3 R0/R1/E1-E3 milestone was squash-merged through PR #108 to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. T2 `047cc86060efb97761d7a0cc4a3806f971ab6fe1` remains the last separately tracked runtime-bearing Approach 3 tranche before that integrated milestone.

## Post-T3 hardening

Canonical plan: [`../superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md`](../superpowers/plans/2026-09-10-post-t3-ecosystem-hardening.md).

Completed integration milestone: PR #108 / R0/R1/E1-E3, squash-merged to `main` at `d69377d3e26831270a00151025d24cc64be9d36b`. The temporary branch was deleted after merge.

E3 repository-executable exact-head checkpoint:

`540b733c29656758f2392817649fc3d4a4db585d`

At that exact SHA:

- Quality Gates / H12 — **SUCCESS**;
- Compatibility Certification — **20/20 runtime cells + Compatibility Gate SUCCESS**;
- Ecosystem Certification — **five free parent themes with real child-theme overrides, four free cache/optimizer coexistence lanes, current-runtime legacy+HPOS lanes + Ecosystem Gate SUCCESS**;
- Provider Sandbox Certification — **SUCCESS**;
- WordPress.org Submission Check — **SUCCESS**;
- Release Artifact — **SUCCESS**;
- delayed/combined/repeated Classic JS lifecycle harness — **26 PASS / 0 FAIL**;
- analytics-return characterization — **27 PASS / 0 FAIL**;
- deterministic installable package — **55 files**, SHA-256 `01dbf672f9e18898a642a216b16fbf79dcc08511a617af9a8553d7341d87478c`.

The GitHub default CodeQL JavaScript/TypeScript job for this historical checkpoint did **not** reach a terminal verdict. That nonterminal hosted-security result is not a plugin failure, but it is also not a success claim. Every descendant merge/release boundary still requires CodeQL/security green.

### E3 evidence boundary

Repository automation now proves the bounded behavior it actually exercised:

- Storefront 4.6.2, Twenty Twenty-Five 1.5, Astra 4.13.11, Blocksy 2.1.56 and Kadence 1.5.2;
- real child-theme WooCommerce template override precedence;
- LiteSpeed Cache 7.9.1, W3 Total Cache 2.10.6, Breeze 2.5.13 and SiteGround Speed Optimizer 7.8.2 coexistence;
- delayed-first-interaction, duplicate/combined evaluation and repeated fragment replacement behavior;
- browser return/replay financial idempotency and stable redirect identity.

This does **not** certify unavailable paid/licensed themes/plugins, Cloudflare custom rules, Rocket Loader, host-specific full-page caches, browser/device visual behavior or third-party analytics deduplication.

The analytics characterization establishes that replayed verified browser callbacks do not mutate financial state but can repeat the same order-received navigation. SUPCheckout itself emits none of the tested GA/Meta/GTM purchase APIs; downstream trackers remain responsible for their own deduplication.

## R0 anti-staleness evidence

R0 permanently introduced current-state regression coverage. For the E3→R2 transition, RED `715b0b8671573a1a4d5ef1d7d998cf00da64e611` produced 230 tests / 1,578 assertions / exactly two intended stale-state failures. The matching living-state reconciliation must keep that test GREEN.

## Remaining post-T3 program

The overall program is **not finished**.

Current executable gate: **Hardening program: full functionality, security and performance audit and fix loop — NEXT** (see "Revamp program"). Release gate (open): **external certification & release readiness**. The first-release core provider contract is `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` (2026-10-13), scoped to the release merchant account and packages containing PR #138. Publication remains NOT AUTHORIZED. Automatic recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED.

R5 callback lifecycle consolidation is **DONE / VERIFIED** (Option B / ADR-003; PR #116 certified head `6fc225fc736da107de533ba8e19a12dc5c37227d`, merged main `50170ea7f0d17b792e133a70beee48da7e2b6326`; package 62 files SHA-256 `0f9c4b6004b31c80b837bd1adca8cf0226abc67fc2c87d6bc3da937b1552d1dd`).

R4 subscription scalability/observability is **DONE / VERIFIED** (PR #114 certified head `1f48d0669af2a8e9fd9559a35568b6ce33ce4a6a`, merged main `10a33b4d10e7ec4e45ba5d7a01139ce0777bf382`). At that historical checkpoint, auto-deduct capture, remote cycle identity, HMAC and token-storage questions were all unresolved. The **current** first-release disposition below supersedes that historical blocker set. Automatic paid-renewal finalization remains **FAIL-CLOSED / HELD**.

R3 subscription safety is **DONE / VERIFIED** (PR #112 certified head `de0162b4a1cca77c62f07290224b055902120c2a`, merged main `e1ad33819b5f4ec1e01c3feb6afd15e604f89b11`). At that historical checkpoint, recurring capture/cycle, HMAC and token-storage questions were unresolved. The current provider-contract disposition below is authoritative for first-release scope. Automatic paid-renewal finalization remains **FAIL-CLOSED / HELD**.

1. **R2 — DONE / VERIFIED:** Woo API URL abstraction via injected platform resolver; `home_url` vs `site_url`; subdirectory, permalink/index and trusted proxy/public-origin behavior; explicit no-cache callback/public-status semantics.
2. **R3 — subscription safety:** exact auto-deduct amount/currency/parent/cycle/provider binding; no first-card fallback; parent discovery beyond `completed`; customer cancel/pause/resume policy; token-retention contract; held-cycle reconciliation.
3. **R4 — scalability/operations:** due-work scheduling/Action Scheduler, bounded batches, durable cycle-journal authority, observability, load/concurrency/failure injection and queue health.
4. **R5 / T4 — callback consolidation:** DONE / VERIFIED under Option B / ADR-003. Legacy public compatibility methods retained; financial lifecycle is PaymentLifecycle only.
5. **R6 — final release qualification:** immutable exact-head gates, manual/external qualification, fresh owner re-acceptance and explicit version/publication decision.

## Revamp program (reconciled 2026-10-21)

Spec: [`../superpowers/specs/2026-10-16-supcheckout-revamp-design.md`](../superpowers/specs/2026-10-16-supcheckout-revamp-design.md). Delivery (spec §9) is Sub-projects 0–5; Sub-project 5 holds the final owner technical acceptance, live acceptance and release. The external certification & release readiness gate above stays open until then.

**Sub-project 0a clean core — DONE / VERIFIED.** PRs #165–#172 (last merge `0cef28dbcf35d3ec5e669f3c0b46f580069c4c31`): `supcheckout.php` main file with the header-less `UPayments.php` shim, namespaced `src/` layout, no global functions or closures in the main file. The owner staging rehearsal passed on 2026-10-17 (77-file package SHA-256 `d8dedbf94c7241e14e701e4a180d308359ab106c3c7651605a53fd1817404c05`; [evidence](evidence/SUBPROJECT-0A-CLEAN-CORE-2026-10-17.md), merged through PR #178 `900bd67b666edeb4e5c447765bbe67f599b2344f`).

**Sub-project 0b takeover engine — Tasks 1–12 merged.** Plan: [`../superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md`](../superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md).

| Task | PR | Merged main |
|---|---|---|
| 1 — `Keys` identity registry and coverage harness | #173 | `88d216f2473f66a70aeb28c93028d2f03391ed10` |
| 3 — persisted state machine and change journal | #174 | `c6c59a0c98ae0f6d05277fc197a844dfbd9fec5c` |
| 2 — every persisted identity through `Keys`, switch-window model | #175 | `4177e725a5d563b53d79b1b6f255de5060ccb3a6` |
| 4 — read-only preflight report | #176 | `7018b1d137f524bf55db87d903ab47e71d8e6578` |
| 5–6 — per-order copier and resumable copy runner | #177 | `3778226ebb3da4ef33a14db381525c576ae4ced3` |
| 8 — permanent `wc_upayments` callback route and the `supcheckout` route | #179 | `8c97f400d1e4e76686936a12bb715d9ade1fbe75` |
| 7 — quiesced cutover (settings, third-party, cron, gateway ID) | #180 | `fce67ec01f943d02a411071fed17bdb2a9d00442` |
| 9 — `wp supcheckout takeover` CLI and paused boot | #181 | `2b941852b5d9f0acc97a92c17344281358814be0` |
| 10 — journal-driven rollback and abort | #182 | `d1a4a2ffb7da75149f19a881a6e4fe602313ff14` |
| 11 — guarded, irreversible finalize | #183 | `e5dde2630d14d548ecdbd9ed354a997baa9967d2` |
| 12 — real-WordPress migration matrix | #184 | `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b` |

Takeover Migration Matrix (`.github/workflows/takeover-migration-matrix.yml`) — **SUCCESS on `main` at `86c65ba73bd8cc7adba18dea47b46b7dcd8ac41b`** (run 39084507907): official plugin refs `V2.0.5`, `upayments-V2.2.1`, `3.0.0` and `3.1.1`, each with HPOS (sync off, WCML option shapes seeded) and legacy storage; the pre-0b SUPCheckout in-place-update lane; the 10,000-order lane (3.1.1, legacy); and the matrix gate. It sends no provider traffic (fixture responses, UPayments hosts sinkholed). WCML is report-only: Preflight reports its gateway rules and the takeover leaves them byte-for-byte unchanged. The workflow is a permanent quality control (`AGENTS.md`).

The 0b takeover staging rehearsal PASSED on 2026-10-18 (`docs/project/evidence/TAKEOVER-REHEARSAL-2026-10-18.md`): preflight blocked correctly during a UPayments sandbox outage; then the switch, an in-flight payment from the official plugin, rollback with reactivation, a second switch and a forced Finalize all behaved as designed. Its two findings were fixed in #185.

**Sub-project 0c onboarding backend (services + REST) — Tasks 1–10.** Plan: [`../superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md`](../superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md). Owner decision 2026-10-18: 0c is the onboarding backend (services + REST) and ships no screens; the wizard UI moves to Sub-project 2, on top of this API and after Sub-project 1. Tasks 1–2 merged through #190 (`032d9a4efc1573b36d6c54031b283de5e3ed9645`, the UI-agnostic `Onboarding` service) and #191 (`41a2b1ce5383bc94bcc4e3ffb5fcf0e755fd8790`, the cache purge at `caches_flushed`).

- `Onboarding` service, used by WP-CLI, the driver and REST. `wp supcheckout takeover` keeps every exit code, flag and output line.
- Action Scheduler driver (`AsyncDriver` + `DriverStep`): one persisted, single-flight `Operation` advanced by bounded ticks. Long work gets 3 attempts, a busy store 17 retries 60 s apart, and a provider cooldown 3 retries 65 s apart; the 120 s settle barrier is a scheduled resume, never a sleeping worker. It needs WP-Cron, a system cron or admin page loads; otherwise the status route reports `runner.stalled`.
- Admin-only REST API `supcheckout/v1`: 12 routes on the full boot, 8 while paused (the official plugin active). Every route needs `manage_woocommerce`; every write also needs the explicit `wp_rest` nonce, so an Application Password alone is refused (403 `supcheckout_nonce_invalid`). Official-plugin consent and a real finalize also need `activate_plugins` (403 `supcheckout_capability_plugins` for a Shop Manager; security review finding fixed).
- Cache purge after the compare-and-swap: LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Breeze and SiteGround Optimizer, plus the object cache; the Cloudflare plugin is reported `manual`.
- Self-test: one read-only availability probe in test and live mode alike.

The on-the-wire notes and the independent security review (no Critical or High finding) are in [`evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md`](evidence/SUBPROJECT-0C-ONBOARDING-BACKEND-2026-10-19.md). Its real-WordPress REST and driver certification (compatibility run 39177850394) is historical: it certified `535b1b4b80b308731bb7352def785130b86d6b72`, before the `activate_plugins` fix and the final review wave. Tasks 4–10 then merged through PR #194 (`5dce3f1d1397d455baa4b4bfe64ef3f89184de6e`) after every exact-head workflow succeeded at its head `b48badf1f1c81284546ac4c3be62ca211201676f`: Compatibility Certification run 39205662675 (all 20 cells and the Compatibility Gate, including the real `shop_manager` assertion), Ecosystem Certification 39205662699, the Takeover Migration Matrix 39205662713, Release Artifact 39205662707, the WordPress.org Submission Check 39205662685, Quality Gates 39205662712, Provider Sandbox 39205662715 and CodeQL 39205661870. The same workflows succeeded again on `main` at `5dce3f1`. The public boundary is `docs/COMPATIBILITY.md` "Onboarding API boundary (Sub-project 0c)". Its package (historical, superseded by the Sub-project 1 package below): 134 files, SHA-256 `914cca32cfc2ea4b60ba1fc32e61205d12e0d443e2ab55b08167436033600bba`.

Sub-project 0c owner staging review — **DONE / OWNER-VERIFIED** (2026-10-22): on the My Organic World staging copy, the `main` `1882254` package (224 files) passed all seven setup checks (the optional sandbox test payment is deferred to the setup screen), WCML per-currency checkout visibility passed (follow-up 3), and a sandbox AED card order completed as Processing with Payment Status CAPTURED. Follow-up 2, the automatic LiteSpeed purge on a REST switch or rollback, could not be repeated there (the takeover is finalized and LiteSpeed Cache is not active); it is covered by the real-plugin CI cell, and confirming it on the live store's real takeover is on the owner manual test list (external certification). Evidence: [`evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md`](evidence/SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md).

Sub-project 1 design-token system and admin component library — **DONE / VERIFIED**. Plan: [`../superpowers/plans/2026-10-19-subproject-1-design-system.md`](../superpowers/plans/2026-10-19-subproject-1-design-system.md). Evidence, the SP2 rules carried from SP1, the owner acknowledgements (all acknowledged 2026-10-22) and the owner manual test list: [`evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md`](evidence/SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md).

| Task | PR | Merged main |
|---|---|---|
| Plan | #195 | `c8e85f4d5a797923099e00e3e825380a037faab5` |
| 1 — pinned toolchain, committed-build contract, Admin UI Certification | #196 | `55899aef09cc9d3d41e1898cda59bb2317d20bdd` |
| 2 — `--sup-*` token system, WCAG 2.2 AA contrast in light and dark | #197 | `a01a7b0c158f1244d1ed4f0ef34e63299334c3d9` |
| 3 — `SupRoot`, scoped portal layer, RTL-aware foundations | #199 | `4495ad60579d60d6eb533037bda1699a3d44ae02` |
| Dev-tooling advisories; the audit blocks every severity from low up | #200 | `dc3ac3f87c51dacfae3d360387b3f76115bc404f` |
| 4 — `AdminAssets` loader, test gallery, real-WordPress browser lane | #202 | `a4d1850ee7b82f5b4ab169f6b30c03d28c7d4dda` |
| 5 — buttons and form controls, write-only `SecretInput` | #203 | `6686e8a7f77c41346eda36cf6dd2f3504903aa2f` |
| Plugin Check wp-env held to the 14-day dependency cooldown | #205 | `eb103cf60feded1ec911a4a18291690e72f30ccb` |
| 6 — status, notice, skeleton, stepper, toast, tooltip | #204 | `b08222c34e7943c65807caece0b88320e231e8de` |
| 7 — dialogs, money-safe confirmation, tabs, data table, app shell, command palette | #206 | `b53a268b15c7e8cb22e4d297986ef274d83f2cf1` |
| 8 — pinned visual-regression lane and the Sub-project 1 exit gate | #207 | `ef9a101ec0ce2d106e1a0418523ed01a6d77ac9a` |
| markdown-it 14.3.3 (owner-approved cooldown exception); both markdown-it allow-list entries removed | #208 | `e0ecd7ff52931957e552a43e0cbec64554b67205` |
| Release, PHP and supply-chain hardening from the whole-SP1 reviews | #209 | `09b6ad7d58e4f3ae1ce7e148ea2ecbf2291628d2` |
| UI and accessibility hardening from the whole-SP1 reviews | #211 | `453e103bd5dc2c81f35e65520ac29dfa9182fbc5` |

- Brand-ready, not branded: SP1 ships no brand values; they arrive only through `brand.tokens.json` in Sub-project 5. It covers light and dark (default: the system preference), the nine core admin colour schemes, Arabic RTL, reduced motion and forced colours.
- The four whole-SP1 reviews (security, code, accessibility, release) are recorded with every finding's resolution in the evidence file; their fixes landed in #208, #209 and #211.
- Every SP1 PR merged after all of its exact-head workflows succeeded. The exit gate (`admin-ui-certification.yml` `gate`: static lane, Windows build, the browser and accessibility suite on WP 6.9.10 / WC 10.8.1 and WP 7.1.3 / WC 11.1.2, and the exact visual-regression suite) succeeded at the Task 8 head `dde7a40726e5e081c8c843597a8ec8836022da2b` (run 39570193162).
- Deterministic package: 224 files (134 after 0c, plus the committed build, the shipped TypeScript source and `AdminAssets.php`), SHA-256 `2e1f7c0349db7a6ab6709de35dc47099609a8a8c761f4a577b2006555fbe4af4` (`RELEASE-ENGINEERING.md`). NOT owner accepted.
- Post-merge verification on `main`: at the Task 8 merge `ef9a101ec0ce2d106e1a0418523ed01a6d77ac9a` every workflow succeeded, including Admin UI Certification run 39575252375 (its gate covers static, Windows drift, both browser cells and visual), Compatibility Certification 39575252634 and Release Artifact 39575252360. At the final SP1 merge `453e103bd5dc2c81f35e65520ac29dfa9182fbc5` (#211, recorded 2026-10-21) the WordPress.org Submission Check 39660030118 and Supply Chain Evidence 39660030107 had succeeded and the rest (Admin UI Certification 39660030227, Quality Gates, Compatibility, Ecosystem, Release Artifact, CodeQL) were still queued; they are not claimed here. `Admin UI Gate` is a required status check in the `Main Rule` ruleset (owner approval 2026-10-21).
- `.github/workflows/admin-ui-certification.yml` is a permanent quality control (`AGENTS.md`). The public boundary is `docs/COMPATIBILITY.md` "Admin UI boundary (Sub-project 1)".

Owner decision 2026-10-20 (binding): the owner shares the full revamp, branding and information architecture only once functionality, security and performance are verified. The program after Sub-project 1 is therefore a hardening program: a full audit of functionality, security and performance, then a fix loop. Sub-project 2 screens, the wizard IA, the menu structure and the visual checkout redesign wait for the owner's information architecture and branding.

Current executable gate: **Hardening program: full functionality, security and performance audit and fix loop — NEXT**.

**Next after the hardening program: Sub-project 2 admin screens, once the owner shares the information architecture and branding**, then Sub-projects 3–5. Follow-up: provider `status: "error"` with a null result stays Pending through bounded reconciliation; closing it sooner needs provider-contract evidence. Publication remains **NOT AUTHORIZED**. Automatic recurring `VERIFIED_SUCCESS` remains **FAIL-CLOSED**.

## Current provider-contract disposition

- `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` — resolved 2026-10-13 by the owner-run account probe (see [`PRODUCTION-AUTH-ACCOUNT-PROBE.md`](PRODUCTION-AUTH-ACCOUNT-PROBE.md), [evidence](evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-2026-10-13.md)): status, button-status and Charge all accepted Bearer-only authentication. Account- and time-scoped; a written UPayments answer supersedes it. Release Candidate 1, and every package before PR #138, sends a non-white-label Charge that this production account rejects (HTTP 422, `paymentGateway.src` missing), so it must not be released.
- saved-card/token persistence: FUTURE FEATURE GATE.
- auto-deduct capture/cycle identity: FUTURE RECURRING GATE.
- webhook signature mechanics: FUTURE DEFENSE-IN-DEPTH.
- sandbox credential-family migration: FUTURE TEST-INFRASTRUCTURE GATE.
- No public-source inference authorizes a runtime HMAC/token/recurring change.
- Publication remains **NOT AUTHORIZED**.

## Permanent payment/security invariants

1. Routing input is never financial truth.
2. Charge initialization is not capture.
3. Captured/paid state requires authenticated provider status bound to the correct attempt/order/economics.
4. Finalized WooCommerce order amount/currency is authoritative; provider `products[]` is descriptive.
5. Shipping, tax, fees, coupons and order-bump economics must be finalized before Charge.
6. Post-dispatch economic mutation cannot silently alter payment authority.
7. Non-idempotent Charge/refund/auto-deduct mutations are never blindly retried.
8. Customer/card/provider identity ambiguity fails closed.
9. Protected persisted/provider identities require an approved migration contract.
10. Runtime corrections use RED → minimal GREEN → exact-head recertification.

## Protected compatibility identities

Protected contracts include `upayments`, `woocommerce_upayments_settings`, Blocks identity `upayments`, callback `wc_upayments`, historical `_upay_*` metadata, `UPayments_order_id`, H12 token/provenance/scope/generation state, subscription/billing-attempt identities, historical order payment-method values, frozen Phase 9I identities, `getAPIUrlForRetreiveCards()`, and normalized `whitelabled`.

They stay protected until migrated by the approved takeover (Sub-project 0b): copy, never move, through `Keys`; historical order `payment_method` never rewritten; `wc_upayments` served permanently. See `AGENTS.md` for the full contract.

## Repository/release governance

Required protected-branch checks are `Governance`, `H12 Regression Harness`, `Compatibility Gate`, `Release Gate` and `Admin UI Gate` (added 2026-10-21 with owner approval).

Exact-head qualification also accounts for CodeQL/security, Provider Sandbox, WordPress.org Submission Check, deterministic cross-platform release evidence, locked dependency audit where applicable, review-thread resolution and post-merge verification.

Tags, GitHub Releases and WordPress.org publication remain prohibited until explicit owner authorization.

## Evidence boundaries

Repository certification does not replace production merchant payment completion, real wallet/account/device completion, WPML/WCML/multilingual/multicurrency/RTL qualification, broad browser/theme/accessibility testing, representative production-store load, penetration/PCI/legal attestation, or live non-idempotent subscription auto-deduction evidence.

Automatic WooCommerce refunds and arbitrary marketplace multi-split remain unsupported.

See [`OWNER-HANDOFF.md`](OWNER-HANDOFF.md), [`NEW-CHAT-HANDOFF.md`](NEW-CHAT-HANDOFF.md), [`../COMPATIBILITY.md`](../COMPATIBILITY.md), and [`RELEASE-ENGINEERING.md`](RELEASE-ENGINEERING.md).
