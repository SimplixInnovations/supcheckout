#!/usr/bin/env python3
"""CycloneDX serialNumber regression: an RFC 4122 UUIDv5 URN, derived deterministically.

The runtime SBOM serial derives from the package ZIP's SHA-256 and the dev SBOM
serial from its canonical component list, so equal inputs give equal serials
(determinism) and different inputs give different ones. The validator rejects
any serial that is not `urn:uuid:` plus an RFC 4122 UUID.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
import uuid
import zipfile
from pathlib import Path

# Importing the generator must not leave scripts/__pycache__ in the checkout.
sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
GEN = ROOT / "scripts" / "generate-sbom-evidence.py"
VALIDATE = ROOT / "scripts" / "validate-sbom-evidence.py"

RFC4122_URN = re.compile(
    r"^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)
LEGACY_RUNTIME = "urn:uuid:supcheckout-runtime-0000-0000-0000-000000000001"
LEGACY_DEV = "urn:uuid:supcheckout-dev-0000-0000-0000-000000000002"

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


def is_uuid5_urn(value: object) -> bool:
    if not isinstance(value, str) or not RFC4122_URN.match(value):
        return False
    parsed = uuid.UUID(value[len("urn:uuid:"):])
    return parsed.version == 5 and parsed.variant == uuid.RFC_4122


def write_zip(path: Path, files: dict[str, bytes]) -> None:
    with zipfile.ZipFile(path, "w") as z:
        for name, data in files.items():
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            z.writestr(info, data)


def check_generator(gen) -> None:
    digest = "a" * 64
    first = gen.serial_number("runtime", digest)
    check(is_uuid5_urn(first), "serial_number returns an RFC 4122 UUIDv5 URN")
    check(gen.serial_number("runtime", digest) == first, "serial_number is deterministic")
    check(gen.serial_number("dev", digest) != first, "runtime and dev serials differ for one digest")
    check(gen.serial_number("runtime", "b" * 64) != first, "a different digest gives a different serial")

    os.environ["SOURCE_DATE_EPOCH"] = "1700000000"
    with tempfile.TemporaryDirectory() as td:
        a = Path(td) / "a.zip"
        b = Path(td) / "b.zip"
        write_zip(a, {"supcheckout/supcheckout.php": b"<?php\n"})
        write_zip(b, {"supcheckout/supcheckout.php": b"<?php // changed\n"})
        rt_a = gen.runtime_sbom(a)
        rt_b = gen.runtime_sbom(b)
        zip_sha = hashlib.sha256(a.read_bytes()).hexdigest()
        check(is_uuid5_urn(rt_a["serialNumber"]), "runtime SBOM serialNumber is an RFC 4122 UUIDv5 URN")
        check(
            rt_a["serialNumber"] == gen.serial_number("runtime", zip_sha),
            "runtime SBOM serial derives from the package ZIP SHA-256",
        )
        check(gen.runtime_sbom(a)["serialNumber"] == rt_a["serialNumber"], "runtime serial is stable across runs")
        check(rt_a["serialNumber"] != rt_b["serialNumber"], "a different package gives a different runtime serial")

        dev, _ = gen.dev_sbom()
        check(is_uuid5_urn(dev["serialNumber"]), "dev SBOM serialNumber is an RFC 4122 UUIDv5 URN")
        check(gen.dev_sbom()[0]["serialNumber"] == dev["serialNumber"], "dev serial is stable across runs")
        check(dev["serialNumber"] != rt_a["serialNumber"], "dev and runtime serials differ")


def evidence(directory: Path, runtime_serial: str, dev_serial: str) -> None:
    def bom(serial: str, count: int) -> dict:
        return {
            "bomFormat": "CycloneDX",
            "serialNumber": serial,
            "components": [{"type": "file", "name": f"f{i}"} for i in range(count)],
        }

    (directory / "runtime-sbom.cdx.json").write_text(json.dumps(bom(runtime_serial, 50)), encoding="utf-8")
    (directory / "dev-sbom.cdx.json").write_text(json.dumps(bom(dev_serial, 1)), encoding="utf-8")
    (directory / "license-inventory.json").write_text(
        json.dumps({"dependencies": [{"package": "x", "license": "MIT"}], "unknown_count": 0}),
        encoding="utf-8",
    )


def validator_accepts(runtime_serial: str, dev_serial: str) -> bool:
    with tempfile.TemporaryDirectory() as td:
        evidence(Path(td), runtime_serial, dev_serial)
        result = subprocess.run([sys.executable, str(VALIDATE), td], capture_output=True, text=True)
        return result.returncode == 0


def check_validator() -> None:
    runtime = uuid.uuid5(uuid.NAMESPACE_URL, "runtime").urn
    dev = uuid.uuid5(uuid.NAMESPACE_URL, "dev").urn
    check(validator_accepts(runtime, dev), "validator accepts UUIDv5 serials")
    check(not validator_accepts(LEGACY_RUNTIME, dev), "validator rejects the legacy runtime serial")
    check(not validator_accepts(runtime, LEGACY_DEV), "validator rejects the legacy dev serial")
    check(not validator_accepts(runtime.upper(), dev), "validator rejects a non-lowercase serial")
    check(not validator_accepts(runtime[len("urn:uuid:"):], dev), "validator rejects a serial without urn:uuid:")
    check(
        not validator_accepts("urn:uuid:00000000-0000-0000-0000-000000000000", dev),
        "validator rejects the nil UUID (no RFC 4122 version or variant)",
    )
    check(not validator_accepts(runtime, runtime), "validator rejects one serial reused for both SBOMs")


def main() -> int:
    gen = load_generator()
    try:
        check_generator(gen)
    except AttributeError as error:
        check(False, f"generator exposes serial_number: {error}")
    check_validator()
    if FAILS:
        print(f"SBOM_SERIAL FAIL ({len(FAILS)})")
        return 1
    print("SBOM_SERIAL PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
