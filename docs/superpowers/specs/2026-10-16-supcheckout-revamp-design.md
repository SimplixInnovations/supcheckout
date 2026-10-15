# SUPCheckout Revamp — Design Specification

- **Date:** 2026-10-16
- **Status:** Draft, awaiting owner review
- **Owner decisions:** recorded in the brainstorming session of 2026-10-16. The section on decisions below lists each one.
- **Supersedes:** the AGENTS.md "first-stable bootstrap exception" (`supcheckout/UPayments.php`) and the protected-identity list. Both get amended when Sub-project 0 lands; the protected identities are migrated by the approved switch.

---

## 1. Purpose and audience

SUPCheckout for UPayments is a WooCommerce payment plugin for **UPayments merchants worldwide**. It launches first on the owner's store, My Organic World: FunnelKit checkout, WPML Arabic/English, WCML multi-currency, and a non-white-label KNET account.

This revamp turns the hardened plugin into a premium product with five qualities:

- **Uncompromising payment integrity.** Paid state comes only from UPayments' authenticated status, bound to the order's finalized amount and currency.
- **A checkout that looks native on every store.** It is fully adaptive to the store's theme.
- **A fully branded, premium admin app** that helps merchants get paid, see problems and fix them.
- **Seamless onboarding.** New merchants get validated setup. Merchants on the official UPayments plugin get a safe, reversible switch.
- **First-class compatibility** with WPML, WCML multi-currency, LiteSpeed Cache, Cloudflare, AJAX-heavy and builder themes, FunnelKit, and both Classic and Blocks checkout.

### Success criteria

1. A merchant on the official plugin (2.x or 3.x) switches without losing any of the following, verified by the migration matrix and a rehearsal on the staging copy with 1,000+ real orders:
   - a historical order;
   - an in-flight payment;
   - a webhook;
   - a subscription;
   - a saved card;
   - a WCML or FunnelKit setting.
2. A new merchant reaches "live" through the wizard, with every precondition verified.
3. The checkout matches the theme on the certified theme set, in light, dark and RTL, within the performance budget.
4. Every quality gate stays green, the deterministic package stays reproducible, and an independent security review closes every sub-project.

### Non-goals

- Other payment providers. SUPCheckout stays UPayments-only.
- Automatic WooCommerce refunds. They remain unsupported until UPayments documents a refund contract and the owner approves it separately.
- Webhook signature verification. Payment truth keeps coming from the authenticated status lookup until UPayments publishes a signing contract.

---

## 2. Owner decisions

| Topic | Decision |
|---|---|
| Audience | All UPayments merchants worldwide. First live store: My Organic World. |
| Front end | Fully adaptive. Components and look and feel match each store's theme automatically. |
| Back end | A fully branded, premium, creative SUPCheckout admin app with a top-level menu. |
| Admin technology | React on `@wordpress/scripts`, with our own design-token component library, light/dark and RTL. |
| Features | Payments command center, revenue recovery, health monitor and alerts, conversion boosters. |
| Main file | Rename to `supcheckout/supcheckout.php` now, before the first public release. |
| Data identities | Fully rename to `supcheckout` names, via a smart, verified, reversible switch. |
| Rollback | One-click rollback for **30 days** after a switch. Cleanup happens only on an explicit "finalize". |
| Migration sources | Official UPayments plugin **3.x and 2.x**, each certified in CI. |
| Quality controls | Retarget the permanent controls (Q1–Q19 and the architecture harnesses) to the new structure. Keep their intent; remove nothing. |
| Communication | State clearly in the readme, the listing, the first-run screen and the docs that official-plugin users get the smart switch and new users need no migration. |

---

## 3. Architecture

Four layers communicate only through defined interfaces.

### 3.1 Payment core (existing, hardened)

This layer covers checkout orchestration, callback routing, status verification, order locks, reconciliation, saved cards and subscriptions. It moves into namespaced classes; its contracts and behavior stay as they are.

The other layers read from the core through its interfaces and never reimplement payment logic. A money-affecting action, such as a pay link or a re-verification, calls into the core and goes through the same verified lifecycle as checkout.

### 3.2 Platform services (new, PHP, `src/`)

- **Event journal.** A dedicated, indexed, append-only table. It records payment events: attempt started, provider rejected (with the sanitized reason), callback received, captured, failed, duplicate capture, reconciled, pay link sent, and admin action (with the acting user).
  - It has bounded retention, configurable with a default of 18 months.
  - It never stores card data, tokens, API keys or provider secrets.
  - The dashboard, health monitor and recovery read only from it, never by scanning orders.
- **Health service.** Scheduled checks that are rate-limited, cached and explained in plain language. Each check returns green, amber or red with an evidence line and a fix.
- **Recovery service.** Creates pay-by-link Charges for eligible unpaid orders. It uses the core orchestrator and therefore the core's verification. Reminders are optional, rate-limited and opt-in per merchant.
- **Migration and onboarding service.** The preflight, switch, verification, rollback and finalize steps of Section 5.

### 3.3 Admin REST API (new)

- **Namespace:** `supcheckout/v1`.
- **Permissions:** every route requires `manage_woocommerce`. Writes require a REST nonce.
- **Input and output:** input is validated against a JSON schema, and output is paginated and bounded.
- **Rate limits:** money-affecting routes are rate-limited.
- **Writes:** no write route mutates payment state directly; each one calls a core service.

### 3.4 Two front ends

- **Admin app (branded).** React, built with `@wordpress/scripts` and code-split per screen. It loads only on SUPCheckout screens and is styled entirely through design tokens (Section 4.1).
- **Checkout UI (adaptive).** Classic checkout uses no framework. Blocks checkout uses a React integration only where WooCommerce Blocks requires it. Styling is derived from the theme (Section 4.2).

### 3.5 Code layout

- `supcheckout/supcheckout.php` is a bootstrap of about 40 lines that does four things:
  - checks requirements;
  - registers the PSR-4 autoloader for `Simplixi\SUPCheckout\`;
  - pauses safely while the official plugin is active;
  - boots the container.
- **No global functions or classes** that can collide with any UPayments plugin version. The only exception is the WooCommerce-required gateway class registration, which happens through a namespaced class.
- **One responsibility per file.** Source files aim for 200–400 lines, with a soft ceiling of 800.
- **Deterministic package.** `.distignore` and the release builder keep producing a deterministic package; the file count changes are recorded.

---

## 4. Design system and theming

### 4.1 Admin: branded tokens

- **Tokens for everything.** Every color, font, size, spacing step, radius, shadow, elevation and motion timing is a named CSS custom property (`--sup-*`). Components never use raw values.
- **Branding as one file.** Applying the owner's brand kit means filling in one token file, with no component changes.
- **Light and dark** are designed together. The default follows the WordPress admin scheme and the system preference, with a manual toggle.
- **RTL and Arabic** from day one: logical properties, a font stack with proper Arabic support, and mirrored icons only where they carry direction.
- **Accessibility:** WCAG 2.2 AA. Contrast is checked automatically in both modes, with full keyboard flow, visible focus and reduced-motion support.

### 4.2 Checkout: adaptive

1. **Block themes:** derive tokens from the `theme.json` presets (palette, typography, spacing, radius).
2. **Classic themes:** derive them at runtime from the computed styles of the theme's own form controls: the primary button, the text input and the border.
3. **Light/dark** follows the store's actual rendered background.
4. **Fallback:** WooCommerce's neutral defaults, never SUPCheckout branding.
5. **Merchant control:** an optional per-store fine-tune panel in the admin overrides any derived token.

---

## 5. Sub-project 0: clean core and smart switch

### 5.1 Clean core

- Rename the main file to `supcheckout/supcheckout.php`. The rename is safe now because the plugin has never been released; the only cost is reactivation on staging and test installs.
- Move every responsibility out of the legacy main file into namespaced classes.
- Remove all legacy globals: the 13 functions and `WC_Upayments`. The coexistence wrapper exception from PR #146 then becomes unnecessary and is retired.
- Retarget the permanent quality controls (about 52 test and CI files reference `UPayments.php`). Each control keeps its rule and checks the new files. Nothing is removed or softened.

### 5.2 Renamed identities

| Legacy (official plugin) | SUPCheckout |
|---|---|
| gateway ID `upayments` | `supcheckout` |
| `woocommerce_upayments_settings` | `woocommerce_supcheckout_settings` |
| `UPayments_*`, `_upay_*` order meta | `_supcheckout_*` |
| `?wc-api=wc_upayments` | `?wc-api=supcheckout` |
| `upay_process_subscriptions` and billing-attempt state | `supcheckout_process_subscriptions` |
| token identity secret and provenance options | `supcheckout_*`, with the H12 contract preserved under the new names |
| availability, rate-gate and lock options | `supcheckout_*` |

### 5.3 Unavoidable bridges

1. **Inbound legacy callback listener.** Payments created before the switch carry `wc_upayments` in URLs stored at UPayments, and UPayments may call them later.
   - SUPCheckout keeps an inbound-only listener on that route. It accepts callbacks only for orders created before the switch timestamp and routes them to the same verified handler.
   - New Charges never use it.
   - The listener is permanent, because removing it would lose real payments.
2. **Third-party settings that store "upayments".**
   - **Known integrations** are translated by the switch: WCML gateway country and currency rules, FunnelKit's gateway lists, WooCommerce's gateway order and its Payments settings.
   - **Unknown references:** the preflight scans the whole options table for `upayments` and lists every remaining reference in its report, so custom code and exports are never missed silently.

### 5.4 Switch lifecycle

1. **Detect.** Identify the official plugin, its version (2.x or 3.x) and its data footprint.
2. **Preflight (read-only).** Produces a report covering:
   - the credential and account mode, verified live with UPayments;
   - currencies against the account's capabilities;
   - pending orders with open payment links;
   - subscriptions and their next billing dates;
   - saved-card token identities;
   - WCML and FunnelKit settings;
   - the options-table scan;
   - whether the callback URL is reachable from the internet;
   - cache, CDN and Cloudflare exclusions.

   Blocking items must be resolved before the switch.
3. **Copy, never move.** Copy settings, options and order meta to the new names in resumable, idempotent batches through Action Scheduler. Legacy data stays untouched. Reads fall back to legacy fields until an order is verified as copied, so checkout keeps working throughout.
4. **Verify.** Compare every copied order field by field. A single mismatch stops the switch, records the order in the report and leaves the store on the official plugin.
5. **Switch.** One click, with the owner's consent. It swaps the active gateway with zero downtime: SUPCheckout activates in the paused state, the official plugin is deactivated, and the next request runs SUPCheckout. It also reschedules the subscription hooks under the new names.
6. **Self-test.** Check callback reachability, a sandbox status call if test mode is on, the gateway's registration and availability, and the event journal.
7. **Rollback (30 days).** One click restores the official plugin, the legacy gateway ID and the legacy settings from the recorded mapping. This is non-destructive because legacy data was never removed.
8. **Finalize.** Explicit, and allowed only after 30 days or on the owner's command. It removes the copied-over legacy data the plugin owns. The inbound legacy listener stays.

### 5.5 New merchants

There are no migration screens. The wizard validates:

- the credential and account mode;
- currencies;
- callback reachability;
- cache, CDN and Cloudflare exclusions;
- the WPML/WCML setup;
- an optional sandbox test payment.

---

## 6. Admin app

**Navigation:** top-level **SUPCheckout** menu.

1. **Home (Command Center)**
   - revenue and conversion by method (KNET, cards, Apple Pay, Google Pay, Samsung Pay) for today, this month, and against the prior period;
   - a live event feed;
   - "needs attention" cards: failures with UPayments' sanitized reasons, items awaiting reconciliation, and health alerts.
2. **Payments**
   - a fast, filterable list built from the journal;
   - a per-payment timeline: attempt → provider response → callback → verified capture → order state, with evidence;
   - actions: send a pay link, re-verify with UPayments, open the order.
3. **Recovery**
   - failed, abandoned and phone orders that are still collectable;
   - pay-by-link (copy, email, WhatsApp-ready text);
   - optional timed reminders;
   - recovered-revenue tracking.
4. **Health**
   - green/amber/red checks, each with a plain-language fix: connectivity, credentials, account mode, currencies, callback reachability, cache/CDN/Cloudflare, WPML/WCML consistency, cron and Action Scheduler;
   - email alerts on red.
5. **Settings**
   - grouped and searchable: Connection, Payment methods, Checkout appearance (fine-tune), Subscriptions, Multi-merchant, Notifications, Advanced;
   - live validation against UPayments where possible.

**First-run wizard:** full screen and resumable. The steps are Welcome (branches into the switch or the new-merchant path), Connect, Checks, Test payment (optional), and Go live.

**Premium details:**
- skeleton loading;
- money-affecting actions always confirm and show the verified result;
- a command palette (⌘K);
- reduced-motion-aware motion;
- empty states that teach;
- full Arabic/RTL.

**Security in the UI:**
- `manage_woocommerce` on every screen;
- the API key is write-only and never shown back;
- every money action is journaled with the acting user.

---

## 7. Checkout experience and compatibility

### 7.1 UX

- **Method list:** only the methods the account and currency support, ordered by the customer's country and currency (KNET first for Kuwait).
- **Express wallets** where the account and device allow.
- **Saved cards** as chips showing the brand and last 4 digits only.
- **After a decline:** the customer stays on the order with the cart intact, sees a translated explanation, and can retry or switch method.
- **Return from UPayments:** the customer always lands on the right page with a translated status. A "confirming your payment…" state covers a webhook that is a few seconds behind.
- **Accessibility:** keyboard and screen-reader support, focus moved to errors, RTL.

### 7.2 Compatibility contract

| Area | Contract |
|---|---|
| WPML / WCML | Every string is translatable. Gateway title and description go through WPML string translation. Amounts are bound to the finalized order currency and total. Per-currency and per-country availability is respected. |
| LiteSpeed Cache, Cloudflare, page caches | No customer-specific markup in cached HTML; dynamic state is fetched over AJAX. Callback and return URLs send `no-store`. Health detects and explains missing exclusions. |
| AJAX-heavy and builder themes, FunnelKit, CheckoutWC | Integrates with WooCommerce's checkout-update events and re-initializes safely after fragment refreshes. Never double-binds. Survives delayed and combined scripts. Assets load only on checkout pages. |
| Classic and Blocks | Same behavior and the same adaptive look in both. |

### 7.3 Performance budget

- under 15 KB of checkout JS and CSS combined (gzipped);
- zero added database queries on non-checkout pages;
- no checkout render blocked by a provider call;
- the admin app is code-split per screen, and REST responses are paginated and cached where safe.

---

## 8. Security, stability and testing

- **Security**
  - capability checks, nonces, schema validation, rate limits and escaped output on every new surface;
  - no secrets in logs, notes, the journal or the browser;
  - optional encryption at rest for the API key;
  - an audit trail for money-affecting actions;
  - CodeQL, Plugin Check (strict) and the dependency audit stay merge-blocking;
  - an independent security review at the end of each sub-project.
- **Stability**
  - background work runs through Action Scheduler, with idempotent jobs and bounded retries;
  - every migration step is resumable and reversible;
  - the journal and health data are bounded and indexed.
- **Testing**
  - test-first (proven RED → GREEN) for all behavior;
  - unit tests, plus integration tests on real WordPress and WooCommerce;
  - the existing 20-cell compatibility matrix, plus new cells: WPML/WCML, LiteSpeed Cache, a Cloudflare-style cache simulation, AJAX and builder themes, FunnelKit;
  - the migration matrix: official plugin 2.x and 3.x; HPOS on and off; every order status, subscriptions, saved cards, multi-currency, WCML and FunnelKit settings; in-flight payments and mid-migration webhooks; interrupted and re-run batches; rollback; a 10,000-order store;
  - visual-regression and accessibility tests for the admin app in light, dark and RTL;
  - Playwright end-to-end checkout flows in sandbox;
  - a staging rehearsal on the production copy before any live switch.

---

## 9. Delivery

Prerequisite: the open hardening PRs are merged and the remaining bug list is closed.

| # | Sub-project | Exit gate |
|---|---|---|
| 0 | Clean core: rename, namespaced architecture, retargeted controls, smart switch and onboarding services, migration matrix | Migration matrix green; staging switch rehearsal with rollback |
| 1 | Design-token system and admin component library (light/dark/RTL) | Visual-regression and accessibility suites green |
| 2 | Admin app: wizard, Command Center, Payments, Health, Settings | End-to-end admin flows green; security review |
| 3 | Adaptive checkout redesign (Classic and Blocks) and compatibility certification | New compatibility cells green; performance budget met |
| 4 | Features: Recovery and pay-by-link, alerts, conversion boosters | End-to-end recovery flows in sandbox; security review |
| 5 | Brand kit applied, final security review, live acceptance, release | Owner technical acceptance; live 16-scenario acceptance |

Each sub-project gets its own implementation plan, focused PRs and exact-head green CI.

---

## 10. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A third-party integration keyed on gateway `upayments` breaks after the rename | Known integrations are translated; the options-table scan lists every unknown reference before the switch; 30-day rollback |
| A late webhook for a pre-switch payment | Permanent inbound legacy listener, scoped to pre-switch orders, through the same verified handler |
| A partial migration | Copy-not-move, resumable idempotent batches, per-order verification, and an automatic stop on any mismatch |
| The quality controls lose strength in the retargeting | Each control keeps its rule; the retargeting is reviewed control by control and recorded |
| Branding arrives late | Branding is a single token file applied in Sub-project 5; nothing waits on it |
