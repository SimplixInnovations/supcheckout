#!/usr/bin/env bash
# Process-level steps of the Sub-project 0b Task 12 migration matrix
# (.github/workflows/takeover-migration-matrix.yml). Assertions live in
# tests/integration/TakeoverMatrixTest.php; this script only does what a PHP process
# cannot do to itself: download and install the official plugin, run the store's web
# server, SIGKILL the copy runner, and deliver webhooks over HTTP.
#
# Usage: takeover-matrix.sh <step> <wordpress-path> [args...]
#   install-official <commit> <tree-sha256> <version>
#                       download upaymentskwt/woocommerce at the pinned commit, verify the
#                       archive's top directory names that commit and the extracted tree's
#                       SHA-256 manifest digest, install it as wp-content/plugins/upayments.
#                       Nothing from the archive is executed here: it is only copied, and
#                       WordPress later loads it as a plugin inside this disposable runtime.
#   start-server        serve the store on http://127.0.0.1:8080 (PHP built-in server)
#   phase <name>        run one TakeoverMatrixTest.php phase
#   interrupted-run     kill `run` mid copy batch (SIGKILL), check, then resume it
#   webhook             two legacy-route deliveries and one on the new route
#
# Requires GITHUB_WORKSPACE, SUPCHECKOUT_MATRIX_DIR, RUNNER_TEMP; WP_CLI_BIN optional.
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <step> <wordpress-path> [args...]" >&2
  exit 64
fi

step="$1"
wp_root="$2"
shift 2
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
: "${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
test_file="$GITHUB_WORKSPACE/tests/integration/TakeoverMatrixTest.php"
base_url='http://127.0.0.1:8080'

wp() {
  "$wp_cli" "$@" --path="$wp_root"
}

phase() {
  SUPCHECKOUT_MATRIX_PHASE="$1" wp eval-file "$test_file"
}

case "$step" in
  install-official)
    commit="$1"
    expected_tree="$2"
    version="$3"
    [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || { echo "Commit must be a full SHA: $commit" >&2; exit 65; }
    work="$(mktemp -d "${RUNNER_TEMP:?}/official-XXXXXX")"
    curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors --connect-timeout 15 --max-time 180 \
      "https://codeload.github.com/upaymentskwt/woocommerce/tar.gz/${commit}" -o "$work/official.tar.gz"
    mkdir "$work/tree"
    tar -xzf "$work/official.tar.gz" -C "$work/tree" --no-same-owner --no-same-permissions
    top="$(find "$work/tree" -mindepth 1 -maxdepth 1)"
    [[ "$top" == "$work/tree/woocommerce-${commit}" ]] || { echo "Unexpected archive layout: $top" >&2; exit 66; }
    if find "$top" -type l | grep -q .; then
      echo 'The official archive contains symbolic links; refusing it' >&2
      exit 67
    fi
    actual_tree="$(cd "$top" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)"
    echo "OFFICIAL: commit=$commit tree_sha256=$actual_tree files=$(find "$top" -type f | wc -l)"
    [[ "$actual_tree" == "$expected_tree" ]] || { echo "Tree digest mismatch: expected $expected_tree, got $actual_tree" >&2; exit 68; }
    header_version="$(grep -m1 -E '^[[:space:]]*\*?[[:space:]]*Version:' "$top/UPayments.php" | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]*$//')"
    [[ "$header_version" == "$version" ]] || { echo "Header version $header_version != $version" >&2; exit 69; }
    rm -rf "$wp_root/wp-content/plugins/upayments"
    cp -R "$top" "$wp_root/wp-content/plugins/upayments"
    rm -rf "$work"
    ;;

  start-server)
    log="${RUNNER_TEMP:?}/takeover-matrix-http.log"
    PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8080 -t "$wp_root" >"$log" 2>&1 &
    disown
    for _ in $(seq 1 30); do
      if curl -fsS -o /dev/null "$base_url/wp-login.php"; then
        echo "HTTP: store served at $base_url"
        exit 0
      fi
      sleep 1
    done
    cat "$log" >&2
    exit 70
    ;;

  phase)
    phase "$1"
    ;;

  interrupted-run)
    set +e
    SUPCHECKOUT_MATRIX_KILL_AFTER_COPIES=57 "$wp_cli" supcheckout takeover run --yes --deactivate-official --path="$wp_root"
    code=$?
    set -e
    echo "MATRIX: killed run exited $code"
    [[ "$code" -eq 137 ]] || { echo "Expected the copy runner to be SIGKILLed (137), got $code" >&2; exit 71; }
    phase killed
    # The killed runner's lock only expires after CopyRunner::LOCK_TTL; `run` waits for it
    # (exit 4 means it is still busy: rerun later, as an operator would).
    for attempt in 1 2 3; do
      set +e
      "$wp_cli" supcheckout takeover run --yes --deactivate-official --path="$wp_root"
      code=$?
      set -e
      echo "MATRIX: resumed run attempt $attempt exited $code"
      if [[ "$code" -eq 0 ]]; then
        exit 0
      fi
      [[ "$code" -eq 4 ]] || exit "$code"
    done
    exit 72
    ;;

  webhook)
    order_id="$(wp eval 'echo (int) get_option("supcheckout_matrix_manifest")["roles"]["pending_open_link"];')"
    requested="$(wp eval '$o = wc_get_order((int) get_option("supcheckout_matrix_manifest")["roles"]["pending_open_link"]); echo $o->get_meta("UPayments_order_id");')"
    [[ "$order_id" =~ ^[0-9]+$ && -n "$requested" ]] || { echo 'Webhook target not found' >&2; exit 73; }
    for route in wc_upayments wc_upayments supcheckout; do
      status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
        --data-urlencode "track_id=matrix-webhook-track" \
        --data-urlencode "requested_order_id=${requested}" \
        "$base_url/?wc-api=${route}&wc_order_id=${order_id}")"
      echo "WEBHOOK: route=$route order=$order_id http=$status"
    done
    ;;

  *)
    echo "Unknown step: $step" >&2
    exit 64
    ;;
esac
