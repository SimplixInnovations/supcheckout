# UPayments Provider Contact Draft

**Status:** REDUCED TO FIRST-RELEASE CORE QUESTIONS — OWNER ACTION REQUIRED
**Do NOT send without:** `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`
**No secrets in this document.**

Deep first-party research is recorded in:

`docs/project/evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md`

The original 32 questions are now individually classified there. Saved-card, recurring/auto-deduct, webhook-signature-mechanics and sandbox-credential-migration questions are deliberately **not** included in this first-release contact because they do not need to block the narrower core one-time-payment release under the current SUPCheckout scope.

The remaining release blocker is the contradictory public contract for **production authentication**.

## Production Charge and Get Payment Status authentication

Current first-party evidence conflicts:

- the HMAC Authentication guide says every authenticated request uses Bearer + fresh `X-Timestamp` + Base64 HMAC-SHA256 `X-Signature`;
- current Charge and Get Payment Status references still document Bearer authentication;
- the FAQ says HMAC is currently rolling out;
- current UPayments web/React SDK surfaces continue to describe Bearer-token integration;
- recent official plugin work uses a different plugin-specific/static `X-Signature` + `Uplugin-Request: 1` pattern, without the documented `X-Timestamp` request HMAC.

For a **third-party WooCommerce server integration using UInterfaceV2**, please confirm only the following:

1. **Production Charge:** Today, for `POST /api/v1/charge`, must a third-party merchant integration send the documented dynamic HMAC headers (`X-Timestamp` + Base64 HMAC-SHA256 `X-Signature`) in addition to Bearer authentication, or can some production merchant accounts still use Bearer-only requests?

2. **Production Get Payment Status:** Today, for `GET /api/v1/get-payment-status/{track_id}`, must the same documented dynamic HMAC contract be used, or can some production merchant accounts still use Bearer-only requests?

3. **Rollout / plugin-signature distinction:** If the answer to either endpoint is merchant/account/channel-specific, how can the merchant determine which contract applies to its production account? Also, are `Uplugin-Request: 1` and the static/configured `X-Signature` pattern used by recent official plugin branches reserved for UPayments-maintained plugins, or are third-party integrations expected to use that scheme instead of the public dynamic-HMAC guide?

If dynamic HMAC is required for our third-party production integration, we will follow the published API-Secret, timestamp, method/path, exact raw-body, empty-GET-body and Base64 HMAC-SHA256 rules. We only need confirmation of **where that contract applies** and whether the official-plugin signature scheme is a separate/private channel contract.

## Evidence request

Written confirmation is preferred because production authentication behavior must be backed by durable provider evidence before SUPCheckout changes runtime or removes its release blocker.

If a call or WhatsApp discussion is easier, that is fine for discovery, but please confirm the final production-authentication answers in writing afterward.

## Explicitly deferred questions

The following remain documented in the evidence matrix but are intentionally deferred because their related features are excluded from the first public production scope or are not required for financial authority:

- webhook signature header/secret/replay mechanics — future defense-in-depth; current webhook input is not financial truth;
- customer/card token persistence and PCI controls — future saved-card gate;
- auto-deduct capture, reconciliation, idempotency and billing-cycle identity — future recurring gate; automatic recurring `VERIFIED_SUCCESS` remains fail-closed/unreachable;
- migration to the newer sandbox credential family / `dev-apiv2api` — future test-infrastructure decision; the current public `jtest123` sandbox Charge path has bounded certification.

**Status:** READY_TO_SEND ONLY AFTER OWNER AUTHORIZATION (`OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`)
