#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <wordpress-version> <woocommerce-version> <wordpress-path>" >&2
  exit 64
fi

WP_VERSION="$1"
WC_VERSION="$2"
WP_PATH="$3"
WP_CLI_BIN="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
PLUGIN_SLUG="${SUPCHECKOUT_PLUGIN_SLUG:-supcheckout}"
SITE_URL="${SUPCHECKOUT_SITE_URL:-http://127.0.0.1:8080}"

if [[ ! -x "$WP_CLI_BIN" ]]; then
  echo "WP-CLI executable not found: $WP_CLI_BIN" >&2
  exit 65
fi

: "${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
: "${SUPCHECKOUT_DB_NAME:=wordpress}"
: "${SUPCHECKOUT_DB_USER:=root}"
: "${SUPCHECKOUT_DB_PASSWORD:=root}"
: "${SUPCHECKOUT_DB_HOST:=127.0.0.1:3306}"

rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"

"$WP_CLI_BIN" core download \
  --path="$WP_PATH" \
  --version="$WP_VERSION" \
  --force \
  --quiet

"$WP_CLI_BIN" config create \
  --path="$WP_PATH" \
  --dbname="$SUPCHECKOUT_DB_NAME" \
  --dbuser="$SUPCHECKOUT_DB_USER" \
  --dbpass="$SUPCHECKOUT_DB_PASSWORD" \
  --dbhost="$SUPCHECKOUT_DB_HOST" \
  --skip-check \
  --quiet

"$WP_CLI_BIN" core install \
  --path="$WP_PATH" \
  --url="$SITE_URL" \
  --title="SUPCheckout Certification" \
  --admin_user="cert-admin" \
  --admin_password="cert-password-not-production" \
  --admin_email="certification@example.test" \
  --skip-email \
  --quiet

"$WP_CLI_BIN" plugin install woocommerce \
  --path="$WP_PATH" \
  --version="$WC_VERSION" \
  --activate \
  --quiet

PLUGIN_PATH="$WP_PATH/wp-content/plugins/$PLUGIN_SLUG"
rm -rf "$PLUGIN_PATH"

if [[ -n "${SUPCHECKOUT_PLUGIN_ZIP:-}" ]]; then
  [[ -f "$SUPCHECKOUT_PLUGIN_ZIP" ]] || {
    echo "SUPCheckout release ZIP not found: $SUPCHECKOUT_PLUGIN_ZIP" >&2
    exit 68
  }

  "$WP_CLI_BIN" plugin install "$SUPCHECKOUT_PLUGIN_ZIP" \
    --path="$WP_PATH" \
    --force \
    --quiet

  [[ -d "$PLUGIN_PATH" && ! -L "$PLUGIN_PATH" ]] || {
    echo "Packaged plugin was not installed as a real directory: $PLUGIN_PATH" >&2
    exit 69
  }
  [[ -f "$PLUGIN_PATH/UPayments.php" ]] || {
    echo "Packaged plugin is missing transitional main file UPayments.php" >&2
    exit 70
  }
else
  ln -s "$GITHUB_WORKSPACE" "$PLUGIN_PATH"
fi

ACTUAL_WP="$("$WP_CLI_BIN" core version --path="$WP_PATH")"
ACTUAL_WC="$("$WP_CLI_BIN" plugin get woocommerce --field=version --path="$WP_PATH")"

[[ "$ACTUAL_WP" == "$WP_VERSION" ]] || {
  echo "WordPress version mismatch: expected=$WP_VERSION actual=$ACTUAL_WP" >&2
  exit 66
}

[[ "$ACTUAL_WC" == "$WC_VERSION" ]] || {
  echo "WooCommerce version mismatch: expected=$WC_VERSION actual=$ACTUAL_WC" >&2
  exit 67
}

echo "CERT ENV: WordPress=$ACTUAL_WP WooCommerce=$ACTUAL_WC PHP=$(php -r 'echo PHP_VERSION;') plugin=$PLUGIN_SLUG site=$SITE_URL"
