#!/usr/bin/env python3
"""Verify every release metadata source names the same plugin version."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION_PATTERNS = {
    "plugin header": (ROOT / "wc-analytics-mcp.php", r"^ \* Version:\s*([^\s]+)\s*$"),
    "runtime constant": (ROOT / "wc-analytics-mcp.php", r"^define\('WC_MCP_VERSION',\s*'([^']+)'\);$"),
    "WordPress readme": (ROOT / "readme.txt", r"^Stable tag:\s*([^\s]+)\s*$"),
}


def match_version(path: Path, pattern: str) -> str:
    match = re.search(pattern, path.read_text(encoding="utf-8"), re.MULTILINE)
    if not match:
        raise ValueError(f"could not find version in {path.relative_to(ROOT)}")
    return match.group(1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--expected", help="required release version")
    args = parser.parse_args()

    versions = {label: match_version(path, pattern) for label, (path, pattern) in VERSION_PATTERNS.items()}
    for manifest in (ROOT / "package.json", ROOT / "admin-ui/package.json", ROOT / "admin-ui/package-lock.json"):
        versions[manifest.relative_to(ROOT).as_posix()] = json.loads(manifest.read_text(encoding="utf-8"))["version"]

    unique_versions = set(versions.values())
    if len(unique_versions) != 1:
        for label, version in versions.items():
            print(f"{label}: {version}", file=sys.stderr)
        print("Version metadata does not agree.", file=sys.stderr)
        return 1

    version = unique_versions.pop()
    if args.expected and version != args.expected:
        print(f"Expected {args.expected}, found {version}.", file=sys.stderr)
        return 1
    print(f"Version metadata verified: {version}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
