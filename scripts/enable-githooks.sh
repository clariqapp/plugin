#!/usr/bin/env bash
# Activate repository-managed hooks for this checkout.
set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
git -C "$repo_root" config core.hooksPath .githooks
printf 'Activated hooks from %s/.githooks\n' "$repo_root"
