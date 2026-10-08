#!/usr/bin/env bash
# Owner-run production authentication probe for ONE merchant account.
# Classification: PRODUCTION_ACCOUNT_AUTH_OBSERVATION (account-scoped, point-in-time).
#
# Answers one question: does THIS production merchant account accept the exact
# Bearer-only requests SUPCheckout sends today, for
#   1. GET  get-payment-status/{track_id}  (StatusVerifier path form), and
#   2. POST charge                          (initialization only, optional)?
#
# Safety contract (enforced by tests/unit/Governance/ProductionAuthProbeContractTest.php):
#   - refuses unless OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES;
#   - refuses inside CI; production credentials never enter CI;
#   - production host is fixed to the plugin's EndpointResolver::LIVE_BASE;
#   - Charge needs a second confirmation, never follows the returned link,
#     and requests a 1-minute link expiry. Initialization is not capture:
#     no money moves unless someone opens and pays the link;
#   - never prints the API key, track ID, payment link or raw provider body.
#
# Env:
#   OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES   required
#   UPAYMENTS_PRODUCTION_API_KEY                     required, production Bearer key
#   UPAYMENTS_PRODUCTION_TRACK_ID                    track ID of a PAST order on this account
#   CONFIRM_PRODUCTION_CHARGE_INIT=YES               optional, enables probe 2
set -euo pipefail
set +x

BASE="https://apiv2api.upayments.com/api/v1"

if [[ -n "${CI:-}" || -n "${GITHUB_ACTIONS:-}" ]]; then
  echo "RESULT=REFUSED_IN_CI"
  exit 3
fi
if [[ "${OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION:-}" != "YES" ]]; then
  echo "RESULT=REFUSED_NOT_AUTHORIZED (set OWNER_PRODUCTION_AUTH_PROBE_AUTHORIZATION=YES)"
  exit 3
fi

API_KEY="${UPAYMENTS_PRODUCTION_API_KEY:-}"
TRACK_ID="${UPAYMENTS_PRODUCTION_TRACK_ID:-}"
if [[ -z "$API_KEY" ]]; then
  echo "RESULT=REFUSED_NO_API_KEY"
  exit 3
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
umask 077
# Keep the Bearer key out of the process list: curl reads it from a file.
printf 'Authorization: Bearer %s\n' "$API_KEY" > "$WORK/auth.hdr"

# Prints only HTTP status, the provider "status" boolean and the presence of
# expected fields. Never the body itself.
summarize() {
  local kind="$1" http="$2" body_file="$3"
  php -r '
    $kind = $argv[1]; $http = (int) $argv[2];
    $raw = (string) @file_get_contents($argv[3]);
    $d = json_decode($raw, true);
    $ok = is_array($d) && array_key_exists("status", $d) && $d["status"] === true;
    echo "http_status=", $http, PHP_EOL;
    echo "provider_status_true=", $ok ? "yes" : "no", PHP_EOL;
    if ($kind === "status") {
      $tx = is_array($d) && isset($d["data"]["transaction"]) && is_array($d["data"]["transaction"]);
      echo "transaction_object_present=", $tx ? "yes" : "no", PHP_EOL;
      $accepted = $http === 201 && $ok && $tx;
    } else {
      // Same two link locations CheckoutOrchestrator / sandbox-charge-smoke accept.
      $link = is_array($d) && (
        (isset($d["data"]["link"]) && is_string($d["data"]["link"]) && $d["data"]["link"] !== "")
        || (isset($d["data"]["transactionData"]["redirect_url"]) && is_string($d["data"]["transactionData"]["redirect_url"]) && $d["data"]["transactionData"]["redirect_url"] !== "")
      );
      echo "payment_link_present=", $link ? "yes" : "no", " (link not printed, not followed)", PHP_EOL;
      $accepted = $http === 201 && $ok && $link;
    }
    if ($accepted) {
      $verdict = "BEARER_ONLY_ACCEPTED";
    } elseif ($http === 401 || $http === 403) {
      $verdict = "REJECTED_AUTH";
    } else {
      $verdict = "INCONCLUSIVE";
    }
    echo "verdict=", $verdict, PHP_EOL;
  ' "$kind" "$http" "$body_file"
}

echo "PRODUCTION_ACCOUNT_AUTH_OBSERVATION"
echo "timestamp_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "host=apiv2api.upayments.com"
echo "auth_headers_sent=Authorization: Bearer (no X-Timestamp, no X-Signature) - identical to SUPCheckout"

echo "--- probe 1: GET get-payment-status/{track_id} ---"
if [[ -z "$TRACK_ID" ]]; then
  echo "verdict=SKIPPED_NO_TRACK_ID"
else
  if [[ ! "$TRACK_ID" =~ ^[A-Za-z0-9_-]{1,128}$ ]]; then
    echo "RESULT=REFUSED_INVALID_TRACK_ID_FORMAT"
    exit 3
  fi
  http="$(curl -sS -o "$WORK/status.json" -w '%{http_code}' --max-redirs 0 --proto '=https' \
    --connect-timeout 5 --max-time 15 \
    -H 'Accept: application/json' -H @"$WORK/auth.hdr" \
    "${BASE}/get-payment-status/${TRACK_ID}" 2>/dev/null || echo 000)"
  summarize status "$http" "$WORK/status.json"
fi

echo "--- probe 2: POST charge (initialization only) ---"
if [[ "${CONFIRM_PRODUCTION_CHARGE_INIT:-}" != "YES" ]]; then
  echo "verdict=SKIPPED_NOT_CONFIRMED (set CONFIRM_PRODUCTION_CHARGE_INIT=YES)"
else
  ID="supcheckout-authprobe-$(date -u +%s)-${RANDOM}"
  BODY="$(printf '{"products":[{"name":"SUPCheckout auth probe","description":"Initialization only - do not pay","price":1.0,"quantity":1}],"order":{"id":"%s","reference":"%s","description":"SUPCheckout production auth probe - do not pay","currency":"KWD","amount":1.0},"language":"en","tokens":{},"reference":{"id":"%s"},"returnUrl":"https://example.com/supcheckout-return","cancelUrl":"https://example.com/supcheckout-cancel","notificationUrl":"https://example.com/supcheckout-webhook","plugin":{"src":"woocommerce"},"paymentLinkExpiryInMinutes":1}' "$ID" "$ID" "$ID")"
  http="$(curl -sS -o "$WORK/charge.json" -w '%{http_code}' --max-redirs 0 --proto '=https' \
    --connect-timeout 5 --max-time 15 -X POST \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H @"$WORK/auth.hdr" \
    --data-binary "$BODY" "${BASE}/charge" 2>/dev/null || echo 000)"
  summarize charge "$http" "$WORK/charge.json"
  echo "probe_order_reference=${ID} (find it in the merchant dashboard; it expires unpaid)"
fi

echo "NOTE: account-scoped and point-in-time; says nothing about other accounts or future enforcement."
echo "classification=PRODUCTION_ACCOUNT_AUTH_OBSERVATION"
