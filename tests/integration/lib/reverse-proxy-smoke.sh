#!/usr/bin/env bash
# R6 local reverse-proxy certification smoke.
# Starts PHP built-in server, then a tiny TCP proxy that injects forged
# forwarded headers, and asserts fail-closed callback URL / no-cache behavior.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi
wp_root="$1"
port="${SUPCHECKOUT_PROXY_ORIGIN_PORT:-8090}"
proxy_port="${SUPCHECKOUT_PROXY_PORT:-8091}"
wp_cli="${WP_CLI_BIN:-wp}"

stop_all() {
  [[ -n "${origin_pid:-}" ]] && kill "$origin_pid" 2>/dev/null || true
  [[ -n "${proxy_pid:-}" ]] && kill "$proxy_pid" 2>/dev/null || true
}
trap stop_all EXIT

php -S "127.0.0.1:${port}" -t "$wp_root" >/tmp/r6-proxy-origin.log 2>&1 &
origin_pid=$!

# Tiny forged-forward proxy: always adds hostile X-Forwarded-* headers.
SUPCHECKOUT_PROXY_ORIGIN_PORT="$port" SUPCHECKOUT_PROXY_PORT="$proxy_port" php -r '
$up = "127.0.0.1:" . getenv("SUPCHECKOUT_PROXY_ORIGIN_PORT");
$ps = stream_socket_server("tcp://127.0.0.1:" . getenv("SUPCHECKOUT_PROXY_PORT"), $errno, $errstr);
if (!$ps) { fwrite(STDERR, $errstr); exit(1); }
while ($c = @stream_socket_accept($ps, 30)) {
    $req = "";
    while (!str_contains($req, "\r\n\r\n")) {
        $chunk = fread($c, 8192);
        if ($chunk === false || $chunk === "") break;
        $req .= $chunk;
    }
    $req = preg_replace("/\r\nHost:.*\r\n/i", "\r\nHost: evil.example\r\n", $req, 1);
    $req = str_replace("\r\n\r\n", "\r\nX-Forwarded-Host: evil.example\r\nX-Forwarded-Proto: https\r\nX-Forwarded-For: 10.0.0.1\r\n\r\n", $req);
    $u = stream_socket_client("tcp://" . $up, $en, $es, 5);
    if (!$u) { fclose($c); continue; }
    fwrite($u, $req);
    stream_copy_to_stream($u, $c);
    fclose($u); fclose($c);
}
' >/tmp/r6-proxy.log 2>&1 &
proxy_pid=$!

sleep 2
base="http://127.0.0.1:${proxy_port}"
origin="http://127.0.0.1:${port}"
callback_url="${R6_CALLBACK_URL:-}"
status_url="${R6_STATUS_URL:-}"
if [[ -z "$callback_url" ]]; then
  callback_url="$origin/wc-api/wc_upayments/"
fi
if [[ -z "$status_url" ]]; then
  status_url="$origin/"
fi

echo "PROXY_SMOKE: origin callback no-cache"
curl -sS -D /tmp/r6-origin-cb.hdr -o /tmp/r6-origin-cb.body --max-time 20 \
  "$callback_url?wc_order_id=1&track_id=x&requested_order_id=x" || true
echo '--- origin callback headers ---'
cat /tmp/r6-origin-cb.hdr || true
if ! grep -qiE 'Cache-Control:.*(no-cache|no-store|must-revalidate)' /tmp/r6-origin-cb.hdr; then
  echo "FAIL: origin callback missing explicit no-cache Cache-Control"
  exit 1
fi

echo "PROXY_SMOKE: request through forged-forward proxy"
code=$(curl -sS -o /tmp/r6-proxy-body.html -w '%{http_code}' --max-time 20 "$base/wp-login.php" || echo 000)
echo "PROXY_SMOKE_STATUS=$code"
if [[ "$code" != "200" && "$code" != "302" ]]; then
  echo "FAIL: proxy request did not return a normal WordPress status"
  cat /tmp/r6-proxy-origin.log || true
  cat /tmp/r6-proxy.log || true
  exit 1
fi

# Case 1: route callback through the forged-forward proxy by swapping host:port.
proxy_callback="${callback_url/$origin/$base}"
curl -sS -D /tmp/r6-proxy-cb.hdr -o /tmp/r6-proxy-cb.body --max-time 20 \
  -H 'X-Forwarded-Host: evil.example' \
  -H 'X-Forwarded-Proto: https' \
  -H 'X-Forwarded-For: 10.0.0.1' \
  "$proxy_callback?wc_order_id=1&track_id=x&requested_order_id=x" || true

echo '--- proxy callback headers ---'
cat /tmp/r6-proxy-cb.hdr || true

if grep -qi 'evil.example' /tmp/r6-proxy-cb.body; then
  echo "FAIL: forged Host leaked into callback body"
  exit 1
fi
if grep -qi '^Location:.*evil.example' /tmp/r6-proxy-cb.hdr; then
  echo "FAIL: forged Host leaked into Location header"
  exit 1
fi

# Public status surface no-cache (direct origin)
curl -sS -D /tmp/r6-proxy-st.hdr -o /tmp/r6-proxy-st.body --max-time 20 \
  -H 'X-Forwarded-Host: evil.example' \
  "$status_url" || true
if ! grep -qiE 'Cache-Control:.*(no-cache|no-store|must-revalidate)' /tmp/r6-proxy-st.hdr; then
  echo "FAIL: public status missing explicit no-cache Cache-Control"
  cat /tmp/r6-proxy-st.hdr || true
  exit 1
fi

echo 'R6 local reverse-proxy smoke: PASS (forged forwarded headers did not rewrite trusted payment origin; no-cache present)'
echo 'Real Cloudflare/CDN remains EXTERNAL REQUIRED'
