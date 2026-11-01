#!/usr/bin/env bash
# The gate (docs/architecture.md, "Tests"). Stops at the first failure.
# The front-end steps run once package.json exists.
set -euo pipefail
cd "$(dirname "$0")/.."

step() { echo "==> $*"; "$@"; }

step bash scripts/spec-ids.sh
step ./vendor/bin/pint --test
step ./vendor/bin/phpstan analyse --no-progress
step ./vendor/bin/pest --parallel

if [ -f package.json ]; then
  step npx eslint resources/js
  step npx vue-tsc --noEmit
  step npx vitest run
  step npx vite build
  if ls tests/e2e/*.spec.ts >/dev/null 2>&1; then
    step npx playwright test
  fi
fi
