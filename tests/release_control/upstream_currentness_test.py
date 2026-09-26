#!/usr/bin/env python3
"""Deterministic tests for release upstream currentness logic."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "scripts" / "check-release-upstream-currentness.py"

FAILS = []


def check(cond: bool, label: str) -> None:
    print(("PASS: " if cond else "FAIL: ") + label)
    if not cond:
        FAILS.append(label)


def run(wp_payload, wc_payload, wp_cert="6.9.9,7.0.6,7.1.2", wc_cert="10.8.1,11.0.1,11.1.2"):
    with tempfile.TemporaryDirectory() as td:
        wpp = Path(td) / "wp.json"
        wcp = Path(td) / "wc.json"
        wpp.write_text(json.dumps(wp_payload))
        wcp.write_text(json.dumps(wc_payload))
        proc = subprocess.run(
            [
                sys.executable,
                str(TOOL),
                "--wp-certified",
                wp_cert,
                "--wc-certified",
                wc_cert,
                "--offline-wp-file",
                str(wpp),
                "--offline-wc-file",
                str(wcp),
            ],
            capture_output=True,
            text=True,
        )
        return proc.returncode, proc.stdout + proc.stderr


WP_BASE = {"offers": [{"version": "6.9.9"}, {"version": "7.0.6"}, {"version": "7.1.2"}]}
WC_BASE = [
    {"tag_name": "10.8.1"},
    {"tag_name": "11.0.1"},
    {"tag_name": "11.1.2"},
    {"tag_name": "11.2.0-beta.1"},
]


def main() -> int:
    rc, out = run(WP_BASE, WC_BASE)
    check(rc == 0 and "CURRENT" in out, "exact match → PASS")

    wp_new = {"offers": [{"version": "6.9.9"}, {"version": "7.0.6"}, {"version": "7.1.3"}]}
    rc, out = run(wp_new, WC_BASE)
    check(rc == 2 and "STALE_UPSTREAM_CERTIFICATION" in out and "7.1.3" in out, "new WP patch → STALE")

    wc_new = WC_BASE + [{"tag_name": "11.1.3"}]
    rc, out = run(WP_BASE, wc_new)
    check(rc == 2 and "STALE_UPSTREAM_CERTIFICATION" in out and "11.1.3" in out, "new WC patch → STALE")

    wc_prerelease_only = [{"tag_name": "11.2.0-beta.1"}, {"tag_name": "11.2.0-rc.1"}]
    # Certified claims 11.2.0 stable but upstream only has prereleases → must not PASS.
    rc, out = run(WP_BASE, wc_prerelease_only, wc_cert="10.8.1,11.0.1,11.2.0")
    check(rc == 3 and "UNKNOWN_UPSTREAM" in out, "prerelease ignored / missing stable → UNKNOWN never PASS")

    rc, out = run("not-json", WC_BASE)
    check(rc == 3 and "UNKNOWN_UPSTREAM" in out, "malformed upstream → ERROR never PASS")

    # Simulate network unavailable by pointing at offline missing file via bad payload type
    rc, out = run({}, {})
    check(rc == 3 and "UNKNOWN_UPSTREAM" in out, "empty upstream → UNKNOWN never CURRENT")

    print()
    if FAILS:
        print(f"RESULT: FAIL ({len(FAILS)})")
        return 1
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
