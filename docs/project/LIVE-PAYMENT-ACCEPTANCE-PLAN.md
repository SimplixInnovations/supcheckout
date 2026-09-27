# Live Payment Acceptance Plan

**Authorization required before any live transaction:**

```text
OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES
```

Until then: prepare only. No live monetary transaction.

## Environment prerequisites

- Isolated staging WooCommerce site
- HTTPS
- Current certified WP/WC/PHP (see Compatibility matrix)
- Dedicated test merchant/account if provider supports
- Minimal-value approved live transaction amount
- No real customer data
- Synthetic product/order
- Logging redacted (no PAN/CVV/secrets)
- Rollback/cleanup procedure

## Mandatory live one-time scenarios

Credit Card / KNET where account supports:

1. successful initial payment
2. explicit cancel
3. decline
4. 3DS/authentication failure if applicable
5. browser closes before return
6. webhook before browser return
7. browser return before webhook
8. duplicate webhook
9. duplicate browser return
10. status inquiry after successful capture
11. status inquiry after failure/cancel
12. network loss after provider dispatch

## Required proof per scenario

- order never false-paid
- verified capture only from authenticated provider status
- transaction/payment IDs bound correctly
- amount/currency/reference/order identity exact
- duplicate/reordered signals do not double-complete
- no secrets/tokens in logs
- user-facing outcome correct

**Scope:** one-time payments only. No automatic recurring debit under this authorization.

**Classification until executed:** `EXTERNAL REQUIRED — OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES`
