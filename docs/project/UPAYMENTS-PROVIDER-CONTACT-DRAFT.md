# UPayments Provider Contact Draft

**Status:** REDUCED AFTER PUBLIC RESEARCH — READY_TO_SEND — OWNER ACTION REQUIRED  
**Do NOT send without:** `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`  
**No secrets in this document.**

Public first-party research has already resolved or narrowed the original 32 questions. See:

`docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md`

The questions below are only the remaining contract ambiguities that current UPayments documentation, SDKs and public WooCommerce code do not resolve consistently.

## Authentication / HMAC

Current public evidence is contradictory: the HMAC guide describes dynamic `X-Timestamp` + Base64 HMAC-SHA256 `X-Signature` for authenticated requests; the FAQ says HMAC is being rolled out; Charge/Status and the current official web SDK still document Bearer authentication; and the current unreleased WooCommerce development branch uses a different plugin-specific `X-Signature` + `Uplugin-Request` pattern.

Please confirm:

1. For a third-party WooCommerce server integration today, is the documented dynamic HMAC contract mandatory for **production Charge**, **Get Payment Status**, and **auto-deduct**, or is enforcement merchant/account/endpoint/channel-specific?
2. If rollout is not universal, what determines enforcement and what is the effective enforcement date/policy? May some production merchant accounts still use Bearer-only requests?
3. Is `Uplugin-Request: 1` a contract intended only for the official UPayments WooCommerce plugin, or is it required/available to third-party integrations?
4. For HMAC Get Payment Status calls using `?session_id=` or `?invoice_id=`, does `API_PATH` include the query string? Please provide the exact canonical payload/path rule, including query encoding and ordering.
5. Does the documented “1-minute window” mean an exact symmetric ±60-second tolerance? Are there stable error/status codes for missing, expired and invalid signatures?

## Webhook verification

The FAQ says server-to-server webhooks are signed, but the public webhook page does not publish the verification contract.

6. Please provide the exact webhook signature/timestamp headers, algorithm, canonical payload, secret/key source, replay window and key-rotation behavior. Is the webhook secret the same API Secret used for outbound request HMAC?

## Customer/card token persistence

Public token/card APIs require stable `customerUniqueToken` reuse, while the Auto Deduction page says generated customer/card tokens are never stored locally. UPayments' public WooCommerce development code also contains local customer-token persistence, so the public sources do not establish a single normative rule.

7. May a **third-party merchant WooCommerce integration** persist `customerUniqueToken` locally?
8. May it persist opaque UPayments card tokens locally when needed for saved-card/renewal operation?
9. Is the Auto Deduction documentation statement that tokens are “NEVER stored in the local WooCommerce database” a universal integration requirement, or a description of a particular UPayments-managed architecture/version?
10. If local persistence is allowed, what encryption-at-rest, access-control, retention/deletion, rotation and PCI requirements apply?

## Auto-deduction financial truth and reconciliation

The general gateway-status documentation defines `CAPTURED` as funds secured, but no public auto-deduct contract found states that a JSON envelope `status: true` itself proves capture.

11. Which exact auto-deduct response field/value is authoritative proof that renewal funds are financially **CAPTURED**?
12. Can every auto-deduct transaction be verified using Get Payment Status? If yes, should the returned `trackId` be used, and can the auto-deduct state transition after the initial API response?
13. The FAQ says generic API idempotency keys are not currently supported. Does auto-deduct have any endpoint-specific idempotency/deduplication exception?
14. Do `order.id`, `reference.id`, `requested_order_id`, or any other merchant field provide server-side duplicate suppression for auto-deduct?
15. Which provider identifier, if any, uniquely represents a **renewal billing cycle** rather than merely one transaction attempt?
16. If a non-idempotent auto-deduct request times out after dispatch before a usable provider identifier is returned, what exact reconciliation and safe-retry procedure should the merchant follow to avoid duplicate charges?

## Sandbox credential families

Current Test Mode/HMAC pages publish a newer Bearer-key family and HMAC test secret, while current Postman/card/token pages still publish the `jtest123`-era family. Existing non-destructive integration probes also observed materially different responses between the two families.

17. Which test host and credential family should new third-party UInterfaceV2 integrations use today: `sandboxapi.upayments.com` as documented publicly, or `dev-apiv2api.upayments.com` as used by the current unreleased official WooCommerce 3.1.2 branch? Is `jtest123` current, legacy, or endpoint-specific?
18. Does the published HMAC test secret pair with both currently published Test Mode Bearer variants? Do those newer credentials require merchant activation/binding, whitelabel configuration, source-IP allowlisting, `Uplugin-Request`, a specific host, or any other prerequisite not stated on the Test Mode page?

## Evidence request

Written answers are preferred because the integration must retain authoritative contract evidence before changing production authentication, token-storage behavior, recurring-payment authority or release claims.

If a call/meeting is easier, we can use that for discussion, but please confirm the final answers in writing afterward.

**Status:** READY_TO_SEND — OWNER ACTION REQUIRED (`OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`)
