# Hardening program: close record (2026-10-23)

**Status:** repository-executable fix loop **DONE / VERIFIED** (batches 1 to 3); batch 4 (#226) closed the last fixable residuals; batch 5 (`hardening/batch-5`) fixes the safe "left on purpose" items; batch 6 (same branch and PR) fixes A6-06, PHPStan over all of `src/` with the official WordPress and WooCommerce stubs.
**Next gate at the close:** owner staging verification of the hardened package. It is done (2026-10-24, [evidence](OWNER-STAGING-VERIFICATION-HARDENED-2026-10-24.md)); the current gate is the revamp information architecture and branding from the owner (Sub-project 2+).
**Publication:** NOT AUTHORIZED. Automatic recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED.

This record closes the hardening program that the owner put before the revamp. The per-unit ledgers (scope, RED/GREEN evidence, review rounds) are in the PR bodies of #215, #221, #222, #223, #226 and the batch 5 PR. This file keeps the program-level facts.

## Why the program ran

- **Owner decision 2026-10-20:** hardening comes before the revamp information architecture and branding. The owner chose "full audit then fix loop".
- Sub-project 1 was done and the 0c owner staging review had passed ([evidence](SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md)). Its observations O1 to O8 fed the audit.

## Audit

Six read-only audits ran against `main` `1882254`:

| Audit | Lens |
|---|---|
| A1 | payment |
| A2 | security |
| A3 | performance |
| A4 | compatibility |
| A5 | takeover and onboarding |
| A6 | code quality |

Before dedupe they found 50 findings: **2 Critical, 3 High, 18 Medium, 27 Low**. Adversarial verifiers then confirmed, corrected or refuted each one. Their verdicts set the final severity and the fix for each finding.

- **The two Criticals.**
  - A1-01 (auto-deduct sent with no success path) was corrected to HIGH, and CRITICAL where white-label subscriptions are enabled.
  - A5-01 (official plugin reactivated after cutover) was corrected to MEDIUM while the A1-01 gate is closed.
- **The three Highs.**
  - A1-02 (a long unit price vetoed the Charge) was confirmed.
  - A1-03 (no currency minor-unit guard) was corrected to MEDIUM.
  - A5-02 (takeover walks never flush the runtime cache) was confirmed.

## Batches

| Batch | PR | Merge on `main` | Exact-head CI |
|---|---|---|---|
| A1-02 (alone) | #215 | `376c482` | 96/96 including CodeQL |
| Batch 1 | #221 | `e3884ca` | 97/97 at exact head `36c62fd` |
| Batch 2 | #222 | `3575721` | 97/97 at exact head `1302568` |
| Batch 3 | #223 (branch `hardening/batch-3`: R1-1, R1-2 and this close record) | `f3e207d` | 97/97 at exact head `8a8306c` |
| Batch 4 | #226 (branch `hardening/batch-4`: the last fixable residuals, A2-02, H12-META, AS-1) | `eed5bbd` | 97/97 at exact head `1e4cee3` |
| Batch 5 | branch `hardening/batch-5` (owner instruction 2026-10-25: the safe "left on purpose" items A1-11, A4-05 rest, A4-08, U2 N-1; A6-08 closed by design), with batch 6 (A6-06 PHPStan stubs) in the same PR | pending its PR | runs at its PR |

**Why the PRs were batched.** GitHub Free allows 20 concurrent jobs, and one PR runs about 80. So each unit was reviewed on its own branch or PR, verified locally, and then shipped in one integration PR per batch. The original PRs #216 to #220 were closed as superseded, and their ledgers stay in their PR bodies.

## Units

| Batch | Unit | Findings | Change |
|---|---|---|---|
| — | #215 | A1-02 | Drop `products[]` instead of failing checkout when a unit price exceeds the provider length cap. |
| 1 | #217 | A6-05 | Characterization tests for the untested lifecycle and token reason codes (tests only). |
| 1 | #216 | A1-01 (+ A5-01 money path) | Never send an automatic renewal charge while its success cannot be verified. Adds the paused-renewal note, the notice and the copy. |
| 1 | #218 | A5-02 | Flush the runtime object cache in the takeover walks, so 10k-order stores can switch from the browser. Adds the `large_walks` step and the `large-store-web` matrix job. |
| 1 | #219 | A3-07 | Decode the stored takeover state once per request: a memo keyed on the raw string, while CAS still reads fresh. |
| 1 | #220 | A1-03 | Fail closed before the Charge when an amount has more decimals than its currency's ISO 4217 minor units. Adds the report-only `currency_decimals` setup check. |
| 2 | U1 | A1-04, A1-05, A6-03 | An authenticated amount or currency mismatch leaves one merchant note instead of a silent Pending order. A late capture on a second track gets a bounded recheck. Schedule failures stay bounded. |
| 2 | U2 | A1-06, A3-03, A3-06, A1-09, A6-04 | The reconciliation sweep rotates fairly. Failed orders are swept only while unverified or non-terminal. Mismatched orders are parked. Adds a neutral "no callback received" note. The sweep ensure moves to `action_scheduler_init` and is throttled. `route_callback` no longer throws. |
| 2 | U5 | A3-01, A3-02 | Checkout renders read a 10-minute last-known-good availability copy and a 120 s display-only saved-card view cache. No provider card tokens are cached. Authorizing paths stay authoritative. |
| 2 | U6 | A4-03, A4-05, A4-06, A4-07 | Blocks use the translated title and description. The description renders with `dir="auto"`. Prices use `wc_price()`. The default description is clearer. |
| 2 | Charge path | A1-07, A4-02, A1-10 | A charge-amount snapshot binds only on exact equality with the persisted order total (fail closed). Arabic orders get the Arabic hosted page. UTF-8 scrubbing keeps accents and NBSP. |
| 2 | OL-1 + R-1 | new (fix loop) | A corrupt or never-expiring order lock is recovered by an exact-value compare-and-swap, and a live lock is never stolen. Every bind confirms the persisted total. An unreadable total is retryable. A filtered order total fails closed. |
| 2 | Gateway/admin | A5-01, A6-01, A6-02, A2-01, A6-06/07/09 | The guard refuses reactivating the official plugin on a switched store and refuses network activation on multisite. The paused notice reflects the takeover state. The availability getter adds no checkout notice. The settings save shows an admin notice. The API key is write-only in the UI and REST. The subscription global is prefixed. |
| 2 | U8 + U11 | A3-08, A4-04, A4-10 | Deactivation cleans up SUPCheckout's scheduled work without destroying data, and never touches `upay_process_subscriptions`. Adds report-only WPML gateway-string and multi-merchant multi-currency setup checks. |
| 2 | T3 + T4 | A5-03 (disclosure) to A5-07 | Preflight blocks a foreign settings target before anything changes. Busy-lock retries are bounded. Stalled-scheduler handling is improved. A lost deactivation record is surfaced as a warning. |
| 2 | U7 | A4-01 | Bundled Arabic translations, loaded through `lang_dir_for_domain` with no `load_plugin_textdomain`. Adds an early-loading regression guard in the Compatibility cells. |
| 2 | T11 | A2-03 | The ABSPATH guard on the last 13 `src` files, with a permanent threat-model assertion. |
| 2 | Final review | I-1, I-2, M-1 to M-5 | Removes contradictory notes. Aligns the lifecycle doc with R-1. The under-lock unreadable total is retryable. Filtered totals get their own reason. Rollback stops the paused sweep. One schedule-failure note per order. The subscription script is content-versioned. |
| 3 | R1-1 | final-review residual | `clear_reconciliation()` deletes the shared could-not-schedule flag, so a later, unrelated refused schedule is noted again: one note per failure episode. |
| 3 | R1-2 | final-review residual | `ReactivationGuard::after_activation()` (`activated_plugin`) unschedules SUPCheckout's hourly sweep and its throttle marker when an official-plugin activation is let through. Failures are isolated. |
| 3 | T12 | A2-04 | This record, the dated threat-model entry-point census addendum and the pentest scope update. Documentation only. |
| 4 | Residuals | A1-03 note, note-lock token, N-2, N-3, M-5 rest, O4 | One minor-unit refusal note per order and currency/decimals fact. The paused-renewal note lock is released by compare-and-delete of its own token. A transient gateway miss leaves the missing-callback note eligible. The public-surface harness attributes `action_scheduler_init` by callback. Every front-end asset is content-versioned. The payment ID label reads "UPayments payment ID" (Arabic «معرّف دفع يوبيمنتس»). |
| 4 | A2-02 revised | A2-02 (Q12) | Live mode requires https Charge redirect links, upgrading http links on `upayments.com` or a subdomain; sandbox unchanged. |
| 4 | H12-META | new (from the A6-06 stub run) | `CustomerTokenIdentity::get_historical_meta_cardinality()` reads the single historical order meta row the way real WooCommerce returns it (`reset()` and `WC_Meta_Data->value`), so save-card, selected-card and subscription checkout and Phase 9I work on real stores. The doubles model real WooCommerce, and a real-WooCommerce integration test runs in every Compatibility cell. |
| 4 | AS-1 and test isolation | new (LOW) | `ActionSchedulerBridge::is_ready()` probes the real `\ActionScheduler` class. The Subscription scheduling tests own their `$wpdb` and option store, so `tests/unit/Subscription` passes on its own. |

## New findings raised during the fix loop

- **H12-META (batch 4, from the A6-06 stub run; opened, then resolved in batch 4 below).** `CustomerTokenIdentity::get_historical_meta_cardinality()` reads `$all_meta[0]` and calls `WC_Meta_Data::get_value()`. Real WooCommerce (checked against 11.2.1 source) returns the meta entries with their original array keys, and `WC_Meta_Data` has no `get_value()` method. So on a real store a present key usually reads as duplicate/invalid (fail closed), and throws an `Error` when the entry is the order's first meta row. The test fixtures define a `get_value()` that WooCommerce lacks. It changes H12 token classification, so it needs its own characterized unit and review; it was not changed in batch 4.
  - **Resolved later in batch 4 (H12 contract repair, not a widening).**
    - **Verification version.** The fix was verified against WooCommerce 11.1.2, the version the Compatibility matrix pins, from the official package. The "11.2.1" in the finding above is kept as originally recorded.
    - **Fix.** The helper now takes the single entry with `reset()` and reads `WC_Meta_Data->value`. It keeps the array-row fallback and stays fail closed on duplicate, non-scalar and unrecognised rows.
    - **Test doubles.** The harness doubles now model real `WC_Meta_Data` (no `get_value()`; `__get`/`get_data()`) and real position-keyed `get_meta($key, false)`.
    - **Scope of change.** Only the classification of an order carrying exactly one row of an H12 key changes. It moves from duplicate/invalid (or an uncaught `Error`) to the per-caller contract outcome. Every caller's duplicate, non-scalar, orphan, mismatch and verify-mismatch branch still fails closed.
    - **Empty-string paths now reachable.** The callers' existing code treats an empty-string token or card row as "no identity". Before the fix those branches were reachable only through the fake `get_value()` doubles, and no fixture wrote an empty token or card. They are now tested explicitly:
      - in history inspection, token `''` with card `''` gives `none`; token `''` with a kind row gives `malformed_scoped`; token `''` with a non-empty card gives `card_without_customer_identity`;
      - in the bootstrap census, a lone `''` row stays `indeterminate`/`bootstrap_blocked_by_history`;
      - in a real save-card `process_payment()`, a lone `''` card row passes the residual gate and a lone `''` token row blocks it before Create and Charge;
      - in Phase 9I preflight, token `''` with card `''` gives `CLEAN`.
    - **Ledger.** The H12 PHP harness gained 13 `helper_unit_runtime`, 3 `semantic_runtime` (family PE) and 1 `harness_self_test` assertions, taking it from 1993 to 2010. The Phase 9I preflight harness went from 123 to 129.
    - **Real WooCommerce.** `tests/integration/HistoricalMetaCardinalityRuntimeTest.php` checks the result on real WooCommerce meta, including a guaranteed index-0 row.
    - **Impact before the fix (code analysis).** Against real WooCommerce, token-dependent checkout (save card, subscription, selected card) failed at the Section M persistence readback, and Phase 9I migration was unreachable on real stores. The in-process save-card control assertion reproduces the end-to-end path with the realistic doubles.
- **AS-1 (batch 4, LOW; resolved).** `ActionSchedulerBridge::is_ready()` probed `\Action_Scheduler`, but the bundled class is `\ActionScheduler`.
  - That branch now probes `\ActionScheduler`, and the `did_action('action_scheduler_init')` fallback stays.
  - The unit stub now models the real class name, and `tests/integration/SchedulingTraversalRuntimeTest.php` now checks `ActionScheduler::is_initialized()`.

- **OL-1** (from the verify-T A5-05 referral). A corrupt or never-expiring order-lock record blocked that order's payment lifecycle forever. Fixed in batch 2: exact-value CAS recovery, and a live lease is never stolen. OL-1 overrode the T4 premise that a corrupt record is held forever; the T4 tests were retargeted to a held lock.
- **R-1** (pre-existing, MEDIUM). A 2-dp Charge later edited at 3 dp bound through `get_total()` in a 2-dp request. Fixed in batch 2: every bind confirms the persisted total (HPOS `total_amount` or CPT `_order_total`).

## Owner decisions

| Date | Decision |
|---|---|
| 2026-10-20 | Hardening before the revamp IA and branding: "full audit then fix loop". |
| 2026-10-22 | A1-01, "Block auto-charges": no auto-deduct is sent until success can be verified. A clear order note and an admin notice are shown, and renewals are collected manually or by payment link. Recorded as the release-scope change in `RELEASE-SCOPE-DECISION.md`. The takeover-matrix "dispatched once" assertion changed deliberately. |
| 2026-10-22 | 0c and SP1 acknowledgements: the recommended options for both ([0c evidence](SUBPROJECT-0C-OWNER-STAGING-REVIEW-2026-10-22.md), [SP1 evidence](SUBPROJECT-1-DESIGN-SYSTEM-2026-10-21.md)). |
| 2026-10-22 | GitHub Free CI capacity: verify locally, then batch the reviewed PRs into one CI run. |

## Parked

None. A2-02 was the only parked finding, and batch 4 fixed it.

| Finding | Was parked because | Fixed |
|---|---|---|
| A2-02 (https-only live redirect link) | It waited on UPayments support question 12. The sandbox Apple Pay link is `http`, so an https-only rule could block all live Apple Pay. The change (T10) was reverted from the charge-path unit. | Batch 4, revised by the Q12 evidence (`.superpowers/hardening/upayments-answers.md`, Q12): every sandbox hosted-page link was https; the documented white-label wallet links are `http://sandboxapi.upayments.com/get-pay-by-*`, and that host 301-redirects to https. In live mode `CheckoutPayload::normalize_upayments_redirect_url()` now accepts https, upgrades an http link on `upayments.com` or a subdomain to https, and rejects any other non-https link with the generic failure and no recorded attempt. Live mode is anything but an explicit sandbox `getMode() === true`. Sandbox is unchanged. There is still no host allowlist for https links, because the live hosted-page hosts are not documented. |

## Recorded, not fixed

| Item | Reason |
|---|---|
| A1-08 (new-card subscriptions never renew) | Moot under the A1-01 gate. It must be part of any design that opens the gate. |
| A3-04 (historical enrollment scans every paid order with OFFSET) | Moot under the A1-01 gate, which gates enrollment. Re-raise to MEDIUM in the gate-opening design. |
| A1-11 | Fixed in batch 5 (owner instruction 2026-10-25): the field default is `no`, so only a genuinely fresh install (no settings option) sends paid orders to Processing. A stored option keeps its value; one without the key keeps the official 3.1.1 `yes`, shown as such on the settings screen. No stored value changes meaning. A whole-array settings write (the settings screen's Save, or code that saves the gateway settings array) stores that `yes` explicitly. Upgraded and taken-over stores are unchanged. |
| A3-05 | The proposed lazy gateway resolution is unsafe: the gateway constructor registers runtime hooks, and Blocks `initialize()` guarantees the gateway exists on every request. A compatibility-sensitive refactor for a few ms. |
| A3-09 | The `wp_cache_flush()` after raw-SQL option writes is a deliberate correctness measure. It is rare and owner-initiated. "Run off-peak" copy goes to the revamp. |
| A4-08 | Fixed in batch 5 with no new packaged file: one restrict-only reader, `Utils::productDisablesSubscription()`, also reads the opt-out on the WPML original product (`wpml_original_element_id`). No `wpml-config.xml`. |
| A4-09 | The owner deferred it to the revamp. |
| A5-03 (full `save()` during copy, removal and finalize) | The proposed meta-only save is unsafe on HPOS with compatibility sync. Only its disclosure was done (T3): preflight reports that each order is saved once. |
| A6-06 (PHPStan scope and stubs) | **Fixed in batch 6** (owner instruction 2026-10-25). PHPStan now analyses all 118 `src/` files at level 5 against `php-stubs/wordpress-stubs` 6.9.4 and `php-stubs/woocommerce-stubs` 10.8.1 (exact `require-dev` pins at the WP 6.9 / WC 10.8 floors), with `treatPhpDocTypesAsCertain: false`, no baseline and no `ignoreErrors`. Batch 4 measured 70 errors on the old scope and about 200 on all of `src/`. With the flag set and the 15 hand-written stubs retired, the start point was **89 errors**. The final count is **0**. The 89 were cleared as follows. 21 came from upstream stub gaps and are cleared by eight documented PHPDoc corrections (`tests/phpstan/stub-corrections.stub`). 12 were missing third-party symbols and runtime-defined constants (`tests/phpstan/external-symbols-stubs.php`). 17 were private seams that harnesses call through reflection; a reviewed allowlist extension clears them, and the harness keeps the allowlist honest. 3 were genuinely dead private `Gateway` delegates, now removed. 15 were analyser-proven redundant conditions, removed with no runtime change. 11 needed precise PHPDoc (`@phpstan-impure`, by-reference closure variables) or an explicit string cast. The last 10 are covered by 8 one-line `@phpstan-ignore` comments, each with a reason; with the existing one there are 9 in `src/` (ceiling 15). No real defect beyond the batch 4 H12-META was found. The static-analysis harness fails if a `src/` file is not analysed. |
| A6-08 (Classic lists a dead option) | **Closed by design** (batch 5). The Classic listing follows settings eligibility by contract. A dead option fails closed at place-order with one notice (A6-01). It carries into the revamp checkout redesign as a design input. Batch 5 re-verified it before closing: the only fix (drop the gateway on a cached invalid availability state) changes the Classic listing for a settings-valid store, and the R6 browser cell (`certification-key`, live mode, the provider refuses, no last-known-good) asserts that Classic shows UPayments on its second render; the listing would also flip with the 65 s cache. |
| A5-01 Scheduler divergence hold | It must land with any future gate-opening change. While the A1-01 gate is closed, `Scheduler::process_parent_order()` sends nothing. |
| A3-07 autoload change | It touches the raw-SQL CAS writers and the `alloptions` invalidation. One non-autoloaded `SELECT` is not worth that risk. The decode memo was fixed (#219). |
| A6-07 deletion | Only the `@deprecated` docblocks were done. Deleting the compatibility methods is recorded for Sub-project 5. |

### Recorded LOW residuals

| Residual | Source | Reason recorded |
|---|---|---|
| Customer retries add duplicate merchant notes | A1-03 (#220) review | Fixed in batch 4: one note per order for the same currency and decimals (`_supcheckout_minor_unit_noted_v1`). |
| Lock release deletes the lock without a token check | A1-01 (#216) review | Fixed in batch 4: compare-and-delete of the holder's own record (`OrderLock::delete_if_current()`). |
| Unreadable stored total under the lock gave `binding_changed_under_lock` | OL-1 + R-1 review | Resolved in batch 2 by final-review M-1: it is now retryable on both paths. |
| A lost event freezes the retry budget (N-1) | U2 review | Fixed in batch 5: the sweep unschedules an event overdue by more than an hour, and the bounded scheduling resumes from the stored attempt count. A refused unschedule is not logged as removed; the sweep still re-checks the order. Note: under WP-Cron starvation, a WP-Cron run that loaded its event list before the sweep removed the event then unschedules an event that is already gone; the cron option write changes nothing, so core may log one harmless `could_not_set` cron error for that event. No payment state is involved, and the reconcile path's rate gates still apply. |
| A transient gateway miss skips the A1-09 note forever (N-2) | U2 review | Fixed in batch 4: the flag is set only on permanent outcomes. |
| The snapshot harness counts by hook name (N-3) | U2 review | Fixed in batch 4: attributed by callback. |
| A third party that renders `payment_fields()` in the same request as `process_payment()` reuses the display availability (up to 10 min old) | U5 report | WooCommerce's Classic and Blocks flows don't do this. Same behaviour as before, with older freshness. |
| Per-order reconcile events are kept on deactivation | U8 ruling | Removing them would orphan queued one-shot secondary checks. |
| A4-05 rest (physical offsets, chevron, save-card switch) | U6 | Fixed in batch 5: logical properties in every front-end stylesheet, template and the Blocks script, and an inline-axis switch; the template harness bars physical left/right. The chevron needed no change: it is U+203A, which Unicode marks bidi-mirrored, so browsers already draw it mirrored in RTL. A `scaleX(-1)` rule added in batch 5 turned it backwards and was removed in review (I-1); the harness now requires U+203A at all six sites and no transform on `.upay-chevron`. |
| Other front-end assets still version by `Identity::VERSION` | final-review M-5 | Fixed in batch 4: every front-end enqueue site uses `CheckoutAssets::asset_version()`. The wp-admin gateway-settings assets keep the plugin version. |
| Renewal and enrollment line-item reads call `get_product()` without an `instanceof WC_Order_Item_Product` guard (`Scheduler.php:1022`, `:1327`, `HistoricalEnrollment.php:185`) | Batch 6 review (A6-06 stub run) | LOW, pre-existing. `get_items('line_item')` returns product items, but the `woocommerce_order_get_items` filter lets third-party code change that. The auto-deduct path stays gated by A1-01, so none of these lines can send a charge. The `src/` lines were left unchanged in batch 6, which was static analysis only. |

## Owner manual and staging items

- Set AED to **2 decimals**, and use '.' as the decimal separator (O5; A1-03 now fails a 3-dp AED amount closed before the Charge).
- Translate the gateway description in WPML String Translation (`admin_texts_woocommerce_gateways`). This is the likely cause of O2, and the report-only `wpml_gateway_strings_legacy_only` setup check flags it.
- Confirm staging email blocking (O6, "New order failed to send").
- Do a native Arabic review of the bundled translations (U7 drafted them by machine).
- At the live cutover, check the LiteSpeed/Cloudflare purge. The automatic purge is covered by the real-plugin CI cell (`CachePurgeRuntimeTest`).
- Send the 14 UPayments support questions (questions 11 to 14 were added during the fix loop). Question 12 has an evidence-based answer, which batch 4 used to fix A2-02; the live hosted-page and wallet hosts remain worth confirming.
- After batch 4 (H12-META), check save-card, selected-card and subscription checkout on staging. These flows need UPayments white-label, so the check is deferred until UPayments enables white-label on the merchant account. Until then the H12-META evidence is the unit, harness and real-WooCommerce integration tests.

Other observations carried to the revamp: O7 (FunnelKit upsell support, a payment-sensitive feature that needs owner approval). O4 ("UPayments payment ID" copy) is fixed in batch 4.

## Package at the batch 4 head

`supcheckout-0.1.0.zip`: **233 files**, SHA-256 `2a8e1aba7e056151a5995c9147eccfd2700efe866ac7b8c36ed87f2b17def6db`.

- **Build:** `bash scripts/build-release.sh` from Git Bash, run twice at the batch 4 head after the rebase onto `main` `dfa461e`. The two archives are byte-identical, and `scripts/verify-release.sh` verified the archive.
- **Last commit that changes distributable bytes:** `46cf129` (AS-1). Batch 4 adds no file; it changes bytes in `src/` and `languages/`.
- **Batch 3 package (#223, historical):** 233 files, SHA-256 `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8`, built at the batch 3 code commit `bab56a8`. The owner verified it on staging on 2026-10-24. It is superseded by the batch 4 runtime change.
- **Batch 2 package:** 233 files, SHA-256 `8b441501d52d3abcde176eebffda5e4261e9a1dcda0209290fcda878c26c0196`. It is superseded by the batch 3 runtime change.
- **Acceptance:** not owner accepted. Release Candidate 1 remains the owner-accepted technical baseline.

## Gates at the close

Local gates at the batch 4 head (Windows, Git Bash):

- `vendor/bin/phpunit`: OK, 1469 tests and 14909 assertions. 7 tests are skipped by design (the A1-01 gate-open companions). `tests/unit/Subscription` alone: OK, 132 tests.
- `composer quality`: exit 0 (PHPStan no errors, phpcs clean).
- All 61 PHP harnesses and all 6 JS harnesses: 0 failures. Release Artifact 125/0 (run from Git Bash), H12 PHP 2010/0, Phase 9I preflight 129/0 and executor 59/0, provider payment lifecycle 618/0, security threat model 106/0, takeover identity coverage 27/0, public surface PASS, H12 Blocks FAIL 0.
- `npm run quality` and `npm run audit:full` with the pinned Node 24.21.0: exit 0 (the audit gate passes, with 7 advisories, all allow-listed development-only).
- Two deterministic builds: byte-identical, 233 files, the SHA-256 above. `scripts/verify-release.sh` verified the archive.
- Not run locally: real-WordPress integration (including `HistoricalMetaCardinalityRuntimeTest`), the Compatibility, Ecosystem, Admin UI, Takeover and Provider Sandbox workflows, and CodeQL.

Exact-head CI runs at the batch 4 PR, and the merge gate in `AGENTS.md` applies unchanged.

Historical: local gates at the batch 3 head with this record (Windows, Git Bash):

- `vendor/bin/phpunit`: OK, 1450 tests and 14705 assertions. 7 tests are skipped by design (the A1-01 gate-open companions).
- `composer quality`: exit 0.
- All 61 PHP harnesses and all 6 JS harnesses: 0 failures. The security threat-model harness reports 106 PASS / 0 FAIL.
- Two deterministic builds: byte-identical, 233 files, the SHA-256 above. `scripts/verify-release.sh` verified the archive.

Exact-head CI runs at the batch 3 PR, and the merge gate in `AGENTS.md` applies unchanged.
