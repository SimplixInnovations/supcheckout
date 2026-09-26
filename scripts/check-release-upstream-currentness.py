#!/usr/bin/env python3
"""Release upstream currentness — query authoritative release metadata.

Compares certified WordPress/WooCommerce pins against the highest stable
patch in each supported minor. Fail closed when upstream truth cannot be
established (network/malformed). Never used in ordinary PR CI.

Usage:
  python3 scripts/check-release-upstream-currentness.py \
    --wp-certified 6.9.9,7.0.6,7.1.2 \
    --wc-certified 10.8.1,11.0.1,11.1.2 \
    [--offline-wp-file fixtures.json] [--offline-wc-file fixtures.json]

Exit 0 CURRENT / 2 STALE_UPSTREAM_CERTIFICATION / 3 UNKNOWN_UPSTREAM / 64 usage
"""
from __future__ import annotations

import json
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

WP_API = "https://api.wordpress.org/core/version-check/1.7/"
WC_RELEASES_API = (
    "https://api.github.com/repos/woocommerce/woocommerce/releases?per_page=100"
)
# Prerelease markers that must never be treated as stable.
PRERELEASE = re.compile(r"(alpha|beta|rc|dev|nightly)", re.IGNORECASE)


def parse_version(v: str) -> tuple[int, ...]:
    m = re.match(r"^(\d+)\.(\d+)(?:\.(\d+))?$", v.strip())
    if not m:
        raise ValueError(f"malformed version: {v!r}")
    return tuple(int(x or 0) for x in m.groups())


def fetch_json(url: str, timeout: int = 20):
    req = urllib.request.Request(url, headers={"User-Agent": "supcheckout-r6-currentness"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def wp_versions_from_payload(payload) -> list[str]:
    """Extract offer versions from wordpress.org version-check payload."""
    out: list[str] = []
    if isinstance(payload, dict):
        offers = payload.get("offers") or payload.get("data") or []
        if isinstance(offers, list):
            for o in offers:
                if isinstance(o, dict) and o.get("version"):
                    out.append(str(o["version"]))
        if payload.get("version"):
            out.append(str(payload["version"]))
    if isinstance(payload, list):
        for item in payload:
            if isinstance(item, dict) and item.get("version"):
                out.append(str(item["version"]))
            elif isinstance(item, str):
                out.append(item)
    return out


def wc_versions_from_payload(payload) -> list[str]:
    """Extract release tag names from GitHub releases payload."""
    out: list[str] = []
    if isinstance(payload, list):
        for r in payload:
            if not isinstance(r, dict):
                continue
            tag = str(r.get("tag_name") or r.get("name") or "")
            tag = tag.lstrip("vV")
            if tag:
                out.append(tag)
    return out


def highest_stable_for_minor(versions: list[str], minor: tuple[int, int]) -> str | None:
    best = None
    best_key = None
    for v in versions:
        if PRERELEASE.search(v):
            continue
        try:
            key = parse_version(v)
        except ValueError:
            continue
        if key[0] != minor[0] or key[1] != minor[1]:
            continue
        if best_key is None or key > best_key:
            best_key = key
            best = v
    return best


def compare(certified: dict[str, str], current: dict[str, str]) -> tuple[str, list[str]]:
    diffs = []
    for branch, cert_v in certified.items():
        cur_v = current.get(branch)
        if cur_v is None:
            return "UNKNOWN_UPSTREAM", [f"{branch}: no current stable upstream version"]
        if parse_version(cur_v) != parse_version(cert_v):
            diffs.append(f"{branch}: certified={cert_v} current={cur_v}")
    if diffs:
        return "STALE_UPSTREAM_CERTIFICATION", diffs
    return "CURRENT", []


def main(argv: list[str]) -> int:
    wp_cert = ""
    wc_cert = ""
    offline_wp = ""
    offline_wc = ""
    args = list(argv)
    while args:
        a = args.pop(0)
        if a == "--wp-certified":
            wp_cert = args.pop(0)
        elif a == "--wc-certified":
            wc_cert = args.pop(0)
        elif a == "--offline-wp-file":
            offline_wp = args.pop(0)
        elif a == "--offline-wc-file":
            offline_wc = args.pop(0)
        else:
            print(f"unknown arg {a}", file=sys.stderr)
            return 64

    if not wp_cert or not wc_cert:
        print("usage: --wp-certified a,b,c --wc-certified x,y,z", file=sys.stderr)
        return 64

    wp_branches = [tuple(parse_version(v)[:2]) for v in wp_cert.split(",")]
    wc_branches = [tuple(parse_version(v)[:2]) for v in wc_cert.split(",")]

    try:
        if offline_wp:
            wp_versions = wp_versions_from_payload(json.loads(Path(offline_wp).read_text()))
        else:
            wp_versions = wp_versions_from_payload(fetch_json(WP_API))
        if offline_wc:
            wc_versions = wc_versions_from_payload(json.loads(Path(offline_wc).read_text()))
        else:
            wc_versions = wc_versions_from_payload(fetch_json(WC_RELEASES_API))
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError) as e:
        print(f"UNKNOWN_UPSTREAM: retrieval failed: {e}")
        return 3

    if not wp_versions or not wc_versions:
        print("UNKNOWN_UPSTREAM: malformed or empty upstream payload")
        return 3

    wp_current = {}
    wc_current = {}
    for minor in wp_branches:
        h = highest_stable_for_minor(wp_versions, minor)
        if not h:
            print(f"UNKNOWN_UPSTREAM: no stable WP for {minor[0]}.{minor[1]}")
            return 3
        wp_current[f"{minor[0]}.{minor[1]}"] = h
    for minor in wc_branches:
        h = highest_stable_for_minor(wc_versions, minor)
        if not h:
            print(f"UNKNOWN_UPSTREAM: no stable WC for {minor[0]}.{minor[1]}")
            return 3
        wc_current[f"{minor[0]}.{minor[1]}"] = h

    wp_cert_map = {f"{parse_version(v)[0]}.{parse_version(v)[1]}": v for v in wp_cert.split(",")}
    wc_cert_map = {f"{parse_version(v)[0]}.{parse_version(v)[1]}": v for v in wc_cert.split(",")}

    status_wp, diffs_wp = compare(wp_cert_map, wp_current)
    status_wc, diffs_wc = compare(wc_cert_map, wc_current)
    diffs = diffs_wp + diffs_wc
    if "UNKNOWN_UPSTREAM" in (status_wp, status_wc):
        print("UNKNOWN_UPSTREAM")
        for d in diffs:
            print(d)
        return 3
    if diffs:
        print("STALE_UPSTREAM_CERTIFICATION")
        for d in diffs:
            print(d)
        return 2
    print("CURRENT")
    for k, v in sorted(wp_current.items()):
        print(f"WP {k}: {v}")
    for k, v in sorted(wc_current.items()):
        print(f"WC {k}: {v}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
