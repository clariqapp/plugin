#!/usr/bin/env bash
# Scan the tracked working tree without mutating it.
set -euo pipefail

if ! command -v gitleaks >/dev/null 2>&1; then
  echo "gitleaks is required; install it before pushing or releasing." >&2
  exit 127
fi

gitleaks detect --source . --config gitleaks.toml --redact --no-git
