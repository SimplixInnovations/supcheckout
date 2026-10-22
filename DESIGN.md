---
version: alpha
name: "SUPCheckout for UPayments"
description: "WooCommerce-native payment and account UI that stays subordinate to the merchant theme while making payment state and actions explicit."
colors:
  checkout-text: "#1B1D21"
  control-border: "#D9D9D9"
  action-blue: "#007CBA"
  destructive: "#E12C2C"
  warning: "#FFB703"
  surface: "#FFFFFF"
  muted-surface: "#F9F9F9"
  admin-border: "#C3C4C7"
  admin-canvas: "#F5F6F8"
  admin-surface: "#FFFFFF"
  admin-text: "#16191D"
  admin-text-muted: "#59606B"
  admin-text-subtle: "#676E79"
  admin-hairline: "#D9DCE1"
  admin-control-border: "#858B96"
  admin-accent-default: "#2271B1"
  admin-dark-canvas: "#121519"
  admin-dark-surface: "#1A1E23"
  admin-dark-text: "#E9ECEF"
  admin-dark-text-muted: "#A4ACB6"
  admin-dark-text-subtle: "#88919C"
  admin-dark-hairline: "#323840"
  admin-dark-control-border: "#6B737E"
typography:
  sans:
    fontFamily: "inherit"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
  admin-sans:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, \"Noto Sans\", sans-serif"
    fontSize: "14px"
    lineHeight: "1.5"
  admin-arabic:
    fontFamily: "system-ui, \"Segoe UI\", \"Noto Sans Arabic\", \"Geeza Pro\", Tahoma, sans-serif"
    lineHeight: "1.7"
    letterSpacing: "0"
  admin-mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Consolas, \"Liberation Mono\", monospace"
rounded:
  DEFAULT: "6px"
  sm: "3px"
  md: "6px"
  lg: "10px"
  admin-control: "6px"
  admin-card: "10px"
  admin-overlay: "12px"
  admin-pill: "999px"
spacing:
  compact-gap: "8px"
  control-gap: "10px"
  panel-padding: "15px"
  status-padding: "18px 20px"
  admin-grid: "4px"
  admin-control-height: "36px"
  admin-control-height-compact: "32px"
  admin-target-min: "24px"
components:
  payment-method: {}
  status: {}
  toast: {}
  subscription-confirmation: {}
  admin-form: {}
  admin-dialog: {}
  admin-confirm-dialog: {}
  admin-toast: {}
  admin-tooltip: {}
  admin-tabs: {}
  admin-data-table: {}
  admin-app-shell: {}
  admin-command-palette: {}
---

# SUPCheckout for UPayments Design System

## Overview

### Creative North Star

SUPCheckout should feel like a **WooCommerce-native payment instrument panel**, not a standalone SaaS application pasted into checkout. Merchant theme and WordPress/WooCommerce chrome own the surrounding visual language; SUPCheckout adds only the minimum provider-specific structure needed to make payment choices, financial state, saved-card consent, subscription controls, and migration operations legible and safe.

### Product context and register

- **Audience and primary job:** WooCommerce shoppers choose a UPayments method and complete payment; store operators configure the gateway and, when explicitly needed, run bounded migration tooling.
- **Target market(s) and evidence:** UPayments/GCC commerce is the product context, while WordPress/WooCommerce is the host platform. Locale is not used as a proxy for market policy.
- **Locale(s) and language policy:** All plugin-owned visible and accessible copy uses the WordPress active locale and the `supcheckout` text domain. JavaScript interaction copy is server-localized. Arabic RTL is a repository-certified layout direction; untranslated hard-coded customer-facing English is not permitted.
- **Usage scene:** Checkout is high-consequence and time-sensitive on desktop and mobile; account/admin tools are lower-frequency operational surfaces.
- **Register:** Hybrid product UI. Checkout/account surfaces inherit merchant/WooCommerce styling. Admin surfaces inherit WordPress admin patterns.
- **Memorable signature:** A restrained, full-width payment-method row that keeps provider iconography, method name, selected state, and downstream WooCommerce checkout action visually coherent.
- **Restraint:** Financial authority, accessibility, host-theme compatibility, and stable control geometry always outrank decoration or animation.
- **Anti-references:** Do not make SUPCheckout look like an independent dashboard, marketing landing page, glassmorphic widget, or bespoke theme that fights WooCommerce.
- **Token ownership/runtime mapping:** There are two owners, split by surface.
  - **Checkout, account and the legacy admin screens:** existing runtime CSS and WordPress/WooCommerce primitives remain canonical. Runtime owners are `assets/css/customer.css`, `assets/css/new-design.css`, and `assets/css/admin-style.css`, using `--supcheckout-*` custom properties.
  - **The React admin app (Sub-project 1 onward):** the generated token layer is canonical. Its source of truth is `assets/admin/src/tokens/*.tokens.json` plus `contrast-pairs.json`. `tools/admin-ui/tokens/build-tokens.mjs` writes `assets/admin/src/tokens/tokens.generated.css`, which defines `--sup-*` custom properties scoped under `.supcheckout-admin`. That file is never edited by hand, and `--check` fails on drift or on any WCAG 2.2 AA contrast failure. Branding goes only through `brand.tokens.json`, an allow-listed file that is validated and contrast-checked.
  - This document records durable intent and exact recurring values for both. A durable token change must update this file and the owning source in the same changeset.

## Colors

- `checkout-text` is the primary plugin-owned checkout foreground and focus-ring color.
- `control-border` is the quiet payment-method border; selection may strengthen the border without shifting geometry.
- `action-blue` is the existing non-destructive account/action accent.
- `destructive` is reserved for unsubscribe/destructive intent and must not be reused as generic emphasis.
- `warning` is limited to the existing subscription badge role.
- `surface` and `muted-surface` are bounded plugin-owned panels, not a license to repaint host pages.
- WordPress admin borders use `admin-border`.
- Forced-colors/high-contrast modes remain system-operable; do not suppress native accessibility affordances.

## Typography

The platform owns typography. Plugin surfaces inherit the active WooCommerce theme or WordPress admin font stack. SUPCheckout may use weight for hierarchy but must not load a competing type family. Technical evidence, identifiers, and diagnostic output may use the documented mono stack. Customer-facing copy remains sentence case and must be translatable.

## Layout

Checkout components follow host-container width and avoid independent page grids. Payment-method controls may span the available gateway panel width. Use existing compact spacing values rather than introducing a second spacing system. RTL-sensitive spacing and alignment use logical properties when plugin-owned CSS controls them. Mobile behavior must preserve every action and readable label without horizontal page overflow.

## Elevation & Depth

Depth is secondary to borders and host-platform structure. Payment rows may use the existing subtle hover shadow, but selected/error/focus state must not depend on shadow alone. Account confirmations and payment-status panels use explicit borders. Avoid floating-card stacks and decorative elevation in WordPress admin.

## Shapes

Controls and feedback use modest radii in the established 3–10 px range. Destructive and non-destructive actions keep stable geometry across default, hover, focus, and busy states. Pill styling is not the default language.

## Components

### Foundational visual states

Every plugin-owned interactive control must preserve visible default, hover where applicable, `focus-visible`, active/selected, disabled/busy, and error/recovery behavior. Focus treatment must remain visible against the active host surface. Motion is disabled or reduced under `prefers-reduced-motion: reduce`.

### Buttons and actions

Payment-method rows are real buttons/radios, never clickable generic containers. Destructive unsubscribe uses an app-owned inline disclosure/confirmation; native `alert()`, `confirm()`, and `prompt()` are prohibited. The confirmation names the consequence and uses the explicit action label “Confirm unsubscribe.” Pause/resume remains a separate non-destructive action.

### Navigation and data display

SUPCheckout does not own global site navigation. Account order filters use an associated native select because platform-owned popup geometry is accepted. Subscription details use WooCommerce table semantics and localized labels. Important financial/status values must remain available as text, not icon or color alone.

### Forms and overlays

WooCommerce checkout remains the canonical submission owner. SUPCheckout custom controls update gateway-specific state and dispatch through WooCommerce rather than inventing a parallel form lifecycle. Migration admin uses WordPress form patterns, server-side validation, nonce/capability checks, and explicit execute confirmation. Toast/status feedback uses a polite atomic live region. Checkout, account and the legacy admin screens need no plugin-owned modal layer; the React admin app's overlay layer is described in "Admin app (Sub-project 1)".

### Iconography

Payment-provider images are decorative when an adjacent visible method label conveys the same information, so they use empty `alt`. Chevron glyphs used as decoration are hidden from assistive technology. Do not repeat visible method text in icon alt/title attributes.

### Motion

Motion communicates state only. The payment-status spinner is the primary animated state signal; toast transitions are brief. Both respect reduced-motion preferences. Do not add ambient or decorative motion to checkout.

### Content and data visualization

Use plain verbs and exact payment terminology. “Captured” and “paid” are financial states, not marketing language. Never imply provider capture from routing or initialization success. Interface strings are localized through WordPress; JavaScript receives server-localized strings rather than embedding English.

## Admin app (Sub-project 1)

The React admin app is the one owned exception to "visually subordinate" (owner decision 2026-10-16): its screens are SUPCheckout's own space inside wp-admin, so they get their own design-token system and component library. Sub-project 1 ships that system **brand-ready, not branded**. It is a neutral graphite default that takes its accent from the WordPress admin colour scheme. Brand values (accent ramps, font families, the radius scale) arrive only through `brand.tokens.json`, in Sub-project 5; that file ships empty. Every checkout, account and legacy-admin rule above stays unchanged, and checkout never uses `--sup-*` tokens or `.supcheckout-admin`.

Sources: `assets/admin/src/tokens/*.tokens.json`, the generator `tools/admin-ui/tokens/build-tokens.mjs`, the components under `assets/admin/src/ui/` (screens import only from the `ui/index.ts` barrel), and the plan `docs/superpowers/plans/2026-10-19-subproject-1-design-system.md`. The front-matter `admin-*` entries mirror the generated values.

### Token tiers

- **Primitive** `--sup-palette-*`: raw ramps (neutral, the accent per admin scheme, status hues). Components never use them.
- **Semantic** `--sup-color-*`, `--sup-space-*`, `--sup-radius-*`, `--sup-font-*`, `--sup-line-height-*`, `--sup-shadow-*`, `--sup-motion-*`, `--sup-z-*` and `--sup-size-*`, in light and dark sets. Names follow the WordPress Design System roles (bg, fg, border, accent, status), so a later adoption stays cheap.
- **Component** `--sup-<component>-*`, which reference semantic tokens only.
- Type scale 12/13/14/16/20/24/32 px on a 14 px base; spacing on a 4 px grid (`--sup-space-0…12`); radii control 6, card 10, overlay 12, pill 999. Controls are 36 px (32 px in `data-sup-density="compact"`), never below the 24 × 24 px target (WCAG 2.5.8). Focus is a 2 px ring with a 2 px offset in `--sup-color-focus-ring`.
- Every rule is scoped under `.supcheckout-admin` (or `body.supcheckout-admin-page`), uses logical properties only and uses `--sup-*` tokens only (stylelint plus the CSS-discipline test).

### Light, dark, admin scheme and brand

- **Mode.** Light / Dark / Match system, default Match system (the `ThemeToggle`). "Match system" is pure CSS (`prefers-color-scheme` unless `data-sup-theme` is set), so nothing flashes before React mounts. In dark mode elevation comes from surface steps and a hairline border, not shadows.
- **Admin colour scheme.** No core scheme is a dark content scheme, so the scheme drives only the accent, never the mode. The nine core schemes (`fresh`, `light`, `modern`, `blue`, `coffee`, `ectoplasm`, `midnight`, `ocean`, `sunrise`) have contrast-verified light and dark accent ramps; an unknown scheme falls back to `fresh` (accent `#2271B1`).
- **Brand.** `brand.tokens.json` may set only allow-listed keys and replaces the scheme accents. The generator's `--check` fails on drift, on any WCAG 2.2 AA contrast failure in either mode across the nine schemes, and on an invalid or inaccessible brand file.
- **Contrast decisions (acknowledged by the owner 2026-10-22).** The light strong border is `#858B96`, a shade darker than the drafted `#8A919C`, so control boundaries reach 3:1 on canvas, surface, raised, sunken and the accent-subtle background. Subtle text (`--sup-color-fg-subtle`) is retuned to 4.5:1 text contrast, including on accent-subtle and status backgrounds. Dark-mode status tints use the calm `*-950` steps. Status colours are never the only signal: every status carries text and an icon.

### Arabic typography

`.supcheckout-admin:lang(ar|fa|ur)`, and any nested element with such a `lang`, switches `--sup-font-family-sans` to the Arabic stack `system-ui, "Segoe UI", "Noto Sans Arabic", "Geeza Pro", Tahoma, sans-serif`, sets every letter-spacing token to 0 (tracking breaks connected script) and raises the body line height from 1.5 to 1.7. WordPress's `rtl.css` heading rules no longer override this stack inside the app. Amounts, order numbers, emails and IDs render through `Numeric`: isolated LTR, tabular figures, end-aligned in tables, so `12.500 KWD` and `#10234` keep their order in RTL. Icons mirror only where they carry direction (`chevron-start`/`chevron-end`, `arrow-start`/`arrow-end`).

Open Sub-project 5 decision: bidi of untranslated English inside RTL. Tooltip (and ProgressBar's value text) use `unicode-bidi: plaintext`, so an untranslated English string takes its own direction; Toast and Notice do not, so it follows the RTL paragraph. Sub-project 5 decides one rule with the real Arabic copy.

### Motion

Durations `--sup-motion-duration-fast/base/slow` are 120/200/320 ms, with easings `--sup-motion-ease-standard` and `--sup-motion-ease-exit` and a 4 px distance. The only orchestrated motion is overlay enter and exit and the toast entrance. Under `prefers-reduced-motion: reduce` every duration is 0 ms, every distance 0 and the skeleton shimmer is static. `forced-colors: active` keeps every boundary, focus ring and state visible with system colours.

### Overlay (portal) layer

Dialogs, the confirmation, toasts, tooltips and the palette render in one body-level portal layer. The layer is itself a scoped root (`.supcheckout-admin sup-portal-layer`) that mirrors the app root's `data-sup-theme`, `dir`, `lang` and density, so portaled overlays keep every token, dark mode, RTL and the Arabic stack. `--sup-z-portal` (100000) puts it above the WordPress admin bar (99999) and menu. A modal makes the page behind inert and returns focus to its opener on close; a tooltip inside a dialog closes on the first Escape, the dialog on the next.

### Live regions

A polite `status` region is announced reliably only when its content changes after the region exists. Notice, SkeletonGroup and DataTable's loading status therefore render the region empty and insert the text `LIVE_REGION_DELAY_MS` (100 ms) later. `role="alert"` regions (a `live` error Notice) need no delay. The toast viewport is a polite region mounted empty before any toast exists. Success and info toasts close after `TOAST_TIMEOUT_MS` (6000 ms), pausing on hover, on focus and while the window is in the background; errors never close on their own; at most `TOAST_LIMIT` (3) show. No financial truth is carried by a toast alone.

### Money-confirmation contract

`ConfirmDialog` (tones `money`, `danger`, `neutral`) is the money-safety primitive:

- It only calls the caller's `onConfirm` and renders the caller's `ConfirmResult` (`verified`, `failed` or `pending`). It never calls a server, never infers success and never closes on its own. Only `verified` may read as done.
- `onConfirm` runs at most once per opening: a double click, a held Enter, Enter then a click, or Space cannot start a second call. While it is pending, Escape and backdrop presses are refused, Cancel is `aria-disabled` and the confirm button is busy.
- With an `actionKey` (for example `refund:10234`), a dialog remounted while that key's request is in flight opens pending and shows that request's result; it cannot call `onConfirm` again.
- `onConfirm` must settle. Screens bound the network call (for example `AbortSignal.timeout(…)`) and map a timeout to `pending`, never `failed`, because the server may still complete it. A rejected promise, a throw or a malformed result becomes a generic `failed` message; raw errors never reach the screen.
- Copy: the title asks the specific question ("Refund 12.500 KWD?"), the consequence says what happens and whether it can be undone, and the confirm label repeats the action, never "OK" or "Yes".

### Mod+K yield and trap rule

Mod+K (Meta+K on Apple platforms, Ctrl+K elsewhere) belongs to WordPress core's command palette wherever core loads it: the SUPCheckout palette then binds no key, and Sub-project 2 registers SUPCheckout commands into `core/commands`. Only where core's palette is absent does Mod+K toggle ours (acknowledged by the owner 2026-10-22). While any SUPCheckout modal is open, the modal frame marks Mod+K handled, so core's palette never opens over a pending confirmation. The binding ignores IME composition, matches the physical K key on other layouts and never overrides an editor that handled the key first.

### Component inventory

| Group | Components |
|---|---|
| Foundations | `SupRoot`, `useTheme`, `useDirection`, `usePortalContainer`, `usePrefersReducedMotion`, `Icon`, `VisuallyHidden`, `Heading`, `Text`, `Numeric`, `Spinner` |
| Actions and forms | `Button`, `IconButton`, `Field`, `TextInput`, `Textarea`, `SecretInput` (write-only: a saved secret is never in the DOM), `Select` (native), `Checkbox`, `RadioGroup`, `Toggle`, `ThemeToggle` |
| Display and feedback | `Card`, `Badge`, `StatusPill`, `Notice`, `EmptyState`, `Skeleton`, `SkeletonGroup`, `ProgressBar`, `Stepper`, `useToast`, `Tooltip` |
| Overlays and navigation | `Dialog`, `ConfirmDialog`, `Tabs`, `DataTable`, `AppShell`, `CommandPalette` (its panel is the lazy `command-palette` chunk) |

Native elements are used wherever native semantics are enough. Dialog, AlertDialog, Tabs, Tooltip, Toast, Progress and the palette's Autocomplete compose `@base-ui/react` headless primitives, bundled and exactly pinned. Icons are `lucide-react` (ISC), imported per icon. No component calls the REST API, a provider or a payment service.

## Do's and Don'ts

- **Do:** inherit WooCommerce/WordPress structure and keep SUPCheckout visually subordinate to the host.
- **Do:** make state, focus, consequence, and recovery explicit in text and semantics.
- **Don't:** use native JavaScript confirmation dialogs or hard-coded customer-facing English.
- **Don't:** change financial or accessibility behavior to achieve a visual flourish.
