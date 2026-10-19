#!/usr/bin/env bash
# Sub-project 0c Task 9: the supcheckout/v1 response headers over real HTTP.
#
# rest_do_request() never applies `rest_post_dispatch`, where Routes::no_store() sets
# Cache-Control and Retry-After, and WP_REST_Server::serve_request() sends core's no-cache
# headers after the response's own. So the headers are checked on real requests to this
# cell's WordPress, served by the same nginx + PHP-FPM stack as the request-context gate.
# The stack runs only for this step; TakeoverRestTest.php (phase rest_http) sends the
# requests and asserts.
#
# Usage: takeover-rest-http.sh <wordpress-path>
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
workspace="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
http_stack="$workspace/tests/integration/lib/start-ci-http-stack.sh"
fixture_source="$workspace/tests/integration/fixtures/takeover-rest-http-mu-plugin.php"
fixture_dest="$wp_root/wp-content/mu-plugins/supcheckout-takeover-rest-http.php"
port="${SUPCHECKOUT_CONTEXT_PORT:-8080}"
base_url="http://127.0.0.1:${port}"
conf_dir="${RUNNER_TEMP:-/tmp}/supcheckout-http-stack"
HTTP_STACK_PID=''
PHP_FPM_PID=''

cleanup() {
  local status=$?
  if [[ "$status" -ne 0 ]]; then
    for log in php-error.log fpm-error.log nginx-error.log; do
      if [[ -s "$conf_dir/$log" ]]; then
        echo "--- $log (last 40 lines) ---" >&2
        tail -n 40 "$conf_dir/$log" >&2 || true
      fi
    done
  fi
  rm -f "$fixture_dest" "$wp_root/supcheckout-fpm-probe.php"
  if [[ -n "$HTTP_STACK_PID" ]]; then
    kill "$HTTP_STACK_PID" 2>/dev/null || true
    wait "$HTTP_STACK_PID" 2>/dev/null || true
  fi
  if [[ -n "$PHP_FPM_PID" ]]; then
    kill "$PHP_FPM_PID" 2>/dev/null || true
    wait "$PHP_FPM_PID" 2>/dev/null || true
  fi
  return "$status"
}
trap cleanup EXIT

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }
[[ -f "$http_stack" ]] || { echo "HTTP stack helper missing: $http_stack" >&2; exit 71; }
[[ -f "$fixture_source" ]] || { echo "REST HTTP fixture missing: $fixture_source" >&2; exit 66; }

# The request-context gate already moved home and siteurl to this loopback origin; the
# logged-in cookie the test mints is named after siteurl, so require it.
siteurl="$("$wp_cli" option get siteurl --path="$wp_root")"
if [[ "$siteurl" != "$base_url" ]]; then
  echo "siteurl is $siteurl, expected $base_url" >&2
  exit 69
fi

mkdir -p "$wp_root/wp-content/mu-plugins"
cp "$fixture_source" "$fixture_dest"

# shellcheck source=/dev/null
source "$http_stack" "$wp_root" "$port"

SUPCHECKOUT_CERT_PHASE=rest_http \
SUPCHECKOUT_REST_HTTP_BASE="$base_url" \
  "$wp_cli" eval-file "$workspace/tests/integration/TakeoverRestTest.php" --path="$wp_root"
