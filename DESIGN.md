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
typography:
  platform:
    fontFamily: "inherit"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
rounded:
  DEFAULT: "6px"
  sm: "3px"
  md: "6px"
  lg: "10px"
spacing:
  compact-gap: "8px"
  control-gap: "10px"
  panel-padding: "15px"
  status-padding: "18px 20px"
components:
  payment-method: {}
  status: {}
  toast: {}
  subscription-confirmation: {}
  admin-form: {}
---

# SUPCheckout for UPayments Design System

## Overview

### Creative North Star

SUPCheckout should feel like a **native WooCommerce payment instrument panel**, not a standalone SaaS application pasted into checkout. Merchant theme and WordPress/WooCommerce chrome own the surrounding visual language; SUPCheckout adds only the minimum provider-specific structure needed to make payment choices, financial state, saved-card consent, subscription controls, and migration operations legible and safe.

### Product context and register

- **Audience and primary job:** WooCommerce shoppers choose a UPayments method and complete payment; store operators configure the gateway and, when explicitly needed, run bounded migration tooling.
- **Target market(s) and evidence:** UPayments/GCC commerce is the product context, while WordPress/WooCommerce is the host platform. Locale is not used as a proxy for market policy.
- **Locale(s) and language policy:** All plugin-owned visible and accessible copy uses the WordPress active locale and the `supcheckout` text domain. JavaScript interaction copy is server-localized. Arabic RTL is a repository-certified layout direction; untranslated hard-coded customer-facing English is not permitted.
- **Usage scene:** Checkout is high-consequence and time-sensitive on desktop and mobile; account/admin tools are lower-frequency operational surfaces.
- **Register:** Hybrid product UI. Checkout/account surfaces inherit merchant/WooCommerce styling. Admin surfaces inherit WordPress admin patterns.
- **Memorable signature:** A restrained, full-width payment-method row that keeps provider iconography, method name, selected state, and downstream WooCommerce checkout action visually coherent.
- **Restraint:** Financial authority, accessibility, host-theme compatibility, and stable control geometry always outrank decoration or animation.
- **Anti-references:** Do not make SUPCheckout look like an independent dashboard, marketing landing page, glassmorphic widget, or bespoke theme that fights WooCommerce.
- **Token ownership/runtime mapping:** Existing runtime CSS and WordPress/WooCommerce primitives remain canonical. This document records their durable intent and exact recurring values. Runtime owners are `assets/css/customer.css`, `assets/css/new-design.css`, and `assets/css/admin-style.css`. A future durable token change must update this file and the relevant runtime owner in the same changeset.

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

WooCommerce checkout remains the canonical submission owner. SUPCheckout custom controls update gateway-specific state and dispatch through WooCommerce rather than inventing a parallel form lifecycle. Migration admin uses WordPress form patterns, server-side validation, nonce/capability checks, and explicit execute confirmation. Toast/status feedback uses a polite atomic live region. No plugin-owned modal layer is currently required.

### Iconography

Payment-provider images are decorative when an adjacent visible method label conveys the same information, so they use empty `alt`. Chevron glyphs used as decoration are hidden from assistive technology. Do not repeat visible method text in icon alt/title attributes.

### Motion

Motion communicates state only. The payment-status spinner is the primary animated state signal; toast transitions are brief. Both respect reduced-motion preferences. Do not add ambient or decorative motion to checkout.

### Content and data visualization

Use plain verbs and exact payment terminology. “Captured” and “paid” are financial states, not marketing language. Never imply provider capture from routing or initialization success. Interface strings are localized through WordPress; JavaScript receives server-localized strings rather than embedding English.

## Do's and Don'ts

- **Do:** inherit WooCommerce/WordPress structure and keep SUPCheckout visually subordinate to the host.
- **Do:** make state, focus, consequence, and recovery explicit in text and semantics.
- **Don't:** use native JavaScript confirmation dialogs or hard-coded customer-facing English.
- **Don't:** change financial or accessibility behavior to achieve a visual flourish.
