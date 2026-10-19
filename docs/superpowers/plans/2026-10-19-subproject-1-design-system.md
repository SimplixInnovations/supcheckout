# Sub-project 1 — Design-Token System and Admin Component Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `--sup-*` design-token system (light, dark, RTL/Arabic, WCAG 2.2 AA) and the accessible React component library that Sub-project 2 builds every admin screen from. Ship it with a deterministic, committed build and permanent visual-regression and accessibility suites.

**Architecture:**
- **Tokens.** They are authored as JSON in three tiers: primitive → semantic (light and dark) → component. A deterministic generator turns them into one scoped CSS file. A single `brand.tokens.json` overrides an allowlisted subset, so the Sub-project 5 brand kit is one file.
- **Components.** They are our own components, written in TypeScript on `@wordpress/scripts`. They use native elements wherever native semantics are enough. They compose `@base-ui/react` headless primitives (bundled and exactly pinned; the same foundation WordPress's own `@wordpress/ui` is built on) for dialogs, tabs, tooltips, toasts and the command-palette combobox. React, the JSX runtime and `@wordpress/i18n` come from WordPress at runtime (externals).
- **Shipping.** The built output is committed under `assets/admin/build/`. The existing Git-HEAD-blob release builder therefore ships it unchanged. A CI drift gate on Linux and Windows proves that the committed build equals a clean rebuild of the committed source.
- **Testing.** Jest covers units, keyboard behavior and jest-axe. Node tests cover token contrast, the CSS rules and the build contract. Playwright runs on real WordPress 6.9 and 7.1, against a test-only component gallery, for axe, keyboard, scoping, RTL and translations, plus pinned visual regression on 7.1. A new permanent workflow, `admin-ui-certification.yml`, carries all of them.

**Tech Stack:** Node 24.21.0 / npm 11.19.0, `@wordpress/scripts` 36.0.0 (webpack 5, Babel, ESLint 10, Stylelint 17, Jest), TypeScript, React 18.3.1 runtime (the version WordPress 6.9–7.1 bundles), `@base-ui/react` 1.8.0, `lucide-react` 1.52.0, `culori` 4.0.2, `@testing-library/react` 16.3.3, `jest-axe` 11.0.0, `@playwright/test` 1.63.0, `@axe-core/playwright` 4.13.0, PHP 7.4+ (one asset-loader class), PHPUnit, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md`: §2 (Admin technology), §3.4, §3.5, §4.1, §4.2 (only the token-naming boundary), §6 "Premium details", §7.3 (code splitting), §8, §9 (Sub-project 1 exit gate). Also read:
- `docs/superpowers/plans/2026-10-18-subproject-0c-onboarding-backend.md`, for the `supcheckout/v1` API that Sub-project 2 consumes;
- `docs/project/RELEASE-ENGINEERING.md`;
- `DESIGN.md` and `UX-CONTRACT.md`, the existing design contracts that Task 9 extends to the admin app.

**Prerequisite:** `main` at `ccf58f8` or later (0c merged).

## Global Constraints

**Identity and naming**
- Script/style handle `supcheckout-admin`, app root element `#supcheckout-admin-root` with class `supcheckout-admin`, and body class `supcheckout-admin-page`.
- Design tokens are CSS custom properties `--sup-*` (spec §4.1). Component classes are `sup-*`, and every rule is scoped under `.supcheckout-admin` or `body.supcheckout-admin-page`.
- Webpack chunk-loading global `supCheckoutAdminChunks`, `output.uniqueName` `supcheckout-admin`. No other JS global.
- Text domain `supcheckout`. Every visible or accessible string goes through `__`/`_x`/`_n`/`sprintf` from `@wordpress/i18n` with `'supcheckout'`. Sentence case. No hard-coded English in components.
- PHP: namespace `Simplixi\SUPCheckout\Admin`, PHP 7.4, the style of `src/Admin/GatewaySettings.php`, and `defined('ABSPATH') || exit;`. No new `upayments` literal in shipped code.

**Platform**
- WordPress ≥ 6.9, WooCommerce ≥ 10.8. The runtime React is WordPress's `wp-element` (React 18.3.1 on 6.9 and on 7.1).
- Code stays forward-compatible with React 19 (make.wordpress.org/core 2026-05-27 and 2026-06-05):
  - `createRoot` only;
  - no `defaultProps` on function components, string refs, `findDOMNode`, `contextTypes`/`childContextTypes` or `ReactDOM.render`;
  - `inert` as a boolean;
  - refs through `forwardRef` from `@wordpress/element`.
- Never bundle `react`, `react-dom` or `react/jsx-runtime`. They resolve to `react`, `react-dom` and `react-jsx-runtime` externals.
- The `wp-components` script and stylesheet are not a dependency.
- No outbound request at runtime: no web fonts, CDN or remote icons. The default font stacks are system stacks.

**Toolchain and supply chain**
- Node `24.21.0` (`.nvmrc`) and npm `11.19.0` (`packageManager`). `engine-strict=true` and `save-exact=true` in `.npmrc`.
- Every dependency is pinned exactly and is at least 14 days old when pinned. A newer pin is added only through a Dependabot npm PR (14-day cooldown).
- CI installs with `npm ci --ignore-scripts`. Playwright browsers are installed explicitly with `npx playwright install --with-deps chromium`.
- Bundled third-party code may use only these licenses: `MIT`, `ISC`, `BSD-2-Clause`, `BSD-3-Clause`, `0BSD`, `CC0-1.0`. No `@wordpress/*` package and no GPL or Apache-2.0 code is bundled into the MIT-licensed plugin.
- The production build has no source maps and no `sourceMappingURL`.

**Package (AGENTS.md "deterministic package")**
- Shipped: `assets/admin/build/**` (committed build output) and `assets/admin/src/**` (human-readable source, WordPress.org guideline 4).
- Never shipped:
  - `tests/`, `tools/` and `node_modules/`;
  - `package.json`, `package-lock.json`, `.nvmrc`, `.npmrc`;
  - every build, lint, type or test config;
  - the gallery, visual baselines, test fonts and `*.map`.
- `scripts/build-release.sh` is not changed: it already packages Git HEAD blobs only.

**Accessibility (spec §4.1)**
- WCAG 2.2 AA. axe runs with tags `wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa`, expects zero violations and disables no rule.
- Text contrast ≥ 4.5:1 (≥ 3:1 for large text, 24 px or more). Control boundaries, focus indicators and meaningful icons ≥ 3:1. Both modes and every admin color scheme are checked.
- Every interactive target is at least 24 × 24 CSS px (2.5.8).
- Focus is always visible: a 2 px ring with a 2 px offset in `--sup-color-focus-ring`. A focused element is never obscured (2.4.11).
- Full keyboard operation, `prefers-reduced-motion` and `forced-colors: active` are respected.
- RTL uses logical properties only (enforced by `stylelint-use-logical`). Icons are mirrored only where they carry direction. Arabic text uses an Arabic-capable stack, never letter-spacing, and a taller line height.

**Payment (AGENTS.md)**
- No component calls the REST API, a provider or a payment service. Money-affecting UI (`ConfirmDialog`) only invokes the caller's `onConfirm` and renders the caller's result.
- Nothing says "paid" or "captured" unless the result the caller passes says `verified`.

**Gates (every task)**
- `npm run quality` (lint, typecheck, Jest, token checks, build plus drift).
- `composer quality` and every harness listed in `quality-gates.yml`.
- `admin-ui-certification.yml` green at the exact head.
- One PR per task, squash-merged after exact-head CI per AGENTS.md "Merge and release discipline". Conventional commits with no attribution footer.

**Out of scope**
- Every admin screen, menu registration, REST call and data hook (Sub-project 2).
- Checkout UI and adaptive checkout tokens (Sub-project 3). The only checkout boundary is that checkout never uses `--sup-*` tokens or `.supcheckout-admin`: checkout derives its styling from the theme (§4.2).
- Brand-kit values, logo and web fonts (Sub-project 5). `brand.tokens.json` ships empty.
- Server-side persistence of the theme preference (Sub-project 2, user meta via `supcheckout/v1`).
- Pagination, timeline, charts and KPI tiles. Sub-project 2 composes them from this library, or adds them with its own tests.

## Review Focus

1. **A money action fired twice** by a double click, a held Enter key that auto-repeats, or Enter followed by a click on the confirm button. Expected: `onConfirm` runs exactly once per opening. The dialog stays open, cannot be dismissed while the action is pending, and then shows the caller's result. Owned by the Task 7 test `ConfirmDialog calls onConfirm exactly once under double click and held Enter`.
2. **Portaled overlays outside the token scope.** Dialogs, toasts, tooltips and the palette render in `document.body`, outside `.supcheckout-admin`, so they lose dark mode, RTL, Arabic fonts and tokens, and WordPress's admin bar (z-index 99999) covers them. Expected: the portal layer is itself a scoped root that mirrors `data-sup-theme`, `dir` and `lang` and sits above the admin bar. Owned by the Task 3 test `SupRoot mirrors theme, dir and lang onto the portal layer` and the Task 7 browser tests `portaled overlays keep tokens, theme and direction` and `modal dialog layer covers the WordPress admin bar and menu`.
3. **WordPress admin CSS bleeding into our controls.** `forms.css` checkbox `::before` checkmarks, the `select` chevron background, `.wp-core-ui` button styles and input `min-height` all apply, and they differ between WP 6.9 and 7.1. Expected: our controls render the same on both versions. Owned by the Task 5 browser test `form controls ignore WordPress admin form styles`, run on both WordPress versions, and by the visual suite.
4. **Bidi reordering in Arabic.** Amounts (`12.500 KWD`), order numbers (`#10234`), emails and IDs get reordered inside RTL sentences, table cells and pills. Expected: numeric and identifier content is isolated LTR and keeps its order. Owned by the Task 3 test `Numeric isolates bidi and uses tabular figures`, the Task 7 test `numeric cells are end-aligned and bidi-isolated`, and the RTL visual specimens.
5. **Landmarks and focus obscured inside wp-admin.** WordPress already provides `#wpbody[role=main]`, so a nested `<main>` duplicates the main landmark. The admin bar, our sticky header or a toast can hide the focused element (2.4.11). Expected: exactly one main landmark, and the focused element is never fully covered. Owned by the Task 7 browser tests `no duplicate main landmark inside wp-admin` and `focused element is never obscured by the sticky header` and the Task 6 browser test `toast viewport never obscures the focused element`.

---

## Design decisions (why)

### Toolchain

- **`@wordpress/scripts` 36.0.0, Node 24.21.0, npm 11.19.0.**
  - 36.0.0 (2026-09-23) is the newest release at least 14 days old; 36.1.0 is 12 days old at plan date and arrives later through Dependabot.
  - It requires Node `^22.22.2 || ^24.15.0`. 24.21.0 is the newest aged Node 24 LTS (Krypton) release, and quality-gates already runs Node 24.
  - The pins go in `.nvmrc`, `engines` (exact), `packageManager` and `engine-strict`, so a wrong toolchain fails at install instead of producing a different build.
- **Lockfile and `npm ci --ignore-scripts`.** A single root `package-lock.json` (lockfile v3, generated on Linux so it records every platform's optional packages). `--ignore-scripts` blocks install-time code. Any package that genuinely needs its script is allow-listed by name in the workflow with a comment; none is expected.
- **Committed build output, not CI-built.**
  - `scripts/build-release.sh` and `scripts/verify-release.sh` package and verify Git HEAD blobs byte for byte, and a release from a mutable worktree is forbidden. Building JS inside the release builder would make the ZIP depend on the npm registry, the Node version and the OS at release time.
  - Committing the build keeps the existing Linux/Windows byte-identity proof valid with no change to the builder.
  - Reproducibility moves to a new drift gate: `npm ci` plus `npm run build`, twice, on `ubuntu-latest` and `windows-latest`, followed by `git diff --exit-code -- assets/admin` and an empty `git status --porcelain -- assets/admin`. A stale or hand-edited build therefore cannot merge.
  - `.gitattributes` forces LF for `*.ts`, `*.tsx`, `*.mjs`, `*.cjs` and `*.json`, as it already does for `*.js` and `*.css`.
- **Human-readable source is shipped.**
  - Guideline 4 allows compiled code only if the source is included or linked. The repository is public, but shipping `assets/admin/src/**` (TypeScript, CSS and token JSON) keeps the ZIP self-sufficient for reviewers and survives a repository move.
  - `readme.txt` also gets a "Source code and build" section that links the repository and names `npm ci && npm run build`.
  - Terser's mangling is ordinary minification, not obfuscation. `@wordpress/scripts` reserves `__`, `_n`, `_x` and `_nx` and keeps `translators:` comments, so string extraction still works on the built file.
- **No source maps.** The `@wordpress/scripts` production config sets no `devtool` unless `WP_DEVTOOL` is set. The build-contract test fails if any `.map` file exists or any file contains `sourceMappingURL`. Maps add size without helping guideline 4, because the source ships anyway.
- **Asset manifest as JSON.** `DependencyExtractionWebpackPlugin` gets `outputFormat: 'json'`, which writes `app.asset.json`. The package then contains no generated PHP file, so PHPCS, PHP lint and Plugin Check see none, and `AdminAssets` validates the manifest strictly.
- **Per-screen code splitting through one entry.**
  - There is one entry, `app`, plus `import()` chunks under `build/chunks/[name].[contenthash:8].js`, with `publicPath: 'auto'` (derived from the script's own URL, so CDN rewrites work).
  - WordPress loads translation JSON only for registered handles, so a custom plugin in `webpack.config.js` also emits `app.chunks.json`. `AdminAssets` inlines `wp.i18n.setLocaleData` for each chunk before the app runs.
  - Sub-project 1 proves this with one real lazy chunk, the command-palette panel.
- **Plugin Check impact.** The packaged ZIP gains JS, CSS, JSON and TS files under `assets/` only. It has no hidden files and no compressed, PHP-generated or `.map` files. The strict packaged Plugin Check (`wordpress-org-submission-check.yml`) stays unchanged and must stay green. The CodeQL JS extractor analyzes `assets/admin/src`. It skips minified bundles on its own; no CodeQL configuration changes.
- **Locked dependency audit.** `npm audit --omit=dev` must report nothing at any level, because those packages ship in the bundle. `npm audit --audit-level=high` must pass for the full tree. Both block the merge.
- **SBOM.** `scripts/generate-sbom-evidence.py` adds the root `package-lock.json` to the development SBOM. It adds every bundled package listed in `assets/admin/build/third-party-notices.json` to the runtime SBOM as `pkg:npm` components with their license. This strengthens the supply-chain evidence; nothing is removed.

### Component-library approach

- **Our own library, behind one import surface.** SP2 imports only from `assets/admin/src/ui` (barrel `index.ts`). ESLint `no-restricted-imports` forbids `@base-ui/react*`, `lucide-react` and `@wordpress/components` anywhere outside `assets/admin/src/ui/`. Primitives can then change without touching screens.
- **Native first.** Button, text input, textarea, select, checkbox, radio and switch (`<input type="checkbox" role="switch">`) are native elements styled with tokens. They have the best assistive-technology and mobile behavior, and they keep the UX-CONTRACT rule that Select/Listbox stays native.
- **`@base-ui/react` 1.8.0 for complex widgets (bundled, MIT, React 18 and 19).** Dialog and AlertDialog (focus trap, return focus, inert outside), Tabs (roving focus, RTL arrows through `DirectionProvider`), Tooltip, Toast, Progress and Combobox/Autocomplete for the palette. It is the primitive layer WordPress's own `@wordpress/ui` builds on, so our behavior matches the direction of the WordPress Design System without its experimental API.
- **Why not `@wordpress/components`.** It is a runtime external, so WP 6.9 and 7.1 serve different versions of its markup and CSS. Visual regression could not be deterministic and the branded look would depend on the WordPress version. Its global stylesheet is large, and theming beyond `--wp-admin-theme-color` means overriding internal class names. The owner decision (§2) is "our own design-token component library".
- **Why not `@wordpress/ui` / `@wordpress/theme`.** Their README calls them experimental ("subject to drastic and breaking changes"). They impose the `--wpds-*` token tree and a `wp-ui` cascade layer. Our semantic token names follow WPDS's semantic roles (bg, fg, border, accent, status) so a later adoption stays cheap.
- **Icons.** `lucide-react` (ISC), imported per icon so only those are bundled, behind our own `Icon` with an `IconName` union. `@wordpress/icons` is GPL-2.0-or-later and would put GPL code in the MIT bundle.
- **CSS architecture.** Plain CSS files next to each component. Classes are `sup-<component>` and `sup-<component>--<modifier>`, all under `.supcheckout-admin`. There are no CSS modules (hashed names would break SP2 tests and overrides) and no cascade layers: WordPress admin CSS is unlayered and would beat any layered rule.

### Token architecture

- **Three tiers, authored as JSON.** The source of truth is `assets/admin/src/tokens/*.tokens.json`, in a DTCG-style `{ "$value", "$type" }` shape:
  - **Primitive** `--sup-palette-*` (raw ramps). Components never use these.
  - **Semantic** `--sup-color-*`, `--sup-space-*`, `--sup-radius-*`, `--sup-font-*`, `--sup-line-height-*`, `--sup-shadow-*`, `--sup-motion-*`, `--sup-z-*` and `--sup-size-*`, in light and dark sets.
  - **Component** `--sup-<component>-*`, which only reference semantic tokens.

  `tools/admin-ui/tokens/build-tokens.mjs` renders `assets/admin/src/tokens/tokens.generated.css`. That file is committed so the shipped source is complete, and `--check` fails CI when it is stale.
- **Branding is one file.** `brand.tokens.json` may set only allowlisted keys: `palette.accent.*` light and dark ramps, `font.family.sans`, `font.family.arabic`, `font.family.mono` and `radius.scale`. The generator rejects any other key. A brand accent replaces the admin-scheme accents, and the contrast suite runs on the merged result, so an inaccessible brand file fails CI.
- **Light and dark.**
  - The resolved mode is the manual preference (Light / Dark / Match system, default Match system), then `prefers-color-scheme`.
  - CSS handles "system" with no JavaScript: `@media (prefers-color-scheme: dark) { .supcheckout-admin:not([data-sup-theme="light"]) { … } }`, so there is no flash before React mounts.
  - An explicit choice sets `data-sup-theme` on the app root and the portal layer, and the root sets `color-scheme`.
  - In dark mode, elevation comes from surface steps plus a hairline border rather than shadows.
- **WordPress admin color scheme mapping.**
  - No core admin scheme is a dark content scheme. The scheme therefore drives the neutral default accent, never the mode.
  - `admin-schemes.tokens.json` holds contrast-verified light and dark accent ramps for the nine core schemes: `fresh`, `light`, `modern`, `blue`, `coffee`, `ectoplasm`, `midnight`, `ocean` and `sunrise`.
  - They apply through `body.admin-color-<scheme> .supcheckout-admin`. An unknown or custom scheme falls back to the `fresh` ramp.
  - The fixture records each scheme's `--wp-admin-theme-color` at WP 7.1.3, and a real-WordPress test alarms on drift.
  - The brand file overrides all of it (owner decision 2 below).
- **RTL.** Logical properties only (`stylelint-use-logical`, plus a CSS-discipline test). The `-rtl.css` that `@wordpress/scripts` (rtlcss) generates is registered with `wp_style_add_data(…, 'rtl', 'replace')`, so the few physical residues (transforms, shadows) flip as well. Directional icons are named logically (`chevron-start`/`chevron-end`, `arrow-start`/`arrow-end`) and mirror only in RTL.
- **Arabic.**
  - `.supcheckout-admin:lang(ar), :lang(fa), :lang(ur)` switches `--sup-font-family-sans` to `--sup-font-family-arabic`: `system-ui, "Segoe UI", "Noto Sans Arabic", "Geeza Pro", Tahoma, sans-serif`. On macOS `system-ui` resolves to SF Arabic.
  - The same selector sets `--sup-letter-spacing-*: 0`, because tracking breaks connected script, and sets `--sup-line-height-body: 1.7` (against `1.5`).
  - The default Latin stack is `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif`, matching the wp-admin family.
- **Type and space.** The base size is 14 px: the scale is `--sup-font-size-xs…3xl` = 12/13/14/16/20/24/32. Spacing is on a 4 px grid aligned with WPDS grid units: `--sup-space-0…12` = 0/2/4/8/12/16/20/24/32/40/48/56/64. Radii: control 6, card 10, overlay 12, pill 999. Money and IDs use `font-variant-numeric: tabular-nums`.
- **Density.** `data-sup-density="compact"` lowers `--sup-size-control-md` from 36 to 32 px and table rows from 48 to 36 px, never below the 24 px target. It is a prop on `SupRoot` and `DataTable`, not a user setting.
- **Motion.**
  - Durations `--sup-motion-duration-fast/base/slow` = 120/200/320 ms. Easings `--sup-motion-ease-standard: cubic-bezier(.2,0,0,1)` and `--sup-motion-ease-exit: cubic-bezier(.3,0,1,1)`. Distances `--sup-motion-distance-sm: 4px`.
  - Under `prefers-reduced-motion: reduce`, every duration becomes `0ms`, every distance `0`, and the skeleton shimmer becomes static.
  - The only orchestrated motion is overlay enter and exit plus the toast entrance (frontend-design restraint).
- **Default visual direction (neutral, premium, Operate mode).**
  - A cool graphite neutral ramp. The anchors constrain the generator tests:
    - light: canvas `#f5f6f8`, surface `#ffffff`, raised `#ffffff` plus a shadow, fg `#16191d`, muted fg `#59606b`, border `#d9dce1`, strong border `#8a919c`;
    - dark: canvas `#121519`, surface `#1a1e23`, raised `#22272d`, fg `#e9ecef`, muted fg `#a4acb6`, border `#323840`, strong border `#6b737e`.
  - The accent comes from the WordPress admin scheme until branding (above).
  - Status colors (success, warning, danger, info) each have `bg`, `fg`, `border` and `solid`. They are never the only signal: every status carries text and an icon.
  - The one memorable element is the money-confirmation result panel (Task 7). Everything else stays quiet.

### Testing

| Layer | Tool | Runs | What it proves |
|---|---|---|---|
| Unit and behavior | Jest (`wp-scripts test-unit-js`, jsdom), `@testing-library/react`, `user-event` | `npm run test:unit` | Roles, names, keyboard, state and i18n per component |
| Unit a11y | `jest-axe` through `renderInModes()` (light/dark × ltr/rtl) | same | Structural ARIA and labelling per state |
| React 19 lane | Jest with `react@19.3.0` aliased through `moduleNameMapper` (`SUP_REACT=19`) | `npm run test:unit:react19` | Forward compatibility with the planned WordPress React 19 runtime |
| Tokens | Node tests on the resolved tokens, `culori` WCAG contrast | in `test:unit` | Every semantic pair, both modes, nine schemes, plus the brand file |
| CSS discipline | Stylelint (`@wordpress/stylelint-config` + `stylelint-use-logical`) and a postcss test | `lint:css`, `test:unit` | No raw values, no physical properties, scoped selectors, `--sup-*` only |
| Build contract | Node test over `assets/admin/build` | `test:unit` | Externals, no React/JSX runtime bundled, no maps, chunk global, licenses, budgets, RTL CSS, chunk manifest |
| Real WordPress | Playwright 1.63.0 + `@axe-core/playwright` on WP 6.9.10/WC 10.8.1 and WP 7.1.3/WC 11.1.2 | `admin-ui-certification.yml` `browser` | Scoping to SUPCheckout screens, admin CSS bleed, RTL file swap, translations including chunks, axe in four modes with overlays open, keyboard, target size, focus, reduced motion, forced colors |
| Visual regression | Playwright `toHaveScreenshot` on WP 7.1.3, Linux baselines | `visual` | Pixel stability of every specimen in four modes |

- **Determinism of visual regression.**
  - It pins WordPress, WooCommerce, PHP 8.3, Playwright and the Chromium build Playwright pins.
  - Viewport 1280×900, `deviceScaleFactor: 1`, Chromium arguments `--font-render-hinting=none --force-color-profile=srgb`, `animations: 'disabled'`, `caret: 'hide'`, `reducedMotion: 'reduce'`, and an awaited `document.fonts.ready`.
  - Screenshots are taken per specimen locator, never of WordPress chrome.
  - Fonts are vendored test-only OFL Noto Sans and Noto Sans Arabic. They are applied through a test brand file, which also proves the one-file branding path. The default system stack is covered by token tests instead.
  - Baselines are Linux-only. CI runs with `updateSnapshots: 'none'`, so a missing baseline fails.
  - Baselines are refreshed only by a `workflow_dispatch` run with `update_visual_baselines: true`. That run uploads them as an artifact, a human commits them, and the run's gate deliberately fails, so an update can never be green on its own.
- **Permanent control.** New workflow `.github/workflows/admin-ui-certification.yml`. It has jobs `static`, `build-windows`, `browser` (two-version matrix), `visual` and `gate`. Like the H12 job, `gate` fails unless every needed job is `success`: skipped or cancelled counts as failure. It triggers on every PR to `main`, on push to `main` and on dispatch, with no path filters. It is added to the quality-gates "Required control files" list and, in Task 9, to the AGENTS.md permanent-controls list. No existing workflow, harness or check is changed except to add assertions.

### Gallery

- **Test-only, never shipped.** The gallery lives in `tests/admin-ui/gallery/`. It is built by `tests/admin-ui/gallery/webpack.gallery.config.cjs` into a git-ignored `tests/admin-ui/gallery/build/`, using the same externals as production and importing components from source.
- A test mu-plugin, `tests/admin-ui/wp/supcheckout-ui-gallery.php`, registers two hidden pages with the `manage_woocommerce` capability:
  - `supcheckout-ui-gallery` renders every specimen in a scoped root;
  - `supcheckout-ui-shell` mounts the shipped `app` bundle through the real `AdminAssets`.

  The mu-plugin exits unless `SUPCHECKOUT_UI_GALLERY` is true, so it is inert even if copied by mistake.
- **RTL without a network language pack.** A test user with user locale `ar` gets `$wp_locale->text_direction = 'rtl'` from the mu-plugin, the technique RTL Tester uses. Arabic strings come from fixture JSON files that `tests/admin-ui/wp/make-fixture-translations.php` writes with the md5 of each built file's relative path.

### Component set (sized to Sub-project 2's screens, YAGNI)

| Group | Components | SP2 consumer |
|---|---|---|
| Foundations | `SupRoot`, `useTheme`, `useDirection`, `usePortalContainer`, `usePrefersReducedMotion`, `Icon`, `VisuallyHidden`, `Heading`, `Text`, `Numeric`, `Spinner` | all |
| Actions and forms | `Button`, `IconButton`, `Field`, `TextInput`, `Textarea`, `SecretInput`, `Select`, `Checkbox`, `RadioGroup`, `Toggle`, `ThemeToggle` | wizard, Settings, filters |
| Display and feedback | `Card`, `Badge`, `StatusPill`, `Notice`, `EmptyState`, `Skeleton`, `SkeletonGroup`, `ProgressBar`, `Stepper`, `Toaster`/`useToast`, `Tooltip` | Command Center, Health, wizard |
| Overlays and navigation | `Dialog`, `ConfirmDialog`, `Tabs`, `DataTable`, `AppShell`, `CommandPalette` | Payments, Settings, every screen |

`SecretInput` exists because §6 says the API key is "write-only and never shown back". `Numeric` exists because of Review Focus 4.

---

## Version pins

| Package | Pin | Note |
|---|---|---|
| node / npm | 24.21.0 / 11.19.0 | `.nvmrc`, `engines`, `packageManager` |
| `@wordpress/scripts` | 36.0.0 | includes webpack 5, ESLint 10 (flat config `eslint.config.mjs`), Stylelint 17, Jest |
| `@wordpress/element`, `@wordpress/i18n`, `@wordpress/dom-ready`, `@wordpress/a11y` | the versions published in the 2026-09-23 Gutenberg batch (`@wordpress/element` 8.8.0) | dev only (types and Jest); external at runtime |
| `@base-ui/react` | 1.8.0 | bundled |
| `lucide-react` | 1.52.0 | bundled per icon |
| `react`, `react-dom` | 18.3.1 | dev only; matches WP 6.9 and 7.1 `wp-element` |
| `react-19` / `react-dom-19` | `npm:react@19.3.0` / `npm:react-dom@19.3.0` | React 19 Jest lane only |
| `typescript` | 6.0.3 (or the newest version inside `@wordpress/eslint-plugin` 27's `@typescript-eslint` peer range) | `tsc --noEmit` |
| `@testing-library/react` / `user-event` / `jest-axe` | 16.3.3 / 14.6.7 / 11.0.0 | |
| `@playwright/test` / `@axe-core/playwright` | 1.63.0 / 4.13.0 | |
| `culori` | 4.0.2 | contrast math |
| `stylelint-use-logical` | 2.1.3 | peer `stylelint >=11 <18` |

---

## File Structure

```text
package.json, package-lock.json, .nvmrc, .npmrc          toolchain pins (not shipped)
webpack.config.js                                        extends @wordpress/scripts: entry app, output, chunk global, JSON manifest, notices + chunk-manifest plugin
tsconfig.json, eslint.config.mjs, .stylelintrc.json, jest.config.js
tools/admin-ui/
  webpack/notices-plugin.cjs      writes build/THIRD-PARTY-NOTICES.txt, build/third-party-notices.json, build/app.chunks.json
  tokens/build-tokens.mjs         CLI: JSON -> tokens.generated.css (--check)
  tokens/resolve.mjs              loadTokens, resolveColor, renderCss, validateBrand
  tokens/contrast.mjs             checkContrast (culori wcagContrast)
assets/admin/src/                 SHIPPED source
  app/index.tsx                   domReady -> createRoot(#supcheckout-admin-root) -> <SupRoot><AppShell/></SupRoot>
  tokens/core.tokens.json, admin-schemes.tokens.json, brand.tokens.json, contrast-pairs.json, tokens.generated.css
  ui/index.ts                     the only import surface for SP2
  ui/foundations/                 SupRoot.tsx theme.ts direction.ts portal.tsx motion.ts Icon.tsx icons.ts VisuallyHidden.tsx Heading.tsx Text.tsx Numeric.tsx Spinner.tsx foundations.css
  ui/controls/                    Button.tsx IconButton.tsx Field.tsx TextInput.tsx Textarea.tsx SecretInput.tsx Select.tsx Checkbox.tsx RadioGroup.tsx Toggle.tsx ThemeToggle.tsx + one .css each
  ui/feedback/                    Card Badge StatusPill Notice EmptyState Skeleton ProgressBar Stepper Toast Tooltip (.tsx + .css)
  ui/overlays/                    Dialog.tsx ConfirmDialog.tsx Tabs.tsx DataTable.tsx AppShell.tsx CommandPalette.tsx CommandPalettePanel.tsx search.ts (+ .css)
assets/admin/build/               SHIPPED, committed: app.js app.css app-rtl.css app.asset.json app.chunks.json chunks/*.js THIRD-PARTY-NOTICES.txt third-party-notices.json
src/Admin/AdminAssets.php         register/enqueue only on SUPCheckout screens; manifest validation; RTL; translations incl. chunks; body class
tests/unit/Admin/AdminAssetsTest.php, tests/support/wordpress-admin-assets.php
tests/admin-ui/
  unit/**.test.tsx, unit/support/render.tsx (renderInModes, axe)
  types/*.test-d.tsx              compile-time API contracts (@ts-expect-error)
  tokens/*.test.mjs, build/build-contract.test.mjs
  gallery/index.tsx, gallery/specimens/*.tsx, gallery/webpack.gallery.config.cjs, gallery/vr-brand.tokens.json
  wp/supcheckout-ui-gallery.php, wp/make-fixture-translations.php, wp/fixtures/ar-strings.json
  browser/*.spec.ts, visual/gallery.visual.spec.ts, visual/baselines/**, fonts/*.woff2 + OFL.txt
  playwright.config.ts
.github/workflows/admin-ui-certification.yml (new), .github/dependabot.yml (npm entry)
```

Modified: `.gitignore`, `.gitattributes`, `.distignore`, `scripts/verify-release.sh` (more forbidden paths), `tests/harness/release-artifact-harness.php`, `tests/harness/wordpress-org-submission-harness.php`, `.github/workflows/quality-gates.yml` (required files), `scripts/generate-sbom-evidence.py`, `phpcs.xml.dist`, `phpstan.neon.dist`, `readme.txt`, `DESIGN.md`, `UX-CONTRACT.md`, `premium-ui.json`, `docs/project/NAMING-IDENTITY-STANDARD.md`, and the living status documents.

---

### Task 1: Toolchain, committed-build contract and the Admin UI Certification workflow (static lane)

**Files:**
- Create:
  - the toolchain files: `package.json`, `package-lock.json`, `.nvmrc`, `.npmrc`, `webpack.config.js`, `tsconfig.json`, `eslint.config.mjs`, `.stylelintrc.json`, `jest.config.js`;
  - `tools/admin-ui/webpack/notices-plugin.cjs`;
  - `assets/admin/src/app/index.tsx`, a placeholder mount with one translated string (`__( 'SUPCheckout', 'supcheckout' )`);
  - the committed `assets/admin/build/*`;
  - `tests/admin-ui/build/build-contract.test.mjs`;
  - `.github/workflows/admin-ui-certification.yml`, with jobs `static`, `build-windows` and `gate`.
- Modify:
  - `.gitignore`: `/node_modules/`, `/tests/admin-ui/gallery/build/`, `/tests/admin-ui/artifacts/`;
  - `.gitattributes`: `*.ts`, `*.tsx`, `*.mjs`, `*.cjs` LF; `*.woff2` binary;
  - `.distignore`: `/node_modules/`, `/tools/`, `/package.json`, `/package-lock.json`, `/.nvmrc`, `/.npmrc`, `/webpack.config.js`, `/tsconfig.json`, `/eslint.config.mjs`, `/.stylelintrc.json`, `/jest.config.js`;
  - `scripts/verify-release.sh`: those names added to `forbidden_exact`, and `node_modules/` and `tools/` to `forbidden_prefixes`. A `.map` suffix is forbidden;
  - `tests/harness/release-artifact-harness.php` and `tests/harness/wordpress-org-submission-harness.php`;
  - `.github/workflows/quality-gates.yml`: required control files add `package.json`, `package-lock.json` and `.github/workflows/admin-ui-certification.yml`;
  - `.github/dependabot.yml`: `npm` at `/`, weekly, `cooldown: { default-days: 14 }`, grouped;
  - `scripts/generate-sbom-evidence.py`;
  - `readme.txt`: a "Source code and build" section.

**Interfaces:**
- Produces npm scripts:
  - `build`: `wp-scripts build --config webpack.config.js`. Task 2 prefixes it with `node tools/admin-ui/tokens/build-tokens.mjs &&`.
  - `build:gallery`, `lint:js`, `lint:css`, `typecheck`, `test:unit`, `test:unit:react19`, `test:browser`, `test:visual`.
  - `check:drift`: `git diff --exit-code -- assets/admin && test -z "$(git status --porcelain -- assets/admin)"`.
  - `quality`: lint:js → lint:css → typecheck → test:unit → build → check:drift.
- Produces build files:
  - `app.asset.json` `{dependencies: string[], version: string}`;
  - `app.chunks.json`, a sorted `string[]` of `chunks/<name>.<hash8>.js`;
  - `third-party-notices.json` `[{name, version, license}]`, sorted by name.
- `webpack.config.js` sets:
  - `output.uniqueName = 'supcheckout-admin'`;
  - `output.chunkLoadingGlobal = 'supCheckoutAdminChunks'`;
  - `output.chunkFilename = 'chunks/[name].[contenthash:8].js'`;
  - `output.publicPath = 'auto'`;
  - `optimization.moduleIds = 'deterministic'`;
  - `DependencyExtractionWebpackPlugin({ outputFormat: 'json' })`, replacing the default instance.

- [ ] **Step 1: Write the failing build-contract test.**

```js
const BUDGET = { appJs: 60 * 1024, appCss: 16 * 1024, chunk: 30 * 1024 }; // gzip bytes
const ALLOWED = [ 'MIT', 'ISC', 'BSD-2-Clause', 'BSD-3-Clause', '0BSD', 'CC0-1.0' ];
test( 'app manifest is JSON with WordPress externals and no wp-components', () => {
	const m = readJson( 'assets/admin/build/app.asset.json' );
	expect( Object.keys( m ) ).toEqual( [ 'dependencies', 'version' ] );
	expect( m.dependencies ).toEqual( expect.arrayContaining( [ 'react-jsx-runtime', 'wp-element', 'wp-i18n', 'wp-dom-ready' ] ) );
	expect( m.dependencies ).not.toContain( 'wp-components' );
} );
test( 'React and the JSX runtime are never bundled', () => {
	for ( const needle of [ '__SECRET_INTERNALS', 'react.transitional.element', 'react.production' ] ) expect( allJs() ).not.toContain( needle );
} );
test( 'no source maps ship', () => { expect( listBuild().filter( ( f ) => f.endsWith( '.map' ) ) ).toEqual( [] ); expect( allText() ).not.toMatch( /sourceMappingURL/ ); } );
test( 'translator calls and the supcheckout domain survive minification', () => { expect( appJs() ).toMatch( /\b__\(/ ); expect( appJs() ).toContain( '"supcheckout"' ); } );
test( 'the webpack runtime uses a SUPCheckout-owned chunk global', () => { expect( appJs() ).toContain( 'supCheckoutAdminChunks' ); expect( appJs() ).not.toMatch( /webpackChunk/ ); } );
test( 'every stylesheet has an RTL twin', () => { expect( listBuild() ).toEqual( expect.arrayContaining( [ 'app.css', 'app-rtl.css' ] ) ); } );
test( 'the chunk manifest lists exactly the emitted chunks', () => { expect( readJson( 'assets/admin/build/app.chunks.json' ) ).toEqual( listBuild( 'chunks' ).sort() ); } );
test( 'bundled packages carry an allowed license and none is WordPress or React', () => {
	for ( const p of readJson( 'assets/admin/build/third-party-notices.json' ) ) {
		expect( ALLOWED ).toContain( p.license ); expect( p.name ).not.toMatch( /^(@wordpress\/|react$|react-dom$)/ );
	}
} );
test( 'gzip sizes stay within budget', () => { expect( gz( 'app.js' ) ).toBeLessThanOrEqual( BUDGET.appJs ); expect( gz( 'app.css' ) ).toBeLessThanOrEqual( BUDGET.appCss ); for ( const c of listBuild( 'chunks' ) ) expect( gz( c ) ).toBeLessThanOrEqual( BUDGET.chunk ); } );
```

- [ ] **Step 2: Run it.** `npx wp-scripts test-unit-js tests/admin-ui/build`. Expected: FAIL, `ENOENT … assets/admin/build/app.asset.json`.
- [ ] **Step 3: Add the toolchain, `webpack.config.js`, the notices plugin and the placeholder entry, then build.** `nvm use && npm ci --ignore-scripts && npm run build`. The notices plugin collects every module resource under `node_modules` from `compilation.modules`, maps it to its package `name`, `version` and `license`, and writes the TXT (each package's LICENSE text, sorted) and the JSON deterministically (sorted, LF, trailing newline).
- [ ] **Step 4: Run.** `npm run test:unit -- tests/admin-ui/build`. Expected: PASS. Then run `npm run build && npm run check:drift` twice. Expected: exit 0 both times (byte-identical rebuild).
- [ ] **Step 5: Extend the release harnesses.** In `release-artifact-harness.php`:
  - assert that each new `.distignore` rule is present;
  - in the packaged-ZIP inspection, require `supcheckout/assets/admin/build/app.js`, `app.asset.json`, `app.css`, `app-rtl.css`, `THIRD-PARTY-NOTICES.txt` and `supcheckout/assets/admin/src/app/index.tsx`;
  - reject any entry ending in `.map`, containing `/__tests__/`, or under `tools/`, `node_modules/` or `tests/admin-ui/`.

  In `wordpress-org-submission-harness.php`, assert that `readme.txt` contains the "Source code and build" section with the repository URL.

  Run `php tests/harness/release-artifact-harness.php && php tests/harness/wordpress-org-submission-harness.php`. Expected: PASS.
- [ ] **Step 6: Build and verify the package.** `bash scripts/build-release.sh dist && bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip`. Expected: VERIFIED. Record the file count.
- [ ] **Step 7: Add the workflow and the SBOM change.**
  - `static` (ubuntu): setup-node with `node-version-file: .nvmrc`, `npm ci --ignore-scripts`, `npm audit --omit=dev`, `npm audit --audit-level=high`, `npx wp-scripts check-engines`, then `npm run quality`, then `npm run build && npm run check:drift` a second time.
  - `build-windows` (windows-latest, `shell: bash`): `npm ci --ignore-scripts && npm run build && npm run check:drift`.
  - `gate`: `needs: [static, build-windows]` with `if: always()`; it fails unless every result is `success`.
  - SBOM: the dev components gain the root `package-lock.json`; the runtime components gain the `pkg:npm` entries from `third-party-notices.json`.

  Run the generator and the validator with the same arguments and `SOURCE_DATE_EPOCH` that `supply-chain-evidence.yml` uses. Expected: PASS, and the runtime SBOM lists `pkg:npm/@base-ui/react@1.8.0`.
- [ ] **Step 8: Run all gates** (Global Constraints → Gates).
- [ ] **Step 9: Commit.** `chore(admin-ui): pin the @wordpress/scripts toolchain and the committed-build contract`

---

### Task 2: Token system, contrast checker and CSS discipline

**Files:**
- Create:
  - `assets/admin/src/tokens/core.tokens.json`, `admin-schemes.tokens.json`, `brand.tokens.json` (`{}`), `contrast-pairs.json` and the generated `tokens.generated.css`;
  - `tools/admin-ui/tokens/{resolve,contrast,build-tokens}.mjs`;
  - tests `tests/admin-ui/tokens/{tokens,contrast,brand,css-discipline}.test.mjs`;
  - `tests/admin-ui/tokens/fixtures/{raw-color.css,physical.css,inaccessible-brand.tokens.json}`.
- Modify:
  - `.stylelintrc.json`: `@wordpress/stylelint-config` plus `plugins: ['stylelint-use-logical']`, `'csstools/use-logical': 'always'`, and `selector-class-pattern` `^(sup|supcheckout)-[a-z0-9-]+(__[a-z0-9-]+)?(--[a-z0-9-]+)?$`;
  - `assets/admin/src/app/index.tsx`: imports `../tokens/tokens.generated.css`;
  - `docs/project/NAMING-IDENTITY-STANDARD.md`: an amendment row stating that admin design tokens use `--sup-*` and component classes `sup-*` under `.supcheckout-admin` (spec §4.1, owner decision 2026-10-16). Checkout and other plugin CSS keep `--supcheckout-*`.

**Interfaces:**
- `loadTokens(opts?: { brandPath?: string }): TokenSet`, where:
  - `TokenSet = { primitives: Record<string,string>; semantic: { light: Record<string,string>; dark: Record<string,string> }; component: Record<string,string>; schemes: Record<SchemeName, { light: Record<string,string>; dark: Record<string,string> }>; brandAccent: boolean }`;
  - `SchemeName = 'fresh'|'light'|'modern'|'blue'|'coffee'|'ectoplasm'|'midnight'|'ocean'|'sunrise'`.
- `resolveColor(set: TokenSet, mode: 'light'|'dark', token: string, scheme?: SchemeName): string` returns `#rrggbb`.
- `renderCss(set: TokenSet): string` is deterministic: sorted, LF, trailing newline. Selector order:
  1. `.supcheckout-admin` (primitives, light semantic, component, `color-scheme: light`);
  2. dark under `@media (prefers-color-scheme: dark)` with `:not([data-sup-theme="light"])`;
  3. `[data-sup-theme="dark"]`;
  4. `body.admin-color-<scheme> .supcheckout-admin` light and dark accent overrides (omitted when `brandAccent`);
  5. `:lang(ar), :lang(fa), :lang(ur)` (Arabic font, line height and letter spacing);
  6. `[data-sup-density="compact"]`;
  7. `@media (prefers-reduced-motion: reduce)`.
- `checkContrast(set: TokenSet): ContrastFailure[]`, where `ContrastFailure = { mode, scheme, fg, bg, ratio, required }`. Pairs come from `contrast-pairs.json` entries `{ fg, bg: string[], kind: 'text'|'large-text'|'non-text' }`, which require 4.5, 3 and 3.
- `validateBrand(json: unknown): string[]` returns error messages.
- `findCssViolations(css: string, file: string): string[]`.
- CLI: `node tools/admin-ui/tokens/build-tokens.mjs [--check] [--brand <path>] [--out <path>]`. Task 2 also prefixes the `build` script with it.
- Token keys in JSON, in `contrast-pairs.json` and in `ContrastFailure` are written without the `--sup-` prefix (for example `color-fg-on-accent`). `renderCss` adds the prefix.
- Semantic color names, all produced here and consumed by every later task:
  - background `--sup-color-bg-{canvas,surface,surface-raised,surface-sunken,overlay-backdrop}`;
  - foreground `--sup-color-fg-{default,muted,subtle,on-accent,link}`;
  - border `--sup-color-border-{default,strong}`;
  - accent `--sup-color-accent-{bg,bg-hover,bg-active,fg,subtle-bg}`;
  - status `--sup-color-status-{success,warning,danger,info,neutral}-{bg,fg,border,solid}`;
  - focus `--sup-color-focus-ring`;
  - skeleton `--sup-color-skeleton-{base,highlight}`.

- [ ] **Step 1: Write the failing tests.**

```js
test( 'every contrast pair meets WCAG 2.2 AA in both modes for every admin scheme', () => { expect( checkContrast( loadTokens() ) ).toEqual( [] ); } );
test( 'every semantic color token is covered by a contrast pair', () => {
	const covered = new Set( pairs.flatMap( ( p ) => [ p.fg, ...p.bg ] ) );
	expect( semanticColorNames().filter( ( n ) => ! covered.has( n ) && ! n.includes( 'backdrop' ) && ! n.includes( 'skeleton' ) ) ).toEqual( [] );
} );
test( 'component tokens reference semantic tokens only', () => {
	for ( const v of Object.values( loadTokens().component ) ) { expect( v ).toMatch( /^var\(--sup-(color|space|radius|font|line-height|letter-spacing|shadow|motion|size|z|border-width)-/ ); }
} );
test( 'the focus ring has 3:1 against canvas, surface and raised surface', () => { /* covered pair asserted explicitly for both modes */ } );
test( 'a brand accent replaces the admin-scheme accents', () => { const s = loadTokens( { brandPath: fixture( 'accessible-brand' ) } ); expect( s.brandAccent ).toBe( true ); expect( renderCss( s ) ).not.toContain( 'admin-color-' ); } );
test( 'an unknown brand key is rejected', () => { expect( validateBrand( { colour: {} } ) ).toEqual( [ 'unknown brand key: colour' ] ); } );
test( 'an inaccessible brand accent fails the contrast check', () => {
	expect( checkContrast( loadTokens( { brandPath: fixture( 'inaccessible-brand' ) } ) ) ).toEqual( expect.arrayContaining( [ expect.objectContaining( { fg: 'color-fg-on-accent', bg: 'color-accent-bg' } ) ] ) );
} );
test( 'generated CSS is deterministic and committed', () => { const css = renderCss( loadTokens() ); expect( renderCss( loadTokens() ) ).toBe( css ); expect( read( 'assets/admin/src/tokens/tokens.generated.css' ) ).toBe( css ); } );
test( 'reduced motion zeroes every duration and distance', () => { expect( reducedMotionBlock() ).toMatch( /--sup-motion-duration-base:\s*0ms/ ); expect( reducedMotionBlock() ).toMatch( /--sup-motion-distance-sm:\s*0/ ); } );
test( 'Arabic scope switches the font family and removes letter spacing', () => { /* :lang(ar) block sets --sup-font-family-sans: var(--sup-font-family-arabic) and every --sup-letter-spacing-*: 0 */ } );
test( 'only --sup-* properties are defined and every selector is scoped', () => { expect( findCssViolations( generatedCss, 'tokens' ) ).toEqual( [] ); } );
test( 'the CSS checker flags raw colors and physical properties', () => {
	expect( findCssViolations( fixtureCss( 'raw-color' ), 'x' ) ).toEqual( [ 'x: raw color #ff0000 in color' ] );
	expect( findCssViolations( fixtureCss( 'physical' ), 'x' ) ).toEqual( [ 'x: physical property margin-left' ] );
} );
test( 'component CSS has no violations', () => { for ( const f of glob( 'assets/admin/src/ui/**/*.css' ) ) expect( findCssViolations( read( f ), f ) ).toEqual( [] ); } );
```

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/tokens`. Expected: FAIL, `Cannot find module '…/tools/admin-ui/tokens/resolve.mjs'`.
- [ ] **Step 3: Author the tokens and the tools.**
  - The anchors and scales come from Design decisions → Token architecture.
  - Each scheme's ramp is tuned from its WordPress theme color until `checkContrast` is empty. The source of each color is `wordpress-develop` `src/wp-admin/css/colors/<scheme>/colors.scss` at 7.1.3, recorded in a `$description` field.
  - `findCssViolations` uses postcss. It allows raw lengths only as `0`, `1px` (hairlines) and `100%`, inside `@media` queries and in `tokens.generated.css`. It flags hex, `rgb()`, `hsl()` and `oklch()` literals outside `tokens.generated.css`, the physical properties (margin/padding/border/inset `left` and `right`, `left`, `right`, `float: left|right`, `text-align: left|right`), and any selector not scoped under `.supcheckout-admin` or `body.supcheckout-admin-page`.
- [ ] **Step 4: Run.** `npm run test:unit -- tests/admin-ui/tokens && node tools/admin-ui/tokens/build-tokens.mjs --check && npm run lint:css`. Expected: PASS.
- [ ] **Step 5: Run all gates.** The build changes `app.css`; commit the rebuilt output.
- [ ] **Step 6: Commit.** `feat(admin-ui): add the --sup-* token system with WCAG 2.2 AA contrast checks in light and dark`

---

### Task 3: Foundations runtime (root, theme, direction, portal layer, icons, text, Numeric)

**Files:**
- Create:
  - `assets/admin/src/ui/foundations/*` (see File Structure) and `assets/admin/src/ui/index.ts`;
  - `tests/admin-ui/unit/support/render.tsx`;
  - `tests/admin-ui/unit/foundations/{theme,SupRoot,direction,Icon,Numeric,Spinner,Heading}.test.tsx`.
- Modify:
  - `jest.config.js`: a `SUP_REACT=19` `moduleNameMapper` to `react-19` and `react-dom-19`;
  - `package.json`: the aliases and the `test:unit:react19` script;
  - `eslint.config.mjs`: `no-restricted-imports` for `@base-ui/react*`, `lucide-react` and `@wordpress/components` outside `assets/admin/src/ui/**`, plus `react/no-unknown-property` defaults;
  - `.github/workflows/admin-ui-certification.yml`: `static` also runs `npm run test:unit:react19`.

**Interfaces:**
- Types: `type ThemePreference = 'light'|'dark'|'system'` and `type ResolvedTheme = 'light'|'dark'`.
- `resolveTheme(preference: ThemePreference, systemPrefersDark: boolean): ResolvedTheme`.
- `SupRoot(props: { preference?: ThemePreference; onPreferenceChange?: (p: ThemePreference) => void; density?: 'comfortable'|'compact'; children: ReactNode })`:
  - it renders `<div class="supcheckout-admin" data-sup-theme? dir lang data-sup-density>`. `data-sup-theme` is present only when the preference is explicit;
  - it creates a body-level portal layer `<div class="supcheckout-admin sup-portal-layer">` with the same `data-sup-theme`, `dir`, `lang` and `data-sup-density`, kept in sync and removed on unmount;
  - it wraps its children in Base UI `DirectionProvider`.
- Hooks:
  - `useTheme(): { preference: ThemePreference; resolved: ResolvedTheme; setPreference(p: ThemePreference): void }`;
  - `useDirection(): 'ltr'|'rtl'`, from `isRTL()` of `@wordpress/i18n`;
  - `usePortalContainer(): HTMLElement|null`;
  - `usePrefersReducedMotion(): boolean`.
- `Icon(props: { name: IconName; size?: 16|20|24; label?: string })`:
  - decorative with `aria-hidden` unless `label` is given; then `role="img"` and `aria-label`;
  - `IconName = 'check'|'close'|'info'|'warning'|'error'|'success'|'search'|'chevron-start'|'chevron-end'|'chevron-down'|'arrow-start'|'arrow-end'|'external'|'copy'|'more'|'sun'|'moon'|'monitor'|'command'|'refresh'|'lock'|'eye-off'`;
  - `DIRECTIONAL_ICONS: ReadonlySet<IconName>` = chevron-start, chevron-end, arrow-start, arrow-end.
- `VisuallyHidden({ children, as?: 'span'|'div' })`.
- `Heading({ level: 1|2|3|4; size?: 'xl'|'lg'|'md'|'sm'; children })`.
- `Text({ variant?: 'body'|'muted'|'caption'|'strong'; as?: 'p'|'span'|'div'; children })`.
- `Numeric({ children: string; kind?: 'amount'|'id'|'count' })` renders `<bdi dir="ltr" class="sup-numeric sup-numeric--<kind>">` with tabular figures.
- `Spinner({ label?: string; size?: 16|20 })`: decorative without a label, `role="status"` with a visually hidden label when one is given.
- `renderInModes(ui: ReactElement): Promise<void>`. A test helper that renders `ui` in light-ltr, dark-ltr, light-rtl and dark-rtl and asserts `toHaveNoViolations()` in each.

- [ ] **Step 1: Write the failing tests.**

```tsx
test( 'system preference resolves from the media query', () => { expect( resolveTheme( 'system', true ) ).toBe( 'dark' ); expect( resolveTheme( 'light', true ) ).toBe( 'light' ); } );
test( 'SupRoot sets data-sup-theme only for an explicit preference', () => {
	const { container, rerender } = render( <SupRoot preference="system">x</SupRoot> );
	expect( root( container ) ).not.toHaveAttribute( 'data-sup-theme' );
	rerender( <SupRoot preference="dark">x</SupRoot> ); expect( root( container ) ).toHaveAttribute( 'data-sup-theme', 'dark' );
} );
test( 'SupRoot follows a system change while the preference is system', () => { mockMatchMedia( false ); /* render, dispatch change(true) */ expect( resolvedText() ).toBe( 'dark' ); } );
test( 'SupRoot mirrors theme, dir and lang onto the portal layer', () => {
	mockIsRTL( true ); document.documentElement.lang = 'ar';
	render( <SupRoot preference="dark">x</SupRoot> );
	const layer = document.body.querySelector( ':scope > .sup-portal-layer' );
	expect( layer ).toHaveClass( 'supcheckout-admin' ); expect( layer ).toHaveAttribute( 'data-sup-theme', 'dark' );
	expect( layer ).toHaveAttribute( 'dir', 'rtl' ); expect( layer ).toHaveAttribute( 'lang', 'ar' );
} );
test( 'the portal layer is removed on unmount', () => { /* unmount -> no .sup-portal-layer */ } );
test( 'directional icons mirror in RTL only', () => { mockIsRTL( true ); expect( icon( 'chevron-end' ) ).toHaveClass( 'sup-icon--mirrored' ); expect( icon( 'check' ) ).not.toHaveClass( 'sup-icon--mirrored' ); } );
test( 'decorative icons are hidden and labelled icons are images', () => { /* aria-hidden="true" vs role img + name */ } );
test( 'Numeric isolates bidi and uses tabular figures', () => {
	render( <p>المبلغ <Numeric kind="amount">12.500 KWD</Numeric></p> );
	const el = screen.getByText( '12.500 KWD' ); expect( el.tagName ).toBe( 'BDI' ); expect( el ).toHaveAttribute( 'dir', 'ltr' ); expect( el ).toHaveClass( 'sup-numeric--amount' );
} );
test( 'a labelled spinner is a polite status', () => { render( <Spinner label="Loading payments" /> ); expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Loading payments' ); } );
test( 'foundations have no axe violations in four modes', () => renderInModes( <FoundationsSpecimen /> ) );
```

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/unit/foundations`. Expected: FAIL, module not found.
- [ ] **Step 3: Implement the foundations.** `SupRoot` reads the system preference through `matchMedia('(prefers-color-scheme: dark)')` with a change listener. It creates the portal layer in a layout effect, appended to `document.body`. `foundations.css` sets:
  - `.sup-portal-layer { position: relative; z-index: var(--sup-z-portal) }` with `--sup-z-portal: 100000`, above the admin bar's 99999;
  - `isolation: isolate` on both roots;
  - `.sup-icon--mirrored { transform: scaleX(-1) }`.
- [ ] **Step 4: Run.** `npm run test:unit -- tests/admin-ui/unit/foundations && npm run test:unit:react19 -- tests/admin-ui/unit/foundations && npm run typecheck`. Expected: PASS.
- [ ] **Step 5: Run all gates**, rebuild and commit the build output.
- [ ] **Step 6: Commit.** `feat(admin-ui): add SupRoot, the scoped portal layer and RTL-aware foundations`

---

### Task 4: `AdminAssets`, the test gallery and the real-WordPress browser lane

**Files:**
- Create:
  - `src/Admin/AdminAssets.php`;
  - `tests/unit/Admin/AdminAssetsTest.php` and `tests/support/wordpress-admin-assets.php` (stubs recording `wp_register_*`, `wp_enqueue_*`, `wp_style_add_data`, `wp_set_script_translations`, `load_script_textdomain`, `wp_add_inline_script` and `add_filter`/`add_action`);
  - the gallery: `tests/admin-ui/gallery/{index.tsx,specimens/foundations.tsx,webpack.gallery.config.cjs}`;
  - the WordPress side: `tests/admin-ui/wp/{supcheckout-ui-gallery.php,make-fixture-translations.php,fixtures/ar-strings.json}`;
  - `tests/admin-ui/playwright.config.ts`;
  - `tests/admin-ui/browser/{scoping,foundations-a11y,admin-schemes,i18n}.spec.ts`.
- Modify:
  - `phpcs.xml.dist` and `phpstan.neon.dist`: add `src/Admin/AdminAssets.php`;
  - `assets/admin/src/app/index.tsx`: mounts `<SupRoot>` into `#supcheckout-admin-root`;
  - `.github/workflows/admin-ui-certification.yml`: add the `browser` job; `gate.needs` gains `browser`.

**Interfaces:**
- Produces `Simplixi\SUPCheckout\Admin\AdminAssets`:
  - constants `HANDLE = 'supcheckout-admin'`, `ROOT_ID = 'supcheckout-admin-root'`, `BODY_CLASS = 'supcheckout-admin-page'`, `TEXT_DOMAIN = 'supcheckout'`, `CHUNK_PATTERN = '#^chunks/[a-z0-9-]+\.[a-f0-9]{8}\.js$#'`;
  - `__construct(string $plugin_file)`, which has no side effects;
  - `hook(string ...$hook_suffixes): void`, which adds `admin_enqueue_scripts` → `enqueue_for` and `admin_body_class` → `body_class` for exactly those suffixes. SP2 calls it with the suffixes `add_menu_page`/`add_submenu_page` return, for pages gated on `manage_woocommerce`;
  - `enqueue_for(string $hook_suffix): bool`: false and nothing enqueued for any other suffix or for an invalid manifest;
  - `body_class(string $classes): string`;
  - `static manifest(string $path): ?array{dependencies: list<string>, version: string}`: strict. Exactly those two keys, a non-empty version, and string dependencies; anything else returns null;
  - `static chunks(string $path): list<string>`: only entries matching `CHUNK_PATTERN`; anything else drops the whole list and returns `[]`;
  - `root_markup(): string`: `<div id="supcheckout-admin-root" class="supcheckout-admin"></div>`, escaped.
- Enqueue details:
  - the style is registered with deps `[]`, the version from the manifest and `wp_style_add_data(HANDLE, 'rtl', 'replace')`;
  - the script loads in the footer;
  - `wp_set_script_translations(HANDLE, 'supcheckout')`;
  - for each chunk it registers `supcheckout-admin-chunk-<name>` (never enqueued) and calls `load_script_textdomain`. When that returns JSON, it adds `wp_add_inline_script(HANDLE, '(function(d){var l=d.locale_data["supcheckout"]||d.locale_data.messages;l[""].domain="supcheckout";wp.i18n.setLocaleData(l,"supcheckout");})(' . wp_json_encode(json_decode($json, true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ');', 'before')`.
- The gallery page `admin.php?page=supcheckout-ui-gallery` takes query `theme=light|dark|system` and renders `[data-specimen="<group>-<state>"]` elements in a fixed DOM order with fixed widths. The shell page `admin.php?page=supcheckout-ui-shell` is wired through `AdminAssets::hook()`.

- [ ] **Step 1: Write the failing PHPUnit tests.**

```php
public function test_enqueue_for_another_screen_enqueues_nothing(): void { self::assertFalse($this->assets()->enqueue_for('index.php')); self::assertSame(array(), supcheckout_test_enqueued()); }
public function test_a_registered_screen_enqueues_script_and_style_from_the_manifest(): void {
    $a = $this->assets(); $a->hook('toplevel_page_supcheckout');
    self::assertTrue($a->enqueue_for('toplevel_page_supcheckout'));
    self::assertSame(array('react-jsx-runtime', 'wp-dom-ready', 'wp-element', 'wp-i18n'), supcheckout_test_script('supcheckout-admin')['deps']);
    self::assertSame('rtl', supcheckout_test_style_data('supcheckout-admin')[0]);
}
public function test_script_translations_use_the_supcheckout_domain(): void { /* wp_set_script_translations('supcheckout-admin','supcheckout') recorded */ }
/** @dataProvider broken_manifests */
public function test_a_missing_or_malformed_manifest_fails_closed(string $json): void { self::assertNull(AdminAssets::manifest($this->fixture($json))); }
public function test_chunk_translations_are_inlined_before_the_app(): void { /* stub load_script_textdomain -> json; inline 'before' contains setLocaleData and "supcheckout" */ }
public function test_a_chunk_path_outside_chunks_drops_the_chunk_list(): void { self::assertSame(array(), AdminAssets::chunks($this->fixture('["../../wp-config.php"]'))); }
public function test_body_class_is_added_only_on_registered_screens(): void { /* body_class on other suffix unchanged */ }
public function test_construct_and_hook_register_no_global_callbacks_beyond_the_two_filters(): void { /* recorded hooks === admin_enqueue_scripts + admin_body_class */ }
```

`broken_manifests`: missing file, `{`, `{"dependencies":"x","version":"1"}`, `{"dependencies":[],"version":""}` and `{"dependencies":[],"version":"1","x":1}`.

- [ ] **Step 2: Run them.** `vendor/bin/phpunit --filter AdminAssetsTest`. Expected: FAIL, `Class "Simplixi\SUPCheckout\Admin\AdminAssets" not found`.
- [ ] **Step 3: Implement `AdminAssets`.** Use `wp_json_file_decode($path, array('associative' => true))` and `plugins_url('assets/admin/build/…', $plugin_file)`. Read the manifest lazily inside `enqueue_for`, never at construct or hook time.
- [ ] **Step 4: Run.** `vendor/bin/phpunit --filter AdminAssetsTest && composer analyse && composer lint:phpcs`. Expected: PASS. Run `php tests/harness/public-surface-snapshot-harness.php`. Expected: unchanged, because nothing in `Plugin` boot calls `AdminAssets`.
- [ ] **Step 5: Write the failing browser tests.**

```ts
test( 'admin assets load only on SUPCheckout screens', async ( { page } ) => {
	for ( const url of [ '/wp-admin/index.php', '/wp-admin/plugins.php', '/wp-admin/admin.php?page=wc-settings&tab=checkout' ] ) {
		await page.goto( url ); await expect( page.locator( 'script[src*="assets/admin/build/app.js"], link[href*="assets/admin/build/app"]' ) ).toHaveCount( 0 );
	}
	await page.goto( SHELL ); await expect( page.locator( 'script[src*="assets/admin/build/app.js"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'body' ) ).toHaveClass( /supcheckout-admin-page/ );
} );
test( 'RTL users get the generated -rtl stylesheet', async ( { page } ) => { /* rtl project: link href contains app-rtl.css; html[dir=rtl] */ } );
test( 'app strings are translated from the supcheckout JSON', async ( { page } ) => { /* rtl project: fixture Arabic string visible */ } );
test( 'tokens resolve inside the app root in all four modes', async ( { page } ) => { /* computed --sup-color-bg-surface equals resolveColor(mode) */ } );
test( 'admin color scheme fixture matches WordPress', async ( { page } ) => { /* for each scheme: wp user meta admin_color, compare --wp-admin-theme-color to fixture */ } );
test( 'foundations gallery has no axe violations', async ( { page } ) => { expect( ( await axe( page ).withTags( TAGS ).include( '.supcheckout-admin' ).analyze() ).violations ).toEqual( [] ); } );
```

The Playwright projects are `light-ltr`, `dark-ltr` (`colorScheme: 'dark'`, preference system), `light-rtl` and `dark-rtl`. Each signs in through `storageState`, as `admin` or `sup-ar` (user locale `ar`, RTL forced by the mu-plugin).

- [ ] **Step 6: Run them before the mu-plugin exists.** Run locally or in CI. Expected: FAIL, the gallery page returns "Sorry, you are not allowed to access this page."
- [ ] **Step 7: Add the mu-plugin, the fixture-translation script, the gallery build and the `browser` job.** The job has a matrix of `{wp: 6.9.10, wc: 10.8.1}` and `{wp: 7.1.3, wc: 11.1.2}`, PHP 8.3. It reuses the pinned WP-CLI and `scripts/install-wp-test-environment.sh` from `r6-final-evidence.yml`, then:
  - copies the mu-plugin and defines `SUPCHECKOUT_UI_GALLERY`;
  - creates the `sup-ar` user;
  - runs `php tests/admin-ui/wp/make-fixture-translations.php "$WP"`;
  - runs `wp i18n make-pot . /tmp/sup.pot --include=assets/admin/build --domain=supcheckout` and asserts that `grep -q 'msgid "SUPCheckout"' /tmp/sup.pot` succeeds;
  - runs `npm run build:gallery && npx playwright install --with-deps chromium && npm run test:browser`.

  No step uses `|| true`. Screenshots and traces upload on failure.
- [ ] **Step 8: Run.** Push and wait for the `browser` jobs. Expected: both versions green.
- [ ] **Step 9: Run all gates.**
- [ ] **Step 10: Commit.** `feat(admin-ui): load the admin app only on SUPCheckout screens with RTL and chunk translations`

---

### Task 5: Actions and form controls

**Files:**
- Create:
  - `assets/admin/src/ui/controls/*` (11 components plus CSS);
  - `tests/admin-ui/unit/controls/*.test.tsx`;
  - `tests/admin-ui/types/controls.test-d.tsx`;
  - `tests/admin-ui/gallery/specimens/controls.tsx`;
  - `tests/admin-ui/browser/controls.spec.ts`.
- Modify: `assets/admin/src/ui/index.ts` (exports).

**Interfaces:**
- `Button`, a `forwardRef<HTMLButtonElement>` with props `ButtonHTMLAttributes & { variant?: 'primary'|'secondary'|'tertiary'|'danger'; size?: 'sm'|'md'; busy?: boolean; busyLabel?: string; iconStart?: IconName; iconEnd?: IconName }`:
  - `busy` sets `aria-disabled="true"`, suppresses `onClick`, shows a decorative spinner, keeps the visible label and keeps focus;
  - `busyLabel` is announced through a visually hidden polite status;
  - `type` defaults to `'button'`.
- `IconButton({ icon: IconName; label: string; … })`. `label` is required by the type, becomes the `aria-label` and adds a `Tooltip` from Task 6 when `showTooltip` is set.
- `Field({ label: string; description?: string; error?: string; required?: boolean; children: (control: { id: string; 'aria-describedby'?: string; 'aria-invalid'?: true; required?: boolean }) => ReactNode })`. It shows "(required)" as visible text, not only an asterisk.
- Text controls:
  - `TextInput` (`InputHTMLAttributes & { invalid?: boolean }`) and `Textarea`;
  - `SecretInput({ label: string; hasSavedValue: boolean; onChange(value: string): void; description?: string })`. When a value is saved, it shows the text `__( 'A key is saved. For security it is never shown.', 'supcheckout' )` and a "Replace key" button. Replacing reveals an empty `type="password"` input with `autocomplete="new-password"` and `spellCheck={false}`. The type accepts no `value` or `defaultValue` prop.
- Choice controls:
  - `Select(SelectHTMLAttributes & { options: { value: string; label: string; disabled?: boolean }[]; invalid?: boolean })`, a native `<select>`;
  - `Checkbox({ label: ReactNode; description?: string; indeterminate?: boolean } & InputHTMLAttributes)`;
  - `RadioGroup({ legend: string; name: string; options: { value: string; label: string; description?: string }[]; value: string; onChange(value: string): void; variant?: 'list'|'segmented' })`, using `fieldset` and `legend`;
  - `Toggle({ label: string; description?: string; checked: boolean; onChange(checked: boolean): void })`, rendered as `<input type="checkbox" role="switch">`;
  - `ThemeToggle()`: a segmented `RadioGroup` with legend "Appearance" and options Light, Dark and Match system, bound to `useTheme()`.

- [ ] **Step 1: Write the failing unit and type tests.**

```tsx
test( 'a busy button keeps focus, announces the busy label and ignores clicks', async () => {
	const onClick = jest.fn(); render( <Button busy busyLabel="Saving" onClick={ onClick }>Save</Button> );
	const b = screen.getByRole( 'button', { name: 'Save' } ); b.focus(); await userEvent.click( b ); await userEvent.keyboard( '{Enter}' );
	expect( onClick ).not.toHaveBeenCalled(); expect( b ).toHaveAttribute( 'aria-disabled', 'true' ); expect( b ).toHaveFocus(); expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Saving' );
} );
test( 'Field wires label, description and error to its control', () => {
	render( <Field label="API key" description="From your UPayments dashboard" error="Key not accepted">{ ( c ) => <TextInput { ...c } /> }</Field> );
	const input = screen.getByLabelText( 'API key' ); expect( input ).toHaveAccessibleDescription( 'From your UPayments dashboard Key not accepted' ); expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
} );
test( 'SecretInput never renders a saved secret and replacing starts empty', async () => { /* no textbox with a value; click "Replace key" -> password input value '' and autocomplete new-password */ } );
test( 'RadioGroup is a fieldset with a legend and arrow keys move selection', async () => { /* role group named by legend; ArrowDown checks next radio */ } );
test( 'Toggle exposes the switch role and its state', () => { /* getByRole('switch',{name}) toBeChecked */ } );
test( 'Checkbox supports indeterminate', () => { /* .indeterminate === true; toBePartiallyChecked */ } );
test( 'ThemeToggle sets the preference', async () => { /* click "Dark" -> onPreferenceChange('dark') */ } );
test( 'controls have no axe violations in four modes', () => renderInModes( <ControlsSpecimen /> ) );
```

```tsx
// tests/admin-ui/types/controls.test-d.tsx
// @ts-expect-error label is required
<IconButton icon="close" />;
// @ts-expect-error a saved secret is never passed in
<SecretInput label="API key" hasSavedValue value="sk_live" onChange={ () => {} } />;
```

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/unit/controls && npm run typecheck`. Expected: FAIL, modules not found, plus the unused-`@ts-expect-error` errors.
- [ ] **Step 3: Implement the controls with their CSS.** Native controls use `appearance: none` with a token-drawn check, radio dot and chevron. Each class selector also neutralizes `::before`/`::after`: WordPress `forms.css` draws its checkmark in `input[type=checkbox]:checked::before`. All sizes come from `--sup-size-control-*`, with a minimum target of 24 px.
- [ ] **Step 4: Run.** `npm run test:unit -- tests/admin-ui/unit/controls && npm run test:unit:react19 -- tests/admin-ui/unit/controls && npm run typecheck && npm run lint:css`. Expected: PASS.
- [ ] **Step 5: Add the specimens and the failing browser tests.**

```ts
test( 'form controls ignore WordPress admin form styles', async ( { page } ) => {
	const checked = page.locator( '[data-specimen="checkbox-checked"] input' );
	expect( await checked.evaluate( ( el ) => getComputedStyle( el, '::before' ).content ) ).toBe( 'none' );
	expect( await cssVar( page, '[data-specimen="text-input-default"] input', 'min-height' ) ).toBe( await token( page, '--sup-size-control-md' ) );
	expect( await page.locator( '[data-specimen="select-default"] select' ).evaluate( ( el ) => getComputedStyle( el ).backgroundImage ) ).toContain( 'sup' ); // our data-URI marker, not WordPress's
} );
test( 'every interactive control is at least 24 by 24 CSS pixels', async ( { page } ) => { /* boundingBox of each button/input/select/[role=switch] >= 24x24 */ } );
test( 'keyboard reaches every control in order with a visible focus ring', async ( { page } ) => { /* Tab through; activeElement matches the expected data-specimen order; outline-width >= 2px and outline-color === focus token */ } );
test( 'forced colors keep the focus indicator', async ( { page } ) => { await page.emulateMedia( { forcedColors: 'active' } ); /* outline-style !== 'none' on focus */ } );
```

- [ ] **Step 6: Run** the `browser` job on both WordPress versions. Expected: PASS after the CSS fixes it reveals.
- [ ] **Step 7: Run all gates**, rebuild and commit the build output.
- [ ] **Step 8: Commit.** `feat(admin-ui): add accessible buttons and form controls, including the write-only SecretInput`

---

### Task 6: Display and feedback

**Files:**
- Create:
  - `assets/admin/src/ui/feedback/*`;
  - `tests/admin-ui/unit/feedback/*.test.tsx`;
  - `tests/admin-ui/gallery/specimens/feedback.tsx`;
  - `tests/admin-ui/browser/feedback.spec.ts`.
- Modify: `assets/admin/src/ui/index.ts`, and `SupRoot`, which renders `<Toaster/>` inside the portal layer.

**Interfaces:**
- Containers:
  - `Card({ title?: string; headingLevel?: 2|3|4; actions?: ReactNode; as?: 'section'|'div'; children })`. A `section` is labelled by its heading.
  - `Badge({ tone?: 'neutral'|'accent'; children })`.
  - `StatusPill({ status: 'success'|'warning'|'danger'|'info'|'neutral'; children: string })`. It shows a decorative icon and visible text; the color is never the only signal.
  - `Notice({ tone: 'info'|'success'|'warning'|'error'; title?: string; live?: boolean; onDismiss?: () => void; children })`. It is not a live region unless `live` is set; then `error` is `role="alert"` and the other tones are `role="status"`. Dismiss is an `IconButton` labelled "Dismiss notice".
  - `EmptyState({ icon?: IconName; title: string; description: string; headingLevel?: 2|3; action?: ReactNode })`.
- Loading and progress:
  - `Skeleton({ shape?: 'line'|'block'|'circle'; width?: string; height?: 'text'|'title'|'control'|string })`, always `aria-hidden`;
  - `SkeletonGroup({ label: string; children })`: `aria-busy="true"` plus one visually hidden status, "Loading {label}…";
  - `ProgressBar({ label: string; value?: number; max?: number; valueText?: string })` on Base UI Progress; indeterminate when `value` is undefined;
  - `Stepper({ label: string; steps: { id: string; label: string }[]; currentId: string; completedIds: string[] })`: an `<ol>` with `aria-current="step"` and visually hidden "Completed" / "Current step" / "Not started".
- Toasts:
  - `useToast(): { show(t: { tone: 'success'|'info'|'error'; title: string; description?: string; action?: { label: string; onClick(): void } }): string; dismiss(id: string): void }`;
  - `TOAST_TIMEOUT_MS = 6000` for success and info, paused while the toast is hovered or focused. Errors never auto-dismiss;
  - at most 3 visible, `aria-live="polite"`, viewport at block-end / inline-end inside the portal layer.
- `Tooltip({ content: string; children: ReactElement; side?: 'top'|'bottom'|'inline-start'|'inline-end' })`:
  - `TOOLTIP_DELAY_MS = 400` on hover, immediate on keyboard focus;
  - hoverable, Escape dismisses (1.4.13), wired through `aria-describedby`;
  - it warns in development and renders no tooltip when the child is `disabled`.

- [ ] **Step 1: Write the failing tests.**

```tsx
test( 'StatusPill conveys status in text, not color alone', () => { render( <StatusPill status="danger">Callback unreachable</StatusPill> ); expect( screen.getByText( 'Callback unreachable' ) ).toBeVisible(); expect( pillIcon() ).toHaveAttribute( 'aria-hidden', 'true' ); } );
test( 'a static notice is not a live region; a live error notice is an alert', () => { /* no role alert/status without live; live+error -> role alert */ } );
test( 'SkeletonGroup is busy and announces loading once', () => { /* aria-busy true; exactly one role status 'Loading payments…'; skeleton children aria-hidden */ } );
test( 'ProgressBar without a value is indeterminate with an accessible name', () => { /* role progressbar named; no aria-valuenow */ } );
test( 'Stepper marks the current step and announces completed steps', () => { /* li aria-current="step"; text 'Completed' in completed li */ } );
test( 'success toasts dismiss after 6000 ms but pause while hovered', async () => { jest.useFakeTimers(); /* show; hover; advance 7000 -> still there; unhover; advance 6000 -> gone */ } );
test( 'error toasts persist until dismissed', () => { /* advance 60000 -> still there */ } );
test( 'toasts render inside the scoped portal layer', () => { expect( toastEl().closest( '.sup-portal-layer' ) ).not.toBeNull(); } );
test( 'Tooltip opens on focus, closes on Escape and describes its trigger', async () => { /* tab to trigger -> role tooltip; trigger aria-describedby; Escape -> hidden */ } );
test( 'feedback components have no axe violations in four modes', () => renderInModes( <FeedbackSpecimen /> ) );
```

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/unit/feedback`. Expected: FAIL, module not found.
- [ ] **Step 3: Implement** with Base UI `Toast`, `Tooltip` and `Progress`, each portaled to `usePortalContainer()`. The skeleton shimmer uses `--sup-motion-duration-slow` and is static under reduced motion.
- [ ] **Step 4: Run.** The unit, react19, typecheck and lint commands as in Task 5. Expected: PASS.
- [ ] **Step 5: Add the specimens and the browser tests.**

```ts
test( 'toast viewport never obscures the focused element', async ( { page } ) => { /* focus [data-specimen="focus-target-block-end"]; trigger toast; focused rect minus toast rect area > 0 */ } );
test( 'reduced motion removes toast and skeleton animation', async ( { page } ) => { await page.emulateMedia( { reducedMotion: 'reduce' } ); /* computed animation-duration of .sup-skeleton and transition-duration of .sup-toast are 0s */ } );
test( 'feedback gallery with a toast and a tooltip open has no axe violations', async ( { page } ) => { /* open both, then axe */ } );
```

- [ ] **Step 6: Run** the `browser` job. Expected: PASS on both versions.
- [ ] **Step 7: Run all gates**, rebuild and commit the build output.
- [ ] **Step 8: Commit.** `feat(admin-ui): add status, notice, empty-state, skeleton, stepper, toast and tooltip components`

---

### Task 7: Overlays, navigation, AppShell and the command palette

**Files:**
- Create:
  - `assets/admin/src/ui/overlays/*`;
  - `tests/admin-ui/unit/overlays/*.test.tsx`;
  - `tests/admin-ui/gallery/specimens/overlays.tsx`;
  - `tests/admin-ui/browser/overlays.spec.ts`.
- Modify:
  - `assets/admin/src/ui/index.ts`;
  - `assets/admin/src/app/index.tsx`: renders `<SupRoot><AppShell title="SUPCheckout" nav={[]} …><EmptyState …/></AppShell></SupRoot>`, with the command palette using an empty registry;
  - `tests/admin-ui/build/build-contract.test.mjs`: the palette chunk exists.

**Interfaces:**
- `Dialog({ open: boolean; onOpenChange(open: boolean): void; title: string; description?: string; size?: 'sm'|'md'|'lg'; dismissible?: boolean; initialFocus?: RefObject<HTMLElement>; footer?: ReactNode; children })` on Base UI Dialog:
  - modal, portaled into the portal layer;
  - the backdrop uses `--sup-color-bg-overlay-backdrop`;
  - focus returns to the trigger on close.
- `ConfirmDialog({ open; onOpenChange; title: string; consequence: ReactNode; confirmLabel: string; cancelLabel?: string; tone: 'money'|'danger'|'neutral'; onConfirm(): Promise<ConfirmResult>; renderResult?(r: ConfirmResult): ReactNode })`:
  - `type ConfirmResult = { status: 'verified'|'failed'|'pending'; message: string }`;
  - Base UI AlertDialog. Initial focus is on Cancel;
  - a ref guard runs `onConfirm` at most once per opening;
  - while pending, Escape and backdrop clicks are ignored, Cancel is `aria-disabled`, and the confirm button is `busy`;
  - on settle it shows a result panel: a `StatusPill` (verified → success, failed → danger, pending → warning), the message, and a single "Close" button that receives focus. It never closes on its own;
  - a rejected promise becomes `{ status: 'failed', message: __( 'The request did not complete. Refresh to see the current state before trying again.', 'supcheckout' ) }`.
- `Tabs({ label: string; tabs: { id: string; label: string; badge?: string; panel: ReactNode }[]; selectedId?: string; onSelect?(id: string): void; activation?: 'automatic'|'manual' })` on Base UI Tabs; arrow keys follow the direction.
- `DataTable<Row>`:
  - props `{ caption: string; captionHidden?: boolean; columns: Column<Row>[]; rows: Row[]; rowKey(row: Row): string; loading?: boolean; skeletonRows?: number; empty?: ReactNode; sort?: { columnId: string; direction: 'ascending'|'descending' }; onSortChange?(columnId: string): void; density?: 'comfortable'|'compact' }`;
  - `Column<Row> = { id: string; header: string; cell(row: Row): ReactNode; align?: 'start'|'end'; numeric?: boolean; sortable?: boolean }`;
  - a semantic `<table>` with `<caption>`, inside `div[role=region][tabindex=0][aria-labelledby=<caption id>]`;
  - sortable headers are buttons, and the `<th>` carries `aria-sort`;
  - numeric cells are wrapped in `Numeric` and end-aligned;
  - `loading` sets `aria-busy` and renders `skeletonRows` (default 5) skeleton rows.
- `AppShell({ title: string; nav: NavItem[]; currentNavId?: string; actions?: ReactNode; children })` with `NavItem = { id: string; label: string; href: string; icon?: IconName }`:
  - a skip link "Skip to content" → `#supcheckout-content`;
  - a sticky `<header>` at `inset-block-start: var(--wp-admin--admin-bar--height, 32px)` holding the `<h1>`, the actions, the `ThemeToggle` and the palette trigger;
  - `<nav aria-label={ __( 'SUPCheckout sections', 'supcheckout' ) }>` with `aria-current="page"`;
  - content in `<div id="supcheckout-content" tabIndex={-1}>`, with no `main` element or role, because wp-admin owns `role=main`;
  - `html:has(body.supcheckout-admin-page)` gets `scroll-padding-block-start` equal to the admin bar plus the header.
- `CommandPalette({ commands: Command[] })` with `Command = { id: string; label: string; group?: string; keywords?: string[]; perform(): void }`:
  - `PALETTE_SHORTCUT`: Meta+K on macOS, Ctrl+K elsewhere, ignored while an IME composition is active;
  - the panel is `lazy(() => import(/* webpackChunkName: "command-palette" */ './CommandPalettePanel'))` in a `Dialog` with Base UI Autocomplete;
  - empty results show the text "No matching commands".
- `normalizeForSearch(s: string): string`: NFKD, removes combining marks including Arabic tashkeel U+064B–U+065F and U+0670, removes tatweel U+0640, lowercases.

- [ ] **Step 1: Write the failing tests.**

```tsx
test( 'ConfirmDialog for money focuses Cancel first', () => { renderConfirm( { tone: 'money' } ); expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toHaveFocus(); } );
test( 'ConfirmDialog calls onConfirm exactly once under double click and held Enter', async () => {
	const onConfirm = jest.fn( () => new Promise<ConfirmResult>( () => {} ) );
	renderConfirm( { onConfirm, confirmLabel: 'Send pay link' } ); const btn = screen.getByRole( 'button', { name: 'Send pay link' } );
	await userEvent.dblClick( btn ); btn.focus(); await userEvent.keyboard( '{Enter>5}{/Enter}' ); await userEvent.click( btn );
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
} );
test( 'ConfirmDialog cannot be dismissed while pending and then shows the verified result', async () => {
	const d = deferred<ConfirmResult>(); const onOpenChange = jest.fn(); renderConfirm( { onConfirm: () => d.promise, onOpenChange } );
	await userEvent.click( confirmBtn() ); await userEvent.keyboard( '{Escape}' ); expect( onOpenChange ).not.toHaveBeenCalledWith( false );
	await act( () => d.resolve( { status: 'verified', message: 'UPayments confirmed the pay link.' } ) );
	expect( screen.getByText( 'UPayments confirmed the pay link.' ) ).toBeVisible(); expect( screen.getByRole( 'button', { name: 'Close' } ) ).toHaveFocus();
} );
test( 'a rejected confirmation shows a failed result and stays open', async () => { /* reject -> danger pill + generic message; dialog open */ } );
test( 'Dialog traps Tab, closes on Escape and returns focus to the trigger', async () => { /* ... */ } );
test( 'tab arrow keys are mirrored in RTL', async () => { mockIsRTL( true ); /* ArrowLeft selects the next tab */ } );
test( 'DataTable renders a captioned table with column headers and aria-sort', () => { /* role table name = caption; columnheader aria-sort ascending; click header -> onSortChange(id) */ } );
test( 'numeric cells are end-aligned and bidi-isolated', () => { /* cell contains bdi[dir=ltr]; class sup-table__cell--end */ } );
test( 'loading shows skeleton rows and aria-busy', () => { /* table aria-busy true; 5 rows of aria-hidden skeletons */ } );
test( 'the scroll region is focusable and labelled by the caption', () => { /* role region name = caption; tabIndex 0 */ } );
test( 'AppShell has a skip link, a labelled nav with aria-current and no main landmark', () => { expect( screen.queryByRole( 'main' ) ).toBeNull(); /* link 'Skip to content' href #supcheckout-content; nav name; aria-current page */ } );
test( 'Mod+K opens the palette and Escape returns focus', async () => { /* Ctrl+K (non-mac) -> dialog; Escape -> focus back */ } );
test( 'search ignores Arabic diacritics and tatweel', () => { expect( normalizeForSearch( 'تَطْبِيـق' ) ).toBe( normalizeForSearch( 'تطبيق' ) ); } );
test( 'Enter performs the active command once and closes', async () => { /* perform called 1 */ } );
test( 'overlays have no axe violations in four modes when open', () => renderInModes( <OverlaysSpecimen open /> ) );
```

Build contract addition: `expect( readJson( 'assets/admin/build/app.chunks.json' ).some( ( c ) => /^chunks\/command-palette\.[a-f0-9]{8}\.js$/.test( c ) ) ).toBe( true );`

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/unit/overlays tests/admin-ui/build`. Expected: FAIL.
- [ ] **Step 3: Implement** the overlays, `AppShell`, `DataTable` and the palette. `ConfirmDialog`'s result panel is the one signature moment: a calm, generous panel with the status pill and the message in `--sup-font-size-lg`. It has no animation beyond the shared overlay transition.
- [ ] **Step 4: Run** the unit, react19, typecheck, lint and build commands. Expected: PASS. The palette chunk stays within the 30 KB gzip budget.
- [ ] **Step 5: Add the specimens and the browser tests.**

```ts
test( 'modal dialog layer covers the WordPress admin bar and menu', async ( { page } ) => { /* open dialog; elementFromPoint at #wpadminbar and #adminmenu centres is inside .sup-portal-layer */ } );
test( 'portaled overlays keep tokens, theme and direction', async ( { page } ) => { /* dark-rtl: dialog popup background === dark --sup-color-bg-surface-raised; closest [dir] is rtl */ } );
test( 'no duplicate main landmark inside wp-admin', async ( { page } ) => { await expect( page.locator( 'main, [role="main"]' ) ).toHaveCount( 1 ); } );
test( 'focused element is never obscured by the sticky header', async ( { page } ) => { /* Tab through the long specimen; for each activeElement, its rect is not fully inside the header or admin-bar rect */ } );
test( 'the command palette chunk loads and its strings are translated', async ( { page } ) => { /* shell page as sup-ar: Control+K -> Arabic fixture placeholder; network saw chunks/command-palette.*.js */ } );
test( 'overlay gallery with dialog, confirm and palette open has no axe violations', async ( { page } ) => { /* each open in turn, then axe */ } );
```

- [ ] **Step 6: Run** the `browser` job. Expected: PASS on both versions.
- [ ] **Step 7: Run all gates**, rebuild and commit the build output.
- [ ] **Step 8: Commit.** `feat(admin-ui): add dialogs, money confirmation, tabs, data table, app shell and the command palette`

---

### Task 8: Visual-regression lane and the Sub-project 1 exit gate

**Files:**
- Create:
  - `tests/admin-ui/visual/gallery.visual.spec.ts`;
  - `tests/admin-ui/visual/baselines/**`, generated by CI and committed by a human;
  - `tests/admin-ui/fonts/{NotoSans-Regular,NotoSans-SemiBold,NotoSansArabic-Regular,NotoSansArabic-SemiBold}.woff2` and `OFL.txt`;
  - `tests/admin-ui/gallery/vr-brand.tokens.json`, which only sets `font.family.sans` and `font.family.arabic` to the vendored faces;
  - `tests/admin-ui/visual/coverage.test.mjs`.
- Modify:
  - `tests/admin-ui/playwright.config.ts`: a `visual` project with `snapshotPathTemplate: '{testDir}/baselines/{arg}{ext}'`, `updateSnapshots: process.env.SUP_UPDATE_BASELINES ? 'all' : 'none'`, `expect.toHaveScreenshot: { maxDiffPixelRatio: 0.0005, animations: 'disabled', caret: 'hide', scale: 'css' }`, `viewport: { width: 1280, height: 900 }`, `deviceScaleFactor: 1`, `reducedMotion: 'reduce'` and `launchOptions.args: ['--font-render-hinting=none', '--force-color-profile=srgb']`;
  - `.github/workflows/admin-ui-certification.yml`: a `visual` job on WP 7.1.3 / WC 11.1.2 / PHP 8.3, the `workflow_dispatch` input `update_visual_baselines`, and `gate.needs` gaining `visual`.

**Interfaces:**
- `SPECIMEN_GROUPS: readonly string[]` is exported from `tests/admin-ui/gallery/specimens/index.ts`. It is the single list the gallery renders and the visual suite iterates.
- `VISUAL_MODES = ['light-ltr', 'dark-ltr', 'light-rtl', 'dark-rtl'] as const`.
- Baseline names: `<group>--<mode>.png`. Two extra specimens, `data-table-compact` and `focus-forced-colors`, run in `light-ltr` only.

- [ ] **Step 1: Write the failing coverage test and the visual spec.**

```js
test( 'a committed baseline exists for every specimen group and mode', () => {
	const expected = SPECIMEN_GROUPS.flatMap( ( g ) => VISUAL_MODES.map( ( m ) => `${ g }--${ m }.png` ) ).concat( [ 'data-table-compact--light-ltr.png', 'focus-forced-colors--light-ltr.png' ] ).sort();
	expect( readdirSync( 'tests/admin-ui/visual/baselines' ).sort() ).toEqual( expected );
} );
test( 'the gallery mu-plugin returns early unless SUPCHECKOUT_UI_GALLERY is true', () => {
	expect( read( 'tests/admin-ui/wp/supcheckout-ui-gallery.php' ) ).toContain( "if ( ! defined( 'SUPCHECKOUT_UI_GALLERY' ) || true !== SUPCHECKOUT_UI_GALLERY ) {\n\treturn;\n}" );
} );
```

```ts
for ( const group of SPECIMEN_GROUPS ) test( `visual ${ group }`, async ( { page }, info ) => {
	await gotoGallery( page, info.project.name ); await page.evaluate( () => document.fonts.ready );
	await expect( page.locator( `[data-specimen-group="${ group }"]` ) ).toHaveScreenshot( `${ group }--${ info.project.name }.png` );
} );
```

- [ ] **Step 2: Run them.** `npm run test:unit -- tests/admin-ui/visual`. Expected: FAIL, `ENOENT … baselines`. In CI, `visual` also fails, with "A snapshot doesn't exist".
- [ ] **Step 3: Add the fonts, the test brand file and the `visual` job.** The job builds the gallery with `SUP_BRAND=tests/admin-ui/gallery/vr-brand.tokens.json`; the gallery config passes it to `build-tokens.mjs` and writes the output into the gallery build only, never into `assets/`. It then runs `npm run test:visual`.

  When dispatched with `update_visual_baselines: true`, the job sets `SUP_UPDATE_BASELINES=1`, uploads `tests/admin-ui/visual/baselines` as an artifact, and fails the gate with "baselines regenerated; commit them and rerun".
- [ ] **Step 4: Generate the baselines.** Dispatch the workflow with `update_visual_baselines: true` on the task branch. Download the artifact, review every image in light, dark and RTL by eye against the design decisions, and commit it as `test(admin-ui): record the visual baselines`.
- [ ] **Step 5: Run.** Push. Expected: `visual` passes. Then rerun the job once. Expected: passes again, so it is stable across runs. The coverage test passes.
- [ ] **Step 6: Prove the gate.** On a scratch commit (not merged), change `--sup-radius-control` from 6 to 7 px. Expected: `visual` fails with a diff and `gate` fails. Revert.
- [ ] **Step 7: Run all gates.**
- [ ] **Step 8: Commit.** `test(admin-ui): add the pinned visual-regression lane and make it part of the certification gate`

---

### Task 9: Living documents, package record and the independent reviews

**Files:**
- Modify:
  - `DESIGN.md`: an "Admin app (Sub-project 1)" section. It says that the admin app is the branded exception to "visually subordinate", per the owner decision of 2026-10-16. It records the token tiers, the light/dark/scheme/brand rules, Arabic typography, motion, the overlay layer and the component inventory, and adds `admin-*` entries to the front-matter token map. The checkout rules stay unchanged.
  - `UX-CONTRACT.md`: Canonical UI Map rows for Dialog, ConfirmDialog (money), Toast, Tooltip, Tabs, DataTable, CommandPalette and the native Select in the admin. "Overlays and feedback" now names the admin dialog primitive and the money-confirmation levels. Verification adds `npm run quality` and `admin-ui-certification.yml`.
  - `premium-ui.json`: `sourceRoots` adds `assets/admin/src`; `requiredCapabilities` adds `Dialog`, `Tabs`, `Tooltip` and `CommandPalette`; `commands.quality-admin` is `npm run quality`.
  - `docs/COMPATIBILITY.md`: an "Admin UI boundary (Sub-project 1)" section. Verified: Chromium (Playwright 1.63.0) on WP 6.9.10/WC 10.8.1 and WP 7.1.3/WC 11.1.2, the React 18.3.1 runtime, LTR plus Arabic RTL, light and dark, the nine core admin schemes. Unit-only: React 19. External: Safari, Firefox, mobile devices, screen-reader manual passes, real Arabic copy review.
  - `docs/project/RELEASE-ENGINEERING.md`: the committed-build and drift contract, the toolchain pins, the source-shipping rule, and the new file count and SHA-256 from `scripts/build-release.sh` at the final head (built twice, byte-identical).
  - `docs/project/START-HERE.md` and `docs/project/PROJECT-STATUS.md`: Sub-project 1 status.
  - `CONTRIBUTING.md`: the npm workflow (`nvm use`, `npm ci`, `npm run quality`) and the visual-baseline update flow.
  - `AGENTS.md`: add `.github/workflows/admin-ui-certification.yml` and "Admin UI token, contrast, build-contract, accessibility and visual-regression suites" to "Permanent quality controls". This is an addition only; the owner reviews this diff.

- [ ] **Step 1: Update the documents.**
- [ ] **Step 2: Build and verify the package.** `bash scripts/build-release.sh dist && bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip`, twice. Expected: VERIFIED, with an identical SHA-256 both times. Record the count and the hash.
- [ ] **Step 3: Run the independent reviews on the whole Sub-project 1 diff from `ccf58f8`.** Fix every CRITICAL and HIGH finding test-first.
  - `ecc:security-reviewer`: `AdminAssets` path and JSON handling, inline-script encoding, the SecretInput rules, and the supply chain (lockfile, licenses, `--ignore-scripts`).
  - `ecc:code-reviewer`.
  - An accessibility review (`wp-accessibility-review` skill) of the gallery in four modes, including a manual NVDA or VoiceOver pass over the dialog, money confirmation, toast, tabs and palette, recorded in the PR.
  - A release review (`wp-ci-cd-and-release-engineering` skill) of the workflow and the package.
- [ ] **Step 4: Confirm the exit gate.** At the exact head, `admin-ui-certification.yml` `gate` is green: the visual-regression and accessibility suites green on both WordPress versions. Every other workflow is green as well.
- [ ] **Step 5: Commit.** `docs(admin-ui): record the Sub-project 1 design system, package and certification state`

---

## PR grouping (why one PR per task)

Each task has its own test cycle and a reviewer could reject it alone. Task 1 (toolchain and release contract) and Task 2 (tokens) are both foundations, but a reviewer judges them on different grounds: release safety and design/contrast. Tasks 5, 6 and 7 depend only on Tasks 3 and 4, so they can run in parallel worktrees. Task 7 uses `Button` (Task 5) and `StatusPill`/`Toaster` (Task 6), so it merges last of the three. Task 8 needs every specimen. Each PR rebuilds and commits `assets/admin/build`, so the drift gate sees source and build in one commit. A later PR rebases onto the earlier merged build and rebuilds; it never hand-merges build files.

## Owner decisions needed (work proceeds on the recommendation)

1. **Token prefix.**
   - The spec §4.1 (and this brief) says `--sup-*`. `NAMING-IDENTITY-STANDARD.md` says new CSS uses `--supcheckout-*` and `.supcheckout` scoping.
   - **Recommendation:** `--sup-*` tokens and `sup-*` classes, scoped under `.supcheckout-admin`. Record the amendment in the naming standard in Task 2. Checkout keeps `--supcheckout-*`. This follows the newer owner decision and keeps the root class canonical.
2. **What "follows the WordPress admin scheme" means.**
   - No core scheme has a dark content area.
   - **Recommendation:** the mode is the manual toggle (Light / Dark / Match system, default Match system). The admin scheme sets the neutral default accent through nine pre-verified ramps. The Sub-project 5 brand accent replaces them.
3. **Shipping the TypeScript source in the ZIP.**
   - **Recommendation:** ship `assets/admin/src/**` and link the public repository in `readme.txt`. This satisfies guideline 4 in the ZIP itself. The cost is about 70 more packaged files.
