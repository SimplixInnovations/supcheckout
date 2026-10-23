# Security Threat-Model Closure

> **Historical closed-gate record with permanent security invariants.** Repository/product/current-gate wording below reflects the verified security closure at that time. Current SUCheckout state is owned by `PROJECT-STATUS.md`; the security rules remain binding unless a later reviewed contract explicitly supersedes them.

**Status:** DONE / VERIFIED

**Canonical repository:** `SimplixInnovations/simplixpay-upayments`

**Threat-model base:** verified closure `main` `08054a93c619f3c34fef747a6e530abce1e8986e`

**Base tree:** `5d11a9a7e72e110b12298a0d11b0e3740a6476f3`

**Implementation PR:** #17

**Final reviewed implementation head:** `fba12225899c3e01d6b23a6bba2f757a3b5f6a4a`

**Verified squash merge on `main`:** `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9`

**Verified merge tree:** `e0027005f059fad03d8c08273b7aac6553c45f53`

**Merge parent:** `08054a93c619f3c34fef747a6e530abce1e8986e`

**GitHub signature:** **VERIFIED**

**Post-merge Quality Gates:** run #89 — **SUCCESS**

**Implementation branch:** **deleted after verified merge**

**Closure reconciliation PR:** #18

## Purpose

Close concrete security boundaries across the now-frozen ordinary payment lifecycle, H12 token identity, Phase 9I migration, subscription/customer actions, administration, external transport, rendering/logging and repository supply chain without turning the phase into an unauditable big-bang refactor.

This gate is not a claim of broad penetration-test certification, PCI certification, ecosystem compatibility, or public-production readiness. It is an engineering threat-model closure: enumerate meaningful assets and trust boundaries, fix verified P0/P1 security defects and bounded high-confidence defense-in-depth issues, add executable regression contracts, record unresolved provider/feature constraints, and leave a precise continuation record.

## Security principles

The gate follows the repository's existing fail-closed rules plus current WordPress security guidance:

- an object ID is never authorization;
- a WordPress nonce is CSRF intent evidence, not authorization;
- state-changing customer actions require both authorization and nonce verification;
- validation/rejection is preferred over permissive coercion;
- database/provider values remain untrusted when rendered and are escaped for their exact output context;
- public/browser/provider callback data is never payment truth by itself;
- provider credentials never cross to a browser or unapproved host;
- non-idempotent financial mutations are never blindly retried after an indeterminate outcome;
- security-sensitive compatibility behavior may be retained only behind an explicit authenticated/authorized boundary;
- third-party checkout dependencies are treated as supply-chain/privacy trust boundaries, not visual implementation details.

## Protected assets

1. UPayments API bearer credential and environment selection.
2. WooCommerce order ownership, order key and order lifecycle state.
3. Provider payment/track/customer/card tokens.
4. H12 identity secret, scope/generation and provenance.
5. Phase 9I migration result/checkpoint ledgers.
6. Subscription state, stored recurring-payment evidence and cycle journal.
7. Merchant gateway settings, IBAN/charge routing configuration and administrative actions.
8. Customer PII and order/payment metadata.
9. Repository/update/CI authority and immutable release identity.

## Actors and trust boundaries

| Actor / boundary | Trusted for | Not trusted for |
|---|---|---|
| Anonymous browser | routing to public endpoints | order ownership, payment truth, arbitrary object access |
| Logged-in customer | own WooCommerce account/order actions | another customer's orders/tokens; provider truth |
| WooCommerce manager/admin | merchant configuration within capabilities | bypassing provider/payment invariants; raw secrets in logs/public output |
| UPayments authenticated API response | provider-side transaction evidence after strict binding | arbitrary redirect hosts; undocumented semantics beyond frozen contract |
| UPayments browser/webhook callback | lookup/routing cursor only | payment success/failure authority by itself |
| WP-Cron | dispatch of locally scheduled bounded work | permission to duplicate ambiguous financial mutations |
| Database/object cache | persisted state to validate | automatically trusted security evidence merely because it is stored |
| GitHub Actions dependencies | CI execution at reviewed immutable commit pins | floating mutable tags/branches |
| Third-party frontend CDN | none required for payment correctness | checkout execution/style dependency by default |

## Entry-point census

### Public / customer

- `woocommerce_api_wc_upayments` browser return, provider webhook and historical status poll;
- Classic checkout POST fields;
- WooCommerce Store API / Blocks extension data;
- My Account subscription pause/resume/unsubscribe actions;
- My Account order filtering and subscription display;
- thank-you/order display metadata;
- saved-card rendering/retrieval for authenticated users.

### Privileged

- WooCommerce payment-gateway settings save;
- migration admin submenu (`manage_woocommerce` + nonce);
- product custom subscription metadata save;
- order-admin diagnostics/rendering;
- WP-CLI migration command.

### Background / machine

- `simplixpay_upayments_reconcile_order` bounded payment-status reconciliation;
- `upay_process_subscriptions` subscription scheduler and cycle journal;
- outbound UPayments Charge/Create/Retrieve/Status/auto-deduct transports;
- GitHub Actions quality workflow.

## Findings and disposition

### SEC-01 — Public historical order-status poll accepted order ID as authority

**Severity:** P1
**State:** DONE / VERIFIED

The inherited `?wc-api=wc_upayments&get_order_status=...&wc_order_id=N` path delegated around the hardened payment lifecycle and read `UPayments_WHS` by numeric post/order ID without an order key, exact logged-in ownership, or even an UPayments payment-method preflight.

This was an IDOR/privacy boundary defect even though the returned field was narrow. Numeric object identifiers are enumerable and cannot grant access.

Closure contract:

- hardened priority-5 callback intercepts the legacy poll before inherited priority 10;
- request is read-only GET;
- strict positive decimal order ID;
- target must be an UPayments order;
- access requires either exact logged-in owner or exact WooCommerce order key;
- guest compatibility is preserved only via order key;
- status output is allowlisted to `wait|pending|failed|completed|cancelled`;
- unknown/malformed stored values collapse to `wait`;
- unauthorized/nonexistent/non-UPayments targets share a generic unavailable response;
- inherited gateway method delegates to the same boundary as defense in depth.

### SEC-02 — Subscription state changes used nonce-bearing GET URLs

**Severity:** P1
**State:** DONE / VERIFIED

Before remediation, the handler already checked exact logged-in order ownership and an action-specific WordPress nonce. That prevented ordinary cross-user IDOR and CSRF. The remaining defect was that pause/resume/unsubscribe were state-changing operations represented as GET URLs, so browsers, prefetchers, link scanners, history replays or copied URLs could re-request a valid nonce-bearing URL.

Closure contract:

- mutations use POST only;
- links become POST forms with hidden action/order ID and action-specific nonce;
- exact owner authorization remains mandatory;
- target must be an UPayments order;
- target must be a real non-auto-deduction subscription with a recognized plan/interval;
- transition must be valid for current subscription state;
- nonce is checked only after object/owner preflight;
- redirects remain local WooCommerce account URLs.

### SEC-03 — Third-party CSS/font dependencies loaded on checkout

**Severity:** P2
**State:** DONE / VERIFIED

Before remediation, Classic checkout requested Google Fonts and cdnjs Font Awesome. Neither was required for payment correctness; both added third-party availability, privacy and supply-chain boundaries on the payment page.

Closure contract:

- remove those external checkout stylesheet requests;
- use site/system typography;
- replace Font Awesome chevrons in both classic and Blocks renderers with local text/CSS presentation;
- do not add a replacement remote asset dependency.

### SEC-04 — Plain persisted/provider values permitted broader HTML than necessary

**Severity:** P2
**State:** DONE / VERIFIED

Before remediation, several legacy display/settings surfaces used broader output handling than their data contract warranted, including plain provider payment status/ID through `wp_kses_post()` and stored multimerchant values echoed into attributes without explicit `esc_attr()`.

Closure contract:

- plain payment metadata uses `esc_html()`;
- stored setting values in HTML attributes use `esc_attr()`;
- checkout templates use explicit GET markers instead of `$_REQUEST`;
- customer/provider data is never upgraded into trusted HTML merely because it came from storage.

### SEC-05 — Product custom-meta callback relied only on WooCommerce upstream save ordering

**Severity:** P2 defense in depth
**State:** DONE / VERIFIED

WooCommerce's product meta-box pipeline verifies `woocommerce_meta_nonce` against `woocommerce_save_data`, binds `post_ID`, checks `edit_post`, then fires `woocommerce_process_product_meta`. Before remediation, the plugin callback was therefore behind upstream authorization, but because it performed its own write it still needed to defend its boundary explicitly rather than relying solely on hook provenance.

Closure contract mirrors WooCommerce's save preconditions locally:

- WooCommerce nonce present and valid;
- posted product ID exactly matches callback ID;
- current user has `edit_post` for that product;
- field value is unslashed and sanitized before persistence.

## Verified existing controls retained

The security gate must not regress already-reviewed protections:

- `PaymentLifecycle` intercepts the historical callback at priority 5 before inherited priority 10;
- callback request values merge GET/POST conflict-safely and exclude cookies/`$_REQUEST`;
- browser/webhook fields are routing evidence only;
- `StatusVerifier` validates exact HTTPS UPayments host/path before applying the bearer token, disables redirects, verifies TLS and uses finite timeout;
- authenticated provider transaction binds track, provider order ID, Woo order reference, currency and exact canonical decimal amount;
- capture uses WooCommerce `payment_complete()` and cannot resurrect refunded/verified orders;
- indeterminate outcomes reconcile boundedly and never issue a new Charge;
- per-order lifecycle lock prevents concurrent state mutation/TOCTOU;
- H12 customer/card token identity and Phase 9I attribution contracts remain frozen;
- migration admin requires `manage_woocommerce` and nonce; CLI has no API-key argument and execute requires explicit confirmation;
- diagnostic gateway logging is opt-in and complex payloads are omitted;
- GitHub Actions in the quality workflow remain immutable full-SHA pins and run with read-only contents permission.

## Explicit boundaries not falsely closed here

### Provider webhook signature/HMAC

The reviewed public UPayments material has not established a complete stable signature-header/canonicalization/key contract. This gate must not invent one. Ordinary webhook/browser callback input remains non-authoritative: paid/failed/cancelled state is derived only after authenticated Get Payment Status and exact local binding.

A future first-party signature contract may add webhook-level authentication as defense in depth without replacing authenticated status verification.

### Subscription auto-deduction

The historical scheduler has substantial independent H12 safety work: persistent per-cycle claims, claimed→dispatching boundary before POST, no automatic retry after ambiguous dispatch, held-state recovery and persistence verification. However its provider response semantics are not the closed ordinary-checkout lifecycle contract and it retains historical token/order evidence by design.

This security gate records that surface and protects its no-duplicate-charge journal invariants; it does **not** turn auto-deduction into broadly feature-certified recurring billing. Provider semantics, identity upgrade behavior and end-to-end subscription certification remain later feature-certification work.

### Automatic refunds

Still unsupported. The security gate must not add a mutating refund transport before a durable provider idempotency/reconciliation contract exists.

## Required executable security gate

`tests/harness/security-threat-model-harness.php` is permanent once this gate merges. It covers:

- public order-status ID parsing, exact owner/order-key authorization and status allowlist;
- priority-5 interception and defense-in-depth delegation;
- POST-only subscription mutation, owner/nonce/order/subscription preflight;
- removal of remote checkout font/icon dependencies;
- plain-data escaping contracts;
- explicit Woo product-meta save authorization;
- preservation of provider status host/TLS/redirect/Bearer controls;
- preservation of migration capability/nonce controls;
- immutable full-SHA GitHub Actions pins;
- existence and base anchor of this security control record.

It must run inside the permanent `H12 Regression Harness` job together with every existing Phase 0, Phase 9I, Provider Lifecycle and H12 regression suite.

Final verified implementation-head security characterization: **81 PASS / 0 FAIL**. The exact cleaned PR #17 head `fba12225899c3e01d6b23a6bba2f757a3b5f6a4a` passed full merge-ref Quality Gates run #88; merged `main` `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9` passed the complete push-triggered workflow again in run #89.

## Merge/closure process

1. implement only the characterized bounded fixes above;
2. run the security harness plus complete existing regression stack;
3. open PR from the exact verified base lineage;
4. inspect exact diff and automated review findings;
5. resolve every valid finding and rerun the exact final merge-ref;
6. squash-merge pinned exact head only;
7. verify `main`, tree, sole parent, signature, branch deletion and push-triggered full Quality Gates;
8. only then create the separate security closure reconciliation tranche;
9. closure tranche updates `PROJECT-STATUS.md`, `NEW-CHAT-HANDOFF.md`, the living Master Engineering Playbook, README/changelog/roadmap/audit and this file to exact post-merge truth;
10. closure merge must itself receive review, full regression and post-merge verification before Security Threat-Model Closure becomes DONE / VERIFIED.

## Verified implementation closure evidence

- implementation PR: **#17**;
- final reviewed implementation head: `fba12225899c3e01d6b23a6bba2f757a3b5f6a4a`;
- exact final merge-ref Quality Gates run #88: **SUCCESS**;
- final security harness: **81 PASS / 0 FAIL**;
- one valid automated P2 review finding (Checkout Blocks Font Awesome dependency) was fixed with permanent Blocks regression coverage before final approval;
- squash merge on `main`: `01f3fc59eed8641b3e5372558f61a7a0f0cdfac9`;
- tree: `e0027005f059fad03d8c08273b7aac6553c45f53`;
- sole parent: `08054a93c619f3c34fef747a6e530abce1e8986e`;
- GitHub signature: **VERIFIED**;
- push-triggered post-merge Quality Gates run #89: **SUCCESS**;
- implementation branch: **deleted after verified merge**;
- closure reconciliation: **PR #18**, documentation/control-plane only, with no payment/runtime behavior change.

The bounded threat-model gate is therefore closed. This remains deliberately narrower than penetration testing, PCI/compliance certification, broad platform/feature certification, performance certification, or public-production readiness.

## New-chat continuity requirement

This record is a required clean-chat source after implementation merges. A new conversation must be able to reconstruct, without hidden memory:

- exact latest verified `main` SHA/tree;
- exact security implementation/closure PRs;
- security harness counts;
- fixed findings and frozen invariants;
- unresolved provider/feature boundaries;
- current next gate: **Enterprise Compatibility Certification**, after verified closure of Quality Platform Q1-Q19.

`PROJECT-STATUS.md` remains the first current-state authority, with `NEW-CHAT-HANDOFF.md` as the concise operational restart. The Master Engineering Playbook living state must agree with both while dated historical sections remain historical.

## Addendum 2026-10-23: current entry-point census

> Dated addendum (hardening program, finding A2-04). The sections above are the historical closure record and stay unchanged. This census lists the entry points in `src/` at the hardening close (branch `hardening/batch-3`). An external pentest is scoped from this census and `docs/security/PENTEST-SCOPE.md`. A governance test (`tests/unit/Governance/CurrentProjectStateRegressionTest.php`) fails when a `supcheckout/v1` route in `src/Rest/Routes.php` is missing from it.

Current security harness (`tests/harness/security-threat-model-harness.php`): **106 PASS / 0 FAIL**. The historical 81 PASS above is the closure-time count.

One correction to "Security principles" above: the API key was echoed into the settings HTML and the gateway REST read (A2-01). It is now write-only. The settings field renders blank, a blank save keeps the stored key, and the WooCommerce payment-gateway REST response blanks it.

### REST: `supcheckout/v1` (`src/Rest/Routes.php`)

Every route:
- requires `manage_woocommerce` (`RequestGuard::can_manage`);
- runs capability, nonce and unknown-parameter checks at `rest_request_before_callbacks`, before core reports a schema error, including on the namespace index;
- returns `Cache-Control: no-store, private`, and never sends an exception message.

Every write also:
- requires the explicit `wp_rest` `X-WP-Nonce`, so Application Passwords alone cannot write;
- takes only typed JSON-schema arguments, with consent flags as booleans that must be strictly `true`;
- is rate limited through a CAS bucket;
- only starts one single-flight Operation, which Action Scheduler runs.

Consent to deactivate or reactivate the official plugin, and a real finalize, additionally require `activate_plugins`. No route marks, unmarks or re-verifies a payment.

| Route | Paused boot | Rate bucket |
|---|---|---|
| `GET /supcheckout/v1/onboarding/status` | yes | none |
| `GET /supcheckout/v1/onboarding/preflight` | yes | none |
| `POST /supcheckout/v1/onboarding/preflight` | yes | `provider_probe` |
| `POST /supcheckout/v1/onboarding/switch` | yes | `switch` |
| `POST /supcheckout/v1/onboarding/rollback` | yes | `rollback` |
| `POST /supcheckout/v1/onboarding/abort` | yes | `abort` |
| `POST /supcheckout/v1/onboarding/finalize` | yes | `finalize` (`confirm` enum `FINALIZE`) |
| `POST /supcheckout/v1/onboarding/stale-claims/release` | yes | `stale_claims` (at most 500 rows under the lease) |
| `GET /supcheckout/v1/onboarding/self-test` | no | none |
| `POST /supcheckout/v1/onboarding/self-test` | no | `provider_probe` |
| `GET /supcheckout/v1/setup/checks` | no | none |
| `POST /supcheckout/v1/setup/checks` | no | `provider_probe` |

The Store API (Blocks checkout) is WooCommerce's REST surface. SUPCheckout adds its payment-method data, and the `StaleGatewayChoice` filter at `rest_request_before_callbacks` maps a stale legacy gateway choice on checkout requests.

### WC-API (public, unauthenticated)

| Hook | Listeners | Notes |
|---|---|---|
| `woocommerce_api_wc_upayments` (route `wc_upayments`, permanent) | `PaymentLifecycle::handle_callback` at priority 5; the inherited `Gateway::check_ipn_response` at 10 | Browser return, provider webhook and the historical status poll (`PublicOrderStatus`). Callback data is a lookup cursor only. Paid state needs authenticated Get Payment Status, bound to the order, track, amount, currency and the persisted order total (R-1). Not registered while SUPCheckout is paused. |
| `woocommerce_api_supcheckout` (route `supcheckout`) | `LegacyCallbackListener` mirrors the same pair at 5 and 10 | Same treatment as the legacy route. Registered before cutover, so Preflight can prove it reachable. |
| `supcheckout_probe=1` on `woocommerce_api_supcheckout` | `LegacyCallbackListener::handle_probe` at 5, on the full and the paused boot | Unauthenticated. Reads only that argument. Returns a 200 with no-store and `X-SUPCheckout-Probe`, then exits. It touches no order, writes nothing, calls no provider and schedules nothing. |

### AJAX and admin-post

- No `wp_ajax_*` or `wp_ajax_nopriv_*` handler is registered.
- No `admin_post_*` handler is registered.

### Other request handlers (front end and wp-admin)

| Surface | Guard |
|---|---|
| Classic checkout POST (`process_payment`) and `woocommerce_checkout_posted_data` (`StaleGatewayChoice`) | WooCommerce checkout nonce. The finalized order amount and currency are the payment authority. A1-03 fails closed on too many decimals before the Charge. |
| My Account subscription pause, resume and unsubscribe (`SubscriptionActions` on `init`, POST `upay_action`) | POST only, logged-in order owner, nonce, and only manual UPayments subscription orders. |
| Gateway settings save (`woocommerce_update_options_payment_gateways_upayments`) | WooCommerce settings capability and nonce. The API key is write-only (A2-01), and the save result shows as an admin notice (A6-02). |
| Product subscription metadata save (`woocommerce_process_product_meta`) | Explicit product edit authorization (SEC-05). |
| Migration admin submenu (`manage_woocommerce`) | `check_admin_referer`. |
| Plugin activation (`ReactivationGuard`: `pre_update_option_active_plugins`, `pre_update_site_option_active_sitewide_plugins`, `activate_plugin`, `activated_plugin`) | Refuses reactivating the official plugin on a switched store, and refuses network activation on multisite (A5-01). For an activation it lets through, it stops SUPCheckout's sweep (R1-2). |
| Deactivation hook (`Bootstrap\Deactivation`) | Removes only SUPCheckout's own schedules. It never touches `upay_process_subscriptions` and deletes no data. |

### WP-CLI

- `wp supcheckout takeover` (`src/Takeover/Cli.php`): `preflight`, `run`, `status`, `release-stale-claims`, `rollback`, `abort` and `finalize`. Registered on the full and the paused boot.
- `wp simplixpay-upayments migration` (`MigrationBootstrap`, the frozen Phase 9I command).

### WP-Cron

| Hook | Kind | Notes |
|---|---|---|
| `upay_process_subscriptions` (protected; twin `supcheckout_process_subscriptions` from `copying` on) | hourly | Subscription scheduler. Under the A1-01 gate, `Scheduler::process_parent_order()` sends no auto-deduct. |
| `simplixpay_upayments_reconcile_order` (twin `supcheckout_reconcile_order`) | single event | Bounded payment-status reconciliation (at most 4 attempts). |
| `simplixpay_upayments_reconcile_secondary` (twin `supcheckout_reconcile_secondary`) | single event | Bounded capture-only check of an earlier attempt's track. |

### Action Scheduler (group `supcheckout`)

| Hook | Kind | Notes |
|---|---|---|
| `supcheckout_reconciliation_sweep` | recurring, hourly | Fair-rotation sweep of unverified orders (U2). Ensured from `admin_init` and `action_scheduler_init` behind a 12 h throttle. Unscheduled on deactivation, on rollback and abort, and on a manual official-plugin activation. |
| `supcheckout_onboarding_drive` | single | Drives one single-flight onboarding Operation. |
| `supcheckout_onboarding_watchdog` | single | Finishes a stalled operation as `scheduler_unavailable`. |
| `supcheckout_takeover_copy_batch` | single | Bounded copy batch, with a 20 s budget per action. |
| `supcheckout_process_due_parent` | single | Due-parent subscription worker, under the A1-01 gate. |

SUPCheckout also listens on two of Action Scheduler's own hooks:
- `action_scheduler_init`: the copy watchdog and the sweep ensure;
- `action_scheduler_before_process_queue`: the lazy registration of the onboarding driver on the paused boot.

### Outbound

These are the UPayments transports:
- Charge;
- Create Customer Token;
- Retrieve Cards;
- Get Payment Status;
- check-payment-button-status;
- auto-deduct.

The host, TLS, redirect and Bearer controls are unchanged. A2-02 (an https-only live payment link) is parked on UPayments support question 12.
