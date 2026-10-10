# Production Auth Account Probe

**Status:** PREPARED — OWNER ACTION REQUIRED
**Owner decision (2026-10-08):** account-scoped empirical evidence is an accepted resolution path for `PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS`, alongside (not instead of) a written UPayments answer. Sending the provider message stays optional.
**Run only after:** `OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES`

## Why this path exists

Public UPayments sources contradict each other on production authentication (see `evidence/UPAYMENTS-PUBLIC-CONTRACT-RESEARCH-2026-09-27.md`), and a provider reply may never arrive. The first-release question is narrower than the public contract: **does the release merchant's own production account accept the authentication SUPCheckout sends today, on the same endpoints and request shapes?** SUPCheckout sends `Authorization: Bearer` only, with no `X-Timestamp` or `X-Signature`, to:

1. `GET get-payment-status/{track_id}` — `StatusVerifier`, the only source of paid state;
2. `POST charge` — `CheckoutOrchestrator`, payment initialization.

The probe sends those requests from the owner's machine and records what the account answers.

### How close the probe requests are to the plugin's

| Request | Same as the plugin | Differs |
|---|---|---|
| Status | method `GET`, path `get-payment-status/{track_id}`, `Accept: application/json`, `Authorization: Bearer`, no redirects | User-Agent: the plugin sends the WordPress HTTP API default; the probe sends curl's default |
| Charge | method `POST`, path `charge`, `Accept`/`Content-Type: application/json`, `Authorization: Bearer`, live User-Agent `UpaymentsWoocommercePlugin/2.2.1`, the same top-level body keys and token placeholders as a guest, one-time, non-white-labelled order with no saved card and no multi-merchant split | adds `paymentLinkExpiryInMinutes: 1` as a safety bound; probe values in place of a real order's |

Status acceptance also requires the returned transaction to carry the probed track ID, the same binding `StatusVerifier` enforces, so a `201` for an unknown or foreign track ID is `INCONCLUSIVE`, not acceptance.

## Safety boundary

- Owner-run only. The script refuses inside CI and without the authorization variable. Production keys never enter CI, the repository, a screenshot or this document.
- The production host is fixed to `EndpointResolver::LIVE_BASE` and cannot be overridden.
- Probe 1 reads the status of a **past** order. It changes nothing.
- Probe 2 (optional, second confirmation) creates one unpaid Charge session for 1.000 KWD with a 1-minute link expiry. The link is never printed or followed. Initialization is not capture: no money moves unless someone opens and pays the link. The session may appear in the merchant dashboard as an unpaid or expired transaction.
- Output never contains the key, the track ID, the payment link or the raw provider body. The key reaches curl from a `0600` file and the track ID through stdin, so neither appears in the process list. On a transport failure the output records the curl exit code.

## Run

From the repository root in Git Bash (`bash`, `curl` and `php` on `PATH`):

```bash
export OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES
read -rs -p "Production API key: " UPAYMENTS_PRODUCTION_API_KEY; echo; export UPAYMENTS_PRODUCTION_API_KEY
export UPAYMENTS_PRODUCTION_TRACK_ID='<track ID of a past order on this account>'
export CONFIRM_PRODUCTION_CHARGE_INIT=YES   # omit to run probe 1 only
bash tests/provider/production-auth-account-probe.sh | tee production-auth-probe.txt
unset UPAYMENTS_PRODUCTION_API_KEY
```

`read -rs` keeps the key out of shell history. The track ID of a past order is in the UPayments merchant dashboard, or in that WooCommerce order's notes.

Also record, from the merchant dashboard, whether the account exposes an **API Secret** or an HMAC setting, and whether it is enabled.

## Verdicts

| Probe output | Meaning |
|---|---|
| `verdict=BEARER_ONLY_ACCEPTED` | The account accepted the SUPCheckout authentication on that request (see the comparison table above). |
| `verdict=REJECTED_AUTH` | HTTP 401/403: the account enforces something SUPCheckout does not send. |
| `verdict=INCONCLUSIVE` | Any other outcome, including network errors and an unknown track ID. Fix the input and re-run; never count it as acceptance. |

## Resolution rule

`PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS` may move to `RESOLVED_ACCOUNT_SCOPED_BEARER_ONLY` only when **all** of these hold:

1. both probes report `verdict=BEARER_ONLY_ACCEPTED` on the release merchant account, in one run;
2. the run used the request shapes of the owner-accepted package (`StatusVerifier` and `CheckoutOrchestrator` unchanged since that package);
3. the evidence file below is committed;
4. the fail-closed property still holds: any non-201 Status response leaves the order unpaid (`StatusVerifier` returns `unexpected_http_*`, never an authenticated result), and any non-201 Charge response fails checkout without creating paid state;
5. public copy claims nothing about HMAC and names the auth model as a known limitation.

If either probe reports `REJECTED_AUTH`, the gate stays **UNRESOLVED** and `NEW_RUNTIME_TRANCHE_REQUIRED=HMAC`. In that case a provider answer is needed for which signature scheme applies, so the provider message in `UPAYMENTS-PROVIDER-CONTACT-DRAFT.md` becomes necessary.

A written UPayments answer, if one arrives, supersedes this rule.

### What this resolution does not prove

It is evidence about one account at one time. It does not prove HMAC is optional for other merchants, and UPayments may enforce HMAC later. Item 4 bounds that risk: enforcement would make checkouts fail and orders stay unpaid, never mark an order paid incorrectly. Re-run the probe before each release, and whenever UPayments announces an authentication change.

## Evidence file

Create `docs/project/evidence/PRODUCTION-AUTH-ACCOUNT-PROBE-<YYYY-MM-DD>.md` containing:

- the full probe output (it is already redacted);
- the owner-accepted package SHA-256 and source SHA in force at the time of the run;
- the dashboard observation: API Secret / HMAC setting present? enabled?;
- the operator and the authorization token;
- the resulting gate value.

Do not paste the key, a track ID, a payment link, merchant identifiers or screenshots.
