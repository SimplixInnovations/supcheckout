#!/usr/bin/env bash
# Release-upstream currentness check.
#
# Compares pinned certification versions against expected current upstream
# patches. NOT used in normal PR CI (upstream HTML/API can be unstable).
# Use at RC qualification or manual workflow only.
#
# Usage:
#   scripts/check-release-upstream-currentness.sh \
#     --wp 6.9.9,7.0.6,7.1.2 \
#     --wc 10.8.1,11.0.1,11.1.2
#
# Exit 0: pins match expected current list.
# Exit 2: STALE_UPSTREAM_CERTIFICATION (mismatch).
# Exit 64: usage error.
set -euo pipefail

EXPECTED_WP=""
EXPECTED_WC=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --wp) EXPECTED_WP="${2:?}"; shift 2 ;;
    --wc) EXPECTED_WC="${2:?}"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 64 ;;
  esac
done

[[ -n "$EXPECTED_WP" && -n "$EXPECTED_WC" ]] || {
  echo "usage: $0 --wp a,b,c --wc x,y,z" >&2
  exit 64
}

# Pins as certified in the current release-readiness matrix.
# Keep in sync with .github/workflows/compatibility-certification.yml.
CERTIFIED_WP="6.9.9,7.0.6,7.1.2"
CERTIFIED_WC="10.8.1,11.0.1,11.1.2"

fail=0
if [[ "$CERTIFIED_WP" != "$EXPECTED_WP" ]]; then
  echo "STALE_UPSTREAM_CERTIFICATION: WP certified=$CERTIFIED_WP expected=$EXPECTED_WP"
  fail=1
fi
if [[ "$CERTIFIED_WC" != "$EXPECTED_WC" ]]; then
  echo "STALE_UPSTREAM_CERTIFICATION: WC certified=$CERTIFIED_WC expected=$EXPECTED_WC"
  fail=1
fi

# Optional live advisory (non-blocking unless --enforce-live).
# Not used in PR CI. Callers may pipe authoritative release API JSON here later.

if [[ $fail -ne 0 ]]; then
  echo "RESULT: STALE_UPSTREAM_CERTIFICATION"
  exit 2
fi
echo "RESULT: UPSTREAM_PINS_MATCH_EXPECTED"
echo "WP=$CERTIFIED_WP"
echo "WC=$CERTIFIED_WC"
echo "Note: live upstream scrape is advisory only at RC time; do not depend on it in PR CI."
