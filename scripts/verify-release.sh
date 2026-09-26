#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <release-zip>" >&2
  exit 64
fi

ROOT="$(git rev-parse --show-toplevel)"
ZIP="$1"
[[ -f "$ZIP" ]] || { echo "Release ZIP not found: $ZIP" >&2; exit 65; }

ROOT="$ROOT" ZIP="$ZIP" python3 <<'PY'
import hashlib
import os
import pathlib
import posixpath
import re
import subprocess
import zipfile

root = pathlib.Path(os.environ["ROOT"])
zip_path = pathlib.Path(os.environ["ZIP"])
slug = "supcheckout"

identity = subprocess.check_output(
    ["git", "-C", str(root), "show", "HEAD:src/Release/Identity.php"],
    text=True,
)
match = re.search(r"public const VERSION = '([^']+)';", identity)
if not match:
    raise SystemExit("Cannot read canonical version")
version = match.group(1)

expected_name = f"{slug}-{version}.zip"
if zip_path.name != expected_name:
    raise SystemExit(f"Unexpected ZIP filename: {zip_path.name} != {expected_name}")

checksum_path = pathlib.Path(str(zip_path) + ".sha256")
manifest_path = zip_path.with_name(f"{slug}-{version}.manifest.sha256")
if not checksum_path.is_file():
    raise SystemExit("Missing ZIP checksum sidecar")
if not manifest_path.is_file():
    raise SystemExit("Missing release manifest sidecar")

actual_zip_hash = hashlib.sha256(zip_path.read_bytes()).hexdigest()
if checksum_path.read_text(encoding="utf-8") != f"{actual_zip_hash}  {zip_path.name}\n":
    raise SystemExit("ZIP checksum sidecar does not match artifact bytes")

with zipfile.ZipFile(zip_path, "r") as archive:
    infos = archive.infolist()
    names = [i.filename for i in infos]
    if not names:
        raise SystemExit("Release ZIP is empty")
    if len(names) != len(set(names)):
        raise SystemExit("Release ZIP contains duplicate paths")
    if names != sorted(names):
        raise SystemExit("Release ZIP paths are not sorted deterministically")

    prefix = slug + "/"
    for info in infos:
        name = info.filename
        if info.is_dir():
            raise SystemExit(f"Unexpected directory entry: {name}")
        if not name.startswith(prefix):
            raise SystemExit(f"Path escapes canonical root: {name}")
        if "\\" in name or name.startswith("/") or "\x00" in name:
            raise SystemExit(f"Unsafe artifact path: {name}")
        if info.compress_type != zipfile.ZIP_STORED:
            raise SystemExit(f"Non-deterministic compression method: {name}")
        if info.date_time != (1980, 1, 1, 0, 0, 0):
            raise SystemExit(f"Non-deterministic ZIP timestamp: {name}")
        if info.create_system != 3:
            raise SystemExit(f"Non-deterministic ZIP creator system: {name}")
        if (info.external_attr >> 16) != 0o100644:
            raise SystemExit(f"Non-deterministic ZIP mode: {name}")
        rel = name[len(prefix):]
        if posixpath.normpath(rel) != rel or rel in ("", ".", "..") or rel.startswith("../"):
            raise SystemExit(f"Unsafe normalized path: {name}")

    forbidden_exact = {
        ".distignore", ".editorconfig", ".gitattributes", ".gitignore",
        "AGENTS.md", "composer.json", "composer.lock", "phpcs.xml.dist",
        "phpstan.neon.dist", "phpunit.xml.dist", "CONTRIBUTING.md",
        "MAINTAINERS.md", "SUPPORT.md", "UPSTREAM.md", "README.md",
        "CHANGELOG.md", "SECURITY.md",
    }
    forbidden_prefixes = (".github/", ".cache/", ".phpunit.cache/", "tests/", "vendor/", "docs/", "scripts/")
    rel_names = [n[len(prefix):] for n in names]
    for rel in rel_names:
        if rel in forbidden_exact or rel.startswith(forbidden_prefixes):
            raise SystemExit(f"Forbidden development/control path: {rel}")

    allowed_exact = {
        "UPayments.php", "index.php", "uninstall.php", "LICENSE", "readme.txt",
    }
    allowed_prefixes = ("src/", "includes/", "assets/", "templates/")
    for rel in rel_names:
        if rel not in allowed_exact and not rel.startswith(allowed_prefixes):
            raise SystemExit(f"Unexpected release path outside allowlist: {rel}")

    distignore = subprocess.check_output(
        ["git", "-C", str(root), "show", "HEAD:.distignore"],
        text=True,
    )
    patterns = []
    for raw in distignore.splitlines():
        value = raw.strip()
        if not value or value.startswith("#"):
            continue
        if not value.startswith("/"):
            raise SystemExit(f"Unsupported non-root .distignore rule: {value}")
        patterns.append(value)

    def excluded(path: str) -> bool:
        candidate = "/" + path
        for pattern in patterns:
            if pattern.endswith("/"):
                if candidate.startswith(pattern):
                    return True
            elif candidate == pattern:
                return True
        return False

    tree = subprocess.run(
        ["git", "-C", str(root), "ls-tree", "-r", "-z", "HEAD"],
        check=True,
        stdout=subprocess.PIPE,
    ).stdout
    expected = {}
    for record in tree.split(b"\0"):
        if not record:
            continue
        metadata, path_bytes = record.split(b"\t", 1)
        mode, object_type, object_sha = metadata.decode("ascii").split(" ", 2)
        relative = path_bytes.decode("utf-8")
        if excluded(relative):
            continue
        if object_type != "blob" or mode not in {"100644", "100755"}:
            raise SystemExit(f"Unsupported release tree entry: {mode} {object_type} {relative}")
        expected[prefix + relative] = object_sha

    expected_names = sorted(expected)
    if names != expected_names:
        raise SystemExit("ZIP paths do not exactly match the Git HEAD distribution set")

    for name in names:
        source_blob = subprocess.run(
            ["git", "-C", str(root), "cat-file", "blob", expected[name]],
            check=True,
            stdout=subprocess.PIPE,
        ).stdout
        if archive.read(name) != source_blob:
            raise SystemExit(f"ZIP bytes do not match Git HEAD source: {name}")

    required = {
        "UPayments.php", "index.php", "uninstall.php", "LICENSE", "readme.txt",
        "src/Release/Identity.php",
        "includes/class-wc-gateway-upayments-blocks.php",
    }
    missing = sorted(required.difference(rel_names))
    if missing:
        raise SystemExit("Missing required release files: " + ", ".join(missing))
    for subtree in ("src/", "includes/", "assets/", "templates/"):
        if not any(rel.startswith(subtree) for rel in rel_names):
            raise SystemExit(f"Missing required runtime subtree: {subtree}")

    plugin_source = archive.read(prefix + "UPayments.php").decode("utf-8")
    identity_source = archive.read(prefix + "src/Release/Identity.php").decode("utf-8")
    if "Plugin Name: SUPCheckout for UPayments" not in plugin_source:
        raise SystemExit("Packaged plugin name is invalid")
    if f"Version: {version}" not in plugin_source:
        raise SystemExit("Packaged plugin version mismatches canonical identity")
    if "Text Domain: supcheckout" not in plugin_source:
        raise SystemExit("Canonical SUPCheckout text domain changed during packaging")
    if "public const LEGACY_MAIN_FILE = 'UPayments.php';" not in identity_source:
        raise SystemExit("Transitional main-file identity changed during packaging")

    parsed = {}
    for line in manifest_path.read_text(encoding="utf-8").splitlines():
        m = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not m:
            raise SystemExit(f"Malformed manifest line: {line}")
        digest, name = m.groups()
        if name in parsed:
            raise SystemExit(f"Duplicate manifest entry: {name}")
        parsed[name] = digest

    if set(parsed) != set(names):
        raise SystemExit("Manifest paths do not match ZIP contents")
    for name in names:
        digest = hashlib.sha256(archive.read(name)).hexdigest()
        if parsed[name] != digest:
            raise SystemExit(f"Manifest digest mismatch: {name}")

print(f"VERIFIED RELEASE ZIP: {zip_path.name}")
print(f"VERIFIED RELEASE SHA256: {actual_zip_hash}")
print(f"VERIFIED RELEASE FILES: {len(names)}")
PY
