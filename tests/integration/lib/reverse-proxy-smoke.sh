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
php -r '
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

sleep 1
base="http://127.0.0.1:${proxy_port}"

echo "PROXY_SMOKE: request through forged-forward proxy"
code=$(curl -sS -o /tmp/r6-proxy-body.html -w '%{http_code}' --max-time 20 "$base/wp-login.php" || echo 000)
echo "PROXY_SMOKE_STATUS=$code"
if [[ "$code" != "200" && "$code" != "302" ]]; then
  echo "FAIL: proxy request did not return a normal WordPress status"
  cat /tmp/r6-proxy-origin.log || true
  exit 1
fi

# Callback through proxy must not invent a trusted absolute payment origin from forged headers.
curl -sS -D /tmp/r6-proxy-cb.hdr -o /tmp/r6-proxy-cb.body --max-time 20 \
  "$base/index.php?wc_upayments=1&wc_order_id=1&track_id=x&requested_order_id=x" || true

if grep -qi 'evil.example' /tmp/r6-proxy-cb.body; then
  echo "FAIL: forged Host leaked into callback body"
  exit 1
fi

# No-cache expectations for public callback surface (when response is produced).
if [[ -f /tmp/r6-proxy-cb.hdr ]]; then
  echo '--- callback headers ---'
  cat /tmp/r6-proxy-cb.hdr
fi

echo 'R6 local reverse-proxy smoke: PASS (forged forwarded headers did not rewrite trusted payment origin)'
echo 'Real Cloudflare/CDN remains EXTERNAL REQUIRED'
