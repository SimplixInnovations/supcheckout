#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
child_slug="supcheckout-cert-child"
marker="SUPCHECKOUT_CHILD_THEME_OVERRIDE_RENDERED"
child_dir="$wp_root/wp-content/themes/$child_slug"

test_file="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}/tests/integration/ChildThemeOverrideRuntimeTest.php"

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$test_file" ]] || { echo "Child-theme runtime test missing: $test_file" >&2; exit 66; }

parent_slug="$($wp_cli theme list --status=active --field=name --path="$wp_root")"
[[ -n "$parent_slug" ]] || { echo "Unable to determine active parent theme" >&2; exit 67; }

cleanup() {
  "$wp_cli" theme activate "$parent_slug" --path="$wp_root" >/dev/null 2>&1 || true
  rm -rf "$child_dir"
}
trap cleanup EXIT

rm -rf "$child_dir"
mkdir -p "$child_dir/woocommerce"

cat > "$child_dir/style.css" <<EOF
/*
Theme Name: SUPCheckout Certification Child
Template: $parent_slug
Version: 1.0.0
*/
EOF

cat > "$child_dir/woocommerce/new-design-form.php" <<'PHP'
<?php
defined('ABSPATH') || exit;
?>
<div data-supcheckout-cert-child-theme="1">SUPCHECKOUT_CHILD_THEME_OVERRIDE_RENDERED</div>
PHP

"$wp_cli" theme activate "$child_slug" --path="$wp_root" >/dev/null

active_stylesheet="$($wp_cli theme list --status=active --field=name --path="$wp_root")"
[[ "$active_stylesheet" == "$child_slug" ]] || {
  echo "Certification child theme did not become active: $active_stylesheet" >&2
  exit 68
}

SUPCHECKOUT_CHILD_THEME_SLUG="$child_slug" \
SUPCHECKOUT_PARENT_THEME_SLUG="$parent_slug" \
SUPCHECKOUT_CHILD_THEME_MARKER="$marker" \
  "$wp_cli" eval-file "$test_file" --path="$wp_root"

echo "Child-theme checkout override runtime certification passed for parent $parent_slug."
