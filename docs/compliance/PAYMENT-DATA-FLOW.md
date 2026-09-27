# Payment Data Flow

```text
Browser → WordPress/WooCommerce checkout
       → SUPCheckout gateway (prepare/init)
       → UPayments (Charge / redirect / whitelist UI)
       → Browser return (wc_upayments / order-received)
       → SUPCheckout StatusVerifier (authenticated provider status)
       → WooCommerce order state

Webhook path:
UPayments → SUPCheckout callback → StatusVerifier → Woo order
```

## Explicit fact

SUPCheckout does **not** handle raw PAN/CVV (provider-hosted payment page / tokenization).
`NOT TESTED` for any undocumented card-data path; treat as absent unless evidence says otherwise.

## Surfaces

| Surface | Data | Notes |
|---|---|---|
| Browser | order id, keys | no PAN |
| WordPress/Woo | order, currency, totals | payment authority |
| SUPCheckout | protected token meta, order binding | no PAN |
| UPayments | card data (provider side) | out of PCI merchant scope if properly delegated |
| Logs | request ids, non-secret meta | must not log secrets |
| Backups | DB including token meta | token persistence clarification required |

**PCI:** `EXTERNAL ORGANIZATIONAL/ACQUIRER/QSA REVIEW REQUIRED`
**legal/privacy:** `EXTERNAL REVIEW REQUIRED`
