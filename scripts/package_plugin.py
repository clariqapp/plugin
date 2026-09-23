#!/usr/bin/env python3
"""Create a deterministic, allowlisted WordPress plugin ZIP using only Python's standard library."""
from __future__ import annotations

import argparse
import hashlib
import re
import stat
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ARCHIVE_ROOT = "wc-analytics-mcp"
FIXED_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
EXACT_FILES = (
    "LICENSE",
    "README.md",
    "readme.txt",
    "uninstall.php",
    "wc-analytics-mcp.php",
    "admin-ui/package.json",
    "admin-ui/package-lock.json",
    "admin-ui/tsconfig.json",
)
TREE_DIRECTORIES = ("src", "admin-ui/src", "admin-ui/build")


def version() -> str:
    bootstrap = (ROOT / "wc-analytics-mcp.php").read_text(encoding="utf-8")
    match = re.search(r"^ \* Version:\s*([^\s]+)\s*$", bootstrap, re.MULTILINE)
    if not match:
        raise ValueError("could not find plugin version in bootstrap header")
    return match.group(1)


def allowlisted_files() -> list[Path]:
    files: list[Path] = []
    for relative in EXACT_FILES:
        path = ROOT / relative
        if not path.is_file():
            raise FileNotFoundError(f"required release file is missing: {relative}")
        files.append(path)
    for relative in TREE_DIRECTORIES:
        directory = ROOT / relative
        if not directory.is_dir():
            raise FileNotFoundError(f"required release directory is missing: {relative}")
        files.extend(path for path in directory.rglob("*") if path.is_file())
    if not any((ROOT / "admin-ui/build").iterdir()):
        raise ValueError("admin-ui/build is empty; run npm run build before packaging")
    return sorted(files, key=lambda path: path.relative_to(ROOT).as_posix())


def zip_info(archive_path: str, source: Path) -> zipfile.ZipInfo:
    info = zipfile.ZipInfo(archive_path, date_time=FIXED_TIMESTAMP)
    info.compress_type = zipfile.ZIP_DEFLATED
    # Normalize Unix permissions while retaining executability for executable source files.
    mode = 0o755 if source.stat().st_mode & stat.S_IXUSR else 0o644
    info.external_attr = (stat.S_IFREG | mode) << 16
    info.create_system = 3
    return info


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, help="archive path (defaults to dist/wc-analytics-mcp-<version>.zip)")
    args = parser.parse_args()
    release_version = version()
    output = args.output or ROOT / "dist" / f"{ARCHIVE_ROOT}-{release_version}.zip"
    output = output.resolve()
    if ROOT not in output.parents:
        print("Output must be inside the repository.", file=sys.stderr)
        return 2

    files = allowlisted_files()
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9, strict_timestamps=True) as archive:
        for source in files:
            relative = source.relative_to(ROOT).as_posix()
            archive.writestr(zip_info(f"{ARCHIVE_ROOT}/{relative}", source), source.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    checksum = output.with_suffix(output.suffix + ".sha256")
    checksum.write_text(f"{digest}  {output.name}\n", encoding="utf-8")
    print(f"Created {output.relative_to(ROOT)}")
    print(f"SHA256 {digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
