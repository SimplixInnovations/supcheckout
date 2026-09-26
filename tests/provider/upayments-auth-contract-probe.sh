#!/usr/bin/env bash
# Repeatable UPayments public-sandbox auth contract probe.
#
# Uses ONLY documented public sandbox credentials injected via environment.
# Never commits secrets. Never prints token or HMAC secret.
# Never prints full Authorization / X-Signature values.
# No real money.
#
# Required env:
#   UPAYMENTS_SANDBOX_BASE_URL
#   UPAYMENTS_SANDBOX_TOKEN
# Optional:
#   UPAYMENTS_SANDBOX_API_SECRET   (for HMAC cases)
#
# Output: redacted SANDBOX_AUTH_OBSERVATION (not PRODUCTION_AUTH_CONTRACT).
set -euo pipefail

BASE="${UPAYMENTS_SANDBOX_BASE_URL:?}"
TOKEN="${UPAYMENTS_SANDBOX_TOKEN:?}"
SECRET="${UPAYMENTS_SANDBOX_API_SECRET:-}"

redact() { sed -E 's/[A-Za-z0-9._-]{8,}/[REDACTED]/g'; }

echo "SANDBOX_AUTH_OBSERVATION"
echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "endpoint=charge"
echo "token_family=present/absent only"
echo "token_present=$([[ -n $TOKEN ]] && echo yes || echo no)"
echo "hmac_secret_present=$([[ -n $SECRET ]] && echo yes || echo no)"

# A. Bearer-only Charge init (synthetic ids)
ORDER_ID="r6probe-$(date +%s)-$RANDOM"
echo "--- A. Bearer-only ---"
echo "request_method=POST"
echo "canonical_path=/api/v1/charge"
echo "signed_body_matches_raw=n/a (no HMAC)"
# Actual HTTP call is left to the operator/CI with network policy.
# curl -sS -o /tmp/auth_a.json -w 'http_status=%{http_code}\n' \
#   -X POST "$BASE/api/v1/charge" \
#   -H "Authorization: Bearer $TOKEN" \
#   -H 'Content-Type: application/json' \
#   -d "{\"order\":{\"id\":\"$ORDER_ID\"},\"reference\":{\"id\":\"$ORDER_ID\"}}"
# Do not print Authorization header.

echo "NOTE: execute A–D in a network-authorized environment; capture status codes and provider status/message only."
echo "NOTE: Bearer-only success does NOT prove HMAC globally optional."
echo "NOTE: valid-HMAC success does NOT prove HMAC globally mandatory."
echo "NOTE: invalid-HMAC rejection proves only that an invalid signature is rejected."
echo "NOTE: sandbox does not prove live merchant behavior."
echo "classification=SANDBOX_AUTH_OBSERVATION"
