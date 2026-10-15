#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

matches="$(mktemp)"
trap 'rm -f -- "$matches"' EXIT
if git grep -I -l -E '(AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9_]{20,}|xox[baprs]-[A-Za-z0-9-]{20,})' -- . ':(exclude)*.lock' >"$matches"; then
  echo "High-confidence secret patterns found in $(wc -l <"$matches") tracked file(s):" >&2
  sed 's/^/  /' "$matches" >&2
  exit 1
fi

echo "Tracked-secret scan passed."
