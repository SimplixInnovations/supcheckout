#!/usr/bin/env bash
# Multi-process CycleClaim concurrency certification against real MySQL/WordPress.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <wordpress-path>" >&2
  exit 64
fi

wp_root="$1"
wp_cli="${WP_CLI_BIN:-/tmp/wp-cli.phar}"
workspace="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
worker="$workspace/tests/integration/fixtures/cycleclaim-worker.php"
workdir="${RUNNER_TEMP:-/tmp}/supcheckout-cycleclaim-concurrency"
sentinel_file="$workdir/dispatch-sentinels.jsonl"
scenario_log="$workdir/scenario-log.jsonl"

mkdir -p "$workdir"
: >"$sentinel_file"
: >"$scenario_log"

[[ -x "$wp_cli" ]] || { echo "WP-CLI not executable: $wp_cli" >&2; exit 65; }
[[ -f "$worker" ]] || { echo "worker missing: $worker" >&2; exit 71; }
[[ -f "$wp_root/wp-load.php" ]] || { echo "WordPress runtime missing: $wp_root" >&2; exit 68; }

export SUPCHECKOUT_WP_LOAD="$wp_root/wp-load.php"
export SUPCHECKOUT_DISPATCH_SENTINEL="$sentinel_file"

php_bin="$(command -v php)"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

pass() {
  echo "PASS: $*"
}

# Prepare schema.
"$wp_cli" eval-file "$workspace/tests/integration/fixtures/cycleclaim-schema-reset.php" --path="$wp_root" >/dev/null

# ---------------------------------------------------------------------------
# Scenario A — acquisition race: 3 workers, same cycle/parent/snapshot
# ---------------------------------------------------------------------------
cycle_a="$(printf 'a%.0s' {1..64})"
rm -f "$sentinel_file"
: >"$sentinel_file"

pids=()
for owner in owner-a1 owner-a2 owner-a3; do
  "$php_bin" "$worker" acquire "$owner" "$cycle_a" 11 10.000 KWD >"$workdir/a-$owner.json" &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait "$pid" || true
done

a_ok=0
a_owners=()
for owner in owner-a1 owner-a2 owner-a3; do
  if grep -q '"acquired":true' "$workdir/a-$owner.json"; then
    a_ok=$((a_ok + 1))
    a_owners+=("$owner")
  fi
done
[[ "$a_ok" -eq 1 ]] || fail "Scenario A expected exactly 1 acquire winner, got $a_ok"
pass "Scenario A acquisition race: exactly one winner (${a_owners[0]})"

row_count="$("$wp_cli" db query "SELECT COUNT(*) FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_a'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
[[ "$row_count" == "1" ]] || fail "Scenario A expected exactly 1 row, got $row_count"
pass "Scenario A: exactly one journal row exists"

amount="$("$wp_cli" db query "SELECT expected_amount FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_a'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
currency="$("$wp_cli" db query "SELECT expected_currency FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_a'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
[[ "$amount" == "10.000" && "$currency" == "KWD" ]] || fail "Scenario A snapshot mismatch: $amount $currency"
pass "Scenario A: snapshot exact 10.000 KWD"

# ---------------------------------------------------------------------------
# Scenario B — stale reclaim race: owners B and C concurrently
# ---------------------------------------------------------------------------
cycle_b="$(printf 'b%.0s' {1..64})"
"$php_bin" "$worker" acquire owner-b0 "$cycle_b" 12 10.000 KWD >"$workdir/b-seed.json" || true
"$wp_cli" db query "UPDATE wp_upayments_billing_attempts SET updated_gmt=UTC_TIMESTAMP() - INTERVAL 30 MINUTE WHERE cycle_key='$cycle_b'" --path="$wp_root" >/dev/null

pids=()
for owner in owner-b1 owner-b2; do
  "$php_bin" "$worker" reclaim "$owner" "$cycle_b" 12 10.000 KWD >"$workdir/b-$owner.json" &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait "$pid" || true
done

b_ok=0
for owner in owner-b1 owner-b2; do
  if grep -q '"reclaimed":true' "$workdir/b-$owner.json"; then
    b_ok=$((b_ok + 1))
  fi
done
[[ "$b_ok" -eq 1 ]] || fail "Scenario B expected exactly 1 reclaim winner, got $b_ok"
pass "Scenario B reclaim race: exactly one winner"

b_amount="$("$wp_cli" db query "SELECT expected_amount FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_b'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
b_currency="$("$wp_cli" db query "SELECT expected_currency FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_b'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
b_due="$("$wp_cli" db query "SELECT cycle_due_gmt FROM wp_upayments_billing_attempts WHERE cycle_key='$cycle_b'" --skip-column-names --path="$wp_root" | tr -d '[:space:]')"
[[ "$b_amount" == "10.000" && "$b_currency" == "KWD" ]] || fail "Scenario B snapshot rewritten: $b_amount $currency"
pass "Scenario B: snapshot unchanged after reclaim"

# ---------------------------------------------------------------------------
# Scenario C — old-owner dispatch after reclaim
# ---------------------------------------------------------------------------
"$php_bin" "$worker" dispatch owner-b0 "$cycle_b" 12 >"$workdir/c-old.json" || true
"$php_bin" "$worker" dispatch owner-b1 "$cycle_b" 12 >"$workdir/c-new.json" || true
"$php_bin" "$worker" dispatch owner-b2 "$cycle_b" 12 >"$workdir/c-new2.json" || true

if grep -q '"dispatching":true' "$workdir/c-old.json"; then
  fail "Scenario C old owner must not mark_dispatching"
fi
c_wins=0
for f in "$workdir/c-new.json" "$workdir/c-new2.json"; do
  if grep -q '"dispatching":true' "$f"; then
    c_wins=$((c_wins + 1))
  fi
done
[[ "$c_wins" -eq 1 ]] || fail "Scenario C expected exactly 1 new-owner dispatch win, got $c_wins"
pass "Scenario C: old-owner dispatch rejected; exactly one new owner dispatches"

# ---------------------------------------------------------------------------
# Scenario D — dispatching/held/resolved terminal safety
# ---------------------------------------------------------------------------
# cycle_b is now dispatching
if "$php_bin" "$worker" acquire owner-d1 "$cycle_b" 12 10.000 KWD >"$workdir/d-acquire.json"; then
  fail "Scenario D acquire must fail on dispatching"
fi
if "$php_bin" "$worker" reclaim owner-d1 "$cycle_b" 12 >"$workdir/d-reclaim.json"; then
  fail "Scenario D reclaim must fail on dispatching"
fi
if "$php_bin" "$worker" release owner-b1 "$cycle_b" >"$workdir/d-release.json" || "$php_bin" "$worker" release owner-b2 "$cycle_b" >"$workdir/d-release.json"; then
  fail "Scenario D release must fail on dispatching"
fi
pass "Scenario D: dispatching rejects acquire/reclaim/release"

cycle_h="$(printf 'h%.0s' {1..64})"
"$php_bin" "$worker" acquire owner-h "$cycle_h" 13 10.000 KWD >"$workdir/d-h.json"
"$php_bin" "$worker" hold owner-h "$cycle_h" >"$workdir/d-hold.json"
if "$php_bin" "$worker" acquire owner-h2 "$cycle_h" 13 10.000 KWD >"$workdir/d-h2.json"; then
  fail "Scenario D acquire must fail on held"
fi
if "$php_bin" "$worker" reclaim owner-h2 "$cycle_h" 13 >"$workdir/d-hreclaim.json"; then
  fail "Scenario D reclaim must fail on held"
fi
if "$php_bin" "$worker" release owner-h "$cycle_h" >"$workdir/d-hrelease.json"; then
  fail "Scenario D release must fail on held"
fi
pass "Scenario D: held rejects acquire/reclaim/release"

# ---------------------------------------------------------------------------
# Scenario E — dispatch sentinel: concurrent workers, provider-dispatch <= 1
# ---------------------------------------------------------------------------
cycle_e="$(printf 'e%.0s' {1..64})"
rm -f "$sentinel_file"
: >"$sentinel_file"

pids=()
for owner in owner-e1 owner-e2 owner-e3; do
  "$php_bin" "$worker" sentinel "$owner" "$cycle_e" 14 10.000 KWD >"$workdir/e-$owner.json" &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait "$pid" || true
done

sentinel_count=0
if [[ -s "$sentinel_file" ]]; then
  sentinel_count="$(wc -l <"$sentinel_file" | tr -d '[:space:]')"
fi
[[ "$sentinel_count" -le 1 ]] || fail "Scenario E provider-dispatch count must be <= 1, got $sentinel_count"
pass "Scenario E: provider-dispatch sentinel count = $sentinel_count (must be <= 1)"

if [[ "$sentinel_count" == "1" ]]; then
  sent_amount="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["amount"] ?? "";' <"$sentinel_file")"
  [[ "$sent_amount" == "10.000" ]] || fail "Scenario E sentinel amount must be snapshot 10.000, got $sent_amount"
  pass "Scenario E: sentinel amount is immutable snapshot 10.000"
fi

echo "CycleClaim multi-process concurrency certification: PASS"
