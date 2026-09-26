# Production-like Performance Qualification

## Already verified (bounded)

Synthetic 10k HistoricalEnrollment:

- max orders/request ≤ 50
- exact eligible IDs reached
- queries/memory/wall-time measured
- no provider egress

**Classification:** `VERIFIED — BOUNDED (synthetic)`
**Not** production-throughput proof.

## Representative staging workload (EXTERNAL REQUIRED)

When a real staging environment exists:

- real MySQL/MariaDB version
- PHP-FPM
- object cache if actually used
- Action Scheduler queue
- HPOS
- realistic order/subscription mix

Measure:

- checkout request time
- provider-init preparation time excluding provider network
- callback/status processing
- scheduler batch
- DB queries
- memory
- queue growth
- Action Scheduler lag

Compare: baseline Woo without SUPCheckout vs Woo + SUPCheckout.

Do not invent universal SLOs.
