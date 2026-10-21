# Sub-project 1 (design-token system and admin component library): certification record, 2026-10-21

This record covers Sub-project 1 of the SUPCheckout revamp. Plan: `docs/superpowers/plans/2026-10-19-subproject-1-design-system.md`. Spec: `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md` §9 (Sub-project 1 exit gate: visual-regression and accessibility suites green). The public boundary is `docs/COMPATIBILITY.md` "Admin UI boundary (Sub-project 1)"; the design rules are `DESIGN.md` "Admin app (Sub-project 1)" and `UX-CONTRACT.md`; the build contract is `docs/project/RELEASE-ENGINEERING.md` "Admin UI build contract (Sub-project 1)".

**Status: DONE / VERIFIED.** Sub-project 1 ships a brand-neutral (brand-ready, not branded) `--sup-*` token system and an accessible React component library, a committed deterministic build, and the permanent Admin UI Certification workflow. It adds no admin screen, menu or REST call. The package is **not owner accepted**, and publication remains **NOT AUTHORIZED**.

Owner decision 2026-10-20 (binding): the owner shares the full revamp, branding and information architecture only once functionality, security and performance are verified. The next program is therefore the hardening program (a full functionality, security and performance audit, then a fix loop). Sub-project 2 screens, the wizard IA, the menu structure and the visual checkout redesign wait for the owner's information architecture and branding.

## Merged PRs

Every PR was squash-merged after all of its exact-head workflows succeeded. The run ids are the exact-head runs on each PR's final head.

| PR | Scope | Final head | Merged main | Exact-head runs (all SUCCESS) |
|---|---|---|---|---|
| #195 | Plan | `e13d60c87fd2b06e34e6ca656146297d085c9011` | `c8e85f4d5a797923099e00e3e825380a037faab5` | Quality Gates 39250258015, Compatibility 39250257905, Release Artifact 39250258011, Ecosystem 39250258035, CodeQL 39250256002 |
| #196 | Task 1 — pinned toolchain, committed-build contract, Admin UI Certification | `55f099eb10780be07af658c51367235efd0e7d0a` | `55899aef09cc9d3d41e1898cda59bb2317d20bdd` | Admin UI 39267922475, Quality Gates 39267922871, Compatibility 39267922477, Release Artifact 39267922836, WordPress.org 39267922822, Takeover Matrix 39267922461, CodeQL 39267914985 |
| #197 | Task 2 — `--sup-*` tokens, WCAG 2.2 AA contrast in light and dark | `7bfe20329584397377f7f8e224cdc9fd80dc8f4d` | `a01a7b0c158f1244d1ed4f0ef34e63299334c3d9` | Admin UI 39293017768, Quality Gates 39293017588, Compatibility 39293017526, Release Artifact 39293017802, WordPress.org 39293017590, CodeQL 39293013875 |
| #199 | Task 3 — `SupRoot`, scoped portal layer, RTL-aware foundations | `82e6e21bb75c8a8e5f9e75a594f15526721afa34` | `4495ad60579d60d6eb533037bda1699a3d44ae02` | Admin UI 39298659799, Quality Gates 39298659690, Compatibility 39298659893, Release Artifact 39298659898, WordPress.org 39298659668, CodeQL 39298656151 |
| #200 | Dev-tooling advisories patched; the audit blocks every severity from low up | `320a51ea9b2c917c3ec19c6e4c6148b183c6bc9c` | `dc3ac3f87c51dacfae3d360387b3f76115bc404f` | Admin UI 39315372211, Quality Gates 39315372115, Compatibility 39315372161, Release Artifact 39315372134, CodeQL 39315369005 |
| #202 | Task 4 — `AdminAssets` loader, test gallery, real-WordPress browser lane | `78e80308afd1fbf1633dd291b8415033d088247e` | `a4d1850ee7b82f5b4ab169f6b30c03d28c7d4dda` | Admin UI 39358437915, Quality Gates 39358437909, Compatibility 39358437945, Release Artifact 39358437934, WordPress.org 39358437882, Takeover Matrix 39358437869, CodeQL 39358435164 |
| #203 | Task 5 — buttons and form controls, write-only `SecretInput` | `091ab05a6f80eb2ec62d46cd169878c38f1f00aa` | `6686e8a7f77c41346eda36cf6dd2f3504903aa2f` | Admin UI 39369148597, Quality Gates 39369148689, Compatibility 39369148690, Release Artifact 39369148564, WordPress.org 39369148587, CodeQL 39369145578 |
| #205 | Plugin Check wp-env held to the 14-day dependency cooldown | `e5017a3dd104a0079f426d99ddc0fd7beda9d023` | `eb103cf60feded1ec911a4a18291690e72f30ccb` | Admin UI 39435495271, Quality Gates 39435495495, Compatibility 39435495583, Release Artifact 39435495551, WordPress.org 39435495537, CodeQL 39435488817 |
| #204 | Task 6 — status, notice, skeleton, stepper, toast, tooltip | `6b581aa1f1d31d518fa8dccfe968870f02d51eb2` | `b08222c34e7943c65807caece0b88320e231e8de` | Admin UI 39473793132, Quality Gates 39473793366, Compatibility 39473793315, Release Artifact 39473793171, WordPress.org 39473793465, CodeQL 39473786682 |
| #206 | Task 7 — dialogs, money-safe confirmation, tabs, data table, app shell, command palette | `d7e2efb4f2e6e97603661abb0b8cfb05f788ab38` | `b53a268b15c7e8cb22e4d297986ef274d83f2cf1` | Admin UI 39490521473, Quality Gates 39490521862, Compatibility 39490522416, Release Artifact 39490522451, WordPress.org 39490522111, CodeQL 39490516784 |
| #207 | Task 8 — pinned visual-regression lane and the Sub-project 1 exit gate | `dde7a40726e5e081c8c843597a8ec8836022da2b` | `ef9a101ec0ce2d106e1a0418523ed01a6d77ac9a` | Admin UI 39570193162, Quality Gates 39570192907, Compatibility 39570192883, Release Artifact 39570192934, WordPress.org 39570193148, Ecosystem 39570193035, CodeQL 39570187916 |
| Task 9 | This record, the living documents and the governance tests (documents only) | the closeout PR head | — | exact-head gates of the closeout PR |

Not merged: #198 (React 19, TypeScript 7 and `globals` 17 majors) was closed, because the runtime React is WordPress's 18.3.1 and TypeScript 7 breaks `typescript-eslint`. #201 (`globals` 16 → 17, lint-only) was deferred until the SP1 stack merged and is still open.

`ef9a101ec0ce2d106e1a0418523ed01a6d77ac9a` is the last Sub-project 1 commit that changes distributable bytes.

## Exit gate

The Sub-project 1 exit gate is `admin-ui-certification.yml` job `gate`: it succeeds only when `static`, `build-windows`, `browser` (both WordPress versions) and `visual` all succeed; a skipped or cancelled lane fails it, and a baseline-update dispatch always fails it.

- **Exact head of Task 8** (`dde7a40`): Admin UI Certification run **39570193162** SUCCESS, including the browser and accessibility suite on WP 6.9.10 / WC 10.8.1 and WP 7.1.3 / WC 11.1.2 and the exact visual suite on WP 7.1.3 / WC 11.1.2.
- **Post-merge on `main`** (`ef9a101`, recorded 2026-10-21): Admin UI Certification run **39575252375** SUCCESS; Quality Gates 39575252463, WordPress.org Submission Check 39575252335, Supply Chain Evidence 39575252609, R6 Final Evidence 39575252479 and CodeQL 39575253276 SUCCESS. Compatibility Certification 39575252634, Ecosystem Certification 39575252440 and Release Artifact 39575252360 were still queued when this was recorded and are not claimed.
- **Stability:** two fresh dispatched runs at `dde7a40` with exact comparison, **39567360421** and **39568574767**, both SUCCESS.
- **The gate catches a change:** dispatched proof run **39567446898** (scratch commit `7b575b7`: `--sup-radius-control` 6 px → 7 px, CSS rebuilt) FAILED with 69 visual differences under the exact comparison; only groups with no control-radius surface passed. The superseded tolerances caught too little: proof run 39503943298 (Playwright's default per-pixel threshold) passed, and 39505836011 (a 0.0005 pixel ratio) caught only 7.

## Package

`supcheckout-0.1.0.zip`: **224 files**, SHA-256 `67beb72db5e829af5557a258db11c022312312589c11f156b6ce7665e0ed65ac`. Built with `scripts/build-release.sh` twice at the Task 9 closeout head, **byte-identical**, and verified both times with `scripts/verify-release.sh` (VERIFIED). The same bytes come from `ef9a101` and from the Task 8 head `dde7a40`; Task 9 changes no distributable byte. Sub-project 1 adds 90 files to the 134 of 0c: 9 committed build files under `assets/admin/build/`, 80 source files under `assets/admin/src/` and `src/Admin/AdminAssets.php`. The package grew task by task (146, 151, 165, 166, 188, 209, 223 and then 224 files); those intermediate builds are superseded, and each PR records its own count and hash. This package is **not owner accepted**.

## Bundle sizes

Measured on the committed build (gzip level 9, the build-contract method). Budgets: `app.js` 60 KiB, `app.css` 16 KiB, each lazy chunk and the whole palette payload 30 KiB.

| File | Bytes | gzip |
|---|---:|---:|
| `app.js` | 113804 | 38379 |
| `app.css` | 54356 | 7670 |
| `app-rtl.css` | 54356 | 7670 |
| `chunks/884.176adad5.js` (Base UI Autocomplete, async vendor split) | 47771 | 16806 |
| `chunks/command-palette.a159e014.js` | 2104 | 1036 |
| Command palette payload (both chunks) | 49875 | 17622 |

Bundled third-party code: `@base-ui/react` 1.8.0, `@base-ui/utils` 0.4.0, `@floating-ui/utils` 0.2.12 and `use-sync-external-store` 1.7.0 (MIT), and `lucide-react` 1.52.0 (ISC). Runtime externals: `react`, `react-dom`, `react-jsx-runtime`, `wp-dom-ready`, `wp-element` and `wp-i18n`.

## Rulings

Each controller ruling recorded its cost if wrong; the full ledger is in the PR conversations.

- **Audit gate (Task 1).** The full-tree `npm audit` is an allow-list gate. An entry needs a GHSA id, package, severity, expiry and reason, must still be reported, and must not reach a bundled dependency. A bare `npm audit --audit-level=high` could never pass while braces has no patched release. After #200 the gate blocks every severity from low up.
- **Contrast (Task 2).** WCAG 2.2 AA 1.4.11 binds, so the light strong border was darkened to the lightest value with 3:1 on canvas, surface, raised, sunken and accent-subtle (`#858B96`). Subtle text was retuned to 4.5:1 rather than restricted, because a usage restriction cannot be enforced in component code. Four-value physical shorthands are not flagged, because rtlcss flips them.
- **Scope root (Task 4).** `SupRoot` is the single scope root; the PHP host element carries no `.supcheckout-admin` class.
- **Live regions (Task 6).** A real fix with `LIVE_REGION_DELAY_MS` = 100 (the react-aria pattern). Stepper keeps `aria-current` without a hidden "Current step". Dark status tints use the calm `*-950` steps. The limited-toast re-announcement goes to the owner's manual screen-reader list.
- **Toast hold (Task 6).** The background hold applies only when Base UI cannot see the background window (mui/base-ui#5955). Its release is deferred with `setTimeout(0)`, and toasts unseen in the background stay held.
- **Mod+K (Task 7).** Mod+K yields to WordPress core's command palette where core loads it; Sub-project 2 registers SUPCheckout commands into `core/commands`. While any SUPCheckout modal is open, Mod+K is marked handled, so core's palette cannot open over a pending confirmation.
- **Money safety (Task 7).** The ConfirmDialog in-flight guard survives a close and reopen, and a result that arrives after close is ignored. The optional `actionKey` and a module-level in-flight set extend the guard across instances.
- **Visual determinism (Task 8).** Comparison is exact (`threshold 0`, `maxDiffPixels 0`) on a pinned `ubuntu-24.04` runner, with two fresh exact runs and a re-proved radius gate. Fonts come from `@fontsource` development dependencies (OFL Noto Sans, Noto Sans Arabic, Noto Sans Mono), never shipped, and are applied through a test-only brand file. `--disable-partial-raster` is accepted. A fallback tolerance of at most 14 px for `confirm-dialog-pending` alone is approved only if the partial-raster flake returns.
- **Plugin Check (#205).** `@wordpress/env` 12.0.0 depended on an unpublished package, and `plugin-check-action` installs wp-env unpinned. The job therefore sets `npm_config_before` to now minus 14 days, the same cooldown policy as the rest of the toolchain.

## Independent reviews (whole Sub-project 1 diff from `ccf58f8`)

The controller runs these reviews separately, on the whole diff, once this closeout PR is ready.

- **Security review:** APPROVE, with no Critical, High or Medium finding. Low findings:
  - L1: `AdminAssets::CHUNK_PATTERN` `$` also matches before a trailing newline (use `\z` or the `D` modifier).
  - L2: `AppShell` navigation `href` values are unchecked (risk of a `javascript:` URL in Sub-project 2).
  - L3: `enqueue_for(string)` throws a `TypeError` when another plugin passes `null`.
  - L4: `plugin-check-action` installs wp-env unpinned; the 14-day cooldown is in place and no secrets are involved.
  - Documentation: `readme.txt` should say `npm ci --ignore-scripts`.

  Controller ruling: L1, L2, L3 and the readme wording are fixed in one Sub-project 1 follow-up PR, together with the code-review findings. L4 stays recorded. That follow-up changes distributable bytes, so it records its own package.
- **Code review, accessibility review (the gallery in four modes) and release review:** run by the controller and recorded in the closeout PR.

## Pending owner acknowledgements

Work proceeded on these recommendations; each waits for the owner's acknowledgement.

1. The `--sup-*` token prefix, scoped under `.supcheckout-admin`.
2. Light and dark with a manual toggle that defaults to the system preference.
3. Shipping the human-readable TypeScript source (`assets/admin/src/**`) in the package.
4. The audit allow-list: braces GHSA-vfj7-8cjw-p6xm and sprintf-js GHSA-hp3w-g68c-fv3c (no patched release, development-only, expiring 2027-01-17), and the two markdown-it entries below. Alternatively, the owner may require a bare `npm audit --audit-level=high`.
5. The darkened colour values: the light strong border `#858B96` (drafted `#8A919C`), the retuned subtle text, and the dark `*-950` status tints.
6. Mod+K yields to WordPress core's command palette.
7. The `AGENTS.md` diff in the closeout PR: the gate sentence edit and the permanent-controls addition.

## Owner manual test list

CI cannot do these. They are External / manual in `docs/COMPATIBILITY.md`.

1. **Screen readers: NVDA (Windows, Firefox or Chrome) and VoiceOver (macOS Safari).** On the gallery, check:
   - the dialog: name, focus in, focus return;
   - the money confirmation: question, consequence, the busy state, and the result announced with the focus move to Close;
   - toasts: polite announcement; whether a limited toast that becomes visible again is re-announced;
   - tabs: roving focus and RTL arrows;
   - the command palette: search results and the count.
2. **Real Windows High Contrast** (a real contrast theme, not emulation): every boundary, focus ring, selected state and status stays visible.
3. **Safari, Firefox and mobile devices:** the same gallery in the four modes. Only Chromium is automated.
4. **Real Arabic copy review:** a native speaker reviews the Arabic strings and their rendering. The gallery shows English in RTL, and only the `foundations-arabic` specimen shows real Arabic text.

## Hardening tracker

Carried into the hardening program:

- **markdown-it 14.3.3, after 2026-10-30.** The allow-list entries GHSA-vfgh-jcmw-g37q (low) and GHSA-gfr2-rv44-r23c (high) are development-only (markdownlint-cli inside `@wordpress/scripts`, which no repository script runs) and expire 2026-11-06. The fix 14.3.3 was published 2026-10-16 and clears the 14-day release-age floor (`min-release-age=14`) on 2026-10-30. Adopt it after 2026-10-30 with a lockfile-only change; the stale-entry check then fails until both entries are removed in the same change. Dependabot PR #208 (14.3.2 → 14.3.3) waits for that date.
- **`wp-scripts lint-js --fix <path>` rewrote files outside its path**, including payment JavaScript, during Sub-project 1. The cause is not explained. The guard so far is documentation only (`CONTRIBUTING.md`: use `npm run lint:js`, never `--fix` with a path); the hardening audit investigates it.
- **Dependabot #201** (`globals` 17, lint-only) can now rebase and merge if green.
- **Optional:** `wp transient delete _wc_activation_redirect` in the browser job, for determinism.
- **Deferred:** a CODEOWNERS entry for the audit directory. This is a single-owner repository, and review is already required.

## Open Sub-project 5 decision

**Bidi of untranslated English in RTL.** Tooltip (and ProgressBar's value text) use `unicode-bidi: plaintext`, so an untranslated English string takes its own direction. Toast and Notice do not, so the same string follows the RTL paragraph. Sub-project 5 decides one rule against the real Arabic copy.

## SP2 rules carried from SP1

Sub-project 2 (the admin screens) must apply every one of these. They were parked during Sub-project 1 because they need a screen, a route or a server endpoint.

1. **Admin notices.** Emit `<hr class="wp-header-end">` before the app root, so WordPress places admin notices above the app and not inside it.
2. **Failed asset load.** Show an admin notice when `AdminAssets::enqueue_for()` returns false on a registered screen (for example, a corrupt manifest), instead of a blank page.
3. **Leaving mid-request.** Add a `beforeunload` guard while a ConfirmDialog request is pending.
4. **Server-side idempotency.** Refunds and charges need server-side idempotency. The ConfirmDialog guard is a UI guard only, and the server is the real guarantee (AGENTS.md: never blindly retry non-idempotent operations).
5. **Commands.** Register SUPCheckout commands into WordPress core's palette (`wp.data.dispatch( 'core/commands' ).registerCommand()`). The SUPCheckout palette binds Mod+K only where core's palette is absent.
6. **Chunk budget.** Scope the combined chunk budget in the build-contract test to the palette chunks once Sub-project 2 adds other lazy chunks. Today every lazy chunk belongs to the palette.
7. **Keep ConfirmDialog mounted.** Keep it mounted while its request may be in flight, and pass `actionKey` (for example `refund:10234`) whenever the screen may remount it (a route change, a list re-render). Keep the key stable for one opening.
8. **StrictMode.** Guard one-shot mount toasts under React StrictMode, so a toast fired from a mount effect is not shown twice in development.
9. **Bounded `onConfirm`.** Give `onConfirm` a bounded network call (for example `AbortSignal.timeout(…)`), and map a timeout to `{ status: 'pending' }`, never `failed`, because the server may still complete it. A promise that never settles keeps the dialog and its `actionKey` pending until the page reloads.

Also from the reviews: AppShell navigation `href` values must be relative or `http(s)` (security review L2, fixed in the Sub-project 1 follow-up), and the theme preference is persisted server-side (user meta through `supcheckout/v1`) in Sub-project 2.

No real secrets, card data, customer data or production data are recorded here.
