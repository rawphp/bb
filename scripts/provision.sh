#!/usr/bin/env bash
# Prepare a fresh checkout. Safe to run more than once.
# Each step runs only once its project file exists, so this works before the app is scaffolded.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f composer.json ]; then
  composer install --no-interaction --no-progress
  [ -f .env ] || cp .env.example .env
  grep -q '^APP_KEY=base64' .env || php artisan key:generate --no-interaction
fi

if [ -f package.json ]; then
  npm ci
fi
