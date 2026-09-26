#!/usr/bin/env python3
"""Structural validation that SBOM evidence cannot be empty/malformed."""
from __future__ import annotations

import json
import sys
from pathlib import Path


def main(argv: list[str]) -> int:
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
        n = len(o.get("components") or [])
        if n < minc:
            print(f"FAIL {name} components={n} < {minc}")
            ok = False
        print(f"VALIDATED {name} components={n}")
    lic = d / "license-inventory.json"
    if not lic.exists():
        print("FAIL missing license-inventory.json")
        ok = False
    else:
        inv = json.loads(lic.read_text())
        if not inv.get("dependencies"):
            print("FAIL empty license inventory")
            ok = False
        print(f"VALIDATED license-inventory deps={len(inv['dependencies'])} unknown={inv.get('unknown_count')}")
    print("SBOM_VALIDATION: PASS" if ok else "SBOM_VALIDATION: FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
