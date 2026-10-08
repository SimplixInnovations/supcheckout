#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/tests/integration/lib/http-server-diagnostics.sh"

if [[ ! -f "$HELPER" ]]; then
  echo "FAIL: HTTP diagnostics helper is missing: $HELPER" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$HELPER"

tmp="$(mktemp -d)"
cleanup() {
  if [[ -n "${live_pid:-}" ]]; then
    kill "$live_pid" 2>/dev/null || true
    wait "$live_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

fakebin="$tmp/bin"
mkdir -p "$fakebin"
count_file="$tmp/curl-count"
printf '0\n' > "$count_file"
cat > "$fakebin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
count_file="${SUPCHECKOUT_FAKE_CURL_COUNT:?}"
count="$(cat "$count_file")"
printf '%s\n' "$((count + 1))" > "$count_file"
exit "${SUPCHECKOUT_FAKE_CURL_RC:-0}"
CURL
chmod +x "$fakebin/curl"

server_log="$tmp/php-server.log"
printf '%s\n' 'PHP-SERVER-LOG-SENTINEL' > "$server_log"
sleep 60 &
live_pid=$!

export PATH="$fakebin:$PATH"
export SUPCHECKOUT_FAKE_CURL_COUNT="$count_file"
export SUPCHECKOUT_FAKE_CURL_RC=52

# The authoritative gate runs nginx + PHP-FPM, whose evidence lives in the
# stack directory rather than a php -S server log. A failure must surface it.
stack_dir="$tmp/http-stack"
mkdir -p "$stack_dir"
printf '%s\n' 'FPM-ERROR-SENTINEL' > "$stack_dir/fpm-error.log"
printf '%s\n' 'PHP-ERROR-SENTINEL' > "$stack_dir/php-error.log"
printf '%s\n' 'NGINX-ERROR-SENTINEL' > "$stack_dir/nginx-error.log"
printf '%s\n' 'FPM-SLOWLOG-SENTINEL' > "$stack_dir/fpm-slow.log"
export SUPCHECKOUT_HTTP_STACK_DIR="$stack_dir"

diag="$tmp/diag.txt"
set +e
supcheckout_curl_once_or_diagnose \
  'missing API key configuration / Store API cart' \
  "$live_pid" \
  "$server_log" \
  -fsS --max-time 20 http://127.0.0.1:8080/index.php >"$tmp/stdout.txt" 2>"$diag"
rc=$?
set -e

[[ "$rc" -eq 52 ]] || { echo "FAIL: curl exit code was not preserved (got $rc)" >&2; exit 1; }
[[ "$(cat "$count_file")" == '1' ]] || { echo 'FAIL: failing request was retried' >&2; exit 1; }
grep -Fq 'SUPCheckout HTTP transport failure: missing API key configuration / Store API cart' "$diag" || { echo 'FAIL: missing failure label' >&2; exit 1; }
grep -Fq 'curl exit: 52' "$diag" || { echo 'FAIL: missing curl exit code' >&2; exit 1; }
grep -Fq "PHP server PID: $live_pid" "$diag" || { echo 'FAIL: missing server PID' >&2; exit 1; }
grep -Fq 'PHP server state: alive' "$diag" || { echo 'FAIL: missing alive server state' >&2; exit 1; }
grep -Fq 'PHP-SERVER-LOG-SENTINEL' "$diag" || { echo 'FAIL: missing server log contents' >&2; exit 1; }
grep -Fq 'FPM-ERROR-SENTINEL' "$diag" || { echo 'FAIL: missing PHP-FPM error log contents' >&2; exit 1; }
grep -Fq 'PHP-ERROR-SENTINEL' "$diag" || { echo 'FAIL: missing PHP error log contents' >&2; exit 1; }
grep -Fq 'NGINX-ERROR-SENTINEL' "$diag" || { echo 'FAIL: missing nginx error log contents' >&2; exit 1; }
# A request killed by max_execution_time only names the line it died on; the
# PHP-FPM slow log carries the full PHP backtrace that explains the hang.
grep -Fq 'FPM-SLOWLOG-SENTINEL' "$diag" || { echo 'FAIL: missing PHP-FPM slow log contents' >&2; exit 1; }

# The stack must record slow-request backtraces before the hard limits fire.
stack_conf="$ROOT/tests/integration/lib/start-ci-http-stack.sh"
grep -Eq '^request_slowlog_timeout = [0-9]+s$' "$stack_conf" || { echo 'FAIL: PHP-FPM slow-request log is not enabled' >&2; exit 1; }
grep -Fq 'slowlog = $conf_dir/fpm-slow.log' "$stack_conf" || { echo 'FAIL: PHP-FPM slow log is not written to the stack directory' >&2; exit 1; }
slow_s="$(grep -Eo '^request_slowlog_timeout = [0-9]+' "$stack_conf" | grep -Eo '[0-9]+$')"
exec_s="$(grep -Eo 'max_execution_time\] = [0-9]+' "$stack_conf" | grep -Eo '[0-9]+$')"
(( slow_s < exec_s )) || { echo 'FAIL: slow log must fire before max_execution_time' >&2; exit 1; }

printf '0\n' > "$count_file"
export SUPCHECKOUT_FAKE_CURL_RC=0
: > "$diag"
supcheckout_curl_once_or_diagnose \
  'healthy request' \
  "$live_pid" \
  "$server_log" \
  -fsS http://127.0.0.1:8080/ >"$tmp/stdout.txt" 2>"$diag"
[[ "$(cat "$count_file")" == '1' ]] || { echo 'FAIL: healthy request did not execute exactly once' >&2; exit 1; }
[[ ! -s "$diag" ]] || { echo 'FAIL: healthy request emitted failure diagnostics' >&2; cat "$diag" >&2; exit 1; }

kill "$live_pid"
wait "$live_pid" 2>/dev/null || true
live_pid=''
printf '0\n' > "$count_file"
export SUPCHECKOUT_FAKE_CURL_RC=7
: > "$diag"
set +e
supcheckout_curl_once_or_diagnose \
  'dead server request' \
  '999999' \
  "$server_log" \
  -fsS http://127.0.0.1:8080/ >"$tmp/stdout.txt" 2>"$diag"
rc=$?
set -e
[[ "$rc" -eq 7 ]] || { echo "FAIL: dead-server curl exit code was not preserved (got $rc)" >&2; exit 1; }
[[ "$(cat "$count_file")" == '1' ]] || { echo 'FAIL: dead-server request was retried' >&2; exit 1; }
grep -Fq 'PHP server state: not running' "$diag" || { echo 'FAIL: missing dead server state' >&2; exit 1; }

echo 'HTTP server diagnostics harness: PASS'
