#!/usr/bin/env bash
# In-place update paths for a store whose pre-rename build is active as
# supcheckout/UPayments.php (Sub-project 0a Task 2b).
#
# Usage: in-place-update-paths.sh <scenario> <wordpress-path>
#   reset              put the pre-rename build back, active as supcheckout/UPayments.php
#   cli-overwrite      wp plugin install --force, then one plain WP-CLI request
#   web-first-request  overwrite, then the FIRST request is an authenticated GET of wp-admin/plugins.php
#   upload-installer   Plugin_Upgrader::install($zip, overwrite_package) ("Replace current with uploaded")
#   bulk-update        one-click/bulk path: an update offer for the old basename, then wp plugin update
#   single-upgrade     characterization of Plugin_Upgrader::upgrade() (classic single Update-now)
#   multisite          network-activated pre-rename build, overwrite, one plain request (run last)
#
# Requires PRE_RENAME_ZIP, CURRENT_ZIP, GITHUB_WORKSPACE, SUPCHECKOUT_LEGACY_SLUG and
# SUPCHECKOUT_LEGACY_TEXT_DOMAIN. Merchant state is seeded once by the workflow.
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <scenario> <wordpress-path>" >&2
  exit 64
fi

scenario="$1"
wp_root="$2"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
: "${PRE_RENAME_ZIP:?PRE_RENAME_ZIP is required}"
: "${CURRENT_ZIP:?CURRENT_ZIP is required}"
: "${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
test_file="$GITHUB_WORKSPACE/tests/integration/UpgradeCompatibilityTest.php"
diagnostics_helper="$GITHUB_WORKSPACE/tests/integration/lib/http-server-diagnostics.sh"
port="${SUPCHECKOUT_IN_PLACE_PORT:-8080}"
base_url="http://127.0.0.1:${port}"
server_log="${RUNNER_TEMP:-/tmp}/supcheckout-in-place-http.log"
server_pid=''

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }

cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -f "$wp_root/wp-content/mu-plugins/supcheckout-cert-update-offer.php"
}
trap cleanup EXIT

wp() {
  "$wp_cli" "$@" --path="$wp_root"
}

phase() {
  SUPCHECKOUT_UPGRADE_PHASE="$1" SUPCHECKOUT_CURRENT_ZIP="$CURRENT_ZIP" wp eval-file "$test_file"
}

plain_request() {
  wp eval '1;'
}

reset_to_pre_rename() {
  # Drop every SUPCheckout entry without running deactivation hooks, then reinstall and
  # activate the pre-rename build; its only header-bearing file is UPayments.php.
  wp eval '$a = array(); foreach ((array) get_option("active_plugins", array()) as $p) { if (strpos((string) $p, "supcheckout/") !== 0) { $a[] = $p; } } update_option("active_plugins", $a);'
  wp plugin install "$PRE_RENAME_ZIP" --force
  wp plugin activate supcheckout
  phase assert-pre-rename-active
}

overwrite_with_current() {
  wp plugin install "$CURRENT_ZIP" --force
}

case "$scenario" in
  reset)
    reset_to_pre_rename
    ;;

  cli-overwrite)
    overwrite_with_current
    plain_request
    phase verify-in-place
    ;;

  web-first-request)
    [[ -f "$diagnostics_helper" ]] || { echo "HTTP diagnostics helper missing: $diagnostics_helper" >&2; exit 71; }
    # shellcheck source=/dev/null
    source "$diagnostics_helper"
    [[ "$(wp option get siteurl)" == "$base_url" ]] || { echo "siteurl must be $base_url for the web scenario" >&2; exit 72; }
    # Everything that loads WordPress happens BEFORE the overwrite, so the HTTP GET is the
    # first request that loads the new package.
    wp transient delete _wc_activation_redirect >/dev/null 2>&1 || true
    cookie="$(wp eval '$u = get_user_by("login", "cert-admin"); $exp = time() + HOUR_IN_SECONDS; $t = WP_Session_Tokens::get_instance($u->ID)->create($exp); echo AUTH_COOKIE, "=", wp_generate_auth_cookie($u->ID, $exp, "auth", $t), "; ", LOGGED_IN_COOKIE, "=", wp_generate_auth_cookie($u->ID, $exp, "logged_in", $t);')"
    [[ "$cookie" == wordpress_* ]] || { echo "Could not generate an admin auth cookie" >&2; exit 73; }
    overwrite_with_current

    php -S "127.0.0.1:${port}" -t "$wp_root" >"$server_log" 2>&1 &
    server_pid=$!
    ready=0
    last_rc=0
    for attempt in $(seq 1 30); do
      # A static file: readiness must not load WordPress before the plugins.php request.
      if curl -fsS --max-time 5 "$base_url/license.txt" >/dev/null; then
        ready=1
        break
      else
        last_rc=$?
      fi
      sleep 1
    done
    if [[ "$ready" != "1" ]]; then
      supcheckout_dump_http_server_diagnostics 'PHP built-in server readiness' "$last_rc" "$server_pid" "$server_log"
      exit 70
    fi

    body="${RUNNER_TEMP:-/tmp}/supcheckout-in-place-plugins.html"
    status="$(curl -sS --max-time 60 -o "$body" -w '%{http_code}' -b "$cookie" "$base_url/wp-admin/plugins.php")"
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=''
    if [[ "$status" != "200" ]]; then
      echo "wp-admin/plugins.php returned HTTP $status" >&2
      head -c 2000 "$body" >&2 || true
      tail -n 50 "$server_log" >&2 || true
      exit 1
    fi
    for forbidden in 'has been deactivated due to an error' 'Plugin file does not exist' 'does not have a valid header'; do
      if grep -Fq "$forbidden" "$body"; then
        echo "plugins.php shows: $forbidden" >&2
        exit 1
      fi
    done
    grep -Fq 'SUPCheckout for UPayments' "$body" || { echo 'plugins.php did not list SUPCheckout (not the authenticated Plugins screen?)' >&2; exit 1; }
    echo 'PASS: the first request after the overwrite was an authenticated wp-admin/plugins.php GET with HTTP 200 and no deactivation notice'
    phase verify-in-place
    ;;

  upload-installer)
    phase run-upload-installer
    plain_request
    phase verify-in-place
    ;;

  bulk-update)
    # Offer the new package as an update for the OLD basename, as an update server would.
    mkdir -p "$wp_root/wp-content/mu-plugins"
    cp "$GITHUB_WORKSPACE/tests/integration/fixtures/update-offer-mu-plugin.php" \
      "$wp_root/wp-content/mu-plugins/supcheckout-cert-update-offer.php"
    SUPCHECKOUT_CERT_UPDATE_PACKAGE="$CURRENT_ZIP" wp plugin update supcheckout
    rm -f "$wp_root/wp-content/mu-plugins/supcheckout-cert-update-offer.php"
    plain_request
    phase verify-in-place
    ;;

  single-upgrade)
    phase characterize-single-upgrade
    plain_request
    phase verify-single-upgrade-deactivated
    # Recovery: one reactivation of the renamed main file restores the store.
    wp plugin activate supcheckout
    phase verify-in-place
    ;;

  multisite)
    # Multisite cannot use a host with a port; the remaining steps are CLI-only.
    wp eval '$a = array(); foreach ((array) get_option("active_plugins", array()) as $p) { if (strpos((string) $p, "supcheckout/") !== 0) { $a[] = $p; } } update_option("active_plugins", $a);'
    wp option update home 'http://supcheckout.test'
    wp option update siteurl 'http://supcheckout.test'
    wp core multisite-convert --title='SUPCheckout Network Certification'
    wp plugin install "$PRE_RENAME_ZIP" --force
    wp plugin activate supcheckout --network
    phase record-network-activation
    overwrite_with_current
    plain_request
    phase verify-network-in-place
    ;;

  *)
    echo "Unknown scenario: $scenario" >&2
    exit 64
    ;;
esac
