#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
diagnostics_helper="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}/tests/integration/lib/http-server-diagnostics.sh"
port="${SUPCHECKOUT_CALLBACK_CACHE_PORT:-8081}"
base_url="http://127.0.0.1:${port}"
server_log="${RUNNER_TEMP:-/tmp}/supcheckout-callback-cache-http.log"
server_pid=''

cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$diagnostics_helper" ]] || { echo "HTTP diagnostics helper missing: $diagnostics_helper" >&2; exit 71; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }

# shellcheck source=/dev/null
source "$diagnostics_helper"

# Keep the disposable store on the loopback origin so redirects stay local.
"$wp_cli" option update home "$base_url" --path="$wp_root" >/dev/null
"$wp_cli" option update siteurl "$base_url" --path="$wp_root" >/dev/null
"$wp_cli" option update permalink_structure '' --path="$wp_root" >/dev/null
"$wp_cli" option update woocommerce_coming_soon no --path="$wp_root" >/dev/null
"$wp_cli" rewrite structure '' --path="$wp_root" >/dev/null || true

php -S "127.0.0.1:${port}" -t "$wp_root" >"$server_log" 2>&1 &
server_pid=$!

ready=0
last_ready_curl_rc=0
for attempt in $(seq 1 30); do
  if curl -fsS --max-time 10 "$base_url/wp-login.php" >/dev/null; then
    ready=1
    break
  else
    last_ready_curl_rc=$?
  fi
  sleep 1
done
if [[ "$ready" != "1" ]]; then
  supcheckout_dump_http_server_diagnostics \
    'PHP built-in server readiness' \
    "$last_ready_curl_rc" \
    "$server_pid" \
    "$server_log"
  exit 70
fi

headers_file="${RUNNER_TEMP:-/tmp}/supcheckout-callback-cache-headers.txt"
body_file="${RUNNER_TEMP:-/tmp}/supcheckout-callback-cache-body.txt"

assert_no_cache_headers() {
  local label="$1"
  local headers
  headers="$(tr -d '\r' <"$headers_file")"

  grep -Eiq '^Cache-Control:.*no-cache' <<<"$headers" || {
    echo "FAIL: $label missing Cache-Control no-cache" >&2
    echo "$headers" >&2
    exit 1
  }
  grep -Eiq '^Cache-Control:.*no-store' <<<"$headers" || {
    echo "FAIL: $label missing Cache-Control no-store" >&2
    echo "$headers" >&2
    exit 1
  }
  grep -Eiq '^Cache-Control:.*max-age=0' <<<"$headers" || {
    echo "FAIL: $label missing Cache-Control max-age=0" >&2
    echo "$headers" >&2
    exit 1
  }
  if grep -Eiq '^Cache-Control:.*public' <<<"$headers"; then
    echo "FAIL: $label response is publicly cacheable" >&2
    echo "$headers" >&2
    exit 1
  fi
  echo "PASS: $label no-cache policy present"
}

# Invalid browser callback — browser mode with insufficient routing input.
: > "$headers_file"
: > "$body_file"
supcheckout_curl_once_or_diagnose \
  'browser-invalid callback' \
  "$server_pid" \
  "$server_log" \
  -sS -D "$headers_file" -o "$body_file" \
  --max-time 20 \
  -X GET \
  "$base_url/?wc-api=wc_upayments&page=success" \
  >/dev/null

browser_status="$(head -n 1 "$headers_file" | tr -d '\r')"
echo "browser-invalid status line: $browser_status"
grep -Eiq ' 30[0-9] ' <<<"$browser_status" || grep -Eiq 'HTTP/[0-9.]+ 30[0-9]' <<<"$browser_status" || {
  echo "FAIL: browser-invalid callback did not remain a neutral redirect" >&2
  cat "$headers_file" >&2
  exit 1
}
grep -Eiq '^Location:.*upayments_verification=pending' <<<"$(tr -d '\r' <"$headers_file")" || {
  echo "FAIL: browser-invalid callback redirect target changed" >&2
  cat "$headers_file" >&2
  exit 1
}
assert_no_cache_headers 'browser-invalid callback'

# Invalid webhook callback — POST with missing routing input.
: > "$headers_file"
: > "$body_file"
supcheckout_curl_once_or_diagnose \
  'webhook-invalid callback' \
  "$server_pid" \
  "$server_log" \
  -sS -D "$headers_file" -o "$body_file" \
  --max-time 20 \
  -X POST \
  "$base_url/?wc-api=wc_upayments" \
  >/dev/null

webhook_status="$(head -n 1 "$headers_file" | tr -d '\r')"
echo "webhook-invalid status line: $webhook_status"
grep -Eiq 'HTTP/[0-9.]+ 200' <<<"$webhook_status" || {
  echo "FAIL: webhook-invalid callback status semantics changed" >&2
  cat "$headers_file" >&2
  exit 1
}
assert_no_cache_headers 'webhook-invalid callback'

# Public status unavailable — no valid authorization/order.
: > "$headers_file"
: > "$body_file"
supcheckout_curl_once_or_diagnose \
  'public status unavailable' \
  "$server_pid" \
  "$server_log" \
  -sS -D "$headers_file" -o "$body_file" \
  --max-time 20 \
  -X GET \
  "$base_url/?wc-api=wc_upayments&get_order_status=1" \
  >/dev/null

status_status="$(head -n 1 "$headers_file" | tr -d '\r')"
echo "public-status-unavailable status line: $status_status"
grep -Eiq 'HTTP/[0-9.]+ 404' <<<"$status_status" || {
  echo "FAIL: public status unavailable did not remain 404" >&2
  cat "$headers_file" >&2
  exit 1
}
body="$(tr -d '\r' <"$body_file")"
grep -Fq 'Order status unavailable.' <<<"$body" || {
  echo "FAIL: public status unavailable body changed" >&2
  echo "$body" >&2
  exit 1
}
assert_no_cache_headers 'public status unavailable'

# UPayments appends its own query to the return URL with "?" although the URL
# already has one (observed on the sandbox, 2026-10-15), so the order ID arrives as
# "<id>?payment_id=...". A paid, verified order must still reach the order-received
# page; this needs no provider call because the paid track is answered locally.
paid_order_id="$("$wp_cli" eval '
  $order = wc_create_order();
  $order->set_payment_method("upayments");
  $order->set_total("4.450");
  $order->update_meta_data("UPayments_order_id", "cert-glued-requested");
  $order->update_meta_data("UPayments_TrackID", "cert-glued-track");
  $order->update_meta_data("_simplixpay_upayments_status_track_v1", "cert-glued-track");
  $order->update_meta_data("_simplixpay_upayments_status_requested_v1", "cert-glued-requested");
  $order->update_meta_data("_upay_verified_capture", 1);
  $order->set_status("processing");
  echo $order->save();
' --path="$wp_root")"
[[ "$paid_order_id" =~ ^[0-9]+$ ]] || { echo "FAIL: could not seed the paid order ($paid_order_id)" >&2; exit 1; }

: > "$headers_file"
: > "$body_file"
supcheckout_curl_once_or_diagnose \
  'browser return with provider-glued query' \
  "$server_pid" \
  "$server_log" \
  -sS -D "$headers_file" -o "$body_file" \
  --max-time 20 \
  -X GET \
  "$base_url/?wc-api=wc_upayments&page=success&wc_order_id=${paid_order_id}?payment_id=100628810000033571&result=CAPTURED&track_id=cert-glued-track&requested_order_id=cert-glued-requested" \
  >/dev/null

glued_headers="$(tr -d '\r' <"$headers_file")"
echo "glued-return status line: $(head -n 1 <<<"$glued_headers")"
grep -Eiq '^Location:.*order-received' <<<"$glued_headers" || {
  echo "FAIL: paid order's browser return with the provider's glued query did not reach order-received" >&2
  echo "$glued_headers" >&2
  exit 1
}
echo 'PASS: browser return with the provider-glued query reaches order-received'
assert_no_cache_headers 'browser return with provider-glued query'

echo 'PASS: callback-cache HTTP fixture completed'
