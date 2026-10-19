#!/usr/bin/env python3
"""npm package URLs follow the purl spec: a scope's leading @ is percent-encoded."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path

# Importing the generator must not leave scripts/__pycache__ in the checkout.
sys.dont_write_bytecode = True

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


ALIAS_LOCK = {
    "": {"name": "supcheckout"},
    # npm alias: react-19@npm:react@19.3.0 is installed under its alias path.
    "node_modules/react-19": {"name": "react", "version": "19.3.0", "license": "MIT", "dev": True},
    "node_modules/@base-ui/react": {"version": "1.8.0", "license": "MIT"},
    "node_modules/jest/node_modules/braces": {"version": "3.0.3", "license": "MIT", "dev": True},
}


def check_npm_aliases(gen) -> None:
    comps, licenses = gen.npm_lock_components(ALIAS_LOCK, "package-lock.json")
    by_path_name = {c.get("version") + ":" + c["name"]: c for c in comps}
    alias = by_path_name.get("19.3.0:react")
    check(alias is not None, "an npm alias is recorded under its real package name")
    check(
        alias is not None and alias["purl"] == "pkg:npm/react@19.3.0",
        "an npm alias gets the real package purl pkg:npm/react@19.3.0",
    )
    check(
        alias is not None
        and alias.get("properties")
        == [
            {"name": "cdx:npm:package:path", "value": "node_modules/react-19"},
            {"name": "supcheckout:npm:alias", "value": "react-19"},
        ],
        "an alias keeps its lockfile path (cdx:npm:package:path) and alias name (supcheckout:npm:alias)",
    )
    check(
        all("react-19" not in c["purl"] for c in comps),
        "no purl carries the alias name",
    )
    plain = by_path_name.get("1.8.0:@base-ui/react")
    check(
        plain is not None
        and plain["purl"] == "pkg:npm/%40base-ui/react@1.8.0"
        and plain.get("properties") == [{"name": "cdx:npm:package:path", "value": "node_modules/@base-ui/react"}],
        "a non-aliased scoped package keeps its name and records only its lockfile path",
    )
    nested = by_path_name.get("3.0.3:braces")
    check(nested is not None and nested["purl"] == "pkg:npm/braces@3.0.3", "a nested package uses its leaf name")
    check(
        nested is not None
        and nested.get("properties")
        == [{"name": "cdx:npm:package:path", "value": "node_modules/jest/node_modules/braces"}],
        "a nested copy is told apart by its lockfile path",
    )
    nested_lock, _ = gen.npm_lock_components(ALIAS_LOCK, "tests/e2e/package-lock.json")
    e2e = {c.get("version") + ":" + c["name"]: c for c in nested_lock}.get("3.0.3:braces")
    check(
        e2e is not None
        and e2e.get("properties")
        == [{"name": "cdx:npm:package:path", "value": "tests/e2e/node_modules/jest/node_modules/braces"}],
        "a nested lockfile's paths are repository-relative (prefixed with its directory)",
    )
    inventory = {(entry["package"], entry["version"]): entry for entry in licenses}
    check(
        inventory.get(("react", "19.3.0"), {}).get("alias") == "react-19",
        "the license inventory names the real package and records the alias",
    )
    check(
        "alias" not in inventory.get(("braces", "3.0.3"), {"alias": None}),
        "a non-aliased inventory entry has no alias field",
    )


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
    check_npm_aliases(gen)
    if FAILS:
        print(f"SBOM_PURL FAIL ({len(FAILS)})")
        return 1
    print("SBOM_PURL PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
