<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/simplix-innovations-logo-white.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/simplix-innovations-logo-black.svg">
    <img src=".github/assets/simplix-innovations-logo-black.svg" alt="Simplix Innovations" width="260">
  </picture>
</p>

<h1 align="center">SUPCheckout for UPayments</h1>

<p align="center">
  WooCommerce payment gateway integration for UPayments with certified Classic and Blocks compatibility,<br>
  deterministic releases and payment-state safeguards.
</p>

<p align="center">
  <a href="https://github.com/SimplixInnovations/supcheckout/actions/workflows/quality-gates.yml"><img alt="Quality Gates" src="https://img.shields.io/github/actions/workflow/status/SimplixInnovations/supcheckout/quality-gates.yml?branch=main&label=Quality&style=flat-square"></a>
  <a href="https://github.com/SimplixInnovations/supcheckout/actions/workflows/compatibility-certification.yml"><img alt="Compatibility Certification" src="https://img.shields.io/github/actions/workflow/status/SimplixInnovations/supcheckout/compatibility-certification.yml?branch=main&label=Compatibility&style=flat-square"></a>
  <a href="https://github.com/SimplixInnovations/supcheckout/actions/workflows/release-artifact.yml"><img alt="Release Artifact" src="https://img.shields.io/github/actions/workflow/status/SimplixInnovations/supcheckout/release-artifact.yml?branch=main&label=Release%20Artifact&style=flat-square"></a>
  <a href="https://github.com/SimplixInnovations/supcheckout/actions/workflows/provider-sandbox-certification.yml"><img alt="Provider Sandbox" src="https://img.shields.io/github/actions/workflow/status/SimplixInnovations/supcheckout/provider-sandbox-certification.yml?branch=main&label=Provider%20Sandbox&style=flat-square"></a>
  <a href="https://github.com/SimplixInnovations/supcheckout/actions/workflows/wordpress-org-submission-check.yml"><img alt="WordPress.org Submission Check" src="https://img.shields.io/github/actions/workflow/status/SimplixInnovations/supcheckout/wordpress-org-submission-check.yml?branch=main&label=Plugin%20Check&style=flat-square"></a>
</p>

<p align="center">
  <a href="LICENSE"><img alt="MIT License" src="https://img.shields.io/badge/License-MIT-2f6f52?style=flat-square"></a>
  <img alt="Development version 0.1.0" src="https://img.shields.io/badge/Development-0.1.0-285e46?style=flat-square">
  <img alt="WordPress 6.9 through 7.1" src="https://img.shields.io/badge/WordPress-6.9%E2%80%937.1-21759b?style=flat-square">
  <img alt="WooCommerce 10.8 through 11.1" src="https://img.shields.io/badge/WooCommerce-10.8%E2%80%9311.1-96588a?style=flat-square">
  <img alt="PHP 7.4, 8.2, 8.3, 8.4 and 8.5" src="https://img.shields.io/badge/PHP-7.4%20%7C%208.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4?style=flat-square">
</p>

<p align="center">
  <a href="#overview">Overview</a> ·
  <a href="#compatibility">Compatibility</a> ·
  <a href="#security-and-payment-integrity">Security</a> ·
  <a href="docs/COMPATIBILITY.md">Certification</a> ·
  <a href="#build-the-development-package">Development</a> ·
  <a href="SUPPORT.md">Support</a>
</p>

---

## Overview

**SUPCheckout for UPayments** connects WooCommerce checkout to UPayments while preserving WooCommerce order semantics, authenticated provider verification and merchant-data compatibility.

SUPCheckout is intentionally **UPayments-specific**. Other payment providers belong in separate products/repositories; this codebase is not a generic payment-orchestration platform.

### What is covered

| Capability | Repository status |
|---|---|
| WooCommerce Classic checkout | Verified |
| Cart / Checkout Blocks registration and availability | Verified |
| HPOS and legacy order storage | Verified in the certified matrix |
| Authenticated provider-status verification | Verified |
| Saved-card/token provenance boundaries | Verified |
| Subscription eligibility and pre-dispatch safeguards | Verified |
| One additional-merchant allocation | Verified boundary |
| Deterministic ZIP, checksum and manifest | Permanent release gate |
| Historical package-root migration/rollback | Permanent release gate |
| Official WordPress Plugin Check on the packaged artifact | Strict gate |
| Automatic WooCommerce refunds | Not supported |
| Arbitrary marketplace multi-split | Not supported |
| Live subscription auto-deduction | External/manual qualification required |

Payment-method and wallet availability still depends on the merchant's UPayments account, provider configuration, plugin settings and device/account eligibility.

## Compatibility

The current certified matrix covers exact WordPress, WooCommerce, PHP and order-storage combinations rather than broad untested ranges.

| Platform | Certified scope |
|---|---|
| WordPress | 6.9.x, 7.0.x and 7.1 cells |
| WooCommerce | 10.8.x, 11.0.x and 11.1.x cells |
| PHP | 7.4 compatibility floor; 8.2, 8.3, 8.4 and 8.5 current-stack cells |
| Order storage | Legacy + HPOS |
| Checkout | Classic + Cart / Checkout Blocks registration/availability |

See [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) for the exact evidence boundary and unsupported/external-manual cases.

## Release status

The repository is on development line **0.1.0**. A public Git tag, GitHub Release and WordPress.org publication have **not** been created yet.

`main` is protected by required Quality, H12 Regression, Compatibility and Release gates. The release pipeline builds a deterministic package from Git `HEAD`, verifies its source bytes, exercises packaged WordPress/WooCommerce installs and runs the official WordPress Plugin Check against the unpacked artifact.

Current engineering evidence belongs in [`docs/project/PROJECT-STATUS.md`](docs/project/PROJECT-STATUS.md); retained milestone evidence belongs in [`docs/project/ENTERPRISE-CERTIFICATION.md`](docs/project/ENTERPRISE-CERTIFICATION.md).

## Technical identity

| Surface | Canonical identity |
|---|---|
| Product | **SUPCheckout for UPayments** |
| Short name | **SUPCheckout** |
| Repository / package slug | `supcheckout` |
| WordPress text domain | `supcheckout` |
| PHP namespace | `Simplixi\SUPCheckout` |
| Package root | `supcheckout/` |
| Main file | `supcheckout/supcheckout.php` |

The main file was renamed from `UPayments.php` to `supcheckout.php` before the first release. A build activated under the old file name keeps working: on its next load a transitional bootstrap moves the active entry to `supcheckout.php`. No reactivation, no data changes.

Some UPayments-facing/persisted identities are also intentionally retained for merchant compatibility, including the gateway ID `upayments`, `woocommerce_upayments_settings`, callback identity `wc_upayments`, historical `_upay_*` metadata and related token/subscription state. See [`docs/project/NAMING-IDENTITY-STANDARD.md`](docs/project/NAMING-IDENTITY-STANDARD.md).

## Build the development package

The production plugin has no runtime Composer dependency. Composer is development tooling only.

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer audit --locked
composer quality

rm -rf dist
bash scripts/build-release.sh dist
bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip
sha256sum dist/supcheckout-0.1.0.zip
cat dist/supcheckout-0.1.0.zip.sha256
```

The artifact contract provides:

- one `supcheckout/` ZIP root;
- exact distributable bytes sourced from Git `HEAD`;
- deterministic ordering, timestamps and modes;
- ZIP SHA-256 sidecar;
- sorted per-file SHA-256 manifest;
- source-byte verification and tamper rejection;
- packaged legacy/HPOS activation and order-storage smoke;
- historical package-root migration/rollback qualification.

## Security and payment integrity

SUPCheckout treats browser redirects and callback/webhook bodies as signals, not financial truth by themselves.

- Paid state requires authenticated provider verification and exact order/transaction/economic binding.
- Non-idempotent payment operations are not blindly retried.
- Checkout does not rely on third-party font/icon CDNs.
- Merchant secrets, card data and token/provenance secrets must not appear in diagnostics, browser output or CI logs.
- Uninstall is non-destructive by default.

Report suspected vulnerabilities privately using [`SECURITY.md`](SECURITY.md).

## Engineering documentation

| Document | Purpose |
|---|---|
| [`docs/project/PROJECT-STATUS.md`](docs/project/PROJECT-STATUS.md) | Current verified engineering state |
| [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) | Public compatibility/certification boundary |
| [`docs/project/OWNER-HANDOFF.md`](docs/project/OWNER-HANDOFF.md) | Fresh-clone, local acceptance and release sequence |
| [`docs/project/NAMING-IDENTITY-STANDARD.md`](docs/project/NAMING-IDENTITY-STANDARD.md) | Canonical identity + protected compatibility IDs |
| [`docs/project/RELEASE-ENGINEERING.md`](docs/project/RELEASE-ENGINEERING.md) | Deterministic package/release contract |
| [`docs/project/SECURITY-THREAT-MODEL.md`](docs/project/SECURITY-THREAT-MODEL.md) | Security boundaries and threat-model evidence |
| [`docs/project/ARCHITECTURE-CODE-QUALITY.md`](docs/project/ARCHITECTURE-CODE-QUALITY.md) | Architecture and code-quality controls |
| [`docs/project/README.md`](docs/project/README.md) | Project-control document map and precedence |

Historical records intentionally preserve former product names, repository coordinates and milestone SHAs when those were true at the time.

## Support

For reproducible plugin defects and compatibility reports, use GitHub Issues and follow [`SUPPORT.md`](SUPPORT.md). Security findings must use the private process in [`SECURITY.md`](SECURITY.md).

Commercial WooCommerce engineering and production support are available through [Simplix Innovations](https://simplixi.com).

## Service relationship and provenance

SUPCheckout is developed and maintained by **Simplix Innovations** and integrates WooCommerce with the external **UPayments** payment service. UPayments names and trademarks remain the property of their respective owners.

Source lineage, attribution and trademark boundaries are documented in [`NOTICE.md`](NOTICE.md) and [`UPSTREAM.md`](UPSTREAM.md).

## License

MIT. See [`LICENSE`](LICENSE).
