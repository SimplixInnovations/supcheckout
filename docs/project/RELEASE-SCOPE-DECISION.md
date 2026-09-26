# Release Scope Decision

| Area | Decision | Basis |
|---|---|---|
| CORE ONE-TIME PAYMENT | include in first public release (RC eligible) | full repo qualification + fail-closed payment authority |
| SAVED CARD | include — BOUNDED | runtime tested; token policy still clarification-required |
| SUBSCRIPTIONS/AUTO-DEDUCT | exclude from advertised production-ready scope | capture/cycle UNPROVEN; VERIFIED_SUCCESS fail-closed |
| WALLETS | exclude until device/account evidence | NOT TESTED / EXTERNAL |
| MULTI-MERCHANT | include — BOUNDED (one additional merchant) | bounded allocation test |
| INTEGRATIONS (WPML/WCML/themes/CDN) | exclude from claims | EXTERNAL REQUIRED |

## Important

If excluding recurring from public claims requires runtime/UI/package behavior change:

```text
NEW_RUNTIME_TRANCHE_REQUIRED=RELEASE_SCOPE
```

Do not make that change silently. Current fail-closed wording already avoids promising working automatic recurring.
