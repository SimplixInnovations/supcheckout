# Production Auth Account Probe — 2026-10-13

**Classification:** `PRODUCTION_ACCOUNT_AUTH_OBSERVATION` (one merchant account, point in time)
**Runbook:** [`../PRODUCTION-AUTH-ACCOUNT-PROBE.md`](../PRODUCTION-AUTH-ACCOUNT-PROBE.md)
**Operator:** owner (Simplix Innovations), on the owner's own machine (WSL bash), never in CI
**Authorization:** `OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES`, plus `CONFIRM_PRODUCTION_CHARGE_INIT=YES` for probe 2
**Account:** the release merchant's production UPayments account (the store that runs official UPayments 3.1.1 today)

No API key, track ID, payment link, merchant identifier or screenshot appears in this file.

## Code in force for the accepted run

| Item | Value |
|---|---|
| Probe source | `fix/probe-provider-error-message` at `cb6935d` (PR #138) |
| Plugin request shapes | `StatusVerifier` unchanged since Release Candidate 1; `CheckoutOrchestrator` **with the PR #138 fix** (non-white-label `paymentGateway.src = "knet"`) |
| Candidate package carrying those shapes | 62 files, SHA-256 `961aa4727d030d317ec892e8d3ee47e10b209bbabfc53a8fbba8ee6ece8f3133` (NOT owner accepted) |
| Owner-accepted package at the time | Release Candidate 1, `8f2b8af5ec1ed8bc8bc5b69417455b1c680c0243`, SHA-256 `8ef8869421807d0370089c03a3766964274da44213cec3a2a9729119f30bc156` |

## Dashboard observation

The merchant dashboard exposes an **API Key** only. There is no API Secret, HMAC or signature setting.

## Accepted run (2026-10-13T02:30:00Z)

```text
PRODUCTION_ACCOUNT_AUTH_OBSERVATION
timestamp_utc=2026-10-13T02:30:00Z
host=apiv2api.upayments.com
auth_headers_sent=Authorization: Bearer (no X-Timestamp, no X-Signature) - identical to SUPCheckout
--- probe 1: GET get-payment-status/{track_id} ---
curl_exit=0
http_status=201
provider_status_true=yes
transaction_bound_to_probed_track=yes
verdict=BEARER_ONLY_ACCEPTED
--- probe 0: GET check-payment-button-status (read-only account mode) ---
curl_exit=0
http_status=201
provider_status_true=yes
whitelabel=no
gateway_src=knet
verdict=BEARER_ONLY_ACCEPTED
--- probe 2: POST charge (initialization only) ---
charge_account_mode=whitelabel:no gateway_src:knet
curl_exit=0
http_status=201
provider_status_true=yes
payment_link_present=yes (link not printed, not followed)
verdict=BEARER_ONLY_ACCEPTED
probe_order_reference=scprobe-1791858601-226 (find it in the merchant dashboard; it expires unpaid)
NOTE: account-scoped and point-in-time; says nothing about other accounts or future enforcement.
classification=PRODUCTION_ACCOUNT_AUTH_OBSERVATION
```

## Earlier runs the same evening (2026-10-12), retained as the defect record

All three earlier runs had probe 1 at HTTP 201 `BEARER_ONLY_ACCEPTED`. Probe 2 failed each time:

| Time (UTC) | Probe 2 request | Result |
|---|---|---|
| 20:40:58 | the plugin's non-white-label shape (`is_whitelabled:false`, no `paymentGateway`) | HTTP **422**; the probe did not yet print the reason |
| 20:43:13 | same | HTTP **422**: "The payment gateway.src field is required when is whitelabled is false." |
| 20:50:07 | same, now with probe 0 reporting `whitelabel=no` | HTTP **422**, same message |

**Conclusion:** the Release Candidate 1 plugin, and every package since the Simplix baseline `1caf384`, sends a non-white-label Charge that this production account rejects. Every checkout on this store would fail. Official UPayments 3.1.1 always sends `paymentGateway` (`"knet"` for non-white-label) and is accepted. PR #138 restores that.

None of the 422 runs created a provider session. The accepted run's session expired unpaid after one minute. No money moved.

## Resolution rule check

| # | Condition | Result |
|---|---|---|
| 1 | Both probes `BEARER_ONLY_ACCEPTED` on the release merchant account, in one run | **Met** (probes 1, 0 and 2, run at 02:30:00Z) |
| 2 | The run used the request shapes of the owner-accepted package | **Met only for the PR #138 candidate.** `StatusVerifier` is unchanged since Release Candidate 1. The Charge shape is the corrected one; Release Candidate 1's own Charge shape is the one rejected above. The resolution therefore holds for a package that contains PR #138, and becomes owner-accepted evidence only when such a package is accepted. |
| 3 | This evidence file committed | **Met** (this file) |
| 4 | Fail-closed: non-201 Status never authenticates; non-201 Charge fails checkout without paid state | **Met** (`StatusVerifier` returns `unexpected_http_*`; `CheckoutOrchestrator` Section S requires exact 201 and returns a checkout failure) |
| 5 | Public copy claims nothing about HMAC and names the auth model as a known limitation | **Met** (`docs/COMPATIBILITY.md` "Provider authentication" row) |

## Resulting gate value

```text
PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS=RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY
```

Scope: this one account, at this time, for request shapes that include the PR #138 Charge fix. It does not prove that HMAC is optional for other merchants, or that UPayments will not enforce it later. Re-run the probe before each release, and whenever UPayments announces an authentication change.
