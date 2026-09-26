#!/usr/bin/env python3
"""Deterministic SBOM regression: two clean generations must be byte-identical."""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
GEN = ROOT / "scripts" / "generate-sbom-evidence.py"
ZIP = Path(os.environ.get("R6_ARTIFACT_ZIP", "/tmp/final2b/supcheckout-0.1.0.zip"))

FAILS = []


def check(cond: bool, label: str) -> None:
    print(("PASS: " if cond else "FAIL: ") + label)
    if not cond:
        FAILS.append(label)


def gen(out: Path) -> dict:
    env = dict(os.environ)
    env["SOURCE_DATE_EPOCH"] = "1700000000"
    subprocess.run([sys.executable, str(GEN), str(out), str(ZIP)], check=True, env=env, capture_output=True)
    files = {}
    for name in ("runtime-sbom.cdx.json", "dev-sbom.cdx.json", "license-inventory.json"):
        files[name] = (out / name).read_bytes()
    return files


def main() -> int:
    if not ZIP.exists():
        print(f"SKIP/FAIL artifact zip missing: {ZIP}")
        return 1
    with tempfile.TemporaryDirectory() as td:
        a = Path(td) / "a"
        b = Path(td) / "b"
        a.mkdir()
        b.mkdir()
        fa = gen(a)
        fb = gen(b)
        for name in fa:
            check(fa[name] == fb[name], f"two generations byte-identical: {name}")
        # Missing SOURCE_DATE_EPOCH must fail closed
        env = dict(os.environ)
        env.pop("SOURCE_DATE_EPOCH", None)
        proc = subprocess.run(
            [sys.executable, str(GEN), str(Path(td) / "c"), str(ZIP)],
            env=env,
            capture_output=True,
        )
        check(proc.returncode != 0, "missing SOURCE_DATE_EPOCH fails closed")
        # Unknown license must fail
        # (generator currently has 0 unknown; structural rule tested via validator)
    print()
    if FAILS:
        print(f"RESULT: FAIL ({len(FAILS)})")
        return 1
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
