# Local Final Verification — Pre-Release Owner Runbook

**Purpose:** final local/staging acceptance of the exact candidate after repository CI is green and before any fresh owner technical acceptance or publication decision.

This runbook does **not** authorize publication or a live monetary transaction.

## 1. Preconditions

Do not start local final verification until all of these are true:

- the candidate is an exact immutable Git SHA;
- all required GitHub checks for that exact SHA are terminal success;
- no unresolved review threads remain;
- the release artifact for that exact SHA is available;
- living release/provider/design documents match the candidate;
- `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED` is still treated as a release blocker unless durable provider evidence has resolved it.

A candidate that changes distributable runtime/UI bytes after owner acceptance at `146d65a1c182630c1acc651cacafe30cff5f6b79` requires a **fresh** package hash and fresh owner acceptance.

## 2. Clean-clone identity

Run from a clean clone/worktree:

```bash
git fetch --prune --tags origin
git status --short
git rev-parse HEAD
git rev-parse origin/main
git branch --show-current
git worktree list
git stash list
```

Record the exact candidate SHA. Do not continue from an uncommitted or mismatched checkout.

## 3. Portable repository quality gate

Use the repository's supported PHP/Composer environment:

```bash
composer install --no-interaction --prefer-dist
composer quality
```

Required result: exit code 0. No skipped/failing unit, JavaScript harness, static-analysis or PHPCS gate may be hand-waved.

## 4. Deterministic release package

From the exact candidate:

```bash
rm -rf dist/local-final
mkdir -p dist/local-final
bash scripts/build-release.sh dist/local-final
ZIP="$(find dist/local-final -maxdepth 1 -type f -name 'supcheckout-*.zip' -print -quit)"
test -n "$ZIP"
bash scripts/verify-release.sh "$ZIP"
bash tests/security/r6-secret-scan.sh "$ZIP"
sha256sum "$ZIP"
unzip -Z1 "$ZIP" | wc -l
```

Record:

- candidate SHA;
- ZIP filename;
- ZIP SHA-256;
- file count;
- successful `verify-release.sh` output;
- successful exact-ZIP secret scan.

The local package must match the exact-head CI package hash. A byte mismatch is a hard stop.

## 5. Frontend Design Premium gate

Run the installed **Frontend Design Premium** static audit against the repository root in strict mode using the skill-provided `audit_project.py`. Use the actual installed skill path; do not invent a filesystem path.

Required project contracts:

- `DESIGN.md`;
- `UX-CONTRACT.md`;
- `premium-ui.json`.

Required result: no unresolved canonical-owner finding and no unaccepted error-level violation. Preserve the audit output as evidence.

Also verify `DESIGN.md` still reflects the actual checkout/account/admin CSS and that the Canonical UI Map in `UX-CONTRACT.md` matches the runtime owners.

## 6. Real-browser local acceptance

Install the **exact built ZIP**, not a source symlink, into an isolated HTTPS-capable WordPress/WooCommerce staging environment.

Exercise at minimum:

### Classic checkout

- desktop and 390px-class mobile viewport;
- all currently enabled UPayments method rows;
- keyboard-only navigation and visible focus;
- save-card consent visibility/eligibility;
- disabled/busy checkout behavior;
- no console/page errors;
- no failed or >=400 SUPCheckout asset requests;
- no horizontal overflow;
- no secret/token/user-identity leakage in DOM/logs.

### Blocks checkout

Repeat the same interaction/state checks, including:

- subscription purchase type/interval controls when eligible;
- localized interaction copy;
- live-region toast semantics;
- decorative icon semantics;
- payment-method selected state.

### Arabic / RTL

- switch WordPress to Arabic;
- verify Classic + Blocks checkout direction and alignment;
- verify account subscription controls;
- verify no clipped labels or page-level horizontal overflow;
- verify focus remains visible and logical spacing mirrors correctly.

### Reduced motion

Enable `prefers-reduced-motion: reduce` and confirm spinner/toast transitions do not depend on motion for meaning.

### My Account subscription controls

- active subscription: pause + unsubscribe disclosure present;
- paused subscription: resume + unsubscribe disclosure present;
- unsubscribe uses the owned inline confirmation and explicit “Confirm unsubscribe” action;
- cancelled/ineligible/auto-deduct cases fail closed according to the maintained contract;
- no native browser `alert()`, `confirm()`, or `prompt()`.

### Migration admin

- guest cannot access the migration page;
- authorized admin can access it;
- nonce is present;
- preflight is the safe default;
- Execute requires explicit confirmation;
- malformed/XSS-like input is rejected and safely escaped;
- credentials are never rendered;
- result output is redacted;
- textarea does not resize outside the bounded admin layout.

## 7. Browser/accessibility evidence

For each required surface, retain screenshots or a short screen recording and record:

- browser/version;
- OS;
- viewport;
- locale/direction;
- theme;
- storage mode where relevant;
- console errors;
- failed plugin-owned network requests;
- keyboard/focus result;
- accessibility result.

Automated axe/WCAG checks complement, but do not replace, keyboard and visual review.

## 8. No-live-payment boundary

Do **not** perform a real monetary transaction unless the owner explicitly provides:

```text
OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES
```

Without that token, stop before provider payment completion. The maintained live-payment plan remains `LIVE-PAYMENT-ACCEPTANCE-PLAN.md`.

## 9. External gates that local verification cannot close

Even a perfect local run cannot prove:

- the unresolved production Charge/Status authentication contract;
- real merchant production payment completion;
- independent professional penetration testing;
- PCI/legal/acquirer determinations;
- paid/licensed WPML/WCML/commercial-theme coverage;
- excluded saved-card/recurring/wallet production claims without their feature-specific gates.

Do not convert these into repository “PASS” labels.

## 10. Acceptance record

After all repository and local gates pass, record a fresh owner acceptance containing at minimum:

```text
FINAL_CANDIDATE_SHA=<40-hex>
FINAL_ZIP_SHA256=<64-hex>
FINAL_ZIP_FILES=<integer>
LOCAL_FINAL_VERIFICATION=PASS
OWNER_TECHNICAL_ACCEPTANCE=<new explicit acceptance token>
PUBLICATION_AUTHORIZATION=NO
```

Publication remains separate. Do not create a tag, GitHub Release or WordPress.org submission until the owner explicitly authorizes publication and all mandatory release blockers are closed.
