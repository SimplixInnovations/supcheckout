#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
probe_source="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}/tests/integration/fixtures/request-context-probe.php"
state_fixture="${GITHUB_WORKSPACE}/tests/integration/RequestContextState.php"
diagnostics_helper="${GITHUB_WORKSPACE}/tests/integration/lib/http-server-diagnostics.sh"
probe_dest="$wp_root/wp-content/mu-plugins/supcheckout-request-context-probe.php"
port="${SUPCHECKOUT_CONTEXT_PORT:-8080}"
base_url="http://127.0.0.1:${port}"
server_log="${RUNNER_TEMP:-/tmp}/supcheckout-http-server.log"
server_pid=''

cleanup() {
  rm -f "$probe_dest"
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$probe_source" ]] || { echo "Request-context probe missing: $probe_source" >&2; exit 66; }
[[ -f "$state_fixture" ]] || { echo "Request-context state fixture missing: $state_fixture" >&2; exit 67; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }
[[ -f "$diagnostics_helper" ]] || { echo "HTTP diagnostics helper missing: $diagnostics_helper" >&2; exit 71; }

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

# Keep all generated WordPress URLs on the disposable loopback HTTP origin so
# canonical redirects cannot turn a certification request into a DNS/network
# dependency. Explicitly keep the disposable store live as well.
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
# writer before starting a web request. Using normal update_option() here would
# invoke WooCommerce's settings-change observer against the malformed old value
# and test WooCommerce internals instead of SUPCheckout request-context safety.
set_gateway_state KWD yes certification-key

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
    "$server_pid" \
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
    "$server_pid" \
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
  # A generic REST request may or may not cause WooCommerce to hydrate a
  # session depending on the supported WooCommerce/runtime combination. Session
  # hydration is not the availability contract; gateway parity is. The explicit
  # sessionless REST probe below remains the fail-safe proof for no-session use.
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