#!/usr/bin/env python3
"""Structural validation that SBOM evidence cannot be empty/malformed."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path


# purl spec: an npm scope's leading @ is percent-encoded, and the name is non-empty.
NPM_PURL = re.compile(r"^pkg:npm/(%40[^/@]+/)?[^/@%][^/@]*@[^@]+$")


def purl_failures(name: str, components: list) -> list[str]:
    return [
        f"FAIL {name} npm purl not spec-encoded: {c.get('purl')}"
        for c in components
        if str(c.get("purl", "")).startswith("pkg:npm/") and not NPM_PURL.match(c["purl"])
    ]


def main(argv: list[str]) -> int:
    purl_only = "--purl-only" in argv
    argv = [a for a in argv if a != "--purl-only"]
    d = Path(argv[0] if argv else "evidence/sbom")
    ok = True
    for name, minc in (("runtime-sbom.cdx.json", 50), ("dev-sbom.cdx.json", 1)):
        p = d / name
        if not p.exists():
            print(f"FAIL missing {name}")
            ok = False
            continue
        o = json.loads(p.read_text())
        if o.get("bomFormat") != "CycloneDX":
            print(f"FAIL {name} bomFormat")
            ok = False
        failures = purl_failures(name, o.get("components") or [])
        for failure in failures:
            print(failure)
        ok = ok and not failures
        if purl_only:
            continue
        n = len(o.get("components") or [])
        if n < minc:
            print(f"FAIL {name} components={n} < {minc}")
            ok = False
        print(f"VALIDATED {name} components={n}")
    lic = d / "license-inventory.json"
    if purl_only:
        print("SBOM_PURL_VALIDATION: PASS" if ok else "SBOM_PURL_VALIDATION: FAIL")
        return 0 if ok else 1
    if not lic.exists():
        print("FAIL missing license-inventory.json")
        ok = False
    else:
        inv = json.loads(lic.read_text())
        if not inv.get("dependencies"):
            print("FAIL empty license inventory")
            ok = False
        unknown = int(inv.get("unknown_count") or 0)
        print(f"VALIDATED license-inventory deps={len(inv['dependencies'])} unknown={unknown}")
        if unknown != 0:
            print(f"FAIL unknown_count={unknown} must be 0")
            ok = False
    print("SBOM_VALIDATION: PASS" if ok else "SBOM_VALIDATION: FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
