# Public Claims Audit

Rule: **No claim may be broader than its evidence.**

| Claim | Evidence | Status | Allowed public wording |
|---|---|---|---|
| Blocks | CheckoutRegistrationTest + R6 browser job | VERIFIED — BOUNDED | “WooCommerce Blocks checkout supported” |
| HPOS | Compatibility HPOS cells | VERIFIED | “HPOS supported” |
| Subscriptions/auto-deduct | fail-closed runtime; UNPROVEN capture | UNPROVEN / EXTERNAL | “Subscription scaffolding present; automatic recurring is fail-closed pending provider contract” |
| Saved cards | SavedCardRuntimeTest | VERIFIED — BOUNDED | “Saved-card flows tested in runtime certification” |
| Apple Pay / Google Pay / Samsung Pay | none executed | NOT TESTED / EXTERNAL | do not advertise |
| KNET | no live/sandbox wallet evidence | EXTERNAL REQUIRED | do not advertise as verified |
| Multicurrency | no licensed env | EXTERNAL REQUIRED | do not advertise |
| Arabic/RTL | R6 WordPress ar locale | VERIFIED — BOUNDED | “Default-theme Arabic/RTL checkout verified” |
| WPML/WCML | no license | EXTERNAL REQUIRED | do not advertise |
| Multi-merchant | MultiMerchantRuntimeTest bounded | VERIFIED — BOUNDED | “one additional merchant allocation bounded” |
| Themes/builders | only free themes in E3 | VERIFIED — BOUNDED (free) / EXTERNAL (commercial) | name tested free themes only |
| Cloudflare | local reverse proxy only | VERIFIED — BOUNDED (local) / EXTERNAL (edge) | do not claim Cloudflare |
| Security | threat model + CodeQL + gitleaks | VERIFIED — BOUNDED | no pentest/PCI claim |
| PCI/compliance | none | EXTERNAL | do not claim |
| Performance | synthetic 10k | VERIFIED — BOUNDED | no production throughput claim |

## Overclaims found

None in packaged copy that promise working automatic recurring production payments.

## RC metadata changes required

Optional one-line recurring fail-closed notice (see Recurring Claims Audit):

```text
RC_METADATA_CHANGE_REQUIRED
```

Do not alter accepted package bytes in this branch.
