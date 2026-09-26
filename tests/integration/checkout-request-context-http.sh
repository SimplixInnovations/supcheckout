#!/usr/bin/env bash
# Real HTTP request-context certification for SUPCheckout.
#
# Authoritative HTTP runtime is nginx + PHP-FPM (production-style concurrent
# web stack). PHP's built-in development server (php -S) is NOT used for the
# permanent gate: it segfaulted under multi-request WordPress/Woo workloads
# even after Action Scheduler / WP-Cron isolation (PHP 8.2.34 / 8.4.x).
#
# Action Scheduler / WP-Cron background activity was isolated as a potential
# contributor, but PHP's built-in development server still segfaulted after
# isolation. The permanent HTTP certification therefore uses a production-style
# concurrent web runtime.
#
# Certification request transport failures remain hard failures. Only
# infrastructure readiness may retry.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
probe_source="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}/tests/integration/fixtures/request-context-probe.php"
as_isolation_source="${GITHUB_WORKSPACE}/tests/integration/fixtures/request-context-as-isolation.php"
state_fixture="${GITHUB_WORKSPACE}/tests/integration/RequestContextState.php"
diagnostics_helper="${GITHUB_WORKSPACE}/tests/integration/lib/http-server-diagnostics.sh"
http_stack="${GITHUB_WORKSPACE}/tests/integration/lib/start-ci-http-stack.sh"
probe_dest="$wp_root/wp-content/mu-plugins/supcheckout-request-context-probe.php"
as_isolation_dest="$wp_root/wp-content/mu-plugins/supcheckout-request-context-as-isolation.php"
port="${SUPCHECKOUT_CONTEXT_PORT:-8080}"
base_url="http://127.0.0.1:${port}"
server_log="${RUNNER_TEMP:-/tmp}/supcheckout-http-server.log"
HTTP_STACK_PID=''
PHP_FPM_PID=''

cleanup() {
  rm -f "$probe_dest" "$as_isolation_dest"
  # Only tear down a stack we started. Reused stacks are owned by the caller.
  if [[ "${SUPCHECKOUT_HTTP_STACK_STARTED:-0}" != "1" ]]; then
    if [[ -n "$HTTP_STACK_PID" ]]; then
      kill "$HTTP_STACK_PID" 2>/dev/null || true
      wait "$HTTP_STACK_PID" 2>/dev/null || true
    fi
    if [[ -n "$PHP_FPM_PID" ]]; then
      kill "$PHP_FPM_PID" 2>/dev/null || true
      wait "$PHP_FPM_PID" 2>/dev/null || true
    fi
  fi
}
trap cleanup EXIT

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$probe_source" ]] || { echo "Request-context probe missing: $probe_source" >&2; exit 66; }
[[ -f "$as_isolation_source" ]] || { echo "AS isolation fixture missing: $as_isolation_source" >&2; exit 66; }
[[ -f "$state_fixture" ]] || { echo "Request-context state fixture missing: $state_fixture" >&2; exit 67; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }
[[ -f "$diagnostics_helper" ]] || { echo "HTTP diagnostics helper missing: $diagnostics_helper" >&2; exit 71; }
[[ -f "$http_stack" ]] || { echo "HTTP stack helper missing: $http_stack" >&2; exit 71; }

# shellcheck source=/dev/null
source "$diagnostics_helper"

set_gateway_state() {
  local currency="$1"
  local enabled="$2"
  local api_key="$3"

  SUPCHECKOUT_CERT_CURRENCY="$currency" \
  SUPCHECKOUT_CERT_ENABLED="$enabled" \
  SUPCHECKOUT_CERT_API_KEY="$api_key" \
    "$wp_cli" eval-file "$state_fixture" --path="$wp_root" >/dev/null
}

mkdir -p "$wp_root/wp-content/mu-plugins"
cp "$probe_source" "$probe_dest"
# TEST-ONLY: AS async/cron isolation is retained as a potential-contributor
# control. AS itself is certified separately (ActionSchedulerCompatibilityRuntimeTest
# + R4/R6 jobs). This is NOT described as the proven root cause of the historical
# php -S segfault.
cp "$as_isolation_source" "$as_isolation_dest"

# Keep all generated WordPress URLs on the disposable loopback HTTP origin so
# canonical redirects cannot turn a certification request into a DNS/network
# dependency.
"$wp_cli" option update home "$base_url" --path="$wp_root" >/dev/null
"$wp_cli" option update siteurl "$base_url" --path="$wp_root" >/dev/null
"$wp_cli" option update woocommerce_coming_soon no --path="$wp_root" >/dev/null

checkout_page_id="$("$wp_cli" option get woocommerce_checkout_page_id --path="$wp_root")"
if [[ ! "$checkout_page_id" =~ ^[1-9][0-9]*$ ]]; then
  echo "Invalid WooCommerce checkout page ID: $checkout_page_id" >&2
  exit 69
fi

# The preceding activation safety test intentionally persists malformed gateway
# settings. Normalize the disposable HTTP fixture with the raw certification
# writer before starting a web request.
set_gateway_state KWD yes certification-key

# Production-style concurrent HTTP stack (nginx + PHP-FPM).
# Reuse a caller-provided stack when SUPCHECKOUT_HTTP_STACK_STARTED=1 so
# multi-iteration stability runs do not race restart/bind on the same port.
if [[ "${SUPCHECKOUT_HTTP_STACK_STARTED:-0}" != "1" ]]; then
  # shellcheck source=/dev/null
  source "$http_stack" "$wp_root" "$port"
else
  echo "Reusing existing nginx+php-fpm stack on 127.0.0.1:${port}"
fi

assert_probe() {
  local label="$1"
  local url="$2"
  local expect_checkout="$3"
  local expect_admin="$4"
  local expect_ajax="$5"
  local expect_wc_ajax="$6"
  local expect_rest="$7"
  local expect_session="$8"
  local expect_gateway="$9"
  local output="${RUNNER_TEMP:-/tmp}/supcheckout-probe.json"

  supcheckout_curl_once_or_diagnose \
    "$label" \
    "$HTTP_STACK_PID" \
    "$server_log" \
    -fsS --max-time 20 "$url" -o "$output"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($data)) {
        fwrite(STDERR, $argv[2] . ": invalid JSON\n");
        exit(1);
    }
    $expected_raw = array_slice($argv, 3);
    $actual = array(
        !empty($data["context"]["is_checkout"]),
        !empty($data["context"]["is_admin"]),
        !empty($data["context"]["doing_ajax"]),
        !empty($data["context"]["wc_doing_ajax"]),
        !empty($data["context"]["rest_request"]),
        !empty($data["context"]["session_present"]),
        !empty($data["gateway_present"]),
    );
    foreach ($expected_raw as $index => $expected) {
        if ($expected === "*") {
            continue;
        }
        if ($actual[$index] !== ($expected === "1")) {
            fwrite(STDERR, $argv[2] . ": probe mismatch: " . json_encode($data) . "\n");
            exit(1);
        }
    }
  ' "$output" "$label" "$expect_checkout" "$expect_admin" "$expect_ajax" "$expect_wc_ajax" "$expect_rest" "$expect_session" "$expect_gateway"
  echo "PASS: $label"
}

assert_store_api() {
  local label="$1"
  local expect_gateway="$2"
  local output="${RUNNER_TEMP:-/tmp}/supcheckout-store-api-cart.json"

  supcheckout_curl_once_or_diagnose \
    "$label" \
    "$HTTP_STACK_PID" \
    "$server_log" \
    -fsS --max-time 20 \
    -H 'X-SUPCheckout-Cert: 1' \
    "$base_url/index.php?rest_route=/wc/store/v1/cart" \
    -o "$output"

  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $expected_gateway = $argv[3] === "1";
    if (!is_array($data) || !isset($data["totals"]) || !isset($data["_supcheckout_cert"])) {
        fwrite(STDERR, $argv[2] . ": Store API cart response lacks cart/probe data\n");
        exit(1);
    }
    $probe = $data["_supcheckout_cert"];
    $valid_context = empty($probe["context"]["is_checkout"])
        && empty($probe["context"]["is_admin"])
        && empty($probe["context"]["doing_ajax"])
        && empty($probe["context"]["wc_doing_ajax"])
        && !empty($probe["context"]["rest_request"]);
    $actual_gateway = !empty($probe["gateway_present"]);
    if (!$valid_context || $actual_gateway !== $expected_gateway) {
        fwrite(STDERR, $argv[2] . ": Store API probe mismatch: " . json_encode($probe) . "\n");
        exit(1);
    }
  ' "$output" "$label" "$expect_gateway"
  echo "PASS: $label"
}

run_context_matrix() {
  local state_label="$1"
  local expect_gateway="$2"

  assert_probe "$state_label / Classic checkout" \
    "$base_url/index.php?page_id=${checkout_page_id}&supcheckout_context_probe=1" \
    1 0 0 0 0 1 "$expect_gateway"
  assert_probe "$state_label / wc-ajax" \
    "$base_url/index.php?wc-ajax=supcheckout_context_probe" \
    0 0 1 1 0 1 "$expect_gateway"
  assert_probe "$state_label / admin-ajax" \
    "$base_url/wp-admin/admin-ajax.php?action=supcheckout_context_probe" \
    0 1 1 0 0 1 "$expect_gateway"
  assert_probe "$state_label / generic REST" \
    "$base_url/index.php?rest_route=/supcheckout-cert/v1/context" \
    0 0 0 0 1 '*' "$expect_gateway"
  assert_store_api "$state_label / Store API cart" "$expect_gateway"
}

# Valid configuration must expose SUPCheckout consistently, including when the
# generic REST evaluation deliberately has no WooCommerce session object.
run_context_matrix 'eligible KWD configuration' 1
assert_probe 'eligible KWD configuration / sessionless REST' \
  "$base_url/index.php?rest_route=/supcheckout-cert/v1/context&sessionless=1" \
  0 0 0 0 1 0 1

# Every persisted eligibility dimension must fail closed in every real request
# context, rather than only in a CLI/source-shape harness.
set_gateway_state KWD no certification-key
run_context_matrix 'disabled configuration' 0
assert_probe 'disabled configuration / sessionless REST' \
  "$base_url/index.php?rest_route=/supcheckout-cert/v1/context&sessionless=1" \
  0 0 0 0 1 0 0

set_gateway_state KWD yes ''
run_context_matrix 'missing API key configuration' 0

set_gateway_state JPY yes certification-key
run_context_matrix 'unsupported JPY currency' 0

# Leave the disposable runtime in its canonical eligible state for all later
# compatibility tests in the same matrix cell.
set_gateway_state KWD yes certification-key

echo 'Real HTTP checkout request-context certification passed.'
