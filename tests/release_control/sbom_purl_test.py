#!/usr/bin/env python3
"""npm package URLs follow the purl spec: a scope's leading @ is percent-encoded."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
GEN = ROOT / "scripts" / "generate-sbom-evidence.py"
VALIDATE = ROOT / "scripts" / "validate-sbom-evidence.py"

FAILS = []


def check(cond: bool, label: str) -> None:
    print(("PASS: " if cond else "FAIL: ") + label)
    if not cond:
        FAILS.append(label)


def load_generator():
    spec = importlib.util.spec_from_file_location("generate_sbom_evidence", GEN)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def bom(purl: str) -> dict:
    return {"bomFormat": "CycloneDX", "components": [{"type": "library", "purl": purl}]}


def validator_accepts(purl: str) -> bool:
    with tempfile.TemporaryDirectory() as td:
        out = Path(td)
        for name in ("runtime-sbom.cdx.json", "dev-sbom.cdx.json"):
            (out / name).write_text(json.dumps(bom(purl)), encoding="utf-8")
        (out / "license-inventory.json").write_text("[]", encoding="utf-8")
        result = subprocess.run(
            [sys.executable, str(VALIDATE), str(out), "--purl-only"],
            capture_output=True,
            text=True,
        )
        return result.returncode == 0


def main() -> int:
    gen = load_generator()
    check(
        gen.npm_purl("@wordpress/element", "8.8.0") == "pkg:npm/%40wordpress/element@8.8.0",
        "scoped npm name percent-encodes the scope marker",
    )
    check(
        gen.npm_purl("@base-ui/react", "1.8.0") == "pkg:npm/%40base-ui/react@1.8.0",
        "bundled scoped package percent-encodes the scope marker",
    )
    check(gen.npm_purl("braces", "3.0.3") == "pkg:npm/braces@3.0.3", "unscoped npm name is unchanged")
    check(validator_accepts("pkg:npm/%40wordpress/element@8.8.0"), "validator accepts an encoded scope")
    check(not validator_accepts("pkg:npm/@wordpress/element@8.8.0"), "validator rejects a raw @ scope")
    check(not validator_accepts("pkg:npm/@8.8.0"), "validator rejects an npm purl without a name")
    if FAILS:
        print(f"SBOM_PURL FAIL ({len(FAILS)})")
        return 1
    print("SBOM_PURL PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
