#!/usr/bin/env bash
# Real UPayments public-sandbox auth contract probe (SANDBOX_AUTH_OBSERVATION only).
#
# Uses documented public sandbox credentials from environment (ephemeral).
# Never commits secrets. Never prints Authorization / X-Signature / secret.
# No real money. Do not follow hosted payment URL. Do not complete payment.
#
# Env:
#   UPAYMENTS_SANDBOX_BASE_URL  (default https://sandboxapi.upayments.com/api/v1)
#   UPAYMENTS_SANDBOX_TOKEN     (public sandbox Bearer)
#   UPAYMENTS_SANDBOX_API_SECRET (public sandbox HMAC secret; optional)
set -euo pipefail

BASE="${UPAYMENTS_SANDBOX_BASE_URL:-https://sandboxapi.upayments.com/api/v1}"
TOKEN="${UPAYMENTS_SANDBOX_TOKEN:-}"
SECRET="${UPAYMENTS_SANDBOX_API_SECRET:-}"
TS="$(date -u +%s)"
ORDER_ID="r6probe-${TS}-${RANDOM}"

echo "SANDBOX_AUTH_OBSERVATION"
echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "endpoint=POST ${BASE}/charge"
echo "token_present=$([[ -n $TOKEN ]] && echo yes || echo no)"
echo "hmac_secret_present=$([[ -n $SECRET ]] && echo yes || echo no)"

if [[ -z "$TOKEN" ]]; then
  echo "RESULT=SKIPPED_NO_TOKEN"
  echo "classification=SANDBOX_AUTH_OBSERVATION"
  exit 0
fi

# Minimal synthetic Charge body (byte-identical to signed body).
BODY="$(printf '{"order":{"id":"%s"},"reference":{"id":"%s"},"total_price":"1.000","currency_type":"KWD","products":[{"title":"probe","quantity":1,"price":"1.000"}]}' "$ORDER_ID" "$ORDER_ID")"

# HMAC: Base64(HMAC-SHA256(timestamp + METHOD + API_PATH + raw_body, secret))
# API_PATH = charge (not /api/v1/charge) per current first-party HMAC guide.
hmac_sig() {
  local ts="$1" secret="$2" body="$3"
  printf '%s' "${ts}POSTcharge${body}" | openssl dgst -sha256 -hmac "$secret" -binary | base64
}

WORK="${RUNNER_TEMP:-${TEMP:-/tmp}}"
PROBE_HDR="${WORK}/upay-probe.hdr"
PROBE_JSON="${WORK}/upay-probe.json"
PROBE_ERR="${WORK}/upay-probe.err"

probe() {
  local label="$1"
  shift
  echo "--- ${label} ---"
  local out
  out="$(curl -sS -D "$PROBE_HDR" -o "$PROBE_JSON" -w '%{http_code}' \
    -X POST "${BASE}/charge" \
    -H 'Content-Type: application/json' \
    "$@" \
    --data-binary "$BODY" 2>"$PROBE_ERR" || echo 000)"
  echo "http_status=${out}"
  PROBE_HDR="$PROBE_HDR" PROBE_JSON="$PROBE_JSON" python3 - <<'PY'
import json,re,os
hdr=open(os.environ["PROBE_HDR"],errors="replace").read()
body_raw=open(os.environ["PROBE_JSON"],errors="replace").read()
loc=""
for line in hdr.splitlines():
    if line.lower().startswith("location:"):
        loc=line.split(":",1)[1].strip()
def redact(x):
    return re.sub(r"[A-Za-z0-9._-]{12,}","[REDACTED]",str(x))
try:
    d=json.loads(body_raw) if body_raw.strip().startswith("{") else {}
except Exception:
    d={}
print("provider_status=", redact(d.get("status") if d else "n/a"))
print("provider_message=", redact(d.get("message") or d.get("msg") or ("" if d else body_raw[:80])))
print("location_header_present=", "yes" if loc else "no")
print("session_identity_present=", "yes" if (loc or any(k in d for k in ("session_id","payment_url","url","redirect"))) else "no")
PY
}

if [[ -n "$SECRET" ]]; then
  TS_NOW="$(date -u +%s)"
  SIG="$(hmac_sig "$TS_NOW" "$SECRET" "$BODY")"
  BAD_SIG="AAAA$(printf '%s' "$SIG" | tail -c 8)"
  probe "A Bearer-only" -H "Authorization: Bearer ${TOKEN}"
  probe "B Bearer+valid-HMAC" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${SIG}"
  probe "C Bearer+invalid-HMAC" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${BAD_SIG}"
  probe "D HMAC-headers-absent with HMAC-capable token" -H "Authorization: Bearer ${TOKEN}"
else
  probe "A Bearer-only" -H "Authorization: Bearer ${TOKEN}"
  echo "NOTE: UPAYMENTS_SANDBOX_API_SECRET not set; HMAC cases skipped"
fi

echo "NOTE: Bearer-only success does NOT prove HMAC globally optional."
echo "NOTE: valid-HMAC success does NOT prove HMAC globally mandatory."
echo "NOTE: sandbox does not prove live merchant behavior."
echo "classification=SANDBOX_AUTH_OBSERVATION"
