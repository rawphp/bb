#!/usr/bin/env bash
# Prepare a fresh checkout. Safe to run more than once.
# The front-end step runs once package.json exists.
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --no-interaction --no-progress
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --no-interaction

if [ -f package.json ]; then
  npm ci
  # tests/e2e runs on Playwright's Chromium.
  npx playwright install chromium
fi
