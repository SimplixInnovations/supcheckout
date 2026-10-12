#!/usr/bin/env bash
# Owner-run production authentication probe for ONE merchant account.
# Classification: PRODUCTION_ACCOUNT_AUTH_OBSERVATION (account-scoped, point-in-time).
#
# Answers one question: does THIS production merchant account accept the
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
#   - the Charge body carries the same top-level keys, token placeholders and
#     live User-Agent as CheckoutOrchestrator; the only added field is the
#     1-minute paymentLinkExpiryInMinutes safety bound;
#   - the key reaches curl from a 0600 file and the track ID through stdin,
#     so neither appears in the process list;
#   - status acceptance requires a transaction bound to the probed track ID,
#     the same binding StatusVerifier enforces;
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
      // StatusVerifier binds the transaction to the queried track ID; a 201 for an
      // unknown or foreign track ID is not acceptance of the SUPCheckout request.
      $expected_track = (string) getenv("PROBE_TRACK_ID");
      $tx = is_array($d) && isset($d["data"]["transaction"]["track_id"])
        && is_scalar($d["data"]["transaction"]["track_id"])
        && $expected_track !== ""
        && (string) $d["data"]["transaction"]["track_id"] === $expected_track;
      echo "transaction_bound_to_probed_track=", $tx ? "yes" : "no", PHP_EOL;
      $accepted = $http === 201 && $ok && $tx;
    } elseif ($kind === "buttons") {
      // Same isWhiteLabel normalization as PaymentMethodAvailability; same
      // knet-then-cc preference a white-label Charge needs for paymentGateway.src.
      $wl = is_array($d) && isset($d["data"]) && is_array($d["data"]) && array_key_exists("isWhiteLabel", $d["data"])
        ? $d["data"]["isWhiteLabel"] : null;
      $mode = in_array($wl, array(true, 1, "1"), true) ? "yes" : (in_array($wl, array(false, 0, "0"), true) ? "no" : "");
      $buttons = isset($d["data"]["payButtons"]) && is_array($d["data"]["payButtons"]) ? $d["data"]["payButtons"] : array();
      $on = function ($k) use ($buttons) { return isset($buttons[$k]) && in_array($buttons[$k], array(true, 1, "1"), true); };
      if ($mode !== "") {
        echo "whitelabel=", $mode, PHP_EOL;
        echo "gateway_src=", $on("knet") ? "knet" : ($on("credit_card") ? "cc" : ""), PHP_EOL;
      }
      $accepted = $http === 201 && $ok && $mode !== "";
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
    // On a non-accepted response, show only the provider validation text: URLs (payment
    // links) and the probed track ID are replaced, and each line is capped at 200 chars.
    if (!$accepted && is_array($d)) {
      $track = (string) getenv("PROBE_TRACK_ID");
      $clean = function ($text) use ($track) {
        $text = preg_replace("~https?://\\S+~i", "[url]", (string) $text);
        if ($track !== "") {
          $text = str_replace($track, "[track_id]", $text);
        }
        return substr(trim(preg_replace("~\\s+~", " ", $text)), 0, 200);
      };
      foreach (array("message", "error") as $key) {
        if (isset($d[$key]) && is_scalar($d[$key])) {
          echo "provider_", $key, "=", $clean($d[$key]), PHP_EOL;
        }
      }
      if (isset($d["errors"]) && is_array($d["errors"])) {
        $shown = 0;
        foreach ($d["errors"] as $field => $messages) {
          if ($shown++ >= 10) {
            break;
          }
          $first = is_array($messages) ? reset($messages) : $messages;
          if (is_scalar($first)) {
            echo "provider_error=", $clean($field), ": ", $clean($first), PHP_EOL;
          }
        }
      }
    }
  ' "$kind" "$http" "$body_file"
}

# Runs curl with the fixed transport flags. Sets HTTP (000 on transport failure)
# and prints curl_exit so a failure is diagnosable without exposing the request.
probe_curl() {
  local rc=0
  HTTP="$(curl -sS -w '%{http_code}' --max-redirs 0 --proto '=https' \
    --connect-timeout 5 --max-time 15 "$@" 2>/dev/null)" || rc=$?
  if (( rc != 0 )); then
    HTTP="000"
  fi
  echo "curl_exit=${rc}"
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
  # The track ID reaches curl through stdin (--config -), never argv. Its format is
  # validated above, so it cannot break out of the quoted config value.
  printf 'url = "%s"\n' "${BASE}/get-payment-status/${TRACK_ID}" > "$WORK/status.cfg"
  probe_curl --config - -o "$WORK/status.json" \
    -H 'Accept: application/json' -H @"$WORK/auth.hdr" < "$WORK/status.cfg"
  PROBE_TRACK_ID="$TRACK_ID" summarize status "$HTTP" "$WORK/status.json"
fi

echo "--- probe 0: GET check-payment-button-status (read-only account mode) ---"
# The request the plugin makes before every checkout; its answer decides whether
# the plugin sends a white-label Charge.
probe_curl -o "$WORK/buttons.json" -A 'UpaymentsWoocommercePlugin/2.2.1' \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H @"$WORK/auth.hdr" \
  "${BASE}/check-payment-button-status"
BUTTONS_OUT="$(summarize buttons "$HTTP" "$WORK/buttons.json")"
printf '%s\n' "$BUTTONS_OUT"
ACCOUNT_WHITELABEL="$(printf '%s\n' "$BUTTONS_OUT" | sed -n 's/^whitelabel=//p')"
GATEWAY_SRC="$(printf '%s\n' "$BUTTONS_OUT" | sed -n 's/^gateway_src=//p')"

echo "--- probe 2: POST charge (initialization only) ---"
if [[ "${CONFIRM_PRODUCTION_CHARGE_INIT:-}" != "YES" ]]; then
  echo "verdict=SKIPPED_NOT_CONFIRMED (set CONFIRM_PRODUCTION_CHARGE_INIT=YES)"
elif [[ "$ACCOUNT_WHITELABEL" != "yes" && "$ACCOUNT_WHITELABEL" != "no" ]] \
  || [[ "$ACCOUNT_WHITELABEL" == "yes" && -z "$GATEWAY_SRC" ]]; then
  echo "verdict=SKIPPED_ACCOUNT_MODE_UNKNOWN (probe 0 did not establish what the plugin would send)"
else
  # Used as order id and reference.id; CheckoutOrchestrator rejects references over 35 chars.
  ID="scprobe-$(date -u +%s)-${RANDOM}"
  if (( ${#ID} > 35 )); then
    echo "RESULT=REFUSED_REFERENCE_TOO_LONG"
    exit 3
  fi
  # Same top-level keys, token placeholders and decimal amount form as
  # CheckoutOrchestrator (guest, one-time, no saved card, no multi-merchant split),
  # in the account mode probe 0 reported: white-label accounts get
  # is_whitelabled=true plus paymentGateway.src, exactly as the plugin adds it.
  # paymentLinkExpiryInMinutes is the one added field: a safety bound so the
  # never-printed link expires after one minute.
  if [[ "$ACCOUNT_WHITELABEL" == "yes" ]]; then
    # shellcheck disable=SC2059
    MODE_JSON="$(printf '"is_whitelabled":true,"paymentGateway":{"src":"%s"}' "$GATEWAY_SRC")"
  else
    MODE_JSON='"is_whitelabled":false'
  fi
  echo "charge_account_mode=whitelabel:${ACCOUNT_WHITELABEL} gateway_src:${GATEWAY_SRC:-none}"
  BODY="$(printf '{"returnUrl":"https://example.com/supcheckout-return","cancelUrl":"https://example.com/supcheckout-cancel","notificationUrl":"https://example.com/supcheckout-webhook","products":[{"name":"SUPCheckout auth probe","description":"Initialization only - do not pay","price":1.000,"quantity":1}],"order":{"id":"%s","description":"SUPCheckout production auth probe - do not pay","currency":"KWD","amount":1.000},"reference":{"id":"%s"},"customer":{"name":"SUPCheckout auth probe"},"plugin":{"src":"woocommerce"},%s,"language":"en","isSaveCard":false,"tokens":{"creditCard":null,"customerUniqueToken":null},"device":{"browser":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36 OPR/93.0.0.0","browserDetails":{"screenWidth":"1920","screenHeight":"1080","colorDepth":"24","javaEnabled":"false","language":"en","timeZone":"-180","3DSecureChallengeWindowSize":"500_X_600"}},"extraMerchantData":null,"paymentLinkExpiryInMinutes":1}' "$ID" "$ID" "$MODE_JSON")"
  probe_curl -X POST -o "$WORK/charge.json" -A 'UpaymentsWoocommercePlugin/2.2.1' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H @"$WORK/auth.hdr" \
    --data-binary "$BODY" "${BASE}/charge"
  PROBE_TRACK_ID="$TRACK_ID" summarize charge "$HTTP" "$WORK/charge.json"
  echo "probe_order_reference=${ID} (find it in the merchant dashboard; it expires unpaid)"
fi

echo "NOTE: account-scoped and point-in-time; says nothing about other accounts or future enforcement."
echo "classification=PRODUCTION_ACCOUNT_AUTH_OBSERVATION"
