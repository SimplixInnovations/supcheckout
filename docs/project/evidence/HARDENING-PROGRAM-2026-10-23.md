# Hardening program: close record (2026-10-23)

**Status:** repository-executable fix loop **DONE / VERIFIED** (batches 1 to 3).
**Next gate:** owner staging verification of the hardened package.
**Publication:** NOT AUTHORIZED. Automatic recurring `VERIFIED_SUCCESS` stays FAIL-CLOSED.

This record closes the hardening program that the owner put before the revamp. The per-unit ledgers (scope, RED/GREEN evidence, review rounds) are in the PR bodies of #215, #221 and #222 and in the batch 3 commit. This file keeps the program-level facts.

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
| Batch 3 | branch `hardening/batch-3` (R1-1, R1-2 and this close record) | pending its PR | runs at its PR |

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

## New findings raised during the fix loop

- **H12-META (batch 4, from the A6-06 stub run; open).** `CustomerTokenIdentity::get_historical_meta_cardinality()` reads `$all_meta[0]` and calls `WC_Meta_Data::get_value()`. Real WooCommerce (checked against 11.2.1 source) returns the meta entries with their original array keys, and `WC_Meta_Data` has no `get_value()` method. So on a real store a present key usually reads as duplicate/invalid (fail closed), and throws an `Error` when the entry is the order's first meta row. The test fixtures define a `get_value()` that WooCommerce lacks. It changes H12 token classification, so it needs its own characterized unit and review; it was not changed in batch 4.

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
| A1-11 | Owner policy that matches the official 3.1.1 behaviour. Changing the default for an absent setting would silently change upgraded stores. To be surfaced in onboarding at the revamp. |
| A3-05 | The proposed lazy gateway resolution is unsafe: the gateway constructor registers runtime hooks, and Blocks `initialize()` guarantees the gateway exists on every request. A compatibility-sensitive refactor for a few ms. |
| A3-09 | The `wp_cache_flush()` after raw-SQL option writes is a deliberate correctness measure. It is rare and owner-initiated. "Run off-peak" copy goes to the revamp. |
| A4-08 | Nothing writes `_upay_disable_subscription`, subscriptions are gated, and a new packaged file would change the deterministic package for a theory-only chain. |
| A4-09 | The owner deferred it to the revamp. |
| A5-03 (full `save()` during copy, removal and finalize) | The proposed meta-only save is unsafe on HPOS with compatibility sync. Only its disclosure was done (T3): preflight reports that each order is saved once. |
| A6-06 (PHPStan scope and stubs) | No runtime defect. Stubs change `composer.json`/`composer.lock` and trigger every workflow, so they come as one tooling PR. Batch 4 attempted it and stopped under its own rule (no level drop, no large baseline): with `php-stubs/wordpress-stubs` 6.9.4 and `php-stubs/woocommerce-stubs` 10.8.1 at level 5, the current scope alone reports 70 errors and all of `src/` reports about 200, mostly defensive checks the real stubs prove redundant, in PaymentLifecycle, CheckoutOrchestrator, CustomerTokenIdentity and Scheduler. The run also surfaced one real H12 defect, recorded as a new finding below. |
| A6-08 (Classic lists a dead option) | It changes Classic gateway listing behaviour that FunnelKit and the compatibility cells exercise. After A6-01 it fails closed with one notice. To be revisited at the revamp. |
| A5-01 Scheduler divergence hold | It must land with any future gate-opening change. While the A1-01 gate is closed, `Scheduler::process_parent_order()` sends nothing. |
| A3-07 autoload change | It touches the raw-SQL CAS writers and the `alloptions` invalidation. One non-autoloaded `SELECT` is not worth that risk. The decode memo was fixed (#219). |
| A6-07 deletion | Only the `@deprecated` docblocks were done. Deleting the compatibility methods is recorded for Sub-project 5. |

### Recorded LOW residuals

| Residual | Source | Reason recorded |
|---|---|---|
| Customer retries add duplicate merchant notes | A1-03 (#220) review | Fixed in batch 4: one note per order for the same currency and decimals (`_supcheckout_minor_unit_noted_v1`). |
| Lock release deletes the lock without a token check | A1-01 (#216) review | Fixed in batch 4: compare-and-delete of the holder's own record (`OrderLock::delete_if_current()`). |
| Unreadable stored total under the lock gave `binding_changed_under_lock` | OL-1 + R-1 review | Resolved in batch 2 by final-review M-1: it is now retryable on both paths. |
| A lost event freezes the retry budget (N-1) | U2 review | Bounded: the sweep re-checks every 6 h. |
| A transient gateway miss skips the A1-09 note forever (N-2) | U2 review | Fixed in batch 4: the flag is set only on permanent outcomes. |
| The snapshot harness counts by hook name (N-3) | U2 review | Fixed in batch 4: attributed by callback. |
| A third party that renders `payment_fields()` in the same request as `process_payment()` reuses the display availability (up to 10 min old) | U5 report | WooCommerce's Classic and Blocks flows don't do this. Same behaviour as before, with older freshness. |
| Per-order reconcile events are kept on deactivation | U8 ruling | Removing them would orphan queued one-shot secondary checks. |
| A4-05 rest (physical offsets, chevron, save-card switch) | U6 | The revamp replaces this checkout UI. |
| Other front-end assets still version by `Identity::VERSION` | final-review M-5 | Fixed in batch 4: every front-end enqueue site uses `CheckoutAssets::asset_version()`. The wp-admin gateway-settings assets keep the plugin version. |

## Owner manual and staging items

- Set AED to **2 decimals**, and use '.' as the decimal separator (O5; A1-03 now fails a 3-dp AED amount closed before the Charge).
- Translate the gateway description in WPML String Translation (`admin_texts_woocommerce_gateways`). This is the likely cause of O2, and the report-only `wpml_gateway_strings_legacy_only` setup check flags it.
- Confirm staging email blocking (O6, "New order failed to send").
- Do a native Arabic review of the bundled translations (U7 drafted them by machine).
- At the live cutover, check the LiteSpeed/Cloudflare purge. The automatic purge is covered by the real-plugin CI cell (`CachePurgeRuntimeTest`).
- Send the 14 UPayments support questions (questions 11 to 14 were added during the fix loop). Question 12 has an evidence-based answer, which batch 4 used to fix A2-02; the live hosted-page and wallet hosts remain worth confirming.

Other observations carried to the revamp: O4 ("UPayments payment ID" copy) and O7 (FunnelKit upsell support, a payment-sensitive feature that needs owner approval).

## Package at the batch 3 head

`supcheckout-0.1.0.zip`: **233 files**, SHA-256 `f86744833a196a6eef7bcff5f79bd179face94dd0ad42b7f90bb5c6d091502d8`.

- **Build:** `bash scripts/build-release.sh` from Git Bash, run twice. The two archives are byte-identical.
- **Scope:** this close record changes no distributable byte, because `docs/`, `tests/`, `.ai-architect/` and `AGENTS.md` are distignored. So the package equals the one built at the batch 3 code commit `bab56a8`.
- **Batch 2 package:** 233 files, SHA-256 `8b441501d52d3abcde176eebffda5e4261e9a1dcda0209290fcda878c26c0196`. It is superseded by the batch 3 runtime change.
- **Acceptance:** not owner accepted. Release Candidate 1 remains the owner-accepted technical baseline.

## Gates at the close

Local gates at the batch 3 head with this record (Windows, Git Bash):

- `vendor/bin/phpunit`: OK, 1450 tests and 14705 assertions. 7 tests are skipped by design (the A1-01 gate-open companions).
- `composer quality`: exit 0.
- All 61 PHP harnesses and all 6 JS harnesses: 0 failures. The security threat-model harness reports 106 PASS / 0 FAIL.
- Two deterministic builds: byte-identical, 233 files, the SHA-256 above. `scripts/verify-release.sh` verified the archive.

Exact-head CI runs at the batch 3 PR, and the merge gate in `AGENTS.md` applies unchanged.
