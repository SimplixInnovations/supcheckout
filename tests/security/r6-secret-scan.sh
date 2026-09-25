#!/usr/bin/env bash
# R6 working-tree + history + package secret scan (no real credentials allowed).
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

fail=0
note() { echo "$*"; }
hit() { echo "SECRET_SCAN_HIT: $*"; fail=1; }

patterns=(
  'sk_live_[A-Za-z0-9]{10,}'
  'sk_test_[A-Za-z0-9]{10,}'
  'BEGIN RSA PRIVATE KEY'
  'BEGIN OPENSSH PRIVATE KEY'
  'Authorization: Bearer [A-Za-z0-9._-]{20,}'
  'api[_-]?key["'"'"']?\s*[:=]\s*["'"'"'][A-Za-z0-9]{16,}'
)

note '--- current tree (tracked) ---'
for p in "${patterns[@]}"; do
  if git grep -n -E "$p" -- . ':(exclude).cache' ':(exclude)vendor' ':(exclude)node_modules' ':(exclude)tests/security/r6-secret-scan.sh' 2>/dev/null; then
    hit "tree matched: $p"
  fi
done

note '--- git history ---'
for p in "${patterns[@]}"; do
  # Ignore the scanner's own pattern list and pure documentation mentions of PEM headers.
  if git log -p --all -G "$p" -- . ':(exclude)vendor' ':(exclude)tests/security/r6-secret-scan.sh' 2>/dev/null \
    | grep -E "$p" \
    | grep -vE 'r6-secret-scan|BEGIN (RSA|OPENSSH) PRIVATE KEY[[:space:]]*$' \
    | head -3 | grep -q .; then
    hit "history matched: $p"
  fi
done

note '--- package zip ---'
ZIP="$(ls -1t /tmp/r6-final-artifact/supcheckout-0.1.0.zip /tmp/r6-final-candidate/supcheckout-0.1.0.zip 2>/dev/null | head -1 || true)"
if [[ -n "${ZIP:-}" && -f "$ZIP" ]]; then
  for p in "${patterns[@]}"; do
    if unzip -p "$ZIP" | grep -a -E "$p" >/dev/null 2>&1; then
      hit "zip matched: $p in $ZIP"
    fi
  done
else
  note '(zip not present locally; CI package scan covers hosted artifact)'
fi

# Allowlisted synthetic fixtures
note 'allowlisted synthetic: jtest123 (public sandbox smoke), certification-key (HTTP fixtures), example.invalid emails'

if [[ "$fail" -ne 0 ]]; then
  echo 'R6 secret scan: FAIL'
  exit 1
fi
echo 'R6 secret scan: PASS'
