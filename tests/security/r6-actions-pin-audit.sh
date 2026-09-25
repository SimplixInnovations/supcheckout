#!/usr/bin/env bash
# Verify third-party GitHub Actions are pinned by immutable commit SHA.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

fail=0
while IFS= read -r line; do
  file="${line%%:*}"
  rest="${line#*:}"
  lineno="${rest%%:*}"
  content="${rest#*:}"
  # Extract uses: owner/repo@ref
  ref="$(echo "$content" | sed -n 's/.*uses:[[:space:]]*\([^[:space:]]*\).*/\1/p')"
  [[ -n "$ref" ]] || continue
  # local actions:// or ./
  case "$ref" in
    ./*|docker://*) continue ;;
  esac
  pin="${ref#*@}"
  if [[ "$pin" =~ ^[0-9a-f]{40}$ ]]; then
    continue
  fi
  # Allow version tags only if a 40-hex SHA comment follows on same line.
  if echo "$content" | grep -qE '#[[:space:]]*[0-9a-f]{40}'; then
    continue
  fi
  echo "UNPINNED_ACTION ${file}:${lineno} ${ref}"
  fail=1
done < <(git grep -n 'uses:' -- '.github/workflows')

if [[ "$fail" -ne 0 ]]; then
  echo 'R6 actions pin audit: FAIL'
  exit 1
fi
echo 'R6 actions pin audit: PASS'
