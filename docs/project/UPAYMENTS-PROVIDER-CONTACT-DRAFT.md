# UPayments Provider Contact Draft

**Status:** READY_TO_SEND — OWNER ACTION REQUIRED
**Do NOT send without:** `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`
**No secrets in this document.**

## Authentication

1. Are `X-Timestamp` and `X-Signature` currently mandatory for every production Charge request?
2. Are they currently mandatory for Get Payment Status?
3. Is HMAC mandatory for all merchants, or being rolled out per account/endpoint?
4. What is the effective enforcement date?
5. May Bearer-only integrations remain valid for some merchant accounts?
6. Does the HMAC API path include query parameters?
7. What is the exact canonical string for Get Payment Status with `session_id`, `track_id`, or `invoice_id`?
8. Is the accepted clock-skew window exactly ±60 seconds?
9. Is there a stable error code for missing/expired/invalid signatures?

## Webhooks

10. The FAQ says webhooks are signed. What exact headers and signature algorithm verify webhook authenticity?
11. Is the webhook signing secret the same API Secret used for outbound API HMAC?
12. What replay/timestamp protection applies to webhooks?

## Token persistence

13. May merchant WooCommerce software persist `customerUniqueToken` locally?
14. May it persist card tokens locally?
15. The current subscription guide says tokens are NEVER stored locally. Is that normative or only describing UPayments’ official plugin architecture?
16. If local persistence is allowed, what encryption-at-rest, retention, access control, deletion and rotation requirements apply?
17. Must token storage satisfy a specific PCI scope/control?

## Auto-deduction financial truth

18. Which exact auto-deduct response/result proves the renewal funds are actually CAPTURED?
19. Does HTTP success mean only request acceptance, or financial capture?
20. Can a successful auto-deduct be verified using Get Payment Status?
21. Which identifier should be queried?
22. Can an auto-deduct transition after the initial response?

## Recurring cycle identity/idempotency

23. Does auto-deduct accept an idempotency key today?
24. Can `order.id`, `requested_order_id`, `reference`, or another merchant-supplied value act as an idempotency key?
25. Which provider-returned field uniquely identifies a single renewal billing cycle?
26. How should a merchant reconcile timeout-after-dispatch without risking duplicate charge?
27. What is UPayments’ recommended safe retry procedure for an ambiguous auto-deduct?

## References

See `docs/project/evidence/UPAYMENTS-CONTRACT-SNAPSHOT-2026-09-26.md` for first-party source contradictions.
