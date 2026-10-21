# Contribution Policy

Thank you for helping improve **SUPCheckout for UPayments**.

## Current contribution model

SUPCheckout is maintained under the Simplix Innovations engineering and release process.

Public bug reports, compatibility reports, reproduction cases and technical evidence are welcome through GitHub Issues. External code pull requests are **not accepted by default** because payment-flow ownership, compatibility migrations and release responsibility remain inside the maintainer process.

Open a code pull request only when a maintainer explicitly requests one.

## Before reporting a defect

Provide the smallest reproducible case and include, where relevant:

- SUPCheckout version or exact commit;
- WordPress, WooCommerce and PHP versions;
- Classic or Blocks checkout;
- HPOS or legacy order storage;
- relevant theme/plugins and payment feature;
- expected vs actual behavior;
- sanitized logs or screenshots.

Never include API keys, bearer tokens, card data, customer/card tokens, customer PII, token-identity secrets/provenance or production database exports. Security findings belong in the private process described in [`SECURITY.md`](SECURITY.md).

## Engineering standard for requested changes

Read [`AGENTS.md`](AGENTS.md) and the relevant project-control documents before implementation.

Requested changes must be:

- narrowly scoped and reviewable;
- backward-conscious;
- explicit about payment/security impact;
- covered by reproducible validation;
- accompanied by migration and rollback/recovery reasoning when persistent identity or financial state is affected.

Payment-flow behavior, persisted IDs and provider contracts must not be changed for naming or cosmetic uniformity.

## Admin UI (React) workflow

The admin app lives in `assets/admin/src/` and its build is committed under `assets/admin/build/`. The release builder never runs npm; it packages the committed build ([`docs/project/RELEASE-ENGINEERING.md`](docs/project/RELEASE-ENGINEERING.md) "Admin UI build contract"). Design rules are in [`DESIGN.md`](DESIGN.md) "Admin app (Sub-project 1)" and [`UX-CONTRACT.md`](UX-CONTRACT.md).

```sh
nvm use                     # Node 24.21.0 from .nvmrc; npm 11.19.0 (engine-strict)
npm ci --ignore-scripts     # locked install; no install-time scripts
npm run quality             # lint JS and CSS, typecheck, unit tests (jest-axe), build, drift check
npm run test:unit:react19   # the same component tests on React 19
```

- Change the source, run `npm run build`, commit the source and the rebuilt `assets/admin/build/` together, then run `npm run quality`. Its `check:drift` step fails when the committed build differs from a clean rebuild or when `assets/admin` holds uncommitted changes.
- `check:drift` is a POSIX shell command. On Windows run npm with Git Bash as the script shell: set `npm_config_script_shell` to Git Bash's `bash.exe` (for example `C:\Program Files\Git\bin\bash.exe`), as the `build-windows` CI lane does.
- Build in a clean environment: do not set `NODE_ENV` or `BROWSERSLIST` (or a `.browserslistrc`), because they change the emitted bundle and the drift gate then fails against CI's build.
- Never hand-merge build files. On a conflict in `assets/admin/build/`, rebase onto the target branch, take either side, run `npm run build`, and commit the result.
- Never edit `assets/admin/build/` or `assets/admin/src/tokens/tokens.generated.css` by hand. Tokens change in `assets/admin/src/tokens/*.tokens.json`; the build regenerates the CSS and fails on any WCAG 2.2 AA contrast failure.
- Lint with `npm run lint:js` only. Do not run `wp-scripts lint-js --fix` with a path: in Sub-project 1 it rewrote files outside that path, including payment JavaScript.
- New dependencies are pinned exactly and must be at least 14 days old (`.npmrc`); they normally arrive through Dependabot. `npm run audit:full` must stay green.

### Visual-regression policy

The Admin UI Certification `visual` lane compares every gallery specimen in four modes (light and dark, LTR and RTL) on WP 7.1.3 / WC 11.1.2.

- Comparison is exact: `threshold 0` and `maxDiffPixels 0`, on a pinned `ubuntu-24.04` runner.
- A red visual run is investigated, never retried or loosened blindly. Download the `admin-ui-visual-diffs` artifact and find the cause.
- Any tolerance is a reviewed, per-group exception, recorded in the Playwright configuration with its reason. The only known candidate is a conditional fallback of at most 14 px for `confirm-dialog-pending`, used only if its partial-raster flake returns despite `--disable-partial-raster`.

### Regenerating the baselines

1. Dispatch `admin-ui-certification.yml` on your branch with `update_visual_baselines: true`. The run rewrites every baseline, uploads them as the `admin-ui-visual-baselines` artifact, and its gate fails on purpose, so an update is never green on its own.
2. Download the artifact and review every image by eye against the change you meant to make.
3. Commit the reviewed images to `tests/admin-ui/visual/baselines/` and push. The gate stays red until they are committed and a normal run passes.

Regenerate in these cases:

- **A deliberate visual change.** Regenerate in the same PR.
- **A specimen added early in a gallery view.** It shifts the specimens below it, so their baselines change too.
- **A move to a new runner OS image.** Do it in a deliberate regeneration PR of its own, never mixed with other changes.
