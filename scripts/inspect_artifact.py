#!/usr/bin/env python3
"""Validate a release ZIP's layout, allowlist, and deterministic metadata."""
from __future__ import annotations

import argparse
import hashlib
import re
import stat
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ARCHIVE_ROOT = "clariq-analytics-mcp/"
FIXED_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
REQUIRED = {
    "clariq-analytics-mcp/wc-analytics-mcp.php",
    "clariq-analytics-mcp/uninstall.php",
    "clariq-analytics-mcp/LICENSE",
    "clariq-analytics-mcp/readme.txt",
    "clariq-analytics-mcp/src/Plugin.php",
    "clariq-analytics-mcp/admin-ui/src/index.js",
    "clariq-analytics-mcp/admin-ui/build/index.js",
    "clariq-analytics-mcp/admin-ui/build/index.asset.php",
    "clariq-analytics-mcp/admin-ui/build/style-index.css",
    "clariq-analytics-mcp/admin-ui/src/fonts/OFL-InstrumentSans.txt",
    "clariq-analytics-mcp/admin-ui/src/fonts/OFL-InstrumentSerif.txt",
    "clariq-analytics-mcp/admin-ui/src/fonts/OFL-JetBrainsMono.txt",
}
ALLOWED_EXACT = {
    "LICENSE", "README.md", "readme.txt", "uninstall.php", "wc-analytics-mcp.php",
    "admin-ui/package.json", "admin-ui/package-lock.json", "admin-ui/tsconfig.json",
}
ALLOWED_TREES = ("src/", "admin-ui/src/", "admin-ui/build/")
FORBIDDEN_PARTS = (".git/", ".env", "docker/", "tests/", "vendor/", "node_modules/", "dist/", ".phpunit")


def allowed(relative: str) -> bool:
    return relative in ALLOWED_EXACT or relative.startswith(ALLOWED_TREES)


def expected_version() -> str:
    text = (ROOT / "wc-analytics-mcp.php").read_text(encoding="utf-8")
    match = re.search(r"^ \* Version:\s*([^\s]+)\s*$", text, re.MULTILINE)
    if not match:
        raise ValueError("could not determine plugin version")
    return match.group(1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("artifact", type=Path, nargs="?", help="ZIP to inspect")
    args = parser.parse_args()
    artifact = args.artifact or ROOT / "dist" / f"clariq-analytics-mcp-{expected_version()}.zip"
    if not artifact.is_file():
        print(f"Artifact not found: {artifact}", file=sys.stderr)
        return 1

    problems: list[str] = []
    with zipfile.ZipFile(artifact) as archive:
        members = archive.infolist()
        names = [member.filename for member in members]
        if len(names) != len(set(names)):
            problems.append("archive contains duplicate paths")
        if names != sorted(names):
            problems.append("archive members are not sorted")
        missing = REQUIRED.difference(names)
        if missing:
            problems.append("missing required files: " + ", ".join(sorted(missing)))
        for member in members:
            if member.is_dir() or not member.filename.startswith(ARCHIVE_ROOT):
                problems.append(f"invalid archive member: {member.filename}")
                continue
            relative = member.filename.removeprefix(ARCHIVE_ROOT)
            if not allowed(relative):
                problems.append(f"path is outside release allowlist: {relative}")
            if any(part in relative for part in FORBIDDEN_PARTS):
                problems.append(f"forbidden release path: {relative}")
            if member.date_time != FIXED_TIMESTAMP:
                problems.append(f"non-deterministic timestamp: {relative}")
            if member.compress_type != zipfile.ZIP_DEFLATED:
                problems.append(f"unexpected compression: {relative}")
            mode = member.external_attr >> 16
            if stat.S_IFMT(mode) != stat.S_IFREG:
                problems.append(f"non-regular file: {relative}")
    if problems:
        print("Artifact inspection failed:", file=sys.stderr)
        print("\n".join(f"- {problem}" for problem in problems), file=sys.stderr)
        return 1
    if args.artifact is None:
        stable = ROOT / "dist" / "clariq-analytics-mcp.zip"
        if not stable.is_file() or stable.read_bytes() != artifact.read_bytes():
            print("Stable download ZIP is missing or differs from the versioned ZIP.", file=sys.stderr)
            return 1
        digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
        for package in (artifact, stable):
            checksum = package.with_suffix(package.suffix + ".sha256")
            if not checksum.is_file() or checksum.read_text(encoding="utf-8") != f"{digest}  {package.name}\n":
                print(f"Missing or incorrect checksum: {checksum}", file=sys.stderr)
                return 1
    print(f"Artifact inspection passed: {artifact}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
