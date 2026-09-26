#!/usr/bin/env bash
# Real UPayments public-sandbox auth contract probe.
# Classification: SANDBOX_AUTH_OBSERVATION only (never PRODUCTION_AUTH_CONTRACT).
# Never commit secrets. Never print Authorization / X-Signature / secret / raw session_id.
# No real money. Do not follow hosted payment URL. Do not complete payment.
#
# Env:
#   UPAYMENTS_SANDBOX_BASE_URL   (default https://sandboxapi.upayments.com/api/v1)
#   UPAYMENTS_SANDBOX_TOKEN      (credential family Bearer)
#   UPAYMENTS_SANDBOX_API_SECRET (matching HMAC secret; optional)
#   UPAYMENTS_CREDENTIAL_FAMILY  (label: test-mode | jtest123 | cross-family)
set -euo pipefail

BASE="${UPAYMENTS_SANDBOX_BASE_URL:-https://sandboxapi.upayments.com/api/v1}"
TOKEN="${UPAYMENTS_SANDBOX_TOKEN:-}"
SECRET="${UPAYMENTS_SANDBOX_API_SECRET:-}"
FAMILY="${UPAYMENTS_CREDENTIAL_FAMILY:-unspecified}"
WORK="${RUNNER_TEMP:-${TEMP:-/tmp}}"
PROBE_HDR="${WORK}/upay-probe.hdr"
PROBE_JSON="${WORK}/upay-probe.json"
PROBE_ERR="${WORK}/upay-probe.err"
TS="$(date -u +%s)"
ORDER_ID="r6probe-${TS}-${RANDOM}"
BODY="$(printf '{"order":{"id":"%s","currency":"KWD","amount":"1.000"},"reference":{"id":"%s"},"total_price":"1.000","currency_type":"KWD","language":"en","products":[{"name":"probe","quantity":1,"price":"1.000"}],"returnUrl":"https://example.invalid/return","cancelUrl":"https://example.invalid/cancel","notificationUrl":"https://example.invalid/notify"}' "$ORDER_ID" "$ORDER_ID")"

echo "SANDBOX_AUTH_OBSERVATION"
echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "credential_family=${FAMILY}"
echo "endpoint=POST ${BASE}/charge"
echo "accept_header=yes"
echo "content_type_header=yes"
echo "token_present=$([[ -n $TOKEN ]] && echo yes || echo no)"
echo "hmac_secret_present=$([[ -n $SECRET ]] && echo yes || echo no)"

if [[ -z "$TOKEN" ]]; then
  echo "RESULT=SKIPPED_NO_TOKEN"
  echo "classification=SANDBOX_AUTH_OBSERVATION"
  exit 0
fi

hmac_sig() {
  # payload = timestamp + HTTP_METHOD + API_PATH + exact raw body
  # API_PATH = path immediately after /api/v1/ (e.g. charge)
  local ts="$1" method="$2" apipath="$3" body="$4" secret="$5"
  if [[ -z "$body" ]]; then
    printf '%s' "${ts}${method}${apipath}" | openssl dgst -sha256 -hmac "$secret" -binary | base64
  else
    printf '%s' "${ts}${method}${apipath}${body}" | openssl dgst -sha256 -hmac "$secret" -binary | base64
  fi
}

probe_charge() {
  local label="$1"
  shift
  echo "--- ${label} ---"
  local out
  out="$(curl -sS -D "$PROBE_HDR" -o "$PROBE_JSON" -w '%{http_code}' \
    -X POST "${BASE}/charge" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    "$@" \
    --data-binary "$BODY" 2>"$PROBE_ERR" || echo 000)"
  echo "http_status=${out}"
  PROBE_HDR="$PROBE_HDR" PROBE_JSON="$PROBE_JSON" python3 - <<'PY'
import json, re, os
hdr = open(os.environ["PROBE_HDR"], errors="replace").read()
body_raw = open(os.environ["PROBE_JSON"], errors="replace").read()
loc = ""
for line in hdr.splitlines():
    if line.lower().startswith("location:"):
        loc = line.split(":", 1)[1].strip()
def redact(x):
    return re.sub(r"[A-Za-z0-9._-]{10,}", "[REDACTED]", str(x))
try:
    d = json.loads(body_raw) if body_raw.strip().startswith("{") else {}
except Exception:
    d = {}
print("provider_status=", redact(d.get("status") if d else "n/a"))
print("provider_message=", redact(d.get("message") or d.get("msg") or ("" if d else body_raw[:60])))
print("location_header_present=", "yes" if loc else "no")
print("session_identity_present=", "yes" if (loc or any(k in d for k in ("session_id", "payment_url", "url", "redirect"))) else "no")
# extract session_id presence only (never print raw)
sid = ""
if loc:
    m = re.search(r"[?&]session_id=([^&#\s]+)", loc)
    if m:
        sid = m.group(1)
elif isinstance(d, dict):
    sid = str(d.get("session_id") or "")
open(os.environ["PROBE_HDR"] + ".sid", "w").write(sid)
print("session_id_extracted=", "yes" if sid else "no")
PY
}

probe_status() {
  local label="$1" sid="$2"
  shift 2
  echo "--- ${label} ---"
  local out
  out="$(curl -sS -o "$PROBE_JSON" -w '%{http_code}' \
    -H 'Accept: application/json' \
    "$@" \
    "${BASE}/get-payment-status?session_id=${sid}" 2>"$PROBE_ERR" || echo 000)"
  echo "http_status=${out}"
  echo "body_redacted=yes"
}

# A-D Charge cases (byte-identical BODY used for signing and transmission)
if [[ -n "$SECRET" ]]; then
  TS_NOW="$(date -u +%s)"
  SIG="$(hmac_sig "$TS_NOW" POST charge "$BODY" "$SECRET")"
  BAD_SIG="AAAA$(printf '%s' "$SIG" | tail -c 8)"
  probe_charge "A Bearer-only" -H "Authorization: Bearer ${TOKEN}"
  probe_charge "B Bearer+valid-HMAC" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${SIG}"
  probe_charge "C Bearer+invalid-HMAC" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${BAD_SIG}"
  probe_charge "D HMAC-headers-absent" -H "Authorization: Bearer ${TOKEN}"
else
  probe_charge "A Bearer-only" -H "Authorization: Bearer ${TOKEN}"
  echo "NOTE: no HMAC secret for this family; B/C/D skipped"
fi

SID=""
if [[ -f "$PROBE_HDR.sid" ]]; then
  SID="$(cat "$PROBE_HDR.sid")"
fi
echo "session_id_extracted=$([[ -n $SID ]] && echo yes || echo no)"

if [[ -n "$SID" ]]; then
  probe_status "Status Bearer-only" "$SID" -H "Authorization: Bearer ${TOKEN}"
  if [[ -n "$SECRET" ]]; then
    TS_NOW="$(date -u +%s)"
    # Documented: GET + get-payment-status + empty body; session_id is query param.
    SIG="$(hmac_sig "$TS_NOW" GET get-payment-status "" "$SECRET")"
    BAD_SIG="AAAA$(printf '%s' "$SIG" | tail -c 8)"
    probe_status "Status Bearer+valid-HMAC" "$SID" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${SIG}"
    probe_status "Status Bearer+invalid-HMAC" "$SID" -H "Authorization: Bearer ${TOKEN}" -H "X-Timestamp: ${TS_NOW}" -H "X-Signature: ${BAD_SIG}"
    echo "QUERY_CANONICALIZATION = PROVIDER CLARIFICATION REQUIRED"
  fi
fi

echo "NOTE: Bearer-only success does NOT prove HMAC globally optional."
echo "NOTE: valid-HMAC success does NOT prove HMAC globally mandatory."
echo "NOTE: sandbox does not prove live merchant behavior."
if [[ "$FAMILY" == "cross-family" ]]; then
  echo "NOTE: CROSS-FAMILY SANDBOX OBSERVATION (not provider-documented pair)"
fi
echo "classification=SANDBOX_AUTH_OBSERVATION"
