# SUPCheckout UX Contract

## Product context

- **Audience:** WooCommerce shoppers and authorized WooCommerce store operators.
- **Primary jobs:** choose a UPayments method, submit through WooCommerce, understand verified payment state, manage bounded subscription actions, configure the gateway, and run explicit historical migration tooling.
- **Target market(s):** UPayments commerce integrations; market-specific business rules remain in provider/product evidence, not inferred from locale.
- **Active locales:** WordPress active locale; Arabic RTL is explicitly exercised by repository browser certification.
- **Language/content register and native-review policy:** plain, transactional, non-promotional. Plugin-owned copy must use the `supcheckout` text domain; JavaScript copy is server-localized. Native-language review is required before claiming production-quality copy for a newly added locale.
- **Timezone/calendar policy:** WordPress/WooCommerce timezone and date abstractions are authoritative unless a provider field explicitly requires another contract.
- **Accessibility target:** WCAG 2.2 AA for plugin-owned UI. Automated repository coverage is bounded to axe WCAG A/AA plus project-owned semantic/focus tests; local final verification supplies the remaining interaction review.

## Business-context sources

| Domain / scope | Authoritative source | Source type | Reviewed date |
|---|---|---|---|
| Payment lifecycle / financial truth | `docs/project/PROVIDER-PAYMENT-LIFECYCLE.md`, `src/Payment/PaymentLifecycle.php`, `src/Payment/StatusVerifier.php` | maintained contract + runtime invariant | 2026-09-28 |
| Provider authentication | `docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md` | first-party provider research | 2026-09-28 |
| Release scope | `docs/project/RELEASE-SCOPE-DECISION.md`, `docs/project/PROJECT-STATUS.md` | maintained release policy | 2026-09-28 |
| Architecture / protected identities | `.ai-architect/architecture-contract.yaml` | architecture contract | 2026-09-28 |
| Migration behavior | `docs/project/PHASE-9I-MIGRATION.md`, `src/Migration/*` | migration contract + runtime | 2026-09-28 |
| Localization / RTL certification | `docs/project/I18N-MULTICURRENCY-CERTIFICATION.md`, `tests/e2e/r6-browser-ux.spec.ts` | certification evidence | 2026-09-28 |

## Visual contract

- **Project `DESIGN.md`:** root `DESIGN.md`.
- **Token ownership model:** checkout, account and legacy admin: existing runtime CSS/platform primitives are canonical. React admin app: the generated token layer (`assets/admin/src/tokens/*.tokens.json` → `tokens.generated.css`, `--sup-*` under `.supcheckout-admin`) is canonical. `DESIGN.md` mirrors durable values and rationale for both.
- **Runtime design-system/token source:** WooCommerce/theme + WordPress admin. Plugin-owned additions are in `assets/css/customer.css`, `assets/css/new-design.css` and `assets/css/admin-style.css` (`--supcheckout-*`). The React admin app uses `assets/admin/src/tokens/tokens.generated.css` (`--sup-*`), built into `assets/admin/build/app.css` / `app-rtl.css`.
- **Mapping/export/adapters:** checkout and legacy admin have no generated token layer; a durable token change updates `DESIGN.md` and its runtime CSS owner together. The admin app's generated layer comes from `tools/admin-ui/tokens/build-tokens.mjs`, which is deterministic and committed. `--check` fails on drift, on any WCAG 2.2 AA contrast failure in light or dark across the nine admin schemes, and on an invalid or inaccessible `brand.tokens.json`. Admin CSS must pass `findCssViolations`: tokens only, logical properties, scoped selectors.
- **Token drift gate:** review changed CSS against `DESIGN.md`. For the admin app, `npm run quality` (token `--check`, the CSS discipline test, stylelint) and `admin-ui-certification.yml` enforce it. Browser screenshots/axe/focus checks remain the runtime proof.
- **Supported themes:** host-theme rendering is inherited; repository ecosystem certification covers the maintained free-theme matrix. Paid/vendor themes remain external.
- **Design-context owner/review policy:** changes to customer/admin interaction behavior require this contract, relevant business source, and project tests to move together.

## Canonical UI Map

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Select/Listbox | Native HTML/WooCommerce select | This contract + checkout/account implementations | native | keyboard + locale + browser |
| Form | WooCommerce checkout / WordPress admin form + server validation | WooCommerce lifecycle + migration contract | checkout / admin operation | integration + browser |
| Toast | SUPCheckout polite atomic status region | `templates/new-design-form.php` + `assets/js/upayments-block.js` | classic / Blocks | accessibility unit + browser |
| Select (admin) | Native `<select>` styled with `--sup-*` tokens (`Select`) | `assets/admin/src/ui/controls/Select.tsx` | native only; no custom listbox | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| Dialog | `Dialog` on Base UI Dialog, in the scoped portal layer | `assets/admin/src/ui/overlays/Dialog.tsx` | dismissible / `dismissible={ false }`; sizes | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| ConfirmDialog (money) | `ConfirmDialog` on Base UI AlertDialog: the money-safety primitive | `assets/admin/src/ui/overlays/ConfirmDialog.tsx`, `DESIGN.md` "Money-confirmation contract" | tones `money` / `danger` / `neutral`; optional `actionKey` | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual (exactly-once `onConfirm`) |
| Toast (admin) | `useToast` (SupRoot renders the toast viewport), a polite region in the portal layer | `assets/admin/src/ui/feedback/Toast.tsx` | success / info (timed) / error (persistent) | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| Tooltip | `Tooltip` on Base UI Tooltip (WCAG 1.4.13) | `assets/admin/src/ui/feedback/Tooltip.tsx` | description / label (`IconButton showTooltip`) | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| Tabs | `Tabs` on Base UI Tabs (roving focus, RTL arrows) | `assets/admin/src/ui/overlays/Tabs.tsx` | automatic / manual activation | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| DataTable | `DataTable`: semantic `<table>`, caller-owned sort | `assets/admin/src/ui/overlays/DataTable.tsx` | default / compact density; loading; empty | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |
| CommandPalette | `CommandPalette` (modal Dialog + lazy Base UI Autocomplete panel); yields Mod+K to core `core/commands` | `assets/admin/src/ui/overlays/CommandPalette.tsx` | trigger button; Mod+K only where core's palette is absent | Jest + jest-axe in four modes; `admin-ui-certification.yml` browser (axe, keyboard) on WP 6.9.11 and 7.1.4 + visual |

## Component behavior

| Component | Default | Hover | Focus | Active | Disabled | Busy | Error |
|---|---|---|---|---|---|---|---|
| Payment method | bordered full-width control | subtle border/shadow | visible `focus-visible` | explicit selected state | WooCommerce-owned when checkout is unavailable | submission delegated to WooCommerce | WooCommerce notice/recovery |
| Save-card toggle | unchecked unless explicit consent | host behavior | native checkbox focus via associated label | checked only for eligible logged-in card flow | unavailable when feature/user eligibility fails | n/a | fail closed to unsaved |
| Toast/status | hidden | n/a | n/a | polite atomic live region | n/a | bounded display duration | message must describe recovery/state |
| Unsubscribe confirmation | collapsed disclosure | button hover | visible summary/button focus | expanded consequence + explicit confirm | absent for ineligible/cancelled/auto-deduct cases | form submit | server validation/nonce failure |
| Admin migration form | preflight default | host admin | native/admin focus | execute requires explicit confirmation | capability-gated | bounded server operation | visible WordPress notice with localized rejection |

## Dataset navigation

- **Admin tables:** WordPress/WooCommerce owns general table behavior.
- **Exploratory lists:** WooCommerce My Account owns order pagination.
- **URL state:** account subscription filter is a read-only query parameter with a strict allowlist.
- **Page size:** host WooCommerce owns it.
- **Empty/no-results/error/loading treatment:** host behavior unless plugin-specific state is present.
- **Back/scroll restoration:** host browser/WooCommerce behavior.
- **Selection scope:** no plugin-owned bulk table selection in current release scope.

## Flow ledger

| Operation | Trigger | Pending | Success destination | Success feedback | Failure recovery | Focus outcome | Source ref |
|---|---|---|---|---|---|---|---|
| Choose payment method | real method button/radio/select | WooCommerce controls submission availability | WooCommerce checkout | selected state / normal checkout lifecycle | WooCommerce validation notice | remains within checkout control flow | `assets/js/new-upay.js`, `assets/js/upayments-block.js` |
| Save card consent | associated checkbox | state update only | current checkout | localized state/toast where used | fail closed to not saving | checkbox remains operable | checkout JS + saved-card contracts |
| Unsubscribe | “Unsubscribe” disclosure then explicit “Confirm unsubscribe” | normal form submit | current order/account flow | server-owned resulting state | nonce/authorization/server rejection | disclosure/action remains keyboard reachable | `src/Subscription/Presentation.php` |
| Pause/resume | explicit account action | normal form submit | current order/account flow | updated subscription state | server rejection/no state promotion | submitted action | `src/Subscription/Presentation.php` |
| Migration preflight/execute | WordPress admin form | bounded server operation | same admin page | redacted result output | localized notice; safe form values retained | document flow | `src/Migration/MigrationAdmin.php` |
| Provider callback/status | provider/browser route | fail closed while status is indeterminate | WooCommerce order result | verified status only | retry/reconciliation according to payment lifecycle | n/a | `src/Payment/PaymentLifecycle.php`, `StatusVerifier.php` |

## Navigation and responsive behavior

- **Route document title policy:** WordPress/WooCommerce owns page titles and chrome.
- **Route error / 403 behavior:** WordPress/WooCommerce owns global errors; migration capability denial uses WordPress `wp_die`.
- **Breadcrumb/tab/route-state policy:** host platform owns it.
- **Sidebar/drawer/bottom-sheet transformation:** no plugin-owned global navigation surface.
- **Responsive table strategy:** WooCommerce account table behavior is preserved.
- **Truncation/full-value access:** do not truncate payment/subscription identity or action labels without another accessible path.
- **Focus restoration and sticky-obstruction policy:** plugin controls must remain visible and keyboard reachable; plugin code must not create focus traps.

## Overlays and feedback

- **Dialog primitive:** checkout and account have none: destructive subscription confirmation is an owned inline disclosure, and native JS dialogs are prohibited everywhere. The React admin app's primitive is `Dialog` (Base UI Dialog: focus moves in, Tab stays in, the page behind is inert, focus returns to the opener), and `ConfirmDialog` (Base UI AlertDialog) for consequential actions.
- **Destructive confirmation levels:** unsubscribe requires a second explicit action with consequence copy; routine pause/resume does not add an extra confirmation. In the admin app, `ConfirmDialog` has three levels: `money` (refunds, charges and anything that moves money: `onConfirm` runs exactly once per opening, the dialog cannot be dismissed while pending, and only a caller result of `verified` reads as done), `danger` (irreversible but not monetary) and `neutral`. Screens pass `actionKey` when they may remount the dialog, and bound `onConfirm` so that a timeout maps to `pending`.
- **Toast placement/duration/deduplication:** authored status live region, bounded duration; no financial truth is conveyed only by toast. Admin toasts: block end / inline end, moving away from the focused element (WCAG 2.4.11); success and info close after 6 s (paused on hover, focus and a background window), errors persist; at most 3 visible.
- **Alert/banner scope and persistence:** WooCommerce notices for checkout; WordPress notices for migration/admin.
- **Tooltip delay/dismissal:** checkout has none. Admin `Tooltip`: 400 ms hover delay, immediate on keyboard focus, Escape dismisses it without moving focus, hoverable and persistent (WCAG 1.4.13); nothing essential lives only in a tooltip, and a disabled control gets none.
- **Unsaved-changes behavior:** checkout state follows WooCommerce; migration form does not autosave.
- **Layer/z-index contract:** checkout has no plugin-owned modal/drawer/popover stack; the checkout toast remains above the local checkout surface only. The admin app renders every overlay in one scoped portal layer at `--sup-z-portal` (100000), above the WordPress admin bar (99999); the layer mirrors the app root's theme, direction, language and density.

## Async and resilience

- **Mutation default:** pessimistic/fail-closed for financial mutations.
- **Idempotency and duplicate-submit policy:** no blind retry of non-idempotent Charge/refund/auto-deduct mutations; payment buttons delegate through WooCommerce and respect disabled submission state.
- **Auto-save/draft recovery:** not applicable.
- **Offline/read-stale/write behavior:** do not imply payment success; surface/reconcile through WooCommerce/provider lifecycle.
- **Retry/backoff/timeout behavior:** bounded by maintained payment/subscription contracts.
- **Version conflict and multi-tab behavior:** no client-side optimistic financial authority.
- **Session expiry/re-authentication:** host WordPress/WooCommerce behavior; saved-card use fails closed when identity/eligibility is unavailable.
- **Long-running progress and return path:** migration is bounded and returns redacted result state on the same admin screen.
- **Stale-request cancellation/invalidation:** checkout uses current WooCommerce store/fragment state; delayed script behavior is separately characterized.
- **Dialog/form preservation after failure:** unsubscribe uses normal form semantics; migration retains submitted safe form values.

## Validation

- **Schema/validation layer:** server-side PHP validation is authoritative; WooCommerce validates checkout.
- **Trigger timing:** submission, not speculative client-only acceptance.
- **Error summary/inline policy:** WooCommerce/WordPress notices plus explicit migration rejection notice.
- **Server error mapping:** payment ambiguity fails closed; migration exposes only safe internal reason codes.
- **Sensitive-value handling:** API keys/tokens are never displayed in migration UI or toast/log content.
- **Form behavior:** admin migration declares `novalidate` and relies on canonical server validation; duplicate financial submission is prevented by existing lifecycle/locking rules.

## Permission and clipboard

- **Permission UI strategy:** migration route requires `manage_woocommerce`; unauthorized users receive a WordPress-owned denial.
- **Clipboard copy policy:** no plugin-owned secret-copy control in current release UI.
- **Disabled-state explanation:** feature-dependent controls are removed/disabled according to explicit eligibility, not shown as false affordances.

## Verification

- **Required static command:** `composer quality`; for the React admin app also `npm run quality` (lint, typecheck, unit with jest-axe, token and contrast `--check`, build contract, build and drift).
- **Browser matrix:** repository R6 Playwright covers Classic + Blocks at desktop/mobile plus Arabic RTL; local final verification expands success/failure/keyboard/locale/theme/reduced-motion review.
- **Accessibility checks:** axe A/AA, focus visibility, semantic unit contracts, decorative icon checks, live-region checks.
- **Native-language/domain review:** required before claiming a newly added locale is production-quality; Arabic layout direction is automated but copy quality remains a separate human-language review.
- **Component-state/visual regression:** R6 screenshots for Classic/Blocks/RTL plus source/unit contracts. Admin app: `.github/workflows/admin-ui-certification.yml` (static, Windows build and drift, browser and accessibility suite on WP 6.9.11 / WC 10.8.1 and WP 7.1.4 / WC 11.1.2 in four modes, exact visual regression on WP 7.1.4, and the gate).
- **Canonical sibling flow:** WooCommerce core checkout/account/admin behavior.
- **Project audit:** Frontend Design Premium strict audit must be run locally against the final candidate.
- **Failure-path evidence:** `tests/e2e/r6-browser-ux.spec.ts` plus payment lifecycle/integration harnesses.
