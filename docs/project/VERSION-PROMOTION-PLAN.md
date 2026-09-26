# Version Promotion Plan

**Do not change the version.** Owner authorization required:

```text
OWNER_VERSION_PROMOTION_AUTHORIZATION=<version>
```

## Version surfaces inventory

| Surface | Current | Change required at promotion |
|---|---|---|
| `src/Release/Identity.php` | 0.1.0 | yes |
| `UPayments.php` header | 0.1.0 | yes |
| `readme.txt` stable tag | 0.1.0 | yes |
| `CHANGELOG.md` | historical | add release notes |
| `README.md` version text/badges | 0.1.0 | yes |
| release docs | 0.1.0 | yes |
| package filename | `supcheckout-0.1.0.zip` | yes |

## Recommendation (evidence-based, not applied)

First public version: **1.0.0** (core one-time payments), if:

- external certification items are closed or explicitly excluded from claims
- provider contracts resolved or recurring excluded from advertising
- RC gate passes

**promotion authorized:** NO — awaiting owner token
