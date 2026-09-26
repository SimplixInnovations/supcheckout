#!/usr/bin/env python3
"""Deterministic SBOM + license inventory generator (CycloneDX JSON).

Runtime SBOM: from unpacked supcheckout-0.1.0.zip file manifest.
Dev SBOM: from composer.lock + tests/e2e/package-lock.json.

Never adds runtime plugin dependencies. Evidence files stay outside the ZIP.
"""
from __future__ import annotations

import hashlib
import json
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path
import os

ROOT = Path(__file__).resolve().parents[1]


def sha256_file(p: Path) -> str:
    h = hashlib.sha256()
    h.update(p.read_bytes())
    return h.hexdigest()


def _stable_timestamp() -> str:
    epoch = os.environ.get("SOURCE_DATE_EPOCH")
    if epoch:
        return datetime.fromtimestamp(int(epoch), tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    raise SystemExit("SOURCE_DATE_EPOCH is required for deterministic SBOM generation")


def cyclone_dx(name: str, components: list[dict], serial: str) -> dict:
    return {
        "bomFormat": "CycloneDX",
        "specVersion": "1.5",
        "serialNumber": serial,
        "version": 1,
        "metadata": {
            # Deterministic: SOURCE_DATE_EPOCH from exact source commit timestamp.
            "timestamp": _stable_timestamp(),
            "component": {"type": "application", "name": name},
        },
        "components": components,
    }


def runtime_sbom(zip_path: Path) -> dict:
    comps = []
    with zipfile.ZipFile(zip_path) as z:
        for info in sorted(z.infolist(), key=lambda i: i.filename):
            if info.is_dir():
                continue
            data = z.read(info.filename)
            comps.append(
                {
                    "type": "file",
                    "name": info.filename,
                    "hashes": [{"alg": "SHA-256", "content": hashlib.sha256(data).hexdigest()}],
                }
            )
    return cyclone_dx(
        "supcheckout-runtime-artifact",
        comps,
        "urn:uuid:supcheckout-runtime-0000-0000-0000-000000000001",
    )


def dev_sbom() -> tuple[dict, list[dict]]:
    comps = []
    licenses = []
    lock = ROOT / "composer.lock"
    if lock.exists():
        data = json.loads(lock.read_text(encoding="utf-8"))
        for section, eco, cls in (
            ("packages", "composer", "runtime-dev-composer"),
            ("packages-dev", "composer", "development"),
        ):
            for pkg in data.get(section) or []:
                lic = pkg.get("license") or ["UNKNOWN"]
                if isinstance(lic, str):
                    lic = [lic]
                comps.append(
                    {
                        "type": "library",
                        "name": pkg.get("name"),
                        "version": pkg.get("version"),
                        "purl": f"pkg:composer/{pkg.get('name')}@{pkg.get('version')}",
                        "licenses": [{"license": {"id": x}} for x in lic],
                    }
                )
                licenses.append(
                    {
                        "package": pkg.get("name"),
                        "version": pkg.get("version"),
                        "ecosystem": eco,
                        "license": ",".join(lic),
                        "class": cls,
                        "source": "composer.lock",
                    }
                )
    pkg_lock = ROOT / "tests" / "e2e" / "package-lock.json"
    if pkg_lock.exists():
        data = json.loads(pkg_lock.read_text(encoding="utf-8"))
        packages = data.get("packages") or {}
        for path, meta in packages.items():
            if not path:
                continue
            name = path.split("node_modules/")[-1]
            comps.append(
                {
                    "type": "library",
                    "name": name,
                    "version": meta.get("version"),
                    "purl": f"pkg:npm/{name}@{meta.get('version')}",
                }
            )
            licenses.append(
                {
                    "package": name,
                    "version": meta.get("version"),
                    "ecosystem": "npm",
                    "license": meta.get("license") or "UNKNOWN",
                    "class": "development",
                    "source": "tests/e2e/package-lock.json",
                }
            )
    return (
        cyclone_dx(
            "supcheckout-development-dependencies",
            comps,
            "urn:uuid:supcheckout-dev-0000-0000-0000-000000000002",
        ),
        licenses,
    )


def validate_sbom(obj: dict, min_components: int) -> None:
    assert obj.get("bomFormat") == "CycloneDX", "bomFormat must be CycloneDX"
    assert obj.get("components"), "components required"
    assert len(obj["components"]) >= min_components, "components too few"
    for c in obj["components"]:
        assert c.get("name"), "component name required"


def main(argv: list[str]) -> int:
    out_dir = Path(argv[0] if argv else "evidence/sbom")
    out_dir.mkdir(parents=True, exist_ok=True)
    zip_path = Path(
        argv[1]
        if len(argv) > 1
        else "/tmp/final-ext/supcheckout-0.1.0.zip"
    )
    if not zip_path.exists():
        print(f"artifact zip missing: {zip_path}", file=sys.stderr)
        return 2

    rt = runtime_sbom(zip_path)
    validate_sbom(rt, min_components=50)
    rt_path = out_dir / "runtime-sbom.cdx.json"
    rt_path.write_text(json.dumps(rt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    dev, licenses = dev_sbom()
    validate_sbom(dev, min_components=1)
    dev_path = out_dir / "dev-sbom.cdx.json"
    dev_path.write_text(json.dumps(dev, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    unknown = [x for x in licenses if x["license"] in ("", "UNKNOWN", "UNKNOWN/UNRESOLVED")]
    lic_path = out_dir / "license-inventory.json"
    lic_path.write_text(
        json.dumps({"dependencies": licenses, "unknown_count": len(unknown)}, indent=2) + "\n",
        encoding="utf-8",
    )

    for p in (rt_path, dev_path, lic_path):
        print(f"{p.name}: sha256={sha256_file(p)} bytes={p.stat().st_size}")
    print(f"runtime_components={len(rt['components'])}")
    print(f"dev_components={len(dev['components'])}")
    print(f"license_unknown={len(unknown)}")
    if unknown:
        print("FAIL: unknown_count=%d (enterprise rule: fail closed)" % len(unknown), file=sys.stderr)
        for u in unknown:
            print("  unknown license:", u.get("package"), u.get("version"), file=sys.stderr)
        return 2
    print("SBOM_EVIDENCE: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
