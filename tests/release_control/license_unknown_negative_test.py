#!/usr/bin/env python3
"""Unknown-license negative regression: unknown_count != 0 must FAIL validation."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VALID = ROOT / "scripts" / "validate-sbom-evidence.py"

FAILS = []


def check(cond: bool, label: str) -> None:
    print(("PASS: " if cond else "FAIL: ") + label)
    if not cond:
        FAILS.append(label)


def write_fixture(d: Path, unknown: int) -> None:
    d.mkdir(parents=True, exist_ok=True)
    comps = [{"type": "file", "name": f"f{i}.php"} for i in range(50)]
    (d / "runtime-sbom.cdx.json").write_text(
        json.dumps({"bomFormat": "CycloneDX", "specVersion": "1.5", "serialNumber": "urn:uuid:x", "version": 1, "components": comps})
    )
    (d / "dev-sbom.cdx.json").write_text(
        json.dumps({"bomFormat": "CycloneDX", "specVersion": "1.5", "serialNumber": "urn:uuid:y", "version": 1, "components": [{"type": "library", "name": "x", "version": "1"}]})
    )
    deps = [{"package": "p", "version": "1", "license": "MIT", "class": "development", "source": "composer.lock", "ecosystem": "composer"}]
    if unknown:
        deps.append({"package": "bad", "version": "0", "license": "UNKNOWN", "class": "development", "source": "composer.lock", "ecosystem": "composer"})
        # pad to keep unknown_count as specified
        unknown_n = unknown
    else:
        unknown_n = 0
    (d / "license-inventory.json").write_text(
        json.dumps({"dependencies": deps, "unknown_count": unknown_n})
    )


def main() -> int:
    with tempfile.TemporaryDirectory() as td:
        good = Path(td) / "good"
        write_fixture(good, unknown=0)
        rc = subprocess.run([sys.executable, str(VALID), str(good)], capture_output=True).returncode
        check(rc == 0, "unknown_count=0 → PASS")

        bad = Path(td) / "bad"
        write_fixture(bad, unknown=1)
        rc = subprocess.run([sys.executable, str(VALID), str(bad)], capture_output=True).returncode
        check(rc != 0, "unknown_count>0 → FAIL")
    print()
    if FAILS:
        print(f"RESULT: FAIL ({len(FAILS)})")
        return 1
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
