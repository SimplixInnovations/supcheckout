# Token Data Inventory

| Field | Type | Location | Encryption | Access | Retention | Deletion | Backup |
|---|---|---|---|---|---|---|---|
| `_upay_credit_card_token` | provider card token | order meta | not encrypted by plugin | authenticated admin/order | until order/token lifecycle | not automatic | included in DB backup |
| `_upay_customer_unique_token` | provider customer token | order meta | not encrypted by plugin | authenticated admin/order | until order/token lifecycle | not automatic | included in DB backup |
| `upayments_token_identity_secret_v2` | identity HMAC secret | non-autoload option | HMAC-verified, not token ciphertext | plugin crypto | persistent | manual/migration | included in option backup |

**Provider rule (docs):** tokens are never stored locally (subscription guide).  
**SUPCheckout fact:** protected metadata retained for renewals.

```text
token persistence: PROVIDER CLARIFICATION REQUIRED
```

Do not delete/migrate without approved migration design.
