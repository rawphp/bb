#!/usr/bin/env bash
# The gate. Stops at the first failure.
# Steps run once their tools exist; SKEL_API and SKEL_WEB grow this to the full gate in docs/architecture.md.
set -euo pipefail
cd "$(dirname "$0")/.."

ran=0
step() { echo "==> $*"; "$@"; ran=1; }

[ -f scripts/spec-ids.sh ] && step bash scripts/spec-ids.sh

if [ -f composer.json ]; then
  step ./vendor/bin/pint --test
  [ -f phpstan.neon ] && step ./vendor/bin/phpstan analyse --no-progress
  step ./vendor/bin/pest --parallel
fi

if [ -f package.json ]; then
  step npx vue-tsc --noEmit
  step npx vitest run
  step npx vite build
  ls tests/e2e/*.spec.ts >/dev/null 2>&1 && step npx playwright test
fi

[ "$ran" = 1 ] || echo "==> nothing to check yet"
