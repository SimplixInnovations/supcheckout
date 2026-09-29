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
- recent official plugin work uses a different plugin-specific/static `X-Signature` + `Uplugin-Request: 1` pattern, without the documented `X-Timestamp` request HMAC; those plugin branches are themselves inconsistent about the `X-Signature` value (separate configured signature key in WooCommerce/CS-Cart versus the API key itself in inspected OpenCart V4 paths).

For a **third-party WooCommerce server integration using UInterfaceV2**, please confirm only the following:

1. **Production Charge:** Today, for `POST /api/v1/charge`, must a third-party merchant integration send the documented dynamic HMAC headers (`X-Timestamp` + Base64 HMAC-SHA256 `X-Signature`) in addition to Bearer authentication, or can some production merchant accounts still use Bearer-only requests?

2. **Production Get Payment Status:** Today, for `GET /api/v1/get-payment-status/{track_id}`, must the same documented dynamic HMAC contract be used, or can some production merchant accounts still use Bearer-only requests?

3. **Rollout / plugin-signature distinction:** If the answer to either endpoint is merchant/account/channel-specific, how can the merchant determine which contract applies to its production account? Recent official plugin branches use `Uplugin-Request: 1` plus a static/configured `X-Signature`, but they do not even agree on what populates that header (separate configured signature key in WooCommerce/CS-Cart versus the API key itself in inspected OpenCart V4 paths). Is this plugin-only merchant-validation family reserved for UPayments-maintained plugins, and should a third-party UInterfaceV2 integration instead follow the public dynamic-HMAC contract whenever HMAC is enabled?

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

## Ready-to-send message

Send only after `OWNER_PROVIDER_CONTACT_AUTHORIZATION=YES`. Send from the merchant account owner's address to UPayments technical support. Do not attach logs, keys, API secrets or merchant identifiers beyond the merchant/account reference UPayments itself needs to answer.

**Subject:** Production authentication contract for Charge and Get Payment Status (third-party WooCommerce integration, UInterfaceV2)

**Body:**

> Hello,
>
> We maintain an independent WooCommerce payment integration for UPayments built on UInterfaceV2, and we are preparing its first production release. Before we release, we need to confirm one contract question.
>
> Your public documentation currently gives us conflicting answers about production authentication. The HMAC Authentication guide states that every authenticated request carries Bearer plus a fresh `X-Timestamp` and a Base64 HMAC-SHA256 `X-Signature`. The Charge and Get Payment Status references still document Bearer authentication. The FAQ describes HMAC as rolling out. Your own recent plugin releases use a third pattern: `Uplugin-Request: 1` with a static or configured `X-Signature`, and those releases do not agree with each other on what populates that header.
>
> For a third-party merchant integration calling your API from a merchant's own server, please confirm:
>
> 1. For `POST /api/v1/charge` today, must we send the dynamic HMAC headers (`X-Timestamp` and Base64 HMAC-SHA256 `X-Signature`) in addition to Bearer, or do production merchant accounts still accept Bearer-only requests?
> 2. For `GET /api/v1/get-payment-status/{track_id}` today, is the answer the same as for Charge?
> 3. If the answer depends on the merchant, account or channel, how does a merchant determine which contract applies to its own production account? Is the `Uplugin-Request` signature scheme reserved for UPayments-maintained plugins, so that a third-party UInterfaceV2 integration should follow the published dynamic-HMAC contract wherever HMAC is enabled?
>
> If dynamic HMAC is required for us, we will implement the published API-Secret, timestamp, method and path, exact raw body, empty-GET-body and Base64 HMAC-SHA256 rules. We only need to know where that contract applies.
>
> A written answer is what we need, because we record provider-confirmed behaviour as durable evidence before we change payment code. A call first is fine if that is easier, as long as the final answer comes back in writing.
>
> Thank you,
> Simplix Innovations

### After a reply arrives

1. Store the reply verbatim under `docs/project/evidence/` with the date in the filename; do not paraphrase it into a living document first.
2. Answer the decision tree in `UPAYMENTS-CONTRACT-DECISION-TREE.md` from that evidence.
3. Only then decide whether a runtime tranche is required, and open it as its own bounded branch and PR.
4. A verbal-only answer does not clear `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=UNRESOLVED`.
